<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Historia_Functions {
    
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

    public function dopiszDoHistoriiLotu($lot_id, $wpis) {
        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $aktualna_historia = $wpdb->get_var($wpdb->prepare(
            "SELECT historia_modyfikacji FROM $tabela_loty WHERE id = %d", $lot_id
        ));

        $historia = $aktualna_historia ? json_decode($aktualna_historia, true) : array();
        if (!is_array($historia)) $historia = array();

        if ($this->czyDuplikat($wpis, $historia)) return false;

        $historia[] = $wpis;
        if (count($historia) > 50) $historia = array_slice($historia, -50);

        $result = $wpdb->update(
            $tabela_loty,
            array('historia_modyfikacji' => json_encode($historia)),
            array('id' => $lot_id),
            array('%s'), array('%d')
        );

        return $result !== false;
    }

    public function pobierzHistorieLotu($lot_id) {
        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $lot = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabela_loty WHERE id = %d", $lot_id), ARRAY_A);
        if (!$lot) return array('lot_id' => $lot_id, 'events' => array());

        $events = array();
        if (!empty($lot['historia_modyfikacji'])) {
            $historia_json = json_decode($lot['historia_modyfikacji'], true);
            if (is_array($historia_json)) {
                foreach ($historia_json as $modyfikacja) {
                    $events[] = array(
                        'timestamp' => strtotime($modyfikacja['data']),
                        'date' => $modyfikacja['data'],
                        'type' => $modyfikacja['typ'],
                        'action_name' => $this->kategoryzujAkcje($modyfikacja['typ']),
                        'executor' => $modyfikacja['executor'] ?? 'System',
                        'details' => $this->uproszczonyOpisAkcji($modyfikacja['typ'], $modyfikacja['szczegoly'] ?? array()),
                        'formatted_date' => date('d.m.Y H:i', strtotime($modyfikacja['data']))
                    );
                }
            }
        }

        usort($events, function($a, $b) { return $a['timestamp'] - $b['timestamp']; });
        return array('lot_id' => $lot_id, 'events' => $events);
    }

    public function kategoryzujAkcje($typ) {
        $kategorie = array(
            'ZMIANA STATUSU' => array('rezerwacja_klient', 'anulowanie_klient', 'zmiana_statusu_admin', 'realizacja_admin', 
                                     'odwolanie_przez_organizatora', 'przywrocenie_przez_admin', 'anulowanie_przez_organizatora',
                                     'przypisanie_admin', 'zmiana_terminu_admin'),
            'DOKUPIENIE WARIANTU' => array('dokupienie_filmowanie', 'dokupienie_akrobacje', 'przedluzenie_waznosci', 'zmiana_opcji'),
            'SYSTEMOWE' => array('przypisanie_id', 'opcja_przy_zakupie', 'zakup_lotu'),
            'ZMIANA DANYCH' => array('edycja_danych_pasazera', 'aktualizacja_profilu', 'zapisanie_danych_klienta')
        );

        foreach ($kategorie as $kategoria => $typy) {
            if (in_array($typ, $typy)) return $kategoria;
        }
        return 'INNE';
    }

    public function uproszczonyOpisAkcji($typ, $szczegoly) {
        $status_badge = function($status) {
            $colors = array('wolny' => '#28a745', 'zarezerwowany' => '#ffc107', 'zrealizowany' => '#007bff', 
                           'odwolany' => '#dc3545', 'przedawniony' => '#6c757d');
            $color = $colors[$status] ?? '#6c757d';
            return sprintf('<span class="srl-status-badge" style="background: %s; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">%s</span>', 
                          $color, strtoupper($status));
        };

        switch ($typ) {
            case 'przypisanie_admin':
                $text = $status_badge('wolny') . ' → ' . $status_badge('zarezerwowany');
                return isset($szczegoly['termin']) ? $text . '<br><small>Przypisano termin: ' . esc_html($szczegoly['termin']) . '</small>' : $text;

            case 'rezerwacja_klient':
                $text = $status_badge('wolny') . ' → ' . $status_badge('zarezerwowany');
                return isset($szczegoly['termin']) ? $text . '<br><small>Termin: ' . esc_html($szczegoly['termin']) . '</small>' : $text;

            case 'anulowanie_klient':
                $text = $status_badge('zarezerwowany') . ' → ' . $status_badge('wolny');
                return isset($szczegoly['anulowany_termin']) ? $text . '<br><small>Anulowany termin: ' . esc_html($szczegoly['anulowany_termin']) . '</small>' : $text;

            case 'realizacja_admin':
                return $status_badge('zarezerwowany') . ' → ' . $status_badge('zrealizowany');

            case 'odwolanie_przez_organizatora':
            case 'anulowanie_przez_organizatora':
                $text = $status_badge('zarezerwowany') . ' → ' . $status_badge('odwolany') . ' → ' . $status_badge('wolny');
                return isset($szczegoly['odwolany_termin']) ? $text . '<br><small>Odwołany termin: ' . esc_html($szczegoly['odwolany_termin']) . '</small>' : $text;

            case 'przywrocenie_przez_admin':
                $text = $status_badge('odwolany') . ' → ' . $status_badge('zarezerwowany');
                return isset($szczegoly['przywrocony_termin']) ? $text . '<br><small>Przywrócony termin: ' . esc_html($szczegoly['przywrocony_termin']) . '</small>' : $text;

            case 'zmiana_terminu_admin':
                if (isset($szczegoly['stary_termin']) && isset($szczegoly['nowy_termin'])) {
                    return '🔄 Zmiana terminu przez admin<br><small><strong>Z:</strong> ' . esc_html($szczegoly['stary_termin']) . '</small><br><small><strong>Na:</strong> ' . esc_html($szczegoly['nowy_termin']) . '</small>';
                }
                return '🔄 Zmiana terminu przez administratora';

            case 'zmiana_statusu_admin':
                $stary = $szczegoly['stary_status'] ?? '';
                $nowy = $szczegoly['nowy_status'] ?? '';
                return ($stary && $nowy) ? $status_badge($stary) . ' → ' . $status_badge($nowy) : 'Zmiana statusu przez administratora';

            case 'dokupienie_filmowanie':
                $quantity_info = isset($szczegoly['quantity_info']) ? ' (' . $szczegoly['quantity_info'] . ')' : '';
                return 'Dokupiono opcję <strong>filmowania</strong>' . $quantity_info;

            case 'dokupienie_akrobacje':
                $quantity_info = isset($szczegoly['quantity_info']) ? ' (' . $szczegoly['quantity_info'] . ')' : '';
                return 'Dokupiono opcję <strong>akrobacji</strong>' . $quantity_info;

            case 'przedluzenie_waznosci':
                return isset($szczegoly['nowa_data_waznosci']) ? 
                       'Przedłużono ważność do <strong>' . date('d.m.Y', strtotime($szczegoly['nowa_data_waznosci'])) . '</strong>' : 
                       'Przedłużono ważność lotu';

            case 'zmiana_opcji':
                if (isset($szczegoly['opcja'])) {
                    $opcja = $szczegoly['opcja'];
                    if ($opcja === 'filmowanie' || strpos($opcja, 'filmowanie') !== false) return 'Dokupiono opcję <strong>filmowania</strong>';
                    if ($opcja === 'akrobacje' || strpos($opcja, 'akrobacje') !== false) return 'Dokupiono opcję <strong>akrobacji</strong>';
                }
                if (isset($szczegoly['nazwa_produktu'])) {
                    $nazwa = strtolower($szczegoly['nazwa_produktu']);
                    if (strpos($nazwa, 'filmowanie') !== false || strpos($nazwa, 'film') !== false) return 'Dokupiono opcję <strong>filmowania</strong>';
                    if (strpos($nazwa, 'akrobacje') !== false || strpos($nazwa, 'akrobacj') !== false) return 'Dokupiono opcję <strong>akrobacji</strong>';
                }
                return 'Dokupiono opcję lotu';

            case 'przypisanie_id':
                $lot_id = $szczegoly['lot_id'] ?? 'nieznany';
                $data_waznosci = isset($szczegoly['data_waznosci']) ? date('d.m.Y', strtotime($szczegoly['data_waznosci'])) : '';
                $text = 'Przypisano ID lotu <strong>#' . $lot_id . '</strong>';
                return $data_waznosci ? $text . '<br><small>Ważny do: ' . $data_waznosci . '</small>' : $text;

            case 'opcja_przy_zakupie':
                $opcja = $szczegoly['opcja'] ?? '';
                if ($opcja === 'filmowanie') return 'Lot zakupiony <strong>z filmowaniem</strong>';
                if ($opcja === 'akrobacje') return 'Lot zakupiony <strong>z akrobacjami</strong>';
                return 'Opcja dodana przy zakupie';

            case 'edycja_danych_pasazera':
            case 'zapisanie_danych_klienta':
                return 'Zaktualizowano dane pasażera';

            default:
                return 'Nieznana akcja';
        }
    }

    public function czyDuplikat($nowy_wpis, $istniejace_wpisy) {
        if (empty($istniejace_wpisy)) return false;

        $czas_graniczny = strtotime($nowy_wpis['data']) - 300;
        foreach ($istniejace_wpisy as $wpis) {
            $czas_wpisu = strtotime($wpis['data']);
            if ($czas_wpisu < $czas_graniczny) continue;
            if ($wpis['typ'] === $nowy_wpis['typ'] && 
                $wpis['executor'] === $nowy_wpis['executor'] &&
                abs($czas_wpisu - strtotime($nowy_wpis['data'])) < 60) {
                return true;
            }
        }
        return false;
    }

    public function wyczyscHistorieWszystkichLotow() {
        if (!current_user_can('manage_woocommerce')) return false;
        global $wpdb;
        return $wpdb->query("UPDATE {$wpdb->prefix}srl_zakupione_loty SET historia_modyfikacji = NULL");
    }

    public function przebudujHistorieLotow() {
        if (!current_user_can('manage_woocommerce')) return false;

        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $loty = $wpdb->get_results("SELECT * FROM $tabela_loty ORDER BY data_zakupu ASC", ARRAY_A);
        $przetworzone = 0;

        foreach ($loty as $lot) {
            $wpdb->update($tabela_loty, array('historia_modyfikacji' => null), array('id' => $lot['id']), array('%s'), array('%d'));

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
                    'szczegoly' => array('opcja' => 'filmowanie', 'dodano_przy_zakupie' => true, 'order_id' => $lot['order_id'])
                );
            }

            if ($lot['ma_akrobacje']) {
                $historia_poczatkowa[] = array(
                    'data' => $lot['data_zakupu'],
                    'typ' => 'opcja_przy_zakupie',
                    'executor' => 'System',
                    'szczegoly' => array('opcja' => 'akrobacje', 'dodano_przy_zakupie' => true, 'order_id' => $lot['order_id'])
                );
            }

            $wpdb->update($tabela_loty, array('historia_modyfikacji' => json_encode($historia_poczatkowa)), 
                         array('id' => $lot['id']), array('%s'), array('%d'));
            $przetworzone++;
        }

        return $przetworzone;
    }
	
	public function ajaxPobierzHistorieLotu($lot_id) {
		return $this->pobierzHistorieLotu($lot_id);
	}
}