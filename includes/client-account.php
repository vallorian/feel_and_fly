<?php
// Rejestracja endpointów WooCommerce
add_action('init', 'srl_dodaj_endpointy');
function srl_dodaj_endpointy() {
    add_rewrite_endpoint('srl-moje-loty', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('srl-informacje-o-mnie', EP_ROOT | EP_PAGES);
}

// Flush rewrite rules po aktywacji wtyczki
register_activation_hook(SRL_PLUGIN_DIR . '/system-rezerwacji-lotow.php', 'srl_flush_rewrite_rules');
function srl_flush_rewrite_rules() {
    srl_dodaj_endpointy();
    flush_rewrite_rules();
}

// Zakładki w koncie klienta
add_filter('woocommerce_account_menu_items', 'srl_dodaj_zakladki_klienta');
function srl_dodaj_zakladki_klienta($items) {
    // Wstaw przed "logout"
    $logout = $items['customer-logout'];
    unset($items['customer-logout']);
    
    $items['srl-moje-loty'] = 'Moje loty tandemowe';
    $items['srl-informacje-o-mnie'] = 'Dane pasażera';
    $items['customer-logout'] = $logout;
    
    return $items;
}

// Treść zakładki "Moje loty"
add_action('woocommerce_account_srl-moje-loty_endpoint', 'srl_moje_loty_tresc');
function srl_moje_loty_tresc() {
    // Załaduj CSS dla tabeli
    wp_enqueue_style('srl-frontend-style', SRL_PLUGIN_URL . 'assets/css/frontend-style.css', array(), '1.0');
    
    $user_id = get_current_user_id();
    global $wpdb;
    
    // Definicje tabel
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        
    echo '<h2>Twoje loty tandemowe</h2>';
    echo '<div style="margin-bottom: 30px;">';
    echo '<a href="/rezerwuj-lot/" class="srl-zarzadzaj-btn">🎯 Zarządzaj lotami</a>';
    echo '</div>';
    
    // Pobierz wszystkie loty użytkownika (łącznie z przeterminowanymi)
    $wszystkie_loty = $wpdb->get_results($wpdb->prepare(
        "SELECT zl.*, 
               t.data, 
               t.godzina_start, 
               t.godzina_koniec, 
               t.pilot_id,
               v.kod_vouchera,
               v.buyer_imie as voucher_buyer_imie,
               v.buyer_nazwisko as voucher_buyer_nazwisko
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
    
    if (empty($wszystkie_loty)) {
        echo '<div class="woocommerce-message woocommerce-message--info">';
        echo '<p>Nie masz jeszcze żadnych lotów tandemowych.</p>';
        echo '<a href="/produkt/lot-w-tandemie/" class="button">Kup lot tandemowy</a>';
        echo '</div>';
        return;
    }
    
    // Jedna tabela z wszystkimi lotami
    echo '<table class="srl-tabela-lotow">';
    echo '<thead><tr><th class="srl-kolumna-nazwa">Nazwa</th><th class="srl-kolumna-status">Status i termin</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($wszystkie_loty as $lot) {
        echo '<tr>';
        
        // Kolumna Nazwa
        echo '<td class="srl-kolumna-nazwa">';
        echo '<div class="srl-nazwa-lotu">Lot w tandemie (#' . esc_html($lot['id']) . ')</div>';
        
        // Pokaż opcje lotu
		$opcje_tekst = array();
		if (!empty($lot['ma_filmowanie'])) {
			$opcje_tekst[] = '<span style="color: #46b450;">z filmowaniem</span>';
		} else {
			$opcje_tekst[] = '<span style="color: #d63638;">bez filmowania</span>';
		}

		if (!empty($lot['ma_akrobacje'])) {
			$opcje_tekst[] = '<span style="color: #46b450;">z akrobacjami</span>';
		} else {
			$opcje_tekst[] = '<span style="color: #d63638;">bez akrobacji</span>';
		}

		echo '<div class="srl-opcje-lotu">' . implode(', ', $opcje_tekst) . '</div>';

        if (!empty($lot['kod_vouchera'])) {
            // echo '<div class="srl-voucher-info">🎁 Z vouchera: ' . esc_html($lot['kod_vouchera']) . '</div>';
        }
        
        // Data ważności
        if (!empty($lot['data_waznosci'])) {
            echo '<div class="srl-data-waznosci">(Ważny do: ' . date('d.m.Y', strtotime($lot['data_waznosci'])) . ')</div>';
        }
        echo '</td>';
        
        // Kolumna Status i termin
        echo '<td class="srl-kolumna-status">';
        
        // Status
        if ($lot['status'] === 'zarezerwowany') {
            echo '<div class="srl-status-badge srl-status-zarezerwowany">Zarezerwowany</div>';
            if (!empty($lot['data']) && !empty($lot['godzina_start'])) {
                $data_formatowana = srl_formatuj_date_i_czas($lot['data'], $lot['godzina_start']);
                echo '<div class="srl-termin-info">' . $data_formatowana . '</div>';
            }
        } elseif ($lot['status'] === 'wolny') {
            echo '<div class="srl-status-badge srl-status-wolny">Czeka na rezerwację</div>';
        } elseif ($lot['status'] === 'zrealizowany') {
            echo '<div class="srl-status-badge srl-status-zrealizowany">Zrealizowany</div>';
            if (!empty($lot['data']) && !empty($lot['godzina_start'])) {
                $data_formatowana = srl_formatuj_date_i_czas($lot['data'], $lot['godzina_start']);
                echo '<div class="srl-termin-info">' . $data_formatowana . '</div>';
            }
        } elseif ($lot['status'] === 'przedawniony') {
            echo '<div class="srl-status-badge srl-status-przedawniony">Przeterminowany</div>';
            echo '<div class="srl-termin-info">Wygasł: ' . date('d.m.Y', strtotime($lot['data_waznosci'])) . '</div>';
        }
        
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
}
        

// Treść zakładki "Dane pasażera"
add_action('woocommerce_account_srl-informacje-o-mnie_endpoint', 'srl_informacje_o_mnie_tresc');
function srl_informacje_o_mnie_tresc() {
    $user_id = get_current_user_id();
    
    // Obsługa zapisu formularza
	if (isset($_POST['srl_zapisz_info'])) {
		$dane = array(
			'imie' => sanitize_text_field($_POST['srl_imie']),
			'nazwisko' => sanitize_text_field($_POST['srl_nazwisko']),
			'rok_urodzenia' => intval($_POST['srl_rok_urodzenia']),
			'kategoria_wagowa' => sanitize_text_field($_POST['srl_kategoria_wagowa']),
			'sprawnosc_fizyczna' => sanitize_text_field($_POST['srl_sprawnosc_fizyczna']),
			'telefon' => sanitize_text_field($_POST['srl_telefon']),
			'uwagi' => sanitize_textarea_field($_POST['srl_uwagi'])
		);
		
		// Walidacja
		$bledy = array();
		if (empty($dane['imie'])) $bledy[] = 'Imię jest wymagane';
		if (empty($dane['nazwisko'])) $bledy[] = 'Nazwisko jest wymagane';
		if ($dane['rok_urodzenia'] < 1920 || $dane['rok_urodzenia'] > 2010) $bledy[] = 'Nieprawidłowy rok urodzenia';
		if (empty($dane['kategoria_wagowa'])) $bledy[] = 'Kategoria wagowa jest wymagana';
		if (empty($dane['sprawnosc_fizyczna'])) $bledy[] = 'Sprawność fizyczna jest wymagana';
		if (empty($dane['telefon'])) $bledy[] = 'Numer telefonu jest wymagany';
        
        if (empty($bledy)) {
            // Zapisz dane
            foreach ($dane as $key => $value) {
                update_user_meta($user_id, 'srl_' . $key, $value);
            }
            echo '<div class="woocommerce-message">Dane zostały zapisane pomyślnie!</div>';
        } else {
            echo '<div class="woocommerce-error"><ul>';
            foreach ($bledy as $blad) {
                echo '<li>' . esc_html($blad) . '</li>';
            }
            echo '</ul></div>';
        }
    }
    
	// Pobierz zapisane dane
	$imie = get_user_meta($user_id, 'srl_imie', true);
	$nazwisko = get_user_meta($user_id, 'srl_nazwisko', true);
	$rok_urodzenia = get_user_meta($user_id, 'srl_rok_urodzenia', true);
	$kategoria_wagowa = get_user_meta($user_id, 'srl_kategoria_wagowa', true);
	$sprawnosc_fizyczna = get_user_meta($user_id, 'srl_sprawnosc_fizyczna', true);
	$telefon = get_user_meta($user_id, 'srl_telefon', true);
	$uwagi = get_user_meta($user_id, 'srl_uwagi', true);
    
    ?>
    <h2>🪪 Dane pasażera</h2>
    <p>Te dane będą używane podczas rezerwacji lotów. Uzupełnij je dokładnie.</p>
    
	<form method="post" class="edit-account">
		<div class="srl-form-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:20px;">
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="srl_imie">Imię <span class="required">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="srl_imie" id="srl_imie" value="<?php echo esc_attr($imie); ?>" required />
			</p>
			
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="srl_nazwisko">Nazwisko <span class="required">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="srl_nazwisko" id="srl_nazwisko" value="<?php echo esc_attr($nazwisko); ?>" required />
			</p>
			
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="srl_rok_urodzenia">Rok urodzenia <span class="required">*</span></label>
				<input type="number" class="woocommerce-Input woocommerce-Input--text input-text" name="srl_rok_urodzenia" id="srl_rok_urodzenia" value="<?php echo esc_attr($rok_urodzenia); ?>" min="1920" max="2010" required />
			</p>
			
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="srl_telefon">Numer telefonu <span class="required">*</span></label>
				<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="srl_telefon" id="srl_telefon" value="<?php echo esc_attr($telefon); ?>" required />
			</p>
			
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="srl_sprawnosc_fizyczna">Sprawność fizyczna <span class="required">*</span></label>
				<select class="woocommerce-Input woocommerce-Input--text input-text" name="srl_sprawnosc_fizyczna" id="srl_sprawnosc_fizyczna" required>
					<option value="">Wybierz poziom sprawności</option>
					<option value="zdolnosc_do_marszu" <?php selected($sprawnosc_fizyczna, 'zdolnosc_do_marszu'); ?>>Zdolność do marszu</option>
					<option value="zdolnosc_do_biegu" <?php selected($sprawnosc_fizyczna, 'zdolnosc_do_biegu'); ?>>Zdolność do biegu</option>
					<option value="sprinter" <?php selected($sprawnosc_fizyczna, 'sprinter'); ?>>Sprinter!</option>
				</select>
			</p>
			
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="srl_kategoria_wagowa">Kategoria wagowa <span class="required">*</span></label>
				<select class="woocommerce-Input woocommerce-Input--text input-text" name="srl_kategoria_wagowa" id="srl_kategoria_wagowa" required>
					<option value="">Wybierz kategorię wagową</option>
					<option value="25-40kg" <?php selected($kategoria_wagowa, '25-40kg'); ?>>25-40kg</option>
					<option value="41-60kg" <?php selected($kategoria_wagowa, '41-60kg'); ?>>41-60kg</option>
					<option value="61-90kg" <?php selected($kategoria_wagowa, '61-90kg'); ?>>61-90kg</option>
					<option value="91-120kg" <?php selected($kategoria_wagowa, '91-120kg'); ?>>91-120kg</option>
					<option value="120kg+" <?php selected($kategoria_wagowa, '120kg+'); ?>>120kg+</option>
				</select>
			</p>
		</div>
        
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide" style="grid-column: 1 / -1;">
            <label for="srl_uwagi">Dodatkowe uwagi</label>
            <textarea class="woocommerce-Input woocommerce-Input--text input-text" name="srl_uwagi" id="srl_uwagi" rows="4" placeholder="Np. alergie, obawy, specjalne potrzeby..."><?php echo esc_textarea($uwagi); ?></textarea>
        </p>
        
        <p>
            <button type="submit" class="woocommerce-Button button" name="srl_zapisz_info" value="Zapisz zmiany">Zapisz zmiany</button>
        </p>
    </form>
    
    <style>
    .srl-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    
    @media (max-width: 768px) {
        .srl-form-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
    <?php
}

// Funkcja formatowania daty i czasu
function srl_formatuj_date_i_czas($data, $czas) {
    $data_obj = new DateTime($data);
    $dzien = $data_obj->format('j');
    $miesiac = $data_obj->format('n');
    $rok = $data_obj->format('Y');
    $godzina = substr($czas, 0, 5);
    
    $nazwy_dni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
    $nazwy_miesiecy = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 
                      'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    
    $dzien_tygodnia = $nazwy_dni[$data_obj->format('w')];
    $nazwa_miesiaca = $nazwy_miesiecy[$miesiac - 1];
    
    return $dzien . '&nbsp;' . $nazwa_miesiaca . '&nbsp;' . $rok . '<br>godz.&nbsp;' . $godzina . '<br>' . $dzien_tygodnia;
}
