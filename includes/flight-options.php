<?php
/**
 * Zarządzanie opcjami lotów (filmowanie, akrobacje, przedłużanie)
 */

/**
 * ID produktów opcji lotów
 */
function srl_get_flight_option_product_ids() {
    return array(
        'przedluzenie' => 115,
        'filmowanie' => 116,
        'akrobacje' => 117
    );
}

/**
 * Analizuje nazwę produktu i określa opcje lotu
 */
function srl_analiza_opcji_produktu($nazwa_produktu) {
    $opcje = array(
        'ma_filmowanie' => 0,
        'ma_akrobacje' => 0
    );
    
    $nazwa_lower = strtolower($nazwa_produktu);
    
    // Sprawdź filmowanie - więcej wariantów
    if (strpos($nazwa_lower, 'filmowani') !== false || 
        strpos($nazwa_lower, 'film') !== false ||
        strpos($nazwa_lower, 'video') !== false ||
        strpos($nazwa_lower, 'nagrywani') !== false ||
        strpos($nazwa_lower, 'kamer') !== false) {
        $opcje['ma_filmowanie'] = 1;
    }
    
    // Sprawdź akrobacje - więcej wariantów
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

/**
 * Aktualizuje opcje lotu w bazie danych
 */
function srl_ustaw_opcje_lotu($lot_id, $ma_filmowanie, $ma_akrobacje, $opis_zmiany = '') {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz aktualną historię
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT historia_modyfikacji FROM $tabela WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    $historia = !empty($lot['historia_modyfikacji']) ? json_decode($lot['historia_modyfikacji'], true) : array();
    
    // Dodaj nową modyfikację do historii
    if (!empty($opis_zmiany)) {
        $historia[] = array(
            'data' => current_time('mysql'),
            'opis' => $opis_zmiany,
            'filmowanie' => $ma_filmowanie,
            'akrobacje' => $ma_akrobacje
        );
    }
    
    // Aktualizuj lot
    $result = $wpdb->update(
        $tabela,
        array(
            'ma_filmowanie' => $ma_filmowanie,
            'ma_akrobacje' => $ma_akrobacje,
            'historia_modyfikacji' => json_encode($historia)
        ),
        array('id' => $lot_id),
        array('%d', '%d', '%s'),
        array('%d')
    );
    
    return $result !== false;
}

/**
 * Przedłuża ważność lotu o rok
 */
function srl_przedluz_waznosc_lotu($lot_id, $order_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz aktualną datę ważności
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT data_waznosci, nazwa_produktu FROM $tabela WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot) {
        return false;
    }
    
    // Przedłuż o rok od aktualnej daty ważności
    $nowa_data_waznosci = date('Y-m-d', strtotime($lot['data_waznosci'] . ' +1 year'));
    
    // Aktualizuj lot
    $result = $wpdb->update(
        $tabela,
        array('data_waznosci' => $nowa_data_waznosci),
        array('id' => $lot_id),
        array('%s'),
        array('%d')
    );
    
    if ($result !== false) {
        // Dodaj do historii
        $opis_zmiany = "Przedłużono ważność do $nowa_data_waznosci (zamówienie #$order_id)";
        $wpdb->update(
            $tabela,
            array(
                'historia_modyfikacji' => json_encode(array(array(
                    'data' => current_time('mysql'),
                    'opis' => $opis_zmiany,
                    'typ' => 'przedluzenie'
                )))
            ),
            array('id' => $lot_id),
            array('%s'),
            array('%d')
        );
    }
    
    return $result !== false;
}

/**
 * Sprawdza czy lot ma określoną opcję
 */
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

/**
 * Pobiera dostępne opcje do dokupienia dla lotu
 */
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