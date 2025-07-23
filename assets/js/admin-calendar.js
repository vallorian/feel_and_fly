jQuery(document).ready(function($) {
    var aktualnyKrok = 1, maksymalnyKrok = 1;
    var aktualnyMiesiac = new Date().getMonth() + 1, aktualnyRok = new Date().getFullYear();
    var wybranaDana = null, wybranySlot = null, wybranyLot = null;
    var daneKlienta = null, tymczasowaBlokada = null;

    init();

    function init() {
        zaladujDaneKlienta();
        podlaczNasluchy();
    }

    function pokazKrok(nrKroku) {
        if (nrKroku < 1 || nrKroku > 4) return;

        $('.srl-step').removeClass('srl-step-active srl-step-completed');
        for (var i = 1; i <= 4; i++) {
            var step = $('.srl-step[data-step="' + i + '"]');
            if (i < nrKroku) step.addClass('srl-step-completed');
            else if (i === nrKroku) step.addClass('srl-step-active');
        }

        $('.srl-krok').removeClass('srl-krok-active');
        $('#srl-krok-' + nrKroku).addClass('srl-krok-active');
        aktualnyKrok = nrKroku;
        maksymalnyKrok = Math.max(maksymalnyKrok, nrKroku);
        $('html, body').animate({scrollTop: $('#srl-rezerwacja-container').offset().top - 50}, 300);
    }

    function podlaczNasluchy() {
        $('.srl-step').on('click', function() {
            var krok = parseInt($(this).data('step'));
            if (krok <= maksymalnyKrok) pokazKrok(krok);
        });

        $('#srl-formularz-pasazera').on('submit', function(e) {
            e.preventDefault();
            zapiszDanePasazera();
        });

        $('#srl-poprzedni-miesiac').on('click', () => zmienMiesiac(-1));
        $('#srl-nastepny-miesiac').on('click', () => zmienMiesiac(1));
        $('#srl-powrot-krok-1').on('click', () => pokazKrok(1));
        $('#srl-powrot-krok-2').on('click', () => pokazKrok(2));
        $('#srl-powrot-krok-3').on('click', () => pokazKrok(3));
        $('#srl-dalej-krok-4').on('click', () => pokazKrok(4));
        $('#srl-potwierdz-rezerwacje').on('click', dokonajRezerwacji);
    }

    function zaladujDaneKlienta() {
        pokazKomunikat('Ładowanie danych...', 'info');
        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'GET',
            data: {action: 'srl_pobierz_dane_klienta', nonce: srlFrontend.nonce},
            success: function(response) {
                ukryjKomunikat();
                if (response.success) {
                    daneKlienta = response.data;
                    wypelnijDaneKlienta();
                } else {
                    pokazKomunikat('Błąd ładowania danych: ' + response.data, 'error');
                }
            },
            error: () => {
                ukryjKomunikat();
                pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            }
        });
    }

    function wypelnijDaneKlienta() {
        wypelnijListeRezerwacji(daneKlienta.rezerwacje);
        wypelnijDostepneLoty(daneKlienta.dostepne_loty);
        wypelnijFormularzDanych(daneKlienta.dane_osobowe);
        if (daneKlienta.dane_kompletne) {
            $('#srl-formularz-pasazera button[type="submit"]').text('Przejdź dalej →');
        }
    }

    function wypelnijListeRezerwacji(rezerwacje) {
        var container = $('#srl-lista-rezerwacji');
        if (!rezerwacje?.length) {
            container.html('<p class="srl-komunikat srl-komunikat-info">Nie masz aktualnych rezerwacji.</p>');
            return;
        }

        var html = '<table class="srl-tabela"><thead><tr><th>Zamówienie</th><th>Produkt</th><th>Data</th><th>Godzina</th><th>Akcje</th></tr></thead><tbody>';
        rezerwacje.forEach(function(rezerwacja) {
            var dataLotu = new Date(rezerwacja.data + ' ' + rezerwacja.godzina_start);
            var moznaAnulowac = dataLotu.getTime() - Date.now() > 48 * 60 * 60 * 1000;
            
            html += `<tr>
                <td>#${rezerwacja.order_id}</td>
                <td>${escapeHtml(rezerwacja.nazwa_produktu)}</td>
                <td>${formatujDate(rezerwacja.data)}</td>
                <td>${rezerwacja.godzina_start.substring(0, 5)}</td>
                <td>${moznaAnulowac ? 
                    `<button class="srl-btn srl-btn-secondary srl-anuluj-rezerwacje" data-lot-id="${rezerwacja.id}">Odwołaj</button>` : 
                    '<span style="color:#999; font-style:italic;">Nie możesz już dokonać zmian</span>'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.html(html);

        $('.srl-anuluj-rezerwacje').on('click', function() {
            anulujRezerwacje($(this).data('lot-id'));
        });
    }

    function wypelnijDostepneLoty(loty) {
        var container = $('#srl-wybor-lotu');
        if (!loty?.length) {
            container.html('<p class="srl-komunikat srl-komunikat-warning">Nie masz dostępnych lotów do zarezerwowania.</p>');
            return;
        }

        var html = '<div class="srl-form-group"><label for="srl-wybrany-lot">Wybierz lot do zarezerwowania:</label>';
        if (loty.length === 1) {
            var lot = loty[0];
            html += `<div style="padding:15px; background:#f8f9fa; border-radius:8px; font-weight:600;">
                #${lot.order_id} – ${escapeHtml(lot.nazwa_produktu)}
                <input type="hidden" id="srl-wybrany-lot" value="${lot.id}">
            </div>`;
        } else {
            html += '<select id="srl-wybrany-lot" required><option value="">Wybierz lot...</option>';
            loty.forEach(function(lot) {
                html += `<option value="${lot.id}">#${lot.order_id} – ${escapeHtml(lot.nazwa_produktu)}</option>`;
            });
            html += '</select>';
        }
        html += `</div><p style="margin-top:15px; color:#4263be; font-weight:500;">🎫 Ilość lotów do zarezerwowania: ${loty.length}</p>`;
        container.html(html);
    }

    function wypelnijFormularzDanych(dane) {
        $('#srl-imie').val(dane.imie || '');
        $('#srl-nazwisko').val(dane.nazwisko || '');
        $('#srl-rok-urodzenia').val(dane.rok_urodzenia || '');
        $('#srl-plec').val(dane.plec || '');
        $('#srl-waga').val(dane.waga || '');
        $('#srl-wzrost').val(dane.wzrost || '');
        $('#srl-telefon').val(dane.telefon || '');
        $('#srl-uwagi').val(dane.uwagi || '');
    }

    function zapiszDanePasazera() {
        wybranyLot = $('#srl-wybrany-lot').val();
        if (!wybranyLot) {
            pokazKomunikat('Wybierz lot do zarezerwowania.', 'error');
            return;
        }

        var submitBtn = $('#srl-formularz-pasazera button[type="submit"]');
        submitBtn.prop('disabled', true).text('Zapisywanie...');

        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'POST',
            data: {
                action: 'srl_zapisz_dane_pasazera',
                nonce: srlFrontend.nonce,
                imie: $('#srl-imie').val(),
                nazwisko: $('#srl-nazwisko').val(),
                rok_urodzenia: $('#srl-rok-urodzenia').val(),
                plec: $('#srl-plec').val(),
                waga: $('#srl-waga').val(),
                wzrost: $('#srl-wzrost').val(),
                telefon: $('#srl-telefon').val(),
                uwagi: $('#srl-uwagi').val()
            },
            success: function(response) {
                if (response.success) {
                    pokazKrok(2);
                    zaladujKalendarz();
                } else {
                    pokazKomunikat('Błąd: ' + response.data, 'error');
                }
            },
            error: () => pokazKomunikat('Błąd połączenia z serwerem.', 'error'),
            complete: () => submitBtn.prop('disabled', false).text('Zapisz i przejdź dalej →')
        });
    }

    function anulujRezerwacje(lotId) {
        if (!confirm('Czy na pewno chcesz anulować tę rezerwację?')) return;

        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'POST',
            data: {action: 'srl_anuluj_rezerwacje_klient', nonce: srlFrontend.nonce, lot_id: lotId},
            success: function(response) {
                if (response.success) {
                    pokazKomunikat('Rezerwacja została anulowana.', 'success');
                    zaladujDaneKlienta();
                } else {
                    pokazKomunikat('Błąd: ' + response.data, 'error');
                }
            },
            error: () => pokazKomunikat('Błąd połączenia z serwerem.', 'error')
        });
    }

    function zaladujKalendarz() {
        aktualizujNawigacjeKalendarza();
        pobierzDostepneDni();
    }

    function aktualizujNawigacjeKalendarza() {
        var nazwyMiesiecy = ['Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
            'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];
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
            data: {action: 'srl_pobierz_dostepne_dni', nonce: srlFrontend.nonce, rok: aktualnyRok, miesiac: aktualnyMiesiac},
            success: function(response) {
                if (response.success) {
                    wygenerujKalendarz(response.data);
                } else {
                    $('#srl-kalendarz-tabela').html(`<p class="srl-komunikat srl-komunikat-error">Błąd: ${response.data}</p>`);
                }
            },
            error: () => $('#srl-kalendarz-tabela').html('<p class="srl-komunikat srl-komunikat-error">Błąd połączenia z serwerem.</p>')
        });
    }

    function wygenerujKalendarz(dostepneDni) {
        var pierwszyDzienMiesiaca = new Date(aktualnyRok, aktualnyMiesiac - 1, 1);
        var dniWMiesiacu = new Date(aktualnyRok, aktualnyMiesiac, 0).getDate();
        var pierwszyDzienTygodnia = pierwszyDzienMiesiaca.getDay();
        pierwszyDzienTygodnia = pierwszyDzienTygodnia === 0 ? 7 : pierwszyDzienTygodnia;

        var html = '<table class="srl-kalendarz-tabela"><thead><tr><th>Pon</th><th>Wt</th><th>Śr</th><th>Czw</th><th>Pt</th><th>Sob</th><th>Nd</th></tr></thead><tbody>';
        var dzien = 1, pustePrzed = pierwszyDzienTygodnia - 1;
        var calkowiteKomorki = Math.ceil((dniWMiesiacu + pustePrzed) / 7) * 7;

        for (var i = 0; i < calkowiteKomorki; i++) {
            if (i % 7 === 0) html += '<tr>';

            if (i < pustePrzed || dzien > dniWMiesiacu) {
                html += '<td class="srl-dzien-pusty"></td>';
            } else {
                var dataStr = aktualnyRok + '-' + pad2(aktualnyMiesiac) + '-' + pad2(dzien);
                var iloscSlotow = dostepneDni[dataStr] || 0;
                var klasa = iloscSlotow > 0 ? 'srl-dzien-dostepny' : 'srl-dzien-niedostepny';
                var dataAttr = iloscSlotow > 0 ? ` data-data="${dataStr}"` : '';

                html += `<td class="${klasa}"${dataAttr}>
                    <div class="srl-dzien-numer">${dzien}</div>
                    ${iloscSlotow > 0 ? `<div class="srl-dzien-sloty">${iloscSlotow} wolnych</div>` : ''}
                </td>`;
                dzien++;
            }

            if ((i + 1) % 7 === 0) html += '</tr>';
        }
        html += '</tbody></table>';
        $('#srl-kalendarz-tabela').html(html);

        $('.srl-dzien-dostepny').on('click', function() {
            wybranaDana = $(this).data('data');
            $('.srl-dzien-wybrany').removeClass('srl-dzien-wybrany');
            $(this).addClass('srl-dzien-wybrany');
            pokazKrok(3);
            zaladujHarmonogram();
        });
    }

    function zaladujHarmonogram() {
        $('#srl-wybrany-dzien-info').html(`<p><strong>Wybrany dzień:</strong> ${formatujDate(wybranaDana)}</p>`);
        $('#srl-harmonogram-frontend').html('<div class="srl-loader">Ładowanie dostępnych godzin...</div>');

        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'GET',
            data: {action: 'srl_pobierz_dostepne_godziny', nonce: srlFrontend.nonce, data: wybranaDana},
            success: function(response) {
                if (response.success) {
                    wygenerujHarmonogram(response.data);
                } else {
                    $('#srl-harmonogram-frontend').html(`<p class="srl-komunikat srl-komunikat-error">Błąd: ${response.data}</p>`);
                }
            },
            error: () => $('#srl-harmonogram-frontend').html('<p class="srl-komunikat srl-komunikat-error">Błąd połączenia z serwerem.</p>')
        });
    }

    function wygenerujHarmonogram(sloty) {
        if (!sloty?.length) {
            $('#srl-harmonogram-frontend').html('<p class="srl-komunikat srl-komunikat-info">Brak dostępnych godzin w tym dniu.</p>');
            return;
        }

        var html = '<div class="srl-godziny-grid">';
        sloty.forEach(function(slot) {
            html += `<div class="srl-slot-godzina" data-slot-id="${slot.id}">
                <div class="srl-slot-czas">${slot.godzina_start.substring(0, 5)} - ${slot.godzina_koniec.substring(0, 5)}</div>
                <div class="srl-slot-pilot">Pilot ${slot.pilot_id}</div>
            </div>`;
        });
        html += '</div>';
        $('#srl-harmonogram-frontend').html(html);

        $('.srl-slot-godzina').on('click', function() {
            wybierzSlot($(this).data('slot-id'), $(this));
        });
    }

    function wybierzSlot(slotId, element) {
        $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
        element.addClass('srl-slot-wybrany');
        wybranySlot = slotId;
        $('#srl-dalej-krok-4').show();
        zablokujSlotTymczasowo(slotId);
    }

    function zablokujSlotTymczasowo(slotId) {
        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'POST',
            data: {action: 'srl_zablokuj_slot_tymczasowo', nonce: srlFrontend.nonce, termin_id: slotId},
            success: function(response) {
                if (response.success) {
                    tymczasowaBlokada = response.data;
                    pokazKomunikat('Termin został zarezerwowany na 15 minut.', 'info');
                    setTimeout(function() {
                        pokazKomunikat('Blokada terminu wygasła. Wybierz termin ponownie.', 'warning');
                        $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                        $('#srl-dalej-krok-4').hide();
                        wybranySlot = null;
                        tymczasowaBlokada = null;
                    }, 15 * 60 * 1000);
                } else {
                    pokazKomunikat('Błąd blokady: ' + response.data, 'error');
                    $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                    wybranySlot = null;
                    $('#srl-dalej-krok-4').hide();
                }
            },
            error: () => pokazKomunikat('Błąd połączenia z serwerem.', 'error')
        });
    }

    function pokazKrok4() {
        var html = '<div class="srl-podsumowanie-box" style="background:#f8f9fa; padding:30px; border-radius:8px; margin:20px 0;">';
        html += '<h3 style="margin-top:0; color:#4263be;">📋 Podsumowanie rezerwacji</h3>';

        var lot = daneKlienta.dostepne_loty.find(l => l.id == wybranyLot);
        var slotInfo = tymczasowaBlokada?.slot;

        html += '<div class="srl-podsumowanie-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin:20px 0;">';
        html += `<div><strong>🎫 Wybrany lot:</strong><br>#${lot.order_id} – ${escapeHtml(lot.nazwa_produktu)}</div>`;
        html += `<div><strong>📅 Data lotu:</strong><br>${formatujDate(wybranaDana)}</div>`;

        if (slotInfo) {
            html += `<div><strong>⏰ Godzina:</strong><br>${slotInfo.godzina_start.substring(0, 5)} - ${slotInfo.godzina_koniec.substring(0, 5)}</div>`;
            html += `<div><strong>👨‍✈️ Pilot:</strong><br>Pilot ${slotInfo.pilot_id}</div>`;
        }
        html += '</div>';

        html += '<div style="background:white; padding:20px; border-radius:8px; margin-top:20px;">';
        html += '<h4 style="margin-top:0; color:#333;">🪪 Dane pasażera:</h4>';
        html += `<p><strong>Imię i nazwisko:</strong> ${$('#srl-imie').val()} ${$('#srl-nazwisko').val()}</p>`;
        html += `<p><strong>Wiek:</strong> ${new Date().getFullYear() - $('#srl-rok-urodzenia').val()} lat</p>`;
        html += `<p><strong>Waga:</strong> ${$('#srl-waga').val()} kg</p>`;
        html += `<p><strong>Wzrost:</strong> ${$('#srl-wzrost').val()} cm</p>`;
        html += `<p><strong>Telefon:</strong> ${$('#srl-telefon').val()}</p>`;

        var uwagi = $('#srl-uwagi').val();
        if (uwagi) html += `<p><strong>Uwagi:</strong> ${escapeHtml(uwagi)}</p>`;
        html += '</div>';

        html += '<div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:20px;">';
        html += '<h4 style="margin-top:0; color:#f57c00;">⚠️ Ważne informacje:</h4>';
        html += '<ul style="margin:0; padding-left:20px;">';
        html += '<li>Zgłoś się 30 minut przed godziną lotu</li>';
        html += '<li>Weź ze sobą dokument tożsamości</li>';
        html += '<li>Ubierz się stosownie do warunków pogodowych</li>';
        html += '<li>Rezerwację można anulować do 48h przed lotem</li>';
        html += '</ul></div></div>';

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
            data: {action: 'srl_dokonaj_rezerwacji', nonce: srlFrontend.nonce, termin_id: wybranySlot, lot_id: wybranyLot},
            success: function(response) {
                if (response.success) {
                    pokazKomunikatSukcesu();
                } else {
                    pokazKomunikat('Błąd rezerwacji: ' + response.data, 'error');
                    btn.prop('disabled', false).text('🎯 Potwierdź rezerwację');
                }
            },
            error: () => {
                pokazKomunikat('Błąd połączenia z serwerem.', 'error');
                btn.prop('disabled', false).text('🎯 Potwierdź rezerwację');
            }
        });
    }

    function pokazKomunikatSukcesu() {
        var html = '<div class="srl-komunikat srl-komunikat-success" style="text-align:center; padding:40px;">';
        html += '<h2 style="color:#46b450; margin-bottom:20px;">🎉 Rezerwacja potwierdzona!</h2>';
        html += `<p style="font-size:18px; margin-bottom:30px;">Twój lot tandemowy został zarezerwowany na <strong>${formatujDate(wybranaDana)}</strong></p>`;
        html += '<p>Na podany adres email została wysłana informacja z szczegółami rezerwacji.</p>';
        html += '<div style="margin-top:30px;">';
        html += `<a href="${window.location.href}" class="srl-btn srl-btn-primary">Zarezerwuj kolejny lot</a>`;
        html += '</div></div>';
        $('#srl-rezerwacja-container').html(html);
    }

    function pokazKomunikat(tekst, typ) {
        var html = `<div class="srl-komunikat srl-komunikat-${typ}">${tekst}</div>`;
        $('#srl-komunikaty').html(html);
        setTimeout(() => $('#srl-komunikaty').fadeOut(), 5000);
    }

    function ukryjKomunikat() {
        $('#srl-komunikaty').empty();
    }

    function formatujDate(dataStr) {
        var data = new Date(dataStr);
        var nazwyDni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
        var nazwyMiesiecy = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 
                           'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
        return nazwyDni[data.getDay()] + ', ' + data.getDate() + ' ' + nazwyMiesiecy[data.getMonth()] + ' ' + data.getFullYear();
    }

    function pad2(n) {
        return (n < 10) ? '0' + n : '' + n;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }

    var originalPokaz = pokazKrok;
    pokazKrok = function(nrKroku) {
        originalPokaz(nrKroku);
        if (nrKroku === 4) pokazKrok4();
    };
});

$(document).ready(function() {
    $('#srl-voucher-show').on('click', function() {
        $('#srl-voucher-show').hide();
        $('#srl-voucher-form').show();
        $('#srl-voucher-code').focus();
    });

    $('#srl-voucher-cancel').on('click', function() {
        $('#srl-voucher-form').hide();
        $('#srl-voucher-show').show();
        $('#srl-voucher-code').val('');
    });

    $('#srl-voucher-code').on('input', function() {
        var value = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '');
        $(this).val(value);
    });

    $('#srl-voucher-submit').on('click', function() {
        var kod = $('#srl-voucher-code').val().trim();
        if (kod.length < 1) {
            pokazKomunikat('Wprowadź kod vouchera.', 'error');
            return;
        }

        var button = $(this);
        button.prop('disabled', true).text('Sprawdzanie...');

        $.ajax({
            url: srlFrontend.ajaxurl,
            method: 'POST',
            data: {action: 'srl_wykorzystaj_voucher', kod_vouchera: kod, nonce: srlFrontend.nonce},
            success: function(response) {
                if (response.success) {
                    pokazKomunikat(response.data.message, 'success');
                    setTimeout(function() {
                        zaladujDaneKlienta();
                        $('#srl-voucher-form').hide();
                        $('#srl-voucher-show').show();
                        $('#srl-voucher-code').val('');
                    }, 2000);
                } else {
                    pokazKomunikat(response.data, 'error');
                }
            },
            error: () => pokazKomunikat('Błąd połączenia z serwerem.', 'error'),
            complete: () => button.prop('disabled', false).text('Zatwierdź voucher')
        });
    });
});