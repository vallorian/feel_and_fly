<?php
/**
 * AJAX Handlers dla panelu administracyjnego
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_srl_dodaj_godzine', 'srl_dodaj_godzine');
add_action('wp_ajax_srl_zmien_slot', 'srl_zmien_slot');
add_action('wp_ajax_srl_usun_godzine', 'srl_usun_godzine');
add_action('wp_ajax_srl_zmien_status_godziny', 'srl_zmien_status_godziny');
add_action('wp_ajax_srl_anuluj_lot_przez_organizatora', 'srl_anuluj_lot_przez_organizatora');
add_action('wp_ajax_srl_wyszukaj_klientow_loty', 'srl_wyszukaj_klientow_loty');
add_action('wp_ajax_srl_dodaj_voucher_recznie', 'srl_ajax_dodaj_voucher_recznie');
add_action('wp_ajax_srl_wyszukaj_dostepnych_klientow', 'srl_ajax_wyszukaj_dostepnych_klientow');
add_action('wp_ajax_srl_przypisz_klienta_do_slotu', 'srl_ajax_przypisz_klienta_do_slotu');
add_action('wp_ajax_srl_zapisz_dane_prywatne', 'srl_ajax_zapisz_dane_prywatne');
add_action('wp_ajax_srl_pobierz_dane_prywatne', 'srl_ajax_pobierz_dane_prywatne');
add_action('wp_ajax_srl_pobierz_aktualne_godziny', 'srl_ajax_pobierz_aktualne_godziny');
add_action('wp_ajax_srl_wyszukaj_wolne_loty', 'srl_ajax_wyszukaj_wolne_loty');
add_action('wp_ajax_srl_przypisz_wykupiony_lot', 'srl_ajax_przypisz_wykupiony_lot');
add_action('wp_ajax_srl_zapisz_lot_prywatny', 'srl_ajax_zapisz_lot_prywatny');
add_action('wp_ajax_srl_pobierz_historie_lotu', 'srl_ajax_pobierz_historie_lotu');
add_action('wp_ajax_srl_przywroc_rezerwacje', 'srl_ajax_przywroc_rezerwacje');
add_action('wp_ajax_srl_pobierz_dane_odwolanego', 'srl_ajax_pobierz_dane_odwolanego');
add_action('wp_ajax_srl_zrealizuj_lot', 'srl_ajax_zrealizuj_lot');
add_action('wp_ajax_srl_zrealizuj_lot_prywatny', 'srl_ajax_zrealizuj_lot_prywatny');

function srl_dodaj_godzine() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }

    if (!isset($_POST['data']) || !isset($_POST['pilot_id'])) {
        wp_send_json_error('Nieprawidłowe dane.');
        return;
    }

    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';

    $data = sanitize_text_field($_POST['data']);
    $pilot_id = intval($_POST['pilot_id']);
    $godzStart = sanitize_text_field($_POST['godzina_start']) . ':00';
    $godzKoniec = sanitize_text_field($_POST['godzina_koniec']) . ':00';
    $status = sanitize_text_field($_POST['status']);
    $klient_id = 0;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        wp_send_json_error('Nieprawidłowy format daty.');
        return;
    }

    if ($pilot_id < 1 || $pilot_id > 4) {
        wp_send_json_error('Nieprawidłowy ID pilota.');
        return;
    }

    $result = $wpdb->insert(
        $tabela,
        array(
            'data' => $data,
            'pilot_id' => $pilot_id,
            'godzina_start' => $godzStart,
            'godzina_koniec' => $godzKoniec,
            'status' => $status,
            'klient_id' => $klient_id
        ),
        array('%s','%d','%s','%s','%s','%d')
    );
    
    if ($result === false) {
        wp_send_json_error('Nie udało się zapisać slotu: ' . $wpdb->last_error);
        return;
    }
    
    srl_zwroc_godziny_wg_pilota($data);
}

function srl_zmien_slot() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }

    if (!isset($_POST['termin_id'])) {
        wp_send_json_error('Nieprawidłowe dane.');
        return;
    }

    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    $termin_id = intval($_POST['termin_id']);
    $data = sanitize_text_field($_POST['data']);
    $godzStart = sanitize_text_field($_POST['godzina_start']) . ':00';
    $godzKoniec = sanitize_text_field($_POST['godzina_koniec']) . ':00';
    $status = sanitize_text_field($_POST['status']);
    $klient_id = isset($_POST['klient_id']) ? intval($_POST['klient_id']) : 0;

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tabela WHERE id = %d",
        $termin_id
    ));

    if (!$existing) {
        wp_send_json_error('Slot nie istnieje.');
        return;
    }

    $dane = array(
        'godzina_start' => $godzStart,
        'godzina_koniec' => $godzKoniec,
        'status' => $status,
        'klient_id' => $klient_id ? $klient_id : null
    );
    
    $wynik = $wpdb->update(
        $tabela,
        $dane,
        array('id' => $termin_id),
        array('%s','%s','%s','%d'),
        array('%d')
    );
    
    if ($wynik === false) {
        wp_send_json_error('Błąd aktualizacji w bazie: ' . $wpdb->last_error);
        return;
    }
    
    srl_zwroc_godziny_wg_pilota($data);
}

function srl_usun_godzine() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }

    if (!isset($_POST['termin_id'])) {
        wp_send_json_error('Nieprawidłowe dane.');
        return;
    }

    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    $termin_id = intval($_POST['termin_id']);

    $slot = $wpdb->get_row($wpdb->prepare(
        "SELECT data, status, klient_id FROM $tabela WHERE id = %d",
        $termin_id
    ), ARRAY_A);
    
    if (!$slot) {
        wp_send_json_error('Slot nie istnieje.');
        return;
    }

    if ($slot['status'] === 'Zarezerwowany' && intval($slot['klient_id']) > 0) {
        wp_send_json_error('Nie można usunąć zarezerwowanego slotu. Najpierw wypisz klienta.');
        return;
    }

    $data = $slot['data'];

    $usun = $wpdb->delete(
        $tabela, 
        array('id' => $termin_id), 
        array('%d')
    );
    
    if ($usun === false) {
        wp_send_json_error('Błąd usuwania z bazy: ' . $wpdb->last_error);
        return;
    }

    if ($usun === 0) {
        wp_send_json_error('Slot nie został usunięty (może już nie istnieje).');
        return;
    }
    
    srl_zwroc_godziny_wg_pilota($data);
}

function srl_zmien_status_godziny() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }

    if (!isset($_POST['termin_id']) || !isset($_POST['status'])) {
        wp_send_json_error('Nieprawidłowe dane.');
        return;
    }

    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $termin_id = intval($_POST['termin_id']);
    $status = sanitize_text_field($_POST['status']);
    $klient_id = isset($_POST['klient_id']) ? intval($_POST['klient_id']) : 0;

    if ($status === 'Odwołany przez organizatora') {
        srl_anuluj_lot_przez_organizatora();
        return;
    }

    $slot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_terminy WHERE id = %d",
        $termin_id
    ), ARRAY_A);

    if (!$slot) {
        wp_send_json_error('Slot nie istnieje.');
        return;
    }

    $dozwolone_statusy = ['Wolny', 'Prywatny', 'Zarezerwowany', 'Zrealizowany', 'Odwołany przez organizatora'];
    if (!in_array($status, $dozwolone_statusy)) {
        wp_send_json_error('Nieprawidłowy status.');
        return;
    }

    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty WHERE termin_id = %d",
        $termin_id
    ), ARRAY_A);

    $wpdb->query('START TRANSACTION');

    try {
        $dane_slotu = array(
            'status' => $status,
            'klient_id' => $klient_id ? $klient_id : null
        );
        
		// Jeśli zmieniamy na Wolny, wyczyść notatką
		if ($status === 'Wolny') {
			$dane_slotu['notatka'] = null;
		}
		
        $wynik = $wpdb->update(
            $tabela_terminy,
            $dane_slotu,
            array('id' => $termin_id),
            array('%s','%d'),
            array('%d')
        );
        
        if ($wynik === false) {
            throw new Exception('Błąd aktualizacji statusu slotu');
        }

        if ($lot) {
            $nowy_status_lotu = '';
            $dane_lotu_update = array();
            $opis_zmiany = '';
            $stary_status = $lot['status'];
            
            switch ($status) {
                case 'Wolny':
                    $nowy_status_lotu = 'wolny';
                    $dane_lotu_update = array(
                        'status' => $nowy_status_lotu,
                        'termin_id' => null,
                        'data_rezerwacji' => null
                    );
                    $opis_zmiany = 'Status zmieniony na WOLNY przez ADMINA - zwolniono rezerwację';
                    break;
                    
                case 'Zarezerwowany':
                    $nowy_status_lotu = 'zarezerwowany';
                    $dane_lotu_update = array('status' => $nowy_status_lotu);
                    $opis_zmiany = 'Status zmieniony na ZAREZERWOWANY przez ADMINA';
                    break;
                    
                case 'Zrealizowany':
                    $nowy_status_lotu = 'zrealizowany';
                    $dane_lotu_update = array('status' => $nowy_status_lotu);
                    $opis_zmiany = 'Lot oznaczony jako ZREALIZOWANY przez ADMINA';
                    break;
                    
                case 'Prywatny':
                    // Dla slotów prywatnych nie zmieniamy statusu lotu
                    $opis_zmiany = 'Slot oznaczony jako PRYWATNY przez ADMINA';
                    break;
                    
                case 'Odwołany przez organizatora':
                    $nowy_status_lotu = 'wolny';
                    $dane_lotu_update = array(
                        'status' => $nowy_status_lotu,
                        'termin_id' => null,
                        'data_rezerwacji' => null
                    );
                    $opis_zmiany = 'Lot ODOWŁANY przez organizatora';
                    break;
            }
            
            // Aktualizuj lot jeśli potrzeba
            if (!empty($dane_lotu_update)) {
                $update_result = $wpdb->update(
                    $tabela_loty,
                    $dane_lotu_update,
                    array('id' => $lot['id']),
                    array_fill(0, count($dane_lotu_update), '%s'),
                    array('%d')
                );
                
                if ($update_result === false) {
                    throw new Exception('Błąd aktualizacji statusu lotu');
                }
            }
            
            // ZAWSZE dopisz wpis do historii jeśli była zmiana
            if (!empty($opis_zmiany)) {
                $szczegoly = array(
                    'termin_id' => $termin_id,
                    'stary_status_slotu' => $slot['status'],
                    'nowy_status_slotu' => $status,
                    'zmiana_przez_admin' => true
                );
                
                // Dodaj informacje o zmianie statusu lotu jeśli nastąpiła
                if (!empty($nowy_status_lotu) && $stary_status !== $nowy_status_lotu) {
                    $szczegoly['stary_status_lotu'] = $stary_status;
                    $szczegoly['nowy_status_lotu'] = $nowy_status_lotu;
                    $szczegoly['zmiana_statusu'] = $stary_status . ' → ' . $nowy_status_lotu;
                    $opis_zmiany .= " (status lotu: {$stary_status} → {$nowy_status_lotu})";
                }
                
                $wpis_historii = array(
                    'data' => srl_get_current_datetime(),
                    'opis' => $opis_zmiany,
                    'typ' => 'zmiana_statusu_admin',
                    'executor' => 'Admin',
                    'szczegoly' => $szczegoly
                );
                
                srl_dopisz_do_historii_lotu($lot['id'], $wpis_historii);
            }
        }

        $wpdb->query('COMMIT');
        srl_zwroc_godziny_wg_pilota($slot['data']);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error($e->getMessage());
    }
}

/**
 * POPRAWKA dla funkcji srl_anuluj_lot_przez_organizatora
 */
function srl_anuluj_lot_przez_organizatora() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }

    if (!isset($_POST['termin_id'])) {
        wp_send_json_error('Nieprawidłowe dane.');
        return;
    }

    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $termin_id = intval($_POST['termin_id']);

    // Pobierz szczegóły slotu PRZED zmianami
    $slot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_terminy WHERE id = %d",
        $termin_id
    ), ARRAY_A);
    
    if (!$slot) {
        wp_send_json_error('Slot nie istnieje.');
        return;
    }
    
    $data = $slot['data'];
    $klient_id = intval($slot['klient_id']);

    // Znajdź powiązany lot PRZED zmianą
    $lot = null;
    if ($klient_id > 0) {
        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_loty WHERE termin_id = %d AND user_id = %d",
            $termin_id, $klient_id
        ), ARRAY_A);
    }

    $wpdb->query('START TRANSACTION');
    
    try {
        // Przygotuj dane historyczne do zapisania w notatce slotu
        $dane_historyczne = array(
            'typ' => 'odwolany_przez_organizatora',
            'data_odwolania' => srl_get_current_datetime(),
            'oryginalny_status' => $slot['status'],
            'klient_id' => $klient_id
        );

        // Jeśli był klient przypisany, zapisz jego dane
        if ($klient_id > 0) {
            $user = get_userdata($klient_id);
            if ($user) {
                $dane_historyczne['klient_email'] = $user->user_email;
                $dane_historyczne['klient_nazwa'] = $user->display_name;
                
                // Pobierz dane pasażera
                $imie = get_user_meta($klient_id, 'srl_imie', true);
                $nazwisko = get_user_meta($klient_id, 'srl_nazwisko', true);
                if ($imie && $nazwisko) {
                    $dane_historyczne['klient_nazwa'] = $imie . ' ' . $nazwisko;
                }
                
                $dane_historyczne['telefon'] = get_user_meta($klient_id, 'srl_telefon', true);
                $dane_historyczne['rok_urodzenia'] = get_user_meta($klient_id, 'srl_rok_urodzenia', true);
                $dane_historyczne['kategoria_wagowa'] = get_user_meta($klient_id, 'srl_kategoria_wagowa', true);
                $dane_historyczne['sprawnosc_fizyczna'] = get_user_meta($klient_id, 'srl_sprawnosc_fizyczna', true);
                $dane_historyczne['uwagi'] = get_user_meta($klient_id, 'srl_uwagi', true);
            }
            
            // Jeśli był lot, zapisz jego dane
            if ($lot) {
                $dane_historyczne['lot_id'] = $lot['id'];
                $dane_historyczne['order_id'] = $lot['order_id'];
                $dane_historyczne['nazwa_produktu'] = $lot['nazwa_produktu'];
                $dane_historyczne['data_rezerwacji'] = $lot['data_rezerwacji'];
                $dane_historyczne['dane_pasazera'] = $lot['dane_pasazera']; // Zachowaj pełne dane
            }
        }

        // 1. ZAKTUALIZUJ SLOT - ustaw status "Odwołany przez organizatora" ale zachowaj klient_id i dodaj notatką
        $result = $wpdb->update(
            $tabela_terminy,
            array(
                'status' => 'Odwołany przez organizatora',
                'notatka' => json_encode($dane_historyczne)
                // NIE zmieniamy klient_id - zachowujemy dla historii i ID lotu
            ),
            array('id' => $termin_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            throw new Exception('Błąd aktualizacji slotu.');
        }

        // 2. JEŚLI BYŁ LOT - zwolnij go do ponownej rezerwacji, ale ZACHOWAJ status lotu jako "odwołany_tymczasowo"
        if ($lot) {
            $stary_status = $lot['status'];
            $szczegoly_terminu = $slot['data'] . ' ' . substr($slot['godzina_start'], 0, 5) . '-' . substr($slot['godzina_koniec'], 0, 5);
            
            // Zwolnij lot, ale oznacz specjalnie
            $wpdb->update(
                $tabela_loty,
                array(
                    'status' => 'wolny', 
                    'termin_id' => null, 
                    'data_rezerwacji' => null
                ),
                array('id' => $lot['id']),
                array('%s', '%d', '%s'),
                array('%d')
            );
            
            // Wyślij email do klienta
            $user = get_userdata($klient_id);
            if ($user) {
                $to = $user->user_email;
                $subject = 'Twój lot tandemowy został odwołany przez organizatora';
                $body = "Dzień dobry {$user->display_name},\n\n"
                     . "Niestety Twój lot, który był zaplanowany na {$szczegoly_terminu}, został odwołany przez organizatora z powodów niezależnych od nas (prawdopodobnie warunki pogodowe).\n\n"
                     . "Status Twojego lotu został przywrócony – możesz ponownie wybrać inny termin.\n"
                     . "Przepraszamy za niedogodności.\n\n"
                     . "Pozdrawiamy,\nZespół Loty Tandemowe";
                wp_mail($to, $subject, $body);
                
                // DOPISZ do historii lotu
                $opis_zmiany = "Lot odwołany przez organizatora - termin {$szczegoly_terminu} zachowany w historii, lot dostępny do ponownej rezerwacji";
                
                $wpis_historii = array(
                    'data' => srl_get_current_datetime(),
                    'opis' => $opis_zmiany,
                    'typ' => 'odwolanie_przez_organizatora',
                    'executor' => 'Admin',
                    'szczegoly' => array(
                        'termin_id' => $termin_id,
                        'odwolany_termin' => $szczegoly_terminu,
                        'stary_status' => $stary_status,
                        'nowy_status' => 'wolny',
                        'klient_id' => $klient_id,
                        'email_wyslany' => true,
                        'slot_zachowany' => true,
                        'dane_zachowane_w_slocie' => true,
                        'powod' => 'Odwołanie przez organizatora - możliwe przywrócenie'
                    )
                );
                
                srl_dopisz_do_historii_lotu($lot['id'], $wpis_historii);
            }
        }
        
        $wpdb->query('COMMIT');
        srl_zwroc_godziny_wg_pilota($data);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error('Błąd podczas odwoływania: ' . $e->getMessage());
    }
}

function srl_wyszukaj_klientow_loty() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true); //było fronted
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    } 

    if (!isset($_GET['q'])) {
        wp_send_json_error('Brak frazy wyszukiwania.');
        return;
    }

    $search = sanitize_text_field($_GET['q']);
    
    if (strlen($search) < 2) {
        wp_send_json_success(array());
        return;
    }

    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT u.ID, u.display_name, u.user_login 
         FROM {$wpdb->users} u
         INNER JOIN $tabela_loty zl ON u.ID = zl.user_id
         WHERE zl.status = 'wolny' 
         AND zl.data_waznosci >= CURDATE()
         AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)
         ORDER BY u.display_name
         LIMIT 10",
        '%' . $search . '%',
        '%' . $search . '%', 
        '%' . $search . '%'
    ));
    
    $wynik = array();
    foreach ($results as $user) {
        $wynik[] = array(
            'id' => $user->ID,
            'nazwa' => $user->display_name . ' (' . $user->user_login . ')'
        );
    }
    
    wp_send_json_success($wynik);
}

function srl_ajax_dodaj_voucher_recznie() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Brak uprawnień.');
		return;
	}
    
    $kod_vouchera = sanitize_text_field($_POST['kod_vouchera']);
    $data_waznosci = sanitize_text_field($_POST['data_waznosci']);
    
    if (empty($kod_vouchera) || empty($data_waznosci)) {
        wp_send_json_error('Wypełnij wszystkie pola.');
    }
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
    
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tabela WHERE kod_vouchera = %s",
        $kod_vouchera
    ));
    
    if ($exists > 0) {
        wp_send_json_error('Voucher o tym kodzie już istnieje.');
    }
    
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);
    
    $result = $wpdb->insert(
        $tabela,
        array(
            'order_item_id' => 0,
            'order_id' => 0,
            'buyer_user_id' => $user_id,
            'buyer_imie' => $user->first_name ?: 'Admin',
            'buyer_nazwisko' => $user->last_name ?: 'Manual',
            'nazwa_produktu' => 'Voucher dodany ręcznie',
            'kod_vouchera' => $kod_vouchera,
            'status' => 'do_wykorzystania',
            'data_zakupu' => srl_get_current_datetime(),
            'data_waznosci' => $data_waznosci
        ),
        array('%d','%d','%d','%s','%s','%s','%s','%s','%s','%s')
    );
    
    if ($result !== false) {
        wp_send_json_success('Voucher został dodany.');
    } else {
        wp_send_json_error('Błąd zapisu do bazy danych.');
    }
}

function srl_ajax_wyszukaj_dostepnych_klientow() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true); //było fronted
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    } 
    
    $query = sanitize_text_field($_POST['query']);
    
    if (strlen($query) < 2) {
        wp_send_json_success(array());
        return;
    }
    
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT zl.id as lot_id, zl.nazwa_produktu, zl.user_id,
                u.user_email, u.display_name,
                CONCAT(u.display_name, ' (', u.user_email, ')') as nazwa
         FROM $tabela_loty zl
         INNER JOIN {$wpdb->users} u ON zl.user_id = u.ID
         WHERE zl.status = 'wolny' 
         AND zl.data_waznosci >= CURDATE()
         AND (u.user_email LIKE %s 
              OR u.display_name LIKE %s 
              OR zl.id LIKE %s
              OR get_user_meta(u.ID, 'srl_telefon', true) LIKE %s)
         ORDER BY u.display_name
         LIMIT 20",
        '%' . $query . '%',
        '%' . $query . '%',
        '%' . $query . '%',
        '%' . $query . '%'
    ), ARRAY_A);
    
    $wynik = array();
    foreach ($results as $row) {
        $telefon = get_user_meta($row['user_id'], 'srl_telefon', true);
        $wynik[] = array(
            'lot_id' => $row['lot_id'],
            'user_id' => $row['user_id'],
            'nazwa' => $row['display_name'] . ' (' . $row['user_email'] . ')' . ($telefon ? ' - ' . $telefon : ''),
            'produkt' => $row['nazwa_produktu']
        );
    }
    
    wp_send_json_success($wynik);
}

function srl_ajax_przypisz_klienta_do_slotu() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true); //było fronted
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    } 
    
    $termin_id = intval($_POST['termin_id']);
    $lot_id = intval($_POST['lot_id']);
    
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $slot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
        $termin_id
    ), ARRAY_A);
    
    if (!$slot) {
        wp_send_json_error('Slot nie istnieje lub nie jest dostępny.');
        return;
    }
    
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty WHERE id = %d AND status = 'wolny'",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot) {
        wp_send_json_error('Lot nie istnieje lub nie jest dostępny.');
        return;
    }
    
    $wpdb->query('START TRANSACTION');
    
    try {
        $update_slot = $wpdb->update(
            $tabela_terminy,
            array(
                'status' => 'Zarezerwowany',
                'klient_id' => $lot['user_id']
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
                'data_rezerwacji' => srl_get_current_datetime(),
                'termin_id' => $termin_id
            ),
            array('id' => $lot_id),
            array('%s', '%s', '%d'),
            array('%d')
        );
        
        if ($update_lot === false) {
            throw new Exception('Błąd aktualizacji lotu.');
        }
        
		// Pobierz szczegóły terminu dla historii
		$termin = $wpdb->get_row($wpdb->prepare(
			"SELECT data, godzina_start, godzina_koniec, pilot_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
			$termin_id
		), ARRAY_A);

		// Pobierz dane klienta
		$user = get_userdata($lot['user_id']);
		$klient_info = $user ? $user->display_name . ' (' . $user->user_email . ')' : 'Klient #' . $lot['user_id'];

		$termin_opis = sprintf('%s %s-%s (Pilot %d)', 
			$termin['data'], 
			substr($termin['godzina_start'], 0, 5), 
			substr($termin['godzina_koniec'], 0, 5),
			$termin['pilot_id']
		);

		// DOPISZ wpis do historii
		$wpis_historii = array(
			'data' => srl_get_current_datetime(),
			'opis' => "Lot przypisany do terminu przez administratora: {$termin_opis} dla klienta: {$klient_info}",
			'typ' => 'przypisanie_admin',
			'executor' => 'Admin',
			'szczegoly' => array(
				'termin_id' => $termin_id,
				'data_lotu' => $termin['data'],
				'godzina_start' => $termin['godzina_start'],
				'godzina_koniec' => $termin['godzina_koniec'],
				'pilot_id' => $termin['pilot_id'],
				'user_id' => $lot['user_id'],
				'user_info' => $klient_info,
				'stary_status' => 'wolny',
				'nowy_status' => 'zarezerwowany'
			)
		);

		srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
		
		
        $wpdb->query('COMMIT');

		// Pobierz datę ze slotu do odświeżenia harmonogramu
		$slot_date = $wpdb->get_var($wpdb->prepare(
			"SELECT data FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
			$termin_id
		));
		if ($slot_date) {
			srl_zwroc_godziny_wg_pilota($slot_date);
		} else {
			wp_send_json_success('Klient został przypisany do slotu.');
		}

		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			wp_send_json_error('Błąd: ' . $e->getMessage());
		}
}

function srl_ajax_zapisz_dane_prywatne() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $termin_id = intval($_POST['termin_id']);
    $imie = sanitize_text_field($_POST['imie']);
    $nazwisko = sanitize_text_field($_POST['nazwisko']);
    $rok_urodzenia = intval($_POST['rok_urodzenia']);
    $telefon = sanitize_text_field($_POST['telefon']);
    $sprawnosc_fizyczna = sanitize_text_field($_POST['sprawnosc_fizyczna']);
    $kategoria_wagowa = sanitize_text_field($_POST['kategoria_wagowa']);
    $uwagi = sanitize_textarea_field($_POST['uwagi']);
    
    if (empty($imie) || empty($nazwisko) || empty($telefon) || $rok_urodzenia < 1920) {
        wp_send_json_error('Wypełnij wszystkie wymagane pola.');
        return;
    }

    $telefon_clean = str_replace([' ', '-', '(', ')', '+48'], '', $telefon);
    if (strlen($telefon_clean) < 9) {
        wp_send_json_error('Numer telefonu musi mieć minimum 9 cyfr.');
        return;
    }
    
    $dane_pasazera = array(
        'imie' => $imie,
        'nazwisko' => $nazwisko,
        'rok_urodzenia' => $rok_urodzenia,
        'telefon' => $telefon,
        'sprawnosc_fizyczna' => $sprawnosc_fizyczna,
        'kategoria_wagowa' => $kategoria_wagowa,
        'uwagi' => $uwagi,
        'typ' => 'prywatny'
    );
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $result = $wpdb->update(
        $tabela,
        array(
            'status' => 'Prywatny',
            'notatka' => json_encode($dane_pasazera)
        ),
        array('id' => $termin_id),
        array('%s', '%s'),
        array('%d')
    );
    
    if ($result !== false) {
        wp_send_json_success('Dane zostały zapisane.');
    } else {
        wp_send_json_error('Błąd zapisu do bazy danych.');
    }
}

function srl_ajax_pobierz_dane_prywatne() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $termin_id = intval($_POST['termin_id']);
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $notatka = $wpdb->get_var($wpdb->prepare(
        "SELECT notatka FROM $tabela WHERE id = %d",
        $termin_id
    ));
    
    if ($notatka) {
        $dane = json_decode($notatka, true);
        if ($dane && is_array($dane)) {
            wp_send_json_success($dane);
        } else {
            wp_send_json_error('Nieprawidłowe dane.');
        }
    } else {
        wp_send_json_error('Brak danych.');
    }
}

function srl_ajax_pobierz_aktualne_godziny() {
	check_ajax_referer('srl_admin_nonce', 'nonce', true);
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Brak uprawnień.');
		return;
	}
    
    $data = sanitize_text_field($_GET['data'] ?? $_POST['data']);
    if (!$data) {
        wp_send_json_error('Brak daty.');
    }
    
    srl_zwroc_godziny_wg_pilota($data);
}

function srl_ajax_wyszukaj_wolne_loty() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Brak uprawnień.');
		return;
	}
    
    $search_field = sanitize_text_field($_POST['search_field']);
    $query = sanitize_text_field($_POST['query']);
    
    if (strlen($query) < 2) {
        wp_send_json_success(array());
        return;
    }
    
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $where_conditions = array("zl.status = 'wolny'", "zl.data_waznosci >= CURDATE()");
    $where_params = array();
    
    switch ($search_field) {
        case 'id_lotu':
            $where_conditions[] = "zl.id = %s";
            $where_params[] = $query;
            break;
        case 'id_zamowienia':
            $where_conditions[] = "zl.order_id = %s";
            $where_params[] = $query;
            break;
        case 'email':
            $where_conditions[] = "u.user_email LIKE %s";
            $where_params[] = '%' . $query . '%';
            break;
        case 'telefon':
            $where_conditions[] = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s)";
            $where_params[] = '%' . $query . '%';
            break;
        case 'imie_nazwisko':
            $where_conditions[] = "(zl.imie LIKE %s OR zl.nazwisko LIKE %s OR CONCAT(zl.imie, ' ', zl.nazwisko) LIKE %s)";
            $search_param = '%' . $query . '%';
            $where_params = array_merge($where_params, [$search_param, $search_param, $search_param]);
            break;
        case 'login':
            $where_conditions[] = "u.user_login LIKE %s";
            $where_params[] = '%' . $query . '%';
            break;
        default:
            $where_conditions[] = "(zl.id LIKE %s OR zl.order_id LIKE %s OR zl.imie LIKE %s OR zl.nazwisko LIKE %s OR zl.nazwa_produktu LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s))";
            $search_param = '%' . $query . '%';
            $where_params = array_merge($where_params, [$query, $query, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
            break;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT zl.id as lot_id, zl.order_id, zl.user_id, zl.imie, zl.nazwisko, 
                CONCAT(zl.imie, ' ', zl.nazwisko) as klient_nazwa,
                u.user_email as email,
                (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = zl.user_id AND meta_key = 'srl_telefon') as telefon
         FROM $tabela_loty zl
         INNER JOIN {$wpdb->users} u ON zl.user_id = u.ID
         $where_clause
         ORDER BY zl.data_zakupu DESC
         LIMIT 20",
        ...$where_params
    ), ARRAY_A);
    
    wp_send_json_success($results);
}

function srl_ajax_przypisz_wykupiony_lot() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Brak uprawnień.');
		return;
	}
    
    $termin_id = intval($_POST['termin_id']);
    $lot_id = intval($_POST['lot_id']);
    
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $wpdb->query('START TRANSACTION');
    
    try {
        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
            $termin_id
        ), ARRAY_A);
        
        if (!$slot) {
            throw new Exception('Slot nie jest dostępny.');
        }
        
        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_loty WHERE id = %d AND status = 'wolny'",
            $lot_id
        ), ARRAY_A);
        
        if (!$lot) {
            throw new Exception('Lot nie jest dostępny.');
        }
        
        $wpdb->update(
            $tabela_terminy,
            array(
                'status' => 'Zarezerwowany',
                'klient_id' => $lot['user_id']
            ),
            array('id' => $termin_id),
            array('%s', '%d'),
            array('%d')
        );
        
        $wpdb->update(
            $tabela_loty,
            array(
                'status' => 'zarezerwowany',
                'data_rezerwacji' => srl_get_current_datetime(),
                'termin_id' => $termin_id
            ),
            array('id' => $lot_id),
            array('%s', '%s', '%d'),
            array('%d')
        );
        
		// Pobierz szczegóły terminu dla historii
		$termin = $wpdb->get_row($wpdb->prepare(
			"SELECT data, godzina_start, godzina_koniec, pilot_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
			$termin_id
		), ARRAY_A);

		// Pobierz dane klienta
		$user = get_userdata($lot['user_id']);
		$klient_info = $user ? $user->display_name . ' (' . $user->user_email . ')' : 'Klient #' . $lot['user_id'];

		$termin_opis = sprintf('%s %s-%s (Pilot %d)', 
			$termin['data'], 
			substr($termin['godzina_start'], 0, 5), 
			substr($termin['godzina_koniec'], 0, 5),
			$termin['pilot_id']
		);

		// DOPISZ wpis do historii
		$wpis_historii = array(
			'data' => srl_get_current_datetime(),
			'opis' => "Lot przypisany do terminu przez administratora: {$termin_opis} dla klienta: {$klient_info}",
			'typ' => 'przypisanie_admin',
			'executor' => 'Admin',
			'szczegoly' => array(
				'termin_id' => $termin_id,
				'data_lotu' => $termin['data'],
				'godzina_start' => $termin['godzina_start'],
				'godzina_koniec' => $termin['godzina_koniec'],
				'pilot_id' => $termin['pilot_id'],
				'user_id' => $lot['user_id'],
				'user_info' => $klient_info,
				'stary_status' => 'wolny',
				'nowy_status' => 'zarezerwowany'
			)
		);

		srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
		
        $wpdb->query('COMMIT');

		// Pobierz datę ze slotu do odświeżenia harmonogramu  
		$slot_date = $wpdb->get_var($wpdb->prepare(
			"SELECT data FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
			$termin_id
		));
		if ($slot_date) {
			srl_zwroc_godziny_wg_pilota($slot_date);
		} else {
			wp_send_json_success('Lot został przypisany.');
		}

		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			wp_send_json_error($e->getMessage());
		}
}

function srl_ajax_zapisz_lot_prywatny() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Brak uprawnień.');
		return;
	}
    
    $termin_id = intval($_POST['termin_id']);
    $imie = sanitize_text_field($_POST['imie']);
    $nazwisko = sanitize_text_field($_POST['nazwisko']);
    $rok_urodzenia = intval($_POST['rok_urodzenia']);
    $telefon = sanitize_text_field($_POST['telefon']);
    $sprawnosc_fizyczna = sanitize_text_field($_POST['sprawnosc_fizyczna']);
    $kategoria_wagowa = sanitize_text_field($_POST['kategoria_wagowa']);
    $uwagi = sanitize_textarea_field($_POST['uwagi']);
    
    if (empty($imie) || empty($nazwisko) || empty($telefon) || $rok_urodzenia < 1920) {
        wp_send_json_error('Wypełnij wszystkie wymagane pola.');
        return;
    }

    $telefon_clean = str_replace([' ', '-', '(', ')', '+48'], '', $telefon);
    if (strlen($telefon_clean) < 9) {
        wp_send_json_error('Numer telefonu musi mieć minimum 9 cyfr.');
        return;
    }
    
    $dane_pasazera = array(
        'imie' => $imie,
        'nazwisko' => $nazwisko,
        'rok_urodzenia' => $rok_urodzenia,
        'telefon' => $telefon,
        'sprawnosc_fizyczna' => $sprawnosc_fizyczna,
        'kategoria_wagowa' => $kategoria_wagowa,
        'uwagi' => $uwagi,
        'typ' => 'prywatny'
    );
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $result = $wpdb->update(
        $tabela,
        array(
            'status' => 'Prywatny',
            'notatka' => json_encode($dane_pasazera)
        ),
        array('id' => $termin_id),
        array('%s', '%s'),
        array('%d')
    );
    
    if ($result !== false) {
        wp_send_json_success('Lot prywatny został zapisany.');
    } else {
        wp_send_json_error('Błąd zapisu do bazy danych.');
    }
}



function srl_ajax_pobierz_historie_lotu() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $lot_id = intval($_POST['lot_id']);
    
    if (!$lot_id) {
        wp_send_json_error('Nieprawidłowe ID lotu.');
        return;
    }
    
    $historia = srl_pobierz_pelna_historie_lotu($lot_id);
    
    wp_send_json_success($historia);
}

function srl_ajax_przywroc_rezerwacje() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }

    $termin_id = intval($_POST['termin_id']);
    
    if (!$termin_id) {
        wp_send_json_error('Nieprawidłowe ID terminu.');
        return;
    }

    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz dane odwołanego slotu
    $slot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Odwołany przez organizatora'",
        $termin_id
    ), ARRAY_A);
    
    if (!$slot) {
        wp_send_json_error('Slot nie istnieje lub nie jest odwołany.');
        return;
    }
    
    // Pobierz dane historyczne z notatki
    $dane_historyczne = json_decode($slot['notatka'], true);
    if (!$dane_historyczne || !isset($dane_historyczne['lot_id'])) {
        wp_send_json_error('Brak danych do przywrócenia rezerwacji.');
        return;
    }
    
    $lot_id = $dane_historyczne['lot_id'];
    $klient_id = $dane_historyczne['klient_id'];
    
    // Sprawdź czy lot nadal istnieje i jest wolny
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty WHERE id = %d AND user_id = %d",
        $lot_id, $klient_id
    ), ARRAY_A);
    
    if (!$lot) {
        wp_send_json_error('Lot nie istnieje.');
        return;
    }
    
    if ($lot['status'] !== 'wolny') {
        wp_send_json_error('Lot nie jest dostępny (status: ' . $lot['status'] . '). Może być już zarezerwowany ponownie.');
        return;
    }
    
    $wpdb->query('START TRANSACTION');
    
    try {
        // 1. Przywróć slot do statusu zarezerwowany
        $result_slot = $wpdb->update(
            $tabela_terminy,
            array(
                'status' => 'Zarezerwowany',
                'notatka' => null // Wyczyść historię odwołania
            ),
            array('id' => $termin_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result_slot === false) {
            throw new Exception('Błąd przywracania slotu.');
        }
        
        // 2. Przywróć lot do statusu zarezerwowany
        $result_lot = $wpdb->update(
            $tabela_loty,
            array(
                'status' => 'zarezerwowany',
                'termin_id' => $termin_id,
                'data_rezerwacji' => srl_get_current_datetime()
            ),
            array('id' => $lot_id),
            array('%s', '%d', '%s'),
            array('%d')
        );
        
        if ($result_lot === false) {
            throw new Exception('Błąd przywracania lotu.');
        }
        
        // 3. Wyślij email do klienta o przywróceniu
        $user = get_userdata($klient_id);
        if ($user) {
            $szczegoly_terminu = $slot['data'] . ' ' . substr($slot['godzina_start'], 0, 5) . '-' . substr($slot['godzina_koniec'], 0, 5);
            
            $to = $user->user_email;
            $subject = 'Twój lot tandemowy został przywrócony';
            $body = "Dzień dobry {$user->display_name},\n\n"
                 . "Mamy dobrą wiadomość! Twój lot na {$szczegoly_terminu} został przywrócony.\n\n"
                 . "Możesz się już cieszyć na nadchodzący lot!\n\n"
                 . "Pozdrawiamy,\nZespół Loty Tandemowe";
            wp_mail($to, $subject, $body);
            
            // DOPISZ do historii lotu
            $wpis_historii = array(
                'data' => srl_get_current_datetime(),
                'opis' => "Rezerwacja przywrócona przez administratora - termin {$szczegoly_terminu}",
                'typ' => 'przywrocenie_przez_admin',
                'executor' => 'Admin',
                'szczegoly' => array(
                    'termin_id' => $termin_id,
                    'przywrocony_termin' => $szczegoly_terminu,
                    'klient_id' => $klient_id,
                    'email_wyslany' => true,
                    'powod' => 'Przywrócenie po odwołaniu przez organizatora'
                )
            );
            
            srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
        }
        
        $wpdb->query('COMMIT');
        srl_zwroc_godziny_wg_pilota($slot['data']);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error('Błąd przywracania: ' . $e->getMessage());
    }
}

function srl_ajax_pobierz_dane_odwolanego() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $termin_id = intval($_POST['termin_id']);
    
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $notatka = $wpdb->get_var($wpdb->prepare(
        "SELECT notatka FROM $tabela WHERE id = %d AND status = 'Odwołany przez organizatora'",
        $termin_id
    ));
    
    if ($notatka) {
        $dane = json_decode($notatka, true);
        if ($dane && is_array($dane)) {
            wp_send_json_success($dane);
        } else {
            wp_send_json_error('Nieprawidłowe dane.');
        }
    } else {
        wp_send_json_error('Brak danych odwołania.');
    }
}


function srl_ajax_zrealizuj_lot() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $termin_id = intval($_POST['termin_id']);
    
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $wpdb->query('START TRANSACTION');
    
    try {
        // Pobierz szczegóły slotu
        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);
        
        if (!$slot || $slot['status'] !== 'Zarezerwowany') {
            throw new Exception('Slot nie istnieje lub nie jest zarezerwowany.');
        }
        
        // Zaktualizuj status slotu - ZACHOWAJ wszystkie dane
        $wpdb->update(
            $tabela_terminy,
            array('status' => 'Zrealizowany'),
            array('id' => $termin_id),
            array('%s'),
            array('%d')
        );
        
        // Zaktualizuj lot - ZACHOWAJ wszystkie dane
        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_loty WHERE termin_id = %d",
            $termin_id
        ), ARRAY_A);
        
        if ($lot) {
            $wpdb->update(
                $tabela_loty,
                array('status' => 'zrealizowany'),
                array('id' => $lot['id']),
                array('%s'),
                array('%d')
            );
            
            // Dodaj wpis do historii
            $wpis_historii = array(
                'data' => srl_get_current_datetime(),
                'opis' => 'Lot oznaczony jako zrealizowany przez administratora',
                'typ' => 'realizacja_admin',
                'executor' => 'Admin',
                'szczegoly' => array(
                    'termin_id' => $termin_id,
                    'stary_status' => 'zarezerwowany',
                    'nowy_status' => 'zrealizowany',
                    'lot_id' => $lot['id']
                )
            );
            
            srl_dopisz_do_historii_lotu($lot['id'], $wpis_historii);
        }
        
        $wpdb->query('COMMIT');
        
        // Zwróć zaktualizowane dane
        $data = $slot['data'];
        srl_zwroc_godziny_wg_pilota($data);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error($e->getMessage());
    }
}

function srl_ajax_zrealizuj_lot_prywatny() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    
    $termin_id = intval($_POST['termin_id']);
    
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    try {
        // Pobierz szczegóły slotu
        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);
        
        if (!$slot || $slot['status'] !== 'Prywatny') {
            throw new Exception('Slot nie istnieje lub nie jest prywatny.');
        }
        
        // Zaktualizuj status slotu - ZACHOWAJ notatę z danymi pasażera
        $result = $wpdb->update(
            $tabela_terminy,
            array('status' => 'Zrealizowany'),
            array('id' => $termin_id),
            array('%s'),
            array('%d')
        );
        
        if ($result === false) {
            throw new Exception('Błąd aktualizacji statusu slotu.');
        }
        
        // Zwróć zaktualizowane dane
        $data = $slot['data'];
        srl_zwroc_godziny_wg_pilota($data);
        
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

?>