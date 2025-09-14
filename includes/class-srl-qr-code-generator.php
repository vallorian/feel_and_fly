<?php
if (!defined('ABSPATH')) {exit;}

class SRL_QR_Code_Generator {
    private static $instance = null;
    private $flight_view;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->flight_view = SRL_Flight_View::getInstance();
        $this->initHooks();
		
		add_shortcode('srl_qr_code', [$this, 'qrCodeShortcode']);
    }

    private function initHooks() {
        // AJAX endpoint dla generowania QR kodu
        add_action('wp_ajax_srl_generate_qr_code', [$this, 'ajaxGenerateQRCode']);
        
        // Dodaj przycisk QR w panelu admina (opcjonalnie)
        add_action('admin_init', [$this, 'addAdminStyles']);
    }

    /**
     * Generuje QR kod używając Google Charts API
     */
    public function generateQRCodeUrl($text, $size = 200) {
		$encoded_text = urlencode($text);
		return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encoded_text}";
	}

    /**
     * Generuje QR kod dla konkretnego lotu
     */
    public function generateFlightQRCode($flight_id, $size = 200) {
        global $wpdb;
        
        // Pobierz dane lotu
        $flight = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d", 
            $flight_id
        ), ARRAY_A);
        
        if (!$flight) {
            return false;
        }

        // Wygeneruj URL do lotu
        $flight_url = $this->flight_view->generateFlightViewUrl($flight_id, $flight['data_zakupu']);
        
        // Wygeneruj QR kod
        return $this->generateQRCodeUrl($flight_url, $size);
    }

    /**
     * AJAX handler dla generowania QR kodu
     */
    public function ajaxGenerateQRCode() {
        check_ajax_referer('srl_admin_nonce', 'nonce');
        SRL_Helpers::getInstance()->checkAdminPermissions();

        $flight_id = intval($_POST['flight_id']);
        $size = intval($_POST['size'] ?? 200);
        
        if (!$flight_id) {
            wp_send_json_error('Nieprawidłowy ID lotu.');
        }

        $qr_url = $this->generateFlightQRCode($flight_id, $size);
        
        if (!$qr_url) {
            wp_send_json_error('Nie udało się wygenerować QR kodu.');
        }

        // Pobierz dane lotu dla dodatkowych informacji
        global $wpdb;
        $flight = $wpdb->get_row($wpdb->prepare(
            "SELECT zl.*, u.display_name, CONCAT(zl.imie, ' ', zl.nazwisko) as full_name
             FROM {$wpdb->prefix}srl_zakupione_loty zl
             LEFT JOIN {$wpdb->users} u ON zl.user_id = u.ID
             WHERE zl.id = %d", 
            $flight_id
        ), ARRAY_A);

        $flight_url = $this->flight_view->generateFlightViewUrl($flight_id, $flight['data_zakupu']);

        wp_send_json_success([
            'qr_url' => $qr_url,
            'flight_url' => $flight_url,
            'flight_info' => [
                'id' => $flight['id'],
                'client_name' => $flight['full_name'] ?: $flight['display_name'],
                'status' => $flight['status'],
                'expiry_date' => $flight['data_waznosci']
            ]
        ]);
    }

    /**
     * Pobiera QR kod jako obraz (base64) - opcjonalne
     */
    public function getQRCodeAsBase64($text, $size = 200) {
        $qr_url = $this->generateQRCodeUrl($text, $size);
        
        $image_data = wp_remote_get($qr_url);
        
        if (is_wp_error($image_data)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($image_data);
        return 'data:image/png;base64,' . base64_encode($body);
    }

    /**
     * Renderuje przycisk QR kodu w panelu admin
     */
    public function renderQRButton($flight_id, $button_text = 'Generuj QR kod') {
        $nonce = wp_create_nonce('srl_admin_nonce');
        
        ob_start();
        ?>
        <button type="button" 
                class="button button-secondary srl-qr-generator" 
                data-flight-id="<?php echo esc_attr($flight_id); ?>"
                data-nonce="<?php echo esc_attr($nonce); ?>">
            📱 <?php echo esc_html($button_text); ?>
        </button>
        
        <!-- Modal dla QR kodu -->
        <div id="srl-qr-modal-<?php echo $flight_id; ?>" class="srl-qr-modal" style="display: none;">
            <div class="srl-qr-modal-content">
                <span class="srl-qr-close">&times;</span>
                <h3>QR kod dla lotu #<?php echo $flight_id; ?></h3>
                <div class="srl-qr-container">
                    <img id="srl-qr-image-<?php echo $flight_id; ?>" src="" alt="QR Code" style="max-width: 100%;">
                </div>
                <div class="srl-qr-info">
                    <p><strong>Link:</strong> <span id="srl-qr-url-<?php echo $flight_id; ?>"></span></p>
                    <p><small>Klient może zeskanować kod QR lub użyć linku aby zobaczyć szczegóły lotu.</small></p>
                </div>
                <div class="srl-qr-actions">
                    <button type="button" class="button button-primary" onclick="srlDownloadQR(<?php echo $flight_id; ?>)">
                        💾 Pobierz QR kod
                    </button>
                    <button type="button" class="button" onclick="srlCopyLink(<?php echo $flight_id; ?>)">
                        📋 Kopiuj link
                    </button>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Obsługa kliknięcia przycisku QR
            $('.srl-qr-generator[data-flight-id="<?php echo $flight_id; ?>"]').on('click', function() {
                var flightId = $(this).data('flight-id');
                var nonce = $(this).data('nonce');
                var button = $(this);
                
                button.prop('disabled', true).text('Generowanie...');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'srl_generate_qr_code',
                        flight_id: flightId,
                        size: 250,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Wyświetl modal z QR kodem
                            $('#srl-qr-image-' + flightId).attr('src', response.data.qr_url);
                            $('#srl-qr-url-' + flightId).text(response.data.flight_url);
                            $('#srl-qr-modal-' + flightId).show();
                            
                            // Zapisz dane do wykorzystania przez inne funkcje
                            window.srlQRData = window.srlQRData || {};
                            window.srlQRData[flightId] = response.data;
                        } else {
                            alert('Błąd: ' + response.data);
                        }
                        button.prop('disabled', false).text('📱 <?php echo esc_js($button_text); ?>');
                    },
                    error: function() {
                        alert('Błąd połączenia z serwerem');
                        button.prop('disabled', false).text('📱 <?php echo esc_js($button_text); ?>');
                    }
                });
            });
            
            // Obsługa zamykania modala
            $('.srl-qr-close').on('click', function() {
                $(this).closest('.srl-qr-modal').hide();
            });
            
            // Zamknij modal po kliknięciu poza nim
            $('.srl-qr-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });
        });
        
        // Funkcja pobierania QR kodu
        function srlDownloadQR(flightId) {
            if (window.srlQRData && window.srlQRData[flightId]) {
                var qrUrl = window.srlQRData[flightId].qr_url;
                var link = document.createElement('a');
                link.href = qrUrl;
                link.download = 'qr-code-lot-' + flightId + '.png';
                link.target = '_blank';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
        
        // Funkcja kopiowania linku
        function srlCopyLink(flightId) {
            if (window.srlQRData && window.srlQRData[flightId]) {
                var url = window.srlQRData[flightId].flight_url;
                
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(function() {
                        alert('Link został skopiowany do schowka!');
                    });
                } else {
                    // Fallback dla starszych przeglądarek
                    var textArea = document.createElement('textarea');
                    textArea.value = url;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    alert('Link został skopiowany do schowka!');
                }
            }
        }
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Dodaje style CSS dla modala QR
     */
    public function addAdminStyles() {
        add_action('admin_head', function() {
            ?>
            <style>
            .srl-qr-modal {
                position: fixed;
                z-index: 9999;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
            }
            
            .srl-qr-modal-content {
                background-color: #fefefe;
                margin: 5% auto;
                padding: 20px;
                border-radius: 8px;
                width: 90%;
                max-width: 500px;
                position: relative;
            }
            
            .srl-qr-close {
                color: #aaa;
                float: right;
                font-size: 28px;
                font-weight: bold;
                position: absolute;
                right: 15px;
                top: 10px;
                cursor: pointer;
            }
            
            .srl-qr-close:hover {
                color: #000;
            }
            
            .srl-qr-container {
                text-align: center;
                margin: 20px 0;
            }
            
            .srl-qr-info {
                background: #f9f9f9;
                padding: 15px;
                border-radius: 4px;
                margin: 15px 0;
                word-break: break-all;
            }
            
            .srl-qr-actions {
                text-align: center;
                margin-top: 20px;
            }
            
            .srl-qr-actions button {
                margin: 0 5px;
            }
            </style>
            <?php
        });
    }

    /**
     * Shortcode dla QR kodu [srl_qr_code flight_id="123"]
     */
    public function qrCodeShortcode($atts) {
        $atts = shortcode_atts([
            'flight_id' => 0,
            'size' => 200
        ], $atts);

        if (!$atts['flight_id']) {
            return '<p>Błąd: Brak ID lotu.</p>';
        }

        $qr_url = $this->generateFlightQRCode($atts['flight_id'], $atts['size']);
        
        if (!$qr_url) {
            return '<p>Błąd: Nie udało się wygenerować QR kodu.</p>';
        }

        return sprintf(
            '<div class="srl-qr-shortcode"><img src="%s" alt="QR kod dla lotu #%d" style="max-width: 100%%;"></div>',
            esc_url($qr_url),
            intval($atts['flight_id'])
        );
    }
	
	public function generateQRAttachment($flight_id, $filename = null) {
		global $wpdb;
		
		$flight = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d", 
			$flight_id
		), ARRAY_A);
		
		if (!$flight) {
			return false;
		}

		$flight_url = $this->flight_view->generateFlightViewUrl($flight_id, $flight['data_zakupu']);
		$qr_url = $this->generateQRCodeUrl($flight_url, 300);
		
		// Pobierz obraz QR
		$response = wp_remote_get($qr_url);
		if (is_wp_error($response)) {
			return false;
		}
		
		$image_data = wp_remote_retrieve_body($response);
		if (empty($image_data)) {
			return false;
		}
		
		// Utwórz tymczasowy plik
		$upload_dir = wp_upload_dir();
		$filename = $filename ?: "qr-code-lot-{$flight_id}.png";
		$file_path = $upload_dir['basedir'] . '/qr_temp_' . uniqid() . '.png';
		
		file_put_contents($file_path, $image_data);
		
		return [
			'path' => $file_path,
			'name' => $filename,
			'cleanup' => true // oznacza że plik powinien być usunięty po wysłaniu
		];
	}

	/**
	 * Renderuje QR kod dla widoku lotu
	 */
	public function renderQRForFlightView($flight_id, $size = 200) {
		global $wpdb;
		
		$flight = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d", 
			$flight_id
		), ARRAY_A);
		
		if (!$flight) {
			return '';
		}

		$flight_url = $this->flight_view->generateFlightViewUrl($flight_id, $flight['data_zakupu']);
		$qr_url = $this->generateQRCodeUrl($flight_url, $size);
		
		ob_start();
		?>
		<div class="srl-qr-section" style="text-align: center; margin: 25px 0; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 2px solid #4263be;">
			<h4 style="margin: 0 0 15px; color: #4263be;">📱 QR kod lotu</h4>
			<img src="<?php echo esc_url($qr_url); ?>" 
				 alt="QR kod lotu #<?php echo $flight_id; ?>" 
				 style="max-width: <?php echo $size; ?>px; border: 2px solid #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
			<p style="margin: 15px 0 5px; font-size: 14px; color: #666;">
				Zeskanuj kod telefonem aby szybko otworzyć szczegóły lotu
			</p>
			<p style="margin: 0; font-size: 12px; color: #888; font-style: italic;">
				Pokaż ten kod na lotnisku
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
	

	public function generateQREmailHTML($flight_id, $size = 200) {
		global $wpdb;
		
		$flight = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d", 
			$flight_id
		), ARRAY_A);
		
		if (!$flight) {
			return '';
		}

		$flight_url = $this->flight_view->generateFlightViewUrl($flight_id, $flight['data_zakupu']);
		$qr_url = $this->generateQRCodeUrl($flight_url, $size);
		
		return $qr_url;
	}
	
}