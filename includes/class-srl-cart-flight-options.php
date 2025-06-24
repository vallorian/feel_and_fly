<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Cart_Flight_Options {
    
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
        add_filter('woocommerce_add_cart_item_data', array($this, 'addFlightOptionCartItemData'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'displayFlightOptionCartItemData'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'saveFlightOptionOrderItemMeta'), 10, 4);
        add_filter('woocommerce_order_item_display_meta_key', array($this, 'displayFlightOptionMetaKey'));
        add_filter('woocommerce_order_item_display_meta_value', array($this, 'displayFlightOptionMetaValue'), 10, 3);
        add_action('woocommerce_order_status_changed', array($this, 'processFlightOptionsPurchase'), 20, 3);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validateFlightOptionAddToCart'), 10, 3);
    }

    public function addFlightOptionCartItemData($cart_item_data, $product_id, $variation_id) {
        $opcje_produkty = SRL_Helpers::getInstance()->getFlightOptionProductIds();

        if (in_array($product_id, $opcje_produkty) && isset($_GET['srl_lot_id'])) {
            $lot_id = intval($_GET['srl_lot_id']);

            if (is_user_logged_in()) {
                global $wpdb;
                $tabela = $wpdb->prefix . 'srl_zakupione_loty';

                $lot = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $tabela WHERE id = %d AND user_id = %d",
                    $lot_id, get_current_user_id()
                ), ARRAY_A);

                if ($lot) {
                    $cart_item_data['srl_lot_id'] = $lot_id;
                    $cart_item_data['srl_lot_info'] = array(
                        'nazwa_produktu' => $lot['nazwa_produktu'],
                        'data_zakupu' => $lot['data_zakupu']
                    );
                }
            }
        }

        return $cart_item_data;
    }

    public function displayFlightOptionCartItemData($item_data, $cart_item) {
        if (isset($cart_item['srl_lot_id']) && isset($cart_item['srl_lot_info'])) {
            $item_data[] = array(
                'key' => 'Lot do modyfikacji',
                'value' => '#' . $cart_item['srl_lot_id'] . ' - ' . $cart_item['srl_lot_info']['nazwa_produktu'],
                'display' => ''
            );
        }

        return $item_data;
    }

    public function saveFlightOptionOrderItemMeta($item, $cart_item_key, $values, $order) {
        if (isset($values['srl_lot_id'])) {
            $item->add_meta_data('_srl_lot_id', $values['srl_lot_id']);
            if (isset($values['srl_lot_info'])) {
                $item->add_meta_data('_srl_lot_info', $values['srl_lot_info']);
            }
        }
    }

    public function displayFlightOptionMetaKey($display_key) {
        if ($display_key === '_srl_lot_info') {
            return 'Lot do modyfikacji';
        }
        return $display_key;
    }

    public function displayFlightOptionMetaValue($display_value, $meta, $item) {
        if ($meta->key === '_srl_lot_info' && is_array($meta->value)) {
            $lot_id = $item->get_meta('_srl_lot_id');
            return '#' . $lot_id . ' - ' . $meta->value['nazwa_produktu'];
        }
        return $display_value;
    }

    public function processFlightOptionsPurchase($order_id, $old_status, $new_status) {
        if (!in_array($new_status, array('processing', 'completed'))) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $opcje_produkty = SRL_Helpers::getInstance()->getFlightOptionProductIds();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $lot_id = $item->get_meta('_srl_lot_id');
            $quantity = $item->get_quantity();
            
            if ($lot_id && in_array($product_id, $opcje_produkty)) {
                if ($product_id == $opcje_produkty['przedluzenie']) {
                    SRL_WooCommerce_Integration::getInstance()->dokupPrzedluzenie($lot_id, $order_id, $item_id, $quantity);
                } elseif ($product_id == $opcje_produkty['filmowanie']) {
                    SRL_WooCommerce_Integration::getInstance()->dokupFilmowanie($lot_id, $order_id, $item_id, $quantity);
                } elseif ($product_id == $opcje_produkty['akrobacje']) {
                    SRL_WooCommerce_Integration::getInstance()->dokupAkrobacje($lot_id, $order_id, $item_id, $quantity);
                }
            }
        }
    }

    public function validateFlightOptionAddToCart($passed, $product_id, $quantity) {
        $opcje_produkty = SRL_Helpers::getInstance()->getFlightOptionProductIds();

        if (in_array($product_id, $opcje_produkty)) {
            if (!is_user_logged_in()) {
                wc_add_notice('Musisz być zalogowany, aby kupić opcje lotu.', 'error');
                return false;
            }

            global $wpdb;
            $tabela = $wpdb->prefix . 'srl_zakupione_loty';
            $user_id = get_current_user_id();

            $ma_loty = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $tabela WHERE user_id = %d AND status IN ('wolny', 'zarezerwowany')",
                $user_id
            ));

            if (!$ma_loty) {
                wc_add_notice('Aby kupić opcje lotu, musisz najpierw mieć wykupiony lot tandemowy.', 'error');
                return false;
            }

            if (!isset($_GET['srl_lot_id']) && !isset($_POST['srl_lot_id'])) {
                wc_add_notice('Wybierz lot, do którego chcesz dokupić opcje.', 'error');
                return false;
            }
        }

        return $passed;
    }
}