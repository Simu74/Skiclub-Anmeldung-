<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader();
$pdo = db();

$showPast = !empty($_GET['alle']);
$sql = 'SELECT e.*,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = \'in\') AS n_in,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = \'out\') AS n_out,
    (SELECT COALESCE(SUM(seats), 0) FROM drivers d WHERE d.event_id = e.id) AS seats
  FROM events e ' . ($showPast ? '' : "WHERE e.date >= date('now', 'localtime') ") . 'ORDER BY e.date' . ($showPast ? ' DESC' : '');
$events = $pdo->query($sql)->fetchAll();

layout_head('Events', admin_nav(), 'index.php');
?>
<div class="row between"><div><p class="eyebrow">Leiter · <?= e($me['name']) ?></p><h1>Events</h1></div>
  <a class="note" href="logout.php">Abmelden</a></div>

<div class="card">
  <h3>Neues Event aus Vorlage</h3>
  <div class="seg">
    <a class="segbtn" href="event_edit.php?vorlage=off">Off Snow Samstag</a>
    <a class="segbtn" href="event_edit.php?vorlage=on_sa">On Snow Samstag</a>
    <a class="segbtn" href="event_edit.php?vorlage=on_mi">On Snow Mittwoch</a>
    <a class="segbtn" href="event_edit.php?vorlage=race">Rennen</a>
  </div>
  <p class="note">Neue Events sind zuerst ein Entwurf. Eltern sehen sie erst nach dem Freischalten.</p>
</div>

<?php foreach ($events as $ev): ?>
<article class="card">
  <div class="row between"><span class="date"><?= fmt_date($ev['date']) ?><?= $ev['time_text'] !== '' ? ' · ' . e($ev['time_text']) : '' ?></span>
    <span class="pill <?= e($ev['status']) ?>"><?= EVENT_STATUS[$ev['status']] ?></span></div>
  <div class="row between"><h2><a href="event.php?id=<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></a></h2>
    <span class="pill <?= e($ev['type']) ?>"><?= EVENT_TYPES[$ev['type']] ?></span></div>
  <div class="row stats">
    <div><div class="count"><?= (int)$ev['n_in'] ?></div><div class="note">angemeldet</div></div>
    <div><div class="count"><?= (int)$ev['n_out'] ?></div><div class="note">abgemeldet</div></div>
    <div><div class="count"><?= (int)$ev['seats'] ?></div><div class="note">Fahrplätze</div></div>
  </div>
  <a class="btn ghost small" href="event.php?id=<?= (int)$ev['id'] ?>">Öffnen: Anmeldungen, Gruppen, WhatsApp</a>
</article>
<?php endforeach ?>
<?php if (!$events): ?><div class="card"><p>Keine kommenden Events. Erstellen Sie oben eines aus einer Vorlage.</p></div><?php endif ?>
<p class="note"><a href="?alle=<?= $showPast ? '0' : '1' ?>"><?= $showPast ? 'Nur kommende Events zeigen' : 'Auch vergangene Events zeigen' ?></a></p>
<?php layout_foot();
