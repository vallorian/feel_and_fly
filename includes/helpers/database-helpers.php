<?php if (!defined('ABSPATH')) {exit;}

function srl_execute_query($query, $params = array(), $return_type = 'results') {
    global $wpdb;
    
    if (!empty($params)) {
        $prepared_query = $wpdb->prepare($query, ...$params);
    } else {
        $prepared_query = $query;
    }
    
    switch ($return_type) {
        case 'var':
            return $wpdb->get_var($prepared_query);
        case 'row':
            return $wpdb->get_row($prepared_query, ARRAY_A);
        case 'results':
            return $wpdb->get_results($prepared_query, ARRAY_A);
        case 'count':
            return intval($wpdb->get_var($prepared_query));
        default:
            return $wpdb->get_results($prepared_query, ARRAY_A);
    }
}

function srl_get_flight_by_id($flight_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    return srl_execute_query(
        "SELECT * FROM $tabela WHERE id = %d",
        array($flight_id),
        'row'
    );
}

function srl_get_user_flights($user_id, $status = null, $include_expired = false) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    $where_conditions = array("user_id = %d");
    $params = array($user_id);
    
    if (!$include_expired) {
        $where_conditions[] = "data_waznosci >= CURDATE()";
    }
    
    if ($status) {
        $where_conditions[] = "status = %s";
        $params[] = $status;
    }
    
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
    
    return srl_execute_query(
        "SELECT * FROM $tabela $where_clause ORDER BY data_zakupu DESC",
        $params
    );
}

function srl_get_available_slots($date) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    return srl_execute_query(
        "SELECT id, pilot_id, godzina_start, godzina_koniec 
         FROM $tabela 
         WHERE data = %s AND status = 'Wolny'
         ORDER BY pilot_id ASC, godzina_start ASC",
        array($date)
    );
}

function srl_is_slot_available($slot_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $count = srl_execute_query(
        "SELECT COUNT(*) FROM $tabela WHERE id = %d AND status = 'Wolny'",
        array($slot_id),
        'count'
    );
    
    return $count > 0;
}

function srl_get_slot_details($slot_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    return srl_execute_query(
        "SELECT * FROM $tabela WHERE id = %d",
        array($slot_id),
        'row'
    );
}

function srl_reserve_slot($slot_id, $flight_id, $user_id) {
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Rozpocznij transakcję
    $wpdb->query('START TRANSACTION');
    
    try {
        // Sprawdź czy slot jest nadal wolny
        if (!srl_is_slot_available($slot_id)) {
            throw new Exception('Slot nie jest już dostępny.');
        }
        
        // Aktualizuj slot
        $result1 = $wpdb->update(
            $tabela_terminy,
            array(
                'status' => 'Zarezerwowany',
                'klient_id' => $user_id
            ),
            array('id' => $slot_id),
            array('%s', '%d'),
            array('%d')
        );
        
        // Aktualizuj lot
        $result2 = $wpdb->update(
            $tabela_loty,
            array(
                'status' => 'zarezerwowany',
                'termin_id' => $slot_id,
                'data_rezerwacji' => current_time('mysql')
            ),
            array('id' => $flight_id),
            array('%s', '%d', '%s'),
            array('%d')
        );
        
        if ($result1 === false || $result2 === false) {
            throw new Exception('Błąd podczas rezerwacji.');
        }
        
        $wpdb->query('COMMIT');
        return array('success' => true, 'message' => 'Rezerwacja wykonana pomyślnie.');
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return array('success' => false, 'message' => $e->getMessage());
    }
}

function srl_cancel_reservation($flight_id) {
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Pobierz informacje o locie
    $lot = srl_get_flight_by_id($flight_id);
    if (!$lot || !$lot['termin_id']) {
        return array('success' => false, 'message' => 'Lot nie ma przypisanego terminu.');
    }
    
    $wpdb->query('START TRANSACTION');
    
    try {
        // Zwolnij slot
        $result1 = $wpdb->update(
            $tabela_terminy,
            array(
                'status' => 'Wolny',
                'klient_id' => null
            ),
            array('id' => $lot['termin_id']),
            array('%s', '%d'),
            array('%d')
        );
        
        // Zaktualizuj lot
        $result2 = $wpdb->update(
            $tabela_loty,
            array(
                'status' => 'wolny',
                'termin_id' => null,
                'data_rezerwacji' => null
            ),
            array('id' => $flight_id),
            array('%s', '%d', '%s'),
            array('%d')
        );
        
        if ($result1 === false || $result2 === false) {
            throw new Exception('Błąd podczas anulowania rezerwacji.');
        }
        
        $wpdb->query('COMMIT');
        return array('success' => true, 'message' => 'Rezerwacja została anulowana.');
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return array('success' => false, 'message' => $e->getMessage());
    }
}

function srl_update_flight_status($flight_id, $new_status, $additional_data = array()) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    
    $update_data = array_merge(array('status' => $new_status), $additional_data);
    $format = array_fill(0, count($update_data), '%s');
    
    $result = $wpdb->update(
        $tabela,
        $update_data,
        array('id' => $flight_id),
        $format,
        array('%d')
    );
    
    return $result !== false;
}

function srl_batch_insert($table_name, $data, $format = null) {
    global $wpdb;
    
    if (empty($data)) {
        return false;
    }
    
    $table = $wpdb->prefix . $table_name;
    $first_row = reset($data);
    $columns = array_keys($first_row);
    $placeholders = array();
    
    $values = array();
    foreach ($data as $row) {
        $row_placeholders = array();
        foreach ($columns as $column) {
            $row_placeholders[] = '%s';
            $values[] = $row[$column];
        }
        $placeholders[] = '(' . implode(', ', $row_placeholders) . ')';
    }
    
    $query = sprintf(
        "INSERT INTO %s (%s) VALUES %s",
        $table,
        implode(', ', $columns),
        implode(', ', $placeholders)
    );
    
    return $wpdb->query($wpdb->prepare($query, $values));
}

function srl_get_day_schedule_optimized($date) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $slots = srl_execute_query(
        "SELECT t.id, t.pilot_id, t.godzina_start, t.godzina_koniec, t.status, t.klient_id, t.notatka,
                zl.id as lot_id, zl.user_id as lot_user_id, zl.status as lot_status, zl.dane_pasazera
           FROM $tabela t
           LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
          WHERE t.data = %s
          ORDER BY t.pilot_id ASC, t.godzina_start ASC",
        array($date)
    );
    
    $grouped_by_pilot = array();
    $user_cache = array();
    
    foreach ($slots as $slot) {
        $pilot_id = intval($slot['pilot_id']);
        if (!isset($grouped_by_pilot[$pilot_id])) {
            $grouped_by_pilot[$pilot_id] = array();
        }
        
        $client_name = '';
        $order_link = '';
        $passenger_data_cache = null;
        
        if (($slot['status'] === 'Zarezerwowany' || $slot['status'] === 'Zrealizowany') && intval($slot['klient_id']) > 0) {
            $user_id = intval($slot['klient_id']);
            
            // Cache użytkowników aby uniknąć wielokrotnych zapytań
            if (!isset($user_cache[$user_id])) {
                $user_cache[$user_id] = srl_get_user_full_data($user_id);
            }
            
            $user_data = $user_cache[$user_id];
            if ($user_data) {
                $client_name = ($user_data['imie'] && $user_data['nazwisko']) 
                    ? $user_data['imie'] . ' ' . $user_data['nazwisko']
                    : $user_data['display_name'];
                
                $order_link = admin_url('edit.php?post_type=shop_order&customer=' . $user_id);
                
                // Użyj danych z lotu jeśli dostępne, inaczej z profilu
                if (!empty($slot['dane_pasazera'])) {
                    $passenger_data_cache = json_decode($slot['dane_pasazera'], true);
                }
                
                if (empty($passenger_data_cache['imie'])) {
                    $passenger_data_cache = array(
                        'imie' => $user_data['imie'],
                        'nazwisko' => $user_data['nazwisko'],
                        'rok_urodzenia' => $user_data['rok_urodzenia'],
                        'telefon' => $user_data['telefon'],
                        'kategoria_wagowa' => $user_data['kategoria_wagowa'],
                        'sprawnosc_fizyczna' => $user_data['sprawnosc_fizyczna'],
                        'uwagi' => $user_data['uwagi']
                    );
                }
            }
        } elseif ($slot['status'] === 'Prywatny' && !empty($slot['notatka'])) {
            $private_data = json_decode($slot['notatka'], true);
            if ($private_data && isset($private_data['imie']) && isset($private_data['nazwisko'])) {
                $client_name = $private_data['imie'] . ' ' . $private_data['nazwisko'];
                $passenger_data_cache = $private_data;
            }
        }
        
        $grouped_by_pilot[$pilot_id][] = array(
            'id' => intval($slot['id']),
            'start' => substr($slot['godzina_start'], 0, 5),
            'koniec' => substr($slot['godzina_koniec'], 0, 5),
            'status' => $slot['status'],
            'klient_id' => intval($slot['klient_id']),
            'klient_nazwa' => $client_name,
            'link_zamowienia' => $order_link,
            'lot_id' => $slot['lot_id'] ? intval($slot['lot_id']) : null,
            'notatka' => $slot['notatka'],
            'dane_pasazera_cache' => $passenger_data_cache
        );
    }
    
    return $grouped_by_pilot;
}

function srl_format_slot_details($slot_id) {
    $slot = srl_get_slot_details($slot_id);
    
    if ($slot) {
        return sprintf('%s %s-%s', 
            srl_formatuj_date($slot['data']), 
            substr($slot['godzina_start'], 0, 5),
            substr($slot['godzina_koniec'], 0, 5)
        );
    }
    
    return 'nieznany termin';
}