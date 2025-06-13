
// Zabezpieczenie przed undefined srlAdmin
if (typeof srlAdmin === 'undefined') {
    var srlAdmin = {
        data: new Date().toISOString().split('T')[0],
        istniejaceGodziny: {},
        domyslnaLiczbaPilotow: 1,
        ajaxurl: '/wp-admin/admin-ajax.php',
        adminUrl: '/wp-admin/',
        nonce: ''
    };
}

var srlData = srlAdmin.data;
var srlIstniejaceGodziny = srlAdmin.istniejaceGodziny;
var srlDomyslnaLiczbaPilotow = srlAdmin.domyslnaLiczbaPilotow;

// Plik JS – planowanie godzin w zakładce „Dzień tygodnia"
jQuery(document).ready(function($) {
    // ------------------------------
    // 1. Definicje podstawowych zmiennych
    // ------------------------------
    var liczbaPilotowSelect = $('#srl-liczba-pilotow');
    var interwalSelect       = $('#srl-interwal');
    var ustawieniaGodzin     = $('#srl-ustawienia-godzin');
    var kontenerTabele       = $('#srl-tabele-pilotow');
    var checkboxPlanowanie   = $('#srl-planowane-godziny');
    var generujSlotyButton   = $('#srl-generuj-sloty');
    var generujPilotSelect   = $('#srl-generuj-pilot');
    var generujOdInput       = $('#srl-generuj-od');
    var generujDoInput       = $('#srl-generuj-do');
    var dataDnia             = srlData;                 
    var domyslnaLiczbaPid    = srlDomyslnaLiczbaPilotow; 
    var generowanieWToku     = false;

    // 2. Inicjalizacja
	if (checkboxPlanowanie.is(':checked') && Object.keys(srlIstniejaceGodziny).length > 0) {
		generujTabelePilotow();
	}

	// Dodaj obsługę podstawowych kontrolek
	checkboxPlanowanie.on('change', function() {
		if ($(this).is(':checked')) {
			$('#srl-ustawienia-godzin').slideDown();
			generujTabelePilotow();
		} else {
			// Sprawdź czy są jakieś sloty do usunięcia
			var istniejeCoUsunac = Object.keys(srlIstniejaceGodziny).some(function(pid) {
				return srlIstniejaceGodziny[pid] && srlIstniejaceGodziny[pid].length > 0;
			});
			
			if (istniejeCoUsunac) {
				alert('Nie możesz ustawić dnia jako „nielotny", ponieważ są już zaplanowane loty. Usuń wszystkie sloty, aby odznaczyć.');
				$(this).prop('checked', true);
				return;
			}
			
			$('#srl-ustawienia-godzin').slideUp();
			$('#srl-tabele-pilotow').empty();
		}
	});

	// Obsługa zmiany daty
	$('#srl-wybierz-date').on('change', function() {
		var nowaData = $(this).val();
		window.location.href = addOrUpdateUrlParam('data', nowaData);
	});

	// Funkcja pomocnicza do dodawania/aktualizacji parametru URL
	function addOrUpdateUrlParam(key, value) {
		var url = new URL(window.location.href);
		url.searchParams.set(key, value);
		return url.toString();
	}

    // ------------------------------
    // 3. Obsługa zmiany liczby pilotów lub interwału
    // ------------------------------
    liczbaPilotowSelect.on('change', function() {
        if (checkboxPlanowanie.is(':checked')) {
            aktualizujDropdownPilotow();
            generujTabelePilotow();
        }
    });
    
    interwalSelect.on('change', function() {
        if (checkboxPlanowanie.is(':checked')) {
            generujTabelePilotow();
        }
    });

    // ------------------------------
    // 4. Obsługa przycisku „Generuj sloty"
    // ------------------------------
    generujSlotyButton.on('click', function(e) {
        e.preventDefault();
        
        if (generowanieWToku) {
            return;
        }
        
        var pilotId  = parseInt(generujPilotSelect.val());
        var godzOd   = generujOdInput.val();
        var godzDo   = generujDoInput.val();
        var interwal = parseInt(interwalSelect.val());

        // Walidacja
        if (!godzOd || !godzDo) {
            alert('Podaj godzinę początkową i końcową.');
            return;
        }
        var wzor = /^[0-2]\d:[0-5]\d$/;
        if (!wzor.test(godzOd) || !wzor.test(godzDo)) {
            alert('Nieprawidłowy format godziny (HH:MM).');
            return;
        }
        if (zamienNaMinuty(godzDo) <= zamienNaMinuty(godzOd)) {
            alert('Godzina końcowa musi być późniejsza niż godzina początkowa.');
            return;
        }
        
        if (!confirm('Czy na pewno wygenerować sloty dla Pilot ' + pilotId + ' od ' + godzOd + ' do ' + godzDo + ' wg interwału ' + interwal + ' min?')) {
            return;
        }

        generowanieWToku = true;
        generujSlotyButton.prop('disabled', true).text('Generowanie...');

        // Generuj listę slotów
        var startMin   = zamienNaMinuty(godzOd);
        var endLimit   = zamienNaMinuty(godzDo);
        var listaDoDodania = [];
        
        while (startMin + interwal <= endLimit) {
            var hStart = pad2(Math.floor(startMin / 60)) + ':' + pad2(startMin % 60);
            var endMin = startMin + interwal;
            if (endMin > endLimit) break;
            var hEnd = pad2(Math.floor(endMin / 60)) + ':' + pad2(endMin % 60);
            listaDoDodania.push({ start: hStart, koniec: hEnd });
            startMin = endMin;
        }

        // Dodaj sloty sekwencyjnie
        dodajSlotyRekurencyjnie(listaDoDodania, 0, pilotId, function(dodanych, blad) {
            generowanieWToku = false;
            generujSlotyButton.prop('disabled', false).text('Generuj sloty');
            
            if (blad) {
                alert('Wystąpił błąd przy generowaniu niektórych slotów.');
            }
            if (dodanych > 0) {
                alert('Wygenerowano ' + dodanych + ' slotów dla Pilota ' + pilotId + '.');
            }
        });
    });

    function dodajSlotyRekurencyjnie(lista, index, pilotId, callback) {
        if (index >= lista.length) {
            callback(index, false);
            return;
        }

        var slot = lista[index];
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'srl_dodaj_godzine',
                data: dataDnia,
                pilot_id: pilotId,
                godzina_start: slot.start,
                godzina_koniec: slot.koniec,
                status: 'Wolny'
            },
            success: function(response) {
                if (response.success) {
                    srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                    // Natychmiast odśwież interfejs
                    generujTabelePilotow();
                    // Kontynuuj z następnym slotem
                    dodajSlotyRekurencyjnie(lista, index + 1, pilotId, callback);
                } else {
                    console.error('Błąd przy dodawaniu slotu:', response.data);
                    callback(index, true);
                }
            },
            error: function() {
                callback(index, true);
            }
        });
    }

    // ------------------------------
    // 5. Funkcja: Generuj tabele
    // ------------------------------
    function generujTabelePilotow() {
        kontenerTabele.empty();
        var liczbaPilotow = parseInt(liczbaPilotowSelect.val());

        for (var pid = 1; pid <= liczbaPilotow; pid++) {
            var divaPilota = $('<div class="srl-pilot-container" style="margin-bottom:30px; border:1px solid #ddd; padding:15px; border-radius:8px;"></div>');
            divaPilota.append('<h2 style="background:#0073aa; color:white; margin:0 -15px 15px -15px; padding:12px 15px; font-size:16px;">Pilot nr ' + pid + '</h2>');

            // Funkcje grupowe
            var grupoweFunkcje = $('<div class="srl-grupowe-funkcje" style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:12px; margin-bottom:15px; display:flex; align-items:center; gap:15px; flex-wrap:wrap;"></div>');
            grupoweFunkcje.append('<label style="font-weight:500; margin:0; display:flex; align-items:center; gap:5px;"><input type="checkbox" class="srl-zaznacz-wszystkie" data-pilot="' + pid + '"> Zaznacz wszystkie</label>');
            grupoweFunkcje.append('<select class="srl-grupowa-zmiana-statusu" data-pilot="' + pid + '" style="min-width:150px; padding:4px 8px; border:1px solid #ccd0d4; border-radius:3px;"><option value="">-- Zmień status --</option><option value="Wolny">Wolny</option><option value="Prywatny">Prywatny</option><option value="Zrealizowany">Zrealizowany</option><option value="Odwołany przez klienta">Odwołany przez klienta</option></select>');
            grupoweFunkcje.append('<button class="button srl-grupowe-usun" data-pilot="' + pid + '" style="background:#dc3545; color:white; border:none; padding:6px 12px; border-radius:3px; cursor:pointer; font-size:13px;">Usuń zaznaczone</button>');
            divaPilota.append(grupoweFunkcje);

            var tabela = $(
                '<table class="widefat fixed" data-pilot-id="' + pid + '">' +
                    '<thead>' +
                        '<tr>' +
                            '<th style="width:30px;"><input type="checkbox" class="srl-zaznacz-wszystkie-naglowek" data-pilot="' + pid + '"></th>' +
                            '<th style="width:30px;">Nr</th>' +
                            '<th>Czas lotu</th>' +
                            '<th>Status slotu</th>' +
							'<th>ID lotu</th>' +
							'<th>Dane pasażera</th>' +
							'<th>Akcje</th>' +
							'<th>Usuń</th>' +
                        '</tr>' +
                    '</thead>' +
                    '<tbody></tbody>' +
                '</table>'
            );

            // Dodajemy istniejące sloty
            var listaGodzin = srlIstniejaceGodziny[pid] || [];
            for (var i = 0; i < listaGodzin.length; i++) {
                dodajWierszDoTabeli(pid, i + 1, listaGodzin[i], tabela);
            }

            divaPilota.append(tabela);
            kontenerTabele.append(divaPilota);
        }

        // Podpięcie nasłuchów
        zaladujNasluchiwace();
        
        // Generuj harmonogram czasowy
        generujHarmonogramCzasowy();
    }

    // ------------------------------
    // 6. Funkcja: Dodaje wiersz do tabeli
    // ------------------------------
// POPRAWIONA funkcja dodajWierszDoTabeli z przeniesionym przyciskiem Edytuj i opcją Wypisz dla Prywatnych
function dodajWierszDoTabeli(pilotId, numer, obiektGodziny, referencjaTabela) {
    var tr = $('<tr data-termin-id="' + obiektGodziny.id + '"></tr>');
    tr.append('<td><input type="checkbox" class="srl-slot-checkbox" data-pilot="' + pilotId + '" data-termin-id="' + obiektGodziny.id + '"></td>');
    tr.append('<td>' + numer + '</td>');
    
    // POPRAWIONA kolumna czasu - z przyciskiem Edytuj w tej samej linii
    var startMin = zamienNaMinuty(obiektGodziny.start);
    var endMin   = zamienNaMinuty(obiektGodziny.koniec);
    var delta    = endMin - startMin;
    var czasTxt  = obiektGodziny.start + ' - ' + obiektGodziny.koniec + ' (' + delta + 'min)';
    
    var czasTd = $('<td class="srl-czas-col"></td>');
    czasTd.html(czasTxt + ' <button class="button button-small srl-edytuj-button" style="margin-left:10px; font-size:11px;">Edytuj godziny</button>');
    tr.append(czasTd);

    // Status slotu - tylko wyświetlanie, stylizowane jak w wykupionych lotach
    var statusTd = $('<td></td>');
    var statusClass = '';
    var statusIcon = '';
    switch (obiektGodziny.status) {
        case 'Wolny':
            statusClass = 'status-available';
            statusIcon = '🟢';
            break;
        case 'Prywatny':
            statusClass = 'status-private';
            statusIcon = '🟤';
            break;
        case 'Zarezerwowany':
            statusClass = 'status-reserved';
            statusIcon = '🟡';
            break;
        case 'Zrealizowany':
            statusClass = 'status-completed';
            statusIcon = '🔵';
            break;
        case 'Odwołany przez organizatora':
            statusClass = 'status-cancelled';
            statusIcon = '🔴';
            break;
        default:
            statusClass = 'status-unknown';
            statusIcon = '⚪';
            break;
    }

    statusTd.html('<span class="' + statusClass + '" style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">' + statusIcon + ' ' + obiektGodziny.status + '</span>');
    tr.append(statusTd);
    
    // Kolumna ID lotu
    var lotIdTd = $('<td></td>');
    if ((obiektGodziny.status === 'Zarezerwowany' || obiektGodziny.status === 'Zrealizowany') && obiektGodziny.lot_id) {
        lotIdTd.html('<a href="' + srlAdmin.adminUrl + 'admin.php?page=srl-wykupione-loty&search_field=id_lotu&s=' + obiektGodziny.lot_id + '" target="_blank" style="color:#0073aa; font-weight:bold;">#' + obiektGodziny.lot_id + '</a>');
    } else {
        lotIdTd.html('—');
    }
    tr.append(lotIdTd);

    // Kolumna Dane pasażera - POPRAWIONA OBSŁUGA DANYCH PRYWATNYCH
    var danePasazeraTd = $('<td></td>');
    if (obiektGodziny.status === 'Zarezerwowany' || obiektGodziny.status === 'Zrealizowany') {
        if (obiektGodziny.lot_id && obiektGodziny.klient_nazwa) {
            // Pokaż imię i nazwisko jako przycisk
            danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-pasazera" data-lot-id="' + obiektGodziny.lot_id + '" data-user-id="' + obiektGodziny.klient_id + '">' + obiektGodziny.klient_nazwa + '</button>');
        } else {
            danePasazeraTd.html('<button class="button button-small srl-przypisz-klienta" data-termin-id="' + obiektGodziny.id + '">Przypisz klienta</button>');
        }
    } else if (obiektGodziny.status === 'Prywatny') {
        // POPRAWIONA OBSŁUGA: sprawdź dane z notatki przekazanej z backend
        if (obiektGodziny.klient_nazwa) {
            // Jeśli klient_nazwa jest wypełniona, znaczy że dane są w notatce
            danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">' + obiektGodziny.klient_nazwa + '</button>');
        } else if (obiektGodziny.notatka) {
            // Sprawdź czy w notatce są dane JSON
            try {
                var danePrivate = JSON.parse(obiektGodziny.notatka);
                if (danePrivate.imie && danePrivate.nazwisko) {
                    danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">' + danePrivate.imie + ' ' + danePrivate.nazwisko + '</button>');
                } else {
                    danePasazeraTd.html('<button class="button button-small srl-edytuj-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">Edytuj dane</button>');
                }
            } catch (e) {
                danePasazeraTd.html('<button class="button button-small srl-edytuj-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">Edytuj dane</button>');
            }
        } else {
            danePasazeraTd.html('<button class="button button-small srl-edytuj-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">Edytuj dane</button>');
        }
    } else {
        danePasazeraTd.html('—');
    }
    tr.append(danePasazeraTd);

    // POPRAWIONA kolumna Akcje - bez przycisku Edytuj (jest już w kolumnie czasu)
    var akcjeTd = $('<td></td>');

    if (obiektGodziny.status === 'Wolny') {
        akcjeTd.append('<button class="button button-primary srl-przypisz-slot" data-termin-id="' + obiektGodziny.id + '">Przypisz klienta</button>');
    } else if ((obiektGodziny.status === 'Zarezerwowany' || obiektGodziny.status === 'Zrealizowany') && obiektGodziny.klient_id > 0) {
        akcjeTd.append('<button class="button srl-wypisz-klienta">Wypisz klienta</button>');
    } else if (obiektGodziny.status === 'Prywatny') {
        // NOWA OPCJA: Wypisz dla slotów prywatnych
        akcjeTd.append('<button class="button srl-wypisz-slot-prywatny" data-termin-id="' + obiektGodziny.id + '">Wyczyść slot</button>');
    } else {
        akcjeTd.html('—');
    }
    tr.append(akcjeTd);

    // Kolumna Usuń (nowa kolumna)
    var usunTd = $('<td></td>');
    usunTd.append('<button class="button button-secondary srl-usun-button">USUŃ SLOT</button>');
    tr.append(usunTd);

    referencjaTabela.find('tbody').append(tr);
    sprawdzNakladanie(referencjaTabela, pilotId);
}

    // ------------------------------
    // 7. Funkcja: Podłącza nasłuchy
    // ------------------------------
// POPRAWIONA FUNKCJA zaladujNasluchiwace - używa .off() przed .on()
function zaladujNasluchiwace() {
    console.log('Ładowanie nasłuchów...'); // Debug
    
    // 7.0. Obsługa grupowych funkcji - usuń stare nasłuchy przed dodaniem nowych
    $('.srl-zaznacz-wszystkie, .srl-zaznacz-wszystkie-naglowek').off('change').on('change', function() {
        var pilot = $(this).data('pilot');
        var checked = $(this).is(':checked');
        $('.srl-slot-checkbox[data-pilot="' + pilot + '"]').prop('checked', checked);
    });

    $('.srl-grupowa-zmiana-statusu').off('change').on('change', function() {
        var pilot = $(this).data('pilot');
        var nowyStatus = $(this).val();
        var selectElement = $(this);
        
        if (!nowyStatus) return;

        var zaznaczone = $('.srl-slot-checkbox[data-pilot="' + pilot + '"]:checked');
        if (zaznaczone.length === 0) {
            alert('Nie zaznaczono żadnych slotów.');
            selectElement.val('');
            return;
        }

        if (!confirm('Czy na pewno zmienić status ' + zaznaczone.length + ' slotów na "' + nowyStatus + '"?')) {
            selectElement.val('');
            return;
        }

        // Zmień status dla zaznaczonych slotów
        var zmienionych = 0;
        var doZmiany = zaznaczone.length;
        
        zaznaczone.each(function() {
            var terminId = $(this).data('termin-id');
            zmienStatusSlotu(terminId, nowyStatus, 0, '', null, function() {
                zmienionych++;
                if (zmienionych === doZmiany) {
                    // Wszystkie zmienione - odśwież tabelę
                    generujTabelePilotow();
                }
            }, true);
        });

        selectElement.val('');
    });

    $('.srl-grupowe-usun').off('click').on('click', function() {
        var pilot = $(this).data('pilot');
        var zaznaczone = $('.srl-slot-checkbox[data-pilot="' + pilot + '"]:checked');
        
        if (zaznaczone.length === 0) {
            alert('Nie zaznaczono żadnych slotów do usunięcia.');
            return;
        }

        if (!confirm('Czy na pewno usunąć ' + zaznaczone.length + ' zaznaczonych slotów?')) {
            return;
        }

        var usuniete = 0;
        var doUsuniecia = zaznaczone.length;

        zaznaczone.each(function() {
            var terminId = $(this).data('termin-id');
            $.post(ajaxurl, {
                action: 'srl_usun_godzine',
                termin_id: terminId
            }, function(response) {
                usuniete++;
                if (response.success) {
                    srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                } else {
                    console.error('Błąd usuwania slotu:', response.data);
                }
                
                // Gdy wszystkie usunięte, odśwież tabelę
                if (usuniete === doUsuniecia) {
                    generujTabelePilotow();
                }
            }).fail(function() {
                usuniete++;
                console.error('Błąd połączenia przy usuwaniu slotu');
                if (usuniete === doUsuniecia) {
                    generujTabelePilotow();
                }
            });
        });
    });

    // 7.1. „Usuń" slot – AJAX z natychmiastowym odświeżaniem
    $('.srl-usun-button').off('click').on('click', function() {
        var wiersz    = $(this).closest('tr');
        var terminId  = wiersz.data('termin-id');
        var button    = $(this);
        
        if (!confirm('Czy na pewno usunąć ten slot?')) return;

        // Zablokuj przycisk
        button.prop('disabled', true).text('Usuwanie...');

        $.post(ajaxurl, {
            action: 'srl_usun_godzine',
            termin_id: terminId
        }, function(response) {
            if (response.success) {
                srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                // Natychmiast usuń wiersz z interfejsu
                wiersz.fadeOut(300, function() {
                    wiersz.remove();
                    // Odśwież całą tabelę aby przenumerować wiersze
                    generujTabelePilotow();
                });
            } else {
                alert('Błąd usuwania: ' + response.data);
                button.prop('disabled', false).text('Usuń');
            }
        }).fail(function() {
            alert('Błąd połączenia z serwerem');
            button.prop('disabled', false).text('Usuń');
        });
    });

// POPRAWIONA obsługa edycji godzin - zastąp w funkcji zaladujNasluchiwace()
// 7.2. „Edytuj godziny" slot z natychmiastowym odświeżaniem
$(document).off('click', '.srl-edytuj-button').on('click', '.srl-edytuj-button', function() {
    var btn = $(this);
    var wiersz = btn.closest('tr');
    var terminId = wiersz.data('termin-id');
    
    // Pobierz aktualny status slotu
    var aktualnyStatus = 'Wolny'; // domyślny
    var statusSpan = wiersz.find('td:nth-child(4) span');
    if (statusSpan.length > 0) {
        var statusText = statusSpan.text().trim();
        if (statusText.includes('Wolny')) aktualnyStatus = 'Wolny';
        else if (statusText.includes('Prywatny')) aktualnyStatus = 'Prywatny';
        else if (statusText.includes('Zarezerwowany')) aktualnyStatus = 'Zarezerwowany';
        else if (statusText.includes('Zrealizowany')) aktualnyStatus = 'Zrealizowany';
    }

    // Pobierz obecne godziny
    var currText = wiersz.find('.srl-czas-col').text();
    var czasParts = currText.match(/(\d{1,2}:\d{2}) - (\d{1,2}:\d{2})/);
    var currStart = czasParts ? czasParts[1] : '09:00';
    var currStop = czasParts ? czasParts[2] : '09:30';
    
    // Zachowaj oryginalny tekst
    wiersz.find('.srl-czas-col').data('original-text', currText);
    wiersz.find('.srl-czas-col').data('current-status', aktualnyStatus);
    
    // Zastąp zawartość kolumny czasu formularzem edycji
    wiersz.find('.srl-czas-col').html(
        '<div style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">' +
        '<input type="time" class="srl-edit-start" value="' + currStart + '" style="width:90px;">' +
        '<span>-</span>' +
        '<input type="time" class="srl-edit-stop" value="' + currStop + '" style="width:90px;">' +
        '<button class="button button-small button-primary srl-zapisz-godziny" data-termin-id="' + terminId + '" style="margin-left:5px;">Zapisz</button>' +
        '<button class="button button-small srl-anuluj-edycje-godzin" style="margin-left:2px;">Anuluj</button>' +
        '</div>'
    );
});

// Obsługa przycisku "Zapisz godziny" - używamy delegacji eventów
$(document).off('click', '.srl-zapisz-godziny').on('click', '.srl-zapisz-godziny', function() {
    var btn = $(this);
    var wiersz = btn.closest('tr');
    var terminId = btn.data('termin-id');
    var aktualnyStatus = wiersz.find('.srl-czas-col').data('current-status') || 'Wolny';
    
    var newStart = wiersz.find('.srl-edit-start').val();
    var newStop = wiersz.find('.srl-edit-stop').val();
    
    if (!newStart || !newStop) {
        alert('Podaj nowe godziny startu i zakończenia.');
        return;
    }
    
    var wzor = /^[0-2]\d:[0-5]\d$/;
    if (!wzor.test(newStart) || !wzor.test(newStop) || zamienNaMinuty(newStop) <= zamienNaMinuty(newStart)) {
        alert('Nieprawidłowe godziny lub koniec nie jest później niż start.');
        return;
    }
    
    // Wyłącz przycisk i pokaż loader
    btn.prop('disabled', true).text('Zapisywanie...');
    
    $.post(ajaxurl, {
        action: 'srl_zmien_slot',
        termin_id: terminId,
        data: dataDnia,
        godzina_start: newStart,
        godzina_koniec: newStop,
        status: aktualnyStatus,
        klient_id: 0
    }, function(response) {
        if (response.success) {
            srlIstniejaceGodziny = response.data.godziny_wg_pilota;
            // Natychmiast odśwież interfejs
            generujTabelePilotow();
            
            // Pokaż komunikat sukcesu
            pokazKomunikatSukcesu('Godziny zostały zaktualizowane!');
        } else {
            alert('Błąd zapisu: ' + response.data);
            btn.prop('disabled', false).text('Zapisz');
        }
    }).fail(function() {
        alert('Błąd połączenia z serwerem');
        btn.prop('disabled', false).text('Zapisz');
    });
});

// Obsługa przycisku "Anuluj edycję godzin" - używamy delegacji eventów
$(document).off('click', '.srl-anuluj-edycje-godzin').on('click', '.srl-anuluj-edycje-godzin', function() {
    var btn = $(this);
    var wiersz = btn.closest('tr');
    var originalText = wiersz.find('.srl-czas-col').data('original-text');
    
    // Przywróć oryginalny wygląd kolumny
    var startMin = zamienNaMinuty(originalText.match(/(\d{1,2}:\d{2})/)[1]);
    var endMin = zamienNaMinuty(originalText.match(/- (\d{1,2}:\d{2})/)[1]);
    var delta = endMin - startMin;
    var czasTxt = originalText.match(/(\d{1,2}:\d{2} - \d{1,2}:\d{2})/)[1] + ' (' + delta + 'min)';
    
    wiersz.find('.srl-czas-col').html(czasTxt + ' <button class="button button-small srl-edytuj-button" style="margin-left:10px; font-size:11px;">Edytuj godziny</button>');
});

    // NOWY nasłuch: Wyczyść slot prywatny
    $(document).off('click', '.srl-wypisz-slot-prywatny').on('click', '.srl-wypisz-slot-prywatny', function() {
        var terminId = $(this).data('termin-id');
        
        if (!confirm('Czy na pewno wyczyścić ten slot prywatny i zmienić status na wolny?')) return;
        
        $.post(ajaxurl, {
            action: 'srl_zmien_status_godziny',
            termin_id: terminId,
            status: 'Wolny',
            klient_id: 0,
            notatka: '' // Wyczyść notatke
        }, function(response) {
            if (response.success) {
                srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                generujTabelePilotow();
                pokazKomunikatSukcesu('Slot został wyczyszczony i zmieniony na wolny.');
            } else {
                alert('Błąd: ' + response.data);
            }
        }).fail(function() {
            alert('Błąd połączenia z serwerem');
        });
    });

    // 7.3. „Wypisz klienta" - używaj $(document).off().on() dla dynamicznych elementów
    $(document).off('click', '.srl-wypisz-klienta').on('click', '.srl-wypisz-klienta', function() {
        var wiersz   = $(this).closest('tr');
        var terminId = wiersz.data('termin-id');
        
        if (!confirm('Czy na pewno wypisać klienta i przywrócić slot jako wolny?')) return;
        
        zmienStatusSlotu(terminId, 'Wolny', 0, '', null, function() {
            generujTabelePilotow();
        });
    });
    
    // 7.4. WSZYSTKIE OBSŁUGI PRZYCISKÓW - używaj $(document).off().on() aby uniknąć wielokrotnych nasłuchów
    $(document).off('click', '.srl-pokaz-dane-pasazera').on('click', '.srl-pokaz-dane-pasazera', function() {
        var lotId = $(this).data('lot-id');
        var userId = $(this).data('user-id');
        pokazDanePasazeraModal(lotId, userId);
    });

    $(document).off('click', '.srl-przypisz-klienta').on('click', '.srl-przypisz-klienta', function() {
        var terminId = $(this).data('termin-id');
        pokazFormularzPrzypisaniaKlienta(terminId);
    });

    $(document).off('click', '.srl-edytuj-dane-prywatne').on('click', '.srl-edytuj-dane-prywatne', function() {
        var terminId = $(this).data('termin-id');
        pokazFormularzDanychPrywatnych(terminId);
    });

    $(document).off('click', '.srl-pokaz-dane-prywatne').on('click', '.srl-pokaz-dane-prywatne', function() {
        var terminId = $(this).data('termin-id');
        pokazDanePrywatneModal(terminId);
    });

    $(document).off('click', '.srl-przypisz-slot').on('click', '.srl-przypisz-slot', function() {
        console.log('Kliknięto przycisk przypisz slot'); // Debug
        var terminId = $(this).data('termin-id');
        console.log('terminId:', terminId); // Debug
        pokazModalPrzypisaniaSlotu(terminId);
    });
}

    // ------------------------------
    // 8. Funkcje pomocnicze do obsługi AJAX
    // ------------------------------
    function zmienStatusSlotu(terminId, status, klientId, notatka, selectElement, callback, grupowa) {
        $.post(ajaxurl, {
            action: 'srl_zmien_status_godziny',
            termin_id: terminId,
            status: status,
            klient_id: klientId || 0,
            notatka: notatka || ''
        }, function(response) {
            if (response.success) {
                srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                if (callback) {
                    callback();
                } else if (!grupowa) {
                    generujTabelePilotow();
                }
            } else {
                alert('Błąd zmiany statusu: ' + response.data);
                if (selectElement) {
                    selectElement.val(selectElement.data('poprzedni'));
                }
            }
        }).fail(function() {
            alert('Błąd połączenia z serwerem');
            if (selectElement) {
                selectElement.val(selectElement.data('poprzedni'));
            }
        });
    }

    function anulujLotPrzezOrganizatora(terminId, selectElement) {
        $.post(ajaxurl, {
            action: 'srl_anuluj_lot_przez_organizatora',
            termin_id: terminId
        }, function(response) {
            if (response.success) {
                srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                generujTabelePilotow();
                
                // Pokaż komunikat sukcesu
                var successMsg = $('<div style="position:fixed; top:50px; right:20px; background:#4CAF50; color:white; padding:10px 20px; border-radius:4px; z-index:9999;">Lot został odwołany i klient otrzymał powiadomienie</div>');
                $('body').append(successMsg);
                setTimeout(function() {
                    successMsg.fadeOut(function() {
                        successMsg.remove();
                    });
                }, 4000);
            } else {
                alert('Błąd: ' + response.data);
                selectElement.val(selectElement.data('poprzedni'));
            }
        }).fail(function() {
            alert('Błąd połączenia z serwerem');
            selectElement.val(selectElement.data('poprzedni'));
        });
    }

    function ustawAutoCompleteKlienta(inputEl, wiersz, terminId, selectElement) {
        var timeout;
        
        inputEl.on('input', function() {
            var query = $(this).val();
            
            clearTimeout(timeout);
            wiersz.find('.srl-wyniki-klientow').remove();
            
            if (query.length < 2) return;

            timeout = setTimeout(function() {
                $.getJSON(ajaxurl, {
                    action: 'srl_wyszukaj_klientow_loty',
                    q: query
                }, function(data) {
                    if (data.success && data.data.length > 0) {
                        var wyniki = $('<div class="srl-wyniki-klientow" style="position:absolute; background:white; border:1px solid #ccc; max-height:150px; overflow-y:auto; z-index:1000; width:200px; box-shadow:0 2px 8px rgba(0,0,0,0.1); border-radius:4px;"></div>');
                        
                        data.data.forEach(function(klient) {
                            var opcja = $('<div style="padding:8px; cursor:pointer; border-bottom:1px solid #eee;">' + klient.nazwa + '</div>');
                            opcja.on('click', function() {
                                zmienStatusSlotu(terminId, 'Zarezerwowany', klient.id, '', selectElement, function() {
                                    generujTabelePilotow();
                                });
                            });
                            opcja.on('mouseenter', function() {
                                $(this).css('background', '#f0f0f0');
                            }).on('mouseleave', function() {
                                $(this).css('background', 'white');
                            });
                            wyniki.append(opcja);
                        });
                        
                        inputEl.after(wyniki);
                    }
                }).fail(function() {
                    console.error('Błąd wyszukiwania klientów');
                });
            }, 300);
        });

        // Ukryj wyniki po kliknięciu poza
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.srl-wyszukaj-klienta, .srl-wyniki-klientow').length) {
                $('.srl-wyniki-klientow').remove();
            }
        });
    }

    // ------------------------------
    // 9. Funkcja: Sprawdza nakładanie się slotów
    // ------------------------------
    function sprawdzNakladanie(tabela, pilotId) {
        var wiersze = tabela.find('tbody tr');
        var interwaly = [];
        
        wiersze.each(function() {
            var rd = $(this);
            var startText = rd.find('.srl-start-col').text() || rd.find('.srl-edit-start').val();
            var koniécText = rd.find('.srl-stop-col').text() || rd.find('.srl-edit-stop').val();
            
            if (startText && koniécText) {
                interwaly.push({
                    start: zamienNaMinuty(startText),
                    koniec: zamienNaMinuty(koniécText),
                    wiersz: rd
                });
            }
        });
        
        // Reset tła
        interwaly.forEach(function(el) {
            el.wiersz.css('background-color', '');
        });
        
        // Porównanie każdej pary
        for (var i = 0; i < interwaly.length; i++) {
            for (var j = i + 1; j < interwaly.length; j++) {
                var a = interwaly[i], b = interwaly[j];
                if (a.start < b.koniec && b.start < a.koniec) {
                    a.wiersz.css('background-color', '#fdd');
                    b.wiersz.css('background-color', '#fdd');
                }
            }
        }
    }

    // ------------------------------
    // 10. Funkcja: Aktualizuje dropdown pilotów
    // ------------------------------
    function aktualizujDropdownPilotow() {
        var nowaLiczba = parseInt(liczbaPilotowSelect.val());
        generujPilotSelect.empty();
        for (var i = 1; i <= nowaLiczba; i++) {
            generujPilotSelect.append('<option value="' + i + '">Pilot ' + i + '</option>');
        }
    }

    // ------------------------------
    // 11. Funkcje pomocnicze
    // ------------------------------
    function zamienNaMinuty(czas) {
        var p = czas.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }

    function pad2(n) {
        return (n < 10) ? '0' + n : '' + n;
    }

    // ------------------------------
    // 12. Funkcja: Generuje harmonogram czasowy (timeline)
    // ------------------------------
    function generujHarmonogramCzasowy() {
        var kontener = $('#srl-harmonogram-container');
        kontener.empty();

        // Sprawdź czy są jakieś sloty
        var maSloty = Object.keys(srlIstniejaceGodziny).some(function(pid) {
            return srlIstniejaceGodziny[pid] && srlIstniejaceGodziny[pid].length > 0;
        });

        if (!maSloty) {
            kontener.html('<p style="text-align:center; color:#666; font-style:italic; padding:20px;">Brak zaplanowanych lotów na ten dzień</p>');
            return;
        }

        var liczbaPilotow = parseInt(liczbaPilotowSelect.val());
        
        // Znajdź zakres godzin (najwcześniejsza i najpóźniejsza)
        var najwczesniej = 24 * 60; // w minutach od 00:00
        var najpozniej = 0;

        for (var pid = 1; pid <= liczbaPilotow; pid++) {
            var sloty = srlIstniejaceGodziny[pid] || [];
            sloty.forEach(function(slot) {
                var start = zamienNaMinuty(slot.start);
                var koniec = zamienNaMinuty(slot.koniec);
                najwczesniej = Math.min(najwczesniej, start);
                najpozniej = Math.max(najpozniej, koniec);
            });
        }

        // Dodaj margines (30 min przed i po)
        najwczesniej = Math.max(0, najwczesniej - 30);
        najpozniej = Math.min(24 * 60, najpozniej + 30);

        // Zaokrąglij do pełnych godzin
        najwczesniej = Math.floor(najwczesniej / 60) * 60;
        najpozniej = Math.ceil(najpozniej / 60) * 60;

        var czasTrwania = najpozniej - najwczesniej; // w minutach
        var wysokoscRzadu = 240; // piksele na godzinę (zwiększone z 60 do 120)
        var szerokoscKolumny = 300; // piksele na kolumnę pilota

        // Utwórz kontener harmonogramu
        var harmonogram = $('<div class="srl-harmonogram" style="position:relative; border:1px solid #ddd; background:white; border-radius:4px; overflow:hidden;"></div>');

        // Wysokość całkowita
        var wysokoscCalkowita = (czasTrwania / 60) * wysokoscRzadu + 40; // +40 na nagłówek

        // Utwórz nagłówek z pilotami
        var naglowek = $('<div class="srl-harmonogram-header" style="position:absolute; top:0; left:80px; right:0; height:40px; background:#0073aa; color:white; display:flex; z-index:10;"></div>');
        
        for (var pid = 1; pid <= liczbaPilotow; pid++) {
            var kolumnaPilota = $('<div style="width:' + szerokoscKolumny + 'px; text-align:center; line-height:40px; font-weight:bold; border-right:1px solid rgba(255,255,255,0.3);">Pilot ' + pid + '</div>');
            naglowek.append(kolumnaPilota);
        }
        harmonogram.append(naglowek);

        // Utwórz oś czasu (lewa strona)
        var osCzasu = $('<div class="srl-harmonogram-time-axis" style="position:absolute; top:40px; left:0; width:80px; height:' + (wysokoscCalkowita - 40) + 'px; background:#f5f5f5; border-right:1px solid #ddd;"></div>');
        
        // Dodaj znaczniki godzin z większymi odstępami
        for (var godzina = Math.floor(najwczesniej / 60); godzina <= Math.ceil(najpozniej / 60); godzina++) {
            var pozycjaY = ((godzina * 60 - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);
            // Etykieta godziny ma być na tej samej wysokości co linia, nie na środku bloku
            var etykietaGodziny = $('<div style="position:absolute; top:' + (Math.round(pozycjaY) - 10) + 'px; left:0; width:80px; height:20px; text-align:center; line-height:20px; font-size:14px; font-weight:bold; display:flex; align-items:center; justify-content:center; background:rgba(0,115,170,0.05);">' + pad2(godzina) + ':00</div>');
            osCzasu.append(etykietaGodziny);
            
            // Dodaj znaczniki co 15 minut dla lepszej orientacji
            for (var minuta = 15; minuta < 60; minuta += 15) {
                var pozycjaYMin = (((godzina * 60 + minuta) - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);
                var etykietaMinuty = $('<div style="position:absolute; top:' + (Math.round(pozycjaYMin) - 8) + 'px; left:10px; width:60px; height:16px; text-align:center; line-height:16px; font-size:10px; color:#666;">' + pad2(godzina) + ':' + pad2(minuta) + '</div>');
                osCzasu.append(etykietaMinuty);
            }
        }
        harmonogram.append(osCzasu);

        // Utwórz obszar slotów
        var obszarSlotow = $('<div class="srl-harmonogram-slots" style="position:absolute; top:40px; left:80px; right:0; height:' + (wysokoscCalkowita - 40) + 'px; background:white;"></div>');

        // Dodaj linie godzinowe (siatka) z większymi odstępami
        for (var godzina = Math.floor(najwczesniej / 60); godzina <= Math.ceil(najpozniej / 60); godzina++) {
            var pozycjaY = ((godzina * 60 - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);
            var liniaGodzinowa = $('<div style="position:absolute; top:' + Math.round(pozycjaY) + 'px; left:0; right:0; height:2px; background:#0073aa; z-index:2; opacity:0.3;"></div>');
            obszarSlotow.append(liniaGodzinowa);
            
            // Dodaj subtelne linie co 15 minut
            for (var minuta = 15; minuta < 60; minuta += 15) {
                var pozycjaYMin = (((godzina * 60 + minuta) - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);
                var liniaMinutowa = $('<div style="position:absolute; top:' + Math.round(pozycjaYMin) + 'px; left:0; right:0; height:1px; background:#ccc; z-index:1; opacity:0.5;"></div>');
                obszarSlotow.append(liniaMinutowa);
            }
        }

        // Dodaj linie pionowe (kolumny pilotów)
        for (var pid = 1; pid <= liczbaPilotow; pid++) {
            var pozycjaX = (pid - 1) * szerokoscKolumny;
            var liniaPilota = $('<div style="position:absolute; top:0; left:' + pozycjaX + 'px; width:1px; height:100%; background:#e0e0e0; z-index:1;"></div>');
            obszarSlotow.append(liniaPilota);
        }

        // Dodaj sloty dla każdego pilota
        for (var pid = 1; pid <= liczbaPilotow; pid++) {
            var sloty = srlIstniejaceGodziny[pid] || [];
            var pozycjaXKolumny = (pid - 1) * szerokoscKolumny;

            sloty.forEach(function(slot) {
                var startMin = zamienNaMinuty(slot.start);
                var koniecMin = zamienNaMinuty(slot.koniec);
                var czasSlotu = koniecMin - startMin;

                // Pozycja Y (od góry) - precyzyjna pozycja bez nakładania
                var pozycjaY = ((startMin - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);
                var wysokoscSlotu = (czasSlotu / czasTrwania) * (wysokoscCalkowita - 40);
                
                // Zaokrąglij do pełnych pikseli aby uniknąć nakładania
                pozycjaY = Math.round(pozycjaY);
                wysokoscSlotu = Math.round(wysokoscSlotu);
                
                // Dodaj 1px do wysokości, aby zniwelować wizualnie grubsze linie przy stykających się slotach
                wysokoscSlotu += 1;
                
                // Zapewnij minimalną wysokość 3 piksele
                if (wysokoscSlotu < 3) {
                    wysokoscSlotu = 3;
                }

                // Kolor na podstawie statusu
                var kolor = getKolorStatusu(slot.status);
                
                // Tekst na slocie
                // Tekst na slocie - PEŁNE INFORMACJE O PASAŻERZE
                var tekstSlotu = slot.start + ' - ' + slot.koniec;
                var maInformacjeOPasazerze = false;
                
                if ((slot.status === 'Zarezerwowany' || slot.status === 'Zrealizowany') && slot.klient_nazwa) {
                    // Dla przypisanych klientów - spróbuj pobrać pełne dane z cache lub wyświetl podstawowe
                    var pelneInfoKlienta = slot.dane_pasazera_cache || null;
                    
                    if (pelneInfoKlienta) {
                        // Jeśli mamy pełne dane z cache - wyświetl tak jak dla prywatnych
                        var linia1 = slot.klient_nazwa;
                        if (pelneInfoKlienta.rok_urodzenia) {
                            var wiek = new Date().getFullYear() - parseInt(pelneInfoKlienta.rok_urodzenia);
                            linia1 += ' (' + wiek + ' lat)';
                        }
                        if (pelneInfoKlienta.kategoria_wagowa) {
                            linia1 += ', ' + pelneInfoKlienta.kategoria_wagowa;
                        }
                        if (pelneInfoKlienta.sprawnosc_fizyczna) {
                            var sprawnoscMapKlient = {
                                'zdolnosc_do_marszu': 'Marsz',
                                'zdolnosc_do_biegu': 'Bieg', 
                                'sprinter': 'Sprinter'
                            };
                            linia1 += ', ' + (sprawnoscMapKlient[pelneInfoKlienta.sprawnosc_fizyczna] || pelneInfoKlienta.sprawnosc_fizyczna);
                        }
                        tekstSlotu += '\n' + linia1;
                        
                        // Linia 2: Telefon
                        if (pelneInfoKlienta.telefon) {
                            tekstSlotu += '\nTel: ' + pelneInfoKlienta.telefon;
                        }
                        
                        // Linia 3: Uwagi (jeśli są)
                        if (pelneInfoKlienta.uwagi && pelneInfoKlienta.uwagi.trim()) {
                            var uwagiKlient = pelneInfoKlienta.uwagi.trim();
                            if (uwagiKlient.length > 50) {
                                uwagiKlient = uwagiKlient.substring(0, 47) + '...';
                            }
                            tekstSlotu += '\nUwagi: ' + uwagiKlient;
                        }
                    } else {
                        // Fallback - podstawowe info + oznaczenie że brak pełnych danych
                        tekstSlotu += '\n' + slot.klient_nazwa;
                        if (slot.lot_id) {
                            tekstSlotu += '\n(ID: #' + slot.lot_id + ')';
                            tekstSlotu += '\n[Kliknij INFO w tabeli]';
                        }
                    }
                    maInformacjeOPasazerze = true;
                    
                } else if (slot.status === 'Prywatny') {
                    // Dla lotów prywatnych - pełne dane z notatki
                    if (slot.klient_nazwa) {
                        tekstSlotu += '\n' + slot.klient_nazwa;
                        maInformacjeOPasazerze = true;
                    } else if (slot.notatka) {
                        try {
                            var danePrivate = JSON.parse(slot.notatka);
                            if (danePrivate.imie && danePrivate.nazwisko) {
                                var imieNazwisko = danePrivate.imie + ' ' + danePrivate.nazwisko;
                                
                                // Linia 1: Imię, nazwisko, wiek, waga, sprawność
                                var linia1 = imieNazwisko;
                                if (danePrivate.rok_urodzenia) {
                                    var wiek = new Date().getFullYear() - parseInt(danePrivate.rok_urodzenia);
                                    linia1 += ' (' + wiek + ' lat)';
                                }
                                if (danePrivate.kategoria_wagowa) {
                                    linia1 += ', ' + danePrivate.kategoria_wagowa;
                                }
                                if (danePrivate.sprawnosc_fizyczna) {
                                    var sprawnoscMap = {
                                        'zdolnosc_do_marszu': 'Marsz',
                                        'zdolnosc_do_biegu': 'Bieg', 
                                        'sprinter': 'Sprinter'
                                    };
                                    linia1 += ', ' + (sprawnoscMap[danePrivate.sprawnosc_fizyczna] || danePrivate.sprawnosc_fizyczna);
                                }
                                tekstSlotu += '\n' + linia1;
                                
                                // Linia 2: Telefon
                                if (danePrivate.telefon) {
                                    tekstSlotu += '\nTel: ' + danePrivate.telefon;
                                }
                                
                                // Linia 3: Uwagi (jeśli są)
                                if (danePrivate.uwagi && danePrivate.uwagi.trim()) {
                                    var uwagi = danePrivate.uwagi.trim();
                                    // Skróć uwagi jeśli są za długie
                                    if (uwagi.length > 50) {
                                        uwagi = uwagi.substring(0, 47) + '...';
                                    }
                                    tekstSlotu += '\nUwagi: ' + uwagi;
                                }
                                
                                maInformacjeOPasazerze = true;
                            }
                        } catch (e) {
                            // W przypadku błędu parsowania JSON, nie dodawaj szczegółów
                        }
                    }
                }

                // Dynamiczne obliczenie wysokości slotu na podstawie zawartości
                var liczbaLinii = tekstSlotu.split('\n').length;
                var minimalnaWysokoscNaPodstawieLinii = Math.max(liczbaLinii * 16 + 8, 30); // 16px na linię + padding
                var finalna_wysokosc = Math.max(wysokoscSlotu, minimalnaWysokoscNaPodstawieLinii);
                
                // Jeśli slot ma informacje o pasażerze, zwiększ minimalną wysokość
                if (maInformacjeOPasazerze) {
                    finalna_wysokosc = Math.max(finalna_wysokosc, 85);
                }

                // Utwórz element slotu z odpowiednią wysokością
                var elementSlotu = $('<div class="srl-slot-harmonogram" style="position:absolute; top:' + pozycjaY + 'px; left:' + (pozycjaXKolumny + 3) + 'px; width:' + (szerokoscKolumny - 6) + 'px; height:' + finalna_wysokosc + 'px; background:' + kolor.bg + '; border:1px solid ' + kolor.border + '; border-radius:3px; z-index:5; cursor:pointer; overflow:hidden; display:flex; align-items:flex-start; justify-content:center; text-align:center; font-size:' + (finalna_wysokosc > 60 ? '10px' : '9px') + '; font-weight:' + (maInformacjeOPasazerze ? '500' : 'bold') + '; color:' + kolor.text + '; white-space:pre-line; padding:4px 2px; box-sizing:border-box; margin:0; line-height:1.3;"></div>');
                
                elementSlotu.text(tekstSlotu);
                
                // Hover effect - bez tooltip
                elementSlotu.on('mouseenter', function() {
                    $(this).css('transform', 'scale(1.02)');
                    $(this).css('box-shadow', '0 4px 8px rgba(0,0,0,0.2)');
                }).on('mouseleave', function() {
                    $(this).css('transform', 'scale(1)');
                    $(this).css('box-shadow', 'none');
                });

                // Kliknięcie - scroll do odpowiedniego wiersza w tabeli
                elementSlotu.on('click', function() {
                    var targetRow = $('tr[data-termin-id="' + slot.id + '"]');
                    if (targetRow.length) {
                        $('html, body').animate({
                            scrollTop: targetRow.offset().top - 100
                        }, 500);
                        targetRow.css('background-color', '#fff3cd').delay(2000).queue(function() {
                            $(this).css('background-color', '');
                            $(this).dequeue();
                        });
                    }
                });

                obszarSlotow.append(elementSlotu);
            });
        }

        harmonogram.append(obszarSlotow);

        // Ustaw wysokość kontenera
        harmonogram.css('height', wysokoscCalkowita + 'px');

        // Dodaj legendę statusów
        var legenda = $('<div class="srl-harmonogram-legenda" style="margin-top:15px; padding:10px; background:#f8f9fa; border-radius:4px; display:flex; gap:15px; flex-wrap:wrap; justify-content:center;"></div>');
        
        var statusy = [
            {status: 'Wolny', label: 'Wolny'},
            {status: 'Prywatny', label: 'Prywatny'},
            {status: 'Zarezerwowany', label: 'Zarezerwowany'},
            {status: 'Zrealizowany', label: 'Zrealizowany'},
            {status: 'Odwołany przez klienta', label: 'Odwołany przez klienta'},
            {status: 'Odwołany przez organizatora', label: 'Odwołany przez organizatora'}
        ];

        statusy.forEach(function(item) {
            var kolor = getKolorStatusu(item.status);
            var elementLegenda = $('<div style="display:flex; align-items:center; gap:5px;"><div style="width:20px; height:20px; background:' + kolor.bg + '; border:2px solid ' + kolor.border + '; border-radius:3px;"></div><span style="font-size:12px;">' + item.label + '</span></div>');
            legenda.append(elementLegenda);
        });

        kontener.append(harmonogram);
        kontener.append(legenda);
    }

    // Funkcja pomocnicza: zwraca kolory dla statusów
    function getKolorStatusu(status) {
        switch(status) {
            case 'Wolny':
                return {bg: '#d4edda', border: '#28a745', text: '#155724'};
            case 'Prywatny':
                return {bg: '#e2e3e5', border: '#6c757d', text: '#495057'};
            case 'Zarezerwowany':
                return {bg: '#cce5ff', border: '#007bff', text: '#004085'};
            case 'Zrealizowany':
                return {bg: '#d1ecf1', border: '#17a2b8', text: '#0c5460'};
            case 'Odwołany przez organizatora':
                return {bg: '#f8d7da', border: '#dc3545', text: '#721c24'};
            default:
                return {bg: '#f8f9fa', border: '#6c757d', text: '#495057'};
        }
    }
	
	// Funkcja pokazywania formularza przypisania klienta
function pokazFormularzPrzypisaniaKlienta(terminId) {
    var modal = $('<div class="srl-modal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;"></div>');
    var content = $('<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:30px; border-radius:8px; width:500px; max-width:90%; max-height:80%; overflow-y:auto;"></div>');
    
    content.html(`
        <h3>Przypisz klienta do slotu</h3>
        <div style="margin-bottom:20px;">
            <label>Wyszukaj klienta:</label>
            <input type="text" id="srl-search-client" placeholder="Email, telefon, imię, nazwisko lub ID lotu..." style="width:100%; padding:8px; margin-top:5px;">
        </div>
        <div id="srl-search-results" style="max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:10px; display:none;"></div>
        <div style="margin-top:20px; text-align:right;">
            <button class="button" onclick="$(this).closest('.srl-modal').remove();">Anuluj</button>
        </div>
    `);
    
    modal.append(content);
    $('body').append(modal);
    
    // Obsługa wyszukiwania
    var searchTimeout;
    $('#srl-search-client').on('input', function() {
        clearTimeout(searchTimeout);
        var query = $(this).val();
        
        if (query.length < 2) {
            $('#srl-search-results').hide();
            return;
        }
        
        searchTimeout = setTimeout(function() {
            szukajDostepnychKlientow(query, terminId);
        }, 300);
    });
}

// Funkcja pokazywania formularza danych prywatnych
function pokazFormularzDanychPrywatnych(terminId) {
    var modal = $('<div class="srl-modal" style="position:fixed; top:0; left:0; width:100%; background:rgba(0,0,0,0.5); z-index:9999;"></div>');
    var content = $('<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:30px; border-radius:8px; width:600px; max-width:90%; max-height:80%; overflow-y:auto;"></div>');
    
    content.html(`
        <h3>🪪 Dane pasażera (lot prywatny)</h3>
        <form id="srl-form-dane-prywatne">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                <div>
                    <label>Imię *</label>
                    <input type="text" name="imie" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div>
                    <label>Nazwisko *</label>
                    <input type="text" name="nazwisko" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div>
                    <label>Rok urodzenia *</label>
                    <input type="number" name="rok_urodzenia" min="1920" max="2010" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div>
                    <label>Telefon *</label>
                    <input type="tel" name="telefon" required style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div>
                    <label>Sprawność fizyczna *</label>
                    <select name="sprawnosc_fizyczna" required style="width:100%; padding:8px; margin-top:5px;">
                        <option value="">Wybierz...</option>
                        <option value="zdolnosc_do_marszu">Zdolność do marszu</option>
                        <option value="zdolnosc_do_biegu">Zdolność do biegu</option>
                        <option value="sprinter">Sprinter!</option>
                    </select>
                </div>
                <div>
                    <label>Kategoria wagowa *</label>
                    <select name="kategoria_wagowa" required style="width:100%; padding:8px; margin-top:5px;">
                        <option value="">Wybierz...</option>
                        <option value="25-40kg">25-40kg</option>
                        <option value="41-60kg">41-60kg</option>
                        <option value="61-90kg">61-90kg</option>
                        <option value="91-120kg">91-120kg</option>
                        <option value="120kg+">120kg+</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:20px;">
                <label>Dodatkowe uwagi</label>
                <textarea name="uwagi" rows="3" style="width:100%; padding:8px; margin-top:5px;" placeholder="Np. alergie, obawy, specjalne potrzeby..."></textarea>
            </div>
            <div style="text-align:right;">
                <button type="button" class="button" onclick="$(this).closest('.srl-modal').remove();">Anuluj</button>
                <button type="submit" class="button button-primary" style="margin-left:10px;">Zapisz</button>
            </div>
        </form>
    `);
    
    modal.append(content);
    $('body').append(modal);
    
    // Obsługa zapisu
    $('#srl-form-dane-prywatne').on('submit', function(e) {
        e.preventDefault();
        zapiszDanePrywatne(terminId, $(this).serialize(), modal);
    });
}

// Funkcja wyszukiwania dostępnych klientów
function szukajDostepnychKlientow(query, terminId) {
    $.post(ajaxurl, {
        action: 'srl_wyszukaj_dostepnych_klientow',
        query: query
    }, function(response) {
        if (response.success && response.data.length > 0) {
            var html = '<h4>Znalezieni klienci z dostępnymi lotami:</h4>';
            response.data.forEach(function(klient) {
                html += '<div style="border:1px solid #ddd; padding:10px; margin:5px 0; cursor:pointer;" onclick="przypisKlienta(' + terminId + ', ' + klient.lot_id + ', \'' + klient.nazwa + '\')">';
                html += '<strong>' + klient.nazwa + '</strong><br>';
                html += '<small>Lot #' + klient.lot_id + ' - ' + klient.produkt + '</small>';
                html += '</div>';
            });
            $('#srl-search-results').html(html).show();
        } else {
            $('#srl-search-results').html('<p>Brak wyników</p>').show();
        }
    });
}

// Funkcja przypisywania klienta
function przypisKlienta(terminId, lotId, nazwa) {
    if (!confirm('Czy na pewno przypisać klienta "' + nazwa + '" do tego slotu?')) return;
    
    $.post(ajaxurl, {
        action: 'srl_przypisz_klienta_do_slotu',
        termin_id: terminId,
        lot_id: lotId
    }, function(response) {
        if (response.success) {
            alert('Klient został przypisany pomyślnie!');
            $('.srl-modal').remove();
            // Odśwież tabelę
            generujTabelePilotow();
        } else {
            alert('Błąd: ' + response.data);
        }
    });
}

// Funkcja zapisu danych prywatnych
function zapiszDanePrywatne(terminId, formData, modal) {
    $.post(ajaxurl, {
        action: 'srl_zapisz_dane_prywatne',
        termin_id: terminId
    } + '&' + formData, function(response) {
        if (response.success) {
            alert('Dane zostały zapisane!');
            modal.remove();
            // Odśwież tabelę
            generujTabelePilotow();
        } else {
            alert('Błąd: ' + response.data);
        }
    });
}
	
// UJEDNOLICONA funkcja pokazDanePasazeraModal (dla przypisanych klientów)
function pokazDanePasazeraModal(lotId, userId) {
    $.post(ajaxurl, {
        action: 'srl_pobierz_szczegoly_lotu',
        lot_id: lotId,
        user_id: userId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            var dane = response.data;
            pokazUjednoliconyModalDanych(dane, 'Szczegóły lotu #' + lotId, false);
        } else {
            alert('Błąd: ' + response.data);
        }
    });
}

// UJEDNOLICONA funkcja pokazDanePrywatneModal (dla lotów prywatnych)
function pokazDanePrywatneModal(terminId) {
    $.post(ajaxurl, {
        action: 'srl_pobierz_dane_prywatne',
        termin_id: terminId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success && response.data) {
            var dane = response.data;
            pokazUjednoliconyModalDanych(dane, 'Szczegóły lotu prywatnego', true, terminId);
        } else {
            alert('Brak danych do wyświetlenia');
        }
    });
}

// NOWA funkcja - ujednolicony modal danych pasażera
function pokazUjednoliconyModalDanych(dane, tytul, moznaEdytowac, terminId) {
    // Przygotuj dane do wyświetlenia
    var imieNazwisko = '';
    if (dane.imie && dane.nazwisko) {
        imieNazwisko = dane.imie + ' ' + dane.nazwisko;
    } else if (dane.imie) {
        imieNazwisko = dane.imie;
    } else if (dane.nazwisko) {
        imieNazwisko = dane.nazwisko;
    } else {
        imieNazwisko = 'Brak danych';
    }
    
    var rokUrodzeniaText = '';
    if (dane.rok_urodzenia) {
        var wiek = new Date().getFullYear() - parseInt(dane.rok_urodzenia);
        rokUrodzeniaText = dane.rok_urodzenia + ' (' + wiek + ' lat)';
    } else {
        rokUrodzeniaText = 'Brak danych';
    }
    
    // Funkcja do konwersji wartości technicznych na czytelne etykiety
    function formatujSprawnoscFizyczna(wartosc) {
        var mapowanie = {
            'zdolnosc_do_marszu': 'Zdolność do marszu',
            'zdolnosc_do_biegu': 'Zdolność do biegu',
            'sprinter': 'Sprinter!'
        };
        return mapowanie[wartosc] || wartosc || 'Brak danych';
    }
    
    // Utwórz modal
    var modal = $('<div class="srl-modal-dane-pasazera" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;"></div>');
    
    var content = $('<div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; max-height: 90%; overflow-y: auto; width: 90%;"></div>');
    
    var contentHtml = '<h3 style="margin-top: 0; color: #0073aa; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">' + tytul + '</h3>';
    
    // Sekcja wyświetlania danych
    contentHtml += '<div id="srl-dane-wyswietl">';
    contentHtml += '<div style="line-height: 1.8; font-size: 15px;">';
    contentHtml += '<p><strong>Imię i nazwisko:</strong> ' + imieNazwisko + '</p>';
    contentHtml += '<p><strong>Rok urodzenia:</strong> ' + rokUrodzeniaText + '</p>';
    contentHtml += '<p><strong>Telefon:</strong> ' + (dane.telefon || 'Brak danych') + '</p>';
    contentHtml += '<p><strong>Sprawność fizyczna:</strong> ' + formatujSprawnoscFizyczna(dane.sprawnosc_fizyczna) + '</p>';
    contentHtml += '<p><strong>Kategoria wagowa:</strong> ' + (dane.kategoria_wagowa || 'Brak danych') + '</p>';
    if (dane.uwagi && dane.uwagi.trim()) {
        contentHtml += '<p><strong>Uwagi:</strong> ' + dane.uwagi + '</p>';
    } else {
        contentHtml += '<p><strong>Uwagi:</strong> Brak uwag</p>';
    }
    contentHtml += '</div>';
    contentHtml += '</div>';
    
    // Sekcja edycji (tylko dla lotów prywatnych)
    if (moznaEdytowac) {
        contentHtml += '<div id="srl-dane-edytuj" style="display:none;">';
        contentHtml += '<form id="srl-form-edytuj-prywatne">';
        contentHtml += '<div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">';
        contentHtml += '<div><label><strong>Imię *</strong></label><input type="text" name="imie" value="' + (dane.imie || '') + '" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;"></div>';
        contentHtml += '<div><label><strong>Nazwisko *</strong></label><input type="text" name="nazwisko" value="' + (dane.nazwisko || '') + '" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;"></div>';
        contentHtml += '<div><label><strong>Rok urodzenia *</strong></label><input type="number" name="rok_urodzenia" value="' + (dane.rok_urodzenia || '') + '" min="1920" max="2010" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;"></div>';
        contentHtml += '<div><label><strong>Telefon *</strong></label><input type="tel" name="telefon" value="' + (dane.telefon || '') + '" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;"></div>';
        contentHtml += '<div><label><strong>Sprawność fizyczna *</strong></label><select name="sprawnosc_fizyczna" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;">';
        contentHtml += '<option value="">Wybierz...</option>';
        var sprawnoscOpcje = [
            {value: 'zdolnosc_do_marszu', label: 'Zdolność do marszu'},
            {value: 'zdolnosc_do_biegu', label: 'Zdolność do biegu'},
            {value: 'sprinter', label: 'Sprinter!'}
        ];
        sprawnoscOpcje.forEach(function(opcja) {
            var selected = dane.sprawnosc_fizyczna === opcja.value ? 'selected' : '';
            contentHtml += '<option value="' + opcja.value + '" ' + selected + '>' + opcja.label + '</option>';
        });
        contentHtml += '</select></div>';
        contentHtml += '<div><label><strong>Kategoria wagowa *</strong></label><select name="kategoria_wagowa" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;">';
        contentHtml += '<option value="">Wybierz...</option>';
        var wagoweOpcje = ['25-40kg', '41-60kg', '61-90kg', '91-120kg', '120kg+'];
        wagoweOpcje.forEach(function(opcja) {
            var selected = dane.kategoria_wagowa === opcja ? 'selected' : '';
            contentHtml += '<option value="' + opcja + '" ' + selected + '>' + opcja + '</option>';
        });
        contentHtml += '</select></div>';
        contentHtml += '</div>';
        contentHtml += '<div style="margin-bottom:20px;"><label><strong>Dodatkowe uwagi</strong></label><textarea name="uwagi" rows="3" style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Np. alergie, obawy, specjalne potrzeby...">' + (dane.uwagi || '') + '</textarea></div>';
        contentHtml += '</form>';
        contentHtml += '</div>';
    }
    
    // Przyciski
    contentHtml += '<div style="text-align:right; border-top:1px solid #ddd; padding-top:15px; margin-top: 20px;">';
    if (moznaEdytowac) {
        contentHtml += '<button id="srl-btn-edytuj" class="button button-primary" style="margin-right:10px;">Edytuj dane</button>';
        contentHtml += '<button id="srl-btn-zapisz" class="button button-primary" style="display:none; margin-right:10px;">Zapisz zmiany</button>';
        contentHtml += '<button id="srl-btn-anuluj-edycje" class="button" style="display:none; margin-right:10px;">Anuluj</button>';
    }
    contentHtml += '<button class="button srl-btn-zamknij">Zamknij</button>';
    contentHtml += '</div>';
    
    content.html(contentHtml);
    modal.append(content);
    $('body').append(modal);
    
    // Obsługa przycisków (tylko dla lotów prywatnych)
    if (moznaEdytowac) {
        modal.find('#srl-btn-edytuj').on('click', function() {
            modal.find('#srl-dane-wyswietl').hide();
            modal.find('#srl-dane-edytuj').show();
            modal.find('#srl-btn-edytuj').hide();
            modal.find('#srl-btn-zapisz, #srl-btn-anuluj-edycje').show();
        });
        
        modal.find('#srl-btn-anuluj-edycje').on('click', function() {
            modal.find('#srl-dane-edytuj').hide();
            modal.find('#srl-dane-wyswietl').show();
            modal.find('#srl-btn-zapisz, #srl-btn-anuluj-edycje').hide();
            modal.find('#srl-btn-edytuj').show();
        });
        
        modal.find('#srl-btn-zapisz').on('click', function() {
            var formData = modal.find('#srl-form-edytuj-prywatne').serialize();
            zapiszEdytowaneDanePrywatne(terminId, formData, modal);
        });
    }
    
    // Zamknięcie modalu
    modal.find('.srl-btn-zamknij').on('click', function() {
        modal.remove();
    });
    
    modal.on('click', function(e) {
        if (e.target === this) {
            modal.remove();
        }
    });
}

// Funkcja do zapisywania edytowanych danych prywatnych (bez zmian)
function zapiszEdytowaneDanePrywatne(terminId, formData, modal) {
    // Przekonwertuj formData na obiekt
    var formDataObj = {};
    formData.split('&').forEach(function(pair) {
        var keyValue = pair.split('=');
        if (keyValue.length === 2) {
            formDataObj[decodeURIComponent(keyValue[0])] = decodeURIComponent(keyValue[1]);
        }
    });
    
    var requestData = {
        action: 'srl_zapisz_dane_prywatne',
        termin_id: terminId,
        nonce: srlAdmin.nonce,
        imie: formDataObj.imie || '',
        nazwisko: formDataObj.nazwisko || '',
        rok_urodzenia: formDataObj.rok_urodzenia || '',
        telefon: formDataObj.telefon || '',
        sprawnosc_fizyczna: formDataObj.sprawnosc_fizyczna || '',
        kategoria_wagowa: formDataObj.kategoria_wagowa || '',
        uwagi: formDataObj.uwagi || ''
    };
    
    $.post(ajaxurl, requestData, function(response) {
        if (response.success) {
            modal.remove();
            generujTabelePilotow();
            pokazKomunikatSukcesu('Dane zostały zaktualizowane!');
        } else {
            alert('Błąd: ' + response.data);
        }
    }).fail(function(xhr, status, error) {
        alert('Błąd połączenia z serwerem: ' + error);
    });
}
	


// Dodaj również odświeżanie po każdej zmianie statusu
function zmienStatusSlotu(terminId, status, klientId, notatka, selectElement, callback, grupowa) {
    $.post(ajaxurl, {
        action: 'srl_zmien_status_godziny',
        termin_id: terminId,
        status: status,
        klient_id: klientId || 0,
        notatka: notatka || ''
    }, function(response) {
        if (response.success) {
            srlIstniejaceGodziny = response.data.godziny_wg_pilota;
            if (callback) {
                callback();
            } else if (!grupowa) {
                generujTabelePilotow();
            }
        } else {
            alert('Błąd zmiany statusu: ' + response.data);
            if (selectElement) {
                selectElement.val(selectElement.data('poprzedni'));
            }
        }
    }).fail(function() {
        alert('Błąd połączenia z serwerem');
        if (selectElement) {
            selectElement.val(selectElement.data('poprzedni'));
        }
    });
}



/// Modal przypisywania slotu - POPRAWIONA WERSJA
function pokazModalPrzypisaniaSlotu(terminId) {
	console.log('Wywołano pokazModalPrzypisaniaSlotu z terminId:', terminId); // Debug
    var modal = $('<div class="srl-modal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;"></div>');
    var content = $('<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:30px; border-radius:8px; width:600px; max-width:90%; max-height:80%; overflow-y:auto;"></div>');
    
    content.html(`
        <h3>Przypisz klienta do slotu</h3>
        <div style="margin-bottom:20px;">
            <label style="display:block; margin-bottom:10px; font-weight:bold;">Wybierz typ przypisania:</label>
            <div style="display:flex; gap:15px;">
                <button id="srl-typ-wykupiony" class="button button-primary">Wykupiony lot</button>
                <button id="srl-typ-prywatny" class="button button-secondary">Lot prywatny</button>
            </div>
        </div>
        
        <div id="srl-sekcja-wykupiony" style="display:none;">
            <h4>Wyszukaj wykupiony lot</h4>
            <div style="display:flex; gap:10px; margin-bottom:15px; align-items:end;">
                <div style="flex:1;">
                    <label>Szukaj w:</label>
                    <select id="srl-search-field" style="width:100%; padding:5px;">
                        <option value="wszedzie">Wszędzie</option>
                        <option value="email">Email</option>
                        <option value="id_lotu">ID lotu</option>
                        <option value="id_zamowienia">ID zamówienia</option>
                        <option value="imie_nazwisko">Imię i nazwisko</option>
                        <option value="login">Login</option>
                        <option value="telefon">Telefon</option>
                    </select>
                </div>
                <div style="flex:2;">
                    <label>Szukana fraza:</label>
                    <input type="text" id="srl-search-query" placeholder="Wprowadź szukaną frazę..." style="width:100%; padding:5px;">
                </div>
                <div>
                    <button id="srl-search-btn" class="button">Szukaj</button>
                </div>
            </div>
            <div id="srl-search-results" style="max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:10px; display:none;"></div>
        </div>
        
        <div id="srl-sekcja-prywatny" style="display:none;">
            <h4>Dane pasażera (lot prywatny)</h4>
            <form id="srl-form-prywatny">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                    <div>
                        <label>Imię *</label>
                        <input type="text" name="imie" required style="width:100%; padding:8px; margin-top:5px;">
                    </div>
                    <div>
                        <label>Nazwisko *</label>
                        <input type="text" name="nazwisko" required style="width:100%; padding:8px; margin-top:5px;">
                    </div>
                    <div>
                        <label>Rok urodzenia *</label>
                        <input type="number" name="rok_urodzenia" min="1920" max="2010" required style="width:100%; padding:8px; margin-top:5px;">
                    </div>
                    <div>
                        <label>Numer telefonu *</label>
                        <input type="tel" name="telefon" required style="width:100%; padding:8px; margin-top:5px;">
                    </div>
                    <div>
                        <label>Sprawność fizyczna *</label>
                        <select name="sprawnosc_fizyczna" required style="width:100%; padding:8px; margin-top:5px;">
                            <option value="">Wybierz poziom sprawności</option>
                            <option value="zdolnosc_do_marszu">Zdolność do marszu</option>
                            <option value="zdolnosc_do_biegu">Zdolność do biegu</option>
                            <option value="sprinter">Sprinter!</option>
                        </select>
                    </div>
                    <div>
                        <label>Kategoria wagowa *</label>
                        <select name="kategoria_wagowa" required style="width:100%; padding:8px; margin-top:5px;">
                            <option value="">Wybierz kategorię wagową</option>
                            <option value="25-40kg">25-40kg</option>
                            <option value="41-60kg">41-60kg</option>
                            <option value="61-90kg">61-90kg</option>
                            <option value="91-120kg">91-120kg</option>
                            <option value="120kg+">120kg+</option>
                        </select>
                    </div>
                </div>
                <div style="grid-column: 1 / -1; margin-bottom:20px;">
                    <label>Dodatkowe uwagi</label>
                    <textarea name="uwagi" rows="3" style="width:100%; padding:8px; margin-top:5px;" placeholder="Np. alergie, obawy, specjalne potrzeby..."></textarea>
                </div>
                <div style="text-align:right;">
                    <button type="submit" class="button button-primary">Zapisz lot prywatny</button>
                </div>
            </form>
        </div>
        
        <div style="margin-top:20px; text-align:right; border-top:1px solid #ddd; padding-top:15px;">
            <button class="button srl-modal-anuluj">Anuluj</button>
        </div>
    `);
    
    modal.append(content);
    $('body').append(modal);
    
    // OBSŁUGA EVENTÓW BEZPOŚREDNIO NA ELEMENTACH MODALU
    // Zamknięcie modalu
    modal.find('.srl-modal-anuluj').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        modal.remove();
    });

    // Kliknięcie poza modalem zamyka go
    modal.on('click', function(e) {
        if (e.target === this) {
            modal.remove();
        }
    });
    
    // Przełączanie między typami lotów - bezpośrednio na elementach modalu
    modal.find('#srl-typ-wykupiony').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Kliknięto Wykupiony lot'); // Debug
        $(this).removeClass('button-secondary').addClass('button-primary');
        modal.find('#srl-typ-prywatny').removeClass('button-primary').addClass('button-secondary');
        modal.find('#srl-sekcja-wykupiony').show();
        modal.find('#srl-sekcja-prywatny').hide();
    });

    modal.find('#srl-typ-prywatny').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Kliknięto Lot prywatny'); // Debug
        $(this).removeClass('button-secondary').addClass('button-primary');
        modal.find('#srl-typ-wykupiony').removeClass('button-primary').addClass('button-secondary');
        modal.find('#srl-sekcja-prywatny').show();
        modal.find('#srl-sekcja-wykupiony').hide();
    });

    // Obsługa wyszukiwania lotów
    modal.find('#srl-search-btn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        wyszukajWykupionyLot(terminId, modal);
    });

    modal.find('#srl-search-query').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            wyszukajWykupionyLot(terminId, modal);
        }
    });

    // Obsługa formularza prywatnego
    modal.find('#srl-form-prywatny').on('submit', function(e) {
        e.preventDefault();
        e.stopPropagation();
        zapiszLotPrywatny(terminId, $(this).serialize(), modal);
    });
}

// Wyszukiwanie wykupionego lotu - ZAKTUALIZOWANA FUNKCJA
function wyszukajWykupionyLot(terminId, modal) {
    var searchField = modal.find('#srl-search-field').val();
    var query = modal.find('#srl-search-query').val();
    
    console.log('Debug - searchField:', searchField);
    console.log('Debug - query przed trim:', "'" + query + "'");
    
    if (query) {
        query = query.trim();
        console.log('Debug - query po trim:', "'" + query + "'");
        console.log('Debug - query.length:', query.length);
    } else {
        console.log('Debug - query jest null/undefined');
        query = '';
    }
    
    if (query.length < 2) {
        alert('Wprowadź co najmniej 2 znaki do wyszukania. Aktualna długość: ' + query.length);
        return;
    }
    
    $.post(ajaxurl, {
        action: 'srl_wyszukaj_wolne_loty',
        search_field: searchField,
        query: query,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success && response.data.length > 0) {
            var html = '<h5>Znalezione loty:</h5>';
            response.data.forEach(function(lot) {
                html += '<div class="srl-lot-result" data-termin-id="' + terminId + '" data-lot-id="' + lot.lot_id + '" data-klient-nazwa="' + lot.klient_nazwa + '" style="border:1px solid #ddd; padding:15px; margin:5px 0; cursor:pointer; border-radius:4px;">';
                html += '<div style="font-weight:bold; color:#0073aa;">Lot #' + lot.lot_id + ' - Zamówienie #' + lot.order_id + '</div>';
                html += '<div style="margin:5px 0;"><strong>' + lot.klient_nazwa + '</strong> (' + lot.email + ')</div>';
                if (lot.telefon) {
                    html += '<div style="font-size:12px; color:#666;">📞 ' + lot.telefon + '</div>';
                }
                html += '</div>';
            });
            modal.find('#srl-search-results').html(html).show();
            
            // Dodaj nasłuch dla kliknięć w wyniki - bezpośrednio na elemencie modalu
            modal.find('#srl-search-results').off('click', '.srl-lot-result').on('click', '.srl-lot-result', function() {
                var terminIdLocal = $(this).data('termin-id');
                var lotId = $(this).data('lot-id');
                var klientNazwa = $(this).data('klient-nazwa');
                przypisWykupionyLot(terminIdLocal, lotId, klientNazwa, modal);
            });
        } else {
            modal.find('#srl-search-results').html('<p style="color:#666; font-style:italic;">Brak wyników dla podanej frazy.</p>').show();
        }
    }).fail(function() {
        alert('Błąd wyszukiwania. Spróbuj ponownie.');
    });
}

// 1. USUŃ te fragmenty z admin-day.js:

// Usuń wywołanie setInterval na końcu pliku:
// setInterval(odswiezDaneWTle, 10000);

// Usuń całą funkcję odswiezDaneWTle

// Usuń to wywołanie z funkcji zmienStatusSlotu:
// setTimeout(odswiezDaneWTle, 2000);

// 2. POPRAWIONA funkcja przypisWykupionyLot - z natychmiastowym odświeżaniem
function przypisWykupionyLot(terminId, lotId, klientNazwa, modal) {
    if (!confirm('Czy na pewno przypisać lot #' + lotId + ' (' + klientNazwa + ') do tego slotu?')) return;
    
    var button = modal.find('.srl-lot-result[data-lot-id="' + lotId + '"]');
    if (button.length) {
        button.css('opacity', '0.5').find('*').prop('disabled', true);
        button.prepend('<span style="color: #0073aa; font-weight: bold;">⏳ Przypisywanie...</span><br>');
    }
    
    $.post(ajaxurl, {
        action: 'srl_przypisz_wykupiony_lot',
        termin_id: terminId,
        lot_id: lotId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            // NATYCHMIASTOWE ODŚWIEŻENIE - pobierz nowe dane z serwera
            $.ajax({
                url: ajaxurl,
                method: 'GET',
                data: {
                    action: 'srl_pobierz_aktualne_godziny',
                    data: srlData,
                    nonce: srlAdmin.nonce
                },
                success: function(refreshResponse) {
                    if (refreshResponse.success) {
                        // Aktualizuj dane lokalnie
                        srlIstniejaceGodziny = refreshResponse.data.godziny_wg_pilota;
                        
                        // Zamknij modal
                        modal.remove();
                        
                        // Odśwież interfejs
                        generujTabelePilotow();
                        
                        // Pokaż komunikat sukcesu
                        pokazKomunikatSukcesu('Wykupiony lot został przypisany do slotu!');
                    } else {
                        modal.remove();
                        generujTabelePilotow(); // Fallback
                        pokazKomunikatSukcesu('Lot został przypisany!');
                    }
                },
                error: function() {
                    modal.remove();
                    generujTabelePilotow(); // Fallback  
                    pokazKomunikatSukcesu('Lot został przypisany!');
                }
            });
        } else {
            if (button.length) {
                button.css('opacity', '1').find('*').prop('disabled', false);
                button.find('span:first').remove();
            }
            alert('Błąd: ' + response.data);
        }
    }).fail(function() {
        if (button.length) {
            button.css('opacity', '1').find('*').prop('disabled', false);
            button.find('span:first').remove();
        }
        alert('Błąd połączenia z serwerem.');
    });
}

// 3. POPRAWIONA funkcja zapiszLotPrywatny - z natychmiastowym odświeżaniem
function zapiszLotPrywatny(terminId, formData, modal) {
    // Przekonwertuj formData na obiekt
    var formDataObj = {};
    formData.split('&').forEach(function(pair) {
        var keyValue = pair.split('=');
        if (keyValue.length === 2) {
            formDataObj[decodeURIComponent(keyValue[0])] = decodeURIComponent(keyValue[1]);
        }
    });
    
    var requestData = {
        action: 'srl_zapisz_lot_prywatny',
        termin_id: terminId,
        nonce: srlAdmin.nonce,
        imie: formDataObj.imie || '',
        nazwisko: formDataObj.nazwisko || '',
        rok_urodzenia: formDataObj.rok_urodzenia || '',
        telefon: formDataObj.telefon || '',
        sprawnosc_fizyczna: formDataObj.sprawnosc_fizyczna || '',
        kategoria_wagowa: formDataObj.kategoria_wagowa || '',
        uwagi: formDataObj.uwagi || ''
    };
    
    // Wyłącz przycisk submit
    var submitBtn = modal.find('#srl-form-prywatny button[type="submit"]');
    submitBtn.prop('disabled', true).text('Zapisywanie...');
    
    $.post(ajaxurl, requestData, function(response) {
        if (response.success) {
            // NATYCHMIASTOWE ODŚWIEŻENIE - pobierz nowe dane z serwera
            $.ajax({
                url: ajaxurl,
                method: 'GET',
                data: {
                    action: 'srl_pobierz_aktualne_godziny',
                    data: srlData,
                    nonce: srlAdmin.nonce
                },
                success: function(refreshResponse) {
                    if (refreshResponse.success) {
                        // Aktualizuj dane lokalnie
                        srlIstniejaceGodziny = refreshResponse.data.godziny_wg_pilota;
                        
                        // Zamknij modal
                        modal.remove();
                        
                        // Odśwież interfejs
                        generujTabelePilotow();
                        
                        // Pokaż komunikat sukcesu
                        pokazKomunikatSukcesu('Lot prywatny został zapisany!');
                    } else {
                        modal.remove();
                        generujTabelePilotow(); // Fallback
                        pokazKomunikatSukcesu('Lot prywatny został zapisany!');
                    }
                },
                error: function() {
                    modal.remove();
                    generujTabelePilotow(); // Fallback
                    pokazKomunikatSukcesu('Lot prywatny został zapisany!');
                }
            });
        } else {
            submitBtn.prop('disabled', false).text('Zapisz lot prywatny');
            alert('Błąd: ' + response.data);
        }
    }).fail(function(xhr, status, error) {
        submitBtn.prop('disabled', false).text('Zapisz lot prywatny');
        alert('Błąd połączenia z serwerem: ' + error);
    });
}

// 4. POPRAWIONA funkcja zapiszEdytowaneDanePrywatne - z natychmiastowym odświeżaniem
function zapiszEdytowaneDanePrywatne(terminId, formData, modal) {
    // Przekonwertuj formData na obiekt
    var formDataObj = {};
    formData.split('&').forEach(function(pair) {
        var keyValue = pair.split('=');
        if (keyValue.length === 2) {
            formDataObj[decodeURIComponent(keyValue[0])] = decodeURIComponent(keyValue[1]);
        }
    });
    
    var requestData = {
        action: 'srl_zapisz_dane_prywatne',
        termin_id: terminId,
        nonce: srlAdmin.nonce,
        imie: formDataObj.imie || '',
        nazwisko: formDataObj.nazwisko || '',
        rok_urodzenia: formDataObj.rok_urodzenia || '',
        telefon: formDataObj.telefon || '',
        sprawnosc_fizyczna: formDataObj.sprawnosc_fizyczna || '',
        kategoria_wagowa: formDataObj.kategoria_wagowa || '',
        uwagi: formDataObj.uwagi || ''
    };
    
    // Wyłącz przycisk zapisz
    var saveBtn = modal.find('#srl-btn-zapisz');
    saveBtn.prop('disabled', true).text('Zapisywanie...');
    
    $.post(ajaxurl, requestData, function(response) {
        if (response.success) {
            // NATYCHMIASTOWE ODŚWIEŻENIE - pobierz nowe dane z serwera  
            $.ajax({
                url: ajaxurl,
                method: 'GET',
                data: {
                    action: 'srl_pobierz_aktualne_godziny',
                    data: srlData,
                    nonce: srlAdmin.nonce
                },
                success: function(refreshResponse) {
                    if (refreshResponse.success) {
                        // Aktualizuj dane lokalnie
                        srlIstniejaceGodziny = refreshResponse.data.godziny_wg_pilota;
                        
                        // Zamknij modal
                        modal.remove();
                        
                        // Odśwież interfejs
                        generujTabelePilotow();
                        
                        // Pokaż komunikat sukcesu
                        pokazKomunikatSukcesu('Dane zostały zaktualizowane!');
                    } else {
                        modal.remove();
                        generujTabelePilotow(); // Fallback
                        pokazKomunikatSukcesu('Dane zostały zaktualizowane!');
                    }
                },
                error: function() {
                    modal.remove();
                    generujTabelePilotow(); // Fallback
                    pokazKomunikatSukcesu('Dane zostały zaktualizowane!');
                }
            });
        } else {
            saveBtn.prop('disabled', false).text('Zapisz zmiany');
            alert('Błąd: ' + response.data);
        }
    }).fail(function(xhr, status, error) {
        saveBtn.prop('disabled', false).text('Zapisz zmiany');
        alert('Błąd połączenia z serwerem: ' + error);
    });
}

// Komunikat sukcesu
function pokazKomunikatSukcesu(tekst) {
    var successMsg = $('<div style="position:fixed; top:20px; right:20px; background:#46b450; color:white; padding:15px 20px; border-radius:4px; z-index:9999; font-weight:bold;">✅ ' + tekst + '</div>');
    $('body').append(successMsg);
    setTimeout(function() {
        successMsg.fadeOut(function() {
            successMsg.remove();
        });
    }, 4000);
}

















// końcówka
	
});

