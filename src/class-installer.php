<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Installer {
    const DB_VERSION = '1.1.0';

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'maybeUpgrade']);
    }

    public static function activate() {
        self::upgradeSchema();
    }

    public static function maybeUpgrade() {
        if (get_option('pbsr_db_version') === self::DB_VERSION) {
            return;
        }

        self::upgradeSchema();
    }

    public static function upgradeSchema() {
        global $wpdb;

        $table = $wpdb->prefix . 'pbsr_logs';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            source VARCHAR(50) NOT NULL,
            idempotency_key VARCHAR(191) NOT NULL,
            requester_email VARCHAR(190) NULL,
            household_key VARCHAR(64) NULL,
            request_status VARCHAR(30) NULL,
            lead_channel VARCHAR(100) NULL,
            lead_source_detail VARCHAR(100) NULL,
            blocked_reason VARCHAR(100) NULL,
            payload LONGTEXT NULL,
            crm_status VARCHAR(30) NULL,
            crm_response LONGTEXT NULL,
            books_status VARCHAR(30) NULL,
            books_response LONGTEXT NULL,
            retry_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_idem (idempotency_key),
            KEY idx_request_status (request_status),
            KEY idx_requester_email (requester_email),
            KEY idx_household_key (household_key),
            KEY idx_created_status (created_at, request_status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        self::backfillMetadata();
        update_option('pbsr_db_version', self::DB_VERSION);
    }

    private static function backfillMetadata() {
        global $wpdb;

        $table = $wpdb->prefix . 'pbsr_logs';
        $last_id = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, source, payload, request_status, blocked_reason, crm_status, books_status
                     FROM {$table}
                     WHERE id > %d
                       AND (
                            request_status IS NULL
                            OR request_status = ''
                            OR requester_email IS NULL
                            OR requester_email = ''
                            OR household_key IS NULL
                            OR lead_channel IS NULL
                            OR lead_channel = ''
                       )
                     ORDER BY id ASC
                     LIMIT 250",
                    $last_id
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $payload = json_decode((string) ($row['payload'] ?? ''), true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                $status = self::inferStatus($row);
                $meta = PBSR_Logger::buildLogMeta($row['source'] ?? '', $payload, $status, $row['blocked_reason'] ?? '', false);
                $wpdb->update(
                    $table,
                    [
                        'requester_email' => $meta['requester_email'],
                        'household_key' => $meta['household_key'],
                        'request_status' => $status,
                        'lead_channel' => $meta['lead_channel'],
                        'lead_source_detail' => $meta['lead_source_detail'],
                        'blocked_reason' => $row['blocked_reason'] ?? '',
                    ],
                    ['id' => (int) $row['id']],
                    ['%s', '%s', '%s', '%s', '%s', '%s'],
                    ['%d']
                );

                $last_id = (int) $row['id'];
            }
        } while (!empty($rows));
    }

    private static function inferStatus(array $row) {
        if (!empty($row['request_status'])) {
            return (string) $row['request_status'];
        }

        if (($row['books_status'] ?? '') === 'error') {
            return 'skipped';
        }

        return 'accepted';
    }
}
