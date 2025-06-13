<?php
// Rejestracja stron w panelu administracyjnym
add_action('admin_menu', 'srl_dodaj_strony_admin');

function srl_dodaj_strony_admin() {
    add_menu_page(
        'Rezerwacje lotów tandemowych',
        'Rezerwacje Tandem',
        'manage_options',
        'srl-dashboard',
        'srl_wyswietl_dashboard',
        'dashicons-calendar',
        56
    );

    add_submenu_page(
        'srl-dashboard',
        'Kalendarz',
        'Kalendarz',
        'manage_options',
        'srl-kalendarz',
        'srl_wyswietl_kalendarz'
    );

    add_submenu_page(
        'srl-dashboard',
        'Planowanie dni',
        'Dzień tygodnia',
        'manage_options',
        'srl-dzien',
        'srl_wyswietl_dzien'
    );
    
    add_submenu_page(
        'srl-dashboard',
        'Wykupione loty',
        'Wykupione loty',
        'manage_options',
        'srl-wykupione-loty',
        'srl_wyswietl_wykupione_loty'
    );
    
    add_submenu_page(
        'srl-dashboard',
        'Synchronizacja',
        'Synchronizacja',
        'manage_options',
        'srl-sync-flights',
        'srl_wyswietl_synchronizacje'
    );

    add_submenu_page(
        'srl-dashboard',
        'Vouchery oczekujące',
        'Oczekujące na potwierdzenie',
        'manage_options',
        'srl-voucher',
        'srl_wyswietl_vouchery'
    );
	

	if (function_exists('srl_voucher_table_exists') && srl_voucher_table_exists()) {
		add_submenu_page(
			'srl-dashboard',
			'Zakupione Vouchery',
			'Zakupione Vouchery',
			'manage_options',
			'srl-zakupione-vouchery',
			'srl_wyswietl_zakupione_vouchery'
		);
	}

}

function srl_wyswietl_dashboard() {
    echo '<div class="wrap">';
    echo '<h1>System rezerwacji lotów tandemowych</h1>';
    echo '<div class="card" style="max-width: 800px;">';
    echo '<h2>🎫 Witaj w systemie rezerwacji!</h2>';
    echo '<p>System umożliwia kompleksowe zarządzanie rezerwacjami lotów tandemowych.</p>';
    echo '<h3>Dostępne funkcje:</h3>';
    echo '<ul>';
    echo '<li><strong>Kalendarz</strong> - przegląd wszystkich terminów i rezerwacji</li>';
    echo '<li><strong>Dzień tygodnia</strong> - szczegółowe planowanie godzin lotów</li>';
    echo '<li><strong>Wykupione loty</strong> - zarządzanie wszystkimi zakupionymi lotami</li>';
    echo '<li><strong>Synchronizacja</strong> - synchronizacja lotów z zamówieniami WooCommerce</li>';
    echo '<li><strong>Vouchery</strong> - zatwierdzanie voucherów</li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';
}

// Rejestracja skryptów i styli w panelu admina
add_action('admin_enqueue_scripts', 'srl_enqueue_admin_scripts');
function srl_enqueue_admin_scripts($hook) {
    // Jeżeli to jest KALENDARZ
    if (isset($_GET['page']) && $_GET['page'] === 'srl-kalendarz') {
        wp_enqueue_script('srl-admin-calendar', SRL_PLUGIN_URL . 'assets/js/admin-calendar.js', array('jquery'), '1.0', true);
        wp_enqueue_style('srl-admin-style', SRL_PLUGIN_URL . 'assets/css/style.css');
    }

    // Jeżeli to jest DZIEŃ TYGODNIA
if (isset($_GET['page']) && $_GET['page'] === 'srl-dzien') {
    wp_enqueue_script('srl-admin-day', SRL_PLUGIN_URL . 'assets/js/admin-day.js', array('jquery'), '1.1', true);
    
    // Pobierz datę z parametru GET lub domyślnie dzisiaj
    $data = isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data']) 
            ? sanitize_text_field($_GET['data']) 
            : date('Y-m-d');
    
    // Pobierz istniejące godziny dla tej daty - UŻYJ TEJ SAMEJ LOGIKI CO W AJAX
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    $istniejace_godziny = $wpdb->get_results($wpdb->prepare(
        "SELECT t.id, t.pilot_id, t.godzina_start, t.godzina_koniec, t.status, t.klient_id, t.notatka,
                zl.id as lot_id, zl.user_id as lot_user_id, zl.dane_pasazera
           FROM $tabela t
           LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
          WHERE t.data = %s
          ORDER BY t.pilot_id ASC, t.godzina_start ASC",
        $data
    ), ARRAY_A);
    
    // Grupuj wg pilota - UŻYJ TEJ SAMEJ LOGIKI CO W srl_zwroc_godziny_wg_pilota
    $godziny_wg_pilota = array();
    foreach ($istniejace_godziny as $wiersz) {
        $pid = intval($wiersz['pilot_id']);
        if (!isset($godziny_wg_pilota[$pid])) {
            $godziny_wg_pilota[$pid] = array();
        }
        
        // Pobierz dane klienta jeśli zarezerwowane - IDENTYCZNA LOGIKA
        $klient_nazwa = '';
        $link_zamowienia = '';
        $lot_id = null;
        $dane_pasazera_cache = null;
        
        if (($wiersz['status'] === 'Zarezerwowany' || $wiersz['status'] === 'Zrealizowany') && intval($wiersz['klient_id']) > 0) {
            $user = get_userdata(intval($wiersz['klient_id']));
            if ($user) {
                // Pobierz imię i nazwisko z meta danych użytkownika
                $imie = get_user_meta(intval($wiersz['klient_id']), 'srl_imie', true);
                $nazwisko = get_user_meta(intval($wiersz['klient_id']), 'srl_nazwisko', true);
                
                if ($imie && $nazwisko) {
                    $klient_nazwa = $imie . ' ' . $nazwisko;
                } else {
                    // Fallback na display_name jeśli brak danych w meta
                    $klient_nazwa = $user->display_name;
                }
                $link_zamowienia = admin_url('edit.php?post_type=shop_order&customer=' . intval($wiersz['klient_id']));
                
                // Znajdź lot_id dla tego slotu
                $lot = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d AND status IN ('zarezerwowany', 'zrealizowany')",
                    $wiersz['id']
                ));
                $lot_id = $lot ? intval($lot) : null;
                
                // NOWE: Pobierz pełne dane pasażera dla harmonogramu
                $dane_pasazera_cache = array();
                
                // Sprawdź czy są dane w kolumnie dane_pasazera lotu
                if (!empty($wiersz['dane_pasazera'])) {
                    $dane_z_lotu = json_decode($wiersz['dane_pasazera'], true);
                    if ($dane_z_lotu && is_array($dane_z_lotu)) {
                        $dane_pasazera_cache = $dane_z_lotu;
                    }
                }
                
                // Jeśli brak danych w locie, pobierz z profilu użytkownika
                if (empty($dane_pasazera_cache['imie'])) {
                    $dane_pasazera_cache = array(
                        'imie' => $imie,
                        'nazwisko' => $nazwisko,
                        'rok_urodzenia' => get_user_meta(intval($wiersz['klient_id']), 'srl_rok_urodzenia', true),
                        'telefon' => get_user_meta(intval($wiersz['klient_id']), 'srl_telefon', true),
                        'kategoria_wagowa' => get_user_meta(intval($wiersz['klient_id']), 'srl_kategoria_wagowa', true),
                        'sprawnosc_fizyczna' => get_user_meta(intval($wiersz['klient_id']), 'srl_sprawnosc_fizyczna', true),
                        'uwagi' => get_user_meta(intval($wiersz['klient_id']), 'srl_uwagi', true)
                    );
                }
            }
        } elseif ($wiersz['status'] === 'Prywatny' && !empty($wiersz['notatka'])) {
            // Dla lotów prywatnych pobierz dane z notatki
            $dane_prywatne = json_decode($wiersz['notatka'], true);
            if ($dane_prywatne && isset($dane_prywatne['imie']) && isset($dane_prywatne['nazwisko'])) {
                $klient_nazwa = $dane_prywatne['imie'] . ' ' . $dane_prywatne['nazwisko'];
            }
        }
        
        $godziny_wg_pilota[$pid][] = array(
            'id' => intval($wiersz['id']),
            'start' => substr($wiersz['godzina_start'], 0, 5),
            'koniec' => substr($wiersz['godzina_koniec'], 0, 5),
            'status' => $wiersz['status'],
            'klient_id' => intval($wiersz['klient_id']),
            'klient_nazwa' => $klient_nazwa,
            'link_zamowienia' => $link_zamowienia,
            'lot_id' => $lot_id,
            'notatka' => $wiersz['notatka'],
            'dane_pasazera_cache' => $dane_pasazera_cache // DODANE: pełne dane dla harmonogramu
        );
    }
    
    // Określ domyślną liczbę pilotów
    $domyslna_liczba = 1;
    if (!empty($godziny_wg_pilota)) {
        $max_pid = max(array_keys($godziny_wg_pilota));
        $domyslna_liczba = max(1, min(4, $max_pid));
    }
    
    // Przekaż wszystkie dane do JS w jednym obiekcie
    wp_localize_script('srl-admin-day', 'srlAdmin', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'adminUrl' => admin_url(),
        'nonce' => wp_create_nonce('srl_admin_nonce'),
        'data' => $data,
        'istniejaceGodziny' => $godziny_wg_pilota,
        'domyslnaLiczbaPilotow' => $domyslna_liczba
    ));
    
    wp_enqueue_style('srl-admin-style', SRL_PLUGIN_URL . 'assets/css/style.css');
}

    // Jeżeli to są WYKUPIONE LOTY lub SYNCHRONIZACJA
    if (isset($_GET['page']) && in_array($_GET['page'], ['srl-wykupione-loty', 'srl-sync-flights'])) {
        wp_enqueue_style('srl-admin-style', SRL_PLUGIN_URL . 'assets/css/style.css');
    }
	
	// Dla stron WooCommerce My Account (konto klienta)
	global $wp;
	if (isset($wp->query_vars['srl-moje-loty']) || isset($wp->query_vars['srl-informacje-o-mnie'])) {
		wp_enqueue_script('srl-flight-options', SRL_PLUGIN_URL . 'assets/js/flight-options-unified.js', array('jquery'), '1.0', true);
		wp_localize_script('srl-flight-options', 'srlFrontend', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('srl_frontend_nonce')
		));
	}
	
    // Jeżeli to są VOUCHERY
    if (isset($_GET['page']) && $_GET['page'] === 'srl-voucher') {
        wp_enqueue_style('srl-admin-style', SRL_PLUGIN_URL . 'assets/css/style.css');
    }
	
	// Jeżeli to są ZAKUPIONE VOUCHERY
	if (isset($_GET['page']) && $_GET['page'] === 'srl-zakupione-vouchery') {
		wp_enqueue_style('srl-admin-style', SRL_PLUGIN_URL . 'assets/css/style.css');
	}
	
	// Dodaj domyślne dane dla wszystkich stron admina
	if (!isset($_GET['page']) || $_GET['page'] !== 'srl-dzien') {
		// Dla innych stron podaj podstawowe dane
		wp_localize_script('srl-admin-day', 'srlAdmin', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'adminUrl' => admin_url(),
			'nonce' => wp_create_nonce('srl_admin_nonce'),
			'data' => date('Y-m-d'),
			'istniejaceGodziny' => array(),
			'domyslnaLiczbaPilotow' => 1
		));
	}
	
}

// Enqueue skryptów dla frontendu
add_action('wp_enqueue_scripts', 'srl_enqueue_frontend_scripts');
function srl_enqueue_frontend_scripts() {
    // Sprawdź czy jesteśmy na stronie z shortcode lub stronie rezerwuj-lot
    global $post;
    
    $should_load = false;
    
    // Sprawdź czy to strona rezerwuj-lot
    if (is_page('rezerwuj-lot')) {
        $should_load = true;
    }
    
    // Sprawdź czy shortcode jest używany na stronie
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'srl_kalendarz')) {
        $should_load = true;
    }
    
    if ($should_load) {
        wp_enqueue_script('srl-frontend-calendar', SRL_PLUGIN_URL . 'assets/js/frontend-calendar.js', array('jquery'), '1.0', true);
		wp_enqueue_script('srl-flight-options', SRL_PLUGIN_URL . 'assets/js/flight-options-unified.js', array('jquery'), '1.0', true);
		wp_enqueue_style('srl-frontend-style', SRL_PLUGIN_URL . 'assets/css/frontend-style.css', array(), '1.0');
        
        // Przekaż dane do JS
        wp_localize_script('srl-frontend-calendar', 'srlFrontend', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('srl_frontend_nonce'),
            'user_id' => get_current_user_id()
        ));
    }
}