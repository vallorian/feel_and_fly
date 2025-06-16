<?php

if (!defined('ABSPATH')) {
    exit;
}

function srl_wyslij_email_potwierdzenia($user_id, $slot, $lot) {
    $user = get_userdata($user_id);
    if (!$user) {
        error_log('SRL: Nie można wysłać emaila - użytkownik nie istnieje: ' . $user_id);
        return false;
    }

    $data_lotu = srl_formatuj_date($slot['data']);
    $godzina_lotu = srl_formatuj_czas($slot['godzina_start']);

    $subject = 'Potwierdzenie rezerwacji lotu tandemowego';

    $message = "Dzień dobry {$user->display_name},\n\n";
    $message .= "Twoja rezerwacja lotu tandemowego została potwierdzona!\n\n";
    $message .= "Szczegóły:\n";
    $message .= "📅 Data: {$data_lotu}\n";
    $message .= "⏰ Godzina: {$godzina_lotu}\n";
    $message .= "🎫 Produkt: {$lot['nazwa_produktu']}\n\n";
    $message .= "Pamiętaj:\n";
    $message .= "- Zgłoś się 30 minut przed godziną lotu\n";
    $message .= "- Weź ze sobą dokument tożsamości\n";
    $message .= "- Ubierz się stosownie do warunków pogodowych\n\n";
    $message .= "W razie pytań, skontaktuj się z nami.\n\n";
    $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );

    $sent = wp_mail($user->user_email, $subject, $message, $headers);

    if (!$sent) {
        error_log('SRL: Nie udało się wysłać emaila potwierdzenia do: ' . $user->user_email);
    }

    return $sent;
}

function srl_wyslij_email_anulowania($user_id, $slot, $lot, $powod = '') {
    $user = get_userdata($user_id);
    if (!$user) {
        error_log('SRL: Nie można wysłać emaila - użytkownik nie istnieje: ' . $user_id);
        return false;
    }

    $data_lotu = srl_formatuj_date($slot['data']);
    $godzina_lotu = srl_formatuj_czas($slot['godzina_start']);

    $subject = 'Anulowanie rezerwacji lotu tandemowego';

    $message = "Dzień dobry {$user->display_name},\n\n";
    $message .= "Informujemy, że Twoja rezerwacja lotu tandemowego została anulowana.\n\n";
    $message .= "Szczegóły anulowanej rezerwacji:\n";
    $message .= "📅 Data: {$data_lotu}\n";
    $message .= "⏰ Godzina: {$godzina_lotu}\n";
    $message .= "🎫 Produkt: {$lot['nazwa_produktu']}\n\n";

    if (!empty($powod)) {
        $message .= "Powód anulowania: {$powod}\n\n";
    }

    $message .= "Twój lot został przywrócony do stanu dostępnego - możesz ponownie dokonać rezerwacji w dogodnym dla Ciebie terminie.\n\n";
    $message .= "W razie pytań, skontaktuj się z nami.\n\n";
    $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );

    $sent = wp_mail($user->user_email, $subject, $message, $headers);

    if (!$sent) {
        error_log('SRL: Nie udało się wysłać emaila anulowania do: ' . $user->user_email);
    }

    return $sent;
}

function srl_wyslij_email_voucher($email_odbiorcy, $kod_vouchera, $nazwa_produktu, $data_waznosci, $buyer_name = '') {
    if (!is_email($email_odbiorcy)) {
        error_log('SRL: Nieprawidłowy email odbiorcy vouchera: ' . $email_odbiorcy);
        return false;
    }

    $data_waznosci_formatted = srl_formatuj_date($data_waznosci);
    $nazwa_odbiorcy = !empty($buyer_name) ? $buyer_name : 'Drogi Odbiorco';

    $subject = 'Voucher upominkowy na lot tandemowy';

    $message = "Dzień dobry {$nazwa_odbiorcy},\n\n";
    $message .= "Otrzymujesz voucher upominkowy na lot tandemowy!\n\n";
    $message .= "Szczegóły vouchera:\n";
    $message .= "🎫 Kod vouchera: {$kod_vouchera}\n";
    $message .= "✈️ Produkt: {$nazwa_produktu}\n";
    $message .= "📅 Ważny do: {$data_waznosci_formatted}\n\n";
    $message .= "Jak wykorzystać voucher:\n";
    $message .= "1. Zarejestruj się na naszej stronie internetowej\n";
    $message .= "2. Przejdź do sekcji rezerwacji lotów\n";
    $message .= "3. Wprowadź kod vouchera: {$kod_vouchera}\n";
    $message .= "4. Wybierz dogodny termin lotu\n\n";
    $message .= "Voucher jest ważny do dnia {$data_waznosci_formatted}.\n\n";
    $message .= "W razie pytań, skontaktuj się z nami.\n\n";
    $message .= "Życzymy wspaniałych wrażeń!\nZespół Lotów Tandemowych";

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );

    $sent = wp_mail($email_odbiorcy, $subject, $message, $headers);

    if (!$sent) {
        error_log('SRL: Nie udało się wysłać emaila z voucherem do: ' . $email_odbiorcy);
    }

    return $sent;
}

function srl_wyslij_email_przypomnienie($user_id, $slot, $lot, $dni_przed = 1) {
    $user = get_userdata($user_id);
    if (!$user) {
        error_log('SRL: Nie można wysłać emaila - użytkownik nie istnieje: ' . $user_id);
        return false;
    }

    $data_lotu = srl_formatuj_date($slot['data']);
    $godzina_lotu = srl_formatuj_czas($slot['godzina_start']);

    if ($dni_przed == 1) {
        $subject = 'Przypomnienie: Twój lot tandemowy jutro!';
        $kiedy = 'jutro';
    } else {
        $subject = "Przypomnienie: Twój lot tandemowy za {$dni_przed} dni";
        $kiedy = "za {$dni_przed} dni";
    }

    $message = "Dzień dobry {$user->display_name},\n\n";
    $message .= "Przypominamy o Twoim nadchodzącym locie tandemowym {$kiedy}!\n\n";
    $message .= "Szczegóły:\n";
    $message .= "📅 Data: {$data_lotu}\n";
    $message .= "⏰ Godzina: {$godzina_lotu}\n";
    $message .= "🎫 Produkt: {$lot['nazwa_produktu']}\n\n";
    $message .= "Ważne informacje:\n";
    $message .= "- Zgłoś się 30 minut przed godziną lotu\n";
    $message .= "- Weź ze sobą dokument tożsamości\n";
    $message .= "- Ubierz się stosownie do warunków pogodowych\n";
    $message .= "- W przypadku złej pogody, skontaktujemy się z Tobą\n\n";
    $message .= "Cieszymy się na spotkanie!\n\n";
    $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );

    $sent = wp_mail($user->user_email, $subject, $message, $headers);

    if (!$sent) {
        error_log('SRL: Nie udało się wysłać emaila przypomnienia do: ' . $user->user_email);
    }

    return $sent;
}

function srl_wyslij_email_wygasniecia_lotu($user_id, $lot, $dni_przed = 7) {
    $user = get_userdata($user_id);
    if (!$user) {
        error_log('SRL: Nie można wysłać emaila - użytkownik nie istnieje: ' . $user_id);
        return false;
    }

    $data_waznosci = srl_formatuj_date($lot['data_waznosci']);

    if ($dni_przed == 1) {
        $subject = 'Uwaga: Twój lot tandemowy wygasa jutro!';
        $kiedy = 'jutro';
    } else {
        $subject = "Uwaga: Twój lot tandemowy wygasa za {$dni_przed} dni";
        $kiedy = "za {$dni_przed} dni";
    }

    $message = "Dzień dobry {$user->display_name},\n\n";
    $message .= "Przypominamy, że Twój lot tandemowy wygasa {$kiedy}!\n\n";
    $message .= "Szczegóły lotu:\n";
    $message .= "🎫 Produkt: {$lot['nazwa_produktu']}\n";
    $message .= "📅 Data ważności: {$data_waznosci}\n\n";
    $message .= "Aby nie utracić możliwości skorzystania z lotu, zarezerwuj termin już dziś!\n\n";
    $message .= "Jak dokonać rezerwacji:\n";
    $message .= "1. Zaloguj się na naszej stronie\n";
    $message .= "2. Przejdź do sekcji rezerwacji\n";
    $message .= "3. Wybierz dogodny termin\n\n";
    $message .= "W razie pytań, skontaktuj się z nami.\n\n";
    $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );

    $sent = wp_mail($user->user_email, $subject, $message, $headers);

    if (!$sent) {
        error_log('SRL: Nie udało się wysłać emaila o wygaśnięciu do: ' . $user->user_email);
    }

    return $sent;
}

function srl_wyslij_email_administratora($temat, $wiadomosc, $dane_dodatkowe = array()) {
    $admin_email = get_option('admin_email');
    if (empty($admin_email)) {
        error_log('SRL: Brak emaila administratora');
        return false;
    }

    $subject = '[Loty Tandemowe] ' . $temat;

    $message = $wiadomosc . "\n\n";

    if (!empty($dane_dodatkowe)) {
        $message .= "Dodatkowe informacje:\n";
        foreach ($dane_dodatkowe as $klucz => $wartosc) {
            $message .= "- {$klucz}: {$wartosc}\n";
        }
        $message .= "\n";
    }

    $message .= "Data: " . date('d.m.Y H:i:s') . "\n";
    $message .= "System Rezerwacji Lotów";

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: System <noreply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>'
    );

    $sent = wp_mail($admin_email, $subject, $message, $headers);

    if (!$sent) {
        error_log('SRL: Nie udało się wysłać emaila do administratora');
    }

    return $sent;
}

function srl_waliduj_szablon_emaila($szablon) {
    $wymagane_pola = array('subject', 'message');
    $errors = array();

    foreach ($wymagane_pola as $pole) {
        if (empty($szablon[$pole])) {
            $errors[] = "Pole '{$pole}' jest wymagane.";
        }
    }

    if (!empty($szablon['subject']) && strlen($szablon['subject']) > 200) {
        $errors[] = 'Temat emaila nie może być dłuższy niż 200 znaków.';
    }

    if (!empty($szablon['message']) && strlen($szablon['message']) > 5000) {
        $errors[] = 'Treść emaila nie może być dłuższa niż 5000 znaków.';
    }

    return array(
        'valid' => empty($errors),
        'errors' => $errors
    );
}

function srl_zastap_zmienne_w_emailu($tekst, $zmienne = array()) {
    $domyslne_zmienne = array(
        '{SITE_NAME}' => get_option('blogname'),
        '{SITE_URL}' => home_url(),
        '{ADMIN_EMAIL}' => get_option('admin_email'),
        '{DATE}' => date('d.m.Y'),
        '{TIME}' => date('H:i'),
        '{DATETIME}' => date('d.m.Y H:i')
    );

    $wszystkie_zmienne = array_merge($domyslne_zmienne, $zmienne);

    return str_replace(array_keys($wszystkie_zmienne), array_values($wszystkie_zmienne), $tekst);
}

function srl_sprawdz_czy_email_dozwolony($email) {
    if (!is_email($email)) {
        return array('allowed' => false, 'message' => 'Nieprawidłowy format adresu email.');
    }

    $blacklisted_domains = array(
        'tempmail.org',
        '10minutemail.com',
        'guerrillamail.com',
        'mailinator.com'
    );

    $domain = substr(strrchr($email, "@"), 1);

    if (in_array(strtolower($domain), $blacklisted_domains)) {
        return array('allowed' => false, 'message' => 'Adresy z tymczasowych serwisów email nie są dozwolone.');
    }

    return array('allowed' => true, 'message' => '');
}