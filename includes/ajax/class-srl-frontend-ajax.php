<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Frontend_Ajax {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_srl_pobierz_dane_klienta', array($this, 'ajaxPobierzDaneKlienta'));
        add_action('wp_ajax_srl_zapisz_dane_pasazera', array($this, 'ajaxZapiszDanePasazera'));
        add_action('wp_ajax_srl_pobierz_dostepne_dni', array($this, 'ajaxPobierzDostepneDni'));
        add_action('wp_ajax_srl_pobierz_dostepne_godziny', array($this, 'ajaxPobierzDostepneGodziny'));
        add_action('wp_ajax_srl_dokonaj_rezerwacji', array($this, 'ajaxDokonajRezerwacji'));
        add_action('wp_ajax_srl_anuluj_rezerwacje_klient', array($this, 'ajaxAnulujRezerwacjeKlient'));
        add_action('wp_ajax_srl_zablokuj_slot_tymczasowo', array($this, 'ajaxZablokujSlotTymczasowo'));
        add_action('wp_ajax_nopriv_srl_ajax_login', array($this, 'ajaxLogin'));
        add_action('wp_ajax_nopriv_srl_ajax_register', array($this, 'ajaxRegister'));
        add_action('wp_ajax_srl_waliduj_wiek', array($this, 'ajaxWalidujWiek'));
        add_action('wp_ajax_nopriv_srl_waliduj_wiek', array($this, 'ajaxWalidujWiek'));
        add_action('wp_ajax_srl_waliduj_kategorie_wagowa', array($this, 'ajaxWalidujKategorieWagowa'));
        add_action('wp_ajax_nopriv_srl_waliduj_kategorie_wagowa', array($this, 'ajaxWalidujKategorieWagowa'));
        add_action('wp_ajax_srl_pobierz_dostepne_loty', array($this, 'ajaxPobierzDostepneLoty'));
        add_action('wp_ajax_srl_pobierz_dane_dnia', array($this, 'ajaxPobierzDaneDnia'));
    }

    public function ajaxPobierzDaneKlienta() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $user_id = get_current_user_id();
        global $wpdb;

        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';

        $rezerwacje = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.*, t.data, t.godzina_start, t.godzina_koniec,
                    v.kod_vouchera,
                    v.buyer_imie as voucher_buyer_imie,
                    v.buyer_nazwisko as voucher_buyer_nazwisko
             FROM $tabela_loty zl 
             LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
             LEFT JOIN {$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
             WHERE zl.user_id = %d 
             AND zl.status = 'zarezerwowany'
             AND zl.data_waznosci >= CURDATE()
             ORDER BY t.data ASC, t.godzina_start ASC",
            $user_id
        ), ARRAY_A);

        $dostepne_loty = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.*, v.kod_vouchera,
                    v.buyer_imie as voucher_buyer_imie,
                    v.buyer_nazwisko as voucher_buyer_nazwisko
             FROM $tabela_loty zl
             LEFT JOIN {$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
             WHERE zl.user_id = %d 
             AND zl.status = 'wolny'
             AND zl.data_waznosci >= CURDATE()
             ORDER BY zl.data_zakupu DESC",
            $user_id
        ), ARRAY_A);

        $dane_osobowe = SRL_Helpers::getInstance()->getUserFullData($user_id);

        $dane_kompletne = !empty($dane_osobowe['imie']) && !empty($dane_osobowe['nazwisko']) 
                         && !empty($dane_osobowe['rok_urodzenia']) && !empty($dane_osobowe['kategoria_wagowa']) 
                         && !empty($dane_osobowe['sprawnosc_fizyczna']);

        wp_send_json_success(array(
            'rezerwacje' => $rezerwacje,
            'dostepne_loty' => $dostepne_loty,
            'dane_osobowe' => $dane_osobowe,
            'dane_kompletne' => $dane_kompletne
        ));
    }

    public function ajaxZapiszDanePasazera() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $user_id = get_current_user_id();

        $dane = array(
            'imie' => sanitize_text_field($_POST['imie']),
            'nazwisko' => sanitize_text_field($_POST['nazwisko']),
            'rok_urodzenia' => intval($_POST['rok_urodzenia']),
            'kategoria_wagowa' => sanitize_text_field($_POST['kategoria_wagowa']),
            'sprawnosc_fizyczna' => sanitize_text_field($_POST['sprawnosc_fizyczna']),
            'telefon' => sanitize_text_field($_POST['telefon']),
            'uwagi' => sanitize_textarea_field($_POST['uwagi']),
            'akceptacja_regulaminu' => isset($_POST['akceptacja_regulaminu']) && $_POST['akceptacja_regulaminu'] === 'true'
        );

        $walidacja = SRL_Helpers::getInstance()->walidujDanePasazera($dane);
        if (!$walidacja['valid']) {
            $bledy_tekst = array();
            foreach ($walidacja['errors'] as $pole => $blad) {
                $bledy_tekst[] = $blad;
            }
            wp_send_json_error(implode(' ', $bledy_tekst));
            return;
        }

        $stare_dane = array();
        $zmienione_pola = array();
        $czy_zmiana = false;

        foreach ($dane as $key => $value) {
            $stara_wartosc = get_user_meta($user_id, 'srl_' . $key, true);
            $stare_dane[$key] = $stara_wartosc;

            if ($stara_wartosc != $value) {
                $zmienione_pola[] = $key;
                $czy_zmiana = true;
            }

            update_user_meta($user_id, 'srl_' . $key, $value);
        }

        if ($czy_zmiana) {
            global $wpdb;
            $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

            $loty_uzytkownika = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM $tabela_loty WHERE user_id = %d AND status IN ('wolny', 'zarezerwowany') AND data_waznosci >= CURDATE()",
                $user_id
            ), ARRAY_A);

            if (!empty($loty_uzytkownika)) {
                foreach ($loty_uzytkownika as $lot) {
                    $wpis_historii = array(
                        'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                        'typ' => 'zapisanie_danych_klienta',
                        'executor' => 'Klient',
                        'szczegoly' => array(
                            'zmienione_pola' => $zmienione_pola,
                            'user_id' => $user_id,
                            'stare_dane' => $stare_dane,
                            'nowe_dane' => $dane
                        )
                    );

                    SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], $wpis_historii);
                }
            }
        }
		SRL_Helpers::getInstance()->invalidateUserCache($user_id);
        wp_send_json_success(array('message' => 'Dane zostały zapisane.'));
    }

	public function ajaxPobierzDostepneDni() {
		check_ajax_referer('srl_frontend_nonce', 'nonce', true);
		SRL_Helpers::getInstance()->requireLogin();

		$rok = intval($_GET['rok']);
		$miesiac = intval($_GET['miesiac']);

		if ($rok < 2020 || $rok > 2030 || $miesiac < 1 || $miesiac > 12) {
			wp_send_json_error('Nieprawidłowa data.');
			return;
		}

		$dostepne_dni = SRL_Cache_Manager::getInstance()->getAvailableDays($rok, $miesiac);
		wp_send_json_success($dostepne_dni);
	}
	
    public function ajaxPobierzDostepneGodziny() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $data = sanitize_text_field($_GET['data']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            wp_send_json_error('Nieprawidłowy format daty.');
            return;
        }

        if (strtotime($data) < strtotime(date('Y-m-d'))) {
            wp_send_json_error('Nie można rezerwować lotów w przeszłości.');
            return;
        }

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $wolne_sloty = $wpdb->get_results($wpdb->prepare(
            "SELECT id, pilot_id, godzina_start, godzina_koniec 
             FROM $tabela 
             WHERE data = %s 
             AND status = 'Wolny'
             ORDER BY pilot_id ASC, godzina_start ASC",
            $data
        ), ARRAY_A);

        wp_send_json_success($wolne_sloty);
    }

    public function ajaxZablokujSlotTymczasowo() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $termin_id = intval($_POST['termin_id']);
        $user_id = get_current_user_id();

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela WHERE id = %d AND status = 'Wolny'",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Ten termin nie jest już dostępny.');
            return;
        }

        set_transient('srl_block_' . $termin_id . '_' . $user_id, true, 15 * MINUTE_IN_SECONDS);

        wp_send_json_success(array(
            'slot' => $slot,
            'blokada_do' => time() + 15 * MINUTE_IN_SECONDS
        ));
    }

    public function ajaxDokonajRezerwacji() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $user_id = get_current_user_id();
        $termin_id = intval($_POST['termin_id']);
        $lot_id = intval($_POST['lot_id']);

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        if (!get_transient('srl_block_' . $termin_id . '_' . $user_id)) {
            wp_send_json_error('Sesja rezerwacji wygasła. Spróbuj ponownie.');
            return;
        }

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Ten termin nie jest już dostępny.');
            return;
        }

        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_loty 
             WHERE id = %d AND user_id = %d AND status = 'wolny' AND data_waznosci >= CURDATE()",
            $lot_id, $user_id
        ), ARRAY_A);

        if (!$lot) {
            wp_send_json_error('Ten lot nie jest dostępny do rezerwacji.');
            return;
        }

        $dane_osobowe = SRL_Helpers::getInstance()->getUserFullData($user_id);

        $wpdb->query('START TRANSACTION');

        try {
            $current_datetime = SRL_Helpers::getInstance()->getCurrentDatetime();

            $update_slot = $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Zarezerwowany',
                    'klient_id' => $user_id
                ),
                array('id' => $termin_id),
                array('%s', '%d'),
                array('%d')
            );

            if ($update_slot === false) {
                throw new Exception('Błąd aktualizacji slotu.');
            }

            $update_lot = $wpdb->update(
                $tabela_loty,
                array(
                    'status' => 'zarezerwowany',
                    'data_rezerwacji' => $current_datetime,
                    'termin_id' => $termin_id,
                    'dane_pasazera' => json_encode($dane_osobowe)
                ),
                array('id' => $lot_id),
                array('%s', '%s', '%d', '%s'),
                array('%d')
            );

            if ($update_lot === false) {
                throw new Exception('Błąd aktualizacji lotu.');
            }

            $termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                $slot['data'], 
                substr($slot['godzina_start'], 0, 5), 
                substr($slot['godzina_koniec'], 0, 5),
                $slot['pilot_id']
            );

            $wpis_historii = array(
                'data' => $current_datetime,
                'typ' => 'rezerwacja_klient',
                'executor' => 'Klient',
                'szczegoly' => array(
                    'termin_id' => $termin_id,
                    'termin' => $termin_opis,
                    'pilot_id' => $slot['pilot_id'],
                    'data_lotu' => $slot['data'],
                    'godzina_start' => $slot['godzina_start'],
                    'godzina_koniec' => $slot['godzina_koniec'],
                    'user_id' => $user_id,
                    'dane_pasazera_zapisane' => !empty($dane_osobowe['imie']) && !empty($dane_osobowe['nazwisko']),
                    'stary_status' => 'wolny',
                    'nowy_status' => 'zarezerwowany'
                )
            );

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);

            $wpdb->query('COMMIT');

            delete_transient('srl_block_' . $termin_id . '_' . $user_id);

            SRL_Email_Functions::getInstance()->wyslijEmailPotwierdzenia($user_id, $slot, $lot);

            wp_send_json_success(array(
                'message' => 'Rezerwacja została potwierdzona!',
                'slot' => $slot,
                'lot' => $lot
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Błąd podczas rezerwacji: ' . $e->getMessage());
        }
    }

    public function ajaxAnulujRezerwacjeKlient() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $user_id = get_current_user_id();
        $lot_id = intval($_POST['lot_id']);

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT zl.*, t.data, t.godzina_start, t.godzina_koniec, t.pilot_id
             FROM $tabela_loty zl
             LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
             WHERE zl.id = %d AND zl.user_id = %d AND zl.status = 'zarezerwowany'",
            $lot_id, $user_id
        ), ARRAY_A);

        if (!$lot) {
            wp_send_json_error('Nie znaleziono rezerwacji.');
            return;
        }

        $data_lotu = $lot['data'] . ' ' . $lot['godzina_start'];
        $czas_do_lotu = strtotime($data_lotu) - time();

        if ($czas_do_lotu < 48 * 3600) {
            wp_send_json_error('Nie można anulować rezerwacji na mniej niż 48h przed lotem.');
            return;
        }

        $termin_id = $lot['termin_id'];

        $szczegoly_terminu = sprintf('%s %s-%s (Pilot %d)', 
            $lot['data'],
            substr($lot['godzina_start'], 0, 5),
            substr($lot['godzina_koniec'], 0, 5),
            $lot['pilot_id']
        );

        $wpdb->query('START TRANSACTION');

        try {
            $update_slot = $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Wolny',
                    'klient_id' => null
                ),
                array('id' => $termin_id),
                array('%s', '%d'),
                array('%d')
            );

            if ($update_slot === false) {
                throw new Exception('Błąd aktualizacji slotu.');
            }

            $update_lot = $wpdb->update(
                $tabela_loty,
                array(
                    'status' => 'wolny',
                    'data_rezerwacji' => null,
                    'termin_id' => null
                ),
                array('id' => $lot_id),
                array('%s', '%s', '%d'),
                array('%d')
            );

            if ($update_lot === false) {
                throw new Exception('Błąd aktualizacji lotu.');
            }

            $wpis_historii = array(
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'anulowanie_klient',
                'executor' => 'Klient',
                'szczegoly' => array(
                    'anulowany_termin' => $szczegoly_terminu,
                    'termin_id' => $termin_id,
                    'data_anulowania' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'powod' => 'Anulowanie przez klienta w dozwolonym czasie (powyżej 48h przed lotem)',
                    'stary_status' => 'zarezerwowany',
                    'nowy_status' => 'wolny',
                    'czas_do_lotu_godzin' => round($czas_do_lotu / 3600, 1)
                )
            );

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);

            $wpdb->query('COMMIT');
            wp_send_json_success(array('message' => 'Rezerwacja została anulowana.'));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Błąd podczas anulowania: ' . $e->getMessage());
        }
    }

    public function ajaxLogin() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $attempts_key = 'login_attempts_' . md5($ip);
        $attempts = get_transient($attempts_key) ?: 0;

        if ($attempts >= 5) {
            wp_send_json_error('Za dużo nieudanych prób logowania. Spróbuj ponownie za 15 minut.');
            return;
        }

        if (empty($_POST['username']) || empty($_POST['password'])) {
            wp_send_json_error('Wprowadź nazwę użytkownika i hasło.');
            return;
        }

        $username = sanitize_user(wp_unslash($_POST['username']));
        $password = wp_unslash($_POST['password']); 
        $remember = filter_var($_POST['remember'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            set_transient($attempts_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
            wp_send_json_error('Nieprawidłowe dane logowania.');
            return;
        }

        delete_transient($attempts_key);

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);

        wp_send_json_success('Zalogowano pomyślnie!');
    }

    public function ajaxRegister() {
        if (!get_option('users_can_register')) {
            wp_send_json_error('Rejestracja nowych użytkowników jest wyłączona.');
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'];
        $register_key = 'register_attempts_' . md5($ip);
        $attempts = get_transient($register_key) ?: 0;

        if ($attempts >= 3) {
            wp_send_json_error('Za dużo prób rejestracji. Spróbuj ponownie za 30 minut.');
            return;
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $password = wp_unslash($_POST['password'] ?? ''); 
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));

        if (empty($email) || !is_email($email)) {
            wp_send_json_error('Wprowadź prawidłowy adres email.');
            return;
        }

        if (email_exists($email)) {
            set_transient($register_key, $attempts + 1, 30 * MINUTE_IN_SECONDS);
            wp_send_json_error('Użytkownik z tym adresem email już istnieje.');
            return;
        }

        if (strlen($password) < 8) { 
            wp_send_json_error('Hasło musi mieć co najmniej 8 znaków.');
            return;
        }

        if (empty($first_name) || empty($last_name)) {
            wp_send_json_error('Wprowadź imię i nazwisko.');
            return;
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
            wp_send_json_error('Hasło musi zawierać małą literę, wielką literę i cyfrę.');
            return;
        }

        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            set_transient($register_key, $attempts + 1, 30 * MINUTE_IN_SECONDS);
            wp_send_json_error('Błąd podczas tworzenia konta: ' . $user_id->get_error_message());
            return;
        }

        wp_update_user(array(
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name
        ));

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        delete_transient($register_key);

        wp_send_json_success('Konto zostało utworzone i zalogowano automatycznie!');
    }

    public function ajaxWalidujWiek() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        
        $rok_urodzenia = intval($_POST['rok_urodzenia']);
        
        if (!$rok_urodzenia || $rok_urodzenia < 1920 || $rok_urodzenia > date('Y')) {
            wp_send_json_success(array('html' => ''));
            return;
        }
        
        $walidacja = SRL_Helpers::getInstance()->WalidujWiek($rok_urodzenia, 'html');
        
        if (!empty($walidacja['html'])) {
            wp_send_json_success(array('html' => $walidacja['html']));
        } else {
            wp_send_json_success(array('html' => ''));
        }
    }

    public function ajaxWalidujKategorieWagowa() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        
        $kategoria_wagowa = sanitize_text_field($_POST['kategoria_wagowa']);
        
        if (empty($kategoria_wagowa)) {
            wp_send_json_success(array('html' => ''));
            return;
        }
        
        $walidacja = SRL_Helpers::getInstance()->WalidujKategorieWagowa($kategoria_wagowa, 'html');
        
        if (!empty($walidacja['html'])) {
            wp_send_json_success(array('html' => $walidacja['html']));
        } else {
            wp_send_json_success(array('html' => ''));
        }
    }

    public function ajaxPobierzDostepneLoty() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $user_id = get_current_user_id();
        $user_data = SRL_Helpers::getInstance()->getUserFullData($user_id);
        
        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';

        $loty = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.*, t.data as data_lotu, t.godzina_start, t.godzina_koniec, t.pilot_id,
                    v.kod_vouchera, v.buyer_imie as voucher_buyer_imie, v.buyer_nazwisko as voucher_buyer_nazwisko
             FROM $tabela_loty zl 
             LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
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

        $html = '';
        
        if (empty($loty)) {
            $html = '<div class="srl-brak-lotow">';
            $html .= '<h3>Brak dostępnych lotów</h3>';
            $html .= '<p>Nie masz jeszcze żadnych lotów do zarezerwowania.</p>';
            $html .= '</div>';
        } else {
            foreach ($loty as $lot) {
                $html .= '<div class="srl-lot-item" data-lot-id="' . $lot['id'] . '" data-status="' . $lot['status'] . '">';
				$html .= '<div class="srl-lot-header">';
				$html .= '<h4>Lot w tandemie (#' . $lot['id'] . ')</h4>';
                $html .= SRL_Helpers::getInstance()->generateStatusBadge($lot['status'], 'lot');
                $html .= '</div>';
                
                $html .= '<div class="srl-lot-details">';
                $html .= '<div class="srl-lot-options">' . SRL_Helpers::getInstance()->formatFlightOptionsHtml($lot['ma_filmowanie'], $lot['ma_akrobacje']) . '</div>';
                
                if ($lot['status'] === 'zarezerwowany' && !empty($lot['data_lotu'])) {
                    $html .= '<div class="srl-lot-termin">';
                    $html .= SRL_Helpers::getInstance()->formatujDateICzasPolski($lot['data_lotu'], $lot['godzina_start']);
                    $html .= '</div>';
                }
                
                $html .= '<div class="srl-lot-waznosc">' . SRL_Helpers::getInstance()->formatujWaznoscLotu($lot['data_waznosci']) . '</div>';
                $html .= '</div>';
                
                $html .= '<div class="srl-lot-actions">';
                if ($lot['status'] === 'wolny') {
                    $html .= '<button class="srl-btn srl-btn-primary srl-rezerwuj-lot" data-lot-id="' . $lot['id'] . '">Zarezerwuj lot</button>';
                } elseif ($lot['status'] === 'zarezerwowany') {
                    if (SRL_Helpers::getInstance()->canCancelReservation($lot['data_lotu'], $lot['godzina_start'])) {
                        $html .= '<button class="srl-btn srl-btn-secondary srl-anuluj-rezerwacje" data-lot-id="' . $lot['id'] . '">Anuluj rezerwację</button>';
                    }
                    $html .= '<button class="srl-btn srl-btn-primary srl-zmien-termin" data-lot-id="' . $lot['id'] . '">Zmień termin</button>';
                }
                $html .= '</div>';
                
                $html .= '</div>';
            }
        }

        wp_send_json_success(array(
            'html' => $html,
            'user_data' => $user_data,
            'message' => 'Dane pobrane pomyślnie.'
        ));
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

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $dostepne_sloty = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tabela 
             WHERE data = %s 
             AND status = 'Wolny' 
             ORDER BY pilot_id ASC, godzina_start ASC",
            $data
        ), ARRAY_A);

        if (empty($dostepne_sloty)) {
            wp_send_json_error('Brak dostępnych terminów w wybranym dniu.');
        }

        $html = '<div class="srl-sloty-lista">';
        $html .= '<h3>Dostępne terminy na dzień ' . SRL_Helpers::getInstance()->formatujDate($data) . '</h3>';
        
        $poprzedni_pilot = null;
        foreach ($dostepne_sloty as $slot) {
            if ($poprzedni_pilot !== $slot['pilot_id']) {
                if ($poprzedni_pilot !== null) {
                    $html .= '</div>';
                }
                $html .= '<div class="srl-pilot-group">';
                $html .= '<h4>Pilot ' . $slot['pilot_id'] . '</h4>';
                $html .= '<div class="srl-pilot-sloty">';
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

        wp_send_json_success(array(
            'html' => $html,
            'sloty_count' => count($dostepne_sloty)
        ));
    }
}