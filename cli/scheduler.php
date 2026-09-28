#!/usr/bin/env php
<?php
// PHP 7.3 compatible scheduler wrapper.
// The web application keeps using its configured PHP runtime; only this CLI file
// must run with the NAS default PHP 7.3 binary.

$baseUrl = rtrim(getenv('COMMESSE_SCHEDULER_URL') ?: 'https://gestionalerossitti.jdev.bid/test/public', '/');
$heartbeatFile = __DIR__ . '/scheduler-heartbeat.json';

function scheduler_http($method, $url, $body = null)
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, $raw === false ? '' : (string)$raw, $error];
    }

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : $body,
        'timeout' => 120,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\\s(\\d{3})\\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    return [$status, $raw === false ? '' : (string)$raw, $raw === false ? 'Nessuna risposta HTTP' : null];
}

function scheduler_read_heartbeat($file)
{
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function scheduler_write_heartbeat($file, array $data)
{
    $data['heartbeat_at'] = date('Y-m-d H:i:s');
    $data['php_version'] = PHP_VERSION;
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$previous = scheduler_read_heartbeat($heartbeatFile);
$heartbeat = $previous;
$heartbeat['state'] = 'checking';
$heartbeat['message'] = 'Verifica configurazione scheduler';
scheduler_write_heartbeat($heartbeatFile, $heartbeat);

list($settingsStatus, $settingsRaw, $settingsError) = scheduler_http('GET', $baseUrl . '/api.php?route=scheduling');
$settingsJson = $settingsRaw !== '' ? json_decode($settingsRaw, true) : null;

if ($settingsError !== null || $settingsStatus < 200 || $settingsStatus >= 300 || !is_array($settingsJson) || empty($settingsJson['ok'])) {
    $heartbeat['state'] = 'error';
    $heartbeat['message'] = $settingsError ?: ('Errore lettura scheduling HTTP ' . $settingsStatus);
    $heartbeat['last_http_code'] = $settingsStatus;
    scheduler_write_heartbeat($heartbeatFile, $heartbeat);
    fwrite(STDERR, $heartbeat['message'] . "\n");
    exit(1);
}

$settings = isset($settingsJson['data']) && is_array($settingsJson['data']) ? $settingsJson['data'] : [];
$enabled = !empty($settings['enabled']);
$interval = max(1, (int)(isset($settings['interval_minutes']) ? $settings['interval_minutes'] : 5));
$heartbeat['enabled'] = $enabled;
$heartbeat['interval_minutes'] = $interval;

if (!$enabled) {
    $heartbeat['state'] = 'disabled';
    $heartbeat['message'] = 'Scheduler disabilitato nelle impostazioni';
    scheduler_write_heartbeat($heartbeatFile, $heartbeat);
    fwrite(STDOUT, $heartbeat['message'] . "\n");
    exit(0);
}

$lastSyncAt = isset($previous['last_sync_at']) ? strtotime($previous['last_sync_at']) : false;
if ($lastSyncAt && (time() - $lastSyncAt) < ($interval * 60)) {
    $heartbeat['state'] = 'waiting';
    $heartbeat['message'] = 'Scheduler attivo, prossima sincronizzazione non ancora dovuta';
    scheduler_write_heartbeat($heartbeatFile, $heartbeat);
    fwrite(STDOUT, $heartbeat['message'] . "\n");
    exit(0);
}

list($runStatus, $runRaw, $runError) = scheduler_http('POST', $baseUrl . '/api.php?route=scheduler/run', '{}');
$runJson = $runRaw !== '' ? json_decode($runRaw, true) : null;
$heartbeat['last_http_code'] = $runStatus;
$heartbeat['last_sync_at'] = date('Y-m-d H:i:s');

if ($runError !== null || $runStatus < 200 || $runStatus >= 300 || !is_array($runJson) || empty($runJson['ok'])) {
    $heartbeat['state'] = 'error';
    $heartbeat['message'] = $runError ?: ('Sincronizzazione fallita HTTP ' . $runStatus);
    scheduler_write_heartbeat($heartbeatFile, $heartbeat);
    fwrite(STDERR, $heartbeat['message'] . "\n");
    exit(2);
}

$result = isset($runJson['data']) && is_array($runJson['data']) ? $runJson['data'] : [];
$heartbeat['state'] = !empty($result['success']) ? 'ok' : 'warning';
$heartbeat['message'] = !empty($result['success']) ? 'Sincronizzazione automatica completata' : 'Sincronizzazione completata con errori';
$heartbeat['last_run_id'] = isset($result['run_id']) ? $result['run_id'] : null;
scheduler_write_heartbeat($heartbeatFile, $heartbeat);

fwrite(STDOUT, $heartbeat['message'] . "\n");
exit(!empty($result['success']) ? 0 : 2);
