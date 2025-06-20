<?php
/**
 * Centralna klasa formatowania danych
 * Zastępuje rozproszone funkcje formatujące
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Formatter {
    
    private static $status_configs = array(
        'lot' => array(
            'wolny' => array(
                'label' => '🟢 Dostępny do rezerwacji',
                'class' => 'status-available',
                'color' => '#28a745'
            ),
            'zarezerwowany' => array(
                'label' => '🟡 Zarezerwowany', 
                'class' => 'status-reserved',
                'color' => '#ffc107'
            ),
            'zrealizowany' => array(
                'label' => '🔵 Zrealizowany',
                'class' => 'status-completed', 
                'color' => '#007bff'
            ),
            'przedawniony' => array(
                'label' => '🔴 Przeterminowany',
                'class' => 'status-expired',
                'color' => '#dc3545'
            )
        ),
        'slot' => array(
            'Wolny' => array(
                'label' => '🟢 Wolny',
                'class' => 'status-available',
                'color' => '#28a745'
            ),
            'Prywatny' => array(
                'label' => '🟤 Prywatny',
                'class' => 'status-private',
                'color' => '#6f4e37'
            ),
            'Zarezerwowany' => array(
                'label' => '🟡 Zarezerwowany',
                'class' => 'status-reserved', 
                'color' => '#ffc107'
            ),
            'Zrealizowany' => array(
                'label' => '🔵 Zrealizowany',
                'class' => 'status-completed',
                'color' => '#007bff'
            ),
            'Odwołany przez organizatora' => array(
                'label' => '🔴 Odwołany',
                'class' => 'status-cancelled',
                'color' => '#dc3545'
            )
        ),
        'voucher' => array(
            'do_wykorzystania' => array(
                'label' => '🟢 Do wykorzystania',
                'class' => 'status-available',
                'color' => '#28a745'
            ),
            'wykorzystany' => array(
                'label' => '🔵 Wykorzystany',
                'class' => 'status-completed',
                'color' => '#007bff'
            ),
            'przeterminowany' => array(
                'label' => '🔴 Przeterminowany',
                'class' => 'status-expired',
                'color' => '#dc3545'
            )
        ),
        'partner_voucher' => array(
            'oczekuje' => array(
                'label' => 'OCZEKUJE',
                'class' => 'status-pending',
                'color' => '#f39c12'
            ),
            'zatwierdzony' => array(
                'label' => 'ZATWIERDZONY', 
                'class' => 'status-approved',
                'color' => '#27ae60'
            ),
            'odrzucony' => array(
                'label' => 'ODRZUCONY',
                'class' => 'status-rejected',
                'color' => '#e74c3c'
            )
        )
    );

    private static $date_formats = array(
        'default' => 'd.m.Y',
        'long' => 'd.m.Y H:i',
        'short' => 'd.m',
        'mysql' => 'Y-m-d',
        'mysql_datetime' => 'Y-m-d H:i:s'
    );

    private static $polish_days = array(
        'Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 
        'Czwartek', 'Piątek', 'Sobota'
    );

    private static $polish_months = array(
        'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
        'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'
    );

    /**
     * Formatuje datę
     */
    public static function format_date($date, $format = 'default') {
        if (empty($date)) {
            return '';
        }

        $timestamp = is_string($date) ? strtotime($date) : (is_numeric($date) ? $date : null);
        
        if ($timestamp === false || $timestamp === null) {
            return $date; // Zwróć oryginalną wartość jeśli nie można sparsować
        }

        $format_string = self::$date_formats[$format] ?? $format;
        return date($format_string, $timestamp);
    }

    /**
     * Formatuje czas
     */
    public static function format_time($time, $format = 'H:i') {
        if (empty($time)) {
            return '';
        }

        // Jeśli już jest w formacie HH:MM lub HH:MM:SS
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            return substr($time, 0, 5);
        }

        $timestamp = strtotime($time);
        return $timestamp !== false ? date($format, $timestamp) : $time;
    }

    /**
     * Formatuje datę i czas po polsku
     */
    public static function format_polish_datetime($date, $start_time = null) {
        if (empty($date)) {
            return '';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        $day_name = self::$polish_days[date('w', $timestamp)];
        $month_name = self::$polish_months[date('n', $timestamp) - 1];
        
        $formatted = sprintf(
            '%d %s %d',
            date('j', $timestamp),
            $month_name,
            date('Y', $timestamp)
        );

        if ($start_time) {
            $time_formatted = self::format_time($start_time);
            $formatted .= ', godz: ' . $time_formatted;
        }

        return sprintf(
            '<div class="srl-termin-data">%s</div><div class="srl-termin-dzien">%s</div>',
            $formatted,
            $day_name
        );
    }

    /**
     * Formatuje ważność lotu z dodatkowymi informacjami
     */
    public static function format_expiry($date) {
        if (empty($date)) {
            return 'Brak daty ważności';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        $today = strtotime('today');
        $diff_days = floor(($timestamp - $today) / (24 * 60 * 60));
        $formatted = self::format_date($date);

        if ($diff_days < 0) {
            return $formatted . ' <span style="color: #dc3545; font-weight: bold;">(przeterminowany)</span>';
        }
        
        if ($diff_days == 0) {
            return $formatted . ' <span style="color: #ff6b35; font-weight: bold;">(wygasa dziś)</span>';
        }
        
        if ($diff_days <= 7) {
            return $formatted . " <span style=\"color: #ff6b35; font-weight: bold;\">(zostało {$diff_days} dni)</span>";
        }
        
        if ($diff_days <= 30) {
            return $formatted . " <span style=\"color: #ffc107;\">(zostało {$diff_days} dni)</span>";
        }

        return $formatted;
    }

    /**
     * Generuje badge statusu
     */
    public static function generate_status_badge($status, $type = 'lot', $inline_styles = true) {
        $config = self::$status_configs[$type][$status] ?? array(
            'label' => ucfirst($status),
            'class' => 'status-unknown',
            'color' => '#6c757d'
        );

        $style_attr = '';
        if ($inline_styles) {
            $style_attr = sprintf(
                ' style="background-color: %s; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; display: inline-block;"',
                $config['color']
            );
        }

        return sprintf(
            '<span class="%s"%s>%s</span>',
            esc_attr($config['class']),
            $style_attr,
            esc_html($config['label'])
        );
    }

    /**
     * Formatuje opcje lotu (filmowanie, akrobacje)
     */
    public static function format_flight_options($has_filming, $has_acrobatics, $format = 'html') {
        $options = array();

        if ($format === 'html') {
            $options[] = $has_filming 
                ? '<span style="color: #46b450; font-weight: bold;">📹 z filmowaniem</span>'
                : '<span style="color: #d63638;">bez filmowania</span>';
                
            $options[] = $has_acrobatics 
                ? '<span style="color: #46b450; font-weight: bold;">🌪️ z akrobacjami</span>'
                : '<span style="color: #d63638;">bez akrobacji</span>';
                
            return implode(', ', $options);
        }

        // Format tekstowy
        if ($has_filming) $options[] = 'z filmowaniem';
        if ($has_acrobatics) $options[] = 'z akrobacjami';
        
        return !empty($options) ? implode(', ', $options) : 'standardowy lot';
    }

    /**
     * Generuje komunikat/wiadomość
     */
    public static function generate_message($text, $type = 'info', $dismissible = false) {
        $types = array(
            'info' => array('class' => 'srl-komunikat-info', 'color' => '#0073aa'),
            'success' => array('class' => 'srl-komunikat-success', 'color' => '#46b450'),
            'warning' => array('class' => 'srl-komunikat-warning', 'color' => '#ff9800'),
            'error' => array('class' => 'srl-komunikat-error', 'color' => '#d63638')
        );

        $config = $types[$type] ?? $types['info'];
        $dismiss_btn = $dismissible ? '<button type="button" class="srl-dismiss">×</button>' : '';

        return sprintf(
            '<div class="srl-komunikat %s">%s%s</div>',
            esc_attr($config['class']),
            $text,
            $dismiss_btn
        );
    }

    /**
     * Generuje select HTML
     */
    public static function generate_select($name, $options, $selected = '', $attrs = array()) {
        $attrs_str = '';
        foreach ($attrs as $key => $value) {
            $attrs_str .= sprintf(' %s="%s"', esc_attr($key), esc_attr($value));
        }

        $class = isset($attrs['class']) ? $attrs['class'] : '';
        
        $html = sprintf('<select name="%s"%s>', esc_attr($name), $attrs_str);
        
        foreach ($options as $value => $label) {
            $selected_attr = ($selected == $value) ? ' selected' : '';
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                $selected_attr,
                esc_html($label)
            );
        }
        
        $html .= '</select>';
        
        return $html;
    }

    /**
     * Generuje link HTML
     */
    public static function generate_link($url, $text, $class = '', $attrs = array()) {
        $attrs['href'] = $url;
        if ($class) {
            $attrs['class'] = $class;
        }

        $attrs_str = '';
        foreach ($attrs as $key => $value) {
            $attrs_str .= sprintf(' %s="%s"', esc_attr($key), esc_attr($value));
        }

        return sprintf('<a%s>%s</a>', $attrs_str, esc_html($text));
    }

    /**
     * Generuje przycisk HTML
     */
    public static function generate_button($text, $class = 'srl-btn-primary', $attrs = array()) {
        if (!isset($attrs['type'])) {
            $attrs['type'] = 'button';
        }
        
        $attrs['class'] = 'srl-btn ' . $class;

        $attrs_str = '';
        foreach ($attrs as $key => $value) {
            $attrs_str .= sprintf(' %s="%s"', esc_attr($key), esc_attr($value));
        }

        return sprintf('<button%s>%s</button>', $attrs_str, esc_html($text));
    }

    /**
     * Formatuje kwotę (jeśli będzie potrzebne)
     */
    public static function format_price($amount, $currency = 'PLN') {
        if (!is_numeric($amount)) {
            return $amount;
        }

        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }

    /**
     * Formatuje numer telefonu
     */
    public static function format_phone($phone) {
        if (empty($phone)) {
            return '';
        }

        // Usuń wszystko oprócz cyfr i +
        $clean = preg_replace('/[^0-9\+]/', '', $phone);
        
        // Jeśli zaczyna się od +48, sformatuj polski numer
        if (preg_match('/^\+48(\d{9})$/', $clean, $matches)) {
            $number = $matches[1];
            return sprintf('+48 %s %s %s', 
                substr($number, 0, 3),
                substr($number, 3, 3), 
                substr($number, 6, 3)
            );
        }
        
        // Jeśli to 9-cyfrowy polski numer bez +48
        if (preg_match('/^(\d{9})$/', $clean, $matches)) {
            $number = $matches[1];
            return sprintf('%s %s %s',
                substr($number, 0, 3),
                substr($number, 3, 3),
                substr($number, 6, 3)
            );
        }

        return $phone; // Zwróć oryginalny jeśli nie można sformatować
    }

    /**
     * Skraca tekst do określonej długości
     */
    public static function truncate_text($text, $length = 100, $suffix = '...') {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - strlen($suffix)) . $suffix;
    }

    /**
     * Konwertuje minuty na czas HH:MM
     */
    public static function minutes_to_time($minutes) {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * Konwertuje czas HH:MM na minuty
     */
    public static function time_to_minutes($time) {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
            return intval($matches[1]) * 60 + intval($matches[2]);
        }
        return 0;
    }

    /**
     * Sprawdza czy można anulować rezerwację (48h przed lotem)
     */
    public static function can_cancel_reservation($flight_date, $flight_time) {
        if (empty($flight_date) || empty($flight_time)) {
            return false;
        }

        $flight_timestamp = strtotime($flight_date . ' ' . $flight_time);
        if ($flight_timestamp === false) {
            return false;
        }

        return ($flight_timestamp - time()) > (48 * 3600);
    }

    /**
     * Sprawdza czy data jest z przeszłości
     */
    public static function is_date_past($date) {
        return strtotime($date) < strtotime('today');
    }

    /**
     * Generuje datę ważności (np. +1 rok od daty)
     */
    public static function generate_expiry_date($from_date = null, $years = 1) {
        $base = $from_date ?: current_time('mysql');
        return date('Y-m-d', strtotime($base . " +{$years} year"));
    }

    /**
     * Formatuje slot szczegóły dla admina
     */
    public static function format_slot_details($slot_data) {
        if (!$slot_data) {
            return 'Nieznany termin';
        }

        return sprintf('%s %s-%s (Pilot %d)',
            self::format_date($slot_data['data']),
            self::format_time($slot_data['godzina_start']),
            self::format_time($slot_data['godzina_koniec']),
            $slot_data['pilot_id']
        );
    }
}

// Funkcje kompatybilności wstecznej
function srl_formatuj_date($date, $format = 'd.m.Y') {
    return SRL_Formatter::format_date($date, $format);
}

function srl_formatuj_czas($time, $format = 'H:i') {
    return SRL_Formatter::format_time($time, $format);
}

function srl_formatuj_date_i_czas_polski($date, $start_time) {
    return SRL_Formatter::format_polish_datetime($date, $start_time);
}

function srl_formatuj_waznosc_lotu($date) {
    return SRL_Formatter::format_expiry($date);
}

function srl_generate_status_badge($status, $type = 'lot') {
    return SRL_Formatter::generate_status_badge($status, $type);
}

function srl_format_flight_options_html($has_filming, $has_acrobatics) {
    return SRL_Formatter::format_flight_options($has_filming, $has_acrobatics, 'html');
}

function srl_generate_message($text, $type = 'info', $dismissible = false) {
    return SRL_Formatter::generate_message($text, $type, $dismissible);
}

function srl_generate_select($name, $options, $selected = '', $attrs = array()) {
    return SRL_Formatter::generate_select($name, $options, $selected, $attrs);
}

function srl_generate_link($url, $text, $class = '', $attrs = array()) {
    return SRL_Formatter::generate_link($url, $text, $class, $attrs);
}

function srl_generate_button($text, $class = 'srl-btn-primary', $attrs = array()) {
    return SRL_Formatter::generate_button($text, $class, $attrs);
}

function srl_can_cancel_reservation($date, $time) {
    return SRL_Formatter::can_cancel_reservation($date, $time);
}

function srl_is_date_past($date) {
    return SRL_Formatter::is_date_past($date);
}

function srl_generate_expiry_date($from_date = null, $years = 1) {
    return SRL_Formatter::generate_expiry_date($from_date, $years);
}

function srl_zamien_na_minuty($time) {
    return SRL_Formatter::time_to_minutes($time);
}

function srl_minuty_na_czas($minutes) {
    return SRL_Formatter::minutes_to_time($minutes);
}

function srl_format_slot_details($slot_data) {
    return SRL_Formatter::format_slot_details($slot_data);
}