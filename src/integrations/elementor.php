<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('elementor_pro/forms/new_record', function ($record, $handler) {
    try {
        $raw = [];
        $fields = $record->get('fields');

        foreach ($fields as $id => $field) {
            $value = $field['value'];

            if (is_string($value) && strpos($value, ',') !== false) {
                $value = array_map('trim', explode(',', $value));
            }

            $raw[$id] = $value;
        }

        $meta = $record->get('meta');
        $form_id = $meta['form_name'] ?? 'elementor';
        $raw['context'] = [
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
        ];

        $key = md5(wp_json_encode([
            $form_id,
            $raw['email'] ?? '',
            $raw['blends'] ?? '',
            $raw['reference'] ?? '',
            date('Y-m-d-H'),
        ]));

        PBSR_Dispatcher::process($raw, 'elementor', $key);
    } catch (Throwable $e) {
        error_log('PBSR Elementor relay error: ' . $e->getMessage());
    }
}, 10, 2);
