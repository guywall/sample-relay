<?php
if (!defined('ABSPATH')) exit;

class PBSR_Settings {

    const OPT_KEY = 'pbsr_settings';

    public static function get() {
        $defaults = [
            'zoho_dc'         => 'eu', // zoho domain cluster: eu | com | in | au | jp | ca
            'org_id'          => '',   // Zoho Books organization_id
            'client_id'       => '',
            'client_secret'   => '',
            'refresh_token'   => '',
            'crm_module'      => 'Contacts', // or Leads
            'books_doc_type'  => 'salesorder', // 'salesorder' or 'estimate'
            'field_map'       => [
                'first_name'        => 'first_name',
                'last_name'         => 'last_name',
                'email'             => 'email',
                'phone'             => 'phone',
                'organisation_name' => 'company',
                'street'            => 'street',
                'city'              => 'city',
                'state'             => 'state',
                'postcode'          => 'zip',
                'country'           => 'country',
                'notes'             => 'notes',
                'reference'         => 'reference',
                'blends'            => 'blends',
            ],
            'sku_map'         => [],
            'webhook_secret'  => wp_generate_password(24, false),
            'enable_crm'      => 1,
            'enable_books'    => 1,
            'notify_emails'   => get_option('admin_email'),
            'enable_notify'   => 1,
            'sample_cost_override' => '',
            'allowed_sources' => 'permabound_sample_request', // NEW setting
            'hidden_samples'  => '',
            'repeat_limit_days' => 30,
            'google_places_api_key' => '',
            'form_fields' => self::form_field_defaults(),
        ];

        $settings = wp_parse_args(get_option(self::OPT_KEY, []), $defaults);
        $settings['form_fields'] = self::normalize_form_fields($settings['form_fields'] ?? []);

        return $settings;
    }

    public static function update($data) {
        update_option(self::OPT_KEY, $data);
    }

    public static function form_field_defaults() {
        return [
            'first_name' => [
                'label' => 'First name',
                'width' => 'half',
                'required' => 1,
            ],
            'surname' => [
                'label' => 'Surname',
                'width' => 'half',
                'required' => 1,
            ],
            'email' => [
                'label' => 'Email',
                'width' => 'half',
                'required' => 1,
            ],
            'phone' => [
                'label' => 'Phone',
                'width' => 'half',
                'required' => 1,
            ],
            'enquiry_type' => [
                'label' => 'Enquiry type',
                'width' => 'half',
                'required' => 1,
            ],
            'organisation_name' => [
                'label' => 'Organisation name',
                'width' => 'half',
                'required' => 0,
            ],
            'project_type' => [
                'label' => 'Project type',
                'width' => 'half',
                'required' => 0,
            ],
            'project_size_m2' => [
                'label' => 'Project size',
                'width' => 'half',
                'required' => 0,
            ],
            'street' => [
                'label' => 'Street',
                'width' => 'full',
                'required' => 1,
            ],
            'address_2' => [
                'label' => 'Address 2',
                'width' => 'full',
                'required' => 0,
            ],
            'city' => [
                'label' => 'Town/City',
                'width' => 'half',
                'required' => 1,
            ],
            'county' => [
                'label' => 'County',
                'width' => 'half',
                'required' => 1,
            ],
            'country' => [
                'label' => 'Country',
                'width' => 'half',
                'required' => 1,
            ],
            'postcode' => [
                'label' => 'Postcode',
                'width' => 'half',
                'required' => 1,
            ],
            'gdpr_consent' => [
                'label' => 'Consent checkbox',
                'width' => 'full',
                'required' => 1,
            ],
        ];
    }

    public static function normalize_form_fields($fields) {
        $fields = is_array($fields) ? $fields : [];
        $defaults = self::form_field_defaults();
        $normalized = [];

        foreach ($defaults as $key => $default) {
            $incoming = isset($fields[$key]) && is_array($fields[$key]) ? $fields[$key] : [];
            $has_incoming = array_key_exists($key, $fields);
            $width = isset($incoming['width']) && in_array($incoming['width'], ['half', 'full'], true)
                ? $incoming['width']
                : $default['width'];

            $normalized[$key] = [
                'label' => $default['label'],
                'width' => $width,
                'required' => $has_incoming ? (!empty($incoming['required']) ? 1 : 0) : (int) $default['required'],
            ];
        }

        return $normalized;
    }
}
