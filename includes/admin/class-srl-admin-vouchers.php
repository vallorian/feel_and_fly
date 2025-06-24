<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Admin_Vouchers {
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
        add_action('admin_init', [$this, 'handleBulkActions']);
    }

    public static function wyswietlVouchery() {
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        $tab = $_GET['tab'] ?? 'partner';
        
        echo '<div class="wrap"><h1>Zarządzanie Voucherami</h1>';
        self::renderTabs($tab);
        
        if ($tab === 'partner') {
            self::getInstance()->displayPartnerVouchers();
        } else {
            self::getInstance()->displayGiftVouchers();
        }
        
        echo '</div>';
    }

    private function displayPartnerVouchers() {
        $current_status = $_GET['status_filter'] ?? '';
        $pagination = $this->getPartnerVoucherPagination();
        $vouchery = $this->getPartnerVouchersWithCache($pagination);
        
        echo '<div class="tablenav top">';
        echo SRL_Helpers::getInstance()->generateSelect('status_filter', [
            '' => 'Wszystkie statusy',
            'oczekuje' => 'Oczekuje',
            'zatwierdzony' => 'Zatwierdzony', 
            'odrzucony' => 'Odrzucony'
        ], $current_status, ['id' => 'status-filter']);
        echo '<button type="button" class="button" onclick="filterVouchers()">Filtruj</button>';
        echo '</div>';
        
        echo $this->tables->renderVouchersTable($vouchery, $pagination, 'partner');
        $this->renderPartnerVoucherModals();
        $this->renderPartnerVoucherScripts();
    }

    private function displayGiftVouchers() {
        echo '<h1 class="wp-heading-inline">Zakupione Vouchery</h1>';
        echo '<button type="button" class="page-title-action" id="srl-dodaj-voucher-recznie">Dodaj voucher ręcznie</button>';
        echo '<hr class="wp-header-end">';
        
        $pagination = $this->getGiftVoucherPagination();
        $vouchery = $this->getGiftVouchersWithCache($pagination);
        $stats = $this->getGiftVoucherStats();
        
        $status_labels = [
            'do_wykorzystania' => '🟢 Do wykorzystania',
            'wykorzystany' => '🔵 Wykorzystane',
            'przeterminowany' => '🔴 Przeterminowane'
        ];
        
        echo $this->tables->renderStats($stats, $status_labels);
        $this->renderGiftVoucherFilters($pagination);
        
        echo '<form method="post" id="srl-vouchers-form">';
        echo $this->tables->renderVouchersTable($vouchery, $pagination, 'upominkowe');
        echo '</form>';
        
        $this->renderGiftVoucherModals();
        $this->renderGiftVoucherScripts();
    }

    private function getPartnerVouchersWithCache($pagination) {
        $cache_key = "partner_vouchers_" . md5(serialize($pagination));
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $vouchers = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVouchers($pagination['status_filter'], 50);
        wp_cache_set($cache_key, $vouchers, 'srl_cache', 600);
        
        return $vouchers;
    }

    private function getGiftVouchersWithCache($pagination) {
        $cache_key = "gift_vouchers_" . md5(serialize($pagination));
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
        
        $where_conditions = [];
        $where_params = [];
        
        if ($pagination['status_filter']) {
            $where_conditions[] = "v.status = %s";
            $where_params[] = $pagination['status_filter'];
        }
        
        if ($pagination['search']) {
            $where_conditions[] = "(v.buyer_imie LIKE %s OR v.buyer_nazwisko LIKE %s OR v.kod_vouchera LIKE %s OR v.nazwa_produktu LIKE %s OR o.ID LIKE %s)";
            $search_param = '%' . $pagination['search'] . '%';
            $where_params = array_merge($where_params, array_fill(0, 5, $search_param));
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        $offset = ($pagination['current_page'] - 1) * $pagination['per_page'];
        
        $vouchers = $this->db_helpers->executeQuery(
            "SELECT v.*, buyer.user_email as buyer_email, user.user_email as user_email, 
                    user.display_name as user_display_name, o.post_status as order_status
             FROM $tabela v
             LEFT JOIN {$wpdb->users} buyer ON v.buyer_user_id = buyer.ID
             LEFT JOIN {$wpdb->users} user ON v.wykorzystany_przez_user_id = user.ID
             LEFT JOIN {$wpdb->posts} o ON v.order_id = o.ID
             $where_clause
             ORDER BY v.data_zakupu DESC
             LIMIT %d OFFSET %d",
            array_merge($where_params, [$pagination['per_page'], $offset])
        );
        
        wp_cache_set($cache_key, $vouchers, 'srl_cache', 600);
        return $vouchers;
    }

    private function getGiftVoucherStats() {
        $cache_key = 'gift_voucher_stats';
        $cached = wp_cache_get($cache_key, 'srl_cache');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $stats = $this->db_helpers->executeQuery("SELECT status, COUNT(*) as count FROM {$wpdb->prefix}srl_vouchery_upominkowe GROUP BY status");
        wp_cache_set($cache_key, $stats, 'srl_cache', 1800);
        
        return $stats;
    }

    private function getPartnerVoucherPagination() {
        return [
            'status_filter' => sanitize_text_field($_GET['status_filter'] ?? ''),
            'current_page' => max(1, intval($_GET['paged'] ?? 1)),
            'per_page' => 20
        ];
    }

    private function getGiftVoucherPagination() {
        return [
            'per_page' => 20,
            'current_page' => max(1, intval($_GET['paged'] ?? 1)),
            'status_filter' => sanitize_text_field($_GET['status_filter'] ?? ''),
            'search' => sanitize_text_field($_GET['s'] ?? '')
        ];
    }

    private function renderGiftVoucherFilters($pagination) {
        echo '<div class="tablenav top">';
        echo '<form method="get" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">';
        echo '<input type="hidden" name="page" value="srl-voucher">';
        echo '<input type="hidden" name="tab" value="upominkowe">';
        
        echo SRL_Helpers::getInstance()->generateSelect('status_filter', [
            '' => 'Wszystkie statusy',
            'do_wykorzystania' => '🟢 Do wykorzystania',
            'wykorzystany' => '🔵 Wykorzystane', 
            'przeterminowany' => '🔴 Przeterminowane'
        ], $pagination['status_filter']);
        
        echo '<input type="search" name="s" value="' . esc_attr($pagination['search']) . '" placeholder="Szukaj po kodzie, imieniu, nazwisku...">';
        echo SRL_Helpers::getInstance()->generateButton('Filtruj', 'button', ['type' => 'submit']);
        
        if ($pagination['status_filter'] || $pagination['search']) {
            echo SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=srl-voucher&tab=upominkowe'), 'Wyczyść filtry', 'button');
        }
        
        echo '</form></div>';
    }

    public function handleBulkActions() {
        if (!isset($_POST['action']) || !isset($_POST['voucher_ids'])) return;
        
        SRL_Helpers::getInstance()->checkAdminPermissions();
        $ids = array_map('intval', $_POST['voucher_ids']);
        $action = $_POST['action'];
        
        if ($action === 'bulk_delete') {
            $this->batchDeleteVouchers($ids);
            echo SRL_Helpers::getInstance()->generateMessage('Usunięto ' . count($ids) . ' voucherów.', 'success');
        }
    }

    private function batchDeleteVouchers($ids) {
        if (empty($ids)) return;
        
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}srl_vouchery_upominkowe WHERE id IN ($placeholders)",
            ...$ids
        ));
        
        wp_cache_delete('gift_voucher_stats', 'srl_cache');
        wp_cache_flush_group('srl_cache');
    }

    private static function renderTabs($active_tab) {
        $tabs = [
            'partner' => 'Vouchery Partnera',
            'upominkowe' => 'Vouchery Upominkowe'
        ];
        
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $tab_key => $tab_name) {
            $active_class = ($active_tab === $tab_key) ? ' nav-tab-active' : '';
            $url = admin_url('admin.php?page=srl-voucher&tab=' . $tab_key);
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . $active_class . '">' . $tab_name . '</a>';
        }
        echo '</h2>';
    }

    private function renderPartnerVoucherModals() {
        echo '<div id="passenger-details-modal" style="display: none;">';
        echo '<div class="wp-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 100000; max-width: 600px; max-height: 80vh; overflow-y: auto;">';
        echo '<div class="wp-dialog-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">';
        echo '<h3 style="margin: 0;">Dane pasażerów</h3>';
        echo '<button type="button" class="button" onclick="closeModal(\'passenger-details-modal\')">✕</button>';
        echo '</div>';
        echo '<div id="passenger-details-content">Ładowanie...</div>';
        echo '</div>';
        echo '<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999;" onclick="closeModal(\'passenger-details-modal\')"></div>';
        echo '</div>';
        
        echo '<div id="reject-modal" style="display: none;">';
        echo '<div class="wp-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 100000; max-width: 500px;">';
        echo '<div class="wp-dialog-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">';
        echo '<h3 style="margin: 0;">Odrzuć voucher</h3>';
        echo '<button type="button" class="button" onclick="closeModal(\'reject-modal\')">✕</button>';
        echo '</div>';
        echo '<div><p>Podaj powód odrzucenia vouchera:</p>';
        echo '<textarea id="reject-reason" style="width: 100%; height: 100px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Np. Nieprawidłowy kod vouchera, brak dokumentacji, itp."></textarea>';
        echo '<div style="margin-top: 15px; text-align: right;">';
        echo '<button type="button" class="button" onclick="closeModal(\'reject-modal\')">Anuluj</button>';
        echo '<button type="button" class="button button-primary" onclick="confirmRejectVoucher()" style="margin-left: 10px;">Odrzuć voucher</button>';
        echo '</div></div></div>';
        echo '<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999;" onclick="closeModal(\'reject-modal\')"></div>';
        echo '</div>';
    }

    private function renderGiftVoucherModals() {
        echo '<div id="srl-modal-voucher" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999;">';
        echo '<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; max-width: 600px; width: 90%;">';
        echo '<h2>Dodaj voucher ręcznie</h2>';
        echo '<form id="srl-form-voucher-recznie">';
        echo '<table class="form-table"><tbody>';
        
        echo '<tr><th scope="row"><label for="srl-kod-vouchera">Kod vouchera *</label></th>';
        echo '<td><input type="text" id="srl-kod-vouchera" name="kod_vouchera" class="regular-text" required placeholder="np. VOUCHER2025"></td></tr>';
        
        echo '<tr><th scope="row"><label for="srl-nazwa-produktu">Nazwa produktu *</label></th>';
        echo '<td><input type="text" id="srl-nazwa-produktu" name="nazwa_produktu" class="regular-text" required value="Voucher na lot tandemowy" placeholder="Nazwa produktu"></td></tr>';
        
        echo '<tr><th scope="row"><label for="srl-data-waznosci">Data ważności *</label></th>';
        echo '<td><input type="date" id="srl-data-waznosci" name="data_waznosci" class="regular-text" required></td></tr>';
        
        echo '<tr><th scope="row">Opcje lotu</th>';
        echo '<td>';
        echo '<label style="display: block; margin-bottom: 10px;"><input type="checkbox" id="srl-ma-filmowanie" name="ma_filmowanie" value="1"> 📹 Z filmowaniem</label>';
        echo '<label style="display: block;"><input type="checkbox" id="srl-ma-akrobacje" name="ma_akrobacje" value="1"> 🌪️ Z akrobacjami</label>';
        echo '<p class="description">Zaznacz opcje, które ma zawierać voucher</p>';
        echo '</td></tr>';
       
        echo '</tbody></table>';
        echo '<p class="submit">';
        echo '<input type="submit" class="button-primary" value="Dodaj voucher">';
        echo '<button type="button" class="button" id="srl-anuluj-voucher" style="margin-left: 10px;">Anuluj</button>';
        echo '</p></form></div></div>';
    }

    private function renderPartnerVoucherScripts() {
        $nonce = wp_create_nonce('srl_admin_nonce');
        
        echo '<script>
        let currentVoucherId = null;

        function filterVouchers() {
            const status = document.getElementById("status-filter").value;
            const url = new URL(window.location);
            url.searchParams.set("tab", "partner");
            if (status) {
                url.searchParams.set("status_filter", status);
            } else {
                url.searchParams.delete("status_filter");
            }
            window.location = url;
        }

        function showPassengerDetails(voucherId) {
            currentVoucherId = voucherId;
            
            fetch(ajaxurl, {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: new URLSearchParams({
                    action: "srl_get_partner_voucher_details",
                    voucher_id: voucherId,
                    nonce: "' . $nonce . '"
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = "<div style=\"max-height: 400px; overflow-y: auto;\">";
                    data.data.dane_pasazerow.forEach((pasazer, index) => {
                        html += "<div style=\"border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 6px; background: #f9f9f9;\">";
                        html += "<h4 style=\"margin-top: 0; color: #0073aa;\">Pasażer " + (index + 1) + "</h4>";
                        html += "<div style=\"display: grid; grid-template-columns: 1fr 1fr; gap: 10px;\">";
                        html += "<div><strong>Imię:</strong> " + pasazer.imie + "</div>";
                        html += "<div><strong>Nazwisko:</strong> " + pasazer.nazwisko + "</div>";
                        html += "<div><strong>Rok urodzenia:</strong> " + pasazer.rok_urodzenia + "</div>";
                        html += "<div><strong>Telefon:</strong> " + pasazer.telefon + "</div>";
                        html += "<div><strong>Kategoria wagowa:</strong> " + pasazer.kategoria_wagowa + "</div>";
                        html += "<div><strong>Sprawność fizyczna:</strong> " + pasazer.sprawnosc_fizyczna + "</div>";
                        html += "</div></div>";
                    });
                    html += "</div>";
                    
                    document.getElementById("passenger-details-content").innerHTML = html;
                    document.getElementById("passenger-details-modal").style.display = "block";
                } else {
                    alert("Błąd: " + data.data);
                }
            });
        }

        function approvePartnerVoucher(voucherId) {
            if (!confirm("Czy na pewno chcesz zatwierdzić ten voucher?")) return;
            
            var validityDateElement = document.getElementById("validity-date-" + voucherId);
            var validityDate = validityDateElement ? validityDateElement.value : null;
            
            fetch(ajaxurl, {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: new URLSearchParams({
                    action: "srl_approve_partner_voucher",
                    voucher_id: voucherId,
                    validity_date: validityDate,
                    nonce: "' . $nonce . '"
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert("Voucher został zatwierdzony! " + data.data.message);
                    location.reload();
                } else {
                    alert("Błąd: " + data.data);
                }
            });
        }

        function showRejectModal(voucherId) {
            currentVoucherId = voucherId;
            document.getElementById("reject-reason").value = "";
            document.getElementById("reject-modal").style.display = "block";
        }

        function confirmRejectVoucher() {
            const reason = document.getElementById("reject-reason").value.trim();
            if (!reason) {
                alert("Musisz podać powód odrzucenia.");
                return;
            }
            
            fetch(ajaxurl, {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: new URLSearchParams({
                    action: "srl_reject_partner_voucher",
                    voucher_id: currentVoucherId,
                    reason: reason,
                    nonce: "' . $nonce . '"
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert("Voucher został odrzucony. Klient otrzyma email z informacją.");
                    closeModal("reject-modal");
                    location.reload();
                } else {
                    alert("Błąd: " + data.data);
                }
            });
        }

        function showRejectReason(voucherId) {
            fetch(ajaxurl, {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: new URLSearchParams({
                    action: "srl_get_partner_voucher_details",
                    voucher_id: voucherId,
                    nonce: "' . $nonce . '"
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data.powod_odrzucenia) {
                    alert("Powód odrzucenia:\\n\\n" + data.data.powod_odrzucenia);
                } else {
                    alert("Brak powodu odrzucenia.");
                }
            });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = "none";
            currentVoucherId = null;
        }
        </script>';
    }

    private function renderGiftVoucherScripts() {
        $nonce = wp_create_nonce('srl_admin_nonce');
        $current_user = wp_get_current_user();
        $buyer_imie = $current_user->first_name ?: "Admin";
        $buyer_nazwisko = $current_user->last_name ?: "Manual";
        
        echo '<script>
        jQuery(document).ready(function($) {
            $("#cb-select-all-1").on("change", function() {
                $("input[name=\"voucher_ids[]\"]").prop("checked", $(this).is(":checked"));
            });
            
            $(document).on("click", "#srl-dodaj-voucher-recznie", function(e) {
                e.preventDefault();
                $("#srl-modal-voucher").show();
                
                var nextYear = new Date();
                nextYear.setFullYear(nextYear.getFullYear() + 1);
                $("#srl-data-waznosci").val(nextYear.toISOString().split("T")[0]);
            });
            
            $(document).on("click", "#srl-anuluj-voucher", function(e) {
                e.preventDefault();
                $("#srl-modal-voucher").hide();
                $("#srl-form-voucher-recznie")[0].reset();
            });
            
            $(document).on("click", "#srl-modal-voucher", function(e) {
                if (e.target === this) {
                    $(this).hide();
                    $("#srl-form-voucher-recznie")[0].reset();
                }
            });
            
            $(document).on("submit", "#srl-form-voucher-recznie", function(e) {
                e.preventDefault();
                
                var submitBtn = $(this).find("input[type=\"submit\"]");
                submitBtn.prop("disabled", true).val("Dodawanie...");
                
                $.post(ajaxurl, {
                    action: "srl_dodaj_voucher_recznie",
                    kod_vouchera: $("#srl-kod-vouchera").val().trim(),
                    data_waznosci: $("#srl-data-waznosci").val(),
                    nazwa_produktu: $("#srl-nazwa-produktu").val().trim(),
                    buyer_imie: "' . $buyer_imie . '",
                    buyer_nazwisko: "' . $buyer_nazwisko . '",
                    ma_filmowanie: $("#srl-ma-filmowanie").is(":checked") ? 1 : 0,
                    ma_akrobacje: $("#srl-ma-akrobacje").is(":checked") ? 1 : 0,
                    nonce: "' . $nonce . '"
                }, function(response) {
                    if (response.success) {
                        alert("Voucher został dodany pomyślnie!");
                        $("#srl-modal-voucher").hide();
                        $("#srl-form-voucher-recznie")[0].reset();
                        location.reload();
                    } else {
                        alert("Błąd: " + response.data);
                    }
                    submitBtn.prop("disabled", false).val("Dodaj voucher");
                });
            });
        });
        </script>';
        
        echo '<style>
        .srl-stats { background: #f1f1f1; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .wp-list-table th, .wp-list-table td { vertical-align: top; }
        .wp-list-table small { color: #666; font-size: 12px; }
        </style>';
    }

    public function __destruct() {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('srl_cache');
        }
    }
}