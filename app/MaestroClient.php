<?php
final class MaestroClient
{
    private array $machine;

    public function __construct(array $machine)
    {
        $this->machine = $machine;
    }

    public function status(): array
    {
        return $this->get($this->endpoint('status'));
    }

    public function info(): array
    {
        return $this->get($this->endpoint('info'));
    }

    public function activeAlarms(): array
    {
        return $this->get($this->endpoint('alarms_status'));
    }

    public function alarmsHistory(string $from, string $to, int $limit = 100, int $page = 1): array
    {
        return $this->get($this->endpoint('alarms_history'), [
            'from' => $from,
            'to' => $to,
            'limit' => $limit,
            'page' => $page,
        ]);
    }

    public function productionHistory(string $from, string $to, int $limit = 100, int $page = 1): array
    {
        return $this->get($this->endpoint('production_history'), [
            'from' => $from,
            'to' => $to,
            'limit' => $limit,
            'page' => $page,
        ]);
    }

    public function openOrder(string $orderName): array
    {
        return $this->postText($this->endpoint('order_open'), $orderName);
    }

    public function activateOrder(string $orderName): array
    {
        return $this->postText($this->endpoint('order_activate'), $orderName);
    }

    public function closeOrder(string $orderName): array
    {
        return $this->postText($this->endpoint('order_close'), $orderName);
    }

    public function normalizeStatusResponse(array $response): array
    {
        $row = is_array($response['data'] ?? null) ? $response['data'] : [];
        if ($this->version() === 'v1') {
            $stateCode = !empty($row['Alarms']) ? 'ALARM' : (!empty($row['Work']) ? 'WORK' : (!empty($row['Warnings']) ? 'WARNING' : 'READY'));
            $stateLabel = [
                'ALARM' => 'Allarme',
                'WORK' => 'In lavorazione',
                'WARNING' => 'Attenzione',
                'READY' => 'Pronta',
            ][$stateCode];
            return [
                'machine_state' => $stateLabel,
                'machine_state_code' => $stateCode,
                'working' => (bool)($row['Work'] ?? false),
                'alarms' => (bool)($row['Alarms'] ?? false),
                'warnings' => (bool)($row['Warnings'] ?? false),
                'track_speed' => $row['TrackSpeed'] ?? null,
                'empty' => array_key_exists('Empty', $row) ? (bool)$row['Empty'] : null,
                'pieces_in_machine' => $row['PiecesInMachine'] ?? null,
                'axes_zero' => array_key_exists('AxesZero', $row) ? (bool)$row['AxesZero'] : null,
                'current_order' => str_or_null($row['CurrentOrder'] ?? null),
                'order_status' => str_or_null($row['OrderStatus'] ?? null),
                'last_order_closed' => str_or_null($row['LastOrderClosed'] ?? null),
                'execution_list_status' => str_or_null($row['ExecutionListStatus'] ?? null),
                'user_name' => str_or_null($row['User'] ?? null),
                'raw' => $row,
            ];
        }

        $stateCode = strtoupper(trim((string)($row['status'] ?? '')));
        $stateLabels = [
            'EXE' => 'In lavorazione',
            'WORK' => 'In lavorazione',
            'READY' => 'Pronta',
            'SETUP' => 'Preparazione',
            'FAIL' => 'Allarme',
            'POWER OFF' => 'Spenta',
            'POWEROFF' => 'Spenta',
        ];
        $stateLabel = $stateCode !== '' ? ($stateLabels[$stateCode] ?? $stateCode) : null;

        return [
            'machine_state' => $stateLabel,
            'machine_state_code' => $stateCode !== '' ? $stateCode : null,
            'working' => (bool)($row['working'] ?? false),
            'alarms' => (bool)($row['alarm'] ?? false),
            'warnings' => (bool)($row['warning'] ?? false),
            'track_speed' => $row['trackSpeed'] ?? null,
            'empty' => array_key_exists('workpiecePresent', $row) ? !(bool)$row['workpiecePresent'] : null,
            'pieces_in_machine' => $row['workpiecesInMachine'] ?? null,
            'axes_zero' => array_key_exists('calibrated', $row) ? (bool)$row['calibrated'] : null,
            'current_order' => str_or_null($row['currentJobOrder'] ?? null),
            'order_status' => str_or_null($row['jobOrderStatus'] ?? null),
            'last_order_closed' => str_or_null($row['lastJobOrderClosed'] ?? null),
            'execution_list_status' => str_or_null($row['executionListStatus'] ?? $row['ExecutionlistStatus'] ?? $row['ExecutionListStatus'] ?? null),
            'user_name' => str_or_null($row['user'] ?? null),
            'raw' => $row,
        ];
    }

    public function normalizeProductionResponse(array $response): array
    {
        $payload = $response['data'];
        $records = $this->version() === 'v1' ? (is_array($payload) ? $payload : []) : ($payload['records'] ?? []);
        $out = [];
        foreach ($records as $row) {
            if (!is_array($row)) continue;
            $remoteOrder = $row['Order'] ?? $row['order'] ?? $row['jobOrder'] ?? null;
            $start = $row['DateTime'] ?? $row['dateFrom'] ?? null;
            $end = $row['DateTimeExit'] ?? $row['dateTo'] ?? null;
            $barcode = $row['Barcode'] ?? $row['barcode'] ?? $row['barCode'] ?? null;
            $program = $row['ProgramName'] ?? $row['name'] ?? null;
            $normalized = [
                'remote_order_name' => str_or_null($remoteOrder),
                'barcode' => str_or_null($barcode),
                'program_name' => str_or_null($program),
                'length_mm' => $row['Length'] ?? $row['length'] ?? null,
                'width_mm' => $row['Width'] ?? $row['width'] ?? null,
                'thickness_mm' => $row['Thickness'] ?? $row['thickness'] ?? null,
                'passage' => $row['Passage'] ?? $row['pass'] ?? null,
                'edge_name_lh' => str_or_null($row['EdgeNameLH'] ?? $row['edgeNameLH'] ?? null),
                'edge_consumption_lh' => $row['EdgeConsumptionLH'] ?? $row['edgeConsumptionLH'] ?? null,
                'datetime_start' => dt_or_null($start),
                'datetime_end' => dt_or_null($end),
                'track_speed' => $row['TrackSpeed'] ?? $row['trackSpeed'] ?? null,
                'raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $normalized['source_hash'] = hash('sha256', implode('|', [
                $this->machine['id'], $normalized['remote_order_name'], $normalized['barcode'], $normalized['program_name'],
                $normalized['datetime_start'], $normalized['datetime_end'], $normalized['length_mm'], $normalized['width_mm'],
                $normalized['thickness_mm'], $normalized['passage'],
            ]));
            $out[] = $normalized;
        }
        return $out;
    }

    public function normalizeAlarmResponse(array $response): array
    {
        $payload = $response['data'];
        $records = $this->version() === 'v1' ? (is_array($payload) ? $payload : []) : ($payload['records'] ?? []);
        $out = [];
        foreach ($records as $row) {
            if (!is_array($row)) continue;
            $normalized = [
                'source' => str_or_null($row['Source'] ?? $row['source'] ?? null),
                'code' => str_or_null($row['Code'] ?? $row['code'] ?? null),
                'message' => str_or_null($row['Description'] ?? $row['Message'] ?? $row['message'] ?? null),
                'other_info' => str_or_null($row['OtherInfo'] ?? $row['otherInfo'] ?? null),
                'type' => str_or_null($row['Type'] ?? $row['type'] ?? null),
                'severity' => int_or_null($row['Severity'] ?? $row['severity'] ?? null),
                'date_from' => dt_or_null($row['Start'] ?? $row['DateFrom'] ?? $row['dateFrom'] ?? null),
                'date_to' => dt_or_null($row['Stop'] ?? $row['DateTo'] ?? $row['dateTo'] ?? null),
                'user_name' => str_or_null($row['UserID'] ?? $row['User'] ?? $row['user'] ?? null),
                'raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            $normalized['source_hash'] = hash('sha256', implode('|', [
                $this->machine['id'], $normalized['source'], $normalized['code'], $normalized['message'],
                $normalized['date_from'], $normalized['date_to'], $normalized['type'],
            ]));
            $out[] = $normalized;
        }
        return $out;
    }

    public function totalPages(array $response): int
    {
        $bodyDecoded = json_decode($response['body'] ?? '', true);
        $bodyData = is_array($response['data'] ?? null) ? $response['data'] : [];
        return max(1, (int)($bodyDecoded['TotalPages'] ?? $bodyDecoded['totalPages'] ?? $bodyData['TotalPages'] ?? $bodyData['totalPages'] ?? 1));
    }

    public function version(): string
    {
        return ($this->machine['api_version'] ?? null) === 'v1' ? 'v1' : 'v2';
    }

    private function endpoint(string $key): string
    {
        $v1 = [
            'info' => 'machine/info', 'status' => 'status/Machine', 'alarms_status' => 'status/alarms',
            'alarms_history' => 'report/alarms', 'production_history' => 'report/production',
            'order_open' => 'order/open', 'order_activate' => 'order', 'order_close' => 'order/close',
        ];
        $v2 = [
            'info' => 'machine/identification', 'status' => 'machine/status', 'alarms_status' => 'machine/alarms/status',
            'alarms_history' => 'machine/alarms/history', 'production_history' => 'machine/production/history',
            'order_open' => 'machine/joborders/open', 'order_activate' => 'machine/joborders/activate', 'order_close' => 'machine/joborders/close',
        ];
        $map = $this->version() === 'v1' ? $v1 : $v2;
        return $map[$key];
    }

    private function baseUrl(): string
    {
        $host = trim((string)$this->machine['host']);
        $port = (int)$this->machine['port'];
        $basePath = trim((string)($this->machine['base_path'] ?? ''));
        if ($host === '' || $port <= 0) throw new RuntimeException('Host/porta macchina non configurati');
        if ($basePath === '') $basePath = $this->version() === 'v1' ? '/api/v1/' : '/api/v2/';
        $basePath = '/' . trim($basePath, '/') . '/';
        return 'http://' . $host . ':' . $port . $basePath;
    }

    private function get(string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl() . ltrim($endpoint, '/');
        if ($query) $url .= '?' . http_build_query($query);
        return $this->request('GET', $url, null);
    }

    private function postText(string $endpoint, string $body): array
    {
        return $this->request('POST', $this->baseUrl() . ltrim($endpoint, '/'), $body);
    }

    private function request(string $method, string $url, ?string $body): array
    {
        $headers = ['Accept: application/json'];
        $responseBody = '';
        $httpCode = 0;
        $error = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: text/plain; charset=utf-8']));
            }
            $raw = curl_exec($ch);
            $responseBody = $raw === false ? '' : (string)$raw;
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (curl_errno($ch)) $error = curl_error($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $body !== null ? array_merge($headers, ['Content-Type: text/plain; charset=utf-8']) : $headers),
                'content' => $body ?? '', 'timeout' => 15, 'ignore_errors' => true,
            ]]);
            $responseBody = (string)@file_get_contents($url, false, $context);
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $httpCode = (int)$m[1];
            if ($responseBody === '') $error = 'Nessuna risposta dalla macchina';
        }

        $decoded = $responseBody !== '' ? json_decode($responseBody, true) : null;
        $success = $httpCode >= 200 && $httpCode < 300 && $error === null;
        $data = $decoded;

        if ($success && $this->version() === 'v1' && is_array($decoded) && (array_key_exists('Executed', $decoded) || array_key_exists('executed', $decoded))) {
            $executed = $decoded['Executed'] ?? $decoded['executed'] ?? true;
            $success = (bool)$executed;
            $data = $decoded['Result'] ?? $decoded['result'] ?? null;
            if (!$success) $error = implode('; ', (array)($decoded['Errors'] ?? $decoded['errors'] ?? []));
        }

        return [
            'success' => $success, 'http_code' => $httpCode, 'data' => $data, 'body' => $responseBody,
            'error' => $error, 'url' => $url,
        ];
    }
}
