<?php if (!defined('ABSPATH')) {
    exit;
}
function srl_waliduj_telefon($telefon) {
    if (empty($telefon)) {
        return array('valid' => false, 'message' => 'Numer telefonu jest wymagany.');
    }
    $telefon_clean = preg_replace('/[^0-9]/', '', $telefon);
    if (strlen($telefon_clean) < 9) {
        return array('valid' => false, 'message' => 'Numer telefonu musi mieć minimum 9 cyfr.');
    }
    if (strlen($telefon_clean) > 11) {
        return array('valid' => false, 'message' => 'Numer telefonu jest za długi.');
    }
    if (strlen($telefon_clean) == 11 && substr($telefon_clean, 0, 2) !== '48') {
        return array('valid' => false, 'message' => 'Nieprawidłowy format numeru telefonu.');
    }
    return array('valid' => true, 'message' => '');
}
function srl_waliduj_email($email) {
    if (empty($email)) {
        return array('valid' => false, 'message' => 'Adres email jest wymagany.');
    }
    if (!is_email($email)) {
        return array('valid' => false, 'message' => 'Nieprawidłowy format adresu email.');
    }
    return array('valid' => true, 'message' => '');
}
function srl_waliduj_rok_urodzenia($rok) {
    if (empty($rok) || !is_numeric($rok)) {
        return array('valid' => false, 'message' => 'Rok urodzenia jest wymagany.');
    }
    $rok = intval($rok);
    $current_year = intval(date('Y'));
    if ($rok < 1920) {
        return array('valid' => false, 'message' => 'Rok urodzenia nie może być wcześniejszy niż 1920.');
    }
    if ($rok > $current_year) {
        return array('valid' => false, 'message' => 'Rok urodzenia nie może być z przyszłości.');
    }
    $wiek = $current_year - $rok;
    if ($wiek < 10) {
        return array('valid' => false, 'message' => 'Pasażer musi mieć co najmniej 10 lat.');
    }
    return array('valid' => true, 'message' => '');
}
function srl_waliduj_kategorie_wagowa($kategoria) {
    $dozwolone_kategorie = array('25-40kg', '41-60kg', '61-90kg', '91-120kg', '120kg+');
    if (empty($kategoria)) {
        return array('valid' => false, 'message' => 'Kategoria wagowa jest wymagana.');
    }
    if (!in_array($kategoria, $dozwolone_kategorie)) {
        return array('valid' => false, 'message' => 'Nieprawidłowa kategoria wagowa.');
    }
    if ($kategoria === '120kg+') {
        return array('valid' => false, 'message' => 'Nie można dokonać rezerwacji z kategorią wagową 120kg+. Skontaktuj się z organizatorem.');
    }
    return array('valid' => true, 'message' => '');
}
function srl_waliduj_sprawnosc_fizyczna($sprawnosc) {
    $dozwolone_sprawnosci = array('zdolnosc_do_marszu', 'zdolnosc_do_biegu', 'sprinter');
    if (empty($sprawnosc)) {
        return array('valid' => false, 'message' => 'Sprawność fizyczna jest wymagana.');
    }
    if (!in_array($sprawnosc, $dozwolone_sprawnosci)) {
        return array('valid' => false, 'message' => 'Nieprawidłowa sprawność fizyczna.');
    }
    return array('valid' => true, 'message' => '');
}
function srl_waliduj_date($data, $format = 'Y-m-d') {
    if (empty($data)) {
        return array('valid' => false, 'message' => 'Data jest wymagana.');
    }
    $d = DateTime::createFromFormat($format, $data);
    if (!$d || $d->format($format) !== $data) {
        return array('valid' => false, 'message' => 'Nieprawidłowy format daty.');
    }
    return array('valid' => true, 'message' => '');
}
function srl_waliduj_godzine($godzina) {
    if (empty($godzina)) {
        return array('valid' => false, 'message' => 'Godzina jest wymagana.');
    }
    if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $godzina)) {
        return array('valid' => false, 'message' => 'Nieprawidłowy format godziny (HH:MM).');
    }
    return array('valid' => true, 'message' => '');
}
function srl_sprawdz_kompatybilnosc_z_akrobacjami($wiek, $sprawnosc_fizyczna, $kategoria_wagowa) {
    if ($wiek < 16) {
        return array('compatible' => false, 'message' => 'Akrobacje dostępne od 16 lat.');
    }
    if ($sprawnosc_fizyczna === 'ograniczona') {
        return array('compatible' => false, 'message' => 'Akrobacje wymagają dobrej sprawności fizycznej.');
    }
    if ($kategoria_wagowa === '120kg+') {
        return array('compatible' => false, 'message' => 'Akrobacje niedostępne dla kategorii wagowej 120kg+.');
    }
    return array('compatible' => true, 'message' => '');
}
function srl_waliduj_dane_pasazera($dane) {
    $errors = array();
    $walidacja_imie = srl_waliduj_pole_tekstowe($dane['imie']??'', 'Imię');
    if (!$walidacja_imie['valid']) {
        $errors['imie'] = $walidacja_imie['message'];
    }
    $walidacja_nazwisko = srl_waliduj_pole_tekstowe($dane['nazwisko']??'', 'Nazwisko');
    if (!$walidacja_nazwisko['valid']) {
        $errors['nazwisko'] = $walidacja_nazwisko['message'];
    }
    $walidacja_rok = srl_waliduj_rok_urodzenia($dane['rok_urodzenia']??'');
    if (!$walidacja_rok['valid']) {
        $errors['rok_urodzenia'] = $walidacja_rok['message'];
    }
    $walidacja_telefon = srl_waliduj_telefon($dane['telefon']??'');
    if (!$walidacja_telefon['valid']) {
        $errors['telefon'] = $walidacja_telefon['message'];
    }
    $walidacja_kategoria = srl_waliduj_kategorie_wagowa($dane['kategoria_wagowa']??'');
    if (!$walidacja_kategoria['valid']) {
        $errors['kategoria_wagowa'] = $walidacja_kategoria['message'];
        error_log('SRL DEBUG - Błąd kategorii wagowej: ' . $dane['kategoria_wagowa']??'BRAK');
    }
    $walidacja_sprawnosc = srl_waliduj_sprawnosc_fizyczna($dane['sprawnosc_fizyczna']??'');
    if (!$walidacja_sprawnosc['valid']) {
        $errors['sprawnosc_fizyczna'] = $walidacja_sprawnosc['message'];
        error_log('SRL DEBUG - Błąd sprawności fizycznej: ' . $dane['sprawnosc_fizyczna']??'BRAK');
    }
    if (!isset($dane['akceptacja_regulaminu']) || $dane['akceptacja_regulaminu'] !== true) {
        $errors['akceptacja_regulaminu'] = 'Musisz zaakceptować regulamin.';
    }
    return array('valid' => empty($errors), 'errors' => $errors);
}
function srl_waliduj_pole_tekstowe($wartosc, $nazwa_pola, $min_length = 2, $max_length = 100) {
    if (empty($wartosc)) {
        return array('valid' => false, 'message' => $nazwa_pola . ' jest wymagane.');
    }
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
    if (empty($kod)) {
        return array('valid' => false, 'message' => 'Kod vouchera jest wymagany.');
    }
    $kod = strtoupper(trim($kod));
    if (strlen($kod) < 3 || strlen($kod) > 12) {
        return array('valid' => false, 'message' => 'Kod vouchera musi mieć od 3 do 12 znaków.');
    }
    if (!preg_match('/^[A-Z0-9]+$/', $kod)) {
        return array('valid' => false, 'message' => 'Kod vouchera może zawierać tylko wielkie litery i cyfry.');
    }
    return array('valid' => true, 'message' => '', 'kod' => $kod);
}
function srl_sprawdz_czy_mozna_anulowac_rezerwacje($data_lotu, $godzina_lotu, $godzin_przed = 48) {
    if (empty($data_lotu) || empty($godzina_lotu)) {
        return array('can_cancel' => false, 'message' => 'Brak danych o terminie lotu.');
    }
    $datetime_lotu = $data_lotu . ' ' . $godzina_lotu;
    $timestamp_lotu = strtotime($datetime_lotu);
    if ($timestamp_lotu === false) {
        return array('can_cancel' => false, 'message' => 'Nieprawidłowy format daty/godziny lotu.');
    }
    $czas_do_lotu = $timestamp_lotu - time();
    $wymagany_czas = $godzin_przed * 3600;
    if ($czas_do_lotu < $wymagany_czas) {
        return array('can_cancel' => false, 'message' => "Nie można anulować rezerwacji na mniej niż {$godzin_przed}h przed lotem.");
    }
    return array('can_cancel' => true, 'message' => '');
}
