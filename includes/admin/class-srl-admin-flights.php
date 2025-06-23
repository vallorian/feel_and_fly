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
        
        $stats = self::getCachedStats();
        $filters = self::parseFilters();
        
        if (isset($_POST['action']) && isset($_POST['loty_ids'])) {
            self::processBulkActions($_POST['action'], $_POST['loty_ids']);
            wp_cache_delete('flights_stats', 'srl_admin_cache');
        }
        
        $pagination = self::getPaginationData($filters);
        $loty = self::getFlightsWithCache($filters, $pagination);
        
        self::renderFlightsPage($stats, $filters, $pagination, $loty);
    }

    private static function getCachedStats() {
        $cache_key = 'flights_stats';
        $stats = wp_cache_get($cache_key, 'srl_admin_cache');
        
        if ($stats === false) {
            global $wpdb;
            $stats = $wpdb->get_results(
                "SELECT status, COUNT(*) as count 
                 FROM {$wpdb->prefix}srl_zakupione_loty 
                 GROUP BY status",
                ARRAY_A
            );
            wp_cache_set($cache_key, $stats, 'srl_admin_cache', 600);
        }
        
        return $stats;
    }

    private static function parseFilters() {
        return array(
            'status_filter' => sanitize_text_field($_GET['status_filter'] ?? ''),
            'search' => sanitize_text_field($_GET['s'] ?? ''),
            'search_field' => sanitize_text_field($_GET['search_field'] ?? 'wszedzie'),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? '')
        );
    }

    private static function getPaginationData($filters) {
        $per_page = 50;
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;
        
        global $wpdb;
        $where_clause = self::buildWhereClause($filters);
        $where_params = $where_clause['params'];
        
        $count_query = "
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}srl_zakupione_loty zl
            LEFT JOIN {$wpdb->prefix}srl_terminy t ON zl.termin_id = t.id
            LEFT JOIN {$wpdb->users} u ON zl.user_id = u.ID
            LEFT JOIN {$wpdb->posts} o ON zl.order_id = o.ID
            {$where_clause['clause']}
        ";

        $cache_key = 'flights_count_' . md5(serialize($where_params));
        $total_items = wp_cache_get($cache_key, 'srl_admin_cache');
        
        if ($total_items === false) {
            $total_items = !empty($where_params) 
                ? $wpdb->get_var($wpdb->prepare($count_query, ...$where_params))
                : $wpdb->get_var($count_query);
            wp_cache_set($cache_key, $total_items, 'srl_admin_cache', 300);
        }

        return array(
            'per_page' => $per_page,
            'current_page' => $current_page,
            'offset' => $offset,
            'total_items' => $total_items,
            'total_pages' => ceil($total_items / $per_page)
        );
    }

    private static function buildWhereClause($filters) {
        $where_conditions = array();
        $where_params = array();

        if ($filters['status_filter']) {
            $where_conditions[] = "zl.status = %s";
            $where_params[] = $filters['status_filter'];
        }

        if ($filters['date_from'] && $filters['date_to']) {
            $where_conditions[] = "t.data BETWEEN %s AND %s";
            $where_params = array_merge($where_params, [$filters['date_from'], $filters['date_to']]);
        } elseif ($filters['date_from']) {
            $where_conditions[] = "t.data >= %s";
            $where_params[] = $filters['date_from'];
        } elseif ($filters['date_to']) {
            $where_conditions[] = "t.data <= %s";
            $where_params[] = $filters['date_to'];
        }

        if ($filters['search']) {
            $search_condition = self::buildSearchCondition($filters['search_field'], $filters['search']);
            $where_conditions[] = $search_condition['condition'];
            $where_params = array_merge($where_params, $search_condition['params']);
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        return array('clause' => $where_clause, 'params' => $where_params);
    }

    private static function buildSearchCondition($search_field, $search) {
        switch ($search_field) {
            case 'id_lotu':
                return array('condition' => "zl.id = %s", 'params' => [$search]);
            case 'id_zamowienia':
                return array('condition' => "zl.order_id = %s", 'params' => [$search]);
            case 'email':
                return array('condition' => "u.user_email LIKE %s", 'params' => ['%' . $search . '%']);
            case 'telefon':
                return array(
                    'condition' => "EXISTS (SELECT 1 FROM {$GLOBALS['wpdb']->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s)",
                    'params' => ['%' . $search . '%']
                );
            case 'imie_nazwisko':
                $search_param = '%' . $search . '%';
                return array(
                    'condition' => "(zl.imie LIKE %s OR zl.nazwisko LIKE %s OR CONCAT(zl.imie, ' ', zl.nazwisko) LIKE %s)",
                    'params' => [$search_param, $search_param, $search_param]
                );
            case 'login':
                return array('condition' => "u.user_login LIKE %s", 'params' => ['%' . $search . '%']);
            default:
                $search_param = '%' . $search . '%';
                return array(
                    'condition' => "(zl.id LIKE %s OR zl.order_id LIKE %s OR zl.imie LIKE %s OR zl.nazwisko LIKE %s OR zl.nazwa_produktu LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR EXISTS (SELECT 1 FROM {$GLOBALS['wpdb']->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s))",
                    'params' => [$search, $search, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param]
                );
        }
    }

    private static function getFlightsWithCache($filters, $pagination) {
        $cache_key = 'flights_data_' . md5(serialize($filters) . serialize($pagination));
        $loty = wp_cache_get($cache_key, 'srl_admin_cache');
        
        if ($loty === false) {
            $loty = self::fetchFlights($filters, $pagination);
            wp_cache_set($cache_key, $loty, 'srl_admin_cache', 300);
        }
        
        return $loty;
    }

    private static function fetchFlights($filters, $pagination) {
        global $wpdb;
        $where_clause = self::buildWhereClause($filters);
        
        $query = "
            SELECT zl.*, 
                   t.data as data_lotu, 
                   t.godzina_start, 
                   t.godzina_koniec,
                   u.user_email,
                   o.post_status as order_status
            FROM {$wpdb->prefix}srl_zakupione_loty zl
            LEFT JOIN {$wpdb->prefix}srl_terminy t ON zl.termin_id = t.id
            LEFT JOIN {$wpdb->users} u ON zl.user_id = u.ID
            LEFT JOIN {$wpdb->posts} o ON zl.order_id = o.ID
            {$where_clause['clause']}
            ORDER BY zl.data_zakupu DESC
            LIMIT %d OFFSET %d
        ";

        $query_params = array_merge($where_clause['params'], [$pagination['per_page'], $pagination['offset']]);
        return $wpdb->get_results($wpdb->prepare($query, ...$query_params), ARRAY_A);
    }

    private static function processBulkActions($action, $lot_ids) {
        $ids = array_map('intval', $lot_ids);
        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        
        if ($action === 'bulk_delete') {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $tabela_loty WHERE id IN ($placeholders)",
                ...$ids
            ));
            echo SRL_Helpers::getInstance()->generateMessage('Usuni?to ' . count($ids) . ' lot車w.', 'success');
        } 
        elseif (strpos($action, 'bulk_status_') === 0) {
            $nowy_status = str_replace('bulk_status_', '', $action);
            self::updateMultipleFlightStatuses($ids, $nowy_status, $tabela_loty, $tabela_terminy);
            echo SRL_Helpers::getInstance()->generateMessage('Zmieniono status ' . count($ids) . ' lot車w na "' . $nowy_status . '".', 'success');
        }
    }

    private static function updateMultipleFlightStatuses($ids, $nowy_status, $tabela_loty, $tabela_terminy) {
        global $wpdb;
        
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
    }

    private static function renderFlightsPage($stats, $filters, $pagination, $loty) {
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">?? Wykupione loty tandemowe</h1>';
        
        self::renderStatsSection($stats);
        self::renderFiltersSection($filters);
        self::renderFlightsTable($loty, $pagination, $filters);
        
        echo '</div>';
        
        self::renderStyles();
        self::renderScripts();
    }

    private static function renderStatsSection($stats) {
        echo '<div class="srl-stats" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">';
        $status_labels = [
            'wolny' => '?? Wolne',
            'zarezerwowany' => '?? Zarezerwowane', 
            'zrealizowany' => '?? Zrealizowane',
            'przedawniony' => '?? Przedawnione'
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
    }

    private static function renderFiltersSection($filters) {
        echo '<div class="tablenav top">';
        echo '<form method="get" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">';
        echo '<input type="hidden" name="page" value="srl-wykupione-loty">';
        
        echo SRL_Helpers::getInstance()->generateSelect('status_filter', array_merge(
            ['' => 'Wszystkie statusy'],
            [
                'wolny' => '?? Wolne',
                'zarezerwowany' => '?? Zarezerwowane', 
                'zrealizowany' => '?? Zrealizowane',
                'przedawniony' => '?? Przedawnione'
            ]
        ), $filters['status_filter']);
        
        echo SRL_Helpers::getInstance()->generateSelect('search_field', [
            'wszedzie' => 'Wsz?dzie',
            'email' => 'Email',
            'id_lotu' => 'ID lotu',
            'id_zamowienia' => 'ID zam車wienia',
            'imie_nazwisko' => 'Imi? i nazwisko',
            'login' => 'Login',
            'telefon' => 'Telefon'
        ], $filters['search_field']);

        self::renderDateRangeFilter($filters);

        echo '<input type="search" name="s" value="' . esc_attr($filters['search']) . '" placeholder="Wprowad? szukan? fraz?...">';
        echo SRL_Helpers::getInstance()->generateButton('Filtruj', 'button', array('type' => 'submit'));
        
        if ($filters['status_filter'] || $filters['search'] || $filters['date_from'] || $filters['date_to']) {
            echo SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=srl-wykupione-loty'), 'Wyczy?? filtry', 'button');
        }
        echo '</form>';
        echo '</div>';
    }

    private static function renderDateRangeFilter($filters) {
        echo '<button type="button" id="srl-date-range-btn" class="button" style="margin-left: 5px;">';
        if ($filters['date_from'] || $filters['date_to']) {
            echo '?? ' . ($filters['date_from'] ? SRL_Helpers::getInstance()->formatujDate($filters['date_from']) : '') . 
                 (($filters['date_from'] && $filters['date_to']) ? ' - ' : '') . 
                 ($filters['date_to'] ? SRL_Helpers::getInstance()->formatujDate($filters['date_to']) : '');
        } else {
            echo '?? Wybierz zakres daty lotu';
        }
        echo '</button>';

        echo '<div id="srl-date-range-panel" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 4px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; margin-top: 5px;">';
        echo '<div style="margin-bottom: 10px;">';
        echo '<label>Data od:</label><br>';
        echo '<input type="date" name="date_from" value="' . esc_attr($filters['date_from']) . '" style="width: 150px;">';
        echo '</div>';
        echo '<div style="margin-bottom: 15px;">';
        echo '<label>Data do:</label><br>';
        echo '<input type="date" name="date_to" value="' . esc_attr($filters['date_to']) . '" style="width: 150px;">';
        echo '</div>';
        echo '<div>';
        echo SRL_Helpers::getInstance()->generateButton('Wyczy??', 'button', array('type' => 'button', 'id' => 'srl-clear-dates', 'style' => 'margin-right: 10px;'));
        echo SRL_Helpers::getInstance()->generateButton('OK', 'button button-primary', array('type' => 'button', 'id' => 'srl-close-panel'));
        echo '</div>';
        echo '</div>';
    }

    private static function renderFlightsTable($loty, $pagination, $filters) {
        echo '<form method="post" id="bulk-action-form">';
        self::renderBulkActions($pagination);
        
        echo '<table class="wp-list-table widefat striped">';
        self::renderTableHeader();
        self::renderTableBody($loty, $filters);
        echo '</table>';
        echo '</form>';
    }

    private static function renderBulkActions($pagination) {
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions bulkactions">';
        echo SRL_Helpers::getInstance()->generateSelect('action', [
            '' => 'Akcje grupowe',
            'bulk_delete' => 'Usu里 zaznaczone',
            'bulk_status_wolny' => 'Zmie里 status na: Wolny',
            'bulk_status_zarezerwowany' => 'Zmie里 status na: Zarezerwowany',
            'bulk_status_zrealizowany' => 'Zmie里 status na: Zrealizowany',
            'bulk_status_przedawniony' => 'Zmie里 status na: Przedawniony'
        ], '');
        echo SRL_Helpers::getInstance()->generateButton('Zastosuj', 'button action', array(
            'type' => 'submit',
            'onclick' => "return confirm('Czy na pewno chcesz wykona? t? akcj? na zaznaczonych lotach?')"
        ));
        echo '</div>';
        
        if ($pagination['total_pages'] > 1) {
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . $pagination['total_items'] . ' element車w</span>';
            $page_links = paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $pagination['total_pages'],
                'current' => $pagination['current_page']
            ));
            echo $page_links;
            echo '</div>';
        }
        echo '</div>';
    }

    private static function renderTableHeader() {
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
        echo '<th scope="col" class="manage-column">Wa?no??</th>';
        echo '<th scope="col" class="manage-column">Rezerwacja</th>';
        echo '<th scope="col" class="manage-column">Odwo?ywanie</th>';
        echo '<th scope="col" class="manage-column">Zatwierdzanie</th>';
        echo '<th scope="col" class="manage-column">Szczeg車?y</th>';
        echo '</tr>';
        echo '</thead>';
    }

    private static function renderTableBody($loty, $filters) {
        echo '<tbody>';
        
        if (empty($loty)) {
            echo '<tr>';
            echo '<td colspan="11" style="text-align: center; padding: 40px; color: #666;">';
            echo '<p style="font-size: 16px;">Brak lot車w do wy?wietlenia</p>';
            if ($filters['search'] || $filters['status_filter'] || $filters['date_from'] || $filters['date_to']) {
                echo '<p>' . SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=srl-wykupione-loty'), 'Wyczy?? filtry') . '</p>';
            }
            echo '</td>';
            echo '</tr>';
        } else {
            foreach ($loty as $lot) {
                self::renderFlightRow($lot);
            }
        }
        
        echo '</tbody>';
    }

    private static function renderFlightRow($lot) {
        $telefon = get_user_meta($lot['user_id'], 'srl_telefon', true);
        $order_url = admin_url('post.php?post=' . $lot['order_id'] . '&action=edit');
        
        echo '<tr>';
        echo '<th scope="row" class="check-column">';
        echo '<input type="checkbox" name="loty_ids[]" value="' . $lot['id'] . '">';
        echo '</th>';
        
        echo '<td>';
        echo '<strong>ID lotu: #' . $lot['id'] . '</strong><br>';
        if ($lot['order_id'] == 0) {
            echo '<small style="color:#666;font-style:italic;">dod. r?cznie</small>';
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
            echo '<br><small>?? ' . esc_html($telefon) . '</small>';
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
        
        self::renderReservationColumn($lot);
        self::renderCancellationColumn($lot);
        self::renderApprovalColumn($lot);
        self::renderDetailsColumn($lot);
        
        echo '</tr>';
    }

    private static function renderReservationColumn($lot) {
        echo '<td>';
        if ($lot['status'] === 'zarezerwowany' && $lot['data_lotu']) {
            echo '<strong>' . SRL_Helpers::getInstance()->formatujDate($lot['data_lotu']) . '</strong>';
            echo '<br><small>' . substr($lot['godzina_start'], 0, 5) . ' - ' . substr($lot['godzina_koniec'], 0, 5) . '</small>';
            if ($lot['data_rezerwacji']) {
                echo '<br><small style="color: #666;">Rez: ' . date('d.m.Y H:i', strtotime($lot['data_rezerwacji'])) . '</small>';
            }
        } elseif ($lot['status'] === 'zrealizowany' && $lot['data_lotu']) {
            echo '<span style="color: #46b450;">? ' . SRL_Helpers::getInstance()->formatujDate($lot['data_lotu']) . '</span>';
            echo '<br><small style="color: #46b450;">' . substr($lot['godzina_start'], 0, 5) . ' - ' . substr($lot['godzina_koniec'], 0, 5) . '</small>';
        } else {
            echo '<span style="color: #999;">〞</span>';
        }
        echo '</td>';
    }

    private static function renderCancellationColumn($lot) {
        echo '<td>';
        if ($lot['status'] !== 'wolny') {
            echo SRL_Helpers::getInstance()->generateButton('Odwo?aj', 'button button-small srl-cancel-lot', array(
                'type' => 'button',
                'data-lot-id' => $lot['id']
            ));
        } else {
            echo '<span style="color:#999;">〞</span>';
        }
        echo '</td>';
    }

    private static function renderApprovalColumn($lot) {
        echo '<td>';
        if ($lot['status'] === 'zarezerwowany') {
            echo SRL_Helpers::getInstance()->generateButton('Zrealizuj', 'button button-primary button-small srl-complete-lot', array(
                'type' => 'button',
                'data-lot-id' => $lot['id']
            ));
        } else {
            echo '<span style="color:#999;">〞</span>';
        }
        echo '</td>';
    }

    private static function renderDetailsColumn($lot) {
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
        if (!confirm("Czy na pewno chcesz odwo?a? ten lot?")) return;
        
        var lotId = $(this).data("lot-id");
        var button = $(this);
        
        button.prop("disabled", true).text("Odwo?uj?c...");
        
        $.post(ajaxurl, {
            action: "srl_admin_zmien_status_lotu",
            lot_id: lotId,
            nowy_status: "wolny",
            nonce: "' . $nonce . '"
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert("B??d: " + response.data);
                button.prop("disabled", false).text("Odwo?aj");
            }
        });
    });
    
    $(".srl-complete-lot").on("click", function() {
        if (!confirm("Czy na pewno chcesz oznaczy? ten lot jako zrealizowany?")) return;
        
        var lotId = $(this).data("lot-id");
        var button = $(this);
        
        button.prop("disabled", true).text("Realizuj?c...");
        
        $.post(ajaxurl, {
            action: "srl_admin_zmien_status_lotu",
            lot_id: lotId,
            nowy_status: "zrealizowany",
            nonce: "' . $nonce . '"
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert("B??d: " + response.data);
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
                content += "<h3>Szczeg車?y lotu #" + lotId + "</h3>";
                content += "<p><strong>Imi?:</strong> " + (info.imie || "Brak danych") + "</p>";
                content += "<p><strong>Nazwisko:</strong> " + (info.nazwisko || "Brak danych") + "</p>";
                content += "<p><strong>Rok urodzenia:</strong> " + (info.rok_urodzenia || "Brak danych") + "</p>";
                if (info.wiek) {
                    content += "<p><strong>Wiek:</strong> " + info.wiek + " lat</p>";
                }
                content += "<p><strong>Telefon:</strong> " + (info.telefon || "Brak danych") + "</p>";
                content += "<p><strong>Sprawno?? fizyczna:</strong> " + (info.sprawnosc_fizyczna || "Brak danych") + "</p>";
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
                alert("B??d: " + response.data);
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
        $("#srl-date-range-btn").html("?? Wybierz zakres daty lotu");
    });
    
    $(document).on("click", function(e) {
        if (!$(e.target).closest("#srl-date-range-btn, #srl-date-range-panel").length) {
            $("#srl-date-range-panel").hide();
        }
    });
    
    $("input[name=\"date_from\"], input[name=\"date_to\"]").on("change", function() {
        var dateFrom = $("input[name=\"date_from\"]").val();
        var dateTo = $("input[name=\"date_to\"]").val();
        var buttonText = "?? ";
        
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
                alert("B??d: " + response.data);
            }
        });
    });

    function pokazHistorieLotu(historia) {
        var content = "<div class=\"srl-historia-container\">";
        content += "<h3 style=\"margin-top: 0; color: #0073aa; border-bottom: 2px solid #0073aa; padding-bottom: 10px;\">?? Historia lotu #" + historia.lot_id + "</h3>";
        
        if (historia.events.length === 0) {
            content += "<p style=\"text-align: center; color: #6c757d; padding: 40px;\">Brak zdarze里 w historii tego lotu.</p>";
        } else {
            content += "<table class=\"srl-historia-table\">";
            content += "<thead><tr><th>Data</th><th>Akcja</th><th>Wykonawca</th><th>Szczeg車?y</th></tr></thead>";
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