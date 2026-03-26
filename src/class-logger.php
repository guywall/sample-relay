<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Logger {
    public static function write($source, $key, $payload, $crm_status = null, $crm_resp = null, $books_status = null, $books_resp = null, $retry = 0, $request_status = 'accepted', $blocked_reason = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'pbsr_logs';
        $meta = self::buildLogMeta($source, $payload, $request_status, $blocked_reason);

        $wpdb->insert($table, [
            'created_at' => current_time('mysql'),
            'source' => $source,
            'idempotency_key' => $key,
            'requester_email' => $meta['requester_email'],
            'household_key' => $meta['household_key'],
            'request_status' => $request_status,
            'lead_channel' => $meta['lead_channel'],
            'lead_source_detail' => $meta['lead_source_detail'],
            'blocked_reason' => $blocked_reason,
            'payload' => wp_json_encode($payload),
            'crm_status' => $crm_status,
            'crm_response' => is_string($crm_resp) ? $crm_resp : wp_json_encode($crm_resp),
            'books_status' => $books_status,
            'books_response' => is_string($books_resp) ? $books_resp : wp_json_encode($books_resp),
            'retry_count' => (int) $retry,
        ]);
    }

    public static function updateByKey($key, $fields) {
        global $wpdb;

        $table = $wpdb->prefix . 'pbsr_logs';
        return $wpdb->update($table, $fields, ['idempotency_key' => $key]);
    }

    public static function existsKey($key) {
        global $wpdb;

        $table = $wpdb->prefix . 'pbsr_logs';
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE idempotency_key = %s", $key));
    }

    public static function incrementRetryCount($key) {
        global $wpdb;

        $table = $wpdb->prefix . 'pbsr_logs';
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET retry_count = retry_count + 1
             WHERE idempotency_key = %s",
            $key
        ));
    }

    public static function findRecentAcceptedMatch($email, $household_key, $window_days) {
        global $wpdb;

        $email = self::normalizeEmail($email);
        $household_key = trim((string) $household_key);
        $window_days = max(1, (int) $window_days);
        $cutoff = wp_date('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * $window_days));

        if ($email === '' && $household_key === '') {
            return null;
        }

        $conditions = ["request_status = 'accepted'", 'created_at >= %s'];
        $params = [$cutoff];

        if ($email !== '' && $household_key !== '') {
            $conditions[] = '(requester_email = %s OR household_key = %s)';
            $params[] = $email;
            $params[] = $household_key;
        } elseif ($email !== '') {
            $conditions[] = 'requester_email = %s';
            $params[] = $email;
        } else {
            $conditions[] = 'household_key = %s';
            $params[] = $household_key;
        }

        $sql = "SELECT id, created_at, requester_email, household_key, lead_channel
                FROM {$wpdb->prefix}pbsr_logs
                WHERE " . implode(' AND ', $conditions) . '
                ORDER BY created_at DESC
                LIMIT 1';

        return $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function recent($limit = 100) {
        global $wpdb;

        $table = $wpdb->prefix . 'pbsr_logs';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, created_at AS time, source, idempotency_key AS `key`,
                        request_status, requester_email, lead_channel, lead_source_detail,
                        blocked_reason, crm_status, books_status, payload
                 FROM {$table}
                 ORDER BY id DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['payload'], true);
            $row['data'] = is_array($decoded) ? $decoded : $row['payload'];
            unset($row['payload']);
        }

        return $rows ?: [];
    }

    public static function buildLogMeta($source, array $payload, $request_status = 'accepted', $blocked_reason = '', $merge_cookie_context = true) {
        $email = self::normalizeEmail(
            $payload['email'] ??
            ($payload['contact']['email'] ?? '')
        );

        $street = $payload['street'] ?? ($payload['shipping']['street'] ?? '');
        $postcode = $payload['postcode'] ?? ($payload['zip'] ?? ($payload['shipping']['postcode'] ?? ''));
        $household_key = self::buildHouseholdKey($street, $postcode);
        $context = self::buildContextForMeta($payload['context'] ?? [], $merge_cookie_context);
        $attribution = $context['attribution'] ?? [];

        return [
            'request_status' => $request_status,
            'blocked_reason' => $blocked_reason,
            'requester_email' => $email,
            'household_key' => $household_key,
            'lead_channel' => $attribution['channel'] ?? 'Direct',
            'lead_source_detail' => $attribution['source_detail'] ?? $source,
        ];
    }

    public static function normalizeEmail($email) {
        $email = sanitize_email((string) $email);
        return strtolower(trim($email));
    }

    public static function buildHouseholdKey($street, $postcode) {
        $street = self::normalizeFragment($street);
        $postcode = self::normalizeFragment($postcode);

        if ($street === '' || $postcode === '') {
            return '';
        }

        return md5($street . '|' . $postcode);
    }

    private static function normalizeFragment($value) {
        $value = strtolower(trim((string) $value));
        return preg_replace('/[^a-z0-9]/', '', $value);
    }

    private static function buildContextForMeta($context, $merge_cookie_context) {
        $context = is_array($context) ? $context : [];
        if ($merge_cookie_context) {
            return PBSR_Attribution::enrichContext($context);
        }

        $attr = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];
        $utm = is_array($context['utm'] ?? null) ? $context['utm'] : [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'msclkid', 'fbclid', 'landing_page', 'submit_page', 'referrer'] as $key) {
            if (!empty($attr[$key])) {
                continue;
            }

            if (isset($utm[$key]) && is_string($utm[$key]) && trim($utm[$key]) !== '') {
                $attr[$key] = trim($utm[$key]);
                continue;
            }

            if (isset($context[$key]) && is_string($context[$key]) && trim($context[$key]) !== '') {
                $attr[$key] = trim($context[$key]);
            }
        }

        if (empty($attr['submit_page']) && !empty($context['page_url'])) {
            $attr['submit_page'] = trim((string) $context['page_url']);
        }

        $classification = PBSR_Attribution::classify($attr);
        $attr['channel'] = $classification['channel'];
        $attr['source_detail'] = $classification['source_detail'];
        $context['attribution'] = $attr;

        return $context;
    }
}
