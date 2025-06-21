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
        $this->initHooks();
    }

    private function initHooks() {
    }

    public function executeQuery($query, $params = array(), $return_type = 'results') {
        global $wpdb;
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
        return $this->executeQuery(
            "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d",
            array($flight_id), 'row'
        );
    }

    public function getUserFlights($user_id, $status = null, $include_expired = false) {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_zakupione_loty';
        
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
        return $this->executeQuery(
            "SELECT * FROM $tabela $where_clause ORDER BY data_zakupu DESC",
            $params
        );
    }

    public function getAvailableSlots($date) {
        global $wpdb;
        return $this->executeQuery(
            "SELECT id, pilot_id, godzina_start, godzina_koniec 
             FROM {$wpdb->prefix}srl_terminy 
             WHERE data = %s AND status = 'Wolny'
             ORDER BY pilot_id ASC, godzina_start ASC",
            array($date)
        );
    }

    public function isSlotAvailable($slot_id) {
        global $wpdb;
        return $this->executeQuery(
            "SELECT COUNT(*) FROM {$wpdb->prefix}srl_terminy WHERE id = %d AND status = 'Wolny'",
            array($slot_id), 'count'
        ) > 0;
    }

    public function getSlotDetails($slot_id) {
        global $wpdb;
        return $this->executeQuery(
            "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            array($slot_id), 'row'
        );
    }

    public function reserveSlot($slot_id, $flight_id, $user_id) {
        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        
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
        $update_data = array_merge(array('status' => $new_status), $additional_data);
        $format = array_fill(0, count($update_data), '%s');
        
        $result = $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            $update_data,
            array('id' => $flight_id),
            $format, array('%d')
        );
        
        return $result !== false;
    }

    public function getDayScheduleOptimized($date) {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';
        
        $slots = $this->executeQuery(
            "SELECT t.id, t.pilot_id, t.godzina_start, t.godzina_koniec, t.status, t.klient_id, t.notatka,
                    zl.id as lot_id, zl.user_id as lot_user_id, zl.status as lot_status, zl.dane_pasazera
             FROM $tabela t
             LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
             WHERE t.data = %s
             ORDER BY t.pilot_id ASC, t.godzina_start ASC",
            array($date)
        );
        
        $grouped_by_pilot = array();
        $user_cache = array();
        
        foreach ($slots as $slot) {
            $pilot_id = intval($slot['pilot_id']);
            if (!isset($grouped_by_pilot[$pilot_id])) {
                $grouped_by_pilot[$pilot_id] = array();
            }
            
            $client_name = '';
            $order_link = '';
            $passenger_data_cache = null;
            
            if (($slot['status'] === 'Zarezerwowany' || $slot['status'] === 'Zrealizowany') && intval($slot['klient_id']) > 0) {
                $user_id = intval($slot['klient_id']);
                
                if (!isset($user_cache[$user_id])) {
                    $user_cache[$user_id] = SRL_Helpers::getInstance()->getUserFullData($user_id);
                }
                
                $user_data = $user_cache[$user_id];
                if ($user_data) {
                    $client_name = ($user_data['imie'] && $user_data['nazwisko']) 
                        ? $user_data['imie'] . ' ' . $user_data['nazwisko']
                        : $user_data['display_name'];
                    
                    $order_link = admin_url('edit.php?post_type=shop_order&customer=' . $user_id);
                    
                    if (!empty($slot['dane_pasazera'])) {
                        $passenger_data_cache = json_decode($slot['dane_pasazera'], true);
                    }
                    
                    if (empty($passenger_data_cache['imie'])) {
                        $passenger_data_cache = array(
                            'imie' => $user_data['imie'],
                            'nazwisko' => $user_data['nazwisko'],
                            'rok_urodzenia' => $user_data['rok_urodzenia'],
                            'telefon' => $user_data['telefon'],
                            'kategoria_wagowa' => $user_data['kategoria_wagowa'],
                            'sprawnosc_fizyczna' => $user_data['sprawnosc_fizyczna'],
                            'uwagi' => $user_data['uwagi']
                        );
                    }
                }
            } elseif ($slot['status'] === 'Prywatny' && !empty($slot['notatka'])) {
                $private_data = json_decode($slot['notatka'], true);
                if ($private_data && isset($private_data['imie']) && isset($private_data['nazwisko'])) {
                    $client_name = $private_data['imie'] . ' ' . $private_data['nazwisko'];
                    $passenger_data_cache = $private_data;
                }
            }
            
            $grouped_by_pilot[$pilot_id][] = array(
                'id' => intval($slot['id']),
                'start' => substr($slot['godzina_start'], 0, 5),
                'koniec' => substr($slot['godzina_koniec'], 0, 5),
                'status' => $slot['status'],
                'klient_id' => intval($slot['klient_id']),
                'klient_nazwa' => $client_name,
                'link_zamowienia' => $order_link,
                'lot_id' => $slot['lot_id'] ? intval($slot['lot_id']) : null,
                'notatka' => $slot['notatka'],
                'dane_pasazera_cache' => $passenger_data_cache
            );
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

    public function zwrocGodzinyWgPilota($data) {
        $godziny_wg_pilota = $this->getDayScheduleOptimized(sanitize_text_field($data));
        wp_send_json_success(array('godziny_wg_pilota' => $godziny_wg_pilota));
    }
}