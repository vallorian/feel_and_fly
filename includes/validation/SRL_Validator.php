<?php
/**
 * Centralna klasa walidacji i formatowania danych
 * Zastępuje rozproszone funkcje walidacyjne
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Validator {
    
    private static $rules = array(
        'imie' => array(
            'required' => true,
            'min_length' => 2,
            'max_length' => 50,
            'pattern' => '/^[a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ\s\-\']+$/u',
            'sanitize' => 'trim_ucfirst'
        ),
        'nazwisko' => array(
            'required' => true,
            'min_length' => 2,
            'max_length' => 50,
            'pattern' => '/^[a-zA-ZąćęłńóśźżĄĆĘŁŃÓŚŹŻ\s\-\']+$/u',
            'sanitize' => 'trim_ucfirst'
        ),
        'telefon' => array(
            'required' => true,
            'pattern' => '/^[\+]?[0-9\s\-\(\)]{9,15}$/u',
            'sanitize' => 'normalize_phone'
        ),
        'email' => array(
            'required' => true,
            'pattern' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
            'sanitize' => 'trim_lower'
        ),
        'kod_vouchera' => array(
            'required' => true,
            'min_length' => 3,
            'max_length' => 20,
            'pattern' => '/^[A-Z0-9]+$/',
            'sanitize' => 'trim_upper'
        ),
        'rok_urodzenia' => array(
            'required' => true,
            'type' => 'integer',
            'min_value' => 1920,
            'max_value' => null // Będzie ustawiane dynamicznie
        ),
        'data' => array(
            'required' => true,
            'type' => 'date',
            'format' => 'Y-m-d'
        ),
        'godzina' => array(
            'required' => true,
            'type' => 'time',
            'format' => 'H:i'
        )
    );

    private static $age_warnings = array(
        'minor' => array(
            'max_age' => 18,
            'type' => 'warning',
            'message' => 'Osoby niepełnoletnie wymagają zgody rodzica/opiekuna.',
            'link' => '/zgoda-na-lot-osoba-nieletnia/',
            'link_text' => 'Pobierz zgodę tutaj'
        )
    );

    private static $weight_warnings = array(
        '91-120kg' => array(
            'type' => 'warning',
            'message' => 'Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji.'
        ),
        '120kg+' => array(
            'type' => 'error',
            'message' => 'Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.'
        )
    );

    /**
     * Waliduje pojedyncze pole
     */
    public static function validate_field($field_name, $value, $custom_rules = array()) {
        $rules = array_merge(self::$rules[$field_name] ?? array(), $custom_rules);
        $errors = array();
        
        // Sanityzacja
        $value = self::sanitize_value($value, $rules['sanitize'] ?? 'trim');
        
        // Sprawdzenie wymaganego pola
        if (isset($rules['required']) && $rules['required'] && empty($value)) {
            $errors[] = self::get_field_label($field_name) . ' jest wymagane.';
            return array('valid' => false, 'errors' => $errors, 'value' => $value);
        }
        
        if (!empty($value)) {
            // Sprawdzenie typu
            if (isset($rules['type'])) {
                $type_validation = self::validate_type($value, $rules['type']);
                if (!$type_validation['valid']) {
                    $errors = array_merge($errors, $type_validation['errors']);
                }
            }
            
            // Sprawdzenie długości
            if (isset($rules['min_length']) && strlen($value) < $rules['min_length']) {
                $errors[] = self::get_field_label($field_name) . ' musi mieć co najmniej ' . $rules['min_length'] . ' znaków.';
            }
            
            if (isset($rules['max_length']) && strlen($value) > $rules['max_length']) {
                $errors[] = self::get_field_label($field_name) . ' nie może być dłuższe niż ' . $rules['max_length'] . ' znaków.';
            }
            
            // Sprawdzenie wartości
            if (isset($rules['min_value'])) {
                $min_value = $rules['min_value'];
                if ($field_name === 'rok_urodzenia' && $min_value === null) {
                    $min_value = date('Y') - 100; // Maksymalnie 100 lat
                }
                
                if (intval($value) < $min_value) {
                    $errors[] = self::get_field_label($field_name) . ' nie może być mniejsze niż ' . $min_value . '.';
                }
            }
            
            if (isset($rules['max_value'])) {
                $max_value = $rules['max_value'];
                if ($field_name === 'rok_urodzenia' && $max_value === null) {
                    $max_value = date('Y') - 10; // Minimum 10 lat
                }
                
                if (intval($value) > $max_value) {
                    $errors[] = self::get_field_label($field_name) . ' nie może być większe niż ' . $max_value . '.';
                }
            }
            
            // Sprawdzenie wzorca
            if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) {
                $errors[] = self::get_field_label($field_name) . ' zawiera nieprawidłowe znaki.';
            }
        }
        
        return array(
            'valid' => empty($errors),
            'errors' => $errors,
            'value' => $value
        );
    }

    /**
     * Waliduje wiele pól jednocześnie
     */
    public static function validate_fields($data, $field_rules = array()) {
        $results = array();
        $all_valid = true;
        $all_errors = array();
        
        foreach ($data as $field => $value) {
            $custom_rules = $field_rules[$field] ?? array();
            $result = self::validate_field($field, $value, $custom_rules);
            
            $results[$field] = $result;
            
            if (!$result['valid']) {
                $all_valid = false;
                $all_errors[$field] = $result['errors'];
            }
        }
        
        return array(
            'valid' => $all_valid,
            'errors' => $all_errors,
            'fields' => $results
        );
    }

    /**
     * Waliduje wiek i zwraca ostrzeżenia
     */
    public static function validate_age($birth_year, $return_format = 'array') {
        if (empty($birth_year)) {
            return array('valid' => true, 'age' => null, 'warnings' => array(), 'html' => '');
        }
        
        $age = date('Y') - intval($birth_year);
        $warnings = array();
        
        foreach (self::$age_warnings as $warning) {
            if ($age <= $warning['max_age']) {
                $warnings[] = array(
                    'type' => $warning['type'],
                    'text' => $warning['message'],
                    'link' => $warning['link'] ?? null,
                    'link_text' => $warning['link_text'] ?? null
                );
            }
        }
        
        $result = array(
            'valid' => true,
            'age' => $age,
            'warnings' => $warnings
        );
        
        if ($return_format === 'html') {
            $result['html'] = self::format_warnings_html($warnings);
        }
        
        return $result;
    }

    /**
     * Waliduje kategorię wagową
     */
    public static function validate_weight($category, $return_format = 'array') {
        if (empty($category)) {
            return array('valid' => true, 'warnings' => array(), 'errors' => array(), 'html' => '');
        }
        
        $warnings = array();
        $errors = array();
        
        if (isset(self::$weight_warnings[$category])) {
            $warning = self::$weight_warnings[$category];
            
            if ($warning['type'] === 'error') {
                $errors[] = array('type' => 'error', 'text' => $warning['message']);
            } else {
                $warnings[] = array('type' => 'warning', 'text' => $warning['message']);
            }
        }
        
        $result = array(
            'valid' => empty($errors),
            'warnings' => $warnings,
            'errors' => $errors
        );
        
        if ($return_format === 'html') {
            $result['html'] = self::format_warnings_html(array_merge($warnings, $errors));
        }
        
        return $result;
    }

    /**
     * Waliduje dane pasażera (kompletna walidacja)
     */
    public static function validate_passenger_data($data) {
        $required_fields = array('imie', 'nazwisko', 'rok_urodzenia', 'telefon', 'kategoria_wagowa', 'sprawnosc_fizyczna');
        $passenger_data = array();
        
        // Wyciągnij tylko potrzebne pola
        foreach ($required_fields as $field) {
            $passenger_data[$field] = $data[$field] ?? '';
        }
        
        // Podstawowa walidacja pól
        $validation = self::validate_fields($passenger_data);
        
        // Sprawdź dodatkowe wymagania
        if (!isset($data['akceptacja_regulaminu']) || $data['akceptacja_regulaminu'] !== true) {
            $validation['valid'] = false;
            $validation['errors']['akceptacja_regulaminu'] = 'Musisz zaakceptować regulamin.';
        }
        
        // Sprawdź kategorie wagową - błąd blokujący
        if (!empty($data['kategoria_wagowa'])) {
            $weight_validation = self::validate_weight($data['kategoria_wagowa']);
            if (!$weight_validation['valid']) {
                $validation['valid'] = false;
                foreach ($weight_validation['errors'] as $error) {
                    $validation['errors']['kategoria_wagowa'] = $error['text'];
                }
            }
        }
        
        return $validation;
    }

    /**
     * Sanityzuje wartość według podanej metody
     */
    private static function sanitize_value($value, $method) {
        switch ($method) {
            case 'trim_ucfirst':
                return ucfirst(trim($value));
            case 'trim_upper':
                return strtoupper(trim($value));
            case 'trim_lower':
                return strtolower(trim($value));
            case 'normalize_phone':
                return preg_replace('/[^0-9\+]/', '', trim($value));
            case 'trim':
            default:
                return trim($value);
        }
    }

    /**
     * Waliduje typ danych
     */
    private static function validate_type($value, $type) {
        switch ($type) {
            case 'integer':
                if (!is_numeric($value) || intval($value) != $value) {
                    return array('valid' => false, 'errors' => array('Wartość musi być liczbą całkowitą.'));
                }
                break;
                
            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !strtotime($value)) {
                    return array('valid' => false, 'errors' => array('Nieprawidłowy format daty (wymagany: RRRR-MM-DD).'));
                }
                break;
                
            case 'time':
                if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
                    return array('valid' => false, 'errors' => array('Nieprawidłowy format czasu (wymagany: HH:MM).'));
                }
                break;
        }
        
        return array('valid' => true, 'errors' => array());
    }

    /**
     * Zwraca czytelną nazwę pola
     */
    private static function get_field_label($field_name) {
        $labels = array(
            'imie' => 'Imię',
            'nazwisko' => 'Nazwisko',
            'telefon' => 'Telefon',
            'email' => 'Email',
            'kod_vouchera' => 'Kod vouchera',
            'rok_urodzenia' => 'Rok urodzenia',
            'kategoria_wagowa' => 'Kategoria wagowa',
            'sprawnosc_fizyczna' => 'Sprawność fizyczna',
            'data' => 'Data',
            'godzina' => 'Godzina'
        );
        
        return $labels[$field_name] ?? ucfirst($field_name);
    }

    /**
     * Formatuje ostrzeżenia do HTML
     */
    private static function format_warnings_html($messages) {
        if (empty($messages)) {
            return '';
        }
        
        $html = '';
        foreach ($messages as $msg) {
            $class = $msg['type'] === 'error' ? 'srl-uwaga-error' : 'srl-uwaga-warning';
            $bg = $msg['type'] === 'error' ? '#fdeaea' : '#fff3e0';
            $border = $msg['type'] === 'error' ? '#d63638' : '#ff9800';
            $icon = $msg['type'] === 'error' ? '❌ Błąd:' : 'Uwaga:';
            
            $html .= "<div class=\"{$class}\" style=\"background:{$bg}; border:2px solid {$border}; border-radius:8px; padding:15px; margin:10px 0;\">";
            $html .= "<strong>{$icon}</strong> {$msg['text']}";
            
            if (isset($msg['link'], $msg['link_text'])) {
                $html .= " <a href=\"{$msg['link']}\" target=\"_blank\" style=\"color:#f57c00; font-weight:bold;\">{$msg['link_text']}</a>";
            }
            
            $html .= '</div>';
        }
        
        return $html;
    }

    /**
     * Waliduje czy data nie jest z przeszłości
     */
    public static function validate_future_date($date) {
        if (empty($date)) {
            return array('valid' => false, 'message' => 'Data jest wymagana.');
        }
        
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return array('valid' => false, 'message' => 'Nieprawidłowy format daty.');
        }
        
        if ($timestamp < strtotime('today')) {
            return array('valid' => false, 'message' => 'Data nie może być z przeszłości.');
        }
        
        return array('valid' => true, 'message' => 'Data jest prawidłowa.');
    }
}

// Funkcje kompatybilności wstecznej - mapują na nową klasę
function srl_waliduj_dane_pasazera($data) {
    return SRL_Validator::validate_passenger_data($data);
}

function srl_waliduj_wiek($birth_year, $format = 'array') {
    return SRL_Validator::validate_age($birth_year, $format);
}

function srl_waliduj_kategorie_wagowa($category, $format = 'array') {
    return SRL_Validator::validate_weight($category, $format);
}

function srl_waliduj_date($date) {
    $result = SRL_Validator::validate_field('data', $date);
    return array(
        'valid' => $result['valid'],
        'message' => implode(' ', $result['errors'])
    );
}

function srl_waliduj_godzine($time) {
    $result = SRL_Validator::validate_field('godzina', $time);
    return array(
        'valid' => $result['valid'],
        'message' => implode(' ', $result['errors'])
    );
}

function srl_waliduj_telefon($phone) {
    $result = SRL_Validator::validate_field('telefon', $phone);
    return array(
        'valid' => $result['valid'],
        'message' => implode(' ', $result['errors'])
    );
}

function srl_waliduj_kod_vouchera($code) {
    $result = SRL_Validator::validate_field('kod_vouchera', $code);
    return array(
        'valid' => $result['valid'],
        'message' => implode(' ', $result['errors']),
        'kod' => $result['value']
    );
}