<?php if (!defined('ABSPATH')) {exit;}

add_action('wp_ajax_srl_sprawdz_opcje_w_koszyku', 'srl_ajax_sprawdz_opcje_w_koszyku');
add_action('wp_ajax_nopriv_srl_sprawdz_opcje_w_koszyku', 'srl_ajax_sprawdz_opcje_w_koszyku');
add_action('wp_ajax_srl_usun_opcje_z_koszyka', 'srl_ajax_usun_opcje_z_koszyka');
add_action('wp_ajax_nopriv_srl_usun_opcje_z_koszyka', 'srl_ajax_usun_opcje_z_koszyka');
add_action('wp_ajax_srl_sprawdz_i_dodaj_opcje', 'srl_ajax_sprawdz_i_dodaj_opcje');
add_action('wp_ajax_woocommerce_add_to_cart', 'srl_ajax_add_to_cart');
add_action('wp_ajax_nopriv_woocommerce_add_to_cart', 'srl_ajax_add_to_cart');

function srl_ajax_sprawdz_opcje_w_koszyku() {
    check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    srl_require_login();

    $opcje_w_koszyku = array();

    if (WC()->cart) {
        $opcje_produkty = srl_get_flight_option_product_ids();
        
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

function srl_ajax_usun_opcje_z_koszyka() {
    check_ajax_referer('srl_frontend_nonce', 'nonce', true); 
    srl_require_login();

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

function srl_ajax_sprawdz_i_dodaj_opcje() {
    check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    srl_require_login();

    $product_id = intval($_POST['product_id']);
    $lot_id = intval($_POST['srl_lot_id']);

    if (!WC()->cart) {
        wp_send_json_error('Koszyk nie jest dostępny.');
    }

    // Sprawdź czy opcja już jest w koszyku
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (isset($cart_item['srl_lot_id']) && 
            $cart_item['srl_lot_id'] == $lot_id && 
            $cart_item['product_id'] == $product_id) {
            wp_send_json_error('Ta opcja jest już w koszyku.');
        }
    }

    $cart_item_data = srl_prepare_cart_item_data($lot_id);
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

function srl_ajax_add_to_cart() {
    check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    
    if (!isset($_POST['product_id'])) {
        wp_send_json_error('Brak ID produktu');
    }

    $product_id = intval($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    $lot_id = isset($_POST['srl_lot_id']) ? intval($_POST['srl_lot_id']) : 0;

    $cart_item_data = array();
    if ($lot_id) {
        $cart_item_data = srl_prepare_cart_item_data($lot_id);
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

function srl_prepare_cart_item_data($lot_id) {
    if (!$lot_id || !is_user_logged_in()) {
        return array();
    }
    
    $lot = srl_get_flight_by_id($lot_id);
    
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