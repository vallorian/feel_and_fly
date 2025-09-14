<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Admin_Tables {
    private static $instance = null;
    private $cache_manager;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->cache_manager = SRL_Cache_Manager::getInstance();
    }

	public function renderFlightsTable($flights, $pagination) {
		if (empty($flights)) {
			return $this->renderEmptyTable('Brak lotów do wyświetlenia', $pagination);
		}

		ob_start();
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<td class="manage-column check-column"><input type="checkbox" id="cb-select-all-1"></td>';
		echo '<th>ID lotu (zam.)</th><th>Klient</th><th>Produkt</th><th>Status</th>';
		echo '<th>Data zakupu</th><th>Ważność</th><th>Rezerwacja</th>';
		echo '<th>Opcje lotu</th><th>Szczegóły</th>';
		echo '</tr></thead><tbody>';

		foreach ($flights as $flight) {
			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="loty_ids[]" value="' . $flight['id'] . '"></th>';
			echo '<td>' . $this->renderFlightIdCell($flight) . '</td>';
			echo '<td>' . $this->renderClientCell($flight) . '</td>';
			echo '<td>' . $this->renderProductCell($flight) . '</td>';
			echo '<td>' . SRL_Helpers::getInstance()->generateStatusBadge($flight['status'], 'lot') . '</td>';
			echo '<td>' . SRL_Helpers::getInstance()->formatujDate($flight['data_zakupu']) . '</td>';
			echo '<td>' . SRL_Helpers::getInstance()->formatujWaznoscLotu($flight['data_waznosci']) . '</td>';
			echo '<td>' . $this->renderReservationCell($flight) . '</td>';
			echo '<td>' . $this->renderFlightOptionsCell($flight) . '</td>';
			echo '<td>' . $this->renderDetailsCell($flight) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		return ob_get_clean();
	}
	
	private function renderFlightOptionsCell($flight) {
		$buttons = [];
		
		// Sprawdź czy lot ma opcje dostępne do modyfikacji
		if (in_array($flight['status'], ['wolny', 'zarezerwowany'])) {
			
			// Przycisk filmowania
			if ($flight['ma_filmowanie']) {
				$buttons[] = '<button type="button" class="button button-small srl-remove-option" 
					data-lot-id="' . $flight['id'] . '" 
					data-option="filmowanie" 
					style="background:#dc3545; color:white; margin-right:5px;">
					Usuń filmowanie
				</button>';
			} else {
				$buttons[] = '<button type="button" class="button button-small srl-add-option" 
					data-lot-id="' . $flight['id'] . '" 
					data-option="filmowanie" 
					style="background:#28a745; color:white; margin-right:5px;">
					Dodaj filmowanie
				</button>';
			}
			
			// Przycisk akrobacji
			if ($flight['ma_akrobacje']) {
				$buttons[] = '<button type="button" class="button button-small srl-remove-option" 
					data-lot-id="' . $flight['id'] . '" 
					data-option="akrobacje" 
					style="background:#dc3545; color:white; margin-right:5px;">
					Usuń akrobacje
				</button>';
			} else {
				$buttons[] = '<button type="button" class="button button-small srl-add-option" 
					data-lot-id="' . $flight['id'] . '" 
					data-option="akrobacje" 
					style="background:#28a745; color:white; margin-right:5px;">
					Dodaj akrobacje
				</button>';
			}
			
			return '<div style="display:flex; flex-direction:column; gap:3px;">' . implode('', $buttons) . '</div>';
		}
		
		return '<span style="color:#999;">—</span>';
	}
	
    public function renderVouchersTable($vouchers, $pagination, $type = 'upominkowe') {
        if (empty($vouchers)) {
            return $this->renderEmptyTable('Brak voucherów do wyświetlenia', $pagination);
        }

        ob_start();
        echo '<table class="wp-list-table widefat striped">';
        
        if ($type === 'partner') {
            echo '<thead><tr>';
            echo '<th>ID</th><th>Partner</th><th>Typ</th><th>Kod</th><th>Kod zabezp.</th>';
            echo '<th>Ważność</th><th>Liczba osób</th><th>Klient</th><th>Data zgłoszenia</th><th>Status</th><th>Akcje</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($vouchers as $voucher) {
                echo '<tr>';
                echo '<td><strong>#' . $voucher['id'] . '</strong></td>';
                echo '<td>' . esc_html($this->getPartnerName($voucher['partner'])) . '</td>';
                echo '<td>' . esc_html($this->getVoucherTypeName($voucher['partner'], $voucher['typ_vouchera'])) . '</td>';
                echo '<td><code>' . esc_html($voucher['kod_vouchera']) . '</code></td>';
                echo '<td><code>' . esc_html($voucher['kod_zabezpieczajacy']) . '</code></td>';
                echo '<td>' . $this->renderVoucherValidityCell($voucher) . '</td>';
                echo '<td>' . $voucher['liczba_osob'] . ' ' . ($voucher['liczba_osob'] == 1 ? 'osoba' : 'osoby') . '</td>';
                echo '<td>' . $this->renderVoucherClientCell($voucher) . '</td>';
                echo '<td>' . SRL_Helpers::getInstance()->formatujDate($voucher['data_zgloszenia'], 'd.m.Y H:i') . '</td>';
                echo '<td>' . $this->renderVoucherStatusBadge($voucher['status'], 'partner') . '</td>';
                echo '<td>' . $this->renderPartnerVoucherActions($voucher) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<thead><tr>';
            echo '<td class="manage-column check-column"><input type="checkbox" id="cb-select-all-1"></td>';
			echo '<th>ID (nr. zam)</th><th>Kupujący</th><th>Produkt</th><th>Kod</th>';
			echo '<th>Status</th><th>Data zakupu</th><th>Ważność</th><th>Wykorzystany przez</th><th>ID Lotu</th><th>Akcje</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($vouchers as $voucher) {
                echo '<tr>';
                echo '<th scope="row" class="check-column"><input type="checkbox" name="voucher_ids[]" value="' . $voucher['id'] . '"></th>';
                echo '<td>' . $this->renderVoucherIdCell($voucher) . '</td>';
                echo '<td>' . $this->renderVoucherBuyerCell($voucher) . '</td>';
                echo '<td>' . $this->renderVoucherProductCell($voucher) . '</td>';
                echo '<td><code>' . esc_html($voucher['kod_vouchera']) . '</code></td>';
                echo '<td>' . $this->renderVoucherStatusBadge($voucher['status'], 'upominkowe') . '</td>';
                echo '<td>' . SRL_Helpers::getInstance()->formatujDate($voucher['data_zakupu'], 'd.m.Y H:i') . '</td>';
                echo '<td>' . SRL_Helpers::getInstance()->formatujWaznoscLotu($voucher['data_waznosci']) . '</td>';
                echo '<td>' . $this->renderVoucherUserCell($voucher) . '</td>';
                echo '<td>' . $this->renderVoucherFlightCell($voucher) . '</td>';
				echo '<td>' . $this->renderVoucherActionsCell($voucher) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        return ob_get_clean();
    }

    public function renderCalendarTable($year, $month, $stats) {
        $first_day = strtotime("$year-$month-01");
        $days_in_month = date('t', $first_day);
        $first_weekday = date('N', $first_day);
        $current_date = date('Y-m-d');

        ob_start();
        echo '<table class="widefat fixed" style="text-align: center; border-collapse: collapse;">';
        echo '<thead><tr>';
        $weekdays = ['Pon', 'Wt', 'Śr', 'Czw', 'Pt', 'Sob', 'Nd'];
        foreach ($weekdays as $day) {
            echo '<th style="padding:10px; border:1px solid #ddd;">' . $day . '</th>';
        }
        echo '</tr></thead><tbody>';

        $day_counter = 1;
        $empty_cells = $first_weekday - 1;
        $total_rows = ceil(($days_in_month + $empty_cells) / 7);

        for ($row = 0; $row < $total_rows; $row++) {
            echo '<tr>';
            for ($col = 1; $col <= 7; $col++) {
                $cell_index = $row * 7 + $col;
                
                if ($cell_index <= $empty_cells || $day_counter > $days_in_month) {
                    echo '<td style="padding:20px; border:1px solid #ddd; background:#f9f9f9;"></td>';
                } else {
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day_counter);
                    echo $this->renderCalendarCell($date_str, $day_counter, $stats, $current_date);
                    $day_counter++;
                }
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        return ob_get_clean();
    }

    private function renderCalendarCell($date_str, $day, $stats, $current_date) {
        $day_stats = $stats[$date_str] ?? [
            'wszystkie' => 0, 'wolne' => 0, 'zarezerwowane' => 0,
            'prywatne' => 0, 'zrealizowane' => 0, 'odwolane' => 0, 'zarezerwowane_razem' => 0
        ];

        $bg_color = '#fdd';
        if ($day_stats['wszystkie'] > 0) {
            $bg_color = $day_stats['wolne'] > 0 ? '#dfd' : '#ffd';
        }

        $is_today = ($date_str === $current_date);
        $today_border = $is_today ? 'border: 3px solid #4263be; box-shadow: 0 0 8px rgba(0,115,170,0.3);' : '';

        $url = add_query_arg(['page' => 'srl-dzien', 'data' => $date_str], admin_url('admin.php'));

        ob_start();
        echo '<td style="vertical-align: top; padding:8px; border:1px solid #ddd; background:' . $bg_color . '; position: relative; ' . $today_border . ' cursor: pointer; height: 100px;" onclick="window.location.href=\'' . esc_url($url) . '\'" onmouseover="this.style.opacity=\'0.8\'" onmouseout="this.style.opacity=\'1\'" title="Kliknij aby przejść do planowania dnia ' . $day . '">';

        if ($is_today) {
            echo '<div style="position: absolute; top: 2px; right: 2px; background: #4263be; color: white; font-size: 9px; padding: 2px 4px; border-radius: 3px; font-weight: bold; pointer-events: none;">DZIŚ</div>';
        }

        echo '<div style="font-weight:bold; margin-bottom: 5px; pointer-events: none;">' . $day . '</div>';
        echo '<div style="font-size:10px; line-height: 1.3; pointer-events: none;">';
        echo '<strong>Wszystkie:</strong> ' . $day_stats['wszystkie'] . '<br>';

        if ($day_stats['wolne'] > 0) {
            echo '<strong>Wolne:</strong> ' . $day_stats['wolne'] . '<br>';
        }

        if ($day_stats['zarezerwowane_razem'] > 0) {
            echo '<strong>Zarezerwowane:</strong> ' . $day_stats['zarezerwowane_razem'] . '<br>';
            if ($day_stats['zarezerwowane'] > 0 && $day_stats['prywatne'] > 0) {
                echo '<span style="font-size: 9px; color: #666;">(zwykłe: ' . $day_stats['zarezerwowane'] . ', prywatne: ' . $day_stats['prywatne'] . ')</span><br>';
            } elseif ($day_stats['prywatne'] > 0 && $day_stats['zarezerwowane'] == 0) {
                echo '<span style="font-size: 9px; color: #666;">(prywatne: ' . $day_stats['prywatne'] . ')</span><br>';
            }
        }

        if ($day_stats['zrealizowane'] > 0) {
            echo '<strong>Zrealizowane:</strong> ' . $day_stats['zrealizowane'] . '<br>';
        }

        if ($day_stats['odwolane'] > 0) {
            echo '<strong>Odwołane:</strong> ' . $day_stats['odwolane'] . '<br>';
        }

        echo '</div></td>';
        return ob_get_clean();
    }

    private function renderEmptyTable($message, $pagination) {
        ob_start();
        echo '<table class="wp-list-table widefat striped">';
        echo '<tbody><tr><td colspan="11" style="text-align: center; padding: 40px; color: #666;">';
        echo '<p style="font-size: 16px;">' . $message . '</p>';
        if ($pagination['search'] || $pagination['status_filter']) {
            echo '<p>' . SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=' . $_GET['page']), 'Wyczyść filtry') . '</p>';
        }
        echo '</td></tr></tbody></table>';
        return ob_get_clean();
    }

	private function renderFlightIdCell($flight) {
		$html = '<strong>ID lotu: #' . $flight['id'] . '</strong><br>';
		if ($flight['order_id'] == 0) {
			$html .= '<small style="color:#666;font-style:italic;">dod. ręcznie</small>';
		} else {
			$order_url = admin_url('post.php?post=' . $flight['order_id'] . '&action=edit');
			$html .= '<small>Nr. zam: ' . SRL_Helpers::getInstance()->generateLink($order_url, '#' . $flight['order_id'], '', ['target' => '_blank', 'style' => 'color:#4263be;']) . '</small>';
		}
		
		$flight_view = SRL_Flight_View::getInstance();
		$view_url = $flight_view->generateFlightViewUrl($flight['id'], $flight['data_zakupu']);
		$html .= '<br><small>' . SRL_Helpers::getInstance()->generateLink($view_url, '🔗 Link do podglądu', '', ['target' => '_blank', 'style' => 'color:#4263be;']) . '</small>';
		
		return $html;
	}

    private function renderClientCell($flight) {
        $link = admin_url('admin.php?page=wc-orders&customer=' . $flight['user_id']);
        $html = '<strong>' . SRL_Helpers::getInstance()->generateLink($link, esc_html($flight['user_email']), '', ['target' => '_blank', 'style' => 'color: #4263be; text-decoration: none;']) . '</strong>';
        
        $telefon = get_user_meta($flight['user_id'], 'srl_telefon', true);
        if ($telefon) {
            $html .= '<br><small>📞 ' . esc_html($telefon) . '</small>';
        }
        return $html;
    }

    private function renderProductCell($flight) {
        $html = '<strong>Lot w tandemie</strong>';
        $html .= '<br><small style="font-weight: bold;">' . SRL_Helpers::getInstance()->formatFlightOptionsHtml($flight['ma_filmowanie'], $flight['ma_akrobacje']) . '</small>';
        return $html;
    }

    private function renderReservationCell($flight) {
        if ($flight['status'] === 'zarezerwowany' && $flight['data_lotu']) {
            $html = '<strong>' . SRL_Helpers::getInstance()->formatujDate($flight['data_lotu']) . '</strong>';
            $html .= '<br><small>' . substr($flight['godzina_start'], 0, 5) . ' - ' . substr($flight['godzina_koniec'], 0, 5) . '</small>';
            if ($flight['data_rezerwacji']) {
                $html .= '<br><small style="color: #666;">Rez: ' . date('d.m.Y H:i', strtotime($flight['data_rezerwacji'])) . '</small>';
            }
            return $html;
        } elseif ($flight['status'] === 'zrealizowany' && $flight['data_lotu']) {
            $html = '<span style="color: #46b450;">✅ ' . SRL_Helpers::getInstance()->formatujDate($flight['data_lotu']) . '</span>';
            $html .= '<br><small style="color: #46b450;">' . substr($flight['godzina_start'], 0, 5) . ' - ' . substr($flight['godzina_koniec'], 0, 5) . '</small>';
            return $html;
        }
        return '<span style="color: #999;">—</span>';
    }

	private function renderDetailsCell($flight) {
		$html = SRL_Helpers::getInstance()->generateButton('INFO', 'button button-small srl-info-lot', [
			'type' => 'button', 
			'data-lot-id' => $flight['id'], 
			'data-user-id' => $flight['user_id']
		]);
		
		$html .= SRL_Helpers::getInstance()->generateButton('Historia', 'button button-small srl-historia-lot', [
			'type' => 'button', 
			'data-lot-id' => $flight['id'], 
			'style' => 'margin-left: 5px;'
		]);
		
		// DODAJ TEN FRAGMENT - przycisk QR
		$html .= SRL_QR_Code_Generator::getInstance()->renderQRButton($flight['id'], 'QR kod');
		
		// Dodaj przycisk "Zarządzaj" tylko dla zarezerwowanych lotów
		if ($flight['status'] === 'zarezerwowany' && !empty($flight['data_lotu'])) {
			$manage_url = admin_url('admin.php?page=srl-dzien&data=' . $flight['data_lotu']);
			$html .= '<a href="' . esc_url($manage_url) . '" class="button button-primary button-small" style="margin-left: 5px;">Zarządzaj</a>';
		}
		
		return $html;
	}

    private function renderVoucherIdCell($voucher) {
        $html = '<strong>#' . $voucher['id'] . '</strong><br>';
        if ($voucher['order_id'] == 0) {
            $html .= '<small style="color: #666; font-style: italic;">dod. ręcznie</small>';
        } else {
            $order_url = admin_url('post.php?post=' . $voucher['order_id'] . '&action=edit');
            $html .= '<small>Zam: ' . SRL_Helpers::getInstance()->generateLink($order_url, '#' . $voucher['order_id'], '', ['target' => '_blank']) . '</small>';
        }
        return $html;
    }

    private function renderVoucherBuyerCell($voucher) {
        $link = admin_url('admin.php?page=wc-orders&customer=' . $voucher['buyer_user_id']);
        $html = '<strong>' . SRL_Helpers::getInstance()->generateLink($link, esc_html($voucher['buyer_imie'] . ' ' . $voucher['buyer_nazwisko']), '', ['target' => '_blank', 'style' => 'color: #4263be; text-decoration: none;']) . '</strong>';
        $html .= '<br><small>' . esc_html($voucher['buyer_email']) . '</small>';
        return $html;
    }

    private function renderVoucherProductCell($voucher) {
        $html = '<strong>' . esc_html($voucher['nazwa_produktu']) . '</strong>';
        if ($voucher['ma_filmowanie'] || $voucher['ma_akrobacje']) {
            $html .= '<br><small style="color: #4263be;">' . SRL_Helpers::getInstance()->formatFlightOptionsHtml($voucher['ma_filmowanie'], $voucher['ma_akrobacje']) . '</small>';
        }
        return $html;
    }

    private function renderVoucherUserCell($voucher) {
        if ($voucher['status'] === 'wykorzystany' && $voucher['user_display_name']) {
            $link = admin_url('admin.php?page=wc-orders&customer=' . $voucher['wykorzystany_przez_user_id']);
            $html = '<strong>' . SRL_Helpers::getInstance()->generateLink($link, esc_html($voucher['user_display_name']), '', ['target' => '_blank', 'style' => 'color: #4263be; text-decoration: none;']) . '</strong>';
            $html .= '<br><small>' . esc_html($voucher['user_email']) . '</small>';
            if ($voucher['data_wykorzystania']) {
                $html .= '<br><small style="color: #666;">Wykorzystano: ' . SRL_Helpers::getInstance()->formatujDate($voucher['data_wykorzystania'], 'd.m.Y H:i') . '</small>';
            }
            return $html;
        }
        return '<span style="color: #999;">—</span>';
    }

    private function renderVoucherFlightCell($voucher) {
        if ($voucher['lot_id']) {
            return SRL_Helpers::getInstance()->generateLink(admin_url('admin.php?page=srl-wykupione-loty&s=' . $voucher['lot_id']), '#' . $voucher['lot_id'], '', ['target' => '_blank', 'style' => 'color: #4263be; font-weight: bold;']);
        }
        return '<span style="color: #999;">—</span>';
    }

    private function renderVoucherValidityCell($voucher) {
        if ($voucher['status'] === 'oczekuje') {
            return '<input type="date" id="validity-date-' . $voucher['id'] . '" value="' . esc_attr($voucher['data_waznosci_vouchera']) . '" style="width: 140px; padding: 4px; border: 1px solid #ddd; border-radius: 4px;">';
        }
        return esc_html($voucher['data_waznosci_vouchera'] ? SRL_Helpers::getInstance()->formatujDate($voucher['data_waznosci_vouchera']) : 'Brak');
    }

    private function renderVoucherClientCell($voucher) {
        $user = get_userdata($voucher['klient_id']);
        return $user ? esc_html($user->display_name . ' (' . $user->user_email . ')') : 'Nieznany użytkownik';
    }

    private function renderVoucherStatusBadge($status, $type) {
        $config = [
            'partner' => [
                'oczekuje' => ['bg' => '#f39c12', 'label' => 'OCZEKUJE'],
                'zatwierdzony' => ['bg' => '#27ae60', 'label' => 'ZATWIERDZONY'],
                'odrzucony' => ['bg' => '#e74c3c', 'label' => 'ODRZUCONY']
            ],
            'upominkowe' => [
                'do_wykorzystania' => ['bg' => '#28a745', 'label' => 'Do wykorzystania'],
                'wykorzystany' => ['bg' => '#007bff', 'label' => 'Wykorzystany'],
                'przeterminowany' => ['bg' => '#dc3545', 'label' => 'Przeterminowany']
            ]
        ];

        $item = $config[$type][$status] ?? ['bg' => '#6c757d', 'label' => ucfirst($status)];
        return sprintf('<span style="background: %s; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">%s</span>', $item['bg'], $item['label']);
    }

    private function renderPartnerVoucherActions($voucher) {
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

    private function getPartnerName($partner_key) {
        $config = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherConfig();
        return $config[$partner_key]['nazwa'] ?? $partner_key;
    }

    private function getVoucherTypeName($partner_key, $type_key) {
        $config = SRL_Partner_Voucher_Functions::getInstance()->getPartnerVoucherConfig();
        return $config[$partner_key]['typy'][$type_key]['nazwa'] ?? $type_key;
    }

    public function renderPagination($total_items, $per_page, $current_page) {
        if ($total_items <= $per_page) return '';

        $total_pages = ceil($total_items / $per_page);
        
        ob_start();
        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . $total_items . ' elementów</span>';
        
        $page_links = paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $total_pages,
            'current' => $current_page
        ]);
        
        echo $page_links;
        echo '</div>';
        return ob_get_clean();
    }

    public function renderStats($stats, $labels) {
        ob_start();
        echo '<div class="srl-stats" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">';
        
        $stats_array = [];
        foreach ($stats as $stat) {
            $stats_array[$stat['status']] = $stat['count'];
        }
        
        foreach ($labels as $status => $label) {
            $count = $stats_array[$status] ?? 0;
            echo '<div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); min-width: 120px; display: flex; align-items: center; gap: 10px;">';
            echo '<div style="font-size: 24px; font-weight: bold; color: #4263be;">' . $count . '</div>';
            echo '<div style="font-size: 13px; color: #666;">' . $label . '</div>';
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public function __destruct() {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('srl_cache');
        }
    }
	
	public function formatVoucherOptions($voucher) {
		$opcje = array();
		
		// Sprawdź bezpośrednie opcje z bazy
		$ma_filmowanie = isset($voucher['ma_filmowanie']) ? intval($voucher['ma_filmowanie']) : 0;
		$ma_akrobacje = isset($voucher['ma_akrobacje']) ? intval($voucher['ma_akrobacje']) : 0;
		
		// Jeśli nie ma bezpośrednich opcji, spróbuj wykryć z nazwy
		if (!$ma_filmowanie && !$ma_akrobacje && isset($voucher['nazwa_produktu'])) {
			$detected = SRL_Helpers::getInstance()->detectFlightOptions($voucher['nazwa_produktu']);
			$ma_filmowanie = $detected['ma_filmowanie'];
			$ma_akrobacje = $detected['ma_akrobacje'];
		}
		
		$opcje[] = $ma_filmowanie ? 
			'<span style="color: #46b450; font-weight: bold;">z filmowaniem</span>' : 
			'<span style="color: #d63638;">bez filmowania</span>';
			
		$opcje[] = $ma_akrobacje ? 
			'<span style="color: #46b450; font-weight: bold;">z akrobacjami</span>' : 
			'<span style="color: #d63638;">bez akrobacji</span>';
		
		return implode(', ', $opcje);
	}
	
	private function renderVoucherActionsCell($voucher) {
		$nonce = wp_create_nonce('srl_admin_nonce');
		
		return '<button type="button" 
				class="button button-secondary button-small srl-download-voucher" 
				data-voucher-id="' . $voucher['id'] . '"
				data-nonce="' . $nonce . '"
				style="margin-right: 5px;">
			📥 Pobierz
		</button>
		<button type="button" 
				class="button button-primary button-small srl-send-voucher-email" 
				data-voucher-id="' . $voucher['id'] . '"
				data-nonce="' . $nonce . '">
			📧 Wyślij
		</button>
		
		<script>
		jQuery(document).ready(function($) {
			// Istniejący kod pobierania...
			$(".srl-download-voucher").off("click").on("click", function(e) {
				e.preventDefault();
				
				var voucherId = $(this).data("voucher-id");
				var nonce = $(this).data("nonce");
				var button = $(this);
				
				if (!voucherId) {
					alert("Błąd: Nieprawidłowy ID vouchera");
					return;
				}
				
				button.prop("disabled", true).text("Generowanie...");
				
				$.ajax({
					url: ajaxurl,
					method: "POST",
					data: {
						action: "srl_download_voucher",
						voucher_id: voucherId,
						nonce: nonce
					},
					xhrFields: {
						responseType: "blob"
					},
					success: function(data, status, xhr) {
						var blob = new Blob([data], { type: "image/jpeg" });
						var url = window.URL.createObjectURL(blob);
						var a = document.createElement("a");
						a.href = url;
						a.download = "voucher-" + voucherId + ".jpg";
						document.body.appendChild(a);
						a.click();
						
						window.URL.revokeObjectURL(url);
						document.body.removeChild(a);
						
						button.prop("disabled", false).text("📥 Pobierz");
					},
					error: function(xhr, status, error) {
						console.error("Błąd pobierania vouchera:", error);
						alert("Błąd podczas generowania vouchera. Sprawdź logi serwera.");
						button.prop("disabled", false).text("📥 Pobierz");
					}
				});
			});
			
			// Nowy kod wysyłania emailem...
			$(".srl-send-voucher-email").off("click").on("click", function(e) {
				e.preventDefault();
				
				var voucherId = $(this).data("voucher-id");
				var nonce = $(this).data("nonce");
				var button = $(this);
				
				if (!voucherId) {
					alert("Błąd: Nieprawidłowy ID vouchera");
					return;
				}
				
				if (!confirm("Czy na pewno wysłać voucher emailem do klienta?")) {
					return;
				}
				
				button.prop("disabled", true).text("Wysyłanie...");
				
				$.ajax({
					url: ajaxurl,
					method: "POST",
					data: {
						action: "srl_send_voucher_email",
						voucher_id: voucherId,
						nonce: nonce
					},
					success: function(response) {
						if (response.success) {
							alert("Voucher został wysłany emailem!");
						} else {
							alert("Błąd: " + response.data);
						}
						button.prop("disabled", false).text("📧 Wyślij");
					},
					error: function(xhr, status, error) {
						console.error("Błąd wysyłania vouchera:", error);
						alert("Błąd podczas wysyłania vouchera emailem.");
						button.prop("disabled", false).text("📧 Wyślij");
					}
				});
			});
		});
		</script>';
	}
	
}