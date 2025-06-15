<?php
function srl_dopisz_do_historii_lotu($lot_id, $nowy_wpis) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz aktualną historię
    $aktualna_historia_json = $wpdb->get_var($wpdb->prepare(
        "SELECT historia_modyfikacji FROM $tabela_loty WHERE id = %d",
        $lot_id
    ));
    
    // Dekoduj istniejącą historię lub utwórz pustą tablicę
    $historia = !empty($aktualna_historia_json) ? json_decode($aktualna_historia_json, true) : array();
    
    // Upewnij się, że historia to tablica
    if (!is_array($historia)) {
        $historia = array();
    }
    
    // Dodaj timestamp jeśli nie ma
    if (!isset($nowy_wpis['timestamp'])) {
        $nowy_wpis['timestamp'] = time();
    }
    
    // Dodaj datę jeśli nie ma
    if (!isset($nowy_wpis['data'])) {
        $nowy_wpis['data'] = srl_get_current_datetime();
    }
    
    // ZAWSZE dopisz nowy wpis na koniec
    $historia[] = $nowy_wpis;
    
    // Zapisz zaktualizowaną historię
    $result = $wpdb->update(
        $tabela_loty,
        array('historia_modyfikacji' => json_encode($historia)),
        array('id' => $lot_id),
        array('%s'),
        array('%d')
    );
    
    return $result !== false;
}

/**
 * Funkcja zmiany statusu lotu z pełną historią
 */
function srl_zmien_status_lotu($lot_id, $nowy_status, $executor = 'System', $dodatkowe_szczegoly = array()) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    // Pobierz aktualny status lotu
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot) {
        return false;
    }
    
    $stary_status = $lot['status'];
    
    // Rozpocznij transakcję
    $wpdb->query('START TRANSACTION');
    
    try {
        // Aktualizuj status lotu
        $dane_do_aktualizacji = array('status' => $nowy_status);
        
        // Specjalne przypadki dla różnych statusów
        if ($nowy_status === 'wolny' && $lot['termin_id']) {
            // Przy zmianie na "wolny" - usuń dane rezerwacji
            $dane_do_aktualizacji['termin_id'] = null;
            $dane_do_aktualizacji['data_rezerwacji'] = null;
            
            // Zwolnij slot
            $wpdb->update(
                $tabela_terminy,
                array('status' => 'Wolny', 'klient_id' => null),
                array('id' => $lot['termin_id']),
                array('%s', '%d'),
                array('%d')
            );
        }
        
        // Aktualizuj lot w bazie
        $update_result = $wpdb->update(
            $tabela_loty,
            $dane_do_aktualizacji,
            array('id' => $lot_id),
            array_fill(0, count($dane_do_aktualizacji), '%s'),
            array('%d')
        );
        
        if ($update_result === false) {
            throw new Exception('Błąd aktualizacji statusu lotu');
        }
        
        // Przygotuj szczegóły dla historii
        $szczegoly_historii = array_merge(array(
            'stary_status' => $stary_status,
            'nowy_status' => $nowy_status,
            'zmiana_statusu' => $stary_status . ' → ' . $nowy_status
        ), $dodatkowe_szczegoly);
        
        // Opisz zmianę
        $opis_zmiany = "Status lotu zmieniony z '{$stary_status}' na '{$nowy_status}'";
        if ($executor !== 'System') {
            $opis_zmiany .= " przez {$executor}";
        }
        
        // Dodaj szczegóły specyficzne dla statusu
        if ($nowy_status === 'wolny' && $lot['termin_id']) {
            $termin_info = $wpdb->get_row($wpdb->prepare(
                "SELECT data, godzina_start, godzina_koniec FROM $tabela_terminy WHERE id = %d",
                $lot['termin_id']
            ), ARRAY_A);
            
            if ($termin_info) {
                $opis_zmiany .= " - zwolniono termin " . $termin_info['data'] . " " . substr($termin_info['godzina_start'], 0, 5);
                $szczegoly_historii['zwolniony_termin'] = $termin_info['data'] . " " . substr($termin_info['godzina_start'], 0, 5);
            }
        }
        
        // DOPISZ wpis do historii (NIGDY NIE NADPISUJ)
        $wpis_historii = array(
            'data' => srl_get_current_datetime(),
            'opis' => $opis_zmiany,
            'typ' => 'zmiana_statusu',
            'executor' => $executor,
            'szczegoly' => $szczegoly_historii
        );
        
        srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
        
        $wpdb->query('COMMIT');
        return true;
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log("SRL: Błąd zmiany statusu lotu #$lot_id: " . $e->getMessage());
        return false;
    }
}

/**
 * Funkcja dokupowania opcji z historią
 */
function srl_dokup_opcje_lotu($lot_id, $opcja, $order_id = null, $executor = 'Klient') {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz aktualny stan lotu
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot) {
        return false;
    }
    
    $dane_do_aktualizacji = array();
    $opis_zmiany = '';
    
    // Określ co się zmienia
    if ($opcja === 'filmowanie' && !$lot['ma_filmowanie']) {
        $dane_do_aktualizacji['ma_filmowanie'] = 1;
        $opis_zmiany = 'Dokupiono opcję filmowania lotu';
    } elseif ($opcja === 'akrobacje' && !$lot['ma_akrobacje']) {
        $dane_do_aktualizacji['ma_akrobacje'] = 1;
        $opis_zmiany = 'Dokupiono opcję akrobacji podczas lotu';
    } else {
        return false; // Opcja już jest aktywna lub nieprawidłowa
    }
    
    if ($order_id) {
        $opis_zmiany .= " (zamówienie #$order_id)";
    }
    
    // Aktualizuj lot
    $result = $wpdb->update(
        $tabela_loty,
        $dane_do_aktualizacji,
        array('id' => $lot_id),
        array('%d'),
        array('%d')
    );
    
    if ($result !== false) {
        // DOPISZ wpis do historii
        $wpis_historii = array(
            'data' => srl_get_current_datetime(),
            'opis' => $opis_zmiany,
            'typ' => 'dokupienie_opcji',
            'executor' => $executor,
            'szczegoly' => array(
                'opcja' => $opcja,
                'order_id' => $order_id,
                'stary_stan_filmowanie' => $lot['ma_filmowanie'],
                'stary_stan_akrobacje' => $lot['ma_akrobacje'],
                'nowy_stan_filmowanie' => $opcja === 'filmowanie' ? 1 : $lot['ma_filmowanie'],
                'nowy_stan_akrobacje' => $opcja === 'akrobacje' ? 1 : $lot['ma_akrobacje']
            )
        );
        
        srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
    }
    
    return $result !== false;
}

/**
 * Funkcja do logowania rezerwacji z pełną historią
 */
function srl_zaloguj_rezerwacje_lotu($lot_id, $termin_id, $user_id = null, $executor = 'Klient') {
    global $wpdb;
    
    // Pobierz szczegóły terminu
    $termin = $wpdb->get_row($wpdb->prepare(
        "SELECT data, godzina_start, godzina_koniec, pilot_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
        $termin_id
    ), ARRAY_A);
    
    if (!$termin) {
        return false;
    }
    
    // Pobierz dane użytkownika jeśli podano
    $user_info = '';
    if ($user_id) {
        $user = get_userdata($user_id);
        if ($user) {
            $user_info = $user->display_name . ' (' . $user->user_email . ')';
        }
    }
    
    $termin_opis = sprintf('%s %s-%s (Pilot %d)', 
        $termin['data'], 
        substr($termin['godzina_start'], 0, 5), 
        substr($termin['godzina_koniec'], 0, 5),
        $termin['pilot_id']
    );
    
    $opis_zmiany = "Lot zarezerwowany na termin: " . $termin_opis;
    if ($user_info) {
        $opis_zmiany .= " przez klienta: " . $user_info;
    }
    
    // DOPISZ wpis do historii
    $wpis_historii = array(
        'data' => srl_get_current_datetime(),
        'opis' => $opis_zmiany,
        'typ' => 'rezerwacja',
        'executor' => $executor,
        'szczegoly' => array(
            'termin_id' => $termin_id,
            'data_lotu' => $termin['data'],
            'godzina_start' => $termin['godzina_start'],
            'godzina_koniec' => $termin['godzina_koniec'],
            'pilot_id' => $termin['pilot_id'],
            'user_id' => $user_id,
            'user_info' => $user_info
        )
    );
    
    return srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
}

/**
 * Funkcja do logowania anulowania rezerwacji z pełną historią
 */
function srl_zaloguj_anulowanie_lotu($lot_id, $powod = '', $executor = 'Klient', $szczegoly_terminu = null) {
    $opis_zmiany = "Anulowano rezerwację lotu";
    
    if (!empty($powod)) {
        $opis_zmiany .= " - powód: " . $powod;
    }
    
    if ($szczegoly_terminu) {
        $opis_zmiany .= " (termin: " . $szczegoly_terminu . ")";
    }
    
    // DOPISZ wpis do historii
    $wpis_historii = array(
        'data' => srl_get_current_datetime(),
        'opis' => $opis_zmiany,
        'typ' => 'anulowanie',
        'executor' => $executor,
        'szczegoly' => array(
            'powod' => $powod,
            'szczegoly_terminu' => $szczegoly_terminu,
            'anulowany_przez' => $executor
        )
    );
    
    return srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
}

function srl_get_current_datetime() {
    return wp_date('Y-m-d H:i:s');
}


?>