<?php

add_action('woocommerce_order_status_changed', 'srl_order_status_changed', 10, 3);

function srl_order_status_changed($order_id, $old_status, $new_status) {

    $valid_statuses = array('processing', 'completed');

    $invalid_statuses = array('cancelled', 'refunded', 'failed', 'pending', 'on-hold', 'trash');

    if (in_array($new_status, $valid_statuses)) {

        srl_dodaj_loty_po_zakupie($order_id);

        if (function_exists('srl_voucher_table_exists') && srl_voucher_table_exists() && function_exists('srl_dodaj_vouchery_po_zakupie')) {
            srl_dodaj_vouchery_po_zakupie($order_id);
        }
    } elseif (in_array($new_status, $invalid_statuses)) {

        srl_usun_loty_zamowienia($order_id);

        if (function_exists('srl_voucher_table_exists') && srl_voucher_table_exists() && function_exists('srl_usun_vouchery_zamowienia')) {
            srl_usun_vouchery_zamowienia($order_id);
        }
    }
}

function srl_get_flight_product_ids() {
    return array(62, 63, 65, 67, 69, 73, 74, 75, 76, 77);
}

function srl_czy_produkt_lotu($product) {
    if (!$product) return false;

    $dozwolone_id = srl_get_flight_product_ids();
    return in_array($product->get_id(), $dozwolone_id);
}

function srl_dodaj_loty_po_zakupie($order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';

    $order = wc_get_order($order_id);
    if (!$order) return;

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tabela WHERE order_id = %d",
        $order_id
    ));

    if ($existing > 0) {
        return; 
    }

    $user_id = $order->get_user_id();
    if (!$user_id) return; 

    $data_zakupu = $order->get_date_created()->date('Y-m-d H:i:s');
    $data_waznosci = date('Y-m-d', strtotime($data_zakupu . ' +1 year'));

    $imie = $order->get_billing_first_name();
    $nazwisko = $order->get_billing_last_name();

    $dodane_loty = 0;

    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();

        if ($product && srl_czy_produkt_lotu($product)) {
            $quantity = $item->get_quantity();
            $nazwa_produktu = $item->get_name();

            for ($i = 0; $i < $quantity; $i++) {

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

                    $historia_poczatkowa = array();

                    $historia_poczatkowa[] = array(
                        'data' => $data_zakupu,
                        'typ' => 'przypisanie_id',
                        'executor' => 'System',
                        'szczegoly' => array(
                            'lot_id' => $lot_id,
                            'nazwa_produktu' => $nazwa_produktu,
                            'order_id' => $order_id,
                            'order_item_id' => $item_id,
                            'user_id' => $user_id,
                            'data_waznosci' => $data_waznosci,
                            'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1'
                        )
                    );

                    if ($opcje['ma_filmowanie']) {
                        $historia_poczatkowa[] = array(
                            'data' => $data_zakupu,
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
                            'typ' => 'opcja_przy_zakupie',
                            'executor' => 'System',
                            'szczegoly' => array(
                                'opcja' => 'akrobacje',
                                'dodano_przy_zakupie' => true,
                                'order_id' => $order_id
                            )
                        );
                    }

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

    if ($dodane_loty > 0) {
        error_log("SRL: Dodano $dodane_loty lotów dla zamówienia #$order_id z nowym systemem historii");
    }

    return $dodane_loty;
}

function srl_usun_loty_zamowienia($order_id) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';

    $zarezerwowane_loty = $wpdb->get_results($wpdb->prepare(
        "SELECT id, termin_id FROM $tabela_loty 
         WHERE order_id = %d AND status = 'zarezerwowany' AND termin_id IS NOT NULL",
        $order_id
    ));

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

add_action('before_delete_post', 'srl_before_delete_order');
function srl_before_delete_order($post_id) {
    if (get_post_type($post_id) === 'shop_order') {
        srl_usun_loty_zamowienia($post_id);
    }
}

function srl_migruj_istniejace_zamowienia() {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';

    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela'") == $tabela;
    if (!$table_exists) {
        return;
    }

    $count = $wpdb->get_var("SELECT COUNT(*) FROM $tabela");
    if ($count > 0) {
        return; 
    }

    $orders = wc_get_orders(array(
        'status' => array('processing', 'completed'),
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'DESC'
    ));

    $przetworzone = 0;
    foreach ($orders as $order) {

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

add_action('admin_init', 'srl_migruj_istniejace_zamowienia');

function srl_oznacz_przeterminowane_loty() {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';

    $wpdb->query(
        "UPDATE $tabela 
         SET status = 'przedawniony' 
         WHERE status IN ('wolny', 'zarezerwowany') 
         AND data_waznosci < CURDATE()"
    );

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

    $wpdb->query(
        "UPDATE $tabela 
         SET termin_id = NULL 
         WHERE status = 'przedawniony'"
    );
}

add_action('wp', 'srl_schedule_daily_check');
function srl_schedule_daily_check() {
    if (!wp_next_scheduled('srl_sprawdz_przeterminowane_loty')) {
        wp_schedule_event(time(), 'daily', 'srl_sprawdz_przeterminowane_loty');
    }
}

add_action('srl_sprawdz_przeterminowane_loty', function() {
    if (function_exists('srl_voucher_table_exists') && srl_voucher_table_exists() && function_exists('srl_oznacz_przeterminowane_vouchery')) {
        srl_oznacz_przeterminowane_vouchery();
    }
});
add_action('srl_sprawdz_przeterminowane_loty', 'srl_oznacz_przeterminowane_loty');


function srl_dokup_filmowanie($lot_id, $order_id, $item_id, $quantity = 1) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT ma_filmowanie FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);

    if (!$lot || $lot['ma_filmowanie']) {
        return false; 
    }

    $result = $wpdb->update(
        $tabela_loty,
        array('ma_filmowanie' => 1),
        array('id' => $lot_id),
        array('%d'),
        array('%d')
    );

    if ($result !== false) {
        // Zapisz każdą opcję osobno w historii
        for ($i = 0; $i < $quantity; $i++) {
            // Dodaj sekundy do timestamp aby uniknąć duplikatów
            $current_time = srl_get_current_datetime();
            if ($i > 0) {
                $current_time = date('Y-m-d H:i:s', strtotime($current_time) + $i);
            }
            
            $wpis_historii = array(
                'data' => $current_time,
                'typ' => 'dokupienie_filmowanie',
                'executor' => 'Klient',
                'szczegoly' => array(
                    'opcja' => 'filmowanie',
                    'order_id' => $order_id,
                    'item_id' => $item_id,
                    'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1',
                    'stary_stan' => 0,
                    'nowy_stan' => 1
                )
            );

            srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
        }
    }

    return $result !== false;
}

function srl_dokup_akrobacje($lot_id, $order_id, $item_id, $quantity = 1) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT ma_akrobacje FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);

    if (!$lot || $lot['ma_akrobacje']) {
        return false; 
    }

    $result = $wpdb->update(
        $tabela_loty,
        array('ma_akrobacje' => 1),
        array('id' => $lot_id),
        array('%d'),
        array('%d')
    );

    if ($result !== false) {
        // Zapisz każdą opcję osobno w historii
        for ($i = 0; $i < $quantity; $i++) {
            // Dodaj sekundy do timestamp aby uniknąć duplikatów
            $current_time = srl_get_current_datetime();
            if ($i > 0) {
                $current_time = date('Y-m-d H:i:s', strtotime($current_time) + $i);
            }
            
            $wpis_historii = array(
                'data' => $current_time,
                'typ' => 'dokupienie_akrobacje',
                'executor' => 'Klient',
                'szczegoly' => array(
                    'opcja' => 'akrobacje',
                    'order_id' => $order_id,
                    'item_id' => $item_id,
                    'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1',
                    'stary_stan' => 0,
                    'nowy_stan' => 1
                )
            );

            srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
        }
    }

    return $result !== false;
}

function srl_dokup_przedluzenie($lot_id, $order_id, $item_id = 0, $quantity = 1) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT data_waznosci FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);

    if (!$lot) {
        return false;
    }

    $stara_data = $lot['data_waznosci'];

    $nowa_data = $stara_data;
    for ($i = 0; $i < $quantity; $i++) {
        $nowa_data = date('Y-m-d', strtotime($nowa_data . ' +1 year'));
    }

    $result = $wpdb->update(
        $tabela_loty,
        array('data_waznosci' => $nowa_data),
        array('id' => $lot_id),
        array('%s'),
        array('%d')
    );

	if ($result !== false) {
		// Zapisz każde przedłużenie osobno w historii
		for ($i = 0; $i < $quantity; $i++) {
			$data_przedluzenia = date('Y-m-d', strtotime($stara_data . ' +' . ($i + 1) . ' year'));
			
			$wpis_historii = array(
				'data' => srl_get_current_datetime(),
				'typ' => 'przedluzenie_waznosci',
				'executor' => 'Klient',
				'szczegoly' => array(
					'stara_data_waznosci' => $i == 0 ? $stara_data : date('Y-m-d', strtotime($stara_data . ' +' . $i . ' year')),
					'nowa_data_waznosci' => $data_przedluzenia,
					'przedluzenie_lat' => 1,
					'order_id' => $order_id,
					'item_id' => $item_id,
					'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1'
				)
			);

			srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
			
			if ($i < $quantity - 1) {
				usleep(100000);
			}
		}
	}

    return $result !== false;
}