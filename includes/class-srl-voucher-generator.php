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

   // Rejestruje akcje AJAX dla pobierania i wysyłania voucherów
   private function initHooks() {
       add_action('wp_ajax_srl_download_voucher', array($this, 'downloadVoucher'));
       add_action('wp_ajax_srl_send_voucher_email', array($this, 'sendVoucherEmail'));
   }

   // Obsługuje pobieranie vouchera jako plik JPG
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

       header('Content-Type: image/jpeg');
       header('Content-Disposition: attachment; filename="voucher-' . $voucher['kod_vouchera'] . '.jpg"');
       header('Content-Length: ' . strlen($image_data));
       
       echo $image_data;
       exit;
   }

   // Obsługuje wysyłanie vouchera emailem jako załącznik
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

       if (empty($voucher['buyer_email'])) {
           wp_send_json_error('Brak adresu email dla tego vouchera');
       }

       $image_data = $this->generateVoucherImage($voucher);
       if (!$image_data) {
           wp_send_json_error('Błąd generowania vouchera');
       }

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

   // Pobiera dane vouchera z bazy wraz z emailem użytkownika
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

   // Generuje obrazek vouchera JPG z nałożonym tekstem
   private function generateVoucherImage($voucher) {
       $template_path = SRL_PLUGIN_DIR . 'temp-voucher.jpg';
       
       if (!file_exists($template_path)) {
           return false;
       }

       if (!extension_loaded('gd')) {
           return false;
       }

       $image = imagecreatefromjpeg($template_path);
       if (!$image) {
           return false;
       }

       $black = imagecolorallocate($image, 0, 0, 0);
       $font_path = $this->getFontPath();

       $kod_vouchera = strtoupper($voucher['kod_vouchera']);
       $this->addRotatedText($image, $kod_vouchera, 1895, 841, 90, $black, $font_path, 36);

       $data_waznosci = $this->formatujDatePolski($voucher['data_waznosci']);
       $this->addRotatedText($image, $data_waznosci, 2035, 841, 90, $black, $font_path, 36);

       if (isset($voucher['ma_filmowanie']) && $voucher['ma_filmowanie']) {
           $this->addRotatedText($image, 'X', 1970, 360, 90, $black, $font_path, 60);
       }
       
       if (isset($voucher['ma_akrobacje']) && $voucher['ma_akrobacje']) {
           $this->addRotatedText($image, 'X', 1970, 155, 90, $black, $font_path, 60);
       }

       ob_start();
       imagejpeg($image, null, 95);
       $image_data = ob_get_contents();
       ob_end_clean();

       imagedestroy($image);
       
       return $image_data;
   }

   // Dodaje obrócony tekst na obrazek
   private function addRotatedText($image, $text, $x, $y, $angle, $color, $font_path, $size) {
       if ($font_path && file_exists($font_path)) {
           imagettftext($image, $size, $angle, $x, $y, $color, $font_path, $text);
       } else {
           imagestring($image, 5, $x, $y, $text, $color);
       }
   }

   // Szuka dostępnej czcionki TTF na serwerze
   private function getFontPath() {
       $possible_paths = array(
           SRL_PLUGIN_DIR . 'assets/fonts/arial.ttf',
           SRL_PLUGIN_DIR . 'assets/fonts/OpenSans-Regular.ttf',
           SRL_PLUGIN_DIR . 'arial.ttf',
           '/System/Library/Fonts/Arial.ttf',
           '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
           '/usr/share/fonts/TTF/arial.ttf',
           'C:\Windows\Fonts\arial.ttf'
       );

       foreach ($possible_paths as $path) {
           if (file_exists($path)) {
               return $path;
           }
       }

       return false;
   }

   // Formatuje datę do polskiego formatu dd.mm.yyyy
   private function formatujDatePolski($date) {
       $timestamp = strtotime($date);
       if (!$timestamp) return $date;
       
       return date('d.m.Y', $timestamp);
   }
}