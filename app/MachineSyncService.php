<?php
final class MachineSyncService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function syncLive(array $machine): array
    {
        $client = new MaestroClient($machine);
        $statusRes = $client->status();
        if (!$statusRes['success']) {
            $this->saveRuntimeError((int)$machine['id'], $statusRes['error'] ?: ('HTTP ' . $statusRes['http_code']));
            return ['success' => false, 'error' => $statusRes['error'], 'http_code' => $statusRes['http_code']];
        }

        $status = $client->normalizeStatusResponse($statusRes);
        $alarmsRes = $client->activeAlarms();
        $activeAlarms = [];
        if ($alarmsRes['success']) {
            $activeAlarms = $client->normalizeAlarmResponse($alarmsRes);
        }

        $info = null;
        try {
            $stmt = $this->pdo->prepare('SELECT info_json FROM machine_runtime WHERE machine_id=?');
            $stmt->execute([(int)$machine['id']]);
            $existingInfo = $stmt->fetchColumn();
            if ($existingInfo === false || $existingInfo === null || $existingInfo === '') {
                $infoRes = $client->info();
                $info = $infoRes['success'] ? ($infoRes['data'] ?? null) : null;
            }
            $this->saveRuntime((int)$machine['id'], $status, $activeAlarms, $info);
            $this->syncJobStates((int)$machine['id'], $status);
        } catch (Throwable $e) {
            $localError = 'Errore sincronizzazione gestionale: ' . $e->getMessage();
            try {
                $this->saveRuntimeLocalError((int)$machine['id'], $localError);
            } catch (Throwable $ignored) {
            }
            return [
                'success' => false,
                'reachable' => true,
                'status' => $status,
                'active_alarms' => $activeAlarms,
                'info' => $info,
                'error' => $localError,
                'http_code' => 200,
            ];
        }

        return ['success' => true, 'reachable' => true, 'status' => $status, 'active_alarms' => $activeAlarms, 'info' => $info];
    }

    public function syncFull(array $machine, int $productionLookbackMinutes, int $alarmLookbackMinutes, int $limit): array
    {
        $result = $this->syncLive($machine);
        if (!$result['success']) return $result;

        $now = new DateTimeImmutable('now');
        $prodFrom = $now->modify('-' . max(1, $productionLookbackMinutes) . ' minutes');
        $alarmFrom = $now->modify('-' . max(1, $alarmLookbackMinutes) . ' minutes');

        $errors = [];
        try {
            $result['production'] = $this->importProduction(
                $machine,
                $prodFrom->format('Y-m-d\TH:i:s'),
                $now->format('Y-m-d\TH:i:s'),
                $limit
            );
        } catch (Throwable $e) {
            $result['production'] = ['success' => false, 'error' => $e->getMessage()];
            $errors[] = $e->getMessage();
        }

        try {
            $result['alarms'] = $this->importAlarms(
                $machine,
                $alarmFrom->format('Y-m-d\TH:i:s'),
                $now->format('Y-m-d\TH:i:s'),
                $limit
            );
        } catch (Throwable $e) {
            $result['alarms'] = ['success' => false, 'error' => $e->getMessage()];
            $errors[] = $e->getMessage();
        }

        if ($errors) {
            $result['success'] = false;
            $result['error'] = implode(' | ', $errors);
        }
        return $result;
    }

    public function importProduction(array $machine, string $from, string $to, int $limit = 100, ?int $forcedJobId = null): array
    {
        $client = new MaestroClient($machine);
        $page = 1;
        $totalPages = 1;
        $seen = 0;
        $inserted = 0;
        $updated = 0;
        $limit = max(1, min(500, $limit));

        do {
            $res = $client->productionHistory($from, $to, $limit, $page);
            if (!$res['success']) {
                throw new RuntimeException('Errore lettura produzione: ' . ($res['error'] ?: 'HTTP ' . $res['http_code']));
            }
            $records = $client->normalizeProductionResponse($res);
            $seen += count($records);
            foreach ($records as $r) {
                $jobId = $this->findJobByCode($r['remote_order_name'], (int)$machine['id']);
                if ($forcedJobId !== null && $jobId !== $forcedJobId) continue;
                $stmt = $this->pdo->prepare('INSERT INTO production_records
                    (machine_id, job_id, remote_order_name, barcode, program_name, length_mm, width_mm, thickness_mm, passage, edge_name_lh, edge_consumption_lh, datetime_start, datetime_end, track_speed, raw_json, source_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                      job_id=VALUES(job_id),
                      remote_order_name=VALUES(remote_order_name),
                      barcode=VALUES(barcode),
                      program_name=VALUES(program_name),
                      length_mm=VALUES(length_mm),
                      width_mm=VALUES(width_mm),
                      thickness_mm=VALUES(thickness_mm),
                      passage=VALUES(passage),
                      edge_name_lh=VALUES(edge_name_lh),
                      edge_consumption_lh=VALUES(edge_consumption_lh),
                      datetime_start=VALUES(datetime_start),
                      datetime_end=VALUES(datetime_end),
                      track_speed=VALUES(track_speed),
                      raw_json=VALUES(raw_json)');
                $stmt->execute([
                    (int)$machine['id'], $jobId, $r['remote_order_name'], $r['barcode'], $r['program_name'],
                    $r['length_mm'], $r['width_mm'], $r['thickness_mm'], $r['passage'], $r['edge_name_lh'],
                    $r['edge_consumption_lh'], $r['datetime_start'], $r['datetime_end'], $r['track_speed'],
                    $r['raw_json'], $r['source_hash'],
                ]);
                $affected = $stmt->rowCount();
                if ($affected === 1) $inserted++;
                elseif ($affected >= 2) $updated++;
            }
            $totalPages = $client->totalPages($res);
            $page++;
        } while ($page <= $totalPages && $page <= 100);

        return ['seen' => $seen, 'inserted' => $inserted, 'updated' => $updated, 'pages' => $totalPages];
    }

    public function importAlarms(array $machine, string $from, string $to, int $limit = 100): array
    {
        $client = new MaestroClient($machine);
        $page = 1;
        $totalPages = 1;
        $seen = 0;
        $inserted = 0;
        $limit = max(1, min(500, $limit));

        do {
            $res = $client->alarmsHistory($from, $to, $limit, $page);
            if (!$res['success']) {
                throw new RuntimeException('Errore lettura allarmi: ' . ($res['error'] ?: 'HTTP ' . $res['http_code']));
            }
            $records = $client->normalizeAlarmResponse($res);
            $seen += count($records);
            foreach ($records as $r) {
                $stmt = $this->pdo->prepare('INSERT IGNORE INTO alarm_records
                    (machine_id, source, code, message, other_info, type, severity, date_from, date_to, user_name, raw_json, source_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    (int)$machine['id'], $r['source'], $r['code'], $r['message'], $r['other_info'], $r['type'],
                    $r['severity'], $r['date_from'], $r['date_to'], $r['user_name'], $r['raw_json'], $r['source_hash'],
                ]);
                $inserted += $stmt->rowCount();
            }
            $totalPages = $client->totalPages($res);
            $page++;
        } while ($page <= $totalPages && $page <= 100);

        return ['seen' => $seen, 'inserted' => $inserted, 'pages' => $totalPages];
    }

    private function saveRuntime(int $machineId, array $s, array $activeAlarms, $info): void
    {
        $sql = 'INSERT INTO machine_runtime
            (machine_id, online, machine_state, working, alarms, warnings, track_speed, empty_machine, pieces_in_machine, axes_zero,
             current_order, order_status, last_order_closed, execution_list_status, user_name, active_alarms_json, info_json, raw_status_json,
             last_checked_at, last_success_at, last_error)
            VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NULL)
            ON DUPLICATE KEY UPDATE
             online=1, machine_state=VALUES(machine_state), working=VALUES(working), alarms=VALUES(alarms), warnings=VALUES(warnings),
             track_speed=VALUES(track_speed), empty_machine=VALUES(empty_machine), pieces_in_machine=VALUES(pieces_in_machine), axes_zero=VALUES(axes_zero),
             current_order=VALUES(current_order), order_status=VALUES(order_status), last_order_closed=VALUES(last_order_closed),
             execution_list_status=VALUES(execution_list_status), user_name=VALUES(user_name), active_alarms_json=VALUES(active_alarms_json),
             info_json=COALESCE(VALUES(info_json), info_json), raw_status_json=VALUES(raw_status_json), last_checked_at=NOW(), last_success_at=NOW(), last_error=NULL';
        $this->pdo->prepare($sql)->execute([
            $machineId, $s['machine_state'], (int)$s['working'], (int)$s['alarms'], (int)$s['warnings'], $s['track_speed'],
            $s['empty'] === null ? null : (int)$s['empty'], $s['pieces_in_machine'], $s['axes_zero'] === null ? null : (int)$s['axes_zero'],
            $s['current_order'], $s['order_status'], $s['last_order_closed'], $s['execution_list_status'], $s['user_name'],
            json_encode($activeAlarms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $info === null ? null : json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($s['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function saveRuntimeLocalError(int $machineId, string $error): void
    {
        $this->pdo->prepare('INSERT INTO machine_runtime (machine_id, online, last_checked_at, last_error)
            VALUES (?,1,NOW(),?) ON DUPLICATE KEY UPDATE online=1,last_checked_at=NOW(),last_error=VALUES(last_error)')
            ->execute([$machineId, $error]);
    }

    private function saveRuntimeError(int $machineId, string $error): void
    {
        $this->pdo->prepare('INSERT INTO machine_runtime (machine_id, online, last_checked_at, last_error)
            VALUES (?,0,NOW(),?) ON DUPLICATE KEY UPDATE online=0,last_checked_at=NOW(),last_error=VALUES(last_error)')
            ->execute([$machineId, $error]);
    }

    private function syncJobStates(int $machineId, array $status): void
    {
        $current = $status['current_order'];
        $remoteStatus = strtolower((string)($status['order_status'] ?? ''));
        if ($current) {
            $jobId = $this->findJobByCode($current, $machineId);
            if ($jobId) {
                $newStatus = $remoteStatus === 'running' ? 'in_lavorazione' : ($remoteStatus === 'ended' ? 'chiusa' : null);
                if ($newStatus) {
                    $closedAt = $newStatus === 'chiusa' ? date('Y-m-d H:i:s') : null;
                    $this->pdo->prepare('UPDATE jobs SET machine_id=?, status=?, closed_at=? WHERE id=?')
                        ->execute([$machineId, $newStatus, $closedAt, $jobId]);
                }
            }
        }

        $lastClosed = $status['last_order_closed'];
        if ($lastClosed) {
            $jobId = $this->findJobByCode($lastClosed, $machineId);
            if ($jobId) {
                $now = date('Y-m-d H:i:s');
                $this->pdo->prepare("UPDATE jobs SET machine_id=?, status='chiusa', closed_at=COALESCE(closed_at, ?) WHERE id=?")
                    ->execute([$machineId, $now, $jobId]);
            }
        }
    }

    private function findJobByCode(?string $code, int $machineId): ?int
    {
        if ($code === null) return null;
        $code = trim($code);
        if ($code === '' || $code === '-') return null;
        $stmt = $this->pdo->prepare("SELECT j.id
            FROM jobs j
            JOIN job_types jt ON jt.id=j.job_type_id
            WHERE j.job_code=? AND jt.source_type='MAESTRO_REST' AND (j.machine_id=? OR j.machine_id IS NULL)
            ORDER BY CASE WHEN j.machine_id=? THEN 0 ELSE 1 END
            LIMIT 1");
        $stmt->execute([$code, $machineId, $machineId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }
}
