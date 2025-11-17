<?php

if (!defined('ABSPATH')) exit;



class PBSR_Logger {

    public static function write($source, $key, $payload, $crm_status=null, $crm_resp=null, $books_status=null, $books_resp=null, $retry=0) {

        global $wpdb; $table = $wpdb->prefix . 'pbsr_logs';

        $wpdb->insert($table, [

            'created_at'      => current_time('mysql'),

            'source'          => $source,

            'idempotency_key' => $key,

            'payload'         => wp_json_encode($payload),

            'crm_status'      => $crm_status,

            'crm_response'    => is_string($crm_resp) ? $crm_resp : wp_json_encode($crm_resp),

            'books_status'    => $books_status,

            'books_response'  => is_string($books_resp) ? $books_resp : wp_json_encode($books_resp),

            'retry_count'     => $retry,

        ]);

    }



    public static function updateByKey($key, $fields) {

        global $wpdb; $table = $wpdb->prefix . 'pbsr_logs';

        return $wpdb->update($table, $fields, ['idempotency_key' => $key]);

    }



    public static function existsKey($key) {

        global $wpdb; $table = $wpdb->prefix . 'pbsr_logs';

        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE idempotency_key=%s", $key));

    }
	
	public static function recent( $limit = 100 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'pbsr_logs';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, created_at AS time, source, idempotency_key AS `key`,
                    crm_status, books_status, payload
             FROM {$table}
             ORDER BY id DESC
             LIMIT %d",
            $limit
        ),
        ARRAY_A
    );

    foreach ( $rows as &$row ) {
        $decoded = json_decode( $row['payload'], true );
        $row['data'] = is_array( $decoded ) ? $decoded : $row['payload'];
        unset( $row['payload'] );
    }

    return $rows ?: [];
}


}

