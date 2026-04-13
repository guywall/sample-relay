<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Zoho_Field_Manager {
    public static function init() {
        add_action('admin_post_pbsr_sync_zoho_fields', [__CLASS__, 'handleSyncRequest']);
    }

    public static function handleSyncRequest() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorised');
        }

        check_admin_referer('pbsr_sync_zoho_fields');

        $result = self::syncAll();
        $args = [
            'page' => 'pbsr_admin',
            'pbsr_notice' => $result['status'] ?? (empty($result['ok']) ? 'sync_failed' : 'sync_ok'),
        ];

        if (!empty($result['message'])) {
            $args['pbsr_message'] = rawurlencode($result['message']);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function syncAll() {
        $settings = PBSR_Settings::get();
        $client = new PBSR_Zoho_Client();
        $doc_type = ($settings['books_doc_type'] ?? 'salesorder') === 'estimate' ? 'estimates' : 'salesorders';
        $existing_cache = isset($settings['zoho_field_cache']) && is_array($settings['zoho_field_cache']) ? $settings['zoho_field_cache'] : [];
        $cache = $existing_cache;
        $cache['synced_at'] = current_time('mysql');
        $cache['errors'] = [];
        $messages = [];
        $successes = 0;

        try {
            $cache['crm'] = self::fetchCRMFields($client, $settings['crm_module'] ?? 'Contacts');
            $messages[] = sprintf('CRM fields synced: %d.', count($cache['crm']));
            $successes++;
        } catch (Throwable $e) {
            $cache['errors']['crm'] = $e->getMessage();
            $messages[] = $e->getMessage();
        }

        try {
            $cache['books_contact'] = self::fetchBooksFields($client, 'contacts');
            $messages[] = sprintf('Books contact fields available: %d.', count($cache['books_contact']));
            $successes++;
        } catch (Throwable $e) {
            $cache['errors']['books_contact'] = $e->getMessage();
            $messages[] = $e->getMessage();
        }

        try {
            $cache['books_document'] = self::fetchBooksFields($client, $doc_type);
            $messages[] = sprintf('Books document fields available: %d.', count($cache['books_document']));
            $successes++;
        } catch (Throwable $e) {
            $cache['errors']['books_document'] = $e->getMessage();
            $messages[] = $e->getMessage();
        }

        $new_settings = $settings;
        $new_settings['zoho_field_cache'] = $cache;
        PBSR_Settings::update($new_settings);

        if ($successes === 0) {
            return [
                'ok' => false,
                'status' => 'sync_failed',
                'cache' => $cache,
                'message' => implode(' ', $messages),
            ];
        }

        if (!empty($cache['errors'])) {
            return [
                'ok' => true,
                'status' => 'sync_partial',
                'cache' => $cache,
                'message' => implode(' ', $messages),
            ];
        }

        return [
            'ok' => true,
            'status' => 'sync_ok',
            'cache' => $cache,
            'message' => 'Zoho fields synced successfully.',
        ];
    }

    public static function getCachedFields($scope) {
        $settings = PBSR_Settings::get();
        $cache = isset($settings['zoho_field_cache']) && is_array($settings['zoho_field_cache']) ? $settings['zoho_field_cache'] : [];
        $fields = isset($cache[$scope]) && is_array($cache[$scope]) ? $cache[$scope] : [];

        if (!empty($fields)) {
            return $fields;
        }

        if ($scope === 'books_contact') {
            return self::defaultBooksContactFields();
        }

        if ($scope === 'books_document') {
            return self::defaultBooksDocumentFields(($settings['books_doc_type'] ?? 'salesorder') === 'estimate' ? 'estimates' : 'salesorders');
        }

        if ($scope === 'crm') {
            return self::defaultCRMFields($settings['crm_module'] ?? 'Contacts');
        }

        return [];
    }

    public static function lastSyncedAt() {
        $settings = PBSR_Settings::get();
        return $settings['zoho_field_cache']['synced_at'] ?? '';
    }

    public static function getSyncErrors() {
        $settings = PBSR_Settings::get();
        $cache = isset($settings['zoho_field_cache']) && is_array($settings['zoho_field_cache']) ? $settings['zoho_field_cache'] : [];
        return isset($cache['errors']) && is_array($cache['errors']) ? $cache['errors'] : [];
    }

    public static function legacyMappingReference() {
        return [
            'crm' => [
                ['field' => 'last_name', 'target' => 'Last_Name', 'note' => 'Sent as the primary surname field.'],
                ['field' => 'first_name', 'target' => 'First_Name', 'note' => 'Sent as the CRM first name field.'],
                ['field' => 'email', 'target' => 'Email', 'note' => 'Sent on each CRM upsert.'],
                ['field' => 'phone', 'target' => 'Phone', 'note' => 'Sent on each CRM upsert.'],
                ['field' => 'notes', 'target' => 'Description', 'note' => 'Copied into the CRM description field.'],
                ['field' => '(fixed value)', 'target' => 'Lead_Source', 'note' => 'Always set to "Sample Request".'],
            ],
            'books_contact' => [
                ['field' => 'full_name (first_name + last_name)', 'target' => 'contact_name', 'note' => 'Joined into a single Books contact name.'],
                ['field' => 'organisation_name / company', 'target' => 'company_name', 'note' => 'Used for the company name when present.'],
                ['field' => 'email', 'target' => 'email', 'note' => 'Also copied into the primary contact person email.'],
                ['field' => 'phone', 'target' => 'phone', 'note' => 'Also copied into the primary contact person phone.'],
                ['field' => 'first_name', 'target' => 'contact_persons[0].first_name', 'note' => 'Primary contact first name.'],
                ['field' => 'last_name', 'target' => 'contact_persons[0].last_name', 'note' => 'Primary contact surname.'],
                ['field' => 'street', 'target' => 'billing_address.address', 'note' => 'Also copied to shipping address.'],
                ['field' => 'city', 'target' => 'billing_address.city', 'note' => 'Also copied to shipping city.'],
                ['field' => 'state / county', 'target' => 'billing_address.state', 'note' => 'Also copied to shipping state.'],
                ['field' => 'postcode', 'target' => 'billing_address.zip', 'note' => 'Also copied to shipping postcode.'],
                ['field' => 'country', 'target' => 'billing_address.country', 'note' => 'Also copied to shipping country and VAT treatment.'],
                ['field' => 'full_name (first_name + last_name)', 'target' => 'shipping_address.attention', 'note' => 'Used as the shipping attention line.'],
                ['field' => '(fixed value)', 'target' => 'contact_type', 'note' => 'Always set to "customer".'],
            ],
            'books_document' => [
                ['field' => 'reference', 'target' => 'reference_number', 'note' => 'Used on the Books sales order or estimate.'],
                ['field' => 'notes', 'target' => 'notes', 'note' => 'Copied to the document notes field.'],
                ['field' => 'selected sample SKUs', 'target' => 'line_items', 'note' => 'Mapped separately through the SKU mapper, not the field table.'],
            ],
        ];
    }

    public static function applyCRMMappings(array &$record, array $field_values, array $mapping_rules = [], array $crm_cache = []) {
        foreach ($mapping_rules as $field_key => $mapping) {
            $target = trim((string) ($mapping['crm'] ?? ''));
            if ($target === '' || !array_key_exists($field_key, $field_values)) {
                continue;
            }

            $record[$target] = PBSR_Form_Fields::prepareMappedValue($field_values[$field_key]);
        }
    }

    public static function applyBooksMappings(array &$payload, array $field_values, array $mapping_rules = [], array $field_cache = [], $scope = 'books_contact') {
        $index = self::indexFieldsByTarget($field_cache);
        $mapping_key = $scope === 'books_document' ? 'books_document' : 'books_contact';

        foreach ($mapping_rules as $field_key => $mapping) {
            $target = trim((string) ($mapping[$mapping_key] ?? ''));
            if ($target === '' || !array_key_exists($field_key, $field_values)) {
                continue;
            }

            $value = PBSR_Form_Fields::prepareMappedValue($field_values[$field_key]);
            if ($value === '' || $value === null) {
                continue;
            }

            $meta = $index[$target] ?? null;
            if (is_array($meta) && !empty($meta['custom']) && !empty($meta['customfield_id'])) {
                self::applyBooksCustomField($payload, $meta['customfield_id'], $value);
                continue;
            }

            self::applyBooksStandardField($payload, $target, $value);
        }
    }

    private static function fetchCRMFields(PBSR_Zoho_Client $client, $module) {
        $module = trim((string) $module);
        if ($module === '') {
            throw new Exception('Unable to fetch Zoho CRM fields because no CRM module is selected.');
        }

        $res = $client->crm_get('/settings/fields?module=' . rawurlencode($module));
        if (($res['code'] ?? 0) !== 200 || empty($res['body']['fields']) || !is_array($res['body']['fields'])) {
            throw new Exception(self::buildCRMFieldErrorMessage($module, $res));
        }

        $out = [];
        foreach ($res['body']['fields'] as $field) {
            $api_name = trim((string) ($field['api_name'] ?? ''));
            if ($api_name === '') {
                continue;
            }

            $out[] = [
                'label' => (string) ($field['field_label'] ?? $field['display_label'] ?? $api_name),
                'target' => $api_name,
                'api_name' => $api_name,
                'type' => (string) ($field['data_type'] ?? ''),
                'custom' => !empty($field['custom_field']),
                'read_only' => !empty($field['read_only']),
            ];
        }

        usort($out, [__CLASS__, 'sortFields']);
        return $out;
    }

    private static function fetchBooksFields(PBSR_Zoho_Client $client, $entity) {
        $settings = PBSR_Settings::get();
        $org_id = trim((string) ($settings['org_id'] ?? ''));
        $attempts = [
            "/settings/fields?entity={$entity}&organization_id={$org_id}",
            "/settings/fields?module={$entity}&organization_id={$org_id}",
            "/settings/customfields?entity={$entity}&organization_id={$org_id}",
            "/settings/customfields?module={$entity}&organization_id={$org_id}",
        ];

        $collected = [];
        foreach ($attempts as $endpoint) {
            try {
                $res = $client->books_get($endpoint);
            } catch (Throwable $e) {
                continue;
            }

            if (($res['code'] ?? 0) !== 200 || !is_array($res['body'] ?? null)) {
                continue;
            }

            $fields = self::normalizeBooksFieldResponse($res['body'], $entity);
            foreach ($fields as $field) {
                $collected[$field['target']] = $field;
            }
        }

        $defaults = $entity === 'contacts' ? self::defaultBooksContactFields() : self::defaultBooksDocumentFields($entity);
        foreach ($defaults as $field) {
            if (!isset($collected[$field['target']])) {
                $collected[$field['target']] = $field;
            }
        }

        $out = array_values($collected);
        usort($out, [__CLASS__, 'sortFields']);
        return $out;
    }

    private static function normalizeBooksFieldResponse(array $body, $entity) {
        $rows = [];
        foreach (['fields', 'customfields', 'custom_fields'] as $key) {
            if (!empty($body[$key]) && is_array($body[$key])) {
                $rows = array_merge($rows, $body[$key]);
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $customfield_id = trim((string) ($row['customfield_id'] ?? $row['field_id'] ?? ''));
            $api_name = trim((string) ($row['api_name'] ?? $row['field_name'] ?? ''));
            $label = trim((string) ($row['label'] ?? $row['display_name'] ?? $row['field_label'] ?? $api_name));
            $is_custom = !empty($row['is_customfield']) || !empty($row['customfield_id']) || !empty($row['is_custom_field']);

            if ($customfield_id !== '') {
                $target = 'customfield:' . $customfield_id;
            } else {
                $target = $api_name;
            }

            if ($target === '') {
                continue;
            }

            $out[] = [
                'label' => $label !== '' ? $label : $target,
                'target' => $target,
                'api_name' => $api_name,
                'type' => (string) ($row['data_type'] ?? $row['type'] ?? ''),
                'custom' => $is_custom,
                'customfield_id' => $customfield_id,
                'entity' => $entity,
            ];
        }

        return $out;
    }

    private static function defaultBooksContactFields() {
        return [
            ['label' => 'Contact Name', 'target' => 'contact_name', 'api_name' => 'contact_name', 'type' => 'text', 'custom' => false],
            ['label' => 'Company Name', 'target' => 'company_name', 'api_name' => 'company_name', 'type' => 'text', 'custom' => false],
            ['label' => 'Email', 'target' => 'email', 'api_name' => 'email', 'type' => 'email', 'custom' => false],
            ['label' => 'Phone', 'target' => 'phone', 'api_name' => 'phone', 'type' => 'text', 'custom' => false],
            ['label' => 'Website', 'target' => 'website', 'api_name' => 'website', 'type' => 'text', 'custom' => false],
            ['label' => 'Notes', 'target' => 'notes', 'api_name' => 'notes', 'type' => 'textarea', 'custom' => false],
            ['label' => 'Billing Address', 'target' => 'billing_address.address', 'api_name' => 'billing_address.address', 'type' => 'text', 'custom' => false],
            ['label' => 'Billing City', 'target' => 'billing_address.city', 'api_name' => 'billing_address.city', 'type' => 'text', 'custom' => false],
            ['label' => 'Billing State', 'target' => 'billing_address.state', 'api_name' => 'billing_address.state', 'type' => 'text', 'custom' => false],
            ['label' => 'Billing Postcode', 'target' => 'billing_address.zip', 'api_name' => 'billing_address.zip', 'type' => 'text', 'custom' => false],
            ['label' => 'Billing Country', 'target' => 'billing_address.country', 'api_name' => 'billing_address.country', 'type' => 'text', 'custom' => false],
            ['label' => 'Shipping Attention', 'target' => 'shipping_address.attention', 'api_name' => 'shipping_address.attention', 'type' => 'text', 'custom' => false],
            ['label' => 'Shipping Address', 'target' => 'shipping_address.address', 'api_name' => 'shipping_address.address', 'type' => 'text', 'custom' => false],
            ['label' => 'Shipping City', 'target' => 'shipping_address.city', 'api_name' => 'shipping_address.city', 'type' => 'text', 'custom' => false],
            ['label' => 'Shipping State', 'target' => 'shipping_address.state', 'api_name' => 'shipping_address.state', 'type' => 'text', 'custom' => false],
            ['label' => 'Shipping Postcode', 'target' => 'shipping_address.zip', 'api_name' => 'shipping_address.zip', 'type' => 'text', 'custom' => false],
            ['label' => 'Shipping Country', 'target' => 'shipping_address.country', 'api_name' => 'shipping_address.country', 'type' => 'text', 'custom' => false],
        ];
    }

    private static function defaultCRMFields($module) {
        $module = trim((string) $module);
        $common = [
            ['label' => 'First Name', 'target' => 'First_Name', 'api_name' => 'First_Name', 'type' => 'text', 'custom' => false, 'read_only' => false],
            ['label' => 'Last Name', 'target' => 'Last_Name', 'api_name' => 'Last_Name', 'type' => 'text', 'custom' => false, 'read_only' => false],
            ['label' => 'Email', 'target' => 'Email', 'api_name' => 'Email', 'type' => 'email', 'custom' => false, 'read_only' => false],
            ['label' => 'Phone', 'target' => 'Phone', 'api_name' => 'Phone', 'type' => 'text', 'custom' => false, 'read_only' => false],
            ['label' => 'Description', 'target' => 'Description', 'api_name' => 'Description', 'type' => 'textarea', 'custom' => false, 'read_only' => false],
        ];

        if (strcasecmp($module, 'Leads') === 0) {
            $common[] = ['label' => 'Lead Source', 'target' => 'Lead_Source', 'api_name' => 'Lead_Source', 'type' => 'picklist', 'custom' => false, 'read_only' => false];
            $common[] = ['label' => 'Company', 'target' => 'Company', 'api_name' => 'Company', 'type' => 'text', 'custom' => false, 'read_only' => false];
        }

        usort($common, [__CLASS__, 'sortFields']);
        return $common;
    }

    private static function defaultBooksDocumentFields($entity) {
        return [
            ['label' => ucfirst($entity) . ' Reference Number', 'target' => 'reference_number', 'api_name' => 'reference_number', 'type' => 'text', 'custom' => false],
            ['label' => ucfirst($entity) . ' Notes', 'target' => 'notes', 'api_name' => 'notes', 'type' => 'textarea', 'custom' => false],
        ];
    }

    private static function indexFieldsByTarget(array $fields) {
        $out = [];
        foreach ($fields as $field) {
            if (!empty($field['target'])) {
                $out[$field['target']] = $field;
            }
        }

        return $out;
    }

    private static function applyBooksCustomField(array &$payload, $customfield_id, $value) {
        if (empty($payload['custom_fields']) || !is_array($payload['custom_fields'])) {
            $payload['custom_fields'] = [];
        }

        foreach ($payload['custom_fields'] as &$row) {
            if (($row['customfield_id'] ?? '') === $customfield_id) {
                $row['value'] = $value;
                return;
            }
        }
        unset($row);

        $payload['custom_fields'][] = [
            'customfield_id' => $customfield_id,
            'value' => $value,
        ];
    }

    private static function applyBooksStandardField(array &$payload, $target, $value) {
        if (strpos($target, '.') !== false) {
            $parts = explode('.', $target);
            $cursor = &$payload;
            foreach ($parts as $part) {
                if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                    $cursor[$part] = [];
                }
                $cursor = &$cursor[$part];
            }
            $cursor = $value;
            return;
        }

        $payload[$target] = $value;
    }

    private static function sortFields(array $a, array $b) {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    }

    private static function buildCRMFieldErrorMessage($module, array $response) {
        $code = (int) ($response['code'] ?? 0);
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : [];
        $details = self::extractErrorDetail($body);
        $message = 'Unable to fetch Zoho CRM fields for module ' . $module;

        if ($code > 0) {
            $message .= ' (HTTP ' . $code . ')';
        }

        if ($details !== '') {
            $message .= ': ' . $details;
        } else {
            $message .= '.';
        }

        return $message;
    }

    private static function extractErrorDetail(array $body) {
        $candidates = [
            $body['message'] ?? '',
            $body['code'] ?? '',
        ];

        if (!empty($body['details']) && is_array($body['details'])) {
            foreach ($body['details'] as $key => $value) {
                if (is_scalar($value) && $value !== '') {
                    $candidates[] = $key . ': ' . $value;
                }
            }
        }

        if (!empty($body['data'][0]) && is_array($body['data'][0])) {
            foreach (['message', 'code', 'details'] as $key) {
                if (empty($body['data'][0][$key])) {
                    continue;
                }

                if (is_array($body['data'][0][$key])) {
                    $candidates[] = wp_json_encode($body['data'][0][$key]);
                } else {
                    $candidates[] = (string) $body['data'][0][$key];
                }
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return self::truncateMessage($candidate, 220);
            }
        }

        $json = wp_json_encode($body);
        if (!empty($json) && $json !== '[]' && $json !== '{}') {
            return self::truncateMessage($json, 220);
        }

        return '';
    }

    private static function truncateMessage($message, $limit) {
        $message = trim((string) $message);
        if (strlen($message) <= $limit) {
            return $message;
        }

        return rtrim(substr($message, 0, $limit - 3)) . '...';
    }
}

PBSR_Zoho_Field_Manager::init();
