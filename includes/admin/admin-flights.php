<?php
function srl_wyswietl_wykupione_loty() {
    srl_check_admin_permissions();
    
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    // Obsługa akcji grupowych
    if (isset($_POST['action']) && isset($_POST['loty_ids'])) {
        $ids = array_map('intval', $_POST['loty_ids']);
        $action = $_POST['action'];
        
        if ($action === 'bulk_delete') {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $tabela_loty WHERE id IN ($placeholders)",
                ...$ids
            ));
            echo srl_generate_message('Usunięto ' . count($ids) . ' lotów.', 'success');
        } 
        elseif (in_array($action, ['bulk_status_wolny', 'bulk_status_zarezerwowany', 'bulk_status_zrealizowany', 'bulk_status_przedawniony'])) {
            $nowy_status = str_replace('bulk_status_', '', $action);
            
            foreach ($ids as $lot_id) {
                $lot = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $tabela_loty WHERE id = %d",
                    $lot_id
                ), ARRAY_A);
                
                if ($lot) {
                    if ($lot['status'] === 'zarezerwowany' && $lot['termin_id'] && $nowy_status === 'wolny') {
                        $wpdb->update(
                            $tabela_terminy,
                            array('status' => 'Wolny', 'klient_id' => null),
                            array('id' => $lot['termin_id']),
                            array('%s', '%d'),
                            array('%d')
                        );
                        
                        $wpdb->update(
                            $tabela_loty,
                            array(
                                'status' => $nowy_status,
                                'termin_id' => null,
                                'data_rezerwacji' => null
                            ),
                            array('id' => $lot_id),
                            array('%s', '%d', '%s'),
                            array('%d')
                        );
                    } else {
                        $wpdb->update(
                            $tabela_loty,
                            array('status' => $nowy_status),
                            array('id' => $lot_id),
                            array('%s'),
                            array('%d')
                        );
                    }
                }
            }
            
            echo srl_generate_message('Zmieniono status ' . count($ids) . ' lotów na "' . $nowy_status . '".', 'success');
        }
    }
    
    // Parametry paginacji i filtrowania
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $search_field = isset($_GET['search_field']) ? sanitize_text_field($_GET['search_field']) : 'wszedzie';
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

    // Buduj WHERE clause
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
            default: // wszedzie
                $where_conditions[] = "(zl.id LIKE %s OR zl.order_id LIKE %s OR zl.imie LIKE %s OR zl.nazwisko LIKE %s OR zl.nazwa_produktu LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s))";
                $search_param = '%' . $search . '%';
                $where_params = array_merge($where_params, [$search, $search, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
                break;
        }
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Query główny
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

    // Query do liczenia rekordów
    $count_query = "
        SELECT COUNT(*) 
        FROM $tabela_loty zl
        LEFT JOIN $tabela_terminy t ON zl.termin_id = t.id
        LEFT JOIN {$wpdb->users} u ON zl.user_id = u.ID
        LEFT JOIN {$wpdb->posts} o ON zl.order_id = o.ID
        $where_clause
    ";

    // Przygotuj parametry dla zapytania głównego
    $main_query_params = array_merge($where_params, [$per_page, $offset]);

    // Wykonaj zapytanie główne
    $loty = $wpdb->get_results($wpdb->prepare($query, ...$main_query_params), ARRAY_A);

    // Count dla paginacji
    if (!empty($where_params)) {
        $total_items = $wpdb->get_var($wpdb->prepare($count_query, ...$where_params));
    } else {
        $total_items = $wpdb->get_var($count_query);
    }

    $total_pages = ceil($total_items / $per_page);
    
    // Statystyki
    $stats = $wpdb->get_results(
        "SELECT status, COUNT(*) as count 
         FROM $tabela_loty 
         GROUP BY status",
        ARRAY_A
    );
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🎫 Wykupione loty tandemowe</h1>
        
        <!-- Statystyki -->
        <div class="srl-stats" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
            <?php
            $status_labels = [
                'wolny' => '🟢 Wolne',
                'zarezerwowany' => '🟡 Zarezerwowane', 
                'zrealizowany' => '🔵 Zrealizowane',
                'przedawniony' => '🔴 Przedawnione'
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
                <input type="hidden" name="page" value="srl-wykupione-loty">
                
                <?php echo srl_generate_select('status_filter', array_merge(
                    ['' => 'Wszystkie statusy'],
                    $status_labels
                ), $status_filter); ?>
                
                <?php echo srl_generate_select('search_field', [
                    'wszedzie' => 'Wszędzie',
                    'email' => 'Email',
                    'id_lotu' => 'ID lotu',
                    'id_zamowienia' => 'ID zamówienia',
                    'imie_nazwisko' => 'Imię i nazwisko',
                    'login' => 'Login',
                    'telefon' => 'Telefon'
                ], $search_field); ?>

                <button type="button" id="srl-date-range-btn" class="button" style="margin-left: 5px;">
                    <?php if ($date_from || $date_to): ?>
                        📅 <?php echo $date_from ? srl_formatuj_date($date_from) : ''; ?><?php echo ($date_from && $date_to) ? ' - ' : ''; ?><?php echo $date_to ? srl_formatuj_date($date_to) : ''; ?>
                    <?php else: ?>
                        📅 Wybierz zakres daty lotu
                    <?php endif; ?>
                </button>

                <div id="srl-date-range-panel" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 4px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; margin-top: 5px;">
                    <div style="margin-bottom: 10px;">
                        <label>Data od:</label><br>
                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" style="width: 150px;">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label>Data do:</label><br>
                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" style="width: 150px;">
                    </div>
                    <div>
                        <?php echo srl_generate_button('Wyczyść', 'button', array('type' => 'button', 'id' => 'srl-clear-dates', 'style' => 'margin-right: 10px;')); ?>
                        <?php echo srl_generate_button('OK', 'button button-primary', array('type' => 'button', 'id' => 'srl-close-panel')); ?>
                    </div>
                </div>

                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Wprowadź szukaną frazę...">
                <?php echo srl_generate_button('Filtruj', 'button', array('type' => 'submit')); ?>
                
                <?php if ($status_filter || $search || $date_from || $date_to): ?>
                    <?php echo srl_generate_link(admin_url('admin.php?page=srl-wykupione-loty'), 'Wyczyść filtry', 'button'); ?>
                <?php endif; ?>
            </form>
        </div>
        
        <form method="post" id="bulk-action-form">
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <?php echo srl_generate_select('action', [
                        '' => 'Akcje grupowe',
                        'bulk_delete' => 'Usuń zaznaczone',
                        'bulk_status_wolny' => 'Zmień status na: Wolny',
                        'bulk_status_zarezerwowany' => 'Zmień status na: Zarezerwowany',
                        'bulk_status_zrealizowany' => 'Zmień status na: Zrealizowany',
                        'bulk_status_przedawniony' => 'Zmień status na: Przedawniony'
                    ], ''); ?>
                    <?php echo srl_generate_button('Zastosuj', 'button action', array(
                        'type' => 'submit',
                        'onclick' => "return confirm('Czy na pewno chcesz usunąć zaznaczone loty?')"
                    )); ?>
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
            
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <td class="manage-column check-column">
                            <input type="checkbox" id="cb-select-all-1">
                        </td>
                        <th scope="col" class="manage-column">ID lotu (zam.)</th>
                        <th scope="col" class="manage-column">Klient</th>
                        <th scope="col" class="manage-column">Produkt</th>
                        <th scope="col" class="manage-column">Status</th>
                        <th scope="col" class="manage-column">Data zakupu</th>
                        <th scope="col" class="manage-column">Ważność</th>
                        <th scope="col" class="manage-column">Rezerwacja</th>
                        <th scope="col" class="manage-column">Odwoływanie</th>
                        <th scope="col" class="manage-column">Zatwierdzanie</th>
                        <th scope="col" class="manage-column">Szczegóły</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($loty)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 40px; color: #666;">
                                <p style="font-size: 16px;">Brak lotów do wyświetlenia</p>
                                <?php if ($search || $status_filter || $date_from || $date_to): ?>
                                    <p><?php echo srl_generate_link(admin_url('admin.php?page=srl-wykupione-loty'), 'Wyczyść filtry'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($loty as $lot): ?>
                            <?php
                            $telefon = get_user_meta($lot['user_id'], 'srl_telefon', true);
                            $order_url = admin_url('post.php?post=' . $lot['order_id'] . '&action=edit');
                            ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="loty_ids[]" value="<?php echo $lot['id']; ?>">
                                </th>
                                
                                <!-- Kolumna ID lotu (zam.) -->
								<td>
									<strong>ID lotu: #<?php echo $lot['id']; ?></strong><br>

									<?php if ($lot['order_id'] == 0): ?>
										<small style="color:#666;font-style:italic;">dod. ręcznie</small>
									<?php else: ?>
										<small>
											Nr. zam:
											<?php
												echo srl_generate_link(
													$order_url,
													'#' . $lot['order_id'],
													'',
													['target' => '_blank', 'style' => 'color:#0073aa;']
												);
											?>
										</small>
									<?php endif; ?>
								</td>
                                
                                <!-- Kolumna Klient -->
                                <td>
                                    <strong>
                                        <?php echo srl_generate_link(
                                            admin_url('admin.php?page=wc-orders&customer=' . $lot['user_id']),
                                            esc_html($lot['user_email']),
                                            '',
                                            array('target' => '_blank', 'style' => 'color: #0073aa; text-decoration: none;')
                                        ); ?>
                                    </strong>
                                    
                                    <?php if ($telefon): ?>
                                        <br><small>📞 <?php echo esc_html($telefon); ?></small>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Kolumna Produkt -->
                                <td>
                                    <strong>Lot w tandemie</strong>
                                    <?php echo '<br><small style="font-weight: bold;">' . srl_format_flight_options_html($lot['ma_filmowanie'], $lot['ma_akrobacje']) . '</small>'; ?>
                                </td>
                                
                                <!-- Kolumna Status -->
                                <td>
                                    <?php echo srl_generate_status_badge($lot['status'], 'lot'); ?>
                                </td>
                                
                                <!-- Kolumna Data zakupu -->
                                <td>
                                    <?php echo srl_formatuj_date($lot['data_zakupu']); ?>
                                </td>
                                
                                <!-- Kolumna Ważność -->
                                <td>
                                    <?php echo srl_formatuj_waznosc_lotu($lot['data_waznosci']); ?>
                                </td>
                                
                                <!-- Kolumna Rezerwacja -->
                                <td>
                                    <?php if ($lot['status'] === 'zarezerwowany' && $lot['data_lotu']): ?>
                                        <strong><?php echo srl_formatuj_date($lot['data_lotu']); ?></strong>
                                        <br><small><?php echo substr($lot['godzina_start'], 0, 5); ?> - <?php echo substr($lot['godzina_koniec'], 0, 5); ?></small>
                                        <?php if ($lot['data_rezerwacji']): ?>
                                            <br><small style="color: #666;">Rez: <?php echo date('d.m.Y H:i', strtotime($lot['data_rezerwacji'])); ?></small>
                                        <?php endif; ?>
                                    <?php elseif ($lot['status'] === 'zrealizowany' && $lot['data_lotu']): ?>
                                        <span style="color: #46b450;">✅ <?php echo srl_formatuj_date($lot['data_lotu']); ?></span>
                                        <br><small style="color: #46b450;"><?php echo substr($lot['godzina_start'], 0, 5); ?> - <?php echo substr($lot['godzina_koniec'], 0, 5); ?></small>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Kolumna Odwoływanie -->
                                <td>
                                    <?php if ($lot['status'] !== 'wolny'): ?>
                                        <?php echo srl_generate_button('Odwołaj', 'button button-small srl-cancel-lot', array(
                                            'type' => 'button',
                                            'data-lot-id' => $lot['id']
                                        )); ?>
                                    <?php else: ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Kolumna Zatwierdzanie -->
                                <td>
                                    <?php if ($lot['status'] === 'zarezerwowany'): ?>
                                        <?php echo srl_generate_button('Zrealizuj', 'button button-primary button-small srl-complete-lot', array(
                                            'type' => 'button',
                                            'data-lot-id' => $lot['id']
                                        )); ?>
                                    <?php else: ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Kolumna Szczegóły -->
                                <td>
                                    <?php echo srl_generate_button('INFO', 'button button-small srl-info-lot', array(
                                        'type' => 'button',
                                        'data-lot-id' => $lot['id'],
                                        'data-user-id' => $lot['user_id']
                                    )); ?>
                                    <?php echo srl_generate_button('Historia', 'button button-small srl-historia-lot', array(
                                        'type' => 'button',
                                        'data-lot-id' => $lot['id'],
                                        'style' => 'margin-left: 5px;'
                                    )); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>
    
    <style>
    .srl-stats {
        background: #f1f1f1;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .status-available {
        background: #d4edda;
        color: #155724;
    }
    
    .status-reserved {
        background: #fff3cd;
        color: #856404;
    }
    
    .status-completed {
        background: #d1ecf1;
        color: #0c5460;
    }
    
    .status-expired {
        background: #f8d7da;
        color: #721c24;
    }
    
    .wp-list-table th,
    .wp-list-table td {
        vertical-align: top;
    }
    
    .wp-list-table small {
        color: #666;
        font-size: 12px;
    }
    </style>
    
    <script>
jQuery(document).ready(function($) {
    // Select all checkbox
    $('#cb-select-all-1').on('change', function() {
        $('input[name="loty_ids[]"]').prop('checked', $(this).is(':checked'));
    });
    
    // Cancel lot
    $('.srl-cancel-lot').on('click', function() {
        if (!confirm('Czy na pewno chcesz odwołać ten lot?')) return;
        
        var lotId = $(this).data('lot-id');
        var button = $(this);
        
        button.prop('disabled', true).text('Odwołując...');
        
        $.post(ajaxurl, {
            action: 'srl_admin_zmien_status_lotu',
            lot_id: lotId,
            nowy_status: 'wolny',
            nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Błąd: ' + response.data);
                button.prop('disabled', false).text('Odwołaj');
            }
        });
    });
    
    // Complete lot
    $('.srl-complete-lot').on('click', function() {
        if (!confirm('Czy na pewno chcesz oznaczyć ten lot jako zrealizowany?')) return;
        
        var lotId = $(this).data('lot-id');
        var button = $(this);
        
        button.prop('disabled', true).text('Realizując...');
        
        $.post(ajaxurl, {
            action: 'srl_admin_zmien_status_lotu',
            lot_id: lotId,
            nowy_status: 'zrealizowany',
            nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Błąd: ' + response.data);
                button.prop('disabled', false).text('Zrealizuj');
            }
        });
    });
    
    // Show info
    $('.srl-info-lot').on('click', function() {
        var lotId = $(this).data('lot-id');
        var userId = $(this).data('user-id');
        
        $.post(ajaxurl, {
            action: 'srl_pobierz_szczegoly_lotu',
            lot_id: lotId,
            user_id: userId,
            nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                var info = response.data;
                var content = '<div style="max-width: 500px;">';
                content += '<h3>Szczegóły lotu #' + lotId + '</h3>';
                content += '<p><strong>Imię:</strong> ' + (info.imie || 'Brak danych') + '</p>';
                content += '<p><strong>Nazwisko:</strong> ' + (info.nazwisko || 'Brak danych') + '</p>';
                content += '<p><strong>Rok urodzenia:</strong> ' + (info.rok_urodzenia || 'Brak danych') + '</p>';
                if (info.wiek) {
                    content += '<p><strong>Wiek:</strong> ' + info.wiek + ' lat</p>';
                }
                content += '<p><strong>Telefon:</strong> ' + (info.telefon || 'Brak danych') + '</p>';
                content += '<p><strong>Sprawność fizyczna:</strong> ' + (info.sprawnosc_fizyczna || 'Brak danych') + '</p>';
                content += '<p><strong>Kategoria wagowa:</strong> ' + (info.kategoria_wagowa || 'Brak danych') + '</p>';
                if (info.uwagi) {
                    content += '<p><strong>Uwagi:</strong> ' + info.uwagi + '</p>';
                }
                content += '</div>';
                
                // Utwórz modal
                var modal = $('<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;">' +
                    '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 90%; max-height: 90%; overflow-y: auto;">' +
                    content +
                    '<button style="margin-top: 20px; padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">Zamknij</button>' +
                    '</div></div>');
                
                $('body').append(modal);
                
                modal.find('button').on('click', function() {
                    modal.remove();
                    $(document).off('keydown.srl-info-modal');
                });

                // Obsługa klawisza Escape dla modalu INFO
                $(document).on('keydown.srl-info-modal', function(e) {
                    if (e.keyCode === 27) { // Escape key
                        modal.remove();
                        $(document).off('keydown.srl-info-modal');
                    }
                });

                modal.on('remove', function() {
                    $(document).off('keydown.srl-info-modal');
                });
            } else {
                alert('Błąd: ' + response.data);
            }
        });
    });
    
    // Obsługa zakresu dat
    $('#srl-date-range-btn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#srl-date-range-panel').toggle();
    });
    
    $('#srl-close-panel').on('click', function(e) {
        e.preventDefault();
        $('#srl-date-range-panel').hide();
    });
    
    $('#srl-clear-dates').on('click', function(e) {
        e.preventDefault();
        $('input[name="date_from"]').val('');
        $('input[name="date_to"]').val('');
        $('#srl-date-range-panel').hide();
        $('#srl-date-range-btn').html('📅 Wybierz zakres daty lotu');
    });
    
    // Zamknij panel po kliknięciu poza nim
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#srl-date-range-btn, #srl-date-range-panel').length) {
            $('#srl-date-range-panel').hide();
        }
    });
    
    // Aktualizuj tekst przycisku po zmianie dat
    $('input[name="date_from"], input[name="date_to"]').on('change', function() {
        var dateFrom = $('input[name="date_from"]').val();
        var dateTo = $('input[name="date_to"]').val();
        var buttonText = '📅 ';
        
        if (dateFrom || dateTo) {
            if (dateFrom) buttonText += formatDate(dateFrom);
            if (dateFrom && dateTo) buttonText += ' - ';
            if (dateTo) buttonText += formatDate(dateTo);
        } else {
            buttonText += 'Wybierz zakres daty lotu';
        }
        
        $('#srl-date-range-btn').html(buttonText);
    });
    
    function formatDate(dateStr) {
        var date = new Date(dateStr);
        return date.toLocaleDateString('pl-PL');
    }
    
    // Show history
    $('.srl-historia-lot').on('click', function() {
        var lotId = $(this).data('lot-id');
        
        $.post(ajaxurl, {
            action: 'srl_pobierz_historie_lotu',
            lot_id: lotId,
            nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                pokazHistorieLotu(response.data);
            } else {
                alert('Błąd: ' + response.data);
            }
        });
    });

    function pokazHistorieLotu(historia) {
        var content = '<div class="srl-historia-container">';
        content += '<h3 style="margin-top: 0; color: #0073aa; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">📋 Historia lotu #' + historia.lot_id + '</h3>';
        
        if (historia.events.length === 0) {
            content += '<p style="text-align: center; color: #6c757d; padding: 40px;">Brak zdarzeń w historii tego lotu.</p>';
        } else {
            content += '<table class="srl-historia-table">';
            content += '<thead><tr><th>Data</th><th>Akcja</th><th>Wykonawca</th><th>Szczegóły</th></tr></thead>';
            content += '<tbody>';
            
            historia.events.forEach(function(event) {
                var rowClass = 'srl-historia-row';
                if (event.action_name === 'ZMIANA STATUSU') {
                    rowClass += ' srl-historia-zmiana_statusu';
                } else if (event.action_name === 'DOKUPIENIE WARIANTU') {
                    rowClass += ' srl-historia-dokupienie';
                } else if (event.action_name === 'SYSTEMOWE') {
                    rowClass += ' srl-historia-systemowe';
                } else if (event.action_name === 'ZMIANA DANYCH') {
                    rowClass += ' srl-historia-zmiana_danych';
                }
                
                content += '<tr class="' + rowClass + '">';
                content += '<td class="srl-historia-data">' + event.formatted_date + '</td>';
                content += '<td class="srl-historia-akcja">' + event.action_name + '</td>';
                content += '<td class="srl-historia-wykonawca">' + event.executor + '</td>';
                content += '<td class="srl-historia-szczegoly">' + event.details + '</td>';
                content += '</tr>';
            });
            
            content += '</tbody></table>';
        }
        
        content += '</div>';
        
        // Utwórz modal
        var modal = $('<div class="srl-modal-historia">' +
            '<div class="srl-modal-content">' +
            content +
            '<div class="srl-modal-actions"><button class="button button-primary srl-modal-close">Zamknij</button></div>' +
            '</div></div>');
        
        $('body').append(modal);
        
        // Obsługa zamykania
        modal.find('.srl-modal-close').on('click', function() {
            modal.remove();
            $(document).off('keydown.srl-modal');
        });
        
        modal.on('click', function(e) {
            if (e.target === this) {
                modal.remove();
                $(document).off('keydown.srl-modal');
            }
        });
        
        // Obsługa klawisza Escape
        $(document).on('keydown.srl-modal', function(e) {
            if (e.keyCode === 27) { // Escape key
                modal.remove();
                $(document).off('keydown.srl-modal');
            }
        });
    }
});
</script>

<style>
/* Historia lotów - czytelne statusy */
.srl-historia-container {
    max-width: 800px;
    background: white;
    border-radius: 8px;
    padding: 20px;
}

.srl-historia-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.srl-historia-table th,
.srl-historia-table td {
    padding: 12px 8px;
    text-align: left;
    border-bottom: 1px solid #e1e5e9;
    vertical-align: top;
}

.srl-historia-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #495057;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.srl-historia-table td {
    font-size: 14px;
}

.srl-historia-data {
    font-family: 'Courier New', monospace;
    color: #6c757d;
    white-space: nowrap;
    min-width: 120px;
}

.srl-historia-akcja {
    font-weight: 600;
    color: #495057;
    min-width: 140px;
}

.srl-historia-wykonawca {
    color: #6c757d;
    min-width: 80px;
}

.srl-historia-szczegoly {
    max-width: 400px;
    line-height: 1.4;
}

.srl-historia-szczegoly small {
    display: block;
    color: #6c757d;
    font-size: 12px;
    margin-top: 2px;
}

/* Kolorowe statusy */
.srl-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* Kategorie akcji */
.srl-historia-zmiana_statusu {
    border-left: 3px solid #007bff;
}

.srl-historia-dokupienie {
    border-left: 3px solid #28a745;
}

.srl-historia-systemowe {
    border-left: 3px solid #6c757d;
}

.srl-historia-zmiana_danych {
    border-left: 3px solid #ffc107;
}

/* Modal */
.srl-modal-historia {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}

.srl-modal-content {
    background: white;
    border-radius: 8px;
    max-width: 90%;
    max-height: 90%;
    overflow-y: auto;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

.srl-modal-actions {
    text-align: right;
    padding: 20px;
    border-top: 1px solid #e1e5e9;
    background: #f8f9fa;
    border-radius: 0 0 8px 8px;
}

/* Responsywność */
@media (max-width: 768px) {
    .srl-historia-table {
        font-size: 12px;
    }
    
    .srl-historia-table th,
    .srl-historia-table td {
        padding: 8px 4px;
    }
    
    .srl-modal-content {
        margin: 20px;
        max-width: calc(100% - 40px);
        max-height: calc(100% - 40px);
    }
}

/* Animacje */
.srl-modal-historia {
    animation: srl-fadeIn 0.2s ease-out;
}

.srl-modal-content {
    animation: srl-slideIn 0.3s ease-out;
}

@keyframes srl-fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes srl-slideIn {
    from { 
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to { 
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
</style>

    <?php
}


// AJAX: Usuń pojedynczy lot
add_action('wp_ajax_srl_usun_lot', 'srl_ajax_usun_lot');
function srl_ajax_usun_lot() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
	srl_check_admin_permissions();
    
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


// AJAX: Zmień status lotu przez admina
add_action('wp_ajax_srl_admin_zmien_status_lotu', 'srl_ajax_admin_zmien_status_lotu');
function srl_ajax_admin_zmien_status_lotu() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
    srl_check_admin_permissions();
    
    $lot_id = intval($_POST['lot_id']);
    $nowy_status = sanitize_text_field($_POST['nowy_status']);
    
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    $tabela_terminy = $wpdb->prefix . 'srl_terminy';
    
    // Pobierz szczegóły lotu
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    if (!$lot) {
        wp_send_json_error('Lot nie został znaleziony.');
        return;
    }
    
    $stary_status = $lot['status'];
    
    // Rozpocznij transakcję
    $wpdb->query('START TRANSACTION');
    
    try {
        $szczegoly_historii = array();
        $opis_zmiany = '';
        
        // Aktualizuj lot
        if ($nowy_status === 'wolny' && $lot['termin_id']) {
            // Pobierz szczegóły terminu przed jego zwolnieniem
            $termin_info = $wpdb->get_row($wpdb->prepare(
                "SELECT data, godzina_start, godzina_koniec, pilot_id FROM $tabela_terminy WHERE id = %d",
                $lot['termin_id']
            ), ARRAY_A);
            
            // Dla statusu "wolny" - usuń dane rezerwacji i zwolnij slot
            $result = $wpdb->update(
                $tabela_loty,
                array(
                    'status' => $nowy_status,
                    'termin_id' => null,
                    'data_rezerwacji' => null
                ),
                array('id' => $lot_id),
                array('%s', '%d', '%s'),
                array('%d')
            );
            
            // Zwolnij slot - ustaw status na "Wolny" i usuń klient_id
            $wpdb->update(
                $tabela_terminy,
                array(
                    'status' => 'Wolny', 
                    'klient_id' => null
                ),
                array('id' => $lot['termin_id']),
                array('%s', '%d'),
                array('%d')
            );
            
            // Przygotuj szczegóły dla historii
            if ($termin_info) {
                $termin_opis = sprintf('%s %s-%s (Pilot %d)', 
                    $termin_info['data'],
                    substr($termin_info['godzina_start'], 0, 5),
                    substr($termin_info['godzina_koniec'], 0, 5),
                    $termin_info['pilot_id']
                );
                $opis_zmiany = "Status lotu zmieniony przez administratora z '{$stary_status}' na '{$nowy_status}' - zwolniono termin {$termin_opis}";
                $szczegoly_historii['zwolniony_termin'] = $termin_opis;
                $szczegoly_historii['termin_id'] = $lot['termin_id'];
            } else {
                $opis_zmiany = "Status lotu zmieniony przez administratora z '{$stary_status}' na '{$nowy_status}' - zwolniono rezerwację";
            }
            
        } else {
            // Dla innych statusów - zachowaj dane rezerwacji ale zaktualizuj slot
            $result = $wpdb->update(
                $tabela_loty,
                array('status' => $nowy_status),
                array('id' => $lot_id),
                array('%s'),
                array('%d')
            );
            
            $opis_zmiany = "Status lotu zmieniony przez administratora z '{$stary_status}' na '{$nowy_status}'";
            
            // Synchronizuj status slotu tylko jeśli lot ma przypisany termin
            if ($lot['termin_id']) {
                $status_slotu = '';
                switch ($nowy_status) {
                    case 'zarezerwowany':
                        $status_slotu = 'Zarezerwowany';
                        break;
                    case 'zrealizowany':
                        $status_slotu = 'Zrealizowany';
                        $opis_zmiany .= ' - lot oznaczony jako zrealizowany';
                        break;
                    case 'przedawniony':
                        // Dla przedawnionych - zwolnij slot
                        $status_slotu = 'Wolny';
                        $wpdb->update(
                            $tabela_terminy,
                            array(
                                'status' => $status_slotu,
                                'klient_id' => null
                            ),
                            array('id' => $lot['termin_id']),
                            array('%s', '%d'),
                            array('%d')
                        );
                        
                        // Usuń przypisanie terminu z lotu
                        $wpdb->update(
                            $tabela_loty,
                            array(
                                'termin_id' => null,
                                'data_rezerwacji' => null
                            ),
                            array('id' => $lot_id),
                            array('%d', '%s'),
                            array('%d')
                        );
                        
                        $opis_zmiany .= ' - slot zwolniony z powodu przedawnienia';
                        $szczegoly_historii['slot_zwolniony'] = true;
                        break;
                }
                
                // Dla statusów innych niż przedawniony
                if ($status_slotu && $nowy_status !== 'przedawniony') {
                    $wpdb->update(
                        $tabela_terminy,
                        array('status' => $status_slotu),
                        array('id' => $lot['termin_id']),
                        array('%s'),
                        array('%d')
                    );
                    
                    $szczegoly_historii['status_slotu_zmieniony'] = $status_slotu;
                }
            }
        }
        
        if ($result === false) {
            throw new Exception('Błąd aktualizacji w bazie danych.');
        }
        
        // DOPISZ wpis do historii - ZAWSZE gdy zmienia się status
        if ($stary_status !== $nowy_status) {
            $szczegoly_historii = array_merge($szczegoly_historii, array(
                'stary_status' => $stary_status,
                'nowy_status' => $nowy_status,
                'zmiana_statusu' => $stary_status . ' → ' . $nowy_status,
                'zmiana_przez_admin' => true,
                'lot_id' => $lot_id
            ));
            
            $wpis_historii = array(
                'data' => srl_get_current_datetime(),
                'opis' => $opis_zmiany,
                'typ' => 'zmiana_statusu_admin',
                'executor' => 'Admin',
                'szczegoly' => $szczegoly_historii
            );
            
            srl_dopisz_do_historii_lotu($lot_id, $wpis_historii);
        }
        
        // Zatwierdź transakcję
        $wpdb->query('COMMIT');
        wp_send_json_success('Status lotu został zmieniony i slot zaktualizowany.');
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error($e->getMessage());
    }
}

// AJAX: Pobierz szczegóły lotu
add_action('wp_ajax_srl_pobierz_szczegoly_lotu', 'srl_ajax_pobierz_szczegoly_lotu');
function srl_ajax_pobierz_szczegoly_lotu() {
    check_ajax_referer('srl_admin_nonce', 'nonce', true);
	srl_check_admin_permissions();
    
    $lot_id = intval($_POST['lot_id']);
    $user_id = intval($_POST['user_id']);
    
    global $wpdb;
    $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
    
    // Sprawdź czy lot ma zapisane dane pasażera
    $lot = $wpdb->get_row($wpdb->prepare(
        "SELECT dane_pasazera FROM $tabela_loty WHERE id = %d",
        $lot_id
    ), ARRAY_A);
    
    $dane = array();
    
    // Jeśli są zapisane dane w rezerwacji, użyj ich
    if (!empty($lot['dane_pasazera'])) {
        $dane = json_decode($lot['dane_pasazera'], true);
    }
    
    // Jeśli brak danych w rezerwacji lub niekompletne, pobierz z profilu użytkownika
	if (empty($dane) || !isset($dane['imie'])) {
		$dane = srl_get_user_full_data($user_id);
	}
    
    // Dodaj wiek jeśli jest rok urodzenia
    if (!empty($dane['rok_urodzenia'])) {
        $dane['wiek'] = date('Y') - intval($dane['rok_urodzenia']);
    }
    
    wp_send_json_success($dane);
}

?>