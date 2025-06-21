<?php

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Admin_Menu {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'dodajStronyAdmin'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueueFrontendScripts'));
    }

    public function dodajStronyAdmin() {
        add_menu_page(
            'Kalendarz lotów', 'Rezerwacje Tandem', 'manage_options',
            'srl-kalendarz', array('SRL_Admin_Calendar', 'wyswietlKalendarz'), 'dashicons-calendar', 56
        );

        $submenu_pages = [
            ['Planowanie dni', 'srl-dzien', array('SRL_Admin_Day', 'wyswietlDzien')],
            ['Wykupione loty', 'srl-wykupione-loty', array('SRL_Admin_Flights', 'wyswietlWykupioneLoty')],
            ['Vouchery', 'srl-voucher', array('SRL_Admin_Vouchers', 'wyswietlVouchery')]
        ];

        foreach ($submenu_pages as [$title, $slug, $function]) {
            add_submenu_page('srl-kalendarz', $title, $title, 'manage_options', $slug, $function);
        }
    }

    public function enqueueAdminScripts($hook) {
        $admin_pages = [
            'srl-kalendarz' => ['admin-calendar.js', 'admin-calendar'],
            'srl-dzien' => ['admin-day.js', 'admin-day'],
            'srl-wykupione-loty' => [null, 'admin-style'],
            'srl-voucher' => [null, 'admin-style']
        ];

        $current_page = $_GET['page'] ?? '';
        if (!isset($admin_pages[$current_page])) return;

        [$js_file, $handle] = $admin_pages[$current_page];
        
        wp_enqueue_style('srl-admin-style', SRL_PLUGIN_URL . 'assets/css/style.css');
        
        if ($js_file) {
            wp_enqueue_script("srl-{$handle}", SRL_PLUGIN_URL . "assets/js/{$js_file}", ['jquery'], '1.1', true);
            
            if ($current_page === 'srl-dzien') {
                $this->prepareDayData($handle);
            }
        }

        if ($current_page !== 'srl-dzien') {
            wp_localize_script('srl-admin-day', 'srlAdmin', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'adminUrl' => admin_url(),
                'nonce' => wp_create_nonce('srl_admin_nonce'),
                'data' => date('Y-m-d'),
                'istniejaceGodziny' => [],
                'domyslnaLiczbaPilotow' => 1
            ]);
        }
    }

    private function prepareDayData($handle) {
        $data = (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'])) 
                ? sanitize_text_field($_GET['data']) 
                : date('Y-m-d');

        $godziny_wg_pilota = SRL_Database_Helpers::getInstance()->getDayScheduleOptimized($data);
        $domyslna_liczba = $this->calculateDefaultPilots($godziny_wg_pilota);

        wp_localize_script("srl-{$handle}", 'srlAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'adminUrl' => admin_url(),
            'nonce' => wp_create_nonce('srl_admin_nonce'),
            'data' => $data,
            'istniejaceGodziny' => $godziny_wg_pilota,
            'domyslnaLiczbaPilotow' => $domyslna_liczba
        ]);
    }

    private function calculateDefaultPilots($godziny_wg_pilota) {
        if (empty($godziny_wg_pilota)) return 1;
        $max_pid = max(array_keys($godziny_wg_pilota));
        return max(1, min(4, $max_pid));
    }

    public function enqueueFrontendScripts() {
        $should_load = is_page('rezerwuj-lot') || 
                       (is_a($GLOBALS['post'], 'WP_Post') && has_shortcode($GLOBALS['post']->post_content, 'srl_kalendarz'));

        if ($should_load) {
            wp_enqueue_script('srl-frontend-calendar', SRL_PLUGIN_URL . 'assets/js/frontend-calendar.js', ['jquery'], '1.0', true);
            wp_enqueue_style('srl-frontend-style', SRL_PLUGIN_URL . 'assets/css/frontend-style.css', [], '1.0');

            wp_localize_script('srl-frontend-calendar', 'srlFrontend', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('srl_frontend_nonce'),
                'user_id' => get_current_user_id(),
                'productIds' => SRL_Helpers::getInstance()->getFlightOptionProductIds()
            ]);
        }

        if (function_exists('is_wc_endpoint_url') && is_account_page()) {
            wp_enqueue_script('srl-flight-options', SRL_PLUGIN_URL . 'assets/js/flight-options-unified.js', ['jquery'], '1.0', true);
            wp_localize_script('srl-flight-options', 'srlFrontend', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('srl_frontend_nonce'),
                'productIds' => SRL_Helpers::getInstance()->getFlightOptionProductIds()
            ]);
        }
    }
}