<?php if (!defined('ABSPATH')) {exit;}

class SRL_Helper {
    private static $config = array(
        'flight_products' => array(62, 63, 65, 67, 69, 73, 74, 75, 76, 77),
        'option_products' => array('przedluzenie' => 115, 'filmowanie' => 116, 'akrobacje' => 117),
        'voucher_products' => array(105, 106, 107, 108)
    );
    
    private static $validation_rules = array(
        'imie' => array('required' => true, 'min' => 2, 'max' => 50, 'pattern' => '/^[a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ\s\-]+$/u'),
        'nazwisko' => array('required' => true, 'min' => 2, 'max' => 50, 'pattern' => '/^[a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ\s\-]+$/u'),
        'telefon' => array('required' => true, 'min' => 9, 'max' => 11, 'pattern' => '/^[0-9\+\s\-\(\)]+$/'),
        'voucher_code' => array('required' => true, 'min' => 3, 'max' => 20, 'pattern' => '/^[A-Z0-9]+$/'),
        'sprawnosc_fizyczna' => array('required' => true, 'options' => array('zdolnosc_do_marszu', 'zdolnosc_do_biegu', 'sprinter')),
        'kategoria_wagowa' => array('required' => true, 'options' => array('25-40kg', '41-60kg', '61-90kg', '91-120kg', '120kg+'))
    );
    
    public static function get_config($key) {
        return isset(self::$config[$key]) ? self::$config[$key] : null;
    }
    
    public static function format_date($date, $format = 'd.m.Y') {
        if (empty($date)) return '';
        $timestamp = is_string($date) ? strtotime($date) : (is_numeric($date) ? $date : null);
        return $timestamp ? date($format, $timestamp) : $date;
    }
    
    public static function format_time($time, $format = 'H:i') {
        if (empty($time)) return '';
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) return substr($time, 0, 5);
        $timestamp = strtotime($time);
        return $timestamp ? date($format, $timestamp) : $time;
    }
    
    public static function format_polish_datetime($date, $start_time) {
        if (empty($date) || empty($start_time)) return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return $date;

        $day_names = array('Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota');
        $month_names = array('stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 
                           'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia');

        return sprintf(
            '<div class="srl-termin-data">%d %s %d, godz: %s</div><div class="srl-termin-dzien">%s</div>',
            date('j', $timestamp), $month_names[date('n', $timestamp) - 1], date('Y', $timestamp),
            substr($start_time, 0, 5), $day_names[date('w', $timestamp)]
        );
    }
    
    public static function format_expiry($date) {
        if (empty($date)) return 'Brak daty ważności';
        $timestamp = strtotime($date);
        if ($timestamp === false) return $date;

        $today = strtotime('today');
        $diff_days = floor(($timestamp - $today) / (24 * 60 * 60));
        $formatted = date('d.m.Y', $timestamp);

        if ($diff_days < 0) return $formatted . ' (przeterminowany)';
        if ($diff_days == 0) return $formatted . ' (wygasa dziś)';
        if ($diff_days <= 30) return $formatted . " (zostało {$diff_days} dni)";
        return $formatted;
    }
    
    public static function validate($data, $rules = null) {
        $rules = $rules ?: self::$validation_rules;
        $errors = array();

        foreach ($rules as $field => $rule) {
            $value = isset($data[$field]) ? trim($data[$field]) : '';
            
            if ($rule['required'] && empty($value)) {
                $errors[$field] = ucfirst($field) . ' jest wymagane.';
                continue;
            }
            
            if (!empty($value)) {
                if (isset($rule['min']) && strlen($value) < $rule['min']) {
                    $errors[$field] = ucfirst($field) . ' musi mieć co najmniej ' . $rule['min'] . ' znaków.';
                }
                if (isset($rule['max']) && strlen($value) > $rule['max']) {
                    $errors[$field] = ucfirst($field) . ' nie może być dłuższe niż ' . $rule['max'] . ' znaków.';
                }
                if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                    $errors[$field] = ucfirst($field) . ' zawiera nieprawidłowe znaki.';
                }
                if (isset($rule['options']) && !in_array($value, $rule['options'])) {
                    $errors[$field] = 'Nieprawidłowa wartość dla ' . $field . '.';
                }
            }
        }
        
        if ($field === 'telefon' && !empty($value)) {
            $clean = preg_replace('/[^0-9]/', '', $value);
            if (strlen($clean) < 9 || strlen($clean) > 11) {
                $errors['telefon'] = 'Numer telefonu musi mieć 9-11 cyfr.';
            }
        }

        return array('valid' => empty($errors), 'errors' => $errors);
    }
    
    public static function validate_age($birth_year) {
        $warnings = array();
        if (!$birth_year) return array('valid' => true, 'age' => null, 'warnings' => array());
        
        $age = date('Y') - intval($birth_year);
        if ($age <= 18) {
            $warnings[] = array(
                'type' => 'warning',
                'text' => 'Osoby niepełnoletnie wymagają zgody rodzica/opiekuna.',
                'link' => '/zgoda-na-lot-osoba-nieletnia/',
                'link_text' => 'Pobierz zgodę tutaj'
            );
        }
        
        return array('valid' => true, 'age' => $age, 'warnings' => $warnings);
    }
    
    public static function validate_weight($category) {
        $warnings = $errors = array();
        if (!$category) return array('valid' => true, 'warnings' => array(), 'errors' => array());
        
        if ($category === '91-120kg') {
            $warnings[] = array('type' => 'warning', 'text' => 'Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji.');
        } elseif ($category === '120kg+') {
            $errors[] = array('type' => 'error', 'text' => 'Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.');
        }
        
        return array('valid' => empty($errors), 'warnings' => $warnings, 'errors' => $errors);
    }
    
    public static function generate_html_messages($warnings, $errors) {
        $html = '';
        foreach (array_merge($warnings, $errors) as $msg) {
            $class = $msg['type'] === 'error' ? 'srl-uwaga-error' : 'srl-uwaga-warning';
            $bg = $msg['type'] === 'error' ? '#fdeaea' : '#fff3e0';
            $border = $msg['type'] === 'error' ? '#d63638' : '#ff9800';
            $icon = $msg['type'] === 'error' ? '❌ Błąd:' : 'Uwaga:';
            
            $html .= "<div class=\"{$class}\" style=\"background:{$bg}; border:2px solid {$border}; border-radius:8px; padding:20px; margin-top:10px;\">";
            $html .= "<strong>{$icon}</strong> {$msg['text']}";
            if (isset($msg['link'], $msg['link_text'])) {
                $html .= " <a href=\"{$msg['link']}\" target=\"_blank\" style=\"color:#f57c00; font-weight:bold;\">{$msg['link_text']}</a>";
            }
            $html .= '</div>';
        }
        return $html;
    }
    
    public static function generate_status_badge($status, $type = 'lot') {
        $configs = array(
            'lot' => array(
                'wolny' => '🟢 Dostępny do rezerwacji|status-available',
                'zarezerwowany' => '🟡 Zarezerwowany|status-reserved',
                'zrealizowany' => '🔵 Zrealizowany|status-completed',
                'przedawniony' => '🔴 Przeterminowany|status-expired'
            ),
            'slot' => array(
                'Wolny' => '🟢 Wolny|status-available',
                'Prywatny' => '🟤 Prywatny|status-private',
                'Zarezerwowany' => '🟡 Zarezerwowany|status-reserved',
                'Zrealizowany' => '🔵 Zrealizowany|status-completed',
                'Odwołany przez organizatora' => '🔴 Odwołany|status-cancelled'
            )
        );

        $config = isset($configs[$type][$status]) ? $configs[$type][$status] : '⚪ ' . ucfirst($status) . '|status-unknown';
        list($label, $class) = explode('|', $config);
        
        return "<span class=\"{$class}\" style=\"display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;\">{$label}</span>";
    }
    
    public static function format_flight_options($has_filming, $has_acrobatics) {
        $options = array(
            $has_filming ? '<span style="color: #46b450; font-weight: bold;">z filmowaniem</span>' : '<span style="color: #d63638;">bez filmowania</span>',
            $has_acrobatics ? '<span style="color: #46b450; font-weight: bold;">z akrobacjami</span>' : '<span style="color: #d63638;">bez akrobacji</span>'
        );
        return implode(', ', $options);
    }
    
    public static function generate_ui_element($type, $content, $class = '', $attrs = array()) {
        $attr_str = '';
        foreach ($attrs as $key => $value) {
            $attr_str .= " {$key}=\"" . esc_attr($value) . "\"";
        }
        $class_str = $class ? " class=\"" . esc_attr($class) . "\"" : '';
        
        switch ($type) {
            case 'message':
                $types = array('info' => 'srl-komunikat-info', 'success' => 'srl-komunikat-success', 
                              'warning' => 'srl-komunikat-warning', 'error' => 'srl-komunikat-error');
                $msg_class = isset($types[$attrs['type']]) ? $types[$attrs['type']] : $types['info'];
                return "<div class=\"srl-komunikat {$msg_class}\">{$content}</div>";
                
            case 'select':
                $html = "<select name=\"" . esc_attr($attrs['name']) . "\"{$class_str}{$attr_str}>";
                foreach ($attrs['options'] as $value => $label) {
                    $selected = isset($attrs['selected']) && $attrs['selected'] == $value ? ' selected' : '';
                    $html .= "<option value=\"" . esc_attr($value) . "\"{$selected}>" . esc_html($label) . "</option>";
                }
                return $html . '</select>';
                
            case 'link':
                return "<a href=\"" . esc_url($attrs['url']) . "\"{$class_str}{$attr_str}>{$content}</a>";
                
            case 'button':
                return "<button{$class_str}{$attr_str}>{$content}</button>";
                
            default:
                return $content;
        }
    }
    
    public static function detect_flight_options($text) {
        $lower = strtolower($text);
        return array(
            'ma_filmowanie' => (strpos($lower, 'filmowani') !== false || strpos($lower, 'film') !== false ||
                               strpos($lower, 'video') !== false || strpos($lower, 'kamer') !== false) ? 1 : 0,
            'ma_akrobacje' => (strpos($lower, 'akrobacj') !== false || strpos($lower, 'trick') !== false ||
                              strpos($lower, 'spiral') !== false || strpos($lower, 'figur') !== false) ? 1 : 0
        );
    }
    
    public static function can_cancel_reservation($flight_date, $flight_time) {
        if (empty($flight_date) || empty($flight_time)) return false;
        $flight_timestamp = strtotime($flight_date . ' ' . $flight_time);
        return $flight_timestamp !== false && ($flight_timestamp - time()) > (48 * 3600);
    }
    
    public static function generate_expiry_date($from_date = null, $years = 1) {
        $base = $from_date ?: current_time('mysql');
        return date('Y-m-d', strtotime($base . " +{$years} year"));
    }
    
    public static function is_date_past($date) {
        return strtotime($date) < strtotime('today');
    }
    
    public static function time_to_minutes($time) {
        list($h, $m) = explode(':', $time);
        return intval($h) * 60 + intval($m);
    }
    
    public static function minutes_to_time($minutes) {
        return sprintf('%02d:%02d', floor($minutes / 60), $minutes % 60);
    }
}

function srl_get_flight_option_product_ids() {
    return SRL_Helper::get_config('option_products');
}

function srl_get_flight_product_ids() {
    return SRL_Helper::get_config('flight_products');
}

function srl_check_admin_permissions() {
    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
}

function srl_require_login($return_data = false) {
    if (!is_user_logged_in()) {
        if ($return_data) return array('success' => false, 'data' => 'Musisz być zalogowany.');
        wp_send_json_error('Musisz być zalogowany.');
    }
    return true;
}

function srl_get_current_datetime() {
    return current_time('Y-m-d H:i:s');
}

function srl_formatuj_date($date, $format = 'd.m.Y') {
    return SRL_Helper::format_date($date, $format);
}

function srl_formatuj_czas($time, $format = 'H:i') {
    return SRL_Helper::format_time($time, $format);
}

function srl_formatuj_date_i_czas_polski($date, $start_time) {
    return SRL_Helper::format_polish_datetime($date, $start_time);
}

function srl_formatuj_waznosc_lotu($date) {
    return SRL_Helper::format_expiry($date);
}

function srl_waliduj_dane_pasazera($data) {
    $validation = SRL_Helper::validate($data);
    if (!isset($data['akceptacja_regulaminu']) || $data['akceptacja_regulaminu'] !== true) {
        $validation['errors']['akceptacja_regulaminu'] = 'Musisz zaakceptować regulamin.';
        $validation['valid'] = false;
    }
    return $validation;
}

function srl_waliduj_wiek($birth_year, $format = 'html') {
    $result = SRL_Helper::validate_age($birth_year);
    if ($format === 'html') {
        $result['html'] = SRL_Helper::generate_html_messages($result['warnings'], array());
    }
    return $result;
}

function srl_waliduj_kategorie_wagowa($category, $format = 'html') {
    $result = SRL_Helper::validate_weight($category);
    if ($format === 'html') {
        $result['html'] = SRL_Helper::generate_html_messages($result['warnings'], $result['errors']);
    }
    return $result;
}

function srl_generate_status_badge($status, $type = 'lot') {
    return SRL_Helper::generate_status_badge($status, $type);
}

function srl_format_flight_options_html($has_filming, $has_acrobatics) {
    return SRL_Helper::format_flight_options($has_filming, $has_acrobatics);
}

function srl_generate_message($text, $type = 'info', $dismissible = false) {
    $dismiss_btn = $dismissible ? '<button type="button" class="srl-dismiss">×</button>' : '';
    return SRL_Helper::generate_ui_element('message', $text . $dismiss_btn, '', array('type' => $type));
}

function srl_generate_select($name, $options, $selected = '', $attrs = array()) {
    $attrs['name'] = $name;
    $attrs['options'] = $options;
    if ($selected !== '') $attrs['selected'] = $selected;
    return SRL_Helper::generate_ui_element('select', '', '', $attrs);
}

function srl_generate_link($url, $text, $class = '', $attrs = array()) {
    $attrs['url'] = $url;
    return SRL_Helper::generate_ui_element('link', $text, $class, $attrs);
}

function srl_generate_button($text, $class = 'srl-btn-primary', $attrs = array()) {
    return SRL_Helper::generate_ui_element('button', $text, "srl-btn {$class}", $attrs);
}

function srl_can_cancel_reservation($date, $time) {
    return SRL_Helper::can_cancel_reservation($date, $time);
}

function srl_detect_flight_options($text) {
    return SRL_Helper::detect_flight_options($text);
}

function srl_generate_expiry_date($from_date = null, $years = 1) {
    return SRL_Helper::generate_expiry_date($from_date, $years);
}

function srl_is_date_past($date) {
    return SRL_Helper::is_date_past($date);
}

function srl_zamien_na_minuty($time) {
    return SRL_Helper::time_to_minutes($time);
}

function srl_minuty_na_czas($minutes) {
    return SRL_Helper::minutes_to_time($minutes);
}