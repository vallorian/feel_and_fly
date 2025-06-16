<?php

add_action('woocommerce_single_product_summary', 'srl_wyswietl_formularz_wyboru_lotu', 25);

function srl_wyswietl_formularz_wyboru_lotu() {
    global $product;

    $opcje_produkty = srl_get_flight_option_product_ids();

    if (!in_array($product->get_id(), $opcje_produkty)) {
        return; 
    }

    if (!is_user_logged_in()) {
        echo '<div class="woocommerce-message woocommerce-message--info">';
        echo '<p>Musisz być zalogowany, aby kupić opcje lotu. <a href="' . wp_login_url(get_permalink()) . '">Zaloguj się</a></p>';
        echo '</div>';
        return;
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';

    $dostepne_loty = array();

    if ($product->get_id() == $opcje_produkty['przedluzenie']) {

        $dostepne_loty = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tabela 
             WHERE user_id = %d 
             AND status IN ('wolny', 'zarezerwowany')
             AND data_waznosci >= CURDATE()
             ORDER BY data_zakupu DESC",
            $user_id
        ), ARRAY_A);
    } else {

        $dostepne_loty = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.*, t.data as data_lotu 
             FROM $tabela zl
             LEFT JOIN {$wpdb->prefix}srl_terminy t ON zl.termin_id = t.id
             WHERE zl.user_id = %d 
             AND zl.status IN ('wolny', 'zarezerwowany')
             AND zl.data_waznosci >= CURDATE()
             AND (t.data IS NULL OR t.data >= CURDATE())
             ORDER BY zl.data_zakupu DESC",
            $user_id
        ), ARRAY_A);

        if ($product->get_id() == $opcje_produkty['filmowanie']) {
            $dostepne_loty = array_filter($dostepne_loty, function($lot) {
                return empty($lot['ma_filmowanie']);
            });
        } elseif ($product->get_id() == $opcje_produkty['akrobacje']) {
            $dostepne_loty = array_filter($dostepne_loty, function($lot) {
                return empty($lot['ma_akrobacje']);
            });
        }
    }

    if (empty($dostepne_loty)) {
        echo '<div class="woocommerce-message woocommerce-message--info">';
        if ($product->get_id() == $opcje_produkty['przedluzenie']) {
            echo '<p>Nie masz lotów, dla których można przedłużyć ważność. <a href="/produkt/lot-w-tandemie/">Kup lot tandemowy</a></p>';
        } else {
            $opcja_nazwa = ($product->get_id() == $opcje_produkty['filmowanie']) ? 'filmowanie' : 'akrobacje';
            echo '<p>Nie masz lotów, do których można dodać ' . $opcja_nazwa . '. <a href="/produkt/lot-w-tandemie/">Kup lot tandemowy</a></p>';
        }
        echo '</div>';

        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        return;
    }

    echo '<div class="srl-flight-selection" style="margin: 20px 0; padding: 20px; border: 2px solid #0073aa; border-radius: 8px; background: #f8f9fa;">';
    echo '<h4 style="margin-top: 0; color: #0073aa;">Wybierz lot do modyfikacji:</h4>';

    if (count($dostepne_loty) == 1) {
        $lot = reset($dostepne_loty);
        echo '<div style="padding: 15px; background: white; border-radius: 6px; font-weight: 600;">';
        echo '#' . $lot['id'] . ' – ' . esc_html($lot['nazwa_produktu']);
        if ($lot['status'] === 'zarezerwowany' && !empty($lot['data_lotu'])) {
            echo '<br><small>Zarezerwowany na: ' . date('d.m.Y', strtotime($lot['data_lotu'])) . '</small>';
        }
        echo '<input type="hidden" id="srl_selected_flight" value="' . $lot['id'] . '">';
        echo '</div>';
    } else {
        echo '<select id="srl_selected_flight" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<option value="">Wybierz lot...</option>';
        foreach ($dostepne_loty as $lot) {
            $opis = '#' . $lot['id'] . ' – ' . esc_html($lot['nazwa_produktu']);
            if ($lot['status'] === 'zarezerwowany' && !empty($lot['data_lotu'])) {
                $opis .= ' (Zarezerwowany na: ' . date('d.m.Y', strtotime($lot['data_lotu'])) . ')';
            }
            echo '<option value="' . $lot['id'] . '">' . $opis . '</option>';
        }
        echo '</select>';
    }

    echo '</div>';

    ?>
    <script>
    jQuery(document).ready(function($) {

        $('form.cart').on('submit', function(e) {
            var selectedFlight = $('#srl_selected_flight').val();
            if (!selectedFlight) {
                e.preventDefault();
                alert('Wybierz lot do modyfikacji.');
                return false;
            }

            if (!$(this).find('input[name="srl_lot_id"]').length) {
                $(this).append('<input type="hidden" name="srl_lot_id" value="' + selectedFlight + '">');
            } else {
                $(this).find('input[name="srl_lot_id"]').val(selectedFlight);
            }
        });

        $('#srl_selected_flight').on('change', function() {
            var selectedFlight = $(this).val();
            $('input[name="srl_lot_id"]').val(selectedFlight);
        });
    });
    </script>
    <?php
}