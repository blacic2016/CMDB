<?php
// Centralized Zabbix API handler

// --- Zabbix API Configuration ---
// Ensure you have these values defined, perhaps in a central config or .env file in a real scenario
if (!defined('ZABBIX_API_URL')) {
    define('ZABBIX_API_URL', 'http://172.32.1.50/zabbix/api_jsonrpc.php');
}
if (!defined('ZABBIX_API_TOKEN')) {
    define('ZABBIX_API_TOKEN', '23c5e835efd1c26742b6848ee63b2547ce5349efb88b4ecefee83fa27683cb9a');
}

/**
 * Calls the Zabbix API with a given method and parameters.
 *
 * @param string $method The Zabbix API method to call (e.g., 'host.get').
 * @param array $params The parameters for the API method.
 * @return array The decoded JSON response from the API. Returns ['error' => message] on failure.
 */
function call_zabbix_api($method, $params) {
    $ch = curl_init(ZABBIX_API_URL);
    if ($ch === false) {
        return ['error' => 'Failed to initialize cURL.'];
    }

    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => time(), // Use timestamp for a unique ID
    ]);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . ZABBIX_API_TOKEN,
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    // Optional: for development with self-signed SSL certificates
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return ['error' => "cURL Error: " . $error];
    }
    
    if ($http_code >= 400) {
        return ['error' => "HTTP Error: Received status code " . $http_code];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Failed to decode JSON response from Zabbix API.'];
    }

    if (isset($decoded['error'])) {
        return ['error' => "Zabbix API Error: " . $decoded['error']['message'] . " - " . $decoded['error']['data']];
    }
    return $decoded;
}
