<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Database_Setup {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
    }

    public function activatePlugin() {
        $this->createTables();
        $this->updateDatabase();
        $this->createProductCategories();
        $this->createReservationPage();
        $this->addEndpoints();
        flush_rewrite_rules();

        update_option('srl_db_version', '1.0');

        if (!get_option('users_can_register')) {
            update_option('users_can_register', 1);
        }
    }

    private function createTables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $sql_terminy = "CREATE TABLE $tabela_terminy (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            data DATE NOT NULL,
            pilot_id tinyint(2) NOT NULL,
            godzina_start TIME NOT NULL,
            godzina_koniec TIME NOT NULL,
            status ENUM('Wolny','Prywatny','Zarezerwowany','Zrealizowany','Odwołany przez organizatora') DEFAULT 'Wolny' NOT NULL,
            klient_id bigint(20) NULL,
            PRIMARY KEY  (id),
            KEY data (data),
            KEY pilot_id (pilot_id)
        ) $charset_collate;";

        $tabela_vouchery = $wpdb->prefix . 'srl_vouchery';
        $sql_vouchery = "CREATE TABLE $tabela_vouchery (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            kod_vouchera varchar(12) NOT NULL,
            status ENUM('Oczekuje','Zatwierdzony','Odrzucony','Zrealizowany') DEFAULT 'Oczekuje' NOT NULL,
            data_zgloszenia DATETIME NOT NULL,
            zrodlo varchar(255),
            klient_id bigint(20) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY kod_vouchera (kod_vouchera)
        ) $charset_collate;";

        $tabela_zakupione_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $sql_zakupione_loty = "CREATE TABLE $tabela_zakupione_loty (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_item_id bigint(20) NOT NULL,
            order_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            imie varchar(100) NOT NULL,
            nazwisko varchar(100) NOT NULL,
            nazwa_produktu varchar(255) NOT NULL,
            status ENUM('wolny','zarezerwowany','zrealizowany','przedawniony') DEFAULT 'wolny' NOT NULL,
            data_zakupu DATETIME NOT NULL,
            data_waznosci DATE NOT NULL,
            data_rezerwacji DATETIME NULL,
            termin_id mediumint(9) NULL,
            PRIMARY KEY (id),
            KEY order_item_id (order_item_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY data_waznosci (data_waznosci)
        ) $charset_collate;";

        $tabela_vouchery_partnerzy = $wpdb->prefix . 'srl_vouchery_partnerzy';
        $sql_vouchery_partnerzy = "CREATE TABLE $tabela_vouchery_partnerzy (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            partner varchar(100) NOT NULL,
            typ_vouchera varchar(100) NOT NULL,
            kod_vouchera varchar(50) NOT NULL,
            kod_zabezpieczajacy varchar(50) NOT NULL,
            liczba_osob tinyint(2) NOT NULL,
            dane_pasazerow TEXT NOT NULL,
            status ENUM('oczekuje','zatwierdzony','odrzucony') DEFAULT 'oczekuje' NOT NULL,
            powod_odrzucenia TEXT NULL,
            klient_id bigint(20) NOT NULL,
            data_zgloszenia DATETIME NOT NULL,
            data_modyfikacji DATETIME NULL,
            id_oryginalnego mediumint(9) NULL,
            PRIMARY KEY (id),
            KEY klient_id (klient_id),
            KEY status (status),
            KEY partner (partner),
            KEY data_zgloszenia (data_zgloszenia),
            KEY id_oryginalnego (id_oryginalnego)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_terminy);
        dbDelta($sql_vouchery);
        dbDelta($sql_zakupione_loty);
        dbDelta($sql_vouchery_partnerzy);
    }

    private function updateDatabase() {
        global $wpdb;
        $current_version = get_option('srl_db_version', '1.0');

        if (version_compare($current_version, '1.1', '<')) {
            $tabela_terminy = $wpdb->prefix . 'srl_terminy';
            $wpdb->query("ALTER TABLE $tabela_terminy MODIFY COLUMN status ENUM('Wolny','Prywatny','Zarezerwowany','Zrealizowany','Odwołany przez organizatora') DEFAULT 'Wolny' NOT NULL");
            update_option('srl_db_version', '1.1');
        }

        if (version_compare($current_version, '1.2', '<')) {
            $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
            $kolumna_istnieje = $wpdb->get_results("SHOW COLUMNS FROM $tabela_loty LIKE 'dane_pasazera'");

            if (empty($kolumna_istnieje)) {
                $wpdb->query("ALTER TABLE $tabela_loty ADD COLUMN dane_pasazera TEXT NULL AFTER termin_id");
            }
            update_option('srl_db_version', '1.2');
        }

        if (version_compare($current_version, '1.3', '<')) {
            $tabela_vouchery_upominkowe = $wpdb->prefix . 'srl_vouchery_upominkowe';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela_vouchery_upominkowe'") == $tabela_vouchery_upominkowe;

            if (!$table_exists) {
                $charset_collate = $wpdb->get_charset_collate();
                $sql_vouchery_upominkowe = "CREATE TABLE $tabela_vouchery_upominkowe (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    order_item_id bigint(20) NOT NULL,
                    order_id bigint(20) NOT NULL,
                    buyer_user_id bigint(20) NOT NULL,
                    buyer_imie varchar(100) NOT NULL,
                    buyer_nazwisko varchar(100) NOT NULL,
                    nazwa_produktu varchar(255) NOT NULL,
                    kod_vouchera varchar(10) NOT NULL,
                    status ENUM('do_wykorzystania','wykorzystany','przeterminowany') DEFAULT 'do_wykorzystania' NOT NULL,
                    data_zakupu DATETIME NOT NULL,
                    data_waznosci DATE NOT NULL,
                    data_wykorzystania DATETIME NULL,
                    wykorzystany_przez_user_id bigint(20) NULL,
                    lot_id mediumint(9) NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_kod_vouchera (kod_vouchera),
                    KEY idx_order_item_id (order_item_id),
                    KEY idx_buyer_user_id (buyer_user_id),
                    KEY idx_status (status),
                    KEY idx_data_waznosci (data_waznosci),
                    KEY idx_wykorzystany_przez_user_id (wykorzystany_przez_user_id)
                ) $charset_collate;";

                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql_vouchery_upominkowe);
                error_log('SRL: Tabela voucherów utworzona w wersji 1.3');
            }
            update_option('srl_db_version', '1.3');
        }

        if (version_compare($current_version, '1.4', '<')) {
            $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
            $kolumny = $wpdb->get_results("SHOW COLUMNS FROM $tabela_loty");
            $existing_columns = array();
            foreach ($kolumny as $kolumna) {
                $existing_columns[] = $kolumna->Field;
            }

            if (!in_array('ma_filmowanie', $existing_columns)) {
                $wpdb->query("ALTER TABLE $tabela_loty ADD COLUMN ma_filmowanie TINYINT(1) DEFAULT 0 AFTER dane_pasazera");
            }

            if (!in_array('ma_akrobacje', $existing_columns)) {
                $wpdb->query("ALTER TABLE $tabela_loty ADD COLUMN ma_akrobacje TINYINT(1) DEFAULT 0 AFTER ma_filmowanie");
            }

            if (!in_array('historia_modyfikacji', $existing_columns)) {
                $wpdb->query("ALTER TABLE $tabela_loty ADD COLUMN historia_modyfikacji TEXT NULL AFTER ma_akrobacje");
            }
            update_option('srl_db_version', '1.4');
        }

        if (version_compare($current_version, '1.5', '<')) {
            $tabela_terminy = $wpdb->prefix . 'srl_terminy';
            $kolumna_istnieje = $wpdb->get_results("SHOW COLUMNS FROM $tabela_terminy LIKE 'notatka'");

            if (empty($kolumna_istnieje)) {
                $wpdb->query("ALTER TABLE $tabela_terminy ADD COLUMN notatka TEXT NULL AFTER klient_id");
            }
            update_option('srl_db_version', '1.5');
        }

        if (version_compare($current_version, '1.6', '<')) {
            $tabela_terminy = $wpdb->prefix . 'srl_terminy';
            $kolumna_istnieje = $wpdb->get_results("SHOW COLUMNS FROM $tabela_terminy LIKE 'notatka'");

            if (empty($kolumna_istnieje)) {
                $wpdb->query("ALTER TABLE $tabela_terminy ADD COLUMN notatka TEXT NULL AFTER klient_id");
            }
            update_option('srl_db_version', '1.6');
        }

        if (version_compare($current_version, '1.7', '<')) {
            $tabela_vouchery_partnerzy = $wpdb->prefix . 'srl_vouchery_partnerzy';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela_vouchery_partnerzy'") == $tabela_vouchery_partnerzy;
            
            if (!$table_exists) {
                $charset_collate = $wpdb->get_charset_collate();
                $sql_vouchery_partnerzy = "CREATE TABLE $tabela_vouchery_partnerzy (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    partner varchar(100) NOT NULL,
                    typ_vouchera varchar(100) NOT NULL,
                    kod_vouchera varchar(50) NOT NULL,
                    kod_zabezpieczajacy varchar(50) NOT NULL,
                    liczba_osob tinyint(2) NOT NULL,
                    dane_pasazerow TEXT NOT NULL,
                    status ENUM('oczekuje','zatwierdzony','odrzucony') DEFAULT 'oczekuje' NOT NULL,
                    powod_odrzucenia TEXT NULL,
                    klient_id bigint(20) NOT NULL,
                    data_zgloszenia DATETIME NOT NULL,
                    data_modyfikacji DATETIME NULL,
                    id_oryginalnego mediumint(9) NULL,
                    PRIMARY KEY (id),
                    KEY klient_id (klient_id),
                    KEY status (status),
                    KEY partner (partner),
                    KEY data_zgloszenia (data_zgloszenia),
                    KEY id_oryginalnego (id_oryginalnego)
                ) $charset_collate;";

                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql_vouchery_partnerzy);
                error_log('SRL: Tabela voucherów partnera utworzona w wersji 1.7');
            }
            update_option('srl_db_version', '1.7');
        }
        
        if (version_compare($current_version, '1.8', '<')) {
            $tabela_vouchery_partnerzy = $wpdb->prefix . 'srl_vouchery_partnerzy';
            $kolumna_istnieje = $wpdb->get_results("SHOW COLUMNS FROM $tabela_vouchery_partnerzy LIKE 'data_waznosci_vouchera'");
            
            if (empty($kolumna_istnieje)) {
                $wpdb->query("ALTER TABLE $tabela_vouchery_partnerzy ADD COLUMN data_waznosci_vouchera DATE NULL AFTER kod_zabezpieczajacy");
                error_log('SRL: Dodano kolumnę data_waznosci_vouchera do tabeli voucherów partnera');
            }
            update_option('srl_db_version', '1.8');
        }
        
        if (version_compare($current_version, '1.9', '<')) {
            $tabela_vouchery_upominkowe = $wpdb->prefix . 'srl_vouchery_upominkowe';
            $kolumny = $wpdb->get_results("SHOW COLUMNS FROM $tabela_vouchery_upominkowe");
            $existing_columns = array();
            foreach ($kolumny as $kolumna) {
                $existing_columns[] = $kolumna->Field;
            }
            
            if (!in_array('ma_filmowanie', $existing_columns)) {
                $wpdb->query("ALTER TABLE $tabela_vouchery_upominkowe ADD COLUMN ma_filmowanie TINYINT(1) DEFAULT 0 AFTER lot_id");
            }
            
            if (!in_array('ma_akrobacje', $existing_columns)) {
                $wpdb->query("ALTER TABLE $tabela_vouchery_upominkowe ADD COLUMN ma_akrobacje TINYINT(1) DEFAULT 0 AFTER ma_filmowanie");
            }
            
            error_log('SRL: Dodano kolumny ma_filmowanie i ma_akrobacje do tabeli voucherów w wersji 1.9');
            update_option('srl_db_version', '1.9');
        }
		
		if (version_compare($current_version, '2.0', '<')) {
			$this->addDatabaseIndexes();
			update_option('srl_db_version', '2.0');
		}
		
    }

    public function cleanupVoucherTable() {
        global $wpdb;
        $tabela_vouchery_upominkowe = $wpdb->prefix . 'srl_vouchery_upominkowe';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela_vouchery_upominkowe'") == $tabela_vouchery_upominkowe;

        if ($table_exists) {
            $wpdb->query("DROP TABLE IF EXISTS $tabela_vouchery_upominkowe");
            error_log('SRL: Usunięto uszkodzoną tabelę voucherów');
        }
    }

    private function createProductCategories() {
    }

    private function createReservationPage() {
    }

    private function addEndpoints() {
    }
	
	private function addDatabaseIndexes() {
		global $wpdb;
		
		$indexes = array(
			$wpdb->prefix . 'srl_zakupione_loty' => array(
				'idx_user_status_validity' => 'user_id, status, data_waznosci',
				'idx_status_validity' => 'status, data_waznosci',
				'idx_order_item' => 'order_item_id'
			),
			$wpdb->prefix . 'srl_terminy' => array(
				'idx_data_status' => 'data, status',
				'idx_klient_status' => 'klient_id, status'
			),
			$wpdb->prefix . 'srl_vouchery_upominkowe' => array(
				'idx_status_validity' => 'status, data_waznosci',
				'idx_buyer_status' => 'buyer_user_id, status'
			),
			$wpdb->prefix . 'srl_vouchery_partnerzy' => array(
				'idx_status_data' => 'status, data_zgloszenia',
				'idx_klient_status' => 'klient_id, status'
			)
		);
		
		foreach ($indexes as $table => $table_indexes) {
			if ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table) {
				foreach ($table_indexes as $index_name => $columns) {
					$existing_index = $wpdb->get_results($wpdb->prepare(
						"SHOW INDEX FROM $table WHERE Key_name = %s", 
						$index_name
					));
					
					if (empty($existing_index)) {
						$wpdb->query("ALTER TABLE $table ADD INDEX $index_name ($columns)");
					}
				}
			}
		}
	}
}