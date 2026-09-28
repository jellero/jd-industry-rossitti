<?php
$config = [
    'db' => [
        'host' => getenv('COMMESSE_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('COMMESSE_DB_PORT') ?: 3306),
        'name' => getenv('COMMESSE_DB_NAME') ?: 'commesse_lite',
        'user' => getenv('COMMESSE_DB_USER') ?: 'commesse_user',
        'pass' => getenv('COMMESSE_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'timezone' => getenv('COMMESSE_TIMEZONE') ?: 'Europe/Rome',
        'debug' => filter_var(getenv('COMMESSE_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN),
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

return $config;
