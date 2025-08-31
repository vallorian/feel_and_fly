<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Frontend_Ajax {
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
			'srl_pobierz_dane_klienta', 'srl_zapisz_dane_pasazera', 'srl_pobierz_dostepne_dni',
			'srl_pobierz_dostepne_godziny', 'srl_dokonaj_rezerwacji', 'srl_anuluj_rezerwacje_klient',
			'srl_zablokuj_slot_tymczasowo', 'srl_waliduj_wiek', 'srl_waliduj_kategorie_wagowa',
			'srl_pobierz_dostepne_loty', 'srl_pobierz_dane_dnia'
		];
		
		foreach ($ajax_methods as $method) {
			add_action("wp_ajax_{$method}", [$this, 'ajax' . $this->toCamelCase($method)]);
		}

        add_action('wp_ajax_nopriv_srl_ajax_login', [$this, 'ajaxLogin']);
        add_action('wp_ajax_nopriv_srl_ajax_register', [$this, 'ajaxRegister']);
        add_action('wp_ajax_nopriv_srl_waliduj_wiek', [$this, 'ajaxWalidujWiek']);
        add_action('wp_ajax_nopriv_srl_waliduj_kategorie_wagowa', [$this, 'ajaxWalidujKategorieWagowa']);
    }

    private function toCamelCase($string) {
        return str_replace('_', '', ucwords(str_replace('srl_', '', $string), '_'));
    }

    private function validateUserAccess() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();
    }

    private function getUserFlightsOptimized($user_id) {
        $cache_key = "user_flights_full_{$user_id}";
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached === false) {
            global $wpdb;
            
            $rezerwacje = $wpdb->get_results($wpdb->prepare(
                "SELECT zl.*, t.data, t.godzina_start, t.godzina_koniec,
                        v.kod_vouchera, v.buyer_imie as voucher_buyer_imie, v.buyer_nazwisko as voucher_buyer_nazwisko
                 FROM {$wpdb->prefix}srl_zakupione_loty zl 
                 LEFT JOIN {$wpdb->prefix}srl_terminy t ON zl.termin_id = t.id
                 LEFT JOIN {$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
                 WHERE zl.user_id = %d AND zl.status = 'zarezerwowany' AND zl.data_waznosci >= CURDATE()
                 ORDER BY t.data ASC, t.godzina_start ASC",
                $user_id
            ), ARRAY_A);

            $dostepne_loty = $wpdb->get_results($wpdb->prepare(
                "SELECT zl.*, v.kod_vouchera, v.buyer_imie as voucher_buyer_imie, v.buyer_nazwisko as voucher_buyer_nazwisko
                 FROM {$wpdb->prefix}srl_zakupione_loty zl
                 LEFT JOIN {$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
                 WHERE zl.user_id = %d AND zl.status = 'wolny' AND zl.data_waznosci >= CURDATE()
                 ORDER BY zl.data_zakupu DESC",
                $user_id
            ), ARRAY_A);

            $cached = ['rezerwacje' => $rezerwacje, 'dostepne_loty' => $dostepne_loty];
            wp_cache_set($cache_key, $cached, 'srl_cache', 1800);
        }
        
        return $cached;
    }

    private function invalidateUserFlightCache($user_id) {
        wp_cache_delete("user_flights_full_{$user_id}", 'srl_cache');
        $this->cache_manager->invalidateUserCache($user_id);
    }

	public function ajaxPobierzDaneKlienta() {
		try {
			$this->validateUserAccess();
			
			$user_id = get_current_user_id();
			$flight_data = $this->getUserFlightsOptimized($user_id);
			$dane_osobowe = $this->cache_manager->getUserData($user_id);
			
			$dane_kompletne = !empty($dane_osobowe['imie']) && !empty($dane_osobowe['nazwisko']) 
							 && !empty($dane_osobowe['rok_urodzenia']) && !empty($dane_osobowe['kategoria_wagowa']) 
							 && !empty($dane_osobowe['sprawnosc_fizyczna']);

			wp_send_json_success(array_merge($flight_data, [
				'dane_osobowe' => $dane_osobowe,
				'dane_kompletne' => $dane_kompletne
			]));
		} catch (Exception $e) {
			wp_send_json_error($e->getMessage());
		}
	}

    public function ajaxZapiszDanePasazera() {
        $this->validateUserAccess();
        
        $user_id = get_current_user_id();
        $dane = [
            'imie' => sanitize_text_field($_POST['imie']),
            'nazwisko' => sanitize_text_field($_POST['nazwisko']),
            'rok_urodzenia' => intval($_POST['rok_urodzenia']),
            'kategoria_wagowa' => sanitize_text_field($_POST['kategoria_wagowa']),
            'sprawnosc_fizyczna' => sanitize_text_field($_POST['sprawnosc_fizyczna']),
            'telefon' => sanitize_text_field($_POST['telefon']),
            'uwagi' => sanitize_textarea_field($_POST['uwagi']),
            'akceptacja_regulaminu' => isset($_POST['akceptacja_regulaminu']) && $_POST['akceptacja_regulaminu'] === 'true'
        ];

        $walidacja = SRL_Helpers::getInstance()->walidujDanePasazera($dane);
        if (!$walidacja['valid']) {
            wp_send_json_error(implode(' ', $walidacja['errors']));
        }

        $batch_update = [];
        $zmienione_pola = [];
        foreach ($dane as $key => $value) {
            $stara_wartosc = get_user_meta($user_id, 'srl_' . $key, true);
            if ($stara_wartosc != $value) {
                $zmienione_pola[] = $key;
                $batch_update['srl_' . $key] = $value;
            }
        }

        if (!empty($batch_update)) {
            $success = $this->cache_manager->batchUserDataUpdate([$user_id => $batch_update]);
            if (!$success) {
                wp_send_json_error('Błąd zapisu danych.');
            }

            $user_flights = $this->db_helpers->getUserFlights($user_id, null, false);
            if (!empty($user_flights)) {
                foreach ($user_flights as $lot) {
                    SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], [
                        'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                        'typ' => 'zapisanie_danych_klienta',
                        'executor' => 'Klient',
                        'szczegoly' => ['zmienione_pola' => $zmienione_pola, 'user_id' => $user_id]
                    ]);
                }
            }
        }

        $this->invalidateUserFlightCache($user_id);
        wp_send_json_success(['message' => 'Dane zostały zapisane.']);
    }

    public function ajaxPobierzDostepneDni() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $rok = intval($_GET['rok']);
        $miesiac = intval($_GET['miesiac']);

        if ($rok < 2020 || $rok > 2030 || $miesiac < 1 || $miesiac > 12) {
            wp_send_json_error('Nieprawidłowa data.');
        }

        $dostepne_dni = $this->cache_manager->getAvailableDays($rok, $miesiac);
        wp_send_json_success($dostepne_dni);
    }

    public function ajaxPobierzDostepneGodziny() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $data = sanitize_text_field($_GET['data']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            wp_send_json_error('Nieprawidłowy format daty.');
        }

        if (strtotime($data) < strtotime(date('Y-m-d'))) {
            wp_send_json_error('Nie można rezerwować lotów w przeszłości.');
        }

        $cache_key = "available_slots_{$data}";
        $slots = wp_cache_get($cache_key, 'srl_cache');
        
        if ($slots === false) {
            $slots = $this->db_helpers->getAvailableSlots($data);
            wp_cache_set($cache_key, $slots, 'srl_cache', 300);
        }

        wp_send_json_success($slots);
    }

    public function ajaxZablokujSlotTymczasowo() {
        $this->validateUserAccess();
        
        $termin_id = intval($_POST['termin_id']);
        $user_id = get_current_user_id();

        if (!$this->db_helpers->isSlotAvailable($termin_id)) {
            wp_send_json_error('Ten termin nie jest już dostępny.');
        }

        $slot = $this->db_helpers->getSlotDetails($termin_id);
        set_transient('srl_block_' . $termin_id . '_' . $user_id, true, 15 * MINUTE_IN_SECONDS);

        wp_send_json_success([
            'slot' => $slot,
            'blokada_do' => time() + 15 * MINUTE_IN_SECONDS
        ]);
    }

    public function ajaxDokonajRezerwacji() {
        $this->validateUserAccess();
        
        $user_id = get_current_user_id();
        $termin_id = intval($_POST['termin_id']);
        $lot_id = intval($_POST['lot_id']);

        if (!get_transient('srl_block_' . $termin_id . '_' . $user_id)) {
            wp_send_json_error('Sesja rezerwacji wygasła. Spróbuj ponownie.');
        }

        $result = $this->db_helpers->reserveSlot($termin_id, $lot_id, $user_id);
        
        if ($result['success']) {
            delete_transient('srl_block_' . $termin_id . '_' . $user_id);
            $this->invalidateUserFlightCache($user_id);
            wp_cache_delete("available_slots_*", 'srl_cache');
            
            $slot = $this->db_helpers->getSlotDetails($termin_id);
            $lot = $this->db_helpers->getFlightById($lot_id);
            
            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'rezerwacja_klient',
                'executor' => 'Klient',
                'szczegoly' => [
                    'termin_id' => $termin_id,
                    'pilot_id' => $slot['pilot_id'],
                    'data_lotu' => $slot['data'],
                    'user_id' => $user_id
                ]
            ]);

            SRL_Email_Functions::getInstance()->wyslijEmailPotwierdzenia($user_id, $slot, $lot);
            wp_send_json_success(['message' => 'Rezerwacja została potwierdzona!']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function ajaxAnulujRezerwacjeKlient() {
		$this->validateUserAccess();
		
		$user_id = get_current_user_id();
		$lot_id = intval($_POST['lot_id']);
		global $wpdb;
		$lot = $wpdb->get_row($wpdb->prepare(
			"SELECT zl.*, t.data, t.godzina_start, t.godzina_koniec, t.pilot_id
			 FROM {$wpdb->prefix}srl_zakupione_loty zl
			 LEFT JOIN {$wpdb->prefix}srl_terminy t ON zl.termin_id = t.id
			 WHERE zl.id = %d AND zl.user_id = %d AND zl.status = 'zarezerwowany'",
			$lot_id, $user_id
		), ARRAY_A);
		
		if (!$lot) {
			wp_send_json_error('Nie znaleziono rezerwacji.');
		}
		
		$data_lotu = $lot['data'] . ' ' . $lot['godzina_start'];
		$czas_do_lotu = strtotime($data_lotu) - time();
		if ($czas_do_lotu < 48 * 3600) {
			wp_send_json_error('Nie można anulować rezerwacji na mniej niż 48h przed lotem.');
		}
		
		$result = $this->db_helpers->cancelReservation($lot_id);
		
		if ($result['success']) {
			$this->invalidateUserFlightCache($user_id);
			wp_cache_delete("available_slots_{$lot['data']}", 'srl_cache');
			
			$szczegoly_terminu = sprintf('%s %s-%s (Pilot %d)',
				$lot['data'],
				substr($lot['godzina_start'], 0, 5),
				substr($lot['godzina_koniec'], 0, 5),
				$lot['pilot_id']
			);
			
			SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
				'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
				'typ' => 'anulowanie_klient',
				'executor' => 'Klient',
				'szczegoly' => [
					'anulowany_termin' => $szczegoly_terminu,
					'czas_do_lotu_godzin' => round($czas_do_lotu / 3600, 1),
					'email_wyslany' => false // Początkowo false
				]
			]);

			// NOWY KOD - Wyślij email potwierdzający anulowanie
			$slot_data = array(
				'data' => $lot['data'],
				'godzina_start' => $lot['godzina_start'],
				'godzina_koniec' => $lot['godzina_koniec']
			);
			
			$lot_data = array(
				'data_waznosci' => $lot['data_waznosci']
			);
			
			$email_sent = SRL_Email_Functions::getInstance()->wyslijEmailAnulowaniaPrzezKlienta(
				$user_id,
				$slot_data,
				$lot_data
			);

			// Jeśli email został wysłany, zaktualizuj historię
			if ($email_sent) {
				SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
					'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
					'typ' => 'email_anulowanie_klient',
					'executor' => 'System',
					'szczegoly' => [
						'typ_emaila' => 'potwierdzenie_anulowania',
						'email_odbiorcy' => get_userdata($user_id)->user_email,
						'email_wyslany' => true
					]
				]);
			} else {
				error_log("SRL: Nie udało się wysłać emaila anulowania do user_id: {$user_id}, lot_id: {$lot_id}");
			}
			
			wp_send_json_success([
				'message' => $email_sent 
					? 'Rezerwacja została anulowana. Na Twój email zostało wysłane potwierdzenie.'
					: 'Rezerwacja została anulowana.'
			]);
		} else {
			wp_send_json_error($result['message']);
		}
	}

    public function ajaxLogin() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $attempts_key = 'login_attempts_' . md5($ip);
        $attempts = get_transient($attempts_key) ?: 0;

        if ($attempts >= 5) {
            wp_send_json_error('Za dużo nieudanych prób logowania. Spróbuj ponownie za 15 minut.');
        }

        if (empty($_POST['username']) || empty($_POST['password'])) {
            wp_send_json_error('Wprowadź nazwę użytkownika i hasło.');
        }

        $username = sanitize_user(wp_unslash($_POST['username']));
        $password = wp_unslash($_POST['password']);
        $remember = filter_var($_POST['remember'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            set_transient($attempts_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
            wp_send_json_error('Nieprawidłowe dane logowania.');
        }

        delete_transient($attempts_key);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);

        wp_send_json_success('Zalogowano pomyślnie!');
    }

    public function ajaxRegister() {
        if (!get_option('users_can_register')) {
            wp_send_json_error('Rejestracja nowych użytkowników jest wyłączona.');
        }

        $ip = $_SERVER['REMOTE_ADDR'];
        $register_key = 'register_attempts_' . md5($ip);
        $attempts = get_transient($register_key) ?: 0;

        if ($attempts >= 3) {
            wp_send_json_error('Za dużo prób rejestracji. Spróbuj ponownie za 30 minut.');
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $password = wp_unslash($_POST['password'] ?? '');
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));

        $validation_errors = [];
        if (empty($email) || !is_email($email)) $validation_errors[] = 'Nieprawidłowy email.';
        if (email_exists($email)) $validation_errors[] = 'Email już istnieje.';
        if (strlen($password) < 8) $validation_errors[] = 'Hasło za krótkie (min 8 znaków).';
        if (empty($first_name) || empty($last_name)) $validation_errors[] = 'Imię i nazwisko wymagane.';
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
            $validation_errors[] = 'Hasło musi zawierać małą literę, wielką literę i cyfrę.';
        }

        if (!empty($validation_errors)) {
            set_transient($register_key, $attempts + 1, 30 * MINUTE_IN_SECONDS);
            wp_send_json_error(implode(' ', $validation_errors));
        }

        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            set_transient($register_key, $attempts + 1, 30 * MINUTE_IN_SECONDS);
            wp_send_json_error('Błąd podczas tworzenia konta: ' . $user_id->get_error_message());
        }

        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name
        ]);

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        delete_transient($register_key);

        wp_send_json_success('Konto zostało utworzone i zalogowano automatycznie!');
    }

    public function ajaxWalidujWiek() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        
        $rok_urodzenia = intval($_POST['rok_urodzenia']);
        if (!$rok_urodzenia || $rok_urodzenia < 1920 || $rok_urodzenia > date('Y')) {
            wp_send_json_success(['html' => '']);
        }
        
        $walidacja = SRL_Helpers::getInstance()->walidujWiek($rok_urodzenia, 'html');
        wp_send_json_success(['html' => $walidacja['html'] ?? '']);
    }

    public function ajaxWalidujKategorieWagowa() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        
        $kategoria_wagowa = sanitize_text_field($_POST['kategoria_wagowa']);
        if (empty($kategoria_wagowa)) {
            wp_send_json_success(['html' => '']);
        }
        
        $walidacja = SRL_Helpers::getInstance()->walidujKategorieWagowa($kategoria_wagowa, 'html');
        wp_send_json_success(['html' => $walidacja['html'] ?? '']);
    }

    public function ajaxPobierzDostepneLoty() {
        $this->validateUserAccess();
        
        $user_id = get_current_user_id();
        $user_data = $this->cache_manager->getUserData($user_id);
        
        $cache_key = "user_all_flights_{$user_id}";
        $loty = wp_cache_get($cache_key, 'srl_cache');
        
        if ($loty === false) {
            global $wpdb;
            $loty = $wpdb->get_results($wpdb->prepare(
                "SELECT zl.*, t.data as data_lotu, t.godzina_start, t.godzina_koniec, t.pilot_id,
                        v.kod_vouchera, v.buyer_imie as voucher_buyer_imie, v.buyer_nazwisko as voucher_buyer_nazwisko
                 FROM {$wpdb->prefix}srl_zakupione_loty zl 
                 LEFT JOIN {$wpdb->prefix}srl_terminy t ON zl.termin_id = t.id
                 LEFT JOIN {$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
                 WHERE zl.user_id = %d 
                 ORDER BY 
                    CASE 
                        WHEN zl.status = 'zarezerwowany' THEN 1
                        WHEN zl.status = 'wolny' THEN 2
                        WHEN zl.status = 'zrealizowany' THEN 3
                        WHEN zl.status = 'przedawniony' THEN 4
                    END,
                    t.data ASC, zl.data_zakupu DESC",
                $user_id
            ), ARRAY_A);
            
            wp_cache_set($cache_key, $loty, 'srl_cache', 900);
        }

        $html = $this->generateFlightsHtml($loty);
        wp_send_json_success(['html' => $html, 'user_data' => $user_data]);
    }

	private function generateFlightsHtml($loty) {
		if (empty($loty)) {
			return '<div class="srl-brak-lotow"><h3>Brak dostępnych lotów</h3><p>Nie masz jeszcze żadnych lotów do zarezerwowania.</p></div>';
		}

		$html = '';
		foreach ($loty as $lot) {
			if (!is_array($lot) || !isset($lot['id'])) continue;
			
			$html .= '<div class="srl-lot-item" data-lot-id="' . $lot['id'] . '" data-status="' . ($lot['status'] ?? 'unknown') . '">';
			$html .= '<div class="srl-lot-header">';
			$html .= '<h4>Lot w tandemie (#' . $lot['id'] . ')</h4>';
			$html .= SRL_Helpers::getInstance()->generateStatusBadge($lot['status'] ?? 'unknown', 'lot');
			$html .= '</div>';
			
			$html .= '<div class="srl-lot-details">';
			$filmowanie = $lot['ma_filmowanie'] ?? 0;
			$akrobacje = $lot['ma_akrobacje'] ?? 0;
			$html .= '<div class="srl-lot-options">' . SRL_Helpers::getInstance()->formatFlightOptionsHtml($filmowanie, $akrobacje) . '</div>';
			
			if (($lot['status'] ?? '') === 'zarezerwowany' && !empty($lot['data_lotu'])) {
				$html .= '<div class="srl-lot-termin">' . SRL_Helpers::getInstance()->formatujDateICzasPolski($lot['data_lotu'], $lot['godzina_start'] ?? '') . '</div>';
			}
			
			$html .= '<div class="srl-lot-waznosc">' . SRL_Helpers::getInstance()->formatujWaznoscLotu($lot['data_waznosci'] ?? '') . '</div>';
			$html .= '</div>';
			
			$html .= '<div class="srl-lot-actions">';
			if (($lot['status'] ?? '') === 'wolny') {
				$html .= '<button class="srl-btn srl-btn-primary srl-rezerwuj-lot" data-lot-id="' . $lot['id'] . '">Zarezerwuj lot</button>';
			} elseif (($lot['status'] ?? '') === 'zarezerwowany') {
				if (SRL_Helpers::getInstance()->canCancelReservation($lot['data_lotu'] ?? '', $lot['godzina_start'] ?? '')) {
					$html .= '<button class="srl-btn srl-btn-secondary srl-anuluj-rezerwacje" data-lot-id="' . $lot['id'] . '">Anuluj rezerwację</button>';
				}
				$html .= '<button class="srl-btn srl-btn-primary srl-zmien-termin" data-lot-id="' . $lot['id'] . '">Zmień termin</button>';
			}
			$html .= '</div></div>';
		}
		
		return $html;
	}

    public function ajaxPobierzDaneDnia() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $data = sanitize_text_field($_POST['data']);
        $validation = SRL_Helpers::getInstance()->walidujDate($data);
        if (!$validation['valid']) {
            wp_send_json_error($validation['message']);
        }

        if (SRL_Helpers::getInstance()->isDatePast($data)) {
            wp_send_json_error('Nie można rezerwować lotów w przeszłości.');
        }

        $dostepne_sloty = $this->db_helpers->getAvailableSlots($data);
        if (empty($dostepne_sloty)) {
            wp_send_json_error('Brak dostępnych terminów w wybranym dniu.');
        }

        $html = '<div class="srl-sloty-lista">';
        $html .= '<h3>Dostępne terminy na dzień ' . SRL_Helpers::getInstance()->formatujDate($data) . '</h3>';
        
        $poprzedni_pilot = null;
        foreach ($dostepne_sloty as $slot) {
            if ($poprzedni_pilot !== $slot['pilot_id']) {
                if ($poprzedni_pilot !== null) {
                    $html .= '</div></div>';
                }
                $html .= '<div class="srl-pilot-group"><h4>Pilot ' . $slot['pilot_id'] . '</h4><div class="srl-pilot-sloty">';
                $poprzedni_pilot = $slot['pilot_id'];
            }
            
            $html .= '<button class="srl-btn srl-btn-outline srl-slot-btn" ';
            $html .= 'data-slot-id="' . $slot['id'] . '" ';
            $html .= 'data-pilot="' . $slot['pilot_id'] . '" ';
            $html .= 'data-start="' . substr($slot['godzina_start'], 0, 5) . '" ';
            $html .= 'data-end="' . substr($slot['godzina_koniec'], 0, 5) . '">';
            $html .= substr($slot['godzina_start'], 0, 5) . ' - ' . substr($slot['godzina_koniec'], 0, 5);
            $html .= '</button>';
        }
        
        if ($poprzedni_pilot !== null) {
            $html .= '</div></div>';
        }
        $html .= '</div>';

        wp_send_json_success(['html' => $html, 'sloty_count' => count($dostepne_sloty)]);
    }
}