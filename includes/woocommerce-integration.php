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
    
    // Pobierz dane użytkownika dla historii
    $user = get_userdata($user_id);
    $user_display_name = $user ? $user->display_name : ($imie . ' ' . $nazwisko);
    
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
					
					// Utwórz KOMPLETNĄ historię lotu od początku
					$historia_poczatkowa = array();
					
					// 1. Wpis o przypisaniu ID lotu (główny wpis) - ZMIENIONY FORMAT
					$opis_zakupu = sprintf('Przypisanie ID lotu #%d, Lot ważny do %s (12 miesięcy od zakupu)', 
						$lot_id, 
						date('d.m.Y', strtotime($data_waznosci))
					);
					
					if ($quantity > 1) {
						$opis_zakupu .= sprintf(' [lot %d z %d w zamówieniu #%d]', $i + 1, $quantity, $order_id);
					} else {
						$opis_zakupu .= sprintf(' [zamówienie #%d]', $order_id);
					}
					
					$historia_poczatkowa[] = array(
						'data' => $data_zakupu,
						'opis' => $opis_zakupu,
						'typ' => 'przypisanie_id', // ZMIENIONY TYP
						'executor' => 'System', // ZMIENIONY EXECUTOR
						'szczegoly' => array(
							'nazwa_produktu' => $nazwa_produktu,
							'cena' => $item->get_total(),
							'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1',
							'order_id' => $order_id,
							'order_item_id' => $item_id,
							'user_id' => $user_id,
							'user_info' => $user_display_name,
							'data_waznosci' => $data_waznosci
						)
					);
					
					// 2. Jeśli lot ma opcje przy zakupie, dodaj osobne wpisy dla każdej opcji
					if ($opcje['ma_filmowanie']) {
						$historia_poczatkowa[] = array(
							'data' => $data_zakupu,
							'opis' => 'Lot zakupiony z opcją filmowania',
							'typ' => 'opcja_przy_zakupie',
							'executor' => 'System',
							'szczegoly' => array(
								'opcja' => 'filmowanie',
								'dodano_przy_zakupie' => true,
								'order_id' => $order_id
							)
						);
					}
					
					if ($opcje['ma_akrobacje']) {
						$historia_poczatkowa[] = array(
							'data' => $data_zakupu,
							'opis' => 'Lot zakupiony z opcją akrobacji',
							'typ' => 'opcja_przy_zakupie',
							'executor' => 'System',
							'szczegoly' => array(
								'opcja' => 'akrobacje',
								'dodano_przy_zakupie' => true,
								'order_id' => $order_id
							)
						);
					}
					
					// 3. USUŃ osobny wpis o ważności - jest już w głównym opisie
					// (Usuń cały blok z 'typ' => 'ustawienie_waznosci')
					
					// Zapisz historię do bazy
					$wpdb->update(
						$tabela,
						array('historia_modyfikacji' => json_encode($historia_poczatkowa)),
						array('id' => $lot_id),
						array('%s'),
						array('%d')
					);
				}
            }
        }
    }
    
    // Log dla debugowania
    if ($dodane_loty > 0) {
        error_log("SRL: Dodano $dodane_loty lotów dla zamówienia #$order_id z pełną historią");
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


add_action('woocommerce_order_status_changed', 'srl_obsluz_opcje_lotow', 20, 3);

function srl_obsluz_opcje_lotow($order_id, $old_status, $new_status) {
    // Opcje są przetwarzane tylko dla ważnych statusów
    $valid_statuses = array('processing', 'completed');
    
    if (!in_array($new_status, $valid_statuses)) {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    $user_id = $order->get_user_id();
    if (!$user_id) {
        return;
    }
    
    $opcje_produkty = srl_get_flight_option_product_ids();
    
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $lot_meta = $item->get_meta('_srl_lot_id');
        $quantity = $item->get_quantity();
        
        // Sprawdź czy to opcja dla konkretnego lotu
        if ($lot_meta && in_array($product_id, $opcje_produkty)) {
            $lot_id = intval($lot_meta);
            
            // Sprawdź czy lot istnieje i należy do użytkownika
            global $wpdb;
            $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_loty WHERE id = %d AND user_id = %d",
                $lot_id, $user_id
            ), ARRAY_A);
            
            if (!$lot) {
                continue;
            }
            
            // Obsłuż różne typy opcji
            if ($product_id == $opcje_produkty['filmowanie']) {
                srl_dokup_filmowanie($lot_id, $order_id, $item_id, $quantity);
            } elseif ($product_id == $opcje_produkty['akrobacje']) {
                srl_dokup_akrobacje($lot_id, $order_id, $item_id, $quantity);
            } elseif ($product_id == $opcje_produkty['przedluzenie']) {
                srl_dokup_przedluzenie($lot_id, $order_id, $item_id, $quantity);
            }
        }
    }
}

function srl_dokup_filmowanie($lot_id, $order_id, $item_id, $quantity = 1) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz aktualny stan lotu
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT ma_filmowanie FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot || $lot['ma_filmowanie']) {
        return false; // Już ma filmowanie
    }
    
    // Ustaw filmowanie
    $result = $wpdb->update(
        $tabela_loty,
        array('ma_filmowanie' => 1),
        array('id' => $lot_id),
        array('%d'),
        array('%d')
    );
    
    if ($result !== false) {
        // DOPISZ do historii
        $opis_zmiany = "Dokupiono opcję filmowania lotu (zamówienie #$order_id, item #$item_id)";
        if ($quantity > 1) {
            $opis_zmiany .= " - ilość: $quantity";
        }
        
        $wpis_historii = array(
            'data' => srl_get_current_datetime(),
            'opis' => $opis_zmiany,
            'typ' => 'dokupienie_filmowanie',
            'executor' => 'Klient',
            'szczegoly' => array(
                'opcja' => 'filmowanie',
                'order_id' => $order_id,
                'item_id' => $item_id,
                'quantity' => $quantity,
                'stary_stan' => 0,
                'nowy_stan' => 1
            )
        );
        
        srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
    }
    
    return $result !== false;
}

function srl_dokup_akrobacje($lot_id, $order_id, $item_id, $quantity = 1) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz aktualny stan lotu
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT ma_akrobacje FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot || $lot['ma_akrobacje']) {
        return false; // Już ma akrobacje
    }
    
    // Ustaw akrobacje
    $result = $wpdb->update(
        $tabela_loty,
        array('ma_akrobacje' => 1),
        array('id' => $lot_id),
        array('%d'),
        array('%d')
    );
    
    if ($result !== false) {
        // DOPISZ do historii
        $opis_zmiany = "Dokupiono opcję akrobacji podczas lotu (zamówienie #$order_id, item #$item_id)";
        if ($quantity > 1) {
            $opis_zmiany .= " - ilość: $quantity";
        }
        
        $wpis_historii = array(
            'data' => srl_get_current_datetime(),
            'opis' => $opis_zmiany,
            'typ' => 'dokupienie_akrobacje',
            'executor' => 'Klient',
            'szczegoly' => array(
                'opcja' => 'akrobacje',
                'order_id' => $order_id,
                'item_id' => $item_id,
                'quantity' => $quantity,
                'stary_stan' => 0,
                'nowy_stan' => 1
            )
        );
        
        srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
    }
    
    return $result !== false;
}

function srl_dokup_przedluzenie($lot_id, $order_id, $item_id = 0, $quantity = 1) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz aktualną datę ważności
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT data_waznosci FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot) {
        return false;
    }
    
    $stara_data = $lot['data_waznosci'];
    
    // POPRAWKA: Przedłuż o $quantity lat (ile przedłużeń zakupiono)
    $nowa_data = $stara_data;
    for ($i = 0; $i < $quantity; $i++) {
        $nowa_data = date('Y-m-d', strtotime($nowa_data . ' +1 year'));
    }
    
    // Aktualizuj datę ważności
    $result = $wpdb->update(
        $tabela_loty,
        array('data_waznosci' => $nowa_data),
        array('id' => $lot_id),
        array('%s'),
        array('%d')
    );
    
    if ($result !== false) {
        // Przygotuj opis zależnie od quantity
        $lata_text = ($quantity == 1) ? '1 rok' : (($quantity < 5) ? "$quantity lata" : "$quantity lat");
        
        if ($item_id > 0) {
            $opis_zmiany = "Przedłużono ważność lotu o $lata_text do " . date('d.m.Y', strtotime($nowa_data)) . 
                          " (zamówienie #$order_id, item #$item_id)";
        } else {
            $opis_zmiany = "Przedłużono ważność lotu o $lata_text do " . date('d.m.Y', strtotime($nowa_data)) . 
                          " (zamówienie #$order_id)";
        }
        
        $wpis_historii = array(
            'data' => srl_get_current_datetime(),
            'opis' => $opis_zmiany,
            'typ' => 'przedluzenie_waznosci',
            'executor' => 'Klient',
            'szczegoly' => array(
                'stara_data_waznosci' => $stara_data,
                'nowa_data_waznosci' => $nowa_data,
                'przedluzenie_lat' => $quantity,
                'order_id' => $order_id,
                'item_id' => $item_id,
                'quantity_w_zamowieniu' => $quantity
            )
        );
        
        srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
    }
    
    return $result !== false;
}