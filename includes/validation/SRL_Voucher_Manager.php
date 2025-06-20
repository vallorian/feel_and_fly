<?php
/**
 * Centralna klasa zarządzania voucherami
 * Obsługuje vouchery upominkowe, partnera i Feel&Fly
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Voucher_Manager {
    
    private static $instance = null;
    private $db;
    private $flight_manager;
    
    // Konfiguracje voucherów
    private $voucher_types = array(
        'gift' => array(
            'table' => 'vouchery_upominkowe',
            'code_length' => 10,
            'code_chars' => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789',
            'validity_years' => 1
        ),
        'feelfly' => array(
            'table' => 'vouchery_upominkowe', // Używa tej samej tabeli
            'code_length' => 12,
            'code_chars' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
            'validity_years' => 2
        ),
        'partner' => array(
            'table' => 'vouchery_partnerzy',
            'code_length' => null, // Podawany przez partnera
            'validity_years' => 1
        )
    );

    private $partner_config = array(
        'prezent_marzen' => array(
            'nazwa' => 'PrezentMarzeń',
            'typy' => array(
                'lot_3_osoby' => array('nazwa' => 'Lot tandemowy dla 3 osób', 'liczba_osob' => 3),
                'lot_2_osoby' => array('nazwa' => 'Lot tandemowy dla 2 osób', 'liczba_osob' => 2),
                'lot_1_osoba' => array('nazwa' => 'Lot tandemowy dla 1 osoby', 'liczba_osob' => 1)
            )
        ),
        'groupon' => array(
            'nazwa' => 'Groupon',
            'typy' => array(
                'lot_1_osoba' => array('nazwa' => 'Lot tandemowy dla 1 osoby', 'liczba_osob' => 1)
            )
        )
    );

    private $status_configs = array(
        'gift' => array(
            'do_wykorzystania' => 'Dostępny do wykorzystania',
            'wykorzystany' => 'Wykorzystany',
            'przeterminowany' => 'Przeterminowany'
        ),
        'partner' => array(
            'oczekuje' => 'Oczekuje na weryfikację',
            'zatwierdzony' => 'Zatwierdzony',
            'odrzucony' => 'Odrzucony'
        )
    );

    public function __construct() {
        $this->db = SRL_Database_Manager::getInstance();
        
        // Jeśli Flight_Manager już istnieje, użyj go
        if (class_exists('SRL_Flight_Manager')) {
            $this->flight_manager = SRL_Flight_Manager::getInstance();
        }
        
        // Hook do automatycznego oznaczania przeterminowanych
        add_action('srl_daily_maintenance', array($this, 'mark_expired_vouchers'));
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * === VOUCHERY UPOMINKOWE ===
     */

    /**
     * Tworzy voucher upominkowy z zamówienia WooCommerce
     */
    public function create_gift_voucher($order_id, $item_id, $product_name, $buyer_data, $options = array()) {
        $defaults = array(
            'ma_filmowanie' => 0,
            'ma_akrobacje' => 0,
            'type' => 'gift' // gift lub feelfly
        );
        $options = array_merge($defaults, $options);
        
        $code = $this->generate_voucher_code($options['type']);
        $expiry_date = $this->calculate_expiry_date($options['type']);
        
        $voucher_data = array(
            'order_item_id' => $item_id,
            'order_id' => $order_id,
            'buyer_user_id' => $buyer_data['user_id'],
            'buyer_imie' => $buyer_data['imie'],
            'buyer_nazwisko' => $buyer_data['nazwisko'],
            'nazwa_produktu' => $product_name,
            'kod_vouchera' => $code,
            'status' => 'do_wykorzystania',
            'data_zakupu' => current_time('mysql'),
            'data_waznosci' => $expiry_date,
            'ma_filmowanie' => $options['ma_filmowanie'],
            'ma_akrobacje' => $options['ma_akrobacje']
        );
        
        $result = $this->db->query(
            "INSERT INTO {$this->get_table_name('gift')} 
             (order_item_id, order_id, buyer_user_id, buyer_imie, buyer_nazwisko, nazwa_produktu, 
              kod_vouchera, status, data_zakupu, data_waznosci, ma_filmowanie, ma_akrobacje) 
             VALUES (%d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %d, %d)",
            array_values($voucher_data),
            'var'
        );
        
        if ($result !== false) {
            $voucher_id = $this->db->wpdb->insert_id;
            
            // Wyślij email z voucherem
            $this->send_voucher_email($code, $product_name, $expiry_date, $buyer_data);
            
            // Zapisz w logach
            do_action('srl_voucher_created', $voucher_id, 'gift', $voucher_data);
            
            return array(
                'success' => true,
                'voucher_id' => $voucher_id,
                'code' => $code,
                'expiry_date' => $expiry_date
            );
        }
        
        return array('success' => false, 'message' => 'Błąd podczas tworzenia vouchera.');
    }

    /**
     * Wykorzystuje voucher upominkowy/Feel&Fly
     */
    public function redeem_gift_voucher($code, $user_id) {
        // Walidacja kodu
        $validation = SRL_Validator::validate_field('kod_vouchera', $code);
        if (!$validation['valid']) {
            return array('success' => false, 'message' => implode(' ', $validation['errors']));
        }
        
        $code = $validation['value'];
        
        // Sprawdź voucher
        $voucher = $this->db->query(
            "SELECT * FROM {$this->get_table_name('gift')} 
             WHERE kod_vouchera = %s AND status = 'do_wykorzystania' AND data_waznosci >= CURDATE()",
            array($code),
            'row',
            "gift_voucher_{$code}"
        );
        
        if (!$voucher) {
            return array('success' => false, 'message' => 'Nieprawidłowy kod vouchera lub voucher wygasł.');
        }
        
        // Sprawdź użytkownika
        $user = get_userdata($user_id);
        if (!$user) {
            return array('success' => false, 'message' => 'Nieprawidłowy użytkownik.');
        }
        
        // Wykorzystaj voucher i utwórz lot
        return $this->process_voucher_redemption($voucher, $user);
    }

    /**
     * Przetwarza wykorzystanie vouchera
     */
    private function process_voucher_redemption($voucher, $user) {
        $this->db->wpdb->query('START TRANSACTION');
        
        try {
            $current_time = current_time('mysql');
            $flight_expiry = SRL_Formatter::generate_expiry_date($current_time, 1);
            
            // Utwórz lot
            if ($this->flight_manager) {
                $flight_result = $this->flight_manager->create_flight(array(
                    'order_item_id' => $voucher['order_item_id'],
                    'order_id' => $voucher['order_id'], 
                    'user_id' => $user->ID,
                    'imie' => $user->first_name ?: 'Voucher',
                    'nazwisko' => $user->last_name ?: 'User',
                    'nazwa_produktu' => $voucher['nazwa_produktu'],
                    'data_waznosci' => $flight_expiry,
                    'ma_filmowanie' => $voucher['ma_filmowanie'],
                    'ma_akrobacje' => $voucher['ma_akrobacje'],
                    'source' => 'voucher'
                ));
                
                if (!$flight_result['success']) {
                    throw new Exception('Błąd podczas tworzenia lotu: ' . $flight_result['message']);
                }
                
                $flight_id = $flight_result['flight_id'];
            } else {
                // Fallback - bezpośrednie dodanie do bazy
                $flight_id = $this->create_flight_from_voucher($voucher, $user, $flight_expiry);
                if (!$flight_id) {
                    throw new Exception('Błąd podczas dodawania lotu.');
                }
            }
            
            // Zaktualizuj voucher
            $update_result = $this->db->query(
                "UPDATE {$this->get_table_name('gift')} 
                 SET status = 'wykorzystany', 
                     data_wykorzystania = %s, 
                     wykorzystany_przez_user_id = %d, 
                     lot_id = %d 
                 WHERE id = %d",
                array($current_time, $user->ID, $flight_id, $voucher['id']),
                'var'
            );
            
            if ($update_result === false) {
                throw new Exception('Błąd aktualizacji vouchera.');
            }
            
            $this->db->wpdb->query('COMMIT');
            
            // Wyczyść cache
            $this->db->clear_cache("gift_voucher_{$voucher['kod_vouchera']}");
            
            // Hook dla innych akcji
            do_action('srl_voucher_redeemed', $voucher['id'], $flight_id, $user->ID);
            
            return array(
                'success' => true,
                'message' => 'Voucher został wykorzystany! Lot został dodany do Twojego konta.',
                'flight_id' => $flight_id
            );
            
        } catch (Exception $e) {
            $this->db->wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * === VOUCHERY PARTNERA ===
     */

    /**
     * Tworzy zgłoszenie vouchera partnera
     */
    public function submit_partner_voucher($data) {
        // Walidacja danych
        $validation = $this->validate_partner_voucher_data($data);
        if (!$validation['valid']) {
            return array('success' => false, 'message' => implode(', ', $validation['errors']));
        }
        
        // Sprawdź czy voucher już istnieje
        $existing = $this->db->query(
            "SELECT id FROM {$this->get_table_name('partner')} 
             WHERE kod_vouchera = %s AND kod_zabezpieczajacy = %s AND partner = %s",
            array($data['kod_vouchera'], $data['kod_zabezpieczajacy'], $data['partner']),
            'var'
        );
        
        if ($existing) {
            return array('success' => false, 'message' => 'Voucher z tymi kodami już istnieje w systemie.');
        }
        
        // Zapisz voucher
        $voucher_data = array(
            'partner' => $data['partner'],
            'typ_vouchera' => $data['typ_vouchera'],
            'kod_vouchera' => $data['kod_vouchera'],
            'kod_zabezpieczajacy' => $data['kod_zabezpieczajacy'],
            'data_waznosci_vouchera' => $data['data_waznosci'],
            'liczba_osob' => $data['liczba_osob'],
            'dane_pasazerow' => wp_json_encode($data['dane_pasazerow']),
            'status' => 'oczekuje',
            'klient_id' => $data['klient_id'],
            'data_zgloszenia' => current_time('mysql')
        );
        
        $result = $this->db->query(
            "INSERT INTO {$this->get_table_name('partner')} 
             (partner, typ_vouchera, kod_vouchera, kod_zabezpieczajacy, data_waznosci_vouchera, 
              liczba_osob, dane_pasazerow, status, klient_id, data_zgloszenia) 
             VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %d, %s)",
            array_values($voucher_data),
            'var'
        );
        
        if ($result !== false) {
            $voucher_id = $this->db->wpdb->insert_id;
            
            // Wyślij notyfikację do admina
            $this->send_partner_voucher_notification($voucher_id);
            
            do_action('srl_partner_voucher_submitted', $voucher_id, $data);
            
            return array('success' => true, 'voucher_id' => $voucher_id);
        }
        
        return array('success' => false, 'message' => 'Błąd zapisu do bazy danych.');
    }

    /**
     * Zatwierdza voucher partnera
     */
    public function approve_partner_voucher($voucher_id, $validity_date = null) {
        $voucher = $this->get_partner_voucher($voucher_id);
        if (!$voucher || $voucher['status'] !== 'oczekuje') {
            return array('success' => false, 'message' => 'Voucher nie istnieje lub już został przetworzony.');
        }
        
        $this->db->wpdb->query('START TRANSACTION');
        
        try {
            // Zaktualizuj datę ważności jeśli podana
            if ($validity_date) {
                $this->db->query(
                    "UPDATE {$this->get_table_name('partner')} 
                     SET data_waznosci_vouchera = %s WHERE id = %d",
                    array($validity_date, $voucher_id),
                    'var'
                );
                $voucher['data_waznosci_vouchera'] = $validity_date;
            }
            
            // Zmień status na zatwierdzony
            $this->db->query(
                "UPDATE {$this->get_table_name('partner')} 
                 SET status = 'zatwierdzony', data_modyfikacji = %s WHERE id = %d",
                array(current_time('mysql'), $voucher_id),
                'var'
            );
            
            // Utwórz loty dla każdego pasażera
            $created_flights = $this->create_flights_from_partner_voucher($voucher);
            
            if (empty($created_flights)) {
                throw new Exception('Nie udało się utworzyć lotów dla pasażerów.');
            }
            
            $this->db->wpdb->query('COMMIT');
            
            // Wyślij email do klienta
            $this->send_partner_voucher_approval_email($voucher_id, $created_flights);
            
            do_action('srl_partner_voucher_approved', $voucher_id, $created_flights);
            
            return array(
                'success' => true,
                'message' => 'Voucher zatwierdzony. Utworzono ' . count($created_flights) . ' lotów.',
                'created_flights' => $created_flights
            );
            
        } catch (Exception $e) {
            $this->db->wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Odrzuca voucher partnera
     */
    public function reject_partner_voucher($voucher_id, $reason) {
        $voucher = $this->get_partner_voucher($voucher_id);
        if (!$voucher || $voucher['status'] !== 'oczekuje') {
            return array('success' => false, 'message' => 'Voucher nie istnieje lub już został przetworzony.');
        }
        
        $result = $this->db->query(
            "UPDATE {$this->get_table_name('partner')} 
             SET status = 'odrzucony', powod_odrzucenia = %s, data_modyfikacji = %s 
             WHERE id = %d",
            array($reason, current_time('mysql'), $voucher_id),
            'var'
        );
        
        if ($result !== false) {
            // Wyślij email do klienta
            $this->send_partner_voucher_rejection_email($voucher_id, $reason);
            
            do_action('srl_partner_voucher_rejected', $voucher_id, $reason);
            
            return array('success' => true, 'message' => 'Voucher został odrzucony.');
        }
        
        return array('success' => false, 'message' => 'Błąd aktualizacji statusu vouchera.');
    }

    /**
     * === METODY POMOCNICZE ===
     */

    /**
     * Generuje unikalny kod vouchera
     */
    public function generate_voucher_code($type = 'gift') {
        $config = $this->voucher_types[$type];
        $chars = $config['code_chars'];
        
        do {
            $code = '';
            for ($i = 0; $i < $config['code_length']; $i++) {
                $code .= $chars[mt_rand(0, strlen($chars) - 1)];
            }
            
            // Sprawdź unikalność
            $exists = $this->db->query(
                "SELECT COUNT(*) FROM {$this->get_table_name($type)} WHERE kod_vouchera = %s",
                array($code),
                'count'
            );
        } while ($exists > 0);
        
        return $code;
    }

    /**
     * Oblicza datę ważności vouchera
     */
    private function calculate_expiry_date($type, $from_date = null) {
        $years = $this->voucher_types[$type]['validity_years'];
        return SRL_Formatter::generate_expiry_date($from_date, $years);
    }

    /**
     * Pobiera voucher partnera ze szczegółami
     */
    public function get_partner_voucher($voucher_id) {
        $voucher = $this->db->query(
            "SELECT * FROM {$this->get_table_name('partner')} WHERE id = %d",
            array($voucher_id),
            'row',
            "partner_voucher_{$voucher_id}"
        );
        
        if ($voucher) {
            $voucher['dane_pasazerow'] = json_decode($voucher['dane_pasazerow'], true);
        }
        
        return $voucher;
    }

    /**
     * Pobiera listę voucherów partnera
     */
    public function get_partner_vouchers($filters = array()) {
        $defaults = array(
            'status' => null,
            'partner' => null,
            'limit' => 50,
            'order_by' => 'data_zgloszenia DESC'
        );
        $filters = array_merge($defaults, $filters);
        
        $where_conditions = array('1=1');
        $params = array();
        
        if ($filters['status']) {
            $where_conditions[] = "status = %s";
            $params[] = $filters['status'];
        }
        
        if ($filters['partner']) {
            $where_conditions[] = "partner = %s";
            $params[] = $filters['partner'];
        }
        
        $sql = "SELECT * FROM {$this->get_table_name('partner')} 
                WHERE " . implode(' AND ', $where_conditions) . " 
                ORDER BY {$filters['order_by']} 
                LIMIT %d";
        $params[] = $filters['limit'];
        
        return $this->db->query($sql, $params, 'results');
    }

    /**
     * Oznacza przeterminowane vouchery
     */
    public function mark_expired_vouchers() {
        // Vouchery upominkowe
        $this->db->query(
            "UPDATE {$this->get_table_name('gift')} 
             SET status = 'przeterminowany' 
             WHERE status = 'do_wykorzystania' AND data_waznosci < CURDATE()",
            array(),
            'var'
        );
        
        // Można dodać logikę dla voucherów partnera jeśli potrzebne
        
        do_action('srl_vouchers_expired_marked');
    }

    /**
     * Pobiera statystyki voucherów
     */
    public function get_voucher_stats() {
        $stats = array();
        
        // Statystyki voucherów upominkowych
        $stats['gift'] = $this->db->query(
            "SELECT status, COUNT(*) as count FROM {$this->get_table_name('gift')} GROUP BY status",
            array(),
            'results',
            'gift_voucher_stats'
        );
        
        // Statystyki voucherów partnera
        $stats['partner'] = $this->db->query(
            "SELECT status, COUNT(*) as count FROM {$this->get_table_name('partner')} GROUP BY status",
            array(),
            'results',
            'partner_voucher_stats'
        );
        
        return $stats;
    }

    /**
     * === PRYWATNE METODY POMOCNICZE ===
     */

    private function get_table_name($type) {
        $table_key = $this->voucher_types[$type]['table'];
        return $this->db->wpdb->prefix . 'srl_' . $table_key;
    }

    private function validate_partner_voucher_data($data) {
        $errors = array();
        
        // Sprawdź partnera
        if (empty($data['partner']) || !isset($this->partner_config[$data['partner']])) {
            $errors[] = 'Nieprawidłowy partner.';
        }
        
        // Sprawdź typ vouchera
        if (empty($data['typ_vouchera']) || !isset($this->partner_config[$data['partner']]['typy'][$data['typ_vouchera']])) {
            $errors[] = 'Nieprawidłowy typ vouchera.';
        }
        
        // Sprawdź kody
        if (empty($data['kod_vouchera']) || strlen($data['kod_vouchera']) < 3) {
            $errors[] = 'Kod vouchera musi mieć co najmniej 3 znaki.';
        }
        
        if (empty($data['kod_zabezpieczajacy']) || strlen($data['kod_zabezpieczajacy']) < 3) {
            $errors[] = 'Kod zabezpieczający musi mieć co najmniej 3 znaki.';
        }
        
        // Sprawdź datę ważności
        if (empty($data['data_waznosci'])) {
            $errors[] = 'Data ważności vouchera jest wymagana.';
        } elseif (SRL_Formatter::is_date_past($data['data_waznosci'])) {
            $errors[] = 'Data ważności vouchera nie może być z przeszłości.';
        }
        
        // Sprawdź dane pasażerów
        if (empty($data['dane_pasazerow']) || !is_array($data['dane_pasazerow'])) {
            $errors[] = 'Brak danych pasażerów.';
        } else {
            foreach ($data['dane_pasazerow'] as $index => $pasazer) {
                $walidacja_pasazera = SRL_Validator::validate_passenger_data($pasazer);
                if (!$walidacja_pasazera['valid']) {
                    foreach ($walidacja_pasazera['errors'] as $field => $error) {
                        $errors[] = "Pasażer " . ($index + 1) . " - {$error}";
                    }
                }
            }
        }
        
        return array('valid' => empty($errors), 'errors' => $errors);
    }

    private function create_flight_from_voucher($voucher, $user, $expiry_date) {
        // Fallback gdy nie ma Flight_Manager
        $opcje_produktu = SRL_Formatter::detect_flight_options($voucher['nazwa_produktu']);
        
        $result = $this->db->query(
            "INSERT INTO {$this->db->wpdb->prefix}srl_zakupione_loty 
             (order_item_id, order_id, user_id, imie, nazwisko, nazwa_produktu, status, 
              data_zakupu, data_waznosci, ma_filmowanie, ma_akrobacje) 
             VALUES (%d, %d, %d, %s, %s, %s, %s, %s, %s, %d, %d)",
            array(
                $voucher['order_item_id'], $voucher['order_id'], $user->ID,
                $user->first_name ?: 'Voucher', $user->last_name ?: 'User',
                $voucher['nazwa_produktu'], 'wolny', current_time('mysql'),
                $expiry_date, $voucher['ma_filmowanie'], $voucher['ma_akrobacje']
            ),
            'var'
        );
        
        return $result !== false ? $this->db->wpdb->insert_id : false;
    }

    private function create_flights_from_partner_voucher($voucher) {
        $created_flights = array();
        $data_waznosci = $voucher['data_waznosci_vouchera'] ?: SRL_Formatter::generate_expiry_date(null, 1);
        $data_zakupu = current_time('mysql');
        
        foreach ($voucher['dane_pasazerow'] as $index => $pasazer) {
            if ($this->flight_manager) {
                $flight_result = $this->flight_manager->create_flight(array(
                    'order_item_id' => 0,
                    'order_id' => 0,
                    'user_id' => $voucher['klient_id'],
                    'imie' => $pasazer['imie'],
                    'nazwisko' => $pasazer['nazwisko'],
                    'nazwa_produktu' => 'Lot tandemowy - Voucher ' . $this->partner_config[$voucher['partner']]['nazwa'],
                    'data_waznosci' => $data_waznosci,
                    'dane_pasazera' => wp_json_encode($pasazer),
                    'source' => 'partner_voucher'
                ));
                
                if ($flight_result['success']) {
                    $created_flights[] = $flight_result['flight_id'];
                }
            } else {
                // Fallback
                $flight_id = $this->create_partner_flight_fallback($voucher, $pasazer, $data_zakupu, $data_waznosci);
                if ($flight_id) {
                    $created_flights[] = $flight_id;
                }
            }
        }
        
        return $created_flights;
    }

    private function create_partner_flight_fallback($voucher, $pasazer, $data_zakupu, $data_waznosci) {
        $result = $this->db->query(
            "INSERT INTO {$this->db->wpdb->prefix}srl_zakupione_loty 
             (order_item_id, order_id, user_id, imie, nazwisko, nazwa_produktu, status, 
              data_zakupu, data_waznosci, ma_filmowanie, ma_akrobacje, dane_pasazera) 
             VALUES (%d, %d, %d, %s, %s, %s, %s, %s, %s, %d, %d, %s)",
            array(
                0, 0, $voucher['klient_id'], $pasazer['imie'], $pasazer['nazwisko'],
                'Lot tandemowy - Voucher ' . $this->partner_config[$voucher['partner']]['nazwa'],
                'wolny', $data_zakupu, $data_waznosci, 0, 0, wp_json_encode($pasazer)
            ),
            'var'
        );
        
        return $result !== false ? $this->db->wpdb->insert_id : false;
    }

    /**
     * === METODY EMAIL ===
     */

    private function send_voucher_email($code, $product_name, $expiry_date, $buyer_data) {
        if (function_exists('srl_wyslij_email_voucher')) {
            return srl_wyslij_email_voucher(
                $buyer_data['email'] ?? '',
                $code,
                $product_name,
                $expiry_date,
                trim($buyer_data['imie'] . ' ' . $buyer_data['nazwisko'])
            );
        }
        return false;
    }

    private function send_partner_voucher_notification($voucher_id) {
        if (function_exists('srl_wyslij_email_administratora')) {
            $voucher = $this->get_partner_voucher($voucher_id);
            $user = get_userdata($voucher['klient_id']);
            
            $partner_name = $this->partner_config[$voucher['partner']]['nazwa'];
            $voucher_type_name = $this->partner_config[$voucher['partner']]['typy'][$voucher['typ_vouchera']]['nazwa'];
            
            $message = "Został zgłoszony nowy voucher partnera do weryfikacji.\n\n";
            $message .= "Szczegóły:\n";
            $message .= "Partner: {$partner_name}\n";
            $message .= "Typ: {$voucher_type_name}\n";
            $message .= "Kod vouchera: {$voucher['kod_vouchera']}\n";
            $message .= "Kod zabezpieczający: {$voucher['kod_zabezpieczajacy']}\n";
            $message .= "Liczba osób: {$voucher['liczba_osob']}\n";
            $message .= "Klient: {$user->display_name} ({$user->user_email})\n";
            $message .= "Data zgłoszenia: {$voucher['data_zgloszenia']}\n\n";
            $message .= "Link do panelu: " . admin_url('admin.php?page=srl-voucher') . "\n";
            
            return srl_wyslij_email_administratora('Nowy voucher partnera do weryfikacji', $message);
        }
        return false;
    }

    private function send_partner_voucher_approval_email($voucher_id, $created_flights) {
        if (function_exists('srl_send_partner_voucher_approval_email')) {
            return srl_send_partner_voucher_approval_email($voucher_id, $created_flights);
        }
        return false;
    }

    private function send_partner_voucher_rejection_email($voucher_id, $reason) {
        if (function_exists('srl_send_partner_voucher_rejection_email')) {
            return srl_send_partner_voucher_rejection_email($voucher_id, $reason);
        }
        return false;
    }

    /**
     * === PUBLICZNE GETTERY DLA KONFIGURACJI ===
     */

    public function get_partner_config() {
        return $this->partner_config;
    }

    public function get_partner_voucher_types($partner_key) {
        return $this->partner_config[$partner_key]['typy'] ?? array();
    }

    public function get_voucher_passenger_count($partner_key, $voucher_type) {
        return $this->partner_config[$partner_key]['typy'][$voucher_type]['liczba_osob'] ?? 1;
    }

    public function get_partners_list() {
        $partners = array();
        foreach ($this->partner_config as $key => $partner) {
            $partners[$key] = $partner['nazwa'];
        }
        return $partners;
    }

    /**
     * === ADMIN PANEL HELPERS ===
     */

    /**
     * Pobiera vouchery upominkowe z filtrami
     */
    public function get_gift_vouchers($filters = array()) {
        $defaults = array(
            'status' => null,
            'search' => null,
            'limit' => 20,
            'offset' => 0,
            'order_by' => 'data_zakupu DESC'
        );
        $filters = array_merge($defaults, $filters);
        
        $where_conditions = array('1=1');
        $params = array();
        
        if ($filters['status']) {
            $where_conditions[] = "v.status = %s";
            $params[] = $filters['status'];
        }
        
        if ($filters['search']) {
            $where_conditions[] = "(v.buyer_imie LIKE %s OR v.buyer_nazwisko LIKE %s OR v.kod_vouchera LIKE %s OR v.nazwa_produktu LIKE %s OR o.ID LIKE %s)";
            $search_param = '%' . $filters['search'] . '%';
            $params = array_merge($params, array_fill(0, 5, $search_param));
        }
        
        $sql = "SELECT v.*, 
                       buyer.user_email as buyer_email, 
                       user.user_email as user_email, 
                       user.display_name as user_display_name, 
                       o.post_status as order_status
                FROM {$this->get_table_name('gift')} v
                LEFT JOIN {$this->db->wpdb->users} buyer ON v.buyer_user_id = buyer.ID
                LEFT JOIN {$this->db->wpdb->users} user ON v.wykorzystany_przez_user_id = user.ID
                LEFT JOIN {$this->db->wpdb->posts} o ON v.order_id = o.ID
                WHERE " . implode(' AND ', $where_conditions) . "
                ORDER BY {$filters['order_by']}
                LIMIT %d OFFSET %d";
        
        $params[] = $filters['limit'];
        $params[] = $filters['offset'];
        
        return $this->db->query($sql, $params, 'results');
    }

    /**
     * Zlicza vouchery upominkowe z filtrami
     */
    public function count_gift_vouchers($filters = array()) {
        $where_conditions = array('1=1');
        $params = array();
        
        if (isset($filters['status']) && $filters['status']) {
            $where_conditions[] = "v.status = %s";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['search']) && $filters['search']) {
            $where_conditions[] = "(v.buyer_imie LIKE %s OR v.buyer_nazwisko LIKE %s OR v.kod_vouchera LIKE %s OR v.nazwa_produktu LIKE %s)";
            $search_param = '%' . $filters['search'] . '%';
            $params = array_merge($params, array_fill(0, 4, $search_param));
        }
        
        $sql = "SELECT COUNT(*) 
                FROM {$this->get_table_name('gift')} v
                WHERE " . implode(' AND ', $where_conditions);
        
        return $this->db->query($sql, $params, 'count');
    }

    /**
     * Dodaje voucher ręcznie (przez admina)
     */
    public function create_manual_voucher($data) {
        $validation = SRL_Validator::validate_field('kod_vouchera', $data['kod_vouchera']);
        if (!$validation['valid']) {
            return array('success' => false, 'message' => implode(' ', $validation['errors']));
        }
        
        // Sprawdź czy kod już istnieje
        $existing = $this->db->query(
            "SELECT COUNT(*) FROM {$this->get_table_name('gift')} WHERE kod_vouchera = %s",
            array($validation['value']),
            'count'
        );
        
        if ($existing > 0) {
            return array('success' => false, 'message' => 'Voucher z tym kodem już istnieje.');
        }
        
        // Walidacja daty
        $date_validation = SRL_Validator::validate_field('data', $data['data_waznosci']);
        if (!$date_validation['valid']) {
            return array('success' => false, 'message' => 'Nieprawidłowa data ważności: ' . implode(' ', $date_validation['errors']));
        }
        
        if (SRL_Formatter::is_date_past($data['data_waznosci'])) {
            return array('success' => false, 'message' => 'Data ważności nie może być z przeszłości.');
        }
        
        $current_user = wp_get_current_user();
        $voucher_data = array(
            'order_item_id' => 0,
            'order_id' => 0,
            'buyer_user_id' => $current_user->ID,
            'buyer_imie' => $data['buyer_imie'] ?: 'Admin',
            'buyer_nazwisko' => $data['buyer_nazwisko'] ?: 'Manual',
            'nazwa_produktu' => $data['nazwa_produktu'],
            'kod_vouchera' => $validation['value'],
            'status' => 'do_wykorzystania',
            'data_zakupu' => current_time('mysql'),
            'data_waznosci' => $data['data_waznosci'],
            'ma_filmowanie' => intval($data['ma_filmowanie']) ? 1 : 0,
            'ma_akrobacje' => intval($data['ma_akrobacje']) ? 1 : 0
        );
        
        $result = $this->db->query(
            "INSERT INTO {$this->get_table_name('gift')} 
             (order_item_id, order_id, buyer_user_id, buyer_imie, buyer_nazwisko, nazwa_produktu, 
              kod_vouchera, status, data_zakupu, data_waznosci, ma_filmowanie, ma_akrobacje) 
             VALUES (%d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %d, %d)",
            array_values($voucher_data),
            'var'
        );
        
        if ($result !== false) {
            $voucher_id = $this->db->wpdb->insert_id;
            
            do_action('srl_manual_voucher_created', $voucher_id, $voucher_data);
            
            return array(
                'success' => true,
                'voucher_id' => $voucher_id,
                'message' => 'Voucher został dodany pomyślnie.'
            );
        }
        
        return array('success' => false, 'message' => 'Błąd podczas dodawania vouchera do bazy danych.');
    }

    /**
     * Usuwanie voucherów po anulowaniu zamówienia
     */
    public function delete_vouchers_by_order($order_id) {
        $deleted_count = $this->db->query(
            "DELETE FROM {$this->get_table_name('gift')} 
             WHERE order_id = %d AND status = 'do_wykorzystania'",
            array($order_id),
            'var'
        );
        
        if ($deleted_count > 0) {
            do_action('srl_vouchers_deleted_by_order', $order_id, $deleted_count);
        }
        
        return $deleted_count;
    }

    /**
     * === INTEGRACJA Z WOOCOMMERCE ===
     */

    /**
     * Sprawdza czy produkt to voucher
     */
    public function is_voucher_product($product) {
        if (!$product) return false;
        
        $voucher_product_ids = array(105, 106, 107, 108); // Można przenieść do konfiguracji
        return in_array($product->get_id(), $voucher_product_ids);
    }

    /**
     * Przetwarza zamówienie i tworzy vouchery
     */
    public function process_order_vouchers($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return 0;
        
        // Sprawdź czy już przetworzono
        $existing = $this->db->query(
            "SELECT COUNT(*) FROM {$this->get_table_name('gift')} WHERE order_id = %d",
            array($order_id),
            'count'
        );
        
        if ($existing > 0) {
            return 0; // Już przetworzono
        }
        
        $user_id = $order->get_user_id();
        if (!$user_id) return 0;
        
        $buyer_data = array(
            'user_id' => $user_id,
            'imie' => $order->get_billing_first_name(),
            'nazwisko' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email()
        );
        
        $created_count = 0;
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if ($product && $this->is_voucher_product($product)) {
                $quantity = $item->get_quantity();
                $product_name = $item->get_name();
                
                // Wykryj opcje z nazwy produktu
                $options = SRL_Formatter::detect_flight_options($product_name);
                
                for ($i = 0; $i < $quantity; $i++) {
                    $result = $this->create_gift_voucher($order_id, $item_id, $product_name, $buyer_data, $options);
                    if ($result['success']) {
                        $created_count++;
                    }
                }
            }
        }
        
        return $created_count;
    }
}

// Funkcje kompatybilności wstecznej
function srl_generuj_kod_vouchera() {
    return SRL_Voucher_Manager::getInstance()->generate_voucher_code('gift');
}

function srl_czy_produkt_vouchera($product) {
    return SRL_Voucher_Manager::getInstance()->is_voucher_product($product);
}

function srl_dodaj_vouchery_po_zakupie($order_id) {
    return SRL_Voucher_Manager::getInstance()->process_order_vouchers($order_id);
}

function srl_usun_vouchery_zamowienia($order_id) {
    return SRL_Voucher_Manager::getInstance()->delete_vouchers_by_order($order_id);
}

function srl_wykorzystaj_voucher($kod_vouchera, $user_id) {
    return SRL_Voucher_Manager::getInstance()->redeem_gift_voucher($kod_vouchera, $user_id);
}

function srl_oznacz_przeterminowane_vouchery() {
    return SRL_Voucher_Manager::getInstance()->mark_expired_vouchers();
}

function srl_get_partner_voucher_config() {
    return SRL_Voucher_Manager::getInstance()->get_partner_config();
}

function srl_get_partner_voucher_types($partner_key) {
    return SRL_Voucher_Manager::getInstance()->get_partner_voucher_types($partner_key);
}

function srl_get_voucher_passenger_count($partner_key, $voucher_type) {
    return SRL_Voucher_Manager::getInstance()->get_voucher_passenger_count($partner_key, $voucher_type);
}

function srl_save_partner_voucher($data) {
    return SRL_Voucher_Manager::getInstance()->submit_partner_voucher($data);
}

function srl_get_partner_voucher($voucher_id) {
    return SRL_Voucher_Manager::getInstance()->get_partner_voucher($voucher_id);
}

function srl_approve_partner_voucher($voucher_id) {
    return SRL_Voucher_Manager::getInstance()->approve_partner_voucher($voucher_id);
}

function srl_reject_partner_voucher($voucher_id, $reason) {
    return SRL_Voucher_Manager::getInstance()->reject_partner_voucher($voucher_id, $reason);
}

function srl_get_partner_vouchers($status = null, $limit = 50) {
    $filters = array('limit' => $limit);
    if ($status) $filters['status'] = $status;
    return SRL_Voucher_Manager::getInstance()->get_partner_vouchers($filters);
}