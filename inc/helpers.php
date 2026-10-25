<?php
declare(strict_types=1);

const EVENT_TYPES = ['off' => 'Off Snow', 'on' => 'On Snow', 'race' => 'Rennen'];
const EVENT_STATUS = ['draft' => 'Entwurf', 'open' => 'Freigeschaltet', 'cancelled' => 'Abgesagt'];
const SKILLS = [1 => 'Blau 1', 2 => 'Blau 2', 3 => 'Rot 3', 4 => 'Rot 4', 5 => 'Schwarz 5'];
const WEEKDAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

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

function require_leader(bool $admin = false): array
{
    $l = current_leader();
    if (!$l) {
        redirect('login.php');
    }
    if ($admin && !$l['is_admin']) {
        http_response_code(403);
        exit('Nur für Admins.');
    }
    return $l;
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
    $nav = ['index.php' => 'Events', 'families.php' => 'Familien', 'leaders.php' => 'Leiter'];
    if (current_leader()['is_admin'] ?? false) {
        $nav['stats.php'] = 'Statistik';
    }
    return $nav;
}
