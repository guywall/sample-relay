<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Form_Fields {
    private static $core_keys = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'street',
        'city',
        'county',
        'country',
        'postcode',
        'enquiry_type',
        'gdpr_consent',
    ];

    private static $computed_keys = [
        'full_name',
    ];

    public static function getDefinitions() {
        $settings = PBSR_Settings::get();
        $saved = isset($settings['form_fields']) && is_array($settings['form_fields']) ? $settings['form_fields'] : [];
        return self::mergeWithDefaults($saved);
    }

    public static function getByKey($key) {
        foreach (self::getDefinitions() as $field) {
            if (($field['key'] ?? '') === $key) {
                return $field;
            }
        }

        return null;
    }

    public static function getCoreKeys() {
        return self::$core_keys;
    }

    public static function getFieldKeys() {
        return array_values(array_map(function ($field) {
            return $field['key'];
        }, self::getDefinitions()));
    }

    public static function sanitizeDefinitions($submitted) {
        $submitted = is_array($submitted) ? $submitted : [];
        $defaults = self::defaultDefinitions();
        $defaults_by_key = [];
        foreach ($defaults as $field) {
            $defaults_by_key[$field['key']] = $field;
        }

        $sanitized = [];
        $seen = [];
        foreach ($submitted as $row) {
            $row = is_array($row) ? $row : [];
            $key = sanitize_key($row['key'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $base = $defaults_by_key[$key] ?? self::blankDefinition($key);
            $field = self::sanitizeDefinition($row, $base);
            $sanitized[] = $field;
            $seen[$key] = true;
        }

        foreach ($defaults as $default) {
            if (!empty($default['locked']) && !isset($seen[$default['key']])) {
                $sanitized[] = $default;
            }
        }

        return self::mergeWithDefaults($sanitized);
    }

    public static function sanitizeMappingRules($submitted, array $definitions) {
        $submitted = is_array($submitted) ? $submitted : [];
        $valid_keys = [];
        foreach ($definitions as $field) {
            $valid_keys[$field['key']] = true;
        }

        $out = [];
        foreach ($submitted as $field_key => $row) {
            $field_key = sanitize_key($field_key);
            if ($field_key === '' || !isset($valid_keys[$field_key])) {
                continue;
            }

            $row = is_array($row) ? $row : [];
            $out[$field_key] = [
                'enabled' => empty($row['enabled']) ? 0 : 1,
                'crm' => sanitize_text_field($row['crm'] ?? ''),
                'books_contact' => sanitize_text_field($row['books_contact'] ?? ''),
                'books_document' => sanitize_text_field($row['books_document'] ?? ''),
            ];
        }

        return $out;
    }

    public static function renderFields() {
        $html = '';
        foreach (self::getDefinitions() as $field) {
            if (($field['type'] ?? '') === 'hidden' || !empty($field['hidden'])) {
                $html .= self::renderField($field, true);
                continue;
            }

            $html .= self::renderField($field, false);
        }

        return $html;
    }

    public static function definitionsForJs() {
        $defs = [];
        foreach (self::getDefinitions() as $field) {
            $defs[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'hidden' => !empty($field['hidden']) || ($field['type'] === 'hidden'),
                'options' => self::normalizeOptionsForJs($field['options'] ?? []),
                'condition_field' => $field['condition_field'] ?? '',
                'condition_operator' => $field['condition_operator'] ?? '',
                'condition_value' => $field['condition_value'] ?? '',
            ];
        }

        return $defs;
    }

    public static function collectSubmission(array $source) {
        $definitions = self::getDefinitions();
        $values = [];
        foreach ($definitions as $field) {
            $key = $field['key'];
            $values[$key] = self::extractSubmittedValue($field, $source);
        }

        $values = self::applyComputedValues($values, $definitions);

        $errors = [];
        foreach ($definitions as $field) {
            if (!self::shouldValidateField($field, $values)) {
                continue;
            }

            $value = $values[$field['key']] ?? '';
            if (!empty($field['required']) && self::isEmptyValue($field, $value)) {
                $errors[$field['key']] = sprintf('%s is required.', $field['label']);
            }
        }

        return [
            'values' => $values,
            'errors' => $errors,
            'display' => self::buildDisplayValues($values, $definitions),
        ];
    }

    public static function extractValuesFromRaw(array $raw) {
        $values = [];
        $definitions = self::getDefinitions();
        foreach ($definitions as $field) {
            $key = $field['key'];
            if (array_key_exists($key, $raw)) {
                $values[$key] = self::sanitizeValueByType($field, $raw[$key]);
            }
        }

        return self::applyComputedValues($values, $definitions);
    }

    public static function buildDisplayValues(array $values, array $definitions = null) {
        $definitions = is_array($definitions) ? $definitions : self::getDefinitions();
        $out = [];
        foreach ($definitions as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $values)) {
                continue;
            }

            if (!self::shouldDisplayField($field, $values)) {
                continue;
            }

            $formatted = self::formatDisplayValue($field, $values[$key]);
            if ($formatted === '') {
                continue;
            }

            $out[$key] = [
                'label' => $field['label'],
                'value' => $formatted,
                'core' => in_array($key, self::$core_keys, true),
                'hidden' => !empty($field['hidden']) || ($field['type'] === 'hidden'),
            ];
        }

        return $out;
    }

    public static function extraDisplayValues(array $values) {
        return array_filter(self::buildDisplayValues($values), function ($row) {
            return empty($row['core']) && empty($row['hidden']);
        });
    }

    public static function prepareMappedValue($value) {
        if (is_array($value)) {
            $value = array_values(array_filter(array_map('trim', $value), function ($item) {
                return $item !== '';
            }));
            return implode(', ', $value);
        }

        if (is_bool($value)) {
            return $value;
        }

        return trim((string) $value);
    }

    private static function defaultDefinitions() {
        return [
            [
                'key' => 'full_name',
                'label' => 'Full name',
                'type' => 'hidden',
                'required' => 0,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 1,
                'system' => 1,
            ],
            [
                'key' => 'first_name',
                'label' => 'First name',
                'type' => 'text',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'last_name',
                'label' => 'Surname',
                'type' => 'text',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'phone',
                'label' => 'Phone',
                'type' => 'tel',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'enquiry_type',
                'label' => 'Enquiry type',
                'type' => 'select',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [
                    ['value' => '', 'label' => 'Please select'],
                    ['value' => 'homeowner', 'label' => 'Homeowner'],
                    ['value' => 'contractor_installer', 'label' => 'Contractor/Installer'],
                    ['value' => 'merchant_reseller', 'label' => 'Merchant/Reseller'],
                    ['value' => 'local_authority', 'label' => 'Local Authority'],
                    ['value' => 'other', 'label' => 'Other'],
                ],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'organisation_name',
                'label' => 'Organisation name',
                'type' => 'text',
                'required' => 0,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => 'enquiry_type',
                'condition_operator' => 'not_equals',
                'condition_value' => 'homeowner',
                'locked' => 0,
                'hidden' => 0,
            ],
            [
                'key' => 'street',
                'label' => 'Street',
                'type' => 'text',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'address_2',
                'label' => 'Address 2',
                'type' => 'text',
                'required' => 0,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 0,
                'hidden' => 0,
            ],
            [
                'key' => 'city',
                'label' => 'Town/City',
                'type' => 'text',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'county',
                'label' => 'County',
                'type' => 'text',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'country',
                'label' => 'Country',
                'type' => 'text',
                'required' => 1,
                'default_value' => 'United Kingdom',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'postcode',
                'label' => 'Postcode',
                'type' => 'text',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
            [
                'key' => 'gdpr_consent',
                'label' => 'I agree to be contacted about my sample request and understand how my data will be used.',
                'type' => 'checkbox',
                'required' => 1,
                'default_value' => '',
                'placeholder' => '',
                'help_text' => '',
                'options' => [],
                'condition_field' => '',
                'condition_operator' => '',
                'condition_value' => '',
                'locked' => 1,
                'hidden' => 0,
            ],
        ];
    }

    private static function mergeWithDefaults(array $fields) {
        $defaults = self::defaultDefinitions();
        $defaults_by_key = [];
        foreach ($defaults as $field) {
            $defaults_by_key[$field['key']] = $field;
        }

        $merged = [];
        $seen = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? '';
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $base = $defaults_by_key[$key] ?? self::blankDefinition($key);
            $merged[] = array_merge($base, $field, ['options' => self::normalizeOptions($field['options'] ?? $base['options'] ?? [])]);
            $seen[$key] = true;
        }

        foreach ($defaults as $default) {
            if (!isset($seen[$default['key']])) {
                $merged[] = $default;
            }
        }

        return array_values($merged);
    }

    private static function blankDefinition($key) {
        return [
            'key' => $key,
            'label' => ucwords(str_replace('_', ' ', $key)),
            'type' => 'text',
            'required' => 0,
            'default_value' => '',
            'placeholder' => '',
            'help_text' => '',
            'options' => [],
            'condition_field' => '',
            'condition_operator' => '',
            'condition_value' => '',
            'locked' => 0,
            'hidden' => 0,
            'system' => 0,
        ];
    }

    private static function sanitizeDefinition(array $row, array $base) {
        $field = $base;
        $allowed_types = ['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox', 'hidden', 'date', 'number', 'url'];

        $field['label'] = sanitize_text_field($row['label'] ?? $base['label']);
        $field['placeholder'] = sanitize_text_field($row['placeholder'] ?? '');
        $field['help_text'] = sanitize_text_field($row['help_text'] ?? '');
        $field['default_value'] = sanitize_text_field($row['default_value'] ?? '');
        $field['required'] = !empty($base['locked']) ? (empty($base['required']) ? 0 : 1) : (empty($row['required']) ? 0 : 1);
        $field['hidden'] = !empty($row['hidden']) ? 1 : 0;
        $field['locked'] = !empty($base['locked']) ? 1 : 0;
        $field['system'] = !empty($base['system']) ? 1 : 0;

        $type = sanitize_key($row['type'] ?? $base['type']);
        if (!in_array($type, $allowed_types, true)) {
            $type = $base['type'];
        }
        if (!empty($base['locked'])) {
            $type = $base['type'];
            $field['hidden'] = !empty($base['hidden']) ? 1 : 0;
            $field['condition_field'] = '';
            $field['condition_operator'] = '';
            $field['condition_value'] = '';
        }
        $field['type'] = $type;

        $field['options'] = self::normalizeOptions($row['options'] ?? []);
        if (!in_array($type, ['select', 'radio'], true)) {
            $field['options'] = [];
        }

        $condition_field = sanitize_key($row['condition_field'] ?? '');
        $condition_operator = sanitize_key($row['condition_operator'] ?? '');
        if (!in_array($condition_operator, ['equals', 'not_equals'], true)) {
            $condition_operator = '';
        }
        if (empty($base['locked'])) {
            $field['condition_field'] = $condition_field;
            $field['condition_operator'] = $condition_operator;
            $field['condition_value'] = sanitize_text_field($row['condition_value'] ?? '');
        }

        return $field;
    }

    private static function normalizeOptions($options) {
        if (is_string($options)) {
            $lines = preg_split('/\r\n|\r|\n/', $options);
            $parsed = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $parts = array_map('trim', explode('|', $line, 2));
                $value = sanitize_text_field($parts[0]);
                $label = sanitize_text_field($parts[1] ?? $parts[0]);
                if ($value === '') {
                    continue;
                }

                $parsed[] = ['value' => $value, 'label' => $label];
            }

            return $parsed;
        }

        $options = is_array($options) ? $options : [];
        $parsed = [];
        foreach ($options as $option) {
            if (is_array($option)) {
                $value = sanitize_text_field($option['value'] ?? '');
                $label = sanitize_text_field($option['label'] ?? $value);
            } else {
                $value = sanitize_text_field($option);
                $label = $value;
            }

            if ($value === '') {
                continue;
            }

            $parsed[] = ['value' => $value, 'label' => $label];
        }

        return $parsed;
    }

    private static function normalizeOptionsForJs(array $options) {
        $out = [];
        foreach ($options as $option) {
            $out[] = [
                'value' => (string) ($option['value'] ?? ''),
                'label' => (string) ($option['label'] ?? ''),
            ];
        }

        return $out;
    }

    private static function renderField(array $field, $force_hidden) {
        $key = esc_attr($field['key']);
        $label = esc_html($field['label']);
        $required = !empty($field['required']) ? ' required' : '';
        $placeholder = esc_attr($field['placeholder'] ?? '');
        $default = $field['default_value'] ?? '';
        $help = trim((string) ($field['help_text'] ?? ''));
        $condition_attr = sprintf(
            ' data-condition-field="%s" data-condition-operator="%s" data-condition-value="%s"',
            esc_attr($field['condition_field'] ?? ''),
            esc_attr($field['condition_operator'] ?? ''),
            esc_attr($field['condition_value'] ?? '')
        );

        if ($force_hidden) {
            return '<input type="hidden" name="' . $key . '" value="' . esc_attr($default) . '">';
        }

        $html = '<div class="pb-field pb-field--' . esc_attr($field['type']) . '"' . $condition_attr . ' data-field-key="' . $key . '">';
        if ($field['type'] !== 'checkbox') {
            $html .= '<label for="pb-' . $key . '">' . $label . '</label>';
        }

        switch ($field['type']) {
            case 'textarea':
                $html .= '<textarea id="pb-' . $key . '" name="' . $key . '"' . $required . ' placeholder="' . $placeholder . '">' . esc_textarea($default) . '</textarea>';
                break;

            case 'select':
                $html .= '<select id="pb-' . $key . '" name="' . $key . '"' . $required . '>';
                foreach ($field['options'] as $option) {
                    $html .= '<option value="' . esc_attr($option['value']) . '"' . selected((string) $default, (string) $option['value'], false) . '>' . esc_html($option['label']) . '</option>';
                }
                $html .= '</select>';
                break;

            case 'radio':
                foreach ($field['options'] as $option) {
                    $html .= '<label class="pb-choice-inline"><input type="radio" name="' . $key . '" value="' . esc_attr($option['value']) . '"' . checked($default, $option['value'], false) . $required . '> ' . esc_html($option['label']) . '</label>';
                }
                break;

            case 'checkbox':
                $html .= '<label class="pb-checkbox-label"><input type="checkbox" id="pb-' . $key . '" name="' . $key . '" value="1"' . checked($default, '1', false) . $required . '> ' . $label . '</label>';
                break;

            default:
                $type = in_array($field['type'], ['text', 'email', 'tel', 'date', 'number', 'url'], true) ? $field['type'] : 'text';
                $html .= '<input id="pb-' . $key . '" name="' . $key . '" type="' . esc_attr($type) . '" value="' . esc_attr($default) . '"' . $required . ' placeholder="' . $placeholder . '">';
                break;
        }

        if ($help !== '') {
            $html .= '<small class="pb-help">' . esc_html($help) . '</small>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function extractSubmittedValue(array $field, array $source) {
        $key = $field['key'];
        $raw = isset($source[$key]) ? wp_unslash($source[$key]) : null;

        if ($field['type'] === 'checkbox') {
            return !empty($raw);
        }

        if ($raw === null || $raw === '') {
            $raw = $field['default_value'] ?? '';
        }

        return self::sanitizeValueByType($field, $raw);
    }

    private static function sanitizeValueByType(array $field, $raw) {
        switch ($field['type']) {
            case 'email':
                return sanitize_email((string) $raw);
            case 'url':
                return esc_url_raw((string) $raw);
            case 'textarea':
                return sanitize_textarea_field((string) $raw);
            case 'number':
                return is_numeric($raw) ? (string) $raw : '';
            case 'checkbox':
                return !empty($raw);
            case 'select':
            case 'radio':
                $value = sanitize_text_field((string) $raw);
                $allowed = array_map(function ($option) {
                    return (string) ($option['value'] ?? '');
                }, $field['options'] ?? []);
                return empty($allowed) || in_array($value, $allowed, true) ? $value : '';
            case 'date':
            case 'tel':
            case 'text':
            default:
                return sanitize_text_field((string) $raw);
        }
    }

    private static function shouldValidateField(array $field, array $values) {
        if (!self::isFieldVisible($field, $values) && ($field['type'] ?? '') !== 'hidden' && empty($field['hidden'])) {
            return false;
        }

        return true;
    }

    private static function shouldDisplayField(array $field, array $values) {
        if (!self::isFieldVisible($field, $values)) {
            return false;
        }

        return true;
    }

    private static function isFieldVisible(array $field, array $values) {
        $controller = $field['condition_field'] ?? '';
        $operator = $field['condition_operator'] ?? '';
        $expected = (string) ($field['condition_value'] ?? '');

        if ($controller === '' || $operator === '') {
            return true;
        }

        $actual = isset($values[$controller]) ? self::prepareMappedValue($values[$controller]) : '';
        if ($operator === 'equals') {
            return (string) $actual === $expected;
        }

        if ($operator === 'not_equals') {
            return (string) $actual !== $expected;
        }

        return true;
    }

    private static function isEmptyValue(array $field, $value) {
        if (($field['type'] ?? '') === 'checkbox') {
            return empty($value);
        }

        if (is_array($value)) {
            return empty($value);
        }

        return trim((string) $value) === '';
    }

    private static function applyComputedValues(array $values, array $definitions) {
        $keys = [];
        foreach ($definitions as $field) {
            if (!empty($field['key'])) {
                $keys[$field['key']] = true;
            }
        }

        if (isset($keys['full_name'])) {
            $values['full_name'] = self::buildFullName($values['first_name'] ?? '', $values['last_name'] ?? '');
        }

        return $values;
    }

    private static function buildFullName($first_name, $last_name) {
        $parts = array_filter([
            trim((string) $first_name),
            trim((string) $last_name),
        ], function ($part) {
            return $part !== '';
        });

        return implode(' ', $parts);
    }

    private static function formatDisplayValue(array $field, $value) {
        if (($field['type'] ?? '') === 'checkbox') {
            return empty($value) ? '' : 'Yes';
        }

        if (($field['type'] ?? '') === 'select' || ($field['type'] ?? '') === 'radio') {
            foreach ($field['options'] ?? [] as $option) {
                if (($option['value'] ?? '') === $value) {
                    return (string) ($option['label'] ?? $value);
                }
            }
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return trim((string) $value);
    }
}
