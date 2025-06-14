<?php
/**
 * Funkcje pomocnicze formatowania
 */

if (!defined('ABSPATH')) {
    exit;
}

function srl_formatuj_date($data, $format = 'd.m.Y') {
    if (empty($data)) {
        return '';
    }
    
    if (is_string($data)) {
        $timestamp = strtotime($data);
        if ($timestamp === false) {
            return $data;
        }
        return date($format, $timestamp);
    }
    
    if (is_numeric($data)) {
        return date($format, $data);
    }
    
    return $data;
}


function srl_formatuj_czas($time, $format = 'H:i') {
    if (empty($time)) {
        return '';
    }
    
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return substr($time, 0, 5);
    }
    
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time;
    }
    
    $timestamp = strtotime($time);
    if ($timestamp !== false) {
        return date($format, $timestamp);
    }
    
    return $time;
}

function srl_formatuj_waznosc_lotu($data_waznosci) {
    if (empty($data_waznosci)) {
        return 'Brak daty ważności';
    }
    
    $timestamp = strtotime($data_waznosci);
    if ($timestamp === false) {
        return $data_waznosci;
    }
    
    $dzisiaj = strtotime('today');
    $roznica_dni = floor(($timestamp - $dzisiaj) / (24 * 60 * 60));
    
    $formatted_date = date('d.m.Y', $timestamp);
    
    if ($roznica_dni < 0) {
        return $formatted_date . ' (przeterminowany)';
    } elseif ($roznica_dni == 0) {
        return $formatted_date . ' (wygasa dziś)';
    } elseif ($roznica_dni <= 30) {
        return $formatted_date . " (zostało {$roznica_dni} dni)";
    } else {
        return $formatted_date;
    }
}

function srl_formatuj_status_lotu($status) {
    $statusy = array(
        'wolny' => 'Dostępny do rezerwacji',
        'zarezerwowany' => 'Zarezerwowany',
        'zrealizowany' => 'Zrealizowany',
        'przedawniony' => 'Przeterminowany'
    );
    
    return isset($statusy[$status]) ? $statusy[$status] : ucfirst($status);
}

function srl_formatuj_status_slotu($status) {
    $statusy = array(
        'Wolny' => 'Wolny',
        'Prywatny' => 'Prywatny',
        'Zarezerwowany' => 'Zarezerwowany',
        'Zrealizowany' => 'Zrealizowany',
        'Odwołany przez organizatora' => 'Odwołany przez organizatora'
    );
    
    return isset($statusy[$status]) ? $statusy[$status] : $status;
}

function srl_escape_html($text) {
    if (is_array($text)) {
        return array_map('srl_escape_html', $text);
    }
    
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function srl_pad_2($number) {
    return str_pad($number, 2, '0', STR_PAD_LEFT);
}

function srl_zamien_na_minuty($czas) {
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $czas, $matches)) {
        return intval($matches[1]) * 60 + intval($matches[2]);
    }
    return 0;
}

function srl_minuty_na_czas($minuty) {
    $godziny = floor($minuty / 60);
    $minuty_pozostale = $minuty % 60;
    return srl_pad_2($godziny) . ':' . srl_pad_2($minuty_pozostale);
}

function srl_formatuj_kategorie_wagowa($kategoria) {
    $kategorie = array(
        'do_60kg' => 'do 60 kg',
        '60-80kg' => '60-80 kg',
        '80-100kg' => '80-100 kg',
        '100-120kg' => '100-120 kg',
        '120kg+' => '120 kg+'
    );
    
    return isset($kategorie[$kategoria]) ? $kategorie[$kategoria] : $kategoria;
}

function srl_formatuj_sprawnosc_fizyczna($sprawnosc) {
    $sprawnosci = array(
        'dobra' => 'Dobra',
        'przecietna' => 'Przeciętna',
        'ograniczona' => 'Ograniczona'
    );
    
    return isset($sprawnosci[$sprawnosc]) ? $sprawnosci[$sprawnosc] : ucfirst($sprawnosc);
}

function srl_formatuj_telefon($telefon) {
    $telefon_clean = preg_replace('/[^0-9]/', '', $telefon);
    
    if (strlen($telefon_clean) == 9) {
        return substr($telefon_clean, 0, 3) . ' ' . substr($telefon_clean, 3, 3) . ' ' . substr($telefon_clean, 6, 3);
    }
    
    if (strlen($telefon_clean) == 11 && substr($telefon_clean, 0, 2) == '48') {
        $telefon_clean = substr($telefon_clean, 2);
        return '+48 ' . substr($telefon_clean, 0, 3) . ' ' . substr($telefon_clean, 3, 3) . ' ' . substr($telefon_clean, 6, 3);
    }
    
    return $telefon;
}

function srl_formatuj_wiek($rok_urodzenia) {
    if (empty($rok_urodzenia) || $rok_urodzenia < 1920) {
        return '';
    }
    
    $wiek = date('Y') - $rok_urodzenia;
    return $wiek . ' lat';
}

function srl_formatuj_opcje_lotu($ma_filmowanie, $ma_akrobacje) {
    $opcje = array();
    
    if ($ma_filmowanie) {
        $opcje[] = 'Filmowanie';
    }
    
    if ($ma_akrobacje) {
        $opcje[] = 'Akrobacje';
    }
    
    return empty($opcje) ? 'Brak dodatkowych opcji' : implode(', ', $opcje);
}

function srl_formatuj_date_i_czas_polski($data, $godzina_start) {
    if (empty($data) || empty($godzina_start)) {
        return '';
    }
    
    $timestamp = strtotime($data);
    if ($timestamp === false) {
        return $data;
    }
    
    $nazwy_dni = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
    $nazwy_miesiecy = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 
                       'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    
    $dzien = date('j', $timestamp);
    $miesiac = date('n', $timestamp) - 1;
    $rok = date('Y', $timestamp);
    $dzien_tygodnia = date('w', $timestamp);
    $godzina = substr($godzina_start, 0, 5);
    
    return '<div class="srl-termin-data">' . $dzien . ' ' . $nazwy_miesiecy[$miesiac] . ' ' . $rok . ', godz: ' . $godzina . '</div>' .
           '<div class="srl-termin-dzien">' . $nazwy_dni[$dzien_tygodnia] . '</div>';
}