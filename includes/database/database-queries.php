<?php
/**
 * Wspólne zapytania do bazy danych
 */

if (!defined('ABSPATH')) {
    exit;
}

function srl_pobierz_loty_uzytkownika($user_id, $status = null) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $where_clause = "WHERE user_id = %d AND data_waznosci >= CURDATE()";
    $params = array($user_id);
    
    if ($status) {
        $where_clause .= " AND status = %s";
        $params[] = $status;
    }
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $tabela_loty $where_clause ORDER BY data_zakupu DESC",
        ...$params
    ), ARRAY_A);
}

function srl_pobierz_slot_szczegoly($termin_id) {
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_terminy WHERE id = %d",
        $termin_id
    ), ARRAY_A);
}

function srl_sprawdz_czy_slot_wolny($termin_id) {
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
        $termin_id
    ));
    
    return intval($count) > 0;
}

function srl_pobierz_dostepne_sloty_dnia($data) {
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, pilot_id, godzina_start, godzina_koniec 
         FROM $tabela_terminy 
         WHERE data = %s 
         AND status = 'Wolny'
         ORDER BY pilot_id ASC, godzina_start ASC",
        $data
    ), ARRAY_A);
}

function srl_zwroc_godziny_wg_pilota($data) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $wynik = $wpdb->get_results($wpdb->prepare(
        "SELECT t.id, t.pilot_id, t.godzina_start, t.godzina_koniec, t.status, t.klient_id, t.notatka,
                zl.id as lot_id, zl.user_id as lot_user_id, zl.status as lot_status, zl.dane_pasazera
           FROM $tabela t
           LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
          WHERE t.data = %s
          ORDER BY t.pilot_id ASC, t.godzina_start ASC",
        sanitize_text_field($data)
    ), ARRAY_A);

    $noweWgPilota = array();
    foreach ($wynik as $w) {
        $pid = intval($w['pilot_id']);
        if (!isset($noweWgPilota[$pid])) {
            $noweWgPilota[$pid] = array();
        }
        
        $klient_nazwa = '';
        $link_zamowienia = '';
        $dane_pasazera_cache = null;
        
        if (($w['status'] === 'Zarezerwowany' || $w['status'] === 'Zrealizowany') && intval($w['klient_id']) > 0) {
            $user = get_userdata(intval($w['klient_id']));
            if ($user) {
                $imie = get_user_meta(intval($w['klient_id']), 'srl_imie', true);
                $nazwisko = get_user_meta(intval($w['klient_id']), 'srl_nazwisko', true);
                
                if ($imie && $nazwisko) {
                    $klient_nazwa = $imie . ' ' . $nazwisko;
                } else {
                    $klient_nazwa = $user->display_name;
                }
                $link_zamowienia = admin_url('edit.php?post_type=shop_order&customer=' . intval($w['klient_id']));
                
                $dane_pasazera_cache = array();
                
                if (!empty($w['dane_pasazera'])) {
                    $dane_z_lotu = json_decode($w['dane_pasazera'], true);
                    if ($dane_z_lotu && is_array($dane_z_lotu)) {
                        $dane_pasazera_cache = $dane_z_lotu;
                    }
                }
                
                if (empty($dane_pasazera_cache['imie'])) {
                    $dane_pasazera_cache = array(
                        'imie' => $imie,
                        'nazwisko' => $nazwisko,
                        'rok_urodzenia' => get_user_meta(intval($w['klient_id']), 'srl_rok_urodzenia', true),
                        'telefon' => get_user_meta(intval($w['klient_id']), 'srl_telefon', true),
                        'kategoria_wagowa' => get_user_meta(intval($w['klient_id']), 'srl_kategoria_wagowa', true),
                        'sprawnosc_fizyczna' => get_user_meta(intval($w['klient_id']), 'srl_sprawnosc_fizyczna', true),
                        'uwagi' => get_user_meta(intval($w['klient_id']), 'srl_uwagi', true)
                    );
                }
            }
        } elseif ($w['status'] === 'Prywatny' && !empty($w['notatka'])) {
            $dane_prywatne = json_decode($w['notatka'], true);
            if ($dane_prywatne && isset($dane_prywatne['imie']) && isset($dane_prywatne['nazwisko'])) {
                $klient_nazwa = $dane_prywatne['imie'] . ' ' . $dane_prywatne['nazwisko'];
            }
        }
        
        $noweWgPilota[$pid][] = array(
            'id' => intval($w['id']),
            'start' => substr($w['godzina_start'], 0, 5),
            'koniec' => substr($w['godzina_koniec'], 0, 5),
            'status' => $w['status'],
            'klient_id' => intval($w['klient_id']),
            'klient_nazwa' => $klient_nazwa,
            'link_zamowienia' => $link_zamowienia,
            'lot_id' => $w['lot_id'] ? intval($w['lot_id']) : null,
            'notatka' => $w['notatka'],
            'dane_pasazera_cache' => $dane_pasazera_cache
        );
    }

    wp_send_json_success(array(
        'godziny_wg_pilota' => $noweWgPilota
    ));
}