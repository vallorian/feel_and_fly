<?php

function srl_generuj_unikalny_kod() {
    $znaki = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $kod = '';
    for ($i = 0; $i < 12; $i++) {
        $kod .= $znaki[mt_rand(0, strlen($znaki)-1)];
    }
    return $kod;
}

add_action('init', 'srl_dodaj_produkt_tandemowy');
function srl_dodaj_produkt_tandemowy() {
    if (!post_type_exists('product')) return;

}