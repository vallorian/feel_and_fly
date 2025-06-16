<?php function srl_wyswietl_wykupione_loty() {
    if (!current_user_can('manage_options')) {
        wp_die('Brak uprawnień.');
    }
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    if (isset($_POST['action']) && isset($_POST['loty_ids'])) {
        $ids = array_map('intval', $_POST['loty_ids']);
        $action = $_POST['action'];
        if ($action === 'bulk_delete') {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM $tabela_loty WHERE id IN ($placeholders)", ...$ids));
            echo '<div class="notice notice-success"><p>Usunięto ' . count($ids) . ' lotów.</p></div>';
        } elseif (in_array($action, ['bulk_status_wolny', 'bulk_status_zarezerwowany', 'bulk_status_zrealizowany', 'bulk_status_przedawniony'])) {
            $nowy_status = str_replace('bulk_status_', '', $action);
            foreach ($ids as $lot_id) {
                $lot = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabela_loty WHERE id = %d", $lot_id), ARRAY_A);
                if ($lot) {
                    if ($lot['status'] === 'zarezerwowany' && $lot['termin_id'] && $nowy_status === 'wolny') {
                        $wpdb->update($tabela_terminy, array('status' => 'Wolny', 'klient_id' => null), array('id' => $lot['termin_id']), array('%s', '%d'), array('%d'));
                        $wpdb->update($tabela_loty, array('status' => $nowy_status, 'termin_id' => null, 'data_rezerwacji' => null), array('id' => $lot_id), array('%s', '%d', '%s'), array('%d'));
                    } else {
                        $wpdb->update($tabela_loty, array('status' => $nowy_status), array('id' => $lot_id), array('%s'), array('%d'));
                    }
                }
            }
            echo '<div class="notice notice-success"><p>Zmieniono status ' . count($ids) . ' lotów na "' . $nowy_status . '".</p></div>';
        }
    }
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $search_field = isset($_GET['search_field']) ? sanitize_text_field($_GET['search_field']) : 'wszedzie';
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    $where_conditions = array();
    $where_params = array();
    if ($status_filter) {
        $where_conditions[] = "zl.status = %s";
        $where_params[] = $status_filter;
    }
    if ($date_from && $date_to) {
        $where_conditions[] = "t.data BETWEEN %s AND %s";
        $where_params = array_merge($where_params, [$date_from, $date_to]);
    } elseif ($date_from) {
        $where_conditions[] = "t.data >= %s";
        $where_params[] = $date_from;
    } elseif ($date_to) {
        $where_conditions[] = "t.data <= %s";
        $where_params[] = $date_to;
    }
    if ($search) {
        switch ($search_field) {
            case 'id_lotu':
                $where_conditions[] = "zl.id = %s";
                $where_params[] = $search;
            break;
            case 'id_zamowienia':
                $where_conditions[] = "zl.order_id = %s";
                $where_params[] = $search;
            break;
            case 'email':
                $where_conditions[] = "u.user_email LIKE %s";
                $where_params[] = '%' . $search . '%';
            break;
            case 'telefon':
                $where_conditions[] = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s)";
                $where_params[] = '%' . $search . '%';
            break;
            case 'imie_nazwisko':
                $where_conditions[] = "(zl.imie LIKE %s OR zl.nazwisko LIKE %s OR CONCAT(zl.imie, ' ', zl.nazwisko) LIKE %s)";
                $search_param = '%' . $search . '%';
                $where_params = array_merge($where_params, [$search_param, $search_param, $search_param]);
            break;
            case 'login':
                $where_conditions[] = "u.user_login LIKE %s";
                $where_params[] = '%' . $search . '%';
            break;
            default:
                $where_conditions[] = "(zl.id LIKE %s OR zl.order_id LIKE %s OR zl.imie LIKE %s OR zl.nazwisko LIKE %s OR zl.nazwa_produktu LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s))";
                $search_param = '%' . $search . '%';
                $where_params = array_merge($where_params, [$search, $search, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
            break;
        }
    }
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    $query = "
    SELECT zl.*, 
           t.data as data_lotu, 
           t.godzina_start, 
           t.godzina_koniec,
           u.user_email,
           o.post_status as order_status
    FROM $tabela_loty zl
    LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
    LEFT JOIN {$wpdb->users} u ON zl.user_id = u.ID
    LEFT JOIN {$wpdb->posts} o ON zl.order_id = o.ID
    $where_clause
    ORDER BY zl.data_zakupu DESC
    LIMIT %d OFFSET %d
";
    $count_query = "
    SELECT COUNT(*) 
    FROM $tabela_loty zl
    LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
    LEFT JOIN {$wpdb->users} u ON zl.user_id = u.ID
    LEFT JOIN {$wpdb->posts} o ON zl.order_id = o.ID
    $where_clause
";
    $main_query_params = array_merge($where_params, [$per_page, $offset]);
    $loty = $wpdb->get_results($wpdb->prepare($query, ...$main_query_params), ARRAY_A);
    if (!empty($where_params)) {
        $total_items = $wpdb->get_var($wpdb->prepare($count_query, ...$where_params));
    } else {
        $total_items = $wpdb->get_var($count_query);
    }
    $total_pages = ceil($total_items / $per_page);
    $stats = $wpdb->get_results("SELECT status, COUNT(*) as count 
         FROM $tabela_loty 
         GROUP BY status", ARRAY_A); ?><div class="wrap"><h1 class="wp-heading-inline">🎫 Wykupione loty tandemowe</h1><a href="<?php echo admin_url('admin.php?page=srl-sync-flights'); ?>"class="page-title-action">Synchronizuj loty</a><div class="srl-stats"style="display:flex;gap:20px;margin:20px 0;flex-wrap:wrap"><?php $status_labels = ['wolny' => '🟢 Wolne', 'zarezerwowany' => '🟡 Zarezerwowane', 'zrealizowany' => '🔵 Zrealizowane', 'przedawniony' => '🔴 Przedawnione'];
    $stats_array = array();
    foreach ($stats as $stat) {
        $stats_array[$stat['status']] = $stat['count'];
    }
    foreach ($status_labels as $status => $label) {
        $count = isset($stats_array[$status]) ? $stats_array[$status] : 0;
        echo '<div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); min-width: 120px; display: flex; align-items: center; gap: 10px;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . $count . '</div>';
        echo '<div style="font-size: 13px; color: #666;">' . $label . '</div>';
        echo '</div>';
    } ?></div><div class="tablenav top"><form style="display:flex;gap:10px;align-items:center;margin-bottom:10px"><input type="hidden"name="page"value="srl-wykupione-loty"> <select name="status_filter"><option value="">Wszystkie statusy</option><?php foreach ($status_labels as $status => $label): ?><option value="<?php echo $status; ?>"<?php selected($status_filter, $status); ?>><?php echo $label; ?></option><?php
    endforeach; ?></select> <select name="search_field"><option value="wszedzie"<?php selected($search_field, 'wszedzie'); ?>>Wszędzie</option><option value="email"<?php selected($search_field, 'email'); ?>>Email</option><option value="id_lotu"<?php selected($search_field, 'id_lotu'); ?>>ID lotu</option><option value="id_zamowienia"<?php selected($search_field, 'id_zamowienia'); ?>>ID zamówienia</option><option value="imie_nazwisko"<?php selected($search_field, 'imie_nazwisko'); ?>>Imię i nazwisko</option><option value="login"<?php selected($search_field, 'login'); ?>>Login</option><option value="telefon"<?php selected($search_field, 'telefon'); ?>>Telefon</option></select> <button class="button"type="button"id="srl-date-range-btn"style="margin-left:5px"><?php if ($date_from || $date_to): ?>📅<?php echo $date_from ? date('d.m.Y', strtotime($date_from)) : ''; ?><?php echo ($date_from && $date_to) ? ' - ' : ''; ?><?php echo $date_to ? date('d.m.Y', strtotime($date_to)) : ''; ?><?php
    else: ?>📅 Wybierz zakres daty lotu<?php
    endif; ?></button><div style="display:none;position:absolute;background:#fff;border:1px solid #ccc;border-radius:4px;padding:15px;box-shadow:0 2px 8px rgba(0,0,0,.1);z-index:1000;margin-top:5px"id="srl-date-range-panel"><div style="margin-bottom:10px"><label>Data od:</label><br><input type="date"name="date_from"value="<?php echo esc_attr($date_from); ?>"style="width:150px"></div><div style="margin-bottom:15px"><label>Data do:</label><br><input type="date"name="date_to"value="<?php echo esc_attr($date_to); ?>"style="width:150px"></div><div><button class="button"type="button"id="srl-clear-dates"style="margin-right:10px">Wyczyść</button> <button class="button button-primary"type="button"id="srl-close-panel">OK</button></div></div><input type="search"name="s"value="<?php echo esc_attr($search); ?>"placeholder="Wprowadź szukaną frazę..."> <button class="button"type="submit">Filtruj</button><?php if ($status_filter || $search || $date_from || $date_to): ?><a href="<?php echo admin_url('admin.php?page=srl-wykupione-loty'); ?>"class="button">Wyczyść filtry</a><?php
    endif; ?></form></div><form method="post"id="bulk-action-form"><div class="tablenav top"><div class="actions alignleft bulkactions"><select name="action"><option value="">Akcje grupowe</option><option value="bulk_delete">Usuń zaznaczone</option><option value="bulk_status_wolny">Zmień status na: Wolny</option><option value="bulk_status_zarezerwowany">Zmień status na: Zarezerwowany</option><option value="bulk_status_zrealizowany">Zmień status na: Zrealizowany</option><option value="bulk_status_przedawniony">Zmień status na: Przedawniony</option></select> <button class="button action"type="submit"onclick='return confirm("Czy na pewno chcesz usunąć zaznaczone loty?")'>Zastosuj</button></div><div class="tablenav-pages"><?php if ($total_pages > 1): ?><span class="displaying-num"><?php echo $total_items; ?>elementów</span><?php $page_links = paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'prev_text' => '&laquo;', 'next_text' => '&raquo;', 'total' => $total_pages, 'current' => $current_page));
        echo $page_links; ?><?php
    endif; ?></div></div><table class="striped widefat wp-list-table"><thead><tr><td class="manage-column check-column"><input type="checkbox"id="cb-select-all-1"></td><th class="manage-column"scope="col">ID lotu (zam.)</th><th class="manage-column"scope="col">Klient</th><th class="manage-column"scope="col">Produkt</th><th class="manage-column"scope="col">Status</th><th class="manage-column"scope="col">Data zakupu</th><th class="manage-column"scope="col">Ważność</th><th class="manage-column"scope="col">Rezerwacja</th><th class="manage-column"scope="col">Odwoływanie</th><th class="manage-column"scope="col">Zatwierdzanie</th><th class="manage-column"scope="col">Szczegóły</th></tr></thead><tbody><?php if (empty($loty)): ?><tr><td colspan="11"style="text-align:center;padding:40px;color:#666"><p style="font-size:16px">Brak lotów do wyświetlenia</p><?php if ($search || $status_filter || $date_from || $date_to): ?><p><a href="<?php echo admin_url('admin.php?page=srl-wykupione-loty'); ?>">Wyczyść filtry</a></p><?php
        endif; ?></td></tr><?php
    else: ?><?php foreach ($loty as $lot): ?><?php $status_class = '';
            $status_icon = '';
            switch ($lot['status']) {
                case 'wolny':
                    $status_class = 'status-available';
                    $status_icon = '🟢';
                break;
                case 'zarezerwowany':
                    $status_class = 'status-reserved';
                    $status_icon = '🟡';
                break;
                case 'zrealizowany':
                    $status_class = 'status-completed';
                    $status_icon = '🔵';
                break;
                case 'przedawniony':
                    $status_class = 'status-expired';
                    $status_icon = '🔴';
                break;
            }
            $telefon = get_user_meta($lot['user_id'], 'srl_telefon', true);
            $order_url = admin_url('post.php?post=' . $lot['order_id'] . '&action=edit'); ?><tr><th class="check-column"scope="row"><input type="checkbox"name="loty_ids[]"value="<?php echo $lot['id']; ?>"></th><td><strong>ID lotu: #<?php echo $lot['id']; ?></strong><br>Nr. zam: <a href="<?php echo $order_url; ?>"style="color:#0073aa"target="_blank">#<?php echo $lot['order_id']; ?></a></td><td><strong><a href="<?php echo admin_url('admin.php?page=wc-orders&customer=' . $lot['user_id']); ?>"style="color:#0073aa;text-decoration:none"target="_blank"><?php echo esc_html($lot['user_email']); ?></a></strong><?php if (!empty($lot['kod_vouchera'])): ?><br><small style="color:#d63638;font-weight:700">🎁 Voucher:<?php echo esc_html($lot['kod_vouchera']); ?></small><br><small style="color:#666">Kupujący:<?php echo esc_html($lot['voucher_buyer_imie'] . ' ' . $lot['voucher_buyer_nazwisko']); ?></small><?php
            endif; ?><?php if ($telefon): ?><br><small>📞<?php echo esc_html($telefon); ?></small><?php
            endif; ?></td><td><strong>Lot w tandemie</strong><?php $opcje_tekst = array();
            if (!empty($lot['ma_filmowanie'])) {
                $opcje_tekst[] = '<span style="color: #46b450;">z filmowaniem</span>';
            } else {
                $opcje_tekst[] = '<span style="color: #d63638;">bez filmowania</span>';
            }
            if (!empty($lot['ma_akrobacje'])) {
                $opcje_tekst[] = '<span style="color: #46b450;">z akrobacjami</span>';
            } else {
                $opcje_tekst[] = '<span style="color: #d63638;">bez akrobacji</span>';
            }
            echo '<br><small style="font-weight: bold;">' . implode(',&nbsp;', $opcje_tekst) . '</small>'; ?></td><td><span style="display:inline-block;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600"class="<?php echo $status_class; ?>"><?php echo $status_icon; ?><?php echo ucfirst($lot['status']); ?></span></td><td><?php echo date('d.m.Y', strtotime($lot['data_zakupu'])); ?></td><td><?php $data_waznosci = new DateTime($lot['data_waznosci']);
            $dzisiaj = new DateTime();
            $dni_do_wygasniecia = $dzisiaj->diff($data_waznosci)->days;
            $kolor = $dni_do_wygasniecia <= 30 ? 'color: #d63638; font-weight: bold;' : ''; ?><span style="<?php echo $kolor; ?>"><?php echo date('d.m.Y', strtotime($lot['data_waznosci'])); ?><?php if ($data_waznosci > $dzisiaj && $dni_do_wygasniecia <= 30): ?><br><small>(za<?php echo $dni_do_wygasniecia; ?>dni)</small><?php
            endif; ?></span></td><td><?php if ($lot['status'] === 'zarezerwowany' && $lot['data_lotu']): ?><strong><?php echo date('d.m.Y', strtotime($lot['data_lotu'])); ?></strong><br><small><?php echo substr($lot['godzina_start'], 0, 5); ?>-<?php echo substr($lot['godzina_koniec'], 0, 5); ?></small><?php if ($lot['data_rezerwacji']): ?><br><small style="color:#666">Rez:<?php echo date('d.m.Y H:i', strtotime($lot['data_rezerwacji'])); ?></small><?php
                endif; ?><?php
            elseif ($lot['status'] === 'zrealizowany' && $lot['data_lotu']): ?><span style="color:#46b450">✅<?php echo date('d.m.Y', strtotime($lot['data_lotu'])); ?></span><br><small style="color:#46b450"><?php echo substr($lot['godzina_start'], 0, 5); ?>-<?php echo substr($lot['godzina_koniec'], 0, 5); ?></small><?php
            else: ?><span style="color:#999">—</span><?php
            endif; ?></td><td><?php if ($lot['status'] !== 'wolny'): ?><button class="button button-small srl-cancel-lot"type="button"data-lot-id="<?php echo $lot['id']; ?>">Odwołaj</button><?php
            else: ?><span style="color:#999">—</span><?php
            endif; ?></td><td><?php if ($lot['status'] === 'zarezerwowany'): ?><button class="button button-small button-primary srl-complete-lot"type="button"data-lot-id="<?php echo $lot['id']; ?>">Zrealizuj</button><?php
            else: ?><span style="color:#999">—</span><?php
            endif; ?></td><td><button class="button button-small srl-info-lot"type="button"data-lot-id="<?php echo $lot['id']; ?>"data-user-id="<?php echo $lot['user_id']; ?>">INFO</button> <button class="button button-small srl-historia-lot"type="button"data-lot-id="<?php echo $lot['id']; ?>"style="margin-left:5px">Historia</button></td></tr><?php
        endforeach; ?><?php
    endif; ?></tbody></table></form></div><style>.srl-stats{background:#f1f1f1;padding:15px;border-radius:8px;margin-bottom:20px}.status-available{background:#d4edda;color:#155724}.status-reserved{background:#fff3cd;color:#856404}.status-completed{background:#d1ecf1;color:#0c5460}.status-expired{background:#f8d7da;color:#721c24}.wp-list-table td,.wp-list-table th{vertical-align:top}.wp-list-table small{color:#666;font-size:12px}</style>
	<script>jQuery(document).ready(function(i){function n(t){return new Date(t).toLocaleDateString("pl-PL")}i("#cb-select-all-1").on("change",function(){i('input[name="loty_ids[]"]').prop("checked",i(this).is(":checked"))}),i(".srl-cancel-lot").on("click",function(){if(confirm("Czy na pewno chcesz odwołać ten lot?")){var t=i(this).data("lot-id"),o=i(this);o.prop("disabled",!0).text("Odwołując..."),i.post(ajaxurl,{action:"srl_admin_zmien_status_lotu",lot_id:t,nowy_status:"wolny",nonce:"<?php echo wp_create_nonce('srl_admin_nonce'); ?>"},function(t){t.success?location.reload():(alert("Błąd: "+t.data),o.prop("disabled",!1).text("Odwołaj"))})}}),i(".srl-complete-lot").on("click",function(){if(confirm("Czy na pewno chcesz oznaczyć ten lot jako zrealizowany?")){var t=i(this).data("lot-id"),o=i(this);o.prop("disabled",!0).text("Realizując..."),i.post(ajaxurl,{action:"srl_admin_zmien_status_lotu",lot_id:t,nowy_status:"zrealizowany",nonce:"<?php echo wp_create_nonce('srl_admin_nonce'); ?>"},function(t){t.success?location.reload():(alert("Błąd: "+t.data),o.prop("disabled",!1).text("Zrealizuj"))})}}),i(".srl-info-lot").on("click",function(){var e=i(this).data("lot-id"),t=i(this).data("user-id");i.post(ajaxurl,{action:"srl_pobierz_szczegoly_lotu",lot_id:e,user_id:t,nonce:"<?php echo wp_create_nonce('srl_admin_nonce'); ?>"},function(t){if(t.success){var o=t.data,a='<div style="max-width: 500px;">';a+="<h3>Szczegóły lotu #"+e+"</h3>",a+="<p><strong>Imię:</strong> "+(o.imie||"Brak danych")+"</p>",a+="<p><strong>Nazwisko:</strong> "+(o.nazwisko||"Brak danych")+"</p>",a+="<p><strong>Rok urodzenia:</strong> "+(o.rok_urodzenia||"Brak danych")+"</p>",o.wiek&&(a+="<p><strong>Wiek:</strong> "+o.wiek+" lat</p>"),a+="<p><strong>Telefon:</strong> "+(o.telefon||"Brak danych")+"</p>",a+="<p><strong>Sprawność fizyczna:</strong> "+(o.sprawnosc_fizyczna||"Brak danych")+"</p>",a+="<p><strong>Kategoria wagowa:</strong> "+(o.kategoria_wagowa||"Brak danych")+"</p>",o.uwagi&&(a+="<p><strong>Uwagi:</strong> "+o.uwagi+"</p>");var n=i('<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;"><div style="background: white; padding: 30px; border-radius: 8px; max-width: 90%; max-height: 90%; overflow-y: auto;">'+(a+="</div>")+'<button style="margin-top: 20px; padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">Zamknij</button></div></div>');i("body").append(n),n.find("button").on("click",function(){n.remove(),i(document).off("keydown.srl-info-modal")}),i(document).on("keydown.srl-info-modal",function(t){27===t.keyCode&&(n.remove(),i(document).off("keydown.srl-info-modal"))}),n.on("remove",function(){i(document).off("keydown.srl-info-modal")})}else alert("Błąd: "+t.data)})}),i("#srl-date-range-btn").on("click",function(t){t.preventDefault(),t.stopPropagation(),console.log("Kliknięto przycisk daty"),i("#srl-date-range-panel").toggle()}),i("#srl-close-panel").on("click",function(t){t.preventDefault(),i("#srl-date-range-panel").hide()}),i("#srl-clear-dates").on("click",function(t){t.preventDefault(),i('input[name="date_from"]').val(""),i('input[name="date_to"]').val(""),i("#srl-date-range-panel").hide(),i("#srl-date-range-btn").html("📅 Wybierz zakres daty lotu")}),i(document).on("click",function(t){i(t.target).closest("#srl-date-range-btn, #srl-date-range-panel").length||i("#srl-date-range-panel").hide()}),i('input[name="date_from"], input[name="date_to"]').on("change",function(){var t=i('input[name="date_from"]').val(),o=i('input[name="date_to"]').val(),a="📅 ";t||o?(t&&(a+=n(t)),t&&o&&(a+=" - "),o&&(a+=n(o))):a+="Wybierz zakres daty lotu",i("#srl-date-range-btn").html(a)}),i(".srl-historia-lot").on("click",function(){var t=i(this).data("lot-id");i.post(ajaxurl,{action:"srl_pobierz_historie_lotu",lot_id:t,nonce:"<?php echo wp_create_nonce('srl_admin_nonce'); ?>"},function(t){t.success?function(t){var o='<div class="srl-historia-container">';o+="<h3>Historia lotu #"+t.lot_id+"</h3>",0===t.events.length?o+="<p>Brak zdarzeń w historii tego lotu.</p>":(o+='<table class="srl-historia-table">',o+="<thead><tr><th>Data</th><th>Akcja</th><th>Wykonawca</th><th>Szczegóły</th></tr></thead>",o+="<tbody>",t.events.forEach(function(t){o+='<tr class="srl-historia-row srl-historia-'+t.type+'">',o+='<td class="srl-historia-data">'+t.formatted_date+"</td>",o+='<td class="srl-historia-akcja">'+t.action_name+"</td>",o+='<td class="srl-historia-wykonawca">'+t.executor+"</td>",o+='<td class="srl-historia-szczegoly">'+t.details+"</td>",o+="</tr>"}),o+="</tbody></table>");var a=i('<div class="srl-modal-historia" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;"><div class="srl-modal-content">'+(o+="</div>")+'<div class="srl-modal-actions"><button class="button button-primary srl-modal-close">Zamknij</button></div></div></div>');i("body").append(a),a.find(".srl-modal-close, .srl-modal-historia").on("click",function(t){t.target===this&&a.remove()}),i(document).on("keydown.srl-modal",function(t){27===t.keyCode&&(a.remove(),i(document).off("keydown.srl-modal"))}),a.on("remove",function(){i(document).off("keydown.srl-modal")})}(t.data):alert("Błąd: "+t.data)})})})</script><?php
}
function srl_wyswietl_synchronizacje() {
    if (!current_user_can('manage_options')) {
        wp_die('Brak uprawnień.');
    }
    if (isset($_POST['sync_flights'])) {
        $result = srl_synchronizuj_loty_z_zamowieniami();
        echo '<div class="notice notice-success"><p>' . $result . '</p></div>';
    } ?><div class="wrap"><h1>🔄 Synchronizacja lotów</h1><div class="card"style="max-width:800px"><h2>Synchronizuj loty z zamówieniami</h2><p>Ta funkcja przeskanuje wszystkie zamówienia i:</p><ul><li>Doda brakujące loty z zamówień ze statusem "processing" lub "completed"</li><li>Usunie loty z zamówień o innych statusach</li><li>Zaktualizuje dane klientów</li><li>Oznczy przeterminowane loty</li></ul><form method="post"><p><button class="button button-primary button-large"type="submit"name="sync_flights">🔄 Uruchom synchronizację</button></p></form></div><div class="card"style="max-width:800px;margin-top:20px"><h2>Produkty uznawane za loty</h2><?php $dozwolone_id = srl_get_flight_product_ids();
    echo '<p>Aktualne ID produktów: <strong>' . implode(', ', $dozwolone_id) . '</strong></p>';
    foreach ($dozwolone_id as $id) {
        $product = wc_get_product($id);
        if ($product) {
            echo '<p>• ID ' . $id . ': ' . esc_html($product->get_name()) . '</p>';
        } else {
            echo '<p style="color: #d63638;">• ID ' . $id . ': Produkt nie istnieje!</p>';
        }
    } ?></div></div><?php
}
add_action('wp_ajax_srl_usun_lot', 'srl_ajax_usun_lot');
function srl_ajax_usun_lot() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    $lot_id = intval($_POST['lot_id']);
    global $wpdb;
    $tabela = $wpdb->prefix . 'srl_zakupione_loty';
    $result = $wpdb->delete($tabela, array('id' => $lot_id), array('%d'));
    if ($result === false) {
        wp_send_json_error('Błąd usuwania z bazy danych.');
    } else {
        wp_send_json_success('Lot został usunięty.');
    }
}
function srl_synchronizuj_loty_z_zamowieniami() {
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $dodane = 0;
    $usuniete = 0;
    $zaktualizowane = 0;
    $nieważne_statusy = ['trash', 'cancelled', 'refunded', 'failed', 'pending', 'on-hold'];
    $placeholders = implode(',', array_fill(0, count($nieważne_statusy), '%s'));
    $do_usuniecia = $wpdb->get_results($wpdb->prepare("SELECT zl.id FROM $tabela_loty zl 
         LEFT JOIN {$wpdb->posts} p ON zl.order_id = p.ID 
         WHERE p.post_status IN ($placeholders) OR p.ID IS NULL", ...$nieważne_statusy));
    foreach ($do_usuniecia as $lot) {
        $wpdb->delete($tabela_loty, array('id' => $lot->id), array('%d'));
        $usuniete++;
    }
    $ważne_statusy = ['wc-processing', 'wc-completed'];
    $orders = get_posts(array('post_type' => 'shop_order', 'post_status' => $ważne_statusy, 'posts_per_page' => - 1, 'fields' => 'ids'));
    foreach ($orders as $order_id) {
        $existing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tabela_loty WHERE order_id = %d", $order_id));
        if ($existing == 0) {
            srl_dodaj_loty_po_zakupie($order_id);
            $dodane++;
        }
    }
    $wpdb->query("UPDATE $tabela_loty 
         SET status = 'przedawniony' 
         WHERE status IN ('wolny', 'zarezerwowany') 
         AND data_waznosci < CURDATE()");
    return "Synchronizacja zakończona. Dodano: $dodane, Usunięto: $usuniete lotów.";
}
add_action('wp_ajax_srl_admin_zmien_status_lotu', 'srl_ajax_admin_zmien_status_lotu');
function srl_ajax_admin_zmien_status_lotu() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    $lot_id = intval($_POST['lot_id']);
    $nowy_status = sanitize_text_field($_POST['nowy_status']);
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    $lot = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabela_loty WHERE id = %d", $lot_id), ARRAY_A);
    if (!$lot) {
        wp_send_json_error('Lot nie został znaleziony.');
        return;
    }
    $stary_status = $lot['status'];
    $wpdb->query('START TRANSACTION');
    try {
        $szczegoly_historii = array();
        $opis_zmiany = '';
        if ($nowy_status === 'wolny' && $lot['termin_id']) {
            $termin_info = $wpdb->get_row($wpdb->prepare("SELECT data, godzina_start, godzina_koniec, pilot_id FROM $tabela_terminy WHERE id = %d", $lot['termin_id']), ARRAY_A);
            $result = $wpdb->update($tabela_loty, array('status' => $nowy_status, 'termin_id' => null, 'data_rezerwacji' => null), array('id' => $lot_id), array('%s', '%d', '%s'), array('%d'));
            $wpdb->update($tabela_terminy, array('status' => 'Wolny', 'klient_id' => null), array('id' => $lot['termin_id']), array('%s', '%d'), array('%d'));
            if ($termin_info) {
                $termin_opis = sprintf('%s %s-%s (Pilot %d)', $termin_info['data'], substr($termin_info['godzina_start'], 0, 5), substr($termin_info['godzina_koniec'], 0, 5), $termin_info['pilot_id']);
                $opis_zmiany = "Status lotu zmieniony przez administratora z '{$stary_status}' na '{$nowy_status}' - zwolniono termin {$termin_opis}";
                $szczegoly_historii['zwolniony_termin'] = $termin_opis;
                $szczegoly_historii['termin_id'] = $lot['termin_id'];
            } else {
                $opis_zmiany = "Status lotu zmieniony przez administratora z '{$stary_status}' na '{$nowy_status}' - zwolniono rezerwację";
            }
        } else {
            $result = $wpdb->update($tabela_loty, array('status' => $nowy_status), array('id' => $lot_id), array('%s'), array('%d'));
            $opis_zmiany = "Status lotu zmieniony przez administratora z '{$stary_status}' na '{$nowy_status}'";
            if ($lot['termin_id']) {
                $status_slotu = '';
                switch ($nowy_status) {
                    case 'zarezerwowany':
                        $status_slotu = 'Zarezerwowany';
                    break;
                    case 'zrealizowany':
                        $status_slotu = 'Zrealizowany';
                        $opis_zmiany.= ' - lot oznaczony jako zrealizowany';
                    break;
                    case 'przedawniony':
                        $status_slotu = 'Wolny';
                        $wpdb->update($tabela_terminy, array('status' => $status_slotu, 'klient_id' => null), array('id' => $lot['termin_id']), array('%s', '%d'), array('%d'));
                        $wpdb->update($tabela_loty, array('termin_id' => null, 'data_rezerwacji' => null), array('id' => $lot_id), array('%d', '%s'), array('%d'));
                        $opis_zmiany.= ' - slot zwolniony z powodu przedawnienia';
                        $szczegoly_historii['slot_zwolniony'] = true;
                    break;
                }
                if ($status_slotu && $nowy_status !== 'przedawniony') {
                    $wpdb->update($tabela_terminy, array('status' => $status_slotu), array('id' => $lot['termin_id']), array('%s'), array('%d'));
                    $szczegoly_historii['status_slotu_zmieniony'] = $status_slotu;
                }
            }
        }
        if ($result === false) {
            throw new Exception('Błąd aktualizacji w bazie danych.');
        }
        if ($stary_status !== $nowy_status) {
            $szczegoly_historii = array_merge($szczegoly_historii, array('stary_status' => $stary_status, 'nowy_status' => $nowy_status, 'zmiana_statusu' => $stary_status . ' → ' . $nowy_status, 'zmiana_przez_admin' => true, 'lot_id' => $lot_id));
            $wpis_historii = array('data' => srl_get_current_datetime(), 'opis' => $opis_zmiany, 'typ' => 'zmiana_statusu_admin', 'executor' => 'Admin', 'szczegoly' => $szczegoly_historii);
            srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
        }
        $wpdb->query('COMMIT');
        wp_send_json_success('Status lotu został zmieniony i slot zaktualizowany.');
    }
    catch(Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_srl_pobierz_szczegoly_lotu', 'srl_ajax_pobierz_szczegoly_lotu');
function srl_ajax_pobierz_szczegoly_lotu() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień.');
        return;
    }
    $lot_id = intval($_POST['lot_id']);
    $user_id = intval($_POST['user_id']);
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $lot = $wpdb->get_row($wpdb->prepare("SELECT dane_pasazera FROM $tabela_loty WHERE id = %d", $lot_id), ARRAY_A);
    $dane = array();
    if (!empty($lot['dane_pasazera'])) {
        $dane = json_decode($lot['dane_pasazera'], true);
    }
    if (empty($dane) || !isset($dane['imie'])) {
        $dane = array('imie' => get_user_meta($user_id, 'srl_imie', true), 'nazwisko' => get_user_meta($user_id, 'srl_nazwisko', true), 'rok_urodzenia' => get_user_meta($user_id, 'srl_rok_urodzenia', true), 'kategoria_wagowa' => get_user_meta($user_id, 'srl_kategoria_wagowa', true), 'sprawnosc_fizyczna' => get_user_meta($user_id, 'srl_sprawnosc_fizyczna', true), 'telefon' => get_user_meta($user_id, 'srl_telefon', true), 'uwagi' => get_user_meta($user_id, 'srl_uwagi', true));
    }
    if (!empty($dane['rok_urodzenia'])) {
        $dane['wiek'] = date('Y') - intval($dane['rok_urodzenia']);
    }
    wp_send_json_success($dane);
} ?>