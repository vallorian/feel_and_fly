<?php

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Admin_Voucher {
    
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
        echo '<div class="wrap">';
        echo '<h1>Vouchery partnerów</h1>';
        
        self::wyswietlVoucheryPartnera();
        
        echo '</div>';
    }

    private static function wyswietlVoucheryPartnera() {
        $cache_key = 'partner_vouchers_filtered_' . ($_GET['status_filter'] ?? '');
        $cached_data = wp_cache_get($cache_key, 'srl_admin_cache');
        
        if ($cached_data === false) {
            $vouchery_partnera = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVouchers();
            $config = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherConfig();
            
            $cached_data = [
                'vouchery' => $vouchery_partnera,
                'config' => $config
            ];
            
            wp_cache_set($cache_key, $cached_data, 'srl_admin_cache', 600);
        }
        
        $vouchery_partnera = $cached_data['vouchery'];
        $config = $cached_data['config'];
        $current_status = $_GET['status_filter'] ?? '';
        
        self::renderFilterControls($current_status);
        self::renderVouchersTable($vouchery_partnera, $config, $current_status);
        self::renderPartnerVoucherModals();
        self::renderPartnerVoucherJs();
    }

    private static function renderFilterControls($current_status) {
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<select name="status_filter" id="status-filter">';
        echo '<option value="">Wszystkie statusy</option>';
        echo '<option value="oczekuje"' . selected($current_status, 'oczekuje', false) . '>Oczekuje</option>';
        echo '<option value="zatwierdzony"' . selected($current_status, 'zatwierdzony', false) . '>Zatwierdzony</option>';
        echo '<option value="odrzucony"' . selected($current_status, 'odrzucony', false) . '>Odrzucony</option>';
        echo '</select>';
        echo '<button type="button" class="button" onclick="filterVouchers()">Filtruj</button>';
        echo '</div>';
        echo '</div>';
    }

    private static function renderVouchersTable($vouchery_partnera, $config, $current_status) {
        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>ID</th>';
        echo '<th>Partner</th>';
        echo '<th>Typ vouchera</th>';
        echo '<th>Kod vouchera</th>';
        echo '<th>Kod zabezpieczający</th>';
        echo '<th>Data ważności</th>'; 
        echo '<th>Liczba osób</th>';
        echo '<th>Klient</th>';
        echo '<th>Data zgłoszenia</th>';
        echo '<th>Status</th>';
        echo '<th>Akcje</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        if (empty($vouchery_partnera)) {
            echo '<tr><td colspan="11" style="text-align: center; padding: 20px; color: #666;">Brak voucherów partnera.</td></tr>';
        } else {
            foreach ($vouchery_partnera as $voucher) {
                if (!empty($current_status) && $voucher['status'] !== $current_status) {
                    continue;
                }
                self::renderVoucherRow($voucher, $config);
            }
        }
        
        echo '</tbody>';
        echo '</table>';
    }

    private static function renderVoucherRow($voucher, $config) {
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
            echo esc_html($voucher['data_waznosci_vouchera'] ? date('d.m.Y', strtotime($voucher['data_waznosci_vouchera'])) : 'Brak');
        }
        echo '</td>';
        
        echo '<td>' . $voucher['liczba_osob'] . ' ' . ($voucher['liczba_osob'] == 1 ? 'osoba' : 'osoby') . '</td>';
        echo '<td>' . ($user ? esc_html($user->display_name . ' (' . $user->user_email . ')') : 'Nieznany użytkownik') . '</td>';
        echo '<td>' . esc_html(date('d.m.Y H:i', strtotime($voucher['data_zgloszenia']))) . '</td>';
        echo '<td>';
        
        echo self::renderStatusBadge($voucher['status']);
        
        echo '</td>';
        echo '<td>';
        
        echo self::renderActionButtons($voucher);
        
        echo '</td>';
        echo '</tr>';
    }

    private static function renderStatusBadge($status) {
        $badges = [
            'oczekuje' => ['color' => '#f39c12', 'label' => 'OCZEKUJE'],
            'zatwierdzony' => ['color' => '#27ae60', 'label' => 'ZATWIERDZONY'],
            'odrzucony' => ['color' => '#e74c3c', 'label' => 'ODRZUCONY']
        ];
        
        $badge = $badges[$status] ?? ['color' => '#6c757d', 'label' => strtoupper($status)];
        
        return '<span class="srl-status-badge" style="background: ' . $badge['color'] . '; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">' . $badge['label'] . '</span>';
    }

    private static function renderActionButtons($voucher) {
        $buttons = [];
        
        $buttons[] = '<button class="button button-small" onclick="showPassengerDetails(' . $voucher['id'] . ')">👥 Dane pasażerów</button> ';
        
        if ($voucher['status'] === 'oczekuje') {
            $buttons[] = '<button class="button button-primary button-small" onclick="approvePartnerVoucher(' . $voucher['id'] . ')">✅ Zatwierdź</button> ';
            $buttons[] = '<button class="button button-secondary button-small" onclick="showRejectModal(' . $voucher['id'] . ')">❌ Odrzuć</button>';
        } elseif ($voucher['status'] === 'odrzucony') {
            $buttons[] = '<button class="button button-small" onclick="showRejectReason(' . $voucher['id'] . ')">📝 Powód odrzucenia</button>';
        }
        
        return implode('', $buttons);
    }

    private static function renderPartnerVoucherModals() {
        echo '<div id="passenger-details-modal" style="display: none;">';
        echo '<div class="wp-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 100000; max-width: 600px; max-height: 80vh; overflow-y: auto;">';
        echo '<div class="wp-dialog-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">';
        echo '<h3 style="margin: 0;">Dane pasażerów</h3>';
        echo '<button type="button" class="button" onclick="closeModal(\'passenger-details-modal\')">✕</button>';
        echo '</div>';
        echo '<div id="passenger-details-content">';
        echo 'Ładowanie...';
        echo '</div>';
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
        
        echo '<script>';
        echo 'let currentVoucherId = null;';

        echo 'function filterVouchers() {';
        echo '    const status = document.getElementById("status-filter").value;';
        echo '    const url = new URL(window.location);';
        echo '    if (status) {';
        echo '        url.searchParams.set("status_filter", status);';
        echo '    } else {';
        echo '        url.searchParams.delete("status_filter");';
        echo '    }';
        echo '    window.location = url;';
        echo '}';

        echo 'function showPassengerDetails(voucherId) {';
        echo '    currentVoucherId = voucherId;';
        echo '    ';
        echo '    fetch(ajaxurl, {';
        echo '        method: "POST",';
        echo '        headers: {';
        echo '            "Content-Type": "application/x-www-form-urlencoded",';
        echo '        },';
        echo '        body: new URLSearchParams({';
        echo '            action: "srl_get_partner_voucher_details",';
        echo '            voucher_id: voucherId,';
        echo '            nonce: "' . $nonce . '"';
        echo '        })';
        echo '    })';
        echo '    .then(response => response.json())';
        echo '    .then(data => {';
        echo '        if (data.success) {';
        echo '            let html = "<div style=\"max-height: 400px; overflow-y: auto;\">";';
        echo '            data.data.dane_pasazerow.forEach((pasazer, index) => {';
        echo '                html += "<div style=\"border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 6px; background: #f9f9f9;\">";';
        echo '                html += "<h4 style=\"margin-top: 0; color: #0073aa;\">Pasażer " + (index + 1) + "</h4>";';
        echo '                html += "<div style=\"display: grid; grid-template-columns: 1fr 1fr; gap: 10px;\">";';
        echo '                html += "<div><strong>Imię:</strong> " + pasazer.imie + "</div>";';
        echo '                html += "<div><strong>Nazwisko:</strong> " + pasazer.nazwisko + "</div>";';
        echo '                html += "<div><strong>Rok urodzenia:</strong> " + pasazer.rok_urodzenia + "</div>";';
        echo '                html += "<div><strong>Telefon:</strong> " + pasazer.telefon + "</div>";';
        echo '                html += "<div><strong>Kategoria wagowa:</strong> " + pasazer.kategoria_wagowa + "</div>";';
        echo '                html += "<div><strong>Sprawność fizyczna:</strong> " + pasazer.sprawnosc_fizyczna + "</div>";';
        echo '                html += "</div>";';
        echo '                html += "</div>";';
        echo '            });';
        echo '            html += "</div>";';
        echo '            ';
        echo '            document.getElementById("passenger-details-content").innerHTML = html;';
        echo '            document.getElementById("passenger-details-modal").style.display = "block";';
        echo '        } else {';
        echo '            alert("Błąd: " + data.data);';
        echo '        }';
        echo '    })';
        echo '    .catch(error => {';
        echo '        alert("Błąd połączenia: " + error);';
        echo '    });';
        echo '}';

        echo 'function approvePartnerVoucher(voucherId) {';
        echo '    if (!confirm("Czy na pewno chcesz zatwierdzić ten voucher? Zostaną utworzone loty dla wszystkich pasażerów.")) {';
        echo '        return;';
        echo '    }';
        echo '    ';
        echo '    var validityDateElement = document.getElementById("validity-date-" + voucherId);';
        echo '    var validityDate = validityDateElement ? validityDateElement.value : null;';
        echo '    ';
        echo '    fetch(ajaxurl, {';
        echo '        method: "POST",';
        echo '        headers: {';
        echo '            "Content-Type": "application/x-www-form-urlencoded",';
        echo '        },';
        echo '        body: new URLSearchParams({';
        echo '            action: "srl_approve_partner_voucher",';
        echo '            voucher_id: voucherId,';
        echo '            validity_date: validityDate,';
        echo '            nonce: "' . $nonce . '"';
        echo '        })';
        echo '    })';
        echo '    .then(response => response.json())';
        echo '    .then(data => {';
        echo '        if (data.success) {';
        echo '            alert("Voucher został zatwierdzony! " + data.data.message);';
        echo '            location.reload();';
        echo '        } else {';
        echo '            alert("Błąd: " + data.data);';
        echo '        }';
        echo '    })';
        echo '    .catch(error => {';
        echo '        alert("Błąd połączenia: " + error);';
        echo '    });';
        echo '}';

        echo 'function showRejectModal(voucherId) {';
        echo '    currentVoucherId = voucherId;';
        echo '    document.getElementById("reject-reason").value = "";';
        echo '    document.getElementById("reject-modal").style.display = "block";';
        echo '}';

        echo 'function confirmRejectVoucher() {';
        echo '    const reason = document.getElementById("reject-reason").value.trim();';
        echo '    if (!reason) {';
        echo '        alert("Musisz podać powód odrzucenia.");';
        echo '        return;';
        echo '    }';
        echo '    ';
        echo '    fetch(ajaxurl, {';
        echo '        method: "POST",';
        echo '        headers: {';
        echo '            "Content-Type": "application/x-www-form-urlencoded",';
        echo '        },';
        echo '        body: new URLSearchParams({';
        echo '            action: "srl_reject_partner_voucher",';
        echo '            voucher_id: currentVoucherId,';
        echo '            reason: reason,';
        echo '            nonce: "' . $nonce . '"';
        echo '        })';
        echo '    })';
        echo '    .then(response => response.json())';
        echo '    .then(data => {';
        echo '        if (data.success) {';
        echo '            alert("Voucher został odrzucony. Klient otrzyma email z informacją.");';
        echo '            closeModal("reject-modal");';
        echo '            location.reload();';
        echo '        } else {';
        echo '            alert("Błąd: " + data.data);';
        echo '        }';
        echo '    })';
        echo '    .catch(error => {';
        echo '        alert("Błąd połączenia: " + error);';
        echo '    });';
        echo '}';

        echo 'function showRejectReason(voucherId) {';
        echo '    fetch(ajaxurl, {';
        echo '        method: "POST",';
        echo '        headers: {';
        echo '            "Content-Type": "application/x-www-form-urlencoded",';
        echo '        },';
        echo '        body: new URLSearchParams({';
        echo '            action: "srl_get_partner_voucher_details",';
        echo '            voucher_id: voucherId,';
        echo '            nonce: "' . $nonce . '"';
        echo '        })';
        echo '    })';
        echo '    .then(response => response.json())';
        echo '    .then(data => {';
        echo '        if (data.success && data.data.powod_odrzucenia) {';
        echo '            alert("Powód odrzucenia:\\n\\n" + data.data.powod_odrzucenia);';
        echo '        } else {';
        echo '            alert("Brak powodu odrzucenia.");';
        echo '        }';
        echo '    });';
        echo '}';

        echo 'function closeModal(modalId) {';
        echo '    document.getElementById(modalId).style.display = "none";';
        echo '    currentVoucherId = null;';
        echo '}';
        echo '</script>';
    }

    public function przetwarzajVouchery() {
        if (!isset($_POST['srl_zatwierdz_voucher']) && !isset($_POST['srl_odrzuc_voucher'])) return;
        
        SRL_Helpers::getInstance()->checkAdminPermissions();
        $id = intval($_POST['voucher_id']);
        
        global $wpdb;
        $tabela = $wpdb->prefix . 'srl_vouchery';
        
        if (isset($_POST['srl_zatwierdz_voucher'])) {
            $wpdb->update($tabela, array('status' => 'Zatwierdzony'), array('id' => $id));
        }
        if (isset($_POST['srl_odrzuc_voucher'])) {
            $wpdb->update($tabela, array('status' => 'Odrzucony'), array('id' => $id));
        }
        
        wp_cache_delete('partner_vouchers_filtered_', 'srl_admin_cache');
        wp_cache_delete('partner_vouchers_', 'srl_admin_cache');
    }
}