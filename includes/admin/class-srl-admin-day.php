<?php

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Admin_Day {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
    }

    public static function wyswietlDzien() {
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        if (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'])) {
            $data = sanitize_text_field($_GET['data']);
        } else {
            $data = date('Y-m-d');
        }

        $numer_dnia = date('N', strtotime($data));
        $dni_tyg = array(
            1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek',
            5 => 'Piątek', 6 => 'Sobota', 7 => 'Niedziela'
        );
        $nazwa_dnia = $dni_tyg[$numer_dnia];

        $istniejace_godziny = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.pilot_id, t.godzina_start, t.godzina_koniec, t.status, t.klient_id, t.notatka,
                    zl.id as lot_id, zl.user_id as lot_user_id
               FROM $tabela t
               LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
              WHERE t.data = %s
              ORDER BY t.pilot_id ASC, t.godzina_start ASC",
            $data
        ), ARRAY_A);

        $godziny_wg_pilota = array();
        foreach ($istniejace_godziny as $wiersz) {
            $pid = intval($wiersz['pilot_id']);
            if (!isset($godziny_wg_pilota[$pid])) {
                $godziny_wg_pilota[$pid] = array();
            }

            $klient_nazwa = '';
            $link_zamowienia = '';
            
            if (($wiersz['status'] === 'Zarezerwowany' || $wiersz['status'] === 'Zrealizowany') && intval($wiersz['klient_id']) > 0) {
                $user_data = SRL_Helpers::getInstance()->getUserFullData(intval($wiersz['klient_id']));
                if ($user_data) {
                    if ($user_data['imie'] && $user_data['nazwisko']) {
                        $klient_nazwa = $user_data['imie'] . ' ' . $user_data['nazwisko'];
                    } else {
                        $klient_nazwa = $user_data['display_name'];
                    }
                    $link_zamowienia = admin_url('edit.php?post_type=shop_order&customer=' . intval($wiersz['klient_id']));

                    $lot = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d AND status IN ('zarezerwowany', 'zrealizowany')",
                        $wiersz['id']
                    ));
                    $lot_id = $lot ? intval($lot) : null;
                }
            } elseif ($wiersz['status'] === 'Prywatny' && !empty($wiersz['notatka'])) {
                $dane_prywatne = json_decode($wiersz['notatka'], true);
                if ($dane_prywatne && isset($dane_prywatne['imie']) && isset($dane_prywatne['nazwisko'])) {
                    $klient_nazwa = $dane_prywatne['imie'] . ' ' . $dane_prywatne['nazwisko'];
                }
            }

            $godziny_wg_pilota[$pid][] = array(
                'id' => intval($wiersz['id']),
                'start' => substr($wiersz['godzina_start'], 0, 5),
                'koniec' => substr($wiersz['godzina_koniec'], 0, 5),
                'status' => $wiersz['status'],
                'klient_id' => intval($wiersz['klient_id']),
                'klient_nazwa' => $klient_nazwa,
                'link_zamowienia' => $link_zamowienia,
                'lot_id' => $wiersz['lot_id'] ? intval($wiersz['lot_id']) : null,
                'notatka' => $wiersz['notatka']
            );
        }

        $poprzedni_dzien = date('Y-m-d', strtotime($data . ' -1 day'));
        $nastepny_dzien = date('Y-m-d', strtotime($data . ' +1 day'));
        
        echo '<div class="wrap">';
        echo '<h1>Planowanie godzin lotów – ' . esc_html($nazwa_dnia) . ', ' . esc_html($data) . '</h1>';
        
        echo '<div style="margin-bottom:20px;">';
        echo SRL_Helpers::getInstance()->generateLink(add_query_arg('data', $poprzedni_dzien), '← Poprzedni dzień', 'button');
        echo '<input type="date" id="srl-wybierz-date" value="' . esc_attr($data) . '" style="margin:0 10px;" />';
        echo SRL_Helpers::getInstance()->generateLink(add_query_arg('data', $nastepny_dzien), 'Następny dzień →', 'button');
        echo '</div>';

        $ma_sloty = (count($istniejace_godziny) > 0);
        $checked = $ma_sloty ? 'checked' : '';
        echo '<p><label><input type="checkbox" id="srl-planowane-godziny" ' . $checked . ' /> Czy zaplanować godziny tandemowe?</label></p>';

        $styl = $ma_sloty ? '' : 'display:none;';
        echo '<div id="srl-ustawienia-godzin" style="' . $styl . '">';

        $domyslna_liczba = 1;
        if ($ma_sloty) {
            $max_pid = max(array_keys($godziny_wg_pilota));
            $domyslna_liczba = max(1, $max_pid);
            if ($domyslna_liczba > 4) {
                $domyslna_liczba = 4;
            }
        }
        
        echo '<p><label>Ile pilotów? ';
        echo SRL_Helpers::getInstance()->generateSelect('srl-liczba-pilotow', array(
            1 => '1', 2 => '2', 3 => '3', 4 => '4'
        ), $domyslna_liczba, array('id' => 'srl-liczba-pilotow'));
        echo '</label>';

        $domyslny_interwal = 15;
        echo '<label>Interwał czasowy: ';
        echo SRL_Helpers::getInstance()->generateSelect('srl-interwal', array(
            1 => '1 min', 5 => '5 min', 10 => '10 min', 15 => '15 min',
            20 => '20 min', 30 => '30 min', 45 => '45 min', 60 => '60 min'
        ), $domyslny_interwal, array('id' => 'srl-interwal'));
        echo '</label></p>';

        echo '<div style="margin-top:20px; margin-bottom:20px; padding:10px; border:1px solid #ccc; border-radius:4px; background:#f9f9f9;">';
        echo '<strong>Generuj sloty: </strong>';

        echo '<label>Pilot: <select id="srl-generuj-pilot">';
        for ($i = 1; $i <= intval($domyslna_liczba); $i++) {
            echo '<option value="' . $i . '">Pilot ' . $i . '</option>';
        }
        echo '</select></label>';

        echo '<label> Godzina od: <input type="time" id="srl-generuj-od" value="09:00" /></label> ';
        echo '<label> do: <input type="time" id="srl-generuj-do" value="18:00" /></label>';

        echo ' <button class="button button-secondary" id="srl-generuj-sloty">Generuj sloty</button>';
        echo '<span style="font-size:12px; color:#555; margin-left:10px;">(sloty powstają wg interwału)</span>';
        echo '</div>';

        echo '<div id="srl-tabele-pilotow"></div>';

        echo '<div id="srl-harmonogram-section" style="margin-top:40px; padding:20px; border:2px solid #0073aa; border-radius:8px; background:#f8f9fa;">';
        echo '<h2 style="margin-top:0; color:#0073aa; border-bottom:2px solid #0073aa; padding-bottom:10px;">📅 Harmonogram czasowy dnia</h2>';
        echo '<div id="srl-harmonogram-container"></div>';
        echo '</div>';

        echo '</div>'; 
        echo '</div>'; 

        echo '<script type="text/javascript" src="' . SRL_PLUGIN_URL . 'assets/js/admin-day.js"></script>';
    }
}