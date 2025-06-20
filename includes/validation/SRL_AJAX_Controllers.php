<?php
/**
 * Zorganizowane kontrolery AJAX
 * Zastępuje rozproszone funkcje AJAX w osobnych plikach
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bazowy kontroler AJAX z wspólną funkcjonalnością
 */
abstract class SRL_AJAX_Base_Controller {
    
    protected $db;
    protected $validator;
    protected $formatter;
    
    public function __construct() {
        $this->db = SRL_Database_Manager::getInstance();
        $this->validator = new SRL_Validator();
        $this->formatter = new SRL_Formatter();
    }
    
    /**
     * Sprawdza nonce dla bezpieczeństwa
     */
    protected function verify_nonce($nonce_action = 'srl_frontend_nonce') {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', $nonce_action)) {
            wp_send_json_error('Nieprawidłowy token bezpieczeństwa.');
        }
    }
    
    /**
     * Sprawdza czy użytkownik jest zalogowany
     */
    protected function require_login() {
        if (!is_user_logged_in()) {
            wp_send_json_error('Musisz być zalogowany.');
        }
    }
    
    /**
     * Sprawdza uprawnienia administratora
     */
    protected function require_admin() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Brak uprawnień administratora.');
        }
    }
    
    /**
     * Sanityzuje i waliduje dane wejściowe
     */
    protected function validate_input($data, $rules = array()) {
        return $this->validator->validate_fields($data, $rules);
    }
    
    /**
     * Emituje zdarzenie w systemie
     */
    protected function emit_event($event_name, $data = array()) {
        srl_emit_event($event_name, $data);
    }
    
    /**
     * Standardowa odpowiedź success
     */
    protected function success($data = null, $message = '') {
        wp_send_json_success($data ?: $message);
    }
    
    /**
     * Standardowa odpowiedź error
     */
    protected function error($message, $data = null) {
        wp_send_json_error($message, $data);
    }
}

/**
 * Kontroler AJAX dla operacji na slotach i terminach
 */
class SRL_AJAX_Slot_Controller extends SRL_AJAX_Base_Controller {
    
    /**
     * Dodaje nowy slot/godzinę
     */
    public function add_slot() {
        $this->verify_nonce('srl_admin_nonce');
        $this->require_admin();
        
        $data = array(
            'data' => sanitize_text_field($_POST['data'] ?? ''),
            'pilot_id' => intval($_POST['pilot_id'] ?? 0),
            'godzina_start' => sanitize_text_field($_POST['godzina_start'] ?? ''),
            'godzina_koniec' => sanitize_text_field($_POST['godzina_koniec'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'Wolny')
        );
        
        // Walidacja
        $validation = $this->validate_slot_data($data);
        if (!$validation['valid']) {
            $this->error(implode(' ', array_values($validation['errors'])));
        }
        
        // Dodaj do bazy
        $result = $this->db->query(
            "INSERT INTO {$this->db->wpdb->prefix}srl_terminy 
             (data, pilot_id, godzina_start, godzina_koniec, status) 
             VALUES (%s, %d, %s, %s, %s)",
            array($data['data'], $data['pilot_id'], $data['godzina_start'] . ':00', 
                  $data['godzina_koniec'] . ':00', $data['status']),
            'var'
        );
        
        if ($result === false) {
            $this->error('Błąd dodawania slotu do bazy danych.');
        }
        
        $slot_id = $this->db->wpdb->insert_id;
        
        $this->emit_event('slot_created', array(
            'slot_id' => $slot_id,
            'data' => $data,
            'admin_id' => get_current_user_id()
        ));
        
        // Zwróć zaktualizowane godziny dla dnia
        $this->return_day_schedule($data['data']);
    }
    
    /**
     * Aktualizuje istniejący slot
     */
    public function update_slot() {
        $this->verify_nonce('srl_admin_nonce');
        $this->require_admin();
        
        $slot_id = intval($_POST['termin_id'] ?? 0);
        $data = array(
            'data' => sanitize_text_field($_POST['data'] ?? ''),
            'godzina_start' => sanitize_text_field($_POST['godzina_start'] ?? ''),
            'godzina_koniec' => sanitize_text_field($_POST['godzina_koniec'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? ''),
            'klient_id' => intval($_POST['klient_id'] ?? 0)
        );
        
        if (!$slot_id) {
            $this->error('Nieprawidłowe ID slotu.');
        }
        
        // Sprawdź czy slot istnieje
        $existing = $this->db->get_slot_details($slot_id);
        if (!$existing) {
            $this->error('Slot nie istnieje.');
        }
        
        // Walidacja danych
        $validation = $this->validate_slot_data($data);
        if (!$validation['valid']) {
            $this->error(implode(' ', array_values($validation['errors'])));
        }
        
        // Aktualizuj slot
        $update_data = array(
            'godzina_start' => $data['godzina_start'] . ':00',
            'godzina_koniec' => $data['godzina_koniec'] . ':00', 
            'status' => $data['status'],
            'klient_id' => $data['klient_id'] ?: null
        );
        
        $result = $this->db->wpdb->update(
            $this->db->wpdb->prefix . 'srl_terminy',
            $update_data,
            array('id' => $slot_id),
            array('%s', '%s', '%s', '%d'),
            array('%d')
        );
        
        if ($result === false) {
            $this->error('Błąd aktualizacji slotu.');
        }
        
        $this->emit_event('slot_status_changed', array(
            'slot_id' => $slot_id,
            'old_data' => $existing,
            'new_data' => $data,
            'admin_id' => get_current_user_id()
        ));
        
        $this->return_day_schedule($data['data']);
    }
    
    /**
     * Usuwa slot
     */
    public function delete_slot() {
        $this->verify_nonce('srl_admin_nonce');
        $this->require_admin();
        
        $slot_id = intval($_POST['termin_id'] ?? 0);
        
        if (!$slot_id) {
            $this->error('Nieprawidłowe ID slotu.');
        }
        
        // Pobierz szczegóły przed usunięciem
        $slot = $this->db->get_slot_details($slot_id);
        if (!$slot) {
            $this->error('Slot nie istnieje.');
        }
        
        // Sprawdź czy można usunąć
        if ($slot['status'] === 'Zarezerwowany' && intval($slot['klient_id']) > 0) {
            $this->error('Nie można usunąć zarezerwowanego slotu. Najpierw wypisz klienta.');
        }
        
        // Usuń slot
        $result = $this->db->wpdb->delete(
            $this->db->wpdb->prefix . 'srl_terminy',
            array('id' => $slot_id),
            array('%d')
        );
        
        if ($result === false) {
            $this->error('Błąd usuwania slotu.');
        }
        
        $this->emit_event('slot_deleted', array(
            'slot_id' => $slot_id,
            'slot_data' => $slot,
            'admin_id' => get_current_user_id()
        ));
        
        $this->return_day_schedule($slot['data']);
    }
    
    /**
     * Pobiera aktualny harmonogram dnia
     */
    public function get_day_schedule() {
        $this->verify_nonce('srl_admin_nonce');
        $this->require_admin();
        
        $data = sanitize_text_field($_GET['data'] ?? $_POST['data'] ?? '');
        if (!$data) {
            $this->error('Brak daty.');
        }
        
        $this->return_day_schedule($data);
    }
    
    /**
     * Waliduje dane slotu
     */
    private function validate_slot_data($data) {
        $rules = array(
            'data' => array('required' => true, 'type' => 'date'),
            'godzina_start' => array('required' => true, 'type' => 'time'),
            'godzina_koniec' => array('required' => true, 'type' => 'time'),
            'pilot_id' => array('required' => true, 'type' => 'integer', 'min_value' => 1, 'max_value' => 4)
        );
        
        $validation = $this->validator->validate_fields($data, $rules);
        
        // Sprawdź czy godzina końca jest po godzinie początku
        if ($validation['valid']) {
            $start_minutes = $this->formatter->time_to_minutes($data['godzina_start']);
            $end_minutes = $this->formatter->time_to_minutes($data['godzina_koniec']);
            
            if ($start_minutes >= $end_minutes) {
                $validation['valid'] = false;
                $validation['errors']['godzina_koniec'] = 'Godzina końca musi być późniejsza niż godzina początku.';
            }
        }
        
        return $validation;
    }
    
    /**
     * Zwraca harmonogram dnia jako JSON
     */
    private function return_day_schedule($date) {
        $schedule = $this->db->get_day_schedule($date, true);
        $this->success(array('godziny_wg_pilota' => $schedule));
    }
}

/**
 * Kontroler AJAX dla operacji na lotach
 */
class SRL_AJAX_Flight_Controller extends SRL_AJAX_Base_Controller {
    
    /**
     * Rezerwuje lot
     */
    public function reserve_flight() {
        $this->verify_nonce('srl_frontend_nonce');
        $this->require_login();
        
        $user_id = get_current_user_id();
        $slot_id = intval($_POST['termin_id'] ?? 0);
        $flight_id = intval($_POST['lot_id'] ?? 0);
        
        if (!$slot_id || !$flight_id) {
            $this->error('Nieprawidłowe parametry rezerwacji.');
        }
        
        // Sprawdź blokadę tymczasową
        if (!get_transient("srl_block_{$slot_id}_{$user_id}")) {
            $this->error('Sesja rezerwacji wygasła. Spróbuj ponownie.');
        }
        
        // Wykonaj rezerwację
        $result = $this->db->reserve_slot($slot_id, $flight_id, $user_id);
        
        if (!$result['success']) {
            $this->error($result['message']);
        }
        
        // Pobierz szczegóły dla emaila
        $slot = $this->db->get_slot_details($slot_id);
        $flight = $this->db->get_flight_details($flight_id);
        
        $this->emit_event('flight_reserved', array(
            'user_id' => $user_id,
            'slot_id' => $slot_id,
            'flight_id' => $flight_id,
            'slot' => $slot,
            'flight' => $flight
        ));
        
        // Usuń blokadę
        delete_transient("srl_block_{$slot_id}_{$user_id}");
        
        $this->success(array(
            'message' => 'Rezerwacja została potwierdzona!',
            'slot' => $slot,
            'flight' => $flight
        ));
    }
    
    /**
     * Anuluje rezerwację
     */
    public function cancel_reservation() {
        $this->verify_nonce('srl_frontend_nonce');
        $this->require_login();
        
        $user_id = get_current_user_id();
        $flight_id = intval($_POST['lot_id'] ?? 0);
        
        if (!$flight_id) {
            $this->error('Nieprawidłowe ID lotu.');
        }
        
        // Pobierz szczegóły przed anulowaniem
        $flight = $this->db->get_flight_details($flight_id);
        if (!$flight || $flight['user_id'] != $user_id) {
            $this->error('Lot nie istnieje lub nie należy do Ciebie.');
        }
        
        if ($flight['status'] !== 'zarezerwowany') {
            $this->error('Ten lot nie jest zarezerwowany.');
        }
        
        // Sprawdź czy można anulować (48h przed lotem)
        if (!$this->formatter->can_cancel_reservation($flight['data_lotu'], $flight['godzina_start'])) {
            $this->error('Nie można anulować rezerwacji na mniej niż 48h przed lotem.');
        }
        
        // Anuluj rezerwację
        $result = $this->db->cancel_reservation($flight_id);
        
        if (!$result['success']) {
            $this->error($result['message']);
        }
        
        $this->emit_event('flight_cancelled', array(
            'user_id' => $user_id,
            'flight_id' => $flight_id,
            'flight' => $flight,
            'reason' => 'Anulowanie przez klienta'
        ));
        
        $this->success('Rezerwacja została anulowana.');
    }
    
    /**
     * Blokuje slot tymczasowo
     */
    public function block_slot_temporarily() {
        $this->verify_nonce('srl_frontend_nonce');
        $this->require_login();
        
        $slot_id = intval($_POST['termin_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if (!$slot_id) {
            $this->error('Nieprawidłowe ID slotu.');
        }
        
        // Sprawdź dostępność
        if (!$this->db->is_slot_available($slot_id)) {
            $this->error('Ten termin nie jest już dostępny.');
        }
        
        // Ustaw blokadę na 15 minut
        set_transient("srl_block_{$slot_id}_{$user_id}", true, 15 * MINUTE_IN_SECONDS);
        
        $slot = $this->db->get_slot_details($slot_id);
        
        $this->success(array(
            'slot' => $slot,
            'blokada_do' => time() + 15 * MINUTE_IN_SECONDS
        ));
    }
    
    /**
     * Pobiera dostępne loty użytkownika
     */
    public function get_user_flights() {
        $this->verify_nonce('srl_frontend_nonce');
        $this->require_login();
        
        $user_id = get_current_user_id();
        
        // Pobierz loty z cache
        $flights = $this->db->get_user_flights($user_id, array(
            'status' => array('wolny', 'zarezerwowany', 'zrealizowany'),
            'include_expired' => false
        ));
        
        $user_data = $this->db->get_user_data($user_id);
        
        // Generuj HTML dla listy lotów
        $html = $this->generate_flights_html($flights);
        
        $this->success(array(
            'html' => $html,
            'user_data' => $user_data,
            'flights_count' => count($flights)
        ));
    }
    
    /**
     * Generuje HTML dla listy lotów
     */
    private function generate_flights_html($flights) {
        if (empty($flights)) {
            return '<div class="srl-brak-lotow"><h3>Brak dostępnych lotów</h3><p>Nie masz jeszcze żadnych lotów do zarezerwowania.</p></div>';
        }
        
        $html = '';
        foreach ($flights as $flight) {
            $html .= '<div class="srl-lot-item" data-lot-id="' . $flight['id'] . '" data-status="' . $flight['status'] . '">';
            $html .= '<div class="srl-lot-header">';
            $html .= '<h4>Lot w tandemie (#' . $flight['id'] . ')</h4>';
            $html .= $this->formatter->generate_status_badge($flight['status'], 'lot');
            $html .= '</div>';
            
            $html .= '<div class="srl-lot-details">';
            $html .= '<div class="srl-lot-options">' . $this->formatter->format_flight_options($flight['ma_filmowanie'], $flight['ma_akrobacje']) . '</div>';
            
            if ($flight['status'] === 'zarezerwowany' && !empty($flight['data_lotu'])) {
                $html .= '<div class="srl-lot-termin">';
                $html .= $this->formatter->format_polish_datetime($flight['data_lotu'], $flight['godzina_start']);
                $html .= '</div>';
            }
            
            $html .= '<div class="srl-lot-waznosc">' . $this->formatter->format_expiry($flight['data_waznosci']) . '</div>';
            $html .= '</div>';
            
            $html .= '<div class="srl-lot-actions">';
            if ($flight['status'] === 'wolny') {
                $html .= '<button class="srl-btn srl-btn-primary srl-rezerwuj-lot" data-lot-id="' . $flight['id'] . '">Zarezerwuj lot</button>';
            } elseif ($flight['status'] === 'zarezerwowany') {
                if ($this->formatter->can_cancel_reservation($flight['data_lotu'], $flight['godzina_start'])) {
                    $html .= '<button class="srl-btn srl-btn-secondary srl-anuluj-rezerwacje" data-lot-id="' . $flight['id'] . '">Anuluj rezerwację</button>';
                }
            }
            $html .= '</div>';
            
            $html .= '</div>';
        }
        
        return $html;
    }
}

/**
 * Kontroler AJAX dla danych użytkownika
 */
class SRL_AJAX_User_Controller extends SRL_AJAX_Base_Controller {
    
    /**
     * Pobiera dane klienta
     */
    public function get_client_data() {
        $this->verify_nonce('srl_frontend_nonce');
        $this->require_login();
        
        $user_id = get_current_user_id();
        
        // Pobierz wszystkie dane jednym wywołaniem
        $user_data = $this->db->get_user_data($user_id);
        $flights = $this->db->get_user_flights($user_id, array(
            'status' => array('wolny', 'zarezerwowany'),
            'include_expired' => false
        ));
        
        // Sprawdź kompletność danych
        $required_fields = array('imie', 'nazwisko', 'rok_urodzenia', 'kategoria_wagowa', 'sprawnosc_fizyczna');
        $data_complete = true;
        
        foreach ($required_fields as $field) {
            if (empty($user_data[$field])) {
                $data_complete = false;
                break;
            }
        }
        
        // Podziel loty na kategorie
        $reserved_flights = array_filter($flights, function($f) { return $f['status'] === 'zarezerwowany'; });
        $available_flights = array_filter($flights, function($f) { return $f['status'] === 'wolny'; });
        
        $this->success(array(
            'rezerwacje' => array_values($reserved_flights),
            'dostepne_loty' => array_values($available_flights),
            'dane_osobowe' => $user_data,
            'dane_kompletne' => $data_complete
        ));
    }
    
    /**
     * Zapisuje dane pasażera
     */
    public function save_passenger_data() {
        $this->verify_nonce('srl_frontend_nonce');
        $this->require_login();
        
        $user_id = get_current_user_id();
        
        $data = array(
            'imie' => sanitize_text_field($_POST['imie'] ?? ''),
            'nazwisko' => sanitize_text_field($_POST['nazwisko'] ?? ''),
            'rok_urodzenia' => intval($_POST['rok_urodzenia'] ?? 0),
            'kategoria_wagowa' => sanitize_text_field($_POST['kategoria_wagowa'] ?? ''),
            'sprawnosc_fizyczna' => sanitize_text_field($_POST['sprawnosc_fizyczna'] ?? ''),
            'telefon' => sanitize_text_field($_POST['telefon'] ?? ''),
            'uwagi' => sanitize_textarea_field($_POST['uwagi'] ?? ''),
            'akceptacja_regulaminu' => isset($_POST['akceptacja_regulaminu']) && $_POST['akceptacja_regulaminu'] === 'true'
        );
        
        // Walidacja danych
        $validation = $this->validator->validate_passenger_data($data);
        if (!$validation['valid']) {
            $this->error(implode(' ', array_values($validation['errors'])));
        }
        
        // Pobierz stare dane dla porównania
        $old_data = $this->db->get_user_data($user_id);
        $changed_fields = array();
        
        // Zapisz dane i sprawdź co się zmieniło
        foreach ($data as $key => $value) {
            if ($key === 'akceptacja_regulaminu') continue;
            
            $old_value = $old_data[$key] ?? '';
            if ($old_value != $value) {
                $changed_fields[] = $key;
            }
            
            update_user_meta($user_id, 'srl_' . $key, $value);
        }
        
        // Wyczyść cache użytkownika
        $this->db->clear_cache("user_data_{$user_id}");
        
        // Emituj zdarzenie jeśli były zmiany
        if (!empty($changed_fields)) {
            $this->emit_event('user_data_updated', array(
                'user_id' => $user_id,
                'changed_fields' => $changed_fields,
                'old_data' => $old_data,
                'new_data' => $data
            ));
        }
        
        $this->success('Dane zostały zapisane.');
    }
    
    /**
     * Waliduje wiek użytkownika
     */
    public function validate_age() {
        $this->verify_nonce('srl_frontend_nonce');
        
        $birth_year = intval($_POST['rok_urodzenia'] ?? 0);
        $validation = $this->validator->validate_age($birth_year, 'html');
        
        $this->success(array('html' => $validation['html']));
    }
    
    /**
     * Waliduje kategorię wagową
     */
    public function validate_weight() {
        $this->verify_nonce('srl_frontend_nonce');
        
        $category = sanitize_text_field($_POST['kategoria_wagowa'] ?? '');
        $validation = $this->validator->validate_weight($category, 'html');
        
        $this->success(array('html' => $validation['html']));
    }
}

/**
 * Router AJAX - mapuje akcje na kontrolery
 */
class SRL_AJAX_Router {
    
    private static $controllers = array();
    
    public static function init() {
        self::$controllers = array(
            'slot' => new SRL_AJAX_Slot_Controller(),
            'flight' => new SRL_AJAX_Flight_Controller(),
            'user' => new SRL_AJAX_User_Controller()
        );
    }
    
    /**
     * Routuje akcje admin
     */
    public static function route_admin($action) {
        $mapping = array(
            // Slot operations
            'srl_dodaj_godzine' => array('slot', 'add_slot'),
            'srl_zmien_slot' => array('slot', 'update_slot'), 
            'srl_usun_godzine' => array('slot', 'delete_slot'),
            'srl_pobierz_aktualne_godziny' => array('slot', 'get_day_schedule'),
            
            // Flight operations - admin part
            'srl_przypisz_wykupiony_lot' => array('flight', 'assign_flight_to_slot'),
            'srl_zrealizuj_lot' => array('flight', 'complete_flight')
        );
        
        if (isset($mapping[$action])) {
            list($controller, $method) = $mapping[$action];
            if (isset(self::$controllers[$controller])) {
                call_user_func(array(self::$controllers[$controller], $method));
                return;
            }
        }
        
        // Fallback do starych funkcji
        self::fallback_to_legacy($action);
    }
    
    /**
     * Routuje akcje frontend
     */
    public static function route_frontend($action) {
        $mapping = array(
            // Flight operations
            'srl_dokonaj_rezerwacji' => array('flight', 'reserve_flight'),
            'srl_anuluj_rezerwacje_klient' => array('flight', 'cancel_reservation'),
            'srl_zablokuj_slot_tymczasowo' => array('flight', 'block_slot_temporarily'),
            'srl_pobierz_dostepne_loty' => array('flight', 'get_user_flights'),
            
            // User operations
            'srl_pobierz_dane_klienta' => array('user', 'get_client_data'),
            'srl_zapisz_dane_pasazera' => array('user', 'save_passenger_data'),
            'srl_waliduj_wiek' => array('user', 'validate_age'),
            'srl_waliduj_kategorie_wagowa' => array('user', 'validate_weight')
        );
        
        if (isset($mapping[$action])) {
            list($controller, $method) = $mapping[$action];
            if (isset(self::$controllers[$controller])) {
                call_user_func(array(self::$controllers[$controller], $method));
                return;
            }
        }
        
        // Fallback do starych funkcji
        self::fallback_to_legacy($action);
    }
    
    /**
     * Fallback do starych funkcji (kompatybilność wsteczna)
     */
    private static function fallback_to_legacy($action) {
        // Załaduj odpowiedni plik i wywołaj funkcję
        if (strpos($action, 'srl_') === 0) {
            // Sprawdź w różnych plikach AJAX
            $ajax_files = array(
                'admin-ajax.php',
                'frontend-ajax.php', 
                'voucher-ajax.php',
                'flight-options-ajax.php'
            );
            
            foreach ($ajax_files as $file) {
                $file_path = SRL_PLUGIN_DIR . 'includes/ajax/' . $file;
                if (file_exists($file_path)) {
                    require_once $file_path;
                    if (function_exists($action)) {
                        call_user_func($action);
                        return;
                    }
                }
            }
        }
        
        wp_send_json_error('Nieznana akcja AJAX: ' . $action);
    }
}

// Inicjalizuj router
SRL_AJAX_Router::init();