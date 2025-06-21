<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Voucher_Ajax {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_srl_wykorzystaj_voucher', array($this, 'ajaxWykorzystajVoucher'));
        add_action('wp_ajax_nopriv_srl_wykorzystaj_voucher', array($this, 'ajaxWykorzystajVoucher'));
        add_action('wp_ajax_srl_get_partner_voucher_types', array($this, 'ajaxGetPartnerVoucherTypes'));
        add_action('wp_ajax_nopriv_srl_get_partner_voucher_types', array($this, 'ajaxGetPartnerVoucherTypes'));
        add_action('wp_ajax_srl_submit_partner_voucher', array($this, 'ajaxSubmitPartnerVoucher'));
        add_action('wp_ajax_nopriv_srl_submit_partner_voucher', array($this, 'ajaxSubmitPartnerVoucher'));
        add_action('wp_ajax_srl_get_partner_voucher_details', array($this, 'ajaxGetPartnerVoucherDetails'));
        add_action('wp_ajax_srl_approve_partner_voucher', array($this, 'ajaxApprovePartnerVoucher'));
        add_action('wp_ajax_srl_reject_partner_voucher', array($this, 'ajaxRejectPartnerVoucher'));
        add_action('wp_ajax_srl_get_partner_vouchers_list', array($this, 'ajaxGetPartnerVouchersList'));
        add_action('wp_ajax_srl_check_partner_voucher_exists', array($this, 'ajaxCheckPartnerVoucherExists'));
        add_action('wp_ajax_nopriv_srl_check_partner_voucher_exists', array($this, 'ajaxCheckPartnerVoucherExists'));
        add_action('wp_ajax_srl_get_partner_voucher_stats', array($this, 'ajaxGetPartnerVoucherStats'));
    }

	public function ajaxWykorzystajVoucher() {
		// Używaj frontend nonce zamiast admin nonce dla użytkowników
		if (!check_ajax_referer('srl_frontend_nonce', 'nonce', false)) {
			// Fallback dla admin nonce w przypadku panelu administracyjnego
			check_ajax_referer('srl_admin_nonce', 'nonce', true);
		}
		
		if (!is_user_logged_in()) {
			wp_send_json_error('Musisz być zalogowany aby wykorzystać voucher.');
		}
		
		$kod_vouchera = strtoupper(sanitize_text_field($_POST['kod_vouchera']));
		$user_id = get_current_user_id();
		
		if (empty($kod_vouchera)) {
			wp_send_json_error('Kod vouchera jest wymagany.');
		}
		
		$validation = SRL_Helpers::getInstance()->walidujKodVouchera($kod_vouchera);
		if (!$validation['valid']) {
			wp_send_json_error($validation['message']);
		}
		
		// Sprawdź czy klasa SRL_Voucher_Gift_Functions istnieje i ma metodę wykorzystajVoucher
		if (!class_exists('SRL_Voucher_Gift_Functions')) {
			wp_send_json_error('Funkcja voucherów nie jest dostępna - brak klasy SRL_Voucher_Gift_Functions.');
		}
		
		$voucher_functions = SRL_Voucher_Gift_Functions::getInstance();
		if (!method_exists($voucher_functions, 'wykorzystajVoucher')) {
			wp_send_json_error('Funkcja wykorzystania voucherów nie jest dostępna.');
		}
		
		$result = $voucher_functions->wykorzystajVoucher($validation['kod'], $user_id);
		
		if ($result['success']) {
			wp_send_json_success($result['message']);
		} else {
			wp_send_json_error($result['message']);
		}
	}

	// Dodaj też metodę dla poprawnego nonce w partner voucherach:
	public function ajaxGetPartnerVoucherTypes() {
		// Używaj frontend nonce dla frontendowych żądań
		if (!check_ajax_referer('srl_frontend_nonce', 'nonce', false)) {
			// Fallback dla admin nonce
			check_ajax_referer('srl_admin_nonce', 'nonce', true);
		}
		
		$partner = sanitize_text_field($_POST['partner']);
		
		if (empty($partner)) {
			wp_send_json_error('Nie wybrano partnera.');
		}
		
		$types = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherTypes($partner);
		
		if (empty($types)) {
			wp_send_json_error('Brak dostępnych typów voucherów dla tego partnera.');
		}
		
		wp_send_json_success($types);
	}

	public function ajaxSubmitPartnerVoucher() {
		// Używaj frontend nonce dla frontendowych żądań
		if (!check_ajax_referer('srl_frontend_nonce', 'nonce', false)) {
			// Fallback dla admin nonce
			check_ajax_referer('srl_admin_nonce', 'nonce', true);
		}
		
		if (!is_user_logged_in()) {
			wp_send_json_error('Musisz być zalogowany.');
		}
		
		$user_id = get_current_user_id();
		$partner = sanitize_text_field($_POST['partner']);
		$typ_vouchera = sanitize_text_field($_POST['typ_vouchera']);
		$kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
		$kod_zabezpieczajacy = sanitize_text_field($_POST['kod_zabezpieczajacy']);
		$data_waznosci = sanitize_text_field($_POST['data_waznosci']);
		$dane_pasazerow = $_POST['dane_pasazerow'];
		
		// Walidacja danych
		$required_fields = ['partner', 'typ_vouchera', 'kod_vouchera', 'kod_zabezpieczajacy', 'data_waznosci'];
		foreach ($required_fields as $field) {
			if (empty($_POST[$field])) {
				wp_send_json_error("Pole {$field} jest wymagane.");
			}
		}
		
		$date_validation = SRL_Helpers::getInstance()->walidujDate($data_waznosci);
		if (!$date_validation['valid']) {
			wp_send_json_error('Błąd w dacie ważności: ' . $date_validation['message']);
		}
		
		if (SRL_Helpers::getInstance()->isDatePast($data_waznosci)) {
			wp_send_json_error('Data ważności nie może być z przeszłości.');
		}
		
		if (!is_array($dane_pasazerow) || empty($dane_pasazerow)) {
			wp_send_json_error('Brak danych pasażerów.');
		}
		
		// Sprawdź liczbę osób dla wybranego typu vouchera
		$liczba_osob = SRL_Partner_Voucher_Functions::getInstance()->getVoucherPassengerCount($partner, $typ_vouchera);
		
		if (count($dane_pasazerow) !== $liczba_osob) {
			wp_send_json_error("Nieprawidłowa liczba pasażerów. Oczekiwano: {$liczba_osob}");
		}
		
		// Walidacja i czyszczenie danych pasażerów
		$sanitized_passengers = array();
		foreach ($dane_pasazerow as $index => $pasazer) {
			$sanitized_passenger = array(
				'imie' => sanitize_text_field($pasazer['imie']),
				'nazwisko' => sanitize_text_field($pasazer['nazwisko']),
				'rok_urodzenia' => intval($pasazer['rok_urodzenia']),
				'telefon' => sanitize_text_field($pasazer['telefon']),
				'kategoria_wagowa' => sanitize_text_field($pasazer['kategoria_wagowa']),
				'sprawnosc_fizyczna' => sanitize_text_field($pasazer['sprawnosc_fizyczna']),
				'uwagi' => sanitize_textarea_field($pasazer['uwagi'] ?? ''),
				'akceptacja_regulaminu' => true
			);
			
			// Walidacja każdego pasażera
			$walidacja_pasazera = SRL_Helpers::getInstance()->walidujDanePasazera($sanitized_passenger);
			if (!$walidacja_pasazera['valid']) {
				$numer_pasazera = $index + 1;
				$errors = implode(', ', $walidacja_pasazera['errors']);
				wp_send_json_error("Błędy w danych pasażera {$numer_pasazera}: {$errors}");
			}
			
			// Sprawdź kategorię wagową
			$walidacja_waga = SRL_Helpers::getInstance()->walidujKategorieWagowa($sanitized_passenger['kategoria_wagowa']);
			if (!$walidacja_waga['valid']) {
				$numer_pasazera = $index + 1;
				foreach ($walidacja_waga['errors'] as $error) {
					wp_send_json_error("Pasażer {$numer_pasazera} - " . $error['tresc']);
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
		
		$result = SRL_Partner_Voucher_Functions::getInstance()->savePartnerVoucher($voucher_data);
		
		if ($result['success']) {
			wp_send_json_success('Voucher został wysłany do weryfikacji. Otrzymasz email z informacją o statusie.');
		} else {
			wp_send_json_error($result['message']);
		}
	}

    public function ajaxGetPartnerVoucherDetails() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        $voucher_id = intval($_POST['voucher_id']);
        
        if (empty($voucher_id)) {
            wp_send_json_error('Nieprawidłowy ID vouchera.');
        }
        
        $voucher = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucher($voucher_id);
        
        if (!$voucher) {
            wp_send_json_error('Voucher nie został znaleziony.');
        }
        
        wp_send_json_success($voucher);
    }

    public function ajaxApprovePartnerVoucher() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
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
            $tabela_vouchery = $wpdb->prefix . 'srl_vouchery_partnerzy';
            
            $wpdb->update(
                $tabela_vouchery,
                array('data_waznosci_vouchera' => $validity_date),
                array('id' => $voucher_id),
                array('%s'),
                array('%d')
            );
        }
        
        $result = SRL_Partner_Voucher_Functions::getInstance()->approvePartnerVoucher($voucher_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function ajaxRejectPartnerVoucher() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
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
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function ajaxGetPartnerVouchersList() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : null;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        
        $vouchery = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVouchers($status, $limit);
        
        wp_send_json_success($vouchery);
    }

    public function ajaxCheckPartnerVoucherExists() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        
        $kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
        $kod_zabezpieczajacy = sanitize_text_field($_POST['kod_zabezpieczajacy']);
        $partner = sanitize_text_field($_POST['partner']);
        
        if (empty($kod_vouchera) || empty($kod_zabezpieczajacy) || empty($partner)) {
            wp_send_json_error('Brak wymaganych danych.');
        }
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
        
        $existing = SRL_Database_Helpers::getInstance()->executeQuery(
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

    public function ajaxGetPartnerVoucherStats() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
        
        $stats = array(
            'total' => SRL_Database_Helpers::getInstance()->executeQuery("SELECT COUNT(*) FROM $tabela", array(), 'count'),
            'oczekuje' => SRL_Database_Helpers::getInstance()->executeQuery("SELECT COUNT(*) FROM $tabela WHERE status = 'oczekuje'", array(), 'count'),
            'zatwierdzony' => SRL_Database_Helpers::getInstance()->executeQuery("SELECT COUNT(*) FROM $tabela WHERE status = 'zatwierdzony'", array(), 'count'),
            'odrzucony' => SRL_Database_Helpers::getInstance()->executeQuery("SELECT COUNT(*) FROM $tabela WHERE status = 'odrzucony'", array(), 'count'),
            'by_partner' => SRL_Database_Helpers::getInstance()->executeQuery("SELECT partner, COUNT(*) as count FROM $tabela GROUP BY partner"),
            'recent' => SRL_Database_Helpers::getInstance()->executeQuery("SELECT * FROM $tabela ORDER BY data_zgloszenia DESC LIMIT 5")
        );
        
        wp_send_json_success($stats);
    }
}