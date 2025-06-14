<?php
/**
 * AJAX Handlers dla voucherów
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_srl_wykorzystaj_voucher', 'srl_ajax_wykorzystaj_voucher');

function srl_ajax_wykorzystaj_voucher() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
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