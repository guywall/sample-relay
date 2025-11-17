<?php
if (!defined('ABSPATH')) exit;

// Hook runs on successful submission
add_action('elementor_pro/forms/new_record', function($record, $handler) {
    try {
        $raw = [];
        $fields = $record->get('fields');
        foreach ($fields as $id => $field) {
            $val = $field['value'];
            // normalise checkbox fields to array
            if (is_string($val) && strpos($val, ',') !== false) {
                $val = array_map('trim', explode(',', $val));
            }
            $raw[$id] = $val;
        }

        // example: ensure we capture blends even if field IDs differ
        // Map via settings[field_map] instead for full control

        // Use Elementor's form ID + time as idempotency
        $meta = $record->get('meta');
        $form_id = $meta['form_name'] ?? 'elementor';
        $key = md5(wp_json_encode([$form_id, $raw['email'] ?? '', $raw['blends'] ?? '', $raw['reference'] ?? '', date('Y-m-d-H')]));

        $res = PBSR_Dispatcher::process($raw, 'elementor', $key);
        // You could also set a dynamic response message here if needed

    } catch (Throwable $e) {
        // swallow to avoid breaking user flow; it’s logged inside dispatcher
    }
}, 10, 2);
