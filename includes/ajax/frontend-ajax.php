<?php
/**
 * AJAX Handlers dla frontendu
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_srl_pobierz_dane_klienta', 'srl_pobierz_dane_klienta');
add_action('wp_ajax_srl_zapisz_dane_pasazera', 'srl_zapisz_dane_pasazera');
add_action('wp_ajax_srl_pobierz_dostepne_dni', 'srl_pobierz_dostepne_dni');
add_action('wp_ajax_srl_pobierz_dostepne_godziny', 'srl_pobierz_dostepne_godziny');
add_action('wp_ajax_srl_dokonaj_rezerwacji', 'srl_dokonaj_rezerwacji');
add_action('wp_ajax_srl_anuluj_rezerwacje_klient', 'srl_anuluj_rezerwacje_klient');
add_action('wp_ajax_srl_zablokuj_slot_tymczasowo', 'srl_zablokuj_slot_tymczasowo');
add_action('wp_ajax_nopriv_srl_ajax_login', 'srl_ajax_login');
add_action('wp_ajax_nopriv_srl_ajax_register', 'srl_ajax_register');

function srl_pobierz_dane_klienta() {
	check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
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
    
    $dane_osobowe = array(
        'imie' => get_user_meta($user_id, 'srl_imie', true),
        'nazwisko' => get_user_meta($user_id, 'srl_nazwisko', true),
        'rok_urodzenia' => get_user_meta($user_id, 'srl_rok_urodzenia', true),
        'kategoria_wagowa' => get_user_meta($user_id, 'srl_kategoria_wagowa', true),
        'sprawnosc_fizyczna' => get_user_meta($user_id, 'srl_sprawnosc_fizyczna', true),
        'telefon' => get_user_meta($user_id, 'srl_telefon', true),
        'uwagi' => get_user_meta($user_id, 'srl_uwagi', true)
    );

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

function srl_zapisz_dane_pasazera() {
	check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
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

    $walidacja = srl_waliduj_dane_pasazera($dane);
    if (!$walidacja['valid']) {
        $bledy_tekst = array();
        foreach ($walidacja['errors'] as $pole => $blad) {
            $bledy_tekst[] = $blad;
        }
        wp_send_json_error(implode(' ', $bledy_tekst));
        return;
    }
    
    foreach ($dane as $key => $value) {
        update_user_meta($user_id, 'srl_' . $key, $value);
    }
    
    wp_send_json_success(array('message' => 'Dane zostały zapisane.'));
}

function srl_pobierz_dostepne_dni() {
	check_ajax_referer('srl_frontend_nonce', 'nonce', true);
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

function srl_pobierz_dostepne_godziny() {
	check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
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

function srl_zablokuj_slot_tymczasowo() {
	check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
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

function srl_dokonaj_rezerwacji() {
	check_ajax_referer('srl_frontend_nonce', 'nonce', true);
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
    
    $dane_pasazera = array(
        'imie' => get_user_meta($user_id, 'srl_imie', true),
        'nazwisko' => get_user_meta($user_id, 'srl_nazwisko', true),
        'rok_urodzenia' => get_user_meta($user_id, 'srl_rok_urodzenia', true),
        'kategoria_wagowa' => get_user_meta($user_id, 'srl_kategoria_wagowa', true),
        'sprawnosc_fizyczna' => get_user_meta($user_id, 'srl_sprawnosc_fizyczna', true),
        'telefon' => get_user_meta($user_id, 'srl_telefon', true),
        'uwagi' => get_user_meta($user_id, 'srl_uwagi', true)
    );

    $wpdb->query('START TRANSACTION');

    try {
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
        
        $wpdb->query('COMMIT');
        
        delete_transient('srl_block_' . $termin_id . '_' . $user_id);
        
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

function srl_anuluj_rezerwacje_klient() {
	check_ajax_referer('srl_frontend_nonce', 'nonce', true);
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
        return;
    }
    
    $user_id = get_current_user_id();
    $lot_id = intval($_POST['lot_id']);
    
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
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
    
    $data_lotu = $lot['data'] . ' ' . $lot['godzina_start'];
    $czas_do_lotu = strtotime($data_lotu) - time();
    
    if ($czas_do_lotu < 48 * 3600) {
        wp_send_json_error('Nie można anulować rezerwacji na mniej niż 48h przed lotem.');
        return;
    }
    
    $termin_id = $lot['termin_id'];
    
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
        
        $wpdb->query('COMMIT');
        wp_send_json_success(array('message' => 'Rezerwacja została anulowana.'));
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error('Błąd podczas anulowania: ' . $e->getMessage());
    }
}




function srl_ajax_login() {
   
    // Sprawdź czy nie za dużo prób logowania
    $ip = $_SERVER['REMOTE_ADDR'];
    $attempts_key = 'login_attempts_' . md5($ip);
    $attempts = get_transient($attempts_key) ?: 0;
    
    if ($attempts >= 5) {
        wp_send_json_error('Za dużo nieudanych prób logowania. Spróbuj ponownie za 15 minut.');
        return;
    }
    
    // Walidacja danych
    if (empty($_POST['username']) || empty($_POST['password'])) {
        wp_send_json_error('Wprowadź nazwę użytkownika i hasło.');
        return;
    }
    
    $username = sanitize_user(wp_unslash($_POST['username']));
    $password = wp_unslash($_POST['password']); // Nie sanityzuj hasła!
    $remember = filter_var($_POST['remember'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Próba logowania
    $user = wp_authenticate($username, $password);
    
    if (is_wp_error($user)) {
        // Zwiększ licznik nieudanych prób
        set_transient($attempts_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
        wp_send_json_error('Nieprawidłowe dane logowania.');
        return;
    }
    
    // Wyczyść licznik po udanym logowaniu
    delete_transient($attempts_key);
    
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, $remember);
    
    wp_send_json_success('Zalogowano pomyślnie!');
}

function srl_ajax_register() {
    
    // Sprawdź czy rejestracja jest włączona
    if (!get_option('users_can_register')) {
        wp_send_json_error('Rejestracja nowych użytkowników jest wyłączona.');
        return;
    }
    
    // Rate limiting dla rejestracji
    $ip = $_SERVER['REMOTE_ADDR'];
    $register_key = 'register_attempts_' . md5($ip);
    $attempts = get_transient($register_key) ?: 0;
    
    if ($attempts >= 3) {
        wp_send_json_error('Za dużo prób rejestracji. Spróbuj ponownie za 30 minut.');
        return;
    }
    
    // Walidacja i sanityzacja danych
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $password = wp_unslash($_POST['password'] ?? ''); // Nie sanityzuj hasła!
    $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
    $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
    
    // Walidacja
    if (empty($email) || !is_email($email)) {
        wp_send_json_error('Wprowadź prawidłowy adres email.');
        return;
    }
    
    if (email_exists($email)) {
        // Zwiększ licznik prób (ochrona przed brute force)
        set_transient($register_key, $attempts + 1, 30 * MINUTE_IN_SECONDS);
        wp_send_json_error('Użytkownik z tym adresem email już istnieje.');
        return;
    }
    
    if (strlen($password) < 8) { // Zwiększ wymagania
        wp_send_json_error('Hasło musi mieć co najmniej 8 znaków.');
        return;
    }
    
    if (empty($first_name) || empty($last_name)) {
        wp_send_json_error('Wprowadź imię i nazwisko.');
        return;
    }
    
    // Dodatkowa walidacja hasła
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
        wp_send_json_error('Hasło musi zawierać małą literę, wielką literę i cyfrę.');
        return;
    }
    
    // Tworzenie użytkownika
    $user_id = wp_create_user($email, $password, $email);
    
    if (is_wp_error($user_id)) {
        // Zwiększ licznik przy błędzie
        set_transient($register_key, $attempts + 1, 30 * MINUTE_IN_SECONDS);
        wp_send_json_error('Błąd podczas tworzenia konta: ' . $user_id->get_error_message());
        return;
    }
    
    // Aktualizuj dane użytkownika
    wp_update_user(array(
        'ID' => $user_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => $first_name . ' ' . $last_name
    ));
    
    // Automatyczne logowanie
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id);
    
    // Wyczyść licznik po udanej rejestracji
    delete_transient($register_key);
    
    wp_send_json_success('Konto zostało utworzone i zalogowano automatycznie!');
}