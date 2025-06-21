<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Flight_Options {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->initHooks();
    }

    private function initHooks() {
    }

    public function analizaOpcjiProduktu($nazwa_produktu) {
        return $this->detectFlightOptions($nazwa_produktu);
    }

    public function przedluzWaznoscLotu($lot_id, $order_id) {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_zakupione_loty';

        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT data_waznosci, nazwa_produktu FROM $tabela WHERE id = %d",
            $lot_id
        ), ARRAY_A);

        if (!$lot) return false;

        $stara_data = $lot['data_waznosci'];
        $nowa_data = SRL_Helpers::getInstance()->generateExpiryDate($stara_data, 1);

        $result = $wpdb->update(
            $tabela,
            array('data_waznosci' => $nowa_data),
            array('id' => $lot_id),
            array('%s'),
            array('%d')
        );

        return $result !== false;
    }

    public function lotMaOpcje($lot_id, $opcja) {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_zakupione_loty';

        $dozwolone_opcje = array('ma_filmowanie', 'ma_akrobacje');
        if (!in_array($opcja, $dozwolone_opcje)) {
            return false;
        }

        $wartosc = $wpdb->get_var($wpdb->prepare(
            "SELECT $opcja FROM $tabela WHERE id = %d",
            $lot_id
        ));

        return (bool) $wartosc;
    }

    public function dostepneOpcjeDoDokupienia($lot_id) {
        $opcje = array();

        if (!$this->lotMaOpcje($lot_id, 'ma_filmowanie')) {
            $opcje['filmowanie'] = array(
                'nazwa' => 'Filmowanie lotu',
                'product_id' => SRL_Helpers::getInstance()->getFlightOptionProductIds()['filmowanie']
            );
        }

        if (!$this->lotMaOpcje($lot_id, 'ma_akrobacje')) {
            $opcje['akrobacje'] = array(
                'nazwa' => 'Akrobacje podczas lotu',
                'product_id' => SRL_Helpers::getInstance()->getFlightOptionProductIds()['akrobacje']
            );
        }

        return $opcje;
    }

    private function detectFlightOptions($text) {
        $text_lower = strtolower($text);
        return array(
            'ma_filmowanie' => (strpos($text_lower, 'filmowani') !== false || strpos($text_lower, 'film') !== false ||
                               strpos($text_lower, 'video') !== false || strpos($text_lower, 'kamer') !== false) ? 1 : 0,
            'ma_akrobacje' => (strpos($text_lower, 'akrobacj') !== false || strpos($text_lower, 'trick') !== false ||
                              strpos($text_lower, 'spiral') !== false || strpos($text_lower, 'figur') !== false) ? 1 : 0
        );
    }
}