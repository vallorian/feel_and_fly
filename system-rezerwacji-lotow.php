<?php if (!defined('ABSPATH')) {
    exit;
}
define('SRL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SRL_PLUGIN_URL', plugin_dir_url(__FILE__));
require_once SRL_PLUGIN_DIR . 'includes/database/database-setup.php';
require_once SRL_PLUGIN_DIR . 'includes/database/database-queries.php';
require_once SRL_PLUGIN_DIR . 'includes/helpers/formatting-helpers.php';
require_once SRL_PLUGIN_DIR . 'includes/helpers/validation-helpers.php';
require_once SRL_PLUGIN_DIR . 'includes/helpers/email-helpers.php';
require_once SRL_PLUGIN_DIR . 'includes/helpers/historia-helpers.php';
require_once SRL_PLUGIN_DIR . 'includes/admin/admin-menu.php';
require_once SRL_PLUGIN_DIR . 'includes/admin/admin-calendar.php';
require_once SRL_PLUGIN_DIR . 'includes/admin/admin-day.php';
require_once SRL_PLUGIN_DIR . 'includes/admin/admin-voucher.php';
require_once SRL_PLUGIN_DIR . 'includes/admin/admin-vouchers.php';
require_once SRL_PLUGIN_DIR . 'includes/admin/admin-flights.php';
require_once SRL_PLUGIN_DIR . 'includes/frontend/frontend-shortcodes.php';
require_once SRL_PLUGIN_DIR . 'includes/voucher-functions.php';
require_once SRL_PLUGIN_DIR . 'includes/voucher-gift-functions.php';
require_once SRL_PLUGIN_DIR . 'includes/flight-options.php';
require_once SRL_PLUGIN_DIR . 'includes/cart-flight-options.php';
require_once SRL_PLUGIN_DIR . 'includes/product-flight-options.php';
require_once SRL_PLUGIN_DIR . 'includes/client-account.php';
require_once SRL_PLUGIN_DIR . 'includes/historia-lotow-helpers.php';
require_once SRL_PLUGIN_DIR . 'includes/woocommerce-integration.php';
require_once SRL_PLUGIN_DIR . 'includes/ajax/admin-ajax.php';
require_once SRL_PLUGIN_DIR . 'includes/ajax/frontend-ajax.php';
require_once SRL_PLUGIN_DIR . 'includes/ajax/voucher-ajax.php';
require_once SRL_PLUGIN_DIR . 'includes/ajax/flight-options-ajax.php';
register_activation_hook(__FILE__, 'srl_aktywacja_wtyczki');
register_deactivation_hook(__FILE__, 'srl_dezaktywacja_wtyczki');
function srl_dezaktywacja_wtyczki() {
    wp_clear_scheduled_hook('srl_sprawdz_przeterminowane_loty');
}
function srl_utworz_kategorie_produktow() {
    if (!term_exists('loty-tandemowe', 'product_cat')) {
        wp_insert_term('Loty tandemowe', 'product_cat', array('description' => 'Produkty lotów tandemowych', 'slug' => 'loty-tandemowe'));
    }
}
function srl_utworz_strone_rezerwacji() {
    $strona_istnieje = get_page_by_path('rezerwuj-lot');
    if (!$strona_istnieje) {
        $strona_id = wp_insert_post(array('post_title' => 'Rezerwuj lot', 'post_content' => '[srl_kalendarz]', 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'rezerwuj-lot'));
        update_option('srl_strona_rezerwacji_id', $strona_id);
    }
}
add_action('wp_enqueue_scripts', 'srl_enqueue_flight_options_globally');
function srl_enqueue_flight_options_globally() {
    if (is_user_logged_in()) {
        wp_enqueue_script('srl-flight-options', SRL_PLUGIN_URL . 'assets/js/flight-options-unified.js', array('jquery'), '1.0', true);
        wp_localize_script('srl-flight-options', 'srlFrontend', array('ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('srl_frontend_nonce'), 'productIds' => srl_get_flight_option_product_ids()));
    }
}
