<?php
// Liefert Anhänge von Events aus. Zugriff für eingeloggte Leiter oder Familien mit gültigem Link,
// für Familien nur bei freigeschalteten oder abgesagten Events.

declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$st = db()->prepare('SELECT f.*, e.status FROM event_files f JOIN events e ON e.id = f.event_id WHERE f.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$file = $st->fetch();

$allowed = false;
if ($file) {
    if (current_leader()) {
        $allowed = true;
    } elseif ($file['status'] !== 'draft') {
        $token = (string)($_GET['f'] ?? $_COOKIE['familie'] ?? '');
        $fam = db()->prepare('SELECT 1 FROM families WHERE token = ?');
        $fam->execute([$token]);
        $allowed = preg_match('/^[a-f0-9]{32}$/', $token) && $fam->fetchColumn();
    }
}
$path = $file ? upload_dir() . '/' . basename($file['stored_name']) : '';
if (!$allowed || !is_file($path)) {
    http_response_code(404);
    exit('Datei nicht gefunden oder kein Zugriff. Bitte die Seite über den persönlichen Link öffnen.');
}

$inline = in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: " . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['orig_name']));
header('Cache-Control: private, max-age=3600');
readfile($path);
