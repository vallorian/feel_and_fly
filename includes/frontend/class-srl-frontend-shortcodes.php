<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Frontend_Shortcodes {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_shortcode('srl_kalendarz', array($this, 'shortcodeKalendarz'));
    }

    public function shortcodeKalendarz() {
        if (!is_user_logged_in()) {
            return $this->komunikatNiezalogowany();
        }
        
        $user_id = get_current_user_id();
        $loty_dostepne = $this->pobierzDostepneLoty($user_id);
        
        if (empty($loty_dostepne)) {
            return $this->komunikatBrakLotow();
        }
        
        wp_enqueue_script('srl-frontend-calendar', SRL_PLUGIN_URL . 'assets/js/frontend-calendar.js', array('jquery'), '1.0', true);
        wp_enqueue_style('srl-frontend-style', SRL_PLUGIN_URL . 'assets/css/frontend-style.css', array(), '1.0');
        
        wp_localize_script('srl-frontend-calendar', 'srlFrontend', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('srl_frontend_nonce'),
            'user_id' => $user_id
        ));
        
        ob_start();
        ?>
        
        <div id="srl-rezerwacja-container">
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

            <div id="srl-krok-1" class="srl-krok srl-krok-active">
                <h2>👋 Witaj!</h2>
                
                <div id="srl-aktualne-rezerwacje">
                    <h3 style="text-transform: uppercase;">Twoje aktualne rezerwacje i wykupione loty</h3>
                    <div id="srl-lista-rezerwacji">
                        <div class="srl-loader">Ładowanie...</div>
                    </div>
                </div>
                
                <div id="srl-voucher-section" style="margin-top: 30px;">
                    <?php echo $this->generujSekcjeVoucherow(true); ?>
                </div>
            </div>

            <div id="srl-krok-2" class="srl-krok">
                <h2 style="text-transform: uppercase;">Twoje dane</h2>
                
                <div id="srl-wybrany-lot-info" style="margin-bottom:30px;">
                    <h3 style="margin-top:0; color:#0073aa; text-transform: uppercase;">Wybrany lot do rezerwacji:</h3>
                    <div id="srl-wybrany-lot-szczegoly"></div>
                </div>
                
                <div id="srl-dane-pasazera">
                    <h3 style="text-transform: uppercase;">Dane pasażera</h3>
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
                                <input type="number" id="srl-rok-urodzenia" name="rok_urodzenia" min="1920" max="2020" required>
                            </div>
                            <div class="srl-form-group">
                                <label for="srl-telefon">Numer telefonu *</label>
                                <input type="tel" id="srl-telefon" name="telefon" required 
                                   pattern="(\+48\s?)?[0-9\s\-\(\)]{9,}" 
                                   title="Numer telefonu musi mieć minimum 9 cyfr">
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
                            
                            <div class="srl-form-group srl-full-width">
                                <div id="srl-wiek-ostrzezenie" style="display:none; margin-bottom:15px; border-radius:8px;"></div>
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

            <div id="srl-krok-5" class="srl-krok">
                <h2 style="text-transform: uppercase;">✅ Potwierdzenie rezerwacji</h2>
                
                <div id="srl-podsumowanie-rezerwacji">
                    <div class="srl-podsumowanie-box" style="background:#f8f9fa; padding:30px; border-radius:8px; margin:20px 0;">
                        <h3 style="margin-top:0; color:#0073aa; text-transform: uppercase;">Podsumowanie rezerwacji</h3>
                        
                        <div class="srl-podsumowanie-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin:20px 0;">
                            <div id="srl-wybrany-lot-podsumowanie"><strong>🎫 Wybrany lot:</strong><br><span id="srl-lot-details">Ładowanie...</span></div>
                            <div id="srl-data-godzina-podsumowanie"><strong>📅 Data i godzina lotu:</strong><br><span id="srl-datetime-details">Ładowanie...</span></div>
                        </div>
                        
                        <div class="srl-dane-pasazera-box" style="background:#f8f9fa; padding-top:30px; border-radius:8px; margin-top:20px;">
                            <h3 style="margin-top:0; color:#0073aa; text-transform: uppercase;">Dane pasażera</h3>
                            <div id="srl-dane-pasazera-podsumowanie">
                            </div>
                        </div>
                        
                        <div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:20px;">
                            <h4 style="margin-top:0; color:#f57c00;">⚠️ Ważne informacje:</h4>
                            <ul style="margin:0; padding-left:20px;">
                                <li>Zgłoś się 30 minut przed godziną lotu</li>
                                <li>Weź ze sobą dokument tożsamości</li>
                                <li>Ubierz się stosownie do warunków pogodowych</li>
                                <li>Rezerwację można anulować do 48h przed lotem</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="srl-form-actions">
                    <button id="srl-powrot-krok-4" class="srl-btn srl-btn-secondary">← Zmień godzinę</button>
                    <button id="srl-potwierdz-rezerwacje" class="srl-btn srl-btn-success">
                        🎯 Potwierdź rezerwację
                    </button>
                </div>
            </div>
            
            <div id="srl-komunikaty"></div>
            
            <div id="srl-cart-notification" style="display:none; position:fixed; top:20px; right:20px; background:#46b450; color:white; padding:15px 20px; border-radius:8px; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.3);">
                <div id="srl-cart-message"></div>
                <a href="<?php echo function_exists('wc_get_cart_url') ? wc_get_cart_url() : '/koszyk/'; ?>" class="button" style="margin-top:10px; background:white; color:#46b450; text-decoration:none; display:inline-block; padding:8px 15px; border-radius:4px; font-weight:600;">Przejdź do koszyka</a>
            </div>
        </div>
        
        <?php 
        $this->generujJsVoucherow(); 

        return ob_get_clean();
    }

    private function generujSekcjeVoucherow($show_title = true) {
        ob_start();
        ?>
        <?php if ($show_title): ?>
        <h3>Kup lub dodaj voucher</h3>
        <?php endif; ?>
        
    <div class="srl-voucher-options" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
        
        <div class="srl-voucher-card">
            <div class="srl-voucher-header" style="background: linear-gradient(135deg, #46b450, #3ba745); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">
                <h4 style="margin: 0; font-size: 18px;">💳 Kup lot</h4>
                <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">Bezpośrednio ze sklepu</p>
            </div>
            <div class="srl-voucher-content" style="padding: 20px; border: 2px solid #46b450; border-top: none; border-radius: 0 0 8px 8px; background: white;">
                <a href="/produkt/lot-w-tandemie/" class="srl-btn srl-btn-success" style="text-decoration: none; display: block; text-align: center;">Przejdź do sklepu</a>
            </div>
        </div>
        
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
        
        <div class="srl-voucher-card" id="srl-voucher-partner">
            <div class="srl-voucher-header" style="background: linear-gradient(135deg, #6c757d, #5a6268); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">
                <h4 style="margin: 0; font-size: 18px;">🤝 Voucher partnera</h4>
                <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">PrezentMarzeń, Groupon</p>
            </div>
            <div class="srl-voucher-content" style="padding: 22px; border: 2px solid #6c757d; border-top: none; border-radius: 0 0 8px 8px; background: white;">
                <button id="srl-partner-voucher-show" class="srl-btn srl-btn-primary" style="width: 100%;">Mam voucher</button>
            </div>
        </div>
    </div>

    <div id="srl-partner-voucher-modal" class="srl-modal" style="display: none;">
        <div class="srl-modal-content">
            <div class="srl-modal-header">
                <h3>Voucher partnera</h3>
                <span class="srl-modal-close">&times;</span>
            </div>
            <div class="srl-modal-body">
                <form id="srl-partner-voucher-form">
                    <div class="srl-form-grid">
                        <div class="srl-form-group">
                            <label for="srl-partner-select">Partner *</label>
                            <select id="srl-partner-select" name="partner" required>
                                <option value="">Wybierz partnera</option>
                                <option value="prezent_marzen">PrezentMarzeń</option>
                                <option value="groupon">Groupon</option>
                            </select>
                        </div>
                        <div class="srl-form-group">
                            <label for="srl-voucher-type-select">Typ vouchera *</label>
                            <select id="srl-voucher-type-select" name="typ_vouchera" required disabled>
                                <option value="">Najpierw wybierz partnera</option>
                            </select>
                        </div>
                        <div class="srl-form-group">
                            <label for="srl-partner-voucher-code">Kod vouchera *</label>
                            <input type="text" id="srl-partner-voucher-code" name="kod_vouchera" required placeholder="Wprowadź kod vouchera">
                        </div>
                        <div class="srl-form-group">
                            <label for="srl-security-code">Kod zabezpieczający *</label>
                            <input type="text" id="srl-security-code" name="kod_zabezpieczajacy" required placeholder="Wprowadź kod zabezpieczający">
                        </div>
                        <div class="srl-form-group">
                            <label for="srl-voucher-validity-date">Data ważności vouchera *</label>
                            <input type="date" id="srl-voucher-validity-date" name="data_waznosci" 
                                   min="<?php echo date('Y-m-d'); ?>" 
                                   max="2050-12-31" 
                                   required="">
                        </div>
                    </div>
                    
                    <div id="srl-passengers-container" style="display: none;">
                        <h4>Dane pasażerów</h4>
                        <div id="srl-passengers-forms"></div>
                    </div>
                    
                    <div class="srl-modal-actions">
                        <button type="button" id="srl-partner-voucher-cancel" class="srl-btn srl-btn-secondary">Anuluj</button>
                        <button type="submit" id="srl-partner-voucher-submit" class="srl-btn srl-btn-primary">Wyślij do akceptacji</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    return ob_get_clean();
    }

    private function generujJsVoucherow() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            $(document).on('click', '#srl-voucher-show', function() {
                $('#srl-voucher-show').hide();
                $('#srl-voucher-form').show();
                $('#srl-voucher-code').focus();
            });
            
            $(document).on('click', '#srl-voucher-cancel', function() {
                $('#srl-voucher-form').hide();
                $('#srl-voucher-show').show();
                $('#srl-voucher-code').val('');
            });
            
            $(document).on('input', '#srl-voucher-code', function() {
                var value = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '');
                $(this).val(value);
            });
            
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
                        nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
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
            
            $(document).on('click', '#srl-partner-voucher-show', function() {
                $('#srl-partner-voucher-modal').show();
            });

            $(document).on('click', '.srl-modal-close, #srl-partner-voucher-cancel', function() {
                $('#srl-partner-voucher-modal').hide();
                $('#srl-partner-voucher-form')[0].reset();
                $('#srl-passengers-container').hide();
                $('#srl-passengers-forms').empty();
                $('#srl-voucher-type-select').prop('disabled', true).html('<option value="">Najpierw wybierz partnera</option>');
            });
            

            $(document).on('change', '.passenger-rok', function() {
                var form = $(this).closest('.srl-passenger-form');
                var index = $('.srl-passenger-form').index(form) + 1;
                srlValidatePassengerAge(index);
            });

            $(document).on('change', '.passenger-kategoria', function() {
                var form = $(this).closest('.srl-passenger-form');
                var index = $('.srl-passenger-form').index(form) + 1;
                srlValidatePassengerWeight(index);
            });
            
            $(document).on('change', '#srl-partner-select', function() {
                var partner = $(this).val();
                var typeSelect = $('#srl-voucher-type-select');
                
                if (!partner) {
                    typeSelect.prop('disabled', true).html('<option value="">Najpierw wybierz partnera</option>');
                    $('#srl-passengers-container').hide();
                    return;
                }
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    method: 'POST',
                    data: {
                        action: 'srl_get_partner_voucher_types',
                        partner: partner,
                        nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var options = '<option value="">Wybierz typ vouchera</option>';
                            $.each(response.data, function(key, type) {
                                options += '<option value="' + key + '" data-passengers="' + type.liczba_osob + '">' + type.nazwa + '</option>';
                            });
                            typeSelect.prop('disabled', false).html(options);
                        }
                    },
                    error: function() {
                        alert('Błąd ładowania typów voucherów.');
                    }
                });
            });

            $(document).on('change', '#srl-voucher-type-select', function() {
                var selectedOption = $(this).find('option:selected');
                var passengerCount = selectedOption.data('passengers');
                
                if (passengerCount) {
                    srlGeneratePassengerForms(passengerCount);
                    $('#srl-passengers-container').show();
                } else {
                    $('#srl-passengers-container').hide();
                }
            });

            $(document).on('submit', '#srl-partner-voucher-form', function(e) {
                e.preventDefault();
                
                var hasWeightErrors = false;
                $('.srl-passenger-weight-warning-1, .srl-passenger-weight-warning-2, .srl-passenger-weight-warning-3').each(function() {
                    if ($(this).is(':visible') && $(this).html().includes('❌')) {
                        hasWeightErrors = true;
                    }
                });
                
                if (hasWeightErrors) {
                    alert('Nie można wysłać formularza - jeden lub więcej pasażerów ma kategorię wagową 120kg+, która uniemożliwia lot.');
                    return;
                }
                
                var formData = {
                    action: 'srl_submit_partner_voucher',
                    partner: $('#srl-partner-select').val(),
                    typ_vouchera: $('#srl-voucher-type-select').val(),
                    kod_vouchera: $('#srl-partner-voucher-code').val(),
                    kod_zabezpieczajacy: $('#srl-security-code').val(),
                    data_waznosci: $('#srl-voucher-validity-date').val(),
                    dane_pasazerow: [],
                    nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>'
                };
                
                if (!$('#srl-all-passengers-regulamin').is(':checked')) {
                    alert('Musisz zaakceptować Regulamin.');
                    return;
                }
                
                if (!formData.data_waznosci) {
                    alert('Musisz podać datę ważności vouchera.');
                    return;
                }
                
                $('#srl-passengers-forms .srl-passenger-form').each(function() {
                    var passengerData = {
                        imie: $(this).find('.passenger-imie').val(),
                        nazwisko: $(this).find('.passenger-nazwisko').val(),
                        rok_urodzenia: $(this).find('.passenger-rok').val(),
                        telefon: $(this).find('.passenger-telefon').val(),
                        kategoria_wagowa: $(this).find('.passenger-kategoria').val(),
                        sprawnosc_fizyczna: $(this).find('.passenger-sprawnosc').val(),
                        uwagi: $(this).find('.passenger-uwagi').val(),
                        akceptacja_regulaminu: true
                    };
                    formData.dane_pasazerow.push(passengerData);
                });
                
                var submitBtn = $('#srl-partner-voucher-submit');
                submitBtn.prop('disabled', true).text('Wysyłanie...');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    method: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            showSuccessMessage();
                        } else {
                            alert('Błąd: ' + response.data);
                            submitBtn.prop('disabled', false).text('Wyślij do akceptacji');
                        }
                    },
                    error: function() {
                        alert('Błąd połączenia z serwerem.');
                        submitBtn.prop('disabled', false).text('Wyślij do akceptacji');
                    }
                });
            });

            function showSuccessMessage() {
                $('.srl-modal-body').html(
                    '<div class="srl-success-container" style="text-align: center; padding: 40px 20px;">' +
                        '<div class="srl-success-icon" style="font-size: 80px; color: #27ae60; margin-bottom: 20px;">' +
                            '✅' +
                        '</div>' +
                        '<h2 style="color: #27ae60; margin-bottom: 20px; font-size: 28px; font-weight: 600;">' +
                            'Voucher przesłany do akceptacji!' +
                        '</h2>' +
                        '<div style="background: #eaf4ea; border: 2px solid #27ae60; border-radius: 12px; padding: 25px; margin: 20px 0; color: #155724;">' +
                            '<h3 style="margin-top: 0; font-size: 18px;">Co dalej?</h3>' +
                            '<ul style="text-align: left; line-height: 1.6; margin: 15px 0;">' +
                                '<li>📧 <strong>Otrzymasz email z potwierdzeniem</strong> zgłoszenia</li>' +
                                '<li>🔍 <strong>Nasz zespół zweryfikuje</strong> podane dane</li>' +
                                '<li>⏰ <strong>Proces weryfikacji</strong> trwa 1-2 dni robocze</li>' +
                                '<li>✈️ <strong>Po zatwierdzeniu</strong> otrzymasz loty do rezerwacji</li>' +
                            '</ul>' +
                        '</div>' +
                        '<div style="margin-top: 30px;">' +
                            '<button type="button" id="srl-success-close-btn" class="srl-btn srl-btn-success" style="padding: 15px 40px; font-size: 16px;">' +
                                'Zamknij' +
                            '</button>' +
                        '</div>' +
                    '</div>'
                );
                
                $('.srl-modal-header h3').text('Sukces!');
                
                $('#srl-success-close-btn').on('click', function() {
                    closeSuccessModal();
                });
            }

            function closeSuccessModal() {
                $('#srl-partner-voucher-modal').hide();
                $('#srl-partner-voucher-form')[0].reset();
                $('#srl-passengers-container').hide();
                $('#srl-passengers-forms').empty();
                $('#srl-voucher-type-select').prop('disabled', true).html('<option value="">Najpierw wybierz partnera</option>');
                
                setTimeout(function() {
                    location.reload();
                }, 300);
            }
            
            function srlGeneratePassengerForms(count) {
                var container = $('#srl-passengers-forms');
                container.empty();
                
                for (var i = 1; i <= count; i++) {
                    var formHtml = '<div class="srl-passenger-form" style="border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 8px;">';
                    formHtml += '<h5>Pasażer ' + i + '</h5>';
                    formHtml += '<div class="srl-form-grid">';
                    
                    formHtml += '<div class="srl-form-group">';
                    formHtml += '<label>Imię *</label>';
                    formHtml += '<input type="text" class="passenger-imie" required>';
                    formHtml += '</div>';
                    
                    formHtml += '<div class="srl-form-group">';
                    formHtml += '<label>Nazwisko *</label>';
                    formHtml += '<input type="text" class="passenger-nazwisko" required>';
                    formHtml += '</div>';
                    
                    formHtml += '<div class="srl-form-group">';
                    formHtml += '<label>Rok urodzenia *</label>';
                    formHtml += '<input type="number" class="passenger-rok" min="1920" max="2020" required>';
                    formHtml += '</div>';
                    
                    formHtml += '<div class="srl-form-group">';
                    formHtml += '<label>Telefon *</label>';
                    formHtml += '<input type="tel" class="passenger-telefon" required>';
                    formHtml += '</div>';
                    
                    formHtml += '<div class="srl-form-group">';
                    formHtml += '<label>Kategoria wagowa *</label>';
                    formHtml += '<select class="passenger-kategoria" required>';
                    formHtml += '<option value="">Wybierz kategorię</option>';
                    formHtml += '<option value="25-40kg">25-40kg</option>';
                    formHtml += '<option value="41-60kg">41-60kg</option>';
                    formHtml += '<option value="61-90kg">61-90kg</option>';
                    formHtml += '<option value="91-120kg">91-120kg</option>';
                    formHtml += '<option value="120kg+">120kg+</option>';
                    formHtml += '</select>';
                    formHtml += '</div>';
                    
                    formHtml += '<div class="srl-form-group">';
                    formHtml += '<label>Sprawność fizyczna *</label>';
                    formHtml += '<select class="passenger-sprawnosc" required>';
                    formHtml += '<option value="">Wybierz poziom</option>';
                    formHtml += '<option value="zdolnosc_do_marszu">Zdolność do marszu</option>';
                    formHtml += '<option value="zdolnosc_do_biegu">Zdolność do biegu</option>';
                    formHtml += '<option value="sprinter">Sprinter!</option>';
                    formHtml += '</select>';
                    formHtml += '</div>';
                    
                    formHtml += '<div class="srl-form-group srl-full-width">';
                    formHtml += '<label>Dodatkowe uwagi</label>';
                    formHtml += '<textarea class="passenger-uwagi" rows="3" placeholder="Np. alergie, obawy, specjalne potrzeby..."></textarea>';
                    formHtml += '</div>';
                    
                    formHtml += '</div>';
                    formHtml += '<div class="srl-passenger-age-warning-' + i + '" style="margin-top:15px;"></div>';
                    formHtml += '<div class="srl-passenger-weight-warning-' + i + '" style="margin-top:15px;"></div>';
                    formHtml += '</div>';
                    
                    container.append(formHtml);
                }
                
                var regulaminHtml = '<div class="srl-regulamin-section" style="border-top: 2px solid #ddd; padding-top: 20px; margin-top: 20px;">';
                regulaminHtml += '<label style="display: flex; align-items: center; gap: 8px; font-weight: 500;">';
                regulaminHtml += '<input type="checkbox" id="srl-all-passengers-regulamin" required>';
                regulaminHtml += 'Akceptuję <a href="/regulamin/" target="_blank" style="color: #0073aa; text-decoration: none;">Regulamin</a> w imieniu wszystkich pasażerów *';
                regulaminHtml += '</label>';
                regulaminHtml += '</div>';
                
                container.append(regulaminHtml);
                
                setTimeout(function() {
                    for (var i = 1; i <= count; i++) {
                        var rokVal = $('.srl-passenger-form').eq(i - 1).find('.passenger-rok').val();
                        var katVal = $('.srl-passenger-form').eq(i - 1).find('.passenger-kategoria').val();
                        
                        if (rokVal) {
                            srlValidatePassengerAge(i);
                        }
                        if (katVal) {
                            srlValidatePassengerWeight(i);
                        }
                    }
                }, 100);
            }	
            
            function srlValidatePassengerAge(passengerIndex) {
                var rokInput = $('.srl-passenger-form').eq(passengerIndex - 1).find('.passenger-rok');
                var rok = rokInput.val();
                
                if (!rok) {
                    $('.srl-passenger-age-warning-' + passengerIndex).hide();
                    return;
                }
                
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'srl_waliduj_wiek',
                    rok_urodzenia: rok,
                    nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>'
                }, function(response) {
                    if (response.success && response.data.html) {
                        $('.srl-passenger-age-warning-' + passengerIndex).html(response.data.html).show();
                    } else {
                        $('.srl-passenger-age-warning-' + passengerIndex).hide();
                    }
                });
            }

            function srlValidatePassengerWeight(passengerIndex) {
                var kategoriaInput = $('.srl-passenger-form').eq(passengerIndex - 1).find('.passenger-kategoria');
                var kategoria = kategoriaInput.val();
                
                if (!kategoria) {
                    $('.srl-passenger-weight-warning-' + passengerIndex).hide();
                    return;
                }
                
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'srl_waliduj_kategorie_wagowa',
                    kategoria_wagowa: kategoria,
                    nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>'
                }, function(response) {
                    if (response.success && response.data.html) {
                        $('.srl-passenger-weight-warning-' + passengerIndex).html(response.data.html).show();
                    } else {
                        $('.srl-passenger-weight-warning-' + passengerIndex).hide();
                    }
                });
            }
        });
        </script>
        <?php
    }

    private function komunikatNiezalogowany() {
        ob_start();
        ?>
        <div id="srl-auth-container">
            <div class="srl-auth-header">
                <h3 style="text-transform: uppercase;">Logowanie wymagane</h3>
                <p>Aby dokonać rezerwacji lotu lub wykorzystać voucher, musisz być zalogowany.</p>
            </div>
            
            <div class="srl-auth-tabs">
                <button class="srl-tab-btn srl-tab-active" data-tab="login">Logowanie</button>
                <button class="srl-tab-btn" data-tab="register">Rejestracja</button>
            </div>
            
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
            $('.srl-tab-btn').on('click', function() {
                var tabId = $(this).data('tab');
                
                $('.srl-tab-btn').removeClass('srl-tab-active');
                $('.srl-tab-content').removeClass('srl-tab-active');
                
                $(this).addClass('srl-tab-active');
                $('#srl-' + tabId + '-tab').addClass('srl-tab-active');
            });
            
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
                    nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>'
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
                    nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>'
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

    private function komunikatBrakLotow() {
        ob_start();
        ?>
        <div class="srl-komunikat srl-komunikat-info">
            <h3>Brak dostępnych lotów</h3>
            <p>Nie masz jeszcze żadnych lotów do zarezerwowania.</p>
        </div>
        
        <div id="srl-voucher-section" style="margin-top: 30px;">
            <?php echo $this->generujSekcjeVoucherow(); ?>
        </div>
        
        <?php $this->generujJsVoucherow(); ?>
        <?php
        return ob_get_clean();
    }

    private function pobierzDostepneLoty($user_id) {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_zakupione_loty';
        
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
}