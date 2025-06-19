<?php if (!defined('ABSPATH')) {exit;}

function srl_generate_button($text, $class = 'srl-btn-primary', $attrs = array()) {
    $attributes = '';
    foreach ($attrs as $key => $value) {
        $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
    }
    return '<button class="srl-btn ' . $class . '"' . $attributes . '>' . $text . '</button>';
}

function srl_generate_status_badge($status, $type = 'lot') {
    $config = array(
        'lot' => array(
            'wolny' => array('icon' => '🟢', 'class' => 'status-available', 'label' => 'Dostępny do rezerwacji'),
            'zarezerwowany' => array('icon' => '🟡', 'class' => 'status-reserved', 'label' => 'Zarezerwowany'),
            'zrealizowany' => array('icon' => '🔵', 'class' => 'status-completed', 'label' => 'Zrealizowany'),
            'przedawniony' => array('icon' => '🔴', 'class' => 'status-expired', 'label' => 'Przeterminowany')
        ),
        'slot' => array(
            'Wolny' => array('icon' => '🟢', 'class' => 'status-available', 'label' => 'Wolny'),
            'Prywatny' => array('icon' => '🟤', 'class' => 'status-private', 'label' => 'Prywatny'),
            'Zarezerwowany' => array('icon' => '🟡', 'class' => 'status-reserved', 'label' => 'Zarezerwowany'),
            'Zrealizowany' => array('icon' => '🔵', 'class' => 'status-completed', 'label' => 'Zrealizowany'),
            'Odwołany przez organizatora' => array('icon' => '🔴', 'class' => 'status-cancelled', 'label' => 'Odwołany')
        )
    );

    $item = $config[$type][$status] ?? array('icon' => '⚪', 'class' => 'status-unknown', 'label' => ucfirst($status));
    
    return sprintf(
        '<span class="%s" style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">%s %s</span>',
        $item['class'],
        $item['icon'],
        $item['label']
    );
}

function srl_format_flight_options_html($ma_filmowanie, $ma_akrobacje) {
    $opcje = array();
    
    if ($ma_filmowanie) {
        $opcje[] = '<span style="color: #46b450; font-weight: bold;">z filmowaniem</span>';
    } else {
        $opcje[] = '<span style="color: #d63638;">bez filmowania</span>';
    }

    if ($ma_akrobacje) {
        $opcje[] = '<span style="color: #46b450; font-weight: bold;">z akrobacjami</span>';
    } else {
        $opcje[] = '<span style="color: #d63638;">bez akrobacji</span>';
    }

    return implode(', ', $opcje);
}

function srl_generate_message($text, $type = 'info', $dismissible = false) {
    $classes = array(
        'info' => 'srl-komunikat-info',
        'success' => 'srl-komunikat-success', 
        'warning' => 'srl-komunikat-warning',
        'error' => 'srl-komunikat-error'
    );
    
    $class = $classes[$type] ?? $classes['info'];
    $dismiss_btn = $dismissible ? '<button type="button" class="srl-dismiss">×</button>' : '';
    
    return '<div class="srl-komunikat ' . $class . '">' . $text . $dismiss_btn . '</div>';
}

function srl_generate_select($name, $options, $selected = '', $attrs = array()) {
    $attributes = '';
    foreach ($attrs as $key => $value) {
        $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
    }
    
    $html = '<select name="' . esc_attr($name) . '"' . $attributes . '>';
    
    foreach ($options as $value => $label) {
        $selected_attr = selected($selected, $value, false);
        $html .= '<option value="' . esc_attr($value) . '"' . $selected_attr . '>' . esc_html($label) . '</option>';
    }
    
    $html .= '</select>';
    return $html;
}

function srl_generate_link($url, $text, $class = '', $attrs = array()) {
    $attributes = '';
    foreach ($attrs as $key => $value) {
        $attributes .= ' ' . $key . '="' . esc_attr($value) . '"';
    }
    
    $class_attr = $class ? ' class="' . esc_attr($class) . '"' : '';
    return '<a href="' . esc_url($url) . '"' . $class_attr . $attributes . '>' . $text . '</a>';
}

function srl_format_passenger_data($data, $show_age = true) {
    $output = array();
    
    if (!empty($data['imie']) && !empty($data['nazwisko'])) {
        $name = $data['imie'] . ' ' . $data['nazwisko'];
        if ($show_age && !empty($data['rok_urodzenia'])) {
            $wiek = date('Y') - intval($data['rok_urodzenia']);
            $name .= ' (' . $wiek . ' lat)';
        }
        $output[] = $name;
    }
    
    if (!empty($data['telefon'])) {
        $output[] = '📞 ' . $data['telefon'];
    }
    
    if (!empty($data['kategoria_wagowa'])) {
        $output[] = 'Waga: ' . $data['kategoria_wagowa'];
    }
    
    if (!empty($data['sprawnosc_fizyczna'])) {
        $sprawnosci = array(
            'zdolnosc_do_marszu' => 'Marsz',
            'zdolnosc_do_biegu' => 'Bieg',
            'sprinter' => 'Sprinter'
        );
        $output[] = 'Sprawność: ' . ($sprawnosci[$data['sprawnosc_fizyczna']] ?? $data['sprawnosc_fizyczna']);
    }
    
    if (!empty($data['uwagi'])) {
        $uwagi = strlen($data['uwagi']) > 50 ? substr($data['uwagi'], 0, 47) . '...' : $data['uwagi'];
        $output[] = 'Uwagi: ' . $uwagi;
    }
    
    return implode('<br>', $output);
}