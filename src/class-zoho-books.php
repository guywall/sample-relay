<?php
if (!defined('ABSPATH')) exit;

class PBSR_Zoho_Books {

    private $client;
    private $settings;

    public function __construct(PBSR_Zoho_Client $client) {
        $this->client   = $client;
        $this->settings = PBSR_Settings::get();
    }

    private function url($path) {
        $org = $this->settings['org_id'];
        return "/{$path}?organization_id={$org}";
    }

    /**
     * Find an existing customer by email, or create a new one.
     */
    public function findOrCreateCustomer(array $data) {
        $email = $data['email'] ?? '';
        $contact_id = null;

        // Try to find existing contact first (by email)
        if ($email) {
            $res  = $this->client->books_get('contacts?email=' . rawurlencode($email));
            $code = $res['code'] ?? 0;
            $body = $res['body'] ?? [];

            if ($code === 200 && !empty($body['contacts'][0]['contact_id'])) {
                $contact    = $body['contacts'][0];
                $contact_id = $contact['contact_id'];

                // Ensure correct contact type
                if (empty($contact['contact_type']) || strtolower($contact['contact_type']) !== 'customer') {
                    $update = ['contact_type' => 'customer'];
                    $ures = $this->client->books_post("contacts/{$contact_id}", $update);
                    error_log("Updated contact type for {$email} ({$contact_id}) -> " . wp_json_encode($ures));
                }

                return $contact_id;
            }
        }

        // Otherwise create new contact
        $contact_name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

        // Ensure email & phone are defined from the latest $data array
$email = $data['email'] ?? '';
$phone = $data['phone'] ?? '';

// Determine VAT treatment based on country
$country = trim(strtolower($data['country'] ?? ''));
$vat_treatment = ($country === 'united kingdom' || $country === 'uk') ? 'uk' : 'overseas';



        $payload = [
            'contact_name' => $contact_name,
            'company_name' => $data['company'] ?? '',
			'vat_treatment' => $vat_treatment,
            'billing_address' => [
                'address'  => substr($data['street'] ?? '', 0, 90),
                'city'     => $data['city'] ?? '',
                'state'    => $data['state'] ?? '',
                'zip' => $data['zip'] ?? '',
                'country'  => $data['country'] ?? '',
            ],
            'shipping_address' => [
                'attention' => substr($contact_name, 0, 50),
                'address'   => substr($data['street'] ?? '', 0, 80),
                'city'      => substr($data['city'] ?? '', 0, 50),
                'state'     => substr($data['state'] ?? '', 0, 50),
                'zip'  => substr($data['zip'] ?? '', 0, 20),
                'country'   => substr($data['country'] ?? '', 0, 50),
            ],
            //'email'        => $email,
            //'phone'        => $phone,
            'contact_type' => 'customer',
        ];
		
		// Remove empty top-level email/phone to avoid silent ignore
			if (empty($email)) unset($payload['email']);
			if (empty($phone)) unset($payload['phone']);

			// Add proper contact_persons array (Zoho Books v3 spec)
			$payload['contact_persons'] = [[
				'first_name' => $data['first_name'] ?? '',
				'last_name'  => $data['last_name'] ?? '',
				'email'      => $email,
				'phone'      => $phone,
				'is_primary_contact' => true,
			]];

        $endpoint = "contacts?organization_id=" . urlencode($this->settings['org_id']);
        $res  = $this->client->books_post($endpoint, $payload);
        $code = $res['code'] ?? 0;
        $body = $res['body'] ?? [];

        error_log("BOOKS CONTACT CREATE -> code={$code} body=" . wp_json_encode($body));

        if ($code !== 201) {
            throw new Exception(
                'Create contact failed (' . ($code ?: 'no code') . '): ' .
                (wp_json_encode($body) ?: 'no body')
            );
        }

        return $body['contact']['contact_id'] ?? null;
    }

    /**
     * Search for an item in Books by SKU.
     */
    public function searchItemBySKU($sku) {
    if (empty($sku)) return null;

    $org = $this->settings['org_id'];

    // Primary search by SKU text
    $res  = $this->client->books_get("/items?organization_id={$org}&search_text=" . rawurlencode((string)$sku));
    $code = $res['code'] ?? 0;
    $body = $res['body'] ?? [];
    error_log("PBSR Items GET #1 /items?search_text={$sku} -> {$code}");

    if ($code === 200 && !empty($body['items'])) {
        foreach ($body['items'] as $item) {
            if (!empty($item['sku']) && (string)$item['sku'] === (string)$sku) {
                error_log("PBSR Mapper: Found Zoho item by SKU {$sku} -> {$item['item_id']}");
                return $item;
            }
        }
    }

    // Fallback search by generic text
    $res2  = $this->client->books_get("/items?organization_id={$org}&search_text=" . rawurlencode('Resin Bound'));
    $code2 = $res2['code'] ?? 0;
    $body2 = $res2['body'] ?? [];
    error_log("PBSR Items GET #2 fallback -> {$code2}");

    if ($code2 === 200 && !empty($body2['items'])) {
        foreach ($body2['items'] as $item) {
            if (!empty($item['sku']) && (string)$item['sku'] === (string)$sku) {
                error_log("PBSR Mapper: Found Zoho item by fallback {$sku} -> {$item['item_id']}");
                return $item;
            }
        }
    }

    error_log("PBSR Mapper: No Zoho item found for SKU {$sku} (Codes: {$code}/{$code2})");
    return null;
}


/**
 * Create a Sales Order or Estimate document.
 */
public function createDocument(array $data, array $lines) {
    $org        = $this->settings['org_id'];
    $contact_id = $this->findOrCreateCustomer($data);

    // Fetch contact to get address IDs
$contact_info = $this->client->books_get("/contacts/{$contact_id}?organization_id={$org}");
$billing_id = $contact_info['body']['contact']['billing_address']['address_id'] ?? null;
$shipping_id = $contact_info['body']['contact']['shipping_address']['address_id'] ?? null;

$doc = [
    'customer_id'         => $contact_id,
    'reference_number'    => $data['reference'] ?? '',
    'notes'               => $data['notes'] ?? 'Sample request.',
    'billing_address_id'  => $billing_id,
    'shipping_address_id' => $shipping_id,
    'line_items'          => $lines,
];


    // Apply sample cost override if set
    $sample_cost_override = trim($this->settings['sample_cost_override'] ?? '');
    
    if ($sample_cost_override !== '' && is_numeric($sample_cost_override)) {
        $sample_cost = floatval($sample_cost_override);
        
        if ($sample_cost >= 0 && !empty($doc['line_items'])) {
            foreach ($doc['line_items'] as &$item) {
                $item['rate'] = $sample_cost;
            }
            unset($item); // Clear reference after foreach loop
            
            error_log("PBSR: Applied sample cost override of £{$sample_cost} to " . count($doc['line_items']) . " line items");
        }
    }

    $type = ($this->settings['books_doc_type'] === 'estimate') ? 'estimates' : 'salesorders';
    $res  = $this->client->books_post("/{$type}?organization_id={$org}", $doc);

    if (($res['code'] ?? 0) !== 201) {
        error_log('ZOHO BOOKS DOCUMENT ERROR: ' . wp_json_encode($res['body']));
    }

    return [$res['code'] ?? 0, wp_json_encode($res['body'] ?? [])];
}
}
