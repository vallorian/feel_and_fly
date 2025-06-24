<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Voucher_Ajax {
    private static $instance = null;
    private $cache_manager;
    private $db_helpers;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->cache_manager = SRL_Cache_Manager::getInstance();
        $this->db_helpers = SRL_Database_Helpers::getInstance();
        $this->initHooks();
    }

    private function initHooks() {
        $ajax_methods = [
            'srl_wykorzystaj_voucher', 'srl_get_partner_voucher_types', 'srl_submit_partner_voucher',
            'srl_get_partner_voucher_details', 'srl_approve_partner_voucher', 'srl_reject_partner_voucher',
            'srl_get_partner_vouchers_list', 'srl_check_partner_voucher_exists', 'srl_get_partner_voucher_stats'
        ];

        foreach ($ajax_methods as $method) {
            add_action("wp_ajax_{$method}", [$this, 'ajax' . $this->toCamelCase($method)]);
            if (in_array($method, ['srl_wykorzystaj_voucher', 'srl_get_partner_voucher_types', 'srl_submit_partner_voucher', 'srl_check_partner_voucher_exists'])) {
                add_action("wp_ajax_nopriv_{$method}", [$this, 'ajax' . $this->toCamelCase($method)]);
            }
        }
    }

    private function toCamelCase($string) {
        return str_replace('_', '', ucwords(str_replace('srl_', '', $string), '_'));
    }

    private function validateNonce($require_admin = false) {
        if (!check_ajax_referer('srl_frontend_nonce', 'nonce', false)) {
            if ($require_admin) {
                check_ajax_referer('srl_admin_nonce', 'nonce', true);
            } else {
                check_ajax_referer('srl_admin_nonce', 'nonce', true);
            }
        }
    }

    private function validateAdminAccess() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
    }

    private function validateUserLogin() {
        if (!is_user_logged_in()) {
            wp_send_json_error('Musisz być zalogowany.');
        }
    }

    private function getPartnerVoucherConfig() {
        $cache_key = 'partner_voucher_config';
        $config = wp_cache_get($cache_key, 'srl_cache');
        
        if ($config === false) {
            $config = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherConfig();
            wp_cache_set($cache_key, $config, 'srl_cache', 3600);
        }
        
        return $config;
    }

    private function invalidatePartnerVoucherCache() {
        wp_cache_delete('partner_voucher_config', 'srl_cache');
        wp_cache_delete('partner_voucher_stats', 'srl_cache');
    }

    public function ajaxWykorzystajVoucher() {
        $this->validateNonce();
        $this->validateUserLogin();
        
        $kod_vouchera = strtoupper(sanitize_text_field($_POST['kod_vouchera']));
        $user_id = get_current_user_id();
        
        if (empty($kod_vouchera)) {
            wp_send_json_error('Kod vouchera jest wymagany.');
        }
        
        $validation = SRL_Helpers::getInstance()->walidujKodVouchera($kod_vouchera);
        if (!$validation['valid']) {
            wp_send_json_error($validation['message']);
        }
        
        if (!class_exists('SRL_Voucher_Gift_Functions')) {
            wp_send_json_error('Funkcja voucherów nie jest dostępna.');
        }
        
        $voucher_functions = SRL_Voucher_Gift_Functions::getInstance();
        if (!method_exists($voucher_functions, 'wykorzystajVoucher')) {
            wp_send_json_error('Funkcja wykorzystania voucherów nie jest dostępna.');
        }
        
        $cache_key = "voucher_check_{$validation['kod']}_{$user_id}";
        $cached_result = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached_result !== false) {
            wp_send_json($cached_result);
        }
        
        $result = $voucher_functions->wykorzystajVoucher($validation['kod'], $user_id);
        
        if ($result['success']) {
            wp_cache_set($cache_key, $result, 'srl_cache', 300);
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function ajaxGetPartnerVoucherTypes() {
        $this->validateNonce();
        
        $partner = sanitize_text_field($_POST['partner']);
        if (empty($partner)) {
            wp_send_json_error('Nie wybrano partnera.');
        }
        
        $cache_key = "partner_types_{$partner}";
        $types = wp_cache_get($cache_key, 'srl_cache');
        
        if ($types === false) {
            $types = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherTypes($partner);
            wp_cache_set($cache_key, $types, 'srl_cache', 1800);
        }
        
        if (empty($types)) {
            wp_send_json_error('Brak dostępnych typów voucherów dla tego partnera.');
        }
        
        wp_send_json_success($types);
    }

    public function ajaxSubmitPartnerVoucher() {
        $this->validateNonce();
        $this->validateUserLogin();
        
        $user_id = get_current_user_id();
        $voucher_data = $this->validateAndSanitizePartnerVoucherData();
        
        if (is_wp_error($voucher_data)) {
            wp_send_json_error($voucher_data->get_error_message());
        }
        
        $voucher_data['klient_id'] = $user_id;
        
        $duplicate_key = md5($voucher_data['kod_vouchera'] . $voucher_data['kod_zabezpieczajacy'] . $voucher_data['partner']);
        $duplicate_check = wp_cache_get("voucher_duplicate_{$duplicate_key}", 'srl_cache');
        
        if ($duplicate_check !== false) {
            wp_send_json_error('Voucher z tymi kodami już został zgłoszony.');
        }
        
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}srl_vouchery_partnerzy 
             WHERE kod_vouchera = %s AND kod_zabezpieczajacy = %s AND partner = %s",
            $voucher_data['kod_vouchera'], $voucher_data['kod_zabezpieczajacy'], $voucher_data['partner']
        ));
        
        if ($existing) {
            wp_send_json_error('Voucher z tymi kodami już istnieje w systemie.');
        }
        
        $result = SRL_Partner_Voucher_Functions::getInstance()->savePartnerVoucher($voucher_data);
        
        if ($result['success']) {
            wp_cache_set("voucher_duplicate_{$duplicate_key}", true, 'srl_cache', 3600);
            $this->invalidatePartnerVoucherCache();
            wp_send_json_success('Voucher został wysłany do weryfikacji. Otrzymasz email z informacją o statusie.');
        } else {
            wp_send_json_error($result['message']);
        }
    }

    private function validateAndSanitizePartnerVoucherData() {
        $required_fields = ['partner', 'typ_vouchera', 'kod_vouchera', 'kod_zabezpieczajacy', 'data_waznosci'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                return new WP_Error('missing_field', "Pole {$field} jest wymagane.");
            }
        }

        $partner = sanitize_text_field($_POST['partner']);
        $typ_vouchera = sanitize_text_field($_POST['typ_vouchera']);
        $kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
        $kod_zabezpieczajacy = sanitize_text_field($_POST['kod_zabezpieczajacy']);
        $data_waznosci = sanitize_text_field($_POST['data_waznosci']);
        $dane_pasazerow = $_POST['dane_pasazerow'] ?? [];

        $date_validation = SRL_Helpers::getInstance()->walidujDate($data_waznosci);
        if (!$date_validation['valid']) {
            return new WP_Error('invalid_date', 'Błąd w dacie ważności: ' . $date_validation['message']);
        }

        if (SRL_Helpers::getInstance()->isDatePast($data_waznosci)) {
            return new WP_Error('past_date', 'Data ważności nie może być z przeszłości.');
        }

        if (!is_array($dane_pasazerow) || empty($dane_pasazerow)) {
            return new WP_Error('no_passengers', 'Brak danych pasażerów.');
        }

        $liczba_osob = SRL_Partner_Voucher_Functions::getInstance()->getVoucherPassengerCount($partner, $typ_vouchera);
        if (count($dane_pasazerow) !== $liczba_osob) {
            return new WP_Error('passenger_count', "Nieprawidłowa liczba pasażerów. Oczekiwano: {$liczba_osob}");
        }

        $sanitized_passengers = [];
        foreach ($dane_pasazerow as $index => $pasazer) {
            $sanitized_passenger = [
                'imie' => sanitize_text_field($pasazer['imie']),
                'nazwisko' => sanitize_text_field($pasazer['nazwisko']),
                'rok_urodzenia' => intval($pasazer['rok_urodzenia']),
                'telefon' => sanitize_text_field($pasazer['telefon']),
                'kategoria_wagowa' => sanitize_text_field($pasazer['kategoria_wagowa']),
                'sprawnosc_fizyczna' => sanitize_text_field($pasazer['sprawnosc_fizyczna']),
                'uwagi' => sanitize_textarea_field($pasazer['uwagi'] ?? ''),
                'akceptacja_regulaminu' => true
            ];

            $walidacja_pasazera = SRL_Helpers::getInstance()->walidujDanePasazera($sanitized_passenger);
            if (!$walidacja_pasazera['valid']) {
                $numer_pasazera = $index + 1;
                $errors = implode(', ', $walidacja_pasazera['errors']);
                return new WP_Error('passenger_validation', "Błędy w danych pasażera {$numer_pasazera}: {$errors}");
            }

            $walidacja_waga = SRL_Helpers::getInstance()->walidujKategorieWagowa($sanitized_passenger['kategoria_wagowa']);
            if (!$walidacja_waga['valid']) {
                foreach ($walidacja_waga['errors'] as $error) {
                    return new WP_Error('weight_validation', "Pasażer " . ($index + 1) . " - " . $error['tresc']);
                }
            }

            $sanitized_passengers[] = $sanitized_passenger;
        }

        return [
            'partner' => $partner,
            'typ_vouchera' => $typ_vouchera,
            'kod_vouchera' => $kod_vouchera,
            'kod_zabezpieczajacy' => $kod_zabezpieczajacy,
            'data_waznosci' => $data_waznosci,
            'liczba_osob' => $liczba_osob,
            'dane_pasazerow' => $sanitized_passengers
        ];
    }

    public function ajaxGetPartnerVoucherDetails() {
        $this->validateAdminAccess();
        
        $voucher_id = intval($_POST['voucher_id']);
        if (empty($voucher_id)) {
            wp_send_json_error('Nieprawidłowy ID vouchera.');
        }
        
        $cache_key = "partner_voucher_details_{$voucher_id}";
        $voucher = wp_cache_get($cache_key, 'srl_cache');
        
        if ($voucher === false) {
            $voucher = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucher($voucher_id);
            if ($voucher) {
                wp_cache_set($cache_key, $voucher, 'srl_cache', 1800);
            }
        }
        
        if (!$voucher) {
            wp_send_json_error('Voucher nie został znaleziony.');
        }
        
        wp_send_json_success($voucher);
    }

    public function ajaxApprovePartnerVoucher() {
        $this->validateAdminAccess();
        
        $voucher_id = intval($_POST['voucher_id']);
        $validity_date = isset($_POST['validity_date']) ? sanitize_text_field($_POST['validity_date']) : null;
        
        if (empty($voucher_id)) {
            wp_send_json_error('Nieprawidłowy ID vouchera.');
        }
        
        if (!empty($validity_date)) {
            $date_validation = SRL_Helpers::getInstance()->walidujDate($validity_date);
            if (!$date_validation['valid']) {
                wp_send_json_error('Nieprawidłowa data ważności: ' . $date_validation['message']);
            }
            
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'srl_vouchery_partnerzy',
                ['data_waznosci_vouchera' => $validity_date],
                ['id' => $voucher_id],
                ['%s'], ['%d']
            );
        }
        
        $result = SRL_Partner_Voucher_Functions::getInstance()->approvePartnerVoucher($voucher_id);
        
        if ($result['success']) {
            $this->invalidatePartnerVoucherCache();
            wp_cache_delete("partner_voucher_details_{$voucher_id}", 'srl_cache');
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function ajaxRejectPartnerVoucher() {
        $this->validateAdminAccess();
        
        $voucher_id = intval($_POST['voucher_id']);
        $reason = sanitize_textarea_field($_POST['reason']);
        
        if (empty($voucher_id)) {
            wp_send_json_error('Nieprawidłowy ID vouchera.');
        }
        
        if (empty($reason)) {
            wp_send_json_error('Musisz podać powód odrzucenia.');
        }
        
        $result = SRL_Partner_Voucher_Functions::getInstance()->rejectPartnerVoucher($voucher_id, $reason);
        
        if ($result['success']) {
            $this->invalidatePartnerVoucherCache();
            wp_cache_delete("partner_voucher_details_{$voucher_id}", 'srl_cache');
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function ajaxGetPartnerVouchersList() {
        $this->validateAdminAccess();
        
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : null;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        
        $cache_key = "partner_vouchers_list_{$status}_{$limit}";
        $vouchery = wp_cache_get($cache_key, 'srl_cache');
        
        if ($vouchery === false) {
            $vouchery = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVouchers($status, $limit);
            wp_cache_set($cache_key, $vouchery, 'srl_cache', 600);
        }
        
        wp_send_json_success($vouchery);
    }

    public function ajaxCheckPartnerVoucherExists() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', false) || check_ajax_referer('srl_admin_nonce', 'nonce', true);
        
        $kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
        $kod_zabezpieczajacy = sanitize_text_field($_POST['kod_zabezpieczajacy']);
        $partner = sanitize_text_field($_POST['partner']);
        
        if (empty($kod_vouchera) || empty($kod_zabezpieczajacy) || empty($partner)) {
            wp_send_json_error('Brak wymaganych danych.');
        }
        
        $cache_key = "voucher_exists_" . md5($kod_vouchera . $kod_zabezpieczajacy . $partner);
        $exists = wp_cache_get($cache_key, 'srl_cache');
        
        if ($exists === false) {
            global $wpdb;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}srl_vouchery_partnerzy 
                 WHERE kod_vouchera = %s AND kod_zabezpieczajacy = %s AND partner = %s",
                $kod_vouchera, $kod_zabezpieczajacy, $partner
            ));
            
            $exists = $existing ? 'yes' : 'no';
            wp_cache_set($cache_key, $exists, 'srl_cache', 300);
        }
        
        if ($exists === 'yes') {
            wp_send_json_error('Voucher z tymi kodami już istnieje w systemie.');
        } else {
            wp_send_json_success('Voucher jest dostępny.');
        }
    }

    public function ajaxGetPartnerVoucherStats() {
        $this->validateAdminAccess();
        
        $cache_key = 'partner_voucher_stats';
        $stats = wp_cache_get($cache_key, 'srl_cache');
        
        if ($stats === false) {
            global $wpdb;
            $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
            
            $stats = [
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM $tabela"),
                'oczekuje' => $wpdb->get_var("SELECT COUNT(*) FROM $tabela WHERE status = 'oczekuje'"),
                'zatwierdzony' => $wpdb->get_var("SELECT COUNT(*) FROM $tabela WHERE status = 'zatwierdzony'"),
                'odrzucony' => $wpdb->get_var("SELECT COUNT(*) FROM $tabela WHERE status = 'odrzucony'"),
                'by_partner' => $wpdb->get_results("SELECT partner, COUNT(*) as count FROM $tabela GROUP BY partner", ARRAY_A),
                'recent' => $wpdb->get_results("SELECT * FROM $tabela ORDER BY data_zgloszenia DESC LIMIT 5", ARRAY_A)
            ];
            
            wp_cache_set($cache_key, $stats, 'srl_cache', 1800);
        }
        
        wp_send_json_success($stats);
    }
}