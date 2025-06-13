<?php
/**
 * Obsługa koszyka dla opcji lotów
 */

// Hook do dodawania meta danych do produktów w koszyku
add_filter('woocommerce_add_cart_item_data', 'srl_add_flight_option_cart_item_data', 10, 3);

/**
 * Dodaje ID lotu do meta danych produktu w koszyku
 */
function srl_add_flight_option_cart_item_data($cart_item_data, $product_id, $variation_id) {
    // Sprawdź czy to produkt opcji lotu
    $opcje_produkty = srl_get_flight_option_product_ids();
    
    if (in_array($product_id, $opcje_produkty) && isset($_GET['srl_lot_id'])) {
        $lot_id = intval($_GET['srl_lot_id']);
        
        // Sprawdź czy lot należy do aktualnego użytkownika
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

// Wyświetl informacje o locie w koszyku
add_filter('woocommerce_get_item_data', 'srl_display_flight_option_cart_item_data', 10, 2);

/**
 * Wyświetla informacje o locie w koszyku
 */
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

// Zapisz meta dane w zamówieniu
add_action('woocommerce_checkout_create_order_line_item', 'srl_save_flight_option_order_item_meta', 10, 4);

/**
 * Zapisuje meta dane o locie w zamówieniu
 */
function srl_save_flight_option_order_item_meta($item, $cart_item_key, $values, $order) {
    if (isset($values['srl_lot_id'])) {
        $item->add_meta_data('_srl_lot_id', $values['srl_lot_id']);
        if (isset($values['srl_lot_info'])) {
            $item->add_meta_data('_srl_lot_info', $values['srl_lot_info']);
        }
    }
}

// Wyświetl meta dane w panelu admina zamówienia
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

// Przetwarzaj opcje po zakupie
add_action('woocommerce_order_status_changed', 'srl_process_flight_options_purchase', 20, 3);

/**
 * Przetwarza zakup opcji lotu
 */
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
            // Sprawdź typ opcji
            if ($product_id == $opcje_produkty['przedluzenie']) {
                // Przedłuż ważność
                srl_przedluz_waznosc_lotu($lot_id, $order_id);
                
            } elseif ($product_id == $opcje_produkty['filmowanie']) {
                // Dodaj filmowanie
                global $wpdb;
                $tabela = $wpdb->prefix . 'srl_zakupione_loty';
                $lot = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabela WHERE id = %d", $lot_id), ARRAY_A);
                
                if ($lot) {
                    srl_ustaw_opcje_lotu(
                        $lot_id, 
                        1, // filmowanie = tak
                        $lot['ma_akrobacje'], // zachowaj akrobacje
                        "Dokupiono filmowanie (zamówienie #$order_id)"
                    );
                }
                
            } elseif ($product_id == $opcje_produkty['akrobacje']) {
                // Dodaj akrobacje
                global $wpdb;
                $tabela = $wpdb->prefix . 'srl_zakupione_loty';
                $lot = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabela WHERE id = %d", $lot_id), ARRAY_A);
                
                if ($lot) {
                    srl_ustaw_opcje_lotu(
                        $lot_id, 
                        $lot['ma_filmowanie'], // zachowaj filmowanie
                        1, // akrobacje = tak
                        "Dokupiono akrobacje (zamówienie #$order_id)"
                    );
                }
            }
        }
    }
}

// Walidacja - sprawdź czy użytkownik ma loty przed dodaniem do koszyka
add_filter('woocommerce_add_to_cart_validation', 'srl_validate_flight_option_add_to_cart', 10, 3);

/**
 * Waliduje dodawanie opcji lotu do koszyka
 */
function srl_validate_flight_option_add_to_cart($passed, $product_id, $quantity) {
    $opcje_produkty = srl_get_flight_option_product_ids();
    
    if (in_array($product_id, $opcje_produkty)) {
        if (!is_user_logged_in()) {
            wc_add_notice('Musisz być zalogowany, aby kupić opcje lotu.', 'error');
            return false;
        }
        
        // Sprawdź czy użytkownik ma jakieś loty
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
        
        // Sprawdź czy wybrano lot (z linku lub z formularza)
        if (!isset($_GET['srl_lot_id']) && !isset($_POST['srl_lot_id'])) {
            wc_add_notice('Wybierz lot, do którego chcesz dokupić opcje.', 'error');
            return false;
        }
    }
    
    return $passed;
}

// AJAX handler dla dodawania do koszyka
add_action('wp_ajax_woocommerce_add_to_cart', 'srl_ajax_add_to_cart');
add_action('wp_ajax_nopriv_woocommerce_add_to_cart', 'srl_ajax_add_to_cart');

function srl_ajax_add_to_cart() {
    if (!isset($_POST['product_id'])) {
        wp_send_json_error('Brak ID produktu');
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    $lot_id = isset($_POST['srl_lot_id']) ? intval($_POST['srl_lot_id']) : 0;
    
    // Przygotuj dane koszyka
    $cart_item_data = array();
    if ($lot_id) {
        // Sprawdź czy lot należy do użytkownika
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
    
    // Dodaj do koszyka
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