<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Bootstrap {
    
    private static $instance = null;
    private $initialized = false;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
    }

    public function init() {
        if ($this->initialized) {
            return;
        }

        $this->loadClasses();
        $this->initializeClasses();
        $this->initHooks();
        
        $this->initialized = true;
    }

    private function loadClasses() {
        require_once SRL_PLUGIN_DIR . 'includes/database/class-srl-database-setup.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-helpers.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-database-helpers.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-email-functions.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-historia-functions.php';

        require_once SRL_PLUGIN_DIR . 'includes/ajax/class-srl-admin-ajax.php';
        require_once SRL_PLUGIN_DIR . 'includes/ajax/class-srl-frontend-ajax.php';
        require_once SRL_PLUGIN_DIR . 'includes/ajax/class-srl-voucher-ajax.php';
        require_once SRL_PLUGIN_DIR . 'includes/ajax/class-srl-flight-options-ajax.php';

        require_once SRL_PLUGIN_DIR . 'includes/frontend/class-srl-frontend-shortcodes.php';

        require_once SRL_PLUGIN_DIR . 'includes/class-srl-partner-voucher-functions.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-voucher-functions.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-voucher-gift-functions.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-flight-options.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-cart-flight-options.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-product-flight-options.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-client-account.php';
        require_once SRL_PLUGIN_DIR . 'includes/class-srl-woocommerce-integration.php';

        require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-menu.php';
        require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-calendar.php';
        require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-day.php';
        require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-flights.php';
        require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-vouchers.php';
        require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-voucher.php';
    }

    private function initializeClasses() {
        SRL_Database_Setup::getInstance();
        SRL_Helpers::getInstance();
        SRL_Database_Helpers::getInstance();
        SRL_Email_Functions::getInstance();
        SRL_Historia_Functions::getInstance();

        SRL_Admin_Ajax::getInstance();
        SRL_Frontend_Ajax::getInstance();
        SRL_Voucher_Ajax::getInstance();
        SRL_Flight_Options_Ajax::getInstance();

        SRL_Frontend_Shortcodes::getInstance();

        SRL_Partner_Voucher_Functions::getInstance();
        SRL_Voucher_Functions::getInstance();
        SRL_Voucher_Gift_Functions::getInstance();
        SRL_Flight_Options::getInstance();
        SRL_Cart_Flight_Options::getInstance();
        SRL_Product_Flight_Options::getInstance();
        SRL_Client_Account::getInstance();
        SRL_WooCommerce_Integration::getInstance();

        SRL_Admin_Menu::getInstance();
        SRL_Admin_Calendar::getInstance();
        SRL_Admin_Day::getInstance();
        SRL_Admin_Flights::getInstance();
        SRL_Admin_Vouchers::getInstance();
        SRL_Admin_Voucher::getInstance();
    }

    private function initHooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueueScripts'));
        add_action('wp', array($this, 'scheduleDailyCheck'));
        add_action('srl_sprawdz_przeterminowane_loty', array($this, 'checkExpiredFlights'));
    }

    public function enqueueScripts() {
        if (is_user_logged_in()) {
            wp_enqueue_script('srl-flight-options', SRL_PLUGIN_URL . 'assets/js/flight-options-unified.js', array('jquery'), '1.0', true);
            wp_localize_script('srl-flight-options', 'srlFrontend', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('srl_frontend_nonce'),
                'productIds' => SRL_Helpers::getInstance()->getFlightOptionProductIds()
            ));
        }
    }

    public function scheduleDailyCheck() {
        if (!wp_next_scheduled('srl_sprawdz_przeterminowane_loty')) {
            wp_schedule_event(time(), 'daily', 'srl_sprawdz_przeterminowane_loty');
        }
    }

    public function checkExpiredFlights() {
        SRL_WooCommerce_Integration::getInstance()->oznaczPrzeterminowaneLoty();
    }
}

if (!function_exists('srl_dopisz_do_historii_lotu')) {
    function srl_dopisz_do_historii_lotu($lot_id, $wpis) {
        return SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis);
    }
}

if (!function_exists('srl_pobierz_historie_lotu')) {
    function srl_pobierz_historie_lotu($lot_id) {
        return SRL_Historia_Functions::getInstance()->pobierzHistorieLotu($lot_id);
    }
}

if (!function_exists('srl_get_current_datetime')) {
    function srl_get_current_datetime() {
        return SRL_Helpers::getInstance()->getCurrentDatetime();
    }
}

if (!function_exists('srl_get_user_full_data')) {
    function srl_get_user_full_data($user_id) {
        return SRL_Helpers::getInstance()->getUserFullData($user_id);
    }
}