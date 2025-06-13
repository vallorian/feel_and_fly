<?php
/**
 * Integracja z WooCommerce - zarządzanie lotami przy zmianie statusów zamówień
 */

// Rejestracja hooków WooCommerce
add_action('woocommerce_order_status_changed', 'srl_order_status_changed', 10, 3);

/**
 * Główna funkcja obsługująca zmiany statusów zamówień
 */
function srl_order_status_changed($order_id, $old_status, $new_status) {
    // Statusy które kwalifikują zamówienie do lotów/voucherów
    $valid_statuses = array('processing', 'completed');
    
    // Statusy które dyskwalifikują zamówienie
    $invalid_statuses = array('cancelled', 'refunded', 'failed', 'pending', 'on-hold', 'trash');
    
    if (in_array($new_status, $valid_statuses)) {
        // Dodaj loty jeśli zamówienie ma produkty lotów
        srl_dodaj_loty_po_zakupie($order_id);
        
        // Dodaj vouchery jeśli zamówienie ma produkty voucherów i tabela istnieje
        if (function_exists('srl_voucher_table_exists') && srl_voucher_table_exists() && function_exists('srl_dodaj_vouchery_po_zakupie')) {
            srl_dodaj_vouchery_po_zakupie($order_id);
        }
    } elseif (in_array($new_status, $invalid_statuses)) {
        // Usuń loty jeśli zamówienie zostało anulowane/zwrócone itp.
        srl_usun_loty_zamowienia($order_id);
        
        // Usuń vouchery jeśli zamówienie zostało anulowane/zwrócone itp.
        if (function_exists('srl_voucher_table_exists') && srl_voucher_table_exists() && function_exists('srl_usun_vouchery_zamowienia')) {
            srl_usun_vouchery_zamowienia($order_id);
        }
    }
}

/**
 * Funkcja helper - pobiera ID produktów które są lotami
 */
function srl_get_flight_product_ids() {
    return array(62, 63, 65, 67, 69, 73, 74, 75, 76, 77);
}

/**
 * Sprawdza czy produkt jest lotem
 */
function srl_czy_produkt_lotu($product) {
    if (!$product) return false;
    
    $dozwolone_id = srl_get_flight_product_ids();
    return in_array($product->get_id(), $dozwolone_id);
}

/**
 * Dodaje loty do bazy po zakupie/zatwierdzeniu zamówienia
 */
function srl_dodaj_loty_po_zakupie($order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    // Sprawdź czy już dodano loty dla tego zamówienia
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tabela WHERE order_id = %d",
        $order_id
    ));
    
    if ($existing > 0) {
        return; // Już dodano loty dla tego zamówienia
    }
    
    $user_id = $order->get_user_id();
    if (!$user_id) return; // Tylko dla zalogowanych użytkowników
    
    $data_zakupu = $order->get_date_created()->date('Y-m-d H:i:s');
    $data_waznosci = date('Y-m-d', strtotime($data_zakupu . ' +1 year'));
    
    // Pobierz dane billing
    $imie = $order->get_billing_first_name();
    $nazwisko = $order->get_billing_last_name();
    
    $dodane_loty = 0;
    
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        
        if ($product && srl_czy_produkt_lotu($product)) {
            $quantity = $item->get_quantity();
            $nazwa_produktu = $item->get_name();
            
			// Dodaj tyle rekordów ile kupiono lotów
			for ($i = 0; $i < $quantity; $i++) {
				// Analizuj opcje produktu
				$opcje = srl_analiza_opcji_produktu($nazwa_produktu);
				
				$result = $wpdb->insert(
					$tabela,
					array(
						'order_item_id' => $item_id,
						'order_id' => $order_id,
						'user_id' => $user_id,
						'imie' => $imie,
						'nazwisko' => $nazwisko,
						'nazwa_produktu' => $nazwa_produktu,
						'status' => 'wolny',
						'data_zakupu' => $data_zakupu,
						'data_waznosci' => $data_waznosci,
						'ma_filmowanie' => $opcje['ma_filmowanie'],
						'ma_akrobacje' => $opcje['ma_akrobacje']
					),
					array('%d','%d','%d','%s','%s','%s','%s','%s','%s','%d','%d')
				);
				
				if ($result !== false) {
					$dodane_loty++;
					$lot_id = $wpdb->insert_id;
					
					// Dodaj do historii jeśli ma jakieś opcje
					if ($opcje['ma_filmowanie'] || $opcje['ma_akrobacje']) {
						$opcje_tekst = array();
						if ($opcje['ma_filmowanie']) $opcje_tekst[] = 'filmowanie';
						if ($opcje['ma_akrobacje']) $opcje_tekst[] = 'akrobacje';
						
						srl_ustaw_opcje_lotu(
							$lot_id, 
							$opcje['ma_filmowanie'], 
							$opcje['ma_akrobacje'], 
							'Zakup lotu z opcjami: ' . implode(', ', $opcje_tekst)
						);
					}
				}
			}
        }
    }
    
    // Log dla debugowania
    if ($dodane_loty > 0) {
        error_log("SRL: Dodano $dodane_loty lotów dla zamówienia #$order_id");
    }
    
    return $dodane_loty;
}

/**
 * Usuwa loty zamówienia z bazy (przy anulowaniu, zwrocie itp.)
 */
function srl_usun_loty_zamowienia($order_id) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    // Pobierz loty tego zamówienia które są zarezerwowane
    $zarezerwowane_loty = $wpdb->get_results($wpdb->prepare(
        "SELECT id, termin_id FROM $tabela_loty 
         WHERE order_id = %d AND status = 'zarezerwowany' AND termin_id IS NOT NULL",
        $order_id
    ));
    
    // Zwolnij zarezerwowane sloty
    foreach ($zarezerwowane_loty as $lot) {
        if ($lot->termin_id) {
            $wpdb->update(
                $tabela_terminy,
                array('status' => 'Wolny', 'klient_id' => null),
                array('id' => $lot->termin_id),
                array('%s', '%d'),
                array('%d')
            );
        }
    }
    
    // Usuń wszystkie loty tego zamówienia
    $usuniete = $wpdb->delete(
        $tabela_loty,
        array('order_id' => $order_id),
        array('%d')
    );
    
    if ($usuniete > 0) {
        error_log("SRL: Usunięto $usuniete lotów dla zamówienia #$order_id");
    }
    
    return $usuniete;
}

/**
 * Hook na usunięcie zamówienia - usuń powiązane loty
 */
add_action('before_delete_post', 'srl_before_delete_order');
function srl_before_delete_order($post_id) {
    if (get_post_type($post_id) === 'shop_order') {
        srl_usun_loty_zamowienia($post_id);
    }
}

/**
 * Funkcja do migracji istniejących zamówień (jednorazowa)
 */
function srl_migruj_istniejace_zamowienia() {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Sprawdź czy tabela istnieje
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela'") == $tabela;
    if (!$table_exists) {
        return;
    }
    
    // Sprawdź czy już istnieją jakieś loty w tabeli
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $tabela");
    if ($count > 0) {
        return; // Już zmigrowano
    }
    
    // Pobierz wszystkie zamówienia ze statusem processing lub completed
    $orders = wc_get_orders(array(
        'status' => array('processing', 'completed'),
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'DESC'
    ));
    
    $przetworzone = 0;
    foreach ($orders as $order) {
        // Sprawdź czy zamówienie ma produkty lotów
        $ma_loty = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && srl_czy_produkt_lotu($product)) {
                $ma_loty = true;
                break;
            }
        }
        
        if ($ma_loty) {
            $dodane = srl_dodaj_loty_po_zakupie($order->get_id());
            if ($dodane > 0) {
                $przetworzone++;
            }
        }
    }
    
    if ($przetworzone > 0) {
        error_log("SRL: Zmigrowano $przetworzone zamówień");
    }
}

// Uruchom migrację przy ładowaniu panelu admina (jednorazowo)
add_action('admin_init', 'srl_migruj_istniejace_zamowienia');


function srl_oznacz_przeterminowane_loty() {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    // Oznacz loty jako przeterminowane
    $wpdb->query(
        "UPDATE $tabela 
         SET status = 'przedawniony' 
         WHERE status IN ('wolny', 'zarezerwowany') 
         AND data_waznosci < CURDATE()"
    );
    
    // Zwolnij sloty z przeterminowanych zarezerwowanych lotów
    $przeterminowane_rezerwacje = $wpdb->get_results(
        "SELECT termin_id FROM $tabela 
         WHERE status = 'przedawniony' 
         AND termin_id IS NOT NULL"
    );
    
    foreach ($przeterminowane_rezerwacje as $rez) {
        if ($rez->termin_id) {
            $wpdb->update(
                $tabela_terminy,
                array('status' => 'Wolny', 'klient_id' => null),
                array('id' => $rez->termin_id),
                array('%s', '%d'),
                array('%d')
            );
        }
    }
    
    // Wyczyść termin_id dla przeterminowanych
    $wpdb->query(
        "UPDATE $tabela 
         SET termin_id = NULL 
         WHERE status = 'przedawniony'"
    );
}



/**
 * Daily cron - sprawdź przeterminowane loty
 */
add_action('wp', 'srl_schedule_daily_check');
function srl_schedule_daily_check() {
    if (!wp_next_scheduled('srl_sprawdz_przeterminowane_loty')) {
        wp_schedule_event(time(), 'daily', 'srl_sprawdz_przeterminowane_loty');
    }
}
// Dodaj sprawdzanie przeterminowanych voucherów tylko jeśli tabela istnieje
add_action('srl_sprawdz_przeterminowane_loty', function() {
    if (function_exists('srl_voucher_table_exists') && srl_voucher_table_exists() && function_exists('srl_oznacz_przeterminowane_vouchery')) {
        srl_oznacz_przeterminowane_vouchery();
    }
});
add_action('srl_sprawdz_przeterminowane_loty', 'srl_oznacz_przeterminowane_loty');