<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Rest_Endpoint {
    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('pbsr/v1', '/submit', [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function handle(WP_REST_Request $req) {
        $incoming = $req->get_json_params() ?: [];
        $settings = PBSR_Settings::get();
        $allowed_raw = $settings['allowed_sources'] ?? 'permabound_sample_request';
        $allowed_sources = array_filter(array_map('trim', explode(',', strtolower($allowed_raw))));
        $source = strtolower(trim($incoming['source'] ?? ($incoming['payload']['source'] ?? '')));

        if ($source === '' || !in_array($source, $allowed_sources, true)) {
            return new WP_REST_Response([
                'ok' => true,
                'status' => 'skipped',
                'message' => 'Source not allowed.',
                'blocked_reason' => 'source_not_allowed',
            ], 200);
        }

        $data = $incoming['payload'] ?? $incoming;
        $flat = array_merge(
            $data['contact'] ?? [],
            $data['shipping'] ?? [],
            [
                'samples' => $data['samples'] ?? [],
                'blends' => $data['sample_names'] ?? ($data['blends'] ?? []),
                'reference' => $data['reference'] ?? '',
                'notes' => $data['notes'] ?? '',
                'source' => $source,
                'context' => $data['context'] ?? [],
            ]
        );

        try {
            $res = PBSR_Dispatcher::process($flat, 'rest', $data['idempotency_key'] ?? null);
            return new WP_REST_Response($res, $res['ok'] ? 200 : 500);
        } catch (Throwable $e) {
            error_log('PBSR DISPATCHER FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_REST_Response([
                'ok' => false,
                'status' => 'skipped',
                'message' => 'Internal error',
                'blocked_reason' => 'internal_error',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}

PBSR_Rest_Endpoint::init();
