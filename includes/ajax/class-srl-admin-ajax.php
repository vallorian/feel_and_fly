<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Admin_Ajax {
    
    private static $instance = null;
    private $cache_manager;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->cache_manager = SRL_Cache_Manager::getInstance();
        $this->initAjaxActions();
    }

    private function initAjaxActions() {
        $actions = [
            'srl_dodaj_godzine', 'srl_zmien_slot', 'srl_usun_godzine', 'srl_zmien_status_godziny',
            'srl_anuluj_lot_przez_organizatora', 'srl_wyszukaj_klientow_loty', 'srl_dodaj_voucher_recznie',
            'srl_wyszukaj_dostepnych_klientow', 'srl_przypisz_klienta_do_slotu', 'srl_zapisz_dane_prywatne',
            'srl_pobierz_dane_prywatne', 'srl_pobierz_aktualne_godziny', 'srl_wyszukaj_wolne_loty',
            'srl_przypisz_wykupiony_lot', 'srl_zapisz_lot_prywatny', 'srl_pobierz_historie_lotu',
            'srl_przywroc_rezerwacje', 'srl_pobierz_dane_odwolanego', 'srl_zrealizuj_lot',
            'srl_zrealizuj_lot_prywatny', 'srl_pobierz_dostepne_terminy_do_zmiany', 'srl_zmien_termin_lotu',
            'srl_admin_zmien_status_lotu', 'srl_pobierz_szczegoly_lotu'
        ];

        foreach ($actions as $action) {
            add_action("wp_ajax_{$action}", [$this, str_replace('srl_', 'ajax', ucwords($action, '_'))]);
        }
    }

    private function validateAdminRequest() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
    }

    private function getCachedOrExecute($cache_key, $callback, $expire = 300) {
        $cached = wp_cache_get($cache_key, 'srl_admin_cache');
        if ($cached !== false) {
            return $cached;
        }
        
        $result = $callback();
        wp_cache_set($cache_key, $result, 'srl_admin_cache', $expire);
        return $result;
    }

    private function invalidateRelatedCache($data) {
        $year = date('Y', strtotime($data));
        $month = date('n', strtotime($data));
        
        wp_cache_delete("admin_day_schedule_{$data}", 'srl_admin_cache');
        wp_cache_delete("available_days_{$year}_{$month}", 'srl_admin_cache');
        wp_cache_delete("calendar_data_{$year}_{$month}", 'srl_admin_cache');
    }

    private function getDayScheduleOptimized($data) {
        return $this->getCachedOrExecute(
            "admin_day_schedule_{$data}",
            function() use ($data) {
                return SRL_Database_Helpers::getInstance()->getDayScheduleOptimized($data);
            }
        );
    }

    public function ajaxAdminZmienStatusLotu() {
        $this->validateAdminRequest();

        $lot_id = intval($_POST['lot_id']);
        $nowy_status = sanitize_text_field($_POST['nowy_status']);

        if (!$lot_id || !$nowy_status) {
            wp_send_json_error('Nieprawidłowe parametry.');
        }

        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';

        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_loty WHERE id = %d",
            $lot_id
        ), ARRAY_A);

        if (!$lot) {
            wp_send_json_error('Lot nie istnieje.');
        }

        $stary_status = $lot['status'];
        $wpdb->query('START TRANSACTION');

        try {
            if ($stary_status === 'zarezerwowany' && $nowy_status === 'wolny' && $lot['termin_id']) {
                $wpdb->update(
                    $tabela_terminy,
                    ['status' => 'Wolny', 'klient_id' => null],
                    ['id' => $lot['termin_id']],
                    ['%s', '%d'],
                    ['%d']
                );

                $update_data = [
                    'status' => $nowy_status,
                    'termin_id' => null,
                    'data_rezerwacji' => null
                ];
            } else {
                $update_data = ['status' => $nowy_status];
            }

            $result = $wpdb->update(
                $tabela_loty,
                $update_data,
                ['id' => $lot_id],
                array_fill(0, count($update_data), '%s'),
                ['%d']
            );

            if ($result === false) {
                throw new Exception('Błąd aktualizacji statusu lotu.');
            }

            $wpis_historii = [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'zmiana_statusu_admin',
                'executor' => 'Admin',
                'szczegoly' => [
                    'stary_status' => $stary_status,
                    'nowy_status' => $nowy_status,
                    'zmiana_przez_admin' => true,
                    'lot_id' => $lot_id
                ]
            ];

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);

            $wpdb->query('COMMIT');
            wp_send_json_success('Status lotu został zmieniony z "' . $stary_status . '" na "' . $nowy_status . '".');

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxPobierzSzczegolyLotu() {
        $this->validateAdminRequest();

        $lot_id = intval($_POST['lot_id']);
        $user_id = intval($_POST['user_id']);

        if (!$lot_id || !$user_id) {
            wp_send_json_error('Nieprawidłowe parametry.');
        }

        $cache_key = "flight_details_{$lot_id}_{$user_id}";
        
        $dane = $this->getCachedOrExecute($cache_key, function() use ($lot_id, $user_id) {
            $user_data = SRL_Helpers::getInstance()->getUserFullData($user_id);
            if (!$user_data) {
                throw new Exception('Nie znaleziono danych użytkownika.');
            }

            global $wpdb;
            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d",
                $lot_id
            ), ARRAY_A);

            if (!$lot) {
                throw new Exception('Nie znaleziono danych lotu.');
            }

            $dane = [
                'lot_id' => $lot_id,
                'imie' => $user_data['imie'],
                'nazwisko' => $user_data['nazwisko'],
                'email' => $user_data['email'],
                'telefon' => $user_data['telefon'],
                'rok_urodzenia' => $user_data['rok_urodzenia'],
                'kategoria_wagowa' => $user_data['kategoria_wagowa'],
                'sprawnosc_fizyczna' => $user_data['sprawnosc_fizyczna'],
                'uwagi' => $user_data['uwagi'],
                'nazwa_produktu' => $lot['nazwa_produktu'],
                'status' => $lot['status'],
                'data_zakupu' => $lot['data_zakupu'],
                'data_waznosci' => $lot['data_waznosci']
            ];

            if ($user_data['rok_urodzenia']) {
                $dane['wiek'] = date('Y') - intval($user_data['rok_urodzenia']);
            }

            return $dane;
        });

        wp_send_json_success($dane);
    }

    public function ajaxDodajGodzine() {
        $this->validateAdminRequest();

        if (!isset($_POST['data']) || !isset($_POST['pilot_id'])) {
            wp_send_json_error('Nieprawidłowe dane.');
        }

        $data = sanitize_text_field($_POST['data']);
        $pilot_id = intval($_POST['pilot_id']);
        $godzStart = sanitize_text_field($_POST['godzina_start']) . ':00';
        $godzKoniec = sanitize_text_field($_POST['godzina_koniec']) . ':00';
        $status = sanitize_text_field($_POST['status']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $pilot_id < 1 || $pilot_id > 4) {
            wp_send_json_error('Nieprawidłowe parametry.');
        }

        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'srl_terminy',
            [
                'data' => $data,
                'pilot_id' => $pilot_id,
                'godzina_start' => $godzStart,
                'godzina_koniec' => $godzKoniec,
                'status' => $status,
                'klient_id' => 0
            ],
            ['%s','%d','%s','%s','%s','%d']
        );

        if ($result === false) {
            wp_send_json_error('Nie udało się zapisać slotu: ' . $wpdb->last_error);
        }

        $this->invalidateRelatedCache($data);
        $godziny_wg_pilota = $this->getDayScheduleOptimized($data);
        wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);
    }

    public function ajaxZmienSlot() {
        $this->validateAdminRequest();

        if (!isset($_POST['termin_id'])) {
            wp_send_json_error('Nieprawidłowe dane.');
        }

        global $wpdb;
        $termin_id = intval($_POST['termin_id']);
        $data = sanitize_text_field($_POST['data']);
        $godzStart = sanitize_text_field($_POST['godzina_start']) . ':00';
        $godzKoniec = sanitize_text_field($_POST['godzina_koniec']) . ':00';

        $aktualny_slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$aktualny_slot) {
            wp_send_json_error('Slot nie istnieje.');
        }

        $wynik = $wpdb->update(
            $wpdb->prefix . 'srl_terminy',
            [
                'godzina_start' => $godzStart,
                'godzina_koniec' => $godzKoniec
            ],
            ['id' => $termin_id],
            ['%s','%s'],
            ['%d']
        );

        if ($wynik === false) {
            wp_send_json_error('Błąd aktualizacji w bazie: ' . $wpdb->last_error);
        }

        $this->invalidateRelatedCache($data);
        $godziny_wg_pilota = $this->getDayScheduleOptimized($data);
        wp_send_json_success([
            'message' => 'Godziny zostały zaktualizowane.',
            'godziny_wg_pilota' => $godziny_wg_pilota
        ]);
    }

    public function ajaxUsunGodzine() {
        $this->validateAdminRequest();

        if (!isset($_POST['termin_id'])) {
            wp_send_json_error('Nieprawidłowe dane.');
        }

        global $wpdb;
        $termin_id = intval($_POST['termin_id']);

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT data, status, klient_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje.');
        }

        if ($slot['status'] === 'Zarezerwowany' && intval($slot['klient_id']) > 0) {
            wp_send_json_error('Nie można usunąć zarezerwowanego slotu. Najpierw wypisz klienta.');
        }

        $data = $slot['data'];
        $usun = $wpdb->delete($wpdb->prefix . 'srl_terminy', ['id' => $termin_id], ['%d']);

        if ($usun === false || $usun === 0) {
            wp_send_json_error('Błąd usuwania slotu.');
        }

        $this->invalidateRelatedCache($data);
        $godziny_wg_pilota = $this->getDayScheduleOptimized($data);
        wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);
    }

    public function ajaxZmienStatusGodziny() {
        $this->validateAdminRequest();

        if (!isset($_POST['termin_id']) || !isset($_POST['status'])) {
            wp_send_json_error('Nieprawidłowe dane.');
        }

        global $wpdb;
        $termin_id = intval($_POST['termin_id']);
        $status = sanitize_text_field($_POST['status']);
        $klient_id = isset($_POST['klient_id']) ? intval($_POST['klient_id']) : 0;

        if ($status === 'Odwołany przez organizatora') {
            $this->ajaxAnulujLotPrzezOrganizatora();
            return;
        }

        $dozwolone_statusy = ['Wolny', 'Prywatny', 'Zarezerwowany', 'Zrealizowany', 'Odwołany przez organizatora'];
        if (!in_array($status, $dozwolone_statusy)) {
            wp_send_json_error('Nieprawidłowy status.');
        }

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje.');
        }

        $wpdb->query('START TRANSACTION');

        try {
            $dane_slotu = [
                'status' => $status,
                'klient_id' => $klient_id ?: null
            ];

            if ($status === 'Wolny') {
                $dane_slotu['notatka'] = null;
            }

            $wynik = $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                $dane_slotu,
                ['id' => $termin_id],
                ['%s','%d'],
                ['%d']
            );

            if ($wynik === false) {
                throw new Exception('Błąd aktualizacji statusu slotu');
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d",
                $termin_id
            ), ARRAY_A);

            if ($lot) {
                $this->updateFlightStatus($lot, $status, $termin_id, $wpdb);
            }

            $wpdb->query('COMMIT');
            
            $this->invalidateRelatedCache($slot['data']);
            $godziny_wg_pilota = $this->getDayScheduleOptimized($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    private function updateFlightStatus($lot, $status, $termin_id, $wpdb) {
        $status_mapping = [
            'Wolny' => 'wolny',
            'Zarezerwowany' => 'zarezerwowany',
            'Zrealizowany' => 'zrealizowany',
            'Odwołany przez organizatora' => 'wolny'
        ];

        if (!isset($status_mapping[$status])) {
            return;
        }

        $nowy_status_lotu = $status_mapping[$status];
        $stary_status = $lot['status'];

        $dane_lotu_update = ['status' => $nowy_status_lotu];

        if (in_array($status, ['Wolny', 'Odwołany przez organizatora'])) {
            $dane_lotu_update['termin_id'] = null;
            $dane_lotu_update['data_rezerwacji'] = null;
        }

        $update_result = $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            $dane_lotu_update,
            ['id' => $lot['id']],
            array_fill(0, count($dane_lotu_update), '%s'),
            ['%d']
        );

        if ($update_result === false) {
            throw new Exception('Błąd aktualizacji statusu lotu');
        }

        if ($stary_status !== $nowy_status_lotu) {
            $wpis_historii = [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'zmiana_statusu_admin',
                'executor' => 'Admin',
                'szczegoly' => [
                    'termin_id' => $termin_id,
                    'stary_status_slotu' => $status,
                    'nowy_status_slotu' => $status,
                    'stary_status' => $stary_status,
                    'nowy_status' => $nowy_status_lotu,
                    'zmiana_przez_admin' => true
                ]
            ];

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], $wpis_historii);
        }
    }

    public function ajaxAnulujLotPrzezOrganizatora() {
        $this->validateAdminRequest();

        if (!isset($_POST['termin_id'])) {
            wp_send_json_error('Nieprawidłowe dane.');
        }

        global $wpdb;
        $termin_id = intval($_POST['termin_id']);

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje.');
        }

        $klient_id = intval($slot['klient_id']);
        $wpdb->query('START TRANSACTION');

        try {
            $dane_historyczne = $this->prepareHistoricalData($slot, $klient_id, $termin_id, $wpdb);

            $result = $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                [
                    'status' => 'Odwołany przez organizatora',
                    'notatka' => json_encode($dane_historyczne)
                ],
                ['id' => $termin_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($result === false) {
                throw new Exception('Błąd aktualizacji slotu.');
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d",
                $termin_id
            ), ARRAY_A);

            if ($lot) {
                $this->handleFlightCancellation($lot, $slot, $klient_id, $termin_id, $wpdb);
            }

            $wpdb->query('COMMIT');
            
            $this->invalidateRelatedCache($slot['data']);
            $godziny_wg_pilota = $this->getDayScheduleOptimized($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Błąd podczas odwoływania: ' . $e->getMessage());
        }
    }

    private function prepareHistoricalData($slot, $klient_id, $termin_id, $wpdb) {
        $dane_historyczne = [
            'typ' => 'odwolany_przez_organizatora',
            'data_odwolania' => SRL_Helpers::getInstance()->getCurrentDatetime(),
            'oryginalny_status' => $slot['status'],
            'klient_id' => $klient_id
        ];

        if ($klient_id > 0) {
            $user_data = $this->cache_manager->getUserData($klient_id);
            if ($user_data) {
                $dane_historyczne = array_merge($dane_historyczne, [
                    'klient_email' => $user_data['email'],
                    'klient_nazwa' => $user_data['display_name'],
                    'telefon' => $user_data['telefon'],
                    'rok_urodzenia' => $user_data['rok_urodzenia'],
                    'kategoria_wagowa' => $user_data['kategoria_wagowa'],
                    'sprawnosc_fizyczna' => $user_data['sprawnosc_fizyczna'],
                    'uwagi' => $user_data['uwagi']
                ]);

                if ($user_data['imie'] && $user_data['nazwisko']) {
                    $dane_historyczne['klient_nazwa'] = $user_data['imie'] . ' ' . $user_data['nazwisko'];
                }
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d AND user_id = %d",
                $termin_id, $klient_id
            ), ARRAY_A);

            if ($lot) {
                $dane_historyczne = array_merge($dane_historyczne, [
                    'lot_id' => $lot['id'],
                    'order_id' => $lot['order_id'],
                    'nazwa_produktu' => $lot['nazwa_produktu'],
                    'data_rezerwacji' => $lot['data_rezerwacji'],
                    'dane_pasazera' => $lot['dane_pasazera']
                ]);
            }
        }

        return $dane_historyczne;
    }

    private function handleFlightCancellation($lot, $slot, $klient_id, $termin_id, $wpdb) {
        $stary_status = $lot['status'];
        $szczegoly_terminu = $slot['data'] . ' ' . substr($slot['godzina_start'], 0, 5) . '-' . substr($slot['godzina_koniec'], 0, 5);

        $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            [
                'status' => 'wolny', 
                'termin_id' => null, 
                'data_rezerwacji' => null
            ],
            ['id' => $lot['id']],
            ['%s', '%d', '%s'],
            ['%d']
        );

        $user_data = $this->cache_manager->getUserData($klient_id);
        if ($user_data) {
            $this->sendCancellationEmail($user_data, $szczegoly_terminu);
            $this->addCancellationHistoryEntry($lot['id'], $termin_id, $szczegoly_terminu, $stary_status, $klient_id);
        }
    }

    private function sendCancellationEmail($user_data, $szczegoly_terminu) {
        $to = $user_data['email'];
        $subject = 'Twój lot tandemowy został odwołany przez organizatora';
        $body = "Dzień dobry {$user_data['display_name']},\n\n"
             . "Niestety Twój lot, który był zaplanowany na {$szczegoly_terminu}, został odwołany przez organizatora z powodów niezależnych od nas (prawdopodobnie warunki pogodowe).\n\n"
             . "Status Twojego lotu został przywrócony – możesz ponownie wybrać inny termin.\n"
             . "Przepraszamy za niedogodności.\n\n"
             . "Pozdrawiamy,\nZespół Loty Tandemowe";
        wp_mail($to, $subject, $body);
    }

    private function addCancellationHistoryEntry($lot_id, $termin_id, $szczegoly_terminu, $stary_status, $klient_id) {
        $wpis_historii = [
            'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
            'typ' => 'odwolanie_przez_organizatora',
            'executor' => 'Admin',
            'szczegoly' => [
                'termin_id' => $termin_id,
                'odwolany_termin' => $szczegoly_terminu,
                'stary_status' => $stary_status,
                'nowy_status' => 'wolny',
                'klient_id' => $klient_id,
                'email_wyslany' => true,
                'slot_zachowany' => true
            ]
        ];

        SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);
    }

    public function ajaxZrealizujLot() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $this->processFlightCompletion($termin_id, false);
    }

    public function ajaxZrealizujLotPrywatny() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $this->processFlightCompletion($termin_id, true);
    }

    private function processFlightCompletion($termin_id, $is_private = false) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ), ARRAY_A);

            $expected_status = $is_private ? 'Prywatny' : 'Zarezerwowany';
            if (!$slot || $slot['status'] !== $expected_status) {
                throw new Exception('Slot nie istnieje lub ma nieprawidłowy status.');
            }

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Zrealizowany'],
                ['id' => $termin_id],
                ['%s'],
                ['%d']
            );

            if (!$is_private) {
                $lot = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d",
                    $termin_id
                ), ARRAY_A);

                if ($lot) {
                    $wpdb->update(
                        $wpdb->prefix . 'srl_zakupione_loty',
                        ['status' => 'zrealizowany'],
                        ['id' => $lot['id']],
                        ['%s'],
                        ['%d']
                    );

                    $wpis_historii = [
                        'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                        'typ' => 'realizacja_admin',
                        'executor' => 'Admin',
                        'szczegoly' => [
                            'termin_id' => $termin_id,
                            'stary_status' => 'zarezerwowany',
                            'nowy_status' => 'zrealizowany',
                            'lot_id' => $lot['id']
                        ]
                    ];

                    SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], $wpis_historii);
                }
            }

            $wpdb->query('COMMIT');

            $this->invalidateRelatedCache($slot['data']);
            $godziny_wg_pilota = $this->getDayScheduleOptimized($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxPrzypiszWykupionyLot() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $lot_id = intval($_POST['lot_id']);

        $this->assignFlightToSlot($termin_id, $lot_id);
    }

    public function ajaxPrzypiszKlientaDoSlotu() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $lot_id = intval($_POST['lot_id']);

        $this->assignFlightToSlot($termin_id, $lot_id);
    }

    private function assignFlightToSlot($termin_id, $lot_id) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Wolny'",
                $termin_id
            ), ARRAY_A);

            if (!$slot) {
                throw new Exception('Slot nie jest dostępny.');
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d AND status = 'wolny'",
                $lot_id
            ), ARRAY_A);

            if (!$lot) {
                throw new Exception('Lot nie jest dostępny.');
            }

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                [
                    'status' => 'Zarezerwowany',
                    'klient_id' => $lot['user_id']
                ],
                ['id' => $termin_id],
                ['%s', '%d'],
                ['%d']
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                [
                    'status' => 'zarezerwowany',
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'termin_id' => $termin_id
                ],
                ['id' => $lot_id],
                ['%s', '%s', '%d'],
                ['%d']
            );

            $termin = $wpdb->get_row($wpdb->prepare(
                "SELECT data, godzina_start, godzina_koniec, pilot_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ), ARRAY_A);

            $termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                $termin['data'], 
                substr($termin['godzina_start'], 0, 5), 
                substr($termin['godzina_koniec'], 0, 5),
                $termin['pilot_id']
            );

            $wpis_historii = [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'przypisanie_admin',  
                'executor' => 'Admin',
                'szczegoly' => [
                    'termin_id' => $termin_id,
                    'termin' => $termin_opis,
                    'data_lotu' => $termin['data'],
                    'godzina_start' => $termin['godzina_start'],
                    'godzina_koniec' => $termin['godzina_koniec'],
                    'pilot_id' => $termin['pilot_id'],
                    'user_id' => $lot['user_id'],
                    'stary_status' => 'wolny',
                    'nowy_status' => 'zarezerwowany'
                ]
            ];

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);

            $wpdb->query('COMMIT');

            $this->invalidateRelatedCache($slot['data']);
            $godziny_wg_pilota = $this->getDayScheduleOptimized($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxWyszukajKlientowLoty() {
        $this->validateAdminRequest();

        if (!isset($_GET['q'])) {
            wp_send_json_error('Brak frazy wyszukiwania.');
        }

        $search = sanitize_text_field($_GET['q']);
        if (strlen($search) < 2) {
            wp_send_json_success([]);
        }

        $cache_key = "search_clients_" . md5($search);
        
        $results = $this->getCachedOrExecute($cache_key, function() use ($search) {
            global $wpdb;
            
            $users = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT u.ID, u.display_name, u.user_login 
                 FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->prefix}srl_zakupione_loty zl ON u.ID = zl.user_id
                 WHERE zl.status = 'wolny' 
                 AND zl.data_waznosci >= CURDATE()
                 AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)
                 ORDER BY u.display_name
                 LIMIT 10",
                '%' . $search . '%',
                '%' . $search . '%', 
                '%' . $search . '%'
            ));

            $result = [];
            foreach ($users as $user) {
                $result[] = [
                    'id' => $user->ID,
                    'nazwa' => $user->display_name . ' (' . $user->user_login . ')'
                ];
            }

            return $result;
        }, 180);

        wp_send_json_success($results);
    }

    public function ajaxDodajVoucherRecznie() {
        $this->validateAdminRequest();
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela'") == $tabela;
        if (!$table_exists) {
            wp_send_json_error('Tabela voucherów nie istnieje.');
        }
        
        $kod_vouchera = strtoupper(sanitize_text_field($_POST['kod_vouchera']));
        $data_waznosci = sanitize_text_field($_POST['data_waznosci']);
        $nazwa_produktu = sanitize_text_field($_POST['nazwa_produktu']) ?: 'Voucher na lot tandemowy';
        $buyer_imie = sanitize_text_field($_POST['buyer_imie']);
        $buyer_nazwisko = sanitize_text_field($_POST['buyer_nazwisko']);
        $ma_filmowanie = intval($_POST['ma_filmowanie']) ? 1 : 0;
        $ma_akrobacje = intval($_POST['ma_akrobacje']) ? 1 : 0;
        
        $validation_kod = SRL_Helpers::getInstance()->walidujKodVouchera($kod_vouchera);
        if (!$validation_kod['valid']) {
            wp_send_json_error($validation_kod['message']);
        }
        
        $validation_data = SRL_Helpers::getInstance()->walidujDate($data_waznosci);
        if (!$validation_data['valid']) {
            wp_send_json_error('Nieprawidłowa data ważności: ' . $validation_data['message']);
        }
        
        if (SRL_Helpers::getInstance()->isDatePast($data_waznosci)) {
            wp_send_json_error('Data ważności nie może być z przeszłości.');
        }
        
        if (empty($buyer_imie) || empty($buyer_nazwisko)) {
            wp_send_json_error('Imię i nazwisko kupującego są wymagane.');
        }
        
        $existing = SRL_Database_Helpers::getInstance()->executeQuery(
            "SELECT COUNT(*) FROM $tabela WHERE kod_vouchera = %s",
            [$validation_kod['kod']],
            'count'
        );
        
        if ($existing > 0) {
            wp_send_json_error('Voucher z tym kodem już istnieje.');
        }
        
        $current_user = wp_get_current_user();
        $data_zakupu = current_time('mysql');

        $result = $wpdb->insert(
            $tabela,
            [
                'order_item_id' => 0,
                'order_id' => 0,
                'buyer_user_id' => $current_user->ID,
                'buyer_imie' => $current_user->first_name ?: 'Admin',
                'buyer_nazwisko' => $current_user->last_name ?: 'Manual',
                'nazwa_produktu' => $nazwa_produktu,
                'kod_vouchera' => $validation_kod['kod'],
                'status' => 'do_wykorzystania',
                'data_zakupu' => $data_zakupu,
                'data_waznosci' => $data_waznosci,
                'ma_filmowanie' => $ma_filmowanie,
                'ma_akrobacje' => $ma_akrobacje
            ],
            ['%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d']
        );
        
        if ($result !== false) {
            $opcje_text = '';
            if ($ma_filmowanie || $ma_akrobacje) {
                $opcje = [];
                if ($ma_filmowanie) $opcje[] = 'filmowanie';
                if ($ma_akrobacje) $opcje[] = 'akrobacje';
                $opcje_text = ' z opcjami: ' . implode(', ', $opcje);
            }
            
            wp_send_json_success('Voucher został dodany pomyślnie' . $opcje_text . '.');
        } else {
            wp_send_json_error('Błąd podczas dodawania vouchera do bazy danych.');
        }
    }

    public function ajaxWyszukajDostepnychKlientow() {
        $this->validateAdminRequest();

        $query = sanitize_text_field($_POST['query']);
        if (strlen($query) < 2) {
            wp_send_json_success([]);
        }

        $cache_key = "available_clients_" . md5($query);
        
        $results = $this->getCachedOrExecute($cache_key, function() use ($query) {
            global $wpdb;
            
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT zl.id as lot_id, zl.nazwa_produktu, zl.user_id,
                        u.user_email, u.display_name,
                        CONCAT(u.display_name, ' (', u.user_email, ')') as nazwa
                 FROM {$wpdb->prefix}srl_zakupione_loty zl
                 INNER JOIN {$wpdb->users} u ON zl.user_id = u.ID
                 WHERE zl.status = 'wolny' 
                 AND zl.data_waznosci >= CURDATE()
                 AND (u.user_email LIKE %s 
                      OR u.display_name LIKE %s 
                      OR zl.id LIKE %s
                      OR get_user_meta(u.ID, 'srl_telefon', true) LIKE %s)
                 ORDER BY u.display_name
                 LIMIT 20",
                '%' . $query . '%',
                '%' . $query . '%',
                '%' . $query . '%',
                '%' . $query . '%'
            ), ARRAY_A);

            $result = [];
            foreach ($results as $row) {
                $telefon = get_user_meta($row['user_id'], 'srl_telefon', true);
                $result[] = [
                    'lot_id' => $row['lot_id'],
                    'user_id' => $row['user_id'],
                    'nazwa' => $row['display_name'] . ' (' . $row['user_email'] . ')' . ($telefon ? ' - ' . $telefon : ''),
                    'produkt' => $row['nazwa_produktu']
                ];
            }

            return $result;
        }, 180);

        wp_send_json_success($results);
    }

    public function ajaxZapiszDanePrywatne() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $this->savePrivateFlightData($termin_id, $_POST, 'Prywatny');
    }

    public function ajaxZapiszLotPrywatny() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $this->savePrivateFlightData($termin_id, $_POST, 'Prywatny');
    }

    private function savePrivateFlightData($termin_id, $post_data, $status) {
        $imie = sanitize_text_field($post_data['imie']);
        $nazwisko = sanitize_text_field($post_data['nazwisko']);
        $rok_urodzenia = intval($post_data['rok_urodzenia']);
        $telefon = sanitize_text_field($post_data['telefon']);
        $sprawnosc_fizyczna = sanitize_text_field($post_data['sprawnosc_fizyczna']);
        $kategoria_wagowa = sanitize_text_field($post_data['kategoria_wagowa']);
        $uwagi = sanitize_textarea_field($post_data['uwagi']);

        if (empty($imie) || empty($nazwisko) || empty($telefon) || $rok_urodzenia < 1920) {
            wp_send_json_error('Wypełnij wszystkie wymagane pola.');
        }

        $validation = SRL_Helpers::getInstance()->walidujTelefon($telefon);
        if (!$validation['valid']) {
            wp_send_json_error($validation['message']);
        }

        $dane_pasazera = [
            'imie' => $imie,
            'nazwisko' => $nazwisko,
            'rok_urodzenia' => $rok_urodzenia,
            'telefon' => $telefon,
            'sprawnosc_fizyczna' => $sprawnosc_fizyczna,
            'kategoria_wagowa' => $kategoria_wagowa,
            'uwagi' => $uwagi,
            'typ' => 'prywatny'
        ];

        global $wpdb;
        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT data FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        $result = $wpdb->update(
            $wpdb->prefix . 'srl_terminy',
            [
                'status' => $status,
                'notatka' => json_encode($dane_pasazera)
            ],
            ['id' => $termin_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($result !== false) {
            $this->invalidateRelatedCache($slot['data']);
            $godziny_wg_pilota = $this->getDayScheduleOptimized($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);
        } else {
            wp_send_json_error('Błąd zapisu do bazy danych.');
        }
    }

    public function ajaxPobierzDanePrywatne() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $cache_key = "private_data_{$termin_id}";

        $dane = $this->getCachedOrExecute($cache_key, function() use ($termin_id) {
            global $wpdb;
            
            $notatka = $wpdb->get_var($wpdb->prepare(
                "SELECT notatka FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ));

            if ($notatka) {
                $dane = json_decode($notatka, true);
                if ($dane && is_array($dane)) {
                    return $dane;
                }
            }
            
            return null;
        }, 600);

        if ($dane) {
            wp_send_json_success($dane);
        } else {
            wp_send_json_error('Brak danych.');
        }
    }

    public function ajaxPobierzAktualneGodziny() {
        $this->validateAdminRequest();

        $data = sanitize_text_field($_GET['data'] ?? $_POST['data']);
        if (!$data) {
            wp_send_json_error('Brak daty.');
        }

        $godziny_wg_pilota = $this->getDayScheduleOptimized($data);
        wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);
    }

    public function ajaxWyszukajWolneLoty() {
        $this->validateAdminRequest();

        $search_field = sanitize_text_field($_POST['search_field']);
        $query = sanitize_text_field($_POST['query']);

        if (strlen($query) < 2) {
            wp_send_json_success([]);
        }

        $cache_key = "free_flights_" . md5($search_field . $query);
        
        $results = $this->getCachedOrExecute($cache_key, function() use ($search_field, $query) {
            global $wpdb;
            
            $where_conditions = ["zl.status = 'wolny'", "zl.data_waznosci >= CURDATE()"];
            $where_params = [];

            $field_mapping = [
                'id_lotu' => "zl.id = %s",
                'id_zamowienia' => "zl.order_id = %s",
                'email' => "u.user_email LIKE %s",
                'telefon' => "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s)",
                'imie_nazwisko' => "(zl.imie LIKE %s OR zl.nazwisko LIKE %s OR CONCAT(zl.imie, ' ', zl.nazwisko) LIKE %s)",
                'login' => "u.user_login LIKE %s"
            ];

            if (isset($field_mapping[$search_field])) {
                $where_conditions[] = $field_mapping[$search_field];
                
                if ($search_field === 'imie_nazwisko') {
                    $search_param = '%' . $query . '%';
                    $where_params = [$search_param, $search_param, $search_param];
                } elseif (in_array($search_field, ['email', 'telefon', 'login'])) {
                    $where_params[] = '%' . $query . '%';
                } else {
                    $where_params[] = $query;
                }
            } else {
                $where_conditions[] = "(zl.id LIKE %s OR zl.order_id LIKE %s OR zl.imie LIKE %s OR zl.nazwisko LIKE %s OR zl.nazwa_produktu LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s))";
                $search_param = '%' . $query . '%';
                $where_params = [$query, $query, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param];
            }

            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

            return $wpdb->get_results($wpdb->prepare(
                "SELECT zl.id as lot_id, zl.order_id, zl.user_id, zl.imie, zl.nazwisko, 
                        CONCAT(zl.imie, ' ', zl.nazwisko) as klient_nazwa,
                        u.user_email as email,
                        (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = zl.user_id AND meta_key = 'srl_telefon') as telefon
                 FROM {$wpdb->prefix}srl_zakupione_loty zl
                 INNER JOIN {$wpdb->users} u ON zl.user_id = u.ID
                 $where_clause
                 ORDER BY zl.data_zakupu DESC
                 LIMIT 20",
                ...$where_params
            ), ARRAY_A);
        }, 300);

        wp_send_json_success($results);
    }

    public function ajaxPobierzHistorieLotu() {
        $this->validateAdminRequest();

        $lot_id = intval($_POST['lot_id']);
        if (!$lot_id) {
            wp_send_json_error('Nieprawidłowe ID lotu.');
        }

        $historia = SRL_Historia_Functions::getInstance()->ajaxPobierzHistorieLotu($lot_id);
        wp_send_json_success($historia);
    }

    public function ajaxPrzywrocRezerwacje() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        if (!$termin_id) {
            wp_send_json_error('Nieprawidłowe ID terminu.');
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Odwołany przez organizatora'",
                $termin_id
            ), ARRAY_A);

            if (!$slot) {
                throw new Exception('Slot nie istnieje lub nie jest odwołany.');
            }

            $dane_historyczne = json_decode($slot['notatka'], true);
            if (!$dane_historyczne || !isset($dane_historyczne['lot_id'])) {
                throw new Exception('Brak danych do przywrócenia rezerwacji.');
            }

            $lot_id = $dane_historyczne['lot_id'];
            $klient_id = $dane_historyczne['klient_id'];

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d AND user_id = %d",
                $lot_id, $klient_id
            ), ARRAY_A);

            if (!$lot || $lot['status'] !== 'wolny') {
                throw new Exception('Lot nie jest dostępny do przywrócenia.');
            }

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                [
                    'status' => 'Zarezerwowany',
                    'notatka' => null 
                ],
                ['id' => $termin_id],
                ['%s', '%s'],
                ['%d']
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                [
                    'status' => 'zarezerwowany',
                    'termin_id' => $termin_id,
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime()
                ],
                ['id' => $lot_id],
                ['%s', '%d', '%s'],
                ['%d']
            );

            $user_data = $this->cache_manager->getUserData($klient_id);
            if ($user_data) {
                $szczegoly_terminu = $slot['data'] . ' ' . substr($slot['godzina_start'], 0, 5) . '-' . substr($slot['godzina_koniec'], 0, 5);
                $this->sendRestorationEmail($user_data, $szczegoly_terminu);
                $this->addRestorationHistoryEntry($lot_id, $termin_id, $szczegoly_terminu, $klient_id);
            }

            $wpdb->query('COMMIT');
            
            $this->invalidateRelatedCache($slot['data']);
            $godziny_wg_pilota = $this->getDayScheduleOptimized($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $godziny_wg_pilota]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Błąd przywracania: ' . $e->getMessage());
        }
    }

    private function sendRestorationEmail($user_data, $szczegoly_terminu) {
        $to = $user_data['email'];
        $subject = 'Twój lot tandemowy został przywrócony';
        $body = "Dzień dobry {$user_data['display_name']},\n\n"
             . "Mamy dobrą wiadomość! Twój lot na {$szczegoly_terminu} został przywrócony.\n\n"
             . "Możesz się już cieszyć na nadchodzący lot!\n\n"
             . "Pozdrawiamy,\nZespół Loty Tandemowe";
        wp_mail($to, $subject, $body);
    }

    private function addRestorationHistoryEntry($lot_id, $termin_id, $szczegoly_terminu, $klient_id) {
        $wpis_historii = [
            'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
            'opis' => "Rezerwacja przywrócona przez administratora - termin {$szczegoly_terminu}",
            'typ' => 'przywrocenie_przez_admin',
            'executor' => 'Admin',
            'szczegoly' => [
                'termin_id' => $termin_id,
                'przywrocony_termin' => $szczegoly_terminu,
                'klient_id' => $klient_id,
                'email_wyslany' => true,
                'powod' => 'Przywrócenie po odwołaniu przez organizatora'
            ]
        ];

        SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);
    }

    public function ajaxPobierzDaneOdwolanego() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $cache_key = "cancelled_data_{$termin_id}";

        $dane = $this->getCachedOrExecute($cache_key, function() use ($termin_id) {
            global $wpdb;
            
            $notatka = $wpdb->get_var($wpdb->prepare(
                "SELECT notatka FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Odwołany przez organizatora'",
                $termin_id
            ));

            if ($notatka) {
                $dane = json_decode($notatka, true);
                if ($dane && is_array($dane)) {
                    return $dane;
                }
            }
            
            return null;
        }, 600);

        if ($dane) {
            wp_send_json_success($dane);
        } else {
            wp_send_json_error('Brak danych odwołania.');
        }
    }

    public function ajaxPobierzDostepneTerminyDoZmiany() {
        $this->validateAdminRequest();

        $termin_id = intval($_POST['termin_id']);
        $cache_key = "available_terms_change_{$termin_id}";

        $data = $this->getCachedOrExecute($cache_key, function() use ($termin_id) {
            global $wpdb;
            
            $aktualny_termin = $wpdb->get_row($wpdb->prepare(
                "SELECT t.*, zl.id as lot_id, zl.user_id, zl.imie, zl.nazwisko
                 FROM {$wpdb->prefix}srl_terminy t
                 LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
                 WHERE t.id = %d",
                $termin_id
            ), ARRAY_A);

            if (!$aktualny_termin || $aktualny_termin['status'] !== 'Zarezerwowany') {
                throw new Exception('Można zmieniać tylko zarezerwowane terminy.');
            }

            $data_od = date('Y-m-d');
            $data_do = date('Y-m-d', strtotime('+90 days'));

            $dostepne_terminy = $wpdb->get_results($wpdb->prepare(
                "SELECT id, data, pilot_id, godzina_start, godzina_koniec,
                        TIMESTAMPDIFF(MINUTE, godzina_start, godzina_koniec) as czas_trwania
                 FROM {$wpdb->prefix}srl_terminy 
                 WHERE status = 'Wolny' 
                 AND data BETWEEN %s AND %s
                 AND data >= CURDATE()
                 AND id != %d
                 ORDER BY data ASC, godzina_start ASC",
                $data_od, $data_do, $termin_id
            ), ARRAY_A);

            $dostepne_dni = [];
            foreach ($dostepne_terminy as $termin) {
                $data = $termin['data'];
                if (!isset($dostepne_dni[$data])) {
                    $dostepne_dni[$data] = [];
                }
                $dostepne_dni[$data][] = $termin;
            }

            $klient_nazwa = '';
            if ($aktualny_termin['imie'] && $aktualny_termin['nazwisko']) {
                $klient_nazwa = $aktualny_termin['imie'] . ' ' . $aktualny_termin['nazwisko'];
            } elseif ($aktualny_termin['user_id']) {
                $user = get_userdata($aktualny_termin['user_id']);
                if ($user) {
                    $klient_nazwa = $user->display_name;
                }
            }

            return [
                'dostepne_dni' => $dostepne_dni,
                'aktualny_termin' => [
                    'id' => $aktualny_termin['id'],
                    'data' => $aktualny_termin['data'],
                    'godzina_start' => $aktualny_termin['godzina_start'],
                    'godzina_koniec' => $aktualny_termin['godzina_koniec'],
                    'pilot_id' => $aktualny_termin['pilot_id'],
                    'klient_nazwa' => $klient_nazwa,
                    'lot_id' => $aktualny_termin['lot_id']
                ]
            ];
        }, 300);

        wp_send_json_success($data);
    }

    public function ajaxZmienTerminLotu() {
        $this->validateAdminRequest();

        $stary_termin_id = intval($_POST['stary_termin_id']);
        $nowy_termin_id = intval($_POST['nowy_termin_id']);

        if (!$stary_termin_id || !$nowy_termin_id) {
            wp_send_json_error('Nieprawidłowe parametry.');
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $stary_termin = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Zarezerwowany'",
                $stary_termin_id
            ), ARRAY_A);

            if (!$stary_termin) {
                throw new Exception('Stary termin nie istnieje lub nie jest zarezerwowany.');
            }

            $nowy_termin = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Wolny'",
                $nowy_termin_id
            ), ARRAY_A);

            if (!$nowy_termin) {
                throw new Exception('Nowy termin nie istnieje lub nie jest dostępny.');
            }

            $nowy_datetime = $nowy_termin['data'] . ' ' . $nowy_termin['godzina_start'];
            if (strtotime($nowy_datetime) <= time()) {
                throw new Exception('Nowy termin musi być w przyszłości.');
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d",
                $stary_termin_id
            ), ARRAY_A);

            if (!$lot) {
                throw new Exception('Nie znaleziono lotu przypisanego do tego terminu.');
            }

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                [
                    'status' => 'Wolny',
                    'klient_id' => null
                ],
                ['id' => $stary_termin_id],
                ['%s', '%d'],
                ['%d']
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                [
                    'status' => 'Zarezerwowany',
                    'klient_id' => $stary_termin['klient_id']
                ],
                ['id' => $nowy_termin_id],
                ['%s', '%d'],
                ['%d']
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                [
                    'termin_id' => $nowy_termin_id,
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime()
                ],
                ['id' => $lot['id']],
                ['%d', '%s'],
                ['%d']
            );

            $stary_termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                $stary_termin['data'],
                substr($stary_termin['godzina_start'], 0, 5),
                substr($stary_termin['godzina_koniec'], 0, 5),
                $stary_termin['pilot_id']
            );

            $nowy_termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                $nowy_termin['data'],
                substr($nowy_termin['godzina_start'], 0, 5),
                substr($nowy_termin['godzina_koniec'], 0, 5),
                $nowy_termin['pilot_id']
            );

            $wpis_historii = [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'zmiana_terminu_admin',
                'executor' => 'Admin',
                'szczegoly' => [
                    'stary_termin_id' => $stary_termin_id,
                    'nowy_termin_id' => $nowy_termin_id,
                    'stary_termin' => $stary_termin_opis,
                    'nowy_termin' => $nowy_termin_opis,
                    'powod' => 'Zmiana terminu przez administratora',
                    'user_id' => $lot['user_id']
                ]
            ];

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], $wpis_historii);

            if ($lot['user_id']) {
                $user_data = $this->cache_manager->getUserData($lot['user_id']);
                if ($user_data) {
                    $this->sendTermChangeEmail($user_data, $stary_termin_opis, $nowy_termin_opis);
                }
            }

            $wpdb->query('COMMIT');

            $this->invalidateRelatedCache($stary_termin['data']);
            $this->invalidateRelatedCache($nowy_termin['data']);

            wp_send_json_success([
                'message' => 'Termin został pomyślnie zmieniony.',
                'stary_termin' => $stary_termin_opis,
                'nowy_termin' => $nowy_termin_opis
            ]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    private function sendTermChangeEmail($user_data, $stary_termin_opis, $nowy_termin_opis) {
        $subject = 'Zmiana terminu Twojego lotu tandemowego';
        $message = "Dzień dobry {$user_data['display_name']},\n\n";
        $message .= "Informujemy o zmianie terminu Twojego lotu tandemowego.\n\n";
        $message .= "Poprzedni termin: {$stary_termin_opis}\n";
        $message .= "Nowy termin: {$nowy_termin_opis}\n\n";
        $message .= "Pamiętaj:\n";
        $message .= "- Zgłoś się 30 minut przed godziną lotu\n";
        $message .= "- Weź ze sobą dokument tożsamości\n\n";
        $message .= "W razie pytań, skontaktuj się z nami.\n\n";
        $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";

        wp_mail($user_data['email'], $subject, $message);
    }
}