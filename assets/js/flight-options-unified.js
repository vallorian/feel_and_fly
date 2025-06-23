// PLIK: assets/js/flight-options-unified.js
// ZASTĄP całą zawartość pliku na:

jQuery(document).ready(function($) {
    const SRLOpcje = {
        config: {
            ajaxUrl: typeof srlFrontend !== 'undefined' ? srlFrontend.ajaxurl : ajaxurl,
            nonce: typeof srlFrontend !== 'undefined' ? srlFrontend.nonce : '',
            productIds: typeof srlFrontend !== 'undefined' ? srlFrontend.productIds : {}
        },

        cache: new Map(),
        pendingRequests: new Map(),
        debounceTimers: new Map(),

        ajax: function(action, data = {}, options = {}) {
            const defaults = {
                method: 'POST',
                pokazKomunikat: true,
                useCache: true,
                debounceKey: null,
                debounceDelay: 300,
                onSuccess: null,
                onError: null
            };
            const opts = {...defaults, ...options};

            const cacheKey = opts.useCache ? `${action}_${JSON.stringify(data)}` : null;
            
            if (cacheKey && this.cache.has(cacheKey)) {
                const cached = this.cache.get(cacheKey);
                if (Date.now() - cached.timestamp < 300000) {
                    if (opts.onSuccess) opts.onSuccess(cached.data);
                    return Promise.resolve(cached.data);
                } else {
                    this.cache.delete(cacheKey);
                }
            }

            if (opts.debounceKey) {
                clearTimeout(this.debounceTimers.get(opts.debounceKey));
                return new Promise((resolve, reject) => {
                    this.debounceTimers.set(opts.debounceKey, setTimeout(() => {
                        this.executeAjax(action, data, opts, cacheKey).then(resolve).catch(reject);
                    }, opts.debounceDelay));
                });
            }

            return this.executeAjax(action, data, opts, cacheKey);
        },

        executeAjax: function(action, data, opts, cacheKey) {
            const requestKey = `${action}_${JSON.stringify(data)}`;
            
            if (this.pendingRequests.has(requestKey)) {
                return this.pendingRequests.get(requestKey);
            }

            const request = $.ajax({
                url: this.config.ajaxUrl,
                method: opts.method,
                data: {
                    action: action,
                    nonce: this.config.nonce,
                    ...data
                }
            }).done(response => {
                if (response && !response.error) {
                    if (cacheKey && opts.useCache) {
                        this.cache.set(cacheKey, {
                            data: response,
                            timestamp: Date.now()
                        });
                    }
                    if (opts.onSuccess) opts.onSuccess(response);
                } else {
                    const message = response?.data || 'Błąd dodawania do koszyka';
                    if (opts.pokazKomunikat) this.pokazKomunikatOpcji('❌ ' + message, 'error');
                    if (opts.onError) opts.onError(message);
                }
            }).fail(() => {
                const message = 'Błąd połączenia z serwerem';
                if (opts.pokazKomunikat) this.pokazKomunikatOpcji('❌ ' + message, 'error');
                if (opts.onError) opts.onError(message);
            }).always(() => {
                this.pendingRequests.delete(requestKey);
            });

            this.pendingRequests.set(requestKey, request);
            return request;
        },

        pokazKomunikatOpcji: function(tekst, typ = 'success') {
            if ($('#srl-cart-notification').length) {
                const bgColor = typ === 'success' ? '#46b450' : '#d63638';
                $('#srl-cart-message').html(tekst);
                $('#srl-cart-notification').css('background', bgColor).fadeIn().delay(3000).fadeOut();
            } else if (typeof pokazKomunikat === 'function') {
                pokazKomunikat(tekst, typ);
            } else {
                this.stworzFloatingNotification(tekst, typ);
            }
        },

        stworzFloatingNotification: function(tekst, typ) {
            const bgColor = typ === 'success' ? '#46b450' : '#d63638';
            const notification = $(`
                <div style="position:fixed; top:20px; right:20px; background:${bgColor}; color:white; 
                     padding:15px 20px; border-radius:8px; z-index:9999; 
                     box-shadow:0 4px 12px rgba(0,0,0,0.3);">${tekst}</div>
            `);
            
            $('body').append(notification);
            setTimeout(() => notification.fadeOut(() => notification.remove()), 5000);
        },

        pobierzNazweOpcji: function(productId, typ = 'krotka') {
            const nazwy = {
                [this.config.productIds.filmowanie]: { krotka: 'Filmowanie', pelna: 'Filmowanie lotu' },
                [this.config.productIds.akrobacje]: { krotka: 'Akrobacje', pelna: 'Akrobacje podczas lotu' },
                [this.config.productIds.przedluzenie]: { krotka: 'Przedłużenie', pelna: 'Przedłużenie ważności' }
            };
            return nazwy[productId]?.[typ] || 'Opcja lotu';
        },

        znajdzPrzycisk: function(lotId, productId) {
            const czyKrok2 = $('#srl-wybrany-lot-szczegoly').is(':visible') && $('#srl-wybrany-lot-szczegoly').length > 0;
            
            let button;
            
            if (czyKrok2) {
                button = $(`#srl-wybrany-lot-szczegoly #srl-opcja-${lotId}-${productId}`);
                if (!button.length) {
                    button = $(`#srl-wybrany-lot-szczegoly .srl-add-option[data-lot-id="${lotId}"][data-product-id="${productId}"]`);
                }
            } else {
                button = $(`#srl-opcja-${lotId}-${productId}`);
                if (!button.length) {
                    button = $(`.srl-add-option[data-lot-id="${lotId}"][data-product-id="${productId}"]`);
                }
                if (!button.length) {
                    button = $(`button[onclick*="srlDodajOpcjeLotu(${lotId}, ${productId})"]`);
                }
            }
            
            return button.length > 1 ? button.first() : button;
        },

        przemieniPrzyciskNaWKoszyku: function(button, lotId, productId, optionName) {
            if (button.hasClass('srl-btn-warning') && button.find('.srl-remove-from-cart').length > 0) {
                return;
            }

            const krotkanazwa = this.pobierzNazweOpcji(productId, 'krotka');
            
            button.removeClass('srl-btn-secondary srl-btn-success button-small').addClass('srl-btn-warning')
                  .css({
                      'background': '#ff9800',
                      'border-color': '#ff9800',
                      'color': 'white',
                      'pointer-events': 'auto',
                      'opacity': '1'
                  });

            const nowaZawartosc = `+ ${krotkanazwa} (w koszyku) ` +
                `<span class="srl-remove-from-cart" ` +
                `data-lot-id="${lotId}" ` +
                `data-product-id="${productId}" ` +
                `style="margin-left: 10px; cursor: pointer; font-weight: bold; font-size: 14px; padding: 2px 6px; border-radius: 50%; transition: all 0.2s ease;" ` +
                `onmouseenter="this.style.backgroundColor='#dc3545'; this.style.color='white';" ` +
                `onmouseleave="this.style.backgroundColor=''; this.style.color='white';">✕</span>`;

            button.html(nowaZawartosc).removeAttr('onclick');
        },

        przywrocPrzyciskDoOryginalnegoStanu: function(button, lotId, productId) {
            const optionName = this.pobierzNazweOpcji(productId, 'krotka');
            const pelnaName = this.pobierzNazweOpcji(productId, 'pelna');
            const isStep2Button = button.attr('style') && button.attr('style').includes('padding: 8px 16px');
            
            if (isStep2Button) {
                button.removeClass('srl-btn-warning srl-btn-secondary').addClass('srl-btn-success button-small')
                      .css({ 'background': '', 'border-color': '', 'color': '', 'opacity': '1' })
                      .html(`+ ${optionName}`)
                      .prop('disabled', false)
                      .attr('onclick', `srlDodajOpcjeLotu(${lotId}, ${productId}, '${pelnaName}')`);
            } else {
                button.removeClass('srl-btn-warning srl-btn-success').addClass('srl-btn-secondary button-small')
                      .css({ 'background': '', 'border-color': '', 'color': '', 'opacity': '1' })
                      .html(`+ ${optionName}`)
                      .prop('disabled', false)
                      .attr('onclick', `srlDodajOpcjeLotu(${lotId}, ${productId}, '${pelnaName}')`);
            }
        },

        sprawdzOpcjeWKoszyku: function() {
            if (typeof this.config.productIds === 'undefined' || !this.config.nonce) {
                return;
            }

            this.ajax('srl_sprawdz_opcje_w_koszyku', {}, {
                pokazKomunikat: false,
                useCache: true,
                debounceKey: 'check_cart_options',
                onSuccess: (response) => {
                    if (response.success && response.data) {
                        $.each(response.data, (lotId, opcje) => {
                            if (opcje.filmowanie) {
                                this.oznaczOpcjeJakoWKoszyku(lotId, this.config.productIds.filmowanie, 'Filmowanie lotu');
                            }
                            if (opcje.akrobacje) {
                                this.oznaczOpcjeJakoWKoszyku(lotId, this.config.productIds.akrobacje, 'Akrobacje podczas lotu');
                            }
                            if (opcje.przedluzenie) {
                                this.oznaczOpcjeJakoWKoszyku(lotId, this.config.productIds.przedluzenie, 'Przedłużenie ważności');
                            }
                        });
                    }
                }
            });
        },

        oznaczOpcjeJakoWKoszyku: function(lotId, productId, optionName) {
            const button = this.znajdzPrzycisk(lotId, productId);
            if (button.length) {
                this.przemieniPrzyciskNaWKoszyku(button, lotId, productId, optionName);
            }
        },

        podlaczEventListenery: function() {
            $(document).off('click', '.srl-remove-from-cart');
            $('.srl-remove-from-cart').closest('button').prop('disabled', false);

            $(document).on('click', '.srl-remove-from-cart', (e) => {
                e.stopImmediatePropagation();
                e.stopPropagation();
                e.preventDefault();

                const lotId = $(e.currentTarget).data('lot-id');
                const productId = $(e.currentTarget).data('product-id');
                const button = $(e.currentTarget).closest('button');

                if (!lotId || !productId || !this.config.nonce) {
                    this.pokazKomunikatOpcji('❌ Błąd: Brak danych opcji do usunięcia', 'error');
                    return;
                }

                const originalHtml = button.html();
                button.html('Usuwanie...').css('opacity', '0.6');

                this.ajax('srl_usun_opcje_z_koszyka', {
                    lot_id: lotId,
                    product_id: productId
                }, {
                    pokazKomunikat: false,
                    useCache: false,
                    onSuccess: (response) => {
                        if (response.success) {
                            this.przywrocPrzyciskDoOryginalnegoStanu(button, lotId, productId);
                            this.pokazKomunikatOpcji('✅ Opcja usunięta z koszyka', 'success');
                            
                            this.cache.clear();
                            $(document).trigger('srl_opcje_zmienione');
                            
                            if (typeof wc_add_to_cart_params !== 'undefined') {
                                $(document.body).trigger('wc_fragment_refresh');
                            }
                        } else {
                            this.pokazKomunikatOpcji('❌ Błąd usuwania: ' + response.data, 'error');
                            button.html(originalHtml).css('opacity', '1');
                        }
                    },
                    onError: () => {
                        button.html(originalHtml).css('opacity', '1');
                    }
                });
            });
        },

        clearCache: function() {
            this.cache.clear();
        }
    };

    window.srlDodajOpcjeLotu = function(lotId, productId, optionName) {
        const button = SRLOpcje.znajdzPrzycisk(lotId, productId);
        
        if (button.hasClass('srl-btn-warning') || button.prop('disabled')) {
            return false;
        }

        const originalText = button.text();
        button.text('Dodawanie...').prop('disabled', true);

        SRLOpcje.ajax('srl_sprawdz_i_dodaj_opcje', {
            product_id: productId,
            quantity: 1,
            srl_lot_id: lotId
        }, {
            pokazKomunikat: false,
            useCache: false,
            onSuccess: (response) => {
                if (response && !response.error) {
                    SRLOpcje.pokazKomunikatOpcji(`✅ Dodano "${optionName}" do koszyka!`, 'success');
                    SRLOpcje.przemieniPrzyciskNaWKoszyku(button, lotId, productId, optionName);
                    SRLOpcje.clearCache();
                    $(document).trigger('srl_opcje_zmienione');
                    if (typeof wc_add_to_cart_params !== 'undefined') {
                        $(document.body).trigger('wc_fragment_refresh');
                    }
                } else {
                    SRLOpcje.pokazKomunikatOpcji('❌ Błąd dodawania do koszyka', 'error');
                    button.text(originalText).prop('disabled', false);
                }
            },
            onError: () => {
                button.text(originalText).prop('disabled', false);
            }
        });
    };

    window.SRLOpcje = SRLOpcje;

    SRLOpcje.podlaczEventListenery();

    $(document).on('srl_opcje_zmienione', function() {
        setTimeout(() => SRLOpcje.podlaczEventListenery(), 100);
    });

    setTimeout(() => SRLOpcje.sprawdzOpcjeWKoszyku(), 1000);

    $(document).on('srl_dane_klienta_zaladowane', function() {
        setTimeout(function() {
            SRLOpcje.sprawdzOpcjeWKoszyku();
            SRLOpcje.podlaczEventListenery();
        }, 500);
    });
});