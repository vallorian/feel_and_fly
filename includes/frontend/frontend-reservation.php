<?php
// Rejestracja AJAX handlerów dla frontendu
add_action('wp_ajax_srl_pobierz_dane_klienta', 'srl_pobierz_dane_klienta');
add_action('wp_ajax_srl_zapisz_dane_pasazera', 'srl_zapisz_dane_pasazera');
add_action('wp_ajax_srl_pobierz_dostepne_dni', 'srl_pobierz_dostepne_dni');
add_action('wp_ajax_srl_pobierz_dostepne_godziny', 'srl_pobierz_dostepne_godziny');
add_action('wp_ajax_srl_dokonaj_rezerwacji', 'srl_dokonaj_rezerwacji');
add_action('wp_ajax_srl_anuluj_rezerwacje_klient', 'srl_anuluj_rezerwacje_klient');
add_action('wp_ajax_srl_zablokuj_slot_tymczasowo', 'srl_zablokuj_slot_tymczasowo');

/**
 * AJAX: Pobiera dane klienta (rezerwacje, dostępne loty, dane osobowe)
 */
function srl_pobierz_dane_klienta() {
    // Weryfikacja nonce
    if (!wp_verify_nonce($_GET['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $user_id = get_current_user_id();
    global $wpdb;

    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
	// 1. Pobierz aktualne rezerwacje - przywróć oryginalny
	$rezerwacje = $wpdb->get_results($wpdb->prepare(
		"SELECT zl.*, t.data, t.godzina_start, t.godzina_koniec
		 FROM $tabela_loty zl 
		 LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
		 WHERE zl.user_id = %d 
		 AND zl.status = 'zarezerwowany'
		 AND zl.data_waznosci >= CURDATE()
		 ORDER BY t.data ASC, t.godzina_start ASC",
		$user_id
	), ARRAY_A);

	// 2. Pobierz dostępne loty do rezerwacji - przywróć oryginalny
	$dostepne_loty = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM $tabela_loty 
		 WHERE user_id = %d 
		 AND status = 'wolny'
		 AND data_waznosci >= CURDATE()
		 ORDER BY data_zakupu DESC",
		$user_id
	), ARRAY_A);
	
	
    // 3. Pobierz dane osobowe
	$dane_osobowe = array(
		'imie' => get_user_meta($user_id, 'srl_imie', true),
		'nazwisko' => get_user_meta($user_id, 'srl_nazwisko', true),
		'rok_urodzenia' => get_user_meta($user_id, 'srl_rok_urodzenia', true),
		'kategoria_wagowa' => get_user_meta($user_id, 'srl_kategoria_wagowa', true),
		'sprawnosc_fizyczna' => get_user_meta($user_id, 'srl_sprawnosc_fizyczna', true),
		'telefon' => get_user_meta($user_id, 'srl_telefon', true),
		'uwagi' => get_user_meta($user_id, 'srl_uwagi', true)
	);

	// Sprawdź czy dane są kompletne
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

/**
 * AJAX: Zapisuje dane pasażera
 */
function srl_zapisz_dane_pasazera() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $user_id = get_current_user_id();
    
	// Walidacja danych
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

	// Walidacja wymaganych pól
	if (empty($dane['imie']) || empty($dane['nazwisko']) || $dane['rok_urodzenia'] < 1920 || 
		empty($dane['kategoria_wagowa']) || empty($dane['sprawnosc_fizyczna']) || empty($dane['telefon'])) {
		wp_send_json_error('Wypełnij wszystkie wymagane pola poprawnie.');
		return;
	}

	// Sprawdź akceptację regulaminu
	if (!$dane['akceptacja_regulaminu']) {
		wp_send_json_error('Musisz zaakceptować Regulamin.');
		return;
	}

	// Sprawdź kategorię wagową
	if ($dane['kategoria_wagowa'] === '120kg+') {
		wp_send_json_error('Nie można dokonać rezerwacji z kategorią wagową 120kg+');
		return;
	}    
    // Sprawdź wiek (min 16 lat)
    $wiek = date('Y') - $dane['rok_urodzenia'];
    if ($wiek < 16) {
        wp_send_json_error('Musisz mieć co najmniej 16 lat aby dokonać rezerwacji.');
        return;
    }
    
    // Zapisz dane
    foreach ($dane as $key => $value) {
        update_user_meta($user_id, 'srl_' . $key, $value);
    }
    
    wp_send_json_success(array('message' => 'Dane zostały zapisane.'));
}

/**
 * AJAX: Pobiera dostępne dni w miesiącu
 */
function srl_pobierz_dostepne_dni() {
    if (!wp_verify_nonce($_GET['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $rok = intval($_GET['rok']);
    $miesiac = intval($_GET['miesiac']);
    
    if ($rok < 2020 || $rok > 2030 || $miesiac < 1 || $miesiac > 12) {
        wp_send_json_error('Nieprawidłowa data.');
        return;
    }
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    // Pobierz dni z wolnymi slotami w danym miesiącu
    $poczatek_miesiaca = sprintf('%04d-%02d-01', $rok, $miesiac);
    $koniec_miesiaca = date('Y-m-t', strtotime($poczatek_miesiaca));
    
    $wynik = $wpdb->get_results($wpdb->prepare(
        "SELECT data, COUNT(*) as wolne_sloty
         FROM $tabela 
         WHERE data BETWEEN %s AND %s 
         AND status = 'Wolny'
         AND data >= CURDATE()
         GROUP BY data
         HAVING wolne_sloty > 0
         ORDER BY data ASC",
        $poczatek_miesiaca, $koniec_miesiaca
    ), ARRAY_A);
    
    $dostepne_dni = array();
    foreach ($wynik as $wiersz) {
        $dostepne_dni[$wiersz['data']] = intval($wiersz['wolne_sloty']);
    }
    
    wp_send_json_success($dostepne_dni);
}

/**
 * AJAX: Pobiera dostępne godziny w danym dniu
 */
function srl_pobierz_dostepne_godziny() {
    if (!wp_verify_nonce($_GET['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $data = sanitize_text_field($_GET['data']);
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        wp_send_json_error('Nieprawidłowy format daty.');
        return;
    }
    
    // Sprawdź czy data nie jest z przeszłości
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

/**
 * AJAX: Tymczasowe blokowanie slotu podczas procesu rezerwacji
 */
function srl_zablokuj_slot_tymczasowo() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $termin_id = intval($_POST['termin_id']);
    $user_id = get_current_user_id();
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    // Sprawdź czy slot jest nadal wolny
    $slot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela WHERE id = %d AND status = 'Wolny'",
        $termin_id
    ), ARRAY_A);
    
    if (!$slot) {
        wp_send_json_error('Ten termin nie jest już dostępny.');
        return;
    }
    
    // Ustaw 15-minutową blokadę dla tego użytkownika
    set_transient('srl_block_' . $termin_id . '_' . $user_id, true, 15 * MINUTE_IN_SECONDS);
    
    wp_send_json_success(array(
        'slot' => $slot,
        'blokada_do' => time() + 15 * MINUTE_IN_SECONDS
    ));
}

/**
 * AJAX: Finalizuje rezerwację
 */
function srl_dokonaj_rezerwacji() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $user_id = get_current_user_id();
    $termin_id = intval($_POST['termin_id']);
    $lot_id = intval($_POST['lot_id']);
    
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Sprawdź blokadę
    if (!get_transient('srl_block_' . $termin_id . '_' . $user_id)) {
        wp_send_json_error('Sesja rezerwacji wygasła. Spróbuj ponownie.');
        return;
    }
    
    // Sprawdź czy slot jest nadal wolny
    $slot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
        $termin_id
    ), ARRAY_A);
    
    if (!$slot) {
        wp_send_json_error('Ten termin nie jest już dostępny.');
        return;
    }
    
    // Sprawdź czy lot należy do użytkownika i jest wolny
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty 
         WHERE id = %d AND user_id = %d AND status = 'wolny' AND data_waznosci >= CURDATE()",
        $lot_id, $user_id
    ), ARRAY_A);
    
    if (!$lot) {
        wp_send_json_error('Ten lot nie jest dostępny do rezerwacji.');
        return;
    }
    
		// Pobierz aktualne dane pasażera dla tej rezerwacji
		$dane_pasazera = array(
			'imie' => get_user_meta($user_id, 'srl_imie', true),
			'nazwisko' => get_user_meta($user_id, 'srl_nazwisko', true),
			'rok_urodzenia' => get_user_meta($user_id, 'srl_rok_urodzenia', true),
			'kategoria_wagowa' => get_user_meta($user_id, 'srl_kategoria_wagowa', true),
			'sprawnosc_fizyczna' => get_user_meta($user_id, 'srl_sprawnosc_fizyczna', true),
			'telefon' => get_user_meta($user_id, 'srl_telefon', true),
			'uwagi' => get_user_meta($user_id, 'srl_uwagi', true)
		);

		// Rozpocznij transakcję
		$wpdb->query('START TRANSACTION');

		try {
        // 1. Zaktualizuj slot
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
        
		// 2. Zaktualizuj lot z danymi pasażera
		$update_lot = $wpdb->update(
			$tabela_loty,
			array(
				'status' => 'zarezerwowany',
				'data_rezerwacji' => current_time('mysql'),
				'termin_id' => $termin_id,
				'dane_pasazera' => json_encode($dane_pasazera)
			),
			array('id' => $lot_id),
			array('%s', '%s', '%d', '%s'),
			array('%d')
		);
        
        if ($update_lot === false) {
            throw new Exception('Błąd aktualizacji lotu.');
        }
        
        // Zatwierdź transakcję
        $wpdb->query('COMMIT');
        
        // Usuń blokadę
        delete_transient('srl_block_' . $termin_id . '_' . $user_id);
        
        // Wyślij email potwierdzenia
        srl_wyslij_email_potwierdzenia($user_id, $slot, $lot);
        
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

/**
 * AJAX: Anuluje rezerwację klienta
 */
function srl_anuluj_rezerwacje_klient() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_frontend_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $user_id = get_current_user_id();
    $lot_id = intval($_POST['lot_id']);
    
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz szczegóły lotu
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT zl.*, t.data, t.godzina_start 
         FROM $tabela_loty zl
         LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
         WHERE zl.id = %d AND zl.user_id = %d AND zl.status = 'zarezerwowany'",
        $lot_id, $user_id
    ), ARRAY_A);
    
    if (!$lot) {
        wp_send_json_error('Nie znaleziono rezerwacji.');
        return;
    }
    
    // Sprawdź czy można anulować (48h przed lotem)
    $data_lotu = $lot['data'] . ' ' . $lot['godzina_start'];
    $czas_do_lotu = strtotime($data_lotu) - time();
    
    if ($czas_do_lotu < 48 * 3600) {
        wp_send_json_error('Nie można anulować rezerwacji na mniej niż 48h przed lotem.');
        return;
    }
    
    $termin_id = $lot['termin_id'];
    
    // Rozpocznij transakcję
    $wpdb->query('START TRANSACTION');
    
    try {
        // 1. Zwolnij slot
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
        
        // 2. Przywróć lot
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
        
        // Zatwierdź transakcję
        $wpdb->query('COMMIT');
        
        wp_send_json_success(array('message' => 'Rezerwacja została anulowana.'));
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error('Błąd podczas anulowania: ' . $e->getMessage());
    }
}

/**
 * Wysyła email potwierdzenia rezerwacji
 */
function srl_wyslij_email_potwierdzenia($user_id, $slot, $lot) {
    $user = get_userdata($user_id);
    if (!$user) return;
    
    $data_lotu = date('d.m.Y', strtotime($slot['data']));
    $godzina_lotu = substr($slot['godzina_start'], 0, 5);
    
    $subject = 'Potwierdzenie rezerwacji lotu tandemowego';
    $message = "Dzień dobry {$user->display_name},\n\n";
    $message .= "Twoja rezerwacja lotu tandemowego została potwierdzona!\n\n";
    $message .= "Szczegóły:\n";
    $message .= "📅 Data: {$data_lotu}\n";
    $message .= "⏰ Godzina: {$godzina_lotu}\n";
    $message .= "🎫 Produkt: {$lot['nazwa_produktu']}\n\n";
    $message .= "Pamiętaj:\n";
    $message .= "- Zgłoś się 30 minut przed godziną lotu\n";
    $message .= "- Weź ze sobą dokument tożsamości\n";
    $message .= "- Ubierz się stosownie do warunków pogodowych\n\n";
    $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";
    
    wp_mail($user->user_email, $subject, $message);
}


// Rejestracja AJAX handlerów dla logowania/rejestracji
add_action('wp_ajax_nopriv_srl_ajax_login', 'srl_ajax_login');
add_action('wp_ajax_nopriv_srl_ajax_register', 'srl_ajax_register');

/**
 * AJAX: Logowanie użytkownika
 */
function srl_ajax_login() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_auth_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    $username = sanitize_user($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) && $_POST['remember'];
    
    if (empty($username) || empty($password)) {
        wp_send_json_error('Wprowadź nazwę użytkownika i hasło.');
        return;
    }
    
    $user = wp_authenticate($username, $password);
    
    if (is_wp_error($user)) {
        wp_send_json_error('Nieprawidłowe dane logowania.');
        return;
    }
    
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, $remember);
    
    wp_send_json_success('Zalogowano pomyślnie!');
}

/**
 * AJAX: Rejestracja użytkownika
 */
function srl_ajax_register() {
    if (!wp_verify_nonce($_POST['nonce'], 'srl_auth_nonce')) {
        wp_send_json_error('Nieprawidłowe żądanie.');
        return;
    }
    
    // Sprawdź czy rejestracja jest włączona
    if (!get_option('users_can_register')) {
        wp_send_json_error('Rejestracja nowych użytkowników jest wyłączona.');
        return;
    }
    
    $email = sanitize_email($_POST['email']);
    $password = $_POST['password'];
    $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']);
    
    // Walidacja
    if (empty($email) || !is_email($email)) {
        wp_send_json_error('Wprowadź prawidłowy adres email.');
        return;
    }
    
    if (email_exists($email)) {
        wp_send_json_error('Użytkownik z tym adresem email już istnieje.');
        return;
    }
    
    if (strlen($password) < 6) {
        wp_send_json_error('Hasło musi mieć co najmniej 6 znaków.');
        return;
    }
    
    if (empty($first_name) || empty($last_name)) {
        wp_send_json_error('Wprowadź imię i nazwisko.');
        return;
    }
    
    // Utwórz użytkownika
    $user_id = wp_create_user($email, $password, $email);
    
    if (is_wp_error($user_id)) {
        wp_send_json_error('Błąd podczas tworzenia konta: ' . $user_id->get_error_message());
        return;
    }
    
    // Zaktualizuj dane użytkownika
    wp_update_user(array(
        'ID' => $user_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => $first_name . ' ' . $last_name
    ));
    
    // Automatyczne logowanie
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id);
    
    wp_send_json_success('Konto zostało utworzone i zalogowano automatycznie!');
}

// Rejestracja AJAX handlera dla voucherów
add_action('wp_ajax_srl_wykorzystaj_voucher', 'srl_ajax_wykorzystaj_voucher');

/**
 * AJAX: Wykorzystaj voucher upominkowy
 */
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
    
    if (strlen($kod_vouchera) !== 10) {
        wp_send_json_error('Kod vouchera musi mieć 10 znaków.');
        return;
    }
    
    // Sprawdź czy funkcja istnieje (tabela voucherów)
    if (!function_exists('srl_wykorzystaj_voucher')) {
        wp_send_json_error('Funkcja voucherów nie jest dostępna.');
        return;
    }
    
    // Wykorzystaj voucher
    $result = srl_wykorzystaj_voucher($kod_vouchera, $user_id);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['message']);
    }
}