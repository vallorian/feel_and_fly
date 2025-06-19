<?php if (!defined('ABSPATH')) {exit;}

function srl_get_user_full_data($user_id) {
    $user = get_userdata($user_id);
    if (!$user) return null;
    
    return array(
        'id' => $user_id,
        'email' => $user->user_email,
        'display_name' => $user->display_name,
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'imie' => get_user_meta($user_id, 'srl_imie', true),
        'nazwisko' => get_user_meta($user_id, 'srl_nazwisko', true),
        'rok_urodzenia' => get_user_meta($user_id, 'srl_rok_urodzenia', true),
        'telefon' => get_user_meta($user_id, 'srl_telefon', true),
        'kategoria_wagowa' => get_user_meta($user_id, 'srl_kategoria_wagowa', true),
        'sprawnosc_fizyczna' => get_user_meta($user_id, 'srl_sprawnosc_fizyczna', true),
        'uwagi' => get_user_meta($user_id, 'srl_uwagi', true)
    );
}

function srl_check_admin_permissions() {
    if (!current_user_can('manage_options')) {
        wp_die('Brak uprawnień.');
    }
}

function srl_require_login($return_data = false) {
    if (!is_user_logged_in()) {
        if ($return_data) {
            return array('success' => false, 'data' => 'Musisz być zalogowany.');
        } else {
            wp_send_json_error('Musisz być zalogowany.');
        }
    }
    return true;
}

function srl_get_flight_options_config() {
    return array(
        'filmowanie' => 116,
        'akrobacje' => 117,
        'przedluzenie' => 115
    );
}

function srl_can_cancel_reservation($data_lotu, $godzina_lotu) {
    if (empty($data_lotu) || empty($godzina_lotu)) {
        return false;
    }
    
    $datetime_lotu = $data_lotu . ' ' . $godzina_lotu;
    $timestamp_lotu = strtotime($datetime_lotu);
    
    if ($timestamp_lotu === false) {
        return false;
    }
    
    $czas_do_lotu = $timestamp_lotu - time();
    $wymagany_czas = 48 * 3600; // 48 godzin
    
    return $czas_do_lotu > $wymagany_czas;
}

function srl_time_to_minutes($time) {
    return srl_zamien_na_minuty($time);
}

function srl_minutes_to_time($minutes) {
    return srl_minuty_na_czas($minutes);
}

function srl_detect_flight_options($text) {
    $text_lower = strtolower($text);
    
    return array(
        'ma_filmowanie' => (strpos($text_lower, 'filmowani') !== false || 
                           strpos($text_lower, 'film') !== false ||
                           strpos($text_lower, 'video') !== false ||
                           strpos($text_lower, 'kamer') !== false) ? 1 : 0,
        'ma_akrobacje' => (strpos($text_lower, 'akrobacj') !== false || 
                          strpos($text_lower, 'trick') !== false ||
                          strpos($text_lower, 'spiral') !== false ||
                          strpos($text_lower, 'figur') !== false) ? 1 : 0
    );
}

function srl_generate_expiry_date($from_date = null, $years = 1) {
    $base_date = $from_date ? $from_date : current_time('mysql');
    return date('Y-m-d', strtotime($base_date . " +{$years} year"));
}

function srl_is_date_past($date) {
    return strtotime($date) < strtotime('today');
}

function srl_normalize_product_name($product_name) {
    $name = explode(' - ', $product_name)[0];
    
    if (stripos($name, 'voucher') !== false || 
        stripos($name, 'lot') !== false ||
        stripos($name, 'tandem') !== false) {
        return 'Lot w tandemie';
    }
    
    return $name;
}