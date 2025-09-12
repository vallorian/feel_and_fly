<?php
// includes/class-srl-flight-view.php
if (!defined('ABSPATH')) {exit;}

class SRL_Flight_View {
    private static $instance = null;
    private $helpers;
    private $db_helpers;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->helpers = SRL_Helpers::getInstance();
        $this->db_helpers = SRL_Database_Helpers::getInstance();
        $this->initHooks();
    }

    private function initHooks() {
        add_action('init', [$this, 'addRewriteRule']);
        add_action('template_redirect', [$this, 'handleFlightView']);
        add_action('wp_ajax_srl_change_flight_status_public', [$this, 'ajaxChangeFlightStatus']);
        add_action('wp_ajax_srl_cancel_flight_admin', [$this, 'ajaxCancelFlight']);
    }

    public function addRewriteRule() {
        add_rewrite_rule('^lot/([0-9]+)_([a-zA-Z0-9]{16})/?$', 'index.php?srl_flight_view=1&flight_id=$matches[1]&security_code=$matches[2]', 'top');
        add_rewrite_tag('%srl_flight_view%', '([^&]+)');
        add_rewrite_tag('%flight_id%', '([0-9]+)');
        add_rewrite_tag('%security_code%', '([a-zA-Z0-9]{16})');
    }

    public function flushRewriteRules() {
        $this->addRewriteRule();
        flush_rewrite_rules();
    }

    public function handleFlightView() {
        if (!get_query_var('srl_flight_view')) return;

        $flight_id = intval(get_query_var('flight_id'));
        $security_code = sanitize_text_field(get_query_var('security_code'));

        if (!$flight_id || !$security_code) {
            wp_die('Nieprawidłowy link.');
        }

        $flight = $this->getFlightWithSecurity($flight_id, $security_code);
        if (!$flight) {
            wp_die('Link jest nieprawidłowy lub wygasł.');
        }

        $this->displayFlightView($flight);
        exit;
    }

    private function getFlightWithSecurity($flight_id, $security_code) {
        global $wpdb;
        
        $flight = $wpdb->get_row($wpdb->prepare(
            "SELECT zl.*, t.data, t.godzina_start, t.godzina_koniec, t.pilot_id, t.status as slot_status,
                    u.user_email, u.display_name
             FROM {$wpdb->prefix}srl_zakupione_loty zl
             LEFT JOIN {$wpdb->prefix}srl_terminy t ON zl.termin_id = t.id
             LEFT JOIN {$wpdb->users} u ON zl.user_id = u.ID
             WHERE zl.id = %d",
            $flight_id
        ), ARRAY_A);

        if (!$flight) return false;

        $expected_code = $this->generateSecurityCode($flight_id, $flight['data_zakupu']);
        if (!hash_equals($expected_code, $security_code)) return false;

        // Pobierz dane użytkownika z cache managera
        $user_data = SRL_Cache_Manager::getInstance()->getUserData($flight['user_id']);
        if ($user_data) {
            $flight = array_merge($flight, $user_data);
        }

        return $flight;
    }

    private function displayFlightView($flight) {
        $is_logged_in = is_user_logged_in();
        $is_admin = current_user_can('manage_woocommerce');
        
        wp_head();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Lot #<?php echo $flight['id']; ?> - <?php bloginfo('name'); ?></title>
            <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #fff; margin: 0; padding: 20px; line-height: 1.6; color: #333; }
            .flight-container { max-width: 480px; margin: 0 auto; }
            .flight-header { text-align: center; margin-bottom: 25px; border-bottom: 2px solid #4263be; padding-bottom: 20px; }
            .flight-id { font-size: 24px; font-weight: 700; color: #4263be; margin-bottom: 8px; }
            .flight-date { font-size: 18px; color: #333; margin-bottom: 15px; font-weight: 600; }
            .flight-date.today { color: #155724; }
            .login-panel { background: #f8f9fa; border: 2px solid #4263be; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
            .login-toggle { background: #4263be; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; width: 100%; font-size: 16px; }
            .login-form { display: none; margin-top: 15px; }
            .login-form input { width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            .login-form button { background: #46b450; color: white; padding: 10px; border: none; border-radius: 4px; cursor: pointer; width: 100%; font-size: 14px; margin-top: 10px; }
            .flight-options { display: flex; gap: 10px; justify-content: center; margin: 20px 0; flex-wrap: wrap; }
            .option-badge { padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600; text-align: center; }
            .option-yes { background: #d4edda; color: #155724; }
            .option-no { background: #f8d7da; color: #721c24; }
            .status-badge { display: inline-block; padding: 12px 20px; border-radius: 8px; font-size: 16px; font-weight: 600; text-align: center; margin: 20px 0; width: 100%; box-sizing: border-box; }
            .status-wolny { background: #d4edda; color: #155724; }
            .status-zarezerwowany { background: #fff3cd; color: #856404; }
            .status-zrealizowany { background: #cce5ff; color: #004085; }
            .status-przedawniony { background: #f8d7da; color: #721c24; }
            .details-section { margin-top: 25px; }
            .details-title { font-size: 18px; font-weight: 600; margin-bottom: 15px; color: #333; }
            .details-table { width: 100%; border-collapse: collapse; }
            .details-table tr { border-bottom: 1px solid #eee; }
            .details-table td { padding: 12px 8px; vertical-align: top; }
            .details-table td:first-child { font-weight: 600; color: #666; width: 40%; }
            .details-table td:last-child { color: #333; }
            .phone-link { color: #4263be; text-decoration: none; }
            .admin-controls { background: #fff3e0; border: 2px solid #ff9800; border-radius: 8px; padding: 20px; margin-top: 20px; }
            .admin-controls h4 { margin: 0 0 15px; color: #f57c00; }
            .admin-actions { display: flex; gap: 10px; flex-wrap: wrap; }
            .admin-btn { padding: 12px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; text-align: center; }
            .btn-complete { background: #28a745; color: white; }
            .btn-cancel { background: #dc3545; color: white; }
            .masked { filter: blur(3px); user-select: none; }
            @media (max-width: 480px) {
                body { padding: 10px; }
                .flight-container { padding: 0; }
                .flight-options { flex-direction: column; align-items: center; }
                .option-badge { width: 100%; max-width: 200px; }
                .admin-actions { flex-direction: column; }
                .admin-btn { width: 100%; }
            }
            </style>
        </head>
        <body>
            <div class="flight-container">
                <?php if (!$is_logged_in): ?>
                <div class="login-panel">
                    <button class="login-toggle" onclick="toggleLogin()">🔐 Zaloguj się aby zobaczyć pełne dane</button>
                    <form class="login-form" id="login-form" onsubmit="handleLogin(event)">
                        <input type="text" name="username" placeholder="Email lub login" required>
                        <input type="password" name="password" placeholder="Hasło" required>
                        <button type="submit">Zaloguj się</button>
                        <div id="login-message" style="margin-top: 10px; color: #d63638;"></div>
                    </form>
                </div>
                <?php endif; ?>

                <div class="flight-header">
                    <div class="flight-id">
                        Lot #<?php echo $flight['id']; ?> - 
                        <?php 
                        $full_name = trim($flight['imie'] . ' ' . $flight['nazwisko']);
                        echo $is_logged_in ? esc_html($full_name) : $this->maskText($full_name);
                        ?>
                    </div>
                    
                    <?php if ($flight['data']): ?>
                    <div class="flight-date <?php echo (date('Y-m-d') === $flight['data']) ? 'today' : ''; ?>">
                        Data lotu: <strong><?php echo $this->helpers->formatujDate($flight['data'], 'd.m.Y'); ?>
                        <?php if ($flight['godzina_start']): ?>
                            o <?php echo substr($flight['godzina_start'], 0, 5); ?>
                        <?php endif; ?></strong>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="flight-options">
                    <div class="option-badge <?php echo $flight['ma_filmowanie'] ? 'option-yes' : 'option-no'; ?>">
                        📹 <?php echo $flight['ma_filmowanie'] ? 'Z filmowaniem' : 'Bez filmowania'; ?>
                    </div>
                    <div class="option-badge <?php echo $flight['ma_akrobacje'] ? 'option-yes' : 'option-no'; ?>">
                        🌪️ <?php echo $flight['ma_akrobacje'] ? 'Z akrobacjami' : 'Bez akrobacji'; ?>
                    </div>
                </div>

                <div class="status-badge <?php echo 'status-' . $flight['status']; ?>">
                    <?php echo $this->getStatusIcon($flight['status']) . ' ' . $this->getStatusText($flight['status']); ?>
                </div>

                <div class="details-section">
                    <div class="details-title">Szczegóły lotu</div>
                    <table class="details-table">
                        <tr>
                            <td>Imię i nazwisko:</td>
                            <td class="<?php echo !$is_logged_in ? 'masked' : ''; ?>">
                                <?php echo $is_logged_in ? esc_html($full_name) : $this->maskText($full_name); ?>
                            </td>
                        </tr>
                        <?php if (isset($flight['rok_urodzenia']) && $flight['rok_urodzenia']): ?>
                        <tr>
                            <td>Wiek:</td>
                            <td class="<?php echo !$is_logged_in ? 'masked' : ''; ?>">
                                <?php 
                                if ($is_logged_in) {
                                    echo date('Y') - $flight['rok_urodzenia'] . ' lat';
                                } else {
                                    echo $this->maskText((date('Y') - $flight['rok_urodzenia']) . ' lat');
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($flight['telefon']) && $flight['telefon']): ?>
                        <tr>
                            <td>Telefon:</td>
                            <td class="<?php echo !$is_logged_in ? 'masked' : ''; ?>">
                                <?php 
                                if ($is_logged_in) {
                                    echo '<a href="tel:' . esc_attr($flight['telefon']) . '" class="phone-link">' . esc_html($flight['telefon']) . '</a>';
                                } else {
                                    echo $this->maskText($flight['telefon']);
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($flight['user_email']): ?>
                        <tr>
                            <td>Email:</td>
                            <td class="<?php echo !$is_logged_in ? 'masked' : ''; ?>">
                                <?php echo $is_logged_in ? $flight['user_email'] : $this->maskEmail($flight['user_email']); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($flight['sprawnosc_fizyczna']) && $flight['sprawnosc_fizyczna']): ?>
                        <tr>
                            <td>Sprawność fizyczna:</td>
                            <td class="<?php echo !$is_logged_in ? 'masked' : ''; ?>">
                                <?php 
                                $sprawnosc_map = [
                                    'zdolnosc_do_marszu' => 'Zdolność do marszu',
                                    'zdolnosc_do_biegu' => 'Zdolność do biegu',
                                    'sprinter' => 'Sprinter!'
                                ];
                                $sprawnosc_text = $sprawnosc_map[$flight['sprawnosc_fizyczna']] ?? $flight['sprawnosc_fizyczna'];
                                echo $is_logged_in ? $sprawnosc_text : $this->maskText($sprawnosc_text);
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($flight['kategoria_wagowa']) && $flight['kategoria_wagowa']): ?>
                        <tr>
                            <td>Kategoria wagowa:</td>
                            <td class="<?php echo !$is_logged_in ? 'masked' : ''; ?>">
                                <?php echo $is_logged_in ? $flight['kategoria_wagowa'] : $this->maskText($flight['kategoria_wagowa']); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($flight['uwagi']) && $flight['uwagi']): ?>
                        <tr>
                            <td>Uwagi:</td>
                            <td class="<?php echo !$is_logged_in ? 'masked' : ''; ?>">
                                <?php echo $is_logged_in ? esc_html($flight['uwagi']) : $this->maskText($flight['uwagi']); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>

                <?php if ($is_admin && $flight['status'] === 'zarezerwowany'): ?>
                <div class="admin-controls">
                    <h4>⚙️ Panel administratora</h4>
                    <div class="admin-actions">
                        <button class="admin-btn btn-complete" onclick="changeFlightStatus(<?php echo $flight['id']; ?>, 'zrealizowany')">
                            ✅ Oznacz jako zrealizowany
                        </button>
                        <button class="admin-btn btn-cancel" onclick="cancelFlight(<?php echo $flight['id']; ?>, <?php echo $flight['termin_id'] ?: 'null'; ?>)">
                            ❌ Odwołaj lot
                        </button>
                    </div>
                    <div id="admin-message" style="margin-top: 15px; padding: 10px; border-radius: 4px; display: none;"></div>
                </div>
                <?php endif; ?>
            </div>

            <script>
            function toggleLogin() {
                const form = document.querySelector('.login-form');
                form.style.display = form.style.display === 'block' ? 'none' : 'block';
            }

            function handleLogin(event) {
                event.preventDefault();
                const form = event.target;
                const formData = new FormData(form);
                const messageEl = document.getElementById('login-message');
                
                messageEl.textContent = 'Logowanie...';
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'srl_ajax_login',
                        username: formData.get('username'),
                        password: formData.get('password'),
                        nonce: '<?php echo wp_create_nonce('srl_frontend_nonce'); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        messageEl.textContent = data.data || 'Błąd logowania';
                    }
                });
            }

            <?php if ($is_admin): ?>
            function changeFlightStatus(flightId, newStatus) {
                showAdminMessage('Zmienianie statusu...', 'info');
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'srl_change_flight_status_public',
                        flight_id: flightId,
                        new_status: newStatus,
                        nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAdminMessage('Status został zmieniony!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAdminMessage(data.data || 'Błąd zmiany statusu', 'error');
                    }
                });
            }

            function cancelFlight(flightId, terminId) {
                if (!terminId) {
                    showAdminMessage('Lot nie ma przypisanego terminu do odwołania.', 'error');
                    return;
                }
                
                if (!confirm('Czy na pewno odwołać ten lot? Slot zostanie zachowany jako historia, a lot będzie dostępny do ponownej rezerwacji.')) {
                    return;
                }
                
                showAdminMessage('Odwoływanie lotu...', 'info');
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'srl_cancel_flight_admin',
                        termin_id: terminId,
                        nonce: '<?php echo wp_create_nonce('srl_admin_nonce'); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAdminMessage('Lot został odwołany. Klient otrzyma powiadomienie.', 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showAdminMessage(data.data || 'Błąd odwoływania lotu', 'error');
                    }
                });
            }

            function showAdminMessage(message, type) {
                const messageEl = document.getElementById('admin-message');
                messageEl.style.display = 'block';
                messageEl.textContent = message;
                
                messageEl.className = '';
                if (type === 'success') {
                    messageEl.style.background = '#d4edda';
                    messageEl.style.color = '#155724';
                    messageEl.style.border = '1px solid #c3e6cb';
                } else if (type === 'error') {
                    messageEl.style.background = '#f8d7da';
                    messageEl.style.color = '#721c24';
                    messageEl.style.border = '1px solid #f5c6cb';
                } else {
                    messageEl.style.background = '#cce5ff';
                    messageEl.style.color = '#004085';
                    messageEl.style.border = '1px solid #b8daff';
                }
            }
            <?php endif; ?>
            </script>
        </body>
        </html>
        <?php
    }

    private function getStatusIcon($status) {
        $icons = [
            'wolny' => '🟢',
            'zarezerwowany' => '🟡',
            'zrealizowany' => '🔵',
            'przedawniony' => '🔴'
        ];
        return $icons[$status] ?? '⚪';
    }

    private function getStatusText($status) {
        $texts = [
            'wolny' => 'Dostępny do rezerwacji',
            'zarezerwowany' => 'Zarezerwowany',
            'zrealizowany' => 'Zrealizowany',
            'przedawniony' => 'Przeterminowany'
        ];
        return $texts[$status] ?? ucfirst($status);
    }

    private function maskText($text) {
        if (strlen($text) <= 3) return str_repeat('*', strlen($text));
        return substr($text, 0, 2) . str_repeat('*', strlen($text) - 3) . substr($text, -1);
    }

    private function maskEmail($email) {
        if (strpos($email, '@') === false) return $this->maskText($email);
        list($local, $domain) = explode('@', $email, 2);
        return $this->maskText($local) . '@' . $domain;
    }

    public function generateSecurityCode($flight_id, $date_created) {
        $salt = wp_salt('nonce') . $flight_id . $date_created;
        return substr(hash('sha256', $salt), 0, 16);
    }

    public function generateFlightViewUrl($flight_id, $date_created) {
        $security_code = $this->generateSecurityCode($flight_id, $date_created);
        return home_url("/lot/{$flight_id}_{$security_code}");
    }

    public function ajaxChangeFlightStatus() {
        SRL_Helpers::getInstance()->checkAdminPermissions();
        check_ajax_referer('srl_admin_nonce', 'nonce');

        $flight_id = intval($_POST['flight_id']);
        $new_status = sanitize_text_field($_POST['new_status']);

        if (!in_array($new_status, ['wolny', 'zarezerwowany', 'zrealizowany'])) {
            wp_send_json_error('Nieprawidłowy status.');
        }

        global $wpdb;
        
        // Pobierz dane lotu
        $flight = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE id = %d", $flight_id
        ), ARRAY_A);
        
        if (!$flight) {
            wp_send_json_error('Lot nie istnieje.');
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            ['status' => $new_status],
            ['id' => $flight_id],
            ['%s'], ['%d']
        );

        if ($result !== false) {
            SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($flight_id, [
                'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
                'typ' => 'zmiana_statusu_public_admin',
                'executor' => 'Admin (public view)',
                'szczegoly' => [
                    'nowy_status' => $new_status,
                    'user_id' => get_current_user_id()
                ]
            ]);

            wp_send_json_success('Status został zmieniony.');
        } else {
            wp_send_json_error('Błąd podczas zmiany statusu.');
        }
    }

    public function ajaxCancelFlight() {
        SRL_Helpers::getInstance()->checkAdminPermissions();
        check_ajax_referer('srl_admin_nonce', 'nonce');

        $termin_id = intval($_POST['termin_id']);
        
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_terminy WHERE id = %d", $termin_id
            ), ARRAY_A);

            if (!$slot) {
                throw new Exception('Slot nie istnieje.');
            }

            $historical_data = $this->prepareHistoricalData($slot);
            
            $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                ['status' => 'Odwołany przez organizatora', 'notatka' => json_encode($historical_data)],
                ['id' => $termin_id]
            );

            if ($slot['klient_id'] > 0) {
                $this->handleCancellationNotification($slot, $historical_data);
            }

            $wpdb->query('COMMIT');
            wp_send_json_success(['message' => 'Lot został odwołany.']);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error($e->getMessage());
        }
    }

    private function prepareHistoricalData($slot) {
        $data = [
            'typ' => 'odwolany_przez_organizatora',
            'data_odwolania' => SRL_Helpers::getInstance()->getCurrentDatetime(),
            'oryginalny_status' => $slot['status'],
            'klient_id' => $slot['klient_id']
        ];

        if ($slot['klient_id'] > 0) {
            $user_data = SRL_Cache_Manager::getInstance()->getUserData($slot['klient_id']);
            if ($user_data) {
                $data = array_merge($data, [
                    'klient_email' => $user_data['email'],
                    'klient_nazwa' => $user_data['display_name'],
                    'telefon' => $user_data['telefon']
                ]);
            }

            global $wpdb;
            $lot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}srl_zakupione_loty WHERE termin_id = %d", $slot['id']
            ), ARRAY_A);

            if ($lot) {
                $data['lot_id'] = $lot['id'];
                $data['order_id'] = $lot['order_id'];
            }
        }

        return $data;
    }

    private function handleCancellationNotification($slot, $historical_data) {
        if (!isset($historical_data['lot_id']) || !isset($historical_data['klient_email'])) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'srl_zakupione_loty',
            ['status' => 'wolny', 'termin_id' => null, 'data_rezerwacji' => null],
            ['id' => $historical_data['lot_id']]
        );

        SRL_Email_Functions::getInstance()->wyslijEmailOdwolaniaPrzezOrganizatora(
            $historical_data['klient_email'],
            $slot['data'],
            $slot['godzina_start']
        );

        SRL_Historia_Functions::getInstance()->dopiszDoHistoriiLotu($historical_data['lot_id'], [
            'data' => SRL_Helpers::getInstance()->getCurrentDatetime(),
            'typ' => 'odwolanie_przez_organizatora',
            'executor' => 'Admin (public view)',
            'szczegoly' => [
                'termin_id' => $slot['id'],
                'odwolany_termin' => sprintf('%s %s-%s',
                    $slot['data'],
                    substr($slot['godzina_start'], 0, 5),
                    substr($slot['godzina_koniec'], 0, 5)
                ),
                'email_wyslany' => true
            ]
        ]);
    }
}