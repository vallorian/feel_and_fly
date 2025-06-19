<?php if (!defined('ABSPATH')) {exit;}

function srl_send_email($to, $subject_key, $template_data = array(), $additional_headers = array()) {
    $templates = array(
        'confirmation' => array(
            'subject' => 'Potwierdzenie rezerwacji lotu tandemowego',
            'body' => "Dzień dobry {display_name},\n\nTwoja rezerwacja lotu tandemowego została potwierdzona!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: {godzina_lotu}\n🎫 Produkt: {nazwa_produktu}\n\nPamiętaj:\n- Zgłoś się 30 minut przed godziną lotu\n- Weź ze sobą dokument tożsamości\n- Ubierz się stosownie do warunków pogodowych\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Lotów Tandemowych"
        ),
        'cancellation' => array(
            'subject' => 'Anulowanie rezerwacji lotu tandemowego',
            'body' => "Dzień dobry {display_name},\n\nInformujemy, że Twoja rezerwacja lotu tandemowego została anulowana.\n\nSzczegóły anulowanej rezerwacji:\n📅 Data: {data_lotu}\n⏰ Godzina: {godzina_lotu}\n🎫 Produkt: {nazwa_produktu}\n\n{powod_text}Twój lot został przywrócony do stanu dostępnego - możesz ponownie dokonać rezerwacji w dogodnym dla Ciebie terminie.\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Lotów Tandemowych"
        ),
        'voucher' => array(
            'subject' => 'Voucher upominkowy na lot tandemowy',
            'body' => "Dzień dobry {nazwa_odbiorcy},\n\nOtrzymujesz voucher upominkowy na lot tandemowy!\n\nSzczegóły vouchera:\n🎫 Kod vouchera: {kod_vouchera}\n✈️ Produkt: {nazwa_produktu}\n📅 Ważny do: {data_waznosci}\n\nJak wykorzystać voucher:\n1. Zarejestruj się na naszej stronie internetowej\n2. Przejdź do sekcji rezerwacji lotów\n3. Wprowadź kod vouchera: {kod_vouchera}\n4. Wybierz dogodny termin lotu\n\nVoucher jest ważny do dnia {data_waznosci}.\n\nW razie pytań, skontaktuj się z nami.\n\nŻyczymy wspaniałych wrażeń!\nZespół Lotów Tandemowych"
        ),
        'reminder' => array(
            'subject' => 'Przypomnienie: Twój lot tandemowy {kiedy}!',
            'body' => "Dzień dobry {display_name},\n\nPrzypominamy o Twoim nadchodzącym locie tandemowym {kiedy}!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: {godzina_lotu}\n🎫 Produkt: {nazwa_produktu}\n\nWażne informacje:\n- Zgłoś się 30 minut przed godziną lotu\n- Weź ze sobą dokument tożsamości\n- Ubierz się stosownie do warunków pogodowych\n- W przypadku złej pogody, skontaktujemy się z Tobą\n\nCieszymy się na spotkanie!\n\nPozdrawiamy,\nZespół Lotów Tandemowych"
        ),
        'expiry_warning' => array(
            'subject' => 'Uwaga: Twój lot tandemowy wygasa {kiedy}!',
            'body' => "Dzień dobry {display_name},\n\nPrzypominamy, że Twój lot tandemowy wygasa {kiedy}!\n\nSzczegóły lotu:\n🎫 Produkt: {nazwa_produktu}\n📅 Data ważności: {data_waznosci}\n\nAby nie utracić możliwości skorzystania z lotu, zarezerwuj termin już dziś!\n\nJak dokonać rezerwacji:\n1. Zaloguj się na naszej stronie\n2. Przejdź do sekcji rezerwacji\n3. Wybierz dogodny termin\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiami,\nZespół Lotów Tandemowych"
        ),
        'partner_approval' => array(
            'subject' => 'Voucher partnera został zatwierdzony',
            'body' => "Dzień dobry {display_name},\n\nTwój voucher partnera został zatwierdzony!\n\nSzczegóły:\nPartner: {partner_name}\nTyp: {voucher_type_name}\nKod vouchera: {kod_vouchera}\n\nUtworzono {flight_count} lotów dla podanych pasażerów.\nMożesz teraz dokonać rezerwacji terminów na naszej stronie.\n\nLink do rezerwacji: {reservation_link}\n\nPozdrawiamy,\nZespół Lotów Tandemowych"
        ),
        'partner_rejection' => array(
            'subject' => 'Voucher partnera został odrzucony',
            'body' => "Dzień dobry {display_name},\n\nNiestety, Twój voucher partnera {partner_name} został odrzucony.\n\nPowód odrzucenia:\n{reason}\n\nMożesz poprawić dane i ponownie wysłać voucher.\n\nLink do formularza: {form_link}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Lotów Tandemowych"
        ),
        'admin_notification' => array(
            'subject' => '[Loty Tandemowe] {temat}',
            'body' => "{wiadomosc}\n\n{dane_dodatkowe}Data: {current_datetime}\nSystem Rezerwacji Lotów"
        )
    );

    if (!isset($templates[$subject_key])) return false;

    $template = $templates[$subject_key];
    $subject = $template['subject'];
    $body = $template['body'];

    foreach ($template_data as $key => $value) {
        $subject = str_replace('{' . $key . '}', $value, $subject);
        $body = str_replace('{' . $key . '}', $value, $body);
    }

    $default_headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );
    
    $headers = array_merge($default_headers, $additional_headers);
    $sent = wp_mail($to, $subject, $body, $headers);
    
    if (!$sent) {
        error_log("SRL: Nie udało się wysłać emaila ({$subject_key}) do: {$to}");
    }
    
    return $sent;
}

function srl_wyslij_email_potwierdzenia($user_id, $slot, $lot) {
    $user = get_userdata($user_id);
    if (!$user) return false;

    return srl_send_email($user->user_email, 'confirmation', array(
        'display_name' => $user->display_name,
        'data_lotu' => srl_formatuj_date($slot['data']),
        'godzina_lotu' => srl_formatuj_czas($slot['godzina_start']),
        'nazwa_produktu' => $lot['nazwa_produktu']
    ));
}

function srl_wyslij_email_anulowania($user_id, $slot, $lot, $powod = '') {
    $user = get_userdata($user_id);
    if (!$user) return false;

    return srl_send_email($user->user_email, 'cancellation', array(
        'display_name' => $user->display_name,
        'data_lotu' => srl_formatuj_date($slot['data']),
        'godzina_lotu' => srl_formatuj_czas($slot['godzina_start']),
        'nazwa_produktu' => $lot['nazwa_produktu'],
        'powod_text' => !empty($powod) ? "Powód anulowania: {$powod}\n\n" : ''
    ));
}

function srl_wyslij_email_voucher($email_odbiorcy, $kod_vouchera, $nazwa_produktu, $data_waznosci, $buyer_name = '') {
    if (!is_email($email_odbiorcy)) return false;

    return srl_send_email($email_odbiorcy, 'voucher', array(
        'nazwa_odbiorcy' => !empty($buyer_name) ? $buyer_name : 'Drogi Odbiorco',
        'kod_vouchera' => $kod_vouchera,
        'nazwa_produktu' => $nazwa_produktu,
        'data_waznosci' => srl_formatuj_date($data_waznosci)
    ));
}

function srl_wyslij_email_przypomnienie($user_id, $slot, $lot, $dni_przed = 1) {
    $user = get_userdata($user_id);
    if (!$user) return false;

    $kiedy = ($dni_przed == 1) ? 'jutro' : "za {$dni_przed} dni";
    
    return srl_send_email($user->user_email, 'reminder', array(
        'display_name' => $user->display_name,
        'kiedy' => $kiedy,
        'data_lotu' => srl_formatuj_date($slot['data']),
        'godzina_lotu' => srl_formatuj_czas($slot['godzina_start']),
        'nazwa_produktu' => $lot['nazwa_produktu']
    ));
}

function srl_wyslij_email_wygasniecia_lotu($user_id, $lot, $dni_przed = 7) {
    $user = get_userdata($user_id);
    if (!$user) return false;

    $kiedy = ($dni_przed == 1) ? 'jutro' : "za {$dni_przed} dni";
    
    return srl_send_email($user->user_email, 'expiry_warning', array(
        'display_name' => $user->display_name,
        'kiedy' => $kiedy,
        'nazwa_produktu' => $lot['nazwa_produktu'],
        'data_waznosci' => srl_formatuj_date($lot['data_waznosci'])
    ));
}

function srl_wyslij_email_administratora($temat, $wiadomosc, $dane_dodatkowe = array()) {
    $admin_email = get_option('admin_email');
    if (empty($admin_email)) return false;

    $dane_text = '';
    if (!empty($dane_dodatkowe)) {
        $dane_text = "Dodatkowe informacje:\n";
        foreach ($dane_dodatkowe as $klucz => $wartosc) {
            $dane_text .= "- {$klucz}: {$wartosc}\n";
        }
        $dane_text .= "\n";
    }

    return srl_send_email($admin_email, 'admin_notification', array(
        'temat' => $temat,
        'wiadomosc' => $wiadomosc,
        'dane_dodatkowe' => $dane_text,
        'current_datetime' => date('d.m.Y H:i:s')
    ), array('From: System <noreply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>'));
}

function srl_send_partner_voucher_approval_email($voucher_id, $created_flights) {
    $voucher = srl_get_partner_voucher($voucher_id);
    if (!$voucher) return false;
    
    $user = get_userdata($voucher['klient_id']);
    if (!$user) return false;
    
    $config = srl_get_partner_voucher_config();
    $partner_name = $config[$voucher['partner']]['nazwa'];
    $voucher_type_name = $config[$voucher['partner']]['typy'][$voucher['typ_vouchera']]['nazwa'];
    
    return srl_send_email($user->user_email, 'partner_approval', array(
        'display_name' => $user->display_name,
        'partner_name' => $partner_name,
        'voucher_type_name' => $voucher_type_name,
        'kod_vouchera' => $voucher['kod_vouchera'],
        'flight_count' => count($created_flights),
        'reservation_link' => home_url('/rezerwuj-lot/')
    ));
}

function srl_send_partner_voucher_rejection_email($voucher_id, $reason) {
    $voucher = srl_get_partner_voucher($voucher_id);
    if (!$voucher) return false;
    
    $user = get_userdata($voucher['klient_id']);
    if (!$user) return false;
    
    $config = srl_get_partner_voucher_config();
    $partner_name = $config[$voucher['partner']]['nazwa'];
    
    return srl_send_email($user->user_email, 'partner_rejection', array(
        'display_name' => $user->display_name,
        'partner_name' => $partner_name,
        'reason' => $reason,
        'form_link' => home_url('/rezerwuj-lot/')
    ));
}