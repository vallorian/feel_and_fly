<?php
/**
 * Funkcje związane z voucherami upominkowymi
 */

/**
 * Generuje unikalny kod vouchera (bez podobnych znaków)
 */
function srl_generuj_kod_vouchera() {
    // Znaki bez podobnych (bez O, 0, I, l, 1)
    $znaki = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    
    do {
        $kod = '';
        for ($i = 0; $i < 10; $i++) {
            $kod .= $znaki[mt_rand(0, strlen($znaki) - 1)];
        }
        
        // Sprawdź czy kod już istnieje
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tabela WHERE kod_vouchera = %s",
            $kod
        ));
    } while ($exists > 0);
    
    return $kod;
}

/**
 * Sprawdza czy produkt jest voucherem upominkowym
 */
function srl_czy_produkt_vouchera($product) {
    if (!$product) return false;
    
    $voucher_product_ids = array(105, 106, 107, 108);
    return in_array($product->get_id(), $voucher_product_ids);
}

/**
 * Dodaje vouchery do bazy po zakupie
 */
function srl_dodaj_vouchery_po_zakupie($order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
    
    $order = wc_get_order($order_id);
    if (!$order) return 0;
    
    // Sprawdź czy już dodano vouchery dla tego zamówienia
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tabela WHERE order_id = %d",
        $order_id
    ));
    
    if ($existing > 0) {
        return 0; // Już dodano vouchery dla tego zamówienia
    }
    
    $user_id = $order->get_user_id();
    if (!$user_id) return 0; // Tylko dla zalogowanych użytkowników
    
    $data_zakupu = $order->get_date_created()->date('Y-m-d H:i:s');
    $data_waznosci = date('Y-m-d', strtotime($data_zakupu . ' +1 year'));
    
    // Pobierz dane billing
    $imie = $order->get_billing_first_name();
    $nazwisko = $order->get_billing_last_name();
    $email_odbiorcy = $order->get_billing_email();
    $buyer_name = trim($imie . ' ' . $nazwisko);
    
    $dodane_vouchery = 0;
    
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        
        if ($product && srl_czy_produkt_vouchera($product)) {
            $quantity = $item->get_quantity();
            $nazwa_produktu = $item->get_name();
            
            // Dodaj tyle voucherów ile kupiono
            for ($i = 0; $i < $quantity; $i++) {
                $kod_vouchera = srl_generuj_kod_vouchera();
                
                $result = $wpdb->insert(
                    $tabela,
                    array(
                        'order_item_id' => $item_id,
                        'order_id' => $order_id,
                        'buyer_user_id' => $user_id,
                        'buyer_imie' => $imie,
                        'buyer_nazwisko' => $nazwisko,
                        'nazwa_produktu' => $nazwa_produktu,
                        'kod_vouchera' => $kod_vouchera,
                        'status' => 'do_wykorzystania',
                        'data_zakupu' => $data_zakupu,
                        'data_waznosci' => $data_waznosci
                    ),
                    array('%d','%d','%d','%s','%s','%s','%s','%s','%s','%s')
                );
                
                if ($result !== false) {
                    $dodane_vouchery++;
                    
                    // Wyślij email z kodem vouchera - NOWA FUNKCJA
                    $email_sent = srl_wyslij_email_voucher(
                        $email_odbiorcy,
                        $kod_vouchera,
                        $nazwa_produktu,
                        $data_waznosci,
                        $buyer_name
                    );
                    
                    // Loguj jeśli email się nie wysłał
                    if (!$email_sent) {
                        error_log("SRL: Nie udało się wysłać emaila z voucherem {$kod_vouchera} do {$email_odbiorcy}");
                    }
                }
            }
        }
    }
    
    return $dodane_vouchery;
}

/**
 * Usuwa vouchery zamówienia z bazy (przy anulowaniu, zwrocie itp.)
 */
function srl_usun_vouchery_zamowienia($order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
    
    // Usuń tylko vouchery które nie zostały wykorzystane
    $usuniete = $wpdb->delete(
        $tabela,
        array(
            'order_id' => $order_id,
            'status' => 'do_wykorzystania'
        ),
        array('%d', '%s')
    );
    
    return $usuniete;
}

/**
 * Wykorzystuje voucher - zamienia na lot
 */
function srl_wykorzystaj_voucher($kod_vouchera, $user_id) {
    global $wpdb;
    $tabela_vouchery = $wpdb->prefix . 'srl_vouchery_upominkowe';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Sprawdź czy voucher istnieje i jest dostępny
    $voucher = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_vouchery 
         WHERE kod_vouchera = %s 
         AND status = 'do_wykorzystania' 
         AND data_waznosci >= CURDATE()",
        $kod_vouchera
    ), ARRAY_A);
    
    if (!$voucher) {
        return array('success' => false, 'message' => 'Nieprawidłowy kod vouchera lub voucher wygasł.');
    }
    
    // Pobierz dane użytkownika
    $user = get_userdata($user_id);
    if (!$user) {
        return array('success' => false, 'message' => 'Nieprawidłowy użytkownik.');
    }
    
    $data_wykorzystania = srl_get_current_datetime();
    $data_waznosci_lotu = date('Y-m-d', strtotime($data_wykorzystania . ' +1 year'));
    
    // Rozpocznij transakcję
    $wpdb->query('START TRANSACTION');
    
    try {
        // Analizuj opcje produktu z vouchera
        $opcje_produktu = srl_analiza_opcji_produktu($voucher['nazwa_produktu']);
        
        // 1. Dodaj lot do tabeli lotów
        $result_lot = $wpdb->insert(
            $tabela_loty,
            array(
                'order_item_id' => $voucher['order_item_id'],
                'order_id' => $voucher['order_id'],
                'user_id' => $user_id,
                'imie' => $user->first_name ?: 'Voucher',
                'nazwisko' => $user->last_name ?: 'User',
                'nazwa_produktu' => $voucher['nazwa_produktu'],
                'status' => 'wolny',
                'data_zakupu' => $data_wykorzystania,
                'data_waznosci' => $data_waznosci_lotu,
                'ma_filmowanie' => $opcje_produktu['ma_filmowanie'],
                'ma_akrobacje' => $opcje_produktu['ma_akrobacje']
            ),
            array('%d','%d','%d','%s','%s','%s','%s','%s','%s','%d','%d')
        );
        
        if ($result_lot === false) {
            throw new Exception('Błąd dodania lotu.');
        }
        
        $lot_id = $wpdb->insert_id;
        
        // Dodaj do historii jeśli voucher ma opcje
        if ($opcje_produktu['ma_filmowanie'] || $opcje_produktu['ma_akrobacje']) {
            $opcje_tekst = array();
            if ($opcje_produktu['ma_filmowanie']) $opcje_tekst[] = 'filmowanie';
            if ($opcje_produktu['ma_akrobacje']) $opcje_tekst[] = 'akrobacje';
            
            if (function_exists('srl_ustaw_opcje_lotu')) {
                srl_ustaw_opcje_lotu(
                    $lot_id, 
                    $opcje_produktu['ma_filmowanie'], 
                    $opcje_produktu['ma_akrobacje'], 
                    'Lot z vouchera z opcjami: ' . implode(', ', $opcje_tekst)
                );
            }
        }
        
        // 2. Zaktualizuj voucher
        $result_voucher = $wpdb->update(
            $tabela_vouchery,
            array(
                'status' => 'wykorzystany',
                'data_wykorzystania' => $data_wykorzystania,
                'wykorzystany_przez_user_id' => $user_id,
                'lot_id' => $lot_id
            ),
            array('id' => $voucher['id']),
            array('%s', '%s', '%d', '%d'),
            array('%d')
        );
        
        if ($result_voucher === false) {
            throw new Exception('Błąd aktualizacji vouchera.');
        }
        
        // Zatwierdź transakcję
        $wpdb->query('COMMIT');
        
        return array(
            'success' => true, 
            'message' => 'Voucher został wykorzystany! Lot został dodany do Twojego konta.',
            'lot_id' => $lot_id
        );
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return array('success' => false, 'message' => 'Błąd podczas wykorzystania vouchera: ' . $e->getMessage());
    }
}



/**
 * Oznacz przeterminowane vouchery
 */
function srl_oznacz_przeterminowane_vouchery() {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
    
    $wpdb->query(
        "UPDATE $tabela 
         SET status = 'przeterminowany' 
         WHERE status = 'do_wykorzystania' 
         AND data_waznosci < CURDATE()"
    );
}