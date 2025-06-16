<?php if (!defined('ABSPATH')) {
    exit;
}

function srl_get_current_datetime() {
    return current_time('Y-m-d H:i:s');
}

function srl_dopisz_do_historii_lotu($lot_id, $wpis_historii) {
    if (!$lot_id || empty($wpis_historii)) {
        return false;
    }

    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    $istniejaca_historia = $wpdb->get_var($wpdb->prepare(
        "SELECT historia_modyfikacji FROM $tabela WHERE id = %d", 
        $lot_id
    ));
    
    $historia = !empty($istniejaca_historia) ? json_decode($istniejaca_historia, true) : array();
    if (!is_array($historia)) {
        $historia = array();
    }
    
    $processed_wpis = srl_przetworz_wpis_historii($wpis_historii);
    
    if (srl_wykryj_duplikat_historii($historia, $processed_wpis)) {
        return false;
    }
    
    $historia[] = $processed_wpis;
    
    $result = $wpdb->update(
        $tabela,
        array('historia_modyfikacji' => json_encode($historia)),
        array('id' => $lot_id),
        array('%s'),
        array('%d')
    );
    
    return $result !== false;
}

function srl_przetworz_wpis_historii($wpis) {
    $typ_akcji = srl_okresl_typ_akcji($wpis);
    $akcja_opis = srl_utworz_akcje_opis($wpis, $typ_akcji);
    $unikalny_klucz = srl_utworz_unikalny_klucz($wpis, $typ_akcji);
    
    return array(
        'data' => $wpis['data'] ?? srl_get_current_datetime(),
        'typ' => $typ_akcji,
        'executor' => $wpis['executor'] ?? 'System',
        'akcja' => $akcja_opis,
        'szczegoly' => $wpis['szczegoly'] ?? array(),
        'unikalny_klucz' => $unikalny_klucz
    );
}

function srl_okresl_typ_akcji($wpis) {
    $opis = strtolower($wpis['opis'] ?? '');
    $typ = $wpis['typ'] ?? '';
    
    if (in_array($typ, array('zmiana_statusu', 'zmiana_statusu_admin', 'anulowanie_przez_organizatora', 'realizacja_admin'))) {
        return 'ZMIANA_STATUSU';
    }
    
    if (in_array($typ, array('dokupienie_filmowanie', 'dokupienie_akrobacje', 'przedluzenie_waznosci', 'zmiana_opcji'))) {
        return 'DOKUPIENIE_WARIANTU';
    }
    
    if (in_array($typ, array('przypisanie_id', 'opcja_przy_zakupie')) || strpos($opis, 'przypisanie') !== false) {
        return 'SYSTEMOWE';
    }
    
    if (strpos($opis, 'dane') !== false || strpos($opis, 'pasażer') !== false) {
        return 'ZMIANA_DANYCH';
    }
    
    return 'SYSTEMOWE';
}

function srl_utworz_akcje_opis($wpis, $typ_akcji) {
    $szczegoly = $wpis['szczegoly'] ?? array();
    
    switch ($typ_akcji) {
        case 'ZMIANA_STATUSU':
            return srl_formatuj_zmiane_statusu($szczegoly);
            
        case 'DOKUPIENIE_WARIANTU':
            return srl_formatuj_dokupienie_wariantu($wpis, $szczegoly);
            
        case 'SYSTEMOWE':
            return srl_formatuj_systemowe($wpis, $szczegoly);
            
        case 'ZMIANA_DANYCH':
            return 'Zaktualizowano dane';
            
        default:
            return 'Modyfikacja';
    }
}

function srl_formatuj_zmiane_statusu($szczegoly) {
    $stary = $szczegoly['stary_status'] ?? $szczegoly['stary_status_lotu'] ?? '';
    $nowy = $szczegoly['nowy_status'] ?? $szczegoly['nowy_status_lotu'] ?? '';
    
    if (empty($stary) || empty($nowy)) {
        return 'Zmiana statusu';
    }
    
    $stary_badge = srl_get_status_badge($stary);
    $nowy_badge = srl_get_status_badge($nowy);
    
    if (isset($szczegoly['slot_zwolniony']) || isset($szczegoly['zwolniony_termin'])) {
        $wolny_badge = srl_get_status_badge('wolny');
        return $stary_badge . ' → ' . $nowy_badge . ' → ' . $wolny_badge;
    }
    
    return $stary_badge . ' → ' . $nowy_badge;
}

function srl_formatuj_dokupienie_wariantu($wpis, $szczegoly) {
    $typ = $wpis['typ'] ?? '';
    $opis = $wpis['opis'] ?? '';
    
    if (strpos($typ, 'filmowanie') !== false || strpos($opis, 'filmowanie') !== false) {
        return '+Filmowanie';
    }
    
    if (strpos($typ, 'akrobacje') !== false || strpos($opis, 'akrobacje') !== false) {
        return '+Akrobacje';
    }
    
    if (strpos($typ, 'przedluzenie') !== false || strpos($opis, 'przedłuż') !== false) {
        $lata = $szczegoly['przedluzenie_lat'] ?? 1;
        return '+Przedłużenie (' . $lata . ' rok)';
    }
    
    return '+Opcja';
}

function srl_formatuj_systemowe($wpis, $szczegoly) {
    $opis = $wpis['opis'] ?? '';
    
    if (strpos($opis, 'przypisanie') !== false || strpos($opis, 'ID lotu') !== false) {
        $lot_id = $szczegoly['lot_id'] ?? '';
        return $lot_id ? 'Przypisanie ID #' . $lot_id : 'Przypisanie ID';
    }
    
    if (strpos($opis, 'zakup') !== false) {
        return 'Zakup lotu';
    }
    
    return 'Operacja systemowa';
}

function srl_get_status_badge($status) {
    $statusy = array(
        'wolny' => array('label' => 'WOLNY', 'color' => '#28a745'),
        'zarezerwowany' => array('label' => 'ZAREZERWOWANY', 'color' => '#ffc107'),
        'zrealizowany' => array('label' => 'ZREALIZOWANY', 'color' => '#007bff'),
        'odwolany' => array('label' => 'ODWOŁANY', 'color' => '#dc3545'),
        'przedawniony' => array('label' => 'PRZEDAWNIONY', 'color' => '#6c757d')
    );
    
    $config = $statusy[$status] ?? array('label' => strtoupper($status), 'color' => '#6c757d');
    
    return '<span class="status-badge" style="background-color: ' . $config['color'] . '; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">' . $config['label'] . '</span>';
}

function srl_utworz_unikalny_klucz($wpis, $typ_akcji) {
    $data_min = date('YmdHi', strtotime($wpis['data'] ?? 'now'));
    $szczegoly_hash = substr(md5(json_encode($wpis['szczegoly'] ?? array())), 0, 6);
    
    return strtolower($typ_akcji) . '_' . $data_min . '_' . $szczegoly_hash;
}

function srl_wykryj_duplikat_historii($istniejaca_historia, $nowy_wpis) {
    if (empty($istniejaca_historia) || !is_array($istniejaca_historia)) {
        return false;
    }
    
    $nowy_klucz = $nowy_wpis['unikalny_klucz'];
    $nowy_typ = $nowy_wpis['typ'];
    $nowa_data = strtotime($nowy_wpis['data']);
    
    foreach ($istniejaca_historia as $wpis) {
        if (isset($wpis['unikalny_klucz']) && $wpis['unikalny_klucz'] === $nowy_klucz) {
            return true;
        }
        
        if (isset($wpis['typ']) && $wpis['typ'] === $nowy_typ) {
            $istniejaca_data = strtotime($wpis['data'] ?? '');
            if (abs($nowa_data - $istniejaca_data) <= 120) {
                return true;
            }
        }
    }
    
    return false;
}

function srl_formatuj_historie_lotu($events) {
    if (empty($events) || !is_array($events)) {
        return array();
    }
    
    $sformatowane = array();
    
    foreach ($events as $event) {
        $sformatowane[] = array(
            'timestamp' => $event['timestamp'] ?? strtotime($event['date'] ?? 'now'),
            'formatted_date' => date('d.m.Y H:i', $event['timestamp'] ?? strtotime($event['date'] ?? 'now')),
            'type' => $event['typ'] ?? $event['type'] ?? 'SYSTEMOWE',
            'action_name' => $event['akcja'] ?? $event['action_name'] ?? 'Modyfikacja',
            'executor' => $event['executor'] ?? 'System',
            'details' => $event['szczegoly'] ?? $event['details'] ?? ''
        );
    }
    
    usort($sformatowane, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });
    
    return $sformatowane;
}