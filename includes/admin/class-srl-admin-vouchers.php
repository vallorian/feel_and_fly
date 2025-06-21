<?php

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Admin_Vouchers {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_init', array($this, 'przetwarzajVouchery'));
    }

    public static function wyswietlVouchery() {
        SRL_Helpers::getInstance()->checkAdminPermissions();
        
        $tab = $_GET['tab'] ?? 'partner';
        
        echo '<div class="wrap">';
        echo '<h1>Zarządzanie Voucherami</h1>';
        
        self::renderVoucherTabs($tab);
        
        if ($tab === 'partner') {
            self::wyswietlVoucheryPartnera();
        } else {
            self::wyswietlZakupioneVouchery();
        }
        
        echo '</div>';
    }

    private static function renderVoucherTabs($active_tab) {
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

    private static function wyswietlVoucheryPartnera() {
        $vouchery_partnera = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVouchers();
        $current_status = $_GET['status_filter'] ?? '';
        
        echo '<div class="tablenav top">';
        echo SRL_Helpers::getInstance()->generateSelect('status_filter', [
            '' => 'Wszystkie statusy',
            'oczekuje' => 'Oczekuje',
            'zatwierdzony' => 'Zatwierdzony', 
            'odrzucony' => 'Odrzucony'
        ], $current_status, ['id' => 'status-filter']);
        echo '<button type="button" class="button" onclick="filterVouchers()">Filtruj</button>';
        echo '</div>';
        
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>ID</th><th>Partner</th><th>Typ</th><th>Kod</th><th>Kod zabezp.</th>';
        echo '<th>Ważność</th><th>Liczba osób</th><th>Klient</th><th>Data zgłoszenia</th><th>Status</th><th>Akcje</th>';
        echo '</tr></thead><tbody>';
        
        if (empty($vouchery_partnera)) {
            echo '<tr><td colspan="11" style="text-align: center; padding: 20px; color: #666;">Brak voucherów partnera.</td></tr>';
        } else {
            $config = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherConfig();
            foreach ($vouchery_partnera as $voucher) {
                if (!empty($current_status) && $voucher['status'] !== $current_status) continue;
                
                $user = get_userdata($voucher['klient_id']);
                $partner_name = $config[$voucher['partner']]['nazwa'] ?? $voucher['partner'];
                $voucher_type_name = $config[$voucher['partner']]['typy'][$voucher['typ_vouchera']]['nazwa'] ?? $voucher['typ_vouchera'];
                
                echo '<tr>';
                echo '<td><strong>#' . $voucher['id'] . '</strong></td>';
                echo '<td>' . esc_html($partner_name) . '</td>';
                echo '<td>' . esc_html($voucher_type_name) . '</td>';
                echo '<td><code>' . esc_html($voucher['kod_vouchera']) . '</code></td>';
                echo '<td><code>' . esc_html($voucher['kod_zabezpieczajacy']) . '</code></td>';
                echo '<td>';
                
                if ($voucher['status'] === 'oczekuje') {
                    echo '<input type="date" id="validity-date-' . $voucher['id'] . '" value="' . esc_attr($voucher['data_waznosci_vouchera']) . '" style="width: 140px; padding: 4px; border: 1px solid #ddd; border-radius: 4px;">';
                } else {
                    echo esc_html($voucher['data_waznosci_vouchera'] ? SRL_Helpers::getInstance()->formatujDate($voucher['data_waznosci_vouchera']) : 'Brak');
                }
                
                echo '</td>';
                echo '<td>' . $voucher['liczba_osob'] . ' ' . ($voucher['liczba_osob'] == 1 ? 'osoba' : 'osoby') . '</td>';
                echo '<td>' . ($user ? esc_html($user->display_name . ' (' . $user->user_email . ')') : 'Nieznany użytkownik') . '</td>';
                echo '<td>' . esc_html(SRL_Helpers::getInstance()->formatujDate($voucher['data_zgloszenia'], 'd.m.Y H:i')) . '</td>';
                echo '<td>' . self::renderVoucherStatusBadge($voucher['status'], 'partner') . '</td>';
                echo '<td>' . self::generatePartnerVoucherActions($voucher) . '</td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody></table>';
        self::renderPartnerVoucherModals();
        self::renderPartnerVoucherJs();
    }

    private static function wyswietlZakupioneVouchery() {
        global $wpdb;
        $tabela_vouchery = $wpdb->prefix . 'srl_vouchery_upominkowe';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tabela_vouchery'") == $tabela_vouchery;
        
        if (!$table_exists) {
            echo SRL_Helpers::getInstance()->generateMessage('Tabela voucherów nie istnieje. Aktywuj ponownie wtyczkę, aby ją utworzyć.', 'warning');
            return;
        }
        
        echo '<h1 class="wp-heading-inline">Zakupione Vouchery</h1>';
        echo '<button type="button" class="page-title-action" id="srl-dodaj-voucher-recznie">Dodaj voucher ręcznie</button>';
        echo '<hr class="wp-header-end">';
        
        self::handleVoucherBulkActions();
        
        $pagination_data = self::getVoucherPaginationData();
        $vouchery = self::getVouchersWithFilters($pagination_data);
        $stats = self::getVoucherStats();
        
        self::renderVoucherStats($stats);
        self::renderVoucherFilters($pagination_data);
        self::renderVoucherTable($vouchery, $pagination_data);
        self::renderVoucherModals();
        self::renderPartnerVoucherJs();
    }

    private static function renderVoucherStatusBadge($status, $type) {
        if ($type === 'partner') {
            $config = [
                'oczekuje' => ['bg' => '#f39c12', 'label' => 'OCZEKUJE'],
                'zatwierdzony' => ['bg' => '#27ae60', 'label' => 'ZATWIERDZONY'],
                'odrzucony' => ['bg' => '#e74c3c', 'label' => 'ODRZUCONY']
            ];
        } else {
            $config = [
                'do_wykorzystania' => ['bg' => '#28a745', 'label' => 'Do wykorzystania'],
                'wykorzystany' => ['bg' => '#007bff', 'label' => 'Wykorzystany'],
                'przeterminowany' => ['bg' => '#dc3545', 'label' => 'Przeterminowany']
            ];
        }

        $item = $config[$status] ?? ['bg' => '#6c757d', 'label' => ucfirst($status)];
        return sprintf(
            '<span style="background: %s; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">%s</span>',
            $item['bg'], $item['label']
        );
    }

    private static function generatePartnerVoucherActions($voucher) {
        $actions = [];
        $actions[] = '<button class="button button-small" onclick="showPassengerDetails(' . $voucher['id'] . ')">👥 Dane</button>';
        
        if ($voucher['status'] === 'oczekuje') {
            $actions[] = '<button class="button button-primary button-small" onclick="approvePartnerVoucher(' . $voucher['id'] . ')">✅ Zatwierdź</button>';
            $actions[] = '<button class="button button-secondary button-small" onclick="showRejectModal(' . $voucher['id'] . ')">❌ Odrzuć</button>';
        } elseif ($voucher['status'] === 'odrzucony') {
            $actions[] = '<button class="button button-small" onclick="showRejectReason(' . $voucher['id'] . ')">📝 Powód</button>';
        }
        
        return implode(' ', $actions);
    }

    private static function handleVoucherBulkActions() {
        if (!isset($_POST['action']) || !isset($_POST['voucher_ids'])) return;
        
        $ids = array_map('intval', $_POST['voucher_ids']);
        $action = $_POST['action'];
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery_upominkowe';
        
        if ($action === 'bulk_delete') {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM $tabela WHERE id IN ($placeholders)", ...$ids));
            echo SRL_Helpers::getInstance()->generateMessage('Usunięto ' . count($ids) . ' voucherów.', 'success');
        }
    }

    private static function getVoucherPaginationData() {
        return [
            'per_page' => 20,
            'current_page' => max(1, intval($_GET['paged'] ?? 1)),
            'status_filter' => sanitize_text_field($_GET['status_filter'] ?? ''),
            'search' => sanitize_text_field($_GET['s'] ?? '')
        ];
    }

    private static function getVouchersWithFilters($pagination) {
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
        
        return SRL_Database_Helpers::getInstance()->executeQuery(
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
    }

    private static function getVoucherStats() {
        global $wpdb;
        return SRL_Database_Helpers::getInstance()->executeQuery(
            "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}srl_vouchery_upominkowe GROUP BY status"
        );
    }

    private static function renderVoucherStats($stats) {
        $status_labels = [
            'do_wykorzystania' => '🟢 Do wykorzystania',
            'wykorzystany' => '🔵 Wykorzystane',
            'przeterminowany' => '🔴 Przeterminowane'
        ];
        
        $stats_array = [];
        foreach ($stats as $stat) {
            $stats_array[$stat['status']] = $stat['count'];
        }
        
        echo '<div class="srl-stats" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">';
        foreach ($status_labels as $status => $label) {
            $count = $stats_array[$status] ?? 0;
            echo '<div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); min-width: 120px; display: flex; align-items: center; gap: 10px;">';
            echo '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . $count . '</div>';
            echo '<div style="font-size: 13px; color: #666;">' . $label . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function renderVoucherFilters($pagination) {
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

    private static function renderVoucherTable($vouchery, $pagination) {
        echo '<form method="post" id="srl-vouchers-form">';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        echo '<td class="manage-column check-column"><input type="checkbox" id="cb-select-all-1"></td>';
        echo '<th>ID (nr. zam)</th><th>Kupujący</th><th>Produkt</th><th>Kod</th>';
        echo '<th>Status</th><th>Data zakupu</th><th>Ważność</th><th>Wykorzystany przez</th><th>ID Lotu</th>';
        echo '</tr></thead><tbody>';
        
        if (empty($vouchery)) {
            echo '<tr><td colspan="11" style="text-align: center; padding: 40px; color: #666;">';
            echo '<p style="font-size: 16px;">Brak voucherów do wyświetlenia</p>';
            if ($pagination['search'] || $pagination['status_filter']) {
                echo '<p>' . SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=srl-voucher&tab=upominkowe'), 'Wyczyść filtry') . '</p>';
            }
            echo '</td></tr>';
        } else {
            foreach ($vouchery as $voucher) {
                self::renderVoucherRow($voucher);
            }
        }
        
        echo '</tbody></table></form>';
    }

    private static function renderVoucherRow($voucher) {
        $order_url = admin_url('post.php?post=' . $voucher['order_id'] . '&action=edit');
        
        echo '<tr>';
        echo '<th scope="row" class="check-column"><input type="checkbox" name="voucher_ids[]" value="' . $voucher['id'] . '"></th>';
        echo '<td>';
        echo '<strong>#' . $voucher['id'] . '</strong><br>';
        if ($voucher['order_id'] == 0) {
            echo '<small style="color: #666; font-style: italic;">dod. ręcznie</small>';
        } else {
            echo '<small>Zam: ' . SRL_Helpers::getInstance()->generateLink($order_url, '#' . $voucher['order_id'], '', ['target' => '_blank']) . '</small>';
        }
        echo '</td>';
        echo '<td><strong>' . SRL_Helpers::getInstance()->generateLink(
            admin_url('admin.php?page=wc-orders&customer=' . $voucher['buyer_user_id']),
            esc_html($voucher['buyer_imie'] . ' ' . $voucher['buyer_nazwisko']),
            '', ['target' => '_blank', 'style' => 'color: #0073aa; text-decoration: none;']
        ) . '</strong><br><small>' . esc_html($voucher['buyer_email']) . '</small></td>';
        echo '<td><strong>' . esc_html($voucher['nazwa_produktu']) . '</strong>';
        if ($voucher['ma_filmowanie'] || $voucher['ma_akrobacje']) {
            echo '<br><small style="color: #0073aa;">' . SRL_Helpers::getInstance()->formatFlightOptionsHtml($voucher['ma_filmowanie'], $voucher['ma_akrobacje']) . '</small>';
        }
        echo '</td>';
        echo '<td><code style="background: #f0f0f0; padding: 4px 8px; border-radius: 4px; font-weight: bold; color: #d63638;">' . esc_html($voucher['kod_vouchera']) . '</code></td>';
        echo '<td>' . self::renderVoucherStatusBadge($voucher['status'], 'upominkowe') . '</td>';
        echo '<td>' . SRL_Helpers::getInstance()->formatujDate($voucher['data_zakupu'], 'd.m.Y H:i') . '</td>';
        echo '<td>' . SRL_Helpers::getInstance()->formatujWaznoscLotu($voucher['data_waznosci']) . '</td>';
        
        echo '<td>';
        if ($voucher['status'] === 'wykorzystany' && $voucher['user_display_name']) {
            echo '<strong>' . SRL_Helpers::getInstance()->generateLink(
                admin_url('admin.php?page=wc-orders&customer=' . $voucher['wykorzystany_przez_user_id']),
                esc_html($voucher['user_display_name']), '', ['target' => '_blank', 'style' => 'color: #0073aa; text-decoration: none;']
            ) . '</strong>';
            echo '<br><small>' . esc_html($voucher['user_email']) . '</small>';
            if ($voucher['data_wykorzystania']) {
                echo '<br><small style="color: #666;">Wykorzystano: ' . SRL_Helpers::getInstance()->formatujDate($voucher['data_wykorzystania'], 'd.m.Y H:i') . '</small>';
            }
        } else {
            echo '<span style="color: #999;">—</span>';
        }
        echo '</td>';
        
        echo '<td>';
        if ($voucher['lot_id']) {
            echo SRL_Helpers::getInstance()->generateLink(
                admin_url('admin.php?page=srl-wykupione-loty&s=' . $voucher['lot_id']),
                '#' . $voucher['lot_id'], '', ['target' => '_blank', 'style' => 'color: #0073aa; font-weight: bold;']
            );
        } else {
            echo '<span style="color: #999;">—</span>';
        }
        echo '</td>';
        echo '</tr>';
    }

    private static function renderVoucherModals() {
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

    private static function renderPartnerVoucherModals() {
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
        echo '<div>';
        echo '<p>Podaj powód odrzucenia vouchera:</p>';
        echo '<textarea id="reject-reason" style="width: 100%; height: 100px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Np. Nieprawidłowy kod vouchera, brak dokumentacji, itp."></textarea>';
        echo '<div style="margin-top: 15px; text-align: right;">';
        echo '<button type="button" class="button" onclick="closeModal(\'reject-modal\')">Anuluj</button>';
        echo '<button type="button" class="button button-primary" onclick="confirmRejectVoucher()" style="margin-left: 10px;">Odrzuć voucher</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999;" onclick="closeModal(\'reject-modal\')"></div>';
        echo '</div>';
    }

    private static function renderPartnerVoucherJs() {
        $nonce = wp_create_nonce('srl_admin_nonce');
        $current_user = wp_get_current_user();
        $buyer_imie = $current_user->first_name ?: "Admin";
        $buyer_nazwisko = $current_user->last_name ?: "Manual";
        
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
                
                var kod = $("#srl-kod-vouchera").val().trim();
                var dataWaznosci = $("#srl-data-waznosci").val();
                var nazwaProduktu = $("#srl-nazwa-produktu").val().trim();
                var maFilmowanie = $("#srl-ma-filmowanie").is(":checked") ? 1 : 0;
                var maAkrobacje = $("#srl-ma-akrobacje").is(":checked") ? 1 : 0;
                
                if (!kod || !dataWaznosci || !nazwaProduktu) {
                    alert("Wypełnij wszystkie wymagane pola (oznaczone *)");
                    return;
                }
                
                var opcjeText = "";
                if (maFilmowanie || maAkrobacje) {
                    var opcje = [];
                    if (maFilmowanie) opcje.push("z filmowaniem");
                    if (maAkrobacje) opcje.push("z akrobacjami");
                    opcjeText = " (" + opcje.join(", ") + ")";
                    nazwaProduktu += opcjeText;
                }
                
                var submitBtn = $(this).find("input[type=\"submit\"]");
                submitBtn.prop("disabled", true).val("Dodawanie...");
                
                $.post(ajaxurl, {
                    action: "srl_dodaj_voucher_recznie",
                    kod_vouchera: kod,
                    data_waznosci: dataWaznosci,
                    nazwa_produktu: nazwaProduktu,
                    buyer_imie: "' . $buyer_imie . '",
                    buyer_nazwisko: "' . $buyer_nazwisko . '",
                    ma_filmowanie: maFilmowanie,
                    ma_akrobacje: maAkrobacje,
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
                }).fail(function() {
                    alert("Błąd połączenia z serwerem");
                    submitBtn.prop("disabled", false).val("Dodaj voucher");
                });
            });
        });
        </script>';
        
        echo '<style>
        .status-available { background: #d4edda; color: #155724; }
        .status-completed { background: #d1ecf1; color: #0c5460; }
        .status-expired { background: #f8d7da; color: #721c24; }
        .srl-stats { background: #f1f1f1; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .wp-list-table th, .wp-list-table td { vertical-align: top; }
        .wp-list-table small { color: #666; font-size: 12px; }
        </style>';
    }

    public function przetwarzajVouchery() {
        if (!isset($_POST['srl_zatwierdz_voucher']) && !isset($_POST['srl_odrzuc_voucher'])) return;
        
        SRL_Helpers::getInstance()->checkAdminPermissions();
        $id = intval($_POST['voucher_id']);
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery';
        
        if (isset($_POST['srl_zatwierdz_voucher'])) {
            $wpdb->update($tabela, ['status' => 'Zatwierdzony'], ['id' => $id]);
        }
        if (isset($_POST['srl_odrzuc_voucher'])) {
            $wpdb->update($tabela, ['status' => 'Odrzucony'], ['id' => $id]);
        }
    }
}