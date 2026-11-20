<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader();
$isHead = has_role($me, 'hauptleiter');
$pdo = db();

// Ansicht: Hauptleiter sehen die Events der Woche, Leiter ihre eigenen Einsätze.
// ?alle=kommend zeigt alle kommenden, ?alle=1 auch vergangene Events.
$mode = (string)($_GET['alle'] ?? '');
$showPast = $mode === '1';
$where = [];
if (!$showPast) $where[] = "e.date >= date('now', 'localtime')";
if (!$isHead) {
    $where[] = "e.status <> 'draft'";
    // Leiter sehen nur Events, für die sie im Leiterplan eingetragen oder einer Gruppe zugeteilt sind
    $where[] = "(EXISTS (SELECT 1 FROM leader_availability a WHERE a.event_id = e.id AND a.leader_id = :me AND a.status = 'yes')
        OR EXISTS (SELECT 1 FROM group_leaders g WHERE g.event_id = e.id AND g.leader_id = :me))";
}
$sql = 'SELECT e.*,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = \'in\') AS n_in,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = \'out\') AS n_out,
    (SELECT COALESCE(SUM(seats), 0) FROM drivers d WHERE d.event_id = e.id) AS seats,
    (SELECT COUNT(*) FROM leader_availability a JOIN leaders l ON l.id = a.leader_id WHERE a.event_id = e.id AND a.status = \'yes\' AND l.role = \'leiter\') AS n_leaders,
    (SELECT GROUP_CONCAT(group_no) FROM group_leaders g WHERE g.event_id = e.id AND g.leader_id = :me) AS my_groups
  FROM events e ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.date' . ($showPast ? ' DESC' : '') . ', e.time_text';
$st = $pdo->prepare($sql);
$st->execute(['me' => $me['id']]);
$events = $st->fetchAll();
$templates = $isHead ? $pdo->query('SELECT id, name FROM event_templates ORDER BY sort, name')->fetchAll() : [];

// Hauptleiter: nur die aktuelle Woche (Montag bis Sonntag). Ist sie leer, die Woche des nächsten Events.
$weekLabel = '';
$more = 0;
if ($isHead && $mode === '' && $events) {
    [$from, $to] = week_of(date('Y-m-d'));
    $inWeek = array_values(array_filter($events, fn($e) => $e['date'] <= $to));
    if (!$inWeek) {
        [$from, $to] = week_of($events[0]['date']);
        $inWeek = array_values(array_filter($events, fn($e) => $e['date'] <= $to));
    }
    $weekLabel = ($from <= date('Y-m-d') ? 'Diese Woche' : 'Nächste Woche mit Events') . ': ' . date('d.m.', strtotime($from)) . '–' . date('d.m.', strtotime($to));
    $more = count($events) - count($inWeek);
    $events = $inWeek;
}

layout_head('Events', admin_nav(), 'index.php');
?>
<div><p class="eyebrow"><?= e($config['site_name']) ?></p><h1><?= $isHead ? 'Events' : 'Meine Einsätze' ?></h1></div>

<?php if ($isHead): ?>
<div class="card">
  <h3>Neues Event aus Vorlage</h3>
  <div class="seg">
    <?php foreach ($templates as $t): ?><a class="segbtn" href="event_edit.php?vorlage=<?= (int)$t['id'] ?>"><?= e($t['name']) ?></a><?php endforeach ?>
    <a class="segbtn" href="event_edit.php">Leer</a>
  </div>
  <p class="note">Neue Events sind zuerst ein Entwurf. Eltern sehen sie erst nach dem Freischalten. <a href="templates.php">Vorlagen bearbeiten</a></p>
</div>
<?php if ($weekLabel): ?><h2 class="month"><?= e($weekLabel) ?></h2><?php endif ?>
<?php else: ?>
<p class="note">Hier siehst du die Events, für die du im <a href="plan.php">Leiterplan</a> eingetragen bist oder eine Gruppe leitest.</p>
<?php endif ?>

<?php foreach ($events as $ev): ?>
<article class="card">
  <div class="row between"><span class="date"><?= fmt_date($ev['date']) ?><?= $ev['time_text'] !== '' ? ' · ' . e($ev['time_text']) : '' ?></span>
    <span class="pill <?= e($ev['status']) ?>"><?= EVENT_STATUS[$ev['status']] ?></span></div>
  <div class="row between"><h2><a href="event.php?id=<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></a></h2>
    <span class="pill <?= e($ev['type']) ?>"><?= e(category_name($ev)) ?></span></div>
  <div class="row stats">
    <div><div class="count"><?= (int)$ev['n_in'] ?></div><div class="note">angemeldet</div></div>
    <div><div class="count"><?= (int)$ev['n_out'] ?></div><div class="note">abgemeldet</div></div>
    <div><div class="count"><?= (int)$ev['n_leaders'] ?></div><div class="note">Leiter dabei</div></div>
  </div>
  <?php if ($ev['my_groups']): ?><p>Du leitest <b>Gruppe <?= e(str_replace(',', ', ', $ev['my_groups'])) ?></b>.</p><?php endif ?>
  <a class="btn ghost small" href="event.php?id=<?= (int)$ev['id'] ?>">Öffnen</a>
</article>
<?php endforeach ?>
<?php if (!$events): ?><div class="card"><p><?= $isHead
    ? 'Keine kommenden Events. Erstelle oben eines aus einer Vorlage oder im Plan eine Serie für die Saison.'
    : ($showPast ? 'Keine Einsätze gefunden.' : 'Im Moment bist du für keine kommenden Events eingetragen. Trag dich im <a href="plan.php">Leiterplan</a> ein.') ?></p></div><?php endif ?>

<?php if ($more): ?><a class="btn ghost" href="?alle=kommend">Alle kommenden Events zeigen (<?= $more ?> weitere)</a><?php endif ?>

<p class="note">
  <?php if ($mode !== ''): ?><a href="index.php"><?= $isHead ? 'Nur aktuelle Woche zeigen' : 'Nur kommende Einsätze zeigen' ?></a><?php endif ?>
  <?php if (!$showPast): ?><?= $mode !== '' ? ' · ' : '' ?><a href="?alle=1">Auch vergangene Events zeigen</a><?php endif ?>
</p>
<?php layout_foot();
