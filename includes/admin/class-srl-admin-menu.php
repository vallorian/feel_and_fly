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
        $current_page = $_GET['page'] ?? '';
        if (!$this->shouldLoadAdminAssets($current_page)) return;

        $optimizer = SRL_Asset_Optimizer::getInstance();
        $use_minified = !defined('SCRIPT_DEBUG') || !SCRIPT_DEBUG;
        
        if ($use_minified) {
            $css_url = $optimizer->getMinifiedAsset('assets/css/style.css', 'css');
        } else {
            $css_url = SRL_PLUGIN_URL . 'assets/css/style.css';
        }
        
        wp_enqueue_style('srl-admin-style', $css_url, array(), 
            $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/css/style.css'));
        
        $script_config = $this->getScriptConfig($current_page);
        if ($script_config) {
            $this->enqueueAdminScript($script_config, $use_minified, $optimizer);
        }
    }

    private function shouldLoadAdminAssets($current_page) {
        return in_array($current_page, ['srl-kalendarz', 'srl-dzien', 'srl-wykupione-loty', 'srl-voucher']);
    }

    private function getScriptConfig($current_page) {
        $configs = [
            'srl-kalendarz' => ['file' => 'admin-calendar.js', 'handle' => 'admin-calendar'],
            'srl-dzien' => ['file' => 'admin-day.js', 'handle' => 'admin-day']
        ];
        
        return $configs[$current_page] ?? null;
    }

    private function enqueueAdminScript($config, $use_minified, $optimizer) {
        if ($use_minified) {
            $js_url = $optimizer->getMinifiedAsset('assets/js/' . $config['file'], 'js');
        } else {
            $js_url = SRL_PLUGIN_URL . 'assets/js/' . $config['file'];
        }
        
        wp_enqueue_script("srl-{$config['handle']}", $js_url, ['jquery'], 
            $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/js/' . $config['file']), true);
        
        if ($config['handle'] === 'admin-day') {
            $this->prepareDayData("srl-{$config['handle']}");
        }
    }

    private function prepareDayData($handle) {
        $cache_key = 'admin_day_data_' . ($_GET['data'] ?? date('Y-m-d'));
        $cached_data = wp_cache_get($cache_key, 'srl_admin_cache');
        
        if ($cached_data === false) {
            $data = (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'])) 
                    ? sanitize_text_field($_GET['data']) 
                    : date('Y-m-d');

            $godziny_wg_pilota = SRL_Database_Helpers::getInstance()->getDayScheduleOptimized($data);
            $domyslna_liczba = $this->calculateDefaultPilots($godziny_wg_pilota);

            $cached_data = [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'adminUrl' => admin_url(),
                'nonce' => wp_create_nonce('srl_admin_nonce'),
                'data' => $data,
                'istniejaceGodziny' => $godziny_wg_pilota,
                'domyslnaLiczbaPilotow' => $domyslna_liczba
            ];
            
            wp_cache_set($cache_key, $cached_data, 'srl_admin_cache', 300);
        }

        wp_localize_script($handle, 'srlAdmin', $cached_data);
    }

    private function calculateDefaultPilots($godziny_wg_pilota) {
        if (empty($godziny_wg_pilota)) return 1;
        $max_pid = max(array_keys($godziny_wg_pilota));
        return max(1, min(4, $max_pid));
    }

    public function enqueueFrontendScripts() {
        if (wp_doing_ajax() || is_admin() || !is_user_logged_in()) {
            return;
        }
        
        global $post;
        $load_assets = false;
        $load_calendar = false;
        $load_flight_options = false;
        
        if (is_page() && $post && has_shortcode($post->post_content, 'srl_kalendarz')) {
            $load_calendar = true;
            $load_flight_options = true;
            $load_assets = true;
        }
        
        if (is_account_page() && (is_wc_endpoint_url('srl-moje-loty') || is_wc_endpoint_url('srl-informacje-o-mnie'))) {
            $load_flight_options = true;
            $load_assets = true;
        }
        
        if ($this->isFlightOptionProduct()) {
            $load_flight_options = true;
            $load_assets = true;
        }
        
        if (!$load_assets) {
            return;
        }
        
        $this->enqueueFrontendAssets($load_calendar, $load_flight_options);
    }

    private function enqueueFrontendAssets($load_calendar, $load_flight_options) {
        $optimizer = SRL_Asset_Optimizer::getInstance();
        $use_minified = !defined('SCRIPT_DEBUG') || !SCRIPT_DEBUG;
        
        if ($use_minified) {
            $css_url = $optimizer->getMinifiedAsset('assets/css/frontend-style.css', 'css');
        } else {
            $css_url = SRL_PLUGIN_URL . 'assets/css/frontend-style.css';
        }
        
        wp_enqueue_style('srl-frontend-style', $css_url, array(), 
            $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/css/frontend-style.css'));
        
        if ($load_calendar && $load_flight_options && $use_minified) {
            $combined_js = $optimizer->getCombinedJS(array(
                'assets/js/frontend-calendar.js',
                'assets/js/flight-options-unified.js'
            ));
            
            wp_enqueue_script('srl-combined', $combined_js, array('jquery'), '1.0', true);
            $script_handle = 'srl-combined';
        } else {
            $script_handle = $this->enqueueIndividualScripts($load_calendar, $load_flight_options, $use_minified, $optimizer);
        }
        
        wp_localize_script($script_handle, 'srlFrontend', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('srl_frontend_nonce'),
            'productIds' => SRL_Helpers::getInstance()->getFlightOptionProductIds(),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ));
    }

    private function enqueueIndividualScripts($load_calendar, $load_flight_options, $use_minified, $optimizer) {
        $script_handle = null;
        
        if ($load_flight_options) {
            $js_url = $use_minified ? 
                $optimizer->getMinifiedAsset('assets/js/flight-options-unified.js', 'js') :
                SRL_PLUGIN_URL . 'assets/js/flight-options-unified.js';
                
            wp_enqueue_script('srl-flight-options', $js_url, array('jquery'), 
                $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/js/flight-options-unified.js'), true);
            $script_handle = 'srl-flight-options';
        }
        
        if ($load_calendar) {
            $cal_js_url = $use_minified ? 
                $optimizer->getMinifiedAsset('assets/js/frontend-calendar.js', 'js') :
                SRL_PLUGIN_URL . 'assets/js/frontend-calendar.js';
                
            wp_enqueue_script('srl-frontend-calendar', $cal_js_url, array('jquery'), 
                $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/js/frontend-calendar.js'), true);
            $script_handle = 'srl-frontend-calendar';
        }
        
        return $script_handle;
    }

    private function isFlightOptionProduct() {
        if (!is_product()) return false;
        
        $product = wc_get_product();
        if (!$product) return false;
        
        $opcje_produkty = SRL_Helpers::getInstance()->getFlightOptionProductIds();
        return in_array($product->get_id(), $opcje_produkty);
    }
}