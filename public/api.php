<?php
require_once __DIR__ . '/../app/ApiResponse.php';
require_once __DIR__ . '/../app/Db.php';
require_once __DIR__ . '/../app/Helpers.php';
require_once __DIR__ . '/../app/MaestroClient.php';
require_once __DIR__ . '/../app/FileScanner.php';
require_once __DIR__ . '/../app/Settings.php';
require_once __DIR__ . '/../app/MachineSyncService.php';

$route = trim((string)($_GET['route'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($route) {
        case 'health':
            health();
            break;
        case 'dashboard':
            dashboard();
            break;
        case 'machines/live':
            machines_live();
            break;
        case 'scheduling':
            if ($method === 'GET') scheduling_get();
            if ($method === 'POST') scheduling_save();
            break;
        case 'scheduler/run':
            scheduler_run_manual();
            break;
        case 'costs':
            if ($method === 'GET') costs_get();
            if ($method === 'POST') costs_save();
            break;
        case 'company-settings':
            if ($method === 'GET') company_settings_get();
            if ($method === 'POST') company_settings_save();
            break;
        case 'company-logo':
            if ($method === 'POST') company_logo_upload();
            break;
        case 'reports/job':
            job_report();
            break;
        case 'clients':
            if ($method === 'GET') clients_list();
            if ($method === 'POST') clients_save();
            if ($method === 'DELETE') clients_delete();
            break;
        case 'job-types':
            job_types_list();
            break;
        case 'machines':
            if ($method === 'GET') machines_list();
            if ($method === 'POST') machines_save();
            break;
        case 'jobs':
            if ($method === 'GET') jobs_list();
            if ($method === 'POST') jobs_save();
            if ($method === 'DELETE') jobs_delete();
            break;
        case 'jobs/status':
            jobs_status();
            break;
        case 'maestro/status':
            maestro_status();
            break;
        case 'maestro/info':
            maestro_info();
            break;
        case 'maestro/order':
            maestro_order();
            break;
        case 'maestro/orders-bulk':
            maestro_orders_bulk();
            break;
        case 'maestro/import-production':
            maestro_import_production();
            break;
        case 'production':
            production_list();
            break;
        case 'files/scan':
            files_scan();
            break;
        case 'files':
            files_list();
            break;
        case 'files/assign':
            files_assign();
            break;
        case 'files/unassign':
            files_unassign();
            break;
        default:
            ApiResponse::error('Route non trovata: ' . $route, 404);
    }
} catch (Throwable $e) {
    $config = require __DIR__ . '/../app/config.php';
    ApiResponse::error($e->getMessage(), 500, !empty($config['app']['debug']) ? $e->getTraceAsString() : null);
}

function health(): void
{
    Db::pdo()->query('SELECT 1');
    ApiResponse::ok(['php' => PHP_VERSION, 'db' => true]);
}

function dashboard(): void
{
    $pdo = Db::pdo();
    $data = [];
    $data['clients'] = (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE active=1')->fetchColumn();
    $data['jobs_open'] = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status IN ('bozza','aperta','in_lavorazione')")->fetchColumn();
    $data['jobs_running'] = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status='in_lavorazione'")->fetchColumn();
    $data['jobs_closed'] = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status IN ('chiusa','archiviata')")->fetchColumn();
    $data['files_new'] = (int)$pdo->query('SELECT COUNT(*) FROM job_files WHERE job_id IS NULL')->fetchColumn();
    $data['production_rows'] = (int)$pdo->query('SELECT COUNT(*) FROM production_records')->fetchColumn();
    $data['machines'] = $pdo->query("SELECT m.id,m.name,m.kind,m.api_version,m.host,m.port,m.base_path,m.active,
        mr.online,mr.machine_state,mr.working,mr.alarms,mr.warnings,mr.track_speed,mr.empty_machine,mr.pieces_in_machine,
        mr.current_order,mr.order_status,mr.last_order_closed,mr.execution_list_status,mr.user_name,mr.last_checked_at,mr.last_success_at,mr.last_error
        FROM machines m LEFT JOIN machine_runtime mr ON mr.machine_id=m.id WHERE m.active=1 ORDER BY m.id")->fetchAll();
    $data['recent_jobs'] = $pdo->query("SELECT j.id,j.job_code,j.title,j.status,j.updated_at,j.created_at,c.company_name,m.name machine_name
        FROM jobs j JOIN clients c ON c.id=j.client_id LEFT JOIN machines m ON m.id=j.machine_id
        ORDER BY COALESCE(j.updated_at,j.created_at) DESC, j.id DESC LIMIT 10")->fetchAll();
    $data['refresh_seconds'] = max(5, min(120, Settings::getInt('dashboard.refresh_seconds', 10)));
    ApiResponse::ok($data);
}

function clients_list(): void
{
    $q = str_or_null($_GET['q'] ?? null);
    $sql = 'SELECT * FROM clients WHERE 1=1';
    $params = [];
    if ($q !== null) {
        $sql .= ' AND (company_name LIKE ? OR code LIKE ? OR vat_number LIKE ? OR email LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like];
    }
    $sql .= ' ORDER BY active DESC, company_name ASC LIMIT 500';
    $stmt = Db::pdo()->prepare($sql);
    $stmt->execute($params);
    ApiResponse::ok($stmt->fetchAll());
}

function clients_save(): void
{
    $d = input_json();
    $id = (int)($d['id'] ?? 0);
    $company = str_or_null($d['company_name'] ?? null);
    if ($company === null) {
        ApiResponse::error('Ragione sociale obbligatoria', 400);
    }
    $params = [
        str_or_null($d['code'] ?? null),
        $company,
        str_or_null($d['vat_number'] ?? null),
        str_or_null($d['tax_code'] ?? null),
        str_or_null($d['email'] ?? null),
        str_or_null($d['phone'] ?? null),
        str_or_null($d['address'] ?? null),
        str_or_null($d['city'] ?? null),
        str_or_null($d['province'] ?? null),
        str_or_null($d['postal_code'] ?? null),
        str_or_null($d['notes'] ?? null),
        isset($d['active']) ? (int)(bool)$d['active'] : 1,
    ];
    $pdo = Db::pdo();
    if ($id > 0) {
        $sql = 'UPDATE clients SET code=?, company_name=?, vat_number=?, tax_code=?, email=?, phone=?, address=?, city=?, province=?, postal_code=?, notes=?, active=? WHERE id=?';
        $params[] = $id;
        $pdo->prepare($sql)->execute($params);
    } else {
        $sql = 'INSERT INTO clients (code, company_name, vat_number, tax_code, email, phone, address, city, province, postal_code, notes, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $pdo->prepare($sql)->execute($params);
        $id = (int)$pdo->lastInsertId();
    }
    ApiResponse::ok(['id' => $id]);
}

function clients_delete(): void
{
    $id = require_id($_GET['id'] ?? null);
    Db::pdo()->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
    ApiResponse::ok();
}

function job_types_list(): void
{
    $stmt = Db::pdo()->query('SELECT * FROM job_types WHERE active=1 ORDER BY id');
    ApiResponse::ok($stmt->fetchAll());
}

function machines_list(): void
{
    $stmt = Db::pdo()->query('SELECT m.*, mr.online, mr.machine_state, mr.last_checked_at, mr.last_error FROM machines m LEFT JOIN machine_runtime mr ON mr.machine_id=m.id ORDER BY m.id');
    ApiResponse::ok($stmt->fetchAll());
}

function machines_save(): void
{
    $d = input_json();
    $id = require_id($d['id'] ?? null);
    $name = str_or_null($d['name'] ?? null);
    if ($name === null) ApiResponse::error('Nome macchina obbligatorio', 400);
    $sql = 'UPDATE machines SET name=?, api_version=?, host=?, port=?, base_path=?, upload_path=?, active=?, notes=? WHERE id=?';
    Db::pdo()->prepare($sql)->execute([
        $name,
        str_or_null($d['api_version'] ?? null),
        str_or_null($d['host'] ?? null),
        int_or_null($d['port'] ?? null),
        str_or_null($d['base_path'] ?? null),
        str_or_null($d['upload_path'] ?? null),
        isset($d['active']) ? (int)(bool)$d['active'] : 1,
        str_or_null($d['notes'] ?? null),
        $id,
    ]);
    ApiResponse::ok(['id' => $id]);
}

function jobs_list(): void
{
    $pdo = Db::pdo();
    $where = [];
    $params = [];
    if (isset($_GET['client_id']) && $_GET['client_id'] !== '') {
        $where[] = 'j.client_id=?';
        $params[] = (int)$_GET['client_id'];
    }
    if (isset($_GET['job_type_id']) && $_GET['job_type_id'] !== '') {
        $where[] = 'j.job_type_id=?';
        $params[] = (int)$_GET['job_type_id'];
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[] = 'j.status=?';
        $params[] = $_GET['status'];
    }
    if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
        $where[] = '(j.job_code LIKE ? OR j.title LIKE ? OR c.company_name LIKE ?)';
        $like = '%' . trim((string)$_GET['q']) . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $sql = "SELECT j.*, c.company_name, jt.name AS job_type_name, jt.source_type, m.name AS machine_name
            FROM jobs j
            JOIN clients c ON c.id=j.client_id
            JOIN job_types jt ON jt.id=j.job_type_id
            LEFT JOIN machines m ON m.id=j.machine_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY j.created_at DESC, j.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    ApiResponse::ok($stmt->fetchAll());
}

function jobs_save(): void
{
    $d = input_json();
    $id = (int)($d['id'] ?? 0);
    $clientId = require_id($d['client_id'] ?? null, 'client_id');
    $jobTypeId = require_id($d['job_type_id'] ?? null, 'job_type_id');
    $jobCode = str_or_null($d['job_code'] ?? null);
    $title = str_or_null($d['title'] ?? null);
    if ($jobCode === null) ApiResponse::error('Codice commessa obbligatorio', 400);
    if ($title === null) ApiResponse::error('Titolo commessa obbligatorio', 400);

    $status = str_or_null($d['status'] ?? 'bozza') ?? 'bozza';
    $allowed = ['bozza','aperta','in_lavorazione','chiusa','archiviata'];
    if (!in_array($status, $allowed, true)) $status = 'bozza';

    $machineId = int_or_null($d['machine_id'] ?? null);
    if ($machineId === 0) $machineId = null;

    $params = [
        $clientId,
        $jobTypeId,
        $machineId,
        normalize_order_name($jobCode),
        $title,
        str_or_null($d['description'] ?? null),
        $status,
        date_or_null($d['start_date'] ?? null),
        date_or_null($d['due_date'] ?? null),
        $status === 'chiusa' ? date('Y-m-d H:i:s') : null,
        str_or_null($d['notes'] ?? null),
    ];

    $pdo = Db::pdo();
    if ($id > 0) {
        $sql = 'UPDATE jobs SET client_id=?, job_type_id=?, machine_id=?, job_code=?, title=?, description=?, status=?, start_date=?, due_date=?, closed_at=IF(? IS NULL, closed_at, ?), notes=? WHERE id=?';
        $exec = [$clientId, $jobTypeId, $machineId, normalize_order_name($jobCode), $title, str_or_null($d['description'] ?? null), $status, date_or_null($d['start_date'] ?? null), date_or_null($d['due_date'] ?? null), $status === 'chiusa' ? date('Y-m-d H:i:s') : null, $status === 'chiusa' ? date('Y-m-d H:i:s') : null, str_or_null($d['notes'] ?? null), $id];
        $pdo->prepare($sql)->execute($exec);
    } else {
        $sql = 'INSERT INTO jobs (client_id, job_type_id, machine_id, job_code, title, description, status, start_date, due_date, closed_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $pdo->prepare($sql)->execute($params);
        $id = (int)$pdo->lastInsertId();
    }
    ApiResponse::ok(['id' => $id]);
}

function jobs_delete(): void
{
    $id = require_id($_GET['id'] ?? null);
    Db::pdo()->prepare('DELETE FROM jobs WHERE id=?')->execute([$id]);
    ApiResponse::ok();
}

function jobs_status(): void
{
    $d = input_json();
    $id = require_id($d['id'] ?? null);
    $status = str_or_null($d['status'] ?? null);
    $allowed = ['bozza','aperta','in_lavorazione','chiusa','archiviata'];
    if (!in_array($status, $allowed, true)) ApiResponse::error('Stato non valido', 400);
    $closedAt = $status === 'chiusa' ? date('Y-m-d H:i:s') : null;
    Db::pdo()->prepare('UPDATE jobs SET status=?, closed_at=IF(? IS NULL, closed_at, ?) WHERE id=?')->execute([$status, $closedAt, $closedAt, $id]);
    ApiResponse::ok();
}

function get_machine(int $id): array
{
    $stmt = Db::pdo()->prepare('SELECT * FROM machines WHERE id=?');
    $stmt->execute([$id]);
    $machine = $stmt->fetch();
    if (!$machine) ApiResponse::error('Macchina non trovata', 404);
    return $machine;
}

function get_job(int $id): array
{
    $stmt = Db::pdo()->prepare('SELECT * FROM jobs WHERE id=?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) ApiResponse::error('Commessa non trovata', 404);
    return $job;
}

function maestro_status(): void
{
    $machine = get_machine(require_id($_GET['machine_id'] ?? 1, 'machine_id'));
    $client = new MaestroClient($machine);
    ApiResponse::ok($client->status());
}

function maestro_info(): void
{
    $machine = get_machine(require_id($_GET['machine_id'] ?? 1, 'machine_id'));
    $client = new MaestroClient($machine);
    ApiResponse::ok($client->info());
}

function maestro_order(): void
{
    $d = input_json();
    $job = get_job(require_id($d['job_id'] ?? null, 'job_id'));
    $machineId = (int)($d['machine_id'] ?? ($job['machine_id'] ?: 1));
    $machine = get_machine($machineId);
    $action = str_or_null($d['action'] ?? null);
    if (!in_array($action, ['open','activate','close'], true)) ApiResponse::error('Azione non valida', 400);

    $client = new MaestroClient($machine);
    $orderName = normalize_order_name($job['job_code']);
    if ($action === 'open') $res = $client->openOrder($orderName);
    elseif ($action === 'activate') $res = $client->activateOrder($orderName);
    else $res = $client->closeOrder($orderName);

    $pdo = Db::pdo();
    $pdo->prepare('INSERT INTO maestro_order_events (job_id, machine_id, action, request_value, http_code, success, response_body, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$job['id'], (int)$machine['id'], $action, $orderName, $res['http_code'], (int)$res['success'], $res['body'], $res['error']]);

    if ($res['success']) {
        $newStatus = $action === 'close' ? 'chiusa' : ($action === 'activate' ? 'in_lavorazione' : 'aperta');
        $closedAt = $action === 'close' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE jobs SET machine_id=?, status=?, closed_at=IF(? IS NULL, closed_at, ?) WHERE id=?')
            ->execute([(int)$machine['id'], $newStatus, $closedAt, $closedAt, (int)$job['id']]);
    }

    ApiResponse::ok($res);
}

function maestro_import_production(): void
{
    $d = input_json();
    $machine = get_machine(require_id($d['machine_id'] ?? 1, 'machine_id'));
    $jobId = int_or_null($d['job_id'] ?? null);
    $from = str_or_null($d['from'] ?? null);
    $to = str_or_null($d['to'] ?? null);
    if ($from === null || $to === null) ApiResponse::error('Date from/to obbligatorie', 400);
    $fromApi = str_replace(' ', 'T', $from);
    $toApi = str_replace(' ', 'T', $to);
    $limit = max(1, min(500, (int)($d['limit'] ?? 100)));
    $sync = new MachineSyncService();
    $result = $sync->importProduction($machine, $fromApi, $toApi, $limit, $jobId);
    if ($jobId) {
        Db::pdo()->prepare('INSERT INTO maestro_order_events (job_id, machine_id, action, request_value, http_code, success, response_body) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$jobId, (int)$machine['id'], 'production_import', $fromApi . ' - ' . $toApi, 200, 1, json_encode($result)]);
    }
    ApiResponse::ok($result);
}

function find_job_by_code(?string $code): ?int
{
    if ($code === null || $code === '') return null;
    $stmt = Db::pdo()->prepare('SELECT id FROM jobs WHERE job_code=? LIMIT 1');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function production_list(): void
{
    $where = [];
    $params = [];
    if (isset($_GET['job_id']) && $_GET['job_id'] !== '') {
        $where[] = 'pr.job_id=?';
        $params[] = (int)$_GET['job_id'];
    }
    if (isset($_GET['order']) && trim((string)$_GET['order']) !== '') {
        $where[] = 'pr.remote_order_name LIKE ?';
        $params[] = '%' . trim((string)$_GET['order']) . '%';
    }
    $sql = 'SELECT pr.*, j.job_code, c.company_name, m.name AS machine_name
            FROM production_records pr
            LEFT JOIN jobs j ON j.id=pr.job_id
            LEFT JOIN clients c ON c.id=j.client_id
            JOIN machines m ON m.id=pr.machine_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY pr.datetime_start DESC, pr.id DESC LIMIT 500';
    $stmt = Db::pdo()->prepare($sql);
    $stmt->execute($params);
    ApiResponse::ok($stmt->fetchAll());
}

function files_scan(): void
{
    $machine = get_machine(require_id($_GET['machine_id'] ?? 2, 'machine_id'));
    $scanner = new FileScanner(__DIR__);
    ApiResponse::ok($scanner->scan($machine));
}

function files_list(): void
{
    $where = [];
    $params = [];
    if (isset($_GET['assigned']) && $_GET['assigned'] !== '') {
        if ((int)$_GET['assigned'] === 1) $where[] = 'jf.job_id IS NOT NULL';
        else $where[] = 'jf.job_id IS NULL';
    }
    if (isset($_GET['job_id']) && $_GET['job_id'] !== '') {
        $where[] = 'jf.job_id=?';
        $params[] = (int)$_GET['job_id'];
    }
    if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
        $where[] = '(jf.file_name LIKE ? OR jf.relative_path LIKE ? OR j.job_code LIKE ? OR c.company_name LIKE ?)';
        $like = '%' . trim((string)$_GET['q']) . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    $sql = 'SELECT jf.*, j.job_code, j.title AS job_title, c.company_name, m.upload_path
            FROM job_files jf
            LEFT JOIN jobs j ON j.id=jf.job_id
            LEFT JOIN clients c ON c.id=j.client_id
            JOIN machines m ON m.id=jf.machine_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY jf.detected_at DESC, jf.id DESC LIMIT 1000';
    $stmt = Db::pdo()->prepare($sql);
    $stmt->execute($params);
    ApiResponse::ok($stmt->fetchAll());
}

function files_assign(): void
{
    $d = input_json();
    $fileId = require_id($d['file_id'] ?? null, 'file_id');
    $jobId = require_id($d['job_id'] ?? null, 'job_id');
    Db::pdo()->prepare('UPDATE job_files SET job_id=?, assigned_at=NOW() WHERE id=?')->execute([$jobId, $fileId]);
    ApiResponse::ok();
}

function files_unassign(): void
{
    $d = input_json();
    $fileId = require_id($d['file_id'] ?? null, 'file_id');
    Db::pdo()->prepare('UPDATE job_files SET job_id=NULL, assigned_at=NULL WHERE id=?')->execute([$fileId]);
    ApiResponse::ok();
}


function machines_live(): void
{
    $pdo = Db::pdo();
    $id = int_or_null($_GET['machine_id'] ?? null);
    $sql = "SELECT * FROM machines WHERE active=1 AND kind='maestro_rest'";
    $params = [];
    if ($id) { $sql .= ' AND id=?'; $params[] = $id; }
    $sql .= ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sync = new MachineSyncService();
    $out = [];
    foreach ($stmt->fetchAll() as $machine) {
        try { $out[(string)$machine['id']] = $sync->syncLive($machine); }
        catch (Throwable $e) { $out[(string)$machine['id']] = ['success'=>false,'error'=>$e->getMessage()]; }
    }
    ApiResponse::ok($out);
}

function maestro_orders_bulk(): void
{
    $d = input_json();
    $machine = get_machine(require_id($d['machine_id'] ?? 1, 'machine_id'));
    $action = str_or_null($d['action'] ?? 'open');
    if (!in_array($action, ['open','close'], true)) ApiResponse::error('Azione bulk non valida', 400);
    $jobIds = array_values(array_unique(array_filter(array_map('intval', (array)($d['job_ids'] ?? [])))));
    if (!$jobIds) ApiResponse::error('Seleziona almeno una commessa', 400);
    if (count($jobIds) > 100) ApiResponse::error('Massimo 100 commesse per invio', 400);

    $client = new MaestroClient($machine);
    $pdo = Db::pdo();
    $results = [];
    foreach ($jobIds as $jobId) {
        $job = get_job($jobId);
        $orderName = normalize_order_name($job['job_code']);
        $res = $action === 'open' ? $client->openOrder($orderName) : $client->closeOrder($orderName);
        $pdo->prepare('INSERT INTO maestro_order_events (job_id, machine_id, action, request_value, http_code, success, response_body, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$jobId, (int)$machine['id'], $action, $orderName, $res['http_code'], (int)$res['success'], $res['body'], $res['error']]);
        if ($res['success']) {
            $status = $action === 'open' ? 'aperta' : 'chiusa';
            $closed = $action === 'close' ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE jobs SET machine_id=?, status=?, closed_at=IF(? IS NULL,closed_at,COALESCE(closed_at,?)) WHERE id=?')
                ->execute([(int)$machine['id'], $status, $closed, $closed, $jobId]);
        }
        $results[] = ['job_id'=>$jobId,'job_code'=>$job['job_code'],'success'=>$res['success'],'http_code'=>$res['http_code'],'error'=>$res['error']];
    }
    ApiResponse::ok(['action'=>$action,'results'=>$results]);
}

function scheduling_get(): void
{
    $pdo = Db::pdo();
    $last = $pdo->query('SELECT * FROM scheduler_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    ApiResponse::ok([
        'enabled' => Settings::getBool('scheduler.enabled', true),
        'interval_minutes' => Settings::getInt('scheduler.interval_minutes', 5),
        'production_lookback_minutes' => Settings::getInt('scheduler.production_lookback_minutes', 15),
        'alarm_lookback_minutes' => Settings::getInt('scheduler.alarm_lookback_minutes', 60),
        'page_limit' => Settings::getInt('scheduler.page_limit', 100),
        'dashboard_refresh_seconds' => Settings::getInt('dashboard.refresh_seconds', 10),
        'last_run' => $last,
    ]);
}

function scheduling_save(): void
{
    $d = input_json();
    $interval = max(1, min(1440, (int)($d['interval_minutes'] ?? 5)));
    $prod = max($interval, min(10080, (int)($d['production_lookback_minutes'] ?? max(15,$interval*2))));
    $alarm = max($interval, min(10080, (int)($d['alarm_lookback_minutes'] ?? max(60,$interval*2))));
    $limit = max(10, min(500, (int)($d['page_limit'] ?? 100)));
    $refresh = max(5, min(120, (int)($d['dashboard_refresh_seconds'] ?? 10)));
    Settings::setMany([
        'scheduler.enabled' => !empty($d['enabled']) ? '1' : '0',
        'scheduler.interval_minutes' => $interval,
        'scheduler.production_lookback_minutes' => $prod,
        'scheduler.alarm_lookback_minutes' => $alarm,
        'scheduler.page_limit' => $limit,
        'dashboard.refresh_seconds' => $refresh,
    ]);
    scheduling_get();
}

function scheduler_run_manual(): void
{
    $pdo = Db::pdo();
    $d = input_json();
    $triggeredBy = (($d['triggered_by'] ?? 'manual') === 'cron') ? 'cron' : 'manual';
    $machines = $pdo->query("SELECT * FROM machines WHERE active=1 AND kind='maestro_rest' ORDER BY id")->fetchAll();
    $pdo->prepare('INSERT INTO scheduler_runs (started_at,success,triggered_by) VALUES (NOW(),0,?)')->execute([$triggeredBy]);
    $runId = (int)$pdo->lastInsertId();
    $sync = new MachineSyncService();
    $details = [];
    $ok = 0;
    foreach ($machines as $machine) {
        try {
            $res = $sync->syncFull($machine,
                Settings::getInt('scheduler.production_lookback_minutes',15),
                Settings::getInt('scheduler.alarm_lookback_minutes',60),
                Settings::getInt('scheduler.page_limit',100));
            $details[$machine['name']] = $res;
            if (!empty($res['success'])) $ok++;
        } catch (Throwable $e) { $details[$machine['name']] = ['success'=>false,'error'=>$e->getMessage()]; }
    }
    $success = count($machines) === 0 || $ok === count($machines);
    $pdo->prepare('UPDATE scheduler_runs SET finished_at=NOW(),success=?,machines_total=?,machines_ok=?,details_json=? WHERE id=?')
        ->execute([(int)$success,count($machines),$ok,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$runId]);
    ApiResponse::ok(['run_id'=>$runId,'success'=>$success,'details'=>$details]);
}

function costs_get(): void
{
    ApiResponse::ok([
        'machine_hour' => Settings::getFloat('cost.machine_hour', 0),
        'edge_meter' => Settings::getFloat('cost.edge_meter', 0),
        'fixed_job' => Settings::getFloat('cost.fixed_job', 0),
        'overhead_percent' => Settings::getFloat('cost.overhead_percent', 0),
    ]);
}

function costs_save(): void
{
    $d = input_json();
    $values = [];
    foreach (['machine_hour','edge_meter','fixed_job','overhead_percent'] as $key) {
        $value = max(0, (float)($d[$key] ?? 0));
        $values['cost.' . $key] = number_format($value, 4, '.', '');
    }
    Settings::setMany($values);
    costs_get();
}

function company_settings_get(): void
{
    ApiResponse::ok([
        'name' => Settings::get('company.name', ''),
        'address' => Settings::get('company.address', ''),
        'postal_code' => Settings::get('company.postal_code', ''),
        'city' => Settings::get('company.city', ''),
        'province' => Settings::get('company.province', ''),
        'country' => Settings::get('company.country', 'Italia'),
        'vat_number' => Settings::get('company.vat_number', ''),
        'tax_code' => Settings::get('company.tax_code', ''),
        'phone' => Settings::get('company.phone', ''),
        'email' => Settings::get('company.email', ''),
        'pec' => Settings::get('company.pec', ''),
        'sdi' => Settings::get('company.sdi', ''),
        'website' => Settings::get('company.website', ''),
        'report_footer' => Settings::get('company.report_footer', ''),
        'logo_path' => Settings::get('company.logo_path', ''),
    ]);
}

function company_settings_save(): void
{
    $d = input_json();
    $fields = ['name','address','postal_code','city','province','country','vat_number','tax_code','phone','email','pec','sdi','website','report_footer'];
    $values = [];
    foreach ($fields as $field) {
        $values['company.' . $field] = str_or_null($d[$field] ?? null) ?? '';
    }
    Settings::setMany($values);
    company_settings_get();
}

function company_logo_upload(): void
{
    if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
        ApiResponse::error('File logo mancante', 400);
    }
    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        ApiResponse::error('Errore caricamento logo', 400);
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 2 * 1024 * 1024) {
        ApiResponse::error('Il logo deve avere dimensione massima 2 MB', 400);
    }

    $mime = '';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($file['tmp_name']);
    }
    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) {
        ApiResponse::error('Formato logo non supportato. Usa PNG, JPG o WebP', 400);
    }

    $dir = __DIR__ . '/uploads/company';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossibile creare la cartella del logo');
    }

    foreach (glob($dir . '/logo.*') ?: [] as $old) {
        if (is_file($old)) @unlink($old);
    }

    $filename = 'logo.' . $allowed[$mime];
    $destination = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Impossibile salvare il logo');
    }

    $relative = 'uploads/company/' . $filename;
    Settings::setMany(['company.logo_path' => $relative]);
    ApiResponse::ok(['logo_path' => $relative]);
}

function job_report(): void
{
    $jobId = require_id($_GET['job_id'] ?? null, 'job_id');
    $pdo = Db::pdo();
    $stmt = $pdo->prepare('SELECT j.*,c.company_name,m.name machine_name FROM jobs j JOIN clients c ON c.id=j.client_id LEFT JOIN machines m ON m.id=j.machine_id WHERE j.id=?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if (!$job) ApiResponse::error('Commessa non trovata',404);

    $stmt = $pdo->prepare("SELECT COUNT(*) panels, MIN(datetime_start) first_start, MAX(datetime_end) last_end,
        COALESCE(SUM(edge_consumption_lh),0) edge_mm,
        COALESCE(SUM(CASE WHEN datetime_start IS NOT NULL AND datetime_end IS NOT NULL AND datetime_end>=datetime_start THEN TIMESTAMPDIFF(SECOND,datetime_start,datetime_end) ELSE 0 END),0) process_seconds
        FROM production_records WHERE job_id=?");
    $stmt->execute([$jobId]);
    $agg = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT COALESCE(edge_name_lh,\'Senza bordo\') edge_name, COUNT(*) panels, COALESCE(SUM(edge_consumption_lh),0) edge_mm FROM production_records WHERE job_id=? GROUP BY edge_name_lh ORDER BY edge_mm DESC');
    $stmt->execute([$jobId]);
    $edges = $stmt->fetchAll();

    $hours = ((float)$agg['process_seconds']) / 3600;
    $edgeMeters = ((float)$agg['edge_mm']) / 1000;
    $machineRate = Settings::getFloat('cost.machine_hour',0);
    $edgeRate = Settings::getFloat('cost.edge_meter',0);
    $fixed = Settings::getFloat('cost.fixed_job',0);
    $overheadPct = Settings::getFloat('cost.overhead_percent',0);
    $machineCost = $hours * $machineRate;
    $edgeCost = $edgeMeters * $edgeRate;
    $subtotal = $machineCost + $edgeCost + $fixed;
    $overhead = $subtotal * ($overheadPct / 100);
    $total = $subtotal + $overhead;

    ApiResponse::ok([
        'job'=>$job,
        'production'=>[
            'panels'=>(int)$agg['panels'],'first_start'=>$agg['first_start'],'last_end'=>$agg['last_end'],
            'process_seconds'=>(int)$agg['process_seconds'],'process_hours'=>round($hours,4),'edge_meters'=>round($edgeMeters,3),
            'edges'=>$edges,
        ],
        'rates'=>['machine_hour'=>$machineRate,'edge_meter'=>$edgeRate,'fixed_job'=>$fixed,'overhead_percent'=>$overheadPct],
        'costs'=>['machine'=>round($machineCost,2),'edge'=>round($edgeCost,2),'fixed'=>round($fixed,2),'subtotal'=>round($subtotal,2),'overhead'=>round($overhead,2),'total'=>round($total,2)],
    ]);
}
