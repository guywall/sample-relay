<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PBSR_Zoho_CRM {

    private $client;
    private $settings;

    public function __construct( PBSR_Zoho_Client $client ) {
        $this->client   = $client;
        $this->settings = PBSR_Settings::get();
    }

    private function url( $path ) {
        return "/{$path}";
    }

    /**
     * Upsert or create a person/contact in Zoho CRM.
     */
    public function upsertPerson( array $data ) {
        $module = $this->settings['crm_module'] ?? 'Contacts';

        $payload = [
            'data' => [[
                'Last_Name'  => $data['last_name'] ?? 'Unknown',
                'First_Name' => $data['first_name'] ?? '',
                'Email'      => $data['email'] ?? '',
                'Phone'      => $data['phone'] ?? '',
                'Lead_Source'=> 'Sample Request',
                'Description'=> $data['notes'] ?? '',
            ]]
        ];

        $res = $this->client->crm_post( "/{$module}/upsert", $payload );
        return [ $res['code'], wp_json_encode( $res['body'] ) ];
    }

    /**
     * Add a note to a CRM record.
     */
    public function addNote( $module, $record_id, $content ) {
        $payload = [
            'data' => [[
                'Note_Title' => 'Sample Request',
                'Note_Content' => $content,
                'Parent_Id' => $record_id,
                'se_module'  => $module,
            ]]
        ];

        $res = $this->client->crm_post( "/Notes", $payload );
        return [ $res['code'], wp_json_encode( $res['body'] ) ];
    }
}
