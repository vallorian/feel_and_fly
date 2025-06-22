<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Admin_Ajax {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_srl_dodaj_godzine', array($this, 'ajaxDodajGodzine'));
        add_action('wp_ajax_srl_zmien_slot', array($this, 'ajaxZmienSlot'));
        add_action('wp_ajax_srl_usun_godzine', array($this, 'ajaxUsunGodzine'));
        add_action('wp_ajax_srl_zmien_status_godziny', array($this, 'ajaxZmienStatusGodziny'));
        add_action('wp_ajax_srl_anuluj_lot_przez_organizatora', array($this, 'ajaxAnulujLotPrzezOrganizatora'));
        add_action('wp_ajax_srl_wyszukaj_klientow_loty', array($this, 'ajaxWyszukajKlientowLoty'));
        add_action('wp_ajax_srl_dodaj_voucher_recznie', array($this, 'ajaxDodajVoucherRecznie'));
        add_action('wp_ajax_srl_wyszukaj_dostepnych_klientow', array($this, 'ajaxWyszukajDostepnychKlientow'));
        add_action('wp_ajax_srl_przypisz_klienta_do_slotu', array($this, 'ajaxPrzypiszKlientaDoSlotu'));
        add_action('wp_ajax_srl_zapisz_dane_prywatne', array($this, 'ajaxZapiszDanePrywatne'));
        add_action('wp_ajax_srl_pobierz_dane_prywatne', array($this, 'ajaxPobierzDanePrywatne'));
        add_action('wp_ajax_srl_pobierz_aktualne_godziny', array($this, 'ajaxPobierzAktualneGodziny'));
        add_action('wp_ajax_srl_wyszukaj_wolne_loty', array($this, 'ajaxWyszukajWolneLoty'));
        add_action('wp_ajax_srl_przypisz_wykupiony_lot', array($this, 'ajaxPrzypiszWykupionyLot'));
        add_action('wp_ajax_srl_zapisz_lot_prywatny', array($this, 'ajaxZapiszLotPrywatny'));
        add_action('wp_ajax_srl_pobierz_historie_lotu', array($this, 'ajaxPobierzHistorieLotu'));
        add_action('wp_ajax_srl_przywroc_rezerwacje', array($this, 'ajaxPrzywrocRezerwacje'));
        add_action('wp_ajax_srl_pobierz_dane_odwolanego', array($this, 'ajaxPobierzDaneOdwolanego'));
        add_action('wp_ajax_srl_zrealizuj_lot', array($this, 'ajaxZrealizujLot'));
        add_action('wp_ajax_srl_zrealizuj_lot_prywatny', array($this, 'ajaxajaxZrealizujLotPrywatny'));
        add_action('wp_ajax_srl_pobierz_dostepne_terminy_do_zmiany', array($this, 'ajaxPobierzDostepneTerminyDoZmiany'));
        add_action('wp_ajax_srl_zmien_termin_lotu', array($this, 'ajaxZmienTerminLotu'));
		add_action('wp_ajax_srl_admin_zmien_status_lotu', array($this, 'ajaxAdminZmienStatusLotu'));
		add_action('wp_ajax_srl_pobierz_szczegoly_lotu', array($this, 'ajaxPobierzSzczegolyLotu'));
    }
	
	public function ajaxAdminZmienStatusLotu() {
		check_ajax_referer('srl_admin_nonce', 'nonce', true);
		SRL_Helpers::getInstance()->checkAdminPermissions();

		$lot_id = intval($_POST['lot_id']);
		$nowy_status = sanitize_text_field($_POST['nowy_status']);

		if (!$lot_id || !$nowy_status) {
			wp_send_json_error('Nieprawidłowe parametry.');
		}

		global $wpdb;
		$tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
		$tabela_terminy = $wpdb->prefix . 'srl_terminy';

		$lot = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $tabela_loty WHERE id = %d",
			$lot_id
		), ARRAY_A);

		if (!$lot) {
			wp_send_json_error('Lot nie istnieje.');
		}

		$stary_status = $lot['status'];

		$wpdb->query('START TRANSACTION');

		try {
			// Jeśli lot był zarezerwowany i zmieniamy na wolny, zwolnij termin
			if ($stary_status === 'zarezerwowany' && $nowy_status === 'wolny' && $lot['termin_id']) {
				$wpdb->update(
					$tabela_terminy,
					array('status' => 'Wolny', 'klient_id' => null),
					array('id' => $lot['termin_id']),
					array('%s', '%d'),
					array('%d')
				);

				$update_data = array(
					'status' => $nowy_status,
					'termin_id' => null,
					'data_rezerwacji' => null
				);
			} else {
				$update_data = array('status' => $nowy_status);
			}

			$result = $wpdb->update(
				$tabela_loty,
				$update_data,
				array('id' => $lot_id),
				array_fill(0, count($update_data), '%s'),
				array('%d')
			);

			if ($result === false) {
				throw new Exception('Błąd aktualizacji statusu lotu.');
			}

			// Dodaj wpis do historii
			$wpis_historii = array(
				'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
				'typ' => 'zmiana_statusu_admin',
				'executor' => 'Admin',
				'szczegoly' => array(
					'stary_status' => $stary_status,
					'nowy_status' => $nowy_status,
					'zmiana_przez_admin' => true,
					'lot_id' => $lot_id
				)
			);

			SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);

			$wpdb->query('COMMIT');
			wp_send_json_success('Status lotu został zmieniony z "' . $stary_status . '" na "' . $nowy_status . '".');

		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			wp_send_json_error($e->getMessage());
		}
	}

	public function ajaxPobierzSzczegolyLotu() {
		check_ajax_referer('srl_admin_nonce', 'nonce', true);
		SRL_Helpers::getInstance()->checkAdminPermissions();

		$lot_id = intval($_POST['lot_id']);
		$user_id = intval($_POST['user_id']);

		if (!$lot_id || !$user_id) {
			wp_send_json_error('Nieprawidłowe parametry.');
		}

		// Pobierz dane użytkownika
		$user_data = SRL_Helpers::getInstance()->getUserFullData($user_id);

		if (!$user_data) {
			wp_send_json_error('Nie znaleziono danych użytkownika.');
		}

		// Pobierz dane lotu
		global $wpdb;
		$tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
		
		$lot = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $tabela_loty WHERE id = %d",
			$lot_id
		), ARRAY_A);

		if (!$lot) {
			wp_send_json_error('Nie znaleziono danych lotu.');
		}

		// Przygotuj dane do wysłania
		$dane = array(
			'lot_id' => $lot_id,
			'imie' => $user_data['imie'],
			'nazwisko' => $user_data['nazwisko'],
			'email' => $user_data['email'],
			'telefon' => $user_data['telefon'],
			'rok_urodzenia' => $user_data['rok_urodzenia'],
			'kategoria_wagowa' => $user_data['kategoria_wagowa'],
			'sprawnosc_fizyczna' => $user_data['sprawnosc_fizyczna'],
			'uwagi' => $user_data['uwagi'],
			'nazwa_produktu' => $lot['nazwa_produktu'],
			'status' => $lot['status'],
			'data_zakupu' => $lot['data_zakupu'],
			'data_waznosci' => $lot['data_waznosci']
		);

		// Oblicz wiek jeśli jest rok urodzenia
		if ($user_data['rok_urodzenia']) {
			$dane['wiek'] = date('Y') - intval($user_data['rok_urodzenia']);
		}

		wp_send_json_success($dane);
	}


    public function ajaxDodajGodzine() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        if (!isset($_POST['data']) || !isset($_POST['pilot_id'])) {
            wp_send_json_error('Nieprawidłowe dane.');
            return;
        }

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $data = sanitize_text_field($_POST['data']);
        $pilot_id = intval($_POST['pilot_id']);
        $godzStart = sanitize_text_field($_POST['godzina_start']) . ':00';
        $godzKoniec = sanitize_text_field($_POST['godzina_koniec']) . ':00';
        $status = sanitize_text_field($_POST['status']);
        $klient_id = 0;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            wp_send_json_error('Nieprawidłowy format daty.');
            return;
        }

        if ($pilot_id < 1 || $pilot_id > 4) {
            wp_send_json_error('Nieprawidłowy ID pilota.');
            return;
        }

        $result = $wpdb->insert(
            $tabela,
            array(
                'data' => $data,
                'pilot_id' => $pilot_id,
                'godzina_start' => $godzStart,
                'godzina_koniec' => $godzKoniec,
                'status' => $status,
                'klient_id' => $klient_id
            ),
            array('%s','%d','%s','%s','%s','%d')
        );

        if ($result === false) {
            wp_send_json_error('Nie udało się zapisać slotu: ' . $wpdb->last_error);
            return;
        }

        SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($data);
    }

	public function ajaxZmienSlot() {
		check_ajax_referer('srl_admin_nonce', 'nonce', true);
		SRL_Helpers::getInstance()->checkAdminPermissions();

		if (!isset($_POST['termin_id'])) {
			wp_send_json_error('Nieprawidłowe dane.');
			return;
		}

		global $wpdb;
		$tabela = $wpdb->prefix . 'srl_terminy';
		$termin_id = intval($_POST['termin_id']);
		$data = sanitize_text_field($_POST['data']);
		$godzStart = sanitize_text_field($_POST['godzina_start']) . ':00';
		$godzKoniec = sanitize_text_field($_POST['godzina_koniec']) . ':00';

		// POBIERZ AKTUALNE DANE SLOTU
		$aktualny_slot = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $tabela WHERE id = %d",
			$termin_id
		), ARRAY_A);

		if (!$aktualny_slot) {
			wp_send_json_error('Slot nie istnieje.');
			return;
		}

		// ZACHOWAJ ISTNIEJĄCY STATUS I KLIENTA
		$dane = array(
			'godzina_start' => $godzStart,
			'godzina_koniec' => $godzKoniec
			// NIE ZMIENIAJ status ani klient_id
		);

		$wynik = $wpdb->update(
			$tabela,
			$dane,
			array('id' => $termin_id),
			array('%s','%s'),
			array('%d')
		);

		if ($wynik === false) {
			wp_send_json_error('Błąd aktualizacji w bazie: ' . $wpdb->last_error);
			return;
		}

		$godziny_wg_pilota = SRL_Database_Helpers::getInstance()->getDayScheduleOptimized($data);
		wp_send_json_success(array(
			'message' => 'Godziny zostały zaktualizowane.',
			'godziny_wg_pilota' => $godziny_wg_pilota
		));
	}
	

    public function ajaxUsunGodzine() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        if (!isset($_POST['termin_id'])) {
            wp_send_json_error('Nieprawidłowe dane.');
            return;
        }

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';
        $termin_id = intval($_POST['termin_id']);

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT data, status, klient_id FROM $tabela WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje.');
            return;
        }

        if ($slot['status'] === 'Zarezerwowany' && intval($slot['klient_id']) > 0) {
            wp_send_json_error('Nie można usunąć zarezerwowanego slotu. Najpierw wypisz klienta.');
            return;
        }

        $data = $slot['data'];

        $usun = $wpdb->delete(
            $tabela, 
            array('id' => $termin_id), 
            array('%d')
        );

        if ($usun === false) {
            wp_send_json_error('Błąd usuwania z bazy: ' . $wpdb->last_error);
            return;
        }

        if ($usun === 0) {
            wp_send_json_error('Slot nie został usunięty (może już nie istnieje).');
            return;
        }

        SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($data);
    }

    public function ajaxZmienStatusGodziny() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        if (!isset($_POST['termin_id']) || !isset($_POST['status'])) {
            wp_send_json_error('Nieprawidłowe dane.');
            return;
        }

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $termin_id = intval($_POST['termin_id']);
        $status = sanitize_text_field($_POST['status']);
        $klient_id = isset($_POST['klient_id']) ? intval($_POST['klient_id']) : 0;

        if ($status === 'Odwołany przez organizatora') {
            $this->ajaxAnulujLotPrzezOrganizatora();
            return;
        }

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje.');
            return;
        }

        $dozwolone_statusy = ['Wolny', 'Prywatny', 'Zarezerwowany', 'Zrealizowany', 'Odwołany przez organizatora'];
        if (!in_array($status, $dozwolone_statusy)) {
            wp_send_json_error('Nieprawidłowy status.');
            return;
        }

        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_loty WHERE termin_id = %d",
            $termin_id
        ), ARRAY_A);

        $wpdb->query('START TRANSACTION');

        try {
            $dane_slotu = array(
                'status' => $status,
                'klient_id' => $klient_id ? $klient_id : null
            );

            if ($status === 'Wolny') {
                $dane_slotu['notatka'] = null;
            }

            $wynik = $wpdb->update(
                $tabela_terminy,
                $dane_slotu,
                array('id' => $termin_id),
                array('%s','%d'),
                array('%d')
            );

            if ($wynik === false) {
                throw new Exception('Błąd aktualizacji statusu slotu');
            }

            if ($lot) {
                $nowy_status_lotu = '';
                $dane_lotu_update = array();
                $stary_status = $lot['status'];

                switch ($status) {
                    case 'Wolny':
                        $nowy_status_lotu = 'wolny';
                        $dane_lotu_update = array(
                            'status' => $nowy_status_lotu,
                            'termin_id' => null,
                            'data_rezerwacji' => null
                        );
                        break;

                    case 'Zarezerwowany':
                        $nowy_status_lotu = 'zarezerwowany';
                        $dane_lotu_update = array('status' => $nowy_status_lotu);
                        break;

                    case 'Zrealizowany':
                        $nowy_status_lotu = 'zrealizowany';
                        $dane_lotu_update = array('status' => $nowy_status_lotu);
                        break;

                    case 'Prywatny':
                        break;

                    case 'Odwołany przez organizatora':
                        $nowy_status_lotu = 'wolny';
                        $dane_lotu_update = array(
                            'status' => $nowy_status_lotu,
                            'termin_id' => null,
                            'data_rezerwacji' => null
                        );
                        break;
                }

                if (!empty($dane_lotu_update)) {
                    $update_result = $wpdb->update(
                        $tabela_loty,
                        $dane_lotu_update,
                        array('id' => $lot['id']),
                        array_fill(0, count($dane_lotu_update), '%s'),
                        array('%d')
                    );

                    if ($update_result === false) {
                        throw new Exception('Błąd aktualizacji statusu lotu');
                    }
                }

                if (!empty($nowy_status_lotu) && $stary_status !== $nowy_status_lotu) {
                    $szczegoly = array(
                        'termin_id' => $termin_id,
                        'stary_status_slotu' => $slot['status'],
                        'nowy_status_slotu' => $status,
                        'stary_status' => $stary_status,
                        'nowy_status' => $nowy_status_lotu,
                        'zmiana_przez_admin' => true
                    );

                    $wpis_historii = array(
                        'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                        'typ' => 'zmiana_statusu_admin',
                        'executor' => 'Admin',
                        'szczegoly' => $szczegoly
                    );

                    SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], $wpis_historii);
                }
            }

            $wpdb->query('COMMIT');
            SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($slot['data']);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxAnulujLotPrzezOrganizatora() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        if (!isset($_POST['termin_id'])) {
            wp_send_json_error('Nieprawidłowe dane.');
            return;
        }

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $termin_id = intval($_POST['termin_id']);

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_terminy WHERE id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje.');
            return;
        }

        $data = $slot['data'];
        $klient_id = intval($slot['klient_id']);

        $lot = null;
        if ($klient_id > 0) {
            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_loty WHERE termin_id = %d AND user_id = %d",
                $termin_id, $klient_id
            ), ARRAY_A);
        }

        $wpdb->query('START TRANSACTION');

        try {
            $dane_historyczne = array(
                'typ' => 'odwolany_przez_organizatora',
                'data_odwolania' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'oryginalny_status' => $slot['status'],
                'klient_id' => $klient_id
            );

            if ($klient_id > 0) {
                $user = get_userdata($klient_id);
                if ($user) {
                    $dane_historyczne['klient_email'] = $user->user_email;
                    $dane_historyczne['klient_nazwa'] = $user->display_name;

                    $imie = get_user_meta($klient_id, 'srl_imie', true);
                    $nazwisko = get_user_meta($klient_id, 'srl_nazwisko', true);
                    if ($imie && $nazwisko) {
                        $dane_historyczne['klient_nazwa'] = $imie . ' ' . $nazwisko;
                    }

                    $dane_historyczne['telefon'] = get_user_meta($klient_id, 'srl_telefon', true);
                    $dane_historyczne['rok_urodzenia'] = get_user_meta($klient_id, 'srl_rok_urodzenia', true);
                    $dane_historyczne['kategoria_wagowa'] = get_user_meta($klient_id, 'srl_kategoria_wagowa', true);
                    $dane_historyczne['sprawnosc_fizyczna'] = get_user_meta($klient_id, 'srl_sprawnosc_fizyczna', true);
                    $dane_historyczne['uwagi'] = get_user_meta($klient_id, 'srl_uwagi', true);
                }

                if ($lot) {
                    $dane_historyczne['lot_id'] = $lot['id'];
                    $dane_historyczne['order_id'] = $lot['order_id'];
                    $dane_historyczne['nazwa_produktu'] = $lot['nazwa_produktu'];
                    $dane_historyczne['data_rezerwacji'] = $lot['data_rezerwacji'];
                    $dane_historyczne['dane_pasazera'] = $lot['dane_pasazera'];
                }
            }

            $result = $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Odwołany przez organizatora',
                    'notatka' => json_encode($dane_historyczne)
                ),
                array('id' => $termin_id),
                array('%s', '%s'),
                array('%d')
            );

            if ($result === false) {
                throw new Exception('Błąd aktualizacji slotu.');
            }

            if ($lot) {
                $stary_status = $lot['status'];
                $szczegoly_terminu = $slot['data'] . ' ' . substr($slot['godzina_start'], 0, 5) . '-' . substr($slot['godzina_koniec'], 0, 5);

                $wpdb->update(
                    $tabela_loty,
                    array(
                        'status' => 'wolny', 
                        'termin_id' => null, 
                        'data_rezerwacji' => null
                    ),
                    array('id' => $lot['id']),
                    array('%s', '%d', '%s'),
                    array('%d')
                );

                $user = get_userdata($klient_id);
                if ($user) {
                    $to = $user->user_email;
                    $subject = 'Twój lot tandemowy został odwołany przez organizatora';
                    $body = "Dzień dobry {$user->display_name},\n\n"
                         . "Niestety Twój lot, który był zaplanowany na {$szczegoly_terminu}, został odwołany przez organizatora z powodów niezależnych od nas (prawdopodobnie warunki pogodowe).\n\n"
                         . "Status Twojego lotu został przywrócony – możesz ponownie wybrać inny termin.\n"
                         . "Przepraszamy za niedogodności.\n\n"
                         . "Pozdrawiamy,\nZespół Loty Tandemowe";
                    wp_mail($to, $subject, $body);

                    $wpis_historii = array(
                        'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                        'typ' => 'odwolanie_przez_organizatora',
                        'executor' => 'Admin',
                        'szczegoly' => array(
                            'termin_id' => $termin_id,
                            'odwolany_termin' => $szczegoly_terminu,
                            'stary_status' => $stary_status,
                            'nowy_status' => 'wolny',
                            'klient_id' => $klient_id,
                            'email_wyslany' => true,
                            'slot_zachowany' => true
                        )
                    );

                    SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], $wpis_historii);
                }
            }

            $wpdb->query('COMMIT');
            SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($data);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Błąd podczas odwoływania: ' . $e->getMessage());
        }
    }

    public function ajaxZrealizujLot() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_terminy WHERE id = %d",
                $termin_id
            ), ARRAY_A);

            if (!$slot || $slot['status'] !== 'Zarezerwowany') {
                throw new Exception('Slot nie istnieje lub nie jest zarezerwowany.');
            }

            $wpdb->update(
                $tabela_terminy,
                array('status' => 'Zrealizowany'),
                array('id' => $termin_id),
                array('%s'),
                array('%d')
            );

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_loty WHERE termin_id = %d",
                $termin_id
            ), ARRAY_A);

            if ($lot) {
                $wpdb->update(
                    $tabela_loty,
                    array('status' => 'zrealizowany'),
                    array('id' => $lot['id']),
                    array('%s'),
                    array('%d')
                );

                $wpis_historii = array(
                    'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'typ' => 'realizacja_admin',
                    'executor' => 'Admin',
                    'szczegoly' => array(
                        'termin_id' => $termin_id,
                        'stary_status' => 'zarezerwowany',
                        'nowy_status' => 'zrealizowany',
                        'lot_id' => $lot['id']
                    )
                );

                SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], $wpis_historii);
            }

            $wpdb->query('COMMIT');

            $data = $slot['data'];
            SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($data);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxPrzypiszWykupionyLot() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);
        $lot_id = intval($_POST['lot_id']);

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
                $termin_id
            ), ARRAY_A);

            if (!$slot) {
                throw new Exception('Slot nie jest dostępny.');
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_loty WHERE id = %d AND status = 'wolny'",
                $lot_id
            ), ARRAY_A);

            if (!$lot) {
                throw new Exception('Lot nie jest dostępny.');
            }

            $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Zarezerwowany',
                    'klient_id' => $lot['user_id']
                ),
                array('id' => $termin_id),
                array('%s', '%d'),
                array('%d')
            );

            $wpdb->update(
                $tabela_loty,
                array(
                    'status' => 'zarezerwowany',
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'termin_id' => $termin_id
                ),
                array('id' => $lot_id),
                array('%s', '%s', '%d'),
                array('%d')
            );

            $termin = $wpdb->get_row($wpdb->prepare(
                "SELECT data, godzina_start, godzina_koniec, pilot_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ), ARRAY_A);

            $termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                $termin['data'], 
                substr($termin['godzina_start'], 0, 5), 
                substr($termin['godzina_koniec'], 0, 5),
                $termin['pilot_id']
            );

            $wpis_historii = array(
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'przypisanie_admin',  
                'executor' => 'Admin',
                'szczegoly' => array(
                    'termin_id' => $termin_id,
                    'termin' => $termin_opis,
                    'data_lotu' => $termin['data'],
                    'godzina_start' => $termin['godzina_start'],
                    'godzina_koniec' => $termin['godzina_koniec'],
                    'pilot_id' => $termin['pilot_id'],
                    'user_id' => $lot['user_id'],
                    'stary_status' => 'wolny',
                    'nowy_status' => 'zarezerwowany'
                )
            );

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);

            $wpdb->query('COMMIT');

            $slot_date = $wpdb->get_var($wpdb->prepare(
                "SELECT data FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ));
            if ($slot_date) {
                SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($slot_date);
            } else {
                wp_send_json_success('Lot został przypisany.');
            }

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxWyszukajKlientowLoty() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true); 
        SRL_Helpers::getInstance()->checkAdminPermissions(); 

        if (!isset($_GET['q'])) {
            wp_send_json_error('Brak frazy wyszukiwania.');
            return;
        }

        $search = sanitize_text_field($_GET['q']);

        if (strlen($search) < 2) {
            wp_send_json_success(array());
            return;
        }

        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT u.ID, u.display_name, u.user_login 
             FROM {$wpdb->users} u
             INNER JOIN $tabela_loty zl ON u.ID = zl.user_id
             WHERE zl.status = 'wolny' 
             AND zl.data_waznosci >= CURDATE()
             AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)
             ORDER BY u.display_name
             LIMIT 10",
            '%' . $search . '%',
            '%' . $search . '%', 
            '%' . $search . '%'
        ));

        $wynik = array();
        foreach ($results as $user) {
            $wynik[] = array(
                'id' => $user->ID,
				'nazwa' => $user->display_name . ' (' . $user->user_login . ')'
            );
        }

        wp_send_json_success($wynik);
    }

    public function ajaxDodajVoucherRecznie() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        if (!function_exists('srl_voucher_table_exists')) {
            global $wpdb;
            $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela'") == $tabela;
            if (!$table_exists) {
                wp_send_json_error('Tabela voucherów nie istnieje.');
                return;
            }
        }
        
        $kod_vouchera = strtoupper(sanitize_text_field($_POST['kod_vouchera']));
        $data_waznosci = sanitize_text_field($_POST['data_waznosci']);
        $nazwa_produktu = sanitize_text_field($_POST['nazwa_produktu']) ?: 'Voucher na lot tandemowy';
        $buyer_imie = sanitize_text_field($_POST['buyer_imie']);
        $buyer_nazwisko = sanitize_text_field($_POST['buyer_nazwisko']);
        $ma_filmowanie = intval($_POST['ma_filmowanie']) ? 1 : 0;
        $ma_akrobacje = intval($_POST['ma_akrobacje']) ? 1 : 0;
        
        $validation_kod = SRL_Helpers::getInstance()->walidujKodVouchera($kod_vouchera);
        if (!$validation_kod['valid']) {
            wp_send_json_error($validation_kod['message']);
        }
        
        $validation_data = SRL_Helpers::getInstance()->walidujDate($data_waznosci);
        if (!$validation_data['valid']) {
            wp_send_json_error('Nieprawidłowa data ważności: ' . $validation_data['message']);
        }
        
        if (SRL_Helpers::getInstance()->isDatePast($data_waznosci)) {
            wp_send_json_error('Data ważności nie może być z przeszłości.');
        }
        
        if (empty($buyer_imie) || empty($buyer_nazwisko)) {
            wp_send_json_error('Imię i nazwisko kupującego są wymagane.');
        }
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
        
        $existing = SRL_Database_Helpers::getInstance()->executeQuery(
            "SELECT COUNT(*) FROM $tabela WHERE kod_vouchera = %s",
            array($validation_kod['kod']),
            'count'
        );
        
        if ($existing > 0) {
            wp_send_json_error('Voucher z tym kodem już istnieje.');
        }
        
        $current_user = wp_get_current_user();
        $data_zakupu = current_time('mysql');
        
        $nazwa_produktu = sanitize_text_field($_POST['nazwa_produktu'] ?? 'Voucher dodany ręcznie');
        $ma_filmowanie = isset($_POST['ma_filmowanie']) ? 1 : 0;
        $ma_akrobacje = isset($_POST['ma_akrobacje']) ? 1 : 0;

        $result = $wpdb->insert(
            $tabela,
            array(
                'order_item_id' => 0,
                'order_id' => 0,
                'buyer_user_id' => $current_user->ID,
                'buyer_imie' => $current_user->first_name ?: 'Admin',
                'buyer_nazwisko' => $current_user->last_name ?: 'Manual',
                'nazwa_produktu' => $nazwa_produktu,
                'kod_vouchera' => $validation_kod['kod'],
                'status' => 'do_wykorzystania',
                'data_zakupu' => $data_zakupu,
                'data_waznosci' => $data_waznosci,
                'ma_filmowanie' => $ma_filmowanie,
                'ma_akrobacje' => $ma_akrobacje
            ),
            array('%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d')
        );
        
        if ($result !== false) {
            $opcje_text = '';
            if ($ma_filmowanie || $ma_akrobacje) {
                $opcje = array();
                if ($ma_filmowanie) $opcje[] = 'filmowanie';
                if ($ma_akrobacje) $opcje[] = 'akrobacje';
                $opcje_text = ' z opcjami: ' . implode(', ', $opcje);
            }
            
            wp_send_json_success('Voucher został dodany pomyślnie' . $opcje_text . '.');
        } else {
            wp_send_json_error('Błąd podczas dodawania vouchera do bazy danych.');
        }
    }

    public function ajaxWyszukajDostepnychKlientow() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true); 
        SRL_Helpers::getInstance()->checkAdminPermissions(); 

        $query = sanitize_text_field($_POST['query']);

        if (strlen($query) < 2) {
            wp_send_json_success(array());
            return;
        }

        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.id as lot_id, zl.nazwa_produktu, zl.user_id,
                    u.user_email, u.display_name,
                    CONCAT(u.display_name, ' (', u.user_email, ')') as nazwa
             FROM $tabela_loty zl
             INNER JOIN {$wpdb->users} u ON zl.user_id = u.ID
             WHERE zl.status = 'wolny' 
             AND zl.data_waznosci >= CURDATE()
             AND (u.user_email LIKE %s 
                  OR u.display_name LIKE %s 
                  OR zl.id LIKE %s
                  OR get_user_meta(u.ID, 'srl_telefon', true) LIKE %s)
             ORDER BY u.display_name
             LIMIT 20",
            '%' . $query . '%',
            '%' . $query . '%',
            '%' . $query . '%',
            '%' . $query . '%'
        ), ARRAY_A);

        $wynik = array();
        foreach ($results as $row) {
            $telefon = get_user_meta($row['user_id'], 'srl_telefon', true);
            $wynik[] = array(
                'lot_id' => $row['lot_id'],
                'user_id' => $row['user_id'],
                'nazwa' => $row['display_name'] . ' (' . $row['user_email'] . ')' . ($telefon ? ' - ' . $telefon : ''),
                'produkt' => $row['nazwa_produktu']
            );
        }

        wp_send_json_success($wynik);
    }

    public function ajaxPrzypiszKlientaDoSlotu() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);
        $lot_id = intval($_POST['lot_id']);

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje lub nie jest dostępny.');
            return;
        }

        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_loty WHERE id = %d AND status = 'wolny'",
            $lot_id
        ), ARRAY_A);

        if (!$lot) {
            wp_send_json_error('Lot nie istnieje lub nie jest dostępny.');
            return;
        }

        $wpdb->query('START TRANSACTION');

        try {
            $update_slot = $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Zarezerwowany',
                    'klient_id' => $lot['user_id']
                ),
                array('id' => $termin_id),
                array('%s', '%d'),
                array('%d')
            );

            if ($update_slot === false) {
                throw new Exception('Błąd aktualizacji slotu.');
            }

            $update_lot = $wpdb->update(
                $tabela_loty,
                array(
                    'status' => 'zarezerwowany',
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'termin_id' => $termin_id
                ),
                array('id' => $lot_id),
                array('%s', '%s', '%d'),
                array('%d')
            );

            if ($update_lot === false) {
                throw new Exception('Błąd aktualizacji lotu.');
            }

            $termin = $wpdb->get_row($wpdb->prepare(
                "SELECT data, godzina_start, godzina_koniec, pilot_id FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ), ARRAY_A);

            $termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                $termin['data'], 
                substr($termin['godzina_start'], 0, 5), 
                substr($termin['godzina_koniec'], 0, 5),
                $termin['pilot_id']
            );

            $wpis_historii = array(
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'przypisanie_admin',  
                'executor' => 'Admin',
                'szczegoly' => array(
                    'termin_id' => $termin_id,
                    'termin' => $termin_opis,
                    'data_lotu' => $termin['data'],
                    'godzina_start' => $termin['godzina_start'],
                    'godzina_koniec' => $termin['godzina_koniec'],
                    'pilot_id' => $termin['pilot_id'],
                    'user_id' => $lot['user_id'],
                    'stary_status' => 'wolny',
                    'nowy_status' => 'zarezerwowany'
                )
            );

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);

            $wpdb->query('COMMIT');

            $slot_date = $wpdb->get_var($wpdb->prepare(
                "SELECT data FROM {$wpdb->prefix}srl_terminy WHERE id = %d",
                $termin_id
            ));
            if ($slot_date) {
                SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($slot_date);
            } else {
                wp_send_json_success('Klient został przypisany do slotu.');
            }

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Błąd: ' . $e->getMessage());
        }
    }

    public function ajaxZapiszDanePrywatne() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);
        $imie = sanitize_text_field($_POST['imie']);
        $nazwisko = sanitize_text_field($_POST['nazwisko']);
        $rok_urodzenia = intval($_POST['rok_urodzenia']);
        $telefon = sanitize_text_field($_POST['telefon']);
        $sprawnosc_fizyczna = sanitize_text_field($_POST['sprawnosc_fizyczna']);
        $kategoria_wagowa = sanitize_text_field($_POST['kategoria_wagowa']);
        $uwagi = sanitize_textarea_field($_POST['uwagi']);

        if (empty($imie) || empty($nazwisko) || empty($telefon) || $rok_urodzenia < 1920) {
            wp_send_json_error('Wypełnij wszystkie wymagane pola.');
            return;
        }

        $validation = SRL_Helpers::getInstance()->walidujTelefon($telefon);
        if (!$validation['valid']) {
            wp_send_json_error($validation['message']);
            return;
        }

        $dane_pasazera = array(
            'imie' => $imie,
            'nazwisko' => $nazwisko,
            'rok_urodzenia' => $rok_urodzenia,
            'telefon' => $telefon,
            'sprawnosc_fizyczna' => $sprawnosc_fizyczna,
            'kategoria_wagowa' => $kategoria_wagowa,
            'uwagi' => $uwagi,
            'typ' => 'prywatny'
        );

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $result = $wpdb->update(
            $tabela,
            array(
                'status' => 'Prywatny',
                'notatka' => json_encode($dane_pasazera)
            ),
            array('id' => $termin_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            wp_send_json_success('Dane zostały zapisane.');
        } else {
            wp_send_json_error('Błąd zapisu do bazy danych.');
        }
    }

    public function ajaxPobierzDanePrywatne() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $notatka = $wpdb->get_var($wpdb->prepare(
            "SELECT notatka FROM $tabela WHERE id = %d",
            $termin_id
        ));

        if ($notatka) {
            $dane = json_decode($notatka, true);
            if ($dane && is_array($dane)) {
                wp_send_json_success($dane);
            } else {
                wp_send_json_error('Nieprawidłowe dane.');
            }
        } else {
            wp_send_json_error('Brak danych.');
        }
    }

    public function ajaxPobierzAktualneGodziny() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true); 
        SRL_Helpers::getInstance()->checkAdminPermissions(); 

        $data = sanitize_text_field($_GET['data'] ?? $_POST['data']);
        if (!$data) {
            wp_send_json_error('Brak daty.');
        }

        SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($data);
    }

    public function ajaxWyszukajWolneLoty() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true); 
        SRL_Helpers::getInstance()->checkAdminPermissions(); 

        $search_field = sanitize_text_field($_POST['search_field']);
        $query = sanitize_text_field($_POST['query']);

        if (strlen($query) < 2) {
            wp_send_json_success(array());
            return;
        }

        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $where_conditions = array("zl.status = 'wolny'", "zl.data_waznosci >= CURDATE()");
        $where_params = array();

        switch ($search_field) {
            case 'id_lotu':
                $where_conditions[] = "zl.id = %s";
                $where_params[] = $query;
                break;
            case 'id_zamowienia':
                $where_conditions[] = "zl.order_id = %s";
                $where_params[] = $query;
                break;
            case 'email':
                $where_conditions[] = "u.user_email LIKE %s";
                $where_params[] = '%' . $query . '%';
                break;
            case 'telefon':
                $where_conditions[] = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s)";
                $where_params[] = '%' . $query . '%';
                break;
            case 'imie_nazwisko':
                $where_conditions[] = "(zl.imie LIKE %s OR zl.nazwisko LIKE %s OR CONCAT(zl.imie, ' ', zl.nazwisko) LIKE %s)";
                $search_param = '%' . $query . '%';
                $where_params = array_merge($where_params, [$search_param, $search_param, $search_param]);
                break;
            case 'login':
                $where_conditions[] = "u.user_login LIKE %s";
                $where_params[] = '%' . $query . '%';
                break;
            default:
                $where_conditions[] = "(zl.id LIKE %s OR zl.order_id LIKE %s OR zl.imie LIKE %s OR zl.nazwisko LIKE %s OR zl.nazwa_produktu LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s))";
                $search_param = '%' . $query . '%';
                $where_params = array_merge($where_params, [$query, $query, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
                break;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT zl.id as lot_id, zl.order_id, zl.user_id, zl.imie, zl.nazwisko, 
                    CONCAT(zl.imie, ' ', zl.nazwisko) as klient_nazwa,
                    u.user_email as email,
                    (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = zl.user_id AND meta_key = 'srl_telefon') as telefon
             FROM $tabela_loty zl
             INNER JOIN {$wpdb->users} u ON zl.user_id = u.ID
             $where_clause
             ORDER BY zl.data_zakupu DESC
             LIMIT 20",
            ...$where_params
        ), ARRAY_A);

        wp_send_json_success($results);
    }

    public function ajaxZapiszLotPrywatny() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true); 
        SRL_Helpers::getInstance()->checkAdminPermissions(); 

        $termin_id = intval($_POST['termin_id']);
        $imie = sanitize_text_field($_POST['imie']);
        $nazwisko = sanitize_text_field($_POST['nazwisko']);
        $rok_urodzenia = intval($_POST['rok_urodzenia']);
        $telefon = sanitize_text_field($_POST['telefon']);
        $sprawnosc_fizyczna = sanitize_text_field($_POST['sprawnosc_fizyczna']);
        $kategoria_wagowa = sanitize_text_field($_POST['kategoria_wagowa']);
        $uwagi = sanitize_textarea_field($_POST['uwagi']);

        if (empty($imie) || empty($nazwisko) || empty($telefon) || $rok_urodzenia < 1920) {
            wp_send_json_error('Wypełnij wszystkie wymagane pola.');
            return;
        }

        $validation = SRL_Helpers::getInstance()->walidujTelefon($telefon);
        if (!$validation['valid']) {
            wp_send_json_error($validation['message']);
            return;
        }

        $dane_pasazera = array(
            'imie' => $imie,
            'nazwisko' => $nazwisko,
            'rok_urodzenia' => $rok_urodzenia,
            'telefon' => $telefon,
            'sprawnosc_fizyczna' => $sprawnosc_fizyczna,
            'kategoria_wagowa' => $kategoria_wagowa,
            'uwagi' => $uwagi,
            'typ' => 'prywatny'
        );

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $result = $wpdb->update(
            $tabela,
            array(
                'status' => 'Prywatny',
                'notatka' => json_encode($dane_pasazera)
            ),
            array('id' => $termin_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            wp_send_json_success('Lot prywatny został zapisany.');
        } else {
            wp_send_json_error('Błąd zapisu do bazy danych.');
        }
    }

    public function ajaxPobierzHistorieLotu() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $lot_id = intval($_POST['lot_id']);

        if (!$lot_id) {
            wp_send_json_error('Nieprawidłowe ID lotu.');
            return;
        }

        $historia = SRL_Historia_Functions::getInstance()->ajaxPobierzHistorieLotu($lot_id);

        wp_send_json_success($historia);
    }

    public function ajaxPrzywrocRezerwacje() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);

        if (!$termin_id) {
            wp_send_json_error('Nieprawidłowe ID terminu.');
            return;
        }

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $slot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Odwołany przez organizatora'",
            $termin_id
        ), ARRAY_A);

        if (!$slot) {
            wp_send_json_error('Slot nie istnieje lub nie jest odwołany.');
            return;
        }

        $dane_historyczne = json_decode($slot['notatka'], true);
        if (!$dane_historyczne || !isset($dane_historyczne['lot_id'])) {
            wp_send_json_error('Brak danych do przywrócenia rezerwacji.');
            return;
        }

        $lot_id = $dane_historyczne['lot_id'];
        $klient_id = $dane_historyczne['klient_id'];

        $lot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tabela_loty WHERE id = %d AND user_id = %d",
            $lot_id, $klient_id
        ), ARRAY_A);

        if (!$lot) {
            wp_send_json_error('Lot nie istnieje.');
            return;
        }

        if ($lot['status'] !== 'wolny') {
            wp_send_json_error('Lot nie jest dostępny (status: ' . $lot['status'] . '). Może być już zarezerwowany ponownie.');
            return;
        }

        $wpdb->query('START TRANSACTION');

        try {
            $result_slot = $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Zarezerwowany',
                    'notatka' => null 
                ),
                array('id' => $termin_id),
                array('%s', '%s'),
                array('%d')
            );

            if ($result_slot === false) {
                throw new Exception('Błąd przywracania slotu.');
            }

            $result_lot = $wpdb->update(
                $tabela_loty,
                array(
                    'status' => 'zarezerwowany',
                    'termin_id' => $termin_id,
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime()
                ),
                array('id' => $lot_id),
                array('%s', '%d', '%s'),
                array('%d')
            );

            if ($result_lot === false) {
                throw new Exception('Błąd przywracania lotu.');
            }

            $user = get_userdata($klient_id);
            if ($user) {
                $szczegoly_terminu = $slot['data'] . ' ' . substr($slot['godzina_start'], 0, 5) . '-' . substr($slot['godzina_koniec'], 0, 5);

                $to = $user->user_email;
                $subject = 'Twój lot tandemowy został przywrócony';
                $body = "Dzień dobry {$user->display_name},\n\n"
                     . "Mamy dobrą wiadomość! Twój lot na {$szczegoly_terminu} został przywrócony.\n\n"
                     . "Możesz się już cieszyć na nadchodzący lot!\n\n"
                     . "Pozdrawiamy,\nZespół Loty Tandemowe";
                wp_mail($to, $subject, $body);

                $wpis_historii = array(
                    'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                    'opis' => "Rezerwacja przywrócona przez administratora - termin {$szczegoly_terminu}",
                    'typ' => 'przywrocenie_przez_admin',
                    'executor' => 'Admin',
                    'szczegoly' => array(
                        'termin_id' => $termin_id,
                        'przywrocony_termin' => $szczegoly_terminu,
                        'klient_id' => $klient_id,
                        'email_wyslany' => true,
                        'powod' => 'Przywrócenie po odwołaniu przez organizatora'
                    )
                );

                SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot_id, $wpis_historii);
            }

            $wpdb->query('COMMIT');
            SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($slot['data']);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Błąd przywracania: ' . $e->getMessage());
        }
    }

    public function ajaxPobierzDaneOdwolanego() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);

        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_terminy';

        $notatka = $wpdb->get_var($wpdb->prepare(
            "SELECT notatka FROM $tabela WHERE id = %d AND status = 'Odwołany przez organizatora'",
            $termin_id
        ));

        if ($notatka) {
            $dane = json_decode($notatka, true);
            if ($dane && is_array($dane)) {
                wp_send_json_success($dane);
            } else {
                wp_send_json_error('Nieprawidłowe dane.');
            }
        } else {
            wp_send_json_error('Brak danych odwołania.');
        }
    }

    public function ajaxajaxZrealizujLotPrywatny() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_terminy WHERE id = %d",
                $termin_id
            ), ARRAY_A);

            if (!$slot || $slot['status'] !== 'Prywatny') {
                throw new Exception('Slot nie istnieje lub nie jest prywatny.');
            }

            $result = $wpdb->update(
                $tabela_terminy,
                array('status' => 'Zrealizowany'),
                array('id' => $termin_id),
                array('%s'),
                array('%d')
            );

            if ($result === false) {
                throw new Exception('Błąd aktualizacji statusu slotu.');
            }

            $data = $slot['data'];
            SRL_Database_Helpers::getInstance()->zwrocGodzinyWgPilota($data);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajaxPobierzDostepneTerminyDoZmiany() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $termin_id = intval($_POST['termin_id']);

        global $wpdb;
$tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $aktualny_termin = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, zl.id as lot_id, zl.user_id, zl.imie, zl.nazwisko
             FROM $tabela_terminy t
             LEFT JOIN $tabela_loty zl ON t.id = zl.termin_id
             WHERE t.id = %d",
            $termin_id
        ), ARRAY_A);

        if (!$aktualny_termin) {
            wp_send_json_error('Nie znaleziono terminu.');
            return;
        }

        if ($aktualny_termin['status'] !== 'Zarezerwowany') {
            wp_send_json_error('Można zmieniać tylko zarezerwowane terminy.');
            return;
        }

        $data_od = date('Y-m-d');
        $data_do = date('Y-m-d', strtotime('+90 days'));

        $dostepne_terminy = $wpdb->get_results($wpdb->prepare(
            "SELECT id, data, pilot_id, godzina_start, godzina_koniec,
                    TIMESTAMPDIFF(MINUTE, godzina_start, godzina_koniec) as czas_trwania
             FROM $tabela_terminy 
             WHERE status = 'Wolny' 
             AND data BETWEEN %s AND %s
             AND data >= CURDATE()
             AND id != %d
             ORDER BY data ASC, godzina_start ASC",
            $data_od, $data_do, $termin_id
        ), ARRAY_A);

        $dostepne_dni = array();
        foreach ($dostepne_terminy as $termin) {
            $data = $termin['data'];
            if (!isset($dostepne_dni[$data])) {
                $dostepne_dni[$data] = array();
            }
            $dostepne_dni[$data][] = $termin;
        }

        $klient_nazwa = '';
        if ($aktualny_termin['imie'] && $aktualny_termin['nazwisko']) {
            $klient_nazwa = $aktualny_termin['imie'] . ' ' . $aktualny_termin['nazwisko'];
        } elseif ($aktualny_termin['user_id']) {
            $user = get_userdata($aktualny_termin['user_id']);
            if ($user) {
                $klient_nazwa = $user->display_name;
            }
        }

        wp_send_json_success(array(
            'dostepne_dni' => $dostepne_dni,
            'aktualny_termin' => array(
                'id' => $aktualny_termin['id'],
                'data' => $aktualny_termin['data'],
                'godzina_start' => $aktualny_termin['godzina_start'],
                'godzina_koniec' => $aktualny_termin['godzina_koniec'],
                'pilot_id' => $aktualny_termin['pilot_id'],
                'klient_nazwa' => $klient_nazwa,
                'lot_id' => $aktualny_termin['lot_id']
            )
        ));
    }

    public function ajaxZmienTerminLotu() {
        check_ajax_referer('srl_admin_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $stary_termin_id = intval($_POST['stary_termin_id']);
        $nowy_termin_id = intval($_POST['nowy_termin_id']);

        if (!$stary_termin_id || !$nowy_termin_id) {
            wp_send_json_error('Nieprawidłowe parametry.');
            return;
        }

        global $wpdb;
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

        $wpdb->query('START TRANSACTION');

        try {
            $stary_termin = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Zarezerwowany'",
                $stary_termin_id
            ), ARRAY_A);

            if (!$stary_termin) {
                throw new Exception('Stary termin nie istnieje lub nie jest zarezerwowany.');
            }

            $nowy_termin = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_terminy WHERE id = %d AND status = 'Wolny'",
                $nowy_termin_id
            ), ARRAY_A);

            if (!$nowy_termin) {
                throw new Exception('Nowy termin nie istnieje lub nie jest dostępny.');
            }

            $nowy_datetime = $nowy_termin['data'] . ' ' . $nowy_termin['godzina_start'];
            if (strtotime($nowy_datetime) <= time()) {
                throw new Exception('Nowy termin musi być w przyszłości.');
            }

            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $tabela_loty WHERE termin_id = %d",
                $stary_termin_id
            ), ARRAY_A);

            if (!$lot) {
                throw new Exception('Nie znaleziono lotu przypisanego do tego terminu.');
            }

            $result1 = $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Wolny',
                    'klient_id' => null
                ),
                array('id' => $stary_termin_id),
                array('%s', '%d'),
                array('%d')
            );

            if ($result1 === false) {
                throw new Exception('Błąd zwalniania starego terminu.');
            }

            $result2 = $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Zarezerwowany',
                    'klient_id' => $stary_termin['klient_id']
                ),
                array('id' => $nowy_termin_id),
                array('%s', '%d'),
                array('%d')
            );

            if ($result2 === false) {
                throw new Exception('Błąd rezerwacji nowego terminu.');
            }

            $result3 = $wpdb->update(
                $tabela_loty,
                array(
                    'termin_id' => $nowy_termin_id,
                    'data_rezerwacji' => SRL_Helpers::getInstance()->getCurrentDatetime()
                ),
                array('id' => $lot['id']),
                array('%d', '%s'),
                array('%d')
            );

            if ($result3 === false) {
                throw new Exception('Błąd aktualizacji lotu.');
            }

            $stary_termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                $stary_termin['data'],
                substr($stary_termin['godzina_start'], 0, 5),
                substr($stary_termin['godzina_koniec'], 0, 5),
                $stary_termin['pilot_id']
            );

            $nowy_termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                $nowy_termin['data'],
                substr($nowy_termin['godzina_start'], 0, 5),
                substr($nowy_termin['godzina_koniec'], 0, 5),
                $nowy_termin['pilot_id']
            );

            $wpis_historii = array(
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'zmiana_terminu_admin',
                'executor' => 'Admin',
                'szczegoly' => array(
                    'stary_termin_id' => $stary_termin_id,
                    'nowy_termin_id' => $nowy_termin_id,
                    'stary_termin' => $stary_termin_opis,
                    'nowy_termin' => $nowy_termin_opis,
                    'powod' => 'Zmiana terminu przez administratora',
                    'user_id' => $lot['user_id']
                )
            );

            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($lot['id'], $wpis_historii);

            if ($lot['user_id']) {
                $user = get_userdata($lot['user_id']);
                if ($user) {
                    $subject = 'Zmiana terminu Twojego lotu tandemowego';
                    $message = "Dzień dobry {$user->display_name},\n\n";
                    $message .= "Informujemy o zmianie terminu Twojego lotu tandemowego.\n\n";
                    $message .= "Poprzedni termin: {$stary_termin_opis}\n";
                    $message .= "Nowy termin: {$nowy_termin_opis}\n\n";
                    $message .= "Pamiętaj:\n";
                    $message .= "- Zgłoś się 30 minut przed godziną lotu\n";
                    $message .= "- Weź ze sobą dokument tożsamości\n\n";
                    $message .= "W razie pytań, skontaktuj się z nami.\n\n";
                    $message .= "Pozdrawiamy,\nZespół Lotów Tandemowych";

                    wp_mail($user->user_email, $subject, $message);
                }
            }

            $wpdb->query('COMMIT');

            wp_send_json_success(array(
                'message' => 'Termin został pomyślnie zmieniony.',
                'stary_termin' => $stary_termin_opis,
                'nowy_termin' => $nowy_termin_opis
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }
}