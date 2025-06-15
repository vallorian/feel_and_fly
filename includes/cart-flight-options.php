<?php
/**
 * Obsługa koszyka dla opcji lotów
 */

add_filter('woocommerce_add_cart_item_data', 'srl_add_flight_option_cart_item_data', 10, 3);

function srl_add_flight_option_cart_item_data($cart_item_data, $product_id, $variation_id) {
    $opcje_produkty = srl_get_flight_option_product_ids();
    
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

add_filter('woocommerce_get_item_data', 'srl_display_flight_option_cart_item_data', 10, 2);

function srl_display_flight_option_cart_item_data($item_data, $cart_item) {
    if (isset($cart_item['srl_lot_id']) && isset($cart_item['srl_lot_info'])) {
        $item_data[] = array(
            'key' => 'Lot do modyfikacji',
            'value' => '#' . $cart_item['srl_lot_id'] . ' - ' . $cart_item['srl_lot_info']['nazwa_produktu'],
            'display' => ''
        );
    }
    
    return $item_data;
}

add_action('woocommerce_checkout_create_order_line_item', 'srl_save_flight_option_order_item_meta', 10, 4);

function srl_save_flight_option_order_item_meta($item, $cart_item_key, $values, $order) {
    if (isset($values['srl_lot_id'])) {
        $item->add_meta_data('_srl_lot_id', $values['srl_lot_id']);
        if (isset($values['srl_lot_info'])) {
            $item->add_meta_data('_srl_lot_info', $values['srl_lot_info']);
        }
    }
}

add_filter('woocommerce_order_item_display_meta_key', 'srl_display_flight_option_meta_key');
add_filter('woocommerce_order_item_display_meta_value', 'srl_display_flight_option_meta_value', 10, 3);

function srl_display_flight_option_meta_key($display_key) {
    if ($display_key === '_srl_lot_info') {
        return 'Lot do modyfikacji';
    }
    return $display_key;
}

function srl_display_flight_option_meta_value($display_value, $meta, $item) {
    if ($meta->key === '_srl_lot_info' && is_array($meta->value)) {
        $lot_id = $item->get_meta('_srl_lot_id');
        return '#' . $lot_id . ' - ' . $meta->value['nazwa_produktu'];
    }
    return $display_value;
}

add_action('woocommerce_order_status_changed', 'srl_process_flight_options_purchase', 20, 3);

function srl_process_flight_options_purchase($order_id, $old_status, $new_status) {
    if (!in_array($new_status, array('processing', 'completed'))) {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    $opcje_produkty = srl_get_flight_option_product_ids();
    
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $lot_id = $item->get_meta('_srl_lot_id');
        
        if ($lot_id && in_array($product_id, $opcje_produkty)) {
            if ($product_id == $opcje_produkty['przedluzenie']) {
                //srl_dokup_przedluzenie($lot_id, $order_id);
                
            } elseif ($product_id == $opcje_produkty['filmowanie']) {
                global $wpdb;
                $tabela = $wpdb->prefix . 'srl_zakupione_loty';
                $lot = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabela WHERE id = %d", $lot_id), ARRAY_A);
                
                if ($lot) {
                    srl_ustaw_opcje_lotu(
                        $lot_id, 
                        1,
                        $lot['ma_akrobacje'],
                        "Dokupiono filmowanie (zamówienie #$order_id)"
                    );
                }
                
            } elseif ($product_id == $opcje_produkty['akrobacje']) {
                global $wpdb;
                $tabela = $wpdb->prefix . 'srl_zakupione_loty';
                $lot = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabela WHERE id = %d", $lot_id), ARRAY_A);
                
                if ($lot) {
                    srl_ustaw_opcje_lotu(
                        $lot_id, 
                        $lot['ma_filmowanie'],
                        1,
                        "Dokupiono akrobacje (zamówienie #$order_id)"
                    );
                }
            }
        }
    }
}

add_filter('woocommerce_add_to_cart_validation', 'srl_validate_flight_option_add_to_cart', 10, 3);

function srl_validate_flight_option_add_to_cart($passed, $product_id, $quantity) {
    $opcje_produkty = srl_get_flight_option_product_ids();
    
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