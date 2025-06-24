<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Database_Helpers {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
    }

    public function executeQuery($query, $params = array(), $return_type = 'results') {
        global $wpdb;
        
        if (!$wpdb) {
            error_log('SRL: Database connection not available');
            return $return_type === 'results' ? [] : ($return_type === 'count' ? 0 : null);
        }
        
        $prepared_query = !empty($params) ? $wpdb->prepare($query, ...$params) : $query;
        
        switch ($return_type) {
            case 'var': return $wpdb->get_var($prepared_query);
            case 'row': return $wpdb->get_row($prepared_query, ARRAY_A);
            case 'count': return intval($wpdb->get_var($prepared_query));
            default: return $wpdb->get_results($prepared_query, ARRAY_A);
        }
    }

    public function getFlightById($flight_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'srl_zakupione_loty';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return null;
        }
        
        return $this->executeQuery(
            "SELECT * FROM $table WHERE id = %d",
            array($flight_id), 'row'
        );
    }

    public function getUserFlights($user_id, $status = null, $include_expired = false) {
        $cache_key = "user_flights_{$user_id}_{$status}_" . ($include_expired ? '1' : '0');
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_zakupione_loty';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$tabela'") != $tabela) {
            wp_cache_set($cache_key, [], 'srl_cache', 1800);
            return [];
        }
        
        $where_conditions = array("user_id = %d");
        $params = array($user_id);
        
        if (!$include_expired) {
            $where_conditions[] = "data_waznosci >= CURDATE()";
        }
        if ($status) {
            $where_conditions[] = "status = %s";
            $params[] = $status;
        }
        
        $where_clause = "WHERE " . implode(' AND ', $where_conditions);
        $result = $this->executeQuery(
            "SELECT * FROM $tabela $where_clause ORDER BY data_zakupu DESC",
            $params
        );
        
        wp_cache_set($cache_key, $result, 'srl_cache', 1800);
        return $result;
    }

    public function getAvailableSlots($date) {
        global $wpdb;
        $table = $wpdb->prefix . 'srl_terminy';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return [];
        }
        
        return $this->executeQuery(
            "SELECT id, pilot_id, godzina_start, godzina_koniec 
             FROM $table 
             WHERE data = %s AND status = 'Wolny'
             ORDER BY pilot_id ASC, godzina_start ASC",
            array($date)
        );
    }

    public function isSlotAvailable($slot_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'srl_terminy';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return false;
        }
        
        return $this->executeQuery(
            "SELECT COUNT(*) FROM $table WHERE id = %d AND status = 'Wolny'",
            array($slot_id), 'count'
        ) > 0;
    }

    public function getSlotDetails($slot_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'srl_terminy';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return null;
        }
        
        return $this->executeQuery(
            "SELECT * FROM $table WHERE id = %d",
            array($slot_id), 'row'
        );
    }

    public function reserveSlot($slot_id, $flight_id, $user_id) {
        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$tabela_terminy'") != $tabela_terminy ||
            $wpdb->get_var("SHOW TABLES LIKE '$tabela_loty'") != $tabela_loty) {
            return array('success' => false, 'message' => 'Tabele bazy danych nie istnieją.');
        }
        
        $wpdb->query('START TRANSACTION');
        
        try {
            if (!$this->isSlotAvailable($slot_id)) {
                throw new Exception('Slot nie jest już dostępny.');
            }
            
            $result1 = $wpdb->update(
                $tabela_terminy,
                array('status' => 'Zarezerwowany', 'klient_id' => $user_id),
                array('id' => $slot_id),
                array('%s', '%d'), array('%d')
            );
            
            $result2 = $wpdb->update(
                $tabela_loty,
                array('status' => 'zarezerwowany', 'termin_id' => $slot_id, 'data_rezerwacji' => current_time('mysql')),
                array('id' => $flight_id),
                array('%s', '%d', '%s'), array('%d')
            );
            
            if ($result1 === false || $result2 === false) {
                throw new Exception('Błąd podczas rezerwacji.');
            }
            
            $wpdb->query('COMMIT');
            return array('success' => true, 'message' => 'Rezerwacja wykonana pomyślnie.');
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    public function cancelReservation($flight_id) {
        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$tabela_terminy'") != $tabela_terminy ||
            $wpdb->get_var("SHOW TABLES LIKE '$tabela_loty'") != $tabela_loty) {
            return array('success' => false, 'message' => 'Tabele bazy danych nie istnieją.');
        }
        
        $lot = $this->getFlightById($flight_id);
        if (!$lot || !$lot['termin_id']) {
            return array('success' => false, 'message' => 'Lot nie ma przypisanego terminu.');
        }
        
        $wpdb->query('START TRANSACTION');
        
        try {
            $result1 = $wpdb->update(
                $tabela_terminy,
                array('status' => 'Wolny', 'klient_id' => null),
                array('id' => $lot['termin_id']),
                array('%s', '%d'), array('%d')
            );
            
            $result2 = $wpdb->update(
                $tabela_loty,
                array('status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null),
                array('id' => $flight_id),
                array('%s', '%d', '%s'), array('%d')
            );
            
            if ($result1 === false || $result2 === false) {
                throw new Exception('Błąd podczas anulowania rezerwacji.');
            }
            
            $wpdb->query('COMMIT');
            return array('success' => true, 'message' => 'Rezerwacja została anulowana.');
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    public function updateFlightStatus($flight_id, $new_status, $additional_data = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'srl_zakupione_loty';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return false;
        }
        
        $update_data = array_merge(array('status' => $new_status), $additional_data);
        $format = array_fill(0, count($update_data), '%s');
        
        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $flight_id),
            $format, array('%d')
        );
        
        return $result !== false;
    }

    public function getDayScheduleOptimized($date) {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$tabela'") != $tabela) {
            return [];
        }
        
        $slots = $this->executeQuery(
            "SELECT t.id, t.pilot_id, t.godzina_start, t.godzina_koniec, t.status, t.klient_id, t.notatka,
                    zl.id as lot_id, zl.user_id as lot_user_id, zl.status as lot_status
             FROM $tabela t
             LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
             WHERE t.data = %s
             ORDER BY t.pilot_id ASC, t.godzina_start ASC",
            array($date)
        );
        
        $user_ids = array_filter(array_unique(array_column($slots, 'klient_id')));
        $users_data = !empty($user_ids) ? SRL_Cache_Manager::getInstance()->getUsersDataBatch($user_ids) : [];
        
        $grouped_by_pilot = [];
        foreach ($slots as $slot) {
            $pilot_id = intval($slot['pilot_id']);
            if (!isset($grouped_by_pilot[$pilot_id])) {
                $grouped_by_pilot[$pilot_id] = [];
            }
            
            $client_name = '';
            $order_link = '';
            
            if (($slot['status'] === 'Zarezerwowany' || $slot['status'] === 'Zrealizowany') && intval($slot['klient_id']) > 0) {
                $user_id = intval($slot['klient_id']);
                if (isset($users_data[$user_id])) {
                    $user_data = $users_data[$user_id];
                    $client_name = ($user_data['imie'] && $user_data['nazwisko']) 
                        ? $user_data['imie'] . ' ' . $user_data['nazwisko']
                        : $user_data['display_name'];
                    $order_link = admin_url('edit.php?post_type=shop_order&customer=' . $user_id);
                }
            } elseif ($slot['status'] === 'Prywatny' && !empty($slot['notatka'])) {
                $private_data = json_decode($slot['notatka'], true);
                if ($private_data && isset($private_data['imie']) && isset($private_data['nazwisko'])) {
                    $client_name = $private_data['imie'] . ' ' . $private_data['nazwisko'];
                }
            }
            
            $grouped_by_pilot[$pilot_id][] = [
                'id' => intval($slot['id']),
                'start' => substr($slot['godzina_start'], 0, 5),
                'koniec' => substr($slot['godzina_koniec'], 0, 5),
                'status' => $slot['status'],
                'klient_id' => intval($slot['klient_id']),
                'klient_nazwa' => $client_name,
                'link_zamowienia' => $order_link,
                'lot_id' => $slot['lot_id'] ? intval($slot['lot_id']) : null,
                'notatka' => $slot['notatka']
            ];
        }
        
        return $grouped_by_pilot;
    }

    public function formatSlotDetails($slot_id) {
        $slot = $this->getSlotDetails($slot_id);
        if ($slot) {
            return sprintf('%s %s-%s', 
                SRL_Helpers::getInstance()->formatujDate($slot['data']), 
                substr($slot['godzina_start'], 0, 5),
                substr($slot['godzina_koniec'], 0, 5)
            );
        }
        return 'nieznany termin';
    }

    public function getMultipleFlightsByIds($flight_ids) {
        if (empty($flight_ids)) return array();
        
        $cache_key = "flights_batch_" . md5(implode(',', $flight_ids));
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'srl_zakupione_loty';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            wp_cache_set($cache_key, [], 'srl_cache', 1800);
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($flight_ids), '%d'));
        
        $result = $this->executeQuery(
            "SELECT * FROM $table WHERE id IN ($placeholders)",
            $flight_ids
        );
        
        $indexed_result = array();
        foreach ($result as $flight) {
            $indexed_result[$flight['id']] = $flight;
        }
        
        wp_cache_set($cache_key, $indexed_result, 'srl_cache', 1800);
        return $indexed_result;
    }

    public function ajaxPobierzDaneKlienta() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $user_id = get_current_user_id();
        
        $cache_key = "client_data_full_{$user_id}";
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            wp_send_json_success($cached);
            return;
        }
        
        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';

        if ($wpdb->get_var("SHOW TABLES LIKE '$tabela_loty'") != $tabela_loty ||
            $wpdb->get_var("SHOW TABLES LIKE '$tabela_terminy'") != $tabela_terminy) {
            wp_send_json_success([
                'rezerwacje' => [],
                'dostepne_loty' => [],
                'dane_osobowe' => SRL_Cache_Manager::getInstance()->getUserData($user_id),
                'dane_kompletne' => false,
                'cached_at' => time()
            ]);
            return;
        }

        $rezerwacje = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.id, zl.nazwa_produktu, zl.status, zl.data_waznosci, zl.ma_filmowanie, zl.ma_akrobacje,
                    t.data, t.godzina_start, t.godzina_koniec,
                    v.kod_vouchera, v.buyer_imie as voucher_buyer_imie, v.buyer_nazwisko as voucher_buyer_nazwisko
             FROM $tabela_loty zl 
             LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
             LEFT JOIN {$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
             WHERE zl.user_id = %d 
             AND zl.status = 'zarezerwowany'
             AND zl.data_waznosci >= CURDATE()
             ORDER BY t.data ASC, t.godzina_start ASC
             LIMIT 10",
            $user_id
        ), ARRAY_A);

        $dostepne_loty = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.id, zl.nazwa_produktu, zl.status, zl.data_waznosci, zl.ma_filmowanie, zl.ma_akrobacje,
                    v.kod_vouchera, v.buyer_imie as voucher_buyer_imie, v.buyer_nazwisko as voucher_buyer_nazwisko
             FROM $tabela_loty zl
             LEFT JOIN {$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
             WHERE zl.user_id = %d 
             AND zl.status = 'wolny'
             AND zl.data_waznosci >= CURDATE()
             ORDER BY zl.data_zakupu DESC
             LIMIT 20",
            $user_id
        ), ARRAY_A);

        $dane_osobowe = SRL_Cache_Manager::getInstance()->getUserData($user_id);
        
        $dane_kompletne = !empty($dane_osobowe['imie']) && !empty($dane_osobowe['nazwisko']) 
                         && !empty($dane_osobowe['rok_urodzenia']) && !empty($dane_osobowe['kategoria_wagowa']) 
                         && !empty($dane_osobowe['sprawnosc_fizyczna']);

        $result = array(
            'rezerwacje' => $rezerwacje,
            'dostepne_loty' => $dostepne_loty,
            'dane_osobowe' => $dane_osobowe,
            'dane_kompletne' => $dane_kompletne,
            'cached_at' => time()
        );
        
        wp_cache_set($cache_key, $result, 'srl_cache', 1800);
        wp_send_json_success($result);
    }

    public function invalidateFlightCache($user_id, $flight_id = null) {
        wp_cache_delete("user_flights_{$user_id}_*", 'srl_cache');
        wp_cache_delete("client_data_full_{$user_id}", 'srl_cache');
        wp_cache_delete("flights_batch_*", 'srl_cache');
        
        if ($flight_id) {
            wp_cache_delete("flight_{$flight_id}", 'srl_cache');
        }
        
        SRL_Cache_Manager::getInstance()->invalidateUserCache($user_id);
    }
}