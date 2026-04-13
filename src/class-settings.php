<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Settings {
    const OPT_KEY = 'pbsr_settings';

    public static function get() {
        $defaults = [
            'zoho_dc' => 'eu',
            'org_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'refresh_token' => '',
            'crm_module' => 'Contacts',
            'books_doc_type' => 'salesorder',
            'field_map' => [
                'first_name' => 'first_name',
                'last_name' => 'last_name',
                'email' => 'email',
                'phone' => 'phone',
                'organisation_name' => 'company',
                'street' => 'street',
                'city' => 'city',
                'state' => 'state',
                'postcode' => 'zip',
                'country' => 'country',
                'notes' => 'notes',
                'reference' => 'reference',
                'blends' => 'blends',
            ],
            'sku_map' => [],
            'webhook_secret' => wp_generate_password(24, false),
            'enable_crm' => 1,
            'enable_books' => 1,
            'notify_emails' => get_option('admin_email'),
            'enable_notify' => 1,
            'sample_cost_override' => '',
            'allowed_sources' => 'permabound_sample_request',
            'hidden_samples' => '',
            'repeat_limit_days' => 30,
            'form_fields' => [],
            'mapping_rules' => [],
            'zoho_field_cache' => [],
        ];

        $settings = wp_parse_args(get_option(self::OPT_KEY, []), $defaults);
        $settings['form_fields'] = PBSR_Form_Fields::sanitizeDefinitions($settings['form_fields'] ?? []);
        $settings['mapping_rules'] = PBSR_Form_Fields::sanitizeMappingRules($settings['mapping_rules'] ?? [], $settings['form_fields']);
        $settings['zoho_field_cache'] = is_array($settings['zoho_field_cache']) ? $settings['zoho_field_cache'] : [];

        return $settings;
    }

    public static function update($data) {
        update_option(self::OPT_KEY, $data);
    }

    public static function sanitize($input) {
        $current = self::get();
        $input = is_array($input) ? $input : [];
        $out = $current;

        $out['zoho_dc'] = sanitize_text_field($input['zoho_dc'] ?? $current['zoho_dc']);
        $out['org_id'] = sanitize_text_field($input['org_id'] ?? $current['org_id']);
        $out['client_id'] = sanitize_text_field($input['client_id'] ?? $current['client_id']);
        $out['client_secret'] = sanitize_text_field($input['client_secret'] ?? $current['client_secret']);
        $out['refresh_token'] = sanitize_text_field($input['refresh_token'] ?? $current['refresh_token']);
        $out['crm_module'] = sanitize_text_field($input['crm_module'] ?? $current['crm_module']);
        $out['books_doc_type'] = in_array(($input['books_doc_type'] ?? $current['books_doc_type']), ['salesorder', 'estimate'], true) ? $input['books_doc_type'] : $current['books_doc_type'];
        $out['enable_crm'] = empty($input['enable_crm']) ? 0 : 1;
        $out['enable_books'] = empty($input['enable_books']) ? 0 : 1;
        $out['enable_notify'] = empty($input['enable_notify']) ? 0 : 1;
        $out['notify_emails'] = sanitize_text_field($input['notify_emails'] ?? $current['notify_emails']);
        $out['sample_cost_override'] = sanitize_text_field($input['sample_cost_override'] ?? $current['sample_cost_override']);
        $out['allowed_sources'] = sanitize_text_field($input['allowed_sources'] ?? $current['allowed_sources']);
        $out['hidden_samples'] = sanitize_textarea_field($input['hidden_samples'] ?? $current['hidden_samples']);
        $out['repeat_limit_days'] = max(1, (int) ($input['repeat_limit_days'] ?? $current['repeat_limit_days']));

        $out['form_fields'] = PBSR_Form_Fields::sanitizeDefinitions($input['form_fields'] ?? $current['form_fields']);
        $out['mapping_rules'] = PBSR_Form_Fields::sanitizeMappingRules($input['mapping_rules'] ?? $current['mapping_rules'], $out['form_fields']);
        $out['zoho_field_cache'] = $current['zoho_field_cache'];
        $out['field_map'] = $current['field_map'];
        $out['sku_map'] = $current['sku_map'];
        $out['webhook_secret'] = $current['webhook_secret'];

        return $out;
    }
}
