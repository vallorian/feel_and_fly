<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Helpers {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Brak hooków WordPress w tym pliku - tylko funkcje pomocnicze
    }

	public function getUserFullData($user_id) {
		return SRL_Cache_Manager::getInstance()->getUserData($user_id);
	}

    public function getFlightOptionProductIds() {
        return array('przedluzenie' => 115, 'filmowanie' => 116, 'akrobacje' => 117);
    }

    public function getFlightProductIds() {
        return array(62, 63, 65, 67, 69, 73, 74, 75, 76, 77);
    }

    public function checkAdminPermissions() {
        if (!current_user_can('manage_woocommerce')) wp_die('Brak uprawnień.');
    }

    public function requireLogin($return_data = false) {
        if (!is_user_logged_in()) {
            if ($return_data) return array('success' => false, 'data' => 'Musisz być zalogowany.');
            wp_send_json_error('Musisz być zalogowany.');
        }
        return true;
    }

    public function getCurrentDatetime() {
        return current_time('Y-m-d H:i:s');
    }

    public function formatujDate($data, $format = 'd.m.Y') {
        if (empty($data)) return '';
        $timestamp = is_string($data) ? strtotime($data) : (is_numeric($data) ? $data : null);
        return $timestamp ? date($format, $timestamp) : $data;
    }

    public function formatujCzas($time, $format = 'H:i') {
        if (empty($time)) return '';
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) return substr($time, 0, 5);
        $timestamp = strtotime($time);
        return $timestamp ? date($format, $timestamp) : $time;
    }

    public function formatujDateICzasPolski($data, $godzina_start) {
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

    public function formatujWaznoscLotu($data_waznosci) {
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

    public function walidujTelefon($telefon) {
        if (empty($telefon)) return array('valid' => false, 'message' => 'Numer telefonu jest wymagany.');
        $telefon_clean = preg_replace('/[^0-9]/', '', $telefon);
        if (strlen($telefon_clean) < 9) return array('valid' => false, 'message' => 'Numer telefonu musi mieć minimum 9 cyfr.');
        if (strlen($telefon_clean) > 11) return array('valid' => false, 'message' => 'Numer telefonu jest za długi.');
        if (strlen($telefon_clean) == 11 && substr($telefon_clean, 0, 2) !== '48') {
            return array('valid' => false, 'message' => 'Nieprawidłowy format numeru telefonu.');
        }
        return array('valid' => true, 'message' => '');
    }

    public function walidujDate($data, $format = 'Y-m-d') {
        if (empty($data)) return array('valid' => false, 'message' => 'Data jest wymagana.');
        $d = DateTime::createFromFormat($format, $data);
        if (!$d || $d->format($format) !== $data) {
            return array('valid' => false, 'message' => 'Nieprawidłowy format daty.');
        }
        return array('valid' => true, 'message' => '');
    }

    public function walidujGodzine($godzina) {
        if (empty($godzina)) return array('valid' => false, 'message' => 'Godzina jest wymagana.');
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $godzina)) {
            return array('valid' => false, 'message' => 'Nieprawidłowy format godziny (HH:MM).');
        }
        return array('valid' => true, 'message' => '');
    }

    public function walidujPoleTekstowe($wartosc, $nazwa_pola, $min_length = 2, $max_length = 100) {
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

    public function walidujKodVouchera($kod) {
        if (empty($kod)) return array('valid' => false, 'message' => 'Kod vouchera jest wymagany.');
        $kod = strtoupper(trim($kod));
        if (strlen($kod) < 3 || strlen($kod) > 20) {
            return array('valid' => false, 'message' => 'Kod vouchera musi mieć od 3 do 20 znaków.');
        }
        if (!preg_match('/^[A-Z0-9]+$/', $kod)) {
            return array('valid' => false, 'message' => 'Kod vouchera może zawierać tylko wielkie litery i cyfry.');
        }
        return array('valid' => true, 'message' => '', 'kod' => $kod);
    }

    public function walidujDanePasazera($dane) {
        $errors = array();

        $walidacja_imie = $this->walidujPoleTekstowe($dane['imie'] ?? '', 'Imię');
        if (!$walidacja_imie['valid']) $errors['imie'] = $walidacja_imie['message'];

        $walidacja_nazwisko = $this->walidujPoleTekstowe($dane['nazwisko'] ?? '', 'Nazwisko');
        if (!$walidacja_nazwisko['valid']) $errors['nazwisko'] = $walidacja_nazwisko['message'];

        $walidacja_telefon = $this->walidujTelefon($dane['telefon'] ?? '');
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

    public function walidujWiek($rok_urodzenia, $format = 'html') {
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
        if ($format === 'html') $result['html'] = $this->generujHtmlKomunikatow($komunikaty, array());
        return $result;
    }

    public function walidujKategorieWagowa($kategoria_wagowa, $format = 'html') {
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
        if ($format === 'html') $result['html'] = $this->generujHtmlKomunikatow($komunikaty, $errors);
        return $result;
    }

    public function generujHtmlKomunikatow($komunikaty, $errors) {
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

    public function generateStatusBadge($status, $type = 'lot') {
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

    public function formatFlightOptionsHtml($ma_filmowanie, $ma_akrobacje) {
        $opcje = array();
        $opcje[] = $ma_filmowanie ? '<span style="color: #46b450; font-weight: bold;">z filmowaniem</span>' : '<span style="color: #d63638;">bez filmowania</span>';
        $opcje[] = $ma_akrobacje ? '<span style="color: #46b450; font-weight: bold;">z akrobacjami</span>' : '<span style="color: #d63638;">bez akrobacji</span>';
        return implode(', ', $opcje);
    }

    public function generateMessage($text, $type = 'info', $dismissible = false) {
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

    public function generateSelect($name, $options, $selected = '', $attrs = array()) {
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

    public function generateLink($url, $text, $class = '', $attrs = array()) {
        $attributes = '';
        foreach ($attrs as $key => $value) {
            $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        $class_attr = $class ? ' class="' . esc_attr($class) . '"' : '';
        return '<a href="' . esc_url($url) . '"' . $class_attr . $attributes . '>' . $text . '</a>';
    }

    public function generateButton($text, $class = 'srl-btn-primary', $attrs = array()) {
        $attributes = '';
        foreach ($attrs as $key => $value) {
            $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        return '<button class="srl-btn ' . $class . '"' . $attributes . '>' . $text . '</button>';
    }

    public function canCancelReservation($data_lotu, $godzina_lotu) {
        if (empty($data_lotu) || empty($godzina_lotu)) return false;
        $datetime_lotu = $data_lotu . ' ' . $godzina_lotu;
        $timestamp_lotu = strtotime($datetime_lotu);
        if ($timestamp_lotu === false) return false;
        return ($timestamp_lotu - time()) > (48 * 3600);
    }

    public function detectFlightOptions($text) {
        $text_lower = strtolower($text);
        return array(
            'ma_filmowanie' => (strpos($text_lower, 'filmowani') !== false || strpos($text_lower, 'film') !== false ||
                               strpos($text_lower, 'video') !== false || strpos($text_lower, 'kamer') !== false) ? 1 : 0,
            'ma_akrobacje' => (strpos($text_lower, 'akrobacj') !== false || strpos($text_lower, 'trick') !== false ||
                              strpos($text_lower, 'spiral') !== false || strpos($text_lower, 'figur') !== false) ? 1 : 0
        );
    }

    public function generateExpiryDate($from_date = null, $years = 1) {
        $base_date = $from_date ? $from_date : current_time('mysql');
        return date('Y-m-d', strtotime($base_date . " +{$years} year"));
    }

    public function isDatePast($date) {
        return strtotime($date) < strtotime('today');
    }

    public function zamienNaMinuty($time) {
        list($h, $m) = explode(':', $time);
        return intval($h) * 60 + intval($m);
    }

    public function minutyNaCzas($minutes) {
        $h = floor($minutes / 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }
	
	public function invalidateUserCache($user_id) {
		SRL_Cache_Manager::getInstance()->invalidateUserCache($user_id);
	}
}