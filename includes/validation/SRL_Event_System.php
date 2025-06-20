<?php
/**
 * Centralny system zdarzeń i hooks
 * Zastępuje rozproszone add_action/add_filter
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Event_System {
    
    private static $instance = null;
    private $listeners = array();
    private $event_log = array();
    
    // Predefiniowane zdarzenia systemu
    const EVENTS = array(
        // Zdarzenia lotów
        'flight_created' => 'srl_flight_created',
        'flight_reserved' => 'srl_flight_reserved', 
        'flight_cancelled' => 'srl_flight_cancelled',
        'flight_completed' => 'srl_flight_completed',
        'flight_expired' => 'srl_flight_expired',
        'flight_status_changed' => 'srl_flight_status_changed',
        
        // Zdarzenia slotów
        'slot_created' => 'srl_slot_created',
        'slot_status_changed' => 'srl_slot_status_changed',
        'slot_deleted' => 'srl_slot_deleted',
        
        // Zdarzenia voucherów
        'voucher_created' => 'srl_voucher_created',
        'voucher_used' => 'srl_voucher_used',
        'voucher_expired' => 'srl_voucher_expired',
        'partner_voucher_submitted' => 'srl_partner_voucher_submitted',
        'partner_voucher_approved' => 'srl_partner_voucher_approved',
        'partner_voucher_rejected' => 'srl_partner_voucher_rejected',
        
        // Zdarzenia użytkowników
        'user_data_updated' => 'srl_user_data_updated',
        'passenger_data_saved' => 'srl_passenger_data_saved',
        
        // Zdarzenia systemowe
        'cache_cleared' => 'srl_cache_cleared',
        'daily_cleanup' => 'srl_daily_cleanup',
        'email_sent' => 'srl_email_sent',
        'history_updated' => 'srl_history_updated'
    );
    
    private function __construct() {
        $this->init_wordpress_hooks();
        $this->init_woocommerce_hooks();
        $this->init_cron_hooks();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicjalizuje hooks WordPress
     */
    private function init_wordpress_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'handle_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'handle_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'handle_frontend_scripts'));
        
        // User hooks
        add_action('init', array($this, 'handle_init'));
        add_action('wp_loaded', array($this, 'handle_wp_loaded'));
        
        // AJAX hooks - grupowane według funkcjonalności
        $this->init_ajax_hooks();
    }

    /**
     * Inicjalizuje hooks WooCommerce
     */
    private function init_woocommerce_hooks() {
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
        add_action('woocommerce_single_product_summary', array($this, 'handle_flight_options_form'), 25);
        add_filter('woocommerce_add_cart_item_data', array($this, 'handle_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'handle_display_cart_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'handle_order_line_item'), 10, 4);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'handle_cart_validation'), 10, 3);
        add_action('before_delete_post', array($this, 'handle_order_deletion'));
        
        // Account page hooks
        add_filter('woocommerce_account_menu_items', array($this, 'handle_account_menu'));
        add_action('woocommerce_account_srl-moje-loty_endpoint', array($this, 'handle_my_flights_page'));
        add_action('woocommerce_account_srl-informacje-o-mnie_endpoint', array($this, 'handle_user_info_page'));
    }

    /**
     * Inicjalizuje hooks AJAX
     */
    private function init_ajax_hooks() {
        // Admin AJAX
        $admin_actions = array(
            'srl_dodaj_godzine', 'srl_zmien_slot', 'srl_usun_godzine', 'srl_zmien_status_godziny',
            'srl_anuluj_lot_przez_organizatora', 'srl_wyszukaj_klientow_loty', 'srl_dodaj_voucher_recznie',
            'srl_wyszukaj_dostepnych_klientow', 'srl_przypisz_klienta_do_slotu', 'srl_zapisz_dane_prywatne',
            'srl_pobierz_dane_prywatne', 'srl_pobierz_aktualne_godziny', 'srl_wyszukaj_wolne_loty',
            'srl_przypisz_wykupiony_lot', 'srl_zapisz_lot_prywatny', 'srl_pobierz_historie_lotu',
            'srl_przywroc_rezerwacje', 'srl_pobierz_dane_odwolanego', 'srl_zrealizuj_lot',
            'srl_zrealizuj_lot_prywatny', 'srl_pobierz_dostepne_terminy_do_zmiany', 'srl_zmien_termin_lotu',
            'srl_admin_zmien_status_lotu', 'srl_pobierz_szczegoly_lotu', 'srl_usun_lot'
        );
        
        foreach ($admin_actions as $action) {
            add_action("wp_ajax_{$action}", array($this, 'route_admin_ajax'));
        }
        
        // Frontend AJAX
        $frontend_actions = array(
            'srl_pobierz_dane_klienta', 'srl_zapisz_dane_pasazera', 'srl_pobierz_dostepne_dni',
            'srl_pobierz_dostepne_godziny', 'srl_dokonaj_rezerwacji', 'srl_anuluj_rezerwacje_klient',
            'srl_zablokuj_slot_tymczasowo', 'srl_waliduj_wiek', 'srl_waliduj_kategorie_wagowa',
            'srl_pobierz_dostepne_loty', 'srl_pobierz_dane_dnia'
        );
        
        foreach ($frontend_actions as $action) {
            add_action("wp_ajax_{$action}", array($this, 'route_frontend_ajax'));
            add_action("wp_ajax_nopriv_{$action}", array($this, 'route_frontend_ajax'));
        }
        
        // Voucher AJAX
        $voucher_actions = array(
            'srl_wykorzystaj_voucher', 'srl_get_partner_voucher_types', 'srl_submit_partner_voucher',
            'srl_get_partner_voucher_details', 'srl_approve_partner_voucher', 'srl_reject_partner_voucher',
            'srl_check_partner_voucher_exists'
        );
        
        foreach ($voucher_actions as $action) {
            add_action("wp_ajax_{$action}", array($this, 'route_voucher_ajax'));
            add_action("wp_ajax_nopriv_{$action}", array($this, 'route_voucher_ajax'));
        }
        
        // Flight options AJAX
        $options_actions = array(
            'srl_sprawdz_opcje_w_koszyku', 'srl_usun_opcje_z_koszyka', 'srl_sprawdz_i_dodaj_opcje',
            'woocommerce_add_to_cart'
        );
        
        foreach ($options_actions as $action) {
            add_action("wp_ajax_{$action}", array($this, 'route_flight_options_ajax'));
            add_action("wp_ajax_nopriv_{$action}", array($this, 'route_flight_options_ajax'));
        }
        
        // Auth AJAX
        add_action('wp_ajax_nopriv_srl_ajax_login', array($this, 'route_auth_ajax'));
        add_action('wp_ajax_nopriv_srl_ajax_register', array($this, 'route_auth_ajax'));
    }

    /**
     * Inicjalizuje hooks cron
     */
    private function init_cron_hooks() {
        add_action('wp', array($this, 'schedule_daily_tasks'));
        add_action('srl_sprawdz_przeterminowane_loty', array($this, 'handle_daily_cleanup'));
    }

    /**
     * Emituje zdarzenie w systemie
     */
    public function emit($event_name, $data = array()) {
        $hook_name = self::EVENTS[$event_name] ?? $event_name;
        
        // Loguj zdarzenie
        $this->log_event($event_name, $data);
        
        // Wywołaj WordPress action
        do_action($hook_name, $data);
        
        // Wywołaj wewnętrzne listenery
        $this->call_listeners($event_name, $data);
        
        return true;
    }

    /**
     * Dodaje listener do zdarzenia
     */
    public function listen($event_name, $callback, $priority = 10) {
        if (!isset($this->listeners[$event_name])) {
            $this->listeners[$event_name] = array();
        }
        
        $this->listeners[$event_name][] = array(
            'callback' => $callback,
            'priority' => $priority
        );
        
        // Sortuj według priorytetu
        usort($this->listeners[$event_name], function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
    }

    /**
     * Wywołuje wewnętrzne listenery
     */
    private function call_listeners($event_name, $data) {
        if (!isset($this->listeners[$event_name])) {
            return;
        }
        
        foreach ($this->listeners[$event_name] as $listener) {
            call_user_func($listener['callback'], $data, $event_name);
        }
    }

    /**
     * Loguje zdarzenie
     */
    private function log_event($event_name, $data) {
        $this->event_log[] = array(
            'event' => $event_name,
            'data' => $data,
            'timestamp' => current_time('mysql'),
            'memory' => memory_get_usage(),
            'user_id' => get_current_user_id()
        );
        
        // Ogranicz log do ostatnich 100 zdarzeń
        if (count($this->event_log) > 100) {
            $this->event_log = array_slice($this->event_log, -100);
        }
    }

    /**
     * Handler dla menu admin
     */
    public function handle_admin_menu() {
        require_once SRL_PLUGIN_DIR . 'includes/admin/admin-menu.php';
        srl_dodaj_strony_admin();
    }

    /**
     * Handler dla skryptów admin
     */
    public function handle_admin_scripts($hook) {
        require_once SRL_PLUGIN_DIR . 'includes/admin/admin-menu.php';
        srl_enqueue_admin_scripts($hook);
    }

    /**
     * Handler dla skryptów frontend
     */
    public function handle_frontend_scripts() {
        require_once SRL_PLUGIN_DIR . 'includes/admin/admin-menu.php';
        srl_enqueue_frontend_scripts();
    }

    /**
     * Handler dla init
     */
    public function handle_init() {
        require_once SRL_PLUGIN_DIR . 'includes/client-account.php';
        srl_dodaj_endpointy();
    }

    /**
     * Handler dla wp_loaded
     */
    public function handle_wp_loaded() {
        // Miejsce na dodatkową logikę po załadowaniu WP
    }

    /**
     * Routing AJAX admin
     */
    public function route_admin_ajax() {
        $action = $_REQUEST['action'] ?? '';
        
        // Security check
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Brak uprawnień.');
        }
        
        require_once SRL_PLUGIN_DIR . 'includes/ajax/admin-ajax.php';
        
        // Wywołaj odpowiednią funkcję
        if (function_exists($action)) {
            call_user_func($action);
        } else {
            wp_send_json_error('Nieznana akcja: ' . $action);
        }
    }

    /**
     * Routing AJAX frontend
     */
    public function route_frontend_ajax() {
        $action = $_REQUEST['action'] ?? '';
        
        require_once SRL_PLUGIN_DIR . 'includes/ajax/frontend-ajax.php';
        
        if (function_exists($action)) {
            call_user_func($action);
        } else {
            wp_send_json_error('Nieznana akcja: ' . $action);
        }
    }

    /**
     * Routing AJAX voucher
     */
    public function route_voucher_ajax() {
        $action = $_REQUEST['action'] ?? '';
        
        require_once SRL_PLUGIN_DIR . 'includes/ajax/voucher-ajax.php';
        
        if (function_exists($action)) {
            call_user_func($action);
        } else {
            wp_send_json_error('Nieznana akcja: ' . $action);
        }
    }

    /**
     * Routing AJAX flight options
     */
    public function route_flight_options_ajax() {
        $action = $_REQUEST['action'] ?? '';
        
        require_once SRL_PLUGIN_DIR . 'includes/ajax/flight-options-ajax.php';
        
        if (function_exists($action)) {
            call_user_func($action);
        } else {
            wp_send_json_error('Nieznana akcja: ' . $action);
        }
    }

    /**
     * Routing AJAX auth
     */
    public function route_auth_ajax() {
        $action = $_REQUEST['action'] ?? '';
        
        require_once SRL_PLUGIN_DIR . 'includes/ajax/frontend-ajax.php';
        
        if ($action === 'srl_ajax_login') {
            srl_ajax_login();
        } elseif ($action === 'srl_ajax_register') {
            srl_ajax_register();
        } else {
            wp_send_json_error('Nieznana akcja auth: ' . $action);
        }
    }

    /**
     * Handler dla zmiany statusu zamówienia
     */
    public function handle_order_status_change($order_id, $old_status, $new_status) {
        require_once SRL_PLUGIN_DIR . 'includes/woocommerce-integration.php';
        
        $this->emit('order_status_changed', array(
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status
        ));
        
        srl_order_status_changed($order_id, $old_status, $new_status);
    }

    /**
     * Handler dla formularza opcji lotu
     */
    public function handle_flight_options_form() {
        require_once SRL_PLUGIN_DIR . 'includes/product-flight-options.php';
        srl_wyswietl_formularz_wyboru_lotu();
    }

    /**
     * Handler dla danych koszyka
     */
    public function handle_cart_item_data($cart_item_data, $product_id, $variation_id) {
        require_once SRL_PLUGIN_DIR . 'includes/cart-flight-options.php';
        return srl_add_flight_option_cart_item_data($cart_item_data, $product_id, $variation_id);
    }

    /**
     * Handler dla wyświetlania danych koszyka
     */
    public function handle_display_cart_data($item_data, $cart_item) {
        require_once SRL_PLUGIN_DIR . 'includes/cart-flight-options.php';
        return srl_display_flight_option_cart_item_data($item_data, $cart_item);
    }

    /**
     * Handler dla linii zamówienia
     */
    public function handle_order_line_item($item, $cart_item_key, $values, $order) {
        require_once SRL_PLUGIN_DIR . 'includes/cart-flight-options.php';
        srl_save_flight_option_order_item_meta($item, $cart_item_key, $values, $order);
    }

    /**
     * Handler dla walidacji koszyka
     */
    public function handle_cart_validation($passed, $product_id, $quantity) {
        require_once SRL_PLUGIN_DIR . 'includes/cart-flight-options.php';
        return srl_validate_flight_option_add_to_cart($passed, $product_id, $quantity);
    }

    /**
     * Handler dla usuwania zamówienia
     */
    public function handle_order_deletion($post_id) {
        if (get_post_type($post_id) === 'shop_order') {
            require_once SRL_PLUGIN_DIR . 'includes/woocommerce-integration.php';
            
            $this->emit('order_deleted', array('order_id' => $post_id));
            srl_usun_loty_zamowienia($post_id);
        }
    }

    /**
     * Handler dla menu konta
     */
    public function handle_account_menu($items) {
        require_once SRL_PLUGIN_DIR . 'includes/client-account.php';
        return srl_dodaj_zakladki_klienta($items);
    }

    /**
     * Handler dla strony "Moje loty"
     */
    public function handle_my_flights_page() {
        require_once SRL_PLUGIN_DIR . 'includes/client-account.php';
        srl_moje_loty_tresc();
    }

    /**
     * Handler dla strony informacji o użytkowniku
     */
    public function handle_user_info_page() {
        require_once SRL_PLUGIN_DIR . 'includes/client-account.php';
        srl_informacje_o_mnie_tresc();
    }

    /**
     * Planuje dzienne zadania
     */
    public function schedule_daily_tasks() {
        require_once SRL_PLUGIN_DIR . 'includes/woocommerce-integration.php';
        srl_schedule_daily_check();
    }

    /**
     * Handler dla dziennego sprzątania
     */
    public function handle_daily_cleanup() {
        require_once SRL_PLUGIN_DIR . 'includes/woocommerce-integration.php';
        
        $this->emit('daily_cleanup', array('timestamp' => current_time('mysql')));
        
        srl_oznacz_przeterminowane_loty();
        
        if (function_exists('srl_oznacz_przeterminowane_vouchery')) {
            srl_oznacz_przeterminowane_vouchery();
        }
    }

    /**
     * Pobiera log zdarzeń (dla debugowania)
     */
    public function get_event_log($limit = 50) {
        return array_slice($this->event_log, -$limit);
    }

    /**
     * Czyści log zdarzeń
     */
    public function clear_event_log() {
        $this->event_log = array();
    }

    /**
     * Pobiera statystyki zdarzeń
     */
    public function get_event_stats() {
        $stats = array();
        
        foreach ($this->event_log as $event) {
            $name = $event['event'];
            if (!isset($stats[$name])) {
                $stats[$name] = 0;
            }
            $stats[$name]++;
        }
        
        return $stats;
    }
}

// Pomocnicze funkcje globalne
function srl_emit_event($event_name, $data = array()) {
    return SRL_Event_System::getInstance()->emit($event_name, $data);
}

function srl_listen_event($event_name, $callback, $priority = 10) {
    return SRL_Event_System::getInstance()->listen($event_name, $callback, $priority);
}

function srl_get_event_log($limit = 50) {
    return SRL_Event_System::getInstance()->get_event_log($limit);
}

// Inicjalizuj system zdarzeń
SRL_Event_System::getInstance();

// Przykładowe listenery dla kluczowych zdarzeń
srl_listen_event('flight_reserved', function($data) {
    // Wyślij email potwierdzenia
    if (isset($data['user_id'], $data['slot'], $data['flight'])) {
        srl_wyslij_email_potwierdzenia($data['user_id'], $data['slot'], $data['flight']);
    }
});

srl_listen_event('flight_cancelled', function($data) {
    // Wyślij email anulowania
    if (isset($data['user_id'], $data['slot'], $data['flight'])) {
        srl_wyslij_email_anulowania($data['user_id'], $data['slot'], $data['flight'], $data['reason'] ?? '');
    }
});

srl_listen_event('voucher_used', function($data) {
    // Wyczyść cache użytkownika po wykorzystaniu vouchera
    if (isset($data['user_id'])) {
        SRL_Database_Manager::getInstance()->clear_cache("user_flights_{$data['user_id']}");
    }
});

srl_listen_event('cache_cleared', function($data) {
    // Log czyszczenia cache dla debugowania
    error_log('SRL Cache cleared: ' . ($data['pattern'] ?? 'all'));
});