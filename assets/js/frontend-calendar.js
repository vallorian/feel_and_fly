// Frontend Calendar & Reservation System
jQuery(document).ready(function($) {
    
    // ==========================================================================
    // Zmienne globalne
    // ==========================================================================
    
    var aktualnyKrok = 1;
    var maksymalnyKrok = 1;
    var aktualnyMiesiac = new Date().getMonth() + 1;
    var aktualnyRok = new Date().getFullYear();
    var wybranaDana = null;
    var wybranySlot = null;
    var wybranyLot = null;
    var daneKlienta = null;
    var tymczasowaBlokada = null;
	
	// Udostępnij zmienne globalnie dla funkcji walidacyjnych
    window.wybranyLot = null;
    window.daneKlienta = null;
    
	
	// ==========================================================================
    // Nowe funkcje pomocnicze
    // ==========================================================================
    
    function aktualizujWybranyLotInfo() {
		var aktualnyLot = wybranyLot || window.wybranyLot;
        var aktualneDane = daneKlienta || window.daneKlienta;
        
        if (!aktualnyLot || !aktualneDane || !aktualneDane.dostepne_loty) return;
        
        var lot = aktualneDane.dostepne_loty.find(function(l) {
            return l.id == aktualnyLot;
        });
        
        if (lot) {
            // Usuń wariant z nazwy produktu i ujednolic na "Lot w tandemie"
            var nazwaBezWariantu = lot.nazwa_produktu.split(' - ')[0];
            
            // Ujednolic wszystkie nazwy na "Lot w tandemie"
            if (nazwaBezWariantu.toLowerCase().includes('voucher') || 
                nazwaBezWariantu.toLowerCase().includes('lot') ||
                nazwaBezWariantu.toLowerCase().includes('tandem')) {
                nazwaBezWariantu = 'Lot w tandemie';
            }
            
            // Dodaj informacje o opcjach w tej samej linii
            var opcje_tekst = [];
            if (lot.ma_filmowanie && lot.ma_filmowanie != '0') opcje_tekst.push('+ filmowanie');
            if (lot.ma_akrobacje && lot.ma_akrobacje != '0') opcje_tekst.push('+ akrobacje');
            
            var html = '<strong>Lot #' + lot.id + ' – ' + escapeHtml(nazwaBezWariantu);
            if (opcje_tekst.length > 0) {
                html += ' <span style="color: #46b450; font-weight: bold;">' + opcje_tekst.join(' ') + '</span>';
            }
            html += '</strong>';
            
            // Dodaj informacje o voucherze
            if (lot.kod_vouchera) {
                html += '<br><small style="color:#d63638; font-weight:bold;">🎁 Z vouchera: ' + escapeHtml(lot.kod_vouchera) + '</small>';
            }
            
            $('#srl-wybrany-lot-szczegoly').html(html);
        }
    }
	
	
    // ==========================================================================
    // Inicjalizacja
    // ==========================================================================
    
    init();
    
    function init() {
        // Załaduj dane klienta
        zaladujDaneKlienta();
        
        // Podłącz nasłuchy
        podlaczNasluchy();
    }
    
    // ==========================================================================
    // Zarządzanie krokami
    // ==========================================================================
    
	function pokazKrok(nrKroku) {
		if (nrKroku < 1 || nrKroku > 5) return;
		
		// Aktualizuj progress bar
		$('.srl-step').removeClass('srl-step-active srl-step-completed');
		
		// Usuń poprzednie klasy progress
		$('.srl-progress-bar').removeClass('srl-progress-1 srl-progress-2 srl-progress-3 srl-progress-4 srl-progress-5');
		
		// Dodaj odpowiednią klasę progress
		$('.srl-progress-bar').addClass('srl-progress-' + nrKroku);
		
		for (var i = 1; i <= 5; i++) {
			var step = $('.srl-step[data-step="' + i + '"]');
			if (i < nrKroku) {
				step.addClass('srl-step-completed');
			} else if (i === nrKroku) {
				step.addClass('srl-step-active');
			}
		}
		
		// Pokaż odpowiedni krok
		$('.srl-krok').removeClass('srl-krok-active');
		$('#srl-krok-' + nrKroku).addClass('srl-krok-active');
		
		aktualnyKrok = nrKroku;
		maksymalnyKrok = Math.max(maksymalnyKrok, nrKroku);
		
		// Scrolluj do góry
		$('html, body').animate({
			scrollTop: $('#srl-rezerwacja-container').offset().top - 50
		}, 300);
	}
    
    function podlaczNasluchy() {
        // Kliknięcie w progress bar
        $('.srl-step').on('click', function() {
            var krok = parseInt($(this).data('step'));
            if (krok <= maksymalnyKrok) {
                pokazKrok(krok);
            }
        });
        
        // Formularz danych pasażera
        $('#srl-formularz-pasazera').on('submit', function(e) {
            e.preventDefault();
            zapiszDanePasazera();
        });
        
        // Nawigacja kalendarz
        $('#srl-poprzedni-miesiac').on('click', function() {
            zmienMiesiac(-1);
        });
        
        $('#srl-nastepny-miesiac').on('click', function() {
            zmienMiesiac(1);
        });
        
		// Przyciski nawigacji
        $('#srl-powrot-krok-1').on('click', function() { pokazKrok(1); });
        $('#srl-powrot-krok-2').on('click', function() { pokazKrok(2); });
        $('#srl-powrot-krok-3').on('click', function() { pokazKrok(3); });
        $('#srl-powrot-krok-4').on('click', function() { pokazKrok(4); });
        $('#srl-dalej-krok-5').on('click', function() { pokazKrok(5); });
        
        // Obsługa wyboru lotu w kroku 1
        $(document).on('click', '.srl-wybierz-lot', function() {
            var lotId = $(this).data('lot-id');
            wybranyLot = lotId;
            window.wybranyLot = lotId;
            pokazKrok(2);
            aktualizujWybranyLotInfo();
        });
        
        // Potwierdzenie rezerwacji
        $('#srl-potwierdz-rezerwacje').on('click', function() {
            dokonajRezerwacji();
        });
		
		// Walidacja przed przejściem do kroku 2
        function sprawdzWybranyLot() {
            if (!wybranyLot && !window.wybranyLot) {
                pokazKomunikat('Musisz najpierw wybrać lot do zarezerwowania.', 'error');
                return false;
            }
            return true;
        }
        
        // Blokada bezpośredniego przejścia do kroku 2 bez wybranego lotu
        $('.srl-step[data-step="2"]').on('click', function(e) {
            if (!wybranyLot && !window.wybranyLot) {
                e.preventDefault();
                e.stopPropagation();
                pokazKomunikat('Musisz najpierw wybrać lot do zarezerwowania.', 'error');
                return false;
            }
        });
    }
    
    // ==========================================================================
    // Krok 1: Dane klienta
    // ==========================================================================
    

function zaladujDaneKlienta() {
    console.log('🔄 [DEBUG] Ładowanie danych klienta...');
    pokazKomunikat('Ładowanie danych...', 'info');
    
    $.ajax({
        url: srlFrontend.ajaxurl,
        method: 'GET',
        data: {
            action: 'srl_pobierz_dane_klienta',
            nonce: srlFrontend.nonce
        },
        success: function(response) {
            ukryjKomunikat();
            
            if (response.success) {
                console.log('✅ [DEBUG] Dane klienta załadowane pomyślnie');
                daneKlienta = response.data;
                window.daneKlienta = response.data;
                wypelnijDaneKlienta();
                
                // *** NOWE: Wyślij event po załadowaniu danych ***
                $(document).trigger('srl_dane_klienta_zaladowane');
            } else {
                console.error('❌ [ERROR] Błąd ładowania danych:', response.data);
                pokazKomunikat('Błąd ładowania danych: ' + response.data, 'error');
            }
        },
        error: function(xhr, status, error) {
            ukryjKomunikat();
            console.error('❌ [ERROR] Błąd AJAX ładowania danych:', {xhr, status, error});
            pokazKomunikat('Błąd połączenia z serwerem.', 'error');
        }
    });
}

// Zaktualizuj także funkcję wypelnijListeRezerwacji
function wypelnijListeRezerwacji(lotySpolaczone) {
    var container = $('#srl-lista-rezerwacji');
    
    if (!lotySpolaczone || lotySpolaczone.length === 0) {
        container.html('<p class="srl-komunikat srl-komunikat-info">Nie masz żadnych lotów.</p>');
        return;
    }
    
    var html = '<table class="srl-tabela-lotow">';
    html += '<thead><tr><th class="srl-kolumna-nazwa">Nazwa</th><th class="srl-kolumna-status">Status i termin</th><th class="srl-kolumna-opcje">Opcje</th><th class="srl-kolumna-akcje">Akcje</th></tr></thead>';
    html += '<tbody>';
    
    lotySpolaczone.forEach(function(lot) {
        html += '<tr>';
        
        // Kolumna Nazwa (połączona: ID + Produkt + Data ważności)
        html += '<td class="srl-kolumna-nazwa">';
        html += '<div class="srl-nazwa-lotu">Lot w tandemie (#' + lot.id + ')</div>';

        // Pokaż opcje lotu jako płatne dodatki
        var opcje_tekst = [];
        if (lot.ma_filmowanie && lot.ma_filmowanie != '0') opcje_tekst.push('Filmowanie');
        if (lot.ma_akrobacje && lot.ma_akrobacje != '0') opcje_tekst.push('Akrobacje');

        if (opcje_tekst.length > 0) {
            html += '<div class="srl-opcje-lotu">+ ' + opcje_tekst.join(', ') + '</div>';
        }

        if (lot.kod_vouchera) {
            html += '<div class="srl-voucher-info">🎁 Z vouchera: ' + escapeHtml(lot.kod_vouchera) + '</div>';
        }
        
        // Data ważności mniejszą czcionką
        if (lot.data_waznosci) {
            html += '<div class="srl-data-waznosci">(Ważny do: ' + new Date(lot.data_waznosci).toLocaleDateString('pl-PL') + ')</div>';
        }
        html += '</td>';
        
        // Kolumna Status i termin (połączona)
        html += '<td class="srl-kolumna-status">';
        if (lot.status === 'zarezerwowany') {
            html += '<div class="srl-status-badge srl-status-zarezerwowany">Zarezerwowany</div>';
            if (lot.data && lot.godzina_start) {
                var dataLotu = new Date(lot.data);
                var nazwyDni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
                var dzienTygodnia = nazwyDni[dataLotu.getDay()];
                var dataStr = dataLotu.toLocaleDateString('pl-PL');
                var godzinaStr = lot.godzina_start.substring(0, 5);
                html += '<div class="srl-termin-info">';
				html += '<div class="srl-termin-data">' + dataStr + ', godz: ' + godzinaStr + '</div>';	
                html += '<div class="srl-termin-dzien">' + dzienTygodnia + '</div>';
                html += '</div>';
            }
        } else if (lot.status === 'wolny') {
            html += '<div class="srl-status-badge srl-status-wolny">Czeka na rezerwację</div>';
        }
        html += '</td>';
        
        // Opcje dokupowania - z nowymi klasami CSS
        html += '<td class="srl-kolumna-opcje">';
        if (lot.status === 'zarezerwowany') {
            var dataLotu = new Date(lot.data + ' ' + lot.godzina_start);
            var czasDoLotu = dataLotu.getTime() - Date.now();
            var moznaModyfikowac = czasDoLotu > 48 * 60 * 60 * 1000;
            
            if (moznaModyfikowac) {
                var dostepneOpcje = [];
                if (!lot.ma_filmowanie || lot.ma_filmowanie == '0') {
                    dostepneOpcje.push({nazwa: 'Filmowanie', id: 116});
                }
                if (!lot.ma_akrobacje || lot.ma_akrobacje == '0') {
                    dostepneOpcje.push({nazwa: 'Akrobacje', id: 117});
                }
                
                if (dostepneOpcje.length > 0) {
                    dostepneOpcje.forEach(function(opcja, index) {
                        html += '<button id="srl-opcja-' + lot.id + '-' + opcja.id + '" ' +
                               'class="srl-add-option srl-opcja-btn" ' +
                               'data-lot-id="' + lot.id + '" ' +
                               'data-product-id="' + opcja.id + '" ' +
                               'onclick="srlDodajOpcjeLotu(' + lot.id + ', ' + opcja.id + ', \'' + opcja.nazwa + '\')">' +
                               '+ ' + opcja.nazwa + '</button>';
                    });
                } else {
                    html += '<div class="srl-opcje-info">—</div>';
                }
            } else {
                html += '<div class="srl-opcje-info">Za późno na zmiany</div>';
            }
        } else if (lot.status === 'wolny') {
            // Opcje dla lotów czekających na rezerwację
            var dostepneOpcje = [];
            if (!lot.ma_filmowanie || lot.ma_filmowanie == '0') {
                dostepneOpcje.push({nazwa: 'Filmowanie', id: 116});
            }
            if (!lot.ma_akrobacje || lot.ma_akrobacje == '0') {
                dostepneOpcje.push({nazwa: 'Akrobacje', id: 117});
            }
            
            // Sprawdź czy lot potrzebuje przedłużenia (mniej niż 390 dni do wygaśnięcia)
            if (lot.data_waznosci) {
                var dniDoWaznosci = Math.floor((new Date(lot.data_waznosci).getTime() - Date.now()) / (24 * 60 * 60 * 1000));
                if (dniDoWaznosci <= 390) {
                    dostepneOpcje.push({nazwa: 'Przedłużenie', id: 115});
                }
            }
            
            if (dostepneOpcje.length > 0) {
                dostepneOpcje.forEach(function(opcja, index) {
                    html += '<button id="srl-opcja-' + lot.id + '-' + opcja.id + '" ' +
                           'class="srl-add-option srl-opcja-btn" ' +
                           'data-lot-id="' + lot.id + '" ' +
                           'data-product-id="' + opcja.id + '" ' +
                           'onclick="srlDodajOpcjeLotu(' + lot.id + ', ' + opcja.id + ', \'' + opcja.nazwa + '\')">' +
                           '+ ' + opcja.nazwa + '</button>';
                });
            } else {
                html += '<div class="srl-opcje-info">—</div>';
            }
        } else {
            html += '<div class="srl-opcje-info">—</div>';
        }
        html += '</td>';
        
        // Akcje - z nowymi klasami CSS
        html += '<td class="srl-kolumna-akcje">';
        if (lot.status === 'zarezerwowany') {
            var dataLotu = new Date(lot.data + ' ' + lot.godzina_start);
            var czasDoLotu = dataLotu.getTime() - Date.now();
            var moznaAnulowac = czasDoLotu > 48 * 60 * 60 * 1000;
            
            if (moznaAnulowac) {
                html += '<button class="srl-anuluj-rezerwacje srl-akcja-btn srl-btn-odwolaj" data-lot-id="' + lot.id + '">Odwołaj</button>';
            } else {
                html += '<div class="srl-akcje-info">Za późno</div>';
            }
        } else if (lot.status === 'wolny') {
            html += '<button class="srl-wybierz-lot srl-akcja-btn srl-btn-wybierz" data-lot-id="' + lot.id + '">Wybierz termin</button>';
        }
        html += '</td>';
        
        html += '</tr>';
    });
    
    html += '</tbody></table>';
    container.html(html);
    
    // Podłącz nasłuchy
    $('.srl-anuluj-rezerwacje').on('click', function() {
        var lotId = $(this).data('lot-id');
        anulujRezerwacje(lotId);
    });
    
    console.log('✅ [DEBUG] Lista lotów wypełniona z ' + lotySpolaczone.length + ' elementami');
}
    
	
	function wypelnijDaneKlienta() {
        // Zaktualizuj powitanie
        aktualizujPowitanie();
        
        // Połącz rezerwacje i dostępne loty w jedną listę
        var lotySpolaczone = [];
        
        // Dodaj zarezerwowane loty
        if (daneKlienta.rezerwacje) {
            daneKlienta.rezerwacje.forEach(function(rezerwacja) {
                lotySpolaczone.push(rezerwacja);
            });
        }
        
        // Dodaj dostępne loty (czekające na rezerwację)
        if (daneKlienta.dostepne_loty) {
            daneKlienta.dostepne_loty.forEach(function(lot) {
                lotySpolaczone.push(lot);
            });
        }
        
        // Sortuj: najpierw zarezerwowane, potem wolne
        lotySpolaczone.sort(function(a, b) {
            if (a.status === 'zarezerwowany' && b.status !== 'zarezerwowany') return -1;
            if (a.status !== 'zarezerwowany' && b.status === 'zarezerwowany') return 1;
            return 0;
        });
        
        // Wypełnij listę połączonych lotów
        wypelnijListeRezerwacji(lotySpolaczone);
        
        // Wypełnij formularz danych osobowych (dla kroku 2)
        wypelnijFormularzDanych(daneKlienta.dane_osobowe);
    }
    
	function aktualizujPowitanie() {
		var powitanie = 'Cześć';
		
		if (daneKlienta && daneKlienta.dane_osobowe) {
			var imie = daneKlienta.dane_osobowe.imie;
			var nazwisko = daneKlienta.dane_osobowe.nazwisko;
			
			if (imie && nazwisko) {
				powitanie = 'Cześć, ' + imie + ' ' + nazwisko;
			} else if (imie) {
				powitanie = 'Cześć, ' + imie;
			}
		}
		
		$('#srl-krok-1 h2').text(powitanie + '! 👋');
	}	
	
    
		function wypelnijFormularzDanych(dane) {
			$('#srl-imie').val(dane.imie || '');
			$('#srl-nazwisko').val(dane.nazwisko || '');
			$('#srl-rok-urodzenia').val(dane.rok_urodzenia || '');
			$('#srl-kategoria-wagowa').val(dane.kategoria_wagowa || '');
			$('#srl-sprawnosc-fizyczna').val(dane.sprawnosc_fizyczna || '');
			$('#srl-telefon').val(dane.telefon || '');
			$('#srl-uwagi').val(dane.uwagi || '');
			
			// Sprawdź ostrzeżenia wagowe przy ładowaniu
			if (typeof sprawdzKategorieWagowaWiekowa === 'function') {
				sprawdzKategorieWagowaWiekowa();
			}
		}
		
		function zapiszDanePasazera() {
		
		// Sprawdź czy wybrano lot
        if (!wybranyLot && !window.wybranyLot) {
            pokazKomunikat('Błąd: Nie wybrano lotu do rezerwacji. Wróć do kroku 1.', 'error');
            return;
        }
		var formData = {
			action: 'srl_zapisz_dane_pasazera',
			nonce: srlFrontend.nonce,
			imie: $('#srl-imie').val(),
			nazwisko: $('#srl-nazwisko').val(),
			rok_urodzenia: $('#srl-rok-urodzenia').val(),
			kategoria_wagowa: $('#srl-kategoria-wagowa').val(),
			sprawnosc_fizyczna: $('#srl-sprawnosc-fizyczna').val(),
			telefon: $('#srl-telefon').val(),
			uwagi: $('#srl-uwagi').val(),
			akceptacja_regulaminu: $('#srl-akceptacja-regulaminu').is(':checked')
		};
        
       
        var submitBtn = $('#srl-formularz-pasazera button[type="submit"]');
        submitBtn.prop('disabled', true).text('Zapisywanie...');
        
        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
					// Zaktualizuj dane w pamięci
					daneKlienta.dane_osobowe = {
						imie: formData.imie,
						nazwisko: formData.nazwisko,
						rok_urodzenia: formData.rok_urodzenia,
						kategoria_wagowa: formData.kategoria_wagowa,
						sprawnosc_fizyczna: formData.sprawnosc_fizyczna,
						telefon: formData.telefon,
						uwagi: formData.uwagi,
						akceptacja_regulaminu: formData.akceptacja_regulaminu
					};
					pokazKrok(3);
					zaladujKalendarz();
				} else {
					pokazKomunikat('Błąd: ' + response.data, 'error');
				}
            },
            error: function() {
                pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Zapisz i przejdź dalej →');
            }
        });
    }
    
    function anulujRezerwacje(lotId) {
        if (!confirm('Czy na pewno chcesz anulować tę rezerwację?')) {
            return;
        }
        
        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'POST',
            data: {
                action: 'srl_anuluj_rezerwacje_klient',
                nonce: srlFrontend.nonce,
                lot_id: lotId
            },
            success: function(response) {
                if (response.success) {
                    pokazKomunikat('Rezerwacja została anulowana.', 'success');
                    // Odśwież dane klienta
                    zaladujDaneKlienta();
                } else {
                    pokazKomunikat('Błąd: ' + response.data, 'error');
                }
            },
            error: function() {
                pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            }
        });
    }
    
    // ==========================================================================
    // Krok 2: Kalendarz
    // ==========================================================================
    
    function zaladujKalendarz() {
        aktualizujNawigacjeKalendarza();
        pobierzDostepneDni();
    }
    
    function aktualizujNawigacjeKalendarza() {
        var nazwyMiesiecy = [
            'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
            'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'
        ];
        
        $('#srl-miesiac-rok').text(nazwyMiesiecy[aktualnyMiesiac - 1] + ' ' + aktualnyRok);
    }
    
    function zmienMiesiac(kierunek) {
        aktualnyMiesiac += kierunek;
        
        if (aktualnyMiesiac > 12) {
            aktualnyMiesiac = 1;
            aktualnyRok++;
        } else if (aktualnyMiesiac < 1) {
            aktualnyMiesiac = 12;
            aktualnyRok--;
        }
        
        aktualizujNawigacjeKalendarza();
        pobierzDostepneDni();
    }
    
    function pobierzDostepneDni() {
        $('#srl-kalendarz-tabela').html('<div class="srl-loader">Ładowanie dostępnych terminów...</div>');
        
        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'GET',
            data: {
                action: 'srl_pobierz_dostepne_dni',
                nonce: srlFrontend.nonce,
                rok: aktualnyRok,
                miesiac: aktualnyMiesiac
            },
            success: function(response) {
                if (response.success) {
                    wygenerujKalendarz(response.data);
                } else {
                    $('#srl-kalendarz-tabela').html('<p class="srl-komunikat srl-komunikat-error">Błąd: ' + response.data + '</p>');
                }
            },
            error: function() {
                $('#srl-kalendarz-tabela').html('<p class="srl-komunikat srl-komunikat-error">Błąd połączenia z serwerem.</p>');
            }
        });
    }
    
    function wygenerujKalendarz(dostepneDni) {
        var pierwszyDzienMiesiaca = new Date(aktualnyRok, aktualnyMiesiac - 1, 1);
        var dniWMiesiacu = new Date(aktualnyRok, aktualnyMiesiac, 0).getDate();
        var pierwszyDzienTygodnia = pierwszyDzienMiesiaca.getDay(); // 0 = niedziela
        
        // Konwertuj na format pon-niedz (1-7)
        pierwszyDzienTygodnia = pierwszyDzienTygodnia === 0 ? 7 : pierwszyDzienTygodnia;
        
        var html = '<table class="srl-kalendarz-tabela">';
        html += '<thead><tr><th>Pon</th><th>Wt</th><th>Śr</th><th>Czw</th><th>Pt</th><th>Sob</th><th>Nd</th></tr></thead>';
        html += '<tbody>';
        
        var dzien = 1;
        var pustePrzed = pierwszyDzienTygodnia - 1;
        var calkowiteKomorki = Math.ceil((dniWMiesiacu + pustePrzed) / 7) * 7;
        
        for (var i = 0; i < calkowiteKomorki; i++) {
            if (i % 7 === 0) {
                html += '<tr>';
            }
            
            if (i < pustePrzed || dzien > dniWMiesiacu) {
                html += '<td class="srl-dzien-pusty"></td>';
            } else {
                var dataStr = aktualnyRok + '-' + pad2(aktualnyMiesiac) + '-' + pad2(dzien);
                var iloscSlotow = dostepneDni[dataStr] || 0;
                var klasa = iloscSlotow > 0 ? 'srl-dzien-dostepny' : 'srl-dzien-niedostepny';
                var dataAttr = iloscSlotow > 0 ? ' data-data="' + dataStr + '"' : '';
                
                html += '<td class="' + klasa + '"' + dataAttr + '>';
                html += '<div class="srl-dzien-numer">' + dzien + '</div>';
                if (iloscSlotow > 0) {
                    html += '<div class="srl-dzien-sloty">' + iloscSlotow + ' wolnych</div>';
                }
                html += '</td>';
                
                dzien++;
            }
            
            if ((i + 1) % 7 === 0) {
                html += '</tr>';
            }
        }
        
        html += '</tbody></table>';
        $('#srl-kalendarz-tabela').html(html);
        
		// Podłącz nasłuchy kliknięć
        $('.srl-dzien-dostepny').on('click', function() {
            wybranaDana = $(this).data('data');
            
            // Oznacz wybrany dzień
            $('.srl-dzien-wybrany').removeClass('srl-dzien-wybrany');
            $(this).addClass('srl-dzien-wybrany');
            
            // Przejdź do kroku 4
            pokazKrok(4);
            zaladujHarmonogram();
        });
    }
    
    // ==========================================================================
    // Krok 3: Harmonogram godzin
    // ==========================================================================
    
    function zaladujHarmonogram() {
        $('#srl-wybrany-dzien-info').html('<p><strong>Wybrany dzień:</strong> ' + formatujDate(wybranaDana) + '</p>');
        $('#srl-harmonogram-frontend').html('<div class="srl-loader">Ładowanie dostępnych godzin...</div>');
        
        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'GET',
            data: {
                action: 'srl_pobierz_dostepne_godziny',
                nonce: srlFrontend.nonce,
                data: wybranaDana
            },
            success: function(response) {
                if (response.success) {
                    wygenerujHarmonogram(response.data);
                } else {
                    $('#srl-harmonogram-frontend').html('<p class="srl-komunikat srl-komunikat-error">Błąd: ' + response.data + '</p>');
                }
            },
            error: function() {
                $('#srl-harmonogram-frontend').html('<p class="srl-komunikat srl-komunikat-error">Błąd połączenia z serwerem.</p>');
            }
        });
    }
    
    function wygenerujHarmonogram(sloty) {
        if (!sloty || sloty.length === 0) {
            $('#srl-harmonogram-frontend').html('<p class="srl-komunikat srl-komunikat-info">Brak dostępnych godzin w tym dniu.</p>');
            return;
        }
        
        // Sortuj sloty według czasu (godzina_start)
        sloty.sort(function(a, b) {
            return a.godzina_start.localeCompare(b.godzina_start);
        });
        
        var html = '<div class="srl-godziny-grid">';
        
        sloty.forEach(function(slot) {
            html += '<div class="srl-slot-godzina" data-slot-id="' + slot.id + '">';
            html += '<div class="srl-slot-czas">' + slot.godzina_start.substring(0, 5) + ' - ' + slot.godzina_koniec.substring(0, 5) + '</div>';
            html += '</div>';
        });
        
        html += '</div>';
        $('#srl-harmonogram-frontend').html(html);
        
        // Podłącz nasłuchy
        $('.srl-slot-godzina').on('click', function() {
            var slotId = $(this).data('slot-id');
            wybierzSlot(slotId, $(this));
        });
    }
    
    function wybierzSlot(slotId, element) {
        // Oznacz wybrany slot
        $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
        element.addClass('srl-slot-wybrany');
        
        wybranySlot = slotId;
        
        // Pokaż przycisk dalej
        $('#srl-dalej-krok-5').show();
        
        // Zablokuj slot tymczasowo
        zablokujSlotTymczasowo(slotId);
    }
    
    function zablokujSlotTymczasowo(slotId) {
        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'POST',
            data: {
                action: 'srl_zablokuj_slot_tymczasowo',
                nonce: srlFrontend.nonce,
                termin_id: slotId
            },
            success: function(response) {
                if (response.success) {
                    tymczasowaBlokada = response.data;
                    pokazKomunikat('Termin został zarezerwowany na 15 minut.', 'info');
                    
                    // Ustaw timer
					setTimeout(function() {
                        pokazKomunikat('Blokada terminu wygasła. Wybierz termin ponownie.', 'warning');
                        $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                        $('#srl-dalej-krok-5').hide();
                        wybranySlot = null;
                        tymczasowaBlokada = null;
                    }, 15 * 60 * 1000); // 15 minut
                    
                } else {
                    pokazKomunikat('Błąd blokady: ' + response.data, 'error');
                    $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                    wybranySlot = null;
                    $('#srl-dalej-krok-5').hide();
                }
            },
            error: function() {
                pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            }
        });
    }
    
    // ==========================================================================
    // Krok 4: Potwierdzenie
    // ==========================================================================
    
	function pokazKrok5() {
		var html = '<div class="srl-podsumowanie-box" style="background:#f8f9fa; padding:30px; border-radius:8px; margin:20px 0;">';
		html += '<h3 style="margin-top:0; color:#0073aa;">📋 Podsumowanie rezerwacji</h3>';
		
		// Znajdź dane wybranego lotu
		var lot = daneKlienta.dostepne_loty.find(function(l) {
			return l.id == wybranyLot;
		});
		
		// Znajdź dane wybranego slotu
		var slotInfo = tymczasowaBlokada ? tymczasowaBlokada.slot : null;
		
		html += '<div class="srl-podsumowanie-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin:20px 0;">';
		
		// Usuń wariant z nazwy produktu i ujednolic na "Lot w tandemie"
        var nazwaBezWariantu = lot.nazwa_produktu.split(' - ')[0];
        
        // Ujednolic wszystkie nazwy na "Lot w tandemie"
        if (nazwaBezWariantu.toLowerCase().includes('voucher') || 
            nazwaBezWariantu.toLowerCase().includes('lot') ||
            nazwaBezWariantu.toLowerCase().includes('tandem')) {
            nazwaBezWariantu = 'Lot w tandemie';
        }
        var opcje_tekst = [];
        if (lot.ma_filmowanie && lot.ma_filmowanie != '0') opcje_tekst.push('+ filmowanie');
        if (lot.ma_akrobacje && lot.ma_akrobacje != '0') opcje_tekst.push('+ akrobacje');
        
        var lotOpis = '#' + lot.id + ' – ' + escapeHtml(nazwaBezWariantu);
        if (opcje_tekst.length > 0) {
            lotOpis += ' <span style="color: #46b450; font-weight: bold;">' + opcje_tekst.join(' ') + '</span>';
        }
        
        html += '<div><strong>🎫 Wybrany lot:</strong><br>' + lotOpis + '</div>';
		html += '<div><strong>📅 Data lotu:</strong><br>' + formatujDate(wybranaDana) + '</div>';
		
		if (slotInfo) {
			html += '<div><strong>⏰ Godzina:</strong><br>' + slotInfo.godzina_start.substring(0, 5) + ' - ' + slotInfo.godzina_koniec.substring(0, 5) + '</div>';
			html += '<div></div>'; // Pusta komórka
		}
		
		html += '</div>';
		
		html += '<div class="srl-dane-pasazera-box" style="background:#f8f9fa; padding-top:30px; border-radius:8px; margin-top:20px;">';
		html += '<h3 style="margin-top:0; color:#0073aa;">🪪 Dane pasażera</h3>';
		html += '<p><strong>Imię i nazwisko:</strong> ' + $('#srl-imie').val() + ' ' + $('#srl-nazwisko').val() + '</p>';
		html += '<p><strong>Rok urodzenia:</strong> ' + $('#srl-rok-urodzenia').val() + '</p>';
		html += '<p><strong>Wiek:</strong> ' + (new Date().getFullYear() - $('#srl-rok-urodzenia').val()) + ' lat</p>';
		html += '<p><strong>Telefon:</strong> ' + $('#srl-telefon').val() + '</p>';
		html += '<p><strong>Sprawność fizyczna:</strong> ' + $('#srl-sprawnosc-fizyczna option:selected').text() + '</p>';
		html += '<p><strong>Kategoria wagowa:</strong> ' + $('#srl-kategoria-wagowa').val() + '</p>';

		// Dodaj komunikat wagowy jeśli istnieje
		var kategoriaWagowa = $('#srl-kategoria-wagowa').val();
		if (kategoriaWagowa === '91-120kg') {
			html += '<div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:15px; margin:15px 0;">';
			html += '<p style="margin:0;">Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji. Pilot ma prawo odmówić wykonania lotu jeśli uzna, że zagraża to bezpieczeństwu.</p>';
			html += '</div>';
		} else if (kategoriaWagowa === '120kg+') {
			html += '<div class="srl-uwaga" style="background:#fdeaea; border:2px solid #d63638; border-radius:8px; padding:15px; margin:15px 0;">';
			html += '<h4 style="margin-top:0; color:#721c24;">❌ Błąd wagowy:</h4>';
			html += '<p style="margin:0;">Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.</p>';
			html += '</div>';
		}

		var uwagi = $('#srl-uwagi').val();
		if (uwagi) {
			html += '<p><strong>Uwagi:</strong> ' + escapeHtml(uwagi) + '</p>';
		}

		html += '</div>';
		
		html += '<div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:20px;">';
		html += '<h4 style="margin-top:0; color:#f57c00;">⚠️ Ważne informacje:</h4>';
		html += '<ul style="margin:0; padding-left:20px;">';
		html += '<li>Zgłoś się 30 minut przed godziną lotu</li>';
		html += '<li>Weź ze sobą dokument tożsamości</li>';
		html += '<li>Ubierz się stosownie do warunków pogodowych</li>';
		html += '<li>Rezerwację można anulować do 48h przed lotem</li>';
		html += '</ul></div>';
		
		html += '</div>';
		
		$('#srl-podsumowanie-rezerwacji').html(html);
	}
    
    function dokonajRezerwacji() {
        if (!wybranySlot || !wybranyLot) {
            pokazKomunikat('Brak wybranych danych do rezerwacji.', 'error');
            return;
        }
        
        var btn = $('#srl-potwierdz-rezerwacje');
        btn.prop('disabled', true).text('Finalizowanie rezerwacji...');
        
        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'POST',
            data: {
                action: 'srl_dokonaj_rezerwacji',
                nonce: srlFrontend.nonce,
                termin_id: wybranySlot,
                lot_id: wybranyLot
            },
            success: function(response) {
                if (response.success) {
                    pokazKomunikatSukcesu();
                } else {
                    pokazKomunikat('Błąd rezerwacji: ' + response.data, 'error');
                    btn.prop('disabled', false).text('🎯 Potwierdź rezerwację');
                }
            },
            error: function() {
                pokazKomunikat('Błąd połączenia z serwerem.', 'error');
                btn.prop('disabled', false).text('🎯 Potwierdź rezerwację');
            }
        });
    }
    
    function pokazKomunikatSukcesu() {
        var html = '<div class="srl-komunikat srl-komunikat-success" style="text-align:center; padding:40px;">';
        html += '<h2 style="color:#46b450; margin-bottom:20px;">🎉 Rezerwacja potwierdzona!</h2>';
        html += '<p style="font-size:18px; margin-bottom:30px;">Twój lot tandemowy został zarezerwowany na <strong>' + formatujDate(wybranaDana) + '</strong></p>';
        html += '<p>Na podany adres email została wysłana informacja z szczegółami rezerwacji.</p>';
        html += '<div style="margin-top:30px;">';
        html += '<a href="' + window.location.href + '" class="srl-btn srl-btn-primary">Zarezerwuj kolejny lot</a>';
        html += '</div>';
        html += '</div>';
        
        $('#srl-rezerwacja-container').html(html);
    }
    
	
	
    // ==========================================================================
    // Funkcje pomocnicze
    // ==========================================================================
    
    function pokazKomunikat(tekst, typ) {
        var klasa = 'srl-komunikat-' + typ;
        var html = '<div class="srl-komunikat ' + klasa + '">' + tekst + '</div>';
        
        $('#srl-komunikaty').html(html);
        
        // Auto-ukryj po 5 sekundach
        setTimeout(function() {
            $('#srl-komunikaty').fadeOut();
        }, 5000);
    }
    
    function ukryjKomunikat() {
        $('#srl-komunikaty').empty();
    }
    
    function formatujDate(dataStr) {
        var data = new Date(dataStr);
        var dzien = data.getDate();
        var miesiac = data.getMonth() + 1;
        var rok = data.getFullYear();
        
        var nazwyDni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
        var nazwyMiesiecy = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 
                           'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
        
        return nazwyDni[data.getDay()] + ', ' + dzien + ' ' + nazwyMiesiecy[miesiac - 1] + ' ' + rok;
    }
    
function formatujDateICzas(dataStr, czasStr) {
    var data = new Date(dataStr);
    var dzien = data.getDate();
    var miesiac = data.getMonth() + 1;
    var rok = data.getFullYear();
    var godzina = czasStr.substring(0, 5);
    
    var nazwyDni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
    var nazwyMiesiecy = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 
                       'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    
    var dzien_tygodnia = nazwyDni[data.getDay()];
    var nazwa_miesiaca = nazwyMiesiecy[miesiac - 1];
    
    return dzien + '&nbsp;' + nazwa_miesiaca + '&nbsp;' + rok + '<br>godz.&nbsp;' + godzina + '<br>' + dzien_tygodnia;
}
    
    function pad2(n) {
        return (n < 10) ? '0' + n : '' + n;
    }
    
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }
    
    // Override pokazKrok dla kroku 5
    var originalPokaz = pokazKrok;
    pokazKrok = function(nrKroku) {
        originalPokaz(nrKroku);
        if (nrKroku === 5) {
            pokazKrok5();
        }
    };
   
	// ==========================================================================
// Walidacja kategorii wagowej i sprawdzenie kompatybilności z akrobacjami
// ==========================================================================

function sprawdzKategorieWagowaWiekowa() {
    var kategoria = $('#srl-kategoria-wagowa').val();
    var rokUrodzenia = parseInt($('#srl-rok-urodzenia').val());
    var aktualnyRok = new Date().getFullYear();
    var wiek = aktualnyRok - rokUrodzenia;
    
    var ostrzezenieDiv = $('#srl-waga-ostrzezenie');
    ostrzezenieDiv.hide();
    
    var komunikaty = [];
    
    // Sprawdź wiek
    if (rokUrodzenia && wiek <= 18) {
        komunikaty.push('<div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:10px;"><strong>Lot osoby niepełnoletniej:</strong> Osoby poniżej 18. roku życia mogą wziąć udział w locie tylko za zgodą rodzica lub opiekuna prawnego. Wymagane jest okazanie podpisanej, wydrukowanej zgody w dniu lotu, na miejscu startu. <a href="/zgoda-na-lot-osoba-nieletnia/" target="_blank" style="color:#f57c00; font-weight:bold;">Pobierz zgodę tutaj</a>.</div>');
    }
    
    // Sprawdź wagę
    if (kategoria === '91-120kg') {
        komunikaty.push('<div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:10px;"><strong>Uwaga:</strong> Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji. Pilot ma prawo odmówić wykonania lotu jeśli uzna, że zagraża to bezpieczeństwu.</div>');
    } else if (kategoria === '120kg+') {
        komunikaty.push('<div class="srl-uwaga" style="background:#fdeaea; border:2px solid #d63638; border-radius:8px; padding:20px; margin-top:10px; color:#721c24;"><strong>❌ Błąd:</strong> Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.</div>');
    }
    
    if (komunikaty.length > 0) {
        ostrzezenieDiv.html(komunikaty.join(''));
        ostrzezenieDiv.show();
    }
}

// Dodaj nasłuch na zmianę roku urodzenia
$(document).on('change', '#srl-rok-urodzenia', function() {
    sprawdzKategorieWagowaWiekowa();
});

function sprawdzKompatybilnoscZAkrobacjami() {
    var wybranyLotId = wybranyLot || window.wybranyLot;
    var kategoria = $('#srl-kategoria-wagowa').val();
    
    if (!wybranyLotId || !kategoria) return true;
    
    // Znajdź wybrany lot w danych
    var lot = daneKlienta.dostepne_loty.find(function(l) {
        return l.id == wybranyLotId;
    });
    
    if (!lot) return true;
    
    // Sprawdź czy to lot z akrobacjami (produkt ID: 67, 69, 76, 77)
    var akrobatyczneId = ['67', '69', '76', '77'];
    var czyAkrobatyczny = false;
    
    // Sprawdź czy nazwa produktu zawiera ID lub inne wskazówki
    akrobatyczneId.forEach(function(id) {
        if (lot.nazwa_produktu.indexOf(id) !== -1 || lot.nazwa_produktu.toLowerCase().indexOf('akrobacj') !== -1) {
            czyAkrobatyczny = true;
        }
    });
    
	if (czyAkrobatyczny && (kategoria === '91-120kg' || kategoria === '120kg+')) {
		pokazKomunikat('Wybrana kategoria wagowa (' + kategoria + ') nie jest dostępna dla lotów z akrobacjami.', 'error');
		return false;
	}
    
    if (kategoria === '120kg+') {
        pokazKomunikat('Loty nie są możliwe dla pasażerów powyżej 120 kg.', 'error');
        return false;
    }
    
    return true;
}

// Podłącz nasłuchy
$(document).on('change', '#srl-kategoria-wagowa', function() {
    sprawdzKategorieWagowaWiekowa();
    sprawdzKompatybilnoscZAkrobacjami();
});

// Zmodyfikuj funkcję zapiszDanePasazera
var originalZapiszDane = zapiszDanePasazera;
zapiszDanePasazera = function() {
    // Sprawdź akceptację regulaminu
    if (!$('#srl-akceptacja-regulaminu').is(':checked')) {
        pokazKomunikat('Musisz zaakceptować Regulamin.', 'error');
        return;
    }
    
    // Sprawdź kategorię wagową
    var kategoria = $('#srl-kategoria-wagowa').val();
    if (kategoria === '120kg+') {
        pokazKomunikat('Nie można dokonać rezerwacji z kategorią wagową 120kg+', 'error');
        return;
    }
    
    // Sprawdź kompatybilność z akrobacjami
    if (!sprawdzKompatybilnoscZAkrobacjami()) {
        return;
    }
    
    // Jeśli wszystko OK, kontynuuj normalnie
    originalZapiszDane();
};

   
});

function sprawdzKategorieWagowaWiekowa() {
    var kategoria = jQuery('#srl-kategoria-wagowa').val();
    var rokUrodzenia = parseInt(jQuery('#srl-rok-urodzenia').val());
    var aktualnyRok = new Date().getFullYear();
    var wiek = aktualnyRok - rokUrodzenia;
    
    var ostrzezenieDiv = jQuery('#srl-waga-ostrzezenie');
    ostrzezenieDiv.hide();
    
    var komunikaty = [];
    
    // Sprawdź wiek
    if (rokUrodzenia && wiek <= 18) {
        komunikaty.push('<div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:10px;"><strong>Lot osoby niepełnoletniej:</strong> Osoby poniżej 18. roku życia mogą wziąć udział w locie tylko za zgodą rodzica lub opiekuna prawnego. Wymagane jest okazanie podpisanej, wydrukowanej zgody w dniu lotu, na miejscu startu. <a href="/zgoda-na-lot-osoba-nieletnia/" target="_blank" style="color:#f57c00; font-weight:bold;">Pobierz zgodę tutaj</a>.</div>');
    }
    
    // Sprawdź wagę
    if (kategoria === '91-120kg') {
        komunikaty.push('<div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:10px;"><strong>Uwaga:</strong> Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji. Pilot ma prawo odmówić wykonania lotu jeśli uzna, że zagraża to bezpieczeństwu.</div>');
    } else if (kategoria === '120kg+') {
        komunikaty.push('<div class="srl-uwaga" style="background:#fdeaea; border:2px solid #d63638; border-radius:8px; padding:20px; margin-top:10px; color:#721c24;"><strong>❌ Błąd:</strong> Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.</div>');
    }
    
    if (komunikaty.length > 0) {
        ostrzezenieDiv.html(komunikaty.join(''));
        ostrzezenieDiv.show();
    }
}

function sprawdzKompatybilnoscZAkrobacjami() {
    var wybranyLotId = wybranyLot || window.wybranyLot;
    var kategoria = jQuery('#srl-kategoria-wagowa').val();
    
    if (!wybranyLotId || !kategoria) return true;
    
    // Znajdź wybrany lot w danych
    var lot = daneKlienta.dostepne_loty.find(function(l) {
        return l.id == wybranyLotId;
    });
    
    if (!lot) return true;
    
    // Sprawdź czy to lot z akrobacjami
    var czyAkrobatyczny = lot.nazwa_produktu.toLowerCase().indexOf('akrobacj') !== -1 ||
                         lot.ma_akrobacje == '1';
    
    if (czyAkrobatyczny && (kategoria === '91-120kg' || kategoria === '120kg+')) {
        if (typeof pokazKomunikat === 'function') {
            pokazKomunikat('Wybrana kategoria wagowa (' + kategoria + ') nie jest dostępna dla lotów z akrobacjami.', 'error');
        }
        return false;
    }
    
    if (kategoria === '120kg+') {
        if (typeof pokazKomunikat === 'function') {
            pokazKomunikat('Loty nie są możliwe dla pasażerów powyżej 120 kg.', 'error');
        }
        return false;
    }
    
    return true;
}

// ==========================================================================
// Nasłuchy walidacji
// ==========================================================================

jQuery(document).ready(function($) {
    
    // Podłącz nasłuchy walidacji
    $(document).on('change', '#srl-rok-urodzenia', function() {
        sprawdzKategorieWagowaWiekowa();
    });
    
    $(document).on('change', '#srl-kategoria-wagowa', function() {
        sprawdzKategorieWagowaWiekowa();
        sprawdzKompatybilnoscZAkrobacjami();
    });
    
    // Zmodyfikuj funkcję zapiszDanePasazera w globalnej zmiennej
    if (typeof window.zapiszDanePasazeraOriginal === 'undefined') {
        window.zapiszDanePasazeraOriginal = window.zapiszDanePasazera;
        
        window.zapiszDanePasazera = function() {
            console.log('✅ [DEBUG] Walidacja danych pasażera...');
            
            // Sprawdź akceptację regulaminu
            if (!$('#srl-akceptacja-regulaminu').is(':checked')) {
                if (typeof pokazKomunikat === 'function') {
                    pokazKomunikat('Musisz zaakceptować Regulamin.', 'error');
                }
                return;
            }
            
            // Sprawdź kategorię wagową
            var kategoria = $('#srl-kategoria-wagowa').val();
            if (kategoria === '120kg+') {
                if (typeof pokazKomunikat === 'function') {
                    pokazKomunikat('Nie można dokonać rezerwacji z kategorią wagową 120kg+', 'error');
                }
                return;
            }
            
            // Sprawdź kompatybilność z akrobacjami
            if (!sprawdzKompatybilnoscZAkrobacjami()) {
                return;
            }
            
            console.log('✅ [DEBUG] Walidacja przeszła pomyślnie');
            
            // Jeśli wszystko OK, kontynuuj normalnie
            if (typeof window.zapiszDanePasazeraOriginal === 'function') {
                window.zapiszDanePasazeraOriginal();
            }
        };
    }
});

console.log('🎯 [DEBUG] System walidacji i opcji lotów zainicjalizowany');
