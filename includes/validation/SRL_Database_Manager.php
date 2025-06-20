<?php
/**
 * Zoptymalizowany manager bazy danych
 * Zastępuje rozproszone zapytania SQL i optymalizuje cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Database_Manager {
    
    private static $instance = null;
    private static $cache = array();
    private static $cache_ttl = 300; // 5 minut
    private $wpdb;
    
    // Nazwy tabel
    private $tables = array();
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Inicjalizuj nazwy tabel
        $this->tables = array(
            'terminy' => $wpdb->prefix . 'srl_terminy',
            'loty' => $wpdb->prefix . 'srl_zakupione_loty', 
            'vouchery' => $wpdb->prefix . 'srl_vouchery_upominkowe',
            'vouchery_partnerzy' => $wpdb->prefix . 'srl_vouchery_partnerzy',
            'users' => $wpdb->users,
            'usermeta' => $wpdb->usermeta,
            'posts' => $wpdb->posts
        );
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Wykonuje zapytanie z cache i optymalizacją
     */
    public function query($sql, $params = array(), $return_type = 'results', $cache_key = null, $ttl = null) {
        // Przygotuj cache key
        if ($cache_key) {
            $full_cache_key = $this->generate_cache_key($cache_key, $params);
            
            // Sprawdź cache
            $cached = $this->get_cache($full_cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Przygotuj zapytanie
        $prepared = !empty($params) ? $this->wpdb->prepare($sql, ...$params) : $sql;
        
        // Wykonaj zapytanie
        switch ($return_type) {
            case 'var':
                $result = $this->wpdb->get_var($prepared);
                break;
            case 'row':
                $result = $this->wpdb->get_row($prepared, ARRAY_A);
                break;
            case 'count':
                $result = intval($this->wpdb->get_var($prepared));
                break;
            case 'col':
                $result = $this->wpdb->get_col($prepared);
                break;
            default:
                $result = $this->wpdb->get_results($prepared, ARRAY_A);
        }
        
        // Zapisz w cache jeśli jest cache_key
        if ($cache_key && $result !== false) {
            $this->set_cache($full_cache_key, $result, $ttl ?? self::$cache_ttl);
        }
        
        return $result;
    }

    /**
     * Pobiera loty użytkownika z optymalizacją
     */
    public function get_user_flights($user_id, $options = array()) {
        $defaults = array(
            'status' => null,
            'include_expired' => false,
            'include_terminated' => true,
            'future_only' => false,
            'with_voucher_info' => true,
            'order_by' => 'data_zakupu DESC',
            'limit' => null
        );
        
        $options = array_merge($defaults, $options);
        
        // Buduj WHERE clause
        $where_conditions = array('zl.user_id = %d');
        $params = array($user_id);
        
        if ($options['status']) {
            if (is_array($options['status'])) {
                $placeholders = implode(',', array_fill(0, count($options['status']), '%s'));
                $where_conditions[] = "zl.status IN ($placeholders)";
                $params = array_merge($params, $options['status']);
            } else {
                $where_conditions[] = "zl.status = %s";
                $params[] = $options['status'];
            }
        }
        
        if (!$options['include_expired']) {
            $where_conditions[] = "zl.data_waznosci >= CURDATE()";
        }
        
        if ($options['future_only']) {
            $where_conditions[] = "(t.data IS NULL OR t.data >= CURDATE())";
        }
        
        // Buduj zapytanie
        $select_fields = array(
            'zl.*',
            't.data as data_lotu',
            't.godzina_start', 
            't.godzina_koniec',
            't.pilot_id'
        );
        
        if ($options['with_voucher_info']) {
            $select_fields[] = 'v.kod_vouchera';
            $select_fields[] = 'v.buyer_imie as voucher_buyer_imie';
            $select_fields[] = 'v.buyer_nazwisko as voucher_buyer_nazwisko';
        }
        
        $joins = array(
            "LEFT JOIN {$this->tables['terminy']} t ON zl.termin_id = t.id"
        );
        
        if ($options['with_voucher_info']) {
            $joins[] = "LEFT JOIN {$this->tables['vouchery']} v ON zl.id = v.lot_id";
        }
        
        $sql = sprintf(
            "SELECT %s FROM {$this->tables['loty']} zl %s WHERE %s ORDER BY %s",
            implode(', ', $select_fields),
            implode(' ', $joins),
            implode(' AND ', $where_conditions),
            $options['order_by']
        );
        
        if ($options['limit']) {
            $sql .= " LIMIT " . intval($options['limit']);
        }
        
        $cache_key = $options['limit'] ? null : "user_flights_{$user_id}"; // Cache tylko bez limitu
        
        return $this->query($sql, $params, 'results', $cache_key);
    }

    /**
     * Pobiera szczegóły dnia z optymalizacją
     */
    public function get_day_schedule($date, $include_details = true) {
        $cache_key = "day_schedule_{$date}_" . ($include_details ? 'full' : 'basic');
        
        if ($include_details) {
            $sql = "SELECT t.*, 
                           zl.id as lot_id, 
                           zl.user_id as lot_user_id, 
                           zl.status as lot_status,
                           zl.dane_pasazera,
                           zl.imie as lot_imie,
                           zl.nazwisko as lot_nazwisko
                    FROM {$this->tables['terminy']} t
                    LEFT JOIN {$this->tables['loty']} zl ON t.id = zl.termin_id
                    WHERE t.data = %s 
                    ORDER BY t.pilot_id ASC, t.godzina_start ASC";
                    
            $slots = $this->query($sql, array($date), 'results', $cache_key);
            return $this->process_detailed_schedule($slots);
        } else {
            // Tylko wolne sloty
            $sql = "SELECT id, pilot_id, godzina_start, godzina_koniec, status 
                    FROM {$this->tables['terminy']} 
                    WHERE data = %s AND status = 'Wolny'
                    ORDER BY pilot_id ASC, godzina_start ASC";
                    
            return $this->query($sql, array($date), 'results', $cache_key);
        }
    }

    /**
     * Przetwarza szczegółowy harmonogram dnia
     */
    private function process_detailed_schedule($slots) {
        $grouped = array();
        $user_cache = array();
        
        foreach ($slots as $slot) {
            $pilot_id = intval($slot['pilot_id']);
            if (!isset($grouped[$pilot_id])) {
                $grouped[$pilot_id] = array();
            }
            
            $client_name = '';
            $passenger_data = null;
            
            // Obsługa klienta zarezerwowanego/zrealizowanego
            if (($slot['status'] === 'Zarezerwowany' || $slot['status'] === 'Zrealizowany') && $slot['klient_id']) {
                $user_id = intval($slot['klient_id']);
                
                // Cache danych użytkownika
                if (!isset($user_cache[$user_id])) {
                    $user_cache[$user_id] = $this->get_user_data($user_id);
                }
                
                $user_data = $user_cache[$user_id];
                if ($user_data) {
                    $client_name = $this->format_client_name($user_data, $slot);
                    $passenger_data = $this->get_passenger_data($slot, $user_data);
                }
            } 
            // Obsługa lotu prywatnego
            elseif ($slot['status'] === 'Prywatny' && $slot['notatka']) {
                $private_data = json_decode($slot['notatka'], true);
                if ($private_data && isset($private_data['imie'], $private_data['nazwisko'])) {
                    $client_name = $private_data['imie'] . ' ' . $private_data['nazwisko'];
                    $passenger_data = $private_data;
                }
            }
            
            $grouped[$pilot_id][] = array(
                'id' => intval($slot['id']),
                'start' => substr($slot['godzina_start'], 0, 5),
                'koniec' => substr($slot['godzina_koniec'], 0, 5),
                'status' => $slot['status'],
                'klient_id' => intval($slot['klient_id']),
                'klient_nazwa' => $client_name,
                'lot_id' => $slot['lot_id'] ? intval($slot['lot_id']) : null,
                'notatka' => $slot['notatka'],
                'dane_pasazera_cache' => $passenger_data
            );
        }
        
        return $grouped;
    }

    /**
     * Pobiera dane użytkownika z cache
     */
    public function get_user_data($user_id, $force_refresh = false) {
        $cache_key = "user_data_{$user_id}";
        
        if (!$force_refresh) {
            $cached = $this->get_cache($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }
        
        // Pobierz meta dane jednym zapytaniem
        $meta_keys = array('srl_imie', 'srl_nazwisko', 'srl_rok_urodzenia', 'srl_telefon', 
                          'srl_kategoria_wagowa', 'srl_sprawnosc_fizyczna', 'srl_uwagi');
        
        $sql = "SELECT meta_key, meta_value 
                FROM {$this->tables['usermeta']} 
                WHERE user_id = %d AND meta_key IN ('" . implode("','", $meta_keys) . "')";
                
        $meta_data = $this->query($sql, array($user_id), 'results');
        
        $result = array(
            'id' => $user_id,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name
        );
        
        // Mapuj meta dane
        foreach ($meta_data as $meta) {
            $key = str_replace('srl_', '', $meta['meta_key']);
            $result[$key] = $meta['meta_value'];
        }
        
        $this->set_cache($cache_key, $result);
        return $result;
    }

    /**
     * Pobiera dostępne dni z lotami
     */
    public function get_available_days($year, $month) {
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $sql = "SELECT data, COUNT(*) as wolne_sloty
                FROM {$this->tables['terminy']} 
                WHERE data BETWEEN %s AND %s 
                AND status = 'Wolny'
                AND data >= CURDATE()
                GROUP BY data
                HAVING wolne_sloty > 0
                ORDER BY data ASC";
                
        $cache_key = "available_days_{$year}_{$month}";
        $results = $this->query($sql, array($start_date, $end_date), 'results', $cache_key);
        
        $available_days = array();
        foreach ($results as $row) {
            $available_days[$row['data']] = intval($row['wolne_sloty']);
        }
        
        return $available_days;
    }

    /**
     * Sprawdza dostępność slotu
     */
    public function is_slot_available($slot_id) {
        $sql = "SELECT COUNT(*) FROM {$this->tables['terminy']} 
                WHERE id = %d AND status = 'Wolny'";
                
        return $this->query($sql, array($slot_id), 'count') > 0;
    }

    /**
     * Pobiera szczegóły slotu
     */
    public function get_slot_details($slot_id) {
        $sql = "SELECT * FROM {$this->tables['terminy']} WHERE id = %d";
        return $this->query($sql, array($slot_id), 'row', "slot_details_{$slot_id}");
    }

    /**
     * Pobiera szczegóły lotu
     */
    public function get_flight_details($flight_id) {
        $sql = "SELECT zl.*, t.data as data_lotu, t.godzina_start, t.godzina_koniec, t.pilot_id
                FROM {$this->tables['loty']} zl
                LEFT JOIN {$this->tables['terminy']} t ON zl.termin_id = t.id
                WHERE zl.id = %d";
                
        return $this->query($sql, array($flight_id), 'row', "flight_details_{$flight_id}");
    }

    /**
     * Transakcja - rezerwuje slot
     */
    public function reserve_slot($slot_id, $flight_id, $user_id) {
        $this->wpdb->query('START TRANSACTION');
        
        try {
            // Sprawdź dostępność
            if (!$this->is_slot_available($slot_id)) {
                throw new Exception('Slot nie jest już dostępny.');
            }
            
            // Aktualizuj slot
            $result1 = $this->wpdb->update(
                $this->tables['terminy'],
                array('status' => 'Zarezerwowany', 'klient_id' => $user_id),
                array('id' => $slot_id),
                array('%s', '%d'),
                array('%d')
            );
            
            // Aktualizuj lot
            $result2 = $this->wpdb->update(
                $this->tables['loty'],
                array(
                    'status' => 'zarezerwowany',
                    'termin_id' => $slot_id,
                    'data_rezerwacji' => current_time('mysql')
                ),
                array('id' => $flight_id),
                array('%s', '%d', '%s'),
                array('%d')
            );
            
            if ($result1 === false || $result2 === false) {
                throw new Exception('Błąd podczas rezerwacji.');
            }
            
            $this->wpdb->query('COMMIT');
            
            // Wyczyść cache
            $this->clear_cache_pattern('day_schedule_');
            $this->clear_cache_pattern('user_flights_');
            
            return array('success' => true, 'message' => 'Rezerwacja wykonana pomyślnie.');
            
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Anuluje rezerwację
     */
    public function cancel_reservation($flight_id) {
        $flight = $this->get_flight_details($flight_id);
        if (!$flight || !$flight['termin_id']) {
            return array('success' => false, 'message' => 'Lot nie ma przypisanego terminu.');
        }
        
        $this->wpdb->query('START TRANSACTION');
        
        try {
            // Zwolnij slot
            $result1 = $this->wpdb->update(
                $this->tables['terminy'],
                array('status' => 'Wolny', 'klient_id' => null),
                array('id' => $flight['termin_id']),
                array('%s', '%d'),
                array('%d')
            );
            
            // Aktualizuj lot
            $result2 = $this->wpdb->update(
                $this->tables['loty'],
                array('status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null),
                array('id' => $flight_id),
                array('%s', '%d', '%s'),
                array('%d')
            );
            
            if ($result1 === false || $result2 === false) {
                throw new Exception('Błąd podczas anulowania rezerwacji.');
            }
            
            $this->wpdb->query('COMMIT');
            
            // Wyczyść cache
            $this->clear_cache_pattern('day_schedule_');
            $this->clear_cache_pattern('user_flights_');
            
            return array('success' => true, 'message' => 'Rezerwacja została anulowana.');
            
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Cache management
     */
    private function generate_cache_key($key, $params = array()) {
        return 'srl_' . $key . '_' . md5(serialize($params));
    }
    
    private function get_cache($key) {
        return get_transient($key);
    }
    
    private function set_cache($key, $value, $ttl = null) {
        return set_transient($key, $value, $ttl ?? self::$cache_ttl);
    }
    
    public function clear_cache($key = null) {
        if ($key) {
            delete_transient($key);
        } else {
            // Wyczyść wszystkie cache SRL
            $this->clear_cache_pattern('srl_');
        }
    }
    
    private function clear_cache_pattern($pattern) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_' . $pattern . '%'
        ));
    }

    /**
     * Pomocnicze metody formatowania
     */
    private function format_client_name($user_data, $slot) {
        // Najpierw sprawdź dane z lotu
        if (!empty($slot['lot_imie']) && !empty($slot['lot_nazwisko'])) {
            return $slot['lot_imie'] . ' ' . $slot['lot_nazwisko'];
        }
        
        // Potem z profilu użytkownika
        if (!empty($user_data['imie']) && !empty($user_data['nazwisko'])) {
            return $user_data['imie'] . ' ' . $user_data['nazwisko'];
        }
        
        return $user_data['display_name'];
    }
    
    private function get_passenger_data($slot, $user_data) {
        if (!empty($slot['dane_pasazera'])) {
            $decoded = json_decode($slot['dane_pasazera'], true);
            if ($decoded && is_array($decoded)) {
                return $decoded;
            }
        }
        
        return $user_data;
    }

    /**
     * Statystyki dla dashboard
     */
    public function get_dashboard_stats() {
        $cache_key = 'dashboard_stats';
        $cached = $this->get_cache($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $stats = array();
        
        // Statystyki lotów
        $stats['flights'] = $this->query(
            "SELECT status, COUNT(*) as count FROM {$this->tables['loty']} GROUP BY status",
            array(),
            'results'
        );
        
        // Rezerwacje na dziś i jutro
        $stats['upcoming'] = $this->query(
            "SELECT COUNT(*) FROM {$this->tables['loty']} zl 
             LEFT JOIN {$this->tables['terminy']} t ON zl.termin_id = t.id
             WHERE zl.status = 'zarezerwowany' AND t.data BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)",
            array(),
            'count'
        );
        
        // Vouchery
        if ($this->table_exists('vouchery')) {
            $stats['vouchers'] = $this->query(
                "SELECT status, COUNT(*) as count FROM {$this->tables['vouchery']} GROUP BY status",
                array(),
                'results'
            );
        }
        
        $this->set_cache($cache_key, $stats, 600); // 10 minut cache
        return $stats;
    }

    /**
     * Sprawdza czy tabela istnieje
     */
    private function table_exists($table_key) {
        if (!isset($this->tables[$table_key])) {
            return false;
        }
        
        $table_name = $this->tables[$table_key];
        return $this->wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
}

// Funkcje kompatybilności wstecznej
function srl_get_user_full_data($user_id) {
    return SRL_Database_Manager::getInstance()->get_user_data($user_id);
}

function srl_get_day_schedule_optimized($date) {
    return SRL_Database_Manager::getInstance()->get_day_schedule($date, true);
}

function srl_get_available_slots($date) {
    return SRL_Database_Manager::getInstance()->get_day_schedule($date, false);
}

function srl_is_slot_available($slot_id) {
    return SRL_Database_Manager::getInstance()->is_slot_available($slot_id);
}

function srl_get_slot_details($slot_id) {
    return SRL_Database_Manager::getInstance()->get_slot_details($slot_id);
}

function srl_get_flight_by_id($flight_id) {
    return SRL_Database_Manager::getInstance()->get_flight_details($flight_id);
}

function srl_reserve_slot($slot_id, $flight_id, $user_id) {
    return SRL_Database_Manager::getInstance()->reserve_slot($slot_id, $flight_id, $user_id);
}

function srl_cancel_reservation($flight_id) {
    return SRL_Database_Manager::getInstance()->cancel_reservation($flight_id);
}

// Nowa funkcja dla optymalizowanego pobierania lotów
function srl_get_user_flights($user_id, $status = null, $include_expired = false) {
    $options = array(
        'status' => $status,
        'include_expired' => $include_expired
    );
    return SRL_Database_Manager::getInstance()->get_user_flights($user_id, $options);
}