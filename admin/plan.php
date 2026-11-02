<?php
// Leiterplan: Zu Saisonbeginn tragen sich die Leiter für die Trainings ein.
// Hauptleiter sehen pro Event, ob genug Leiter da sind, und ergänzen oder streichen Leiter.

declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader();
$isHead = has_role($me, 'hauptleiter');
$pdo = db();
$showPast = !empty($_GET['alle']);
$back = 'plan.php' . ($showPast ? '?alle=1' : '');

function set_plan(int $eventId, int $leaderId, string $status): void
{
    $pdo = db();
    if ($status === 'clear') {
        $pdo->prepare('DELETE FROM leader_availability WHERE event_id = ? AND leader_id = ?')->execute([$eventId, $leaderId]);
    } elseif (in_array($status, ['yes', 'no'], true)) {
        // Bemerkung behalten, nur den Status ändern
        $pdo->prepare('INSERT INTO leader_availability (event_id, leader_id, status) VALUES (?, ?, ?)
            ON CONFLICT (event_id, leader_id) DO UPDATE SET status = excluded.status')->execute([$eventId, $leaderId, $status]);
    }
}

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $eid = (int)($_POST['event_id'] ?? 0);
    $anchor = $eid ? '#e' . $eid : '';
    if ($a === 'me') {
        set_plan($eid, (int)$me['id'], (string)($_POST['status'] ?? ''));
        flash(($_POST['status'] ?? '') === 'yes' ? 'Eingetragen. Danke!' : 'Gespeichert.');
    } elseif (!$isHead) {
        http_response_code(403);
        exit('Dafür fehlt die Berechtigung.');
    } elseif ($a === 'set') {
        set_plan($eid, (int)$_POST['leader_id'], (string)($_POST['status'] ?? ''));
        flash('Leiterplan angepasst.');
    } elseif ($a === 'needed') {
        $v = trim((string)($_POST['needed'] ?? ''));
        $pdo->prepare('UPDATE events SET leaders_needed = ? WHERE id = ?')->execute([$v === '' ? null : max(0, min(20, (int)$v)), $eid]);
        flash('Bedarf gespeichert.');
    } elseif ($a === 'series') {
        $st = $pdo->prepare('SELECT * FROM event_templates WHERE id = ?');
        $st->execute([(int)$_POST['template_id']]);
        $t = $st->fetch();
        $from = strtotime((string)($_POST['from'] ?? ''));
        $to = strtotime((string)($_POST['to'] ?? ''));
        if (!$t || !$from || !$to || $to < $from || $to - $from > 366 * 86400) {
            flash('Bitte Vorlage und einen Zeitraum (höchstens ein Jahr) wählen.');
        } else {
            $cats = categories();
            $exists = $pdo->prepare('SELECT 1 FROM events WHERE date = ? AND category_id IS ?');
            $n = 0;
            for ($d = $from; $d <= $to; $d = strtotime('+1 day', $d)) {
                if ((int)date('w', $d) !== (int)$t['weekday']) continue;
                $date = date('Y-m-d', $d);
                $exists->execute([$date, $t['category_id']]);
                if ($exists->fetchColumn()) continue;
                $ev = event_from_template($t, $date);
                unset($ev['id']);
                $ev['type'] = $cats[$t['category_id']]['kind'] ?? 'off';
                $cols = array_keys($ev);
                $pdo->prepare('INSERT INTO events (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($ev);
                $n++;
            }
            flash($n ? "$n Entwürfe erstellt. Die Leiter können sich jetzt eintragen." : 'Keine neuen Daten, diese Events gibt es schon.');
        }
        $anchor = '';
    }
    redirect($back . $anchor);
}

$where = ["e.status <> 'cancelled'"];
$where[] = $showPast ? 'e.date >= :from' : "e.date >= date('now', 'localtime')";
$sql = "SELECT e.*,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'in') AS n_in
  FROM events e WHERE " . implode(' AND ', $where) . ' ORDER BY e.date, e.time_text';
$st = $pdo->prepare($sql);
$st->execute($showPast ? ['from' => (season_year() - 1) . '-07-01'] : []);
$events = $st->fetchAll();

$plan = [];   // event_id => leader_id => row
if ($events) {
    $ids = implode(',', array_map('intval', array_column($events, 'id')));
    foreach ($pdo->query("SELECT a.*, l.name FROM leader_availability a JOIN leaders l ON l.id = a.leader_id
        WHERE a.event_id IN ($ids) ORDER BY l.name") as $r) {
        $plan[$r['event_id']][$r['leader_id']] = $r;
    }
}
$leaders = $pdo->query('SELECT id, name, phone FROM leaders ORDER BY name')->fetchAll();
$templates = $isHead ? $pdo->query('SELECT id, name FROM event_templates ORDER BY sort, name')->fetchAll() : [];

// Übersicht: wo fehlen Leiter? / Wo bin ich eingetragen?
$missing = [];
$mineCount = 0;
foreach ($events as $ev) {
    $yes = array_filter($plan[$ev['id']] ?? [], fn($r) => $r['status'] === 'yes');
    $need = leaders_needed($ev);
    if ($need !== null && count($yes) < $need) {
        $missing[] = $ev + ['n_yes' => count($yes), 'need' => $need];
    }
    if (($plan[$ev['id']][$me['id']]['status'] ?? '') === 'yes') $mineCount++;
}

layout_head('Leiterplan', admin_nav(), 'plan.php');
?>
<div><p class="eyebrow"><?= e($config['site_name']) ?></p><h1>Leiterplan</h1></div>

<?php if ($isHead): ?>
<div class="card stack">
  <p>Hier tragen sich die Leiter für die ganze Saison ein. Du siehst pro Event, ob genug Leiter da sind, und kannst Leiter ergänzen oder streichen.
    Der Bedarf richtet sich nach den Anmeldungen (etwa 7 Kinder pro Leiter) oder nach deiner Vorgabe.</p>
  <?php if ($missing):
      $lines = array_map(fn($m) => '• ' . fmt_date($m['date']) . ' ' . $m['title'] . ': ' . ($m['need'] - $m['n_yes']) . ' fehlen', $missing);
      $text = "🙋 *Leiter gesucht*\n" . implode("\n", $lines) . "\n\nBitte im Leiterplan eintragen:\n" . base_url() . '/admin/plan.php'; ?>
    <p class="warnline"><b><?= count($missing) ?> Events</b> haben noch zu wenig Leiter.</p>
    <a class="btn ghost small" target="_blank" rel="noopener" href="<?= e(wa_link($text)) ?>">«Leiter gesucht» per WhatsApp</a>
  <?php else: ?>
    <p class="note">Bei allen Events mit bekanntem Bedarf sind genug Leiter eingetragen.</p>
  <?php endif ?>
</div>

<details class="card"><summary><b>Saison vorbereiten: Termine als Serie erstellen</b></summary>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="series">
    <p class="note">Erstellt für jede Woche im Zeitraum einen Entwurf aus der Vorlage (am Wochentag der Vorlage). So können sich die Leiter schon zu Saisonbeginn eintragen.
      Eltern sehen die Termine erst, wenn du sie einzeln freischaltest.</p>
    <label for="sv">Vorlage</label>
    <select id="sv" name="template_id"><?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach ?></select>
    <div class="row">
      <span class="stack grow"><label for="sf">Von</label><input type="date" id="sf" name="from" required></span>
      <span class="stack grow"><label for="st">Bis</label><input type="date" id="st" name="to" required></span>
    </div>
    <button class="btn small">Serie erstellen</button>
  </form>
</details>
<?php else: ?>
<div class="card">
  <p>Trag dich für alle Trainings ein, an denen du leiten kannst. Du kannst es jederzeit ändern. Die Hauptleiter planen damit die Gruppen.</p>
  <p class="note">Du bist für <b><?= $mineCount ?></b> <?= $mineCount === 1 ? 'Event' : 'Events' ?> eingetragen.</p>
</div>
<?php endif ?>

<?php $month = '';
foreach ($events as $ev):
    $rows = $plan[$ev['id']] ?? [];
    $yes = array_filter($rows, fn($r) => $r['status'] === 'yes');
    $no = array_filter($rows, fn($r) => $r['status'] === 'no');
    $need = leaders_needed($ev);
    $my = $rows[$me['id']]['status'] ?? '';
    $state = $need === null ? '' : (count($yes) >= $need ? 'ok' : 'short');
    $m = MONTHS[(int)date('n', strtotime($ev['date']))] . ' ' . date('Y', strtotime($ev['date']));
    if ($m !== $month): $month = $m; ?><h2 class="month"><?= e($m) ?></h2><?php endif ?>
<article class="card plan <?= $state ?>" id="e<?= (int)$ev['id'] ?>">
  <div class="row between nowrap">
    <div><span class="date"><?= fmt_date($ev['date']) ?><?= $ev['time_text'] !== '' ? ' · ' . e($ev['time_text']) : '' ?></span>
      <h3><?php if ($isHead || $ev['status'] !== 'draft'): ?><a href="event.php?id=<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></a><?php else: ?><?= e($ev['title']) ?><?php endif ?></h3></div>
    <span class="need <?= $state ?>" title="eingetragen / benötigt"><?= count($yes) ?><?= $need !== null ? '/' . $need : '' ?></span>
  </div>
  <p class="note">
    <?php if ($ev['status'] === 'draft'): ?><span class="pill draft">Entwurf</span> <?php endif ?>
    <?= $yes ? 'Leiter: ' . e(implode(', ', array_column($yes, 'name'))) : 'Noch niemand eingetragen.' ?>
    <?php if ($need === null): ?> · Bedarf offen<?php elseif ($state === 'short'): ?> · <b class="warnline"><?= $need - count($yes) ?> fehlen</b><?php endif ?>
    <?php if ($ev['n_in']): ?> · <?= (int)$ev['n_in'] ?> Kinder<?php endif ?>
  </p>
  <form method="post" class="toggle"><?= csrf_field() ?><input type="hidden" name="action" value="me"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
    <button class="tbtn yes" name="status" value="<?= $my === 'yes' ? 'clear' : 'yes' ?>" aria-pressed="<?= $my === 'yes' ? 'true' : 'false' ?>">✓ Ich leite</button>
    <button class="tbtn no" name="status" value="<?= $my === 'no' ? 'clear' : 'no' ?>" aria-pressed="<?= $my === 'no' ? 'true' : 'false' ?>">✗ Kann nicht</button>
  </form>
  <?php if ($isHead): ?>
  <details><summary class="note">Leiter ergänzen / streichen, Bedarf<?= $no ? ' · ' . count($no) . ' kann nicht' : '' ?></summary>
    <div class="stack">
      <?php foreach ($yes as $lid => $r): ?>
        <form method="post" class="row between nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>"><input type="hidden" name="leader_id" value="<?= (int)$lid ?>">
          <span>✓ <b><?= e($r['name']) ?></b><?= $r['note'] !== '' ? ' <span class="note">· ' . e($r['note']) . '</span>' : '' ?></span>
          <button class="linkbtn" name="status" value="clear">streichen</button></form>
      <?php endforeach ?>
      <?php foreach ($no as $r): ?><p class="note">✗ <?= e($r['name']) ?> kann nicht<?= $r['note'] !== '' ? ' · ' . e($r['note']) : '' ?></p><?php endforeach ?>
      <?php $others = array_filter($leaders, fn($l) => !isset($yes[$l['id']])); if ($others): ?>
      <form method="post" class="row nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>"><input type="hidden" name="status" value="yes">
        <select name="leader_id" class="grow" aria-label="Leiter eintragen"><?php foreach ($others as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?><?= isset($no[$l['id']]) ? ' (kann nicht)' : '' ?></option><?php endforeach ?></select>
        <button class="btn ghost small">Eintragen</button></form>
      <?php endif ?>
      <form method="post" class="row nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="needed"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
        <label for="nd<?= (int)$ev['id'] ?>" class="grow">Leiter benötigt</label>
        <input type="number" id="nd<?= (int)$ev['id'] ?>" name="needed" min="0" max="20" value="<?= $ev['leaders_needed'] !== null ? (int)$ev['leaders_needed'] : '' ?>" placeholder="auto" class="num">
        <button class="btn ghost small">OK</button></form>
      <p class="note">Leer lassen = automatisch aus Anmeldungen bzw. Anzahl Gruppen.</p>
    </div>
  </details>
  <?php endif ?>
</article>
<?php endforeach ?>
<?php if (!$events): ?><div class="card"><p><?= $isHead ? 'Noch keine Events. Erstelle oben eine Serie für die Saison.' : 'Noch keine Events geplant.' ?></p></div><?php endif ?>
<p class="note"><a href="?alle=<?= $showPast ? '0' : '1' ?>"><?= $showPast ? 'Nur kommende Events zeigen' : 'Ganze Saison zeigen (auch vergangene)' ?></a></p>
<?php layout_foot();
