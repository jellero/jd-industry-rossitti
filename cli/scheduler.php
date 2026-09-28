#!/usr/bin/env php
<?php
require_once __DIR__ . '/../app/Db.php';
require_once __DIR__ . '/../app/ApiResponse.php';
require_once __DIR__ . '/../app/Helpers.php';
require_once __DIR__ . '/../app/Settings.php';
require_once __DIR__ . '/../app/MaestroClient.php';
require_once __DIR__ . '/../app/MachineSyncService.php';

$pdo = Db::pdo();
if (!Settings::getBool('scheduler.enabled', true)) {
    fwrite(STDOUT, "Scheduler disabilitato\n");
    exit(0);
}

$interval = max(1, Settings::getInt('scheduler.interval_minutes', 5));
$last = $pdo->query("SELECT MAX(started_at) FROM scheduler_runs WHERE triggered_by='cron'")->fetchColumn();
if ($last && (time() - strtotime($last)) < ($interval * 60)) {
    fwrite(STDOUT, "Non ancora dovuto: intervallo {$interval} minuti\n");
    exit(0);
}

$lock = (int)$pdo->query("SELECT GET_LOCK('commesse_lite_scheduler', 0)")->fetchColumn();
if ($lock !== 1) {
    fwrite(STDOUT, "Un'altra sincronizzazione è già in esecuzione\n");
    exit(0);
}

$pdo->prepare("INSERT INTO scheduler_runs (started_at, success, triggered_by) VALUES (NOW(),0,'cron')")->execute();
$runId = (int)$pdo->lastInsertId();
$details = [];
$okCount = 0;
$machines = [];

try {
    $machines = $pdo->query("SELECT * FROM machines WHERE active=1 AND kind='maestro_rest' ORDER BY id")->fetchAll();
    $sync = new MachineSyncService();
    $prodLookback = max($interval * 2, Settings::getInt('scheduler.production_lookback_minutes', 15));
    $alarmLookback = max($interval * 2, Settings::getInt('scheduler.alarm_lookback_minutes', 60));
    $limit = max(10, min(500, Settings::getInt('scheduler.page_limit', 100)));

    foreach ($machines as $machine) {
        try {
            $res = $sync->syncFull($machine, $prodLookback, $alarmLookback, $limit);
            $details[$machine['name']] = $res;
            if (!empty($res['success'])) $okCount++;
        } catch (Throwable $e) {
            $details[$machine['name']] = ['success' => false, 'error' => $e->getMessage()];
        }
    }

    $success = count($machines) === 0 || $okCount === count($machines);
    $pdo->prepare('UPDATE scheduler_runs SET finished_at=NOW(), success=?, machines_total=?, machines_ok=?, details_json=? WHERE id=?')
        ->execute([(int)$success, count($machines), $okCount, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $runId]);
    fwrite(STDOUT, json_encode(['run_id' => $runId, 'success' => $success, 'machines' => $details], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit($success ? 0 : 2);
} catch (Throwable $e) {
    $pdo->prepare('UPDATE scheduler_runs SET finished_at=NOW(), success=0, machines_total=?, machines_ok=?, error_message=? WHERE id=?')
        ->execute([count($machines), $okCount, $e->getMessage(), $runId]);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('commesse_lite_scheduler')");
}
