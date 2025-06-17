<?php

function srl_wyswietl_vouchery() {
    global $wpdb;
    
    echo '<div class="wrap">';
    echo '<h1>Vouchery partnerów</h1>';
    
    srl_wyswietl_vouchery_partnera();
    
    echo '</div>';
}

function srl_wyswietl_vouchery_partnera() {
    global $wpdb;
    
    // Pobierz vouchery partnera
    $vouchery_partnera = srl_get_partner_vouchers();
    $config = srl_get_partner_voucher_config();
    
    // Filtry statusu
    $current_status = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
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
        echo '<tr><td colspan="10" style="text-align: center; padding: 20px; color: #666;">Brak voucherów partnera.</td></tr>';
    } else {
        foreach ($vouchery_partnera as $voucher) {
            // Pokaż tylko vouchery zgodne z filtrem
            if (!empty($current_status) && $voucher['status'] !== $current_status) {
                continue;
            }
            
            $user = get_userdata($voucher['klient_id']);
            $partner_name = isset($config[$voucher['partner']]) ? $config[$voucher['partner']]['nazwa'] : $voucher['partner'];
            $voucher_type_name = isset($config[$voucher['partner']]['typy'][$voucher['typ_vouchera']]) 
                ? $config[$voucher['partner']]['typy'][$voucher['typ_vouchera']]['nazwa'] 
                : $voucher['typ_vouchera'];
            
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
            
            switch ($voucher['status']) {
                case 'oczekuje':
                    echo '<span class="srl-status-badge" style="background: #f39c12; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">OCZEKUJE</span>';
                    break;
                case 'zatwierdzony':
                    echo '<span class="srl-status-badge" style="background: #27ae60; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">ZATWIERDZONY</span>';
                    break;
                case 'odrzucony':
                    echo '<span class="srl-status-badge" style="background: #e74c3c; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">ODRZUCONY</span>';
                    break;
            }
            
            echo '</td>';
            echo '<td>';
            
            if ($voucher['status'] === 'oczekuje') {
                echo '<button class="button button-small" onclick="showPassengerDetails(' . $voucher['id'] . ')">👥 Dane pasażerów</button> ';
                echo '<button class="button button-primary button-small" onclick="approvePartnerVoucher(' . $voucher['id'] . ')">✅ Zatwierdź</button> ';
                echo '<button class="button button-secondary button-small" onclick="showRejectModal(' . $voucher['id'] . ')">❌ Odrzuć</button>';
            } elseif ($voucher['status'] === 'odrzucony') {
                echo '<button class="button button-small" onclick="showPassengerDetails(' . $voucher['id'] . ')">👥 Dane pasażerów</button> ';
                echo '<button class="button button-small" onclick="showRejectReason(' . $voucher['id'] . ')">📝 Powód odrzucenia</button>';
            } else {
                echo '<button class="button button-small" onclick="showPassengerDetails(' . $voucher['id'] . ')">👥 Dane pasażerów</button>';
            }
            
            echo '</td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody>';
    echo '</table>';
    
    // Modals
    srl_render_partner_voucher_modals();
    
    // JavaScript
    srl_render_partner_voucher_js();
}

function srl_render_partner_voucher_modals() {
    ?>
    <!-- Modal danych pasażerów -->
    <div id="passenger-details-modal" style="display: none;">
        <div class="wp-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 100000; max-width: 600px; max-height: 80vh; overflow-y: auto;">
            <div class="wp-dialog-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">
                <h3 style="margin: 0;">Dane pasażerów</h3>
                <button type="button" class="button" onclick="closeModal('passenger-details-modal')">✕</button>
            </div>
            <div id="passenger-details-content">
                Ładowanie...
            </div>
        </div>
        <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999;" onclick="closeModal('passenger-details-modal')"></div>
    </div>
    
    <!-- Modal odrzucenia -->
    <div id="reject-modal" style="display: none;">
        <div class="wp-dialog" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 100000; max-width: 500px;">
            <div class="wp-dialog-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">
                <h3 style="margin: 0;">Odrzuć voucher</h3>
                <button type="button" class="button" onclick="closeModal('reject-modal')">✕</button>
            </div>
            <div>
                <p>Podaj powód odrzucenia vouchera:</p>
                <textarea id="reject-reason" style="width: 100%; height: 100px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Np. Nieprawidłowy kod vouchera, brak dokumentacji, itp."></textarea>
                <div style="margin-top: 15px; text-align: right;">
                    <button type="button" class="button" onclick="closeModal('reject-modal')">Anuluj</button>
                    <button type="button" class="button button-primary" onclick="confirmRejectVoucher()" style="margin-left: 10px;">Odrzuć voucher</button>
                </div>
            </div>
        </div>
        <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999;" onclick="closeModal('reject-modal')"></div>
    </div>
    <?php
}

function srl_render_partner_voucher_js() {
    ?>
    <script>
    let currentVoucherId = null;

    function filterVouchers() {
        const status = document.getElementById('status-filter').value;
        const url = new URL(window.location);
        if (status) {
            url.searchParams.set('status_filter', status);
        } else {
            url.searchParams.delete('status_filter');
        }
        window.location = url;
    }

    function showPassengerDetails(voucherId) {
        currentVoucherId = voucherId;
        
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'srl_get_partner_voucher_details',
                voucher_id: voucherId,
                nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<div style="max-height: 400px; overflow-y: auto;">';
                data.data.dane_pasazerow.forEach((pasazer, index) => {
                    html += '<div style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 6px; background: #f9f9f9;">';
                    html += '<h4 style="margin-top: 0; color: #0073aa;">Pasażer ' + (index + 1) + '</h4>';
                    html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">';
                    html += '<div><strong>Imię:</strong> ' + pasazer.imie + '</div>';
                    html += '<div><strong>Nazwisko:</strong> ' + pasazer.nazwisko + '</div>';
                    html += '<div><strong>Rok urodzenia:</strong> ' + pasazer.rok_urodzenia + '</div>';
                    html += '<div><strong>Telefon:</strong> ' + pasazer.telefon + '</div>';
                    html += '<div><strong>Kategoria wagowa:</strong> ' + pasazer.kategoria_wagowa + '</div>';
                    html += '<div><strong>Sprawność fizyczna:</strong> ' + pasazer.sprawnosc_fizyczna + '</div>';
                    html += '</div>';
                    html += '</div>';
                });
                html += '</div>';
                
                document.getElementById('passenger-details-content').innerHTML = html;
                document.getElementById('passenger-details-modal').style.display = 'block';
            } else {
                alert('Błąd: ' + data.data);
            }
        })
        .catch(error => {
            alert('Błąd połączenia: ' + error);
        });
    }

    function approvePartnerVoucher(voucherId) {
        if (!confirm('Czy na pewno chcesz zatwierdzić ten voucher? Zostaną utworzone loty dla wszystkich pasażerów.')) {
            return;
        }
        
        // Pobierz datę ważności z pola input (jeśli istnieje)
        var validityDateElement = document.getElementById('validity-date-' + voucherId);
        var validityDate = validityDateElement ? validityDateElement.value : null;
        
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'srl_approve_partner_voucher',
                voucher_id: voucherId,
                validity_date: validityDate, // NOWY PARAMETR
                nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Voucher został zatwierdzony! ' + data.data.message);
                location.reload();
            } else {
                alert('Błąd: ' + data.data);
            }
        })
        .catch(error => {
            alert('Błąd połączenia: ' + error);
        });
    }

    function showRejectModal(voucherId) {
        currentVoucherId = voucherId;
        document.getElementById('reject-reason').value = '';
        document.getElementById('reject-modal').style.display = 'block';
    }

    function confirmRejectVoucher() {
        const reason = document.getElementById('reject-reason').value.trim();
        if (!reason) {
            alert('Musisz podać powód odrzucenia.');
            return;
        }
        
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'srl_reject_partner_voucher',
                voucher_id: currentVoucherId,
                reason: reason,
                nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Voucher został odrzucony. Klient otrzyma email z informacją.');
                closeModal('reject-modal');
                location.reload();
            } else {
                alert('Błąd: ' + data.data);
            }
        })
        .catch(error => {
            alert('Błąd połączenia: ' + error);
        });
    }

    function showRejectReason(voucherId) {
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'srl_get_partner_voucher_details',
                voucher_id: voucherId,
                nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.powod_odrzucenia) {
                alert('Powód odrzucenia:\n\n' + data.data.powod_odrzucenia);
            } else {
                alert('Brak powodu odrzucenia.');
            }
        });
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        currentVoucherId = null;
    }
    </script>
    <?php
}

// Obsługa zatwierdzania/odrzucania voucherów (formularz przesyłany do tej samej strony)
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
