<?php
/**
 * AJAX Handlers dla opcji lotów
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_srl_sprawdz_opcje_w_koszyku', 'srl_ajax_sprawdz_opcje_w_koszyku');
add_action('wp_ajax_nopriv_srl_sprawdz_opcje_w_koszyku', 'srl_ajax_sprawdz_opcje_w_koszyku');
add_action('wp_ajax_srl_usun_opcje_z_koszyka', 'srl_ajax_usun_opcje_z_koszyka');
add_action('wp_ajax_nopriv_srl_usun_opcje_z_koszyka', 'srl_ajax_usun_opcje_z_koszyka');
add_action('wp_ajax_srl_sprawdz_i_dodaj_opcje', 'srl_ajax_sprawdz_i_dodaj_opcje');
add_action('wp_ajax_woocommerce_add_to_cart', 'srl_ajax_add_to_cart');
add_action('wp_ajax_nopriv_woocommerce_add_to_cart', 'srl_ajax_add_to_cart');

function srl_ajax_sprawdz_opcje_w_koszyku() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $opcje_w_koszyku = array();
    
    if (WC()->cart) {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['srl_lot_id'])) {
                $lot_id = $cart_item['srl_lot_id'];
                $product_id = $cart_item['product_id'];
                
                if (!isset($opcje_w_koszyku[$lot_id])) {
                    $opcje_w_koszyku[$lot_id] = array('filmowanie' => false, 'akrobacje' => false);
                }
                
                if ($product_id == 116) {
                    $opcje_w_koszyku[$lot_id]['filmowanie'] = true;
                } elseif ($product_id == 117) {
                    $opcje_w_koszyku[$lot_id]['akrobacje'] = true;
                } elseif ($product_id == 115) {
                    $opcje_w_koszyku[$lot_id]['przedluzenie'] = true;
                }
            }
        }
    }
    
    wp_send_json_success($opcje_w_koszyku);
}

function srl_ajax_usun_opcje_z_koszyka() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $lot_id = intval($_POST['lot_id']);
    $product_id = intval($_POST['product_id']);
    
    if (!WC()->cart) {
        wp_send_json_error('Koszyk nie jest dostępny.');
        return;
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

function srl_ajax_sprawdz_i_dodaj_opcje() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    $lot_id = intval($_POST['srl_lot_id']);
    
    if (!WC()->cart) {
        wp_send_json_error('Koszyk nie jest dostępny.');
        return;
    }
    
    $opcja_w_koszyku = false;
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (isset($cart_item['srl_lot_id']) && 
            $cart_item['srl_lot_id'] == $lot_id && 
            $cart_item['product_id'] == $product_id) {
            $opcja_w_koszyku = true;
            break;
        }
    }
    
    if ($opcja_w_koszyku) {
        wp_send_json_error('Ta opcja jest już w koszyku.');
        return;
    }
    
    $cart_item_data = array();
    if ($lot_id) {
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

function srl_ajax_add_to_cart() {
    if (!isset($_POST['product_id'])) {
        wp_send_json_error('Brak ID produktu');
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    $lot_id = isset($_POST['srl_lot_id']) ? intval($_POST['srl_lot_id']) : 0;
    
    $cart_item_data = array();
    if ($lot_id) {
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