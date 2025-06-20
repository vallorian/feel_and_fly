<?php
/**
 * Klasa zarządzania harmonogramem i slotami
 * Obsługuje planowanie, tworzenie i zarządzanie terminami lotów
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRL_Schedule_Manager {
    
    private $db;
    private static $instance = null;
    
    // Statusy slotów
    const SLOT_AVAILABLE = 'Wolny';
    const SLOT_PRIVATE = 'Prywatny';
    const SLOT_RESERVED = 'Zarezerwowany';
    const SLOT_COMPLETED = 'Zrealizowany';
    const SLOT_CANCELLED = 'Odwołany przez organizatora';
    
    // Maksymalna liczba pilotów
    const MAX_PILOTS = 4;
    
    public function __construct() {
        $this->db = SRL_Database_Manager::getInstance();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Pobiera harmonogram dla określonego dnia
     */
    public function get_day_schedule($date, $include_details = true) {
        $validation = SRL_Validator::validate_field('data', $date);
        if (!$validation['valid']) {
            throw new Exception('Nieprawidłowy format daty');
        }
        
        return $this->db->get_day_schedule($date, $include_details);
    }

    /**
     * Pobiera dostępne dni w miesiącu
     */
    public function get_available_days($year, $month) {
        if ($year < 2020 || $year > 2030 || $month < 1 || $month > 12) {
            throw new Exception('Nieprawidłowy rok lub miesiąc');
        }
        
        return $this->db->get_available_days($year, $month);
    }

    /**
     * Tworzy nowy slot czasowy
     */
    public function create_slot($date, $pilot_id, $start_time, $end_time, $status = self::SLOT_AVAILABLE) {
        try {
            // Walidacja
            $this->validate_slot_data($date, $pilot_id, $start_time, $end_time);
            
            // Sprawdź konflikty
            if ($this->has_time_conflict($date, $pilot_id, $start_time, $end_time)) {
                throw new Exception('Konflikt czasowy - pilot ma już lot w tym czasie');
            }
            
            global $wpdb;
            $result = $wpdb->insert(
                $wpdb->prefix . 'srl_terminy',
                array(
                    'data' => $date,
                    'pilot_id' => $pilot_id,
                    'godzina_start' => $start_time . ':00',
                    'godzina_koniec' => $end_time . ':00',
                    'status' => $status,
                    'klient_id' => null
                ),
                array('%s', '%d', '%s', '%s', '%s', '%d')
            );
            
            if ($result === false) {
                throw new Exception('Błąd podczas tworzenia slotu');
            }
            
            $slot_id = $wpdb->insert_id;
            
            // Wyczyść cache dla tego dnia
            $this->clear_day_cache($date);
            
            return array(
                'success' => true,
                'slot_id' => $slot_id,
                'message' => 'Slot został utworzony'
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Generuje sloty automatycznie dla pilota w określonym przedziale czasowym
     */
    public function generate_slots($date, $pilot_id, $start_time, $end_time, $interval_minutes = 15) {
        try {
            $this->validate_slot_data($date, $pilot_id, $start_time, $end_time);
            
            if ($interval_minutes < 1 || $interval_minutes > 60) {
                throw new Exception('Interwał musi być między 1 a 60 minut');
            }
            
            $start_minutes = SRL_Formatter::time_to_minutes($start_time);
            $end_minutes = SRL_Formatter::time_to_minutes($end_time);
            $created_slots = array();
            
            for ($current = $start_minutes; $current < $end_minutes; $current += $interval_minutes) {
                $slot_start = SRL_Formatter::minutes_to_time($current);
                $slot_end = SRL_Formatter::minutes_to_time($current + $interval_minutes);
                
                // Sprawdź konflikty dla każdego slotu
                if (!$this->has_time_conflict($date, $pilot_id, $slot_start, $slot_end)) {
                    $result = $this->create_slot($date, $pilot_id, $slot_start, $slot_end);
                    
                    if ($result['success']) {
                        $created_slots[] = array(
                            'slot_id' => $result['slot_id'],
                            'start' => $slot_start,
                            'end' => $slot_end
                        );
                    }
                }
            }
            
            return array(
                'success' => true,
                'created_count' => count($created_slots),
                'slots' => $created_slots,
                'message' => 'Utworzono ' . count($created_slots) . ' slotów'
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Aktualizuje slot
     */
    public function update_slot($slot_id, $updates) {
        try {
            $slot = $this->get_slot($slot_id);
            if (!$slot) {
                throw new Exception('Slot nie istnieje');
            }
            
            // Przygotuj dane do aktualizacji
            $allowed_fields = array('godzina_start', 'godzina_koniec', 'status', 'klient_id', 'notatka');
            $update_data = array();
            $format = array();
            
            foreach ($updates as $field => $value) {
                if (in_array($field, $allowed_fields)) {
                    if ($field === 'godzina_start' || $field === 'godzina_koniec') {
                        // Waliduj format czasu
                        $time_validation = SRL_Validator::validate_field('godzina', $value);
                        if (!$time_validation['valid']) {
                            throw new Exception("Nieprawidłowy format czasu dla {$field}");
                        }
                        $update_data[$field] = $value . ':00';
                        $format[] = '%s';
                    } elseif ($field === 'status') {
                        if (!$this->is_valid_slot_status($value)) {
                            throw new Exception('Nieprawidłowy status slotu');
                        }
                        $update_data[$field] = $value;
                        $format[] = '%s';
                    } elseif ($field === 'klient_id') {
                        $update_data[$field] = $value ? intval($value) : null;
                        $format[] = '%d';
                    } else {
                        $update_data[$field] = $value;
                        $format[] = '%s';
                    }
                }
            }
            
            if (empty($update_data)) {
                throw new Exception('Brak prawidłowych danych do aktualizacji');
            }
            
            // Sprawdź konflikty czasowe jeśli zmieniane są godziny
            if (isset($update_data['godzina_start']) || isset($update_data['godzina_koniec'])) {
                $new_start = isset($update_data['godzina_start']) ? substr($update_data['godzina_start'], 0, 5) : substr($slot['godzina_start'], 0, 5);
                $new_end = isset($update_data['godzina_koniec']) ? substr($update_data['godzina_koniec'], 0, 5) : substr($slot['godzina_koniec'], 0, 5);
                
                if ($this->has_time_conflict($slot['data'], $slot['pilot_id'], $new_start, $new_end, $slot_id)) {
                    throw new Exception('Konflikt czasowy z innym slotem');
                }
            }
            
            global $wpdb;
            $result = $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                $update_data,
                array('id' => $slot_id),
                $format,
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('Błąd podczas aktualizacji slotu');
            }
            
            // Wyczyść cache
            $this->clear_day_cache($slot['data']);
            
            return array('success' => true, 'message' => 'Slot został zaktualizowany');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Usuwa slot
     */
    public function delete_slot($slot_id) {
        try {
            $slot = $this->get_slot($slot_id);
            if (!$slot) {
                throw new Exception('Slot nie istnieje');
            }
            
            // Nie można usunąć zarezerwowanego slotu
            if ($slot['status'] === self::SLOT_RESERVED && $slot['klient_id']) {
                throw new Exception('Nie można usunąć zarezerwowanego slotu. Najpierw anuluj rezerwację.');
            }
            
            global $wpdb;
            $result = $wpdb->delete(
                $wpdb->prefix . 'srl_terminy',
                array('id' => $slot_id),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('Błąd podczas usuwania slotu');
            }
            
            // Wyczyść cache
            $this->clear_day_cache($slot['data']);
            
            return array('success' => true, 'message' => 'Slot został usunięty');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Anuluje lot przez organizatora
     */
    public function cancel_slot_by_organizer($slot_id, $reason = 'Odwołanie przez organizatora') {
        try {
            $slot = $this->get_slot($slot_id);
            if (!$slot) {
                throw new Exception('Slot nie istnieje');
            }
            
            if ($slot['status'] !== self::SLOT_RESERVED) {
                throw new Exception('Można anulować tylko zarezerwowane sloty');
            }
            
            // Pobierz szczegóły lotu i klienta
            $flight_data = $this->get_flight_data_for_slot($slot_id);
            
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            
            try {
                // Zapisz dane historyczne do notatki slotu
                $historical_data = array(
                    'typ' => 'odwolany_przez_organizatora',
                    'data_odwolania' => current_time('mysql'),
                    'oryginalny_status' => $slot['status'],
                    'klient_id' => $slot['klient_id'],
                    'reason' => $reason
                );
                
                if ($flight_data) {
                    $historical_data = array_merge($historical_data, $flight_data);
                }
                
                // Aktualizuj slot
                $wpdb->update(
                    $wpdb->prefix . 'srl_terminy',
                    array(
                        'status' => self::SLOT_CANCELLED,
                        'notatka' => json_encode($historical_data)
                    ),
                    array('id' => $slot_id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                // Aktualizuj lot jeśli istnieje
                if ($flight_data && $flight_data['lot_id']) {
                    $wpdb->update(
                        $wpdb->prefix . 'srl_zakupione_loty',
                        array(
                            'status' => 'wolny',
                            'termin_id' => null,
                            'data_rezerwacji' => null
                        ),
                        array('id' => $flight_data['lot_id']),
                        array('%s', '%d', '%s'),
                        array('%d')
                    );
                    
                    // Dodaj historię do lotu
                    $this->add_flight_history($flight_data['lot_id'], 'odwolanie_przez_organizatora', 'Admin', array(
                        'slot_id' => $slot_id,
                        'reason' => $reason,
                        'termin' => SRL_Formatter::format_slot_details($slot)
                    ));
                }
                
                $wpdb->query('COMMIT');
                
                // Wyślij email do klienta jeśli ma dane
                if ($flight_data && $flight_data['user_email']) {
                    $this->send_cancellation_email($flight_data, $slot, $reason);
                }
                
                // Wyczyść cache
                $this->clear_day_cache($slot['data']);
                
                return array('success' => true, 'message' => 'Lot został odwołany przez organizatora');
                
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                throw $e;
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Przywraca odwołany slot
     */
    public function restore_cancelled_slot($slot_id) {
        try {
            $slot = $this->get_slot($slot_id);
            if (!$slot) {
                throw new Exception('Slot nie istnieje');
            }
            
            if ($slot['status'] !== self::SLOT_CANCELLED) {
                throw new Exception('Można przywrócić tylko odwołane sloty');
            }
            
            // Pobierz dane historyczne
            $historical_data = json_decode($slot['notatka'], true);
            if (!$historical_data || !isset($historical_data['lot_id'])) {
                throw new Exception('Brak danych do przywrócenia');
            }
            
            // Sprawdź czy lot nadal istnieje i jest dostępny
            $flight = SRL_Flight_Manager::getInstance()->get_flight($historical_data['lot_id']);
            if (!$flight || $flight['status'] !== 'wolny') {
                throw new Exception('Lot nie jest już dostępny do przywrócenia');
            }
            
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            
            try {
                // Przywróć slot
                $wpdb->update(
                    $wpdb->prefix . 'srl_terminy',
                    array(
                        'status' => self::SLOT_RESERVED,
                        'notatka' => null
                    ),
                    array('id' => $slot_id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                // Przywróć lot
                $wpdb->update(
                    $wpdb->prefix . 'srl_zakupione_loty',
                    array(
                        'status' => 'zarezerwowany',
                        'termin_id' => $slot_id,
                        'data_rezerwacji' => current_time('mysql')
                    ),
                    array('id' => $historical_data['lot_id']),
                    array('%s', '%d', '%s'),
                    array('%d')
                );
                
                $wpdb->query('COMMIT');
                
                // Dodaj historię
                $this->add_flight_history($historical_data['lot_id'], 'przywrocenie_przez_admin', 'Admin', array(
                    'slot_id' => $slot_id,
                    'restored_date' => current_time('mysql')
                ));
                
                // Wyślij email potwierdzenia
                if (isset($historical_data['user_email'])) {
                    $this->send_restoration_email($historical_data, $slot);
                }
                
                // Wyczyść cache
                $this->clear_day_cache($slot['data']);
                
                return array('success' => true, 'message' => 'Slot został przywrócony');
                
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                throw $e;
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Ustawia slot jako prywatny z danymi pasażera
     */
    public function set_private_slot($slot_id, $passenger_data) {
        try {
            $slot = $this->get_slot($slot_id);
            if (!$slot) {
                throw new Exception('Slot nie istnieje');
            }
            
            if ($slot['status'] !== self::SLOT_AVAILABLE) {
                throw new Exception('Można ustawić jako prywatny tylko wolne sloty');
            }
            
            // Waliduj dane pasażera
            $validation = SRL_Validator::validate_passenger_data($passenger_data);
            if (!$validation['valid']) {
                throw new Exception('Nieprawidłowe dane pasażera: ' . implode(', ', array_values($validation['errors'])));
            }
            
            // Dodaj typ jako prywatny
            $passenger_data['typ'] = 'prywatny';
            
            global $wpdb;
            $result = $wpdb->update(
                $wpdb->prefix . 'srl_terminy',
                array(
                    'status' => self::SLOT_PRIVATE,
                    'notatka' => json_encode($passenger_data)
                ),
                array('id' => $slot_id),
                array('%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('Błąd podczas ustawiania slotu jako prywatny');
            }
            
            // Wyczyść cache
            $this->clear_day_cache($slot['data']);
            
            return array('success' => true, 'message' => 'Slot został ustawiony jako prywatny');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Pobiera szczegóły slotu
     */
    public function get_slot($slot_id) {
        return $this->db->get_slot_details($slot_id);
    }

    /**
     * Pobiera statystyki harmonogramu
     */
    public function get_schedule_statistics($date_from = null, $date_to = null) {
        global $wpdb;
        
        $where_clause = "WHERE 1=1";
        $params = array();
        
        if ($date_from) {
            $where_clause .= " AND data >= %s";
            $params[] = $date_from;
        }
        
        if ($date_to) {
            $where_clause .= " AND data <= %s";
            $params[] = $date_to;
        }
        
        $stats = array();
        
        // Statystyki według statusu
        $sql = "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}srl_terminy {$where_clause} GROUP BY status";
        $stats['by_status'] = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        
        // Statystyki według pilotów
        $sql = "SELECT pilot_id, COUNT(*) as count FROM {$wpdb->prefix}srl_terminy {$where_clause} GROUP BY pilot_id ORDER BY pilot_id";
        $stats['by_pilot'] = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        
        // Najbardziej aktywne dni
        $sql = "SELECT data, COUNT(*) as slot_count FROM {$wpdb->prefix}srl_terminy {$where_clause} GROUP BY data ORDER BY slot_count DESC LIMIT 10";
        $stats['busiest_days'] = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        
        return $stats;
    }

    // Metody pomocnicze
    
    private function validate_slot_data($date, $pilot_id, $start_time, $end_time) {
        // Waliduj datę
        $date_validation = SRL_Validator::validate_field('data', $date);
        if (!$date_validation['valid']) {
            throw new Exception('Nieprawidłowa data: ' . implode(', ', $date_validation['errors']));
        }
        
        // Sprawdź czy data nie jest z przeszłości
        if (SRL_Formatter::is_date_past($date)) {
            throw new Exception('Nie można tworzyć slotów w przeszłości');
        }
        
        // Waliduj pilot_id
        if ($pilot_id < 1 || $pilot_id > self::MAX_PILOTS) {
            throw new Exception('Nieprawidłowy ID pilota (1-' . self::MAX_PILOTS . ')');
        }
        
        // Waliduj czasy
        $start_validation = SRL_Validator::validate_field('godzina', $start_time);
        if (!$start_validation['valid']) {
            throw new Exception('Nieprawidłowa godzina rozpoczęcia');
        }
        
        $end_validation = SRL_Validator::validate_field('godzina', $end_time);
        if (!$end_validation['valid']) {
            throw new Exception('Nieprawidłowa godzina zakończenia');
        }
        
        // Sprawdź logikę czasową
        if (SRL_Formatter::time_to_minutes($start_time) >= SRL_Formatter::time_to_minutes($end_time)) {
            throw new Exception('Godzina zakończenia musi być późniejsza niż rozpoczęcia');
        }
    }
    
    private function has_time_conflict($date, $pilot_id, $start_time, $end_time, $exclude_slot_id = null) {
        global $wpdb;
        
        $where_clause = "WHERE data = %s AND pilot_id = %d AND status != %s";
        $params = array($date, $pilot_id, self::SLOT_CANCELLED);
        
        if ($exclude_slot_id) {
            $where_clause .= " AND id != %d";
            $params[] = $exclude_slot_id;
        }
        
        // Sprawdź nakładanie się czasów
        $where_clause .= " AND ((godzina_start < %s AND godzina_koniec > %s) OR (godzina_start < %s AND godzina_koniec > %s))";
        $params = array_merge($params, array($end_time . ':00', $start_time . ':00', $start_time . ':00', $end_time . ':00'));
        
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}srl_terminy {$where_clause}";
        
        return $wpdb->get_var($wpdb->prepare($sql, ...$params)) > 0;
    }
    
    private function is_valid_slot_status($status) {
        $valid_statuses = array(
            self::SLOT_AVAILABLE,
            self::SLOT_PRIVATE,
            self::SLOT_RESERVED,
            self::SLOT_COMPLETED,
            self::SLOT_CANCELLED
        );
        
        return in_array($status, $valid_statuses);
    }
    
    private function get_flight_data_for_slot($slot_id) {
        global $wpdb;
        
        $flight = $wpdb->get_row($wpdb->prepare(
            "SELECT zl.*, u.user_email, u.display_name 
             FROM {$wpdb->prefix}srl_zakupione_loty zl
             LEFT JOIN {$wpdb->users} u ON zl.user_id = u.ID
             WHERE zl.termin_id = %d",
            $slot_id
        ), ARRAY_A);
        
        return $flight;
    }
    
    private function clear_day_cache($date) {
        $this->db->clear_cache("day_schedule_{$date}_full");
        $this->db->clear_cache("day_schedule_{$date}_basic");
    }
    
    private function add_flight_history($flight_id, $action_type, $executor, $details) {
        return srl_dopisz_do_historii_lotu($flight_id, array(
            'data' => current_time('Y-m-d H:i:s'),
            'typ' => $action_type,
            'executor' => $executor,
            'szczegoly' => $details
        ));
    }
    
    private function send_cancellation_email($flight_data, $slot, $reason) {
        if (function_exists('srl_wyslij_email_anulowania')) {
            return srl_wyslij_email_anulowania($flight_data['user_id'], $slot, $flight_data, $reason);
        }
        return false;
    }
    
    private function send_restoration_email($historical_data, $slot) {
        // Implementacja wysyłania emaila o przywróceniu
        return true;
    }
}

// Funkcje kompatybilności wstecznej
function srl_get_day_schedule($date, $include_details = true) {
    return SRL_Schedule_Manager::getInstance()->get_day_schedule($date, $include_details);
}

function srl_create_slot($date, $pilot_id, $start_time, $end_time, $status = 'Wolny') {
    return SRL_Schedule_Manager::getInstance()->create_slot($date, $pilot_id, $start_time, $end_time, $status);
}

function srl_generate_slots($date, $pilot_id, $start_time, $end_time, $interval = 15) {
    return SRL_Schedule_Manager::getInstance()->generate_slots($date, $pilot_id, $start_time, $end_time, $interval);
}

function srl_update_slot($slot_id, $updates) {
    return SRL_Schedule_Manager::getInstance()->update_slot($slot_id, $updates);
}

function srl_delete_slot($slot_id) {
    return SRL_Schedule_Manager::getInstance()->delete_slot($slot_id);
}

function srl_cancel_slot_by_organizer($slot_id, $reason = null) {
    return SRL_Schedule_Manager::getInstance()->cancel_slot_by_organizer($slot_id, $reason);
}