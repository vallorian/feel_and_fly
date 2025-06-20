<?php
/**
 * Główna klasa zarządzania lotami
 * Centralizuje całą logikę biznesową lotów
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Flight_Manager {
    
    private $db;
    private static $instance = null;
    
    // Statusy lotów
    const STATUS_AVAILABLE = 'wolny';
    const STATUS_RESERVED = 'zarezerwowany';
    const STATUS_COMPLETED = 'zrealizowany';
    const STATUS_EXPIRED = 'przedawniony';
    
    // Opcje lotów
    const OPTION_FILMING = 'ma_filmowanie';
    const OPTION_ACROBATICS = 'ma_akrobacje';
    
    public function __construct() {
        $this->db = SRL_Database_Manager::getInstance();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Tworzy nowy lot na podstawie zamówienia WooCommerce
     */
    public function create_flight_from_order($order_item_id, $order_id, $user_id, $product_name) {
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('Zamówienie nie istnieje');
            }

            // Pobierz dane z zamówienia
            $order_data = $this->extract_order_data($order);
            
            // Wykryj opcje lotu z nazwy produktu
            $flight_options = $this->detect_flight_options($product_name);
            
            // Przygotuj dane lotu
            $flight_data = array(
                'order_item_id' => $order_item_id,
                'order_id' => $order_id,
                'user_id' => $user_id,
                'imie' => $order_data['first_name'],
                'nazwisko' => $order_data['last_name'],
                'nazwa_produktu' => $product_name,
                'status' => self::STATUS_AVAILABLE,
                'data_zakupu' => $order_data['date_created'],
                'data_waznosci' => $this->calculate_expiry_date($order_data['date_created']),
                'ma_filmowanie' => $flight_options['filming'],
                'ma_akrobacje' => $flight_options['acrobatics']
            );
            
            // Zapisz lot
            $flight_id = $this->create_flight($flight_data);
            
            // Zapisz historię
            $this->add_history_entry($flight_id, 'utworzenie_lotu', 'System', array(
                'order_id' => $order_id,
                'product_name' => $product_name,
                'options' => $flight_options
            ));
            
            return array('success' => true, 'flight_id' => $flight_id);
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Rezerwuje lot na określony termin
     */
    public function reserve_flight($flight_id, $slot_id, $user_id, $passenger_data = null) {
        try {
            // Walidacja
            $flight = $this->get_flight($flight_id);
            if (!$flight) {
                throw new Exception('Lot nie istnieje');
            }
            
            if ($flight['user_id'] != $user_id) {
                throw new Exception('Brak uprawnień do tego lotu');
            }
            
            if ($flight['status'] !== self::STATUS_AVAILABLE) {
                throw new Exception('Lot nie jest dostępny do rezerwacji');
            }
            
            // Sprawdź ważność
            if ($this->is_flight_expired($flight)) {
                throw new Exception('Lot jest przeterminowany');
            }
            
            // Sprawdź slot
            if (!$this->db->is_slot_available($slot_id)) {
                throw new Exception('Termin nie jest już dostępny');
            }
            
            // Wykonaj rezerwację przez database manager
            $result = $this->db->reserve_slot($slot_id, $flight_id, $user_id);
            
            if (!$result['success']) {
                throw new Exception($result['message']);
            }
            
            // Zapisz dane pasażera jeśli podane
            if ($passenger_data) {
                $this->update_passenger_data($flight_id, $passenger_data);
            }
            
            // Dodaj historię
            $slot_details = $this->db->get_slot_details($slot_id);
            $this->add_history_entry($flight_id, 'rezerwacja', 'Klient', array(
                'slot_id' => $slot_id,
                'termin' => SRL_Formatter::format_slot_details($slot_details),
                'passenger_data_updated' => !empty($passenger_data)
            ));
            
            // Wyślij email potwierdzenia
            $this->send_confirmation_email($flight_id, $slot_details);
            
            return array('success' => true, 'message' => 'Lot został zarezerwowany');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Anuluje rezerwację lotu
     */
    public function cancel_reservation($flight_id, $user_id, $reason = 'Anulowanie przez klienta') {
        try {
            $flight = $this->get_flight($flight_id);
            if (!$flight) {
                throw new Exception('Lot nie istnieje');
            }
            
            if ($flight['user_id'] != $user_id) {
                throw new Exception('Brak uprawnień do tego lotu');
            }
            
            if ($flight['status'] !== self::STATUS_RESERVED) {
                throw new Exception('Lot nie jest zarezerwowany');
            }
            
            // Sprawdź czy można anulować (48h przed lotem)
            if (!$this->can_cancel_reservation($flight)) {
                throw new Exception('Nie można anulować rezerwacji na mniej niż 48h przed lotem');
            }
            
            // Wykonaj anulowanie
            $result = $this->db->cancel_reservation($flight_id);
            
            if (!$result['success']) {
                throw new Exception($result['message']);
            }
            
            // Dodaj historię
            $this->add_history_entry($flight_id, 'anulowanie', 'Klient', array(
                'reason' => $reason,
                'termin' => $flight['data_lotu'] ? SRL_Formatter::format_slot_details($flight) : null
            ));
            
            return array('success' => true, 'message' => 'Rezerwacja została anulowana');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Oznacza lot jako zrealizowany
     */
    public function complete_flight($flight_id, $admin_user_id = null) {
        try {
            $flight = $this->get_flight($flight_id);
            if (!$flight) {
                throw new Exception('Lot nie istnieje');
            }
            
            if ($flight['status'] !== self::STATUS_RESERVED) {
                throw new Exception('Lot musi być zarezerwowany aby go zrealizować');
            }
            
            // Aktualizuj status
            $result = $this->update_flight_status($flight_id, self::STATUS_COMPLETED);
            
            if (!$result) {
                throw new Exception('Błąd podczas aktualizacji statusu');
            }
            
            // Aktualizuj slot jeśli przypisany
            if ($flight['termin_id']) {
                $this->update_slot_status($flight['termin_id'], 'Zrealizowany');
            }
            
            // Dodaj historię
            $executor = $admin_user_id ? 'Admin' : 'System';
            $this->add_history_entry($flight_id, 'realizacja', $executor, array(
                'completed_date' => current_time('mysql'),
                'admin_user_id' => $admin_user_id
            ));
            
            return array('success' => true, 'message' => 'Lot został oznaczony jako zrealizowany');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Dodaje opcję do lotu (filmowanie/akrobacje)
     */
    public function add_flight_option($flight_id, $option_type, $order_id = null) {
        try {
            if (!in_array($option_type, [self::OPTION_FILMING, self::OPTION_ACROBATICS])) {
                throw new Exception('Nieprawidłowy typ opcji');
            }
            
            $flight = $this->get_flight($flight_id);
            if (!$flight) {
                throw new Exception('Lot nie istnieje');
            }
            
            // Sprawdź czy opcja już nie jest dodana
            if ($flight[$option_type]) {
                throw new Exception('Ta opcja jest już dodana do lotu');
            }
            
            // Aktualizuj lot
            $result = $this->update_flight_option($flight_id, $option_type, 1);
            
            if (!$result) {
                throw new Exception('Błąd podczas dodawania opcji');
            }
            
            // Dodaj historię
            $option_name = $option_type === self::OPTION_FILMING ? 'filmowanie' : 'akrobacje';
            $this->add_history_entry($flight_id, 'dodanie_opcji', 'Klient', array(
                'option_type' => $option_type,
                'option_name' => $option_name,
                'order_id' => $order_id
            ));
            
            return array('success' => true, 'message' => "Opcja {$option_name} została dodana");
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Przedłuża ważność lotu
     */
    public function extend_validity($flight_id, $years = 1, $order_id = null) {
        try {
            $flight = $this->get_flight($flight_id);
            if (!$flight) {
                throw new Exception('Lot nie istnieje');
            }
            
            $old_expiry = $flight['data_waznosci'];
            $new_expiry = date('Y-m-d', strtotime($old_expiry . " +{$years} year"));
            
            // Aktualizuj datę ważności
            $result = $this->update_flight_expiry($flight_id, $new_expiry);
            
            if (!$result) {
                throw new Exception('Błąd podczas przedłużania ważności');
            }
            
            // Dodaj historię
            $this->add_history_entry($flight_id, 'przedluzenie_waznosci', 'Klient', array(
                'old_expiry' => $old_expiry,
                'new_expiry' => $new_expiry,
                'years_added' => $years,
                'order_id' => $order_id
            ));
            
            return array('success' => true, 'message' => "Ważność została przedłużona do {$new_expiry}");
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Pobiera loty użytkownika z filtrami
     */
    public function get_user_flights($user_id, $filters = array()) {
        $options = array_merge(array(
            'status' => null,
            'include_expired' => false,
            'future_only' => false,
            'with_voucher_info' => true,
            'order_by' => 'data_zakupu DESC',
            'limit' => null
        ), $filters);
        
        return $this->db->get_user_flights($user_id, $options);
    }

    /**
     * Pobiera szczegóły lotu
     */
    public function get_flight($flight_id) {
        return $this->db->get_flight_details($flight_id);
    }

    /**
     * Sprawdza czy lot jest przeterminowany
     */
    public function is_flight_expired($flight) {
        return strtotime($flight['data_waznosci']) < strtotime('today');
    }

    /**
     * Sprawdza czy można anulować rezerwację
     */
    public function can_cancel_reservation($flight) {
        if (empty($flight['data_lotu']) || empty($flight['godzina_start'])) {
            return false;
        }
        
        $flight_timestamp = strtotime($flight['data_lotu'] . ' ' . $flight['godzina_start']);
        return ($flight_timestamp - time()) > (48 * 3600);
    }

    /**
     * Aktualizuje dane pasażera
     */
    public function update_passenger_data($flight_id, $passenger_data) {
        // Waliduj dane
        $validation = SRL_Validator::validate_passenger_data($passenger_data);
        if (!$validation['valid']) {
            throw new Exception('Nieprawidłowe dane pasażera: ' . implode(', ', array_values($validation['errors'])));
        }
        
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            array('dane_pasazera' => json_encode($passenger_data)),
            array('id' => $flight_id),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            $this->add_history_entry($flight_id, 'aktualizacja_danych_pasazera', 'Klient', array(
                'updated_fields' => array_keys($passenger_data)
            ));
        }
        
        return $result !== false;
    }

    /**
     * Oznacza przeterminowane loty
     */
    public function mark_expired_flights() {
        global $wpdb;
        
        // Pobierz przeterminowane loty
        $expired_flights = $wpdb->get_results(
            "SELECT id, user_id, termin_id FROM {$wpdb->prefix}srl_zakupione_loty 
             WHERE status IN ('wolny', 'zarezerwowany') 
             AND data_waznosci < CURDATE()",
            ARRAY_A
        );
        
        foreach ($expired_flights as $flight) {
            // Aktualizuj status lotu
            $this->update_flight_status($flight['id'], self::STATUS_EXPIRED);
            
            // Zwolnij slot jeśli zarezerwowany
            if ($flight['termin_id']) {
                $this->update_slot_status($flight['termin_id'], 'Wolny');
                $this->clear_slot_assignment($flight['termin_id']);
            }
            
            // Dodaj historię
            $this->add_history_entry($flight['id'], 'wygasniecie', 'System', array(
                'expired_date' => current_time('mysql'),
                'slot_freed' => !empty($flight['termin_id'])
            ));
        }
        
        return count($expired_flights);
    }

    /**
     * Pobiera statystyki lotów
     */
    public function get_flight_statistics() {
        global $wpdb;
        
        $stats = array();
        
        // Statystyki podstawowe
        $stats['by_status'] = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}srl_zakupione_loty GROUP BY status",
            ARRAY_A
        );
        
        // Statystyki opcji
        $stats['with_filming'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}srl_zakupione_loty WHERE ma_filmowanie = 1"
        );
        
        $stats['with_acrobatics'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}srl_zakupione_loty WHERE ma_akrobacje = 1"
        );
        
        // Statystyki ważności
        $stats['expiring_soon'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}srl_zakupione_loty 
             WHERE status IN ('wolny', 'zarezerwowany') 
             AND data_waznosci BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        );
        
        return $stats;
    }

    // Metody pomocnicze
    
    private function extract_order_data($order) {
        return array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'date_created' => $order->get_date_created()->date('Y-m-d H:i:s')
        );
    }
    
    private function detect_flight_options($product_name) {
        $lower = strtolower($product_name);
        
        return array(
            'filming' => (strpos($lower, 'filmowani') !== false || 
                         strpos($lower, 'film') !== false ||
                         strpos($lower, 'video') !== false ||
                         strpos($lower, 'kamer') !== false) ? 1 : 0,
            'acrobatics' => (strpos($lower, 'akrobacj') !== false ||
                            strpos($lower, 'trick') !== false ||
                            strpos($lower, 'spiral') !== false ||
                            strpos($lower, 'figur') !== false) ? 1 : 0
        );
    }
    
    private function calculate_expiry_date($from_date, $years = 1) {
        return date('Y-m-d', strtotime($from_date . " +{$years} year"));
    }
    
    private function create_flight($flight_data) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'srl_zakupione_loty',
            $flight_data,
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        if ($result === false) {
            throw new Exception('Błąd podczas tworzenia lotu');
        }
        
        return $wpdb->insert_id;
    }
    
    private function update_flight_status($flight_id, $status) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            array('status' => $status),
            array('id' => $flight_id),
            array('%s'),
            array('%d')
        );
    }
    
    private function update_flight_option($flight_id, $option_type, $value) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            array($option_type => $value),
            array('id' => $flight_id),
            array('%d'),
            array('%d')
        );
    }
    
    private function update_flight_expiry($flight_id, $new_expiry) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            array('data_waznosci' => $new_expiry),
            array('id' => $flight_id),
            array('%s'),
            array('%d')
        );
    }
    
    private function update_slot_status($slot_id, $status) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'srl_terminy',
            array('status' => $status),
            array('id' => $slot_id),
            array('%s'),
            array('%d')
        );
    }
    
    private function clear_slot_assignment($slot_id) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'srl_terminy',
            array('klient_id' => null),
            array('id' => $slot_id),
            array('%d'),
            array('%d')
        );
    }
    
    private function add_history_entry($flight_id, $action_type, $executor, $details = array()) {
        $entry = array(
            'data' => current_time('Y-m-d H:i:s'),
            'typ' => $action_type,
            'executor' => $executor,
            'szczegoly' => $details
        );
        
        return srl_dopisz_do_historii_lotu($flight_id, $entry);
    }
    
    private function send_confirmation_email($flight_id, $slot_details) {
        $flight = $this->get_flight($flight_id);
        $user = get_userdata($flight['user_id']);
        
        if ($user && $slot_details) {
            return srl_wyslij_email_potwierdzenia($flight['user_id'], $slot_details, $flight);
        }
        
        return false;
    }
}

// Funkcje kompatybilności wstecznej
function srl_create_flight_from_order($order_item_id, $order_id, $user_id, $product_name) {
    return SRL_Flight_Manager::getInstance()->create_flight_from_order($order_item_id, $order_id, $user_id, $product_name);
}

function srl_reserve_flight($flight_id, $slot_id, $user_id, $passenger_data = null) {
    return SRL_Flight_Manager::getInstance()->reserve_flight($flight_id, $slot_id, $user_id, $passenger_data);
}

function srl_cancel_flight_reservation($flight_id, $user_id, $reason = null) {
    return SRL_Flight_Manager::getInstance()->cancel_reservation($flight_id, $user_id, $reason);
}

function srl_complete_flight($flight_id, $admin_user_id = null) {
    return SRL_Flight_Manager::getInstance()->complete_flight($flight_id, $admin_user_id);
}

function srl_add_flight_option($flight_id, $option_type, $order_id = null) {
    return SRL_Flight_Manager::getInstance()->add_flight_option($flight_id, $option_type, $order_id);
}

function srl_extend_flight_validity($flight_id, $years = 1, $order_id = null) {
    return SRL_Flight_Manager::getInstance()->extend_validity($flight_id, $years, $order_id);
}

function srl_mark_expired_flights() {
    return SRL_Flight_Manager::getInstance()->mark_expired_flights();
}