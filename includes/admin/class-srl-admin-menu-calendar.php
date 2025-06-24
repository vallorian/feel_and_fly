<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Admin_Menu_Calendar {
    private static $instance = null;
    private $slot_manager;
    private $tables;
    private $asset_optimizer;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->slot_manager = SRL_Slot_Manager::getInstance();
        $this->tables = SRL_Admin_Tables::getInstance();
        $this->asset_optimizer = SRL_Asset_Optimizer::getInstance();
        add_action('admin_menu', [$this, 'initMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);
    }

    public function initMenu() {
        add_menu_page('Kalendarz lotów', 'Rezerwacje Tandem', 'manage_options', 'srl-kalendarz', [$this, 'displayCalendar'], 'dashicons-calendar', 56);

        $submenu_pages = [
            ['Planowanie dni', 'srl-dzien', [SRL_Admin_Day::getInstance(), 'wyswietlDzien']],
            ['Wykupione loty', 'srl-wykupione-loty', [SRL_Admin_Flights::getInstance(), 'wyswietlWykupioneLoty']],
            ['Vouchery', 'srl-voucher', [SRL_Admin_Vouchers::getInstance(), 'wyswietlVouchery']]
        ];

        foreach ($submenu_pages as [$title, $slug, $function]) {
            add_submenu_page('srl-kalendarz', $title, $title, 'manage_options', $slug, $function);
        }
    }

	public function enqueueAssets($hook) {
		$admin_pages = [
			'srl-kalendarz' => ['admin-calendar.js', 'admin-calendar'],
			'srl-dzien' => ['admin-day.js', 'admin-day'],
			'srl-wykupione-loty' => [null, 'admin-style'],
			'srl-voucher' => [null, 'admin-style']
		];

		$current_page = $_GET['page'] ?? '';
		if (!isset($admin_pages[$current_page])) return;

		[$js_file, $handle] = $admin_pages[$current_page];
		
		$use_minified = !defined('SCRIPT_DEBUG') || !SCRIPT_DEBUG;
		$css_url = $use_minified ? $this->asset_optimizer->getMinifiedAsset('assets/css/style.css', 'css') : SRL_PLUGIN_URL . 'assets/css/style.css';
		
		wp_enqueue_style('srl-admin-style', $css_url, [], $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/css/style.css'));
		
		if ($js_file) {
			$js_url = $use_minified ? $this->asset_optimizer->getMinifiedAsset("assets/js/{$js_file}", 'js') : SRL_PLUGIN_URL . "assets/js/{$js_file}";
			wp_enqueue_script("srl-{$handle}", $js_url, ['jquery'], $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . "assets/js/{$js_file}"), true);
			
			if ($current_page === 'srl-dzien') {
				$this->prepareDayData($handle);
			}
		}

		$script_handle = $js_file ? "srl-{$handle}" : 'jquery';
		wp_localize_script($script_handle, 'srlAdmin', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'adminUrl' => admin_url(),
			'nonce' => wp_create_nonce('srl_admin_nonce'),
			'data' => $current_page === 'srl-dzien' ? (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data']) ? sanitize_text_field($_GET['data']) : date('Y-m-d')) : date('Y-m-d'),
			'istniejaceGodziny' => $current_page === 'srl-dzien' ? $this->getDayDataForJs() : [],
			'domyslnaLiczbaPilotow' => 1
		]);
	}

	private function getDayDataForJs() {
		$data = isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data']) ? sanitize_text_field($_GET['data']) : date('Y-m-d');
		return $this->slot_manager->getDayScheduleOptimized($data);
	}

    public function enqueueFrontendAssets() {
        if (!$this->shouldLoadFrontendAssets()) return;

        $optimizer = $this->asset_optimizer;
        $use_minified = !defined('SCRIPT_DEBUG') || !SCRIPT_DEBUG;
        
        if ($use_minified) {
            $css_url = $optimizer->getMinifiedAsset('assets/css/frontend-style.css', 'css');
        } else {
            $css_url = SRL_PLUGIN_URL . 'assets/css/frontend-style.css';
        }
        
        wp_enqueue_style('srl-frontend-style', $css_url, [], $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/css/frontend-style.css'));
        
        $load_calendar = $this->shouldLoadCalendar();
        $load_flight_options = $this->shouldLoadFlightOptions();
        
        if ($load_calendar && $load_flight_options && $use_minified) {
            $combined_js = $optimizer->getCombinedJS(['assets/js/frontend-calendar.js', 'assets/js/flight-options-unified.js']);
            wp_enqueue_script('srl-combined', $combined_js, ['jquery'], '1.0', true);
            $script_handle = 'srl-combined';
        } else {
            $script_handle = null;
            if ($load_flight_options) {
                $js_url = $use_minified ? $optimizer->getMinifiedAsset('assets/js/flight-options-unified.js', 'js') : SRL_PLUGIN_URL . 'assets/js/flight-options-unified.js';
                wp_enqueue_script('srl-flight-options', $js_url, ['jquery'], $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/js/flight-options-unified.js'), true);
                $script_handle = 'srl-flight-options';
            }
            
            if ($load_calendar) {
                $cal_js_url = $use_minified ? $optimizer->getMinifiedAsset('assets/js/frontend-calendar.js', 'js') : SRL_PLUGIN_URL . 'assets/js/frontend-calendar.js';
                wp_enqueue_script('srl-frontend-calendar', $cal_js_url, ['jquery'], $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/js/frontend-calendar.js'), true);
                $script_handle = $script_handle ?: 'srl-frontend-calendar';
            }
        }
        
        if ($script_handle) {
            wp_localize_script($script_handle, 'srlFrontend', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('srl_frontend_nonce'),
                'productIds' => SRL_Helpers::getInstance()->getFlightOptionProductIds()
            ]);
        }
    }

    public function displayCalendar() {
        if (!current_user_can('manage_options')) return;
        
        $rok = isset($_GET['rok']) && intval($_GET['rok']) > 2000 ? intval($_GET['rok']) : date('Y');
        $miesiac = isset($_GET['miesiac']) && intval($_GET['miesiac']) >= 1 && intval($_GET['miesiac']) <= 12 ? intval($_GET['miesiac']) : date('n');

        $stats = $this->slot_manager->getMonthStats($rok, $miesiac);

        echo '<div class="wrap"><h1>Kalendarz lotów tandemowych</h1>';

        $this->renderNavigationControls($rok, $miesiac);
        $this->renderLegend();
        echo $this->tables->renderCalendarTable($rok, $miesiac, $stats);
        echo '</div>';
    }

    private function prepareDayData($handle) {
        $data = (isset($_GET['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'])) ? sanitize_text_field($_GET['data']) : date('Y-m-d');

        $godziny_wg_pilota = $this->slot_manager->getDayScheduleOptimized($data);
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

    private function shouldLoadFrontendAssets() {
        return is_page('rezerwuj-lot') || 
               (is_a($GLOBALS['post'], 'WP_Post') && has_shortcode($GLOBALS['post']->post_content, 'srl_kalendarz')) ||
               (is_account_page() && (is_wc_endpoint_url('srl-moje-loty') || is_wc_endpoint_url('srl-informacje-o-mnie'))) ||
               $this->isFlightOptionProduct();
    }

    private function shouldLoadCalendar() {
        return is_page('rezerwuj-lot') || 
               (is_a($GLOBALS['post'], 'WP_Post') && has_shortcode($GLOBALS['post']->post_content, 'srl_kalendarz'));
    }

    private function shouldLoadFlightOptions() {
        return (is_account_page() && (is_wc_endpoint_url('srl-moje-loty') || is_wc_endpoint_url('srl-informacje-o-mnie'))) ||
               $this->isFlightOptionProduct() ||
               $this->shouldLoadCalendar();
    }

    private function isFlightOptionProduct() {
        if (!is_product()) return false;
        $product = wc_get_product();
        if (!$product) return false;
        $opcje_produkty = SRL_Helpers::getInstance()->getFlightOptionProductIds();
        return in_array($product->get_id(), $opcje_produkty);
    }

    private function renderNavigationControls($rok, $miesiac) {
        $poprzedni_miesiac = mktime(0, 0, 0, $miesiac - 1, 1, $rok);
        $nastepny_miesiac = mktime(0, 0, 0, $miesiac + 1, 1, $rok);
        $pierwszy_dzien_miesiaca = strtotime("$rok-$miesiac-01");
        
        echo '<div style="margin-bottom:15px;">';
        echo '<a class="button" href="' . esc_url(add_query_arg([
            'miesiac' => date('n', $poprzedni_miesiac),
            'rok' => date('Y', $poprzedni_miesiac)
        ])) . '">&laquo; Poprzedni miesiąc</a> ';
        echo '<span style="margin:0 10px;"><strong>' . date('F Y', $pierwszy_dzien_miesiaca) . '</strong></span>';
        echo '<a class="button" href="' . esc_url(add_query_arg([
            'miesiac' => date('n', $nastepny_miesiac),
            'rok' => date('Y', $nastepny_miesiac)
        ])) . '">Następny miesiąc &raquo;</a>';
        echo '</div>';
    }

    private function renderLegend() {
        echo '<div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;">';
        echo '<h3 style="margin-top: 0; margin-bottom: 10px;">Legenda kolorów:</h3>';
        echo '<div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px;">';
        echo '<div style="display: flex; align-items: center; gap: 8px;"><div style="width: 20px; height: 20px; background: #dfd; border: 1px solid #ccc; border-radius: 3px;"></div><span>Dni z wolnymi slotami</span></div>';
        echo '<div style="display: flex; align-items: center; gap: 8px;"><div style="width: 20px; height: 20px; background: #ffd; border: 1px solid #ccc; border-radius: 3px;"></div><span>Dni pełne (brak wolnych slotów)</span></div>';
        echo '<div style="display: flex; align-items: center; gap: 8px;"><div style="width: 20px; height: 20px; background: #fdd; border: 1px solid #ccc; border-radius: 3px;"></div><span>Dni nielotne (brak slotów)</span></div>';
        echo '<div style="display: flex; align-items: center; gap: 8px;"><div style="width: 20px; height: 20px; background: white; border: 3px solid #0073aa; border-radius: 3px;"></div><span>Dzisiejszy dzień</span></div>';
        echo '</div>';
        echo '</div>';
    }

    public function __destruct() {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('srl_cache');
        }
    }
}