<?php

function srl_generuj_kod_vouchera() {

    $znaki = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    do {
        $kod = '';
        for ($i = 0; $i < 10; $i++) {
            $kod .= $znaki[mt_rand(0, strlen($znaki) - 1)];
        }

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tabela WHERE kod_vouchera = %s",
            $kod
        ));
    } while ($exists > 0);

    return $kod;
}

function srl_czy_produkt_vouchera($product) {
    if (!$product) return false;

    $voucher_product_ids = array(105, 106, 107, 108);
    return in_array($product->get_id(), $voucher_product_ids);
}

function srl_dodaj_vouchery_po_zakupie($order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';

    $order = wc_get_order($order_id);
    if (!$order) return 0;

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tabela WHERE order_id = %d",
        $order_id
    ));

    if ($existing > 0) {
        return 0; 
    }

    $user_id = $order->get_user_id();
    if (!$user_id) return 0; 

    $data_zakupu = $order->get_date_created()->date('Y-m-d H:i:s');
    $data_waznosci = date('Y-m-d', strtotime($data_zakupu . ' +1 year'));

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

                    $email_sent = srl_wyslij_email_voucher(
                        $email_odbiorcy,
                        $kod_vouchera,
                        $nazwa_produktu,
                        $data_waznosci,
                        $buyer_name
                    );

                    if (!$email_sent) {
                        error_log("SRL: Nie udało się wysłać emaila z voucherem {$kod_vouchera} do {$email_odbiorcy}");
                    }
                }
            }
        }
    }

    return $dodane_vouchery;
}

function srl_usun_vouchery_zamowienia($order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';

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

function srl_wykorzystaj_voucher($kod_vouchera, $user_id) {
    global $wpdb;
    $tabela_vouchery = $wpdb->prefix . 'srl_vouchery_upominkowe';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

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

    $user = get_userdata($user_id);
    if (!$user) {
        return array('success' => false, 'message' => 'Nieprawidłowy użytkownik.');
    }

    $data_wykorzystania = srl_get_current_datetime();
    $data_waznosci_lotu = date('Y-m-d', strtotime($data_wykorzystania . ' +1 year'));

    $wpdb->query('START TRANSACTION');

    try {

        $opcje_produktu = srl_analiza_opcji_produktu($voucher['nazwa_produktu']);

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

		$historia_poczatkowa = array();

		$historia_poczatkowa[] = array(
			'data' => $data_wykorzystania,
			'typ' => 'przypisanie_id',
			'executor' => 'System',
			'szczegoly' => array(
				'lot_id' => $lot_id,
				'nazwa_produktu' => $voucher['nazwa_produktu'],
				'kod_vouchera' => $kod_vouchera,
				'voucher_id' => $voucher['id'],
				'user_id' => $user_id,
				'data_waznosci' => $data_waznosci_lotu,
				'zrodlo' => 'voucher'
			)
		);

		if ($opcje_produktu['ma_filmowanie']) {
			$historia_poczatkowa[] = array(
				'data' => $data_wykorzystania,
				'typ' => 'opcja_przy_zakupie',
				'executor' => 'System',
				'szczegoly' => array(
					'opcja' => 'filmowanie',
					'dodano_przy_zakupie' => true,
					'zrodlo' => 'voucher',
					'kod_vouchera' => $kod_vouchera
				)
			);
		}

		if ($opcje_produktu['ma_akrobacje']) {
			$historia_poczatkowa[] = array(
				'data' => $data_wykorzystania,
				'typ' => 'opcja_przy_zakupie',
				'executor' => 'System',
				'szczegoly' => array(
					'opcja' => 'akrobacje',
					'dodano_przy_zakupie' => true,
					'zrodlo' => 'voucher',
					'kod_vouchera' => $kod_vouchera
				)
			);
		}

		$wpdb->update(
			$tabela_loty,
			array('historia_modyfikacji' => json_encode($historia_poczatkowa)),
			array('id' => $lot_id),
			array('%s'),
			array('%d')
		);

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