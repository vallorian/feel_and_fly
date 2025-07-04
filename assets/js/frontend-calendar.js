// PLIK: assets/js/frontend-calendar.js
// ZASTĄP całą zawartość pliku na:

window.SRLOpcje = window.SRLOpcje || {};

jQuery(document).ready(function($) {
    const SRL = {
        state: {
            aktualnyKrok: 1,
            maksymalnyKrok: 1,
            aktualnyMiesiac: new Date().getMonth() + 1,
            aktualnyRok: new Date().getFullYear(),
            wybranaDana: null,
            wybranySlot: null,
            wybranyLot: null,
            daneKlienta: null,
            tymczasowaBlokada: null,
            requestCache: new Map(),
            debounceTimers: new Map()
        },

        config: {
            ajaxUrl: srlFrontend.ajaxurl,
            nonce: srlFrontend.nonce,
            productIds: srlFrontend.productIds,
            cacheTimeout: 300000,
            debounceDelay: 300
        },

        cache: {
            set: function(key, data) {
                SRL.state.requestCache.set(key, {
                    data: data,
                    timestamp: Date.now()
                });
            },
            
            get: function(key) {
                const cached = SRL.state.requestCache.get(key);
                if (!cached) return null;
                
                if (Date.now() - cached.timestamp > SRL.config.cacheTimeout) {
                    SRL.state.requestCache.delete(key);
                    return null;
                }
                
                return cached.data;
            },
            
            clear: function() {
                SRL.state.requestCache.clear();
            }
        },

        ajax: function(action, data = {}, options = {}) {
            const defaults = {
                method: 'POST',
                showLoader: true,
                useCache: true,
                successCallback: null,
                errorCallback: null
            };
            const opts = {...defaults, ...options};
            
            const cacheKey = opts.useCache ? `${action}_${JSON.stringify(data)}` : null;
            
            if (cacheKey && opts.useCache) {
                const cached = SRL.cache.get(cacheKey);
                if (cached) {
                    if (opts.successCallback) opts.successCallback(cached);
                    return Promise.resolve(cached);
                }
            }
            
            const ajaxData = {
                action: action,
                nonce: SRL.config.nonce,
                ...data
            };

            if (opts.showLoader && opts.loadingElement) {
                $(opts.loadingElement).prop('disabled', true).text(opts.loadingText || 'Ładowanie...');
            }

            return $.ajax({
                url: SRL.config.ajaxUrl,
                method: opts.method,
                data: ajaxData
            }).done(response => {
                if (response.success) {
                    if (cacheKey && opts.useCache) {
                        SRL.cache.set(cacheKey, response.data);
                    }
                    if (opts.successCallback) opts.successCallback(response.data);
                } else {
                    SRL.showMessage(response.data || 'Wystąpił błąd', 'error');
                    if (opts.errorCallback) opts.errorCallback(response.data);
                }
            }).fail(() => {
                SRL.showMessage('Błąd połączenia z serwerem', 'error');
                if (opts.errorCallback) opts.errorCallback('Błąd połączenia');
            }).always(() => {
                if (opts.showLoader && opts.loadingElement) {
                    $(opts.loadingElement).prop('disabled', false).text(opts.originalText);
                }
            });
        },

        debounce: function(func, key, delay = SRL.config.debounceDelay) {
            clearTimeout(SRL.state.debounceTimers.get(key));
            SRL.state.debounceTimers.set(key, setTimeout(func, delay));
        },

        showMessage: function(text, type = 'info') {
            const classes = {
                info: 'srl-komunikat-info',
                success: 'srl-komunikat-success',
                warning: 'srl-komunikat-warning',
                error: 'srl-komunikat-error'
            };
            
            const html = `<div class="srl-komunikat ${classes[type]}">${text}</div>`;
            let $komunikaty = $('#srl-komunikaty');
            
            if ($komunikaty.length === 0) {
                $('#srl-formularz-pasazera').prepend('<div id="srl-komunikaty"></div>');
                $komunikaty = $('#srl-komunikaty');
            }
            
            $komunikaty.append(html).show();
            setTimeout(() => $komunikaty.fadeOut(() => $komunikaty.empty()), 15000);
        },

        hideMessages: function() {
            $('#srl-komunikaty').empty().hide();
        },

        validation: {
            validateAge: function(rok) {
                if (!rok) return SRL.hideValidationMessage('#srl-wiek-ostrzezenie');
                
                SRL.debounce(() => {
                    SRL.ajax('srl_waliduj_wiek', { rok_urodzenia: rok }, {
                        showLoader: false,
                        useCache: true,
                        successCallback: data => {
                            if (data.html) SRL.showValidationMessage('#srl-wiek-ostrzezenie', data.html);
                            else SRL.hideValidationMessage('#srl-wiek-ostrzezenie');
                        }
                    });
                }, 'validate_age');
            },

            validateWeight: function(kategoria) {
                if (!kategoria) return SRL.hideValidationMessage('#srl-waga-ostrzezenie');
                
                SRL.debounce(() => {
                    SRL.ajax('srl_waliduj_kategorie_wagowa', { kategoria_wagowa: kategoria }, {
                        showLoader: false,
                        useCache: true,
                        successCallback: data => {
                            if (data.html) SRL.showValidationMessage('#srl-waga-ostrzezenie', data.html);
                            else SRL.hideValidationMessage('#srl-waga-ostrzezenie');
                        }
                    });
                }, 'validate_weight');
            },

            checkCompatibility: function() {
                const kategoria = $('#srl-kategoria-wagowa').val();
                const lot = SRL.state.daneKlienta?.dostepne_loty?.find(l => l.id == SRL.state.wybranyLot);
                
                if (!lot || !kategoria) return true;
                
                const czyAkrobatyczny = lot.nazwa_produktu.toLowerCase().includes('akrobacj') || lot.ma_akrobacje == '1';
                return !(czyAkrobatyczny && ['91-120kg', '120kg+'].includes(kategoria)) && kategoria !== '120kg+';
            }
        },

        showValidationMessage: function(selector, html) {
            let $container = $(selector);
            if ($container.length === 0) {
                $container = $(`<div id="${selector.slice(1)}"></div>`);
                $('#srl-waga-ostrzezenie').before($container);
            }
            $container.html(html).show();
        },

        hideValidationMessage: function(selector) {
            $(selector).hide();
        },

        steps: {
            show: function(nrKroku) {
                if (nrKroku < 1 || nrKroku > 5) return;

                $('.srl-step').removeClass('srl-step-active srl-step-completed');
                $('.srl-progress-bar').removeClass('srl-progress-1 srl-progress-2 srl-progress-3 srl-progress-4 srl-progress-5').addClass(`srl-progress-${nrKroku}`);

                for (let i = 1; i <= 5; i++) {
                    const $step = $(`.srl-step[data-step="${i}"]`);
                    if (i < nrKroku) $step.addClass('srl-step-completed');
                    else if (i === nrKroku) $step.addClass('srl-step-active');
                }

                $('.srl-krok').removeClass('srl-krok-active');
                $(`#srl-krok-${nrKroku}`).addClass('srl-krok-active');
                
                SRL.state.aktualnyKrok = nrKroku;
                SRL.state.maksymalnyKrok = Math.max(SRL.state.maksymalnyKrok, nrKroku);
                
                $('html, body').animate({scrollTop: $('#srl-rezerwacja-container').offset().top - 50}, 300);
                
                if (nrKroku === 5) SRL.steps.setupStep5();
            },

            setupStep5: function() {
                const lot = SRL.state.daneKlienta?.dostepne_loty?.find(l => l.id == SRL.state.wybranyLot);
                const slotInfo = SRL.state.tymczasowaBlokada?.slot;

                if (lot) {
                    const nazwaBezWariantu = lot.nazwa_produktu.split(' - ')[0];
                    const finalName = ['voucher', 'lot', 'tandem'].some(word => nazwaBezWariantu.toLowerCase().includes(word)) ? 'Lot w tandemie' : nazwaBezWariantu;
                    
                    const opcje = [
                        lot.ma_filmowanie && lot.ma_filmowanie != '0' ? '<span style="color: #46b450;">z filmowaniem</span>' : '<span style="color: #d63638;">brak filmowania</span>',
                        lot.ma_akrobacje && lot.ma_akrobacje != '0' ? '<span style="color: #46b450;">z akrobacjami</span>' : '<span style="color: #d63638;">brak akrobacji</span>'
                    ];

                    $('#srl-lot-details').html(`#${lot.id} – ${SRL.utils.escapeHtml(finalName)} <span style="font-weight: bold;">${opcje.join(', ')}</span>`);
                }

                if (slotInfo) {
                    const dataGodzina = `${SRL.utils.formatDate(SRL.state.wybranaDana)}, godz. ${slotInfo.godzina_start.substring(0, 5)} - ${slotInfo.godzina_koniec.substring(0, 5)}`;
                    $('#srl-datetime-details').html(dataGodzina);
                }

                SRL.steps.generatePassengerSummary();
            },

            generatePassengerSummary: function() {
                const formData = SRL.utils.getFormData('#srl-formularz-pasazera');
                let html = `
                    <p><strong>Imię i nazwisko:</strong> ${formData.imie} ${formData.nazwisko}</p>
                    <p><strong>Rok urodzenia:</strong> ${formData.rok_urodzenia}</p>
                    <p><strong>Wiek:</strong> ${new Date().getFullYear() - formData.rok_urodzenia} lat</p>
                    <p><strong>Telefon:</strong> ${formData.telefon}</p>
                    <p><strong>Sprawność fizyczna:</strong> ${$('#srl-sprawnosc-fizyczna option:selected').text()}</p>
                    <p><strong>Kategoria wagowa:</strong> ${formData.kategoria_wagowa}</p>
                `;

                const wiek = new Date().getFullYear() - formData.rok_urodzenia;
                if (wiek <= 18) {
                    html += '<div class="srl-uwaga-warning" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:10px; color:#000;"><strong>Uwaga:</strong> Lot osoby niepełnoletniej: Osoby poniżej 18. roku życia mogą wziąć udział w locie tylko za zgodą rodzica lub opiekuna prawnego. Wymagane jest okazanie podpisanej, wydrukowanej zgody w dniu lotu, na miejscu startu. <a href="/zgoda-na-lot-osoba-nieletnia/" target="_blank" style="color:#f57c00; font-weight:bold;">Pobierz zgodę tutaj</a></div>';
                }

                if (formData.kategoria_wagowa === '91-120kg') {
                    html += '<div class="srl-uwaga-warning" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:10px; color:#000;"><strong>Uwaga:</strong> Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji. Pilot ma prawo odmówić wykonania lotu jeśli uzna, że zagraża to bezpieczeństwu.</div>';
                } else if (formData.kategoria_wagowa === '120kg+') {
                    html += '<div class="srl-uwaga-error" style="background:#fdeaea; border:2px solid #d63638; border-radius:8px; padding:20px; margin-top:10px; color:#721c24;"><strong>❌ Błąd:</strong> Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.</div>';
                }

                $('#srl-dane-pasazera-podsumowanie').html(html);
            }
        },

        data: {
            loadClientData: function() {
                SRL.showMessage('Ładowanie danych...', 'info');

                SRL.ajax('srl_pobierz_dane_klienta', {}, {
                    showLoader: false,
                    useCache: true,
                    successCallback: data => {
                        SRL.hideMessages();
                        SRL.state.daneKlienta = data;
                        window.daneKlienta = data;
                        SRL.data.populateClientData();
                        $(document).trigger('srl_dane_klienta_zaladowane');
                    },
                    errorCallback: error => {
                        SRL.hideMessages();
                        SRL.showMessage('Błąd ładowania danych: ' + error, 'error');
                    }
                });
            },

            populateClientData: function() {
                SRL.ui.updateGreeting();
                const combined = [
                    ...(SRL.state.daneKlienta.rezerwacje || []),
                    ...(SRL.state.daneKlienta.dostepne_loty || [])
                ].sort((a, b) => a.status === 'zarezerwowany' && b.status !== 'zarezerwowany' ? -1 : 
                                 a.status !== 'zarezerwowany' && b.status === 'zarezerwowany' ? 1 : 0);

                SRL.ui.populateFlightList(combined);
                SRL.data.fillForm(SRL.state.daneKlienta.dane_osobowe);
            },

            fillForm: function(dane) {
                const fields = ['imie', 'nazwisko', 'rok_urodzenia', 'kategoria_wagowa', 'sprawnosc_fizyczna', 'telefon', 'uwagi'];
                fields.forEach(field => $(`#srl-${field.replace('_', '-')}`).val(dane[field] || ''));

                if (dane.rok_urodzenia) SRL.validation.validateAge(dane.rok_urodzenia);
                if (dane.kategoria_wagowa) SRL.validation.validateWeight(dane.kategoria_wagowa);
            },

            savePassengerData: function() {
                if (!SRL.state.wybranyLot && !window.wybranyLot) {
                    SRL.showMessage('Błąd: Nie wybrano lotu do rezerwacji. Wróć do kroku 1.', 'error');
                    return;
                }

                SRL.hideMessages();
                const errors = [];

                if (!$('#srl-akceptacja-regulaminu').is(':checked')) {
                    errors.push('Musisz zaakceptować Regulamin.');
                }

                if ($('#srl-kategoria-wagowa').val() === '120kg+') {
                    errors.push('Nie można dokonać rezerwacji z kategorią wagową 120kg+');
                }

                const telefon = $('#srl-telefon').val().trim();
                if (telefon && telefon.replace(/[\s\-\(\)\+48]/g, '').length < 9) {
                    errors.push('Numer telefonu musi mieć minimum 9 cyfr.');
                }

                if (!SRL.validation.checkCompatibility()) {
                    errors.push('Wybrana kategoria wagowa nie jest dostępna dla lotów z akrobacjami.');
                }

                if (errors.length > 0) {
                    errors.forEach(error => SRL.showMessage(error, 'error'));
                    return;
                }

                const formData = SRL.utils.getFormData('#srl-formularz-pasazera');
                formData.akceptacja_regulaminu = $('#srl-akceptacja-regulaminu').is(':checked');

                const $submitBtn = $('#srl-formularz-pasazera button[type="submit"]');
                
                SRL.ajax('srl_zapisz_dane_pasazera', formData, {
                    loadingElement: $submitBtn,
                    originalText: 'Zapisz i przejdź dalej →',
                    loadingText: 'Zapisywanie...',
                    useCache: false,
                    successCallback: () => {
                        Object.assign(SRL.state.daneKlienta.dane_osobowe, formData);
                        SRL.cache.clear();
                        SRL.steps.show(3);
                        SRL.calendar.load();
                    }
                });
            }
        },

        calendar: {
            load: function() {
                SRL.calendar.updateNavigation();
                SRL.calendar.fetchAvailableDays();
            },

            updateNavigation: function() {
                const months = ['Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];
                $('#srl-miesiac-rok').text(`${months[SRL.state.aktualnyMiesiac - 1]} ${SRL.state.aktualnyRok}`);
            },

            changeMonth: function(direction) {
                SRL.state.aktualnyMiesiac += direction;
                if (SRL.state.aktualnyMiesiac > 12) {
                    SRL.state.aktualnyMiesiac = 1;
                    SRL.state.aktualnyRok++;
                } else if (SRL.state.aktualnyMiesiac < 1) {
                    SRL.state.aktualnyMiesiac = 12;
                    SRL.state.aktualnyRok--;
                }
                SRL.calendar.updateNavigation();
                SRL.calendar.fetchAvailableDays();
            },

            fetchAvailableDays: function() {
                $('#srl-kalendarz-tabela').html('<div class="srl-loader">Ładowanie dostępnych terminów...</div>');

                SRL.ajax('srl_pobierz_dostepne_dni', {
                    rok: SRL.state.aktualnyRok,
                    miesiac: SRL.state.aktualnyMiesiac
                }, {
                    method: 'GET',
                    showLoader: false,
                    useCache: true,
                    successCallback: data => SRL.calendar.generate(data),
                    errorCallback: error => $('#srl-kalendarz-tabela').html(`<p class="srl-komunikat srl-komunikat-error">Błąd: ${error}</p>`)
                });
            },

            generate: function(dostepneDni) {
                const firstDay = new Date(SRL.state.aktualnyRok, SRL.state.aktualnyMiesiac - 1, 1);
                const daysInMonth = new Date(SRL.state.aktualnyRok, SRL.state.aktualnyMiesiac, 0).getDate();
                const firstDayOfWeek = firstDay.getDay() === 0 ? 7 : firstDay.getDay();

                let html = '<table class="srl-kalendarz-tabela"><thead><tr><th>Pon</th><th>Wt</th><th>Śr</th><th>Czw</th><th>Pt</th><th>Sob</th><th>Nd</th></tr></thead><tbody>';
                let day = 1;
                const emptyBefore = firstDayOfWeek - 1;
                const totalCells = Math.ceil((daysInMonth + emptyBefore) / 7) * 7;

                for (let i = 0; i < totalCells; i++) {
                    if (i % 7 === 0) html += '<tr>';

                    if (i < emptyBefore || day > daysInMonth) {
                        html += '<td class="srl-dzien-pusty"></td>';
                    } else {
                        const dateStr = `${SRL.state.aktualnyRok}-${SRL.utils.pad(SRL.state.aktualnyMiesiac)}-${SRL.utils.pad(day)}`;
                        const slotsCount = dostepneDni[dateStr] || 0;
                        const cssClass = slotsCount > 0 ? 'srl-dzien-dostepny' : 'srl-dzien-niedostepny';
                        const dataAttr = slotsCount > 0 ? ` data-data="${dateStr}"` : '';

                        html += `<td class="${cssClass}"${dataAttr}>`;
                        html += `<div class="srl-dzien-numer">${day}</div>`;
                        if (slotsCount > 0) {
                            html += `<div class="srl-dzien-sloty">${slotsCount} wolnych</div>`;
                        }
                        html += '</td>';
                        day++;
                    }

                    if ((i + 1) % 7 === 0) html += '</tr>';
                }

                html += '</tbody></table>';
                $('#srl-kalendarz-tabela').html(html);

                $('.srl-dzien-dostepny').on('click', function() {
                    SRL.state.wybranaDana = $(this).data('data');
                    $('.srl-dzien-wybrany').removeClass('srl-dzien-wybrany');
                    $(this).addClass('srl-dzien-wybrany');
                    SRL.steps.show(4);
                    SRL.schedule.load();
                });
            }
        },

        schedule: {
            load: function() {
                $('#srl-wybrany-dzien-info').html(`<p><strong>Wybrany dzień:</strong> ${SRL.utils.formatDate(SRL.state.wybranaDana)}</p>`);
                $('#srl-harmonogram-frontend').html('<div class="srl-loader">Ładowanie dostępnych godzin...</div>');

                SRL.ajax('srl_pobierz_dostepne_godziny', { data: SRL.state.wybranaDana }, {
                    method: 'GET',
                    showLoader: false,
                    useCache: true,
                    successCallback: data => SRL.schedule.generate(data),
                    errorCallback: error => $('#srl-harmonogram-frontend').html(`<p class="srl-komunikat srl-komunikat-error">Błąd: ${error}</p>`)
                });
            },

            generate: function(sloty) {
                if (!sloty || sloty.length === 0) {
                    $('#srl-harmonogram-frontend').html('<p class="srl-komunikat srl-komunikat-info">Brak dostępnych godzin w tym dniu.</p>');
                    return;
                }

                sloty.sort((a, b) => a.godzina_start.localeCompare(b.godzina_start));

                let html = '<div class="srl-godziny-grid">';
                sloty.forEach(slot => {
                    html += `<div class="srl-slot-godzina" data-slot-id="${slot.id}">`;
                    html += `<div class="srl-slot-czas">${slot.godzina_start.substring(0, 5)} - ${slot.godzina_koniec.substring(0, 5)}</div>`;
                    html += '</div>';
                });
                html += '</div>';
                $('#srl-harmonogram-frontend').html(html);

                $('.srl-slot-godzina').on('click', function() {
                    SRL.schedule.selectSlot($(this).data('slot-id'), $(this));
                });
            },

            selectSlot: function(slotId, element) {
                $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                element.addClass('srl-slot-wybrany');
                SRL.state.wybranySlot = slotId;
                $('#srl-dalej-krok-5').show();
                SRL.schedule.blockTemporarily(slotId);
            },

            blockTemporarily: function(slotId) {
                SRL.ajax('srl_zablokuj_slot_tymczasowo', { termin_id: slotId }, {
                    showLoader: false,
                    useCache: false,
                    successCallback: data => {
                        SRL.state.tymczasowaBlokada = data;
                        SRL.showMessage('Termin został zarezerwowany na 15 minut.', 'info');
                        setTimeout(() => {
                            SRL.showMessage('Blokada terminu wygasła. Wybierz termin ponownie.', 'warning');
                            $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                            $('#srl-dalej-krok-5').hide();
                            SRL.state.wybranySlot = null;
                            SRL.state.tymczasowaBlokada = null;
                        }, 15 * 60 * 1000);
                    },
                    errorCallback: () => {
                        $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                        SRL.state.wybranySlot = null;
                        $('#srl-dalej-krok-5').hide();
                    }
                });
            }
        },

        booking: {
            confirm: function() {
                if (!SRL.state.wybranySlot || !SRL.state.wybranyLot) {
                    SRL.showMessage('Brak wybranych danych do rezerwacji.', 'error');
                    return;
                }

                const $btn = $('#srl-potwierdz-rezerwacje');
                
                SRL.ajax('srl_dokonaj_rezerwacji', {
                    termin_id: SRL.state.wybranySlot,
                    lot_id: SRL.state.wybranyLot
                }, {
                    loadingElement: $btn,
                    originalText: '🎯 Potwierdź rezerwację',
                    loadingText: 'Finalizowanie rezerwacji...',
                    useCache: false,
                    successCallback: () => {
                        SRL.cache.clear();
                        SRL.booking.showSuccess();
                    },
                    errorCallback: () => {
                        $btn.prop('disabled', false).text('🎯 Potwierdź rezerwację');
                    }
                });
            },

            showSuccess: function() {
                const html = `
                    <div class="srl-komunikat srl-komunikat-success" style="text-align:center; padding:40px;">
                        <h2 style="color:#46b450; margin-bottom:20px;text-transform: uppercase;">Rezerwacja potwierdzona!</h2>
                        <p style="font-size:18px; margin-bottom:30px;">Twój lot tandemowy został zarezerwowany na <strong>${SRL.utils.formatDate(SRL.state.wybranaDana)}</strong></p>
                        <p>Na podany adres email została wysłana informacja z szczegółami rezerwacji.</p>
                        <div style="margin-top:30px;"><a href="${window.location.href}" class="srl-btn srl-btn-primary">Zarezerwuj kolejny lot</a></div>
                    </div>
                `;
                $('#srl-rezerwacja-container').html(html);
            },

            cancel: function(lotId) {
                if (!confirm('Czy na pewno chcesz anulować tę rezerwację?')) return;

                SRL.ajax('srl_anuluj_rezerwacje_klient', { lot_id: lotId }, {
                    showLoader: false,
                    useCache: false,
                    successCallback: () => {
                        SRL.showMessage('Rezerwacja została anulowana.', 'success');
                        SRL.cache.clear();
                        SRL.data.loadClientData();
                    }
                });
            }
        },

        ui: {
            updateGreeting: function() {
                let greeting = 'Cześć';
                if (SRL.state.daneKlienta?.dane_osobowe) {
                    const { imie, nazwisko } = SRL.state.daneKlienta.dane_osobowe;
                    if (imie && nazwisko) greeting = `Cześć, ${imie} ${nazwisko}`;
                    else if (imie) greeting = `Cześć, ${imie}`;
                }
                $('#srl-krok-1 h2').text(`${greeting}! 👋`);
            },

            populateFlightList: function(flights) {
                const container = $('#srl-lista-rezerwacji');
                
                if (!flights || flights.length === 0) {
                    container.html('<p class="srl-komunikat srl-komunikat-info">Nie masz żadnych lotów.</p>');
                    return;
                }

                let html = '<table class="srl-tabela-lotow"><thead><tr><th class="srl-kolumna-nazwa">Nazwa</th><th class="srl-kolumna-status">Status i termin</th><th class="srl-kolumna-opcje">Opcje</th><th class="srl-kolumna-akcje">Akcje</th></tr></thead><tbody>';

                flights.forEach(lot => {
                    html += SRL.ui.generateFlightRow(lot);
                });

                html += '</tbody></table>';
                container.html(html);

                $('.srl-anuluj-rezerwacje').on('click', function() {
                    SRL.booking.cancel($(this).data('lot-id'));
                });
            },

            generateFlightRow: function(lot) {
                const opcje = [
                    lot.ma_filmowanie && lot.ma_filmowanie != '0' ? '<span style="color: #46b450;">z filmowaniem</span>' : '<span style="color: #d63638;">bez filmowania</span>',
                    lot.ma_akrobacje && lot.ma_akrobacje != '0' ? '<span style="color: #46b450;">z akrobacjami</span>' : '<span style="color: #d63638;">bez akrobacji</span>'
                ];

                let html = `<tr><td class="srl-kolumna-nazwa">`;
                html += `<div class="srl-nazwa-lotu">Lot w tandemie (#${lot.id})</div>`;
                html += `<div class="srl-opcje-lotu">${opcje.join(', ')}</div>`;
                
                if (lot.data_waznosci) {
                    html += `<div class="srl-data-waznosci">(Ważny do: ${new Date(lot.data_waznosci).toLocaleDateString('pl-PL')})</div>`;
                }
                html += `</td><td class="srl-kolumna-status">`;

                html += SRL.ui.generateStatusSection(lot);
                html += `</td><td class="srl-kolumna-opcje">`;
                html += SRL.ui.generateOptionsSection(lot);
                html += `</td><td class="srl-kolumna-akcje">`;
                html += SRL.ui.generateActionsSection(lot);
                html += `</td></tr>`;

                return html;
            },

            generateStatusSection: function(lot) {
                let html = '';
                if (lot.status === 'zarezerwowany') {
                    html += '<div class="srl-status-badge srl-status-zarezerwowany">Zarezerwowany</div>';
                    if (lot.data && lot.godzina_start) {
                        const dataLotu = new Date(lot.data);
                        const nazwyDni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
                        const dzienTygodnia = nazwyDni[dataLotu.getDay()];
                        const dataStr = dataLotu.toLocaleDateString('pl-PL');
                        const godzinaStr = lot.godzina_start.substring(0, 5);
                        html += `<div class="srl-termin-info"><div class="srl-termin-data">${dataStr}, godz: ${godzinaStr}</div><div class="srl-termin-dzien">${dzienTygodnia}</div></div>`;
                    }
                } else if (lot.status === 'wolny') {
                    html += '<div class="srl-status-badge srl-status-wolny">Czeka na rezerwację</div>';
                }
                return html;
            },

            generateOptionsSection: function(lot) {
                let html = '';
                if (lot.status === 'zarezerwowany') {
                    const dataLotu = new Date(lot.data + ' ' + lot.godzina_start);
                    const czasDoLotu = dataLotu.getTime() - Date.now();
                    const moznaModyfikowac = czasDoLotu > 48 * 60 * 60 * 1000;

                    if (moznaModyfikowac) {
                        const dostepneOpcje = [];
                        if (!lot.ma_filmowanie || lot.ma_filmowanie == '0') {
                            dostepneOpcje.push({nazwa: 'Filmowanie', id: SRL.config.productIds.filmowanie});
                        }
                        if (!lot.ma_akrobacje || lot.ma_akrobacje == '0') {
                            dostepneOpcje.push({nazwa: 'Akrobacje', id: SRL.config.productIds.akrobacje});
                        }

                        if (dostepneOpcje.length > 0) {
                            dostepneOpcje.forEach(opcja => {
                                html += `<button id="srl-opcja-${lot.id}-${opcja.id}" class="srl-add-option srl-opcja-btn" data-lot-id="${lot.id}" data-product-id="${opcja.id}" onclick="srlDodajOpcjeLotu(${lot.id}, ${opcja.id}, '${opcja.nazwa}')">+ ${opcja.nazwa}</button>`;
                            });
                        } else {
                            html += '<div class="srl-opcje-info">—</div>';
                        }
                    } else {
                        html += '<div class="srl-opcje-info">Za późno na zmiany</div>';
                    }
                } else if (lot.status === 'wolny') {
                    const dostepneOpcje = [];
                    if (!lot.ma_filmowanie || lot.ma_filmowanie == '0') {
                        dostepneOpcje.push({nazwa: 'Filmowanie', id: SRL.config.productIds.filmowanie});
                    }
                    if (!lot.ma_akrobacje || lot.ma_akrobacje == '0') {
                        dostepneOpcje.push({nazwa: 'Akrobacje', id: SRL.config.productIds.akrobacje});
                    }

                    if (lot.data_waznosci) {
                        const dniDoWaznosci = Math.floor((new Date(lot.data_waznosci).getTime() - Date.now()) / (24 * 60 * 60 * 1000));
                        if (dniDoWaznosci <= 3390) {
                            dostepneOpcje.push({nazwa: 'Przedłużenie', id: SRL.config.productIds.przedluzenie});
                        }
                    }

                    if (dostepneOpcje.length > 0) {
                        dostepneOpcje.forEach(opcja => {
                            html += `<button id="srl-opcja-${lot.id}-${opcja.id}" class="srl-add-option srl-opcja-btn" data-lot-id="${lot.id}" data-product-id="${opcja.id}" onclick="srlDodajOpcjeLotu(${lot.id}, ${opcja.id}, '${opcja.nazwa}')">+ ${opcja.nazwa}</button>`;
                        });
                    } else {
                        html += '<div class="srl-opcje-info">—</div>';
                    }
                } else {
                    html += '<div class="srl-opcje-info">—</div>';
                }
                return html;
            },

            generateActionsSection: function(lot) {
                let html = '';
                if (lot.status === 'zarezerwowany') {
                    const dataLotu = new Date(lot.data + ' ' + lot.godzina_start);
                    const czasDoLotu = dataLotu.getTime() - Date.now();
                    const moznaAnulowac = czasDoLotu > 48 * 60 * 60 * 1000;

                    if (moznaAnulowac) {
                        html += `<button class="srl-anuluj-rezerwacje srl-akcja-btn srl-btn-odwolaj" data-lot-id="${lot.id}">Odwołaj</button>`;
                    } else {
                        html += '<div class="srl-akcje-info">Za późno</div>';
                    }
                } else if (lot.status === 'wolny') {
                    html += `<button class="srl-wybierz-lot srl-akcja-btn srl-btn-wybierz" data-lot-id="${lot.id}">Wybierz termin</button>`;
                }
                return html;
            }
        },

        flightInfo: {
            update: function() {
                const aktualnyLot = SRL.state.wybranyLot || window.wybranyLot;
                const aktualneDane = SRL.state.daneKlienta || window.daneKlienta;
                if (!aktualnyLot || !aktualneDane?.dostepne_loty) return;

                const lot = aktualneDane.dostepne_loty.find(l => l.id == aktualnyLot);
                if (!lot) return;

                const nazwaBezWariantu = lot.nazwa_produktu.split(' - ')[0];
                const finalName = ['voucher', 'lot', 'tandem'].some(word => nazwaBezWariantu.toLowerCase().includes(word)) ? 'Lot w tandemie' : nazwaBezWariantu;

                const maFilmowanie = lot.ma_filmowanie && lot.ma_filmowanie != '0';
                const maAkrobacje = lot.ma_akrobacje && lot.ma_akrobacje != '0';
                const opcje = [
                    maFilmowanie ? '<span style="color: #46b450;">z filmowaniem</span>' : '<span style="color: #d63638;">brak filmowania</span>',
                    maAkrobacje ? '<span style="color: #46b450;">z akrobacjami</span>' : '<span style="color: #d63638;">brak akrobacji</span>'
                ];

                let html = `<strong>Lot #${lot.id} – ${SRL.utils.escapeHtml(finalName)} <span style="font-weight: bold;">${opcje.join(', ')}</span></strong>`;

                if (!maFilmowanie || !maAkrobacje) {
                    SRL.flightInfo.sprawdzOpcjeWKoszyku(lot, (opcjeWKoszyku) => {
                        html += SRL.flightInfo.generateOptionsBox(lot, maFilmowanie, maAkrobacje, opcjeWKoszyku);
                        $('#srl-wybrany-lot-szczegoly').html(html);
                    });
                } else {
                    $('#srl-wybrany-lot-szczegoly').html(html);
                }
            },

            sprawdzOpcjeWKoszyku: function(lot, callback) {
                if (typeof window.SRLOpcje !== 'undefined') {
                    window.SRLOpcje.ajax('srl_sprawdz_opcje_w_koszyku', {}, {
                        pokazKomunikat: false,
                        onSuccess: (response) => {
                            const opcjeWKoszyku = response.success && response.data ? response.data[lot.id] || {} : {};
                            callback(opcjeWKoszyku);
                        },
                        onError: () => {
                            callback({});
                        }
                    });
                } else {
                    callback({});
                }
            },

            generateOptionsBox: function(lot, maFilmowanie, maAkrobacje, opcjeWKoszyku = {}) {
                let html = '<div style="background: #f0f8ff; border: 2px solid #46b450; border-radius: 8px; padding: 20px; margin-top: 15px;">';
                
                const maOpcjeWKoszyku = opcjeWKoszyku.filmowanie || opcjeWKoszyku.akrobacje;
                
                if (!maFilmowanie && !maAkrobacje) {
                    html += '<h4 style="margin-top: 0; color: #46b450;">🌟 Czy wiesz, że Twój lot może być jeszcze ciekawszy?</h4>';
                    html += '<p>Nie masz dodanego <strong>filmowania</strong> ani <strong>akrobacji</strong> – to dwie opcje, które często wybierają nasi pasażerowie.</p>';
                    html += '<p><strong>Film z lotu</strong> to świetna pamiątka, którą możesz pokazać znajomym.</br><strong>Akrobacje</strong>? Idealne, jeśli masz ochotę na więcej adrenaliny!</p>';
                    html += '<p>Możesz wykupić je teraz online lub na lotnisku – bezpośrednio na lotnisku, za gotówkę.</p>';
                    html += '<div style="text-align: left; margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">';
                    html += SRL.flightInfo.generateButton(lot.id, SRL.config.productIds.filmowanie, 'Filmowanie', 'Filmowanie lotu', opcjeWKoszyku.filmowanie);
                    html += SRL.flightInfo.generateButton(lot.id, SRL.config.productIds.akrobacje, 'Akrobacje', 'Akrobacje podczas lotu', opcjeWKoszyku.akrobacje);
                    
                    if (maOpcjeWKoszyku) {
                        html += '<a href="/zamowienie/" class="srl-btn srl-btn-primary button-small" style="margin: 0; padding: 8px 16px; text-decoration: none; margin-left: auto;">Finalizuj zamówienie</a>';
                    }
                    html += '</div>';
                } else if (!maFilmowanie) {
                    html += '<h4 style="margin-top: 0; color: #46b450;">Nie masz dodanego filmowania do swojego lotu?</h4>';
                    html += '<p>To nic, ale warto wiedzieć, że to bardzo lubiana opcja wśród pasażerów.</p>';
                    html += '<p>🎥 <strong>Film z lotu</strong> pozwala wracać do tych emocji, dzielić się nimi z bliskimi i zachować wyjątkową pamiątkę.</p>';
                    html += '<p>Możesz wykupić je teraz online lub na lotnisku – bezpośrednio na lotnisku, za gotówkę.</p>';
                    html += '<div style="text-align: left; margin-top: 15px; display: flex; gap: 10px; align-items: center;">';
                    html += SRL.flightInfo.generateButton(lot.id, SRL.config.productIds.filmowanie, 'Filmowanie', 'Filmowanie lotu', opcjeWKoszyku.filmowanie);
                    
                    if (maOpcjeWKoszyku) {
                        html += '<a href="/zamowienie/" class="srl-btn srl-btn-primary button-small" style="margin: 0; padding: 8px 16px; text-decoration: none; margin-left: auto;">Finalizuj zamówienie</a>';
                    }
                    html += '</div>';
                } else if (!maAkrobacje) {
                    html += '<h4 style="margin-top: 0; color: #46b450;">Nie wybrałeś akrobacji?</h4>';
                    html += '<p>To oczywiście nie jest obowiązkowe – ale jeśli lubisz odrobinę adrenaliny, to może być coś dla Ciebie!</p>';
                    html += '<p><strong>Akrobacje w locie</strong> to kilka dynamicznych manewrów, które robią wrażenie i zostają w pamięci na długo.</p>';
                    html += '<p>Możesz wykupić je teraz online lub na lotnisku – bezpośrednio na lotnisku, za gotówkę.</p>';
                    html += '<div style="text-align: left; margin-top: 15px; display: flex; gap: 10px; align-items: center;">';
                    html += SRL.flightInfo.generateButton(lot.id, SRL.config.productIds.akrobacje, 'Akrobacje', 'Akrobacje podczas lotu', opcjeWKoszyku.akrobacje);
                    
                    if (maOpcjeWKoszyku) {
                        html += '<a href="/zamowienie/" class="srl-btn srl-btn-primary button-small" style="margin: 0; padding: 8px 16px; text-decoration: none; margin-left: auto;">Finalizuj zamówienie</a>';
                    }
                    html += '</div>';
                }
                html += '</div>';
                return html;
            },

            generateButton: function(lotId, productId, nazwa, pelnanazwa, czyWKoszyku) {
                const timestamp = Date.now();
                
                if (czyWKoszyku) {
                    return `<button id="srl-opcja-${lotId}-${productId}" class="srl-add-option srl-btn srl-btn-warning button-small" style="margin: 0; padding: 8px 16px; background: #ff9800; border-color: #ff9800; color: white;" data-lot-id="${lotId}" data-product-id="${productId}" data-timestamp="${timestamp}">+ ${nazwa} (w koszyku) <span class="srl-remove-from-cart" data-lot-id="${lotId}" data-product-id="${productId}" style="margin-left: 10px; cursor: pointer; font-weight: bold; font-size: 14px; padding: 2px 6px; border-radius: 50%; transition: all 0.2s ease;" onmouseenter="this.style.backgroundColor='#dc3545'; this.style.color='white';" onmouseleave="this.style.backgroundColor=''; this.style.color='white';">✕</span></button>`;
                } else {
                    return `<button id="srl-opcja-${lotId}-${productId}" class="srl-add-option srl-btn srl-btn-success button-small" style="margin: 0; padding: 8px 16px;" data-lot-id="${lotId}" data-product-id="${productId}" data-timestamp="${timestamp}" onclick="srlDodajOpcjeLotu(${lotId}, ${productId}, '${pelnanazwa}')">+ ${nazwa}</button>`;
                }
            }
        },

        utils: {
            formatDate: function(dataStr) {
                const data = new Date(dataStr);
                const nazwyDni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
                const nazwyMiesiecy = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
                return `${nazwyDni[data.getDay()]}, ${data.getDate()} ${nazwyMiesiecy[data.getMonth()]} ${data.getFullYear()}`;
            },

            pad: function(n) {
                return n < 10 ? '0' + n : '' + n;
            },

            escapeHtml: function(text) {
                const div = document.createElement('div');
                div.innerText = text;
                return div.innerHTML;
            },

            getFormData: function(selector) {
                const data = {};
                $(selector).find('input, select, textarea').each(function() {
                    const $this = $(this);
                    const name = $this.attr('name');
                    if (name) {
                        if ($this.attr('type') === 'checkbox') {
                            data[name] = $this.is(':checked');
                        } else {
                            data[name] = $this.val();
                        }
                    }
                });
                return data;
            }
        },

        init: function() {
            SRL.data.loadClientData();
            SRL.bindEvents();
        },
        
        bindEvents: function() {
            $('.srl-step').on('click', function() {
                const krok = parseInt($(this).data('step'));
                if (krok <= SRL.state.maksymalnyKrok) SRL.steps.show(krok);
            });

            $('#srl-formularz-pasazera').on('submit', function(e) {
                e.preventDefault();
                SRL.data.savePassengerData();
            });

            $('#srl-poprzedni-miesiac').on('click', () => SRL.calendar.changeMonth(-1));
            $('#srl-nastepny-miesiac').on('click', () => SRL.calendar.changeMonth(1));
            $('#srl-powrot-krok-1').on('click', () => SRL.steps.show(1));
            $('#srl-powrot-krok-2').on('click', () => SRL.steps.show(2));
            $('#srl-powrot-krok-3').on('click', () => SRL.steps.show(3));
            $('#srl-powrot-krok-4').on('click', () => SRL.steps.show(4));
            $('#srl-dalej-krok-5').on('click', () => SRL.steps.show(5));

            $(document).on('click', '.srl-wybierz-lot', function() {
                const lotId = $(this).data('lot-id');
                SRL.state.wybranyLot = lotId;
                window.wybranyLot = lotId;
                SRL.steps.show(2);
                SRL.flightInfo.update();
            });

            $('#srl-potwierdz-rezerwacje').on('click', () => SRL.booking.confirm());

            $('.srl-step[data-step="2"]').on('click', function(e) {
                if (!SRL.state.wybranyLot && !window.wybranyLot) {
                    e.preventDefault();
                    e.stopPropagation();
                    SRL.showMessage('Musisz najpierw wybrać lot do zarezerwowania.', 'error');
                    return false;
                }
            });

            $(document).on('change', '#srl-rok-urodzenia', () => SRL.validation.validateAge($('#srl-rok-urodzenia').val()));
            $(document).on('change', '#srl-kategoria-wagowa', function() {
                SRL.validation.validateWeight($(this).val());
                SRL.validation.checkCompatibility();
            });

            $(document).on('srl_opcje_zmienione', function() {
                setTimeout(() => {
                    SRL.flightInfo.sprawdzIUstawPrzyciski();
                }, 200);
            });

            $(document).on('srl_dane_klienta_zaladowane', function() {
                setTimeout(() => {
                    if (SRL.state.wybranyLot || window.wybranyLot) {
                        SRL.flightInfo.update();
                    }
                }, 500);
            });
            
            $(document).on('srl_opcje_zmienione', function() {
                const aktualnyLot = SRL.state.wybranyLot || window.wybranyLot;
                
                setTimeout(() => {
                    if (aktualnyLot) {
                        SRL.state.wybranyLot = aktualnyLot;
                        window.wybranyLot = aktualnyLot;
                        SRL.flightInfo.update();
                    }
                }, 500);
            });
        }
    };

    window.wybranyLot = null;
    window.daneKlienta = null;

    if (typeof window.SRLOpcje !== 'undefined') {
        Object.assign(window.SRLOpcje, {
            ajax: SRL.ajax,
            cache: SRL.cache,
            debounce: SRL.debounce
        });
    }

    SRL.init();
});