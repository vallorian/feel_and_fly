<?php
if (!defined('ABSPATH')) {exit;}

class SRL_WooCommerce_Integration {
    
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
		add_action('woocommerce_order_status_changed', [$this, 'orderStatusChanged'], 10, 3);
		add_action('before_delete_post', [$this, 'beforeDeleteOrder']);
		add_action('admin_init', [$this, 'migrujIstniejaceZamowienia']);
		add_action('wp', [$this, 'scheduleDailyCheck']);
		add_action('srl_sprawdz_przeterminowane_loty', [SRL_Historia_Functions::getInstance(), 'oznaczPrzeterminowaneLoty']);
		add_action('srl_sprawdz_przeterminowane_loty', [SRL_Voucher_Gift_Functions::getInstance(), 'oznaczPrzeterminowaneVouchery']);
	}

	public function orderStatusChanged($order_id, $old_status, $new_status) {
		$valid   = ['processing', 'completed'];
		$invalid = ['cancelled', 'refunded', 'failed', 'pending', 'on-hold', 'trash'];

		if (in_array($new_status, $valid, true)) {
			$this->dodajLotyPoZakupie($order_id);
			SRL_Voucher_Gift_Functions::getInstance()->dodajVoucheryPoZakupie($order_id);
		}
		elseif (in_array($new_status, $invalid, true)) {
			$this->usunLotyZamowienia($order_id);
			SRL_Voucher_Gift_Functions::getInstance()->usunVoucheryZamowienia($order_id);
		}
	}

    public function czyProduktLotu($product) {
        if (!$product) return false;

        $dozwolone_id = SRL_Helpers::getInstance()->getFlightProductIds();
        return in_array($product->get_id(), $dozwolone_id);
    }

    public function dodajLotyPoZakupie($order_id) {
		global $wpdb;
		$tabela = $wpdb->prefix . 'srl_zakupione_loty';

		$order = wc_get_order($order_id);
		if (!$order) return;

		$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM $tabela WHERE order_id = %d",
			$order_id
		));

		if ($existing > 0) {
			return; 
		}

		$user_id = $order->get_user_id();
		if (!$user_id) return; 

		$data_zakupu = $order->get_date_created()->date('Y-m-d H:i:s');
		$data_waznosci = SRL_Helpers::getInstance()->generateExpiryDate($data_zakupu, 1);

		$imie = $order->get_billing_first_name();
		$nazwisko = $order->get_billing_last_name();

		$dodane_loty = 0;
		$pierwszy_lot_data = null; // Dla emaila

		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();

			if ($product && $this->czyProduktLotu($product)) {
				$quantity = $item->get_quantity();
				$nazwa_produktu = $item->get_name();

				for ($i = 0; $i < $quantity; $i++) {

					$opcje = SRL_Flight_Options::getInstance()->analizaOpcjiProduktu($nazwa_produktu);

					$result = $wpdb->insert(
						$tabela,
						array(
							'order_item_id' => $item_id,
							'order_id' => $order_id,
							'user_id' => $user_id,
							'imie' => $imie,
							'nazwisko' => $nazwisko,
							'nazwa_produktu' => $nazwa_produktu,
							'status' => 'wolny',
							'data_zakupu' => $data_zakupu,
							'data_waznosci' => $data_waznosci,
							'ma_filmowanie' => $opcje['ma_filmowanie'],
							'ma_akrobacje' => $opcje['ma_akrobacje']
						),
						array('%d','%d','%d','%s','%s','%s','%s','%s','%s','%d','%d')
					);

					if ($result !== false) {
						$dodane_loty++;
						$lot_id = $wpdb->insert_id;

						// Zapisz dane pierwszego lotu dla emaila
						if ($dodane_loty === 1) {
							$pierwszy_lot_data = array(
								'id' => $lot_id,
								'nazwa_produktu' => $nazwa_produktu,
								'data_waznosci' => $data_waznosci,
								'ma_filmowanie' => $opcje['ma_filmowanie'],
								'ma_akrobacje' => $opcje['ma_akrobacje']
							);
						}

						$historia_poczatkowa = array();

						$historia_poczatkowa[] = array(
							'data' => $data_zakupu,
							'typ' => 'przypisanie_id',
							'executor' => 'System',
							'szczegoly' => array(
								'lot_id' => $lot_id,
								'nazwa_produktu' => $nazwa_produktu,
								'order_id' => $order_id,
								'order_item_id' => $item_id,
								'user_id' => $user_id,
								'data_waznosci' => $data_waznosci,
								'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1'
							)
						);

						if ($opcje['ma_filmowanie']) {
							$historia_poczatkowa[] = array(
								'data' => $data_zakupu,
								'typ' => 'opcja_przy_zakupie',
								'executor' => 'System',
								'szczegoly' => array(
									'opcja' => 'filmowanie',
									'dodano_przy_zakupie' => true,
									'order_id' => $order_id
								)
							);
						}

						if ($opcje['ma_akrobacje']) {
							$historia_poczatkowa[] = array(
								'data' => $data_zakupu,
								'typ' => 'opcja_przy_zakupie',
								'executor' => 'System',
								'szczegoly' => array(
									'opcja' => 'akrobacje',
									'dodano_przy_zakupie' => true,
									'order_id' => $order_id
								)
							);
						}

						$wpdb->update(
							$tabela,
							array('historia_modyfikacji' => json_encode($historia_poczatkowa)),
							array('id' => $lot_id),
							array('%s'),
							array('%d')
						);
					}
				}
			}
		}

		if ($dodane_loty > 0) {
			SRL_Cache_Manager::getInstance()->invalidateUserCache($user_id);
			
			// NOWY KOD - Wyślij email powitalny po dodaniu lotów
			if ($pierwszy_lot_data) {
				$email_sent = SRL_Email_Functions::getInstance()->wyslijEmailPowitalnyPoZakupie($user_id, $pierwszy_lot_data);
				
				if (!$email_sent) {
					error_log("SRL: Nie udało się wysłać emaila powitalnego do user_id: {$user_id} dla zamówienia #{$order_id}");
				} else {
					error_log("SRL: Wysłano email powitalny do user_id: {$user_id} dla zamówienia #{$order_id}");
				}
			}
			
			error_log("SRL: Dodano $dodane_loty lotów dla zamówienia #$order_id z nowym systemem historii");
		}
		
		return $dodane_loty;
	}

    public function usunLotyZamowienia($order_id) {
		global $wpdb;
		$tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
		$tabela_terminy = $wpdb->prefix . 'srl_terminy';

		// Pobierz user_id przed usunięciem
		$user_id = $wpdb->get_var($wpdb->prepare(
			"SELECT user_id FROM $tabela_loty WHERE order_id = %d LIMIT 1",
			$order_id
		));

		$zarezerwowane_loty = $wpdb->get_results($wpdb->prepare(
			"SELECT id, termin_id FROM $tabela_loty 
			 WHERE order_id = %d AND status = 'zarezerwowany' AND termin_id IS NOT NULL",
			$order_id
		));

		foreach ($zarezerwowane_loty as $lot) {
			if ($lot->termin_id) {
				$wpdb->update(
					$tabela_terminy,
					array('status' => 'Wolny', 'klient_id' => null),
					array('id' => $lot->termin_id),
					array('%s', '%d'),
					array('%d')
				);
			}
		}

		$usuniete = $wpdb->delete(
			$tabela_loty,
			array('order_id' => $order_id),
			array('%d')
		);

		if ($usuniete > 0 && $user_id) {
			SRL_Cache_Manager::getInstance()->invalidateUserCache($user_id);
			error_log("SRL: Usunięto $usuniete lotów dla zamówienia #$order_id");
		}

		return $usuniete;
	}

    public function beforeDeleteOrder($post_id) {
        if (get_post_type($post_id) === 'shop_order') {
            $this->usunLotyZamowienia($post_id);
        }
    }

    public function migrujIstniejaceZamowienia() {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_zakupione_loty';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela'") == $tabela;
        if (!$table_exists) {
            return;
        }

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $tabela");
        if ($count > 0) {
            return; 
        }

        $orders = wc_get_orders(array(
            'status' => array('processing', 'completed'),
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));

        $przetworzone = 0;
        foreach ($orders as $order) {

            $ma_loty = false;
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product && $this->czyProduktLotu($product)) {
                    $ma_loty = true;
                    break;
                }
            }

            if ($ma_loty) {
                $dodane = $this->dodajLotyPoZakupie($order->get_id());
                if ($dodane > 0) {
                    $przetworzone++;
                }
            }
        }

        if ($przetworzone > 0) {
            error_log("SRL: Zmigrowano $przetworzone zamówień");
        }
    }

    public function oznaczPrzeterminowaneLoty() {
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';

        $wpdb->query(
            "UPDATE $tabela 
             SET status = 'przedawniony' 
             WHERE status IN ('wolny', 'zarezerwowany') 
             AND data_waznosci < CURDATE()"
        );

        $przeterminowane_rezerwacje = $wpdb->get_results(
            "SELECT termin_id FROM $tabela 
             WHERE status = 'przedawniony' 
             AND termin_id IS NOT NULL"
        );

        foreach ($przeterminowane_rezerwacje as $rez) {
            if ($rez->termin_id) {
                $wpdb->update(
                    $tabela_terminy,
                    array('status' => 'Wolny', 'klient_id' => null),
                    array('id' => $rez->termin_id),
                    array('%s', '%d'),
                    array('%d')
                );
            }
        }

        $wpdb->query(
            "UPDATE $tabela 
             SET termin_id = NULL 
             WHERE status = 'przedawniony'"
        );
    }

    public function scheduleDailyCheck() {
        if (!wp_next_scheduled('srl_sprawdz_przeterminowane_loty')) {
            wp_schedule_event(time(), 'daily', 'srl_sprawdz_przeterminowane_loty');
        }
    }

	public function dokupFilmowanie($lot_id, $order_id, $item_id, $quantity = 1) {
		global $wpdb;
		$tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

		$lot = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $tabela_loty WHERE id = %d",
			$lot_id
		), ARRAY_A);

		if (!$lot || $lot['ma_filmowanie']) {
			return false; 
		}

		$result = $wpdb->update(
			$tabela_loty,
			array('ma_filmowanie' => 1),
			array('id' => $lot_id),
			array('%d'),
			array('%d')
		);

		if ($result !== false) {
			for ($i = 0; $i < $quantity; $i++) {
				$current_time = SRL_Helpers::getInstance()->getCurrentDatetime();
				if ($i > 0) {
					$current_time = date('Y-m-d H:i:s', strtotime($current_time) + $i);
				}
				
				$wpis_historii = array(
					'data' => $current_time,
					'typ' => 'dokupienie_filmowanie',
					'executor' => 'Klient',
					'szczegoly' => array(
						'opcja' => 'filmowanie',
						'order_id' => $order_id,
						'item_id' => $item_id,
						'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1',
						'stary_stan' => 0,
						'nowy_stan' => 1
					)
				);

				SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);
			}

			// NOWY KOD - Wyślij email
			$email_sent = SRL_Email_Functions::getInstance()->wyslijEmailOpcjiLotu(
				$lot['user_id'],
				$lot_id,
				'filmowanie'
			);
			
			if ($email_sent) {
				SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
					'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
					'typ' => 'email_opcja_filmowanie',
					'executor' => 'System',
					'szczegoly' => [
						'typ_emaila' => 'potwierdzenie_opcji',
						'opcja' => 'filmowanie',
						'email_wyslany' => true
					]
				]);
			}

			SRL_Cache_Manager::getInstance()->invalidateUserCache($lot['user_id']);
		}

		return $result !== false;
	}

	public function dokupAkrobacje($lot_id, $order_id, $item_id, $quantity = 1) {
		global $wpdb;
		$tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

		$lot = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $tabela_loty WHERE id = %d",
			$lot_id
		), ARRAY_A);

		if (!$lot || $lot['ma_akrobacje']) {
			return false; 
		}

		$result = $wpdb->update(
			$tabela_loty,
			array('ma_akrobacje' => 1),
			array('id' => $lot_id),
			array('%d'),
			array('%d')
		);

		if ($result !== false) {
			for ($i = 0; $i < $quantity; $i++) {
				$current_time = SRL_Helpers::getInstance()->getCurrentDatetime();
				if ($i > 0) {
					$current_time = date('Y-m-d H:i:s', strtotime($current_time) + $i);
				}
				
				$wpis_historii = array(
					'data' => $current_time,
					'typ' => 'dokupienie_akrobacje',
					'executor' => 'Klient',
					'szczegoly' => array(
						'opcja' => 'akrobacje',
						'order_id' => $order_id,
						'item_id' => $item_id,
						'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1',
						'stary_stan' => 0,
						'nowy_stan' => 1
					)
				);

				SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);
			}

			// NOWY KOD - Wyślij email
			$email_sent = SRL_Email_Functions::getInstance()->wyslijEmailOpcjiLotu(
				$lot['user_id'],
				$lot_id,
				'akrobacje'
			);
			
			if ($email_sent) {
				SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
					'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
					'typ' => 'email_opcja_akrobacje',
					'executor' => 'System',
					'szczegoly' => [
						'typ_emaila' => 'potwierdzenie_opcji',
						'opcja' => 'akrobacje',
						'email_wyslany' => true
					]
				]);
			}

			SRL_Cache_Manager::getInstance()->invalidateUserCache($lot['user_id']);
		}

		return $result !== false;
	}

	public function dokupPrzedluzenie($lot_id, $order_id, $item_id = 0, $quantity = 1) {
		global $wpdb;
		$tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

		$lot = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $tabela_loty WHERE id = %d",
			$lot_id
		), ARRAY_A);

		if (!$lot) {
			return false;
		}

		$stara_data = $lot['data_waznosci'];

		$nowa_data = $stara_data;
		for ($i = 0; $i < $quantity; $i++) {
			$nowa_data = date('Y-m-d', strtotime($nowa_data . ' +1 year'));
		}

		$result = $wpdb->update(
			$tabela_loty,
			array('data_waznosci' => $nowa_data),
			array('id' => $lot_id),
			array('%s'),
			array('%d')
		);

		if ($result !== false) {
			for ($i = 0; $i < $quantity; $i++) {
				$data_przedluzenia = date('Y-m-d', strtotime($stara_data . ' +' . ($i + 1) . ' year'));
				
				$wpis_historii = array(
					'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
					'typ' => 'przedluzenie_waznosci',
					'executor' => 'Klient',
					'szczegoly' => array(
						'stara_data_waznosci' => $i == 0 ? $stara_data : date('Y-m-d', strtotime($stara_data . ' +' . $i . ' year')),
						'nowa_data_waznosci' => $data_przedluzenia,
						'przedluzenie_lat' => 1,
						'order_id' => $order_id,
						'item_id' => $item_id,
						'quantity_info' => $quantity > 1 ? sprintf('%d/%d', $i + 1, $quantity) : '1/1'
					)
				);

				SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);
				
				if ($i < $quantity - 1) {
					usleep(100000);
				}
			}

			// NOWY KOD - Wyślij email z dodatkowymi danymi
			$dodatkowe_dane = array(
				'stara_data_waznosci' => SRL_Helpers::getInstance()->formatujDate($stara_data),
				'nowa_data_waznosci' => SRL_Helpers::getInstance()->formatujDate($nowa_data),
				'lata' => $quantity,
				'link_rezerwacji' => home_url('/rezerwuj-lot/')
			);
			
			$email_sent = SRL_Email_Functions::getInstance()->wyslijEmailOpcjiLotu(
				$lot['user_id'],
				$lot_id,
				'przedluzenie',
				$dodatkowe_dane
			);
			
			if ($email_sent) {
				SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, [
					'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
					'typ' => 'email_opcja_przedluzenie',
					'executor' => 'System',
					'szczegoly' => [
						'typ_emaila' => 'potwierdzenie_opcji',
						'opcja' => 'przedluzenie',
						'stara_data' => $stara_data,
						'nowa_data' => $nowa_data,
						'lata' => $quantity,
						'email_wyslany' => true
					]
				]);
			}

			SRL_Cache_Manager::getInstance()->invalidateUserCache($lot['user_id']);
		}

		return $result !== false;
	}
}