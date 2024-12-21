<?php
require_once __DIR__ . '/crest/crest.php';

function makeApiRequest(string $url, array $headers)
{
    // Validate the URL before making the request
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        logData('error.log', "Invalid URL: $url");
        throw new Exception("Invalid URL: $url");
    }

    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the response
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Execute cURL request
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        $error_message = "cURL error: " . curl_error($ch);
        logData('error.log', $error_message);  // Log error message to a file
        throw new Exception($error_message);
    }

    // Check the HTTP status code of the response
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        $error_message = "HTTP error: $httpCode - Response: $response";
        logData('error.log', $error_message);  // Log HTTP error
        throw new Exception($error_message);
    }

    // Separate headers and body
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $header_size);

    curl_close($ch);

    // Decode the JSON response
    $data = json_decode($body, true);

    // Check if JSON decoding was successful
    if ($data === null) {
        $json_error_message = "JSON Decoding Error: " . json_last_error_msg();
        logData('error.log', $json_error_message);  // Log JSON decoding error
        throw new Exception($json_error_message);
    }

    // Return the decoded data
    return $data;
}

function logData(string $filename, string $message)
{
    date_default_timezone_set('Asia/Kolkata');

    $logFile = __DIR__ . '/logs/' . $filename;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

function fetchLeads(string $type, string $date, string $authToken)
{
    // $url = "https://www.bayut.com/api-v7/stats/website-client-leads?type=$type&timestamp=$timestamp";
    $url = "https://api-v2.mycrm.com/$type-leads?filters[date][from]=$date";

    try {
        $data = makeApiRequest($url, [
            'Content-Type: application/json',
            "Authorization: Bearer $authToken",
            "X-MyCRM-Expand-Data: true"
        ]);

        if (empty($data)) {
            echo "No new leads available.\n";
            return null;
        }


        return $data;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

function createBitrixLead($entityTypeId, $fields)
{
    $response = CRest::call('crm.item.add', [
        'entityTypeId' => $entityTypeId,
        'fields' => $fields
    ]);

    return $response['result']['item']['id'];
}

function checkExistingContact($filter = [])
{
    $response = CRest::call('crm.contact.list', [
        'filter' => $filter,
        'select' => ['ID', 'EMAIL']
    ]);

    if (isset($response['result']) && $response['total'] > 0) {
        // Check if we have a valid ID and return it
        if (isset($response['result'][0]['ID'])) {
            return $response['result'][0]['ID'];
        }
    }

    return null;
}

function createContact($fields)
{
    $response = CRest::call('crm.contact.add', [
        'fields' => $fields
    ]);

    return $response['result'];
}

function getListingOwner($property_reference)
{
    $response = CRest::call('crm.item.list', [
        'entityTypeId' => 1046,
        'filter' => [
            '%ufCrm13ReferenceNumber' => $property_reference
        ],
        'select' => [
            'ufCrm13ListingOwner',
            'ufCrm13ReferenceNumber'
        ]
    ]);


    if ($response['total'] > 0 && isset($response['result']['items'][0]['ufCrm13ListingOwner'])) {

        return trim($response['result']['items'][0]['ufCrm13ListingOwner']);
    }

    return null;
}

function getUser($filter = [])
{
    $response = CRest::call('user.get', [
        'filter' => $filter,
        'select' => ['ID', 'NAME']
    ]);

    if (isset($response['result']) && $response['total'] > 0) {
        if (isset($response['result'][0]['ID'])) {
            return $response['result'][0]['ID'];
        }
    }

    return null;
}

function getProcessedLeads($file)
{
    if (file_exists($file)) {
        return file($file, FILE_IGNORE_NEW_LINES);
    }

    return [];
}

function saveProcessedLead($file, $lead_id)
{
    file_put_contents($file, $lead_id . PHP_EOL, FILE_APPEND);
}

function determineAgentId($agent_email)
{
    $agent_id = !empty($agent_email) ? getUser(['%EMAIL' => $agent_email]) : 1043;
    return ($agent_id == 433) ? 1043 : $agent_id;
}

function getAuthToken($token_file) {
    if (file_exists($token_file)) {
        $token_data = json_decode(file_get_contents($token_file), true);
        if ($token_data && time() < $token_data['expires_at']) {
            return $token_data['access_token'];
        }
    }

    // If token is expired or missing, fetch a new one
    return getNewAuthToken($token_file);
}

function getNewAuthToken($token_file) {
    $api_url = "https://auth.propertyfinder.com/auth/oauth/v1/token";
    $authorization = "Basic dENMWWguRWZ0RGZNcmJ0ZXhmRWF6S3VWTmtINUJXUkpDZmZuVDMzcTo0MTJiN2FmNjcwMmUxZjA1OTUxODQyNDI0MmMxMzc3OQ==";
    
    // Prepare the request payload
    $data = [
        "scope" => "openid",
        "grant_type" => "client_credentials"
    ];
    
    // Initialize cURL
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: $authorization",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    // Execute the request
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status !== 200) {
        error_log("Failed to fetch token. HTTP Status: $http_status, Response: $response");
        return null;
    }
    
    // Decode and store the token
    $response_data = json_decode($response, true);
    if (isset($response_data['access_token'])) {
        $response_data['expires_at'] = time() + $response_data['expires_in'];
        file_put_contents($token_file, json_encode($response_data));
        return $response_data['access_token'];
    } else {
        error_log("Invalid token response: $response");
        return null;
    }
}
                                                                                                              
function httpPost($url, $headers, $post_data) {
    $ch = curl_init();                                                                                          
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        die("Curl error: " . curl_error($ch));
    }
    curl_close($ch);
    return $response;
}
