<?php

if (!defined('ABSPATH')) {
    exit;
}

function srl_kategoryzuj_akcje($typ) {
    $kategorie = array(
        'ZMIANA STATUSU' => array(
            'rezerwacja_klient', 
            'anulowanie_klient', 
            'zmiana_statusu_admin', 
            'realizacja_admin', 
            'odwolanie_przez_organizatora', 
            'przywrocenie_przez_admin',
            'anulowanie_przez_organizatora',
            'przypisanie_admin' 
        ),
        'DOKUPIENIE WARIANTU' => array(
            'dokupienie_filmowanie', 
            'dokupienie_akrobacje', 
            'przedluzenie_waznosci',
			'zmiana_opcji'
        ),
        'SYSTEMOWE' => array(
            'przypisanie_id', 
            'opcja_przy_zakupie',
            'zakup_lotu'
        ),
        'ZMIANA DANYCH' => array(
            'edycja_danych_pasazera', 
            'aktualizacja_profilu',
            'zapisanie_danych_klienta'
        )
    );

    foreach ($kategorie as $kategoria => $typy) {
        if (in_array($typ, $typy)) {
            return $kategoria;
        }
    }

    return 'INNE'; 
}

function srl_formatuj_status_badge($status) {
    $statusy = array(
        'wolny' => array('label' => 'WOLNY', 'color' => '#28a745'),
        'zarezerwowany' => array('label' => 'ZAREZERWOWANY', 'color' => '#ffc107'),
        'zrealizowany' => array('label' => 'ZREALIZOWANY', 'color' => '#007bff'),
        'odwolany' => array('label' => 'ODWOŁANY', 'color' => '#dc3545'),
        'przedawniony' => array('label' => 'PRZEDAWNIONY', 'color' => '#6c757d')
    );

    $config = isset($statusy[$status]) ? $statusy[$status] : array('label' => strtoupper($status), 'color' => '#6c757d');

    return sprintf(
        '<span class="srl-status-badge" style="background: %s; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">%s</span>',
        $config['color'],
        $config['label']
    );
}

function srl_uproszczony_opis_akcji($typ, $szczegoly) {
    switch ($typ) {

        case 'przypisanie_admin':
			if (isset($szczegoly['termin'])) {
				return srl_formatuj_status_badge('wolny'). ' → ' . srl_formatuj_status_badge('zarezerwowany') . '<br><small>Przypisano termin: ' . esc_html($szczegoly['termin']) . '</small>';
			}
			return srl_formatuj_status_badge('wolny'). ' → ' . srl_formatuj_status_badge('zarezerwowany');
		case 'rezerwacja_klient':
            if (isset($szczegoly['termin'])) {
                return srl_formatuj_status_badge('wolny'). ' → ' . srl_formatuj_status_badge('zarezerwowany') . '<br><small>Termin: ' . esc_html($szczegoly['termin']) . '</small>';
            }
            return srl_formatuj_status_badge('wolny'). ' → ' . srl_formatuj_status_badge('zarezerwowany');

        case 'anulowanie_klient':
            if (isset($szczegoly['anulowany_termin'])) {
                return srl_formatuj_status_badge('zarezerwowany') . ' → ' . srl_formatuj_status_badge('wolny') . '<br><small>Anulowany termin: ' . esc_html($szczegoly['anulowany_termin']) . '</small>';
            }
            return srl_formatuj_status_badge('zarezerwowany') . ' → ' . srl_formatuj_status_badge('wolny');

        case 'realizacja_admin':
            return srl_formatuj_status_badge('zarezerwowany') . ' → ' . srl_formatuj_status_badge('zrealizowany');

        case 'odwolanie_przez_organizatora':
        case 'anulowanie_przez_organizatora':
            if (isset($szczegoly['odwolany_termin'])) {
                return srl_formatuj_status_badge('zarezerwowany') . ' → ' . srl_formatuj_status_badge('odwolany') . ' → ' . srl_formatuj_status_badge('wolny') . '<br><small>Odwołany termin: ' . esc_html($szczegoly['odwolany_termin']) . '</small>';
            }
            return srl_formatuj_status_badge('zarezerwowany') . ' → ' . srl_formatuj_status_badge('odwolany') . ' → ' . srl_formatuj_status_badge('wolny');

        case 'przywrocenie_przez_admin':
            if (isset($szczegoly['przywrocony_termin'])) {
                return srl_formatuj_status_badge('odwolany') . ' → ' . srl_formatuj_status_badge('zarezerwowany') . '<br><small>Przywrócony termin: ' . esc_html($szczegoly['przywrocony_termin']) . '</small>';
            }
            return srl_formatuj_status_badge('odwolany') . ' → ' . srl_formatuj_status_badge('zarezerwowany');

        case 'zmiana_statusu_admin':
            $stary = isset($szczegoly['stary_status']) ? $szczegoly['stary_status'] : '';
            $nowy = isset($szczegoly['nowy_status']) ? $szczegoly['nowy_status'] : '';
            if ($stary && $nowy) {
                return srl_formatuj_status_badge($stary) . ' → ' . srl_formatuj_status_badge($nowy);
            }
            return 'Zmiana statusu przez administratora';

        case 'dokupienie_filmowanie':
            return 'Dokupiono opcję <strong>filmowania</strong>';

        case 'dokupienie_akrobacje':
            return 'Dokupiono opcję <strong>akrobacji</strong>';

        case 'przedluzenie_waznosci':
            if (isset($szczegoly['nowa_data_waznosci'])) {
                return 'Przedłużono ważność do <strong>' . date('d.m.Y', strtotime($szczegoly['nowa_data_waznosci'])) . '</strong>';
            }
            return 'Przedłużono ważność lotu';
        case 'zmiana_opcji':

			if (isset($szczegoly['opcja'])) {
				$opcja = $szczegoly['opcja'];
				if ($opcja === 'filmowanie' || strpos($opcja, 'filmowanie') !== false) {
					return 'Dokupiono opcję <strong>filmowania</strong>';
				} elseif ($opcja === 'akrobacje' || strpos($opcja, 'akrobacje') !== false) {
					return 'Dokupiono opcję <strong>akrobacji</strong>';
				}
			}

			if (isset($szczegoly['nazwa_produktu'])) {
				$nazwa = strtolower($szczegoly['nazwa_produktu']);
				if (strpos($nazwa, 'filmowanie') !== false || strpos($nazwa, 'film') !== false) {
					return 'Dokupiono opcję <strong>filmowania</strong>';
				} elseif (strpos($nazwa, 'akrobacje') !== false || strpos($nazwa, 'akrobacj') !== false) {
					return 'Dokupiono opcję <strong>akrobacji</strong>';
				}
			}

			return 'Dokupiono opcję lotu';    

        case 'przypisanie_id':
            $lot_id = isset($szczegoly['lot_id']) ? $szczegoly['lot_id'] : 'nieznany';
            $data_waznosci = isset($szczegoly['data_waznosci']) ? date('d.m.Y', strtotime($szczegoly['data_waznosci'])) : '';
            if ($data_waznosci) {
                return 'Przypisano ID lotu <strong>#' . $lot_id . '</strong><br><small>Ważny do: ' . $data_waznosci . '</small>';
            }
            return 'Przypisano ID lotu <strong>#' . $lot_id . '</strong>';

        case 'opcja_przy_zakupie':
            $opcja = isset($szczegoly['opcja']) ? $szczegoly['opcja'] : '';
            if ($opcja === 'filmowanie') {
                return 'Lot zakupiony <strong>z filmowaniem</strong>';
            } elseif ($opcja === 'akrobacje') {
                return 'Lot zakupiony <strong>z akrobacjami</strong>';
            }
            return 'Opcja dodana przy zakupie';

        case 'edycja_danych_pasazera':
        case 'zapisanie_danych_klienta':
            return 'Zaktualizowano dane pasażera';

        default:
            return 'Nieznana akcja';
    }
}

function srl_czy_duplikat($nowy_wpis, $istniejace_wpisy) {
    if (empty($istniejace_wpisy)) {
        return false;
    }

    $czas_graniczny = strtotime($nowy_wpis['data']) - 300; 

    foreach ($istniejace_wpisy as $wpis) {
        $czas_wpisu = strtotime($wpis['data']);

        if ($czas_wpisu < $czas_graniczny) {
            continue;
        }

        if ($wpis['typ'] === $nowy_wpis['typ'] && 
            $wpis['executor'] === $nowy_wpis['executor']) {

            if ($nowy_wpis['typ'] === 'zmiana_statusu_admin') {
                $nowe_szczegoly = $nowy_wpis['szczegoly'];
                $stare_szczegoly = $wpis['szczegoly'];

                if (isset($nowe_szczegoly['stary_status']) && isset($nowe_szczegoly['nowy_status']) &&
                    isset($stare_szczegoly['stary_status']) && isset($stare_szczegoly['nowy_status'])) {

                    if ($nowe_szczegoly['stary_status'] === $stare_szczegoly['stary_status'] &&
                        $nowe_szczegoly['nowy_status'] === $stare_szczegoly['nowy_status']) {
                        return true; 
                    }
                }
            }

            if (abs($czas_wpisu - strtotime($nowy_wpis['data'])) < 60) { 
                return true;
            }
        }
    }

    return false;
}

function srl_dopisz_do_historii_lotu_v2($lot_id, $wpis) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

    $kategoria = srl_kategoryzuj_akcje($wpis['typ']);
    error_log("SRL DEBUG: Zapisuję wpis historii - Typ: '{$wpis['typ']}', Kategoria: '$kategoria', Lot ID: $lot_id");

    $aktualna_historia = $wpdb->get_var($wpdb->prepare(
        "SELECT historia_modyfikacji FROM $tabela_loty WHERE id = %d",
        $lot_id
    ));

    $historia = array();
    if ($aktualna_historia) {
        $historia = json_decode($aktualna_historia, true);
        if (!is_array($historia)) {
            $historia = array();
        }
    }

    if (srl_czy_duplikat($wpis, $historia)) {
        error_log("SRL DEBUG: Odrzucono duplikat wpisu dla lotu #$lot_id");
        return false; 
    }

    $historia[] = $wpis;

    if (count($historia) > 50) {
        $historia = array_slice($historia, -50);
    }

    $result = $wpdb->update(
        $tabela_loty,
        array('historia_modyfikacji' => json_encode($historia)),
        array('id' => $lot_id),
        array('%s'),
        array('%d')
    );

    if ($result !== false) {
        error_log("SRL DEBUG: Historia zapisana pomyślnie dla lotu #$lot_id");
    } else {
        error_log("SRL DEBUG: BŁĄD zapisu historii dla lotu #$lot_id: " . $wpdb->last_error);
    }

    return $result !== false;
}

function srl_formatuj_nazwe_akcji_v2($wpis) {
    $kategoria = srl_kategoryzuj_akcje($wpis['typ']);
    return $kategoria;
}

function srl_pobierz_historie_lotu_v2($lot_id) {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);

    if (!$lot) {
        return array('lot_id' => $lot_id, 'events' => array());
    }

    $events = array();

    if (!empty($lot['historia_modyfikacji'])) {
        $historia_json = json_decode($lot['historia_modyfikacji'], true);
        if (is_array($historia_json)) {
            foreach ($historia_json as $modyfikacja) {
                $events[] = array(
                    'timestamp' => strtotime($modyfikacja['data']),
                    'date' => $modyfikacja['data'],
                    'type' => $modyfikacja['typ'],
                    'action_name' => srl_formatuj_nazwe_akcji_v2($modyfikacja),
                    'executor' => isset($modyfikacja['executor']) ? $modyfikacja['executor'] : 'System',
                    'details' => srl_uproszczony_opis_akcji($modyfikacja['typ'], $modyfikacja['szczegoly'] ?? array()),
                    'formatted_date' => date('d.m.Y H:i', strtotime($modyfikacja['data']))
                );
            }
        }
    }

    usort($events, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    return array(
        'lot_id' => $lot_id,
        'events' => $events
    );
}

function srl_get_current_datetime() {
    return current_time('Y-m-d H:i:s');
}

function srl_wyczysc_historie_wszystkich_lotow() {
    if (!current_user_can('manage_options')) {
        return false;
    }

    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

    $result = $wpdb->query(
        "UPDATE $tabela_loty SET historia_modyfikacji = NULL"
    );

    return $result;
}

function srl_przebuduj_historie_lotow() {
    if (!current_user_can('manage_options')) {
        return false;
    }

    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

    $loty = $wpdb->get_results(
        "SELECT * FROM $tabela_loty ORDER BY data_zakupu ASC",
        ARRAY_A
    );

    $przetworzone = 0;

    foreach ($loty as $lot) {

        $wpdb->update(
            $tabela_loty,
            array('historia_modyfikacji' => null),
            array('id' => $lot['id']),
            array('%s'),
            array('%d')
        );

        $historia_poczatkowa = array();

        $historia_poczatkowa[] = array(
            'data' => $lot['data_zakupu'],
            'typ' => 'przypisanie_id',
            'executor' => 'System',
            'szczegoly' => array(
                'lot_id' => $lot['id'],
                'nazwa_produktu' => $lot['nazwa_produktu'],
                'order_id' => $lot['order_id'],
                'user_id' => $lot['user_id'],
                'data_waznosci' => $lot['data_waznosci']
            )
        );

        if ($lot['ma_filmowanie']) {
            $historia_poczatkowa[] = array(
                'data' => $lot['data_zakupu'],
                'typ' => 'opcja_przy_zakupie',
                'executor' => 'System',
                'szczegoly' => array(
                    'opcja' => 'filmowanie',
                    'dodano_przy_zakupie' => true,
                    'order_id' => $lot['order_id']
                )
            );
        }

        if ($lot['ma_akrobacje']) {
            $historia_poczatkowa[] = array(
                'data' => $lot['data_zakupu'],
                'typ' => 'opcja_przy_zakupie',
                'executor' => 'System',
                'szczegoly' => array(
                    'opcja' => 'akrobacje',
                    'dodano_przy_zakupie' => true,
                    'order_id' => $lot['order_id']
                )
            );
        }

        $wpdb->update(
            $tabela_loty,
            array('historia_modyfikacji' => json_encode($historia_poczatkowa)),
            array('id' => $lot['id']),
            array('%s'),
            array('%d')
        );

        $przetworzone++;
    }

    return $przetworzone;
}

function srl_debug_kategoryzuj_typ($typ) {
    $kategoria = srl_kategoryzuj_akcje($typ);
    error_log("SRL DEBUG: Typ '$typ' -> Kategoria '$kategoria'");
    return $kategoria;
}