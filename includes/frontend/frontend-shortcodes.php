<?php

/**
 * Generuje sekcję voucherów (wspólna dla różnych miejsc)
 */
function srl_generuj_sekcje_voucherow($show_title = true) {
    ob_start();
    ?>
    <?php if ($show_title): ?>
    <h3>Kup lub dodaj voucher</h3>
    <?php endif; ?>
    
<div class="srl-voucher-options" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
    
    <!-- Kup lot - PIERWSZE MIEJSCE -->
    <div class="srl-voucher-card">
        <div class="srl-voucher-header" style="background: linear-gradient(135deg, #46b450, #3ba745); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">
            <h4 style="margin: 0; font-size: 18px;">💳 Kup lot</h4>
            <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">Bezpośrednio ze sklepu</p>
        </div>
        <div class="srl-voucher-content" style="padding: 20px; border: 2px solid #46b450; border-top: none; border-radius: 0 0 8px 8px; background: white;">
            <a href="/produkt/lot-w-tandemie/" class="srl-btn srl-btn-success" style="text-decoration: none; display: block; text-align: center;">Przejdź do sklepu</a>
        </div>
    </div>
    
    <!-- Wykorzystaj Voucher Feel&Fly - DRUGIE MIEJSCE -->
    <div class="srl-voucher-card" id="srl-voucher-feelfly">
        <div class="srl-voucher-header" style="background: linear-gradient(135deg, #0073aa, #005a87); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">
            <h4 style="margin: 0; font-size: 18px;">🎁 Wykorzystaj Voucher</h4>
            <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">Feel&Fly</p>
        </div>
        <div class="srl-voucher-content" style="padding: 22px; border: 2px solid #0073aa; border-top: none; border-radius: 0 0 8px 8px; background: white;">
            <div id="srl-voucher-form" style="display: none;">
                <div class="srl-form-group">
                    <label for="srl-voucher-code">Kod vouchera:</label>
                    <input type="text" id="srl-voucher-code" placeholder="Wpisz kod vouchera" style="text-transform: uppercase;">
                </div>
                <button id="srl-voucher-submit" class="srl-btn srl-btn-primary" style="width: 100%;">Zatwierdź voucher</button>
                <button id="srl-voucher-cancel" class="srl-btn srl-btn-secondary" style="width: 100%; margin-top: 10px;">Anuluj</button>
            </div>
            <button id="srl-voucher-show" class="srl-btn srl-btn-primary" style="width: 100%;">Mam voucher</button>
        </div>
    </div>
    
    <!-- Wykorzystaj Voucher partnera - TRZECIE MIEJSCE -->
    <div class="srl-voucher-card" style="opacity: 0.6;">
        <div class="srl-voucher-header" style="background: linear-gradient(135deg, #6c757d, #5a6268); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">
            <h4 style="margin: 0; font-size: 18px;">🤝 Voucher partnera</h4>
            <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">Wkrótce dostępne</p>
        </div>
        <div class="srl-voucher-content" style="padding: 22px; border: 2px solid #6c757d; border-top: none; border-radius: 0 0 8px 8px; background: #f8f9fa;">
            <button class="srl-btn srl-btn-secondary" style="width: 100%;" disabled>Niedostępne</button>
        </div>
    </div>
</div>
    <?php
    return ob_get_clean();
}

/**
 * Generuje JavaScript dla obsługi voucherów
 */
function srl_generuj_js_voucherow() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Pokaż formularz vouchera
        $(document).on('click', '#srl-voucher-show', function() {
            $('#srl-voucher-show').hide();
            $('#srl-voucher-form').show();
            $('#srl-voucher-code').focus();
        });
        
        // Anuluj voucher
        $(document).on('click', '#srl-voucher-cancel', function() {
            $('#srl-voucher-form').hide();
            $('#srl-voucher-show').show();
            $('#srl-voucher-code').val('');
        });
        
        // Automatyczne formatowanie kodu vouchera
        $(document).on('input', '#srl-voucher-code', function() {
            var value = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '');
            $(this).val(value);
        });
        
        // Zatwierdź voucher
        $(document).on('click', '#srl-voucher-submit', function() {
            var kod = $('#srl-voucher-code').val().trim();
            
			if (kod.length < 1) {
				alert('Wprowadź kod vouchera.');
				return;
			}
            
            var button = $(this);
            button.prop('disabled', true).text('Sprawdzanie...');
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                method: 'POST',
                data: {
                    action: 'srl_wykorzystaj_voucher',
                    kod_vouchera: kod,
                    nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('Voucher został wykorzystany! Lot został dodany do Twojego konta.');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        alert('Błąd: ' + response.data);
                    }
                },
                error: function() {
                    alert('Błąd połączenia z serwerem.');
                },
                complete: function() {
                    button.prop('disabled', false).text('Zatwierdź voucher');
                }
            });
        });
    });
    </script>
    <?php
}


// Rejestracja shortcode dla kalendarza front-end
add_shortcode('srl_kalendarz', 'srl_shortcode_kalendarz');
function srl_shortcode_kalendarz() {
    // Sprawdź czy użytkownik jest zalogowany
    if (!is_user_logged_in()) {
        return srl_komunikat_niezalogowany();
    }
    
    $user_id = get_current_user_id();
    
    // Sprawdź czy ma jakieś loty do wykorzystania
    $loty_dostepne = srl_pobierz_dostepne_loty($user_id);
    if (empty($loty_dostepne)) {
        return srl_komunikat_brak_lotow();
    }
    
    // Enqueue scripts i styles
    wp_enqueue_script('srl-frontend-calendar', SRL_PLUGIN_URL . 'assets/js/frontend-calendar.js', array('jquery'), '1.0', true);
    wp_enqueue_style('srl-frontend-style', SRL_PLUGIN_URL . 'assets/css/frontend-style.css', array(), '1.0');
    
    // Przekaż dane do JS
    wp_localize_script('srl-frontend-calendar', 'srlFrontend', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('srl_frontend_nonce'),
        'user_id' => $user_id
    ));
    
    ob_start();
    ?>
    
    <div id="srl-rezerwacja-container">
        <!-- Progress Bar -->
		<div class="srl-progress-bar">
			<div class="srl-step srl-step-active" data-step="1">
				<span class="srl-step-number">1</span>
				<span class="srl-step-title">Wybór rezerwacji</span>
			</div>
			<div class="srl-step" data-step="2">
				<span class="srl-step-number">2</span>
				<span class="srl-step-title">Twoje dane</span>
			</div>
			<div class="srl-step" data-step="3">
				<span class="srl-step-number">3</span>
				<span class="srl-step-title">Wybór dnia</span>
			</div>
			<div class="srl-step" data-step="4">
				<span class="srl-step-number">4</span>
				<span class="srl-step-title">Wybór godziny</span>
			</div>
			<div class="srl-step" data-step="5">
				<span class="srl-step-number">5</span>
				<span class="srl-step-title">Potwierdzenie</span>
			</div>
		</div>

        <!-- Krok 1: Wybór rezerwacji -->
        <div id="srl-krok-1" class="srl-krok srl-krok-active">
            <h2>👋 Witaj!</h2>
            
            <!-- Aktualne rezerwacje i wykupione loty -->
            <div id="srl-aktualne-rezerwacje">
                <h3 style="text-transform: uppercase;">Twoje aktualne rezerwacje i wykupione loty</h3>
                <div id="srl-lista-rezerwacji">
                    <div class="srl-loader">Ładowanie...</div>
                </div>
            </div>
            
            <!-- Sekcja voucherów i zakupów -->
            <div id="srl-voucher-section" style="margin-top: 30px;">
                <?php echo srl_generuj_sekcje_voucherow(true); ?>
            </div>
        </div>

		<!-- Krok 2: Twoje dane -->
		<div id="srl-krok-2" class="srl-krok">
			<h2 style="text-transform: uppercase;">Twoje dane</h2>
			
			<!-- Wybrany lot do rezerwacji -->
			<div id="srl-wybrany-lot-info" style="margin-bottom:30px; /*padding:20px; border-radius:8px; border:2px solid #0073aa; background:#f0f8ff; */">
				<h3 style="margin-top:0; color:#0073aa; text-transform: uppercase;">Wybrany lot do rezerwacji:</h3>
				<div id="srl-wybrany-lot-szczegoly"></div>
			</div>
			
			<!-- Formularz danych pasażera -->
			<div id="srl-dane-pasazera">
				<h3  style="text-transform: uppercase;">Dane pasażera</h3>
				<form id="srl-formularz-pasazera">
					<div class="srl-form-grid">
						<div class="srl-form-group">
							<label for="srl-imie">Imię *</label>
							<input type="text" id="srl-imie" name="imie" required>
						</div>
						<div class="srl-form-group">
							<label for="srl-nazwisko">Nazwisko *</label>
							<input type="text" id="srl-nazwisko" name="nazwisko" required>
						</div>
						<div class="srl-form-group">
							<label for="srl-rok-urodzenia">Rok urodzenia *</label>
							<input type="number" id="srl-rok-urodzenia" name="rok_urodzenia" min="1920" max="2010" required>
						</div>
						<div class="srl-form-group">
							<label for="srl-telefon">Numer telefonu *</label>
							<input type="tel" id="srl-telefon" name="telefon" required>
						</div>
						<div class="srl-form-group">
							<label for="srl-sprawnosc-fizyczna">Sprawność fizyczna *</label>
							<select id="srl-sprawnosc-fizyczna" name="sprawnosc_fizyczna" required>
								<option value="">Wybierz poziom sprawności</option>
								<option value="zdolnosc_do_marszu">Zdolność do marszu</option>
								<option value="zdolnosc_do_biegu">Zdolność do biegu</option>
								<option value="sprinter">Sprinter!</option>
							</select>
						</div>
						<div class="srl-form-group">
							<label for="srl-kategoria-wagowa">Kategoria wagowa *</label>
							<select id="srl-kategoria-wagowa" name="kategoria_wagowa" required>
								<option value="">Wybierz kategorię wagową</option>
								<option value="25-40kg">25-40kg</option>
								<option value="41-60kg">41-60kg</option>
								<option value="61-90kg">61-90kg</option>
								<option value="91-120kg">91-120kg</option>
								<option value="120kg+">120kg+</option>
							</select>
						</div>
						
						<!-- Komunikat o wadze na całą szerokość -->
						<div class="srl-form-group srl-full-width">
							<div id="srl-waga-ostrzezenie" style="display:none; margin-bottom:15px; border-radius:8px;"></div>
						</div>
						
						<div class="srl-form-group srl-full-width">
							<label for="srl-uwagi">Dodatkowe uwagi</label>
							<textarea id="srl-uwagi" name="uwagi" rows="3" placeholder="Np. alergie, obawy, specjalne potrzeby..."></textarea>
						</div>
						<div class="srl-form-group srl-full-width">
							<label style="display: flex; align-items: center; gap: 8px; font-weight: 500;">
								<input type="checkbox" id="srl-akceptacja-regulaminu" name="akceptacja_regulaminu" required>
								Akceptuję <a href="/regulamin/" target="_blank" style="color: #0073aa; text-decoration: none;">Regulamin</a> *
							</label>
						</div>
					</div>
					
					<div class="srl-form-actions">
						<button id="srl-powrot-krok-1" class="srl-btn srl-btn-secondary">← Powrót</button>
						<button type="submit" class="srl-btn srl-btn-primary">
							Zapisz i przejdź dalej →
						</button>
					</div>
				</form>
			</div>
		</div>

		<!-- Krok 3: Wybór dnia -->
		<div id="srl-krok-3" class="srl-krok">
			<h2 style="text-transform: uppercase;">Wybierz dzień lotu</h2>
			<div id="srl-kalendarz-frontend">
				<div class="srl-kalendarz-nawigacja">
					<button id="srl-poprzedni-miesiac" class="srl-btn srl-btn-secondary">← Poprzedni</button>
					<h3 id="srl-miesiac-rok"></h3>
					<button id="srl-nastepny-miesiac" class="srl-btn srl-btn-secondary">Następny →</button>
				</div>
				<div id="srl-kalendarz-tabela"></div>
				<div class="srl-kalendarz-legenda">
					<div class="srl-legenda-item">
						<div class="srl-kolor srl-dostepny"></div>
						<span>Dostępne terminy</span>
					</div>
					<div class="srl-legenda-item">
						<div class="srl-kolor srl-niedostepny"></div>
						<span>Brak dostępnych terminów</span>
					</div>
				</div>
			</div>
			
			<div class="srl-form-actions">
				<button id="srl-powrot-krok-2" class="srl-btn srl-btn-secondary">← Powrót</button>
			</div>
		</div>

		<!-- Krok 4: Wybór godziny -->
		<div id="srl-krok-4" class="srl-krok">
			<h2 style="text-transform: uppercase;">Wybierz godzinę lotu</h2>
			<div id="srl-wybrany-dzien-info"></div>
			<div id="srl-harmonogram-frontend"></div>
			
			<div class="srl-form-actions">
				<button id="srl-powrot-krok-3" class="srl-btn srl-btn-secondary">← Zmień dzień</button>
				<button id="srl-dalej-krok-5" class="srl-btn srl-btn-primary" style="display:none;">
					Przejdź do potwierdzenia →
				</button>
			</div>
		</div>

		<!-- Krok 5: Potwierdzenie -->
		<div id="srl-krok-5" class="srl-krok">
			<h2 style="text-transform: uppercase;">✅ Potwierdzenie rezerwacji</h2>
			<div id="srl-podsumowanie-rezerwacji"></div>
			
			<div class="srl-form-actions">
				<button id="srl-powrot-krok-4" class="srl-btn srl-btn-secondary">← Zmień godzinę</button>
				<button id="srl-potwierdz-rezerwacje" class="srl-btn srl-btn-success">
					🎯 Potwierdź rezerwację
				</button>
			</div>
		</div>
        
		<!-- Komunikaty -->
        <div id="srl-komunikaty"></div>
        
        <!-- Komunikat o dodaniu do koszyka (jak w moje konto) -->
        <div id="srl-cart-notification" style="display:none; position:fixed; top:20px; right:20px; background:#46b450; color:white; padding:15px 20px; border-radius:8px; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.3);">
            <div id="srl-cart-message"></div>
            <a href="<?php echo function_exists('wc_get_cart_url') ? wc_get_cart_url() : '/koszyk/'; ?>" class="button" style="margin-top:10px; background:white; color:#46b450; text-decoration:none; display:inline-block; padding:8px 15px; border-radius:4px; font-weight:600;">Przejdź do koszyka</a>
        </div>
    </div>
    
	<?php 
	srl_generuj_js_voucherow(); 

	return ob_get_clean();
}

// Komunikat dla niezalogowanych z formularzami logowania i rejestracji
function srl_komunikat_niezalogowany() {
    ob_start();
    ?>
    <div id="srl-auth-container">
        <div class="srl-auth-header">
            <h3  style="text-transform: uppercase;">Logowanie wymagane</h3>
            <p>Aby dokonać rezerwacji lotu lub wykorzystać voucher, musisz być zalogowany.</p>
        </div>
        
        <!-- Tabs -->
        <div class="srl-auth-tabs">
            <button class="srl-tab-btn srl-tab-active" data-tab="login">Logowanie</button>
            <button class="srl-tab-btn" data-tab="register">Rejestracja</button>
        </div>
        
        <!-- Login Form -->
        <div id="srl-login-tab" class="srl-tab-content srl-tab-active">
            <form id="srl-login-form">
                <div class="srl-auth-form-grid">
                    <div class="srl-form-group">
                        <label for="srl-login-username">Email lub nazwa użytkownika</label>
                        <input type="text" id="srl-login-username" name="username" required>
                    </div>
                    <div class="srl-form-group">
                        <label for="srl-login-password">Hasło</label>
                        <input type="password" id="srl-login-password" name="password" required>
                    </div>
                    <div class="srl-form-group srl-form-checkbox">
                        <label>
                            <input type="checkbox" id="srl-login-remember" name="remember">
                            <span class="checkmark"></span>
                            Zapamiętaj mnie
                        </label>
                    </div>
                </div>
                
                <div class="srl-auth-actions">
                    <button type="submit" class="srl-btn srl-btn-primary srl-btn-large">Zaloguj się</button>
                </div>
                
                <div class="srl-auth-footer">
                    <a href="<?php echo wp_lostpassword_url(get_permalink()); ?>">Zapomniałeś hasła?</a>
                </div>
            </form>
        </div>
        
        <!-- Register Form -->
        <div id="srl-register-tab" class="srl-tab-content">
            <form id="srl-register-form">
                <div class="srl-auth-form-grid">
                    <div class="srl-form-group">
                        <label for="srl-register-email">Adres email</label>
                        <input type="email" id="srl-register-email" name="email" required>
                    </div>
                    <div class="srl-form-group">
                        <label for="srl-register-password">Hasło</label>
                        <input type="password" id="srl-register-password" name="password" required minlength="6">
                    </div>
                    <div class="srl-form-group">
                        <label for="srl-register-password-confirm">Potwierdź hasło</label>
                        <input type="password" id="srl-register-password-confirm" name="password_confirm" required>
                    </div>
                    <div class="srl-form-group">
                        <label for="srl-register-first-name">Imię</label>
                        <input type="text" id="srl-register-first-name" name="first_name" required>
                    </div>
                    <div class="srl-form-group">
                        <label for="srl-register-last-name">Nazwisko</label>
                        <input type="text" id="srl-register-last-name" name="last_name" required>
                    </div>
                </div>
                
                <div class="srl-auth-actions">
                    <button type="submit" class="srl-btn srl-btn-primary srl-btn-large">Załóż konto</button>
                </div>
            </form>
        </div>
        
        <div id="srl-auth-messages"></div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Tab switching
        $('.srl-tab-btn').on('click', function() {
            var tabId = $(this).data('tab');
            
            $('.srl-tab-btn').removeClass('srl-tab-active');
            $('.srl-tab-content').removeClass('srl-tab-active');
            
            $(this).addClass('srl-tab-active');
            $('#srl-' + tabId + '-tab').addClass('srl-tab-active');
        });
        
        // Login form
        $('#srl-login-form').on('submit', function(e) {
            e.preventDefault();
            
            var submitBtn = $(this).find('button[type="submit"]');
            var originalText = submitBtn.text();
            submitBtn.prop('disabled', true).text('Logowanie...');
            
            var formData = {
                action: 'srl_ajax_login',
                username: $('#srl-login-username').val(),
                password: $('#srl-login-password').val(),
                remember: $('#srl-login-remember').is(':checked'),
                nonce: '<?php echo wp_create_nonce('srl_auth_nonce'); ?>'
            };
            
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', formData, function(response) {
                if (response.success) {
                    $('#srl-auth-messages').html('<div class="srl-komunikat srl-komunikat-success">Zalogowano pomyślnie! Przekierowywanie...</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#srl-auth-messages').html('<div class="srl-komunikat srl-komunikat-error">' + response.data + '</div>');
                    submitBtn.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Register form
        $('#srl-register-form').on('submit', function(e) {
            e.preventDefault();
            
            var password = $('#srl-register-password').val();
            var passwordConfirm = $('#srl-register-password-confirm').val();
            
            if (password !== passwordConfirm) {
                $('#srl-auth-messages').html('<div class="srl-komunikat srl-komunikat-error">Hasła nie są identyczne.</div>');
                return;
            }
            
            var submitBtn = $(this).find('button[type="submit"]');
            var originalText = submitBtn.text();
            submitBtn.prop('disabled', true).text('Tworzenie konta...');
            
            var formData = {
                action: 'srl_ajax_register',
                email: $('#srl-register-email').val(),
                password: password,
                first_name: $('#srl-register-first-name').val(),
                last_name: $('#srl-register-last-name').val(),
                nonce: '<?php echo wp_create_nonce('srl_auth_nonce'); ?>'
            };
            
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', formData, function(response) {
                if (response.success) {
                    $('#srl-auth-messages').html('<div class="srl-komunikat srl-komunikat-success">Konto zostało utworzone! Zalogowano automatycznie. Przekierowywanie...</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $('#srl-auth-messages').html('<div class="srl-komunikat srl-komunikat-error">' + response.data + '</div>');
                    submitBtn.prop('disabled', false).text(originalText);
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// Komunikat dla użytkowników bez lotów z opcjami voucherów
function srl_komunikat_brak_lotow() {
    ob_start();
    ?>
    <div class="srl-komunikat srl-komunikat-info">
        <h3>Brak dostępnych lotów</h3>
        <p>Nie masz jeszcze żadnych lotów do zarezerwowania.</p>
    </div>
    
    <!-- Sekcja voucherów i zakupów -->
    <div id="srl-voucher-section" style="margin-top: 30px;">
        <?php echo srl_generuj_sekcje_voucherow(); ?>
    </div>
    
    <?php srl_generuj_js_voucherow(); ?>
    <?php
    return ob_get_clean();
}

// Pobiera dostępne loty użytkownika
function srl_pobierz_dostepne_loty($user_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Sprawdź czy tabela istnieje
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela'") == $tabela;
    if (!$table_exists) {
        return array();
    }
    
    $wynik = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $tabela 
         WHERE user_id = %d 
         AND status IN ('wolny', 'zarezerwowany') 
         AND data_waznosci >= CURDATE()
         ORDER BY data_zakupu DESC",
        $user_id
    ), ARRAY_A);
    
    return $wynik;
}
