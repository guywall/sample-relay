<?php
if (!defined('ABSPATH')) exit;

class PBSR_Rest_Endpoint {

    public static function init() {
        add_action('rest_api_init', function() {
            register_rest_route('pbsr/v1', '/submit', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function handle(WP_REST_Request $req) {
        $incoming = $req->get_json_params() ?: [];

        // Retrieve plugin settings
        $settings = PBSR_Settings::get();
        $allowed_raw = $settings['allowed_sources'] ?? 'permabound_sample_request';
        $allowed_sources = array_filter(array_map('trim', explode(',', strtolower($allowed_raw))));

        // Identify the source
        $source = strtolower(trim($incoming['source'] ?? $incoming['payload']['source'] ?? ''));

        // Abort immediately if source missing or not in allowed list
        if (empty($source) || !in_array($source, $allowed_sources, true)) {
            // Absolutely no logs, no dispatcher, nothing — just a clean 200 OK
            return new WP_REST_Response([
                'ok'      => true,
                'skipped' => true,
                'reason'  => 'Unrecognised or missing source: ' . ($source ?: '(none)')
            ], 200);
        }

        // If allowed, proceed normally
        error_log('PBSR endpoint accepted source: ' . $source);

        // Unwrap payload if nested
        $data = $incoming['payload'] ?? $incoming;

        // Flatten contact and shipping
        $flat = array_merge(
            $data['contact'] ?? [],
            $data['shipping'] ?? [],
            [
                'samples'   => $data['samples'] ?? [],
                'blends'    => $data['sample_names'] ?? [],
                'reference' => $data['reference'] ?? '',
                'notes'     => $data['notes'] ?? '',
                'source'    => $source,
            ]
        );

        try {
            $res = PBSR_Dispatcher::process($flat, 'rest', $data['idempotency_key'] ?? null);
            error_log('Dispatcher returned: ' . json_encode($res));
            return new WP_REST_Response($res, $res['ok'] ? 200 : 500);

        } catch (Throwable $e) {
            error_log('PBSR DISPATCHER FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_REST_Response(['error' => 'Internal error', 'details' => $e->getMessage()], 500);
        }
    }
}

PBSR_Rest_Endpoint::init();
