<?php
require_once __DIR__ . '/utils.php';
$token_file = __DIR__ . '/auth_token.json';

$AUTH_TOKEN = getAuthToken($token_file);

$timestamp = date('Y-m-d');
$encodedTimestamp = urlencode($timestamp);

$lead_file = __DIR__ . '/processed_leads.txt';
$processed_leads = getProcessedLeads($lead_file);

$whatsapp_leads = fetchLeads('whatsapp', $encodedTimestamp, $AUTH_TOKEN)['whatsapp'];

// Process WhatsApp leads
if ($whatsapp_leads) {
    foreach ($whatsapp_leads as $lead) {
        $id = $lead['id'];

        if (in_array($id, $processed_leads)) {
            echo "Duplicate Lead Skipped: $id\n";
            continue;
        }

        $property_reference = $lead['property_reference'];
        $agent_name = $lead['user']['public']['first_name'] . ' ' . $lead['user']['public']['last_name'];
        $agent_phone = $lead['user']['public']['phone'];
        $agent_email = $lead['user']['public']['email'];
        $client_phone = $lead['phone'];

        $existing_contact = checkExistingContact(['PHONE' => $client_phone]);

        $fields = [
            'TITLE' => "Property Finder WhatsApp - $property_reference",
            'ufCrm12_1729224965617' => 'Property Finder - WhatsApp', // Mode of enquiry
            'sourceId' => '41', // Source
        ];

        $agent_id = determineAgentId($agent_email) ?? 1043;
        $fields['assignedById'] = $agent_id;

        if (!$existing_contact) {
            $contact_id = createContact([
                'NAME' => $agent_name,
                'PHONE' => [
                    [
                        'VALUE' => $client_phone,
                        'VALUE_TYPE' => 'WORK'
                    ]
                ],
                'ASSIGNED_BY_ID' => $agent_id,
                'CREATED_BY_ID' => $agent_id
            ]);

            if ($contact_id) {
                $fields['contactId'] = $contact_id;
            }
        } else {
            $fields['contactId'] = $existing_contact;
        }

        logData('fields.log', print_r($fields, true));

        $new_lead_id = createBitrixLead(1042, $fields);
        echo "New Lead Created: $new_lead_id\n";

        saveProcessedLead($lead_file, $id);
    }
}
