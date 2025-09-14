<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Client_Account {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->initHooks();
    }

    private function initHooks() {
        add_action('init', array($this, 'dodajEndpointy'));
        register_activation_hook(SRL_PLUGIN_DIR . '/system-rezerwacji-lotow.php', array($this, 'flushRewriteRules'));
        add_filter('woocommerce_account_menu_items', array($this, 'dodajZakladkiKlienta'));
        add_action('woocommerce_account_srl-moje-loty_endpoint', array($this, 'mojeLotypTresc'));
        add_action('woocommerce_account_srl-informacje-o-mnie_endpoint', array($this, 'informacjeOMnieTresc'));
    }

    public function dodajEndpointy() {
        add_rewrite_endpoint('srl-moje-loty', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('srl-informacje-o-mnie', EP_ROOT | EP_PAGES);
    }

    public function flushRewriteRules() {
        $this->dodajEndpointy();
        flush_rewrite_rules();
    }

    public function dodajZakladkiKlienta($items) {
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);

        $items['srl-moje-loty'] = 'Moje loty tandemowe';
        $items['srl-informacje-o-mnie'] = 'Dane pasażera';
        $items['customer-logout'] = $logout;

        return $items;
    }

    public function mojeLotypTresc() {
        wp_enqueue_style('srl-frontend-style', SRL_PLUGIN_URL . 'assets/css/frontend-style.css', array(), '1.0');

        $user_id = get_current_user_id();
        global $wpdb;

        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';

        echo '<h2 style="text-align: center;">Twoje loty tandemowe</h2>';
        echo '<div style="margin-bottom: 30px;text-align: center;">';
        echo SRL_Helpers::getInstance()->generateLink('/rezerwuj-lot/', 'Zarządzaj lotami', 'srl-zarzadzaj-btn');
        echo '</div>';

        $wszystkie_loty = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.*, 
                   t.data, 
                   t.godzina_start, 
                   t.godzina_koniec, 
                   t.pilot_id,
                   v.kod_vouchera,
                   v.buyer_imie as voucher_buyer_imie,
                   v.buyer_nazwisko as voucher_buyer_nazwisko
             FROM $tabela_loty zl 
             LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
             LEFT JOIN {$wpdb->prefix}srl_vouchery_upominkowe v ON zl.id = v.lot_id
             WHERE zl.user_id = %d 
             ORDER BY 
                CASE 
                    WHEN zl.status = 'zarezerwowany' THEN 1
                    WHEN zl.status = 'wolny' THEN 2
                    WHEN zl.status = 'zrealizowany' THEN 3
                    WHEN zl.status = 'przedawniony' THEN 4
                END,
                t.data ASC, zl.data_zakupu DESC",
            $user_id
        ), ARRAY_A);

        if (empty($wszystkie_loty)) {
            echo '<div class="woocommerce-message woocommerce-message--info">';
            echo '<p>Nie masz jeszcze żadnych lotów tandemowych.</p>';
            echo SRL_Helpers::getInstance()->generateLink('/produkt/lot-w-tandemie/', 'Kup lot tandemowy', 'button');
            echo '</div>';
            return;
        }

        echo '<table class="srl-tabela-lotow">';
        echo '<thead><tr><th class="srl-kolumna-nazwa">Nazwa</th><th class="srl-kolumna-status">Status i termin</th></tr></thead>';
        echo '<tbody>';

        foreach ($wszystkie_loty as $lot) {
            echo '<tr>';

            echo '<td class="srl-kolumna-nazwa">';
            echo '<div class="srl-nazwa-lotu">Lot w tandemie (#' . esc_html($lot['id']) . ')</div>';
            echo '<div class="srl-opcje-lotu">' . SRL_Helpers::getInstance()->formatFlightOptionsHtml($lot['ma_filmowanie'], $lot['ma_akrobacje']) . '</div>';

            if (!empty($lot['data_waznosci'])) {
                echo '<div class="srl-data-waznosci">(Ważny do: ' . SRL_Helpers::getInstance()->formatujDate($lot['data_waznosci']) . ')</div>';
            }
            echo '</td>';

            echo '<td class="srl-kolumna-status">';

            if ($lot['status'] === 'zarezerwowany') {
                echo SRL_Helpers::getInstance()->generateStatusBadge('zarezerwowany', 'lot');
                if (!empty($lot['data']) && !empty($lot['godzina_start'])) {
                    $data_formatowana = SRL_Helpers::getInstance()->formatujDateICzasPolski($lot['data'], $lot['godzina_start']);
                    echo '<div class="srl-termin-info">' . $data_formatowana . '</div>';
                }
            } elseif ($lot['status'] === 'wolny') {
                echo SRL_Helpers::getInstance()->generateStatusBadge('wolny', 'lot');
            } elseif ($lot['status'] === 'zrealizowany') {
                echo SRL_Helpers::getInstance()->generateStatusBadge('zrealizowany', 'lot');
                if (!empty($lot['data']) && !empty($lot['godzina_start'])) {
                    $data_formatowana = SRL_Helpers::getInstance()->formatujDateICzasPolski($lot['data'], $lot['godzina_start']);
                    echo '<div class="srl-termin-info">' . $data_formatowana . '</div>';
                }
            } elseif ($lot['status'] === 'przedawniony') {
                echo SRL_Helpers::getInstance()->generateStatusBadge('przedawniony', 'lot');
                echo '<div class="srl-termin-info">Wygasł: ' . SRL_Helpers::getInstance()->formatujDate($lot['data_waznosci']) . '</div>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function informacjeOMnieTresc() {
        $user_id = get_current_user_id();

        if (isset($_POST['srl_zapisz_info'])) {
            $dane = array(
                'imie' => sanitize_text_field($_POST['srl_imie']),
                'nazwisko' => sanitize_text_field($_POST['srl_nazwisko']),
                'rok_urodzenia' => intval($_POST['srl_rok_urodzenia']),
                'kategoria_wagowa' => sanitize_text_field($_POST['srl_kategoria_wagowa']),
                'sprawnosc_fizyczna' => sanitize_text_field($_POST['srl_sprawnosc_fizyczna']),
                'telefon' => sanitize_text_field($_POST['srl_telefon']),
                'uwagi' => sanitize_textarea_field($_POST['srl_uwagi']),
                'akceptacja_regulaminu' => true 
            );

            $komunikaty_dodatkowe = array();
            
            $walidacja_wiek = SRL_Helpers::getInstance()->walidujWiek($dane['rok_urodzenia']);
            if (!empty($walidacja_wiek['komunikaty'])) {
                foreach ($walidacja_wiek['komunikaty'] as $kom) {
                    $komunikaty_dodatkowe[] = $kom['tresc'];
                }
            }
            
            $walidacja_waga = SRL_Helpers::getInstance()->walidujKategorieWagowa($dane['kategoria_wagowa']);
            if (!$walidacja_waga['valid']) {
                foreach ($walidacja_waga['errors'] as $error) {
                    $komunikaty_dodatkowe[] = $error['tresc'];
                }
            }

            $walidacja = SRL_Helpers::getInstance()->walidujDanePasazera($dane);

            if ($walidacja['valid']) {
                foreach ($dane as $key => $value) {
                    if ($key !== 'akceptacja_regulaminu') { 
                        update_user_meta($user_id, 'srl_' . $key, $value);
                    }
                }
                SRL_Helpers::getInstance()->invalidateUserCache($user_id);
                $sukces_msg = 'Dane zostały zapisane pomyślnie!';
                if (!empty($komunikaty_dodatkowe)) {
                    $sukces_msg .= '<br><strong>Uwagi:</strong><br>' . implode('<br>', $komunikaty_dodatkowe);
                }
                
                echo SRL_Helpers::getInstance()->generateMessage($sukces_msg, 'success');
            } else {
                $errors_list = '';
                foreach ($walidacja['errors'] as $pole => $blad) {
                    $errors_list .= '<li>' . esc_html($blad) . '</li>';
                }
                foreach ($komunikaty_dodatkowe as $kom) {
                    $errors_list .= '<li style="color: #ff9800;">' . esc_html($kom) . '</li>';
                }
                echo SRL_Helpers::getInstance()->generateMessage('<ul>' . $errors_list . '</ul>', 'error');
            }
        }

        $user_data = SRL_Helpers::getInstance()->getUserFullData($user_id);

        ?>
        <h2>🪪 Dane pasażera</h2>
        <p>Te dane będą używane podczas rezerwacji lotów. Uzupełnij je dokładnie.</p>

        <form method="post" class="edit-account">
            <div class="srl-form-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:20px;">
                <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                    <label for="srl_imie">Imię <span class="required">*</span></label>
                    <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="srl_imie" id="srl_imie" value="<?php echo esc_attr($user_data['imie']); ?>" required />
                </p>

                <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                    <label for="srl_nazwisko">Nazwisko <span class="required">*</span></label>
                    <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="srl_nazwisko" id="srl_nazwisko" value="<?php echo esc_attr($user_data['nazwisko']); ?>" required />
                </p>

                <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                    <label for="srl_rok_urodzenia">Rok urodzenia <span class="required">*</span></label>
                    <input type="number" class="woocommerce-Input woocommerce-Input--text input-text" name="srl_rok_urodzenia" id="srl_rok_urodzenia" value="<?php echo esc_attr($user_data['rok_urodzenia']); ?>" min="1920" max="2020" required />
                </p>

                <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                    <label for="srl_telefon">Numer telefonu <span class="required">*</span></label>
                    <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="srl_telefon" id="srl_telefon" value="<?php echo esc_attr($user_data['telefon']); ?>" required 
                       pattern="(\+48\s?)?[0-9\s\-\(\)]{9,}" 
                       title="Numer telefonu musi mieć minimum 9 cyfr">
                </p>

                <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                    <label for="srl_sprawnosc_fizyczna">Sprawność fizyczna <span class="required">*</span></label>
                    <?php echo SRL_Helpers::getInstance()->generateSelect('srl_sprawnosc_fizyczna', array(
                        '' => 'Wybierz poziom sprawności',
                        'zdolnosc_do_marszu' => 'Zdolność do marszu',
                        'zdolnosc_do_biegu' => 'Zdolność do biegu',
                        'sprinter' => 'Sprinter!'
                    ), $user_data['sprawnosc_fizyczna'], array(
                        'id' => 'srl_sprawnosc_fizyczna',
                        'class' => 'woocommerce-Input woocommerce-Input--text input-text',
                        'required' => 'required'
                    )); ?>
                </p>

                <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                    <label for="srl_kategoria_wagowa">Kategoria wagowa <span class="required">*</span></label>
                    <?php echo SRL_Helpers::getInstance()->generateSelect('srl_kategoria_wagowa', array(
                        '' => 'Wybierz kategorię wagową',
                        '25-40kg' => '25-40kg',
                        '41-60kg' => '41-60kg',
                        '61-90kg' => '61-90kg',
                        '91-120kg' => '91-120kg',
                        '120kg+' => '120kg+'
                    ), $user_data['kategoria_wagowa'], array(
                        'id' => 'srl_kategoria_wagowa',
                        'class' => 'woocommerce-Input woocommerce-Input--text input-text',
                        'required' => 'required'
                    )); ?>
                </p>
            </div>

            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide" style="grid-column: 1 / -1;">
                <label for="srl_uwagi">Dodatkowe uwagi</label>
                <textarea class="woocommerce-Input woocommerce-Input--text input-text" name="srl_uwagi" id="srl_uwagi" rows="4" placeholder="Np. alergie, obawy, specjalne potrzeby..."><?php echo esc_textarea($user_data['uwagi']); ?></textarea>
            </p>

            <p>
                <?php echo SRL_Helpers::getInstance()->generateButton('Zapisz zmiany', 'woocommerce-Button button', array(
                    'type' => 'submit',
                    'name' => 'srl_zapisz_info',
                    'value' => 'Zapisz zmiany'
                )); ?>
            </p>
        </form>

        <script>
        jQuery(document).ready(function($) {
            $('#srl_rok_urodzenia, #srl_kategoria_wagowa').on('change', function() {
                var rokUrodzenia = $('#srl_rok_urodzenia').val();
                var kategoria = $('#srl_kategoria_wagowa').val();
                
                $('.srl-account-warnings').remove();
                
                if (!rokUrodzenia || !kategoria) return;
                
                var warnings = [];
                
                var wiek = new Date().getFullYear() - parseInt(rokUrodzenia);
                if (wiek <= 18) {
                    warnings.push('<div style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:15px; margin:10px 0;"><strong>Uwaga:</strong> Osoby niepełnoletnie wymagają zgody rodzica/opiekuna. <a href="/zgoda-na-lot-osoba-nieletnia/" target="_blank" style="color:#f57c00; font-weight:bold;">Pobierz zgodę tutaj</a>.</div>');
                }
                
                if (kategoria === '91-120kg') {
                    warnings.push('<div style="background:#fff3e0; border:2px solid #ff9800; border-radius:8px; padding:15px; margin:10px 0;"><strong>Uwaga:</strong> Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji.</div>');
                } else if (kategoria === '120kg+') {
                    warnings.push('<div style="background:#fdeaea; border:2px solid #d63638; border-radius:8px; padding:15px; margin:10px 0; color:#721c24;"><strong>❌ Błąd:</strong> Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.</div>');
                }
                
                if (warnings.length > 0) {
                    var warningsHtml = '<div class="srl-account-warnings">' + warnings.join('') + '</div>';
                    $('#srl_kategoria_wagowa').closest('.srl-form-grid').after(warningsHtml);
                }
            });
            
            if ($('#srl_rok_urodzenia').val() && $('#srl_kategoria_wagowa').val()) {
                $('#srl_rok_urodzenia').trigger('change');
            }
        });
        </script>

        <style>
        .srl-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .srl-form-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
    }
}