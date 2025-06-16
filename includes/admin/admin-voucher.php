<?php function srl_wyswietl_vouchery() {
    global $wpdb;
    $tabela_vouchery = $wpdb->prefix . 'srl_vouchery';
    $voucherzy = $wpdb->get_results("SELECT * FROM $tabela_vouchery WHERE status = 'Oczekuje'");
    echo '<div class="wrap"><h1>Vouchery oczekujące na potwierdzenie</h1>';
    echo '<table class="widefat"><thead><tr><th>Imię i nazwisko</th><th>Data zgłoszenia</th><th>Kod Vouchera</th><th>Źródło</th><th>Akcje</th></tr></thead><tbody>';
    foreach ($voucherzy as $voucher) {
        echo '<tr>';
        echo '<td>' . esc_html(get_the_author_meta('display_name', $voucher->klient_id)) . '</td>';
        echo '<td>' . esc_html($voucher->data_zgloszenia) . '</td>';
        echo '<td>' . esc_html($voucher->kod_vouchera) . '</td>';
        echo '<td>' . esc_html($voucher->zrodlo) . '</td>';
        echo '<td>';
        echo '<form method="post" style="display:inline">';
        echo '<input type="hidden" name="voucher_id" value="' . esc_attr($voucher->id) . '">';
        echo '<button name="srl_zatwierdz_voucher" class="button button-primary">Zatwierdź</button> ';
        echo '<button name="srl_odrzuc_voucher" class="button button-secondary">Odrzuć</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
add_action('admin_init', 'srl_przetwarzaj_vouchery');
function srl_przetwarzaj_vouchery() {
    if (isset($_POST['srl_zatwierdz_voucher'])) {
        $id = intval($_POST['voucher_id']);
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery';
        $wpdb->update($tabela, array('status' => 'Zatwierdzony'), array('id' => $id));
    }
    if (isset($_POST['srl_odrzuc_voucher'])) {
        $id = intval($_POST['voucher_id']);
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery';
        $wpdb->update($tabela, array('status' => 'Odrzucony'), array('id' => $id));
    }
}
