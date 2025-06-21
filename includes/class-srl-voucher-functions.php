<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Voucher_Functions {
    
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
        add_action('init', array($this, 'dodajProduktTandemowy'));
    }

    public function generujUnikalnyKod() {
        $znaki = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $kod = '';
        for ($i = 0; $i < 12; $i++) {
            $kod .= $znaki[mt_rand(0, strlen($znaki)-1)];
        }
        return $kod;
    }

    public function dodajProduktTandemowy() {
        if (!post_type_exists('product')) return;
    }
}