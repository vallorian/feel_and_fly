<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Admin_Ajax {
    private static $instance = null;
    private $cache_manager;
    private $db_helpers;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->cache_manager = SRL_Cache_Manager::getInstance();
        $this->db_helpers = SRL_Database_Helpers::getInstance();
        $this->initHooks();
    }

    private function initHooks() {
        $ajax_methods = [
            'srl_dodaj_godzine', 'srl_zmien_slot', 'srl_usun_godzine', 'srl_zmien_status_godziny',
            'srl_anuluj_lot_przez_organizatora', 'srl_wyszukaj_klientow_loty', 'srl_dodaj_voucher_recznie',
            'srl_wyszukaj_dostepnych_klientow', 'srl_przypisz_klienta_do_slotu', 'srl_zapisz_dane_prywatne',
            'srl_pobierz_dane_prywatne', 'srl_pobierz_aktualne_godziny', 'srl_wyszukaj_wolne_loty',
            'srl_przypisz_wykupiony_lot', 'srl_zapisz_lot_prywatny', 'srl_pobierz_historie_lotu',
            'srl_przywroc_rezerwacje', 'srl_pobierz_dane_odwolanego', 'srl_zrealizuj_lot',
            'srl_zrealizuj_lot_prywatny', 'srl_pobierz_dostepne_terminy_do_zmiany', 'srl_zmien_termin_lotu',
            'srl_admin_zmien_status_lotu', 'srl_pobierz_szczegoly_lotu'
        ];
        
        foreach ($ajax_methods as $method) {
            add_action("wp_ajax_{$method}", [$this, 'ajax' . $this->toCamelCase($method)]);
        }
    }

    private function toCamelCase($string) {
        return str_replace('_', '', ucwords(str_replace('srl_', '', $string), '_'));
    }

    private function validateAdminAccess() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
    }

    private function getSlotData($data) {
        $cache_key = "day_schedule_{$data}";
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached === false) {
            $cached = $this->db_helpers->getDayScheduleOptimized($data);
            wp_cache_set($cache_key, $cached, 'srl_cache', 900);
        }
        
        return $cached;
    }

    private function invalidateSlotCache($data) {
        wp_cache_delete("day_schedule_{$data}", 'srl_cache');
        $this->cache_manager->invalidateDayCache($data);
    }

    public function ajaxDodajGodzine() {
        $this->validateAdminAccess();
        
        $data = sanitize_text_field($_POST['data']);
        $pilot_id = intval($_POST['pilot_id']);
        $godzina_start = sanitize_text_field($_POST['godzina_start']) . ':00';
        $godzina_koniec = sanitize_text_field($_POST['godzina_koniec']) . ':00';
        $status = sanitize_text_field($_POST['status']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $pilot_id < 1 || $pilot_id > 4) {
            wp_send_json_error('Nieprawidłowe dane.');
        }

        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'srl_terminy',
            [
                'data' => $data,
                'pilot_id' => $pilot_id,
                'godzina_start' => $godzina_start,
                'godzina_koniec' => $godzina_koniec,
                'status' => $status,
                'klient_id' => 0
            ],
            ['%s','%d','%s','%s','%s','%d']
        );

        if ($result === false) {
            wp_send_json_error('Nie udało się zapisać slotu: ' . $wpdb->last_error);
        }

        $this->invalidateSlotCache($data);
        wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($data)]);
    }

    public function ajaxZmienSlot() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        $data = sanitize_text_field($_POST['data']);
        $godzina_start = sanitize_text_field($_POST['godzina_start']) . ':00';
        $godzina_koniec = sanitize_text_field($_POST['godzina_koniec']) . ':00';

        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'srl_terminy',
            ['godzina_start' => $godzina_start, 'godzina_koniec' => $godzina_koniec],
            ['id' => $termin_id],
            ['%s','%s'], ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Błąd aktualizacji: ' . $wpdb->last_error);
        }

        $this->invalidateSlotCache($data);
        wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($data)]);
    }

    public function ajaxUsunGodzine() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        global $wpdb;
        
        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT data, status, klient_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje.');
        }

        if ($slot['status'] === 'Zarezerwowany' && intval($slot['klient_id']) > 0) {
            wp_send_json_error('Nie można usunąć zarezerwowanego slotu.');
        }

        $result = $wpdb->delete($wpdb->prefix . 'srl_terminy', ['id' => $termin_id], ['%d']);
        
        if ($result === false) {
            wp_send_json_error('Błąd usuwania: ' . $wpdb->last_error);
        }

        $this->invalidateSlotCache($slot['data']);
        wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);
    }

    public function ajaxZmienStatusGodziny() {
        $this->validateAdminAccess();
        
        if ($_POST['status'] === 'Odwołany przez organizatora') {
            return $this->ajaxAnulujLotPrzezOrganizatora();
        }

        $termin_id = intval($_POST['termin_id']);
        $status = sanitize_text_field($_POST['status']);
        $klient_id = intval($_POST['klient_id'] ?? 0);

        $allowed_statuses = ['Wolny', 'Prywatny', 'Zarezerwowany', 'Zrealizowany'];
        if (!in_array($status, $allowed_statuses)) {
            wp_send_json_error('Nieprawidłowy status.');
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d", $termin_id
            ), ARRAY_A);

            if (!$slot) throw new Exception('Slot nie istnieje.');

            $update_data = ['status' => $status, 'klient_id' => $klient_id ?: null];
            if ($status === 'Wolny') $update_data['notatka'] = null;

            $wpdb->update($wpdb->prefix . 'srl_terminy', $update_data, ['id' => $termin_id]);

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d", $termin_id
            ), ARRAY_A);

            if ($lot) {
                $this->updateFlightStatus($lot, $status, $wpdb);
            }

            $wpdb->query('COMMIT');
            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    private function updateFlightStatus($lot, $status, $wpdb) {
        $status_map = [
            'Wolny' => ['status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null],
            'Zarezerwowany' => ['status' => 'zarezerwowany'],
            'Zrealizowany' => ['status' => 'zrealizowany'],
            'Odwołany przez organizatora' => ['status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null]
        ];

        if (isset($status_map[$status])) {
            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                $status_map[$status],
                ['id' => $lot['id']]
            );

            if ($status !== $lot['status']) {
                SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], [
                    'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'typ' => 'zmiana_statusu_admin',
                    'executor' => 'Admin',
                    'szczegoly' => [
                        'stary_status' => $lot['status'],
                        'nowy_status' => $status_map[$status]['status'] ?? $status,
                        'zmiana_przez_admin' => true
                    ]
                ]);
            }
        }
    }

    public function ajaxAnulujLotPrzezOrganizatora() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d", $termin_id
            ), ARRAY_A);

            if (!$slot) throw new Exception('Slot nie istnieje.');

            $historical_data = $this->prepareHistoricalData($slot, $wpdb);
            
            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Odwołany przez organizatora', 'notatka' => json_encode($historical_data)],
                ['id' => $termin_id]
            );

            if ($slot['klient_id'] > 0) {
                $this->handleCancellationNotification($slot, $historical_data, $wpdb);
            }

            $wpdb->query('COMMIT');
            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    private function prepareHistoricalData($slot, $wpdb) {
        $data = [
            'typ' => 'odwolany_przez_organizatora',
            'data_odwolania' => SRL_Helpers::getInstance()->getCurrentDatetime(),
            'oryginalny_status' => $slot['status'],
            'klient_id' => $slot['klient_id']
        ];

        if ($slot['klient_id'] > 0) {
            $user_data = $this->cache_manager->getUserData($slot['klient_id']);
            if ($user_data) {
                $data = array_merge($data, [
                    'klient_email' => $user_data['email'],
                    'klient_nazwa' => $user_data['display_name'],
                    'telefon' => $user_data['telefon']
                ]);
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d", $slot['id']
            ), ARRAY_A);

            if ($lot) {
                $data['lot_id'] = $lot['id'];
                $data['order_id'] = $lot['order_id'];
            }
        }

        return $data;
    }

    private function handleCancellationNotification($slot, $historical_data, $wpdb) {
        if (!isset($historical_data['lot_id'])) return;

        $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            ['status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null],
            ['id' => $historical_data['lot_id']]
        );

        if (isset($historical_data['klient_email'])) {
            $termin_info = sprintf('%s %s-%s',
                $slot['data'],
                substr($slot['godzina_start'], 0, 5),
                substr($slot['godzina_koniec'], 0, 5)
            );

            wp_mail(
                $historical_data['klient_email'],
                'Twój lot tandemowy został odwołany',
                "Dzień dobry,\n\nTwój lot na {$termin_info} został odwołany przez organizatora.\nStatus lotu został przywrócony.\n\nPozdrawiamy"
            );

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($historical_data['lot_id'], [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'odwolanie_przez_organizatora',
                'executor' => 'Admin',
                'szczegoly' => [
                    'termin_id' => $slot['id'],
                    'odwolany_termin' => $termin_info,
                    'email_wyslany' => true
                ]
            ]);
        }
    }

    public function ajaxZrealizujLot() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ), ARRAY_A);

            if (!$slot || $slot['status'] !== 'Zarezerwowany') {
                throw new Exception('Slot nie istnieje lub nie jest zarezerwowany.');
            }

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Zrealizowany'],
                ['id' => $termin_id]
            );

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d",
                $termin_id
            ), ARRAY_A);

            if ($lot) {
                $wpdb->update(
                    $wpdb->prefix . 'srl_zakupione_loty',
                    ['status' => 'zrealizowany'],
                    ['id' => $lot['id']]
                );

                SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], [
                    'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'typ' => 'realizacja_admin',
                    'executor' => 'Admin',
                    'szczegoly' => [
                        'termin_id' => $termin_id,
                        'stary_status' => 'zarezerwowany',
                        'nowy_status' => 'zrealizowany'
                    ]
                ]);
            }

            $wpdb->query('COMMIT');
            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxZrealizujLotPrywatny() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        global $wpdb;

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ), ARRAY_A);

            if (!$slot || $slot['status'] !== 'Prywatny') {
                throw new Exception('Slot nie istnieje lub nie jest prywatny.');
            }

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Zrealizowany'],
                ['id' => $termin_id]
            );

            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxAdminZmienStatusLotu() {
        $this->validateAdminAccess();
        
        $lot_id = intval($_POST['lot_id']);
        $nowy_status = sanitize_text_field($_POST['nowy_status']);

        global $wpdb;
        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d", $lot_id
        ), ARRAY_A);

        if (!$lot) wp_send_json_error('Lot nie istnieje.');

        $wpdb->query('START TRANSACTION');

        try {
            $update_data = ['status' => $nowy_status];
            
            if ($lot['status'] === 'zarezerwowany' && $nowy_status === 'wolny' && $lot['termin_id']) {
                $wpdb->update(
                    $wpdb->prefix . 'srl_terminy',
                    ['status' => 'Wolny', 'klient_id' => null],
                    ['id' => $lot['termin_id']]
                );
                $update_data = array_merge($update_data, ['termin_id' => null, 'data_rezerwacji' => null]);
            }

            $wpdb->update($wpdb->prefix . 'srl_zakupione_loty', $update_data, ['id' => $lot_id]);

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'zmiana_statusu_admin',
                'executor' => 'Admin',
                'szczegoly' => [
                    'stary_status' => $lot['status'],
                    'nowy_status' => $nowy_status,
                    'zmiana_przez_admin' => true
                ]
            ]);

            $wpdb->query('COMMIT');
            wp_send_json_success("Status zmieniony z '{$lot['status']}' na '{$nowy_status}'.");

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxPobierzSzczegolyLotu() {
        $this->validateAdminAccess();
        
        $lot_id = intval($_POST['lot_id']);
        $user_id = intval($_POST['user_id']);

        $user_data = $this->cache_manager->getUserData($user_id);
        if (!$user_data) wp_send_json_error('Nie znaleziono danych użytkownika.');

        $lot = $this->db_helpers->getFlightById($lot_id);
        if (!$lot) wp_send_json_error('Nie znaleziono lotu.');

        $dane = array_merge($user_data, [
            'lot_id' => $lot_id,
            'nazwa_produktu' => $lot['nazwa_produktu'],
            'status' => $lot['status'],
            'data_zakupu' => $lot['data_zakupu'],
            'data_waznosci' => $lot['data_waznosci']
        ]);

        if ($user_data['rok_urodzenia']) {
            $dane['wiek'] = date('Y') - intval($user_data['rok_urodzenia']);
        }

        wp_send_json_success($dane);
    }

    public function ajaxWyszukajKlientowLoty() {
        $this->validateAdminAccess();
        
        $search = sanitize_text_field($_GET['q'] ?? '');
        if (strlen($search) < 2) {
            wp_send_json_success([]);
        }

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT u.ID, u.display_name, u.user_login 
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->prefix}srl_zakupione_loty zl ON u.ID = zl.user_id
             WHERE zl.status = 'wolny' AND zl.data_waznosci >= CURDATE()
             AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)
             ORDER BY u.display_name LIMIT 10",
            "%{$search}%", "%{$search}%", "%{$search}%"
        ));

        $wynik = array_map(function($user) {
            return ['id' => $user->ID, 'nazwa' => "{$user->display_name} ({$user->user_login})"];
        }, $results);

        wp_send_json_success($wynik);
    }

    public function ajaxWyszukajDostepnychKlientow() {
        $this->validateAdminAccess();
        
        $query = sanitize_text_field($_POST['query']);
        if (strlen($query) < 2) {
            wp_send_json_success([]);
        }

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.id as lot_id, zl.nazwa_produktu, zl.user_id,
                    u.user_email, u.display_name
             FROM {$wpdb->prefix}srl_zakupione_loty zl
             INNER JOIN {$wpdb->users} u ON zl.user_id = u.ID
             WHERE zl.status = 'wolny' AND zl.data_waznosci >= CURDATE()
             AND (u.user_email LIKE %s OR u.display_name LIKE %s OR zl.id LIKE %s)
             ORDER BY u.display_name LIMIT 20",
            "%{$query}%", "%{$query}%", "%{$query}%"
        ), ARRAY_A);

        $wynik = [];
        foreach ($results as $row) {
            $telefon = get_user_meta($row['user_id'], 'srl_telefon', true);
            $wynik[] = [
                'lot_id' => $row['lot_id'],
                'user_id' => $row['user_id'],
                'nazwa' => $row['display_name'] . ' (' . $row['user_email'] . ')' . ($telefon ? ' - ' . $telefon : ''),
                'produkt' => $row['nazwa_produktu']
            ];
        }

        wp_send_json_success($wynik);
    }

    public function ajaxWyszukajWolneLoty() {
        $this->validateAdminAccess();
        
        $search_field = sanitize_text_field($_POST['search_field']);
        $query = sanitize_text_field($_POST['query']);

        if (strlen($query) < 2) {
            wp_send_json_success([]);
        }

        global $wpdb;
        $where_conditions = ["zl.status = 'wolny'", "zl.data_waznosci >= CURDATE()"];
        $where_params = [];

        switch ($search_field) {
            case 'id_lotu':
                $where_conditions[] = "zl.id = %s";
                $where_params[] = $query;
                break;
            case 'id_zamowienia':
                $where_conditions[] = "zl.order_id = %s";
                $where_params[] = $query;
                break;
            case 'email':
                $where_conditions[] = "u.user_email LIKE %s";
                $where_params[] = '%' . $query . '%';
                break;
            case 'telefon':
                $where_conditions[] = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s)";
                $where_params[] = '%' . $query . '%';
                break;
            default:
                $where_conditions[] = "(zl.id LIKE %s OR zl.order_id LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)";
                $search_param = '%' . $query . '%';
                $where_params = array_merge($where_params, [$query, $query, $search_param, $search_param]);
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.id as lot_id, zl.order_id, zl.user_id, zl.imie, zl.nazwisko, 
                    CONCAT(zl.imie, ' ', zl.nazwisko) as klient_nazwa,
                    u.user_email as email
             FROM {$wpdb->prefix}srl_zakupione_loty zl
             INNER JOIN {$wpdb->users} u ON zl.user_id = u.ID
             $where_clause
             ORDER BY zl.data_zakupu DESC LIMIT 20",
            ...$where_params
        ), ARRAY_A);

        wp_send_json_success($results);
    }

    public function ajaxPrzypiszKlientaDoSlotu() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        $lot_id = intval($_POST['lot_id']);

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Wolny'",
                $termin_id
            ), ARRAY_A);

            if (!$slot) throw new Exception('Slot nie jest dostępny.');

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d AND status = 'wolny'",
                $lot_id
            ), ARRAY_A);

            if (!$lot) throw new Exception('Lot nie jest dostępny.');

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Zarezerwowany', 'klient_id' => $lot['user_id']],
                ['id' => $termin_id]
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                [
                    'status' => 'zarezerwowany',
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'termin_id' => $termin_id
                ],
                ['id' => $lot_id]
            );

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'przypisanie_admin',
                'executor' => 'Admin',
                'szczegoly' => [
                    'termin_id' => $termin_id,
                    'user_id' => $lot['user_id'],
                    'stary_status' => 'wolny',
                    'nowy_status' => 'zarezerwowany'
                ]
            ]);

            $wpdb->query('COMMIT');
            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxPrzypiszWykupionyLot() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        $lot_id = intval($_POST['lot_id']);

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Wolny'",
                $termin_id
            ), ARRAY_A);

            if (!$slot) throw new Exception('Slot nie jest dostępny.');

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d AND status = 'wolny'",
                $lot_id
            ), ARRAY_A);

            if (!$lot) throw new Exception('Lot nie jest dostępny.');

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Zarezerwowany', 'klient_id' => $lot['user_id']],
                ['id' => $termin_id]
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                [
                    'status' => 'zarezerwowany',
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'termin_id' => $termin_id
                ],
                ['id' => $lot_id]
            );

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'przypisanie_admin',
                'executor' => 'Admin',
                'szczegoly' => [
                    'termin_id' => $termin_id,
                    'user_id' => $lot['user_id'],
                    'stary_status' => 'wolny',
                    'nowy_status' => 'zarezerwowany'
                ]
            ]);

            $wpdb->query('COMMIT');
            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxZapiszDanePrywatne() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        $imie = sanitize_text_field($_POST['imie']);
        $nazwisko = sanitize_text_field($_POST['nazwisko']);
        $rok_urodzenia = intval($_POST['rok_urodzenia']);
        $telefon = sanitize_text_field($_POST['telefon']);
        $sprawnosc_fizyczna = sanitize_text_field($_POST['sprawnosc_fizyczna']);
        $kategoria_wagowa = sanitize_text_field($_POST['kategoria_wagowa']);
        $uwagi = sanitize_textarea_field($_POST['uwagi']);

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
                'status' => 'Prywatny',
                'notatka' => json_encode($dane_pasazera)
            ],
            ['id' => $termin_id]
        );

        if ($result !== false) {
            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);
        } else {
            wp_send_json_error('Błąd zapisu do bazy danych.');
        }
    }

    public function ajaxZapiszLotPrywatny() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        $imie = sanitize_text_field($_POST['imie']);
        $nazwisko = sanitize_text_field($_POST['nazwisko']);
        $rok_urodzenia = intval($_POST['rok_urodzenia']);
        $telefon = sanitize_text_field($_POST['telefon']);
        $sprawnosc_fizyczna = sanitize_text_field($_POST['sprawnosc_fizyczna']);
        $kategoria_wagowa = sanitize_text_field($_POST['kategoria_wagowa']);
        $uwagi = sanitize_textarea_field($_POST['uwagi']);

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
                'status' => 'Prywatny',
                'notatka' => json_encode($dane_pasazera)
            ],
            ['id' => $termin_id]
        );

        if ($result !== false) {
            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);
        } else {
            wp_send_json_error('Błąd zapisu do bazy danych.');
        }
    }

    public function ajaxPobierzDanePrywatne() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);

        global $wpdb;
        $notatka = $wpdb->get_var($wpdb->prepare(
            "SELECT notatka FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ));

        if ($notatka) {
            $dane = json_decode($notatka, true);
            if ($dane && is_array($dane)) {
                wp_send_json_success($dane);
            } else {
                wp_send_json_error('Nieprawidłowe dane.');
            }
        } else {
            wp_send_json_error('Brak danych.');
        }
    }

    public function ajaxPobierzAktualneGodziny() {
        $this->validateAdminAccess();
        
        $data = sanitize_text_field($_GET['data'] ?? $_POST['data'] ?? '');
        if (!$data) wp_send_json_error('Brak daty.');

        wp_send_json_success($this->getSlotData($data));
    }

    public function ajaxPobierzHistorieLotu() {
        $this->validateAdminAccess();
        
        $lot_id = intval($_POST['lot_id']);
        if (!$lot_id) wp_send_json_error('Nieprawidłowe ID lotu.');

        $historia = SRL_Historia_Functions::getInstance()->ajaxPobierzHistorieLotu($lot_id);
        wp_send_json_success($historia);
    }

    public function ajaxPrzywrocRezerwacje() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);
        if (!$termin_id) wp_send_json_error('Nieprawidłowe ID terminu.');

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Odwołany przez organizatora'",
                $termin_id
            ), ARRAY_A);

            if (!$slot) throw new Exception('Slot nie istnieje lub nie jest odwołany.');

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
                ['status' => 'Zarezerwowany', 'notatka' => null],
                ['id' => $termin_id]
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                [
                    'status' => 'zarezerwowany',
                    'termin_id' => $termin_id,
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime()
                ],
                ['id' => $lot_id]
            );

            $user = get_userdata($klient_id);
            if ($user) {
                $szczegoly_terminu = $slot['data'] . ' ' . substr($slot['godzina_start'], 0, 5) . '-' . substr($slot['godzina_koniec'], 0, 5);

                wp_mail(
                    $user->user_email,
                    'Twój lot tandemowy został przywrócony',
                    "Dzień dobry {$user->display_name},\n\nTwój lot na {$szczegoly_terminu} został przywrócony.\n\nPozdrawiamy"
                );

                SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
                    'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'typ' => 'przywrocenie_przez_admin',
                    'executor' => 'Admin',
                    'szczegoly' => [
                        'termin_id' => $termin_id,
                        'przywrocony_termin' => $szczegoly_terminu,
                        'email_wyslany' => true
                    ]
                ]);
            }

            $wpdb->query('COMMIT');
            $this->invalidateSlotCache($slot['data']);
            wp_send_json_success(['godziny_wg_pilota' => $this->getSlotData($slot['data'])]);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxPobierzDaneOdwolanego() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);

        global $wpdb;
        $notatka = $wpdb->get_var($wpdb->prepare(
            "SELECT notatka FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Odwołany przez organizatora'",
            $termin_id
        ));

        if ($notatka) {
            $dane = json_decode($notatka, true);
            if ($dane && is_array($dane)) {
                wp_send_json_success($dane);
            } else {
                wp_send_json_error('Nieprawidłowe dane.');
            }
        } else {
            wp_send_json_error('Brak danych odwołania.');
        }
    }

    public function ajaxPobierzDostepneTerminyDoZmiany() {
        $this->validateAdminAccess();
        
        $termin_id = intval($_POST['termin_id']);

        global $wpdb;
        $aktualny_termin = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, zl.id as lot_id, zl.user_id, zl.imie, zl.nazwisko
             FROM {$wpdb->prefix}srl_terminy t
             LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
             WHERE t.id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$aktualny_termin || $aktualny_termin['status'] !== 'Zarezerwowany') {
            wp_send_json_error('Można zmieniać tylko zarezerwowane terminy.');
        }

        $data_od = date('Y-m-d');
        $data_do = date('Y-m-d', strtotime('+90 days'));

        $dostepne_terminy = $wpdb->get_results($wpdb->prepare(
            "SELECT id, data, pilot_id, godzina_start, godzina_koniec
             FROM {$wpdb->prefix}srl_terminy 
             WHERE status = 'Wolny' AND data BETWEEN %s AND %s
             AND data >= CURDATE() AND id != %d
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
            if ($user) $klient_nazwa = $user->display_name;
        }

        wp_send_json_success([
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
        ]);
    }

    public function ajaxZmienTerminLotu() {
        $this->validateAdminAccess();
        
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

            if (!$stary_termin) throw new Exception('Stary termin nie istnieje lub nie jest zarezerwowany.');

            $nowy_termin = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Wolny'",
                $nowy_termin_id
            ), ARRAY_A);

            if (!$nowy_termin) throw new Exception('Nowy termin nie istnieje lub nie jest dostępny.');

            $nowy_datetime = $nowy_termin['data'] . ' ' . $nowy_termin['godzina_start'];
            if (strtotime($nowy_datetime) <= time()) {
                throw new Exception('Nowy termin musi być w przyszłości.');
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d",
                $stary_termin_id
            ), ARRAY_A);

            if (!$lot) throw new Exception('Nie znaleziono lotu przypisanego do tego terminu.');

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Wolny', 'klient_id' => null],
                ['id' => $stary_termin_id]
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Zarezerwowany', 'klient_id' => $stary_termin['klient_id']],
                ['id' => $nowy_termin_id]
            );

            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                [
                    'termin_id' => $nowy_termin_id,
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime()
                ],
                ['id' => $lot['id']]
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

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'zmiana_terminu_admin',
                'executor' => 'Admin',
                'szczegoly' => [
                    'stary_termin_id' => $stary_termin_id,
                    'nowy_termin_id' => $nowy_termin_id,
                    'stary_termin' => $stary_termin_opis,
                    'nowy_termin' => $nowy_termin_opis,
                    'user_id' => $lot['user_id']
                ]
            ]);

            if ($lot['user_id']) {
                $user = get_userdata($lot['user_id']);
                if ($user) {
                    wp_mail(
                        $user->user_email,
                        'Zmiana terminu Twojego lotu tandemowego',
                        "Dzień dobry {$user->display_name},\n\nTwój lot został przeniesiony:\nZ: {$stary_termin_opis}\nNa: {$nowy_termin_opis}\n\nPozdrawiamy"
                    );
                }
            }

            $wpdb->query('COMMIT');
            $this->invalidateSlotCache($stary_termin['data']);
            $this->invalidateSlotCache($nowy_termin['data']);

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

    public function ajaxDodajVoucherRecznie() {
        $this->validateAdminAccess();
        
        $required_fields = ['kod_vouchera', 'data_waznosci', 'nazwa_produktu'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error("Pole {$field} jest wymagane.");
            }
        }

        $kod = strtoupper(sanitize_text_field($_POST['kod_vouchera']));
        $validation = SRL_Helpers::getInstance()->walidujKodVouchera($kod);
        if (!$validation['valid']) wp_send_json_error($validation['message']);

        $data_waznosci = sanitize_text_field($_POST['data_waznosci']);
        $date_validation = SRL_Helpers::getInstance()->walidujDate($data_waznosci);
        if (!$date_validation['valid']) wp_send_json_error($date_validation['message']);

        if (SRL_Helpers::getInstance()->isDatePast($data_waznosci)) {
            wp_send_json_error('Data ważności nie może być z przeszłości.');
        }

        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}srl_vouchery_upominkowe WHERE kod_vouchera = %s",
            $validation['kod']
        ));

        if ($existing > 0) wp_send_json_error('Voucher już istnieje.');

        $current_user = wp_get_current_user();
        $result = $wpdb->insert(
            $wpdb->prefix . 'srl_vouchery_upominkowe',
            [
                'order_item_id' => 0,
                'order_id' => 0,
                'buyer_user_id' => $current_user->ID,
                'buyer_imie' => $current_user->first_name ?: 'Admin',
                'buyer_nazwisko' => $current_user->last_name ?: 'Manual',
                'nazwa_produktu' => sanitize_text_field($_POST['nazwa_produktu']),
                'kod_vouchera' => $validation['kod'],
                'status' => 'do_wykorzystania',
                'data_zakupu' => current_time('mysql'),
                'data_waznosci' => $data_waznosci,
                'ma_filmowanie' => intval($_POST['ma_filmowanie'] ?? 0),
                'ma_akrobacje' => intval($_POST['ma_akrobacje'] ?? 0)
            ]
        );

        if ($result !== false) {
            wp_send_json_success('Voucher dodany pomyślnie.');
        } else {
            wp_send_json_error('Błąd dodawania vouchera.');
        }
    }
}