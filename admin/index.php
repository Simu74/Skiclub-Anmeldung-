<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader();
$isHead = has_role($me, 'hauptleiter');
$pdo = db();

$showPast = !empty($_GET['alle']);
$where = [];
if (!$showPast) $where[] = "e.date >= date('now', 'localtime')";
if (!$isHead) $where[] = "e.status <> 'draft'";
$sql = 'SELECT e.*,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = \'in\') AS n_in,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = \'out\') AS n_out,
    (SELECT COALESCE(SUM(seats), 0) FROM drivers d WHERE d.event_id = e.id) AS seats,
    (SELECT COUNT(*) FROM leader_availability a JOIN leaders l ON l.id = a.leader_id WHERE a.event_id = e.id AND a.status = \'yes\' AND l.role <> \'admin\') AS n_leaders,
    (SELECT status FROM leader_availability a WHERE a.event_id = e.id AND a.leader_id = :me) AS my_status,
    (SELECT GROUP_CONCAT(group_no) FROM group_leaders g WHERE g.event_id = e.id AND g.leader_id = :me) AS my_groups
  FROM events e ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.date' . ($showPast ? ' DESC' : '');
$st = $pdo->prepare($sql);
$st->execute(['me' => $me['id']]);
$events = $st->fetchAll();
$templates = $isHead ? $pdo->query('SELECT id, name FROM event_templates ORDER BY sort, name')->fetchAll() : [];

layout_head('Events', admin_nav(), 'index.php');
?>
<div><p class="eyebrow"><?= e($config['site_name']) ?></p><h1>Events</h1></div>

<?php if ($isHead): ?>
<div class="card">
  <h3>Neues Event aus Vorlage</h3>
  <div class="seg">
    <?php foreach ($templates as $t): ?><a class="segbtn" href="event_edit.php?vorlage=<?= (int)$t['id'] ?>"><?= e($t['name']) ?></a><?php endforeach ?>
    <a class="segbtn" href="event_edit.php">Leer</a>
  </div>
  <p class="note">Neue Events sind zuerst ein Entwurf. Eltern sehen sie erst nach dem Freischalten. <a href="templates.php">Vorlagen bearbeiten</a></p>
</div>
<?php else: ?>
<p class="note">Trag dich im <a href="plan.php">Leiterplan</a> für die Trainings ein, an denen du leitest. Vor Ort erfasst du die Präsenz und Rückmeldungen zu den Kindern.</p>
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
  <?php if ($ev['my_groups']): ?><p>Du leitest <b>Gruppe <?= e(str_replace(',', ', ', $ev['my_groups'])) ?></b>.</p>
  <?php elseif ($ev['my_status'] === 'yes'): ?><p class="note">Du bist im Leiterplan eingetragen.</p>
  <?php elseif ($ev['my_status'] === null && $ev['status'] === 'open' && leads($me)): ?><p class="note">Du hast dich im <a href="plan.php#e<?= (int)$ev['id'] ?>">Leiterplan</a> noch nicht eingetragen.</p><?php endif ?>
  <a class="btn ghost small" href="event.php?id=<?= (int)$ev['id'] ?>">Öffnen</a>
</article>
<?php endforeach ?>
<?php if (!$events): ?><div class="card"><p><?= $isHead ? 'Keine kommenden Events. Erstellen Sie oben eines aus einer Vorlage.' : 'Im Moment sind keine Events freigeschaltet.' ?></p></div><?php endif ?>
<p class="note"><a href="?alle=<?= $showPast ? '0' : '1' ?>"><?= $showPast ? 'Nur kommende Events zeigen' : 'Auch vergangene Events zeigen' ?></a></p>
<?php layout_foot();
