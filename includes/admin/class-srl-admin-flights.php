<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Admin_Flights {
    private static $instance = null;
    private $cache_manager;
    private $tables;
    private $db_helpers;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->cache_manager = SRL_Cache_Manager::getInstance();
        $this->tables = SRL_Admin_Tables::getInstance();
        $this->db_helpers = SRL_Database_Helpers::getInstance();
    }

    public static function wyswietlWykupioneLoty() {
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        $instance = self::getInstance();
        $instance->handleBulkActions();
        
        $pagination = $instance->getPaginationData();
        $flights = $instance->getFlightsWithCache($pagination);
        $stats = $instance->getFlightStatsWithCache();
        
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">?? Wykupione loty tandemowe</h1>';
        
        $status_labels = [
            'wolny' => '?? Wolne',
            'zarezerwowany' => '?? Zarezerwowane', 
            'zrealizowany' => '?? Zrealizowane',
            'przedawniony' => '?? Przedawnione'
        ];
        
        echo $instance->tables->renderStats($stats, $status_labels);
        $instance->renderFilters($pagination);
        
        echo '<form method="post" id="bulk-action-form">';
        $instance->renderBulkActions();
        echo $instance->tables->renderFlightsTable($flights, $pagination);
        echo '</form>';
        echo '</div>';
        
        $instance->renderScripts();
    }

    private function handleBulkActions() {
        if (!isset($_POST['action']) || !isset($_POST['loty_ids'])) return;
        
        $ids = array_map('intval', $_POST['loty_ids']);
        $action = $_POST['action'];
        
        if ($action === 'bulk_delete') {
            $this->batchDeleteFlights($ids);
            echo SRL_Helpers::getInstance()->generateMessage('Usuni?to ' . count($ids) . ' lot車w.', 'success');
        } elseif (strpos($action, 'bulk_status_') === 0) {
            $new_status = str_replace('bulk_status_', '', $action);
            $this->batchUpdateFlightStatus($ids, $new_status);
            echo SRL_Helpers::getInstance()->generateMessage('Zmieniono status ' . count($ids) . ' lot車w na "' . $new_status . '".', 'success');
        }
    }

    private function batchDeleteFlights($ids) {
        if (empty($ids)) return;
        
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}srl_zakupione_loty WHERE id IN ($placeholders)",
            ...$ids
        ));
        
        $this->invalidateFlightCaches();
    }

    private function batchUpdateFlightStatus($ids, $new_status) {
        if (empty($ids)) return;
        
        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($ids as $lot_id) {
                $lot = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $tabela_loty WHERE id = %d", $lot_id
                ), ARRAY_A);
                
                if (!$lot) continue;
                
                if ($lot['status'] === 'zarezerwowany' && $lot['termin_id'] && $new_status === 'wolny') {
                    $wpdb->update(
                        $tabela_terminy,
                        ['status' => 'Wolny', 'klient_id' => null],
                        ['id' => $lot['termin_id']],
                        ['%s', '%d'], ['%d']
                    );
                    
                    $wpdb->update(
                        $tabela_loty,
                        ['status' => $new_status, 'termin_id' => null, 'data_rezerwacji' => null],
                        ['id' => $lot_id],
                        ['%s', '%d', '%s'], ['%d']
                    );
                } else {
                    $wpdb->update(
                        $tabela_loty,
                        ['status' => $new_status],
                        ['id' => $lot_id],
                        ['%s'], ['%d']
                    );
                }
            }
            
            $wpdb->query('COMMIT');
            $this->invalidateFlightCaches();
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private function getPaginationData() {
        return [
            'per_page' => 20,
            'current_page' => max(1, intval($_GET['paged'] ?? 1)),
            'status_filter' => sanitize_text_field($_GET['status_filter'] ?? ''),
            'search' => sanitize_text_field($_GET['s'] ?? ''),
            'search_field' => sanitize_text_field($_GET['search_field'] ?? 'wszedzie'),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? '')
        ];
    }

    private function getFlightsWithCache($pagination) {
        $cache_key = "flights_admin_" . md5(serialize($pagination));
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $tabela_loty = $wpdb->prefix . 'srl_zakupione_loty';
        $tabela_terminy = $wpdb->prefix . 'srl_terminy';
        
        $where_conditions = [];
        $where_params = [];
        
        if ($pagination['status_filter']) {
            $where_conditions[] = "zl.status = %s";
            $where_params[] = $pagination['status_filter'];
        }

        if ($pagination['date_from'] && $pagination['date_to']) {
            $where_conditions[] = "t.data BETWEEN %s AND %s";
            $where_params = array_merge($where_params, [$pagination['date_from'], $pagination['date_to']]);
        } elseif ($pagination['date_from']) {
            $where_conditions[] = "t.data >= %s";
            $where_params[] = $pagination['date_from'];
        } elseif ($pagination['date_to']) {
            $where_conditions[] = "t.data <= %s";
            $where_params[] = $pagination['date_to'];
        }

        if ($pagination['search']) {
            $search_condition = $this->buildSearchCondition($pagination['search_field'], $pagination['search']);
            if ($search_condition) {
                $where_conditions[] = $search_condition['condition'];
                $where_params = array_merge($where_params, $search_condition['params']);
            }
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        $offset = ($pagination['current_page'] - 1) * $pagination['per_page'];
        
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

        $main_query_params = array_merge($where_params, [$pagination['per_page'], $offset]);
        $flights = $this->db_helpers->executeQuery($query, $main_query_params);
        
        wp_cache_set($cache_key, $flights, 'srl_cache', 600);
        return $flights;
    }

    private function getFlightStatsWithCache() {
		global $wpdb;
        $cache_key = 'flight_admin_stats';
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $stats = $this->db_helpers->executeQuery(
            "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}srl_zakupione_loty GROUP BY status"
        );
        
        wp_cache_set($cache_key, $stats, 'srl_cache', 1800);
        return $stats;
    }

    private function buildSearchCondition($search_field, $query) {
        global $wpdb;
        
        switch ($search_field) {
            case 'id_lotu':
                return [
                    'condition' => 'zl.id = %s',
                    'params' => [$query]
                ];
            case 'id_zamowienia':
                return [
                    'condition' => 'zl.order_id = %s',
                    'params' => [$query]
                ];
            case 'email':
                return [
                    'condition' => 'u.user_email LIKE %s',
                    'params' => ['%' . $query . '%']
                ];
            case 'telefon':
                return [
                    'condition' => "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s)",
                    'params' => ['%' . $query . '%']
                ];
            case 'imie_nazwisko':
                return [
                    'condition' => '(zl.imie LIKE %s OR zl.nazwisko LIKE %s OR CONCAT(zl.imie, \' \', zl.nazwisko) LIKE %s)',
                    'params' => ['%' . $query . '%', '%' . $query . '%', '%' . $query . '%']
                ];
            case 'login':
                return [
                    'condition' => 'u.user_login LIKE %s',
                    'params' => ['%' . $query . '%']
                ];
            default:
                return [
                    'condition' => "(zl.id LIKE %s OR zl.order_id LIKE %s OR zl.imie LIKE %s OR zl.nazwisko LIKE %s OR zl.nazwa_produktu LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = zl.user_id AND um.meta_key = 'srl_telefon' AND um.meta_value LIKE %s))",
                    'params' => [$query, $query, '%' . $query . '%', '%' . $query . '%', '%' . $query . '%', '%' . $query . '%', '%' . $query . '%', '%' . $query . '%']
                ];
        }
    }

    private function renderFilters($pagination) {
        echo '<div class="tablenav top">';
        echo '<form method="get" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">';
        echo '<input type="hidden" name="page" value="srl-wykupione-loty">';
        
        echo SRL_Helpers::getInstance()->generateSelect('status_filter', [
            '' => 'Wszystkie statusy',
            'wolny' => '?? Wolne',
            'zarezerwowany' => '?? Zarezerwowane', 
            'zrealizowany' => '?? Zrealizowane',
            'przedawniony' => '?? Przedawnione'
        ], $pagination['status_filter']);
        
        echo SRL_Helpers::getInstance()->generateSelect('search_field', [
            'wszedzie' => 'Wsz?dzie',
            'email' => 'Email',
            'id_lotu' => 'ID lotu',
            'id_zamowienia' => 'ID zam車wienia',
            'imie_nazwisko' => 'Imi? i nazwisko',
            'login' => 'Login',
            'telefon' => 'Telefon'
        ], $pagination['search_field']);

        echo '<button type="button" id="srl-date-range-btn" class="button" style="margin-left: 5px;">';
        if ($pagination['date_from'] || $pagination['date_to']) {
            echo '?? ' . ($pagination['date_from'] ? SRL_Helpers::getInstance()->formatujDate($pagination['date_from']) : '') . 
                 (($pagination['date_from'] && $pagination['date_to']) ? ' - ' : '') . 
                 ($pagination['date_to'] ? SRL_Helpers::getInstance()->formatujDate($pagination['date_to']) : '');
        } else {
            echo '?? Wybierz zakres daty lotu';
        }
        echo '</button>';

        $this->renderDateRangePanel($pagination);

        echo '<input type="search" name="s" value="' . esc_attr($pagination['search']) . '" placeholder="Wprowad? szukan? fraz?...">';
        echo SRL_Helpers::getInstance()->generateButton('Filtruj', 'button', ['type' => 'submit']);
        
        if ($pagination['status_filter'] || $pagination['search'] || $pagination['date_from'] || $pagination['date_to']) {
            echo SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=srl-wykupione-loty'), 'Wyczy?? filtry', 'button');
        }
        echo '</form></div>';
    }

    private function renderDateRangePanel($pagination) {
        echo '<div id="srl-date-range-panel" style="display: none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 4px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; margin-top: 5px;">';
        echo '<div style="margin-bottom: 10px;">';
        echo '<label>Data od:</label><br>';
        echo '<input type="date" name="date_from" value="' . esc_attr($pagination['date_from']) . '" style="width: 150px;">';
        echo '</div>';
        echo '<div style="margin-bottom: 15px;">';
        echo '<label>Data do:</label><br>';
        echo '<input type="date" name="date_to" value="' . esc_attr($pagination['date_to']) . '" style="width: 150px;">';
        echo '</div>';
        echo '<div>';
        echo SRL_Helpers::getInstance()->generateButton('Wyczy??', 'button', ['type' => 'button', 'id' => 'srl-clear-dates', 'style' => 'margin-right: 10px;']);
        echo SRL_Helpers::getInstance()->generateButton('OK', 'button button-primary', ['type' => 'button', 'id' => 'srl-close-panel']);
        echo '</div>';
        echo '</div>';
    }

    private function renderBulkActions() {
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
        echo SRL_Helpers::getInstance()->generateButton('Zastosuj', 'button action', [
            'type' => 'submit',
            'onclick' => "return confirm('Czy na pewno chcesz wykona? wybran? akcj??')"
        ]);
        echo '</div></div>';
    }

    private function renderScripts() {
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
                            "<button style=\"margin-top: 20px; padding: 10px 20px; background: #4263be; color: white; border: none; border-radius: 4px; cursor: pointer;\">Zamknij</button>" +
                            "</div></div>");
                        
                        $("body").append(modal);
                        
                        modal.find("button").on("click", function() {
                            modal.remove();
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
                var content = "<div style=\"max-width: 800px; background: white; border-radius: 8px; padding: 20px;\">";
                content += "<h3 style=\"margin-top: 0; color: #4263be; border-bottom: 2px solid #4263be; padding-bottom: 10px;\">?? Historia lotu #" + historia.lot_id + "</h3>";
                
                if (historia.events.length === 0) {
                    content += "<p style=\"text-align: center; color: #6c757d; padding: 40px;\">Brak zdarze里 w historii tego lotu.</p>";
                } else {
                    content += "<table style=\"width: 100%; border-collapse: collapse; margin-top: 15px;\">";
                    content += "<thead><tr style=\"background: #f8f9fa;\"><th style=\"padding: 12px 8px; text-align: left; border-bottom: 1px solid #e1e5e9;\">Data</th><th style=\"padding: 12px 8px; text-align: left; border-bottom: 1px solid #e1e5e9;\">Akcja</th><th style=\"padding: 12px 8px; text-align: left; border-bottom: 1px solid #e1e5e9;\">Wykonawca</th><th style=\"padding: 12px 8px; text-align: left; border-bottom: 1px solid #e1e5e9;\">Szczeg車?y</th></tr></thead>";
                    content += "<tbody>";
                    
                    historia.events.forEach(function(event) {
                        content += "<tr>";
                        content += "<td style=\"padding: 12px 8px; border-bottom: 1px solid #e1e5e9; font-family: monospace; color: #6c757d;\">" + event.formatted_date + "</td>";
                        content += "<td style=\"padding: 12px 8px; border-bottom: 1px solid #e1e5e9; font-weight: 600;\">" + event.action_name + "</td>";
                        content += "<td style=\"padding: 12px 8px; border-bottom: 1px solid #e1e5e9; color: #6c757d;\">" + event.executor + "</td>";
                        content += "<td style=\"padding: 12px 8px; border-bottom: 1px solid #e1e5e9; line-height: 1.4;\">" + event.details + "</td>";
                        content += "</tr>";
                    });
                    
                    content += "</tbody></table>";
                }
                
                content += "</div>";
                
                var modal = $("<div style=\"position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;\">" +
                    "<div style=\"max-width: 90%; max-height: 90%; overflow-y: auto;\">" +
                    content +
                    "<div style=\"text-align: right; padding: 20px; border-top: 1px solid #e1e5e9; background: #f8f9fa; border-radius: 0 0 8px 8px;\"><button class=\"button button-primary\">Zamknij</button></div>" +
                    "</div></div>");
                
                $("body").append(modal);
                
                modal.find("button").on("click", function() {
                    modal.remove();
                });
                
                modal.on("click", function(e) {
                    if (e.target === this) {
                        modal.remove();
                    }
                });
            }
        });
        </script>';
        
        echo '<style>
        .srl-stats { background: #f1f1f1; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .wp-list-table th, .wp-list-table td { vertical-align: top; }
        .wp-list-table small { color: #666; font-size: 12px; }
        </style>';
    }

    private function invalidateFlightCaches() {
        wp_cache_delete('flight_admin_stats', 'srl_cache');
        wp_cache_flush_group('srl_cache');
    }

    public function __destruct() {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('srl_cache');
        }
    }
}