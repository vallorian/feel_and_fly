<?php

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Admin_Calendar {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
    }

    public static function wyswietlKalendarz() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $rok  = isset($_GET['rok']) && intval($_GET['rok']) > 2000 ? intval($_GET['rok']) : date('Y');
        $miesiac = isset($_GET['miesiac']) && intval($_GET['miesiac']) >= 1 && intval($_GET['miesiac']) <= 12
                   ? intval($_GET['miesiac'])
                   : date('n');

        $pierwszy_dzien_miesiaca = strtotime("$rok-$miesiac-01");
        $dni_w_miesiacu = date('t', $pierwszy_dzien_miesiaca);

        $numer_pierwszego_dnia = date('N', $pierwszy_dzien_miesiaca);

        $poczatek_miesiaca = date('Y-m-01', $pierwszy_dzien_miesiaca);
        $koniec_miesiaca  = date('Y-m-t', $pierwszy_dzien_miesiaca);

        $rezultaty = $wpdb->get_results($wpdb->prepare(
            "SELECT data,
                    COUNT(id) AS ilosc_wszystkich,
                    SUM(CASE WHEN status = 'Wolny' THEN 1 ELSE 0 END) AS ilosc_wolnych,
                    SUM(CASE WHEN status = 'Zarezerwowany' THEN 1 ELSE 0 END) AS ilosc_zarezerwowanych,
                    SUM(CASE WHEN status = 'Prywatny' THEN 1 ELSE 0 END) AS ilosc_prywatnych,
                    SUM(CASE WHEN status = 'Zrealizowany' THEN 1 ELSE 0 END) AS ilosc_zrealizowanych,
                    SUM(CASE WHEN status = 'Odwołany przez organizatora' THEN 1 ELSE 0 END) AS ilosc_odwolanych
               FROM $tabela
              WHERE data BETWEEN %s AND %s
              GROUP BY data",
            $poczatek_miesiaca, $koniec_miesiaca
        ), ARRAY_A);

        $dane_miesiac = array();
        foreach ($rezultaty as $wiersz) {
            $dane_miesiac[$wiersz['data']] = array(
                'wszystkie'      => intval($wiersz['ilosc_wszystkich']),
                'wolne'          => intval($wiersz['ilosc_wolnych']),
                'zarezerwowane'  => intval($wiersz['ilosc_zarezerwowanych']),
                'prywatne'       => intval($wiersz['ilosc_prywatnych']),
                'zrealizowane'   => intval($wiersz['ilosc_zrealizowanych']),
                'odwolane'       => intval($wiersz['ilosc_odwolanych']),

                'zarezerwowane_razem' => intval($wiersz['ilosc_zarezerwowanych']) + intval($wiersz['ilosc_prywatnych'])
            );
        }

        echo '<div class="wrap"><h1>Kalendarz lotów tandemowych</h1>';

        $poprzedni_miesiac = mktime(0, 0, 0, $miesiac - 1, 1, $rok);
        $nastepny_miesiac  = mktime(0, 0, 0, $miesiac + 1, 1, $rok);
        echo '<div style="margin-bottom:15px;">';
        echo '<a class="button" href="' . esc_url(add_query_arg(array(
            'miesiac' => date('n', $poprzedni_miesiac),
            'rok'     => date('Y', $poprzedni_miesiac)
        ))) . '">&laquo; Poprzedni miesiąc</a> ';
        echo '<span style="margin:0 10px;"><strong>' . date('F Y', $pierwszy_dzien_miesiaca) . '</strong></span>';
        echo '<a class="button" href="' . esc_url(add_query_arg(array(
            'miesiac' => date('n', $nastepny_miesiac),
            'rok'     => date('Y', $nastepny_miesiac)
        ))) . '">Następny miesiąc &raquo;</a>';
        echo '</div>';

        echo '<div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;">';
        echo '<h3 style="margin-top: 0; margin-bottom: 10px;">Legenda kolorów:</h3>';
        echo '<div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px;">';
        echo '<div style="display: flex; align-items: center; gap: 8px;"><div style="width: 20px; height: 20px; background: #dfd; border: 1px solid #ccc; border-radius: 3px;"></div><span>Dni z wolnymi slotami</span></div>';
        echo '<div style="display: flex; align-items: center; gap: 8px;"><div style="width: 20px; height: 20px; background: #ffd; border: 1px solid #ccc; border-radius: 3px;"></div><span>Dni pełne (brak wolnych slotów)</span></div>';
        echo '<div style="display: flex; align-items: center; gap: 8px;"><div style="width: 20px; height: 20px; background: #fdd; border: 1px solid #ccc; border-radius: 3px;"></div><span>Dni nielotne (brak slotów)</span></div>';
        echo '<div style="display: flex; align-items: center; gap: 8px;"><div style="width: 20px; height: 20px; background: white; border: 3px solid #0073aa; border-radius: 3px;"></div><span>Dzisiejszy dzień</span></div>';
        echo '</div>';
        echo '</div>';

        echo '<table class="widefat fixed" style="text-align: center; border-collapse: collapse;">';
        echo '<thead><tr>';

        $dni_tygodnia = array('Pon', 'Wt', 'Śr', 'Czw', 'Pt', 'Sob', 'Nd');
        foreach ($dni_tygodnia as $dzien) {
            echo '<th style="padding:10px; border:1px solid #ddd;">' . $dzien . '</th>';
        }
        echo '</tr></thead>';
        echo '<tbody>';

        $dzień_licznik = 1;
        $wypełnienie_puste = $numer_pierwszego_dnia - 1; 
        $liczba_wierszy = ceil(($dni_w_miesiacu + $wypełnienie_puste) / 7);

        for ($wiersz = 0; $wiersz < $liczba_wierszy; $wiersz++) {
            echo '<tr>';
            for ($kol = 1; $kol <= 7; $kol++) {
                $komorka_index = $wiersz * 7 + $kol;
                if ($komorka_index <= $wypełnienie_puste || $dzień_licznik > $dni_w_miesiacu) {

                    echo '<td style="padding:20px; border:1px solid #ddd; background:#f9f9f9;"></td>';
                } else {
                    $data_porownaj = sprintf('%04d-%02d-%02d', $rok, $miesiac, $dzień_licznik);

                    $dane_dnia = isset($dane_miesiac[$data_porownaj]) ? $dane_miesiac[$data_porownaj] : array(
                        'wszystkie' => 0,
                        'wolne' => 0,
                        'zarezerwowane' => 0,
                        'prywatne' => 0,
                        'zrealizowane' => 0,
                        'odwolane' => 0,
                        'zarezerwowane_razem' => 0
                    );

                    if ($dane_dnia['wszystkie'] == 0) {
                        $kolor_tla = '#fdd'; 
                    } elseif ($dane_dnia['wolne'] <= 0) {
                        $kolor_tla = '#ffd'; 
                    } else {
                        $kolor_tla = '#dfd'; 
                    }

                    $dzisiejsza_data = date('Y-m-d');
                    $jest_dzisiaj = ($data_porownaj === $dzisiejsza_data);

                    $ramka_dzisiaj = '';
                    if ($jest_dzisiaj) {
                        if ($dane_dnia['wszystkie'] == 0) {
                            $ramka_dzisiaj = 'border: 3px solid #c55; box-shadow: 0 0 8px rgba(204, 85, 85, 0.3);';
                        } elseif ($dane_dnia['wolne'] <= 0) {
                            $ramka_dzisiaj = 'border: 3px solid #cc8; box-shadow: 0 0 8px rgba(204, 204, 136, 0.3);';
                        } else {
                            $ramka_dzisiaj = 'border: 3px solid #8c8; box-shadow: 0 0 8px rgba(136, 204, 136, 0.3);';
                        }
                    }

                    $url_dzien = add_query_arg(array(
                        'page' => 'srl-dzien',
                        'data' => $data_porownaj
                    ), admin_url('admin.php'));

                    echo '<td style="vertical-align: top; padding:8px; border:1px solid #ddd; background:' . $kolor_tla . '; position: relative; ' . $ramka_dzisiaj . ' cursor: pointer; height: 100px;" onclick="window.location.href=\'' . esc_url($url_dzien) . '\'" onmouseover="this.style.opacity=\'0.8\'" onmouseout="this.style.opacity=\'1\'" title="Kliknij aby przejść do planowania dnia ' . $dzień_licznik . '">';

                    if ($jest_dzisiaj) {
                        echo '<div style="position: absolute; top: 2px; right: 2px; background: #0073aa; color: white; font-size: 9px; padding: 2px 4px; border-radius: 3px; font-weight: bold; pointer-events: none;">DZIŚ</div>';
                    }

                    echo '<div style="font-weight:bold; margin-bottom: 5px; pointer-events: none;">' . $dzień_licznik . '</div>';
                    echo '<div style="font-size:10px; line-height: 1.3; pointer-events: none;">';
                    echo '<strong>Wszystkie:</strong> ' . $dane_dnia['wszystkie'] . '<br>';

                    if ($dane_dnia['wolne'] > 0) {
                        echo '<strong>Wolne:</strong> ' . $dane_dnia['wolne'] . '<br>';
                    }

					if ($dane_dnia['zarezerwowane_razem'] > 0) {
						echo '<strong>Zarezerwowane:</strong> ' . $dane_dnia['zarezerwowane_razem'] . '<br>';
						if ($dane_dnia['zarezerwowane'] > 0 && $dane_dnia['prywatne'] > 0) {
							echo '<span style="font-size: 9px; color: #666;">';
							echo '(zwykłe: ' . $dane_dnia['zarezerwowane'] . ', prywatne: ' . $dane_dnia['prywatne'] . ')';
							echo '</span><br>';
						}
						elseif ($dane_dnia['prywatne'] > 0 && $dane_dnia['zarezerwowane'] == 0) {
							echo '<span style="font-size: 9px; color: #666;">';
							echo '(prywatne: ' . $dane_dnia['prywatne'] . ')';
							echo '</span><br>';
						}
					}

                    if ($dane_dnia['zrealizowane'] > 0) {
						echo '<strong>Zrealizowane:</strong> ' . $dane_dnia['zrealizowane'] . '<br>';
					}

					if ($dane_dnia['odwolane'] > 0) {
						echo '<strong>Odwołane:</strong> ' . $dane_dnia['odwolane'] . '<br>';
					}

                    echo '</div>';
                    echo '</td>';

                    $dzień_licznik++;
                }
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}