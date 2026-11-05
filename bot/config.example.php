<?php

// Copy this file to config.php and provide secrets through environment or .env.
$modasrEnvFile = __DIR__ . '/.env';
if (is_file($modasrEnvFile)) {
    $modasrEnvValues = parse_ini_file($modasrEnvFile, false, INI_SCANNER_RAW);
    if (is_array($modasrEnvValues)) {
        foreach ($modasrEnvValues as $name => $value) {
            if (is_string($name) && is_string($value) && $value !== '') {
                putenv($name . '=' . $value);
            }
        }
    }
}

$request_exec_timeout = 5000;
$dbhost = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'modasr';
$usernamedb = getenv('DB_USER') ?: 'modasr';
$passworddb = getenv('DB_PASSWORD') ?: '';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
];
$dsn = 'mysql:host=' . $dbhost . ';dbname=' . $dbname . ';charset=utf8mb4';
try {
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (\PDOException $e) {
    error_log('MODASR database connection failed');
    die('Database connection failed');
}

$APIKEY = getenv('BOT_API_TOKEN') ?: (getenv('APIKEY') ?: '');
$platform = getenv('MODASR_PLATFORM') ?: 'telegram';
$REPORTS_BOT_TOKEN = getenv('REPORTS_BOT_TOKEN') ?: '';
$PLATFORM_TOKENS = [
    'telegram' => getenv('TELEGRAM_TOKEN') ?: $APIKEY,
    'bale' => getenv('BALE_TOKEN') ?: '',
    'rubika' => getenv('RUBIKA_TOKEN') ?: '',
    'splus' => getenv('SOROUSH_TOKEN') ?: '',
];
$adminnumber = getenv('ADMIN_IDS') ?: '';
$domainhosts = getenv('DOMAIN_HOSTS') ?: '';
$usernamebot = getenv('BOT_USERNAME') ?: '';
$GOOGLE_CLIENT_ID = getenv('GOOGLE_CLIENT_ID') ?: '';
$GOOGLE_CLIENT_SECRET = getenv('GOOGLE_CLIENT_SECRET') ?: '';
$RUBIKA_BOT_USERNAME = getenv('RUBIKA_BOT_USERNAME') ?: '';
