<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Flight_Options_Ajax {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_srl_sprawdz_opcje_w_koszyku', array($this, 'ajaxSprawdzOpcjeWKoszyku'));
        add_action('wp_ajax_nopriv_srl_sprawdz_opcje_w_koszyku', array($this, 'ajaxSprawdzOpcjeWKoszyku'));
        add_action('wp_ajax_srl_usun_opcje_z_koszyka', array($this, 'ajaxUsunOpcjeZKoszyka'));
        add_action('wp_ajax_nopriv_srl_usun_opcje_z_koszyka', array($this, 'ajaxUsunOpcjeZKoszyka'));
        add_action('wp_ajax_srl_sprawdz_i_dodaj_opcje', array($this, 'ajaxSprawdzIDodajOpcje'));
        add_action('wp_ajax_woocommerce_add_to_cart', array($this, 'ajaxAddToCart'));
        add_action('wp_ajax_nopriv_woocommerce_add_to_cart', array($this, 'ajaxAddToCart'));
    }

    public function ajaxSprawdzOpcjeWKoszyku() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $opcje_w_koszyku = array();

        if (WC()->cart) {
            $opcje_produkty = SRL_Helpers::getInstance()->getFlightOptionProductIds();
            
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                if (isset($cart_item['srl_lot_id'])) {
                    $lot_id = $cart_item['srl_lot_id'];
                    $product_id = $cart_item['product_id'];

                    if (!isset($opcje_w_koszyku[$lot_id])) {
                        $opcje_w_koszyku[$lot_id] = array('filmowanie' => false, 'akrobacje' => false, 'przedluzenie' => false);
                    }

                    if ($product_id == $opcje_produkty['filmowanie']) {
                        $opcje_w_koszyku[$lot_id]['filmowanie'] = true;
                    } elseif ($product_id == $opcje_produkty['akrobacje']) {
                        $opcje_w_koszyku[$lot_id]['akrobacje'] = true;
                    } elseif ($product_id == $opcje_produkty['przedluzenie']) {
                        $opcje_w_koszyku[$lot_id]['przedluzenie'] = true;
                    }
                }
            }
        }

        wp_send_json_success($opcje_w_koszyku);
    }

    public function ajaxUsunOpcjeZKoszyka() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true); 
        SRL_Helpers::getInstance()->requireLogin();

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
            wp_send_json_success('Opcja została usunięta z koszyka.');
        } else {
            wp_send_json_error('Nie znaleziono opcji w koszyku.');
        }
    }

    public function ajaxSprawdzIDodajOpcje() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $product_id = intval($_POST['product_id']);
        $lot_id = intval($_POST['srl_lot_id']);

        if (!WC()->cart) {
            wp_send_json_error('Koszyk nie jest dostępny.');
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (isset($cart_item['srl_lot_id']) && 
                $cart_item['srl_lot_id'] == $lot_id && 
                $cart_item['product_id'] == $product_id) {
                wp_send_json_error('Ta opcja jest już w koszyku.');
            }
        }

        $cart_item_data = $this->ajaxPrepareCartItemData($lot_id);
        if (!$cart_item_data) {
            wp_send_json_error('Nie znaleziono lotu lub brak uprawnień.');
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_item_data);

        if ($cart_item_key) {
            wp_send_json_success(array(
                'cart_item_key' => $cart_item_key,
                'product_id' => $product_id,
                'cart_count' => WC()->cart->get_cart_contents_count()
            ));
        } else {
            wp_send_json_error('Nie udało się dodać produktu do koszyka');
        }
    }

    public function ajaxAddToCart() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        
        if (!isset($_POST['product_id'])) {
            wp_send_json_error('Brak ID produktu');
        }

        $product_id = intval($_POST['product_id']);
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $lot_id = isset($_POST['srl_lot_id']) ? intval($_POST['srl_lot_id']) : 0;

        $cart_item_data = array();
        if ($lot_id) {
            $cart_item_data = $this->ajaxPrepareCartItemData($lot_id);
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);

        if ($cart_item_key) {
            wp_send_json_success(array(
                'cart_item_key' => $cart_item_key,
                'product_id' => $product_id,
                'cart_count' => WC()->cart->get_cart_contents_count()
            ));
        } else {
            wp_send_json_error('Nie udało się dodać produktu do koszyka');
        }
    }

    private function ajaxPrepareCartItemData($lot_id) {
        if (!$lot_id || !is_user_logged_in()) {
            return array();
        }
        
        $lot = SRL_Database_Helpers::getInstance()->getFlightById($lot_id);
        
        if (!$lot || $lot['user_id'] != get_current_user_id()) {
            return false;
        }
        
        return array(
            'srl_lot_id' => $lot_id,
            'srl_lot_info' => array(
                'nazwa_produktu' => $lot['nazwa_produktu'],
                'data_zakupu' => $lot['data_zakupu']
            )
        );
    }
}