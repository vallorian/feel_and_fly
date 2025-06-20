<?php
/**
 * Klasa konfiguracyjna SRL - definiuje stałe i ID produktów
 * Plik: includes/validation/SRL_Config.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Config {
    
    /**
     * ID produktów lotów tandemowych
     */
    private static $flight_product_ids = array(62, 63, 65, 67, 69, 73, 74, 75, 76, 77);
    
    /**
     * ID produktów opcji lotu
     */
    private static $flight_option_product_ids = array(
        'przedluzenie' => 115,
        'filmowanie' => 116, 
        'akrobacje' => 117
    );
    
    /**
     * ID produktów voucherów upominkowych
     */
    private static $voucher_product_ids = array(105, 106, 107, 108);
    
    /**
     * Konfiguracja partnerów voucherów
     */
    private static $partner_voucher_config = array(
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
    
    /**
     * Opcje sprawności fizycznej
     */
    private static $fitness_options = array(
        'zdolnosc_do_marszu' => 'Zdolność do marszu',
        'zdolnosc_do_biegu' => 'Zdolność do biegu', 
        'sprinter' => 'Sprinter!'
    );
    
    /**
     * Kategorie wagowe
     */
    private static $weight_categories = array(
        '25-40kg' => '25-40kg',
        '41-60kg' => '41-60kg',
        '61-90kg' => '61-90kg', 
        '91-120kg' => '91-120kg',
        '120kg+' => '120kg+'
    );
    
    /**
     * Zwraca ID produktów opcji lotu
     */
    public static function get_flight_option_product_ids() {
        return self::$flight_option_product_ids;
    }
    
    /**
     * Zwraca ID produktów lotów
     */
    public static function get_flight_product_ids() {
        return self::$flight_product_ids;
    }
    
    /**
     * Zwraca ID produktów voucherów
     */
    public static function get_voucher_product_ids() {
        return self::$voucher_product_ids;
    }
    
    /**
     * Zwraca konfigurację partnerów voucherów
     */
    public static function get_partner_voucher_config() {
        return self::$partner_voucher_config;
    }
    
    /**
     * Zwraca opcje sprawności fizycznej
     */
    public static function get_fitness_options() {
        return self::$fitness_options;
    }
    
    /**
     * Zwraca kategorie wagowe
     */
    public static function get_weight_categories() {
        return self::$weight_categories;
    }
    
    /**
     * Sprawdza czy produkt jest lotem tandemowym
     */
    public static function is_flight_product($product_id) {
        return in_array(intval($product_id), self::$flight_product_ids);
    }
    
    /**
     * Sprawdza czy produkt jest opcją lotu
     */
    public static function is_flight_option_product($product_id) {
        return in_array(intval($product_id), array_values(self::$flight_option_product_ids));
    }
    
    /**
     * Sprawdza czy produkt jest voucherem
     */
    public static function is_voucher_product($product_id) {
        return in_array(intval($product_id), self::$voucher_product_ids);
    }
    
    /**
     * Zwraca nazwę opcji na podstawie ID produktu
     */
    public static function get_option_name_by_product_id($product_id) {
        $product_id = intval($product_id);
        
        foreach (self::$flight_option_product_ids as $option => $id) {
            if ($id === $product_id) {
                return $option;
            }
        }
        
        return null;
    }
    
    /**
     * Zwraca aktualny czas w formacie MySQL
     */
    public static function get_current_datetime() {
        return current_time('Y-m-d H:i:s');
    }
    
    /**
     * Sprawdza uprawnienia administratora
     */
    public static function check_admin_permissions() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }
    }
    
    /**
     * Wymaga zalogowania użytkownika
     */
    public static function require_login($return_data = false) {
        if (!is_user_logged_in()) {
            if ($return_data) {
                return array('success' => false, 'data' => 'Musisz być zalogowany.');
            }
            wp_send_json_error('Musisz być zalogowany.');
        }
        return true;
    }
}

// Funkcje kompatybilności wstecznej
function srl_get_flight_option_product_ids() {
    return SRL_Config::get_flight_option_product_ids();
}

function srl_get_flight_product_ids() {
    return SRL_Config::get_flight_product_ids();
}

function srl_get_current_datetime() {
    return SRL_Config::get_current_datetime();
}

function srl_check_admin_permissions() {
    return SRL_Config::check_admin_permissions();
}

function srl_require_login($return_data = false) {
    return SRL_Config::require_login($return_data);
}

