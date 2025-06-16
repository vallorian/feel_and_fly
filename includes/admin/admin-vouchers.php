<?php

function srl_voucher_table_exists() {
    global $wpdb;
    $tabela_vouchery = $wpdb->prefix . 'srl_vouchery_upominkowe';
    return $wpdb->get_var("SHOW TABLES LIKE '$tabela_vouchery'") == $tabela_vouchery;
}

function srl_wyswietl_zakupione_vouchery() {
    if (!current_user_can('manage_options')) {
        wp_die('Brak uprawnień.');
    }

    if (!srl_voucher_table_exists()) {
        echo '<div class="wrap">';
        echo '<h1>🎁 Zakupione Vouchery</h1>';
        echo '<div class="notice notice-warning"><p>Tabela voucherów nie istnieje. Aktywuj ponownie wtyczkę, aby ją utworzyć.</p></div>';
        echo '</div>';
        return;
    }

    global $wpdb;
    $tabela_vouchery = $wpdb->prefix . 'srl_vouchery_upominkowe';
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';

    if (isset($_POST['action']) && isset($_POST['voucher_ids'])) {
        $ids = array_map('intval', $_POST['voucher_ids']);
        $action = $_POST['action'];

        if ($action === 'bulk_delete') {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $tabela_vouchery WHERE id IN ($placeholders)",
                ...$ids
            ));
            echo '<div class="notice notice-success"><p>Usunięto ' . count($ids) . ' voucherów.</p></div>';
        }
    }

    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

    $where_conditions = array();
    $where_params = array();

    if ($status_filter) {
        $where_conditions[] = "v.status = %s";
        $where_params[] = $status_filter;
    }

    if ($search) {
        $where_conditions[] = "(v.buyer_imie LIKE %s OR v.buyer_nazwisko LIKE %s OR v.kod_vouchera LIKE %s OR v.nazwa_produktu LIKE %s OR o.ID LIKE %s)";
        $search_param = '%' . $search . '%';
        $where_params = array_merge($where_params, [$search_param, $search_param, $search_param, $search_param, $search]);
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $query = "
        SELECT v.*, 
               buyer.user_email as buyer_email,
               user.user_email as user_email,
               user.display_name as user_display_name,
               o.post_status as order_status
        FROM $tabela_vouchery v
        LEFT JOIN {$wpdb->users} buyer ON v.buyer_user_id = buyer.ID
        LEFT JOIN {$wpdb->users} user ON v.wykorzystany_przez_user_id = user.ID
        LEFT JOIN {$wpdb->posts} o ON v.order_id = o.ID
        $where_clause
        ORDER BY v.data_zakupu DESC
        LIMIT %d OFFSET %d
    ";

    $count_query = "
        SELECT COUNT(*) 
        FROM $tabela_vouchery v
        LEFT JOIN {$wpdb->users} buyer ON v.buyer_user_id = buyer.ID
        LEFT JOIN {$wpdb->users} user ON v.wykorzystany_przez_user_id = user.ID
        LEFT JOIN {$wpdb->posts} o ON v.order_id = o.ID
        $where_clause
    ";

    $main_query_params = array_merge($where_params, [$per_page, $offset]);

    $vouchery = $wpdb->get_results($wpdb->prepare($query, ...$main_query_params), ARRAY_A);

    if (!empty($where_params)) {
        $total_items = $wpdb->get_var($wpdb->prepare($count_query, ...$where_params));
    } else {
        $total_items = $wpdb->get_var($count_query);
    }

    $total_pages = ceil($total_items / $per_page);

    $stats = $wpdb->get_results(
        "SELECT status, COUNT(*) as count 
         FROM $tabela_vouchery 
         GROUP BY status",
        ARRAY_A
    );

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Zakupione Vouchery</h1>
		<a href="#" id="srl-dodaj-voucher-recznie" class="page-title-action">Dodaj voucher ręcznie</a>

		<!-- Modal dodawania vouchera -->
		<div id="srl-modal-voucher" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
			<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; padding:30px; border-radius:8px; width:400px; max-width:90%;">
				<h3 style="margin-top:0;">Dodaj voucher ręcznie</h3>
				<form id="srl-form-voucher-recznie">
					<p>
						<label><strong>Kod vouchera:</strong></label><br>
						<input type="text" id="srl-kod-vouchera" style="width:100%; padding:8px; margin-top:5px;" placeholder="Wprowadź kod vouchera" required>
					</p>
					<p>
						<label><strong>Data ważności:</strong></label><br>
						<input type="date" id="srl-data-waznosci" style="width:100%; padding:8px; margin-top:5px;" required>
					</p>
					<p>
						<button type="submit" class="button button-primary">Dodaj voucher</button>
						<button type="button" class="button" id="srl-anuluj-voucher">Anuluj</button>
					</p>
				</form>
			</div>
		</div>

        <!-- Statystyki -->
        <div class="srl-stats" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
            <?php
            $status_labels = [
                'do_wykorzystania' => '🟢 Do wykorzystania',
                'wykorzystany' => '🔵 Wykorzystane',
                'przeterminowany' => '🔴 Przeterminowane'
            ];

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
            }
            ?>
        </div>

        <!-- Filtry i wyszukiwanie -->
        <div class="tablenav top">
            <form method="get" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                <input type="hidden" name="page" value="srl-zakupione-vouchery">

                <select name="status_filter">
                    <option value="">Wszystkie statusy</option>
                    <?php foreach ($status_labels as $status => $label): ?>
                        <option value="<?php echo $status; ?>" <?php selected($status_filter, $status); ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Szukaj po kodzie, imieniu, nazwisku, produkcie...">
                <button type="submit" class="button">Filtruj</button>

                <?php if ($status_filter || $search): ?>
                    <a href="<?php echo admin_url('admin.php?page=srl-zakupione-vouchery'); ?>" class="button">Wyczyść filtry</a>
                <?php endif; ?>
            </form>
        </div>

        <form method="post" id="bulk-action-form">
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="action">
                        <option value="">Akcje grupowe</option>
                        <option value="bulk_delete">Usuń zaznaczone</option>
                    </select>
                    <button type="submit" class="button action" onclick="return confirm('Czy na pewno chcesz usunąć zaznaczone vouchery?')">Zastosuj</button>
                </div>

                <div class="tablenav-pages">
                    <?php if ($total_pages > 1): ?>
                        <span class="displaying-num"><?php echo $total_items; ?> elementów</span>
                        <?php
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        echo $page_links;
                        ?>
                    <?php endif; ?>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column check-column">
                            <input type="checkbox" id="cb-select-all-1">
                        </td>
                        <th scope="col" class="manage-column">ID Vouchera</th>
                        <th scope="col" class="manage-column">Zamówienie</th>
                        <th scope="col" class="manage-column">Kupujący</th>
                        <th scope="col" class="manage-column">Produkt</th>
                        <th scope="col" class="manage-column">Kod Vouchera</th>
                        <th scope="col" class="manage-column">Status</th>
                        <th scope="col" class="manage-column">Data zakupu</th>
                        <th scope="col" class="manage-column">Ważność</th>
                        <th scope="col" class="manage-column">Wykorzystany przez</th>
                        <th scope="col" class="manage-column">ID Lotu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vouchery)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 40px; color: #666;">
                                <p style="font-size: 16px;">Brak voucherów do wyświetlenia</p>
                                <?php if ($search || $status_filter): ?>
                                    <p><a href="<?php echo admin_url('admin.php?page=srl-zakupione-vouchery'); ?>">Wyczyść filtry</a></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vouchery as $voucher): ?>
                            <?php
                            $status_class = '';
                            $status_icon = '';
                            switch ($voucher['status']) {
                                case 'do_wykorzystania':
                                    $status_class = 'status-available';
                                    $status_icon = '🟢';
                                    break;
                                case 'wykorzystany':
                                    $status_class = 'status-completed';
                                    $status_icon = '🔵';
                                    break;
                                case 'przeterminowany':
                                    $status_class = 'status-expired';
                                    $status_icon = '🔴';
                                    break;
                            }

                            $order_url = admin_url('post.php?post=' . $voucher['order_id'] . '&action=edit');
                            ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="voucher_ids[]" value="<?php echo $voucher['id']; ?>">
                                </th>
                                <td><strong>#<?php echo $voucher['id']; ?></strong></td>
                                <td>
                                    <a href="<?php echo $order_url; ?>" target="_blank">
                                        #<?php echo $voucher['order_id']; ?>
                                    </a>
                                </td>
                                <td>
                                    <strong>
                                        <a href="<?php echo admin_url('admin.php?page=wc-orders&customer=' . $voucher['buyer_user_id']); ?>" target="_blank" style="color: #0073aa; text-decoration: none;">
                                            <?php echo esc_html($voucher['buyer_imie'] . ' ' . $voucher['buyer_nazwisko']); ?>
                                        </a>
                                    </strong>
                                    <br><small><?php echo esc_html($voucher['buyer_email']); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($voucher['nazwa_produktu']); ?></strong>
                                </td>
                                <td>
                                    <code style="background: #f0f0f0; padding: 4px 8px; border-radius: 4px; font-weight: bold; color: #d63638;">
                                        <?php echo esc_html($voucher['kod_vouchera']); ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="<?php echo $status_class; ?>" style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                        <?php echo $status_icon; ?> <?php echo ucfirst(str_replace('_', ' ', $voucher['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('d.m.Y H:i', strtotime($voucher['data_zakupu'])); ?>
                                </td>
                                <td>
                                    <?php 
                                    $data_waznosci = new DateTime($voucher['data_waznosci']);
                                    $dzisiaj = new DateTime();
                                    $dni_do_wygasniecia = $dzisiaj->diff($data_waznosci)->days;
                                    $kolor = $dni_do_wygasniecia <= 30 ? 'color: #d63638; font-weight: bold;' : '';
                                    ?>
                                    <span style="<?php echo $kolor; ?>">
                                        <?php echo date('d.m.Y', strtotime($voucher['data_waznosci'])); ?>
                                        <?php if ($data_waznosci > $dzisiaj && $dni_do_wygasniecia <= 30): ?>
                                            <br><small>(za <?php echo $dni_do_wygasniecia; ?> dni)</small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($voucher['status'] === 'wykorzystany' && $voucher['user_display_name']): ?>
                                        <strong>
                                            <a href="<?php echo admin_url('admin.php?page=wc-orders&customer=' . $voucher['wykorzystany_przez_user_id']); ?>" target="_blank" style="color: #0073aa; text-decoration: none;">
                                                <?php echo esc_html($voucher['user_display_name']); ?>
                                            </a>
                                        </strong>
                                        <br><small><?php echo esc_html($voucher['user_email']); ?></small>
                                        <?php if ($voucher['data_wykorzystania']): ?>
                                            <br><small style="color: #666;">Wykorzystano: <?php echo date('d.m.Y H:i', strtotime($voucher['data_wykorzystania'])); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($voucher['lot_id']): ?>
                                        <a href="<?php echo admin_url('admin.php?page=srl-wykupione-loty&s=' . $voucher['lot_id']); ?>" target="_blank" style="color: #0073aa; font-weight: bold;">
                                            #<?php echo $voucher['lot_id']; ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>

    <style>
    .status-available {
        background: #d4edda;
        color: #155724;
    }

    .status-completed {
        background: #d1ecf1;
        color: #0c5460;
    }

    .status-expired {
        background: #f8d7da;
        color: #721c24;
    }
    </style>

    <script>
    jQuery(document).ready(function($) {

        $('#cb-select-all-1').on('change', function() {
            $('input[name="voucher_ids[]"]').prop('checked', $(this).is(':checked'));
        });
    });

	jQuery(document).ready(function($) {
    $('#srl-dodaj-voucher-recznie').on('click', function(e) {
        e.preventDefault();
        $('#srl-modal-voucher').show();

        var nextYear = new Date();
        nextYear.setFullYear(nextYear.getFullYear() + 1);
        $('#srl-data-waznosci').val(nextYear.toISOString().split('T')[0]);
    });

    $('#srl-anuluj-voucher').on('click', function() {
        $('#srl-modal-voucher').hide();
        $('#srl-form-voucher-recznie')[0].reset();
    });

    $('#srl-form-voucher-recznie').on('submit', function(e) {
        e.preventDefault();

        var kod = $('#srl-kod-vouchera').val().trim();
        var dataWaznosci = $('#srl-data-waznosci').val();

        if (!kod || !dataWaznosci) {
            alert('Wypełnij wszystkie pola.');
            return;
        }

        $.post(ajaxurl, {
            action: 'srl_dodaj_voucher_recznie',
            kod_vouchera: kod,
            data_waznosci: dataWaznosci,
            nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                alert('Voucher został dodany pomyślnie!');
                location.reload();
            } else {
                alert('Błąd: ' + response.data);
            }
        });
    });
});

    </script>
    <?php
}