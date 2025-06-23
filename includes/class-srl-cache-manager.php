<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Cache_Manager {
    
    private static $instance = null;
    private static $cache_group = 'srl_cache';
    private static $cache_expire = 3600;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getUserData($user_id) {
        $cache_key = "user_data_{$user_id}";
        $data = wp_cache_get($cache_key, self::$cache_group);
        
        if (false === $data) {
            $data = $this->fetchUserDataOptimized($user_id);
            wp_cache_set($cache_key, $data, self::$cache_group, self::$cache_expire);
        }
        
        return $data;
    }

    private function fetchUserDataOptimized($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return null;
        
        $meta_keys = ['srl_imie', 'srl_nazwisko', 'srl_rok_urodzenia', 'srl_telefon', 
                     'srl_kategoria_wagowa', 'srl_sprawnosc_fizyczna', 'srl_uwagi'];
        
        $all_meta = get_user_meta($user_id);
        
        $meta_data = [];
        foreach ($meta_keys as $key) {
            $meta_data[str_replace('srl_', '', $key)] = isset($all_meta[$key][0]) ? $all_meta[$key][0] : '';
        }
        
        return array_merge([
            'id' => $user_id,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name
        ], $meta_data);
    }

    public function getUsersDataBatch($user_ids) {
        $cache_key = "users_batch_" . md5(implode(',', $user_ids));
        $data = wp_cache_get($cache_key, self::$cache_group);
        
        if (false === $data) {
            $data = $this->fetchUsersDataBatch($user_ids);
            wp_cache_set($cache_key, $data, self::$cache_group, self::$cache_expire);
        }
        
        return $data;
    }

    private function fetchUsersDataBatch($user_ids) {
        if (empty($user_ids)) return [];
        
        global $wpdb;
        
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, user_email, display_name FROM {$wpdb->users} WHERE ID IN ($placeholders)",
            ...$user_ids
        ), ARRAY_A);
        
        $meta_keys = ['srl_imie', 'srl_nazwisko', 'srl_rok_urodzenia', 'srl_telefon', 
                     'srl_kategoria_wagowa', 'srl_sprawnosc_fizyczna', 'srl_uwagi'];
        
        $meta_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        
        $user_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} 
             WHERE user_id IN ($meta_placeholders) AND meta_key IN ($key_placeholders)",
            array_merge($user_ids, $meta_keys)
        ), ARRAY_A);
        
        $result = [];
        foreach ($users as $user) {
            $user_id = $user['ID'];
            $result[$user_id] = [
                'id' => $user_id,
                'email' => $user['user_email'],
                'display_name' => $user['display_name'],
                'imie' => '',
                'nazwisko' => '',
                'rok_urodzenia' => '',
                'telefon' => '',
                'kategoria_wagowa' => '',
                'sprawnosc_fizyczna' => '',
                'uwagi' => ''
            ];
        }
        
        foreach ($user_meta as $meta) {
            $user_id = $meta['user_id'];
            $key = str_replace('srl_', '', $meta['meta_key']);
            if (isset($result[$user_id])) {
                $result[$user_id][$key] = $meta['meta_value'];
            }
        }
        
        return $result;
    }

    public function invalidateUserCache($user_id) {
        wp_cache_delete("user_data_{$user_id}", self::$cache_group);
    }

    public function getAvailableDays($year, $month) {
        $cache_key = "available_days_{$year}_{$month}";
        $data = wp_cache_get($cache_key, self::$cache_group);
        
        if (false === $data) {
            global $wpdb;
            $tabela = $wpdb->prefix . 'srl_terminy';
            
            $start_date = sprintf('%04d-%02d-01', $year, $month);
            $end_date = date('Y-m-t', strtotime($start_date));
            
            $result = $wpdb->get_results($wpdb->prepare(
                "SELECT data, COUNT(*) as wolne_sloty
                 FROM $tabela 
                 WHERE data BETWEEN %s AND %s 
                 AND status = 'Wolny'
                 AND data >= CURDATE()
                 GROUP BY data
                 HAVING wolne_sloty > 0
                 ORDER BY data ASC",
                $start_date, $end_date
            ), ARRAY_A);
            
            $data = [];
            foreach ($result as $row) {
                $data[$row['data']] = intval($row['wolne_sloty']);
            }
            
            wp_cache_set($cache_key, $data, self::$cache_group, 900);
        }
        
        return $data;
    }

    public function invalidateDayCache($date) {
        $year = date('Y', strtotime($date));
        $month = date('n', strtotime($date));
        wp_cache_delete("available_days_{$year}_{$month}", self::$cache_group);
    }
	
	
	public function cleanupExpiredCache() {
		global $wpdb;
		
		$current_time = time();
		$expired_keys = array();
		
		$cache_data = wp_cache_get_multiple(array(
			'user_data_*',
			'available_days_*',
			'client_data_full_*',
			'user_flights_*',
			'flights_batch_*'
		), self::$cache_group);
		
		foreach ($cache_data as $key => $data) {
			if (is_array($data) && isset($data['cached_at'])) {
				if ($current_time - $data['cached_at'] > self::$cache_expire) {
					$expired_keys[] = $key;
				}
			}
		}
		
		foreach ($expired_keys as $key) {
			wp_cache_delete($key, self::$cache_group);
		}
		
		return count($expired_keys);
	}

	public function getCacheStats() {
		return array(
			'cache_group' => self::$cache_group,
			'cache_expire' => self::$cache_expire,
			'memory_usage' => memory_get_usage(true),
			'peak_memory' => memory_get_peak_usage(true)
		);
	}

	public function optimizeMemoryUsage() {
		gc_collect_cycles();
		
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group(self::$cache_group);
		}
		
		$this->cleanupExpiredCache();
	}

	public function batchUserDataUpdate($user_updates) {
		if (empty($user_updates)) return false;
		
		global $wpdb;
		
		$wpdb->query('START TRANSACTION');
		
		try {
			foreach ($user_updates as $user_id => $meta_data) {
				foreach ($meta_data as $key => $value) {
					update_user_meta($user_id, $key, $value);
				}
				$this->invalidateUserCache($user_id);
			}
			
			$wpdb->query('COMMIT');
			return true;
			
		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			return false;
		}
	}
}