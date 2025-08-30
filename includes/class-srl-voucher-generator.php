<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Voucher_Generator {
    
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
        add_action('wp_ajax_srl_download_voucher', array($this, 'downloadVoucher'));
		add_action('wp_ajax_srl_send_voucher_email', array($this, 'sendVoucherEmail'));
    }

    public function downloadVoucher() {
        check_ajax_referer('srl_admin_nonce', 'nonce');
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $voucher_id = intval($_POST['voucher_id']);
        if (!$voucher_id) {
            wp_die('Nieprawidłowy ID vouchera');
        }

        $voucher = $this->getVoucherData($voucher_id);
        if (!$voucher) {
            wp_die('Voucher nie został znaleziony');
        }

        $image_data = $this->generateVoucherImage($voucher);
        
        if (!$image_data) {
            wp_die('Błąd generowania vouchera');
        }

        // Wyślij nagłówki dla pobierania pliku
        header('Content-Type: image/jpeg');
        header('Content-Disposition: attachment; filename="voucher-' . $voucher['kod_vouchera'] . '.jpg"');
        header('Content-Length: ' . strlen($image_data));
        
        echo $image_data;
        exit;
    }

	private function getVoucherData($voucher_id) {
		global $wpdb;
		$tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
		
		return $wpdb->get_row($wpdb->prepare(
			"SELECT v.*, u.user_email as buyer_email 
			 FROM $tabela v
			 LEFT JOIN {$wpdb->users} u ON v.buyer_user_id = u.ID
			 WHERE v.id = %d",
			$voucher_id
		), ARRAY_A);
	}

   private function generateVoucherImage($voucher) {
    // Ścieżka do szablonu
    $template_path = SRL_PLUGIN_DIR . 'temp-voucher.jpg';
    
    error_log('SRL Debug - Ścieżka szablonu: ' . $template_path);
    error_log('SRL Debug - Szablon istnieje: ' . (file_exists($template_path) ? 'TAK' : 'NIE'));
    
    if (!file_exists($template_path)) {
        error_log('SRL: Szablon vouchera nie istnieje: ' . $template_path);
        return false;
    }

    // Sprawdź czy GD jest dostępne
    if (!extension_loaded('gd')) {
        error_log('SRL: Rozszerzenie GD nie jest dostępne');
        return false;
    }

    // Wczytaj szablon
    $image = imagecreatefromjpeg($template_path);
    if (!$image) {
        error_log('SRL: Nie można wczytać szablonu vouchera');
        return false;
    }

    error_log('SRL Debug - Wymiary obrazka: ' . imagesx($image) . 'x' . imagesy($image));
    error_log('SRL Debug - Kod vouchera: ' . $voucher['kod_vouchera']);
    error_log('SRL Debug - Data ważności: ' . $voucher['data_waznosci']);

    // Kolory
    $black = imagecolorallocate($image, 0, 0, 0);
    $blue = imagecolorallocate($image, 66, 99, 190); // #4263be

    // Ścieżka do czcionki
    $font_path = $this->getFontPath();
    error_log('SRL Debug - Ścieżka czcionki: ' . ($font_path ? $font_path : 'BRAK'));

    // 1. Kod vouchera (obrócony o 90 stopni)
    $kod_vouchera = strtoupper($voucher['kod_vouchera']);
    error_log('SRL Debug - Dodawanie kodu vouchera na pozycji 1650, 1100');
            $this->addRotatedText($image, $kod_vouchera, 1890, 841, 90, $blue, $font_path, 36);

        // 2. Data ważności (obracana o 90 stopni) 
        $data_waznosci = $this->formatujDatePolski($voucher['data_waznosci']);
        $this->addRotatedText($image, $data_waznosci, 2030, 841, 90, $black, $font_path, 36);

        // 3. Dodatkowe opcje - X dla filmowania i akrobacji
        if (isset($voucher['ma_filmowanie']) && $voucher['ma_filmowanie']) {
            $this->addText($image, 'X', 1920, 355, $black, $font_path, 46);
        }
        
        if (isset($voucher['ma_akrobacje']) && $voucher['ma_akrobacje']) {
            $this->addText($image, 'X', 1920, 150, $black, $font_path, 46);
    }

    // Konwertuj na JPEG i zwróć dane
    ob_start();
    imagejpeg($image, null, 95);
    $image_data = ob_get_contents();
    ob_end_clean();

    imagedestroy($image);

    error_log('SRL Debug - Rozmiar wygenerowanego obrazka: ' . strlen($image_data) . ' bajtów');
    
    return $image_data;
}

private function addRotatedText($image, $text, $x, $y, $angle, $color, $font_path, $size) {
    if ($font_path && file_exists($font_path)) {
        // Zwiększ rozmiar - może być interpretowany inaczej
        $adjusted_size = $size; // lub * 1.5
        error_log("SRL Debug - Rozmiar oryginalny: $size, dostosowany: $adjusted_size");
        
        $result = @imagettftext($image, $adjusted_size, $angle, $x, $y, $color, $font_path, $text);
        if ($result === false) {
            error_log('SRL Debug - imagettftext failed');
            imagestring($image, 5, $x, $y, $text, $color);
        }
    } else {
        imagestring($image, 5, $x, $y, $text, $color);
    }
}

private function addText($image, $text, $x, $y, $color, $font_path, $size) {
    error_log("SRL Debug - addText: '$text' rozmiar: $size");
    
    if ($font_path && file_exists($font_path)) {
        $result = imagettftext($image, $size, 0, $x, $y, $color, $font_path, $text);
        if ($result === false) {
            error_log('SRL Debug - Błąd imagettftext w addText');
        }
    } else {
        // Wbudowana czcionka - rozmiar 1-5
        $builtin_size = min(5, max(1, intval($size / 4)));
        imagestring($image, $builtin_size, $x, $y, $text, $color);
    }
}

	private function getFontPath() {
		// Spróbuj znaleźć czcionkę TTF w określonej kolejności
		$possible_paths = array(
			SRL_PLUGIN_DIR . 'assets/fonts/arial.ttf',
			SRL_PLUGIN_DIR . 'assets/fonts/OpenSans-Regular.ttf',
			SRL_PLUGIN_DIR . 'arial.ttf', // w głównym folderze wtyczki
			'/System/Library/Fonts/Arial.ttf', // macOS
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', // Linux
			'/usr/share/fonts/TTF/arial.ttf', // Linux
			'C:\Windows\Fonts\arial.ttf' // Windows
		);

		foreach ($possible_paths as $path) {
			if (file_exists($path)) {
				error_log('SRL Debug - Znaleziono czcionkę TTF: ' . $path);
				return $path;
			}
		}

		error_log('SRL Debug - Nie znaleziono żadnej czcionki TTF');
		return false;
	}

    private function formatujDatePolski($date) {
        $timestamp = strtotime($date);
        if (!$timestamp) return $date;
        
        return date('d.m.Y', $timestamp);
    }
	
	public function sendVoucherEmail() {
		check_ajax_referer('srl_admin_nonce', 'nonce');
		SRL_Helpers::getInstance()->checkAdminPermissions();

		$voucher_id = intval($_POST['voucher_id']);
		if (!$voucher_id) {
			wp_send_json_error('Nieprawidłowy ID vouchera');
		}

		$voucher = $this->getVoucherData($voucher_id);
		if (!$voucher) {
			wp_send_json_error('Voucher nie został znaleziony');
		}

		// Sprawdź czy voucher ma adres email
		if (empty($voucher['buyer_email'])) {
			wp_send_json_error('Brak adresu email dla tego vouchera');
		}

		// Wygeneruj obrazek vouchera
		$image_data = $this->generateVoucherImage($voucher);
		if (!$image_data) {
			wp_send_json_error('Błąd generowania vouchera');
		}

		// Wyślij email z voucherem
		$buyer_name = trim($voucher['buyer_imie'] . ' ' . $voucher['buyer_nazwisko']);
		$email_sent = SRL_Email_Functions::getInstance()->wyslijEmailZVoucherem(
			$voucher['buyer_email'],
			$voucher,
			$image_data,
			$buyer_name
		);

		if ($email_sent) {
			wp_send_json_success('Voucher został wysłany emailem');
		} else {
			wp_send_json_error('Błąd wysyłania emaila');
		}
	}
}