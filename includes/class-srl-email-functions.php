<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Email_Functions {
    
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
    }

	private function getWazneInformacje() {
		return "Ważne informacje:\n- Zgłoś się 30 minut przed godziną lotu\n- Weź ze sobą dokument tożsamości\n- Ubierz się stosownie do warunków pogodowych\n";
	}
	
	private function formatujTerminEmail($data, $godzina_start) {
		$formatted_date = SRL_Helpers::getInstance()->formatujDate($data);
		$godzina = substr($godzina_start, 0, 5);
		return "{$formatted_date} ok. godz. {$godzina}";
	}
	
    public function sendEmail($to, $subject_key, $template_data = array(), $additional_headers = array()) {
        $templates = array(
			'flight_confirmation' => array(
				'subject' => '[ Feel&Fly ] Potwierdzenie rezerwacji lotu tandemowego',
				'body' => "Dzień dobry,\n\nTwoja rezerwacja lotu tandemowego została potwierdzona!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{wazne_informacje}\n\n🧭 Jak dojechać na lotnisko (Borowa k. Oleśnicy):\n• Ustaw nawigację na: Paralotnia Borowa (Google Maps)\n  https://www.google.pl/maps/place/Paralotnia+Borowa/@51.188161,17.2892122,983m/data=!3m2!1e3!4b1!4m6!3m5!1s0x470fe471ef66a043:0x837b884330868469!8m2!3d51.188161!4d17.2892122!16s%2Fg%2F11c58kt999\n\n• Alternatywnie: koniec ul. Akacjowej. Na końcu ulicy skręć w lewo w szutrową drogę, dojedź do skrzyżowania i skręć w prawo, dalej jedź szutrem przy granicy lasu. Na łące kieruj się znakami — parking zmienia się w zależności od wiatru.\n• Zatrzymaj się na parkingu i czekaj na ekipę lotniskową (dowóz na start zapewniamy). Nie wchodź za znak zakazu wejścia.\n\nℹ️ Czas dojazdu autem (orientacyjnie):\n• Wrocław – 20 min\n• Oleśnica – 13 min\n• Trzebnica – 32 min\n• Długołęka – 11 min\n• Kiełczów – 18 min\n\n🚆 Bez auta? Do Borowej dojedziesz pociągiem regio z dworców we Wrocławiu lub Oleśnicy.\n\n✈️ Pamiętaj: latamy na paralotniach — najczęściej widzimy z góry, czy pojawiły się nowe auta na parkingu 🙂\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_reschedule' => array(
				'subject' => '[ Feel&Fly ] Zmiana terminu Twojego lotu tandemowego',
				'body' => "Dzień dobry,\n\nInformujemy o zmianie terminu Twojego lotu tandemowego:\n\n📅 Poprzedni termin: {stary_termin}\n📅 Nowy termin: {nowy_termin}\n\n{wazne_informacje}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_assignment' => array(
				'subject' => '[ Feel&Fly ] Przypisanie lotu do terminu',
				'body' => "Dzień dobry,\n\nTwój lot tandemowy został przypisany do terminu!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{wazne_informacje}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'cancellation' => array(
				'subject' => '[ Feel&Fly ] Anulowanie rezerwacji lotu tandemowego',
				'body' => "Dzień dobry,\n\nInformujemy, że Twoja rezerwacja lotu tandemowego została anulowana.\n\nSzczegóły anulowanej rezerwacji:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{powod_text}Twój lot został przywrócony do stanu dostępnego - możesz ponownie dokonać rezerwacji w dogodnym dla Ciebie terminie.\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'voucher' => array(
				'subject' => '[ Feel&Fly ] Voucher upominkowy na lot tandemowy',
				'body' => "Dzień dobry {nazwa_odbiorcy},\n\nOtrzymujesz voucher upominkowy na lot tandemowy!\n\nSzczegóły vouchera:\n🎫 Kod vouchera: {kod_vouchera}\n📅 Ważny do: {data_waznosci}\n\nJak wykorzystać voucher:\n1. Zarejestruj się na naszej stronie internetowej\n2. Przejdź do sekcji rezerwacji lotów\n3. Wprowadź kod vouchera: {kod_vouchera}\n4. Wybierz dogodny termin lotu\n\nVoucher jest ważny do dnia {data_waznosci}.\n\nW razie pytań, skontaktuj się z nami.\n\nŻyczymy wspaniałych wrażeń!\nZespół Feel&Fly"
			),
			'reminder' => array(
				'subject' => '[ Feel&Fly ] Przypomnienie: Twój lot tandemowy {kiedy}!',
				'body' => "Dzień dobry,\n\nPrzypominamy o Twoim nadchodzącym locie tandemowym {kiedy}!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{wazne_informacje}\n- W przypadku złej pogody, skontaktujemy się z Tobą\n\nCieszymy się na spotkanie!\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'expiry_warning' => array(
				'subject' => '[ Feel&Fly ] Uwaga: Twój lot tandemowy wygasa {kiedy}!',
				'body' => "Dzień dobry,\n\nPrzypominamy, że Twój lot tandemowy wygasa {kiedy}!\n\nSzczegóły lotu:\n📅 Data ważności: {data_waznosci}\n\nAby nie utracić możliwości skorzystania z lotu, zarezerwuj termin już dziś!\n\nJak dokonać rezerwacji:\n1. Zaloguj się na naszej stronie\n2. Przejdź do sekcji rezerwacji\n3. Wybierz dogodny termin\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiami,\nZespół Feel&Fly"
			),
			'partner_approval' => array(
				'subject' => '[ Feel&Fly ] Voucher partnera został zatwierdzony',
				'body' => "Dzień dobry,\n\nTwój voucher partnera został zatwierdzony!\n\nSzczegóły:\nPartner: {partner_name}\nTyp: {voucher_type_name}\nKod vouchera: {kod_vouchera}\n\nUtworzono {flight_count} lotów dla podanych pasażerów.\nMożesz teraz dokonać rezerwacji terminów na naszej stronie.\n\nLink do rezerwacji: {reservation_link}\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'partner_rejection' => array(
				'subject' => '[ Feel&Fly ] Voucher partnera został odrzucony',
				'body' => "Dzień dobry,\n\nNiestety, Twój voucher partnera {partner_name} został odrzucony.\n\nPowód odrzucenia:\n{reason}\n\nMożesz poprawić dane i ponownie wysłać voucher.\n\nLink do formularza: {form_link}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_restored' => array(
				'subject' => '[ Feel&Fly ] Przywrócenie rezerwacji lotu tandemowego',
				'body' => "Dzień dobry,\n\nTwój lot tandemowy został przywrócony!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{wazne_informacje}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),

			'flight_cancelled_by_organizer' => array(
				'subject' => '[ Feel&Fly ] Odwołanie lotu przez organizatora',
				'body' => "Dzień dobry,\n\nInformujemy, że Twój lot tandemowy został odwołany przez organizatora.\n\nSzczegóły odwołanego lotu:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\nStatus Twojego lotu został przywrócony - możesz ponownie dokonać rezerwacji w dogodnym dla Ciebie terminie.\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'voucher_attachment' => array(
				'subject' => '[ Feel&Fly ] Twój voucher na lot tandemowy',
				'body' => "Dzień dobry {nazwa_odbiorcy},\n\nW załączniku przesyłamy Twój voucher na lot tandemowy!\n\nSzczegóły vouchera:\n🎫 Kod vouchera: {kod_vouchera}\n📅 Ważny do: {data_waznosci}\n\nJak wykorzystać voucher:\n1. Zarejestruj się na naszej stronie internetowej\n2. Przejdź do sekcji rezerwacji lotów\n3. Wprowadź kod vouchera: {kod_vouchera}\n4. Wybierz dogodny termin lotu\n\nVoucher jest ważny do dnia {data_waznosci}.\n\nW razie pytań, skontaktuj się z nami.\n\nŻyczymy wspaniałych wrażeń!\nZespół Feel&Fly"
			),
			'admin_notification' => array(
				'subject' => '[ Feel&Fly ] {temat}',
				'body' => "{wiadomosc}\n\n{dane_dodatkowe}Data: {current_datetime}\nSystem Rezerwacji Lotów Feel&Fly"
			)
		);

        if (!isset($templates[$subject_key])) return false;

        $template = $templates[$subject_key];
        $subject = $template['subject'];
        $body = $template['body'];

        foreach ($template_data as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
            $body = str_replace('{' . $key . '}', $value, $body);
        }

        $default_headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
        );
        
        $headers = array_merge($default_headers, $additional_headers);
        $sent = wp_mail($to, $subject, $body, $headers);
        
        if (!$sent) {
            error_log("SRL: Nie udało się wysłać emaila ({$subject_key}) do: {$to}");
        }
        
        return $sent;
    }

	public function wyslijEmailPotwierdzenia($user_id, $slot, $lot) {
		$user = get_userdata($user_id);
		if (!$user) return false;

		return $this->sendEmail($user->user_email, 'flight_confirmation', array(
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($slot['data']),
			'godzina_lotu' => substr($slot['godzina_start'], 0, 5),
			'wazne_informacje' => $this->getWazneInformacje()
		));
	}

	public function wyslijEmailAnulowania($user_id, $slot, $lot, $powod = '') {
		$user = get_userdata($user_id);
		if (!$user) return false;

		return $this->sendEmail($user->user_email, 'cancellation', array(
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($slot['data']),
			'godzina_lotu' => substr($slot['godzina_start'], 0, 5),
			'powod_text' => !empty($powod) ? "Powód anulowania: {$powod}\n\n" : ''
		));
	}

	public function wyslijEmailVoucher($email_odbiorcy, $kod_vouchera, $nazwa_produktu, $data_waznosci, $buyer_name = '') {
		if (!is_email($email_odbiorcy)) return false;

		return $this->sendEmail($email_odbiorcy, 'voucher', array(
			'nazwa_odbiorcy' => !empty($buyer_name) ? $buyer_name : 'Drogi Odbiorco',
			'kod_vouchera' => $kod_vouchera,
			'data_waznosci' => SRL_Helpers::getInstance()->formatujDate($data_waznosci)
		));
	}

	public function wyslijEmailPrzypomnienie($user_id, $slot, $lot, $dni_przed = 1) {
		$user = get_userdata($user_id);
		if (!$user) return false;

		$kiedy = ($dni_przed == 1) ? 'jutro' : "za {$dni_przed} dni";
		
		return $this->sendEmail($user->user_email, 'reminder', array(
			'kiedy' => $kiedy,
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($slot['data']),
			'godzina_lotu' => substr($slot['godzina_start'], 0, 5),
			'wazne_informacje' => $this->getWazneInformacje()
		));
	}

	public function wyslijEmailWygasnieciaLotu($user_id, $lot, $dni_przed = 7) {
		$user = get_userdata($user_id);
		if (!$user) return false;

		$kiedy = ($dni_przed == 1) ? 'jutro' : "za {$dni_przed} dni";
		
		return $this->sendEmail($user->user_email, 'expiry_warning', array(
			'kiedy' => $kiedy,
			'data_waznosci' => SRL_Helpers::getInstance()->formatujDate($lot['data_waznosci'])
		));
	}

	// NOWE METODY - jednolite dla wszystkich typów lotów:

	public function wyslijEmailPotwierdzeniaDlaWszystkichTypowLotow($email, $data_lotu, $godzina_start, $godzina_koniec) {
		if (!is_email($email)) return false;
		
		return $this->sendEmail($email, 'flight_confirmation', array(
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($data_lotu),
			'godzina_lotu' => substr($godzina_start, 0, 5),
			'wazne_informacje' => $this->getWazneInformacje()
		));
	}

	public function wyslijEmailZmianyTerminu($email, $stary_termin_data, $stary_termin_start, $nowy_termin_data, $nowy_termin_start) {
		if (!is_email($email)) return false;
		
		$stary_termin = $this->formatujTerminEmail($stary_termin_data, $stary_termin_start);
		$nowy_termin = $this->formatujTerminEmail($nowy_termin_data, $nowy_termin_start);
		
		return $this->sendEmail($email, 'flight_reschedule', array(
			'stary_termin' => $stary_termin,
			'nowy_termin' => $nowy_termin,
			'wazne_informacje' => $this->getWazneInformacje()
		));
	}

	public function wyslijEmailPrzypisaniaLotu($email, $data_lotu, $godzina_start) {
		if (!is_email($email)) return false;
		
		return $this->sendEmail($email, 'flight_assignment', array(
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($data_lotu),
			'godzina_lotu' => substr($godzina_start, 0, 5),
			'wazne_informacje' => $this->getWazneInformacje()
		));
	}
	
	public function wyslijEmailPrzywroceniaLotu($email, $data_lotu, $godzina_start) {
		if (!is_email($email)) return false;
		
		return $this->sendEmail($email, 'flight_restored', array(
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($data_lotu),
			'godzina_lotu' => substr($godzina_start, 0, 5),
			'wazne_informacje' => $this->getWazneInformacje()
		));
	}

	public function wyslijEmailOdwolaniaPrzezOrganizatora($email, $data_lotu, $godzina_start) {
		if (!is_email($email)) return false;
		
		return $this->sendEmail($email, 'flight_cancelled_by_organizer', array(
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($data_lotu),
			'godzina_lotu' => substr($godzina_start, 0, 5)
		));
	}
	
	public function sendPartnerVoucherApprovalEmail($voucher_id, $created_flights) {
		$voucher = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucher($voucher_id);
		if (!$voucher) return false;
		
		$user = get_userdata($voucher['klient_id']);
		if (!$user) return false;
		
		$config = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherConfig();
		$partner_name = $config[$voucher['partner']]['nazwa'];
		$voucher_type_name = $config[$voucher['partner']]['typy'][$voucher['typ_vouchera']]['nazwa'];
		
		return $this->sendEmail($user->user_email, 'partner_approval', array(
			'partner_name' => $partner_name,
			'voucher_type_name' => $voucher_type_name,
			'kod_vouchera' => $voucher['kod_vouchera'],
			'flight_count' => count($created_flights),
			'reservation_link' => home_url('/rezerwuj-lot/')
		));
	}

	public function sendPartnerVoucherRejectionEmail($voucher_id, $reason) {
		$voucher = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucher($voucher_id);
		if (!$voucher) return false;
		
		$user = get_userdata($voucher['klient_id']);
		if (!$user) return false;
		
		$config = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherConfig();
		$partner_name = $config[$voucher['partner']]['nazwa'];
		
		return $this->sendEmail($user->user_email, 'partner_rejection', array(
			'partner_name' => $partner_name,
			'reason' => $reason,
			'form_link' => home_url('/rezerwuj-lot/')
		));
	}
	
	public function wyslijEmailZVoucherem($email_odbiorcy, $voucher_data, $image_data, $buyer_name = '') {
		if (!is_email($email_odbiorcy)) return false;
		
		// Stwórz tymczasowy plik
		$upload_dir = wp_upload_dir();
		$temp_file = $upload_dir['path'] . '/voucher_' . $voucher_data['kod_vouchera'] . '_' . time() . '.jpg';
		
		// Zapisz dane obrazu do pliku
		if (file_put_contents($temp_file, $image_data) === false) {
			error_log('SRL: Nie można zapisać tymczasowego pliku vouchera: ' . $temp_file);
			return false;
		}
		
		$template_data = array(
			'nazwa_odbiorcy' => !empty($buyer_name) ? $buyer_name : 'Drogi Odbiorco',
			'kod_vouchera' => $voucher_data['kod_vouchera'],
			'data_waznosci' => SRL_Helpers::getInstance()->formatujDate($voucher_data['data_waznosci'])
		);
		
		$additional_headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
		);
		
		// Wyślij email z załącznikiem
		$sent = $this->sendEmailWithAttachment(
			$email_odbiorcy, 
			'voucher_attachment', 
			$template_data, 
			$additional_headers,
			$temp_file,
			'voucher_' . $voucher_data['kod_vouchera'] . '.jpg'
		);
		
		// Usuń tymczasowy plik
		if (file_exists($temp_file)) {
			unlink($temp_file);
		}
		
		return $sent;
	}

	private function sendEmailWithAttachment($to, $subject_key, $template_data, $headers, $attachment_path, $attachment_name) {
		$templates = array(
			'voucher_attachment' => array(
				'subject' => '[ Feel&Fly ] Twój voucher na lot tandemowy',
				'body' => "Dzień dobry {nazwa_odbiorcy},\n\nW załączniku przesyłamy Twój voucher na lot tandemowy!\n\nSzczegóły vouchera:\n🎫 Kod vouchera: {kod_vouchera}\n📅 Ważny do: {data_waznosci}\n\nJak wykorzystać voucher:\n1. Zarejestruj się na naszej stronie internetowej\n2. Przejdź do sekcji rezerwacji lotów\n3. Wprowadź kod vouchera: {kod_vouchera}\n4. Wybierz dogodny termin lotu\n\nVoucher jest ważny do dnia {data_waznosci}.\n\nW razie pytań, skontaktuj się z nami.\n\nŻyczymy wspaniałych wrażeń!\nZespół Feel&Fly"
			)
		);
		
		if (!isset($templates[$subject_key])) return false;
		
		$template = $templates[$subject_key];
		$subject = $template['subject'];
		$body = $template['body'];
		
		foreach ($template_data as $key => $value) {
			$subject = str_replace('{' . $key . '}', $value, $subject);
			$body = str_replace('{' . $key . '}', $value, $body);
		}
		
		// Przygotuj załącznik
		$attachments = array();
		if (file_exists($attachment_path)) {
			$attachments[] = $attachment_path;
		}
		
		$sent = wp_mail($to, $subject, $body, $headers, $attachments);
		
		if (!$sent) {
			error_log("SRL: Nie udało się wysłać emaila z voucherem ({$subject_key}) do: {$to}");
		}
		
		return $sent;
	}
	
}