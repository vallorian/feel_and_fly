<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Konfiguracja partnerów i typów voucherów
 */
function srl_get_partner_voucher_config() {
    return array(
        'prezent_marzen' => array(
            'nazwa' => 'PrezentMarzeń',
            'typy' => array(
                'lot_3_osoby' => array('nazwa' => 'Lot tandemowy dla 3 osób', 'liczba_osob' => 3),
                'lot_2_osoby' => array('nazwa' => 'Lot tandemowy dla 2 osób', 'liczba_osob' => 2),
                'lot_1_osoba' => array('nazwa' => 'Lot tandemowy dla 1 osoby', 'liczba_osob' => 1)
            )
        ),
        'groupon' => array(
            'nazwa' => 'Groupon',
            'typy' => array(
                'lot_1_osoba' => array('nazwa' => 'Lot tandemowy dla 1 osoby', 'liczba_osob' => 1)
            )
        )
    );
}

/**
 * Pobiera listę partnerów do selecta
 */
function srl_get_partners_list() {
    $config = srl_get_partner_voucher_config();
    $partners = array();
    
    foreach ($config as $key => $partner) {
        $partners[$key] = $partner['nazwa'];
    }
    
    return $partners;
}

/**
 * Pobiera typy voucherów dla danego partnera
 */
function srl_get_partner_voucher_types($partner_key) {
    $config = srl_get_partner_voucher_config();
    
    if (!isset($config[$partner_key])) {
        return array();
    }
    
    return $config[$partner_key]['typy'];
}

/**
 * Pobiera liczbę osób dla danego typu vouchera
 */
function srl_get_voucher_passenger_count($partner_key, $voucher_type) {
    $config = srl_get_partner_voucher_config();
    
    if (!isset($config[$partner_key]['typy'][$voucher_type])) {
        return 1;
    }
    
    return $config[$partner_key]['typy'][$voucher_type]['liczba_osob'];
}

/**
 * Zapisuje voucher partnera do bazy danych
 */
function srl_save_partner_voucher($data) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
    
    // Walidacja danych
    $validation = srl_validate_partner_voucher_data($data);
    if (!$validation['valid']) {
        return array('success' => false, 'message' => implode(', ', $validation['errors']));
    }
    
    // Sprawdź czy voucher już istnieje
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $tabela WHERE kod_vouchera = %s AND kod_zabezpieczajacy = %s AND partner = %s",
        $data['kod_vouchera'],
        $data['kod_zabezpieczajacy'],
        $data['partner']
    ));
    
    if ($existing) {
        return array('success' => false, 'message' => 'Voucher z tymi kodami już istnieje w systemie.');
    }
    
    $result = $wpdb->insert(
        $tabela,
        array(
            'partner' => $data['partner'],
            'typ_vouchera' => $data['typ_vouchera'],
            'kod_vouchera' => $data['kod_vouchera'],
            'kod_zabezpieczajacy' => $data['kod_zabezpieczajacy'],
            'data_waznosci_vouchera' => $data['data_waznosci'], // NOWE POLE
            'liczba_osob' => $data['liczba_osob'],
            'dane_pasazerow' => json_encode($data['dane_pasazerow']),
            'status' => 'oczekuje',
            'klient_id' => $data['klient_id'],
            'data_zgloszenia' => current_time('mysql'),
            'id_oryginalnego' => isset($data['id_oryginalnego']) ? $data['id_oryginalnego'] : null
        ),
        array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d') // DODANO %s dla daty
    );
    
    if ($result === false) {
        return array('success' => false, 'message' => 'Błąd zapisu do bazy danych.');
    }
    
    $voucher_id = $wpdb->insert_id;
    
    // Wyślij email do administratora
    srl_send_partner_voucher_notification_email($voucher_id);
    
    return array('success' => true, 'voucher_id' => $voucher_id);
}

// ===== ZMIEŃ W partner-voucher-functions.php FUNKCJĘ srl_validate_partner_voucher_data() =====

function srl_validate_partner_voucher_data($data) {
    $errors = array();
    
    // Sprawdź partnera
    $config = srl_get_partner_voucher_config();
    if (empty($data['partner']) || !isset($config[$data['partner']])) {
        $errors[] = 'Nieprawidłowy partner.';
    }
    
    // Sprawdź typ vouchera
    if (empty($data['typ_vouchera']) || !isset($config[$data['partner']]['typy'][$data['typ_vouchera']])) {
        $errors[] = 'Nieprawidłowy typ vouchera.';
    }
    
    // Sprawdź kody
    if (empty($data['kod_vouchera']) || strlen($data['kod_vouchera']) < 3) {
        $errors[] = 'Kod vouchera musi mieć co najmniej 3 znaki.';
    }
    
    if (empty($data['kod_zabezpieczajacy']) || strlen($data['kod_zabezpieczajacy']) < 3) {
        $errors[] = 'Kod zabezpieczający musi mieć co najmniej 3 znaki.';
    }
    
    // NOWA WALIDACJA: Sprawdź datę ważności
    if (empty($data['data_waznosci'])) {
        $errors[] = 'Data ważności vouchera jest wymagana.';
    } else {
        $data_waznosci = strtotime($data['data_waznosci']);
        $dzisiaj = strtotime('today');
        
        if ($data_waznosci === false) {
            $errors[] = 'Nieprawidłowy format daty ważności.';
        } elseif ($data_waznosci < $dzisiaj) {
            $errors[] = 'Data ważności vouchera nie może być z przeszłości.';
        }
    }
    
    // Sprawdź dane pasażerów
    if (empty($data['dane_pasazerow']) || !is_array($data['dane_pasazerow'])) {
        $errors[] = 'Brak danych pasażerów.';
    } else {
        foreach ($data['dane_pasazerow'] as $index => $pasazer) {
            $walidacja_pasazera = srl_waliduj_dane_pasazera($pasazer);
            if (!$walidacja_pasazera['valid']) {
                foreach ($walidacja_pasazera['errors'] as $field => $error) {
                    $errors[] = "Pasażer " . ($index + 1) . " - {$error}";
                }
            }
        }
    }
    
    return array(
        'valid' => empty($errors),
        'errors' => $errors
    );
}

/**
 * Pobiera vouchery partnera dla admina
 */
function srl_get_partner_vouchers($status = null, $limit = 50) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
    
    $where_clause = "WHERE 1=1";
    $params = array();
    
    if ($status) {
        $where_clause .= " AND status = %s";
        $params[] = $status;
    }
    
    $query = "SELECT * FROM $tabela $where_clause ORDER BY data_zgloszenia DESC LIMIT %d";
    $params[] = $limit;
    
    return $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);
}

/**
 * Pobiera szczegóły vouchera partnera
 */
function srl_get_partner_voucher($voucher_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
    
    $voucher = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela WHERE id = %d",
        $voucher_id
    ), ARRAY_A);
    
    if ($voucher) {
        $voucher['dane_pasazerow'] = json_decode($voucher['dane_pasazerow'], true);
    }
    
    return $voucher;
}

/**
 * Zatwierdza voucher partnera i tworzy loty
 */
function srl_approve_partner_voucher($voucher_id) {
    global $wpdb;
    $tabela_vouchery = $wpdb->prefix . 'srl_vouchery_partnerzy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $voucher = srl_get_partner_voucher($voucher_id);
    if (!$voucher || $voucher['status'] !== 'oczekuje') {
        return array('success' => false, 'message' => 'Voucher nie istnieje lub już został przetworzony.');
    }
    
    // Rozpocznij transakcję
    $wpdb->query('START TRANSACTION');
    
    try {
        // Zmień status vouchera
        $update_result = $wpdb->update(
            $tabela_vouchery,
            array(
                'status' => 'zatwierdzony',
                'data_modyfikacji' => current_time('mysql')
            ),
            array('id' => $voucher_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($update_result === false) {
            throw new Exception('Błąd aktualizacji statusu vouchera.');
        }
        
        // Utwórz loty dla każdego pasażera
        $created_flights = array();
        $data_waznosci = !empty($voucher['data_waznosci_vouchera']) ? $voucher['data_waznosci_vouchera'] : date('Y-m-d', strtotime('+1 year'));
        $data_zakupu = current_time('mysql');
        
        foreach ($voucher['dane_pasazerow'] as $index => $pasazer) {
            $result = $wpdb->insert(
                $tabela_loty,
                array(
                    'order_item_id' => 0, // Voucher partnera nie ma order_item_id
                    'order_id' => 0, // Voucher partnera nie ma order_id
                    'user_id' => $voucher['klient_id'],
                    'imie' => $pasazer['imie'],
                    'nazwisko' => $pasazer['nazwisko'],
                    'nazwa_produktu' => 'Lot tandemowy - Voucher ' . srl_get_partner_voucher_config()[$voucher['partner']]['nazwa'],
                    'status' => 'wolny',
                    'data_zakupu' => $data_zakupu,
                    'data_waznosci' => $data_waznosci,
                    'ma_filmowanie' => 0,
                    'ma_akrobacje' => 0,
                    'dane_pasazera' => json_encode($pasazer)
                ),
                array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
            );
            
            if ($result === false) {
                throw new Exception('Błąd tworzenia lotu dla pasażera ' . ($index + 1));
            }
            
            $lot_id = $wpdb->insert_id;
            $created_flights[] = $lot_id;
            
            // Dodaj historię
            $historia_poczatkowa = array(
                array(
                    'data' => $data_zakupu,
                    'typ' => 'przypisanie_id',
                    'executor' => 'System',
                    'szczegoly' => array(
                        'lot_id' => $lot_id,
                        'voucher_partnera_id' => $voucher_id,
                        'partner' => $voucher['partner'],
                        'typ_vouchera' => $voucher['typ_vouchera'],
                        'pasazer_numer' => $index + 1,
                        'data_waznosci' => $data_waznosci
                    )
                )
            );
            
            $wpdb->update(
                $tabela_loty,
                array('historia_modyfikacji' => json_encode($historia_poczatkowa)),
                array('id' => $lot_id),
                array('%s'),
                array('%d')
            );
        }
        
        $wpdb->query('COMMIT');
        
        // Wyślij email do klienta
        srl_send_partner_voucher_approval_email($voucher_id, $created_flights);
        
        return array(
            'success' => true, 
            'message' => 'Voucher zatwierdzony. Utworzono ' . count($created_flights) . ' lotów.',
            'created_flights' => $created_flights
        );
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return array('success' => false, 'message' => $e->getMessage());
    }
}

/**
 * Odrzuca voucher partnera
 */
function srl_reject_partner_voucher($voucher_id, $reason) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_vouchery_partnerzy';
    
    $voucher = srl_get_partner_voucher($voucher_id);
    if (!$voucher || $voucher['status'] !== 'oczekuje') {
        return array('success' => false, 'message' => 'Voucher nie istnieje lub już został przetworzony.');
    }
    
    $result = $wpdb->update(
        $tabela,
        array(
            'status' => 'odrzucony',
            'powod_odrzucenia' => $reason,
            'data_modyfikacji' => current_time('mysql')
        ),
        array('id' => $voucher_id),
        array('%s', '%s', '%s'),
        array('%d')
    );
    
    if ($result === false) {
        return array('success' => false, 'message' => 'Błąd aktualizacji statusu vouchera.');
    }
    
    // Wyślij email do klienta
    srl_send_partner_voucher_rejection_email($voucher_id, $reason);
    
    return array('success' => true, 'message' => 'Voucher został odrzucony.');
}

/**
 * Wysyła email powiadomienia do administratora o nowym voucherze
 */
function srl_send_partner_voucher_notification_email($voucher_id) {
    $voucher = srl_get_partner_voucher($voucher_id);
    if (!$voucher) return false;
    
    $user = get_userdata($voucher['klient_id']);
    $config = srl_get_partner_voucher_config();
    $partner_name = $config[$voucher['partner']]['nazwa'];
    $voucher_type_name = $config[$voucher['partner']]['typy'][$voucher['typ_vouchera']]['nazwa'];
    
    $subject = 'Nowy voucher partnera do weryfikacji';
    $message = "Został zgłoszony nowy voucher partnera do weryfikacji.\n\n";
    $message .= "Szczegóły:\n";
    $message .= "Partner: {$partner_name}\n";
    $message .= "Typ: {$voucher_type_name}\n";
    $message .= "Kod vouchera: {$voucher['kod_vouchera']}\n";
    $message .= "Kod zabezpieczający: {$voucher['kod_zabezpieczajacy']}\n";
    $message .= "Liczba osób: {$voucher['liczba_osob']}\n";
    $message .= "Klient: {$user->display_name} ({$user->user_email})\n";
    $message .= "Data zgłoszenia: {$voucher['data_zgloszenia']}\n\n";
    $message .= "Link do panelu: " . admin_url('admin.php?page=srl-voucher') . "\n\n";
    
    return srl_wyslij_email_administratora($subject, $message);
}

/**
 * Wysyła email do klienta o zatwierdzeniu vouchera
 */
function srl_send_partner_voucher_approval_email($voucher_id, $created_flights) {
    $voucher = srl_get_partner_voucher($voucher_id);
    if (!$voucher) return false;
    
    $user = get_userdata($voucher['klient_id']);
    if (!$user) return false;
    
    $config = srl_get_partner_voucher_config();
    $partner_name = $config[$voucher['partner']]['nazwa'];
    $voucher_type_name = $config[$voucher['partner']]['typy'][$voucher['typ_vouchera']]['nazwa'];
    
    $subject = 'Voucher partnera został zatwierdzony';
    $message = "Dzień dobry {$user->display_name},\n\n";
    $message .= "Twój voucher partnera został zatwierdzony!\n\n";
    $message .= "Szczegóły:\n";
    $message .= "Partner: {$partner_name}\n";
    $message .= "Typ: {$voucher_type_name}\n";
    $message .= "Kod vouchera: {$voucher['kod_vouchera']}\n\n";
    $message .= "Utworzono " . count($created_flights) . " lotów dla podanych pasażerów.\n";
    $message .= "Możesz teraz dokonać rezerwacji terminów na naszej stronie.\n\n";
    $message .= "Link do rezerwacji: " . home_url('/rezerwuj-lot/') . "\n\n";
    $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";
    
    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );
    
    return wp_mail($user->user_email, $subject, $message, $headers);
}

/**
 * Wysyła email do klienta o odrzuceniu vouchera
 */
function srl_send_partner_voucher_rejection_email($voucher_id, $reason) {
    $voucher = srl_get_partner_voucher($voucher_id);
    if (!$voucher) return false;
    
    $user = get_userdata($voucher['klient_id']);
    if (!$user) return false;
    
    $config = srl_get_partner_voucher_config();
    $partner_name = $config[$voucher['partner']]['nazwa'];
    
    $subject = 'Voucher partnera został odrzucony';
    $message = "Dzień dobry {$user->display_name},\n\n";
    $message .= "Niestety, Twój voucher partnera {$partner_name} został odrzucony.\n\n";
    $message .= "Powód odrzucenia:\n{$reason}\n\n";
    $message .= "Możesz poprawić dane i ponownie wysłać voucher.\n\n";
    $message .= "Link do formularza: " . home_url('/rezerwuj-lot/') . "\n\n";
    $message .= "W razie pytań, skontaktuj się z nami.\n\n";
    $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";
    
    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );
    
    return wp_mail($user->user_email, $subject, $message, $headers);
}