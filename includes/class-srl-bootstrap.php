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
		
		$this->scheduleCacheCleanup();
    }

    private function loadClasses() {
		require_once SRL_PLUGIN_DIR . 'includes/class-srl-cache-manager.php';
		require_once SRL_PLUGIN_DIR . 'includes/class-srl-asset-optimizer.php';
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

		require_once SRL_PLUGIN_DIR . 'includes/class-srl-slot-manager.php';
		require_once SRL_PLUGIN_DIR . 'includes/class-srl-admin-tables.php';
		require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-menu-calendar.php';
		require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-vouchers.php'; 
		require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-flights.php';
		require_once SRL_PLUGIN_DIR . 'includes/admin/class-srl-admin-day.php';
    }

    private function initializeClasses() {
		SRL_Cache_Manager::getInstance(); 
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

		SRL_Slot_Manager::getInstance();
		SRL_Admin_Tables::getInstance();
		SRL_Admin_Menu_Calendar::getInstance(); 
		SRL_Admin_Vouchers::getInstance();
		SRL_Admin_Flights::getInstance();
		SRL_Admin_Day::getInstance();
    }

    private function initHooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueueScripts'));
        add_action('wp', array($this, 'scheduleDailyCheck'));
        add_action('srl_sprawdz_przeterminowane_loty', array($this, 'checkExpiredFlights'));
		add_action('wp_footer', array($this, 'cleanupMemory'), 99);
		add_action('admin_footer', array($this, 'cleanupMemory'), 99);
		add_action('wp_scheduled_delete', array($this, 'scheduledCacheCleanup'));
		add_action('srl_cache_cleanup', array($this, 'cacheCleanup'));
		if (defined('WP_DEBUG') && WP_DEBUG) {
			add_action('admin_bar_menu', array($this, 'addDebugInfo'), 999);
		}
    }

	public function enqueueScripts() {
		if (wp_doing_ajax() || is_admin()) {
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
		
		$optimizer = SRL_Asset_Optimizer::getInstance();
		$use_minified = !defined('SCRIPT_DEBUG') || !SCRIPT_DEBUG;
		
		$css_url = $use_minified ? $optimizer->getMinifiedAsset('assets/css/frontend-style.css', 'css') : SRL_PLUGIN_URL . 'assets/css/frontend-style.css';
		wp_enqueue_style('srl-frontend-style', $css_url, [], $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/css/frontend-style.css'));
		
		$script_handle = null;
		if ($load_calendar && $load_flight_options && $use_minified) {
			$combined_js = $optimizer->getCombinedJS(['assets/js/frontend-calendar.js', 'assets/js/flight-options-unified.js']);
			wp_enqueue_script('srl-combined', $combined_js, ['jquery'], '1.0', true);
			$script_handle = 'srl-combined';
		} else {
			if ($load_flight_options) {
				$js_url = $use_minified ? $optimizer->getMinifiedAsset('assets/js/flight-options-unified.js', 'js') : SRL_PLUGIN_URL . 'assets/js/flight-options-unified.js';
				wp_enqueue_script('srl-flight-options', $js_url, ['jquery'], $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/js/flight-options-unified.js'), true);
				$script_handle = 'srl-flight-options';
			}
			
			if ($load_calendar) {
				$cal_js_url = $use_minified ? $optimizer->getMinifiedAsset('assets/js/frontend-calendar.js', 'js') : SRL_PLUGIN_URL . 'assets/js/frontend-calendar.js';
				wp_enqueue_script('srl-frontend-calendar', $cal_js_url, ['jquery'], $use_minified ? '1.0' : filemtime(SRL_PLUGIN_DIR . 'assets/js/frontend-calendar.js'), true);
				if (!$script_handle) $script_handle = 'srl-frontend-calendar';
			}
		}
		
		// KRYTYCZNA POPRAWKA - zawsze lokalizuj jeśli ładujesz jakikolwiek skrypt
		if ($script_handle) {
			wp_localize_script($script_handle, 'srlFrontend', [
				'ajaxurl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('srl_frontend_nonce'),
				'productIds' => SRL_Helpers::getInstance()->getFlightOptionProductIds(),
				'debug' => defined('WP_DEBUG') && WP_DEBUG
			]);
		}
	}
	
	private function isFlightOptionProduct() {
		if (!is_product()) return false;
		
		$product = wc_get_product();
		if (!$product) return false;
		
		$opcje_produkty = SRL_Helpers::getInstance()->getFlightOptionProductIds();
		return in_array($product->get_id(), $opcje_produkty);
	}
	

    public function scheduleDailyCheck() {
        if (!wp_next_scheduled('srl_sprawdz_przeterminowane_loty')) {
            wp_schedule_event(time(), 'daily', 'srl_sprawdz_przeterminowane_loty');
        }
    }

    public function checkExpiredFlights() {
        SRL_WooCommerce_Integration::getInstance()->oznaczPrzeterminowaneLoty();
    }
	
	public function cleanupMemory() {
		if (!is_admin() && !wp_doing_ajax()) {
			SRL_Cache_Manager::getInstance()->optimizeMemoryUsage();
		}
	}

	public function scheduledCacheCleanup() {
		$cleaned = SRL_Cache_Manager::getInstance()->cleanupExpiredCache();
		if ($cleaned > 0) {
			error_log("SRL: Cleaned {$cleaned} expired cache entries");
		}
	}

	public function cacheCleanup() {
		SRL_Cache_Manager::getInstance()->optimizeMemoryUsage();
	}

	public function scheduleCacheCleanup() {
		if (!wp_next_scheduled('srl_cache_cleanup')) {
			wp_schedule_event(time(), 'hourly', 'srl_cache_cleanup');
		}
	}
	
	public function addDebugInfo($wp_admin_bar) {
		if (!current_user_can('manage_woocommerce')) return;
		
		$cache_stats = SRL_Cache_Manager::getInstance()->getCacheStats();
		$memory_mb = round($cache_stats['memory_usage'] / 1024 / 1024, 2);
		
		$wp_admin_bar->add_node(array(
			'id' => 'srl-debug',
			'title' => "SRL: {$memory_mb}MB",
			'href' => admin_url('admin.php?page=srl-debug')
		));
	}
	
}

