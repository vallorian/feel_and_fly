<?php if (!defined('ABSPATH')) {exit;}

class SRL_Database {
    private static $cache = array();
    private static $wpdb;
    
    public static function init() {
        global $wpdb;
        self::$wpdb = $wpdb;
    }
    
    public static function execute($query, $params = array(), $type = 'results', $cache_key = null) {
        if ($cache_key && isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }
        
        $prepared = !empty($params) ? self::$wpdb->prepare($query, ...$params) : $query;
        
        switch ($type) {
            case 'var': $result = self::$wpdb->get_var($prepared); break;
            case 'row': $result = self::$wpdb->get_row($prepared, ARRAY_A); break;
            case 'count': $result = intval(self::$wpdb->get_var($prepared)); break;
            default: $result = self::$wpdb->get_results($prepared, ARRAY_A);
        }
        
        if ($cache_key) self::$cache[$cache_key] = $result;
        return $result;
    }
    
    public static function get_user_data($user_id, $force_refresh = false) {
        $cache_key = "user_data_{$user_id}";
        if (!$force_refresh && isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }
        
        $user = get_userdata($user_id);
        if (!$user) return null;
        
        $meta_keys = array('srl_imie', 'srl_nazwisko', 'srl_rok_urodzenia', 'srl_telefon', 
                          'srl_kategoria_wagowa', 'srl_sprawnosc_fizyczna', 'srl_uwagi');
        $meta_data = self::execute(
            "SELECT meta_key, meta_value FROM {self::$wpdb->usermeta} 
             WHERE user_id = %d AND meta_key IN ('" . implode("','", $meta_keys) . "')",
            array($user_id)
        );
        
        $result = array(
            'id' => $user_id, 'email' => $user->user_email, 'display_name' => $user->display_name,
            'first_name' => $user->first_name, 'last_name' => $user->last_name
        );
        
        foreach ($meta_data as $meta) {
            $key = str_replace('srl_', '', $meta['meta_key']);
            $result[$key] = $meta['meta_value'];
        }
        
        self::$cache[$cache_key] = $result;
        return $result;
    }
    
    public static function get_flights($user_id = null, $filters = array()) {
        $tabela_loty = self::$wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = self::$wpdb->prefix . 'srl_terminy';
        
        $where = array('1=1');
        $params = array();
        
        if ($user_id) {
            $where[] = "zl.user_id = %d";
            $params[] = $user_id;
        }
        
        if (isset($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '%s'));
                $where[] = "zl.status IN ($placeholders)";
                $params = array_merge($params, $filters['status']);
            } else {
                $where[] = "zl.status = %s";
                $params[] = $filters['status'];
            }
        }
        
        if (isset($filters['include_expired']) && !$filters['include_expired']) {
            $where[] = "zl.data_waznosci >= CURDATE()";
        }
        
        if (isset($filters['future_only']) && $filters['future_only']) {
            $where[] = "(t.data IS NULL OR t.data >= CURDATE())";
        }
        
        $order = isset($filters['order']) ? $filters['order'] : 'zl.data_zakupu DESC';
        $limit = isset($filters['limit']) ? "LIMIT " . intval($filters['limit']) : '';
        
        $cache_key = isset($filters['cache']) ? $filters['cache'] : null;
        
        return self::execute(
            "SELECT zl.*, t.data as data_lotu, t.godzina_start, t.godzina_koniec, t.pilot_id,
                    v.kod_vouchera, v.buyer_imie as voucher_buyer_imie, v.buyer_nazwisko as voucher_buyer_nazwisko
             FROM $tabela_loty zl 
             LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
             LEFT JOIN {self::$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
             WHERE " . implode(' AND ', $where) . " ORDER BY $order $limit",
            $params, 'results', $cache_key
        );
    }
    
    public static function get_day_slots($date, $include_details = true) {
        $tabela_terminy = self::$wpdb->prefix . 'srl_terminy';
        $cache_key = "day_slots_{$date}_" . ($include_details ? 'full' : 'basic');
        
        if ($include_details) {
            $slots = self::execute(
                "SELECT t.*, zl.id as lot_id, zl.user_id as lot_user_id, zl.status as lot_status, zl.dane_pasazera
                 FROM $tabela_terminy t
                 LEFT JOIN {self::$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
                 WHERE t.data = %s ORDER BY t.pilot_id ASC, t.godzina_start ASC",
                array($date), 'results', $cache_key
            );
            
            return self::process_detailed_slots($slots);
        }
        
        return self::execute(
            "SELECT id, pilot_id, godzina_start, godzina_koniec, status 
             FROM $tabela_terminy WHERE data = %s AND status = 'Wolny'
             ORDER BY pilot_id ASC, godzina_start ASC",
            array($date), 'results', $cache_key
        );
    }
    
    private static function process_detailed_slots($slots) {
        $grouped = array();
        $user_cache = array();
        
        foreach ($slots as $slot) {
            $pilot_id = intval($slot['pilot_id']);
            if (!isset($grouped[$pilot_id])) $grouped[$pilot_id] = array();
            
            $client_name = '';
            $passenger_data = null;
            
            if (($slot['status'] === 'Zarezerwowany' || $slot['status'] === 'Zrealizowany') && $slot['klient_id']) {
                $user_id = intval($slot['klient_id']);
                
                if (!isset($user_cache[$user_id])) {
                    $user_cache[$user_id] = self::get_user_data($user_id);
                }
                
                $user_data = $user_cache[$user_id];
                if ($user_data) {
                    $client_name = ($user_data['imie'] && $user_data['nazwisko']) 
                        ? $user_data['imie'] . ' ' . $user_data['nazwisko']
                        : $user_data['display_name'];
                    
                    $passenger_data = !empty($slot['dane_pasazera']) 
                        ? json_decode($slot['dane_pasazera'], true) 
                        : $user_data;
                }
            } elseif ($slot['status'] === 'Prywatny' && $slot['notatka']) {
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
    
    public static function reserve_slot($slot_id, $flight_id, $user_id) {
        $tabela_terminy = self::$wpdb->prefix . 'srl_terminy';
        $tabela_loty = self::$wpdb->prefix . 'srl_zakupione_loty';
        
        self::$wpdb->query('START TRANSACTION');
        
        try {
            $available = self::execute(
                "SELECT COUNT(*) FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
                array($slot_id), 'count'
            );
            
            if (!$available) throw new Exception('Slot nie jest już dostępny.');
            
            $updates = array(
                self::$wpdb->update($tabela_terminy, 
                    array('status' => 'Zarezerwowany', 'klient_id' => $user_id),
                    array('id' => $slot_id), array('%s', '%d'), array('%d')),
                    
                self::$wpdb->update($tabela_loty,
                    array('status' => 'zarezerwowany', 'termin_id' => $slot_id, 'data_rezerwacji' => current_time('mysql')),
                    array('id' => $flight_id), array('%s', '%d', '%s'), array('%d'))
            );
            
            if (in_array(false, $updates)) throw new Exception('Błąd podczas rezerwacji.');
            
            self::$wpdb->query('COMMIT');
            self::clear_cache_pattern('day_slots_');
            return array('success' => true, 'message' => 'Rezerwacja wykonana pomyślnie.');
            
        } catch (Exception $e) {
            self::$wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    public static function cancel_reservation($flight_id) {
        $tabela_terminy = self::$wpdb->prefix . 'srl_terminy';
        $tabela_loty = self::$wpdb->prefix . 'srl_zakupione_loty';
        
        $lot = self::execute("SELECT * FROM $tabela_loty WHERE id = %d", array($flight_id), 'row');
        if (!$lot || !$lot['termin_id']) {
            return array('success' => false, 'message' => 'Lot nie ma przypisanego terminu.');
        }
        
        self::$wpdb->query('START TRANSACTION');
        
        try {
            $updates = array(
                self::$wpdb->update($tabela_terminy,
                    array('status' => 'Wolny', 'klient_id' => null),
                    array('id' => $lot['termin_id']), array('%s', '%d'), array('%d')),
                    
                self::$wpdb->update($tabela_loty,
                    array('status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null),
                    array('id' => $flight_id), array('%s', '%d', '%s'), array('%d'))
            );
            
            if (in_array(false, $updates)) throw new Exception('Błąd podczas anulowania rezerwacji.');
            
            self::$wpdb->query('COMMIT');
            self::clear_cache_pattern('day_slots_');
            return array('success' => true, 'message' => 'Rezerwacja została anulowana.');
            
        } catch (Exception $e) {
            self::$wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    public static function clear_cache($key = null) {
        if ($key) {
            unset(self::$cache[$key]);
        } else {
            self::$cache = array();
        }
    }
    
    private static function clear_cache_pattern($pattern) {
        foreach (array_keys(self::$cache) as $key) {
            if (strpos($key, $pattern) === 0) {
                unset(self::$cache[$key]);
            }
        }
    }
}

SRL_Database::init();

function srl_get_user_full_data($user_id) {
    return SRL_Database::get_user_data($user_id);
}

function srl_get_user_flights($user_id, $status = null, $include_expired = false) {
    $filters = array('include_expired' => $include_expired);
    if ($status) $filters['status'] = $status;
    return SRL_Database::get_flights($user_id, $filters);
}

function srl_get_available_slots($date) {
    return SRL_Database::get_day_slots($date, false);
}

function srl_get_day_schedule_optimized($date) {
    return SRL_Database::get_day_slots($date, true);
}

function srl_reserve_slot($slot_id, $flight_id, $user_id) {
    return SRL_Database::reserve_slot($slot_id, $flight_id, $user_id);
}

function srl_cancel_reservation($flight_id) {
    return SRL_Database::cancel_reservation($flight_id);
}

function srl_is_slot_available($slot_id) {
    return SRL_Database::execute(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}srl_terminy WHERE id = %d AND status = 'Wolny'",
        array($slot_id), 'count'
    ) > 0;
}

function srl_get_slot_details($slot_id) {
    return SRL_Database::execute(
        "SELECT * FROM {$GLOBALS['wpdb']->prefix}srl_terminy WHERE id = %d",
        array($slot_id), 'row'
    );
}

function srl_get_flight_by_id($flight_id) {
    return SRL_Database::execute(
        "SELECT * FROM {$GLOBALS['wpdb']->prefix}srl_zakupione_loty WHERE id = %d",
        array($flight_id), 'row'
    );
}

function srl_update_flight_status($flight_id, $new_status, $additional_data = array()) {
    global $wpdb;
    $update_data = array_merge(array('status' => $new_status), $additional_data);
    $format = array_fill(0, count($update_data), '%s');
    
    return $wpdb->update(
        $wpdb->prefix . 'srl_zakupione_loty', $update_data, array('id' => $flight_id), $format, array('%d')
    ) !== false;
}

function srl_format_slot_details($slot_id) {
    $slot = srl_get_slot_details($slot_id);
    return $slot ? sprintf('%s %s-%s', 
        srl_formatuj_date($slot['data']), 
        substr($slot['godzina_start'], 0, 5),
        substr($slot['godzina_koniec'], 0, 5)
    ) : 'nieznany termin';
}

function srl_zwroc_godziny_wg_pilota($data) {
    wp_send_json_success(array('godziny_wg_pilota' => srl_get_day_schedule_optimized(sanitize_text_field($data))));
}