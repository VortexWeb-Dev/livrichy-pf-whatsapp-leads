<?php
require_once __DIR__ . '/utils.php';
$token_file = __DIR__ . '/auth_token.json';

$AUTH_TOKEN = getAuthToken($token_file);

$timestamp = date('Y-m-d');
$encodedTimestamp = urlencode($timestamp);

$lead_file = __DIR__ . '/processed_leads.txt';
$processed_leads = getProcessedLeads($lead_file);

// $whatsapp_leads = fetchLeads('whatsapp-leads', $encodedTimestamp, $AUTH_TOKEN)['whatsapp'];
$call_leads = fetchLeads('calltrackings', $encodedTimestamp, $AUTH_TOKEN)['call_trackings'];

logData('call-lead.log', print_r($call_leads, true));

/*
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

        logData('whatsapp-lead.log', print_r([
            'id' => $id,
            'property_reference' => $property_reference,
            'agent_name' => $agent_name,
            'agent_phone' => $agent_phone,
            'agent_email' => $agent_email,
            'client_phone' => $client_phone
        ], true));

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
*/

// Process Call leads
if ($call_leads) {
    foreach ($call_leads as $lead) {
        $id = $lead['id'];

        if (in_array($id, $processed_leads)) {
            echo "Duplicate Lead Skipped: $id\n";
            continue;
        }

        $property_reference = $lead['reference'];
        $caller_phone = $lead['phone'];
        $call_status = $lead['status'];
        $call_start = $lead['call_start'];
        $call_end = $lead['call_end'];
        $call_time = $lead['call_time'];
        $talk_time = $lead['talk_time'];
        $wait_time = $lead['wait_time'];
        $agent_email = $lead['user']['public']['email'];
        $agent_phone = $lead['user']['public']['phone'];
        $recording_url = $lead['download_url'];

        $existing_contact = checkExistingContact(['PHONE' => $caller_phone]);

        $fields = [
            'TITLE' => "Property Finder Call - $caller_phone",
            'ufCrm12_1729224965617' => 'Property Finder - Call', // Mode of enquiry
            'sourceId' => '41', // Source
            'ufCrm12CallStatus' => $call_status,
            'ufCrm12TotalDuration' => $call_time,
            'ufCrm12ConnectedDuration' => $talk_time,
            'ufCrm12CallRecordingUrl' => shortenUrl($recording_url),
            'ufCrm12CallStart' => $call_start,
            'ufCrm12CallEnd' => $call_end,
            'ufCrm12TalkTime' => $talk_time,
            'ufCrm12WaitingTime' => $wait_time
        ];

        $agent_id = determineAgentId($agent_email) ?? 1043;
        $fields['assignedById'] = $agent_id;

        if (!$existing_contact) {
            $contact_id = createContact([
                'NAME' => $caller_phone,
                'PHONE' => [
                    [
                        'VALUE' => $caller_phone,
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

        if (!empty($recording_url)) {
            $callRecordContent = file_get_contents($recording_url);

            $registerCall = registerCall([
                'USER_PHONE_INNER' => $agent_phone,
                'USER_ID' => $fields['assignedById'],
                'PHONE_NUMBER' => $caller_phone,
                'CALL_START_DATE' => $call_start,
                'CRM_CREATE' => false,
                'CRM_SOURCE' => 41,
                'CRM_ENTITY_TYPE' => 'CONTACT',
                'CRM_ENTITY_ID' => $fields['contactId'],
                'SHOW' => false,
                'TYPE' => 2,
                'LINE_NUMBER' => 'PF ' . $agent_phone,
            ]);

            $callId = $registerCall['CALL_ID'];

            if ($callId) {
                $finishCall = finishCall([
                    'CALL_ID' => $callId,
                    'USER_ID' => $fields['assignedById'],
                    'DURATION' => $talk_time,
                    'STATUS_CODE' => 200,
                ]);

                $attachRecord = attachRecord([
                    'CALL_ID' => $callId,
                    'FILENAME' => $id . '|' . uniqid('call') . '.mp3',
                    'FILE_CONTENT' => base64_encode($callRecordContent),
                ]);
            }
        }

        $new_lead_id = createBitrixLead(1042, $fields);
        echo "New Lead Created: $new_lead_id\n";

        saveProcessedLead($lead_file, $id);
    }
}