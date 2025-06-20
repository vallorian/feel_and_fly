jQuery(document).ready(function($) {
    // Centralne zarządzanie stanem aplikacji
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
            tymczasowaBlokada: null
        },
        
        // Cache dla elementów DOM
        $elements: {},
        
        // Inicjalizacja
        init() {
            this.cacheElements();
            this.bindEvents();
            this.loadClientData();
        },
        
        // Cache często używanych elementów
        cacheElements() {
            this.$elements = {
                container: $('#srl-rezerwacja-container'),
                steps: $('.srl-step'),
                kroki: $('.srl-krok'),
                komunikaty: $('#srl-komunikaty'),
                formPasazera: $('#srl-formularz-pasazera'),
                kalendarz: $('#srl-kalendarz-tabela'),
                harmonogram: $('#srl-harmonogram-frontend')
            };
        },
        
        // Centralne bindowanie eventów
        bindEvents() {
            // Navigation events
            this.$elements.steps.on('click', e => this.handleStepClick(e));
            $('#srl-poprzedni-miesiac').on('click', () => this.changeMonth(-1));
            $('#srl-nastepny-miesiac').on('click', () => this.changeMonth(1));
            
            // Form events
            this.$elements.formPasazera.on('submit', e => this.handleFormSubmit(e));
            $(document).on('click', '.srl-wybierz-lot', e => this.selectFlight(e));
            $(document).on('click', '.srl-anuluj-rezerwacje', e => this.cancelReservation(e));
            
            // Validation events
            $(document).on('change', '#srl-rok-urodzenia', () => this.validateAge());
            $(document).on('change', '#srl-kategoria-wagowa', () => this.validateWeight());
            
            // Voucher events
            this.bindVoucherEvents();
            
            // Button navigation
            $('#srl-powrot-krok-1').on('click', () => this.showStep(1));
            $('#srl-powrot-krok-2').on('click', () => this.showStep(2));
            $('#srl-powrot-krok-3').on('click', () => this.showStep(3));
            $('#srl-powrot-krok-4').on('click', () => this.showStep(4));
            $('#srl-dalej-krok-5').on('click', () => this.showStep(5));
            $('#srl-potwierdz-rezerwacje').on('click', () => this.confirmReservation());
        },
        
        // Voucher events w jednym miejscu
        bindVoucherEvents() {
            const voucherEvents = {
                '#srl-voucher-show': () => this.showVoucherForm(),
                '#srl-voucher-cancel': () => this.hideVoucherForm(),
                '#srl-voucher-submit': () => this.submitVoucher(),
                '#srl-partner-voucher-show': () => this.showPartnerVoucherModal(),
                '.srl-modal-close, #srl-partner-voucher-cancel': () => this.hidePartnerVoucherModal(),
                '#srl-partner-select': e => this.handlePartnerChange(e),
                '#srl-voucher-type-select': e => this.handleVoucherTypeChange(e),
                '#srl-partner-voucher-form': e => this.handlePartnerVoucherSubmit(e)
            };
            
            Object.entries(voucherEvents).forEach(([selector, handler]) => {
                $(document).on('click change submit', selector, handler);
            });
            
            $(document).on('input', '#srl-voucher-code', function() {
                $(this).val($(this).val().toUpperCase().replace(/[^A-Z0-9]/g, ''));
            });
        },
        
        // Zoptymalizowany AJAX wrapper
        ajax(action, data = {}, callback = null) {
            const requestData = {
                action,
                nonce: srlFrontend.nonce,
                ...data
            };
            
            return $.ajax({
                url: srlFrontend.ajaxurl,
                method: 'POST',
                data: requestData,
                success: callback || ((response) => {
                    if (!response.success) {
                        this.showMessage(response.data, 'error');
                    }
                }),
                error: () => this.showMessage('Błąd połączenia z serwerem.', 'error')
            });
        },
        
        // Zarządzanie krokami
        showStep(step) {
            if (step < 1 || step > 5 || step > this.state.maksymalnyKrok) return;
            
            this.$elements.steps.removeClass('srl-step-active srl-step-completed');
            $('.srl-progress-bar').attr('class', 'srl-progress-bar srl-progress-' + step);
            
            for (let i = 1; i <= 5; i++) {
                const $step = $(`.srl-step[data-step="${i}"]`);
                if (i < step) $step.addClass('srl-step-completed');
                else if (i === step) $step.addClass('srl-step-active');
            }
            
            this.$elements.kroki.removeClass('srl-krok-active');
            $(`#srl-krok-${step}`).addClass('srl-krok-active');
            
            this.state.aktualnyKrok = step;
            this.state.maksymalnyKrok = Math.max(this.state.maksymalnyKrok, step);
            
            $('html, body').animate({scrollTop: this.$elements.container.offset().top - 50}, 300);
            
            if (step === 5) this.prepareSummary();
        },
        
        handleStepClick(e) {
            const step = parseInt($(e.currentTarget).data('step'));
            if (step === 2 && !this.state.wybranyLot) {
                e.preventDefault();
                this.showMessage('Musisz najpierw wybrać lot do zarezerwowania.', 'error');
                return false;
            }
            this.showStep(step);
        },
        
        // Ładowanie danych klienta
        loadClientData() {
            this.showMessage('Ładowanie danych...', 'info');
            this.ajax('srl_pobierz_dane_klienta', {}, response => {
                this.hideMessage();
                if (response.success) {
                    this.state.daneKlienta = response.data;
                    window.daneKlienta = response.data;
                    this.populateClientData();
                    $(document).trigger('srl_dane_klienta_zaladowane');
                }
            });
        },
        
        // Wypełnianie danych klienta
        populateClientData() {
            this.updateGreeting();
            this.populateFlightsList();
            this.populatePersonalDataForm();
        },
        
        updateGreeting() {
            let greeting = 'Cześć';
            const data = this.state.daneKlienta?.dane_osobowe;
            if (data?.imie && data?.nazwisko) {
                greeting = `Cześć, ${data.imie} ${data.nazwisko}`;
            } else if (data?.imie) {
                greeting = `Cześć, ${data.imie}`;
            }
            $('#srl-krok-1 h2').text(greeting + '! 👋');
        },
        
        populateFlightsList() {
            const { rezerwacje = [], dostepne_loty = [] } = this.state.daneKlienta;
            const allFlights = [...rezerwacje, ...dostepne_loty].sort((a, b) => 
                a.status === 'zarezerwowany' && b.status !== 'zarezerwowany' ? -1 : 1
            );
            
            if (!allFlights.length) {
                $('#srl-lista-rezerwacji').html('<p class="srl-komunikat srl-komunikat-info">Nie masz żadnych lotów.</p>');
                return;
            }
            
            const html = this.generateFlightsTable(allFlights);
            $('#srl-lista-rezerwacji').html(html);
        },
        
        generateFlightsTable(flights) {
            const rows = flights.map(flight => {
                const options = this.formatFlightOptions(flight.ma_filmowanie, flight.ma_akrobacje);
                const status = this.generateStatusColumn(flight);
                const actions = this.generateActionsColumn(flight);
                
                return `<tr>
                    <td class="srl-kolumna-nazwa">
                        <div class="srl-nazwa-lotu">Lot w tandemie (#${flight.id})</div>
                        <div class="srl-opcje-lotu">${options}</div>
                        ${flight.data_waznosci ? `<div class="srl-data-waznosci">(Ważny do: ${this.formatDate(flight.data_waznosci)})</div>` : ''}
                    </td>
                    <td class="srl-kolumna-status">${status}</td>
                    <td class="srl-kolumna-opcje">${this.generateOptionsColumn(flight)}</td>
                    <td class="srl-kolumna-akcje">${actions}</td>
                </tr>`;
            }).join('');
            
            return `<table class="srl-tabela-lotow">
                <thead><tr>
                    <th class="srl-kolumna-nazwa">Nazwa</th>
                    <th class="srl-kolumna-status">Status i termin</th>
                    <th class="srl-kolumna-opcje">Opcje</th>
                    <th class="srl-kolumna-akcje">Akcje</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
        },
        
        formatFlightOptions(filming, acrobatics) {
            const options = [
                filming ? '<span style="color: #46b450; font-weight: bold;">z filmowaniem</span>' : '<span style="color: #d63638;">bez filmowania</span>',
                acrobatics ? '<span style="color: #46b450; font-weight: bold;">z akrobacjami</span>' : '<span style="color: #d63638;">bez akrobacji</span>'
            ];
            return options.join(', ');
        },
        
        generateStatusColumn(flight) {
            const badges = {
                'zarezerwowany': '<div class="srl-status-badge srl-status-zarezerwowany">Zarezerwowany</div>',
                'wolny': '<div class="srl-status-badge srl-status-wolny">Czeka na rezerwację</div>',
                'zrealizowany': '<div class="srl-status-badge srl-status-zrealizowany">Zrealizowany</div>',
                'przedawniony': '<div class="srl-status-badge srl-status-przedawniony">Przeterminowany</div>'
            };
            
            let html = badges[flight.status] || '';
            
            if ((flight.status === 'zarezerwowany' || flight.status === 'zrealizowany') && flight.data && flight.godzina_start) {
                html += this.formatFlightDateTime(flight.data, flight.godzina_start);
            }
            
            return html;
        },
        
        generateOptionsColumn(flight) {
            if (flight.status !== 'zarezerwowany' && flight.status !== 'wolny') return '—';
            
            const canModify = flight.status === 'wolny' || 
                (flight.status === 'zarezerwowany' && this.canCancelReservation(flight.data, flight.godzina_start));
            
            if (!canModify) return '<div class="srl-opcje-info">Za późno na zmiany</div>';
            
            const options = [];
            if (!flight.ma_filmowanie) {
                options.push(`<button class="srl-add-option srl-opcja-btn" data-lot-id="${flight.id}" data-product-id="${srlFrontend.productIds.filmowanie}" onclick="srlDodajOpcjeLotu(${flight.id}, ${srlFrontend.productIds.filmowanie}, 'Filmowanie')">+ Filmowanie</button>`);
            }
            if (!flight.ma_akrobacje) {
                options.push(`<button class="srl-add-option srl-opcja-btn" data-lot-id="${flight.id}" data-product-id="${srlFrontend.productIds.akrobacje}" onclick="srlDodajOpcjeLotu(${flight.id}, ${srlFrontend.productIds.akrobacje}, 'Akrobacje')">+ Akrobacje</button>`);
            }
            
            if (flight.status === 'wolny' && flight.data_waznosci) {
                const daysToExpiry = Math.floor((new Date(flight.data_waznosci).getTime() - Date.now()) / (24 * 60 * 60 * 1000));
                if (daysToExpiry <= 90) {
                    options.push(`<button class="srl-add-option srl-opcja-btn" data-lot-id="${flight.id}" data-product-id="${srlFrontend.productIds.przedluzenie}" onclick="srlDodajOpcjeLotu(${flight.id}, ${srlFrontend.productIds.przedluzenie}, 'Przedłużenie')">+ Przedłużenie</button>`);
                }
            }
            
            return options.length ? options.join('') : '—';
        },
        
        generateActionsColumn(flight) {
            if (flight.status === 'zarezerwowany') {
                const canCancel = this.canCancelReservation(flight.data, flight.godzina_start);
                return canCancel 
                    ? `<button class="srl-anuluj-rezerwacje srl-akcja-btn srl-btn-odwolaj" data-lot-id="${flight.id}">Odwołaj</button>`
                    : '<div class="srl-akcje-info">Za późno</div>';
            }
            
            if (flight.status === 'wolny') {
                return `<button class="srl-wybierz-lot srl-akcja-btn srl-btn-wybierz" data-lot-id="${flight.id}">Wybierz termin</button>`;
            }
            
            return '—';
        },
        
        // Walidacje
        validateAge() {
            const year = $('#srl-rok-urodzenia').val();
            if (!year) {
                this.hideAgeWarning();
                return;
            }
            
            this.ajax('srl_waliduj_wiek', { rok_urodzenia: year }, response => {
                if (response.success && response.data.html) {
                    this.showAgeWarning(response.data.html);
                } else {
                    this.hideAgeWarning();
                }
            });
        },
        
        validateWeight() {
            const category = $('#srl-kategoria-wagowa').val();
            if (!category) {
                this.hideWeightWarning();
                return;
            }
            
            this.ajax('srl_waliduj_kategorie_wagowa', { kategoria_wagowa: category }, response => {
                if (response.success && response.data.html) {
                    this.showWeightWarning(response.data.html);
                } else {
                    this.hideWeightWarning();
                }
            });
        },
        
        // Voucher handlers
        showVoucherForm() {
            $('#srl-voucher-show').hide();
            $('#srl-voucher-form').show();
            $('#srl-voucher-code').focus();
        },
        
        hideVoucherForm() {
            $('#srl-voucher-form').hide();
            $('#srl-voucher-show').show();
            $('#srl-voucher-code').val('');
        },
        
        submitVoucher() {
            const code = $('#srl-voucher-code').val().trim();
            if (code.length < 1) {
                this.showMessage('Wprowadź kod vouchera.', 'error');
                return;
            }
            
            const $button = $('#srl-voucher-submit');
            $button.prop('disabled', true).text('Sprawdzanie...');
            
            this.ajax('srl_wykorzystaj_voucher', { kod_vouchera: code }, response => {
                if (response.success) {
                    this.showMessage('Voucher został wykorzystany! Lot został dodany do Twojego konta.', 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    $button.prop('disabled', false).text('Zatwierdź voucher');
                }
            });
        },
        
        // Utility functions
        showMessage(text, type = 'info') {
            const classes = {
                info: 'srl-komunikat-info',
                success: 'srl-komunikat-success',
                warning: 'srl-komunikat-warning',
                error: 'srl-komunikat-error'
            };
            
            this.$elements.komunikaty.html(`<div class="srl-komunikat ${classes[type]}">${text}</div>`).show();
            setTimeout(() => this.$elements.komunikaty.fadeOut(() => this.$elements.komunikaty.empty()), 15000);
        },
        
        hideMessage() {
            this.$elements.komunikaty.empty();
        },
        
        formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('pl-PL');
        },
        
        formatFlightDateTime(date, time) {
            const flightDate = new Date(date);
            const dayNames = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
            const dayName = dayNames[flightDate.getDay()];
            const dateStr = flightDate.toLocaleDateString('pl-PL');
            const timeStr = time.substring(0, 5);
            
            return `<div class="srl-termin-info">
                <div class="srl-termin-data">${dateStr}, godz: ${timeStr}</div>
                <div class="srl-termin-dzien">${dayName}</div>
            </div>`;
        },
        
        canCancelReservation(flightDate, flightTime) {
            if (!flightDate || !flightTime) return false;
            const flightDateTime = new Date(flightDate + ' ' + flightTime);
            return (flightDateTime.getTime() - Date.now()) > (48 * 60 * 60 * 1000);
        },
        
        showAgeWarning(html) {
            let $container = $('#srl-wiek-ostrzezenie');
            if (!$container.length) {
                $container = $('<div id="srl-wiek-ostrzezenie"></div>');
                $('#srl-waga-ostrzezenie').before($container);
            }
            $container.html(html).show();
        },
        
        hideAgeWarning() {
            $('#srl-wiek-ostrzezenie').hide();
        },
        
        showWeightWarning(html) {
            $('#srl-waga-ostrzezenie').html(html).show();
        },
        
        hideWeightWarning() {
            $('#srl-waga-ostrzezenie').hide();
        }
    };
    
    // Inicjalizacja aplikacji
    SRL.init();
    
    // Eksport globalny dla kompatybilności wstecznej
    window.SRL = SRL;
    window.wybranyLot = null;
    window.daneKlienta = null;
});