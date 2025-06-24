<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Flight_Options_Ajax {
    private static $instance = null;
    private $cache_manager;
    private $product_ids;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->cache_manager = SRL_Cache_Manager::getInstance();
        $this->product_ids = SRL_Helpers::getInstance()->getFlightOptionProductIds();
        $this->initHooks();
    }

    private function initHooks() {
        $ajax_methods = [
            'srl_sprawdz_opcje_w_koszyku', 'srl_usun_opcje_z_koszyka', 
            'srl_sprawdz_i_dodaj_opcje', 'woocommerce_add_to_cart'
        ];

        foreach ($ajax_methods as $method) {
            add_action("wp_ajax_{$method}", [$this, 'ajax' . $this->toCamelCase($method)]);
            add_action("wp_ajax_nopriv_{$method}", [$this, 'ajax' . $this->toCamelCase($method)]);
        }
    }

    private function toCamelCase($string) {
        $clean = str_replace(['srl_', 'woocommerce_'], '', $string);
        return str_replace('_', '', ucwords($clean, '_'));
    }

    private function validateAccess() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();
    }

	private function getCartOptions() {
		if (!WC() || !WC()->cart) {
			wp_send_json_success([]);
			return [];
		}
		
		$cache_key = 'cart_options_' . get_current_user_id();
		$cached = wp_cache_get($cache_key, 'srl_cache');
		
		if ($cached !== false) {
			return $cached;
		}

		$opcje_w_koszyku = [];
		foreach (WC()->cart->get_cart() as $cart_item) {
			if (!isset($cart_item['srl_lot_id'])) continue;
			
			$lot_id = $cart_item['srl_lot_id'];
			$product_id = $cart_item['product_id'];

			if (!isset($opcje_w_koszyku[$lot_id])) {
				$opcje_w_koszyku[$lot_id] = [
					'filmowanie' => false, 
					'akrobacje' => false, 
					'przedluzenie' => false
				];
			}

			if ($product_id == $this->product_ids['filmowanie']) {
				$opcje_w_koszyku[$lot_id]['filmowanie'] = true;
			} elseif ($product_id == $this->product_ids['akrobacje']) {
				$opcje_w_koszyku[$lot_id]['akrobacje'] = true;
			} elseif ($product_id == $this->product_ids['przedluzenie']) {
				$opcje_w_koszyku[$lot_id]['przedluzenie'] = true;
			}
		}

		wp_cache_set($cache_key, $opcje_w_koszyku, 'srl_cache', 300);
		return $opcje_w_koszyku;
	}

    private function invalidateCartCache() {
        if (is_user_logged_in()) {
            wp_cache_delete('cart_options_' . get_current_user_id(), 'srl_cache');
        }
    }

    private function validateFlightOwnership($lot_id) {
        if (!is_user_logged_in()) return false;
        
        $cache_key = "flight_ownership_{$lot_id}_" . get_current_user_id();
        $is_owner = wp_cache_get($cache_key, 'srl_cache');
        
        if ($is_owner === false) {
            global $wpdb;
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}srl_zakupione_loty 
                 WHERE id = %d AND user_id = %d",
                $lot_id, get_current_user_id()
            ));
            
            $is_owner = $count > 0 ? 'yes' : 'no';
            wp_cache_set($cache_key, $is_owner, 'srl_cache', 1800);
        }
        
        return $is_owner === 'yes';
    }

    private function prepareCartItemData($lot_id) {
        if (!$this->validateFlightOwnership($lot_id)) {
            return false;
        }
        
        $cache_key = "flight_cart_data_{$lot_id}";
        $cart_data = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cart_data === false) {
            global $wpdb;
            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT nazwa_produktu, data_zakupu FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d",
                $lot_id
            ), ARRAY_A);
            
            if (!$lot) return false;
            
            $cart_data = [
                'srl_lot_id' => $lot_id,
                'srl_lot_info' => [
                    'nazwa_produktu' => $lot['nazwa_produktu'],
                    'data_zakupu' => $lot['data_zakupu']
                ]
            ];
            
            wp_cache_set($cache_key, $cart_data, 'srl_cache', 1800);
        }
        
        return $cart_data;
    }

	public function ajaxSprawdzOpcjeWKoszyku() {
		try {
			$this->validateAccess();
			$opcje_w_koszyku = $this->getCartOptions();
			wp_send_json_success($opcje_w_koszyku);
		} catch (Exception $e) {
			wp_send_json_error($e->getMessage());
		}
	}

    public function ajaxUsunOpcjeZKoszyka() {
        $this->validateAccess();
        
        $lot_id = intval($_POST['lot_id']);
        $product_id = intval($_POST['product_id']);

        if (!WC()->cart) {
            wp_send_json_error('Koszyk nie jest dostępny.');
        }

        $removed = false;
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['srl_lot_id']) && 
                $cart_item['srl_lot_id'] == $lot_id && 
                $cart_item['product_id'] == $product_id) {

                WC()->cart->remove_cart_item($cart_item_key);
                $removed = true;
                break;
            }
        }

        if ($removed) {
            $this->invalidateCartCache();
            wp_send_json_success('Opcja została usunięta z koszyka.');
        } else {
            wp_send_json_error('Nie znaleziono opcji w koszyku.');
        }
    }

    public function ajaxSprawdzIDodajOpcje() {
        $this->validateAccess();
        
        $product_id = intval($_POST['product_id']);
        $lot_id = intval($_POST['srl_lot_id']);

        if (!WC()->cart) {
            wp_send_json_error('Koszyk nie jest dostępny.');
        }

        if (!in_array($product_id, $this->product_ids)) {
            wp_send_json_error('Nieprawidłowy produkt.');
        }

        $opcje_w_koszyku = $this->getCartOptions();
        if (isset($opcje_w_koszyku[$lot_id])) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['srl_lot_id']) && 
                    $cart_item['srl_lot_id'] == $lot_id && 
                    $cart_item['product_id'] == $product_id) {
                    wp_send_json_error('Ta opcja jest już w koszyku.');
                }
            }
        }

        $cart_item_data = $this->prepareCartItemData($lot_id);
        if (!$cart_item_data) {
            wp_send_json_error('Nie znaleziono lotu lub brak uprawnień.');
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);

        if ($cart_item_key) {
            $this->invalidateCartCache();
            wp_send_json_success([
                'cart_item_key' => $cart_item_key,
                'product_id' => $product_id,
                'cart_count' => WC()->cart->get_cart_contents_count()
            ]);
        } else {
            wp_send_json_error('Nie udało się dodać produktu do koszyka');
        }
    }

    public function ajaxWoocommerceAddToCart() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        
        if (!isset($_POST['product_id'])) {
            wp_send_json_error('Brak ID produktu');
        }

        $product_id = intval($_POST['product_id']);
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $lot_id = isset($_POST['srl_lot_id']) ? intval($_POST['srl_lot_id']) : 0;

        $cart_item_data = [];
        if ($lot_id && in_array($product_id, $this->product_ids)) {
            $cart_item_data = $this->prepareCartItemData($lot_id);
            if (!$cart_item_data) {
                wp_send_json_error('Nie można dodać opcji - brak uprawnień do lotu.');
            }
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, [], $cart_item_data);

        if ($cart_item_key) {
            $this->invalidateCartCache();
            wp_send_json_success([
                'cart_item_key' => $cart_item_key,
                'product_id' => $product_id,
                'cart_count' => WC()->cart->get_cart_contents_count()
            ]);
        } else {
            wp_send_json_error('Nie udało się dodać produktu do koszyka');
        }
    }
}