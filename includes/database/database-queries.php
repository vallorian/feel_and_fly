<?php
/**
 * Wspólne zapytania do bazy danych
 */

if (!defined('ABSPATH')) {
    exit;
}

function srl_pobierz_loty_uzytkownika($user_id, $status = null) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    $where_clause = "WHERE user_id = %d AND data_waznosci >= CURDATE()";
    $params = array($user_id);
    
    if ($status) {
        $where_clause .= " AND status = %s";
        $params[] = $status;
    }
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $tabela_loty $where_clause ORDER BY data_zakupu DESC",
        ...$params
    ), ARRAY_A);
}

function srl_pobierz_slot_szczegoly($termin_id) {
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_terminy WHERE id = %d",
        $termin_id
    ), ARRAY_A);
}

function srl_sprawdz_czy_slot_wolny($termin_id) {
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
        $termin_id
    ));
    
    return intval($count) > 0;
}

function srl_pobierz_dostepne_sloty_dnia($data) {
    global $wpdb;
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, pilot_id, godzina_start, godzina_koniec 
         FROM $tabela_terminy 
         WHERE data = %s 
         AND status = 'Wolny'
         ORDER BY pilot_id ASC, godzina_start ASC",
        $data
    ), ARRAY_A);
}

function srl_zwroc_godziny_wg_pilota($data) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $wynik = $wpdb->get_results($wpdb->prepare(
        "SELECT t.id, t.pilot_id, t.godzina_start, t.godzina_koniec, t.status, t.klient_id, t.notatka,
                zl.id as lot_id, zl.user_id as lot_user_id, zl.status as lot_status, zl.dane_pasazera
           FROM $tabela t
           LEFT JOIN {$wpdb->prefix}srl_zakupione_loty zl ON t.id = zl.termin_id
          WHERE t.data = %s
          ORDER BY t.pilot_id ASC, t.godzina_start ASC",
        sanitize_text_field($data)
    ), ARRAY_A);

    $noweWgPilota = array();
    foreach ($wynik as $w) {
        $pid = intval($w['pilot_id']);
        if (!isset($noweWgPilota[$pid])) {
            $noweWgPilota[$pid] = array();
        }
        
        $klient_nazwa = '';
        $link_zamowienia = '';
        $dane_pasazera_cache = null;
        
        if (($w['status'] === 'Zarezerwowany' || $w['status'] === 'Zrealizowany') && intval($w['klient_id']) > 0) {
            $user = get_userdata(intval($w['klient_id']));
            if ($user) {
                $imie = get_user_meta(intval($w['klient_id']), 'srl_imie', true);
                $nazwisko = get_user_meta(intval($w['klient_id']), 'srl_nazwisko', true);
                
                if ($imie && $nazwisko) {
                    $klient_nazwa = $imie . ' ' . $nazwisko;
                } else {
                    $klient_nazwa = $user->display_name;
                }
                $link_zamowienia = admin_url('edit.php?post_type=shop_order&customer=' . intval($w['klient_id']));
                
                $dane_pasazera_cache = array();
                
                if (!empty($w['dane_pasazera'])) {
                    $dane_z_lotu = json_decode($w['dane_pasazera'], true);
                    if ($dane_z_lotu && is_array($dane_z_lotu)) {
                        $dane_pasazera_cache = $dane_z_lotu;
                    }
                }
                
                if (empty($dane_pasazera_cache['imie'])) {
                    $dane_pasazera_cache = array(
                        'imie' => $imie,
                        'nazwisko' => $nazwisko,
                        'rok_urodzenia' => get_user_meta(intval($w['klient_id']), 'srl_rok_urodzenia', true),
                        'telefon' => get_user_meta(intval($w['klient_id']), 'srl_telefon', true),
                        'kategoria_wagowa' => get_user_meta(intval($w['klient_id']), 'srl_kategoria_wagowa', true),
                        'sprawnosc_fizyczna' => get_user_meta(intval($w['klient_id']), 'srl_sprawnosc_fizyczna', true),
                        'uwagi' => get_user_meta(intval($w['klient_id']), 'srl_uwagi', true)
                    );
                }
            }
        } elseif ($w['status'] === 'Prywatny' && !empty($w['notatka'])) {
            $dane_prywatne = json_decode($w['notatka'], true);
            if ($dane_prywatne && isset($dane_prywatne['imie']) && isset($dane_prywatne['nazwisko'])) {
                $klient_nazwa = $dane_prywatne['imie'] . ' ' . $dane_prywatne['nazwisko'];
                
                // Dodaj pełne dane prywatne do cache dla harmonogramu
                $dane_pasazera_cache = $dane_prywatne;
            }
        }
        
        $noweWgPilota[$pid][] = array(
            'id' => intval($w['id']),
            'start' => substr($w['godzina_start'], 0, 5),
            'koniec' => substr($w['godzina_koniec'], 0, 5),
            'status' => $w['status'],
            'klient_id' => intval($w['klient_id']),
            'klient_nazwa' => $klient_nazwa,
            'link_zamowienia' => $link_zamowienia,
            'lot_id' => $w['lot_id'] ? intval($w['lot_id']) : null,
            'notatka' => $w['notatka'],
            'dane_pasazera_cache' => $dane_pasazera_cache
        );
    }

    wp_send_json_success(array(
        'godziny_wg_pilota' => $noweWgPilota
    ));
}


function srl_pobierz_pelna_historie_lotu($lot_id) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    // Pobierz podstawowe dane lotu
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot) {
        return array('lot_id' => $lot_id, 'events' => array());
    }
    
    $events = array();
    
    // 1. Zakup lotu (zawsze pierwszy)
    $events[] = array(
        'timestamp' => strtotime($lot['data_zakupu']),
        'date' => $lot['data_zakupu'],
        'type' => 'zakup',
        'action_name' => 'Zakup lotu',
        'executor' => 'Klient',
        'details' => sprintf('Zamówienie #%d - %s', $lot['order_id'], $lot['nazwa_produktu'])
    );
    
	// 2. Historia modyfikacji (z JSON)
	if (!empty($lot['historia_modyfikacji'])) {
		$historia_json = json_decode($lot['historia_modyfikacji'], true);
		if (is_array($historia_json)) {
			foreach ($historia_json as $modyfikacja) {
				// Określ wykonawcę na podstawie opisu
				$executor = 'Admin';
				if (isset($modyfikacja['executor'])) {
					$executor = $modyfikacja['executor'];
				} elseif (strpos(strtolower($modyfikacja['opis'] ?? ''), 'zamówienie') !== false) {
					$executor = 'Klient';
				}
				
				$events[] = array(
					'timestamp' => strtotime($modyfikacja['data']),
					'date' => $modyfikacja['data'],
					'type' => $modyfikacja['typ'] ?? 'modyfikacja',
					'action_name' => srl_formatuj_nazwe_akcji($modyfikacja),
					'executor' => $executor,
					'details' => $modyfikacja['opis']
				);
			}
		}
	}
	
    // 3. Rezerwacje i zmiany statusu
    if ($lot['termin_id']) {
        // Sprawdź czy lot był kiedyś zarezerwowany
        if ($lot['data_rezerwacji']) {
            $events[] = array(
                'timestamp' => strtotime($lot['data_rezerwacji']),
                'date' => $lot['data_rezerwacji'],
                'type' => 'rezerwacja',
                'action_name' => 'Rezerwacja terminu',
                'executor' => 'Klient',
                'details' => sprintf('Zarezerwowano termin na %s', 
                    srl_pobierz_szczegoly_terminu($lot['termin_id']))
            );
        }
    }
    
    // 4. Zmiany statusu lotu
    if ($lot['status'] === 'zrealizowany') {
        // Znajdź termin realizacji (data lotu w przeszłości)
        $data_realizacji = srl_estymuj_date_realizacji($lot);
        if ($data_realizacji) {
            $events[] = array(
                'timestamp' => strtotime($data_realizacji),
                'date' => $data_realizacji,
                'type' => 'realizacja',
                'action_name' => 'Realizacja lotu',
                'executor' => 'Admin',
                'details' => 'Lot został oznaczony jako zrealizowany'
            );
        }
    }
    
    // 5. Dokupione opcje (z zamówień WooCommerce)
    $dodatkowe_opcje = srl_pobierz_historie_opcji($lot['user_id'], $lot_id);
    $events = array_merge($events, $dodatkowe_opcje);
    
    // Sortuj chronologicznie
    usort($events, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });
    
    // Formatuj daty dla wyświetlenia
    foreach ($events as &$event) {
        $event['formatted_date'] = date('d.m.Y H:i', $event['timestamp']);
    }
    
    return array(
        'lot_id' => $lot_id,
        'events' => $events
    );
}


function srl_pobierz_szczegoly_terminu($termin_id) {
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_terminy';
    
    $termin = $wpdb->get_row($wpdb->prepare(
        "SELECT data, godzina_start, godzina_koniec FROM $tabela WHERE id = %d",
        $termin_id
    ), ARRAY_A);
    
    if ($termin) {
        return sprintf('%s %s-%s', 
            srl_formatuj_date($termin['data']), 
            substr($termin['godzina_start'], 0, 5),
            substr($termin['godzina_koniec'], 0, 5)
        );
    }
    
    return 'nieznany termin';
}

function srl_estymuj_date_realizacji($lot) {
    if ($lot['termin_id']) {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';
        
        $data_lotu = $wpdb->get_var($wpdb->prepare(
            "SELECT data FROM $tabela WHERE id = %d",
            $lot['termin_id']
        ));
        
        if ($data_lotu && strtotime($data_lotu) <= time()) {
            // Jeśli lot był w przeszłości, przyjmij że został zrealizowany tego dnia
            return $data_lotu . ' 18:00:00';
        }
    }
    
    return null;
}

function srl_pobierz_historie_opcji($user_id, $lot_id) {
    global $wpdb;
    $events = array();
    $opcje_produkty = srl_get_flight_option_product_ids();
    
    // Znajdź zamówienia użytkownika
    $orders = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_date 
         FROM {$wpdb->posts} 
         WHERE post_author = %d 
         AND post_type = 'shop_order' 
         AND post_status IN ('wc-completed', 'wc-processing')
         ORDER BY post_date ASC",
        $user_id
    ), ARRAY_A);
    
    foreach ($orders as $order_data) {
        $order = wc_get_order($order_data['ID']);
        if (!$order) continue;
        
        // Sprawdź każdy item w zamówieniu osobno
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $lot_meta = $item->get_meta('_srl_lot_id');
            $quantity = $item->get_quantity();
            
            // Sprawdź czy to opcja dla naszego lotu
            if ($lot_meta == $lot_id && in_array($product_id, $opcje_produkty)) {
                $opcja_nazwa = '';
                $details = '';
                
                if ($product_id == $opcje_produkty['filmowanie']) {
                    $opcja_nazwa = 'Dokupiono filmowanie';
                    $details = sprintf('Zamówienie #%d - %s (Item ID: %d)', 
                        $order_data['ID'], $item->get_name(), $item_id);
                } elseif ($product_id == $opcje_produkty['akrobacje']) {
                    $opcja_nazwa = 'Dokupiono akrobacje';
                    $details = sprintf('Zamówienie #%d - %s (Item ID: %d)', 
                        $order_data['ID'], $item->get_name(), $item_id);
                } elseif ($product_id == $opcje_produkty['przedluzenie']) {
                    $opcja_nazwa = 'Przedłużenie ważności';
                    $details = sprintf('Zamówienie #%d - %s (Item ID: %d)', 
                        $order_data['ID'], $item->get_name(), $item_id);
                }
                
                if ($opcja_nazwa) {
                    // Dodaj osobny wpis dla każdej ilości (jeśli quantity > 1)
                    for ($i = 0; $i < $quantity; $i++) {
                        $unique_timestamp = strtotime($order_data['post_date']) + $i; // Dodaj sekundy dla unikalności
                        
                        $events[] = array(
                            'timestamp' => $unique_timestamp,
                            'date' => $order_data['post_date'],
                            'type' => 'dokupienie',
                            'action_name' => $opcja_nazwa,
                            'executor' => 'Klient',
                            'details' => $details . ($quantity > 1 ? ' (' . ($i + 1) . '/' . $quantity . ')' : ''),
                            'unique_key' => $order_data['ID'] . '_' . $item_id . '_' . $i // Klucz unikalności
                        );
                    }
                }
            }
        }
    }
    
    return $events;
}