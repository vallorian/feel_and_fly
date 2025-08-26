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
    return (!rokUrodzenia || rokUrodzenia < 1920) ? '' : new Date().getFullYear() - parseInt(rokUrodzenia);
}

function srlFormatujWiek(rokUrodzenia) {
    var wiek = srlObliczWiek(rokUrodzenia);
    return wiek ? wiek + ' lat' : '';
}

jQuery(document).ready(function($) {
	
	function dodajObslugeEscModal(modal, namespace, onClose) {
		var eventName = 'keydown.srl-' + namespace;
		$(document).on(eventName, function(e) {
			if (e.keyCode === 27) { // ESC
				if (typeof onClose === 'function') {
					onClose();
				}
				modal.remove();
				$(document).off(eventName);
			}
		});
		modal.on('remove', function() { $(document).off(eventName); });
		return function() { $(document).off(eventName); };
	}
	
	
    var generowanieWToku = false;
    
    if ($('#srl-planowane-godziny').is(':checked') && Object.keys(srlIstniejaceGodziny).length > 0) {
        generujTabelePilotow();
    }

    $('#srl-planowane-godziny').on('change', function() {
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
        window.location.href = addOrUpdateUrlParam('data', $(this).val());
    });

    $('#srl-liczba-pilotow, #srl-interwal').on('change', function() {
        if ($('#srl-planowane-godziny').is(':checked')) {
            if ($(this).attr('id') === 'srl-liczba-pilotow') aktualizujDropdownPilotow();
            generujTabelePilotow();
        }
    });

    $('#srl-generuj-sloty').off('click').on('click', function(e) {
        e.preventDefault();
		e.stopPropagation();
        if (generowanieWToku) return;

        var pilotId = parseInt($('#srl-generuj-pilot').val());
        var godzOd = $('#srl-generuj-od').val();
        var godzDo = $('#srl-generuj-do').val();
        var interwal = parseInt($('#srl-interwal').val());

        if (!godzOd || !godzDo || !/^[0-2]\d:[0-5]\d$/.test(godzOd) || !/^[0-2]\d:[0-5]\d$/.test(godzDo)) {
            alert('Podaj prawidłowe godziny (HH:MM).');
            return;
        }
        
        if (zamienNaMinuty(godzDo) <= zamienNaMinuty(godzOd)) {
            alert('Godzina końcowa musi być późniejsza niż początkowa.');
            return;
        }

        if (!confirm(`Czy na pewno wygenerować sloty dla Pilot ${pilotId} od ${godzOd} do ${godzDo} wg interwału ${interwal} min?`)) return;

        generowanieWToku = true;
        var btn = $(this);
        btn.prop('disabled', true).text('Generowanie...');

        var startMin = zamienNaMinuty(godzOd);
        var endLimit = zamienNaMinuty(godzDo);
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
            btn.prop('disabled', false).text('Generuj sloty');
            if (blad) alert('Wystąpił błąd przy generowaniu niektórych slotów.');
            if (dodanych > 0) alert(`Wygenerowano ${dodanych} slotów dla Pilota ${pilotId}.`);
        });
    });

    function addOrUpdateUrlParam(key, value) {
        var url = new URL(window.location.href);
        url.searchParams.set(key, value);
        return url.toString();
    }

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
                data: srlData,
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
        var kontener = $('#srl-tabele-pilotow');
        kontener.empty();
        var liczbaPilotow = parseInt($('#srl-liczba-pilotow').val());

        for (var pid = 1; pid <= liczbaPilotow; pid++) {
            var divaPilota = $(`<div class="srl-pilot-container" style="margin-bottom:30px; border:1px solid #ddd; padding:15px; border-radius:8px;">
                <h2 style="background:#4263be; color:white; margin:0 -15px 15px -15px; padding:12px 15px; font-size:16px;">Pilot nr ${pid}</h2>
                <div class="srl-grupowe-funkcje">
                    <label><input type="checkbox" class="srl-zaznacz-wszystkie" data-pilot="${pid}"> Zaznacz wszystkie</label>
                    <select class="srl-grupowa-zmiana-statusu" data-pilot="${pid}">
                        <option value="">-- Zmień status --</option>
                        <option value="Wolny">Wolny</option>
                        <option value="Zrealizowany">Zrealizowany</option>
                        <option value="Odwołany przez organizatora">Odwołany przez organizatora</option>
                    </select>
                    <button class="button srl-grupowe-usun" data-pilot="${pid}">Usuń zaznaczone</button>
                </div>
                <table class="widefat" data-pilot-id="${pid}">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" class="srl-zaznacz-wszystkie-naglowek" data-pilot="${pid}"></th>
                            <th style="width:30px;">Nr</th>
                            <th>Czas lotu</th>
                            <th>Status slotu</th>
                            <th>ID lotu</th>
                            <th>Dane pasażera</th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>`);

            var listaGodzin = srlIstniejaceGodziny[pid] || [];
            var tabela = divaPilota.find('table');
            
            listaGodzin.forEach(function(obiektGodziny, i) {
                dodajWierszDoTabeli(pid, i + 1, obiektGodziny, tabela);
            });

            kontener.append(divaPilota);
        }

        zaladujNasluchiwace();
        generujHarmonogramCzasowy();
    }

    function dodajWierszDoTabeli(pilotId, numer, slot, tabela) {
        var tr = $(`<tr data-termin-id="${slot.id}">
            <td><input type="checkbox" class="srl-slot-checkbox" data-pilot="${pilotId}" data-termin-id="${slot.id}"></td>
            <td>${numer}</td>
        </tr>`);

        var startMin = zamienNaMinuty(slot.start);
        var endMin = zamienNaMinuty(slot.koniec);
        var delta = endMin - startMin;
        var czasTxt = `${slot.start} - ${slot.koniec} (${delta}min)`;

        tr.append(`<td class="srl-czas-col">${czasTxt} 
            <button class="button button-small srl-edytuj-button" style="margin-left:10px; font-size:11px;">Edytuj godziny</button>
            ${slot.status === 'Wolny' ? '<button class="button button-secondary button-small srl-usun-button" style="margin-left:5px; font-size:11px;">Usuń</button>' : ''}
        </td>`);

        var statusConfig = {
            'Wolny': {class: 'status-available', icon: '🟢'},
            'Prywatny': {class: 'status-private', icon: '🟤'},
            'Zarezerwowany': {class: 'status-reserved', icon: '🟡'},
            'Zrealizowany': {class: 'status-completed', icon: '🔵'},
            'Odwołany przez organizatora': {class: 'status-cancelled', icon: '🔴'}
        };
        
        var config = statusConfig[slot.status] || {class: 'status-unknown', icon: '⚪'};
        tr.append(`<td><span class="${config.class}" style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">${config.icon} ${slot.status}</span></td>`);

        var lotIdHtml = '—';
        if ((slot.status === 'Zarezerwowany' || slot.status === 'Zrealizowany') && slot.lot_id) {
            lotIdHtml = `<a href="${srlAdmin.adminUrl}admin.php?page=srl-wykupione-loty&search_field=id_lotu&s=${slot.lot_id}" target="_blank" style="color:#4263be; font-weight:bold;">#${slot.lot_id}</a>`;
        } else if (slot.status === 'Odwołany przez organizatora' && slot.notatka) {
            try {
                var daneOdwolane = JSON.parse(slot.notatka);
                if (daneOdwolane.lot_id) {
                    lotIdHtml = `<a href="${srlAdmin.adminUrl}admin.php?page=srl-wykupione-loty&search_field=id_lotu&s=${daneOdwolane.lot_id}" target="_blank" style="color:#dc3545; font-weight:bold;">#${daneOdwolane.lot_id} (odwołany)</a>`;
                }
            } catch (e) {}
        } else if (slot.status === 'Prywatny' || (slot.status === 'Zrealizowany' && !slot.lot_id)) {
            lotIdHtml = '<span style="color:#6c757d; font-weight:bold;">PRYWATNY</span>';
        }
        tr.append(`<td>${lotIdHtml}</td>`);

		var danePasazeraHtml = '—';
		if (slot.status === 'Zarezerwowany') {
			// Lot wykupiony zarezerwowany
			if (slot.lot_id && slot.klient_nazwa) {
				danePasazeraHtml = `<button class="button button-small srl-pokaz-dane-pasazera" data-lot-id="${slot.lot_id}" data-user-id="${slot.klient_id}">${slot.klient_nazwa}</button>`;
			} else {
				danePasazeraHtml = `<button class="button button-small srl-przypisz-klienta" data-termin-id="${slot.id}">Przypisz klienta</button>`;
			}
		} else if (slot.status === 'Zrealizowany') {
			// Sprawdź czy to lot wykupiony czy prywatny
			if (slot.lot_id && slot.klient_nazwa) {
				// Lot wykupiony zrealizowany
				danePasazeraHtml = `<button class="button button-small srl-pokaz-dane-pasazera" data-lot-id="${slot.lot_id}" data-user-id="${slot.klient_id}">${slot.klient_nazwa}</button>`;
			} else if (slot.notatka) {
				// Lot prywatny zrealizowany
				var buttonText = 'Dane prywatne (zrealizowany)';
				try {
					var danePrivate = JSON.parse(slot.notatka);
					if (danePrivate.imie && danePrivate.nazwisko) {
						buttonText = danePrivate.imie + ' ' + danePrivate.nazwisko;
					}
				} catch (e) {}
				danePasazeraHtml = `<button class="button button-small srl-pokaz-dane-prywatne" data-termin-id="${slot.id}">${buttonText}</button>`;
			}
		} else if (slot.status === 'Prywatny') {
			// Lot prywatny
			var buttonText = 'Dane prywatne';
			if (slot.notatka) {
				try {
					var danePrivate = JSON.parse(slot.notatka);
					if (danePrivate.imie && danePrivate.nazwisko) {
						buttonText = danePrivate.imie + ' ' + danePrivate.nazwisko;
					}
				} catch (e) {}
			}
			danePasazeraHtml = `<button class="button button-small srl-pokaz-dane-prywatne" data-termin-id="${slot.id}">${buttonText}</button>`;
		} else if (slot.status === 'Odwołany przez organizatora' && slot.notatka) {
			try {
				var daneOdwolane = JSON.parse(slot.notatka);
				if (daneOdwolane.klient_nazwa) {
					danePasazeraHtml = `<button class="button button-small srl-pokaz-dane-odwolane" data-termin-id="${slot.id}" style="background:#dc3545; color:white;">${daneOdwolane.klient_nazwa} (odwołany)</button>`;
				} else {
					danePasazeraHtml = '<span style="color:#dc3545; font-style:italic;">Lot odwołany</span>';
				}
			} catch (e) {
				danePasazeraHtml = '<span style="color:#dc3545; font-style:italic;">Lot odwołany</span>';
			}
		}
        tr.append(`<td>${danePasazeraHtml}</td>`);

        var akcjeHtml = '—';
        if (slot.status === 'Wolny') {
            akcjeHtml = `<button class="button button-primary srl-przypisz-slot" data-termin-id="${slot.id}">Przypisz klienta</button>`;
		} else if (slot.status === 'Zarezerwowany' && slot.klient_id > 0) {
			akcjeHtml = `<button class="button srl-zrealizuj-lot" data-termin-id="${slot.id}" style="background:#28a745; color:white; margin-right:5px;">Zrealizuj</button>
				<button class="button srl-zmien-termin" data-termin-id="${slot.id}" style="background:#ff9800; color:white; margin-right:5px;">Zmiana terminu</button>
				<button class="button srl-wypisz-klienta" style="margin-right:5px;">Wypisz klienta</button>
				<button class="button srl-odwolaj-lot" data-termin-id="${slot.id}" style="background:#dc3545; color:white;">Odwołaj</button>`;
		} else if (slot.status === 'Prywatny') {
			akcjeHtml = `<button class="button srl-zrealizuj-lot-prywatny" data-termin-id="${slot.id}" style="background:#28a745; color:white; margin-right:5px;">Zrealizuj</button>
				<button class="button srl-zmien-termin" data-termin-id="${slot.id}" style="background:#ff9800; color:white; margin-right:5px;">Zmiana terminu</button>
				<button class="button srl-wypisz-slot-prywatny" data-termin-id="${slot.id}">Wypisz klienta</button>`;
        } else if (slot.status === 'Zrealizowany') {
            akcjeHtml = '<span style="color:#28a745; font-weight:bold;">✅ Zrealizowany</span>';
        } else if (slot.status === 'Odwołany przez organizatora') {
            akcjeHtml = `<button class="button srl-przywroc-rezerwacje" data-termin-id="${slot.id}" style="background:#28a745; color:white;">Przywróć rezerwację</button>`;
        }
        tr.append(`<td>${akcjeHtml}</td>`);

        tabela.find('tbody').append(tr);
        sprawdzNakladanie(tabela, pilotId);
    }

    function zaladujNasluchiwace() {
        $('.srl-zaznacz-wszystkie, .srl-zaznacz-wszystkie-naglowek').off('change').on('change', function() {
            var pilot = $(this).data('pilot');
            var checked = $(this).is(':checked');
            $(`.srl-slot-checkbox[data-pilot="${pilot}"]`).prop('checked', checked);
        });

        $('.srl-grupowa-zmiana-statusu').off('change').on('change', function() {
            var pilot = $(this).data('pilot');
            var nowyStatus = $(this).val();
            var selectElement = $(this);

            if (!nowyStatus) return;

            var zaznaczone = $(`.srl-slot-checkbox[data-pilot="${pilot}"]:checked`);
            if (zaznaczone.length === 0) {
                alert('Nie zaznaczono żadnych slotów.');
                selectElement.val('');
                return;
            }

            if (!confirm(`Czy na pewno zmienić status ${zaznaczone.length} slotów na "${nowyStatus}"?`)) {
                selectElement.val('');
                return;
            }

            var zmienionych = 0;
            var doZmiany = zaznaczone.length;

            zaznaczone.each(function() {
                var terminId = $(this).data('termin-id');
                zmienStatusSlotu(terminId, nowyStatus, 0, '', null, function() {
                    zmienionych++;
                    if (zmienionych === doZmiany) generujTabelePilotow();
                }, true);
            });

            selectElement.val('');
        });

        $('.srl-grupowe-usun').off('click').on('click', function() {
            var pilot = $(this).data('pilot');
            var zaznaczone = $(`.srl-slot-checkbox[data-pilot="${pilot}"]:checked`);

            if (zaznaczone.length === 0) {
                alert('Nie zaznaczono żadnych slotów do usunięcia.');
                return;
            }

            if (!confirm(`Czy na pewno usunąć ${zaznaczone.length} zaznaczonych slotów?`)) return;

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
                    }
                    if (usuniete === doUsuniecia) generujTabelePilotow();
                }).fail(function() {
                    usuniete++;
                    if (usuniete === doUsuniecia) generujTabelePilotow();
                });
            });
        });

        $(document).off('click', '.srl-usun-button').on('click', '.srl-usun-button', function() {
            var wiersz = $(this).closest('tr');
            var terminId = wiersz.data('termin-id');
            var button = $(this);

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
            var wiersz = $(this).closest('tr');
            var terminId = wiersz.data('termin-id');
            var currText = wiersz.find('.srl-czas-col').text();
            var czasParts = currText.match(/(\d{1,2}:\d{2}) - (\d{1,2}:\d{2})/);
            var currStart = czasParts ? czasParts[1] : '09:00';
            var currStop = czasParts ? czasParts[2] : '09:30';

            wiersz.find('.srl-czas-col').data('original-text', currText).html(`
                <div style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">
                    <input type="time" class="srl-edit-start" value="${currStart}" style="width:90px;">
                    <span>-</span>
                    <input type="time" class="srl-edit-stop" value="${currStop}" style="width:90px;">
                    <button class="button button-small button-primary srl-zapisz-godziny" data-termin-id="${terminId}" style="margin-left:5px;">Zapisz</button>
                    <button class="button button-small srl-anuluj-edycje-godzin" style="margin-left:2px;">Anuluj</button>
                </div>
            `);
        });

        $(document).off('click', '.srl-zapisz-godziny').on('click', '.srl-zapisz-godziny', function() {
            var btn = $(this);
            var wiersz = btn.closest('tr');
            var terminId = btn.data('termin-id');
            var newStart = wiersz.find('.srl-edit-start').val();
            var newStop = wiersz.find('.srl-edit-stop').val();

            if (!newStart || !newStop || !/^[0-2]\d:[0-5]\d$/.test(newStart) || !/^[0-2]\d:[0-5]\d$/.test(newStop) || zamienNaMinuty(newStop) <= zamienNaMinuty(newStart)) {
                alert('Nieprawidłowe godziny lub koniec nie jest później niż start.');
                return;
            }

            btn.prop('disabled', true).text('Zapisywanie...');

            $.post(ajaxurl, {
                action: 'srl_zmien_slot',
                termin_id: terminId,
                data: srlData,
                godzina_start: newStart,
                godzina_koniec: newStop,
                status: 'Wolny',
                klient_id: 0,
				nonce: srlAdmin.nonce
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
            var wiersz = $(this).closest('tr');
            var originalText = wiersz.find('.srl-czas-col').data('original-text');
            var czasParts = originalText.match(/(\d{1,2}:\d{2} - \d{1,2}:\d{2})/);
            var czasTxt = czasParts ? czasParts[1] : originalText;
            
            wiersz.find('.srl-czas-col').html(czasTxt + ' <button class="button button-small srl-edytuj-button" style="margin-left:10px; font-size:11px;">Edytuj godziny</button>');
        });

        bindEventHandlers();
    }

    function bindEventHandlers() {
        var handlers = {
            '.srl-wypisz-slot-prywatny': function() {
				var terminId = $(this).data('termin-id');
				if (!confirm('Czy na pewno wyczyścić ten slot prywatny i zmienić status na wolny? Klient zostanie powiadomiony o anulowaniu (jeśli podał email).')) return;
				
				var button = $(this);
				button.prop('disabled', true).text('Czyszczenie...');
				
				$.post(ajaxurl, {
					action: 'srl_wypisz_slot_prywatny',
					termin_id: terminId,
					nonce: srlAdmin.nonce
				}, function(response) {
					if (response.success) {
						if (response.data && response.data.godziny_wg_pilota) {
							srlIstniejaceGodziny = response.data.godziny_wg_pilota;
						}
						generujTabelePilotow();
						pokazKomunikatSukcesu('Slot prywatny został wyczyszczony.' + (response.data.email_sent ? ' Klient został powiadomiony.' : ''));
					} else {
						alert('Błąd: ' + response.data);
						button.prop('disabled', false).text('Wyczyść slot');
					}
				}).fail(function() {
					alert('Błąd połączenia z serwerem');
					button.prop('disabled', false).text('Wyczyść slot');
				});
			},
            
            '.srl-wypisz-klienta': function() {
				var terminId = $(this).closest('tr').data('termin-id');
				if (!confirm('Czy na pewno wypisać klienta i przywrócić slot jako wolny? Klient zostanie powiadomiony o anulowaniu.')) return;
				
				// Użyj dedykowanej akcji AJAX dla wypisania klienta
				var button = $(this);
				button.prop('disabled', true).text('Wypisywanie...');
				
				$.post(ajaxurl, {
					action: 'srl_wypisz_klienta_ze_slotu',
					termin_id: terminId,
					nonce: srlAdmin.nonce
				}, function(response) {
					if (response.success) {
						if (response.data && response.data.godziny_wg_pilota) {
							srlIstniejaceGodziny = response.data.godziny_wg_pilota;
						}
						generujTabelePilotow();
						pokazKomunikatSukcesu('Klient został wypisany i powiadomiony o anulowaniu.');
					} else {
						alert('Błąd: ' + response.data);
						button.prop('disabled', false).text('Wypisz klienta');
					}
				}).fail(function() {
					alert('Błąd połączenia z serwerem');
					button.prop('disabled', false).text('Wypisz klienta');
				});
			},
            
            '.srl-pokaz-dane-pasazera': function() {
                pokazDanePasazeraModal($(this).data('lot-id'), $(this).data('user-id'));
            },
            
            '.srl-przypisz-klienta': function() {
                pokazFormularzPrzypisaniaKlienta($(this).data('termin-id'));
            },
            
            '.srl-pokaz-dane-prywatne': function() {
                pokazDanePrywatneModal($(this).data('termin-id'));
            },
            
            '.srl-przypisz-slot': function() {
                pokazModalPrzypisaniaSlotu($(this).data('termin-id'));
            },
            
            '.srl-odwolaj-lot': function() {
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
                });
            },
            
            '.srl-przywroc-rezerwacje': function() {
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
                });
            },
            
            '.srl-pokaz-dane-odwolane': function() {
                pokazDaneOdwolanegoLotu($(this).data('termin-id'));
            },
            
            '.srl-zrealizuj-lot': function() {
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
                });
            },
            
            '.srl-zrealizuj-lot-prywatny': function() {
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
                });
            },
            
            '.srl-zmien-termin': function() {
                pokazModalZmianyTerminu($(this).data('termin-id'));
            }
        };

        Object.keys(handlers).forEach(function(selector) {
            $(document).off('click', selector).on('click', selector, handlers[selector]);
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
                if (selectElement) selectElement.val(selectElement.data('poprzedni'));
            }
        }).fail(function() {
            alert('Błąd połączenia z serwerem');
            if (selectElement) selectElement.val(selectElement.data('poprzedni'));
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
        var nowaLiczba = parseInt($('#srl-liczba-pilotow').val());
        var select = $('#srl-generuj-pilot');
        select.empty();
        for (var i = 1; i <= nowaLiczba; i++) {
            select.append(`<option value="${i}">Pilot ${i}</option>`);
        }
    }

    function zamienNaMinuty(czas) {
        var p = czas.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }

    function pad2(n) {
        return (n < 10) ? '0' + n : '' + n;
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

        var liczbaPilotow = parseInt($('#srl-liczba-pilotow').val());
        var najwczesniej = 24 * 60, najpozniej = 0;

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
            naglowek.append(`<div class="srl-harmonogram-pilot-col">Pilot ${pid}</div>`);
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
                var wysokoscSlotu = Math.max(Math.round((czasSlotu / czasTrwania) * (wysokoscCalkowita - 40)) + 1, 3);

                var tekstSlotu = formatujSlotHarmonogram(slot);
                var maInformacje = !!(slot.klient_nazwa || slot.notatka);

                var liczbaLinii = tekstSlotu.split('\n').length;
                var minimalnaWysokosc = Math.max(liczbaLinii * 16 + 8, 30);
                var finalnaWysokosc = Math.max(wysokoscSlotu, minimalnaWysokosc);

                if (maInformacje) finalnaWysokosc = Math.max(finalnaWysokosc, 120);

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
                    var targetRow = $(`tr[data-termin-id="${slot.id}"]`);
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
        var kolory = {
            'Wolny': {bg: '#d4edda', border: '#28a745', text: '#155724'},
            'Prywatny': {bg: '#e2e3e5', border: '#6c757d', text: '#495057'},
            'Zarezerwowany': {bg: '#cce5ff', border: '#007bff', text: '#004085'},
            'Zrealizowany': {bg: '#d1ecf1', border: '#17a2b8', text: '#0c5460'},
            'Odwołany przez organizatora': {bg: '#f8d7da', border: '#dc3545', text: '#721c24'}
        };
        return kolory[status] || {bg: '#f8f9fa', border: '#6c757d', text: '#495057'};
    }

    function formatujSlotHarmonogram(slot) {
        var linie = [];
        linie.push(`${slot.start} - ${slot.koniec} (${slot.status.toUpperCase()})`);

        if (slot.lot_id) {
            linie.push(`#${slot.lot_id} - Lot w tandemie`);
        } else if (slot.status === 'Odwołany przez organizatora' && slot.notatka) {
            try {
                var daneOdwolane = JSON.parse(slot.notatka);
                if (daneOdwolane.lot_id) {
                    linie.push(`#${daneOdwolane.lot_id} - Lot odwołany`);
                }
            } catch (e) {}
        } else if (slot.status === 'Prywatny' || (slot.status === 'Zrealizowany' && slot.notatka && !slot.lot_id)) {
            linie.push('Lot prywatny');
        }

        var dane = null;
        if (['Prywatny', 'Zrealizowany'].includes(slot.status) && slot.notatka && !slot.lot_id) {
            try {
                dane = JSON.parse(slot.notatka);
            } catch (e) {}
        } else if (['Zarezerwowany', 'Zrealizowany'].includes(slot.status) && slot.klient_nazwa) {
            dane = slot.dane_pasazera_cache;
        } else if (slot.status === 'Odwołany przez organizatora' && slot.notatka) {
            try {
                dane = JSON.parse(slot.notatka);
            } catch (e) {}
        }

        if (dane && (dane.imie || dane.klient_nazwa)) {
            var nazwa = dane.klient_nazwa || `${dane.imie} ${dane.nazwisko}`;
            if (dane.rok_urodzenia) {
                nazwa += ` (${new Date().getFullYear() - parseInt(dane.rok_urodzenia)} lat)`;
            }
            if (dane.kategoria_wagowa) nazwa += `, ${dane.kategoria_wagowa}`;
            
            var sprawnosci = {
                'zdolnosc_do_marszu': 'Marsz',
                'zdolnosc_do_biegu': 'Bieg',
                'sprinter': 'Sprinter'
            };
            if (dane.sprawnosc_fizyczna) {
                nazwa += `, ${sprawnosci[dane.sprawnosc_fizyczna] || dane.sprawnosc_fizyczna}`;
            }
            
            linie.push(nazwa);
            
            if (dane.telefon) linie.push(`Tel: ${dane.telefon}`);
            if (dane.uwagi && dane.uwagi.trim()) {
                var uwagi = dane.uwagi.trim();
                if (uwagi.length > 40) uwagi = uwagi.substring(0, 37) + '...';
                linie.push(`Uwagi: ${uwagi}`);
            }
        } else if (slot.klient_nazwa) {
            linie.push(slot.klient_nazwa);
            linie.push('[Kliknij INFO w tabeli]');
        }

        return linie.join('\n');
    }

    function pokazModalPrzypisaniaSlotu(terminId) {
        var modal = $(`<div class="srl-modal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:30px; border-radius:8px; width:600px; max-width:90%; max-height:80%; overflow-y:auto;">
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
							<div><label>Imię i nazwisko *</label><input type="text" name="imie_nazwisko" required style="width:100%; padding:8px; margin-top:5px;" placeholder="Jan Kowalski"></div>
							<div><label>Adres email</label><input type="email" name="email" style="width:100%; padding:8px; margin-top:5px;" placeholder="jan.kowalski@email.com"></div>
							<div><label>Rok urodzenia *</label><input type="number" name="rok_urodzenia" min="1920" max="2020" required style="width:100%; padding:8px; margin-top:5px;"></div>
							<div><label>Numer telefonu *</label><input type="tel" name="telefon" required style="width:100%; padding:8px; margin-top:5px;" placeholder="+48 123 456 789"></div>
							<div><label>Sprawność fizyczna *</label>
								<select name="sprawnosc_fizyczna" required style="width:100%; padding:8px; margin-top:5px;">
									<option value="">Wybierz poziom sprawności</option>
									<option value="zdolnosc_do_marszu">Zdolność do marszu</option>
									<option value="zdolnosc_do_biegu">Zdolność do biegu</option>
									<option value="sprinter">Sprinter!</option>
								</select>
							</div>
							<div><label>Kategoria wagowa *</label>
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
            </div>
        </div>`);

        $('body').append(modal);
		dodajObslugeEscModal(modal, 'przypisanie-slotu');
        modal.find('.srl-modal-anuluj').on('click', function(e) {
            e.preventDefault();
            modal.remove();
        });

        modal.find('#srl-typ-wykupiony').on('click', function(e) {
            e.preventDefault();
            $(this).removeClass('button-secondary').addClass('button-primary');
            modal.find('#srl-typ-prywatny').removeClass('button-primary').addClass('button-secondary');
            modal.find('#srl-sekcja-wykupiony').show();
            modal.find('#srl-sekcja-prywatny').hide();
        });

        modal.find('#srl-typ-prywatny').on('click', function(e) {
            e.preventDefault();
            $(this).removeClass('button-secondary').addClass('button-primary');
            modal.find('#srl-typ-wykupiony').removeClass('button-primary').addClass('button-secondary');
            modal.find('#srl-sekcja-prywatny').show();
            modal.find('#srl-sekcja-wykupiony').hide();
        });

        modal.find('#srl-search-btn').on('click', function(e) {
            e.preventDefault();
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
            zapiszLotPrywatny(terminId, $(this).serialize(), modal);
        });
    }

    function wyszukajWykupionyLot(terminId, modal) {
        var searchField = modal.find('#srl-search-field').val();
        var query = modal.find('#srl-search-query').val().trim();

        if (query.length < 2) {
            alert(`Wprowadź co najmniej 2 znaki do wyszukania. Aktualna długość: ${query.length}`);
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
                    html += `<div class="srl-lot-result" data-termin-id="${terminId}" data-lot-id="${lot.lot_id}" data-klient-nazwa="${lot.klient_nazwa}" style="border:1px solid #ddd; padding:15px; margin:5px 0; cursor:pointer; border-radius:4px;">
                        <div style="font-weight:bold; color:#4263be;">Lot #${lot.lot_id} - Zamówienie #${lot.order_id}</div>
                        <div style="margin:5px 0;"><strong>${lot.klient_nazwa}</strong> (${lot.email})</div>
                        ${lot.telefon ? `<div style="font-size:12px; color:#666;">📞 ${lot.telefon}</div>` : ''}
                    </div>`;
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
        });
    }

	function przypisWykupionyLot(terminId, lotId, klientNazwa, modal) {
        if (!confirm(`Czy na pewno przypisać lot #${lotId} (${klientNazwa}) do tego slotu?`)) return;

        var button = modal.find(`.srl-lot-result[data-lot-id="${lotId}"]`);
        if (button.length) {
            button.css('opacity', '0.5');
            button.prepend('<span style="color: #4263be; font-weight: bold;">⏳ Przypisywanie...</span><br>');
        }

        $.post(ajaxurl, {
            action: 'srl_przypisz_wykupiony_lot',
            termin_id: terminId,
            lot_id: lotId,
            nonce: srlAdmin.nonce
        }, function(response) {
            if (response.success) {
                modal.remove();
                if (response.data && response.data.godziny_wg_pilota) {
                    srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                    generujTabelePilotow();
                } else {
                    generujTabelePilotow();
                }
                pokazKomunikatSukcesu('Wykupiony lot został przypisany do slotu!');
            } else {
                if (button.length) {
                    button.css('opacity', '1');
                    button.find('span:first').remove();
                }
                alert('Błąd: ' + response.data);
            }
        });
    }

	function zapiszLotPrywatny(terminId, formData, modal) {
		var submitBtn = modal.find('#srl-form-prywatny button[type="submit"]');
		submitBtn.prop('disabled', true).text('Zapisywanie...');

		var formDataObj = {};
		formData.split('&').forEach(function(pair) {
			var keyValue = pair.split('=');
			if (keyValue.length === 2) {
				formDataObj[decodeURIComponent(keyValue[0])] = decodeURIComponent(keyValue[1]);
			}
		});

		// Podziel imię i nazwisko z jednego pola
		var imieNazwisko = formDataObj['imie_nazwisko'] || '';
		var czesciImienia = imieNazwisko.trim().split(/\s+/);
		var imie = czesciImienia[0] || '';
		var nazwisko = czesciImienia.slice(1).join(' ') || '';

		// Walidacja podstawowa
		if (!imie || !nazwisko) {
			submitBtn.prop('disabled', false).text('Zapisz lot prywatny');
			alert('Podaj pełne imię i nazwisko (minimum dwa słowa).');
			return;
		}

		// Walidacja email tylko jeśli został podany
		if (formDataObj['email'] && formDataObj['email'].trim() && !formDataObj['email'].includes('@')) {
			submitBtn.prop('disabled', false).text('Zapisz lot prywatny');
			alert('Jeśli podajesz email, musi być prawidłowy.');
			return;
		}

		$.post(ajaxurl, {
			action: 'srl_zapisz_lot_prywatny',
			termin_id: terminId,
			nonce: srlAdmin.nonce,
			imie: imie,
			nazwisko: nazwisko,
			email: formDataObj['email'],
			rok_urodzenia: formDataObj['rok_urodzenia'],
			telefon: formDataObj['telefon'],
			sprawnosc_fizyczna: formDataObj['sprawnosc_fizyczna'],
			kategoria_wagowa: formDataObj['kategoria_wagowa'],
			uwagi: formDataObj['uwagi']
		}, function(response) {
			if (response.success) {
				modal.remove();
				if (response.data && response.data.godziny_wg_pilota) {
					srlIstniejaceGodziny = response.data.godziny_wg_pilota;
					generujTabelePilotow();
				} else {
					generujTabelePilotow();
				}
				pokazKomunikatSukcesu('Lot prywatny został zapisany!');
			} else {
				submitBtn.prop('disabled', false).text('Zapisz lot prywatny');
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
                pokazUjednoliconyModalDanych(response.data, `Szczegóły lotu #${lotId}`, false);
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
                pokazUjednoliconyModalDanych(response.data, 'Szczegóły lotu prywatnego', true, terminId);
            } else {
                alert('Brak danych do wyświetlenia');
            }
        });
    }

	function pokazUjednoliconyModalDanych(dane, tytul, moznaEdytowac, terminId) {
		var imieNazwisko = dane.imie && dane.nazwisko ? `${dane.imie} ${dane.nazwisko}` : dane.imie || dane.nazwisko || 'Brak danych';
		var rokUrodzeniaText = dane.rok_urodzenia ? `${dane.rok_urodzenia} (${srlFormatujWiek(dane.rok_urodzenia)})` : 'Brak danych';

		var sprawnoscMap = {
			'zdolnosc_do_marszu': 'Zdolność do marszu',
			'zdolnosc_do_biegu': 'Zdolność do biegu',
			'sprinter': 'Sprinter!'
		};

		var modal = $(`<div class="srl-modal-dane-pasazera" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;">
			<div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; max-height: 90%; overflow-y: auto; width: 90%;">
				<h3 style="margin-top: 0; color: #4263be; border-bottom: 2px solid #4263be; padding-bottom: 10px;">${tytul}</h3>
				<div id="srl-dane-wyswietl">
					<div style="line-height: 1.8; font-size: 15px;">
						<p><strong>Imię i nazwisko:</strong> ${imieNazwisko}</p>
						${dane.email ? `<p><strong>Email:</strong> ${dane.email}</p>` : ''}
						<p><strong>Rok urodzenia:</strong> ${rokUrodzeniaText}</p>
						<p><strong>Telefon:</strong> ${dane.telefon || 'Brak danych'}</p>
						<p><strong>Sprawność fizyczna:</strong> ${sprawnoscMap[dane.sprawnosc_fizyczna] || dane.sprawnosc_fizyczna || 'Brak danych'}</p>
						<p><strong>Kategoria wagowa:</strong> ${dane.kategoria_wagowa || 'Brak danych'}</p>
						<p><strong>Uwagi:</strong> ${dane.uwagi && dane.uwagi.trim() ? dane.uwagi : 'Brak uwag'}</p>
					</div>
				</div>
				${moznaEdytowac ? `<div id="srl-dane-edytuj" style="display:none;">
					<form id="srl-form-edytuj-prywatne">
						<div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
							<div><label><strong>Imię i nazwisko *</strong></label><input type="text" name="imie_nazwisko" value="${dane.imie && dane.nazwisko ? dane.imie + ' ' + dane.nazwisko : ''}" required style="width:100%; padding:8px; margin-top:5px;" placeholder="Jan Kowalski"></div>
							<div><label><strong>Email</strong></label><input type="email" name="email" value="${dane.email || ''}" style="width:100%; padding:8px; margin-top:5px;" placeholder="jan.kowalski@email.com"></div>
							<div><label><strong>Rok urodzenia *</strong></label><input type="number" name="rok_urodzenia" value="${dane.rok_urodzenia || ''}" min="1920" max="2020" required style="width:100%; padding:8px; margin-top:5px;"></div>
							<div><label><strong>Telefon *</strong></label><input type="tel" name="telefon" value="${dane.telefon || ''}" required style="width:100%; padding:8px; margin-top:5px;"></div>
							<div><label><strong>Sprawność fizyczna *</strong></label>
								<select name="sprawnosc_fizyczna" required style="width:100%; padding:8px; margin-top:5px;">
									<option value="">Wybierz...</option>
									<option value="zdolnosc_do_marszu" ${dane.sprawnosc_fizyczna === 'zdolnosc_do_marszu' ? 'selected' : ''}>Zdolność do marszu</option>
									<option value="zdolnosc_do_biegu" ${dane.sprawnosc_fizyczna === 'zdolnosc_do_biegu' ? 'selected' : ''}>Zdolność do biegu</option>
									<option value="sprinter" ${dane.sprawnosc_fizyczna === 'sprinter' ? 'selected' : ''}>Sprinter!</option>
								</select>
							</div>
							<div><label><strong>Kategoria wagowa *</strong></label>
								<select name="kategoria_wagowa" required style="width:100%; padding:8px; margin-top:5px;">
									<option value="">Wybierz...</option>
									${['25-40kg', '41-60kg', '61-90kg', '91-120kg', '120kg+'].map(opcja => 
										`<option value="${opcja}" ${dane.kategoria_wagowa === opcja ? 'selected' : ''}>${opcja}</option>`
									).join('')}
								</select>
							</div>
						</div>
						<div style="margin-bottom:20px;">
							<label><strong>Dodatkowe uwagi</strong></label>
							<textarea name="uwagi" rows="3" style="width:100%; padding:8px; margin-top:5px;">${dane.uwagi || ''}</textarea>
						</div>
					</form>
				</div>` : ''}
				<div style="text-align:right; border-top:1px solid #ddd; padding-top:15px; margin-top: 20px;">
					${moznaEdytowac ? `
						<button id="srl-btn-edytuj" class="button button-primary" style="margin-right:10px;">Edytuj dane</button>
						<button id="srl-btn-zapisz" class="button button-primary" style="display:none; margin-right:10px;">Zapisz zmiany</button>
						<button id="srl-btn-anuluj-edycje" class="button" style="display:none; margin-right:10px;">Anuluj</button>
					` : ''}
					<button class="button srl-btn-zamknij">Zamknij</button>
				</div>
			</div>
		</div>`);

        $('body').append(modal);
		dodajObslugeEscModal(modal, 'dane-pasazera');
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

        $.post(ajaxurl, {
            action: 'srl_zapisz_dane_prywatne',
            termin_id: terminId,
            nonce: srlAdmin.nonce,
            ...formDataObj
        }, function(response) {
            if (response.success) {
                modal.remove();
                if (response.data && response.data.godziny_wg_pilota) {
                    srlIstniejaceGodziny = response.data.godziny_wg_pilota;
                    generujTabelePilotow();
                } else {
                    generujTabelePilotow();
                }
                pokazKomunikatSukcesu('Dane zostały zaktualizowane!');
            } else {
                alert('Błąd: ' + response.data);
            }
        });
    }

    function pokazKomunikatSukcesu(tekst) {
        var successMsg = $(`<div style="position:fixed; top:20px; right:20px; background:#46b450; color:white; padding:15px 20px; border-radius:4px; z-index:9999; font-weight:bold;">✅ ${tekst}</div>`);
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
                if (s.id == terminId) slot = s;
            });
        });

        if (slot && slot.notatka) {
            try {
                var daneOdwolane = JSON.parse(slot.notatka);
                pokazUjednoliconyModalDanych(daneOdwolane, '🚫 Lot odwołany przez organizatora', false);
            } catch (e) {
                alert('Błąd odczytu danych odwołanego lotu.');
            }
        } else {
            alert('Brak danych pasażera dla odwołanego lotu.');
        }
    }

    function pokazFormularzPrzypisaniaKlienta(terminId) {
        var modal = $(`<div class="srl-modal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:30px; border-radius:8px; width:500px; max-width:90%; max-height:80%; overflow-y:auto;">
                <h3>Przypisz klienta do slotu</h3>
                <div style="margin-bottom:20px;">
                    <label>Wyszukaj klienta:</label>
                    <input type="text" id="srl-search-client" placeholder="Email, telefon, imię, nazwisko lub ID lotu..." style="width:100%; padding:8px; margin-top:5px;">
                </div>
                <div id="srl-search-results" style="max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:10px; display:none;"></div>
                <div style="margin-top:20px; text-align:right;">
                    <button class="button" onclick="$(this).closest('.srl-modal').remove();">Anuluj</button>
                </div>
            </div>
        </div>`);

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

    function szukajDostepnychKlientow(query, terminId) {
        $.post(ajaxurl, {
            action: 'srl_wyszukaj_dostepnych_klientow',
            query: query
        }, function(response) {
            if (response.success && response.data.length > 0) {
                var html = '<h4>Znalezieni klienci z dostępnymi lotami:</h4>';
                response.data.forEach(function(klient) {
                    html += `<div style="border:1px solid #ddd; padding:10px; margin:5px 0; cursor:pointer;" onclick="przypisKlienta(${terminId}, ${klient.lot_id}, '${klient.nazwa}')">
                        <strong>${klient.nazwa}</strong><br>
                        <small>Lot #${klient.lot_id} - ${klient.produkt}</small>
                    </div>`;
                });
                $('#srl-search-results').html(html).show();
            } else {
                $('#srl-search-results').html('<p>Brak wyników</p>').show();
            }
        });
    }

    function przypisKlienta(terminId, lotId, nazwa) {
        if (!confirm(`Czy na pewno przypisać klienta "${nazwa}" do tego slotu?`)) return;

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

    function pokazModalZmianyTerminu(terminId) {
        var modal = $(`<div class="srl-modal-zmiana-terminu" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:30px; border-radius:8px; width:90%; max-width:95%; height:85%; overflow-y:auto;">
                <div id="srl-zmiana-terminu-loader" style="text-align:center; padding:40px;">
                    <div style="font-size:16px; margin-bottom:20px;">⏳ Ładowanie dostępnych terminów...</div>
                </div>
                <div id="srl-zmiana-terminu-content" style="display:none;">
                    <div id="srl-zmiana-terminu-tabela"></div>
                </div>
                <div style="margin-top:20px; text-align:right; border-top:1px solid #ddd; padding-top:15px;">
                    <button class="button srl-modal-anuluj-zmiane">Anuluj</button>
                </div>
            </div>
        </div>`);

        $('body').append(modal);
		dodajObslugeEscModal(modal, 'zmiana-terminu');

		modal.find('.srl-modal-anuluj-zmiane').on('click', function() {
			modal.remove();
		});

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
                modal.find('#srl-zmiana-terminu-loader').html(`<p style="color:#d63638;">Błąd: ${response.data}</p>`);
            }
        });
    }

    function wygenerujTabeleDostepnychTerminow(dane, terminId, modal) {
        if (!dane.dostepne_dni || Object.keys(dane.dostepne_dni).length === 0) {
            modal.find('#srl-zmiana-terminu-loader').html('<p style="color:#666; text-align:center; padding:40px;">Brak dostępnych terminów do zmiany.</p>');
            return;
        }

        var sortedDates = Object.keys(dane.dostepne_dni).sort();
        var html = `<div class="srl-terminy-tabela-container" style="overflow-y:auto; border:1px solid #ddd; border-radius:6px;">
            <table class="srl-terminy-tabela" style="width:100%; border-collapse:collapse;">
                <thead style="position:sticky; top:0; background:#f8f9fa; z-index:10;">
                    <tr>
                        <th style="padding:12px; border-bottom:2px solid #dee2e6; text-align:left; font-weight:600; width:1%; white-space:nowrap;">Data</th>
                        <th style="padding:12px; border-bottom:2px solid #dee2e6; text-align:left; font-weight:600; width:99%;">ℹ️ Zmiana terminu dla: <span style="font-weight:bold;">${dane.aktualny_termin.klient_nazwa}</span> - Aktualny termin: ${formatujDatePolski(dane.aktualny_termin.data)} ${dane.aktualny_termin.godzina_start.substring(0,5)}-${dane.aktualny_termin.godzina_koniec.substring(0,5)} (Pilot ${dane.aktualny_termin.pilot_id})</th>
                    </tr>
                </thead>
                <tbody>`;

        sortedDates.forEach(function(data) {
            var terminy = dane.dostepne_dni[data];
            if (terminy.length === 0) return;

            html += `<tr>
                <td style="padding:15px; border-bottom:1px solid #eee; vertical-align:top; width:1%; white-space:nowrap;">
                    <div style="font-weight:600; color:#333;">${formatujDatePolski(data)}</div>
                    <div style="font-size:12px; color:#666;">${formatujDzienTygodnia(data)}</div>
                </td>
                <td style="padding:15px; border-bottom:1px solid #eee; width:99%;">
                    <div class="srl-terminy-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); gap:6px;">`;

            terminy.forEach(function(termin) {
                html += `<div class="srl-termin-opcja" data-termin-id="${termin.id}" data-oryginalny-termin="${terminId}" style="border:1px solid #ddd; padding:8px; border-radius:6px; cursor:pointer; background:white; transition:all 0.2s; text-align:center;">
                    <div style="font-weight:600; color:#4263be;">${termin.godzina_start.substring(0,5)} - ${termin.godzina_koniec.substring(0,5)}</div>
                </div>`;
            });

            html += '</div></td></tr>';
        });

        html += '</tbody></table></div>';

        modal.find('#srl-zmiana-terminu-content').html(html);
        modal.find('#srl-zmiana-terminu-loader').hide();
        modal.find('#srl-zmiana-terminu-content').show();

        modal.find('.srl-termin-opcja').on('click', function() {
            var nowyTerminId = $(this).data('termin-id');
            var oryginalnyTerminId = $(this).data('oryginalny-termin');
            
            modal.find('.srl-termin-opcja').removeClass('srl-termin-wybrany');
            $(this).addClass('srl-termin-wybrany');
            
            var terminInfo = $(this).find('div:first').text();
            
            if (confirm(`Czy na pewno zmienić termin na: ${terminInfo}?`)) {
                wykonajZmianeTerminu(oryginalnyTerminId, nowyTerminId, modal);
            } else {
                $(this).removeClass('srl-termin-wybrany');
            }
        });

        modal.find('.srl-termin-opcja').hover(
            function() {
                if (!$(this).hasClass('srl-termin-wybrany')) {
                    $(this).css({
                        'background': '#f0f8ff',
                        'border-color': '#4263be',
                        'transform': 'scale(1.02)'
                    });
                }
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
		modal.find('.srl-terminy-tabela-container').css('opacity', '0.5');
		modal.find('.srl-termin-opcja').css('pointer-events', 'none');
		
		var loader = $('<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:rgba(255,255,255,0.9); padding:20px; border-radius:6px; text-align:center; z-index:1000;">⏳ Zmienianie terminu...</div>');
		modal.find('#srl-zmiana-terminu-content').css('position', 'relative').append(loader);

		$.post(ajaxurl, {
			action: 'srl_zmien_termin_lotu',
			stary_termin_id: staryTerminId,
			nowy_termin_id: nowyTerminId,
			nonce: srlAdmin.nonce
		}, function(response) {
			if (response.success) {
				modal.remove();
				// POPRAWKA: Aktualizuj dane i odśwież tabelę
				if (response.data && response.data.godziny_wg_pilota) {
					srlIstniejaceGodziny = response.data.godziny_wg_pilota;
				}
				generujTabelePilotow(); // DODANE: Odświeżenie tabeli
				pokazKomunikatSukcesu('Termin lotu został pomyślnie zmieniony!');
			} else {
				loader.remove();
				modal.find('.srl-terminy-tabela-container').css('opacity', '1');
				modal.find('.srl-termin-opcja').css('pointer-events', 'auto');
				modal.find('.srl-termin-wybrany').removeClass('srl-termin-wybrany');
				alert('Błąd zmiany terminu: ' + response.data);
			}
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

    window.przypisKlienta = przypisKlienta;

    $('<style>').prop('type', 'text/css').html(`
        .srl-termin-wybrany {
            background: #e3f2fd !important;
            border-color: #1976d2 !important;
            transform: scale(1.02) !important;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.3) !important;
        }
        .srl-termin-opcja {
            transition: all 0.2s ease !important;
        }
    `).appendTo('head');

});