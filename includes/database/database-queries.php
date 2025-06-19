<?php if (!defined('ABSPATH')) {exit;}

function srl_pobierz_loty_uzytkownika($user_id, $status = null) {
    return srl_get_user_flights($user_id, $status);
}

function srl_pobierz_slot_szczegoly($termin_id) {
    return srl_get_slot_details($termin_id);
}

function srl_sprawdz_czy_slot_wolny($termin_id) {
    return srl_is_slot_available($termin_id);
}

function srl_pobierz_dostepne_sloty_dnia($data) {
    return srl_get_available_slots($data);
}

function srl_zwroc_godziny_wg_pilota($data) {
    $godziny_wg_pilota = srl_get_day_schedule_optimized(sanitize_text_field($data));
    
    wp_send_json_success(array(
        'godziny_wg_pilota' => $godziny_wg_pilota
    ));
}

function srl_pobierz_szczegoly_terminu($termin_id) {
    return srl_format_slot_details($termin_id);
}

function srl_estymuj_date_realizacji($lot) {
    if (!$lot['termin_id']) {
        return null;
    }
    
    $slot = srl_get_slot_details($lot['termin_id']);
    
    if ($slot && $slot['data'] && strtotime($slot['data']) <= time()) {
        return $slot['data'] . ' 18:00:00';
    }
    
    return null;
}

function srl_pobierz_historie_opcji($user_id, $lot_id) {
    global $wpdb;
    $events = array();
    $opcje_produkty = srl_get_flight_option_product_ids();
    
    // Zoptymalizowane zapytanie - jedno zamiast wielu
    $orders_with_items = $wpdb->get_results($wpdb->prepare(
        "SELECT o.ID as order_id, o.post_date, 
                i.order_item_id, i.meta_value as lot_meta,
                pm1.meta_value as product_id,
                pm2.meta_value as quantity,
                pm3.meta_value as item_name
         FROM {$wpdb->posts} o
         INNER JOIN {$wpdb->woocommerce_order_items} oi ON o.ID = oi.order_id
         INNER JOIN {$wpdb->woocommerce_order_itemmeta} i ON oi.order_item_id = i.order_item_id AND i.meta_key = '_srl_lot_id'
         INNER JOIN {$wpdb->woocommerce_order_itemmeta} pm1 ON oi.order_item_id = pm1.order_item_id AND pm1.meta_key = '_product_id'
         INNER JOIN {$wpdb->woocommerce_order_itemmeta} pm2 ON oi.order_item_id = pm2.order_item_id AND pm2.meta_key = '_qty'
         INNER JOIN {$wpdb->woocommerce_order_itemmeta} pm3 ON oi.order_item_id = pm3.order_item_id AND pm3.meta_key = '_name'
         WHERE o.post_author = %d 
         AND o.post_type = 'shop_order' 
         AND o.post_status IN ('wc-completed', 'wc-processing')
         AND i.meta_value = %s
         AND pm1.meta_value IN (" . implode(',', array_fill(0, count($opcje_produkty), '%d')) . ")
         ORDER BY o.post_date ASC",
        array_merge([$user_id, $lot_id], array_values($opcje_produkty))
    ), ARRAY_A);
    
    foreach ($orders_with_items as $item) {
        $product_id = intval($item['product_id']);
        $quantity = intval($item['quantity']);
        
        $opcja_nazwa = '';
        if ($product_id == $opcje_produkty['filmowanie']) {
            $opcja_nazwa = 'Dokupiono filmowanie';
        } elseif ($product_id == $opcje_produkty['akrobacje']) {
            $opcja_nazwa = 'Dokupiono akrobacje';
        } elseif ($product_id == $opcje_produkty['przedluzenie']) {
            $opcja_nazwa = 'Przedłużenie ważności';
        }
        
        if ($opcja_nazwa) {
            $details = sprintf('Zamówienie #%d - %s (Item ID: %d)', 
                $item['order_id'], $item['item_name'], $item['order_item_id']);
            
            for ($i = 0; $i < $quantity; $i++) {
                $unique_timestamp = strtotime($item['post_date']) + $i;
                
                $events[] = array(
                    'timestamp' => $unique_timestamp,
                    'date' => $item['post_date'],
                    'type' => 'dokupienie',
                    'action_name' => $opcja_nazwa,
                    'executor' => 'Klient',
                    'details' => $details . ($quantity > 1 ? ' (' . ($i + 1) . '/' . $quantity . ')' : ''),
                    'unique_key' => $item['order_id'] . '_' . $item['order_item_id'] . '_' . $i
                );
            }
        }
    }
    
    return $events;
}