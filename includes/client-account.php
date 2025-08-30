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
    $user_id = get_current_user_id();
    global $wpdb;
    
    echo '<h2>🎫 Twoje loty tandemowe</h2>';
    
    // Pobierz wszystkie loty użytkownika
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
	$loty = $wpdb->get_results($wpdb->prepare(
		"SELECT zl.*, t.data, t.godzina_start, t.godzina_koniec, t.pilot_id
		 FROM $tabela_loty zl 
		 LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
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
    
    if (empty($loty)) {
        echo '<div class="woocommerce-message woocommerce-message--info">';
        echo '<p>Nie masz jeszcze żadnych lotów tandemowych.</p>';
        echo '<a href="/produkt/lot-w-tandemie/" class="button">Kup lot tandemowy</a>';
        echo '</div>';
        return;
    }
    
    // Grupuj loty według statusu
    $loty_grupowane = array(
        'zarezerwowany' => array(),
        'wolny' => array(),
        'zrealizowany' => array(),
        'przedawniony' => array()
    );
    
    foreach ($loty as $lot) {
        $loty_grupowane[$lot['status']][] = $lot;
    }
    
    // Pokaż zarezerwowane loty
    if (!empty($loty_grupowane['zarezerwowany'])) {
        echo '<h3 style="color:#0073aa;">📅 Zarezerwowane loty</h3>';
        echo '<table class="shop_table shop_table_responsive">';
        echo '<thead><tr><th>ID lotu</th><th>Produkt</th><th>Data i godzina</th><th>Akcje</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($loty_grupowane['zarezerwowany'] as $lot) {
            $data_lotu = new DateTime($lot['data'] . ' ' . $lot['godzina_start']);
            $czas_do_lotu = $data_lotu->getTimestamp() - time();
            $mozna_anulowac = $czas_do_lotu > 48 * 3600; // 48h
            
            // Formatuj datę i godzinę
            $data_formatowana = srl_formatuj_date_i_czas($lot['data'], $lot['godzina_start']);
            
            echo '<tr>';
            echo '<td data-title="ID lotu">#' . esc_html($lot['id']) . '</td>';
            echo '<td data-title="Produkt">';
			echo esc_html($lot['nazwa_produktu']);
			if (!empty($lot['kod_vouchera'])) {
				echo '<br><small style="color: #d63638; font-weight: bold;">🎁 Z vouchera: ' . esc_html($lot['kod_vouchera']) . '</small>';
				if (!empty($lot['voucher_buyer_imie'])) {
					echo '<br><small style="color: #666;">Od: ' . esc_html($lot['voucher_buyer_imie'] . ' ' . $lot['voucher_buyer_nazwisko']) . '</small>';
				}
			}
			echo '</td>';
            echo '<td data-title="Data i godzina">' . $data_formatowana . '</td>';
            echo '<td data-title="Akcje">';
            
            if ($mozna_anulowac) {
                echo '<a href="#" class="button srl-anuluj-lot" data-lot-id="' . $lot['id'] . '">Anuluj rezerwację</a>';
            } else {
                echo '<span style="color:#999;">Nie można już anulować</span>';
            }
            
            echo '</td></tr>';
        }
        
        echo '</tbody></table>';
    }
    
    // Pokaż dostępne loty
    if (!empty($loty_grupowane['wolny'])) {
        echo '<h3 style="color:#46b450;">🎫 Loty do zarezerwowania (' . count($loty_grupowane['wolny']) . ')</h3>';
        echo '<table class="shop_table shop_table_responsive">';
        echo '<thead><tr><th>ID lotu</th><th>Produkt</th><th>Data zakupu</th><th>Ważny do</th><th>Akcje</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($loty_grupowane['wolny'] as $lot) {
            $data_waznosci = new DateTime($lot['data_waznosci']);
            $dni_do_waznosci = floor(($data_waznosci->getTimestamp() - time()) / (24 * 3600));
            
            echo '<tr>';
            echo '<td data-title="ID lotu">#' . esc_html($lot['id']) . '</td>';
            echo '<td data-title="Produkt">';
			echo esc_html($lot['nazwa_produktu']);
			if (!empty($lot['kod_vouchera'])) {
				echo '<br><small style="color: #d63638; font-weight: bold;">🎁 Z vouchera: ' . esc_html($lot['kod_vouchera']) . '</small>';
				if (!empty($lot['voucher_buyer_imie'])) {
					echo '<br><small style="color: #666;">Od: ' . esc_html($lot['voucher_buyer_imie'] . ' ' . $lot['voucher_buyer_nazwisko']) . '</small>';
				}
			}
			echo '</td>';
            echo '<td data-title="Data zakupu">' . date('d.m.Y', strtotime($lot['data_zakupu'])) . '</td>';
            echo '<td data-title="Ważny do">' . date('d.m.Y', strtotime($lot['data_waznosci']));
            
            if ($dni_do_waznosci <= 30) {
                echo ' <span style="color:#d63638; font-weight:bold;">(wygasa za ' . $dni_do_waznosci . ' dni)</span>';
            }
            
            echo '</td>';
            echo '<td data-title="Akcje"><a href="/rezerwuj-lot/" class="button button-primary">Zarezerwuj</a></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '<p><a href="/rezerwuj-lot/" class="button button-primary button-large">🎯 Zarezerwuj lot</a></p>';
    }
    
    // Pokaż zrealizowane loty
    if (!empty($loty_grupowane['zrealizowany'])) {
        echo '<h3 style="color:#46b450;">✅ Zrealizowane loty</h3>';
        echo '<table class="shop_table shop_table_responsive">';
        echo '<thead><tr><th>ID lotu</th><th>Produkt</th><th>Data lotu</th><th>Status</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($loty_grupowane['zrealizowany'] as $lot) {
            echo '<tr>';
            echo '<td data-title="ID lotu">#' . esc_html($lot['id']) . '</td>';
            echo '<td data-title="Produkt">';
			echo esc_html($lot['nazwa_produktu']);
			if (!empty($lot['kod_vouchera'])) {
				echo '<br><small style="color: #d63638; font-weight: bold;">🎁 Z vouchera: ' . esc_html($lot['kod_vouchera']) . '</small>';
				if (!empty($lot['voucher_buyer_imie'])) {
					echo '<br><small style="color: #666;">Od: ' . esc_html($lot['voucher_buyer_imie'] . ' ' . $lot['voucher_buyer_nazwisko']) . '</small>';
				}
			}
			echo '</td>';
            echo '<td data-title="Data lotu">' . date('d.m.Y', strtotime($lot['data'])) . '</td>';
            echo '<td data-title="Status"><span style="color:#46b450; font-weight:bold;">✅ Zrealizowany</span></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    // Pokaż przeterminowane loty
    if (!empty($loty_grupowane['przedawniony'])) {
        echo '<h3 style="color:#d63638;">⏰ Przeterminowane loty</h3>';
        echo '<table class="shop_table shop_table_responsive">';
        echo '<thead><tr><th>ID lotu</th><th>Produkt</th><th>Data zakupu</th><th>Data przeterminowania</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($loty_grupowane['przedawniony'] as $lot) {
            echo '<tr>';
            echo '<td data-title="ID lotu">#' . esc_html($lot['id']) . '</td>';
            echo '<td data-title="Produkt">';
			echo esc_html($lot['nazwa_produktu']);
			if (!empty($lot['kod_vouchera'])) {
				echo '<br><small style="color: #d63638; font-weight: bold;">🎁 Z vouchera: ' . esc_html($lot['kod_vouchera']) . '</small>';
				if (!empty($lot['voucher_buyer_imie'])) {
					echo '<br><small style="color: #666;">Od: ' . esc_html($lot['voucher_buyer_imie'] . ' ' . $lot['voucher_buyer_nazwisko']) . '</small>';
				}
			}
			echo '</td>';
            echo '<td data-title="Data zakupu">' . date('d.m.Y', strtotime($lot['data_zakupu'])) . '</td>';
            echo '<td data-title="Przeterminowany">' . date('d.m.Y', strtotime($lot['data_waznosci'])) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '<p><em>Przeterminowane loty nie mogą być już wykorzystane. <a href="/produkt/lot-w-tandemie/">Kup nowy lot</a>.</em></p>';
    }
    
    // JavaScript do anulowania
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.srl-anuluj-lot').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Czy na pewno chcesz anulować tę rezerwację?')) {
                return;
            }
            
            var lotId = $(this).data('lot-id');
            var button = $(this);
            
            button.text('Anulowanie...').prop('disabled', true);
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                method: 'POST',
                data: {
                    action: 'srl_anuluj_rezerwacje_klient',
                    nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>',
                    lot_id: lotId
                },
                success: function(response) {
                    if (response.success) {
                        alert('Rezerwacja została anulowana.');
                        location.reload();
                    } else {
                        alert('Błąd: ' + response.data);
                        button.text('Anuluj rezerwację').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Błąd połączenia z serwerem.');
                    button.text('Anuluj rezerwację').prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php
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
    
    return $dzien_tygodnia . ', ' . $dzien . ' ' . $nazwa_miesiaca . ' ' . $rok . ', godz. ' . $godzina;
}