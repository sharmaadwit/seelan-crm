<?php
// helpers.php

// A simple encryption key (In production, move this to a secure environment variable)
define('ENCRYPTION_KEY', 'my_secure_medical_crm_key_2026'); 

function encryptPassword($plain_text) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($plain_text, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decryptPassword($encrypted_text) {
    if (!$encrypted_text) return '';
    list($encrypted_data, $iv) = explode('::', base64_decode($encrypted_text), 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
}

function generateMeetLink() {
    return "[https://meet.google.com/mock-](https://meet.google.com/mock-)" . substr(md5(uniqid()), 0, 10);
}

/**
 * Dynamically sends a Gupshup Template based on mapped variables
 */
function sendGupshupTemplate($pdo, $org_id, $event_type, $mobile, $dynamic_data, $lead_id) {
    // 1. Get Gupshup Credentials
    $stmt = $pdo->prepare("SELECT gupshup_userid, gupshup_password FROM organizations WHERE id = ?");
    $stmt->execute([$org_id]);
    $creds = $stmt->fetch();

    if (!$creds || empty($creds['gupshup_userid'])) return false;
    $password = decryptPassword($creds['gupshup_password']);

    // 2. Get Template Mapping & Body
    $stmt = $pdo->prepare("
        SELECT m.template_id, m.var_mapping, t.body, t.name 
        FROM wa_event_mappings m 
        JOIN wa_templates t ON m.template_id = t.template_id 
        WHERE m.org_id = ? AND m.event_type = ? AND t.org_id = ?
    ");
    $stmt->execute([$org_id, $event_type, $org_id]);
    $mapping = $stmt->fetch();

    if (!$mapping) return false;

    $template_id = $mapping['template_id'];
    $var_map = json_decode($mapping['var_mapping'], true);
    $final_message_body = $mapping['body'];

    // 3. Construct Variable Query String & Replace variables in body for logging
    $var_string = "";
    if (is_array($var_map)) {
        foreach ($var_map as $var_num => $data_key) {
            $value = $dynamic_data[$data_key] ?? ''; 
            $var_string .= "&var{$var_num}=" . urlencode($value);
            // Replace {{1}} with actual value for our log
            $final_message_body = str_replace("{{" . $var_num . "}}", $value, $final_message_body);
        }
    }

    // 4. Fire Request
    $base_url = "[https://media.smsgupshup.com/GatewayAPI/rest?method=SENDMESSAGE&msg_type=TEXT](https://media.smsgupshup.com/GatewayAPI/rest?method=SENDMESSAGE&msg_type=TEXT)";
    $full_url = $base_url . "&userid={$creds['gupshup_userid']}&auth_scheme=plain&password={$password}&format=text&data_encoding=TEXT&send_to={$mobile}&v=1.1&isHSM=true&template_id={$template_id}" . $var_string;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // 5. Log the message in the database
    $stmt_log = $pdo->prepare("INSERT INTO message_logs (org_id, lead_id, template_name, message_body) VALUES (?, ?, ?, ?)");
    $stmt_log->execute([$org_id, $lead_id, $mapping['name'], $final_message_body]);

    return $response;
}

/**
 * Fire configured CURL webhooks for a given event.
 *
 * $event_type examples: 'lead_created', 'appointment_booked', 'lead_converted', 'message_sent'
 * $payload: associative array of available variables for mapping.
 */
function sendCurlWebhooks(PDO $pdo, int $org_id, string $event_type, array $payload, $project_id = null, $curl_id = null): void {
    try {
        $stmt = $pdo->prepare("SELECT curl_endpoint, variable_mapping, is_active FROM curl_configs WHERE org_id = ? AND webhook_event = ? AND is_active = 1");
        $stmt->execute([$org_id, $event_type]);
        $configs = $stmt->fetchAll();
        if (!$configs) {
            return;
        }

        foreach ($configs as $cfg) {
            $endpoint = trim($cfg['curl_endpoint'] ?? '');
            if ($endpoint === '') {
                continue;
            }

            $body = $payload;
            $map_raw = trim($cfg['variable_mapping'] ?? '');
            if ($map_raw !== '') {
                $map = json_decode($map_raw, true);
                if (is_array($map) && !empty($map)) {
                    // Mapping format: { "external_key": "available_variable_key" }
                    $custom = [];
                    foreach ($map as $external_key => $source_key) {
                        if (!is_string($external_key) || $external_key === '') {
                            continue;
                        }
                        if (is_string($source_key) && array_key_exists($source_key, $payload)) {
                            $custom[$external_key] = $payload[$source_key];
                        } else {
                            $custom[$external_key] = null;
                        }
                    }
                    if (!empty($custom)) {
                        $body = $custom;
                    }
                }
            }

            $body['event_type'] = $event_type;
            $body['org_id'] = $org_id;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            // LOG THE WEBHOOK FIRING
            try {
                // If project_id (org string) is available we put it, otherwise null
                $project_id_str = null;
                // Actually the schema for curl_logs from memory was org_id, project_id (string), webhook_event, endpoint, response_code, error_text, created_at
                $stmt_log = $pdo->prepare("INSERT INTO curl_logs (org_id, project_id, webhook_event, endpoint, response_code, error_text) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_log->execute([
                    $org_id, 
                    $project_id,
                    $event_type, 
                    $endpoint, 
                    $http_code ?: null, 
                    $error ?: substr($response, 0, 255) // Fallback to store response snippet if no error but we want to log
                ]);
            } catch (Exception $logException) {
                error_log('Failed to write to curl_logs: ' . $logException->getMessage());
            }
            
            // Add file log mechanism to check if new lead came whether the curl was triggered or not
            file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . " | EVENT: {$event_type} | URL: {$endpoint} | HTTP: {$http_code} | ERR: {$error} | RESP: " . substr($response, 0, 100) . "\n", FILE_APPEND);

        }
    } catch (Exception $e) {
        error_log('sendCurlWebhooks error: ' . $e->getMessage());
    }
}

/**
 * Parses an AI SSE stream, isolates the text, and extracts a valid JSON payload.
 */
function extractBotJourneyJSON($raw_stream) {
    $final_text = '';

    // Step 1: Parse the Server-Sent Events (SSE) stream line-by-line
    $lines = explode("\n", $raw_stream);
    foreach ($lines as $line) {
        $line = trim($line);
        // Check if the line contains a data payload
        if (strpos($line, 'data: ') === 0) {
            $json_payload = substr($line, 6); // Remove "data: "
            $parsed = json_decode($json_payload, true);
            
            // Stop and grab the text when we hit the "done" event
            if (is_array($parsed) && isset($parsed['type']) && $parsed['type'] === 'done') {
                $final_text = $parsed['response'] ?? '';
                break;
            }
        }
    }

    // Fallback: If parsing the stream failed, treat the whole raw response as text
    if (empty($final_text)) {
        $final_text = $raw_stream;
    }

    // Step 2: Extract the JSON block from the AI's response text
    $extracted = '';
    
    // Strategy A: Look for your custom START/END tags
    if (preg_match('/START_JSON_JOURNEY(.*?)END_JSON_JOURNEY/s', $final_text, $matches)) {
        $extracted = trim($matches[1]);
    }
    // Strategy B: Look for standard Markdown JSON blocks (Fallback)
    // FIX: Using \x60{3} to represent triple backticks. This avoids Markdown copy/paste syntax errors!
    elseif (preg_match('/\x60{3}(?:json)?\s*(.*?)\s*\x60{3}/s', $final_text, $matches)) {
        $extracted = trim($matches[1]);
    } 
    // Strategy C: Absolute fallback, isolate the outermost curly braces { ... }
    else {
        $start = strpos($final_text, '{');
        $end = strrpos($final_text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $extracted = trim(substr($final_text, $start, $end - $start + 1));
        }
    }

    // Step 3: Validate that the extracted string is actually valid JSON
    if (!empty($extracted)) {
        json_decode($extracted);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $extracted; // Success! Return the clean JSON string.
        }
    }

    return false; // Extraction or validation failed
}
?>