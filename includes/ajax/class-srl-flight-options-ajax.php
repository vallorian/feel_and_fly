<?php
if (!defined('ABSPATH')) {exit;}

class SRL_Flight_Options_Ajax {
    
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_srl_sprawdz_opcje_w_koszyku', array($this, 'ajaxSprawdzOpcjeWKoszyku'));
        add_action('wp_ajax_nopriv_srl_sprawdz_opcje_w_koszyku', array($this, 'ajaxSprawdzOpcjeWKoszyku'));
        add_action('wp_ajax_srl_usun_opcje_z_koszyka', array($this, 'ajaxUsunOpcjeZKoszyka'));
        add_action('wp_ajax_nopriv_srl_usun_opcje_z_koszyka', array($this, 'ajaxUsunOpcjeZKoszyka'));
        add_action('wp_ajax_srl_sprawdz_i_dodaj_opcje', array($this, 'ajaxSprawdzIDodajOpcje'));
        add_action('wp_ajax_woocommerce_add_to_cart', array($this, 'ajaxAddToCart'));
        add_action('wp_ajax_nopriv_woocommerce_add_to_cart', array($this, 'ajaxAddToCart'));
    }

    public function ajaxSprawdzOpcjeWKoszyku() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $opcje_w_koszyku = array();

        if (WC()->cart) {
            $opcje_produkty = SRL_Helpers::getInstance()->getFlightOptionProductIds();
            
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                if (isset($cart_item['srl_lot_id'])) {
                    $lot_id = $cart_item['srl_lot_id'];
                    $product_id = $cart_item['product_id'];

                    if (!isset($opcje_w_koszyku[$lot_id])) {
                        $opcje_w_koszyku[$lot_id] = array('filmowanie' => false, 'akrobacje' => false, 'przedluzenie' => false);
                    }

                    if ($product_id == $opcje_produkty['filmowanie']) {
                        $opcje_w_koszyku[$lot_id]['filmowanie'] = true;
                    } elseif ($product_id == $opcje_produkty['akrobacje']) {
                        $opcje_w_koszyku[$lot_id]['akrobacje'] = true;
                    } elseif ($product_id == $opcje_produkty['przedluzenie']) {
                        $opcje_w_koszyku[$lot_id]['przedluzenie'] = true;
                    }
                }
            }
        }

        wp_send_json_success($opcje_w_koszyku);
    }

    public function ajaxUsunOpcjeZKoszyka() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true); 
        SRL_Helpers::getInstance()->requireLogin();

        $lot_id = intval($_POST['lot_id']);
        $product_id = intval($_POST['product_id']);

        if (!WC()->cart) {
            wp_send_json_error('Koszyk nie jest dostępny.');
        }

        $removed = false;

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['srl_lot_id']) && 
                $cart_item['srl_lot_id'] == $lot_id && 
                $cart_item['product_id'] == $product_id) {

                WC()->cart->remove_cart_item($cart_item_key);
                $removed = true;
                break;
            }
        }

        if ($removed) {
            wp_send_json_success('Opcja została usunięta z koszyka.');
        } else {
            wp_send_json_error('Nie znaleziono opcji w koszyku.');
        }
    }

    public function ajaxSprawdzIDodajOpcje() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        SRL_Helpers::getInstance()->requireLogin();

        $product_id = intval($_POST['product_id']);
        $lot_id = intval($_POST['srl_lot_id']);

        if (!WC()->cart) {
            wp_send_json_error('Koszyk nie jest dostępny.');
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (isset($cart_item['srl_lot_id']) && 
                $cart_item['srl_lot_id'] == $lot_id && 
                $cart_item['product_id'] == $product_id) {
                wp_send_json_error('Ta opcja jest już w koszyku.');
            }
        }

        $cart_item_data = $this->ajaxPrepareCartItemData($lot_id);
        if (!$cart_item_data) {
            wp_send_json_error('Nie znaleziono lotu lub brak uprawnień.');
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_item_data);

        if ($cart_item_key) {
            wp_send_json_success(array(
                'cart_item_key' => $cart_item_key,
                'product_id' => $product_id,
                'cart_count' => WC()->cart->get_cart_contents_count()
            ));
        } else {
            wp_send_json_error('Nie udało się dodać produktu do koszyka');
        }
    }

    public function ajaxAddToCart() {
        check_ajax_referer('srl_frontend_nonce', 'nonce', true);
        
        if (!isset($_POST['product_id'])) {
            wp_send_json_error('Brak ID produktu');
        }

        $product_id = intval($_POST['product_id']);
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $lot_id = isset($_POST['srl_lot_id']) ? intval($_POST['srl_lot_id']) : 0;

        $cart_item_data = array();
        if ($lot_id) {
            $cart_item_data = $this->ajaxPrepareCartItemData($lot_id);
        }

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);

        if ($cart_item_key) {
            wp_send_json_success(array(
                'cart_item_key' => $cart_item_key,
                'product_id' => $product_id,
                'cart_count' => WC()->cart->get_cart_contents_count()
            ));
        } else {
            wp_send_json_error('Nie udało się dodać produktu do koszyka');
        }
    }

    private function ajaxPrepareCartItemData($lot_id) {
        if (!$lot_id || !is_user_logged_in()) {
            return array();
        }
        
        $lot = SRL_Database_Helpers::getInstance()->getFlightById($lot_id);
        
        if (!$lot || $lot['user_id'] != get_current_user_id()) {
            return false;
        }
        
        return array(
            'srl_lot_id' => $lot_id,
            'srl_lot_info' => array(
                'nazwa_produktu' => $lot['nazwa_produktu'],
                'data_zakupu' => $lot['data_zakupu']
            )
        );
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
					alert("Status lotu został zmieniony na wolny.");
					location.reload();
				} else {
					alert("Błąd: " + response.data);
					button.prop("disabled", false).text("Odwołaj");
				}
			}).fail(function() {
				alert("Błąd połączenia z serwerem.");
				button.prop("disabled", false).text("Odwołaj");
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
					alert("Lot został oznaczony jako zrealizowany.");
					location.reload();
				} else {
					alert("Błąd: " + response.data);
					button.prop("disabled", false).text("Zrealizuj");
				}
			}).fail(function() {
				alert("Błąd połączenia z serwerem.");
				button.prop("disabled", false).text("Zrealizuj");
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
					content += "<p><strong>Email:</strong> " + (info.email || "Brak danych") + "</p>";
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
					content += "<hr>";
					content += "<p><strong>Nazwa produktu:</strong> " + (info.nazwa_produktu || "Brak danych") + "</p>";
					content += "<p><strong>Status:</strong> " + (info.status || "Brak danych") + "</p>";
					content += "<p><strong>Data zakupu:</strong> " + (info.data_zakupu || "Brak danych") + "</p>";
					content += "<p><strong>Data ważności:</strong> " + (info.data_waznosci || "Brak danych") + "</p>";
					content += "</div>";
					
					showModal("Szczegóły lotu", content);
				} else {
					alert("Błąd: " + response.data);
				}
			}).fail(function() {
				alert("Błąd połączenia z serwerem.");
			});
		});
		
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
			}).fail(function() {
				alert("Błąd połączenia z serwerem.");
			});
		});

		// Funkcja do pokazywania modala
		function showModal(title, content) {
			var modal = $("<div style=\"position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;\">" +
				"<div style=\"background: white; padding: 30px; border-radius: 8px; max-width: 90%; max-height: 90%; overflow-y: auto; position: relative;\">" +
				"<button style=\"position: absolute; top: 10px; right: 15px; background: none; border: none; font-size: 24px; cursor: pointer; color: #999;\" class=\"close-modal\">&times;</button>" +
				"<h2 style=\"margin-top: 0; color: #0073aa;\">" + title + "</h2>" +
				content +
				"<div style=\"margin-top: 20px; text-align: center;\">" +
				"<button style=\"padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;\" class=\"close-modal\">Zamknij</button>" +
				"</div>" +
				"</div></div>");
			
			$("body").append(modal);
			
			modal.find(".close-modal").on("click", function() {
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

		// Funkcja do pokazywania historii lotu
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
			
			showModal("Historia lotu", content);
		}
		
		// Obsługa filtrów dat
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
	});
	</script>';
	}
	
}