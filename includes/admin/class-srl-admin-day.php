<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Admin_Day {
    private static $instance = null;
    private $slot_manager;
    private $cache_manager;
    private $db_helpers;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->slot_manager = SRL_Slot_Manager::getInstance();
        $this->cache_manager = SRL_Cache_Manager::getInstance();
        $this->db_helpers = SRL_Database_Helpers::getInstance();
    }

    public static function wyswietlDzien() {
        self::getInstance()->displayDay();
    }

    public function displayDay() {
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        $data = $this->validateAndSanitizeDate();
        $day_info = $this->getDayInfo($data);
        $schedule_data = $this->getScheduleData($data);
        
        echo '<div class="wrap">';
        echo '<h1>Planowanie godzin lotów – ' . esc_html($day_info['nazwa_dnia']) . ', ' . esc_html($data) . '</h1>';
        
        $this->renderNavigationControls($data);
        $this->renderScheduleControls($schedule_data);
        
        echo '<div id="srl-tabele-pilotow"></div>';
        $this->renderTimelineSection();
        echo '</div>';
        
        $this->enqueueScripts();
    }

    private function validateAndSanitizeDate() {
        if (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'])) {
            return sanitize_text_field($_GET['data']);
        }
        return date('Y-m-d');
    }

    private function getDayInfo($data) {
        $cache_key = "day_info_{$data}";
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $numer_dnia = date('N', strtotime($data));
        $dni_tyg = [
            1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek',
            5 => 'Piątek', 6 => 'Sobota', 7 => 'Niedziela'
        ];
        
        $info = ['nazwa_dnia' => $dni_tyg[$numer_dnia]];
        wp_cache_set($cache_key, $info, 'srl_cache', 3600);
        
        return $info;
    }

    private function getScheduleData($data) {
        $godziny_wg_pilota = $this->slot_manager->getDayScheduleOptimized($data);
        $ma_sloty = !empty($godziny_wg_pilota);
        
        $domyslna_liczba = 1;
        if ($ma_sloty) {
            $max_pid = max(array_keys($godziny_wg_pilota));
            $domyslna_liczba = max(1, min(4, $max_pid));
        }
        
        return [
            'godziny_wg_pilota' => $godziny_wg_pilota,
            'ma_sloty' => $ma_sloty,
            'domyslna_liczba' => $domyslna_liczba
        ];
    }

    private function renderNavigationControls($data) {
        $poprzedni_dzien = date('Y-m-d', strtotime($data . ' -1 day'));
        $nastepny_dzien = date('Y-m-d', strtotime($data . ' +1 day'));
        
        echo '<div style="margin-bottom:20px;">';
        echo SRL_Helpers::getInstance()->generateLink(add_query_arg('data', $poprzedni_dzien), '← Poprzedni dzień', 'button');
        echo '<input type="date" id="srl-wybierz-date" value="' . esc_attr($data) . '" style="margin:0 10px;" />';
        echo SRL_Helpers::getInstance()->generateLink(add_query_arg('data', $nastepny_dzien), 'Następny dzień →', 'button');
        echo '</div>';
    }

    private function renderScheduleControls($schedule_data) {
        $checked = $schedule_data['ma_sloty'] ? 'checked' : '';
        echo '<p><label><input type="checkbox" id="srl-planowane-godziny" ' . $checked . ' /> Czy zaplanować godziny tandemowe?</label></p>';

        $styl = $schedule_data['ma_sloty'] ? '' : 'display:none;';
        echo '<div id="srl-ustawienia-godzin" style="' . $styl . '">';

        echo '<p><label>Ile pilotów? ';
        echo SRL_Helpers::getInstance()->generateSelect('srl-liczba-pilotow', [
            1 => '1', 2 => '2', 3 => '3', 4 => '4'
        ], $schedule_data['domyslna_liczba'], ['id' => 'srl-liczba-pilotow']);
        echo '</label>';

        echo '<label>Interwał czasowy: ';
        echo SRL_Helpers::getInstance()->generateSelect('srl-interwal', [
            1 => '1 min', 5 => '5 min', 10 => '10 min', 15 => '15 min',
            20 => '20 min', 30 => '30 min', 45 => '45 min', 60 => '60 min'
        ], 15, ['id' => 'srl-interwal']);
        echo '</label></p>';

        $this->renderSlotGenerator($schedule_data['domyslna_liczba']);
        echo '</div>';
    }

    private function renderSlotGenerator($domyslna_liczba) {
        echo '<div style="margin-top:20px; margin-bottom:20px; padding:10px; border:1px solid #ccc; border-radius:4px; background:#f9f9f9;">';
        echo '<strong>Generuj sloty: </strong>';

        echo '<label>Pilot: <select id="srl-generuj-pilot">';
        for ($i = 1; $i <= intval($domyslna_liczba); $i++) {
            echo '<option value="' . $i . '">Pilot ' . $i . '</option>';
        }
        echo '</select></label>';

        echo '<label> Godzina od: <input type="time" id="srl-generuj-od" value="09:00" /></label> ';
        echo '<label> do: <input type="time" id="srl-generuj-do" value="18:00" /></label>';

        echo ' <button class="button button-secondary" id="srl-generuj-sloty">Generuj sloty</button>';
        echo '<span style="font-size:12px; color:#555; margin-left:10px;">(sloty powstają wg interwału)</span>';
        echo '</div>';
    }

    private function renderTimelineSection() {
        echo '<div id="srl-harmonogram-section" style="margin-top:40px; padding:20px; border:2px solid #4263be; border-radius:8px; background:#f8f9fa;">';
        echo '<h2 style="margin-top:0; color:#4263be; border-bottom:2px solid #4263be; padding-bottom:10px;">📅 Harmonogram czasowy dnia</h2>';
        echo '<div id="srl-harmonogram-container"></div>';
        echo '</div>';
    }

    private function enqueueScripts() {
        $data = $this->validateAndSanitizeDate();
        $godziny_wg_pilota = $this->slot_manager->getDayScheduleOptimized($data);
        
        $script_data = [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'adminUrl' => admin_url(),
            'nonce' => wp_create_nonce('srl_admin_nonce'),
            'data' => $data,
            'istniejaceGodziny' => $godziny_wg_pilota,
            'domyslnaLiczbaPilotow' => $this->calculateDefaultPilots($godziny_wg_pilota)
        ];
        
        echo '<script>window.srlAdmin = ' . json_encode($script_data) . ';</script>';
        echo '<script type="text/javascript" src="' . SRL_PLUGIN_URL . 'assets/js/admin-day.js"></script>';
    }

    private function calculateDefaultPilots($godziny_wg_pilota) {
        if (empty($godziny_wg_pilota)) return 1;
        $max_pid = max(array_keys($godziny_wg_pilota));
        return max(1, min(4, $max_pid));
    }

    public function generateSlotsBatch($data, $pilot_id, $start_time, $end_time, $interval) {
        $slots = $this->slot_manager->generateSlots($data, $pilot_id, $start_time, $end_time, $interval);
        
        $slots_data = [];
        foreach ($slots as $slot) {
            $slots_data[] = [
                'data' => $data,
                'pilot_id' => $pilot_id,
                'godzina_start' => $slot['start'],
                'godzina_koniec' => $slot['end'],
                'status' => 'Wolny'
            ];
        }
        
        return $this->slot_manager->batchCreateSlots($slots_data);
    }

    public function updateSlotStatus($termin_id, $new_status, $additional_data = []) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        
        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d", $termin_id
            ), ARRAY_A);

            if (!$slot) {
                throw new Exception('Slot nie istnieje.');
            }

            $update_data = array_merge(['status' => $new_status], $additional_data);
            $result = $this->slot_manager->updateSlot($termin_id, $update_data);

            if ($result === false) {
                throw new Exception('Błąd aktualizacji slotu.');
            }

            $this->updateRelatedFlight($slot, $new_status);
            
            $wpdb->query('COMMIT');
            return ['success' => true, 'slot' => $slot];
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function updateRelatedFlight($slot, $new_status) {
        if (!$slot['klient_id']) return;
        
        global $wpdb;
        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d", $slot['id']
        ), ARRAY_A);

        if (!$lot) return;

        $status_map = [
            'Wolny' => ['status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null],
            'Zarezerwowany' => ['status' => 'zarezerwowany'],
            'Zrealizowany' => ['status' => 'zrealizowany'],
            'Odwołany przez organizatora' => ['status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null]
        ];

        if (isset($status_map[$new_status])) {
            $this->db_helpers->updateFlightStatus($lot['id'], $status_map[$new_status]['status'], $status_map[$new_status]);

            if ($new_status !== $lot['status']) {
                SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], [
                    'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'typ' => 'zmiana_statusu_admin',
                    'executor' => 'Admin',
                    'szczegoly' => [
                        'stary_status' => $lot['status'],
                        'nowy_status' => $status_map[$new_status]['status'],
                        'zmiana_przez_admin' => true
                    ]
                ]);
            }
        }
    }

    public function handleCancellationByOrganizer($termin_id) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d", $termin_id
            ), ARRAY_A);

            if (!$slot) {
                throw new Exception('Slot nie istnieje.');
            }

            $historical_data = $this->prepareHistoricalData($slot);
            
            $this->slot_manager->updateSlot($termin_id, [
                'status' => 'Odwołany przez organizatora',
                'notatka' => json_encode($historical_data)
            ]);

            if ($slot['klient_id'] > 0) {
                $this->sendCancellationNotification($slot, $historical_data);
            }

            $wpdb->query('COMMIT');
            return ['success' => true];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function prepareHistoricalData($slot) {
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

            global $wpdb;
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

    private function sendCancellationNotification($slot, $historical_data) {
        if (!isset($historical_data['lot_id']) || !isset($historical_data['klient_email'])) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            ['status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null],
            ['id' => $historical_data['lot_id']]
        );

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

    public function restoreReservation($termin_id) {
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

            $this->slot_manager->updateSlot($termin_id, [
                'status' => 'Zarezerwowany',
                'notatka' => null
            ]);

            $wpdb->update(
                $wpdb->prefix . 'srl_zakupione_loty',
                [
                    'status' => 'zarezerwowany',
                    'termin_id' => $termin_id,
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime()
                ],
                ['id' => $dane_historyczne['lot_id']]
            );

            $this->sendRestorationNotification($slot, $dane_historyczne);

            $wpdb->query('COMMIT');
            return ['success' => true];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function sendRestorationNotification($slot, $dane_historyczne) {
        $user = get_userdata($dane_historyczne['klient_id']);
        if (!$user) return;

        $szczegoly_terminu = $slot['data'] . ' ' . substr($slot['godzina_start'], 0, 5) . '-' . substr($slot['godzina_koniec'], 0, 5);

        wp_mail(
            $user->user_email,
            'Twój lot tandemowy został przywrócony',
            "Dzień dobry {$user->display_name},\n\nTwój lot na {$szczegoly_terminu} został przywrócony.\n\nPozdrawiamy"
        );

        SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($dane_historyczne['lot_id'], [
            'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
            'typ' => 'przywrocenie_przez_admin',
            'executor' => 'Admin',
            'szczegoly' => [
                'termin_id' => $slot['id'],
                'przywrocony_termin' => $szczegoly_terminu,
                'email_wyslany' => true
            ]
        ]);
    }

    public function __destruct() {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('srl_cache');
        }
    }
}