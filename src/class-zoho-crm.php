<?php
if (!defined('ABSPATH')) {
    exit;
}

class PBSR_Zoho_CRM {
    private $client;
    private $settings;

    public function __construct(PBSR_Zoho_Client $client) {
        $this->client = $client;
        $this->settings = PBSR_Settings::get();
    }

    public function upsertPerson(array $data) {
        $module = $this->settings['crm_module'] ?? 'Contacts';
        $record = [
            'Last_Name' => $data['last_name'] ?? 'Unknown',
            'First_Name' => $data['first_name'] ?? '',
            'Email' => $data['email'] ?? '',
            'Phone' => $data['phone'] ?? '',
            'Lead_Source' => 'Sample Request',
            'Description' => $data['notes'] ?? '',
        ];

        PBSR_Zoho_Field_Manager::applyCRMMappings(
            $record,
            $data['field_values'] ?? [],
            $this->settings['mapping_rules'] ?? [],
            $this->settings['zoho_field_cache']['crm'] ?? []
        );

        $payload = ['data' => [$record]];
        $res = $this->client->crm_post("/{$module}/upsert", $payload);

        return [$res['code'], wp_json_encode($res['body'])];
    }

    public function addNote($module, $record_id, $content) {
        $payload = [
            'data' => [[
                'Note_Title' => 'Sample Request',
                'Note_Content' => $content,
                'Parent_Id' => $record_id,
                'se_module' => $module,
            ]],
        ];

        $res = $this->client->crm_post('/Notes', $payload);
        return [$res['code'], wp_json_encode($res['body'])];
    }
}
