<?php

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Admin_Flights {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
    }

    public static function wyswietlWykupioneLoty() {
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        
        if (isset($_POST['action']) && isset($_POST['loty_ids'])) {
            $ids = array_map('intval', $_POST['loty_ids']);
            $action = $_POST['action'];
            
            if ($action === 'bulk_delete') {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $tabela_loty WHERE id IN ($placeholders)",
                    ...$ids
                ));
                echo SRL_Helpers::getInstance()->generateMessage('Usunięto ' . count($ids) . ' lotów.', 'success');
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
                
                echo SRL_Helpers::getInstance()->generateMessage('Zmieniono status ' . count($ids) . ' lotów na "' . $nowy_status . '".', 'success');
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
        
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM $tabela_loty 
             GROUP BY status",
            ARRAY_A
        );
        
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">🎫 Wykupione loty tandemowe</h1>';
        
        echo '<div class="srl-stats" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">';
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
        echo '</div>';
        
        echo '<div class="tablenav top">';
        echo '<form method="get" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">';
        echo '<input type="hidden" name="page" value="srl-wykupione-loty">';
        
        echo SRL_Helpers::getInstance()->generateSelect('status_filter', array_merge(
            ['' => 'Wszystkie statusy'],
            $status_labels
        ), $status_filter);
        
        echo SRL_Helpers::getInstance()->generateSelect('search_field', [
            'wszedzie' => 'Wszędzie',
            'email' => 'Email',
            'id_lotu' => 'ID lotu',
            'id_zamowienia' => 'ID zamówienia',
            'imie_nazwisko' => 'Imię i nazwisko',
            'login' => 'Login',
            'telefon' => 'Telefon'
        ], $search_field);

        echo '<button type="button" id="srl-date-range-btn" class="button" style="margin-left: 5px;">';
        if ($date_from || $date_to) {
            echo '📅 ' . ($date_from ? SRL_Helpers::getInstance()->formatujDate($date_from) : '') . (($date_from && $date_to) ? ' - ' : '') . ($date_to ? SRL_Helpers::getInstance()->formatujDate($date_to) : '');
        } else {
            echo '📅 Wybierz zakres daty lotu';
        }
        echo '</button>';

        echo '<div id="srl-date-range-panel" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 4px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; margin-top: 5px;">';
        echo '<div style="margin-bottom: 10px;">';
        echo '<label>Data od:</label><br>';
        echo '<input type="date" name="date_from" value="' . esc_attr($date_from) . '" style="width: 150px;">';
        echo '</div>';
        echo '<div style="margin-bottom: 15px;">';
        echo '<label>Data do:</label><br>';
        echo '<input type="date" name="date_to" value="' . esc_attr($date_to) . '" style="width: 150px;">';
        echo '</div>';
        echo '<div>';
        echo SRL_Helpers::getInstance()->generateButton('Wyczyść', 'button', array('type' => 'button', 'id' => 'srl-clear-dates', 'style' => 'margin-right: 10px;'));
        echo SRL_Helpers::getInstance()->generateButton('OK', 'button button-primary', array('type' => 'button', 'id' => 'srl-close-panel'));
        echo '</div>';
        echo '</div>';

        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Wprowadź szukaną frazę...">';
        echo SRL_Helpers::getInstance()->generateButton('Filtruj', 'button', array('type' => 'submit'));
        
        if ($status_filter || $search || $date_from || $date_to) {
            echo SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=srl-wykupione-loty'), 'Wyczyść filtry', 'button');
        }
        echo '</form>';
        echo '</div>';
        
        echo '<form method="post" id="bulk-action-form">';
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions bulkactions">';
        echo SRL_Helpers::getInstance()->generateSelect('action', [
            '' => 'Akcje grupowe',
            'bulk_delete' => 'Usuń zaznaczone',
            'bulk_status_wolny' => 'Zmień status na: Wolny',
            'bulk_status_zarezerwowany' => 'Zmień status na: Zarezerwowany',
            'bulk_status_zrealizowany' => 'Zmień status na: Zrealizowany',
            'bulk_status_przedawniony' => 'Zmień status na: Przedawniony'
        ], '');
        echo SRL_Helpers::getInstance()->generateButton('Zastosuj', 'button action', array(
            'type' => 'submit',
            'onclick' => "return confirm('Czy na pewno chcesz usunąć zaznaczone loty?')"
        ));
        echo '</div>';
        
        if ($total_pages > 1) {
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . $total_items . ' elementów</span>';
            $page_links = paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $total_pages,
                'current' => $current_page
            ));
            echo $page_links;
            echo '</div>';
        }
        echo '</div>';
        
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<td class="manage-column check-column">';
        echo '<input type="checkbox" id="cb-select-all-1">';
        echo '</td>';
        echo '<th scope="col" class="manage-column">ID lotu (zam.)</th>';
        echo '<th scope="col" class="manage-column">Klient</th>';
        echo '<th scope="col" class="manage-column">Produkt</th>';
        echo '<th scope="col" class="manage-column">Status</th>';
        echo '<th scope="col" class="manage-column">Data zakupu</th>';
        echo '<th scope="col" class="manage-column">Ważność</th>';
        echo '<th scope="col" class="manage-column">Rezerwacja</th>';
        echo '<th scope="col" class="manage-column">Odwoływanie</th>';
        echo '<th scope="col" class="manage-column">Zatwierdzanie</th>';
        echo '<th scope="col" class="manage-column">Szczegóły</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        if (empty($loty)) {
            echo '<tr>';
            echo '<td colspan="11" style="text-align: center; padding: 40px; color: #666;">';
            echo '<p style="font-size: 16px;">Brak lotów do wyświetlenia</p>';
            if ($search || $status_filter || $date_from || $date_to) {
                echo '<p>' . SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=srl-wykupione-loty'), 'Wyczyść filtry') . '</p>';
            }
            echo '</td>';
            echo '</tr>';
        } else {
            foreach ($loty as $lot) {
                $telefon = get_user_meta($lot['user_id'], 'srl_telefon', true);
                $order_url = admin_url('post.php?post=' . $lot['order_id'] . '&action=edit');
                
                echo '<tr>';
                echo '<th scope="row" class="check-column">';
                echo '<input type="checkbox" name="loty_ids[]" value="' . $lot['id'] . '">';
                echo '</th>';
                
                echo '<td>';
                echo '<strong>ID lotu: #' . $lot['id'] . '</strong><br>';
                if ($lot['order_id'] == 0) {
                    echo '<small style="color:#666;font-style:italic;">dod. ręcznie</small>';
                } else {
                    echo '<small>';
                    echo 'Nr. zam: ';
                    echo SRL_Helpers::getInstance()->generateLink(
                        $order_url,
                        '#' . $lot['order_id'],
                        '',
                        ['target' => '_blank', 'style' => 'color:#0073aa;']
                    );
                    echo '</small>';
                }
                echo '</td>';
                
                echo '<td>';
                echo '<strong>';
                echo SRL_Helpers::getInstance()->generateLink(
                    admin_url('admin.php?page=wc-orders&customer=' . $lot['user_id']),
                    esc_html($lot['user_email']),
                    '',
                    array('target' => '_blank', 'style' => 'color: #0073aa; text-decoration: none;')
                );
                echo '</strong>';
                
                if ($telefon) {
                    echo '<br><small>📞 ' . esc_html($telefon) . '</small>';
                }
                echo '</td>';
                
                echo '<td>';
                echo '<strong>Lot w tandemie</strong>';
                echo '<br><small style="font-weight: bold;">' . SRL_Helpers::getInstance()->formatFlightOptionsHtml($lot['ma_filmowanie'], $lot['ma_akrobacje']) . '</small>';
                echo '</td>';
                
                echo '<td>';
                echo SRL_Helpers::getInstance()->generateStatusBadge($lot['status'], 'lot');
                echo '</td>';
                
                echo '<td>';
                echo SRL_Helpers::getInstance()->formatujDate($lot['data_zakupu']);
                echo '</td>';
                
                echo '<td>';
                echo SRL_Helpers::getInstance()->formatujWaznoscLotu($lot['data_waznosci']);
                echo '</td>';
                
                echo '<td>';
                if ($lot['status'] === 'zarezerwowany' && $lot['data_lotu']) {
                    echo '<strong>' . SRL_Helpers::getInstance()->formatujDate($lot['data_lotu']) . '</strong>';
                    echo '<br><small>' . substr($lot['godzina_start'], 0, 5) . ' - ' . substr($lot['godzina_koniec'], 0, 5) . '</small>';
                    if ($lot['data_rezerwacji']) {
                        echo '<br><small style="color: #666;">Rez: ' . date('d.m.Y H:i', strtotime($lot['data_rezerwacji'])) . '</small>';
                    }
                } elseif ($lot['status'] === 'zrealizowany' && $lot['data_lotu']) {
                    echo '<span style="color: #46b450;">✅ ' . SRL_Helpers::getInstance()->formatujDate($lot['data_lotu']) . '</span>';
                    echo '<br><small style="color: #46b450;">' . substr($lot['godzina_start'], 0, 5) . ' - ' . substr($lot['godzina_koniec'], 0, 5) . '</small>';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                echo '</td>';
                
                echo '<td>';
                if ($lot['status'] !== 'wolny') {
                    echo SRL_Helpers::getInstance()->generateButton('Odwołaj', 'button button-small srl-cancel-lot', array(
                        'type' => 'button',
                        'data-lot-id' => $lot['id']
                    ));
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                echo '</td>';
                
                echo '<td>';
                if ($lot['status'] === 'zarezerwowany') {
                    echo SRL_Helpers::getInstance()->generateButton('Zrealizuj', 'button button-primary button-small srl-complete-lot', array(
                        'type' => 'button',
                        'data-lot-id' => $lot['id']
                    ));
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                echo '</td>';
                
                echo '<td>';
                echo SRL_Helpers::getInstance()->generateButton('INFO', 'button button-small srl-info-lot', array(
                    'type' => 'button',
                    'data-lot-id' => $lot['id'],
                    'data-user-id' => $lot['user_id']
                ));
                echo SRL_Helpers::getInstance()->generateButton('Historia', 'button button-small srl-historia-lot', array(
                    'type' => 'button',
                    'data-lot-id' => $lot['id'],
                    'style' => 'margin-left: 5px;'
                ));
                echo '</td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</form>';
        echo '</div>';
        
        self::renderStyles();
        self::renderScripts();
    }

    private static function renderStyles() {
        echo '<style>
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
            font-family: "Courier New", monospace;
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

        .srl-status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

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
        </style>';
    }

    private static function renderScripts() {
        $nonce = wp_create_nonce('srl_admin_nonce');
        
        echo '<script>
jQuery(document).ready(function($) {
    $("#cb-select-all-1").on("change", function() {
        $("input[name=\"loty_ids[]\"]").prop("checked", $(this).is(":checked"));
    });
    
    $(".srl-cancel-lot").on("click", function() {
        if (!confirm("Czy na pewno chcesz odwołać ten lot?")) return;
        
        var lotId = $(this).data("lot-id");
        var button = $(this);
        
        button.prop("disabled", true).text("Odwołując...");
        
        $.post(ajaxurl, {
            action: "srl_admin_zmien_status_lotu",
            lot_id: lotId,
            nowy_status: "wolny",
            nonce: "' . $nonce . '"
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert("Błąd: " + response.data);
                button.prop("disabled", false).text("Odwołaj");
            }
        });
    });
    
    $(".srl-complete-lot").on("click", function() {
        if (!confirm("Czy na pewno chcesz oznaczyć ten lot jako zrealizowany?")) return;
        
        var lotId = $(this).data("lot-id");
        var button = $(this);
        
        button.prop("disabled", true).text("Realizując...");
        
        $.post(ajaxurl, {
            action: "srl_admin_zmien_status_lotu",
            lot_id: lotId,
            nowy_status: "zrealizowany",
            nonce: "' . $nonce . '"
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert("Błąd: " + response.data);
                button.prop("disabled", false).text("Zrealizuj");
            }
        });
    });
    
    $(".srl-info-lot").on("click", function() {
        var lotId = $(this).data("lot-id");
        var userId = $(this).data("user-id");
        
        $.post(ajaxurl, {
            action: "srl_pobierz_szczegoly_lotu",
            lot_id: lotId,
            user_id: userId,
            nonce: "' . $nonce . '"
        }, function(response) {
            if (response.success) {
                var info = response.data;
                var content = "<div style=\"max-width: 500px;\">";
                content += "<h3>Szczegóły lotu #" + lotId + "</h3>";
                content += "<p><strong>Imię:</strong> " + (info.imie || "Brak danych") + "</p>";
                content += "<p><strong>Nazwisko:</strong> " + (info.nazwisko || "Brak danych") + "</p>";
                content += "<p><strong>Rok urodzenia:</strong> " + (info.rok_urodzenia || "Brak danych") + "</p>";
                if (info.wiek) {
                    content += "<p><strong>Wiek:</strong> " + info.wiek + " lat</p>";
                }
                content += "<p><strong>Telefon:</strong> " + (info.telefon || "Brak danych") + "</p>";
                content += "<p><strong>Sprawność fizyczna:</strong> " + (info.sprawnosc_fizyczna || "Brak danych") + "</p>";
                content += "<p><strong>Kategoria wagowa:</strong> " + (info.kategoria_wagowa || "Brak danych") + "</p>";
                if (info.uwagi) {
                    content += "<p><strong>Uwagi:</strong> " + info.uwagi + "</p>";
                }
                content += "</div>";
                
                var modal = $("<div style=\"position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;\">" +
                    "<div style=\"background: white; padding: 30px; border-radius: 8px; max-width: 90%; max-height: 90%; overflow-y: auto;\">" +
                    content +
                    "<button style=\"margin-top: 20px; padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;\">Zamknij</button>" +
                    "</div></div>");
                
                $("body").append(modal);
                
                modal.find("button").on("click", function() {
                    modal.remove();
                    $(document).off("keydown.srl-info-modal");
                });

                $(document).on("keydown.srl-info-modal", function(e) {
                    if (e.keyCode === 27) {
                        modal.remove();
                        $(document).off("keydown.srl-info-modal");
                    }
                });

                modal.on("remove", function() {
                    $(document).off("keydown.srl-info-modal");
                });
            } else {
                alert("Błąd: " + response.data);
            }
        });
    });
    
    $("#srl-date-range-btn").on("click", function(e) {
        e.preventDefault();
        e.stopPropagation();
        $("#srl-date-range-panel").toggle();
    });
    
    $("#srl-close-panel").on("click", function(e) {
        e.preventDefault();
        $("#srl-date-range-panel").hide();
    });
    
    $("#srl-clear-dates").on("click", function(e) {
        e.preventDefault();
        $("input[name=\"date_from\"]").val("");
        $("input[name=\"date_to\"]").val("");
        $("#srl-date-range-panel").hide();
        $("#srl-date-range-btn").html("📅 Wybierz zakres daty lotu");
    });
    
    $(document).on("click", function(e) {
        if (!$(e.target).closest("#srl-date-range-btn, #srl-date-range-panel").length) {
            $("#srl-date-range-panel").hide();
        }
    });
    
    $("input[name=\"date_from\"], input[name=\"date_to\"]").on("change", function() {
        var dateFrom = $("input[name=\"date_from\"]").val();
        var dateTo = $("input[name=\"date_to\"]").val();
        var buttonText = "📅 ";
        
        if (dateFrom || dateTo) {
            if (dateFrom) buttonText += formatDate(dateFrom);
            if (dateFrom && dateTo) buttonText += " - ";
            if (dateTo) buttonText += formatDate(dateTo);
        } else {
            buttonText += "Wybierz zakres daty lotu";
        }
        
        $("#srl-date-range-btn").html(buttonText);
    });
    
    function formatDate(dateStr) {
        var date = new Date(dateStr);
        return date.toLocaleDateString("pl-PL");
    }
    
    $(".srl-historia-lot").on("click", function() {
        var lotId = $(this).data("lot-id");
        
        $.post(ajaxurl, {
            action: "srl_pobierz_historie_lotu",
            lot_id: lotId,
            nonce: "' . $nonce . '"
        }, function(response) {
            if (response.success) {
                pokazHistorieLotu(response.data);
            } else {
                alert("Błąd: " + response.data);
            }
        });
    });

    function pokazHistorieLotu(historia) {
        var content = "<div class=\"srl-historia-container\">";
        content += "<h3 style=\"margin-top: 0; color: #0073aa; border-bottom: 2px solid #0073aa; padding-bottom: 10px;\">📋 Historia lotu #" + historia.lot_id + "</h3>";
        
        if (historia.events.length === 0) {
            content += "<p style=\"text-align: center; color: #6c757d; padding: 40px;\">Brak zdarzeń w historii tego lotu.</p>";
        } else {
            content += "<table class=\"srl-historia-table\">";
            content += "<thead><tr><th>Data</th><th>Akcja</th><th>Wykonawca</th><th>Szczegóły</th></tr></thead>";
            content += "<tbody>";
            
            historia.events.forEach(function(event) {
                var rowClass = "srl-historia-row";
                if (event.action_name === "ZMIANA STATUSU") {
                    rowClass += " srl-historia-zmiana_statusu";
                } else if (event.action_name === "DOKUPIENIE WARIANTU") {
                    rowClass += " srl-historia-dokupienie";
                } else if (event.action_name === "SYSTEMOWE") {
                    rowClass += " srl-historia-systemowe";
                } else if (event.action_name === "ZMIANA DANYCH") {
                    rowClass += " srl-historia-zmiana_danych";
                }
                
                content += "<tr class=\"" + rowClass + "\">";
                content += "<td class=\"srl-historia-data\">" + event.formatted_date + "</td>";
                content += "<td class=\"srl-historia-akcja\">" + event.action_name + "</td>";
                content += "<td class=\"srl-historia-wykonawca\">" + event.executor + "</td>";
                content += "<td class=\"srl-historia-szczegoly\">" + event.details + "</td>";
                content += "</tr>";
            });
            
            content += "</tbody></table>";
        }
        
        content += "</div>";
        
        var modal = $("<div class=\"srl-modal-historia\">" +
            "<div class=\"srl-modal-content\">" +
            content +
            "<div class=\"srl-modal-actions\"><button class=\"button button-primary srl-modal-close\">Zamknij</button></div>" +
            "</div></div>");
        
        $("body").append(modal);
        
        modal.find(".srl-modal-close").on("click", function() {
            modal.remove();
            $(document).off("keydown.srl-modal");
        });
        
        modal.on("click", function(e) {
            if (e.target === this) {
                modal.remove();
                $(document).off("keydown.srl-modal");
            }
        });
        
        $(document).on("keydown.srl-modal", function(e) {
            if (e.keyCode === 27) {
                modal.remove();
                $(document).off("keydown.srl-modal");
            }
        });
    }
});
</script>';
    }
}