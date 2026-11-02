<?php
declare(strict_types=1);

const EVENT_TYPES = ['off' => 'Off Snow', 'on' => 'On Snow', 'race' => 'Rennen'];
const EVENT_STATUS = ['draft' => 'Entwurf', 'open' => 'Freigeschaltet', 'cancelled' => 'Abgesagt'];
const SKILLS = [1 => 'Blau 1', 2 => 'Blau 2', 3 => 'Rot 3', 4 => 'Rot 4', 5 => 'Schwarz 5'];
const WEEKDAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
const WEEKDAY_NAMES = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 0 => 'Sonntag'];
const ROLES = ['leiter' => 'Leiter', 'hauptleiter' => 'Hauptleiter', 'admin' => 'Admin'];
const ROLE_RANK = ['leiter' => 1, 'hauptleiter' => 2, 'admin' => 3];
const FEEDBACK_KINDS = ['skill' => 'Stufe', 'injury' => 'Verletzung', 'note' => 'Notiz'];
const UPLOAD_TYPES = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function base_url(): string
{
    global $config;
    if ($config['base_url'] !== '') {
        return rtrim($config['base_url'], '/');
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (str_ends_with($dir, '/admin')) {
        $dir = substr($dir, 0, -6);
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function family_link(array $family): string
{
    return base_url() . '/?f=' . $family['token'];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Die Seite war zu lange offen. Bitte zurück und nochmals versuchen.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function current_leader(): ?array
{
    if (empty($_SESSION['leader_id'])) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM leaders WHERE id = ?');
    $st->execute([$_SESSION['leader_id']]);
    return $st->fetch() ?: null;
}

/** Hat der Leiter mindestens diese Rolle? (leiter < hauptleiter < admin) */
function has_role(?array $leader, string $role): bool
{
    return $leader !== null && (ROLE_RANK[$leader['role']] ?? 0) >= ROLE_RANK[$role];
}

function require_leader(string $role = 'leiter'): array
{
    $l = current_leader();
    if (!$l) {
        redirect('login.php');
    }
    if (!has_role($l, $role)) {
        http_response_code(403);
        exit('Diese Seite ist nur für ' . ROLES[$role] . ' freigegeben.');
    }
    return $l;
}

/** Kategorien (Off Snow, On Snow, Rennen, eigene), nach id. */
function categories(): array
{
    static $cats = null;
    return $cats ??= array_column(db()->query('SELECT * FROM event_categories ORDER BY sort, name')->fetchAll(), null, 'id');
}

function category_name(array $event): string
{
    return categories()[$event['category_id'] ?? 0]['name'] ?? EVENT_TYPES[$event['type']];
}

/**
 * Freitext wie in WhatsApp formatieren: *fett*, _kursiv_, Zeilen mit «- » als Aufzählung,
 * Links werden klickbar. Alles andere wird escaped.
 */
function format_text(string $text): string
{
    $inline = function (string $line): string {
        $h = e($line);
        $h = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '<b>$1</b>', $h);
        $h = preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u', '<i>$1</i>', $h);
        return preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)])~u', '<a href="$1" target="_blank" rel="noopener">$1</a>', $h);
    };
    $out = [];
    $list = false;
    foreach (preg_split('/\R/u', trim($text)) as $line) {
        if (preg_match('/^\s*[-•]\s+(.*)$/u', $line, $m)) {
            if (!$list) { $out[] = '<ul>'; $list = true; }
            $out[] = '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if ($list) { $out[] = '</ul>'; $list = false; }
        $out[] = trim($line) === '' ? '<br>' : $inline($line) . '<br>';
    }
    if ($list) $out[] = '</ul>';
    return '<div class="richtext">' . preg_replace('~(<br>)+$~', '', implode('', $out)) . '</div>';
}

function event_files(int $eventId): array
{
    $st = db()->prepare('SELECT * FROM event_files WHERE event_id = ? ORDER BY id');
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function fmt_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

function fmt_date(string $ymd): string
{
    $t = strtotime($ymd);
    return WEEKDAYS[(int)date('w', $t)] . ' ' . date('d.m.', $t);
}

function fmt_deadline(string $dt): string
{
    $t = strtotime($dt);
    return WEEKDAYS[(int)date('w', $t)] . ' ' . date('d.m., H:i', $t);
}

function deadline_passed(array $event): bool
{
    return time() > strtotime($event['deadline']);
}

/** Saisonjahr: Eine Saison ab Juli zählt zum Folgejahr (Saison 26/27 = 2027). */
function season_year(?int $now = null): int
{
    $now ??= time();
    $y = (int)date('Y', $now);
    return (int)date('n', $now) >= 7 ? $y + 1 : $y;
}

function race_category(int $birthYear, ?int $seasonYear = null): string
{
    $age = ($seasonYear ?? season_year()) - $birthYear;
    if ($age < 10) return 'U10';
    if ($age < 12) return 'U12';
    if ($age < 14) return 'U14';
    return 'U16';
}

function piste(int $skill): string
{
    return $skill >= 5 ? 'black' : ($skill >= 3 ? 'red' : 'blue');
}

/** 079 123 45 67 → 41791234567, für wa.me-Links. */
function normalize_phone(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    elseif (str_starts_with($d, '0')) $d = '41' . substr($d, 1);
    return $d;
}

function wa_link(string $text, string $phone = ''): string
{
    $p = $phone !== '' ? normalize_phone($phone) : '';
    return 'https://wa.me/' . $p . '?text=' . rawurlencode($text);
}

function initial_name(array $kid): string
{
    return $kid['first_name'] . ' ' . mb_substr($kid['family_name'], 0, 1) . '.';
}

function layout_head(string $title, array $nav = [], string $active = ''): void
{
    global $config;
    $prefix = str_ends_with(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/admin') ? '../' : '';
    ?><!doctype html>
<html lang="de-CH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($config['site_name']) ?></title>
<link rel="stylesheet" href="<?= $prefix ?>assets/style.css">
</head>
<body>
<main class="app">
<?php if ($m = flash()): ?><div class="toast" role="status"><?= e($m) ?></div><?php endif ?>
<?php
    if ($nav) {
        echo '<nav class="tabs" aria-label="Navigation"><div class="in">';
        foreach ($nav as $href => $label) {
            $cur = $href === $active ? ' aria-current="page"' : '';
            echo '<a href="' . e($href) . '"' . $cur . '>' . e($label) . '</a>';
        }
        echo '</div></nav>';
    }
}

function layout_foot(): void
{
    $prefix = str_ends_with(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/admin') ? '../' : '';
    echo '</main><script src="' . $prefix . 'assets/app.js"></script></body></html>';
}

function admin_nav(): array
{
    $me = current_leader();
    $nav = ['index.php' => 'Events'];
    if (has_role($me, 'hauptleiter')) {
        $nav['families.php'] = 'Familien';
        $nav['templates.php'] = 'Vorlagen';
    }
    $nav['leaders.php'] = 'Team';
    if (has_role($me, 'admin')) {
        $nav['stats.php'] = 'Statistik';
    }
    return $nav;
}

function upload_dir(): string
{
    global $config;
    $dir = $config['data_dir'] . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

/** Speichert einen hochgeladenen Anhang zu einem Event. Gibt eine Meldung für die Leiter zurück. */
function store_upload(int $eventId, ?array $file): string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Bitte eine Datei auswählen.';
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > UPLOAD_MAX_BYTES) {
        return 'Die Datei ist zu gross (max. 10 MB).';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'Hochladen hat nicht geklappt. Bitte nochmals versuchen.';
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!isset(UPLOAD_TYPES[$ext])) {
        return 'Erlaubt sind PDF, JPG, PNG, Word und Excel.';
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], upload_dir() . '/' . $stored)) {
        return 'Die Datei konnte nicht gespeichert werden. Ist der Ordner data/ beschreibbar?';
    }
    $name = preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', basename((string)$file['name']));
    db()->prepare('INSERT INTO event_files (event_id, orig_name, stored_name, mime, size) VALUES (?, ?, ?, ?, ?)')
        ->execute([$eventId, mb_substr($name, 0, 120), $stored, UPLOAD_TYPES[$ext], (int)$file['size']]);
    return 'Anhang hochgeladen.';
}
