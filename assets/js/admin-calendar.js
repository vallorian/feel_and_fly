jQuery(document).ready(function($) {
    const SRLAdminCalendar = {
        cache: new Map(),
        ajaxQueue: new Map(),
        debounceTimers: new Map(),
        
        state: {
            aktualnyKrok: 1,
            maksymalnyKrok: 1,
            aktualnyMiesiac: new Date().getMonth() + 1,
            aktualnyRok: new Date().getFullYear(),
            wybranaDana: null,
            wybranySlot: null,
            wybranyLot: null,
            daneKlienta: null,
            tymczasowaBlokada: null
        },

        init() {
            this.zaladujDaneKlienta();
            this.podlaczNasluchy();
            this.preloadCriticalData();
        },

        getCacheKey(prefix, ...params) {
            return `${prefix}_${params.join('_')}`;
        },

        getCached(key) {
            const cached = this.cache.get(key);
            if (cached && Date.now() - cached.timestamp < cached.ttl) {
                return cached.data;
            }
            this.cache.delete(key);
            return null;
        },

        setCache(key, data, ttl = 300000) {
            this.cache.set(key, {
                data,
                timestamp: Date.now(),
                ttl
            });
        },

        invalidateCache(pattern) {
            for (const key of this.cache.keys()) {
                if (key.includes(pattern)) {
                    this.cache.delete(key);
                }
            }
        },

        debounce(key, func, delay = 300) {
            clearTimeout(this.debounceTimers.get(key));
            this.debounceTimers.set(key, setTimeout(func, delay));
        },

        cachedAjax(url, data, options = {}) {
            const cacheKey = this.getCacheKey('ajax', url, JSON.stringify(data));
            const cached = this.getCached(cacheKey);
            
            if (cached) {
                return Promise.resolve(cached);
            }

            const requestKey = `${url}_${JSON.stringify(data)}`;
            if (this.ajaxQueue.has(requestKey)) {
                return this.ajaxQueue.get(requestKey);
            }

            const request = $.ajax({
                url,
                data,
                method: options.method || 'GET',
                ...options
            }).then(response => {
                this.setCache(cacheKey, response, options.cacheTtl || 300000);
                this.ajaxQueue.delete(requestKey);
                return response;
            }).catch(error => {
                this.ajaxQueue.delete(requestKey);
                throw error;
            });

            this.ajaxQueue.set(requestKey, request);
            return request;
        },

        preloadCriticalData() {
            const currentMonth = this.getCacheKey('month', this.state.aktualnyRok, this.state.aktualnyMiesiac);
            if (!this.getCached(currentMonth)) {
                this.pobierzDostepneDni();
            }
        },

        pokazKrok(nrKroku) {
            if (nrKroku < 1 || nrKroku > 4) return;

            $('.srl-step').removeClass('srl-step-active srl-step-completed');
            for (let i = 1; i <= 4; i++) {
                const step = $(`.srl-step[data-step="${i}"]`);
                if (i < nrKroku) step.addClass('srl-step-completed');
                else if (i === nrKroku) step.addClass('srl-step-active');
            }

            $('.srl-krok').removeClass('srl-krok-active');
            $(`#srl-krok-${nrKroku}`).addClass('srl-krok-active');
            this.state.aktualnyKrok = nrKroku;
            this.state.maksymalnyKrok = Math.max(this.state.maksymalnyKrok, nrKroku);
            
            $('html, body').animate({
                scrollTop: $('#srl-rezerwacja-container').offset().top - 50
            }, 300);

            if (nrKroku === 4) this.pokazKrok4();
        },

        podlaczNasluchy() {
            $(document)
                .off('.srl-calendar')
                .on('click.srl-calendar', '.srl-step', (e) => {
                    const krok = parseInt($(e.currentTarget).data('step'));
                    if (krok <= this.state.maksymalnyKrok) this.pokazKrok(krok);
                })
                .on('submit.srl-calendar', '#srl-formularz-pasazera', (e) => {
                    e.preventDefault();
                    this.zapiszDanePasazera();
                })
                .on('click.srl-calendar', '#srl-poprzedni-miesiac', () => this.zmienMiesiac(-1))
                .on('click.srl-calendar', '#srl-nastepny-miesiac', () => this.zmienMiesiac(1))
                .on('click.srl-calendar', '#srl-powrot-krok-1', () => this.pokazKrok(1))
                .on('click.srl-calendar', '#srl-powrot-krok-2', () => this.pokazKrok(2))
                .on('click.srl-calendar', '#srl-powrot-krok-3', () => this.pokazKrok(3))
                .on('click.srl-calendar', '#srl-dalej-krok-4', () => this.pokazKrok(4))
                .on('click.srl-calendar', '#srl-potwierdz-rezerwacje', () => this.dokonajRezerwacji())
                .on('click.srl-calendar', '.srl-anuluj-rezerwacje', (e) => {
                    this.anulujRezerwacje($(e.currentTarget).data('lot-id'));
                })
                .on('click.srl-calendar', '.srl-dzien-dostepny', (e) => {
                    this.state.wybranaDana = $(e.currentTarget).data('data');
                    $('.srl-dzien-wybrany').removeClass('srl-dzien-wybrany');
                    $(e.currentTarget).addClass('srl-dzien-wybrany');
                    this.pokazKrok(3);
                    this.zaladujHarmonogram();
                })
                .on('click.srl-calendar', '.srl-slot-godzina', (e) => {
                    this.wybierzSlot($(e.currentTarget).data('slot-id'), $(e.currentTarget));
                });

            this.podlaczVoucherHandlers();
        },

        podlaczVoucherHandlers() {
            $(document)
                .on('click.srl-voucher', '#srl-voucher-show', () => {
                    $('#srl-voucher-show').hide();
                    $('#srl-voucher-form').show();
                    $('#srl-voucher-code').focus();
                })
                .on('click.srl-voucher', '#srl-voucher-cancel', () => {
                    $('#srl-voucher-form').hide();
                    $('#srl-voucher-show').show();
                    $('#srl-voucher-code').val('');
                })
                .on('input.srl-voucher', '#srl-voucher-code', (e) => {
                    const value = $(e.target).val().toUpperCase().replace(/[^A-Z0-9]/g, '');
                    $(e.target).val(value);
                })
                .on('click.srl-voucher', '#srl-voucher-submit', () => {
                    this.wykorzystajVoucher();
                });
        },

        zaladujDaneKlienta() {
            const cacheKey = 'client_data';
            const cached = this.getCached(cacheKey);
            
            if (cached) {
                this.state.daneKlienta = cached;
                this.wypelnijDaneKlienta();
                return;
            }

            this.pokazKomunikat('Ładowanie danych...', 'info');
            
            this.cachedAjax(srlFrontend.ajaxurl, {
                action: 'srl_pobierz_dane_klienta',
                nonce: srlFrontend.nonce
            }, { cacheTtl: 180000 })
            .then(response => {
                this.ukryjKomunikat();
                if (response.success) {
                    this.state.daneKlienta = response.data;
                    this.setCache(cacheKey, response.data, 180000);
                    this.wypelnijDaneKlienta();
                } else {
                    this.pokazKomunikat('Błąd ładowania danych: ' + response.data, 'error');
                }
            })
            .catch(() => {
                this.ukryjKomunikat();
                this.pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            });
        },

        wypelnijDaneKlienta() {
            const data = this.state.daneKlienta;
            this.wypelnijListeRezerwacji(data.rezerwacje);
            this.wypelnijDostepneLoty(data.dostepne_loty);
            this.wypelnijFormularzDanych(data.dane_osobowe);
            
            if (data.dane_kompletne) {
                $('#srl-formularz-pasazera button[type="submit"]').text('Przejdź dalej →');
            }
        },

        wypelnijListeRezerwacji(rezerwacje) {
            const container = $('#srl-lista-rezerwacji');
            
            if (!rezerwacje?.length) {
                container.html('<p class="srl-komunikat srl-komunikat-info">Nie masz aktualnych rezerwacji.</p>');
                return;
            }

            const fragment = document.createDocumentFragment();
            const table = document.createElement('table');
            table.className = 'srl-tabela';
            
            table.innerHTML = '<thead><tr><th>Zamówienie</th><th>Produkt</th><th>Data</th><th>Godzina</th><th>Akcje</th></tr></thead>';
            
            const tbody = document.createElement('tbody');
            
            rezerwacje.forEach(rezerwacja => {
                const dataLotu = new Date(rezerwacja.data + ' ' + rezerwacja.godzina_start);
                const moznaAnulowac = dataLotu.getTime() - Date.now() > 48 * 60 * 60 * 1000;
                
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>#${rezerwacja.order_id}</td>
                    <td>${this.escapeHtml(rezerwacja.nazwa_produktu)}</td>
                    <td>${this.formatujDate(rezerwacja.data)}</td>
                    <td>${rezerwacja.godzina_start.substring(0, 5)}</td>
                    <td>${moznaAnulowac ? 
                        `<button class="srl-btn srl-btn-secondary srl-anuluj-rezerwacje" data-lot-id="${rezerwacja.id}">Odwołaj</button>` : 
                        '<span style="color:#999; font-style:italic;">Nie możesz już dokonać zmian</span>'}</td>
                `;
                tbody.appendChild(row);
            });
            
            table.appendChild(tbody);
            fragment.appendChild(table);
            container.empty().append(fragment);
        },

        wypelnijDostepneLoty(loty) {
            const container = $('#srl-wybor-lotu');
            
            if (!loty?.length) {
                container.html('<p class="srl-komunikat srl-komunikat-warning">Nie masz dostępnych lotów do zarezerwowania.</p>');
                return;
            }

            let html = '<div class="srl-form-group"><label for="srl-wybrany-lot">Wybierz lot do zarezerwowania:</label>';
            
            if (loty.length === 1) {
                const lot = loty[0];
                html += `<div style="padding:15px; background:#f8f9fa; border-radius:8px; font-weight:600;">
                    #${lot.order_id} – ${this.escapeHtml(lot.nazwa_produktu)}
                    <input type="hidden" id="srl-wybrany-lot" value="${lot.id}">
                </div>`;
            } else {
                html += '<select id="srl-wybrany-lot" required><option value="">Wybierz lot...</option>';
                loty.forEach(lot => {
                    html += `<option value="${lot.id}">#${lot.order_id} – ${this.escapeHtml(lot.nazwa_produktu)}</option>`;
                });
                html += '</select>';
            }
            
            html += `</div><p style="margin-top:15px; color:#0073aa; font-weight:500;">🎫 Ilość lotów do zarezerwowania: ${loty.length}</p>`;
            container.html(html);
        },

        wypelnijFormularzDanych(dane) {
            const fields = {
                '#srl-imie': dane.imie,
                '#srl-nazwisko': dane.nazwisko,
                '#srl-rok-urodzenia': dane.rok_urodzenia,
                '#srl-plec': dane.plec,
                '#srl-waga': dane.waga,
                '#srl-wzrost': dane.wzrost,
                '#srl-telefon': dane.telefon,
                '#srl-uwagi': dane.uwagi
            };

            Object.entries(fields).forEach(([selector, value]) => {
                $(selector).val(value || '');
            });
        },

        zapiszDanePasazera() {
            this.state.wybranyLot = $('#srl-wybrany-lot').val();
            if (!this.state.wybranyLot) {
                this.pokazKomunikat('Wybierz lot do zarezerwowania.', 'error');
                return;
            }

            const submitBtn = $('#srl-formularz-pasazera button[type="submit"]');
            submitBtn.prop('disabled', true).text('Zapisywanie...');

            const formData = {
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
            };

            $.post(srlFrontend.ajaxurl, formData)
            .then(response => {
                if (response.success) {
                    this.invalidateCache('client_data');
                    this.pokazKrok(2);
                    this.zaladujKalendarz();
                } else {
                    this.pokazKomunikat('Błąd: ' + response.data, 'error');
                }
            })
            .catch(() => {
                this.pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            })
            .always(() => {
                submitBtn.prop('disabled', false).text('Zapisz i przejdź dalej →');
            });
        },

        anulujRezerwacje(lotId) {
            if (!confirm('Czy na pewno chcesz anulować tę rezerwację?')) return;

            $.post(srlFrontend.ajaxurl, {
                action: 'srl_anuluj_rezerwacje_klient',
                nonce: srlFrontend.nonce,
                lot_id: lotId
            })
            .then(response => {
                if (response.success) {
                    this.pokazKomunikat('Rezerwacja została anulowana.', 'success');
                    this.invalidateCache('client_data');
                    this.zaladujDaneKlienta();
                } else {
                    this.pokazKomunikat('Błąd: ' + response.data, 'error');
                }
            })
            .catch(() => {
                this.pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            });
        },

        zaladujKalendarz() {
            this.aktualizujNawigacjeKalendarza();
            this.pobierzDostepneDni();
        },

        aktualizujNawigacjeKalendarza() {
            const nazwyMiesiecy = [
                'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
                'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'
            ];
            $('#srl-miesiac-rok').text(`${nazwyMiesiecy[this.state.aktualnyMiesiac - 1]} ${this.state.aktualnyRok}`);
        },

        zmienMiesiac(kierunek) {
            this.state.aktualnyMiesiac += kierunek;
            if (this.state.aktualnyMiesiac > 12) {
                this.state.aktualnyMiesiac = 1;
                this.state.aktualnyRok++;
            } else if (this.state.aktualnyMiesiac < 1) {
                this.state.aktualnyMiesiac = 12;
                this.state.aktualnyRok--;
            }
            this.aktualizujNawigacjeKalendarza();
            this.pobierzDostepneDni();
        },

        pobierzDostepneDni() {
            const cacheKey = this.getCacheKey('month', this.state.aktualnyRok, this.state.aktualnyMiesiac);
            const cached = this.getCached(cacheKey);
            
            if (cached) {
                this.wygenerujKalendarz(cached);
                return;
            }

            $('#srl-kalendarz-tabela').html('<div class="srl-loader">Ładowanie dostępnych terminów...</div>');
            
            this.cachedAjax(srlFrontend.ajaxurl, {
                action: 'srl_pobierz_dostepne_dni',
                nonce: srlFrontend.nonce,
                rok: this.state.aktualnyRok,
                miesiac: this.state.aktualnyMiesiac
            }, { cacheTtl: 600000 })
            .then(response => {
                if (response.success) {
                    this.setCache(cacheKey, response.data, 600000);
                    this.wygenerujKalendarz(response.data);
                } else {
                    $('#srl-kalendarz-tabela').html(`<p class="srl-komunikat srl-komunikat-error">Błąd: ${response.data}</p>`);
                }
            })
            .catch(() => {
                $('#srl-kalendarz-tabela').html('<p class="srl-komunikat srl-komunikat-error">Błąd połączenia z serwerem.</p>');
            });
        },

        wygenerujKalendarz(dostepneDni) {
            const { aktualnyRok: rok, aktualnyMiesiac: miesiac } = this.state;
            const pierwszyDzienMiesiaca = new Date(rok, miesiac - 1, 1);
            const dniWMiesiacu = new Date(rok, miesiac, 0).getDate();
            let pierwszyDzienTygodnia = pierwszyDzienMiesiaca.getDay();
            pierwszyDzienTygodnia = pierwszyDzienTygodnia === 0 ? 7 : pierwszyDzienTygodnia;

            const fragment = document.createDocumentFragment();
            const table = document.createElement('table');
            table.className = 'srl-kalendarz-tabela';
            
            table.innerHTML = '<thead><tr><th>Pon</th><th>Wt</th><th>Śr</th><th>Czw</th><th>Pt</th><th>Sob</th><th>Nd</th></tr></thead>';
            
            const tbody = document.createElement('tbody');
            let dzien = 1;
            const pustePrzed = pierwszyDzienTygodnia - 1;
            const calkowiteKomorki = Math.ceil((dniWMiesiacu + pustePrzed) / 7) * 7;

            for (let i = 0; i < calkowiteKomorki; i++) {
                if (i % 7 === 0) {
                    const row = document.createElement('tr');
                    tbody.appendChild(row);
                }

                const cell = document.createElement('td');
                const currentRow = tbody.lastElementChild;

                if (i < pustePrzed || dzien > dniWMiesiacu) {
                    cell.className = 'srl-dzien-pusty';
                } else {
                    const dataStr = `${rok}-${this.pad2(miesiac)}-${this.pad2(dzien)}`;
                    const iloscSlotow = dostepneDni[dataStr] || 0;
                    
                    if (iloscSlotow > 0) {
                        cell.className = 'srl-dzien-dostepny';
                        cell.dataset.data = dataStr;
                        cell.innerHTML = `
                            <div class="srl-dzien-numer">${dzien}</div>
                            <div class="srl-dzien-sloty">${iloscSlotow} wolnych</div>
                        `;
                    } else {
                        cell.className = 'srl-dzien-niedostepny';
                        cell.innerHTML = `<div class="srl-dzien-numer">${dzien}</div>`;
                    }
                    dzien++;
                }

                currentRow.appendChild(cell);
            }

            table.appendChild(tbody);
            fragment.appendChild(table);
            $('#srl-kalendarz-tabela').empty().append(fragment);
        },

        zaladujHarmonogram() {
            $('#srl-wybrany-dzien-info').html(`<p><strong>Wybrany dzień:</strong> ${this.formatujDate(this.state.wybranaDana)}</p>`);
            
            const cacheKey = this.getCacheKey('schedule', this.state.wybranaDana);
            const cached = this.getCached(cacheKey);
            
            if (cached) {
                this.wygenerujHarmonogram(cached);
                return;
            }

            $('#srl-harmonogram-frontend').html('<div class="srl-loader">Ładowanie dostępnych godzin...</div>');

            this.cachedAjax(srlFrontend.ajaxurl, {
                action: 'srl_pobierz_dostepne_godziny',
                nonce: srlFrontend.nonce,
                data: this.state.wybranaDana
            }, { cacheTtl: 300000 })
            .then(response => {
                if (response.success) {
                    this.setCache(cacheKey, response.data, 300000);
                    this.wygenerujHarmonogram(response.data);
                } else {
                    $('#srl-harmonogram-frontend').html(`<p class="srl-komunikat srl-komunikat-error">Błąd: ${response.data}</p>`);
                }
            })
            .catch(() => {
                $('#srl-harmonogram-frontend').html('<p class="srl-komunikat srl-komunikat-error">Błąd połączenia z serwerem.</p>');
            });
        },

        wygenerujHarmonogram(sloty) {
            if (!sloty?.length) {
                $('#srl-harmonogram-frontend').html('<p class="srl-komunikat srl-komunikat-info">Brak dostępnych godzin w tym dniu.</p>');
                return;
            }

            const fragment = document.createDocumentFragment();
            const container = document.createElement('div');
            container.className = 'srl-godziny-grid';

            sloty.forEach(slot => {
                const slotDiv = document.createElement('div');
                slotDiv.className = 'srl-slot-godzina';
                slotDiv.dataset.slotId = slot.id;
                slotDiv.innerHTML = `
                    <div class="srl-slot-czas">${slot.godzina_start.substring(0, 5)} - ${slot.godzina_koniec.substring(0, 5)}</div>
                    <div class="srl-slot-pilot">Pilot ${slot.pilot_id}</div>
                `;
                container.appendChild(slotDiv);
            });

            fragment.appendChild(container);
            $('#srl-harmonogram-frontend').empty().append(fragment);
        },

        wybierzSlot(slotId, element) {
            $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
            element.addClass('srl-slot-wybrany');
            this.state.wybranySlot = slotId;
            $('#srl-dalej-krok-4').show();
            this.zablokujSlotTymczasowo(slotId);
        },

        zablokujSlotTymczasowo(slotId) {
            $.post(srlFrontend.ajaxurl, {
                action: 'srl_zablokuj_slot_tymczasowo',
                nonce: srlFrontend.nonce,
                termin_id: slotId
            })
            .then(response => {
                if (response.success) {
                    this.state.tymczasowaBlokada = response.data;
                    this.pokazKomunikat('Termin został zarezerwowany na 15 minut.', 'info');
                    
                    setTimeout(() => {
                        this.pokazKomunikat('Blokada terminu wygasła. Wybierz termin ponownie.', 'warning');
                        $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                        $('#srl-dalej-krok-4').hide();
                        this.state.wybranySlot = null;
                        this.state.tymczasowaBlokada = null;
                    }, 15 * 60 * 1000);
                } else {
                    this.pokazKomunikat('Błąd blokady: ' + response.data, 'error');
                    $('.srl-slot-wybrany').removeClass('srl-slot-wybrany');
                    this.state.wybranySlot = null;
                    $('#srl-dalej-krok-4').hide();
                }
            })
            .catch(() => {
                this.pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            });
        },

        pokazKrok4() {
            const { wybranyLot, daneKlienta, tymczasowaBlokada, wybranaDana } = this.state;
            const lot = daneKlienta.dostepne_loty.find(l => l.id == wybranyLot);
            const slotInfo = tymczasowaBlokada?.slot;

            let html = '<div class="srl-podsumowanie-box" style="background:#f8f9fa; padding:30px; border-radius:8px; margin:20px 0;">';
            html += '<h3 style="margin-top:0; color:#0073aa;">📋 Podsumowanie rezerwacji</h3>';
            html += '<div class="srl-podsumowanie-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin:20px 0;">';
            html += `<div><strong>🎫 Wybrany lot:</strong><br>#${lot.order_id} – ${this.escapeHtml(lot.nazwa_produktu)}</div>`;
            html += `<div><strong>📅 Data lotu:</strong><br>${this.formatujDate(wybranaDana)}</div>`;

            if (slotInfo) {
                html += `<div><strong>⏰ Godzina:</strong><br>${slotInfo.godzina_start.substring(0, 5)} - ${slotInfo.godzina_koniec.substring(0, 5)}</div>`;
                html += `<div><strong>👨‍✈️ Pilot:</strong><br>Pilot ${slotInfo.pilot_id}</div>`;
            }

            html += '</div>';
            html += this.generatePassengerSummary();
            html += this.generateImportantInfo();
            html += '</div>';

            $('#srl-podsumowanie-rezerwacji').html(html);
        },

        generatePassengerSummary() {
            const currentYear = new Date().getFullYear();
            const rokUrodzenia = $('#srl-rok-urodzenia').val();
            const wiek = rokUrodzenia ? currentYear - parseInt(rokUrodzenia) : '';
            const uwagi = $('#srl-uwagi').val();

            let html = '<div style="background:white; padding:20px; border-radius:8px; margin-top:20px;">';
            html += '<h4 style="margin-top:0; color:#333;">🪪 Dane pasażera:</h4>';
            html += `<p><strong>Imię i nazwisko:</strong> ${$('#srl-imie').val()} ${$('#srl-nazwisko').val()}</p>`;
            if (wiek) html += `<p><strong>Wiek:</strong> ${wiek} lat</p>`;
            html += `<p><strong>Waga:</strong> ${$('#srl-waga').val()} kg</p>`;
            html += `<p><strong>Wzrost:</strong> ${$('#srl-wzrost').val()} cm</p>`;
            html += `<p><strong>Telefon:</strong> ${$('#srl-telefon').val()}</p>`;
            if (uwagi) html += `<p><strong>Uwagi:</strong> ${this.escapeHtml(uwagi)}</p>`;
            html += '</div>';
            return html;
        },

        generateImportantInfo() {
            return `<div class="srl-uwaga" style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:20px; margin-top:20px;">
                <h4 style="margin-top:0; color:#f57c00;">⚠️ Ważne informacje:</h4>
                <ul style="margin:0; padding-left:20px;">
                    <li>Zgłoś się 30 minut przed godziną lotu</li>
                    <li>Weź ze sobą dokument tożsamości</li>
                    <li>Ubierz się stosownie do warunków pogodowych</li>
                    <li>Rezerwację można anulować do 48h przed lotem</li>
                </ul>
            </div>`;
        },

        dokonajRezerwacji() {
            if (!this.state.wybranySlot || !this.state.wybranyLot) {
                this.pokazKomunikat('Brak wybranych danych do rezerwacji.', 'error');
                return;
            }

            const btn = $('#srl-potwierdz-rezerwacje');
            btn.prop('disabled', true).text('Finalizowanie rezerwacji...');

            $.post(srlFrontend.ajaxurl, {
                action: 'srl_dokonaj_rezerwacji',
                nonce: srlFrontend.nonce,
                termin_id: this.state.wybranySlot,
                lot_id: this.state.wybranyLot
            })
            .then(response => {
                if (response.success) {
                    this.invalidateCache('client_data');
                    this.invalidateCache('month');
                    this.invalidateCache('schedule');
                    this.pokazKomunikatSukcesu();
                } else {
                    this.pokazKomunikat('Błąd rezerwacji: ' + response.data, 'error');
                }
            })
            .catch(() => {
                this.pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            })
            .always(() => {
                if (!response?.success) {
                    btn.prop('disabled', false).text('🎯 Potwierdź rezerwację');
                }
            });
        },

        pokazKomunikatSukcesu() {
            const html = `<div class="srl-komunikat srl-komunikat-success" style="text-align:center; padding:40px;">
                <h2 style="color:#46b450; margin-bottom:20px;">🎉 Rezerwacja potwierdzona!</h2>
                <p style="font-size:18px; margin-bottom:30px;">Twój lot tandemowy został zarezerwowany na <strong>${this.formatujDate(this.state.wybranaDana)}</strong></p>
                <p>Na podany adres email została wysłana informacja z szczegółami rezerwacji.</p>
                <div style="margin-top:30px;">
                    <a href="${window.location.href}" class="srl-btn srl-btn-primary">Zarezerwuj kolejny lot</a>
                </div>
            </div>`;
            $('#srl-rezerwacja-container').html(html);
        },

        wykorzystajVoucher() {
            const kod = $('#srl-voucher-code').val().trim();
            if (kod.length < 1) {
                this.pokazKomunikat('Wprowadź kod vouchera.', 'error');
                return;
            }

            const button = $('#srl-voucher-submit');
            button.prop('disabled', true).text('Sprawdzanie...');

            $.post(srlFrontend.ajaxurl, {
                action: 'srl_wykorzystaj_voucher',
                kod_vouchera: kod,
                nonce: srlFrontend.nonce
            })
            .then(response => {
                if (response.success) {
                    this.pokazKomunikat(response.data.message, 'success');
                    setTimeout(() => {
                        this.invalidateCache('client_data');
                        this.zaladujDaneKlienta();
                        $('#srl-voucher-form').hide();
                        $('#srl-voucher-show').show();
                        $('#srl-voucher-code').val('');
                    }, 2000);
                } else {
                    this.pokazKomunikat(response.data, 'error');
                }
            })
            .catch(() => {
                this.pokazKomunikat('Błąd połączenia z serwerem.', 'error');
            })
            .always(() => {
                button.prop('disabled', false).text('Zatwierdź voucher');
            });
        },

        pokazKomunikat(tekst, typ) {
            const html = `<div class="srl-komunikat srl-komunikat-${typ}">${tekst}</div>`;
            $('#srl-komunikaty').html(html);
            
            setTimeout(() => {
                $('#srl-komunikaty').fadeOut();
            }, 5000);
        },

        ukryjKomunikat() {
            $('#srl-komunikaty').empty();
        },

        formatujDate(dataStr) {
            const data = new Date(dataStr);
            const nazwyDni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
            const nazwyMiesiecy = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 
                               'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
            return `${nazwyDni[data.getDay()]}, ${data.getDate()} ${nazwyMiesiecy[data.getMonth()]} ${data.getFullYear()}`;
        },

        pad2(n) {
            return n < 10 ? '0' + n : '' + n;
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        cleanup() {
            $(document).off('.srl-calendar .srl-voucher');
            this.cache.clear();
            this.ajaxQueue.clear();
            this.debounceTimers.forEach(timer => clearTimeout(timer));
            this.debounceTimers.clear();
        }
    };

    SRLAdminCalendar.init();

    window.addEventListener('beforeunload', () => {
        SRLAdminCalendar.cleanup();
    });
});