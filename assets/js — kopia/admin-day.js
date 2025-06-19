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

function srlObliczWiek(rokUrodzenia) {
    if (!rokUrodzenia || rokUrodzenia < 1920) return '';
    return new Date().getFullYear() - parseInt(rokUrodzenia);
}

function srlFormatujWiek(rokUrodzenia) {
    var wiek = srlObliczWiek(rokUrodzenia);
    return wiek ? wiek + ' lat' : '';
}

jQuery(document).ready(function($) {

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

	if (checkboxPlanowanie.is(':checked') && Object.keys(srlIstniejaceGodziny).length > 0) {
		generujTabelePilotow();
	}

	checkboxPlanowanie.on('change', function() {
		if ($(this).is(':checked')) {
			$('#srl-ustawienia-godzin').slideDown();
			generujTabelePilotow();
		} else {

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

	$('#srl-wybierz-date').on('change', function() {
		var nowaData = $(this).val();
		window.location.href = addOrUpdateUrlParam('data', nowaData);
	});

function srl_formatujDanePasazera(slot, dlugiFormat) {
    var wynik = {
        tekstSlotu: '',
        maInformacje: false,
        daneDoModalu: null
    };

    var linie = [];

    if ((slot.status === 'Zarezerwowany' || slot.status === 'Zrealizowany') && slot.klient_nazwa) {
        var pelneInfo = slot.dane_pasazera_cache || null;
        var daneOdwolane = null;

        if (slot.notatka) {
            try {
                var parsedNotatka = JSON.parse(slot.notatka);
                if (parsedNotatka.typ === 'odwolany_przez_organizatora') {
                    daneOdwolane = parsedNotatka;
                }
            } catch (e) {}
        }

        if (pelneInfo || daneOdwolane) {
            var dane = pelneInfo || daneOdwolane;

            var statusTekst = slot.status === 'Zarezerwowany' ? '(ZAREZERWOWANY)' : 
                             slot.status === 'Zrealizowany' ? '(ZREALIZOWANY)' : '';
            if (statusTekst) {
                linie.push(statusTekst);
            }

            var nazwaProduktu = daneOdwolane ? daneOdwolane.nazwa_produktu : 'Lot w tandemie';
            var opcjeTekst = srl_formatujOpcjeLotu(slot, daneOdwolane);
            linie.push('#' + slot.lot_id + ' | ' + nazwaProduktu + ' ' + opcjeTekst);

            var daneOsobowe = (dane.klient_nazwa || slot.klient_nazwa || '');
			if (dane.rok_urodzenia) {
				var wiek = srlObliczWiek(dane.rok_urodzenia);
				daneOsobowe += ' (' + wiek + ' lat)';
			}
            if (dane.kategoria_wagowa) {
                daneOsobowe += ', ' + dane.kategoria_wagowa;
            }
            if (dane.sprawnosc_fizyczna) {
                var sprawnoscMap = {
                    'zdolnosc_do_marszu': 'Marsz',
                    'zdolnosc_do_biegu': 'Bieg', 
                    'sprinter': 'Sprinter'
                };
                daneOsobowe += ', ' + (sprawnoscMap[dane.sprawnosc_fizyczna] || dane.sprawnosc_fizyczna);
            }
            if (daneOsobowe.trim()) {
                linie.push(daneOsobowe);
            }

            if (dane.telefon) {
                linie.push('Tel: ' + dane.telefon);
            }

            if (dane.uwagi && dane.uwagi.trim()) {
                var uwagi = dane.uwagi.trim();
                if (!dlugiFormat && uwagi.length > 50) {
                    uwagi = uwagi.substring(0, 47) + '...';
                }
                linie.push('Uwagi: ' + uwagi);
            }

            wynik.daneDoModalu = dane;
        } else {

            linie.push(slot.klient_nazwa);
            if (slot.lot_id) {
                linie.push('(ID: #' + slot.lot_id + ')');
                if (dlugiFormat) {
                    linie.push('[Kliknij INFO w tabeli]');
                }
            }
        }
        wynik.maInformacje = true;
    }

    else if (slot.status === 'Prywatny') {
        if (slot.klient_nazwa) {
            linie.push('(PRYWATNY)');
            linie.push(slot.klient_nazwa);
            wynik.maInformacje = true;
        } else if (slot.notatka) {
            try {
                var danePrivate = JSON.parse(slot.notatka);
                if (danePrivate.imie && danePrivate.nazwisko) {

                    linie.push('(PRYWATNY)');

                    linie.push('Lot prywatny');

                    var daneOsobowe = danePrivate.imie + ' ' + danePrivate.nazwisko;
                    if (danePrivate.rok_urodzenia) {
                        var wiek = new Date().getFullYear() - parseInt(danePrivate.rok_urodzenia);
                        daneOsobowe += ' (' + wiek + ' lat)';
                    }
                    if (danePrivate.kategoria_wagowa) {
                        daneOsobowe += ', ' + danePrivate.kategoria_wagowa;
                    }
                    if (danePrivate.sprawnosc_fizyczna) {
                        var sprawnoscMap = {
                            'zdolnosc_do_marszu': 'Marsz',
                            'zdolnosc_do_biegu': 'Bieg', 
                            'sprinter': 'Sprinter'
                        };
                        daneOsobowe += ', ' + (sprawnoscMap[danePrivate.sprawnosc_fizyczna] || danePrivate.sprawnosc_fizyczna);
                    }
                    linie.push(daneOsobowe);

                    if (danePrivate.telefon) {
                        linie.push('Tel: ' + danePrivate.telefon);
                    }

                    if (danePrivate.uwagi && danePrivate.uwagi.trim()) {
                        var uwagi = danePrivate.uwagi.trim();
                        if (!dlugiFormat && uwagi.length > 50) {
                            uwagi = uwagi.substring(0, 47) + '...';
                        }
                        linie.push('Uwagi: ' + uwagi);
                    }

                    wynik.daneDoModalu = danePrivate;
                    wynik.maInformacje = true;
                }
            } catch (e) {

            }
        }
    }

    else if (slot.status === 'Odwołany przez organizatora' && slot.notatka) {
        try {
            var daneOdwolane = JSON.parse(slot.notatka);
            if (daneOdwolane.klient_nazwa || (daneOdwolane.imie && daneOdwolane.nazwisko)) {

                linie.push('(ODWOŁANY)');

                var nazwaProduktu = 'Lot w tandemie';
				var opcjeTekst = srl_formatujOpcjeLotu(null, daneOdwolane);
                linie.push('#' + slot.lot_id + ' | ' + nazwaProduktu + ' ' + opcjeTekst);

                var nazwaKlienta = daneOdwolane.klient_nazwa || (daneOdwolane.imie + ' ' + daneOdwolane.nazwisko);
                var daneOsobowe = nazwaKlienta;
                if (daneOdwolane.rok_urodzenia) {
                    var wiek = new Date().getFullYear() - parseInt(daneOdwolane.rok_urodzenia);
                    daneOsobowe += ' (' + wiek + ' lat)';
                }
                if (daneOdwolane.kategoria_wagowa) {
                    daneOsobowe += ', ' + daneOdwolane.kategoria_wagowa;
                }
                if (daneOdwolane.sprawnosc_fizyczna) {
                    var sprawnoscMap = {
                        'zdolnosc_do_marszu': 'Marsz',
                        'zdolnosc_do_biegu': 'Bieg', 
                        'sprinter': 'Sprinter'
                    };
                    daneOsobowe += ', ' + (sprawnoscMap[daneOdwolane.sprawnosc_fizyczna] || daneOdwolane.sprawnosc_fizyczna);
                }
                linie.push(daneOsobowe);

                if (daneOdwolane.telefon) {
                    linie.push('Tel: ' + daneOdwolane.telefon);
                }

                if (daneOdwolane.uwagi && daneOdwolane.uwagi.trim()) {
                    var uwagi = daneOdwolane.uwagi.trim();
                    if (!dlugiFormat && uwagi.length > 50) {
                        uwagi = uwagi.substring(0, 47) + '...';
                    }
                    linie.push('Uwagi: ' + uwagi);
                }

                wynik.daneDoModalu = {
                    imie: daneOdwolane.imie || (daneOdwolane.klient_nazwa ? daneOdwolane.klient_nazwa.split(' ')[0] : ''),
                    nazwisko: daneOdwolane.nazwisko || (daneOdwolane.klient_nazwa ? daneOdwolane.klient_nazwa.split(' ').slice(1).join(' ') : ''),
                    rok_urodzenia: daneOdwolane.rok_urodzenia || '',
                    telefon: daneOdwolane.telefon || '',
                    kategoria_wagowa: daneOdwolane.kategoria_wagowa || '',
                    sprawnosc_fizyczna: daneOdwolane.sprawnosc_fizyczna || '',
                    uwagi: daneOdwolane.uwagi || ''
                };

                wynik.maInformacje = true;
            }
        } catch (e) {
            linie.push('(ODWOŁANY)');
            linie.push('🚫 LOT ODWOŁANY');
        }
    }

    wynik.tekstSlotu = linie.join('\n');

    return wynik;
}

function srl_formatujOpcjeLotu(slot, daneOdwolane) {
    var opcje = [];

    var maFilmowanie = false;
    var maAkrobacje = false;

    if (daneOdwolane) {

        if (daneOdwolane.dane_pasazera) {
            try {
                var danePassengerJson = JSON.parse(daneOdwolane.dane_pasazera);
                maFilmowanie = danePassengerJson.ma_filmowanie || false;
                maAkrobacje = danePassengerJson.ma_akrobacje || false;
            } catch (e) {}
        }

        if (!maFilmowanie && !maAkrobacje) {
            maFilmowanie = daneOdwolane.ma_filmowanie || false;
            maAkrobacje = daneOdwolane.ma_akrobacje || false;
        }
    } else if (slot && slot.dane_pasazera_cache) {

        maFilmowanie = slot.dane_pasazera_cache.ma_filmowanie || false;
        maAkrobacje = slot.dane_pasazera_cache.ma_akrobacje || false;
    }

    if (maFilmowanie) {
        opcje.push('<span style="color: #46b450; font-weight: bold;">z filmowaniem</span>');
    } else {
        opcje.push('<span style="color: #d63638;">bez filmowania</span>');
    }

    if (maAkrobacje) {
        opcje.push('<span style="color: #46b450; font-weight: bold;">z akrobacjami</span>');
    } else {
        opcje.push('<span style="color: #d63638;">bez akrobacji</span>');
    }

    return opcje.join(', ');
}

	function addOrUpdateUrlParam(key, value) {
		var url = new URL(window.location.href);
		url.searchParams.set(key, value);
		return url.toString();
	}

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

    generujSlotyButton.on('click', function(e) {
        e.preventDefault();

        if (generowanieWToku) {
            return;
        }

        var pilotId  = parseInt(generujPilotSelect.val());
        var godzOd   = generujOdInput.val();
        var godzDo   = generujDoInput.val();
        var interwal = parseInt(interwalSelect.val());

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
				status: 'Wolny',
				nonce: srlAdmin.nonce
			},
            success: function(response) {
                if (response.success) {
                    srlIstniejaceGodziny = response.data.godziny_wg_pilota;

                    generujTabelePilotow();

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

    function generujTabelePilotow() {
        kontenerTabele.empty();
        var liczbaPilotow = parseInt(liczbaPilotowSelect.val());

        for (var pid = 1; pid <= liczbaPilotow; pid++) {
            var divaPilota = $('<div class="srl-pilot-container" style="margin-bottom:30px; border:1px solid #ddd; padding:15px; border-radius:8px;"></div>');
            divaPilota.append('<h2 style="background:#0073aa; color:white; margin:0 -15px 15px -15px; padding:12px 15px; font-size:16px;">Pilot nr ' + pid + '</h2>');

            var grupoweFunkcje = $('<div class="srl-grupowe-funkcje" style="background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:12px; margin-bottom:15px; display:flex; align-items:center; gap:15px; flex-wrap:wrap;"></div>');
            grupoweFunkcje.append('<label style="font-weight:500; margin:0; display:flex; align-items:center; gap:5px;"><input type="checkbox" class="srl-zaznacz-wszystkie" data-pilot="' + pid + '"> Zaznacz wszystkie</label>');
            grupoweFunkcje.append('<select class="srl-grupowa-zmiana-statusu" data-pilot="' + pid + '" style="min-width:150px; padding:4px 8px; border:1px solid #ccd0d4; border-radius:3px;"><option value="">-- Zmień status --</option><option value="Wolny">Wolny</option><option value="Prywatny">Prywatny</option><option value="Zrealizowany">Zrealizowany</option><option value="Odwołany przez organizatora">Odwołany przez organizatora</option></select>');
            grupoweFunkcje.append('<button class="button srl-grupowe-usun" data-pilot="' + pid + '" style="background:#dc3545; color:white; border:none; padding:6px 12px; border-radius:3px; cursor:pointer; font-size:13px;">Usuń zaznaczone</button>');
            divaPilota.append(grupoweFunkcje);

            var tabela = $(
                '<table class="widefat" data-pilot-id="' + pid + '">' +
                    '<thead>' +
                        '<tr>' +
                            '<th style="width:30px;"><input type="checkbox" class="srl-zaznacz-wszystkie-naglowek" data-pilot="' + pid + '"></th>' +
                            '<th style="width:30px;">Nr</th>' +
                            '<th>Czas lotu</th>' +
                            '<th>Status slotu</th>' +
							'<th>ID lotu</th>' +
							'<th>Dane pasażera</th>' +
							'<th>Akcje</th>' +
                        '</tr>' +
                    '</thead>' +
                    '<tbody></tbody>' +
                '</table>'
            );

            var listaGodzin = srlIstniejaceGodziny[pid] || [];
            for (var i = 0; i < listaGodzin.length; i++) {
                dodajWierszDoTabeli(pid, i + 1, listaGodzin[i], tabela);
            }

            divaPilota.append(tabela);
            kontenerTabele.append(divaPilota);
        }

        zaladujNasluchiwace();

        generujHarmonogramCzasowy();
    }

function dodajWierszDoTabeli(pilotId, numer, obiektGodziny, referencjaTabela) {
    var tr = $('<tr data-termin-id="' + obiektGodziny.id + '"></tr>');
	tr.append('<td><input type="checkbox" class="srl-slot-checkbox" data-pilot="' + pilotId + '" data-termin-id="' + obiektGodziny.id + '"></td>');
	tr.append('<td>' + numer + '</td>');

	var startMin = zamienNaMinuty(obiektGodziny.start);
	var endMin   = zamienNaMinuty(obiektGodziny.koniec);
	var delta    = endMin - startMin;
	var czasTxt  = obiektGodziny.start + ' - ' + obiektGodziny.koniec + ' (' + delta + 'min)';

	var czasTd = $('<td class="srl-czas-col"></td>');
	czasTd.html(czasTxt + ' <button class="button button-small srl-edytuj-button" style="margin-left:10px; font-size:11px;">Edytuj godziny</button>' + 
				 ' <button class="button button-secondary button-small srl-usun-button" style="margin-left:5px; font-size:11px;">Usuń</button>');
	tr.append(czasTd);

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

	var lotIdTd = $('<td></td>');
	if ((obiektGodziny.status === 'Zarezerwowany' || obiektGodziny.status === 'Zrealizowany') && obiektGodziny.lot_id) {
		lotIdTd.html('<a href="' + srlAdmin.adminUrl + 'admin.php?page=srl-wykupione-loty&search_field=id_lotu&s=' + obiektGodziny.lot_id + '" target="_blank" style="color:#0073aa; font-weight:bold;">#' + obiektGodziny.lot_id + '</a>');
	} else if (obiektGodziny.status === 'Odwołany przez organizatora' && obiektGodziny.notatka) {
		try {
			var daneOdwolane = JSON.parse(obiektGodziny.notatka);
			if (daneOdwolane.lot_id) {
				lotIdTd.html('<a href="' + srlAdmin.adminUrl + 'admin.php?page=srl-wykupione-loty&search_field=id_lotu&s=' + daneOdwolane.lot_id + '" target="_blank" style="color:#dc3545; font-weight:bold;">#' + daneOdwolane.lot_id + ' (odwołany)</a>');
			} else {
				lotIdTd.html('—');
			}
		} catch (e) {
			lotIdTd.html('—');
		}
	} else if (obiektGodziny.status === 'Prywatny' || (obiektGodziny.status === 'Zrealizowany' && !obiektGodziny.lot_id)) {
		lotIdTd.html('<span style="color:#6c757d; font-weight:bold;">PRYWATNY</span>');
	} else {
		lotIdTd.html('—');
	}
	tr.append(lotIdTd);

var danePasazeraTd = $('<td></td>');
if (obiektGodziny.status === 'Zarezerwowany' || (obiektGodziny.status === 'Zrealizowany' && obiektGodziny.lot_id)) {
    if (obiektGodziny.lot_id && obiektGodziny.klient_nazwa) {
        danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-pasazera" data-lot-id="' + obiektGodziny.lot_id + '" data-user-id="' + obiektGodziny.klient_id + '">' + obiektGodziny.klient_nazwa + '</button>');
    } else {
        danePasazeraTd.html('<button class="button button-small srl-przypisz-klienta" data-termin-id="' + obiektGodziny.id + '">Przypisz klienta</button>');
    }
} else if (obiektGodziny.status === 'Prywatny' || (obiektGodziny.status === 'Zrealizowany' && obiektGodziny.notatka && !obiektGodziny.lot_id)) {

    if (obiektGodziny.notatka) {
        try {
            var danePrivate = JSON.parse(obiektGodziny.notatka);
            if (danePrivate.imie && danePrivate.nazwisko) {
                var buttonText = danePrivate.imie + ' ' + danePrivate.nazwisko;
                if (obiektGodziny.status === 'Zrealizowany') {
                    buttonText += ' (zrealizowany)';
                }
                danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">' + buttonText + '</button>');
            } else {
                danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">Dane prywatne</button>');
            }
        } catch (e) {
            danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">Dane prywatne</button>');
        }
    } else {
        danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-prywatne" data-termin-id="' + obiektGodziny.id + '">Dane prywatne</button>');
    }
} else if (obiektGodziny.status === 'Odwołany przez organizatora') {

    if (obiektGodziny.notatka) {
        try {
            var daneOdwolane = JSON.parse(obiektGodziny.notatka);
            if (daneOdwolane.klient_nazwa) {
                danePasazeraTd.html('<button class="button button-small srl-pokaz-dane-odwolane" data-termin-id="' + obiektGodziny.id + '" style="background:#dc3545; color:white;">' + daneOdwolane.klient_nazwa + ' (odwołany)</button>');
            } else {
                danePasazeraTd.html('<span style="color:#dc3545; font-style:italic;">Lot odwołany</span>');
            }
        } catch (e) {
            danePasazeraTd.html('<span style="color:#dc3545; font-style:italic;">Lot odwołany</span>');
        }
    } else {
        danePasazeraTd.html('<span style="color:#dc3545; font-style:italic;">Lot odwołany</span>');
    }
} else {
    danePasazeraTd.html('—');
}
tr.append(danePasazeraTd);

	var akcjeTd = $('<td></td>');

	if (obiektGodziny.status === 'Wolny') {
		akcjeTd.append('<button class="button button-primary srl-przypisz-slot" data-termin-id="' + obiektGodziny.id + '">Przypisz klienta</button>');
	} else if (obiektGodziny.status === 'Zarezerwowany' && obiektGodziny.klient_id > 0) {
		akcjeTd.append('<button class="button srl-wypisz-klienta">Wypisz klienta</button> ');
		akcjeTd.append('<button class="button srl-zmien-termin" data-termin-id="' + obiektGodziny.id + '" style="background:#ff9800; color:white; margin-left:5px;">Zmiana terminu</button> ');
		akcjeTd.append('<button class="button srl-zrealizuj-lot" data-termin-id="' + obiektGodziny.id + '" style="background:#28a745; color:white; margin-left:5px;">Zrealizuj</button> ');
		akcjeTd.append('<button class="button srl-odwolaj-lot" data-termin-id="' + obiektGodziny.id + '" style="background:#dc3545; color:white; margin-left:5px;">Odwołaj</button>');
	} else if (obiektGodziny.status === 'Prywatny') {
		akcjeTd.append('<button class="button srl-zrealizuj-lot-prywatny" data-termin-id="' + obiektGodziny.id + '" style="background:#28a745; color:white; margin-right:5px;">Zrealizuj</button> ');
		akcjeTd.append('<button class="button srl-wypisz-slot-prywatny" data-termin-id="' + obiektGodziny.id + '">Wyczyść slot</button>');
	} else if (obiektGodziny.status === 'Zrealizowany') {
		akcjeTd.append('<span style="color:#28a745; font-weight:bold;">✅ Zrealizowany</span>');
	} else if (obiektGodziny.status === 'Odwołany przez organizatora') {
		akcjeTd.append('<button class="button srl-przywroc-rezerwacje" data-termin-id="' + obiektGodziny.id + '" style="background:#28a745; color:white;">Przywróć rezerwację</button>');
	} else {
		akcjeTd.html('—');
	}
	tr.append(akcjeTd);

    referencjaTabela.find('tbody').append(tr);
    sprawdzNakladanie(referencjaTabela, pilotId);
}

function zaladujNasluchiwace() {
    console.log('Ładowanie nasłuchów...'); 

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

        var zmienionych = 0;
        var doZmiany = zaznaczone.length;

        zaznaczone.each(function() {
            var terminId = $(this).data('termin-id');
            zmienStatusSlotu(terminId, nowyStatus, 0, '', null, function() {
                zmienionych++;
                if (zmienionych === doZmiany) {

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
				termin_id: terminId,
				nonce: srlAdmin.nonce
			}, function(response) {
				usuniete++;
				if (response.success) {
					srlIstniejaceGodziny = response.data.godziny_wg_pilota;
				} else {
					console.error('Błąd usuwania slotu:', response.data);
				}

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

$(document).off('click', '.srl-usun-button').on('click', '.srl-usun-button', function() {
    var wiersz    = $(this).closest('tr');
    var terminId  = wiersz.data('termin-id');
    var button    = $(this);

    if (!confirm('Czy na pewno usunąć ten slot?')) return;

    button.prop('disabled', true).text('Usuwanie...');

    $.post(ajaxurl, {
        action: 'srl_usun_godzine',
        termin_id: terminId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            srlIstniejaceGodziny = response.data.godziny_wg_pilota;

            wiersz.fadeOut(300, function() {
                wiersz.remove();

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

$(document).off('click', '.srl-edytuj-button').on('click', '.srl-edytuj-button', function() {
    var btn = $(this);
    var wiersz = btn.closest('tr');
    var terminId = wiersz.data('termin-id');

    var aktualnyStatus = 'Wolny'; 
    var statusSpan = wiersz.find('td:nth-child(4) span');
    if (statusSpan.length > 0) {
        var statusText = statusSpan.text().trim();
        if (statusText.includes('Wolny')) aktualnyStatus = 'Wolny';
        else if (statusText.includes('Prywatny')) aktualnyStatus = 'Prywatny';
        else if (statusText.includes('Zarezerwowany')) aktualnyStatus = 'Zarezerwowany';
        else if (statusText.includes('Zrealizowany')) aktualnyStatus = 'Zrealizowany';
    }

    var currText = wiersz.find('.srl-czas-col').text();
    var czasParts = currText.match(/(\d{1,2}:\d{2}) - (\d{1,2}:\d{2})/);
    var currStart = czasParts ? czasParts[1] : '09:00';
    var currStop = czasParts ? czasParts[2] : '09:30';

    wiersz.find('.srl-czas-col').data('original-text', currText);
    wiersz.find('.srl-czas-col').data('current-status', aktualnyStatus);

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

            generujTabelePilotow();

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

$(document).off('click', '.srl-anuluj-edycje-godzin').on('click', '.srl-anuluj-edycje-godzin', function() {
    var btn = $(this);
    var wiersz = btn.closest('tr');
    var originalText = wiersz.find('.srl-czas-col').data('original-text');

    var startMin = zamienNaMinuty(originalText.match(/(\d{1,2}:\d{2})/)[1]);
    var endMin = zamienNaMinuty(originalText.match(/- (\d{1,2}:\d{2})/)[1]);
    var delta = endMin - startMin;
    var czasTxt = originalText.match(/(\d{1,2}:\d{2} - \d{1,2}:\d{2})/)[1] + ' (' + delta + 'min)';

    wiersz.find('.srl-czas-col').html(czasTxt + ' <button class="button button-small srl-edytuj-button" style="margin-left:10px; font-size:11px;">Edytuj godziny</button>');
});

    $(document).off('click', '.srl-wypisz-slot-prywatny').on('click', '.srl-wypisz-slot-prywatny', function() {
        var terminId = $(this).data('termin-id');

        if (!confirm('Czy na pewno wyczyścić ten slot prywatny i zmienić status na wolny?')) return;

		$.post(ajaxurl, {
			action: 'srl_zmien_status_godziny',
			termin_id: terminId,
			status: 'Wolny',
			klient_id: 0,
			notatka: '', 
			nonce: srlAdmin.nonce  
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

    $(document).off('click', '.srl-wypisz-klienta').on('click', '.srl-wypisz-klienta', function() {
        var wiersz   = $(this).closest('tr');
        var terminId = wiersz.data('termin-id');

        if (!confirm('Czy na pewno wypisać klienta i przywrócić slot jako wolny?')) return;

        zmienStatusSlotu(terminId, 'Wolny', 0, '', null, function() {
            generujTabelePilotow();
        });
    });

    $(document).off('click', '.srl-pokaz-dane-pasazera').on('click', '.srl-pokaz-dane-pasazera', function() {
        var lotId = $(this).data('lot-id');
        var userId = $(this).data('user-id');
        pokazDanePasazeraModal(lotId, userId);
    });

    $(document).off('click', '.srl-przypisz-klienta').on('click', '.srl-przypisz-klienta', function() {
        var terminId = $(this).data('termin-id');
        pokazFormularzPrzypisaniaKlienta(terminId);
    });

    $(document).off('click', '.srl-pokaz-dane-prywatne').on('click', '.srl-pokaz-dane-prywatne', function() {
        var terminId = $(this).data('termin-id');
        pokazDanePrywatneModal(terminId);
    });

    $(document).off('click', '.srl-przypisz-slot').on('click', '.srl-przypisz-slot', function() {
        console.log('Kliknięto przycisk przypisz slot'); 
        var terminId = $(this).data('termin-id');
        console.log('terminId:', terminId); 
        pokazModalPrzypisaniaSlotu(terminId);
    });

$(document).off('click', '.srl-odwolaj-lot').on('click', '.srl-odwolaj-lot', function() {
    var terminId = $(this).data('termin-id');

    if (!confirm('Czy na pewno odwołać ten lot? Slot zostanie zachowany jako historia, a lot klienta będzie dostępny do ponownej rezerwacji.')) return;

    var button = $(this);
    button.prop('disabled', true).text('Odwoływanie...');

    $.post(ajaxurl, {
        action: 'srl_anuluj_lot_przez_organizatora',
        termin_id: terminId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            srlIstniejaceGodziny = response.data.godziny_wg_pilota;
            generujTabelePilotow();
            pokazKomunikatSukcesu('Lot został odwołany. Klient otrzymał powiadomienie, a lot jest dostępny do ponownej rezerwacji.');
        } else {
            alert('Błąd: ' + response.data);
            button.prop('disabled', false).text('Odwołaj');
        }
    }).fail(function() {
        alert('Błąd połączenia z serwerem');
        button.prop('disabled', false).text('Odwołaj');
    });
});

$(document).off('click', '.srl-przywroc-rezerwacje').on('click', '.srl-przywroc-rezerwacje', function() {
    var terminId = $(this).data('termin-id');

    if (!confirm('Czy na pewno przywrócić rezerwację? Klient zostanie ponownie przypisany do tego terminu.')) return;

    var button = $(this);
    button.prop('disabled', true).text('Przywracanie...');

    $.post(ajaxurl, {
        action: 'srl_przywroc_rezerwacje',
        termin_id: terminId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            srlIstniejaceGodziny = response.data.godziny_wg_pilota;
            generujTabelePilotow();
            pokazKomunikatSukcesu('Rezerwacja została przywrócona. Klient otrzymał powiadomienie.');
        } else {
            alert('Błąd: ' + response.data);
            button.prop('disabled', false).text('Przywróć rezerwację');
        }
    }).fail(function() {
        alert('Błąd połączenia z serwerem');
        button.prop('disabled', false).text('Przywróć rezerwację');
    });
});

$(document).off('click', '.srl-pokaz-dane-odwolane').on('click', '.srl-pokaz-dane-odwolane', function() {
    var terminId = $(this).data('termin-id');
    pokazDaneOdwolanegoLotu(terminId);
});

$(document).off('click', '.srl-historia-lot').on('click', '.srl-historia-lot', function() {
    var lotId = $(this).data('lot-id');
    pokazHistorieLotu(lotId);
});

$(document).off('click', '.srl-zrealizuj-lot').on('click', '.srl-zrealizuj-lot', function() {
    var terminId = $(this).data('termin-id');

    if (!confirm('Czy na pewno oznaczyć ten lot jako zrealizowany? Zachowane zostaną wszystkie dane pasażera i lotu.')) return;

    var button = $(this);
    button.prop('disabled', true).text('Realizowanie...');

    $.post(ajaxurl, {
        action: 'srl_zrealizuj_lot',
        termin_id: terminId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            srlIstniejaceGodziny = response.data.godziny_wg_pilota;
            generujTabelePilotow();
            pokazKomunikatSukcesu('Lot został oznaczony jako zrealizowany!');
        } else {
            alert('Błąd: ' + response.data);
            button.prop('disabled', false).text('Zrealizuj');
        }
    }).fail(function() {
        alert('Błąd połączenia z serwerem');
        button.prop('disabled', false).text('Zrealizuj');
    });
});

$(document).off('click', '.srl-zrealizuj-lot-prywatny').on('click', '.srl-zrealizuj-lot-prywatny', function() {
    var terminId = $(this).data('termin-id');

    if (!confirm('Czy na pewno oznaczyć ten lot prywatny jako zrealizowany? Zachowane zostaną wszystkie dane pasażera.')) return;

    var button = $(this);
    button.prop('disabled', true).text('Realizowanie...');

    $.post(ajaxurl, {
        action: 'srl_zrealizuj_lot_prywatny',
        termin_id: terminId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            srlIstniejaceGodziny = response.data.godziny_wg_pilota;
            generujTabelePilotow();
            pokazKomunikatSukcesu('Lot prywatny został oznaczony jako zrealizowany!');
        } else {
            alert('Błąd: ' + response.data);
            button.prop('disabled', false).text('Zrealizuj');
        }
    }).fail(function() {
        alert('Błąd połączenia z serwerem');
        button.prop('disabled', false).text('Zrealizuj');
    });
});

}

    function zmienStatusSlotu(terminId, status, klientId, notatka, selectElement, callback, grupowa) {
		$.post(ajaxurl, {
			action: 'srl_zmien_status_godziny',
			termin_id: terminId,
			status: status,
			klient_id: klientId || 0,
			notatka: notatka || '',
			nonce: srlAdmin.nonce
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
			termin_id: terminId,
			nonce: srlAdmin.nonce
		}, function(response) {
            if (response.success) {
                srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                generujTabelePilotow();

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

        $(document).on('click', function(e) {
            if (!$(e.target).closest('.srl-wyszukaj-klienta, .srl-wyniki-klientow').length) {
                $('.srl-wyniki-klientow').remove();
            }
        });

    }

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

        interwaly.forEach(function(el) {
            el.wiersz.css('background-color', '');
        });

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

    function aktualizujDropdownPilotow() {
        var nowaLiczba = parseInt(liczbaPilotowSelect.val());
        generujPilotSelect.empty();
        for (var i = 1; i <= nowaLiczba; i++) {
            generujPilotSelect.append('<option value="' + i + '">Pilot ' + i + '</option>');
        }
    }

    function zamienNaMinuty(czas) {
        var p = czas.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }

    function pad2(n) {
        return (n < 10) ? '0' + n : '' + n;
    }

function srl_formatujSlotHarmonogram(slot) {
    var linie = [];

    linie.push(slot.start + ' - ' + slot.koniec + ' (' + slot.status.toUpperCase() + ')');

	if (slot.lot_id) {

		linie.push('#' + slot.lot_id + ' - Lot w tandemie');
	} else if (slot.status === 'Odwołany przez organizatora' && slot.notatka) {

		try {
			var daneOdwolane = JSON.parse(slot.notatka);
			if (daneOdwolane.lot_id) {
				linie.push('#' + daneOdwolane.lot_id + ' - Lot odwołany');
			}
		} catch (e) {

		}
	} else if (slot.status === 'Prywatny' || (slot.status === 'Zrealizowany' && slot.notatka && !slot.lot_id)) {

		linie.push('Lot prywatny');
	}

if (slot.status === 'Prywatny' && slot.notatka) {

    try {
        var danePrivate = JSON.parse(slot.notatka);
        if (danePrivate.imie && danePrivate.nazwisko) {
            var daneOsobowe = danePrivate.imie + ' ' + danePrivate.nazwisko;

            if (danePrivate.rok_urodzenia) {
                var wiek = new Date().getFullYear() - parseInt(danePrivate.rok_urodzenia);
                daneOsobowe += ' (' + wiek + ' lat)';
            }
            if (danePrivate.kategoria_wagowa) {
                daneOsobowe += ', ' + danePrivate.kategoria_wagowa;
            }
            if (danePrivate.sprawnosc_fizyczna) {
                var sprawnosci = {
                    'zdolnosc_do_marszu': 'Marsz',
                    'zdolnosc_do_biegu': 'Bieg', 
                    'sprinter': 'Sprinter'
                };
                daneOsobowe += ', ' + (sprawnosci[danePrivate.sprawnosc_fizyczna] || danePrivate.sprawnosc_fizyczna);
            }

            linie.push(daneOsobowe);

            if (danePrivate.telefon) {
                linie.push('Tel: ' + danePrivate.telefon);
            }

            if (danePrivate.uwagi && danePrivate.uwagi.trim()) {
                var uwagi = danePrivate.uwagi.trim();
                if (uwagi.length > 40) {
                    uwagi = uwagi.substring(0, 37) + '...';
                }
                linie.push('Uwagi: ' + uwagi);
            }
        }
    } catch (e) {}
} 
else if (slot.status === 'Zrealizowany' && slot.notatka && !slot.lot_id) {

    try {
        var danePrivate = JSON.parse(slot.notatka);
        if (danePrivate.imie && danePrivate.nazwisko) {
            var daneOsobowe = danePrivate.imie + ' ' + danePrivate.nazwisko;

            if (danePrivate.rok_urodzenia) {
                var wiek = new Date().getFullYear() - parseInt(danePrivate.rok_urodzenia);
                daneOsobowe += ' (' + wiek + ' lat)';
            }
            if (danePrivate.kategoria_wagowa) {
                daneOsobowe += ', ' + danePrivate.kategoria_wagowa;
            }
            if (danePrivate.sprawnosc_fizyczna) {
                var sprawnosci = {
                    'zdolnosc_do_marszu': 'Marsz',
                    'zdolnosc_do_biegu': 'Bieg', 
                    'sprinter': 'Sprinter'
                };
                daneOsobowe += ', ' + (sprawnosci[danePrivate.sprawnosc_fizyczna] || danePrivate.sprawnosc_fizyczna);
            }

            linie.push(daneOsobowe);

            if (danePrivate.telefon) {
                linie.push('Tel: ' + danePrivate.telefon);
            }

            if (danePrivate.uwagi && danePrivate.uwagi.trim()) {
                var uwagi = danePrivate.uwagi.trim();
                if (uwagi.length > 40) {
                    uwagi = uwagi.substring(0, 37) + '...';
                }
                linie.push('Uwagi: ' + uwagi);
            }
        }
    } catch (e) {}
}
else if ((slot.status === 'Zarezerwowany' || slot.status === 'Zrealizowany') && slot.klient_nazwa) {

    var pelneInfo = slot.dane_pasazera_cache || null;

    if (pelneInfo) {
        var daneOsobowe = slot.klient_nazwa;

        if (pelneInfo.rok_urodzenia) {
            var wiek = new Date().getFullYear() - parseInt(pelneInfo.rok_urodzenia);
            daneOsobowe += ' (' + wiek + ' lat)';
        }
        if (pelneInfo.kategoria_wagowa) {
            daneOsobowe += ', ' + pelneInfo.kategoria_wagowa;
        }
        if (pelneInfo.sprawnosc_fizyczna) {
            var sprawnosci = {
                'zdolnosc_do_marszu': 'Marsz',
                'zdolnosc_do_biegu': 'Bieg', 
                'sprinter': 'Sprinter'
            };
            daneOsobowe += ', ' + (sprawnosci[pelneInfo.sprawnosc_fizyczna] || pelneInfo.sprawnosc_fizyczna);
        }

        linie.push(daneOsobowe);

        if (pelneInfo.telefon) {
            linie.push('Tel: ' + pelneInfo.telefon);
        }

        if (pelneInfo.uwagi && pelneInfo.uwagi.trim()) {
            var uwagi = pelneInfo.uwagi.trim();
            if (uwagi.length > 40) {
                uwagi = uwagi.substring(0, 37) + '...';
            }
            linie.push('Uwagi: ' + uwagi);
        }
    } else {
        linie.push(slot.klient_nazwa);
        linie.push('[Kliknij INFO w tabeli]');
    }
} else if (slot.status === 'Odwołany przez organizatora' && slot.notatka) {

    try {
        var daneOdwolane = JSON.parse(slot.notatka);
        if (daneOdwolane.klient_nazwa || (daneOdwolane.imie && daneOdwolane.nazwisko)) {
            var nazwaKlienta = daneOdwolane.klient_nazwa || (daneOdwolane.imie + ' ' + daneOdwolane.nazwisko);
            var daneOsobowe = nazwaKlienta;

            if (daneOdwolane.rok_urodzenia) {
                var wiek = new Date().getFullYear() - parseInt(daneOdwolane.rok_urodzenia);
                daneOsobowe += ' (' + wiek + ' lat)';
            }
            if (daneOdwolane.kategoria_wagowa) {
                daneOsobowe += ', ' + daneOdwolane.kategoria_wagowa;
            }
            if (daneOdwolane.sprawnosc_fizyczna) {
                var sprawnosci = {
                    'zdolnosc_do_marszu': 'Marsz',
                    'zdolnosc_do_biegu': 'Bieg', 
                    'sprinter': 'Sprinter'
                };
                daneOsobowe += ', ' + (sprawnosci[daneOdwolane.sprawnosc_fizyczna] || daneOdwolane.sprawnosc_fizyczna);
            }

            linie.push(daneOsobowe);

            if (daneOdwolane.telefon) {
                linie.push('Tel: ' + daneOdwolane.telefon);
            }

            if (daneOdwolane.uwagi && daneOdwolane.uwagi.trim()) {
                var uwagi = daneOdwolane.uwagi.trim();
                if (uwagi.length > 40) {
                    uwagi = uwagi.substring(0, 37) + '...';
                }
                linie.push('Uwagi: ' + uwagi);
            }
        }
    } catch (e) {
    }
}

    return linie.join('\n');
}

function generujHarmonogramCzasowy() {
    var kontener = $('#srl-harmonogram-container');
    kontener.empty();

    var maSloty = Object.keys(srlIstniejaceGodziny).some(function(pid) {
        return srlIstniejaceGodziny[pid] && srlIstniejaceGodziny[pid].length > 0;
    });

    if (!maSloty) {
        kontener.html('<p style="text-align:center; color:#666; font-style:italic; padding:20px;">Brak zaplanowanych lotów na ten dzień</p>');
        return;
    }

    var liczbaPilotow = parseInt(liczbaPilotowSelect.val());

    var najwczesniej = 24 * 60;
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

    najwczesniej = Math.floor(Math.max(0, najwczesniej - 30) / 60) * 60;
    najpozniej = Math.ceil(Math.min(24 * 60, najpozniej + 30) / 60) * 60;

    var czasTrwania = najpozniej - najwczesniej;
    var wysokoscRzadu = 150;
    var szerokoscKolumny = 300;
    var wysokoscCalkowita = (czasTrwania / 60) * wysokoscRzadu + 40;

    var harmonogram = $('<div class="srl-harmonogram"></div>');
    harmonogram.css('height', wysokoscCalkowita + 'px');

    var naglowek = $('<div class="srl-harmonogram-header"></div>');
    for (var pid = 1; pid <= liczbaPilotow; pid++) {
        naglowek.append('<div class="srl-harmonogram-pilot-col">Pilot ' + pid + '</div>');
    }
    harmonogram.append(naglowek);

    var osCzasu = $('<div class="srl-harmonogram-time-axis"></div>');
    osCzasu.css('height', (wysokoscCalkowita - 40) + 'px');

    for (var godzina = Math.floor(najwczesniej / 60); godzina <= Math.ceil(najpozniej / 60); godzina++) {
        var pozycjaY = ((godzina * 60 - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);

        var etykietaGodziny = $('<div class="srl-time-label-hour"></div>');
        etykietaGodziny.css('top', Math.round(pozycjaY) - 10 + 'px');
        etykietaGodziny.text(pad2(godzina) + ':00');
        osCzasu.append(etykietaGodziny);

        for (var minuta = 15; minuta < 60; minuta += 15) {
            var pozycjaYMin = (((godzina * 60 + minuta) - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);
            var etykietaMinuty = $('<div class="srl-time-label-minute"></div>');
            etykietaMinuty.css('top', Math.round(pozycjaYMin) - 8 + 'px');
            etykietaMinuty.text(pad2(godzina) + ':' + pad2(minuta));
            osCzasu.append(etykietaMinuty);
        }
    }
    harmonogram.append(osCzasu);

    var obszarSlotow = $('<div class="srl-harmonogram-slots"></div>');
    obszarSlotow.css('height', (wysokoscCalkowita - 40) + 'px');

    for (var godzina = Math.floor(najwczesniej / 60); godzina <= Math.ceil(najpozniej / 60); godzina++) {
        var pozycjaY = ((godzina * 60 - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);

        var liniaGodzinowa = $('<div class="srl-grid-line-hour"></div>');
        liniaGodzinowa.css('top', Math.round(pozycjaY) + 'px');
        obszarSlotow.append(liniaGodzinowa);

        for (var minuta = 15; minuta < 60; minuta += 15) {
            var pozycjaYMin = (((godzina * 60 + minuta) - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40);
            var liniaMinutowa = $('<div class="srl-grid-line-minute"></div>');
            liniaMinutowa.css('top', Math.round(pozycjaYMin) + 'px');
            obszarSlotow.append(liniaMinutowa);
        }
    }

    for (var pid = 1; pid <= liczbaPilotow; pid++) {
        var pozycjaX = (pid - 1) * szerokoscKolumny;
        var liniaPilota = $('<div class="srl-pilot-column-line"></div>');
        liniaPilota.css('left', pozycjaX + 'px');
        obszarSlotow.append(liniaPilota);
    }

    for (var pid = 1; pid <= liczbaPilotow; pid++) {
        var sloty = srlIstniejaceGodziny[pid] || [];
        var pozycjaXKolumny = (pid - 1) * szerokoscKolumny;

        sloty.forEach(function(slot) {
            var startMin = zamienNaMinuty(slot.start);
            var koniecMin = zamienNaMinuty(slot.koniec);
            var czasSlotu = koniecMin - startMin;

            var pozycjaY = Math.round(((startMin - najwczesniej) / czasTrwania) * (wysokoscCalkowita - 40));
            var wysokoscSlotu = Math.round((czasSlotu / czasTrwania) * (wysokoscCalkowita - 40)) + 1;
            wysokoscSlotu = Math.max(wysokoscSlotu, 3);

            var tekstSlotu = srl_formatujSlotHarmonogram(slot);
            var maInformacje = (slot.klient_nazwa || slot.notatka) ? true : false;

            var liczbaLinii = tekstSlotu.split('\n').length;
            var minimalnaWysokosc = Math.max(liczbaLinii * 16 + 8, 30);
            var finalnaWysokosc = Math.max(wysokoscSlotu, minimalnaWysokosc);

            if (maInformacje) {
                finalnaWysokosc = Math.max(finalnaWysokosc, 120);
            }

            var kolor = getKolorStatusu(slot.status);
            var fontSize = finalnaWysokosc > 60 ? '10px' : '9px';
            var fontWeight = maInformacje ? '500' : 'bold';

            var elementSlotu = $('<div class="srl-slot-harmonogram"></div>');
            elementSlotu.css({
                'top': pozycjaY + 'px',
                'left': (pozycjaXKolumny + 3) + 'px',
                'width': (szerokoscKolumny - 6) + 'px',
                'height': finalnaWysokosc + 'px',
                'background': kolor.bg,
                'border': '1px solid ' + kolor.border,
                'font-size': fontSize,
                'font-weight': fontWeight,
                'color': kolor.text
            });

            elementSlotu.html(tekstSlotu.replace(/\n/g, '<br>'));

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

    var legenda = $('<div class="srl-harmonogram-legenda"></div>');
    var statusy = [
        {status: 'Wolny', label: 'Wolny'},
        {status: 'Prywatny', label: 'Prywatny'},
        {status: 'Zarezerwowany', label: 'Zarezerwowany'},
        {status: 'Zrealizowany', label: 'Zrealizowany'},
        {status: 'Odwołany przez organizatora', label: 'Odwołany'}
    ];

    statusy.forEach(function(item) {
        var kolor = getKolorStatusu(item.status);
        var elementLegenda = $('<div class="srl-legend-item"></div>');

        var kolorBox = $('<div class="srl-legend-color"></div>');
        kolorBox.css({
            'background': kolor.bg,
            'border-color': kolor.border
        });

        var label = $('<span class="srl-legend-label"></span>').text(item.label);

        elementLegenda.append(kolorBox, label);
        legenda.append(elementLegenda);
    });

    kontener.append(harmonogram);
    kontener.append(legenda);
}

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
                    <input type="number" name="rok_urodzenia" min="1920" max="2020" required style="width:100%; padding:8px; margin-top:5px;">
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

    $('#srl-form-dane-prywatne').on('submit', function(e) {
        e.preventDefault();
        zapiszDanePrywatne(terminId, $(this).serialize(), modal);
    });
}

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

            generujTabelePilotow();
        } else {
            alert('Błąd: ' + response.data);
        }
    });
}

function zapiszDanePrywatne(terminId, formData, modal) {
    $.post(ajaxurl, {
        action: 'srl_zapisz_dane_prywatne',
        termin_id: terminId
    } + '&' + formData, function(response) {
        if (response.success) {
            alert('Dane zostały zapisane!');
            modal.remove();

            generujTabelePilotow();
        } else {
            alert('Błąd: ' + response.data);
        }
    });
}

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

function pokazUjednoliconyModalDanych(dane, tytul, moznaEdytowac, terminId) {

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

    var rokUrodzeniaText = dane.rok_urodzenia ? dane.rok_urodzenia + ' (' + srlFormatujWiek(dane.rok_urodzenia) + ')' : 'Brak danych';

    function formatujSprawnoscFizyczna(wartosc) {
        var mapowanie = {
            'zdolnosc_do_marszu': 'Zdolność do marszu',
            'zdolnosc_do_biegu': 'Zdolność do biegu',
            'sprinter': 'Sprinter!'
        };
        return mapowanie[wartosc] || wartosc || 'Brak danych';
    }

    var modal = $('<div class="srl-modal-dane-pasazera" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;"></div>');

    var content = $('<div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; max-height: 90%; overflow-y: auto; width: 90%;"></div>');

    var contentHtml = '<h3 style="margin-top: 0; color: #0073aa; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">' + tytul + '</h3>';

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

    if (moznaEdytowac) {
        contentHtml += '<div id="srl-dane-edytuj" style="display:none;">';
        contentHtml += '<form id="srl-form-edytuj-prywatne">';
        contentHtml += '<div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">';
        contentHtml += '<div><label><strong>Imię *</strong></label><input type="text" name="imie" value="' + (dane.imie || '') + '" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;"></div>';
        contentHtml += '<div><label><strong>Nazwisko *</strong></label><input type="text" name="nazwisko" value="' + (dane.nazwisko || '') + '" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;"></div>';
        contentHtml += '<div><label><strong>Rok urodzenia *</strong></label><input type="number" name="rok_urodzenia" value="' + (dane.rok_urodzenia || '') + '" min="1920" max="2020" required style="width:100%; padding:8px; margin-top:5px; border: 1px solid #ddd; border-radius: 4px;"></div>';
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

	modal.find('.srl-btn-zamknij').on('click', function() {
		modal.remove();
		$(document).off('keydown.srl-dane-modal');
	});

	$(document).on('keydown.srl-dane-modal', function(e) {
		if (e.keyCode === 27) { 
			modal.remove();
			$(document).off('keydown.srl-dane-modal');
		}
	});

	modal.on('remove', function() {
		$(document).off('keydown.srl-dane-modal');
	});
}

function zapiszEdytowaneDanePrywatne(terminId, formData, modal) {

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

function pokazModalPrzypisaniaSlotu(terminId) {
	console.log('Wywołano pokazModalPrzypisaniaSlotu z terminId:', terminId); 
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
                        <input type="number" name="rok_urodzenia" min="1920" max="2020" required style="width:100%; padding:8px; margin-top:5px;">
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

	modal.find('.srl-modal-anuluj').on('click', function(e) {
		e.preventDefault();
		e.stopPropagation();
		modal.remove();
		$(document).off('keydown.srl-przypisanie-modal');
	});

	$(document).on('keydown.srl-przypisanie-modal', function(e) {
		if (e.keyCode === 27) { 
			modal.remove();
			$(document).off('keydown.srl-przypisanie-modal');
		}
	});

	modal.on('remove', function() {
		$(document).off('keydown.srl-przypisanie-modal');
	});

    modal.find('#srl-typ-wykupiony').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Kliknięto Wykupiony lot'); 
        $(this).removeClass('button-secondary').addClass('button-primary');
        modal.find('#srl-typ-prywatny').removeClass('button-primary').addClass('button-secondary');
        modal.find('#srl-sekcja-wykupiony').show();
        modal.find('#srl-sekcja-prywatny').hide();
    });

    modal.find('#srl-typ-prywatny').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Kliknięto Lot prywatny'); 
        $(this).removeClass('button-secondary').addClass('button-primary');
        modal.find('#srl-typ-wykupiony').removeClass('button-primary').addClass('button-secondary');
        modal.find('#srl-sekcja-prywatny').show();
        modal.find('#srl-sekcja-wykupiony').hide();
    });

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

    modal.find('#srl-form-prywatny').on('submit', function(e) {
        e.preventDefault();
        e.stopPropagation();
        zapiszLotPrywatny(terminId, $(this).serialize(), modal);
    });
}

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

                        srlIstniejaceGodziny = refreshResponse.data.godziny_wg_pilota;

                        modal.remove();

                        generujTabelePilotow();

                        pokazKomunikatSukcesu('Wykupiony lot został przypisany do slotu!');
                    } else {
                        modal.remove();
                        generujTabelePilotow(); 
                        pokazKomunikatSukcesu('Lot został przypisany!');
                    }
                },
                error: function() {
                    modal.remove();
                    generujTabelePilotow(); 
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

function zapiszLotPrywatny(terminId, formData, modal) {

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

    var submitBtn = modal.find('#srl-form-prywatny button[type="submit"]');
    submitBtn.prop('disabled', true).text('Zapisywanie...');

    $.post(ajaxurl, requestData, function(response) {
        if (response.success) {

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

                        srlIstniejaceGodziny = refreshResponse.data.godziny_wg_pilota;

                        modal.remove();

                        generujTabelePilotow();

                        pokazKomunikatSukcesu('Lot prywatny został zapisany!');
                    } else {
                        modal.remove();
                        generujTabelePilotow(); 
                        pokazKomunikatSukcesu('Lot prywatny został zapisany!');
                    }
                },
                error: function() {
                    modal.remove();
                    generujTabelePilotow(); 
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

function zapiszEdytowaneDanePrywatne(terminId, formData, modal) {

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

    var saveBtn = modal.find('#srl-btn-zapisz');
    saveBtn.prop('disabled', true).text('Zapisywanie...');

    $.post(ajaxurl, requestData, function(response) {
        if (response.success) {

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

                        srlIstniejaceGodziny = refreshResponse.data.godziny_wg_pilota;

                        modal.remove();

                        generujTabelePilotow();

                        pokazKomunikatSukcesu('Dane zostały zaktualizowane!');
                    } else {
                        modal.remove();
                        generujTabelePilotow(); 
                        pokazKomunikatSukcesu('Dane zostały zaktualizowane!');
                    }
                },
                error: function() {
                    modal.remove();
                    generujTabelePilotow(); 
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

function pokazKomunikatSukcesu(tekst) {
    var successMsg = $('<div style="position:fixed; top:20px; right:20px; background:#46b450; color:white; padding:15px 20px; border-radius:4px; z-index:9999; font-weight:bold;">✅ ' + tekst + '</div>');
    $('body').append(successMsg);
    setTimeout(function() {
        successMsg.fadeOut(function() {
            successMsg.remove();
        });
    }, 4000);
}

function pokazDaneOdwolanegoLotu(terminId) {

    var slot = null;
    Object.keys(srlIstniejaceGodziny).forEach(function(pilotId) {
        srlIstniejaceGodziny[pilotId].forEach(function(s) {
            if (s.id == terminId) {
                slot = s;
            }
        });
    });

    if (slot) {
        var daneSlotu = srl_formatujDanePasazera(slot, false);
        if (daneSlotu.daneDoModalu) {

            pokazUjednoliconyModalDanych(daneSlotu.daneDoModalu, '🚫 Lot odwołany przez organizatora', false);
        } else {
            alert('Brak danych pasażera dla odwołanego lotu.');
        }
    } else {
        alert('Nie znaleziono danych slotu.');
    }
}


// W pliku assets/js/admin-day.js - DODAJ na końcu pliku przed ostatnim nawiasem });

$(document).off('click', '.srl-zmien-termin').on('click', '.srl-zmien-termin', function() {
    var terminId = $(this).data('termin-id');
    pokazModalZmianyTerminu(terminId);
});

function pokazModalZmianyTerminu(terminId) {
    var modal = $('<div class="srl-modal-zmiana-terminu" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;"></div>');
    var content = $('<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:30px; border-radius:8px; width:90%; max-width:95%; height:85%; overflow-y:auto;"></div>');

    content.html(`
        <div id="srl-zmiana-terminu-loader" style="text-align:center; padding:40px;">
            <div style="font-size:16px; margin-bottom:20px;">⏳ Ładowanie dostępnych terminów...</div>
        </div>
        <div id="srl-zmiana-terminu-content" style="display:none;">
            <div id="srl-zmiana-terminu-tabela"></div>
        </div>
        <div style="margin-top:20px; text-align:right; border-top:1px solid #ddd; padding-top:15px;">
            <button class="button srl-modal-anuluj-zmiane">Anuluj</button>
        </div>
    `);

    modal.append(content);
    $('body').append(modal);

    // Obsługa zamykania modala
    modal.find('.srl-modal-anuluj-zmiane').on('click', function() {
        modal.remove();
        $(document).off('keydown.srl-zmiana-modal');
    });

    $(document).on('keydown.srl-zmiana-modal', function(e) {
        if (e.keyCode === 27) { // ESC
            modal.remove();
            $(document).off('keydown.srl-zmiana-modal');
        }
    });

    modal.on('remove', function() {
        $(document).off('keydown.srl-zmiana-modal');
    });

    // Załaduj dostępne terminy
    zaladujDostepneTerminy(terminId, modal);
}

function zaladujDostepneTerminy(terminId, modal) {
    $.post(ajaxurl, {
        action: 'srl_pobierz_dostepne_terminy_do_zmiany',
        termin_id: terminId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            wygenerujTabeleDostepnychTerminow(response.data, terminId, modal);
        } else {
            modal.find('#srl-zmiana-terminu-loader').html('<p style="color:#d63638;">Błąd: ' + response.data + '</p>');
        }
    }).fail(function() {
        modal.find('#srl-zmiana-terminu-loader').html('<p style="color:#d63638;">Błąd połączenia z serwerem.</p>');
    });
}

function wygenerujTabeleDostepnychTerminow(dane, terminId, modal) {
    if (!dane.dostepne_dni || Object.keys(dane.dostepne_dni).length === 0) {
        modal.find('#srl-zmiana-terminu-loader').html('<p style="color:#666; text-align:center; padding:40px;">Brak dostępnych terminów do zmiany.</p>');
        return;
    }

    var html = '<div class="srl-terminy-tabela-container" style="overflow-y:auto; border:1px solid #ddd; border-radius:6px;">';
    html += '<table class="srl-terminy-tabela" style="width:100%; border-collapse:collapse;">';
    html += '<thead style="position:sticky; top:0; background:#f8f9fa; z-index:10;">';
    html += '<tr>';
	html += '<th style="padding:12px; border-bottom:2px solid #dee2e6; text-align:left; font-weight:600; width:1%; white-space:nowrap;">Data</th>';
	html += '<th style="padding:12px; border-bottom:2px solid #dee2e6; text-align:left; font-weight:600; width:99%;">ℹ️ Zmiana terminu dla: <span style="font-weight:bold;">' + dane.aktualny_termin.klient_nazwa + '</span> - Aktualny termin: ' + formatujDatePolski(dane.aktualny_termin.data) + ' ' + dane.aktualny_termin.godzina_start.substring(0,5) + '-' + dane.aktualny_termin.godzina_koniec.substring(0,5) + ' (Pilot ' + dane.aktualny_termin.pilot_id + ')</th>';
    html += '</tr>';
    html += '</thead>';
    html += '<tbody>';

    // Sortuj daty
    var sortedDates = Object.keys(dane.dostepne_dni).sort();

    sortedDates.forEach(function(data) {
        var terminy = dane.dostepne_dni[data];
        if (terminy.length === 0) return;

        html += '<tr>';
        html += '<td style="padding:15px; border-bottom:1px solid #eee; vertical-align:top; width:1%; white-space:nowrap;">';
        html += '<div style="font-weight:600; color:#333;">' + formatujDatePolski(data) + '</div>';
        html += '<div style="font-size:12px; color:#666;">' + formatujDzienTygodnia(data) + '</div>';
        html += '</td>';
        html += '<td style="padding:15px; border-bottom:1px solid #eee; width:99%;">';
        html += '<div class="srl-terminy-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); gap:6px;">';

		terminy.forEach(function(termin) {
			html += '<div class="srl-termin-opcja" data-termin-id="' + termin.id + '" data-oryginalny-termin="' + terminId + '" style="border:1px solid #ddd; padding:8px; border-radius:6px; cursor:pointer; background:white; transition:all 0.2s; text-align:center;">';
			html += '<div style="font-weight:600; color:#0073aa;">' + termin.godzina_start.substring(0,5) + ' - ' + termin.godzina_koniec.substring(0,5) + '</div>';
			html += '</div>';
		});

        html += '</div>';
        html += '</td>';
        html += '</tr>';
    });

    html += '</tbody>';
    html += '</table>';
    html += '</div>';

    modal.find('#srl-zmiana-terminu-content').html(html);
    modal.find('#srl-zmiana-terminu-loader').hide();
    modal.find('#srl-zmiana-terminu-content').show();

    // Dodaj obsługę kliknięć na terminy
    modal.find('.srl-termin-opcja').on('click', function() {
        var nowyTerminId = $(this).data('termin-id');
        var oryginalnyTerminId = $(this).data('oryginalny-termin');
        
        // Podświetl wybór
        modal.find('.srl-termin-opcja').removeClass('srl-termin-wybrany');
        $(this).addClass('srl-termin-wybrany');
        
        var terminInfo = $(this).find('div:first').text() + ' (' + $(this).find('div:nth-child(2)').text() + ')';
        
        if (confirm('Czy na pewno zmienić termin na: ' + terminInfo + '?')) {
            wykonajZmianeTerminu(oryginalnyTerminId, nowyTerminId, modal);
        } else {
            $(this).removeClass('srl-termin-wybrany');
        }
    });

    // Hover effects
    modal.find('.srl-termin-opcja').hover(
        function() {
            $(this).css({
                'background': '#f0f8ff',
                'border-color': '#0073aa',
                'transform': 'scale(1.02)'
            });
        },
        function() {
            if (!$(this).hasClass('srl-termin-wybrany')) {
                $(this).css({
                    'background': 'white',
                    'border-color': '#ddd',
                    'transform': 'scale(1)'
                });
            }
        }
    );
}

function wykonajZmianeTerminu(staryTerminId, nowyTerminId, modal) {
    // Zablokuj interfejs podczas zmiany
    modal.find('.srl-terminy-tabela-container').css('opacity', '0.5');
    modal.find('.srl-termin-opcja').css('pointer-events', 'none');
    
    // Dodaj loader
    var loader = $('<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:rgba(255,255,255,0.9); padding:20px; border-radius:6px; text-align:center; z-index:1000;">⏳ Zmienianie terminu...</div>');
    modal.find('#srl-zmiana-terminu-content').css('position', 'relative').append(loader);

    $.post(ajaxurl, {
        action: 'srl_zmien_termin_lotu',
        stary_termin_id: staryTerminId,
        nowy_termin_id: nowyTerminId,
        nonce: srlAdmin.nonce
    }, function(response) {
        if (response.success) {
            // Sukces - odśwież tabele
            modal.remove();
            generujTabelePilotow(); // Odśwież aktualną stronę
            pokazKomunikatSukcesu('Termin lotu został pomyślnie zmieniony!');
        } else {
            // Błąd - przywróć interfejs
            loader.remove();
            modal.find('.srl-terminy-tabela-container').css('opacity', '1');
            modal.find('.srl-termin-opcja').css('pointer-events', 'auto');
            modal.find('.srl-termin-wybrany').removeClass('srl-termin-wybrany');
            
            alert('Błąd zmiany terminu: ' + response.data);
        }
    }).fail(function() {
        // Błąd połączenia - przywróć interfejs
        loader.remove();
        modal.find('.srl-terminy-tabela-container').css('opacity', '1');
        modal.find('.srl-termin-opcja').css('pointer-events', 'auto');
        modal.find('.srl-termin-wybrany').removeClass('srl-termin-wybrany');
        
        alert('Błąd połączenia z serwerem.');
    });
}

function formatujDatePolski(dataStr) {
    var data = new Date(dataStr);
    var dzien = data.getDate();
    var miesiac = data.getMonth() + 1;
    var rok = data.getFullYear();

    return pad2(dzien) + '.' + pad2(miesiac) + '.' + rok;
}

function formatujDzienTygodnia(dataStr) {
    var data = new Date(dataStr);
    var nazwyDni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
    return nazwyDni[data.getDay()];
}

// CSS dla wybranego terminu
$(document).ready(function() {
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .srl-termin-wybrany {
                background: #e3f2fd !important;
                border-color: #1976d2 !important;
                transform: scale(1.02) !important;
                box-shadow: 0 2px 8px rgba(25, 118, 210, 0.3) !important;
            }
            
            .srl-termin-opcja {
                transition: all 0.2s ease !important;
            }
        `)
        .appendTo('head');
});

});