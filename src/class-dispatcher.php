<?php

if (!defined('ABSPATH')) exit;



class PBSR_Dispatcher {

    public static function process(array $raw, $source='elementor', $idempotency_key=null) {
		
		        // ---- HARD GATE: only process approved sources ----
        $settings       = PBSR_Settings::get();
        $allowed_raw    = $settings['allowed_sources'] ?? '';
        $allowed_list   = array_filter(array_map('trim', explode(',', strtolower($allowed_raw))));
        // Source can be passed in the payload, or via the $source arg from a hook
        $incoming_source = strtolower(trim($raw['source'] ?? $source ?? ''));

        // If missing or not on the whitelist, skip silently (no logs, no emails, no API)
        if (empty($incoming_source) || (!empty($allowed_list) && !in_array($incoming_source, $allowed_list, true))) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'source_not_allowed'];
        }
        // ---------------------------------------------------

        $settings = PBSR_Settings::get();

        $map      = $settings['field_map'];
		
		// --- FLATTEN NESTED STRUCTURES BEFORE MAPPING ---
		if (isset($raw['contact']) && is_array($raw['contact'])) {
			foreach ($raw['contact'] as $k => $v) {
				if (!isset($raw[$k]) || $raw[$k] === '') {
					$raw[$k] = $v;
				}
			}
		}
		if (isset($raw['shipping']) && is_array($raw['shipping'])) {
			foreach ($raw['shipping'] as $k => $v) {
				if (!isset($raw[$k]) || $raw[$k] === '') {
					$raw[$k] = $v;
				}
			}
		}
		// -----------------------------------------------


        $data     = PBSR_Mapper::canonicalize($raw, $map);
		
		


        // Ensure blends is array

        $blends = $raw[$map['blends']] ?? $raw['blends'] ?? [];

        if (is_string($blends)) {

            $blends = array_filter(array_map('trim', preg_split('/[,;\n]+/', $blends)));

        }

        $data['blends'] = $blends;

        $hidden_samples = PBSR_Mapper::parseHiddenSamples($settings['hidden_samples'] ?? '');
        if (!empty($hidden_samples) && !empty($raw['samples']) && is_array($raw['samples'])) {
            $raw['samples'] = PBSR_Mapper::filterAvailableSamples($raw['samples'], $hidden_samples);
            $data['blends'] = array_values(array_map(function($sample) {
                return trim($sample['name'] ?? '');
            }, $raw['samples']));
            $data['blends'] = array_values(array_filter($data['blends']));
        }

        // Idempotency key

        $key = $idempotency_key ?: md5(wp_json_encode([$source, $data['email'] ?? '', $data['blends'] ?? [], $data['reference'] ?? '', $data['street'] ?? '', time() - (time()%3600)]));



        if (PBSR_Logger::existsKey($key)) {

            return ['ok' => true, 'message' => 'Duplicate ignored (idempotent).', 'key' => $key];

        }



        $crm_status = $books_status = 'skipped';

        $crm_resp = $books_resp = null;



        $client = new PBSR_Zoho_Client();

        $books  = new PBSR_Zoho_Books($client);

        $crm    = new PBSR_Zoho_CRM($client);



        try {

            // Build line items from blends → SKU map

			$line_items = PBSR_Mapper::blendsToLineItemsFromSamples($raw['samples'] ?? [], $books);

			error_log('LINE ITEMS: ' . wp_json_encode($line_items));

            if ($settings['enable_books']) {

                [$bcode, $bbody] = $books->createDocument($data, $line_items);

                $books_status = (string)$bcode; $books_resp = $bbody;

            }



            if ($settings['enable_crm']) {

                [$ccode, $cbody] = $crm->upsertPerson($data);

                $crm_status = (string)$ccode; $crm_resp = $cbody;



                // Try to attach a note to the first upserted record if available (best-effort)

                $decoded = json_decode($cbody, true);

                $module  = $settings['crm_module'] ?: 'Contacts';

                $rid = $decoded['data'][0]['details']['id'] ?? null;

                if ($rid) {

                    $note = "Sample request blends:\n- " . implode("\n- ", $data['blends'] ?? []) . "\n\nNotes: " . ($data['notes'] ?? '');

                    $crm->addNote($module, $rid, $note);

                }

            }

			// Optional email notification
if (!empty($settings['enable_notify']) && !empty($settings['notify_emails'])) {
    $recipients = array_map('trim', explode(',', $settings['notify_emails']));

    // Address (same pattern as pb_submit_samples)
    $street   = $raw['street']    ?? '';
    $addr2    = $raw['address_2'] ?? '';
    $city     = $raw['city']      ?? '';
    $county   = $raw['county']    ?? '';
    $country  = $raw['country']   ?? '';
    $postcode = $raw['postcode']  ?? ($data['zip'] ?? '');

    $full_address = trim(
        $street
        . ($addr2 ? ', ' . $addr2 : '')
        . ($city   ? ', ' . $city   : '')
        . ($county ? ', ' . $county : '')
        . ($country ? ', ' . $country : '')
        . ($postcode ? ' ' . $postcode : '')
    );

    // Context: page, referrer, UTM
    $context   = $raw['context'] ?? [];
    $page_url  = $context['page_url']  ?? '';
    $referrer  = $context['referrer']  ?? '';
    $utm       = $context['utm']       ?? [];
    $utm_src   = $utm['utm_source']   ?? '';
    $utm_med   = $utm['utm_medium']   ?? '';
    $utm_camp  = $utm['utm_campaign'] ?? '';

    $subject = 'New PERMABOUND Sample Request';
    $message  = "A new sample request has been received.\n\n";

    $message .= "Name: " . trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) . "\n";
    $message .= "Company: " . ($data['company'] ?? $data['organisation_name'] ?? '') . "\n";
    $message .= "Email: " . ($data['email'] ?? '') . "\n";
    $message .= "Phone: " . ($data['phone'] ?? '') . "\n";

    if ($full_address !== '') {
        $message .= "Address: {$full_address}\n";
    }

    $message .= "Blends: " . implode(', ', $data['blends'] ?? []) . "\n";

    // Same style as your original snippet
    if ($page_url) {
        $message .= "Page: {$page_url}\n";
    }
    if ($referrer) {
        $message .= "Referrer: {$referrer}\n";
    }

    // Simple UTM line if present
    if ($utm_src || $utm_med || $utm_camp) {
        $parts = [];
        if ($utm_src)  $parts[] = "source={$utm_src}";
        if ($utm_med)  $parts[] = "medium={$utm_med}";
        if ($utm_camp) $parts[] = "campaign={$utm_camp}";
        $message .= "UTM: " . implode(', ', $parts) . "\n";
    }

    $message .= "\nCRM Status: {$crm_status}\nBooks Status: {$books_status}\n\n";
    $message .= "This message was generated automatically by the Sample Relay plugin.";

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    foreach ($recipients as $to) {
        if (is_email($to)) {
            wp_mail($to, $subject, $message, $headers);
        }
    }
}

            PBSR_Logger::write($source, $key, $data, $crm_status, $crm_resp, $books_status, $books_resp, 0);

            return ['ok' => true, 'key' => $key, 'crm_status' => $crm_status, 'books_status' => $books_status];

        } catch (Exception $e) {

            PBSR_Logger::write($source, $key, $data, $crm_status, $crm_resp, 'error', $e->getMessage(), 0);

            return ['ok' => false, 'key' => $key, 'error' => $e->getMessage()];

        }

    }

}

