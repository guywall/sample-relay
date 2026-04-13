<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Dispatcher {
    public static function process(array $raw, $source = 'elementor', $idempotency_key = null) {
        $settings = PBSR_Settings::get();
        $allowed_raw = $settings['allowed_sources'] ?? '';
        $allowed_list = array_filter(array_map('trim', explode(',', strtolower($allowed_raw))));
        $incoming_source = strtolower(trim($raw['source'] ?? $source ?? ''));

        if ($incoming_source === '' || (!empty($allowed_list) && !in_array($incoming_source, $allowed_list, true))) {
            return [
                'ok' => true,
                'status' => 'skipped',
                'message' => 'Source not allowed.',
                'blocked_reason' => 'source_not_allowed',
            ];
        }

        $raw = self::flattenPayload($raw);
        $raw = self::normalizeSupplementalFields($raw);
        $map = $settings['field_map'];
        $data = PBSR_Mapper::canonicalize($raw, $map);
        $data['blends'] = self::resolveBlends($raw, $map);

        $hidden_samples = PBSR_Mapper::parseHiddenSamples($settings['hidden_samples'] ?? '');
        if (!empty($hidden_samples) && !empty($raw['samples']) && is_array($raw['samples'])) {
            $raw['samples'] = PBSR_Mapper::filterAvailableSamples($raw['samples'], $hidden_samples);
            $data['blends'] = array_values(array_filter(array_map(function ($sample) {
                return trim((string) ($sample['name'] ?? ''));
            }, $raw['samples'])));
        }

        $raw['context'] = PBSR_Attribution::enrichContext($raw['context'] ?? []);
        $raw['source'] = $incoming_source;
        $raw['email'] = $data['email'] ?? '';
        $raw['street'] = $raw['street'] ?? ($data['street'] ?? '');
        $raw['postcode'] = $raw['postcode'] ?? ($data['zip'] ?? '');

        $key = $idempotency_key ?: md5(wp_json_encode([
            $incoming_source,
            $data['email'] ?? '',
            $data['blends'] ?? [],
            $data['reference'] ?? '',
            $raw['street'] ?? '',
            time() - (time() % 3600),
        ]));

        if (PBSR_Logger::existsKey($key)) {
            PBSR_Logger::incrementRetryCount($key);

            return [
                'ok' => true,
                'status' => 'duplicate',
                'message' => 'This request has already been received.',
                'key' => $key,
            ];
        }

        $repeat_limit_days = max(1, (int) ($settings['repeat_limit_days'] ?? 30));
        $household_key = PBSR_Logger::buildHouseholdKey($raw['street'] ?? '', $raw['postcode'] ?? '');
        $recent_match = PBSR_Logger::findRecentAcceptedMatch($data['email'] ?? '', $household_key, $repeat_limit_days);

        if (!empty($recent_match)) {
            $result = [
                'ok' => true,
                'status' => 'blocked',
                'message' => self::repeatLimitMessage($repeat_limit_days),
                'blocked_reason' => 'repeat_limit',
                'key' => $key,
                'crm_status' => 'skipped',
                'books_status' => 'skipped',
            ];

            PBSR_Logger::write($incoming_source, $key, self::buildLogPayload($raw, $data), 'skipped', null, 'skipped', null, 0, 'blocked', 'repeat_limit');
            PBSR_Emailer::sendAdminNotification($settings, $data, $raw, $result);

            return $result;
        }

        $crm_status = 'skipped';
        $books_status = 'skipped';
        $crm_resp = null;
        $books_resp = null;

        $client = new PBSR_Zoho_Client();
        $books = new PBSR_Zoho_Books($client);
        $crm = new PBSR_Zoho_CRM($client);

        try {
            $line_items = PBSR_Mapper::blendsToLineItemsFromSamples($raw['samples'] ?? [], $books);

            if (!empty($settings['enable_books'])) {
                [$bcode, $bbody] = $books->createDocument($data, $line_items);
                $books_status = (string) $bcode;
                $books_resp = $bbody;
            }

            if (!empty($settings['enable_crm'])) {
                [$ccode, $cbody] = $crm->upsertPerson($data);
                $crm_status = (string) $ccode;
                $crm_resp = $cbody;

                $decoded = json_decode((string) $cbody, true);
                $module = $settings['crm_module'] ?: 'Contacts';
                $record_id = $decoded['data'][0]['details']['id'] ?? null;

                if ($record_id) {
                    $note = self::buildCrmNote($data, $raw);
                    $crm->addNote($module, $record_id, $note);
                }
            }

            $result = [
                'ok' => true,
                'status' => 'accepted',
                'message' => 'Sample request received.',
                'key' => $key,
                'crm_status' => $crm_status,
                'books_status' => $books_status,
            ];

            PBSR_Logger::write($incoming_source, $key, self::buildLogPayload($raw, $data), $crm_status, $crm_resp, $books_status, $books_resp, 0, 'accepted');
            PBSR_Emailer::sendAdminNotification($settings, $data, $raw, $result);
            PBSR_Emailer::sendRequesterConfirmation($data, $raw);

            return $result;
        } catch (Throwable $e) {
            PBSR_Logger::write($incoming_source, $key, self::buildLogPayload($raw, $data), $crm_status, $crm_resp, 'error', $e->getMessage(), 0, 'skipped', 'internal_error');

            return [
                'ok' => false,
                'status' => 'skipped',
                'message' => 'Relay processing failed.',
                'blocked_reason' => 'internal_error',
                'key' => $key,
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function flattenPayload(array $raw) {
        if (isset($raw['contact']) && is_array($raw['contact'])) {
            foreach ($raw['contact'] as $key => $value) {
                if (!isset($raw[$key]) || $raw[$key] === '') {
                    $raw[$key] = $value;
                }
            }
        }

        if (isset($raw['shipping']) && is_array($raw['shipping'])) {
            foreach ($raw['shipping'] as $key => $value) {
                if (!isset($raw[$key]) || $raw[$key] === '') {
                    $raw[$key] = $value;
                }
            }
        }

        return $raw;
    }

    private static function normalizeSupplementalFields(array $raw) {
        $project_type = self::extractFirstNonEmptyValue($raw, [
            ['project_type'],
            ['project_type[]'],
            ['project_types'],
            ['project_type_serialized'],
            ['project_type_value'],
            ['contact', 'project_type'],
            ['contact', 'project_types'],
            ['context', 'project_type'],
            ['context', 'project_types'],
        ]);

        $project_size = self::extractFirstNonEmptyValue($raw, [
            ['project_size_m2'],
            ['project_size'],
            ['project_size_value'],
            ['contact', 'project_size_m2'],
            ['contact', 'project_size'],
            ['context', 'project_size_m2'],
            ['context', 'project_size'],
        ]);

        $raw['project_type'] = self::normalizeProjectType($project_type);
        $raw['project_size_m2'] = self::normalizeProjectSize($project_size);

        return $raw;
    }

    private static function resolveBlends(array $raw, array $map) {
        $blends = $raw[$map['blends']] ?? $raw['blends'] ?? $raw['sample_names'] ?? [];

        if (is_string($blends)) {
            $blends = array_filter(array_map('trim', preg_split('/[,;\n]+/', $blends)));
        }

        return array_values(array_filter((array) $blends));
    }

    private static function buildLogPayload(array $raw, array $data) {
        $raw['context'] = PBSR_Attribution::enrichContext($raw['context'] ?? []);
        $raw['blends'] = $data['blends'] ?? [];
        $raw['email'] = $data['email'] ?? ($raw['email'] ?? '');

        return $raw;
    }

    private static function extractFirstNonEmptyValue(array $source, array $paths) {
        foreach ($paths as $path) {
            $value = self::valueAtPath($source, $path);
            if ($value === null) {
                continue;
            }

            if (is_array($value) && !empty(array_filter($value, function ($item) {
                return $item !== null && $item !== '';
            }))) {
                return $value;
            }

            if (!is_array($value) && trim((string) $value) !== '') {
                return $value;
            }
        }

        foreach ($paths as $path) {
            $value = self::valueAtPath($source, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function valueAtPath(array $source, array $path) {
        $value = $source;

        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private static function normalizeProjectType($value) {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,;|]+/', $value);
        }

        $allowed = ['Path/Patio', 'Driveway', 'Other'];
        $values = array_map('sanitize_text_field', (array) $value);

        return array_values(array_intersect($allowed, $values));
    }

    private static function normalizeProjectSize($value) {
        if (is_array($value)) {
            $value = reset($value);
        }

        $value = sanitize_text_field((string) $value);

        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            return '';
        }

        return (int) $value;
    }

    private static function repeatLimitMessage($days) {
        $site_url = home_url('/');

        return sprintf(
            'We limit sample requests to one delivery per household every %d days. If you need help, please contact us via %s.',
            (int) $days,
            $site_url
        );
    }

    private static function buildCrmNote(array $data, array $raw) {
        $blends = array_values(array_filter($data['blends'] ?? []));
        $project_type = array_values(array_filter((array) ($raw['project_type'] ?? [])));
        $project_size = $raw['project_size_m2'] ?? '';

        $note = [];
        $note[] = 'Sample request blends:';
        $note[] = !empty($blends) ? '- ' . implode("\n- ", $blends) : '- None supplied';

        if (!empty($project_type)) {
            $note[] = '';
            $note[] = 'Project Type: ' . implode(', ', $project_type);
        }

        if ($project_size !== '' && $project_size !== null) {
            $note[] = 'Project Size (m2): ' . $project_size;
        }

        $note[] = '';
        $note[] = 'Notes: ' . ($data['notes'] ?? '');

        return implode("\n", $note);
    }
}
