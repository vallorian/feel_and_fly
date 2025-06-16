<?php

function srl_wyswietl_dzien() {
    if (!current_user_can('manage_options')) {
        wp_die('Brak uprawnień.');
    }
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';

    if (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'])) {
        $data = sanitize_text_field($_GET['data']);
    } else {
        $data = date('Y-m-d');
    }

    $numer_dnia = date('N', strtotime($data));
    $dni_tyg = array(
        1 => 'Poniedziałek',
        2 => 'Wtorek',
        3 => 'Środa',
        4 => 'Czwartek',
        5 => 'Piątek',
        6 => 'Sobota',
        7 => 'Niedziela'
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

			$user = get_userdata(intval($wiersz['klient_id']));
			if ($user) {

				$imie = get_user_meta(intval($wiersz['klient_id']), 'srl_imie', true);
				$nazwisko = get_user_meta(intval($wiersz['klient_id']), 'srl_nazwisko', true);

				if ($imie && $nazwisko) {
					$klient_nazwa = $imie . ' ' . $nazwisko;
				} else {

					$klient_nazwa = $user->display_name;
				}
				$link_zamowienia = admin_url('edit.php?post_type=shop_order&customer=' . intval($wiersz['klient_id']));

				$lot = $wpdb->get_var($wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d AND status IN ('zarezerwowany', 'zrealizowany')",
					$wiersz['id']
				));
				$lot_id = $lot ? intval($lot) : null;
			}
		}
        $godziny_wg_pilota[$pid][] = array(
			'id'               => intval($wiersz['id']),
			'start'            => substr($wiersz['godzina_start'], 0, 5),
			'koniec'           => substr($wiersz['godzina_koniec'], 0, 5),
			'status'           => $wiersz['status'],
			'klient_id'        => intval($wiersz['klient_id']),
			'klient_nazwa'     => $klient_nazwa,
			'link_zamowienia'  => $link_zamowienia,
			'lot_id'           => $wiersz['lot_id'] ? intval($wiersz['lot_id']) : null,
			'notatka'          => $wiersz['notatka']
		);
    }

    $poprzedni_dzien = date('Y-m-d', strtotime($data . ' -1 day'));
    $nastepny_dzien  = date('Y-m-d', strtotime($data . ' +1 day'));
    $url_poprzedni = add_query_arg('data', $poprzedni_dzien);
    $url_nastepny  = add_query_arg('data', $nastepny_dzien);

    echo '<div class="wrap">';
    echo '<h1>Planowanie godzin lotów – ' . esc_html($nazwa_dnia) . ', ' . esc_html($data) . '</h1>';
    echo '<div style="margin-bottom:20px;">';
    echo '<a class="button" href="' . esc_url($url_poprzedni) . '">&laquo; Poprzedni dzień</a> ';
    echo '<input type="date" id="srl-wybierz-date" value="' . esc_attr($data) . '" style="margin:0 10px;" /> ';
    echo '<a class="button" href="' . esc_url($url_nastepny) . '">Następny dzień &raquo;</a>';
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
    echo '<select id="srl-liczba-pilotow">';
    for ($i = 1; $i <= 4; $i++) {
        $sel = ($i == $domyslna_liczba) ? 'selected' : '';
        echo '<option value="' . $i . '" ' . $sel . '>' . $i . '</option>';
    }
    echo '</select></label>';

    $domyslny_interwal = 15;
    echo '   <label>Interwał czasowy: ';
    echo '<select id="srl-interwal">';
    $interwaly = array(1,5,10,15,20,30,45,60);
    foreach ($interwaly as $ival) {
        $sel = ($ival == $domyslny_interwal) ? 'selected' : '';
        echo '<option value="' . $ival . '" ' . $sel . '>' . $ival . ' min</option>';
    }
    echo '</select></label></p>';

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

    ?>
    <script type="text/javascript" src="<?php echo SRL_PLUGIN_URL; ?>assets/js/admin-day.js"></script>
    <?php
}

add_action('wp_ajax_srl_anuluj_lot_przez_organizatora', 'srl_anuluj_lot_przez_organizatora');
add_action('wp_ajax_srl_wyszukaj_klientow_loty', 'srl_wyszukaj_klientow_loty');
add_action('wp_ajax_srl_dodaj_godzine',        'srl_dodaj_godzine');
add_action('wp_ajax_srl_zmien_slot',           'srl_zmien_slot');
add_action('wp_ajax_srl_usun_godzine',         'srl_usun_godzine');
add_action('wp_ajax_srl_zmien_status_godziny', 'srl_zmien_status_godziny');
?>