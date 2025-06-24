<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Slot_Manager {
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
    }

    public function getDayScheduleOptimized($date) {
        $cache_key = "day_schedule_{$date}";
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';
        
        $slots = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.pilot_id, t.godzina_start, t.godzina_koniec, t.status, t.klient_id, t.notatka,
                    zl.id as lot_id, zl.user_id as lot_user_id, zl.status as lot_status
             FROM $tabela t
             LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
             WHERE t.data = %s
             ORDER BY t.pilot_id ASC, t.godzina_start ASC",
            $date
        ), ARRAY_A);
        
        $user_ids = array_filter(array_unique(array_column($slots, 'klient_id')));
        $users_data = !empty($user_ids) ? $this->cache_manager->getUsersDataBatch($user_ids) : [];
        
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
        
        wp_cache_set($cache_key, $grouped_by_pilot, 'srl_cache', 900);
        return $grouped_by_pilot;
    }

    public function createSlot($data, $pilot_id, $godzina_start, $godzina_koniec, $status = 'Wolny') {
        global $wpdb;
        $result = $wpdb->insert(
            $wpdb->prefix . 'srl_terminy',
            [
                'data' => $data,
                'pilot_id' => $pilot_id,
                'godzina_start' => $godzina_start . ':00',
                'godzina_koniec' => $godzina_koniec . ':00',
                'status' => $status,
                'klient_id' => 0
            ],
            ['%s','%d','%s','%s','%s','%d']
        );
        
        if ($result !== false) {
            $this->invalidateSlotCache($data);
        }
        
        return $result;
    }

    public function updateSlot($termin_id, $data) {
        global $wpdb;
        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT data FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);
        
        $result = $wpdb->update(
            $wpdb->prefix . 'srl_terminy',
            $data,
            ['id' => $termin_id],
            array_fill(0, count($data), '%s'),
            ['%d']
        );
        
        if ($result !== false && $slot) {
            $this->invalidateSlotCache($slot['data']);
        }
        
        return $result;
    }

    public function deleteSlot($termin_id) {
        global $wpdb;
        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT data, status, klient_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$slot) return false;
        if ($slot['status'] === 'Zarezerwowany' && intval($slot['klient_id']) > 0) return false;

        $result = $wpdb->delete($wpdb->prefix . 'srl_terminy', ['id' => $termin_id], ['%d']);
        
        if ($result !== false) {
            $this->invalidateSlotCache($slot['data']);
        }
        
        return $result;
    }

    public function generateSlots($data, $pilot_id, $start_time, $end_time, $interval_minutes) {
        $slots = [];
        $current = strtotime($start_time);
        $end = strtotime($end_time);
        
        while ($current < $end) {
            $next = $current + ($interval_minutes * 60);
            if ($next > $end) break;
            
            $slots[] = [
                'start' => date('H:i', $current),
                'end' => date('H:i', $next)
            ];
            
            $current = $next;
        }
        
        return $slots;
    }

    public function batchCreateSlots($slots_data) {
        global $wpdb;
        $values = [];
        $placeholders = [];
        
        foreach ($slots_data as $slot) {
            $values = array_merge($values, [
                $slot['data'],
                $slot['pilot_id'],
                $slot['godzina_start'] . ':00',
                $slot['godzina_koniec'] . ':00',
                $slot['status'] ?? 'Wolny',
                0
            ]);
            $placeholders[] = "(%s, %d, %s, %s, %s, %d)";
        }
        
        if (empty($values)) return false;
        
        $query = "INSERT INTO {$wpdb->prefix}srl_terminy (data, pilot_id, godzina_start, godzina_koniec, status, klient_id) VALUES " . implode(',', $placeholders);
        
        $result = $wpdb->query($wpdb->prepare($query, $values));
        
        if ($result !== false) {
            $dates = array_unique(array_column($slots_data, 'data'));
            foreach ($dates as $date) {
                $this->invalidateSlotCache($date);
            }
        }
        
        return $result;
    }

    public function getMonthStats($year, $month) {
        $cache_key = "month_stats_{$year}_{$month}";
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT data,
                    COUNT(id) AS ilosc_wszystkich,
                    SUM(CASE WHEN status = 'Wolny' THEN 1 ELSE 0 END) AS ilosc_wolnych,
                    SUM(CASE WHEN status = 'Zarezerwowany' THEN 1 ELSE 0 END) AS ilosc_zarezerwowanych,
                    SUM(CASE WHEN status = 'Prywatny' THEN 1 ELSE 0 END) AS ilosc_prywatnych,
                    SUM(CASE WHEN status = 'Zrealizowany' THEN 1 ELSE 0 END) AS ilosc_zrealizowanych,
                    SUM(CASE WHEN status = 'Odwołany przez organizatora' THEN 1 ELSE 0 END) AS ilosc_odwolanych
               FROM {$wpdb->prefix}srl_terminy
              WHERE data BETWEEN %s AND %s
              GROUP BY data",
            $start_date, $end_date
        ), ARRAY_A);

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['data']] = [
                'wszystkie' => intval($row['ilosc_wszystkich']),
                'wolne' => intval($row['ilosc_wolnych']),
                'zarezerwowane' => intval($row['ilosc_zarezerwowanych']),
                'prywatne' => intval($row['ilosc_prywatnych']),
                'zrealizowane' => intval($row['ilosc_zrealizowanych']),
                'odwolane' => intval($row['ilosc_odwolanych']),
                'zarezerwowane_razem' => intval($row['ilosc_zarezerwowanych']) + intval($row['ilosc_prywatnych'])
            ];
        }
        
        wp_cache_set($cache_key, $stats, 'srl_cache', 1800);
        return $stats;
    }

    public function invalidateSlotCache($date) {
        wp_cache_delete("day_schedule_{$date}", 'srl_cache');
        $this->cache_manager->invalidateDayCache($date);
    }

    public function __destruct() {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('srl_cache');
        }
    }
}