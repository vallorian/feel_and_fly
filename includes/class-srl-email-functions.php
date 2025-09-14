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
		return "Ważne informacje:\n- Zgłoś się 10 minut przed godziną lotu\n- Weź ze sobą dokument tożsamości\n- Ubierz się stosownie do warunków pogodowych\n";
	}
	
	private function formatujTerminEmail($data, $godzina_start) {
		$formatted_date = SRL_Helpers::getInstance()->formatujDate($data);
		$godzina = substr($godzina_start, 0, 5);
		return "{$formatted_date} ok. godz. {$godzina}";
	}
	
    public function sendEmail($to, $subject_key, $template_data = array(), $additional_headers = array(), $attachments = array()) {
		$templates = array(
			'flight_purchase_welcome' => array(
				'subject' => '[Feel and Fly] Dziękujemy za zakup lotu tandemowego!',
				'body' => "Dzień dobry,\n\nDziękujemy za zakup lotu tandemowego! 🪂\n\nTwój lot został dodany do konta i jest gotowy do zarezerwowania terminu.\n\nSzczegóły zakupu:\n📅 Ważny do: {data_waznosci}\n{opcje_lotu}\n\nJak zarezerwować termin lotu:\n\n1. Wejdź na stronę {link_rezerwacji} i się zaloguj\n2. Podążaj za krokami na stronie:\n   a) Wybierz wykupiony lot i kliknij \"Wybierz termin\". Tutaj również możesz dokupić dodatkowe opcje\n   b) Wpisz swoje dane i zapoznaj się z regulaminem\n   c) Wybierz dzień lotu - tutaj monitorujemy warunki pogodowe i na bieżąco wstawiamy nowe terminy\n   d) Wybierz godzinę lotu z dostępnej puli\n   e) Zweryfikuj poprawność danych i zatwierdź rezerwację\n\nPo dokonaniu rezerwacji otrzymasz kolejny email z szczegółami terminu i instrukcjami dojazdu.\n\n💡 Pamiętaj:\n• Lot jest ważny przez rok od daty zakupu\n• Możesz zmienić termin rezerwacji (z wyprzedzeniem minimum 48h)\n• W razie pytań, jesteśmy do Twojej dyspozycji\n\nCieszymy się, że wybrałeś Feel&Fly! Przygotuj się na niezapomniane wrażenia! 🌤️\n\nPozdrawiamy,\nZespół Feel&Fly\n\nPS: Śledź nas na social mediach, aby być na bieżąco z warunkami lotowymi!"
			),
			'flight_confirmation' => array(
				'subject' => '[Feel and Fly] Potwierdzenie rezerwacji lotu tandemowego',
				'body' => "Dzień dobry,\n\nTwoja rezerwacja lotu tandemowego została potwierdzona!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{wazne_informacje}\n\n{qr_section}\n\n🧭 Jak dojechać na lotnisko (Borowa k. Oleśnicy):\n• Ustaw nawigację na: Paralotnia Borowa (Google Maps)\n  https://www.google.pl/maps/place/Paralotnia+Borowa/@51.188161,17.2892122,983m/data=!3m2!1e3!4b1!4m6!3m5!1s0x470fe471ef66a043:0x837b884330868469!8m2!3d51.188161!4d17.2892122!16s%2Fg%2F11c58kt999\n\n• Alternatywnie: koniec ul. Akacjowej. Na końcu ulicy skręć w lewo w szutrową drogę, dojedź do skrzyżowania i skręć w prawo, dalej jedź szutrem przy granicy lasu. Na łące kieruj się znakami — parking zmienia się w zależności od wiatru.\n• Zatrzymaj się na parkingu i czekaj na ekipę lotniskową (dowóz na start zapewniamy). Nie wchodź za znak zakazu wejścia.\n\nℹ️ Czas dojazdu autem (orientacyjnie):\n• Wrocław – 20 min\n• Oleśnica – 13 min\n• Trzebnica – 32 min\n• Długołęka – 11 min\n• Kiełczów – 18 min\n\n🚆 Bez auta? Do Borowej dojedziesz pociągiem regio z dworców we Wrocławiu lub Oleśnicy.\n\n✈️ Pamiętaj: latamy na paralotniach — najczęściej widzimy z góry, czy pojawiły się nowe auta na parkingu 🙂\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_reschedule' => array(
				'subject' => '[Feel and Fly] Zmiana terminu Twojego lotu tandemowego',
				'body' => "Dzień dobry,\n\nInformujemy o zmianie terminu Twojego lotu tandemowego:\n\n📅 Poprzedni termin: {stary_termin}\n📅 Nowy termin: {nowy_termin}\n\n{wazne_informacje}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_assignment' => array(
				'subject' => '[Feel and Fly] Przypisanie lotu do terminu',
				'body' => "Dzień dobry,\n\nTwój lot tandemowy został przypisany do terminu!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{wazne_informacje}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'cancellation' => array(
				'subject' => '[Feel and Fly] Anulowanie rezerwacji lotu tandemowego',
				'body' => "Dzień dobry,\n\nInformujemy, że Twoja rezerwacja lotu tandemowego została anulowana.\n\nSzczegóły anulowanej rezerwacji:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{powod_text}Twój lot został przywrócony do stanu dostępnego - możesz ponownie dokonać rezerwacji w dogodnym dla Ciebie terminie.\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_cancellation_by_client' => array(
				'subject' => '[Feel and Fly] Potwierdzenie anulowania rezerwacji',
				'body' => "Dzień dobry,\n\nPotwierdzamy anulowanie Twojej rezerwacji lotu tandemowego.\n\nSzczegóły anulowanej rezerwacji:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n✅ Status Twojego lotu:\nLot został przywrócony do stanu dostępnego i możesz ponownie dokonać rezerwacji w dogodnym dla Ciebie terminie.\n\n💡 Pamiętaj:\n• Lot jest ważny do {data_waznosci}\n• Możesz zarezerwować nowy termin w dowolnym momencie\n• Rezerwację można anulować do 48h przed lotem\n\n📋 Jak zarezerwować nowy termin:\n1. Wejdź na stronę {link_rezerwacji} i się zaloguj\n2. Wybierz swój lot i kliknij \"Wybierz termin\"\n3. Postępuj zgodnie z instrukcjami na stronie\n\nW razie pytań, jesteśmy do Twojej dyspozycji.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),

			'reminder' => array(
				'subject' => '[Feel and Fly] Przypomnienie: Twój lot tandemowy {kiedy}!',
				'body' => "Dzień dobry,\n\nPrzypominamy o Twoim nadchodzącym locie tandemowym {kiedy}!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{wazne_informacje}\n- W przypadku złej pogody, skontaktujemy się z Tobą\n\nCieszymy się na spotkanie!\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'expiry_warning' => array(
				'subject' => '[Feel and Fly] Uwaga: Twój lot tandemowy wygasa {kiedy}!',
				'body' => "Dzień dobry,\n\nPrzypominamy, że Twój lot tandemowy wygasa {kiedy}!\n\nSzczegóły lotu:\n📅 Data ważności: {data_waznosci}\n\nAby nie utracić możliwości skorzystania z lotu, zarezerwuj termin już dziś!\n\nJak dokonać rezerwacji:\n1. Zaloguj się na naszej stronie\n2. Przejdź do sekcji rezerwacji\n3. Wybierz dogodny termin\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiami,\nZespół Feel&Fly"
			),
			'partner_approval' => array(
				'subject' => '[Feel and Fly] Voucher partnera został zatwierdzony',
				'body' => "Dzień dobry,\n\nTwój voucher partnera został zatwierdzony!\n\nSzczegóły:\nPartner: {partner_name}\nTyp: {voucher_type_name}\nKod vouchera: {kod_vouchera}\n\nUtworzono {flight_count} lotów dla podanych pasażerów.\nMożesz teraz dokonać rezerwacji terminów na naszej stronie.\n\nLink do rezerwacji: {reservation_link}\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'partner_rejection' => array(
				'subject' => '[Feel and Fly] Voucher partnera został odrzucony',
				'body' => "Dzień dobry,\n\nNiestety, Twój voucher partnera {partner_name} został odrzucony.\n\nPowód odrzucenia:\n{reason}\n\nMożesz poprawić dane i ponownie wysłać voucher.\n\nLink do formularza: {form_link}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_restored' => array(
				'subject' => '[Feel and Fly] Przywrócenie rezerwacji lotu tandemowego',
				'body' => "Dzień dobry,\n\nTwój lot tandemowy został przywrócony!\n\nSzczegóły:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\n{wazne_informacje}\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_cancelled_by_organizer' => array(
				'subject' => '[Feel and Fly] Odwołanie lotu przez organizatora',
				'body' => "Dzień dobry,\n\nInformujemy, że Twój lot tandemowy został odwołany przez organizatora.\n\nSzczegóły odwołanego lotu:\n📅 Data: {data_lotu}\n⏰ Godzina: ok. {godzina_lotu}\n\nStatus Twojego lotu został przywrócony - możesz ponownie dokonać rezerwacji w dogodnym dla Ciebie terminie.\n\nW razie pytań, skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_option_filming_added' => array(
				'subject' => '[Feel and Fly] Filmowanie dodane do Twojego lotu #{lot_id}',
				'body' => "Dzień dobry,\n\nPotwierdzamy dodanie opcji filmowania do Twojego lotu tandemowego!\n\n📹 Szczegóły:\n• Lot: #{lot_id}\n• Opcja: Filmowanie lotu\n• Data dodania: {data_dodania}\n\n🎬 Co to oznacza?\nTwój lot zostanie sfilmowany przez pilota lub kamerę pokładową. Po locie otrzymasz nagranie z niezapomnianymi chwilami!\n\n{informacje_o_terminie}\n\n💡 Ważne informacje:\n• Film będzie dostępny do odbioru po zakończeniu lotu\n• Nagranie trwa zwykle całą długość lotu\n• Otrzymasz plik wideo w dobrej jakości\n\nCieszymy się, że Twój lot będzie jeszcze bardziej niezapomniany!\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_option_aerobatics_added' => array(
				'subject' => '[Feel and Fly] Akrobacje dodane do Twojego lotu #{lot_id}',
				'body' => "Dzień dobry,\n\nPotwierdzamy dodanie opcji akrobacji do Twojego lotu tandemowego!\n\n🌪️ Szczegóły:\n• Lot: #{lot_id}\n• Opcja: Akrobacje podczas lotu\n• Data dodania: {data_dodania}\n\n🎢 Co to oznacza?\nTwój lot będzie zawierał dodatkowe manewry akrobatyczne - spirale, zakręty i inne figury, które dodadzą adrenaliny!\n\n{informacje_o_terminie}\n\n💡 Ważne informacje:\n• Akrobacje są wykonywane przez doświadczonych pilotów\n• Poziom intensywności dostosowujemy do Twoich preferencji\n• Możesz poprosić o łagodniejsze manewry w trakcie lotu\n• Akrobacje zwiększają emocje i wrażenia z lotu\n\nPrzygotuj się na dawkę adrenaliny!\n\nPozdrawiamy,\nZespół Feel&Fly"
			),
			'flight_option_extension_added' => array(
				'subject' => '[Feel and Fly] Ważność lotu #{lot_id} została przedłużona',
				'body' => "Dzień dobry,\n\nPotwierdzamy przedłużenie ważności Twojego lotu tandemowego!\n\n📅 Szczegóły:\n• Lot: #{lot_id}\n• Opcja: Przedłużenie ważności o {lata} rok/lata\n• Data dodania: {data_dodania}\n• Poprzednia data ważności: {stara_data_waznosci}\n• Nowa data ważności: {nowa_data_waznosci}\n\n⏰ Co to oznacza?\nMasz teraz więcej czasu na zarezerwowanie terminu lotu. Twój lot jest ważny do {nowa_data_waznosci}!\n\n{informacje_o_terminie}\n\n💡 Pamiętaj:\n• Możesz zarezerwować lot w dowolnym momencie do nowej daty ważności\n• Rezerwację można zmienić z wyprzedzeniem minimum 48h\n• W razie pytań, jesteśmy do Twojej dyspozycji\n\n📋 Jak zarezerwować termin:\n{link_rezerwacji}\n\nPozdrawiamy,\nZespół Feel&Fly"
			),

			'admin_notification' => array(
				'subject' => '[Feel and Fly] {temat}',
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
		$sent = wp_mail($to, $subject, $body, $headers, $attachments);
		
		if (!$sent) {
			error_log("SRL: Nie udało się wysłać emaila ({$subject_key}) do: {$to}");
		}
		
		return $sent;
	}

	// NOWA SCALONA METODA - zastępuje wyslijEmailVoucher() i wyslijEmailZVoucherem()
	public function wyslijEmailVoucherZZalacznikiem($email_odbiorcy, $voucher_data, $buyer_name = '') {
		if (!is_email($email_odbiorcy)) return false;
		
		// Wygeneruj obrazek vouchera
		$voucher_generator = SRL_Voucher_Generator::getInstance();
		$image_data = $voucher_generator->generateVoucherImage($voucher_data);
		
		if (!$image_data) {
			error_log("SRL: Nie można wygenerować obrazka vouchera {$voucher_data['kod_vouchera']}");
			return false;
		}
		
		// Stwórz tymczasowy plik
		$upload_dir = wp_upload_dir();
		$temp_file = $upload_dir['path'] . '/voucher_' . $voucher_data['kod_vouchera'] . '_' . time() . '.jpg';
		
		// Zapisz dane obrazu do pliku
		if (file_put_contents($temp_file, $image_data) === false) {
			error_log('SRL: Nie można zapisać tymczasowego pliku vouchera: ' . $temp_file);
			return false;
		}
		
		// Przygotuj dane do szablonu
		$nazwa_odbiorcy = !empty($buyer_name) ? $buyer_name : 'Drogi Odbiorco';
		$kod_vouchera = $voucher_data['kod_vouchera'];
		$data_waznosci = SRL_Helpers::getInstance()->formatujDate($voucher_data['data_waznosci']);
		
		// Przygotuj treść emaila (scalony szablon)
		$subject = '[Feel and Fly] Voucher upominkowy na lot tandemowy';
		$body = "Dzień dobry {$nazwa_odbiorcy},\n\n";
		$body .= "W załączniku przesyłamy Twój voucher na lot tandemowy!\n\n";
		$body .= "Szczegóły vouchera:\n";
		$body .= "🎫 Kod vouchera: {$kod_vouchera}\n";
		$body .= "📅 Ważny do: {$data_waznosci}\n\n";
		$body .= "Jak wykorzystać voucher:\n";
		$body .= "1. Zarejestruj się na naszej stronie internetowej\n";
		$body .= "2. Przejdź do sekcji rezerwacji lotów\n";
		$body .= "3. Wprowadź kod vouchera: {$kod_vouchera}\n";
		$body .= "4. Wybierz dogodny termin lotu\n\n";
		$body .= "Voucher jest ważny do dnia {$data_waznosci}.\n\n";
		$body .= "W razie pytań, skontaktuj się z nami.\n\n";
		$body .= "Życzymy wspaniałych wrażeń!\nZespół Feel&Fly";
		
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
		);
		
		// Przygotuj załącznik
		$attachments = array();
		if (file_exists($temp_file)) {
			$attachments[] = $temp_file;
		}
		
		$sent = wp_mail($email_odbiorcy, $subject, $body, $headers, $attachments);
		
		// Usuń tymczasowy plik
		if (file_exists($temp_file)) {
			unlink($temp_file);
		}
		
		if (!$sent) {
			error_log("SRL: Nie udało się wysłać emaila z voucherem do: {$email_odbiorcy}");
		}
		
		return $sent;
	}
	
	public function wyslijEmailAnulowaniaPrzezKlienta($user_id, $slot_data, $lot_data) {
		$user = get_userdata($user_id);
		if (!$user) return false;

		$imie = $user->first_name ?: $user->display_name;
		
		return $this->sendEmail($user->user_email, 'flight_cancellation_by_client', array(
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($slot_data['data']),
			'godzina_lotu' => substr($slot_data['godzina_start'], 0, 5),
			'data_waznosci' => SRL_Helpers::getInstance()->formatujDate($lot_data['data_waznosci']),
			'link_rezerwacji' => home_url('/rezerwuj-lot/')
		));
	}
	
	public function wyslijEmailPotwierdzenia($user_id, $slot, $lot) {
		$user = get_userdata($user_id);
		if (!$user) return false;

		// Wygeneruj QR kod jako załącznik
		$qr_generator = SRL_QR_Code_Generator::getInstance();
		$qr_attachment = $qr_generator->generateQRAttachment($lot['id'], "qr-kod-lot-{$lot['id']}.png");
		
		$attachments = [];
		if ($qr_attachment) {
			$attachments[] = $qr_attachment['path'];
		}

		$template_data = [
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($slot['data']),
			'godzina_lotu' => substr($slot['godzina_start'], 0, 5),
			'wazne_informacje' => $this->getWazneInformacje()
		];

		$result = $this->sendEmail($user->user_email, 'flight_confirmation', $template_data, [], $attachments);
		
		// Usuń tymczasowy plik QR
		if ($qr_attachment && $qr_attachment['cleanup'] && file_exists($qr_attachment['path'])) {
			unlink($qr_attachment['path']);
		}
		
		return $result;
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

	public function wyslijEmailPotwierdzeniaDlaWszystkichTypowLotow($email, $data_lotu, $godzina_start, $godzina_koniec, $flight_id = null) {
		if (!is_email($email)) return false;
		
		$attachments = [];
		
		// Dodaj QR kod jako załącznik jeśli mamy ID lotu
		if ($flight_id) {
			$qr_generator = SRL_QR_Code_Generator::getInstance();
			$qr_attachment = $qr_generator->generateQRAttachment($flight_id, "qr-kod-lot-{$flight_id}.png");
			
			if ($qr_attachment) {
				$attachments[] = $qr_attachment['path'];
			}
		}
		
		$template_data = [
			'data_lotu' => SRL_Helpers::getInstance()->formatujDate($data_lotu),
			'godzina_lotu' => substr($godzina_start, 0, 5),
			'wazne_informacje' => $this->getWazneInformacje()
		];

		$result = $this->sendEmail($email, 'flight_confirmation', $template_data, [], $attachments);
		
		// Usuń tymczasowy plik QR
		if (isset($qr_attachment) && $qr_attachment['cleanup'] && file_exists($qr_attachment['path'])) {
			unlink($qr_attachment['path']);
		}
		
		return $result;
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
	
	public function wyslijEmailPowitalnyPoZakupie($user_id, $lot_data) {
		$user = get_userdata($user_id);
		if (!$user) return false;

		$imie = $user->first_name ?: $user->display_name;
		$opcje_tekst = '';
		
		// Formatuj opcje lotu
		if (isset($lot_data['ma_filmowanie']) || isset($lot_data['ma_akrobacje'])) {
			$opcje = array();
			if (!empty($lot_data['ma_filmowanie'])) {
				$opcje[] = '📹 Z filmowaniem';
			}
			if (!empty($lot_data['ma_akrobacje'])) {
				$opcje[] = '🌪️ Z akrobacjami';
			}
			
			if (!empty($opcje)) {
				$opcje_tekst = '🎯 Opcje: ' . implode(', ', $opcje) . "\n";
			}
		}
		
		return $this->sendEmail($user->user_email, 'flight_purchase_welcome', array(
			'data_waznosci' => SRL_Helpers::getInstance()->formatujDate($lot_data['data_waznosci']),
			'opcje_lotu' => $opcje_tekst,
			'link_rezerwacji' => home_url('/rezerwuj-lot/')
		));
	}
	
	public function wyslijEmailOpcjiLotu($user_id, $lot_id, $opcja_typ, $dodatkowe_dane = array()) {
		$user = get_userdata($user_id);
		if (!$user) return false;

		$imie = $user->first_name ?: $user->display_name;
		
		// Pobierz dane lotu jeśli potrzebne
		global $wpdb;
		$lot = $wpdb->get_row($wpdb->prepare(
			"SELECT zl.*, t.data, t.godzina_start, t.godzina_koniec
			 FROM {$wpdb->prefix}srl_zakupione_loty zl
			 LEFT JOIN {$wpdb->prefix}srl_terminy t ON zl.termin_id = t.id
			 WHERE zl.id = %d",
			$lot_id
		), ARRAY_A);
		
		// Podstawowe dane dla szablonu
		$template_data = array(
			'lot_id' => $lot_id,
			'data_dodania' => SRL_Helpers::getInstance()->formatujDate(date('Y-m-d'), 'd.m.Y H:i')
		);
		
		// Informacje o terminie (jeśli lot jest zarezerwowany)
		if ($lot && $lot['data'] && $lot['godzina_start']) {
			$data_terminu = SRL_Helpers::getInstance()->formatujDate($lot['data']);
			$godzina_terminu = substr($lot['godzina_start'], 0, 5);
			$template_data['informacje_o_terminie'] = "🎯 Twój zarezerwowany termin:\n📅 Data: {$data_terminu}\n⏰ Godzina: ok. {$godzina_terminu}\n\nOpcja zostanie uwzględniona podczas tego lotu.";
		} else {
			$template_data['informacje_o_terminie'] = "Opcja zostanie uwzględniona gdy dokonasz rezerwacji terminu.\n\n📋 Zarezerwuj termin: " . home_url('/rezerwuj-lot/');
		}
		
		// Dodaj dane specyficzne dla opcji
		$template_data = array_merge($template_data, $dodatkowe_dane);
		
		// Określ szablon na podstawie typu opcji
		$szablon_map = array(
			'filmowanie' => 'flight_option_filming_added',
			'akrobacje' => 'flight_option_aerobatics_added',
			'przedluzenie' => 'flight_option_extension_added'
		);
		
		$szablon = $szablon_map[$opcja_typ] ?? null;
		if (!$szablon) {
			error_log("SRL: Nieznany typ opcji: {$opcja_typ}");
			return false;
		}
		
		return $this->sendEmail($user->user_email, $szablon, $template_data);
	}
	
}