<?php if (!defined('ABSPATH')) {exit;}

function srl_waliduj_wiek($rok_urodzenia, $format = 'html') {
    $komunikaty = array();
    
    if (!$rok_urodzenia) {
        return array('valid' => true, 'wiek' => null, 'komunikaty' => array());
    }
    
    $wiek = date('Y') - intval($rok_urodzenia);
    
    // Sprawdź wiek - ostrzeżenie dla nieletnich
    if ($wiek <= 18) {
        $komunikaty[] = array(
            'typ' => 'warning',
            'tresc' => 'Lot osoby niepełnoletniej: Osoby poniżej 18. roku życia mogą wziąć udział w locie tylko za zgodą rodzica lub opiekuna prawnego. Wymagane jest okazanie podpisanej, wydrukowanej zgody w dniu lotu, na miejscu startu.',
            'link' => '/zgoda-na-lot-osoba-nieletnia/',
            'link_text' => 'Pobierz zgodę tutaj'
        );
    }
    
    $result = array(
        'valid' => true,
        'wiek' => $wiek,
        'komunikaty' => $komunikaty
    );
    
    if ($format === 'html') {
        $result['html'] = srl_generuj_html_komunikatow($komunikaty, array());
    }
    
    return $result;
}

function srl_waliduj_kategorie_wagowa($kategoria_wagowa, $format = 'html') {
    $komunikaty = array();
    $errors = array();
    
    if (!$kategoria_wagowa) {
        return array('valid' => true, 'komunikaty' => array(), 'errors' => array());
    }
    
    // Sprawdź kategorię wagową
    if ($kategoria_wagowa === '91-120kg') {
        $komunikaty[] = array(
            'typ' => 'warning', 
            'tresc' => 'Loty z pasażerami powyżej 90 kg mogą być krótsze, brak możliwości wykonania akrobacji. Pilot ma prawo odmówić wykonania lotu jeśli uzna, że zagraża to bezpieczeństwu.'
        );
    } elseif ($kategoria_wagowa === '120kg+') {
        $errors[] = array(
            'typ' => 'error',
            'tresc' => 'Brak możliwości wykonania lotu z pasażerem powyżej 120 kg.'
        );
    }
    
    $result = array(
        'valid' => empty($errors),
        'komunikaty' => $komunikaty,
        'errors' => $errors
    );
    
    if ($format === 'html') {
        $result['html'] = srl_generuj_html_komunikatow($komunikaty, $errors);
    }
    
    return $result;
}

function srl_sprawdz_kompatybilnosc_akrobacje($kategoria_wagowa, $ma_akrobacje = false) {
    if (!$ma_akrobacje) {
        return array('compatible' => true, 'message' => '');
    }
    
    if (in_array($kategoria_wagowa, ['91-120kg', '120kg+'])) {
        return array(
            'compatible' => false, 
            'message' => 'Wybrana kategoria wagowa (' . $kategoria_wagowa . ') nie jest dostępna dla lotów z akrobacjami.'
        );
    }
    
    return array('compatible' => true, 'message' => '');
}

function srl_generuj_html_komunikatow($komunikaty, $errors) {
    $html = '';
    
    foreach (array_merge($komunikaty, $errors) as $kom) {
        $class = $kom['typ'] === 'error' ? 'srl-uwaga-error' : 'srl-uwaga-warning';
        $bg_color = $kom['typ'] === 'error' ? '#fdeaea' : '#fff3e0';
        $border_color = $kom['typ'] === 'error' ? '#d63638' : '#ff9800';
        $text_color = $kom['typ'] === 'error' ? '#721c24' : '#000';
        
        $html .= '<div class="' . $class . '" style="background:' . $bg_color . '; border:2px solid ' . $border_color . '; border-radius:8px; padding:20px; margin-top:10px; color:' . $text_color . ';">';
        
        if ($kom['typ'] === 'error') {
            $html .= '<strong>❌ Błąd:</strong> ';
        } else {
            $html .= '<strong>Uwaga:</strong> ';
        }
        
        $html .= $kom['tresc'];
        
        if (isset($kom['link']) && isset($kom['link_text'])) {
            $html .= ' <a href="' . $kom['link'] . '" target="_blank" style="color:#f57c00; font-weight:bold;">' . $kom['link_text'] . '</a>';
        }
        
        $html .= '</div>';
    }
    
    return $html;
}