<?php

function srl_get_flight_option_product_ids() {
    return array(
        'przedluzenie' => 115,
        'filmowanie' => 116,
        'akrobacje' => 117
    );
}

function srl_analiza_opcji_produktu($nazwa_produktu) {
    $opcje = array(
        'ma_filmowanie' => 0,
        'ma_akrobacje' => 0
    );

    $nazwa_lower = strtolower($nazwa_produktu);

    if (strpos($nazwa_lower, 'filmowani') !== false || 
        strpos($nazwa_lower, 'film') !== false ||
        strpos($nazwa_lower, 'video') !== false ||
        strpos($nazwa_lower, 'nagrywani') !== false ||
        strpos($nazwa_lower, 'kamer') !== false) {
        $opcje['ma_filmowanie'] = 1;
    }

    if (strpos($nazwa_lower, 'akrobacj') !== false || 
        strpos($nazwa_lower, 'trick') !== false ||
        strpos($nazwa_lower, 'ekstr') !== false ||
        strpos($nazwa_lower, 'adrenalin') !== false ||
        strpos($nazwa_lower, 'spiral') !== false ||
        strpos($nazwa_lower, 'figur') !== false) {
        $opcje['ma_akrobacje'] = 1;
    }

    return $opcje;
}

function srl_ustaw_opcje_lotu($lot_id, $ma_filmowanie, $ma_akrobacje, $opis_zmiany = '', $executor = 'System') {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';

    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT ma_filmowanie, ma_akrobacje FROM $tabela WHERE id = %d",
        $lot_id
    ), ARRAY_A);

    if (!$lot) {
        return false;
    }

    $stary_filmowanie = $lot['ma_filmowanie'];
    $stary_akrobacje = $lot['ma_akrobacje'];

    $result = $wpdb->update(
        $tabela,
        array(
            'ma_filmowanie' => $ma_filmowanie,
            'ma_akrobacje' => $ma_akrobacje
        ),
        array('id' => $lot_id),
        array('%d', '%d'),
        array('%d')
    );

    if ($result !== false && !empty($opis_zmiany)) {

        $wpis_historii = array(
            'data' => srl_get_current_datetime(),
            'opis' => $opis_zmiany,
            'typ' => 'zmiana_opcji',
            'executor' => $executor,
            'szczegoly' => array(
                'stary_filmowanie' => $stary_filmowanie,
                'nowy_filmowanie' => $ma_filmowanie,
                'stary_akrobacje' => $stary_akrobacje,
                'nowy_akrobacje' => $ma_akrobacje,
                'zmiana_filmowanie' => $stary_filmowanie != $ma_filmowanie,
                'zmiana_akrobacje' => $stary_akrobacje != $ma_akrobacje
            )
        );

        srl_dopisz_do_historii_lotu_v2($lot_id, $wpis_historii);
    }

    return $result !== false;
}

function srl_przedluz_waznosc_lotu($lot_id, $order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';

    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT data_waznosci, nazwa_produktu FROM $tabela WHERE id = %d",
        $lot_id
    ), ARRAY_A);

    if (!$lot) {
        return false;
    }

    $stara_data = $lot['data_waznosci'];
    $nowa_data = date('Y-m-d', strtotime($stara_data . ' +1 year'));

    $result = $wpdb->update(
        $tabela,
        array('data_waznosci' => $nowa_data),
        array('id' => $lot_id),
        array('%s'),
        array('%d')
    );

    if ($result !== false) {

        $opis_zmiany = "Przedłużono ważność lotu do " . date('d.m.Y', strtotime($nowa_data)) . " (zamówienie #$order_id)";

        $wpis_historii = array(
            'data' => srl_get_current_datetime(),
            'opis' => $opis_zmiany,
            'typ' => 'przedluzenie_waznosci',
            'executor' => 'Klient',
            'szczegoly' => array(
                'stara_data_waznosci' => $stara_data,
                'nowa_data_waznosci' => $nowa_data,
                'order_id' => $order_id,
                'przedluzenie' => '12 miesięcy'
            )
        );

        srl_dopisz_do_historii_lotu_v2($lot_id, $wpis_historii);
    }

    return $result !== false;
}

function srl_lot_ma_opcje($lot_id, $opcja) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';

    $dozwolone_opcje = array('ma_filmowanie', 'ma_akrobacje');
    if (!in_array($opcja, $dozwolone_opcje)) {
        return false;
    }

    $wartosc = $wpdb->get_var($wpdb->prepare(
        "SELECT $opcja FROM $tabela WHERE id = %d",
        $lot_id
    ));

    return (bool) $wartosc;
}

function srl_dostepne_opcje_do_dokupienia($lot_id) {
    $opcje = array();

    if (!srl_lot_ma_opcje($lot_id, 'ma_filmowanie')) {
        $opcje['filmowanie'] = array(
            'nazwa' => 'Filmowanie lotu',
            'product_id' => srl_get_flight_option_product_ids()['filmowanie']
        );
    }

    if (!srl_lot_ma_opcje($lot_id, 'ma_akrobacje')) {
        $opcje['akrobacje'] = array(
            'nazwa' => 'Akrobacje podczas lotu',
            'product_id' => srl_get_flight_option_product_ids()['akrobacje']
        );
    }

    return $opcje;
}