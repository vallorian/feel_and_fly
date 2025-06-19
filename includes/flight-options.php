<?php

function srl_get_flight_option_product_ids() {
    return array(
        'przedluzenie' => 115,
        'filmowanie' => 116,
        'akrobacje' => 117
    );
}

function srl_analiza_opcji_produktu($nazwa_produktu) {
    return srl_detect_flight_options($nazwa_produktu);
}

function srl_przedluz_waznosc_lotu($lot_id, $order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';

    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT data_waznosci, nazwa_produktu FROM $tabela WHERE id = %d",
        $lot_id
    ), ARRAY_A);

    if (!$lot) return false;

    $stara_data = $lot['data_waznosci'];
    $nowa_data = srl_generate_expiry_date($stara_data, 1);

    $result = $wpdb->update(
        $tabela,
        array('data_waznosci' => $nowa_data),
        array('id' => $lot_id),
        array('%s'),
        array('%d')
    );

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