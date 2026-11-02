<?php
// Leiter, Hauptleiter und Admin abmelden: Session vollständig löschen, damit beim
// Wechsel auf eine andere Person / Rolle nichts von der alten Anmeldung übrig bleibt.

declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'],
        'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax']);
}
session_destroy();
session_start();
session_regenerate_id(true);
flash('Du bist abgemeldet.');
redirect('login.php');
