<?php
if (!defined('ABSPATH')) {exit;}

add_action('wp_ajax_srl_wykorzystaj_voucher', 'srl_ajax_wykorzystaj_voucher');
add_action('wp_ajax_nopriv_srl_wykorzystaj_voucher', 'srl_ajax_wykorzystaj_voucher');
add_action('wp_ajax_srl_get_partner_voucher_types', 'srl_ajax_get_partner_voucher_types');
add_action('wp_ajax_nopriv_srl_get_partner_voucher_types', 'srl_ajax_get_partner_voucher_types');
add_action('wp_ajax_srl_submit_partner_voucher', 'srl_ajax_submit_partner_voucher');
add_action('wp_ajax_nopriv_srl_submit_partner_voucher', 'srl_ajax_submit_partner_voucher');
add_action('wp_ajax_srl_get_partner_voucher_details', 'srl_ajax_get_partner_voucher_details');
add_action('wp_ajax_srl_approve_partner_voucher', 'srl_ajax_approve_partner_voucher');
add_action('wp_ajax_srl_reject_partner_voucher', 'srl_ajax_reject_partner_voucher');
add_action('wp_ajax_srl_get_partner_vouchers_list', 'srl_ajax_get_partner_vouchers_list');
add_action('wp_ajax_srl_check_partner_voucher_exists', 'srl_ajax_check_partner_voucher_exists');
add_action('wp_ajax_nopriv_srl_check_partner_voucher_exists', 'srl_ajax_check_partner_voucher_exists');
add_action('wp_ajax_srl_get_partner_voucher_stats', 'srl_ajax_get_partner_voucher_stats');

function srl_ajax_wykorzystaj_voucher() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    srl_require_login();
    
    $kod_vouchera = strtoupper(sanitize_text_field($_POST['kod_vouchera']));
    $user_id = get_current_user_id();
    
    $validation = srl_waliduj_kod_vouchera($kod_vouchera);
    if (!$validation['valid']) {
        wp_send_json_error($validation['message']);
    }
    
    if (!function_exists('srl_wykorzystaj_voucher')) {
        wp_send_json_error('Funkcja voucherów nie jest dostępna.');
    }
    
    $result = srl_wykorzystaj_voucher($validation['kod'], $user_id);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['message']);
    }
}

function srl_ajax_get_partner_voucher_types() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    
    $partner = sanitize_text_field($_POST['partner']);
    
    if (empty($partner)) {
        wp_send_json_error('Nie wybrano partnera.');
    }
    
    $types = srl_get_partner_voucher_types($partner);
    
    if (empty($types)) {
        wp_send_json_error('Brak dostępnych typów voucherów dla tego partnera.');
    }
    
    wp_send_json_success($types);
}

function srl_ajax_submit_partner_voucher() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    srl_require_login();
    
    $user_id = get_current_user_id();
    $partner = sanitize_text_field($_POST['partner']);
    $typ_vouchera = sanitize_text_field($_POST['typ_vouchera']);
    $kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
    $kod_zabezpieczajacy = sanitize_text_field($_POST['kod_zabezpieczajacy']);
    $data_waznosci = sanitize_text_field($_POST['data_waznosci']);
    $dane_pasazerow = $_POST['dane_pasazerow'];
    
    // Walidacja daty ważności
    $date_validation = srl_waliduj_date($data_waznosci);
    if (!$date_validation['valid']) {
        wp_send_json_error('Błąd w dacie ważności: ' . $date_validation['message']);
    }
    
    if (srl_is_date_past($data_waznosci)) {
        wp_send_json_error('Data ważności nie może być z przeszłości.');
    }
    
    if (!is_array($dane_pasazerow) || empty($dane_pasazerow)) {
        wp_send_json_error('Brak danych pasażerów.');
    }
    
    $liczba_osob = srl_get_voucher_passenger_count($partner, $typ_vouchera);
    
    if (count($dane_pasazerow) !== $liczba_osob) {
        wp_send_json_error('Nieprawidłowa liczba pasażerów dla wybranego typu vouchera.');
    }
    
    // Sanityzacja i walidacja danych pasażerów
    $sanitized_passengers = array();
    foreach ($dane_pasazerow as $index => $pasazer) {
        $sanitized_passenger = array(
            'imie' => sanitize_text_field($pasazer['imie']),
            'nazwisko' => sanitize_text_field($pasazer['nazwisko']),
            'rok_urodzenia' => intval($pasazer['rok_urodzenia']),
            'telefon' => sanitize_text_field($pasazer['telefon']),
            'kategoria_wagowa' => sanitize_text_field($pasazer['kategoria_wagowa']),
            'sprawnosc_fizyczna' => sanitize_text_field($pasazer['sprawnosc_fizyczna']),
            'akceptacja_regulaminu' => (bool)$pasazer['akceptacja_regulaminu']
        );
        
        // Walidacja danych pasażera
        $walidacja_pasazera = srl_waliduj_dane_pasazera($sanitized_passenger);
        if (!$walidacja_pasazera['valid']) {
            $numer_pasazera = $index + 1;
            foreach ($walidacja_pasazera['errors'] as $field => $error) {
                wp_send_json_error("Pasażer {$numer_pasazera} ({$sanitized_passenger['imie']} {$sanitized_passenger['nazwisko']}) - {$error}");
            }
        }
        
        // Sprawdzenie wagi - blokada dla 120kg+
        $walidacja_waga = srl_waliduj_kategorie_wagowa($sanitized_passenger['kategoria_wagowa']);
        if (!$walidacja_waga['valid']) {
            $numer_pasazera = $index + 1;
            foreach ($walidacja_waga['errors'] as $error) {
                wp_send_json_error("Pasażer {$numer_pasazera} ({$sanitized_passenger['imie']} {$sanitized_passenger['nazwisko']}) - " . $error['tresc']);
            }
        }
        
        $sanitized_passengers[] = $sanitized_passenger;
    }
    
    $voucher_data = array(
        'partner' => $partner,
        'typ_vouchera' => $typ_vouchera,
        'kod_vouchera' => $kod_vouchera,
        'kod_zabezpieczajacy' => $kod_zabezpieczajacy,
        'data_waznosci' => $data_waznosci,
        'liczba_osob' => $liczba_osob,
        'dane_pasazerow' => $sanitized_passengers,
        'klient_id' => $user_id
    );
    
    $result = srl_save_partner_voucher($voucher_data);
    
    if ($result['success']) {
        wp_send_json_success('Voucher został wysłany do weryfikacji. Otrzymasz email z informacją o statusie.');
    } else {
        wp_send_json_error($result['message']);
    }
}

function srl_ajax_get_partner_voucher_details() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    srl_check_admin_permissions();
    
    $voucher_id = intval($_POST['voucher_id']);
    
    if (empty($voucher_id)) {
        wp_send_json_error('Nieprawidłowy ID vouchera.');
    }
    
    $voucher = srl_get_partner_voucher($voucher_id);
    
    if (!$voucher) {
        wp_send_json_error('Voucher nie został znaleziony.');
    }
    
    wp_send_json_success($voucher);
}

function srl_ajax_approve_partner_voucher() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    srl_check_admin_permissions();
    
    $voucher_id = intval($_POST['voucher_id']);
    $validity_date = isset($_POST['validity_date']) ? sanitize_text_field($_POST['validity_date']) : null;
    
    if (empty($voucher_id)) {
        wp_send_json_error('Nieprawidłowy ID vouchera.');
    }
    
    // Walidacja daty jeśli podana
    if (!empty($validity_date)) {
        $date_validation = srl_waliduj_date($validity_date);
        if (!$date_validation['valid']) {
            wp_send_json_error('Nieprawidłowa data ważności: ' . $date_validation['message']);
        }
        
        global $wpdb;
        $tabela_vouchery = $wpdb->prefix . 'srl_vouchery_partnerzy';
        
        $wpdb->update(
            $tabela_vouchery,
            array('data_waznosci_vouchera' => $validity_date),
            array('id' => $voucher_id),
            array('%s'),
            array('%d')
        );
    }
    
    $result = srl_approve_partner_voucher($voucher_id);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['message']);
    }
}

function srl_ajax_reject_partner_voucher() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    srl_check_admin_permissions();
    
    $voucher_id = intval($_POST['voucher_id']);
    $reason = sanitize_textarea_field($_POST['reason']);
    
    if (empty($voucher_id)) {
        wp_send_json_error('Nieprawidłowy ID vouchera.');
    }
    
    if (empty($reason)) {
        wp_send_json_error('Musisz podać powód odrzucenia.');
    }
    
    $result = srl_reject_partner_voucher($voucher_id, $reason);
    
    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['message']);
    }
}

function srl_ajax_get_partner_vouchers_list() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    srl_check_admin_permissions();
    
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : null;
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
    
    $vouchery = srl_get_partner_vouchers($status, $limit);
    
    wp_send_json_success($vouchery);
}

function srl_ajax_check_partner_voucher_exists() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    
    $kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
    $kod_zabezpieczajacy = sanitize_text_field($_POST['kod_zabezpieczajacy']);
    $partner = sanitize_text_field($_POST['partner']);
    
    if (empty($kod_vouchera) || empty($kod_zabezpieczajacy) || empty($partner)) {
        wp_send_json_error('Brak wymaganych danych.');
    }
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
    
    $existing = srl_execute_query(
        "SELECT id FROM $tabela WHERE kod_vouchera = %s AND kod_zabezpieczajacy = %s AND partner = %s",
        array($kod_vouchera, $kod_zabezpieczajacy, $partner),
        'var'
    );
    
    if ($existing) {
        wp_send_json_error('Voucher z tymi kodami już istnieje w systemie.');
    } else {
        wp_send_json_success('Voucher jest dostępny.');
    }
}

function srl_ajax_get_partner_voucher_stats() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    srl_check_admin_permissions();
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
    
    $stats = array(
        'total' => srl_execute_query("SELECT COUNT(*) FROM $tabela", array(), 'count'),
        'oczekuje' => srl_execute_query("SELECT COUNT(*) FROM $tabela WHERE status = 'oczekuje'", array(), 'count'),
        'zatwierdzony' => srl_execute_query("SELECT COUNT(*) FROM $tabela WHERE status = 'zatwierdzony'", array(), 'count'),
        'odrzucony' => srl_execute_query("SELECT COUNT(*) FROM $tabela WHERE status = 'odrzucony'", array(), 'count'),
        'by_partner' => srl_execute_query("SELECT partner, COUNT(*) as count FROM $tabela GROUP BY partner"),
        'recent' => srl_execute_query("SELECT * FROM $tabela ORDER BY data_zgloszenia DESC LIMIT 5")
    );
    
    wp_send_json_success($stats);
}