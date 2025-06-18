<?php

if (!defined('ABSPATH')) {
    exit;
}

function srl_formatuj_date($data, $format = 'd.m.Y') {
    if (empty($data)) return '';
    
    $timestamp = is_string($data) ? strtotime($data) : (is_numeric($data) ? $data : null);
    return $timestamp ? date($format, $timestamp) : $data;
}

function srl_formatuj_czas($time, $format = 'H:i') {
    if (empty($time)) return '';
    
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        return substr($time, 0, 5);
    }
    
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

function srl_formatuj_status_lotu($status) {
    $statusy = array(
        'wolny' => 'Dostępny do rezerwacji',
        'zarezerwowany' => 'Zarezerwowany',
        'zrealizowany' => 'Zrealizowany',
        'przedawniony' => 'Przeterminowany'
    );
    return $statusy[$status] ?? ucfirst($status);
}

function srl_formatuj_telefon($telefon) {
    $clean = preg_replace('/[^0-9]/', '', $telefon);
    
    if (strlen($clean) == 9) {
        return substr($clean, 0, 3) . ' ' . substr($clean, 3, 3) . ' ' . substr($clean, 6, 3);
    }
    
    if (strlen($clean) == 11 && substr($clean, 0, 2) == '48') {
        $clean = substr($clean, 2);
        return '+48 ' . substr($clean, 0, 3) . ' ' . substr($clean, 3, 3) . ' ' . substr($clean, 6, 3);
    }
    
    return $telefon;
}

function srl_formatuj_wiek($rok_urodzenia) {
    if (empty($rok_urodzenia) || $rok_urodzenia < 1920) return '';
    return (date('Y') - $rok_urodzenia) . ' lat';
}