<?php if (!defined('ABSPATH')) {exit;}

// ==== CORE DATA FUNCTIONS ====
function srl_get_user_full_data($user_id) {
    $user = get_userdata($user_id);
    if (!$user) return null;
    
    return array(
        'id' => $user_id,
        'email' => $user->user_email,
        'display_name' => $user->display_name,
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'imie' => get_user_meta($user_id, 'srl_imie', true),
        'nazwisko' => get_user_meta($user_id, 'srl_nazwisko', true),
        'rok_urodzenia' => get_user_meta($user_id, 'srl_rok_urodzenia', true),
        'telefon' => get_user_meta($user_id, 'srl_telefon', true),
        'kategoria_wagowa' => get_user_meta($user_id, 'srl_kategoria_wagowa', true),
        'sprawnosc_fizyczna' => get_user_meta($user_id, 'srl_sprawnosc_fizyczna', true),
        'uwagi' => get_user_meta($user_id, 'srl_uwagi', true)
    );
}

function srl_get_flight_option_product_ids() {
    return array('przedluzenie' => 115, 'filmowanie' => 116, 'akrobacje' => 117);
}

function srl_get_flight_product_ids() {
    return array(62, 63, 65, 67, 69, 73, 74, 75, 76, 77);
}

function srl_check_admin_permissions() {
    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
}

function srl_require_login($return_data = false) {
    if (!is_user_logged_in()) {
        if ($return_data) return array('success' => false, 'data' => 'Musisz być zalogowany.');
        wp_send_json_error('Musisz być zalogowany.');
    }
    return true;
}

function srl_get_current_datetime() {
    return current_time('Y-m-d H:i:s');
}

// ==== FORMATTING FUNCTIONS ====
function srl_formatuj_date($data, $format = 'd.m.Y') {
    if (empty($data)) return '';
    $timestamp = is_string($data) ? strtotime($data) : (is_numeric($data) ? $data : null);
    return $timestamp ? date($format, $timestamp) : $data;
}

function srl_formatuj_czas($time, $format = 'H:i') {
    if (empty($time)) return '';
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) return substr($time, 0, 5);
    $timestamp = strtotime($time);
    return $timestamp ? date($format, $timestamp) : $time;
}

function srl_formatuj_date_i_czas_polski($data, $godzina_start) {
    if (empty($data) || empty($godzina_start)) return '';
    $timestamp = strtotime($data);
    if ($timestamp === false) return $data;

    $nazwy_dni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
    $nazwy_miesiecy = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 
                       'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

    $dzien = date('j', $timestamp);
    $miesiac = date('n', $timestamp) - 1;
    $rok = date('Y', $timestamp);
    $dzien_tygodnia = date('w', $timestamp);
    $godzina = substr($godzina_start, 0, 5);

    return sprintf(
        '<div class="srl-termin-data">%d %s %d, godz: %s</div><div class="srl-termin-dzien">%s</div>',
        $dzien, $nazwy_miesiecy[$miesiac], $rok, $godzina, $nazwy_dni[$dzien_tygodnia]
    );
}

function srl_formatuj_waznosc_lotu($data_waznosci) {
    if (empty($data_waznosci)) return 'Brak daty ważności';
    $timestamp = strtotime($data_waznosci);
    if ($timestamp === false) return $data_waznosci;

    $dzisiaj = strtotime('today');
    $roznica_dni = floor(($timestamp - $dzisiaj) / (24 * 60 * 60));
    $formatted_date = date('d.m.Y', $timestamp);

    if ($roznica_dni < 0) return $formatted_date . ' (przeterminowany)';
    if ($roznica_dni == 0) return $formatted_date . ' (wygasa dziś)';
    if ($roznica_dni <= 30) return $formatted_date . " (zostało {$roznica_dni} dni)";
    
    return $formatted_date;
}

// ==== VALIDATION FUNCTIONS ====
function srl_waliduj_telefon($telefon) {
    if (empty($telefon)) return array('valid' => false, 'message' => 'Numer telefonu jest wymagany.');
    $telefon_clean = preg_replace('/[^0-9]/', '', $telefon);
    if (strlen($telefon_clean) < 9) return array('valid' => false, 'message' => 'Numer telefonu musi mieć minimum 9 cyfr.');
    if (strlen($telefon_clean) > 11) return array('valid' => false, 'message' => 'Numer telefonu jest za długi.');
    if (strlen($telefon_clean) == 11 && substr($telefon_clean, 0, 2) !== '48') {
        return array('valid' => false, 'message' => 'Nieprawidłowy format numeru telefonu.');
    }
    return array('valid' => true, 'message' => '');
}

function srl_waliduj_date($data, $format = 'Y-m-d') {
    if (empty($data)) return array('valid' => false, 'message' => 'Data jest wymagana.');
    $d = DateTime::createFromFormat($format, $data);
    if (!$d || $d->format($format) !== $data) {
        return array('valid' => false, 'message' => 'Nieprawidłowy format daty.');
    }
    return array('valid' => true, 'message' => '');
}

function srl_waliduj_godzine($godzina) {
    if (empty($godzina)) return array('valid' => false, 'message' => 'Godzina jest wymagana.');
    if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $godzina)) {
        return array('valid' => false, 'message' => 'Nieprawidłowy format godziny (HH:MM).');
    }
    return array('valid' => true, 'message' => '');
}

function srl_waliduj_pole_tekstowe($wartosc, $nazwa_pola, $min_length = 2, $max_length = 100) {
    if (empty($wartosc)) return array('valid' => false, 'message' => $nazwa_pola . ' jest wymagane.');
    $wartosc = trim($wartosc);
    if (strlen($wartosc) < $min_length) {
        return array('valid' => false, 'message' => $nazwa_pola . ' musi mieć co najmniej ' . $min_length . ' znaki.');
    }
    if (strlen($wartosc) > $max_length) {
        return array('valid' => false, 'message' => $nazwa_pola . ' nie może być dłuższe niż ' . $max_length . ' znaków.');
    }
    if (!preg_match('/^[a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ\s\-]+$/u', $wartosc)) {
        return array('valid' => false, 'message' => $nazwa_pola . ' może zawierać tylko litery, spacje i myślniki.');
    }
    return array('valid' => true, 'message' => '');
}

function srl_waliduj_kod_vouchera($kod) {
    if (empty($kod)) return array('valid' => false, 'message' => 'Kod vouchera jest wymagany.');
    $kod = strtoupper(trim($kod));
    if (strlen($kod) < 3 || strlen($kod) > 12) {
        return array('valid' => false, 'message' => 'Kod vouchera musi mieć od 3 do 12 znaków.');
    }
    if (!preg_match('/^[A-Z0-9]+$/', $kod)) {
        return array('valid' => false, 'message' => 'Kod vouchera może zawierać tylko wielkie litery i cyfry.');
    }
    return array('valid' => true, 'message' => '', 'kod' => $kod);
}

function srl_waliduj_dane_pasazera($dane) {
    $errors = array();

    $walidacja_imie = srl_waliduj_pole_tekstowe($dane['imie'] ?? '', 'Imię');
    if (!$walidacja_imie['valid']) $errors['imie'] = $walidacja_imie['message'];

    $walidacja_nazwisko = srl_waliduj_pole_tekstowe($dane['nazwisko'] ?? '', 'Nazwisko');
    if (!$walidacja_nazwisko['valid']) $errors['nazwisko'] = $walidacja_nazwisko['message'];

    $walidacja_telefon = srl_waliduj_telefon($dane['telefon'] ?? '');
    if (!$walidacja_telefon['valid']) $errors['telefon'] = $walidacja_telefon['message'];

    $sprawnosci = array('zdolnosc_do_marszu', 'zdolnosc_do_biegu', 'sprinter');
    if (empty($dane['sprawnosc_fizyczna']) || !in_array($dane['sprawnosc_fizyczna'], $sprawnosci)) {
        $errors['sprawnosc_fizyczna'] = 'Sprawność fizyczna jest wymagana.';
    }
    
    if (!isset($dane['akceptacja_regulaminu']) || $dane['akceptacja_regulaminu'] !== true) {
        $errors['akceptacja_regulaminu'] = 'Musisz zaakceptować regulamin.';
    }

    return array('valid' => empty($errors), 'errors' => $errors);
}

// ==== AGE & WEIGHT VALIDATION ====
function srl_waliduj_wiek($rok_urodzenia, $format = 'html') {
    $komunikaty = array();
    if (!$rok_urodzenia) return array('valid' => true, 'wiek' => null, 'komunikaty' => array());
    
    $wiek = date('Y') - intval($rok_urodzenia);
    if ($wiek <= 18) {
        $komunikaty[] = array(
            'typ' => 'warning',
            'tresc' => 'Lot osoby niepełnoletniej: Osoby poniżej 18. roku życia mogą wziąć udział w locie tylko za zgodą rodzica lub opiekuna prawnego. Wymagane jest okazanie podpisanej, wydrukowanej zgody w dniu lotu, na miejscu startu.',
            'link' => '/zgoda-na-lot-osoba-nieletnia/',
            'link_text' => 'Pobierz zgodę tutaj'
        );
    }
    
    $result = array('valid' => true, 'wiek' => $wiek, 'komunikaty' => $komunikaty);
    if ($format === 'html') $result['html'] = srl_generuj_html_komunikatow($komunikaty, array());
    return $result;
}

function srl_waliduj_kategorie_wagowa($kategoria_wagowa, $format = 'html') {
    $komunikaty = $errors = array();
    if (!$kategoria_wagowa) return array('valid' => true, 'komunikaty' => array(), 'errors' => array());
    
    if ($kategoria_wagowa === '91-120kg') {
        $komunikaty[] = array(
            'typ' => 'warning', 
            'tresc' => 'Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji. Pilot ma prawo odmówić wykonania lotu jeśli uzna, że zagraża to bezpieczeństwu.'
        );
    } elseif ($kategoria_wagowa === '120kg+') {
        $errors[] = array('typ' => 'error', 'tresc' => 'Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.');
    }
    
    $result = array('valid' => empty($errors), 'komunikaty' => $komunikaty, 'errors' => $errors);
    if ($format === 'html') $result['html'] = srl_generuj_html_komunikatow($komunikaty, $errors);
    return $result;
}

function srl_generuj_html_komunikatow($komunikaty, $errors) {
    $html = '';
    foreach (array_merge($komunikaty, $errors) as $kom) {
        $class = $kom['typ'] === 'error' ? 'srl-uwaga-error' : 'srl-uwaga-warning';
        $bg_color = $kom['typ'] === 'error' ? '#fdeaea' : '#fff3e0';
        $border_color = $kom['typ'] === 'error' ? '#d63638' : '#ff9800';
        $text_color = $kom['typ'] === 'error' ? '#721c24' : '#000';
        
        $html .= '<div class="' . $class . '" style="background:' . $bg_color . '; border:2px solid ' . $border_color . '; border-radius:8px; padding:20px; margin-top:10px; color:' . $text_color . ';">';
        $html .= $kom['typ'] === 'error' ? '<strong>❌ Błąd:</strong> ' : '<strong>Uwaga:</strong> ';
        $html .= $kom['tresc'];
        if (isset($kom['link']) && isset($kom['link_text'])) {
            $html .= ' <a href="' . $kom['link'] . '" target="_blank" style="color:#f57c00; font-weight:bold;">' . $kom['link_text'] . '</a>';
        }
        $html .= '</div>';
    }
    return $html;
}

// ==== UI HELPER FUNCTIONS ====
function srl_generate_status_badge($status, $type = 'lot') {
    $config = array(
        'lot' => array(
            'wolny' => array('icon' => '🟢', 'class' => 'status-available', 'label' => 'Dostępny do rezerwacji'),
            'zarezerwowany' => array('icon' => '🟡', 'class' => 'status-reserved', 'label' => 'Zarezerwowany'),
            'zrealizowany' => array('icon' => '🔵', 'class' => 'status-completed', 'label' => 'Zrealizowany'),
            'przedawniony' => array('icon' => '🔴', 'class' => 'status-expired', 'label' => 'Przeterminowany')
        ),
        'slot' => array(
            'Wolny' => array('icon' => '🟢', 'class' => 'status-available', 'label' => 'Wolny'),
            'Prywatny' => array('icon' => '🟤', 'class' => 'status-private', 'label' => 'Prywatny'),
            'Zarezerwowany' => array('icon' => '🟡', 'class' => 'status-reserved', 'label' => 'Zarezerwowany'),
            'Zrealizowany' => array('icon' => '🔵', 'class' => 'status-completed', 'label' => 'Zrealizowany'),
            'Odwołany przez organizatora' => array('icon' => '🔴', 'class' => 'status-cancelled', 'label' => 'Odwołany')
        )
    );

    $item = $config[$type][$status] ?? array('icon' => '⚪', 'class' => 'status-unknown', 'label' => ucfirst($status));
    return sprintf(
        '<span class="%s" style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">%s %s</span>',
        $item['class'], $item['icon'], $item['label']
    );
}

function srl_format_flight_options_html($ma_filmowanie, $ma_akrobacje) {
    $opcje = array();
    $opcje[] = $ma_filmowanie ? '<span style="color: #46b450; font-weight: bold;">z filmowaniem</span>' : '<span style="color: #d63638;">bez filmowania</span>';
    $opcje[] = $ma_akrobacje ? '<span style="color: #46b450; font-weight: bold;">z akrobacjami</span>' : '<span style="color: #d63638;">bez akrobacji</span>';
    return implode(', ', $opcje);
}

function srl_generate_message($text, $type = 'info', $dismissible = false) {
    $classes = array(
        'info' => 'srl-komunikat-info',
        'success' => 'srl-komunikat-success', 
        'warning' => 'srl-komunikat-warning',
        'error' => 'srl-komunikat-error'
    );
    $class = $classes[$type] ?? $classes['info'];
    $dismiss_btn = $dismissible ? '<button type="button" class="srl-dismiss">×</button>' : '';
    return '<div class="srl-komunikat ' . $class . '">' . $text . $dismiss_btn . '</div>';
}

function srl_generate_select($name, $options, $selected = '', $attrs = array()) {
    $attributes = '';
    foreach ($attrs as $key => $value) {
        $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
    }
    
    $html = '<select name="' . esc_attr($name) . '"' . $attributes . '>';
    foreach ($options as $value => $label) {
        $selected_attr = selected($selected, $value, false);
        $html .= '<option value="' . esc_attr($value) . '"' . $selected_attr . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function srl_generate_link($url, $text, $class = '', $attrs = array()) {
    $attributes = '';
    foreach ($attrs as $key => $value) {
        $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
    }
    $class_attr = $class ? ' class="' . esc_attr($class) . '"' : '';
    return '<a href="' . esc_url($url) . '"' . $class_attr . $attributes . '>' . $text . '</a>';
}

function srl_generate_button($text, $class = 'srl-btn-primary', $attrs = array()) {
    $attributes = '';
    foreach ($attrs as $key => $value) {
        $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
    }
    return '<button class="srl-btn ' . $class . '"' . $attributes . '>' . $text . '</button>';
}

// ==== UTILITY FUNCTIONS ====
function srl_can_cancel_reservation($data_lotu, $godzina_lotu) {
    if (empty($data_lotu) || empty($godzina_lotu)) return false;
    $datetime_lotu = $data_lotu . ' ' . $godzina_lotu;
    $timestamp_lotu = strtotime($datetime_lotu);
    if ($timestamp_lotu === false) return false;
    return ($timestamp_lotu - time()) > (48 * 3600);
}

function srl_detect_flight_options($text) {
    $text_lower = strtolower($text);
    return array(
        'ma_filmowanie' => (strpos($text_lower, 'filmowani') !== false || strpos($text_lower, 'film') !== false ||
                           strpos($text_lower, 'video') !== false || strpos($text_lower, 'kamer') !== false) ? 1 : 0,
        'ma_akrobacje' => (strpos($text_lower, 'akrobacj') !== false || strpos($text_lower, 'trick') !== false ||
                          strpos($text_lower, 'spiral') !== false || strpos($text_lower, 'figur') !== false) ? 1 : 0
    );
}

function srl_generate_expiry_date($from_date = null, $years = 1) {
    $base_date = $from_date ? $from_date : current_time('mysql');
    return date('Y-m-d', strtotime($base_date . " +{$years} year"));
}

function srl_is_date_past($date) {
    return strtotime($date) < strtotime('today');
}

function srl_zamien_na_minuty($time) {
    list($h, $m) = explode(':', $time);
    return intval($h) * 60 + intval($m);
}

function srl_minuty_na_czas($minutes) {
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', $h, $m);
}