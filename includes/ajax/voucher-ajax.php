<?php
/**
 * AJAX Handlers dla voucherów
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_srl_wykorzystaj_voucher', 'srl_ajax_wykorzystaj_voucher');

function srl_ajax_wykorzystaj_voucher() {
    check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $kod_vouchera = strtoupper(sanitize_text_field($_POST['kod_vouchera']));
    $user_id = get_current_user_id();
    
    if (strlen($kod_vouchera) < 1) {
        wp_send_json_error('Wprowadź kod vouchera.');
        return;
    }
    
    if (!function_exists('srl_wykorzystaj_voucher')) {
        wp_send_json_error('Funkcja voucherów nie jest dostępna.');
        return;
    }
    
    $result = srl_wykorzystaj_voucher($kod_vouchera, $user_id);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['message']);
    }
}



// ===== DODAJ NA KOŃCU PLIKU voucher-ajax.php =====

// ==========================================================================
// AJAX ENDPOINTS DLA VOUCHERÓW PARTNERA
// ==========================================================================

/**
 * AJAX: Pobiera typy voucherów dla wybranego partnera
 */
add_action('wp_ajax_srl_get_partner_voucher_types', 'srl_ajax_get_partner_voucher_types');
add_action('wp_ajax_nopriv_srl_get_partner_voucher_types', 'srl_ajax_get_partner_voucher_types');

function srl_ajax_get_partner_voucher_types() {
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowy nonce.');
        return;
    }
    
    $partner = sanitize_text_field($_POST['partner']);
    
    if (empty($partner)) {
        wp_send_json_error('Nie wybrano partnera.');
        return;
    }
    
    $types = srl_get_partner_voucher_types($partner);
    
    if (empty($types)) {
        wp_send_json_error('Brak dostępnych typów voucherów dla tego partnera.');
        return;
    }
    
    wp_send_json_success($types);
}

/**
 * AJAX: Wysyła voucher partnera do weryfikacji
 */
add_action('wp_ajax_srl_submit_partner_voucher', 'srl_ajax_submit_partner_voucher');
add_action('wp_ajax_nopriv_srl_submit_partner_voucher', 'srl_ajax_submit_partner_voucher');

function srl_ajax_submit_partner_voucher() {
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowy nonce.');
        return;
    }
    
    // Sprawdź czy użytkownik jest zalogowany
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany aby wysłać voucher.');
        return;
    }
    
    $user_id = get_current_user_id();
    
    // Pobierz i zwaliduj dane
    $partner = sanitize_text_field($_POST['partner']);
    $typ_vouchera = sanitize_text_field($_POST['typ_vouchera']);
    $kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
    $kod_zabezpieczajacy = sanitize_text_field($_POST['kod_zabezpieczajacy']);
	$data_waznosci = sanitize_text_field($_POST['data_waznosci']);
    $dane_pasazerow = $_POST['dane_pasazerow'];
    
    // Sprawdź czy dane pasażerów są tablicą
    if (!is_array($dane_pasazerow) || empty($dane_pasazerow)) {
        wp_send_json_error('Brak danych pasażerów.');
        return;
    }
    
    // Pobierz liczbę osób dla danego typu vouchera
    $liczba_osob = srl_get_voucher_passenger_count($partner, $typ_vouchera);
    
    if (count($dane_pasazerow) !== $liczba_osob) {
        wp_send_json_error('Nieprawidłowa liczba pasażerów dla wybranego typu vouchera.');
        return;
    }
    
    // Sanityzuj dane pasażerów
    $sanitized_passengers = array();
    foreach ($dane_pasazerow as $pasazer) {
        $sanitized_passenger = array(
            'imie' => sanitize_text_field($pasazer['imie']),
            'nazwisko' => sanitize_text_field($pasazer['nazwisko']),
            'rok_urodzenia' => intval($pasazer['rok_urodzenia']),
            'telefon' => sanitize_text_field($pasazer['telefon']),
            'kategoria_wagowa' => sanitize_text_field($pasazer['kategoria_wagowa']),
            'sprawnosc_fizyczna' => sanitize_text_field($pasazer['sprawnosc_fizyczna']),
            'akceptacja_regulaminu' => (bool)$pasazer['akceptacja_regulaminu']
        );
        $sanitized_passengers[] = $sanitized_passenger;
    }
    
    // Przygotuj dane do zapisu
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
    
    // Zapisz voucher
    $result = srl_save_partner_voucher($voucher_data);
    
    if ($result['success']) {
        wp_send_json_success('Voucher został wysłany do weryfikacji. Otrzymasz email z informacją o statusie.');
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * AJAX: Pobiera szczegóły vouchera partnera (admin)
 */
add_action('wp_ajax_srl_get_partner_voucher_details', 'srl_ajax_get_partner_voucher_details');

function srl_ajax_get_partner_voucher_details() {
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'srl_admin_nonce')) {
        wp_send_json_error('Nieprawidłowy nonce.');
        return;
    }
    
    // Sprawdź uprawnienia admina
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $voucher_id = intval($_POST['voucher_id']);
    
    if (empty($voucher_id)) {
        wp_send_json_error('Nieprawidłowy ID vouchera.');
        return;
    }
    
    $voucher = srl_get_partner_voucher($voucher_id);
    
    if (!$voucher) {
        wp_send_json_error('Voucher nie został znaleziony.');
        return;
    }
    
    wp_send_json_success($voucher);
}

/**
 * AJAX: Zatwierdza voucher partnera (admin)
 */
add_action('wp_ajax_srl_approve_partner_voucher', 'srl_ajax_approve_partner_voucher');

function srl_ajax_approve_partner_voucher() {
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'srl_admin_nonce')) {
        wp_send_json_error('Nieprawidłowy nonce.');
        return;
    }
    
    // Sprawdź uprawnienia admina
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $voucher_id = intval($_POST['voucher_id']);
    $validity_date = isset($_POST['validity_date']) ? sanitize_text_field($_POST['validity_date']) : null; // NOWY PARAMETR
    
    if (empty($voucher_id)) {
        wp_send_json_error('Nieprawidłowy ID vouchera.');
        return;
    }
    
    // Jeśli admin podał nową datę, zaktualizuj ją przed zatwierdzeniem
    if (!empty($validity_date)) {
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

/**
 * AJAX: Odrzuca voucher partnera (admin)
 */
add_action('wp_ajax_srl_reject_partner_voucher', 'srl_ajax_reject_partner_voucher');

function srl_ajax_reject_partner_voucher() {
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'srl_admin_nonce')) {
        wp_send_json_error('Nieprawidłowy nonce.');
        return;
    }
    
    // Sprawdź uprawnienia admina
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $voucher_id = intval($_POST['voucher_id']);
    $reason = sanitize_textarea_field($_POST['reason']);
    
    if (empty($voucher_id)) {
        wp_send_json_error('Nieprawidłowy ID vouchera.');
        return;
    }
    
    if (empty($reason)) {
        wp_send_json_error('Musisz podać powód odrzucenia.');
        return;
    }
    
    $result = srl_reject_partner_voucher($voucher_id, $reason);
    
    if ($result['success']) {
        wp_send_json_success($result['message']);
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * AJAX: Pobiera listę voucherów partnera z filtrowaniem (opcjonalnie)
 */
add_action('wp_ajax_srl_get_partner_vouchers_list', 'srl_ajax_get_partner_vouchers_list');

function srl_ajax_get_partner_vouchers_list() {
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'srl_admin_nonce')) {
        wp_send_json_error('Nieprawidłowy nonce.');
        return;
    }
    
    // Sprawdź uprawnienia admina
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : null;
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
    
    $vouchery = srl_get_partner_vouchers($status, $limit);
    
    wp_send_json_success($vouchery);
}

/**
 * AJAX: Sprawdza czy voucher partnera już istnieje (walidacja na froncie)
 */
add_action('wp_ajax_srl_check_partner_voucher_exists', 'srl_ajax_check_partner_voucher_exists');
add_action('wp_ajax_nopriv_srl_check_partner_voucher_exists', 'srl_ajax_check_partner_voucher_exists');

function srl_ajax_check_partner_voucher_exists() {
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowy nonce.');
        return;
    }
    
    $kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
    $kod_zabezpieczajacy = sanitize_text_field($_POST['kod_zabezpieczajacy']);
    $partner = sanitize_text_field($_POST['partner']);
    
    if (empty($kod_vouchera) || empty($kod_zabezpieczajacy) || empty($partner)) {
        wp_send_json_error('Brak wymaganych danych.');
        return;
    }
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
    
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $tabela WHERE kod_vouchera = %s AND kod_zabezpieczajacy = %s AND partner = %s",
        $kod_vouchera,
        $kod_zabezpieczajacy,
        $partner
    ));
    
    if ($existing) {
        wp_send_json_error('Voucher z tymi kodami już istnieje w systemie.');
    } else {
        wp_send_json_success('Voucher jest dostępny.');
    }
}

/**
 * AJAX: Pobiera statystyki voucherów partnera (dla dashboardu)
 */
add_action('wp_ajax_srl_get_partner_voucher_stats', 'srl_ajax_get_partner_voucher_stats');

function srl_ajax_get_partner_voucher_stats() {
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'srl_admin_nonce')) {
        wp_send_json_error('Nieprawidłowy nonce.');
        return;
    }
    
    // Sprawdź uprawnienia admina
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
    
    $stats = array(
        'total' => $wpdb->get_var("SELECT COUNT(*) FROM $tabela"),
        'oczekuje' => $wpdb->get_var("SELECT COUNT(*) FROM $tabela WHERE status = 'oczekuje'"),
        'zatwierdzony' => $wpdb->get_var("SELECT COUNT(*) FROM $tabela WHERE status = 'zatwierdzony'"),
        'odrzucony' => $wpdb->get_var("SELECT COUNT(*) FROM $tabela WHERE status = 'odrzucony'"),
        'by_partner' => $wpdb->get_results(
            "SELECT partner, COUNT(*) as count FROM $tabela GROUP BY partner",
            ARRAY_A
        ),
        'recent' => $wpdb->get_results(
            "SELECT * FROM $tabela ORDER BY data_zgloszenia DESC LIMIT 5",
            ARRAY_A
        )
    );
    
    wp_send_json_success($stats);
}