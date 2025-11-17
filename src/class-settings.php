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
        ];

        return wp_parse_args(get_option(self::OPT_KEY, []), $defaults);
    }

    public static function update($data) {
        update_option(self::OPT_KEY, $data);
    }
}
