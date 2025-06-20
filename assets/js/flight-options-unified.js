jQuery(document).ready(function($) {
    const FlightOptions = {
        // Cache elementów
        $cart: null,
        
        init() {
            this.cacheElements();
            this.bindEvents();
            this.checkExistingOptions();
        },
        
        cacheElements() {
            this.$cart = $('#srl-cart-notification');
        },
        
        bindEvents() {
            // Unified event handler dla usuwania opcji
            $(document).off('click', '.srl-remove-from-cart').on('click', '.srl-remove-from-cart', e => this.handleRemoveOption(e));
            
            // Trigger events
            $(document).on('srl_opcje_zmienione', () => setTimeout(() => this.bindEvents(), 100));
            $(document).on('srl_dane_klienta_zaladowane', () => setTimeout(() => {
                this.checkExistingOptions();
                this.bindEvents();
            }, 500));
        },
        
        // Główna funkcja dodawania opcji (globalna)
        addOption(lotId, productId, optionName) {
            const $button = this.getOptionButton(lotId, productId);
            if (!$button.length || $button.hasClass('srl-btn-warning') || $button.prop('disabled')) {
                return false;
            }
            
            const originalText = $button.text();
            $button.text('Dodawanie...').prop('disabled', true);
            
            return this.ajax('srl_sprawdz_i_dodaj_opcje', {
                product_id: productId,
                quantity: 1,
                srl_lot_id: lotId
            }).then(response => {
                if (response && !response.error) {
                    this.showMessage(`✅ Dodano "${optionName}" do koszyka!`, 'success');
                    this.convertToCartButton($button, lotId, productId, optionName);
                    this.triggerEvents();
                    return true;
                } else {
                    this.showMessage('❌ Błąd dodawania do koszyka', 'error');
                    return false;
                }
            }).catch(() => {
                this.showMessage('❌ Błąd połączenia z serwerem', 'error');
                return false;
            }).finally(() => {
                $button.text(originalText).prop('disabled', false);
            });
        },
        
        // Usuwanie opcji z koszyka
        handleRemoveOption(e) {
            e.stopImmediatePropagation();
            e.preventDefault();
            
            const $trigger = $(e.currentTarget);
            const lotId = $trigger.data('lot-id');
            const productId = $trigger.data('product-id');
            const $button = $trigger.closest('button');
            
            if (!lotId || !productId) {
                this.showMessage('❌ Błąd: Brak danych opcji do usunięcia', 'error');
                return;
            }
            
            const originalHtml = $button.html();
            $button.html('Usuwanie...').css('opacity', '0.6');
            
            this.ajax('srl_usun_opcje_z_koszyka', {
                lot_id: lotId,
                product_id: productId
            }).then(response => {
                if (response.success) {
                    this.revertToOriginalButton($button, lotId, productId);
                    this.triggerEvents();
                    this.showMessage('✅ Opcja usunięta z koszyka', 'success');
                } else {
                    this.showMessage(`❌ Błąd usuwania: ${response.data}`, 'error');
                    $button.html(originalHtml).css('opacity', '1');
                }
            }).catch(() => {
                this.showMessage('❌ Błąd połączenia z serwerem', 'error');
                $button.html(originalHtml).css('opacity', '1');
            });
        },
        
        // Sprawdzanie istniejących opcji w koszyku
        checkExistingOptions() {
            if (!srlFrontend?.productIds || !srlFrontend.nonce) return;
            
            this.ajax('srl_sprawdz_opcje_w_koszyku').then(response => {
                if (response.success && response.data) {
                    Object.entries(response.data).forEach(([lotId, options]) => {
                        if (options.filmowanie) this.markAsInCart(lotId, srlFrontend.productIds.filmowanie, 'Filmowanie lotu');
                        if (options.akrobacje) this.markAsInCart(lotId, srlFrontend.productIds.akrobacje, 'Akrobacje podczas lotu');
                        if (options.przedluzenie) this.markAsInCart(lotId, srlFrontend.productIds.przedluzenie, 'Przedłużenie ważności');
                    });
                }
            });
        },
        
        // Konwersja przycisku na stan "w koszyku"
        convertToCartButton($button, lotId, productId, optionName) {
            $button.removeClass('srl-btn-secondary button-small').addClass('srl-btn-warning');
            $button.css({
                background: '#ff9800',
                'border-color': '#ff9800',
                color: 'white',
                'pointer-events': 'auto',
                opacity: '1'
            });
            
            const shortName = this.getShortOptionName(optionName);
            const newContent = `+ ${shortName} (w koszyku) ${this.createRemoveButton(lotId, productId)}`;
            
            $button.html(newContent).removeAttr('onclick');
        },
        
        // Przywrócenie oryginalnego stanu przycisku
        revertToOriginalButton($button, lotId, productId) {
            const optionName = this.getOptionNameByProductId(productId);
            const fullName = this.getFullOptionNameByProductId(productId);
            
            $button.removeClass('srl-btn-warning').addClass('srl-btn-secondary button-small');
            $button.css({
                background: '',
                'border-color': '',
                color: '',
                opacity: '1'
            });
            
            $button.html(`+ ${optionName}`).prop('disabled', false);
            $button.attr('onclick', `srlDodajOpcjeLotu(${lotId}, ${productId}, '${fullName}')`);
        },
        
        // Oznaczanie jako w koszyku
        markAsInCart(lotId, productId, optionName) {
            const $button = this.getOptionButton(lotId, productId);
            if ($button.length) {
                this.convertToCartButton($button, lotId, productId, optionName);
            }
        },
        
        // Utility functions
        getOptionButton(lotId, productId) {
            return $(`#srl-opcja-${lotId}-${productId}`)
                .add($(`.srl-add-option[data-lot-id="${lotId}"][data-product-id="${productId}"]`))
                .add($(`button[onclick*="srlDodajOpcjeLotu(${lotId}, ${productId})"]`));
        },
        
        getShortOptionName(optionName) {
            const shortNames = {
                'Filmowanie lotu': 'Filmowanie',
                'Akrobacje podczas lotu': 'Akrobacje',
                'Przedłużenie ważności': 'Przedłużenie'
            };
            return shortNames[optionName] || optionName;
        },
        
        getOptionNameByProductId(productId) {
            const names = {
                [srlFrontend.productIds.filmowanie]: 'Filmowanie',
                [srlFrontend.productIds.akrobacje]: 'Akrobacje',
                [srlFrontend.productIds.przedluzenie]: 'Przedłużenie'
            };
            return names[productId] || 'Opcja lotu';
        },
        
        getFullOptionNameByProductId(productId) {
            const names = {
                [srlFrontend.productIds.filmowanie]: 'Filmowanie lotu',
                [srlFrontend.productIds.akrobacje]: 'Akrobacje podczas lotu',
                [srlFrontend.productIds.przedluzenie]: 'Przedłużenie ważności'
            };
            return names[productId] || 'Opcja lotu';
        },
        
        createRemoveButton(lotId, productId) {
            return `<span class="srl-remove-from-cart" 
                data-lot-id="${lotId}" 
                data-product-id="${productId}" 
                style="margin-left: 10px; cursor: pointer; font-weight: bold; font-size: 14px; padding: 2px 6px; border-radius: 50%; transition: all 0.2s ease;" 
                onmouseenter="this.style.backgroundColor='#dc3545'; this.style.color='white';" 
                onmouseleave="this.style.backgroundColor=''; this.style.color='white';">✕</span>`;
        },
        
        // AJAX wrapper
        ajax(action, data = {}) {
            const ajaxUrl = typeof srlFrontend !== 'undefined' ? srlFrontend.ajaxurl : ajaxurl;
            const nonce = typeof srlFrontend !== 'undefined' ? srlFrontend.nonce : '';
            
            return $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action,
                    nonce,
                    ...data
                }
            });
        },
        
        // Pokazywanie komunikatów
        showMessage(text, type) {
            if (this.$cart.length) {
                const bgColor = type === 'success' ? '#46b450' : '#d63638';
                this.$cart.find('#srl-cart-message').html(text);
                this.$cart.css('background', bgColor).fadeIn().delay(3000).fadeOut();
            } else if (typeof pokazKomunikat === 'function') {
                pokazKomunikat(text, type);
            } else {
                const bgColor = type === 'success' ? '#46b450' : '#d63638';
                const $notification = $(`<div style="position:fixed; top:20px; right:20px; background:${bgColor}; color:white; padding:15px 20px; border-radius:8px; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.3);">${text}</div>`);
                $('body').append($notification);
                setTimeout(() => $notification.fadeOut(() => $notification.remove()), 5000);
            }
        },
        
        // Trigger events
        triggerEvents() {
            $(document).trigger('srl_opcje_zmienione');
            if (typeof wc_add_to_cart_params !== 'undefined') {
                $(document.body).trigger('wc_fragment_refresh');
            }
        }
    };
    
    // Inicjalizacja
    FlightOptions.init();
    
    // Globalna funkcja dla kompatybilności wstecznej
    window.srlDodajOpcjeLotu = (lotId, productId, optionName) => {
        return FlightOptions.addOption(lotId, productId, optionName);
    };
});