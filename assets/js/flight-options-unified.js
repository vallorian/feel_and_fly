// ==========================================================================
// UJEDNOLICONY SYSTEM OBSŁUGI OPCJI LOTÓW - WERSJA FINALNA
// ==========================================================================
console.log('🎯 [INIT] flight-options-unified.js START', new Date());

jQuery(document).ready(function($) {
    console.log('🎯 [INIT] jQuery ready w flight-options-unified.js');
    
    // ==========================================================================
    // Globalna funkcja dodawania opcji do koszyka
    // ==========================================================================
    
    window.srlDodajOpcjeLotu = function(lotId, productId, optionName) {
		console.log('🎯 [DEBUG] Wywołano srlDodajOpcjeLotu:', {lotId, productId, optionName});
		
		// SPRAWDŹ CZY OPCJA JUŻ JEST W KOSZYKU
		var button = $('#srl-opcja-' + lotId + '-' + productId);
		if (!button.length) {
			button = $('button[onclick*="srlDodajOpcjeLotu(' + lotId + ', ' + productId + ')"]');
		}
		if (!button.length) {
			button = $('.srl-add-option[data-lot-id="' + lotId + '"][data-product-id="' + productId + '"]');
		}
		
		// Jeśli przycisk ma klasę "warning" to znaczy że jest już w koszyku
		if (button.hasClass('srl-btn-warning')) {
			console.log('⚠️ [WARNING] Opcja już jest w koszyku - pomijanie');
			return false;
		}
        
        // Znajdź przycisk - różne sposoby znajdowania
        var button = $('#srl-opcja-' + lotId + '-' + productId);
        if (!button.length) {
            button = $('button[onclick*="srlDodajOpcjeLotu(' + lotId + ', ' + productId + ')"]');
        }
        if (!button.length) {
            button = $('.srl-add-option[data-lot-id="' + lotId + '"][data-product-id="' + productId + '"]');
        }
        
        console.log('🔍 [DEBUG] Znaleziony przycisk:', button.length ? button[0] : 'BRAK');
        
        if (!button.length) {
            console.error('❌ [ERROR] Nie znaleziono przycisku dla:', {lotId, productId});
            pokazKomunikatOpcji('❌ Błąd: Nie znaleziono przycisku opcji', 'error');
            return false;
        }
        
        var originalText = button.text();
        
        // Natychmiast zablokuj przycisk
        if (button.prop('disabled')) {
            console.warn('⚠️ [WARNING] Przycisk już zablokowany');
            return false;
        }
        
        console.log('⏳ [DEBUG] Blokowanie przycisku...');
        button.text('Dodawanie...').prop('disabled', true);
        
        $.ajax({
			url: (typeof srlFrontend !== 'undefined') ? srlFrontend.ajaxurl : ajaxurl,
			method: 'POST',
			data: {
				action: 'srl_sprawdz_i_dodaj_opcje',
				product_id: productId,
				quantity: 1,
				srl_lot_id: lotId,
				nonce: (typeof srlFrontend !== 'undefined') ? srlFrontend.nonce : '' // ⬅️ DODAJ NONCE
			},
            success: function(response) {
                console.log('✅ [DEBUG] Odpowiedź AJAX:', response);
                
                if (response && !response.error) {
                    // Pokaż komunikat sukcesu
                    pokazKomunikatOpcji('✅ Dodano "' + optionName + '" do koszyka!', 'success');
                    
                    // Zmień przycisk na "w koszyku"
                    przemieniPrzyciskNaWKoszyku(button, lotId, productId, optionName);
                    
                    // Wyślij event o zmianie opcji
                    $(document).trigger('srl_opcje_zmienione');
                    
                    // Aktualizuj licznik koszyka
                    if (typeof wc_add_to_cart_params !== 'undefined') {
                        $(document.body).trigger('wc_fragment_refresh');
                    }
                } else {
                    console.error('❌ [ERROR] Błąd dodawania do koszyka:', response);
                    pokazKomunikatOpcji('❌ Błąd dodawania do koszyka', 'error');
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ [ERROR] Błąd AJAX:', {xhr, status, error});
                pokazKomunikatOpcji('❌ Błąd połączenia z serwerem', 'error');
                button.text(originalText).prop('disabled', false);
            }
        });
    };
    
    // ==========================================================================
    // Funkcja przekształcania przycisku na stan "w koszyku"
    // ==========================================================================
    
    function przemieniPrzyciskNaWKoszyku(button, lotId, productId, optionName) {
        console.log('🔄 [DEBUG] Przekształcanie przycisku na "w koszyku":', {lotId, productId, optionName});
        
        // Usuń stare klasy, dodaj nowe
        button.removeClass('srl-btn-secondary button-small')
              .addClass('srl-btn-warning');
        
        // Zmień kolory
        button.css({
            'background': '#ff9800', 
            'border-color': '#ff9800', 
            'color': 'white',
            'pointer-events': 'auto',
            'opacity': '1'
        });
        
        // Skróć nazwę opcji dla wyświetlenia
        var krotkanazwa = optionName;
        if (optionName === 'Filmowanie lotu') krotkanazwa = 'Filmowanie';
        if (optionName === 'Akrobacje podczas lotu') krotkanazwa = 'Akrobacje';
        if (optionName === 'Przedłużenie ważności') krotkanazwa = 'Przedłużenie';
        
        // Nowa zawartość z przyciskiem X
        var nowaZawartosc = '+ ' + krotkanazwa + ' (w koszyku) ' +
            '<span class="srl-remove-from-cart" ' +
			  'data-lot-id="' + lotId + '" ' +
			  'data-product-id="' + productId + '" ' +
			  'style="margin-left: 10px; cursor: pointer; font-weight: bold; font-size: 14px; padding: 2px 6px; border-radius: 50%; transition: all 0.2s ease;" ' +
			  'onmouseenter="this.style.backgroundColor=\'#dc3545\'; this.style.color=\'white\';" ' +
			  'onmouseleave="this.style.backgroundColor=\'\'; this.style.color=\'white\';">✕</span>';
        
        button.html(nowaZawartosc);
		// USUŃ onclick handler, żeby nie kolidował z X
		button.removeAttr('onclick');
        
        console.log('✅ [DEBUG] Przycisk przekształcony');
    }
    
    // ==========================================================================
    // Funkcja przywracania przycisku do oryginalnego stanu
    // ==========================================================================
    
    function przywrocPrzyciskDoOryginalnegoStanu(button, lotId, productId) {
        console.log('🔄 [DEBUG] Przywracanie przycisku do oryginalnego stanu:', {lotId, productId});
        
		// Określ krótką nazwę opcji na podstawie productId
        var optionName = '';
        switch(productId.toString()) {
            case '116':
                optionName = 'Filmowanie';
                break;
            case '117':
                optionName = 'Akrobacje';
                break;
            case '115':
                optionName = 'Przedłużenie';
                break;
            default:
                optionName = 'Opcja lotu';
        }
        
        // Przywróć oryginalne klasy i style
        button.removeClass('srl-btn-warning')
              .addClass('srl-btn-secondary button-small');
        
        button.css({
            'background': '', 
            'border-color': '', 
            'color': '',
            'opacity': '1'
        });
        
        // Przywróć oryginalny tekst i onclick
        button.html('+ ' + optionName);
        button.prop('disabled', false);
        
		// Przywróć onclick handler z pełną nazwą dla backend
        var pelnaName = optionName;
        if (productId == 116) pelnaName = 'Filmowanie lotu';
        if (productId == 117) pelnaName = 'Akrobacje podczas lotu'; 
        if (productId == 115) pelnaName = 'Przedłużenie ważności';
        
        button.attr('onclick', 'srlDodajOpcjeLotu(' + lotId + ', ' + productId + ', \'' + pelnaName + '\')');
        
        console.log('✅ [DEBUG] Przycisk przywrócony i gotowy do ponownego użycia');
    }
    
    // ==========================================================================
    // Funkcja pokazywania komunikatów
    // ==========================================================================
    
    function pokazKomunikatOpcji(tekst, typ) {
        console.log('📢 [DEBUG] Pokazywanie komunikatu:', {tekst, typ});
        
        // Sprawdź czy jesteśmy w sekcji z powiadomieniami
        if ($('#srl-cart-notification').length) {
            // System powiadomień w "moje konto"
            var bgColor = typ === 'success' ? '#46b450' : '#d63638';
            $('#srl-cart-message').html(tekst);
            $('#srl-cart-notification').css('background', bgColor).fadeIn().delay(3000).fadeOut();
        } else if (typeof pokazKomunikat === 'function') {
            // System powiadomień w rezerwacji
            pokazKomunikat(tekst, typ);
        } else {
            // Fallback - stwórz własny komunikat
            var notification = $('<div style="position:fixed; top:20px; right:20px; background:' + 
                (typ === 'success' ? '#46b450' : '#d63638') + 
                '; color:white; padding:15px 20px; border-radius:8px; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.3);">' + 
                tekst + '</div>');
            $('body').append(notification);
            setTimeout(function() {
                notification.fadeOut(function() {
                    notification.remove();
                });
            }, 3000);
        }
    }
    
    // ==========================================================================
    // Sprawdzanie zawartości koszyka przy ładowaniu
    // ==========================================================================
    
    function sprawdzOpcjeWKoszyku() {
        console.log('🔍 [DEBUG] Sprawdzanie opcji w koszyku...');
        
        var ajaxUrl = (typeof srlFrontend !== 'undefined') ? srlFrontend.ajaxurl : ajaxurl;
        var nonce = (typeof srlFrontend !== 'undefined') ? srlFrontend.nonce : '';
        
        if (!nonce) {
            console.warn('⚠️ [WARNING] Brak nonce - pomijanie sprawdzania koszyka');
            return;
        }
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'srl_sprawdz_opcje_w_koszyku',
                nonce: nonce
            },
            success: function(response) {
                console.log('📦 [DEBUG] Odpowiedź sprawdzania koszyka:', response);
                
                if (response.success && response.data) {
                    $.each(response.data, function(lotId, opcje) {
                        console.log('🎫 [DEBUG] Przetwarzanie opcji dla lotu:', {lotId, opcje});
                        
                        if (opcje.filmowanie) {
                            oznaczOpcjeJakoWKoszyku(lotId, 116, 'Filmowanie lotu');
                        }
                        if (opcje.akrobacje) {
                            oznaczOpcjeJakoWKoszyku(lotId, 117, 'Akrobacje podczas lotu');
                        }
                        if (opcje.przedluzenie) {
                            oznaczOpcjeJakoWKoszyku(lotId, 115, 'Przedłużenie ważności');
                        }
                    });
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ [ERROR] Błąd sprawdzania koszyka:', {xhr, status, error});
            }
        });
    }
    
    // ==========================================================================
    // Oznaczanie opcji jako "w koszyku"
    // ==========================================================================
    
    function oznaczOpcjeJakoWKoszyku(lotId, productId, optionName) {
        console.log('🏷️ [DEBUG] Oznaczanie opcji jako w koszyku:', {lotId, productId, optionName});
        
        // Znajdź przycisk - różne sposoby
        var button = $('#srl-opcja-' + lotId + '-' + productId);
        if (!button.length) {
            button = $('.srl-add-option[data-lot-id="' + lotId + '"][data-product-id="' + productId + '"]');
        }
        if (!button.length) {
            button = $('button[onclick*="srlDodajOpcjeLotu(' + lotId + ', ' + productId + ')"]');
        }
        
        if (button.length) {
            przemieniPrzyciskNaWKoszyku(button, lotId, productId, optionName);
            console.log('✅ [DEBUG] Opcja oznaczona jako w koszyku');
        } else {
            console.warn('⚠️ [WARNING] Nie znaleziono przycisku dla opcji:', {lotId, productId});
        }
    }
    
    // ==========================================================================
    // Funkcja podłączająca event listenery
    // ==========================================================================
    
    function podlaczEventListenery() {
        console.log('🔌 [DEBUG] Podłączanie event listenerów...');
        
        // Wyłącz disabled na wszystkich przyciskach z X
        $('.srl-remove-from-cart').closest('button').prop('disabled', false);
        console.log('🔓 [DEBUG] Wyłączono disabled na przyciskach z X');
        
        // JEDEN JEDYNY event listener na usuwanie z koszyka
        $(document).off('click', '.srl-remove-from-cart').on('click', '.srl-remove-from-cart', function(e) {
            e.stopImmediatePropagation();
            e.stopPropagation();
            e.preventDefault();
            
            console.log('🗑️ [DEBUG] Kliknięto usuwanie z koszyka');
            
            var lotId = $(this).data('lot-id');
            var productId = $(this).data('product-id');
            var button = $(this).closest('button');
            
            console.log('📋 [DEBUG] Dane usuwania:', {lotId, productId});
            
            if (!lotId || !productId) {
                console.error('❌ [ERROR] Brak danych do usuwania:', {lotId, productId});
                pokazKomunikatOpcji('❌ Błąd: Brak danych opcji do usunięcia', 'error');
                return;
            }
            
            // BEZ POTWIERDZENIA - usuwamy od razu
            console.log('⏳ [DEBUG] Usuwanie bez potwierdzenia...');
            
            // Natychmiast zmień przycisk na "usuwanie..."
            var originalHtml = button.html();
            button.html('Usuwanie...');
            button.css('opacity', '0.6');
            
            var ajaxUrl = (typeof srlFrontend !== 'undefined') ? srlFrontend.ajaxurl : ajaxurl;
            var nonce = (typeof srlFrontend !== 'undefined') ? srlFrontend.nonce : '';
            
            if (!nonce) {
                console.error('❌ [ERROR] Brak nonce dla usuwania');
                pokazKomunikatOpcji('❌ Błąd: Brak uprawnień do usuwania', 'error');
                button.html(originalHtml);
                button.css('opacity', '1');
                return;
            }
            
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action: 'srl_usun_opcje_z_koszyka',
                    lot_id: lotId,
                    product_id: productId,
                    nonce: nonce
                },
                success: function(response) {
                    console.log('✅ [DEBUG] Odpowiedź usuwania:', response);
                    
                    if (response.success) {
                        // NATYCHMIASTOWE przywrócenie przycisku
                        przywrocPrzyciskDoOryginalnegoStanu(button, lotId, productId);
                        
                        // Wyślij event o zmianie opcji
                        $(document).trigger('srl_opcje_zmienione');
                        
                        // Pokaż komunikat sukcesu
                        pokazKomunikatOpcji('✅ Opcja usunięta z koszyka', 'success');
                        
                        // Aktualizuj licznik koszyka
                        if (typeof wc_add_to_cart_params !== 'undefined') {
                            $(document.body).trigger('wc_fragment_refresh');
                        }
                    } else {
                        console.error('❌ [ERROR] Błąd usuwania z koszyka:', response.data);
                        pokazKomunikatOpcji('❌ Błąd usuwania: ' + response.data, 'error');
                        button.html(originalHtml);
                        button.css('opacity', '1');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ [ERROR] Błąd AJAX usuwania:', {xhr, status, error});
                    pokazKomunikatOpcji('❌ Błąd połączenia z serwerem', 'error');
                    button.html(originalHtml);
                    button.css('opacity', '1');
                }
            });
        });
        
        console.log('✅ [DEBUG] Event listener podłączony, przycisków X:', $('.srl-remove-from-cart').length);
    }
    
    // ==========================================================================
    // Inicjalizacja przy ładowaniu
    // ==========================================================================
    
    // Podłącz event listenery od razu
    podlaczEventListenery();
    
    // I ponownie po zmianie opcji
    $(document).on('srl_opcje_zmienione', function() {
        console.log('🔄 [DEBUG] Opcje zmienione - ponowne podłączanie...');
        setTimeout(podlaczEventListenery, 100);
    });
    
    // Sprawdź opcje w koszyku po załadowaniu strony
    setTimeout(function() {
        sprawdzOpcjeWKoszyku();
    }, 1000);
    
    // Ponowne sprawdzenie po załadowaniu danych klienta
    $(document).on('srl_dane_klienta_zaladowane', function() {
        console.log('🔄 [DEBUG] Dane klienta załadowane - sprawdzanie koszyka...');
        setTimeout(function() {
            sprawdzOpcjeWKoszyku();
            podlaczEventListenery(); // Ponownie podłącz listenery
        }, 500);
    });
    
    console.log('🎯 [DEBUG] System opcji lotów zainicjalizowany');
});