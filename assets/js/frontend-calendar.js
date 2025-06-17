jQuery(document).ready(function($) {

    var aktualnyKrok = 1;
    var maksymalnyKrok = 1;
    var aktualnyMiesiac = new Date().getMonth() + 1;
    var aktualnyRok = new Date().getFullYear();
    var wybranaDana = null;
    var wybranySlot = null;
    var wybranyLot = null;
    var daneKlienta = null;
    var tymczasowaBlokada = null;

    window.wybranyLot = null;
    window.daneKlienta = null;

    function aktualizujWybranyLotInfo() {
        var aktualnyLot = wybranyLot || window.wybranyLot;
        var aktualneDane = daneKlienta || window.daneKlienta;

        if (!aktualnyLot || !aktualneDane || !aktualneDane.dostepne_loty) return;

        var lot = aktualneDane.dostepne_loty.find(function(l) {
            return l.id == aktualnyLot;
        });

        if (lot) {
            var nazwaBezWariantu = lot.nazwa_produktu.split(' - ')[0];

            if (nazwaBezWariantu.toLowerCase().includes('voucher') || 
                nazwaBezWariantu.toLowerCase().includes('lot') ||
                nazwaBezWariantu.toLowerCase().includes('tandem')) {
                nazwaBezWariantu = 'Lot w tandemie';
            }

            var maFilmowanie = lot.ma_filmowanie && lot.ma_filmowanie != '0';
            var maAkrobacje = lot.ma_akrobacje && lot.ma_akrobacje != '0';

            var opcje_tekst = [];
            if (maFilmowanie) {
                opcje_tekst.push('<span style="color: #46b450;">z filmowaniem</span>');
            } else {
                opcje_tekst.push('<span style="color: #d63638;">brak filmowania</span>');
            }

            if (maAkrobacje) {
                opcje_tekst.push('<span style="color: #46b450;">z akrobacjami</span>');
            } else {
                opcje_tekst.push('<span style="color: #d63638;">brak akrobacji</span>');
            }

            var html = '<strong>Lot #' + lot.id + ' – ' + escapeHtml(nazwaBezWariantu);
            html += ' <span style="font-weight: bold;">' + opcje_tekst.join(', ') + '</span>';
            html += '</strong>';

            if (lot.kod_vouchera) {
                // Voucher info if needed
            }

            if (!maFilmowanie || !maAkrobacje) {
                html += '<div style="background: #f0f8ff; border: 2px solid #46b450; border-radius: 8px; padding: 20px; margin-top: 15px;">';

                if (!maFilmowanie && !maAkrobacje) {
                    html += '<h4 style="margin-top: 0; color: #46b450;">🌟 Czy wiesz, że Twój lot może być jeszcze ciekawszy?</h4>';
                    html += '<p>Nie masz dodanego <strong>filmowania</strong> ani <strong>akrobacji</strong> – to dwie opcje, które często wybierają nasi pasażerowie.</p>';
                    html += '<p><strong>Film z lotu</strong> to świetna pamiątka, którą możesz pokazać znajomym.</br><strong>Akrobacje</strong>? Idealne, jeśli masz ochotę na więcej adrenaliny!</p>';
                    html += '<p>Możesz wykupić je teraz online lub na lotnisku – bezpośrednio na lotnisku, za gotówkę.</p>';
                    html += '<div style="text-align: center; margin-top: 15px;">';
                    html += '<button id="srl-opcja-' + lot.id + '-' + srlFrontend.productIds.filmowanie + '" class="srl-add-option srl-btn srl-btn-success" style="margin: 5px; padding: 10px 20px;" data-lot-id="' + lot.id + '" data-product-id="' + srlFrontend.productIds.filmowanie + '" onclick="srlDodajOpcjeLotu(' + lot.id + ', ' + srlFrontend.productIds.filmowanie + ', \'Filmowanie lotu\')">👉 Dodaj filmowanie</button>';
                    html += '<button id="srl-opcja-' + lot.id + '-' + srlFrontend.productIds.akrobacje + '" class="srl-add-option srl-btn srl-btn-success" style="margin: 5px; padding: 10px 20px;" data-lot-id="' + lot.id + '" data-product-id="' + srlFrontend.productIds.akrobacje + '" onclick="srlDodajOpcjeLotu(' + lot.id + ', ' + srlFrontend.productIds.akrobacje + ', \'Akrobacje podczas lotu\')">👉 Dodaj akrobacje</button>';
                    html += '</div>';
                } else if (!maFilmowanie) {
                    html += '<h4 style="margin-top: 0; color: #46b450;">Nie masz dodanego filmowania do swojego lotu?</h4>';
                    html += '<p>To nic, ale warto wiedzieć, że to bardzo lubiana opcja wśród pasażerów.</p>';
                    html += '<p>🎥 <strong>Film z lotu</strong> pozwala wracać do tych emocji, dzielić się nimi z bliskimi i zachować wyjątkową pamiątkę.</p>';
                    html += '<p>Możesz wykupić je teraz online lub na lotnisku – bezpośrednio na lotnisku, za gotówkę.</p>';
                    html += '<div style="text-align: center; margin-top: 15px;">';
                    html += '<button id="srl-opcja-' + lot.id + '-' + srlFrontend.productIds.filmowanie + '" class="srl-add-option srl-btn srl-btn-success" style="padding: 10px 20px;" data-lot-id="' + lot.id + '" data-product-id="' + srlFrontend.productIds.filmowanie + '" onclick="srlDodajOpcjeLotu(' + lot.id + ', ' + srlFrontend.productIds.filmowanie + ', \'Filmowanie lotu\')">👉 Dodaj filmowanie do koszyka</button>';
                    html += '</div>';
                } else if (!maAkrobacje) {
                    html += '<h4 style="margin-top: 0; color: #46b450;">Nie wybrałeś akrobacji?</h4>';
                    html += '<p>To oczywiście nie jest obowiązkowe – ale jeśli lubisz odrobinę adrenaliny, to może być coś dla Ciebie!</p>';
                    html += '<p><strong>Akrobacje w locie</strong> to kilka dynamicznych manewrów, które robią wrażenie i zostają w pamięci na długo.</p>';
                    html += '<p>Możesz wykupić je teraz online lub na lotnisku – bezpośrednio na lotnisku, za gotówkę.</p>';
                    html += '<div style="text-align: center; margin-top: 15px;">';
                    html += '<button id="srl-opcja-' + lot.id + '-' + srlFrontend.productIds.akrobacje + '" class="srl-add-option srl-btn srl-btn-success" style="padding: 10px 20px;" data-lot-id="' + lot.id + '" data-product-id="' + srlFrontend.productIds.akrobacje + '" onclick="srlDodajOpcjeLotu(' + lot.id + ', ' + srlFrontend.productIds.akrobacje + ', \'Akrobacje podczas lotu\')">👉 Dodaj akrobacje do koszyka</button>';
                    html += '</div>';
                }

                html += '</div>';
            }

            $('#srl-wybrany-lot-szczegoly').html(html);
        }
    }

    init();

    function init() {
        zaladujDaneKlienta();
        podlaczNasluchy();
    }

    function pokazKrok(nrKroku) {
        if (nrKroku < 1 || nrKroku > 5) return;

        $('.srl-step').removeClass('srl-step-active srl-step-completed');
        $('.srl-progress-bar').removeClass('srl-progress-1 srl-progress-2 srl-progress-3 srl-progress-4 srl-progress-5');
        $('.srl-progress-bar').addClass('srl-progress-' + nrKroku);

        for (var i = 1; i <= 5; i++) {
            var step = $('.srl-step[data-step="' + i + '"]');
            if (i < nrKroku) {
                step.addClass('srl-step-completed');
            } else if (i === nrKroku) {
                step.addClass('srl-step-active');
            }
        }

        $('.srl-krok').removeClass('srl-krok-active');
        $('#srl-krok-' + nrKroku).addClass('srl-krok-active');

        aktualnyKrok = nrKroku;
        maksymalnyKrok = Math.max(maksymalnyKrok, nrKroku);

        $('html, body').animate({
            scrollTop: $('#srl-rezerwacja-container').offset().top - 50
        }, 300);

        if (nrKroku === 5) {
            pokazKrok5();
        }
    }

    function pokazKrok5() {
        var lot = daneKlienta.dostepne_loty.find(function(l) {
            return l.id == wybranyLot;
        });

        var slotInfo = tymczasowaBlokada ? tymczasowaBlokada.slot : null;

        if (lot) {
            var nazwaBezWariantu = lot.nazwa_produktu.split(' - ')[0];
            if (nazwaBezWariantu.toLowerCase().includes('voucher') || 
                nazwaBezWariantu.toLowerCase().includes('lot') ||
                nazwaBezWariantu.toLowerCase().includes('tandem')) {
                nazwaBezWariantu = 'Lot w tandemie';
            }

            var opcje_tekst = [];
            if (lot.ma_filmowanie && lot.ma_filmowanie != '0') {
                opcje_tekst.push('<span style="color: #46b450;">z filmowaniem</span>');
            } else {
                opcje_tekst.push('<span style="color: #d63638;">brak filmowania</span>');
            }

            if (lot.ma_akrobacje && lot.ma_akrobacje != '0') {
                opcje_tekst.push('<span style="color: #46b450;">z akrobacjami</span>');
            } else {
                opcje_tekst.push('<span style="color: #d63638;">brak akrobacji</span>');
            }

            var lotOpis = '#' + lot.id + ' – ' + escapeHtml(nazwaBezWariantu);
            lotOpis += ' <span style="font-weight: bold;">' + opcje_tekst.join(', ') + '</span>';

            if (lot.kod_vouchera) {
                // Voucher info if needed
            }

            $('#srl-lot-details').html(lotOpis);
        }

        if (slotInfo) {
            var dataGodzina = formatujDate(wybranaDana) + ', godz. ' + slotInfo.godzina_start.substring(0, 5) + ' - ' + slotInfo.godzina_koniec.substring(0, 5);
            $('#srl-datetime-details').html(dataGodzina);
        }

        var daneHtml = '';
        daneHtml += '<p><strong>Imię i nazwisko:</strong> ' + $('#srl-imie').val() + ' ' + $('#srl-nazwisko').val() + '</p>';
        daneHtml += '<p><strong>Rok urodzenia:</strong> ' + $('#srl-rok-urodzenia').val() + '</p>';
        daneHtml += '<p><strong>Wiek:</strong> ' + (new Date().getFullYear() - $('#srl-rok-urodzenia').val()) + ' lat</p>';
        daneHtml += '<p><strong>Telefon:</strong> ' + $('#srl-telefon').val() + '</p>';
        daneHtml += '<p><strong>Sprawność fizyczna:</strong> ' + $('#srl-sprawnosc-fizyczna option:selected').text() + '</p>';
        daneHtml += '<p><strong>Kategoria wagowa:</strong> ' + $('#srl-kategoria-wagowa').val() + '</p>';
		var rokUrodzenia = $('#srl-rok-urodzenia').val();
		var kategoriaWagowa = $('#srl-kategoria-wagowa').val();
		if (rokUrodzenia && kategoriaWagowa) {
			// Pobierz aktualne komunikaty z kontenerów
			var wiekHtml = $('#srl-wiek-ostrzezenie').html();
			var wagaHtml = $('#srl-waga-ostrzezenie').html();
			
			if (wiekHtml) {
				daneHtml += wiekHtml;
			}
			if (wagaHtml) {
				daneHtml += wagaHtml;
			}
		}

        $('#srl-dane-pasazera-podsumowanie').html(daneHtml);
    }

    function podlaczNasluchy() {
        $('.srl-step').on('click', function() {
            var krok = parseInt($(this).data('step'));
            if (krok <= maksymalnyKrok) {
                pokazKrok(krok);
            }
        });

        $('#srl-formularz-pasazera').on('submit', function(e) {
            e.preventDefault();
            zapiszDanePasazera();
        });

        $('#srl-poprzedni-miesiac').on('click', function() {
            zmienMiesiac(-1);
        });

        $('#srl-nastepny-miesiac').on('click', function() {
            zmienMiesiac(1);
        });

        $('#srl-powrot-krok-1').on('click', function() { pokazKrok(1); });
        $('#srl-powrot-krok-2').on('click', function() { pokazKrok(2); });
        $('#srl-powrot-krok-3').on('click', function() { pokazKrok(3); });
        $('#srl-powrot-krok-4').on('click', function() { pokazKrok(4); });
        $('#srl-dalej-krok-5').on('click', function() { pokazKrok(5); });

        $(document).on('click', '.srl-wybierz-lot', function() {
            var lotId = $(this).data('lot-id');
            wybranyLot = lotId;
            window.wybranyLot = lotId;
            pokazKrok(2);
            aktualizujWybranyLotInfo();
        });

        $('#srl-potwierdz-rezerwacje').on('click', function() {
            dokonajRezerwacji();
        });

        function sprawdzWybranyLot() {
            if (!wybranyLot && !window.wybranyLot) {
                pokazKomunikat('Musisz najpierw wybrać lot do zarezerwowania.', 'error');
                return false;
            }
            return true;
        }

        $('.srl-step[data-step="2"]').on('click', function(e) {
            if (!wybranyLot && !window.wybranyLot) {
                e.preventDefault();
                e.stopPropagation();
                pokazKomunikat('Musisz najpierw wybrać lot do zarezerwowania.', 'error');
                return false;
            }
        });

		$(document).on('change', '#srl-rok-urodzenia', function() {
			srlWalidujWiek();
		});

		$(document).on('change', '#srl-kategoria-wagowa', function() {
			srlWalidujKategorieWagowa();
			sprawdzKompatybilnoscZAkrobacjami();
		});
    }

	
	function srlWalidujWiek() {
		var rokUrodzenia = $('#srl-rok-urodzenia').val();
		
		if (!rokUrodzenia) {
			srlUkryjKomunikatWiekowy();
			return;
		}
		
		$.post(srlFrontend.ajaxurl, {
			action: 'srl_waliduj_wiek',
			rok_urodzenia: rokUrodzenia,
			nonce: srlFrontend.nonce
		}, function(response) {
			if (response.success && response.data.html) {
				srlPokazKomunikatWiekowy(response.data.html);
			} else {
				srlUkryjKomunikatWiekowy();
			}
		});
	}

	function srlWalidujKategorieWagowa() {
		var kategoria = $('#srl-kategoria-wagowa').val();
		
		if (!kategoria) {
			srlUkryjKomunikatWagowy();
			return;
		}
		
		$.post(srlFrontend.ajaxurl, {
			action: 'srl_waliduj_kategorie_wagowa',
			kategoria_wagowa: kategoria,
			nonce: srlFrontend.nonce
		}, function(response) {
			if (response.success && response.data.html) {
				srlPokazKomunikatWagowy(response.data.html);
			} else {
				srlUkryjKomunikatWagowy();
			}
		});
	}

	function srlPokazKomunikatWiekowy(html) {
		var container = $('#srl-wiek-ostrzezenie');
		if (container.length === 0) {
			container = $('<div id="srl-wiek-ostrzezenie"></div>');
			$('#srl-waga-ostrzezenie').before(container);
		}
		container.html(html).show();
	}

	function srlUkryjKomunikatWiekowy() {
		$('#srl-wiek-ostrzezenie').hide();
	}

	function srlPokazKomunikatWagowy(html) {
		$('#srl-waga-ostrzezenie').html(html).show();
	}

	function srlUkryjKomunikatWagowy() {
		$('#srl-waga-ostrzezenie').hide();
	}
	
    function zaladujDaneKlienta() {
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
                    daneKlienta = response.data;
                    window.daneKlienta = response.data;
                    wypelnijDaneKlienta();

                    $(document).trigger('srl_dane_klienta_zaladowane');
                } else {
                    pokazKomunikat('Błąd ładowania danych: ' + response.data, 'error');
                }
            },
            error: function(xhr, status, error) {
                ukryjKomunikat();
                pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            }
        });
    }

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

            html += '<td class="srl-kolumna-nazwa">';
            html += '<div class="srl-nazwa-lotu">Lot w tandemie (#' + lot.id + ')</div>';

            var opcje_tekst = [];
            if (lot.ma_filmowanie && lot.ma_filmowanie != '0') {
                opcje_tekst.push('<span style="color: #46b450;">z filmowaniem</span>');
            } else {
                opcje_tekst.push('<span style="color: #d63638;">bez filmowania</span>');
            }

            if (lot.ma_akrobacje && lot.ma_akrobacje != '0') {
                opcje_tekst.push('<span style="color: #46b450;">z akrobacjami</span>');
            } else {
                opcje_tekst.push('<span style="color: #d63638;">bez akrobacji</span>');
            }

            html += '<div class="srl-opcje-lotu">' + opcje_tekst.join(', ') + '</div>';

            if (lot.kod_vouchera) {
                // Voucher info if needed
            }

            if (lot.data_waznosci) {
                html += '<div class="srl-data-waznosci">(Ważny do: ' + new Date(lot.data_waznosci).toLocaleDateString('pl-PL') + ')</div>';
            }
            html += '</td>';

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

            html += '<td class="srl-kolumna-opcje">';
            if (lot.status === 'zarezerwowany') {
                var dataLotu = new Date(lot.data + ' ' + lot.godzina_start);
                var czasDoLotu = dataLotu.getTime() - Date.now();
                var moznaModyfikowac = czasDoLotu > 48 * 60 * 60 * 1000;

                if (moznaModyfikowac) {
                    var dostepneOpcje = [];
                    if (!lot.ma_filmowanie || lot.ma_filmowanie == '0') {
                        dostepneOpcje.push({nazwa: 'Filmowanie', id: srlFrontend.productIds.filmowanie});
                    }
                    if (!lot.ma_akrobacje || lot.ma_akrobacje == '0') {
                        dostepneOpcje.push({nazwa: 'Akrobacje', id: srlFrontend.productIds.akrobacje});
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
                var dostepneOpcje = [];
                if (!lot.ma_filmowanie || lot.ma_filmowanie == '0') {
                    dostepneOpcje.push({nazwa: 'Filmowanie', id: srlFrontend.productIds.filmowanie});
                }
                if (!lot.ma_akrobacje || lot.ma_akrobacje == '0') {
                    dostepneOpcje.push({nazwa: 'Akrobacje', id: srlFrontend.productIds.akrobacje});
                }

                if (lot.data_waznosci) {
                    var dniDoWaznosci = Math.floor((new Date(lot.data_waznosci).getTime() - Date.now()) / (24 * 60 * 60 * 1000));
                    if (dniDoWaznosci <= 3390) {
                        dostepneOpcje.push({nazwa: 'Przedłużenie', id: srlFrontend.productIds.przedluzenie});
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

        $('.srl-anuluj-rezerwacje').on('click', function() {
            var lotId = $(this).data('lot-id');
            anulujRezerwacje(lotId);
        });
    }

    function wypelnijDaneKlienta() {
        aktualizujPowitanie();

        var lotySpolaczone = [];

        if (daneKlienta.rezerwacje) {
            daneKlienta.rezerwacje.forEach(function(rezerwacja) {
                lotySpolaczone.push(rezerwacja);
            });
        }

        if (daneKlienta.dostepne_loty) {
            daneKlienta.dostepne_loty.forEach(function(lot) {
                lotySpolaczone.push(lot);
            });
        }

        lotySpolaczone.sort(function(a, b) {
            if (a.status === 'zarezerwowany' && b.status !== 'zarezerwowany') return -1;
            if (a.status !== 'zarezerwowany' && b.status === 'zarezerwowany') return 1;
            return 0;
        });

        wypelnijListeRezerwacji(lotySpolaczone);
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

        // Uruchom walidację wieku i wagi po załadowaniu danych
		if (dane.rok_urodzenia) {
			srlWalidujWiek();
		}
		if (dane.kategoria_wagowa) {
			srlWalidujKategorieWagowa();
		}
    }

    function zapiszDanePasazera() {
        if (!wybranyLot && !window.wybranyLot) {
            pokazKomunikat('Błąd: Nie wybrano lotu do rezerwacji. Wróć do kroku 1.', 'error');
            return;
        }

        ukryjKomunikaty();

        var bledy = [];

        if (!$('#srl-akceptacja-regulaminu').is(':checked')) {
            bledy.push('Musisz zaakceptować Regulamin.');
        }

        var kategoria = $('#srl-kategoria-wagowa').val();
        if (kategoria === '120kg+') {
            bledy.push('Nie można dokonać rezerwacji z kategorią wagową 120kg+');
        }

        var telefon = $('#srl-telefon').val().trim();
        if (telefon) {
            var telefonClean = telefon.replace(/[\s\-\(\)\+48]/g, '');
            if (telefonClean.length < 9) {
                bledy.push('Numer telefonu musi mieć minimum 9 cyfr.');
            }
        }

        if (!sprawdzKompatybilnoscZAkrobacjami()) {
            bledy.push('Wybrana kategoria wagowa nie jest dostępna dla lotów z akrobacjami.');
        }

        if (bledy.length > 0) {
            bledy.forEach(function(blad) {
                pokazKomunikat(blad, 'error');
            });
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
        var pierwszyDzienTygodnia = pierwszyDzienMiesiaca.getDay(); 

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

        $('.srl-dzien-dostepny').on('click', function() {
            wybranaDana = $(this).data('data');

            $('.srl-dzien-wybrany').removeClass('srl-dzien-wybrany');
            $(this).addClass('srl-dzien-wybrany');

            pokazKrok(4);
            zaladujHarmonogram();
        });
    }

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

        $('.srl-slot-godzina').on('click', function() {
            var slotId = $(this).data('slot-id');
            wybierzSlot(slotId, $(this));
        });
    }

    function wybierzSlot(slotId, element) {
        $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
        element.addClass('srl-slot-wybrany');

        wybranySlot = slotId;

        $('#srl-dalej-krok-5').show();

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

                    setTimeout(function() {
                        pokazKomunikat('Blokada terminu wygasła. Wybierz termin ponownie.', 'warning');
                        $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                        $('#srl-dalej-krok-5').hide();
                        wybranySlot = null;
                        tymczasowaBlokada = null;
                    }, 15 * 60 * 1000); 

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
        html += '<h2 style="color:#46b450; margin-bottom:20px;text-transform: uppercase;">Rezerwacja potwierdzona!</h2>';
        html += '<p style="font-size:18px; margin-bottom:30px;">Twój lot tandemowy został zarezerwowany na <strong>' + formatujDate(wybranaDana) + '</strong></p>';
        html += '<p>Na podany adres email została wysłana informacja z szczegółami rezerwacji.</p>';
        html += '<div style="margin-top:30px;">';
        html += '<a href="' + window.location.href + '" class="srl-btn srl-btn-primary">Zarezerwuj kolejny lot</a>';
        html += '</div>';
        html += '</div>';

        $('#srl-rezerwacja-container').html(html);
    }

    function ukryjKomunikaty() {
        $('#srl-komunikaty').empty().hide();
    }

    function pokazKomunikat(tekst, typ) {
        var klasa = 'srl-komunikat-' + typ;
        var html = '<div class="srl-komunikat ' + klasa + '">' + tekst + '</div>';

        var komunikatyElement = $('#srl-komunikaty');

        if (komunikatyElement.length === 0) {
            $('#srl-formularz-pasazera').prepend('<div id="srl-komunikaty"></div>');
            komunikatyElement = $('#srl-komunikaty');
        }

        komunikatyElement.append(html).show();

        setTimeout(function() {
            komunikatyElement.fadeOut(function() {
                komunikatyElement.empty(); 
            });
        }, 15000);
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

    function sprawdzKompatybilnoscZAkrobacjami() {
        var wybranyLotId = wybranyLot || window.wybranyLot;
        var kategoria = $('#srl-kategoria-wagowa').val();

        if (!wybranyLotId || !kategoria) return true;

        var lot = daneKlienta.dostepne_loty.find(function(l) {
            return l.id == wybranyLotId;
        });

        if (!lot) return true;

        var czyAkrobatyczny = lot.nazwa_produktu.toLowerCase().indexOf('akrobacj') !== -1 ||
                             lot.ma_akrobacje == '1';

        if (czyAkrobatyczny && (kategoria === '91-120kg' || kategoria === '120kg+')) {
            return false;
        }

        if (kategoria === '120kg+') {
            return false;
        }

        return true;
    }

});