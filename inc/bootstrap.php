<?php
// Gemeinsamer Start für alle Seiten: Konfiguration, Datenbank, Session, Hilfsfunktionen.

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$config = [
    'site_name' => 'Skiklub Nachwuchs',
    'data_dir'  => APP_ROOT . '/data',
    'base_url'  => '',          // leer = automatisch erkennen
    'timezone'  => 'Europe/Zurich',
];
if (is_file(APP_ROOT . '/config.php')) {
    $config = array_merge($config, require APP_ROOT . '/config.php');
}
date_default_timezone_set($config['timezone']);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/groups.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
