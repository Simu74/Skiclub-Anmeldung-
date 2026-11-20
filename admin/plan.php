<?php
// Leiterplanung: Zu Saisonbeginn tragen sich die Leiter für die Trainings ein («Meine Planung»).
// Hauptleiter sehen pro Event, ob genug Leiter da sind, und ergänzen oder streichen Leiter.
// Serien, Excel und Gesamtplan liegen unter «Saisonplanung» (saison.php).

declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader();
$isHead = has_role($me, 'hauptleiter');
$leads = leads($me);
$pdo = db();
$showPast = !empty($_GET['alle']);
$back = 'plan.php' . ($showPast ? '?alle=1' : '');

/** Gibt eine Fehlermeldung zurück, wenn der Leiter zur selben Zeit schon eingeteilt ist. */
function set_plan(int $eventId, int $leaderId, string $status): ?string
{
    $pdo = db();
    if ($status === 'yes' && ($other = leader_busy($eventId, $leaderId))) {
        return 'Geht nicht: zur selben Zeit schon eingeteilt für «' . $other['title'] . '» (' . fmt_date($other['date']) . ').';
    }
    if ($status === 'clear') {
        $pdo->prepare('DELETE FROM leader_availability WHERE event_id = ? AND leader_id = ?')->execute([$eventId, $leaderId]);
    } elseif (in_array($status, ['yes', 'no'], true)) {
        // Bemerkung behalten, nur den Status ändern
        $pdo->prepare('INSERT INTO leader_availability (event_id, leader_id, status) VALUES (?, ?, ?)
            ON CONFLICT (event_id, leader_id) DO UPDATE SET status = excluded.status')->execute([$eventId, $leaderId, $status]);
    }
    return null;
}

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $eid = (int)($_POST['event_id'] ?? 0);
    $anchor = $eid ? '#e' . $eid : '';
    $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$eid]);
    $pev = $st->fetch() ?: null;
    if ($a === 'me' && !$leads) {
        flash('Nur Leiter tragen sich in der Leiterplanung ein.');
    } elseif ($a === 'me' && (!$pev || deadline_passed($pev))) {
        flash('Der Anmeldeschluss ist vorbei. Änderungen bitte einem Hauptleiter melden.');
    } elseif ($a === 'me') {
        $err = set_plan($eid, (int)$me['id'], (string)($_POST['status'] ?? ''));
        flash($err ?? (($_POST['status'] ?? '') === 'yes' ? 'Eingetragen. Danke!' : 'Gespeichert.'));
    } elseif (!$isHead) {
        http_response_code(403);
        exit('Dafür fehlt die Berechtigung.');
    } elseif ($a === 'set') {
        $err = set_plan($eid, (int)$_POST['leader_id'], (string)($_POST['status'] ?? ''));
        flash($err ?? 'Leiterplanung angepasst.');
    } elseif ($a === 'needed') {
        $v = trim((string)($_POST['needed'] ?? ''));
        $pdo->prepare('UPDATE events SET leaders_needed = ? WHERE id = ?')->execute([$v === '' ? null : max(0, min(20, (int)$v)), $eid]);
        flash('Bedarf gespeichert.');
    }
    redirect($back . $anchor);
}

// ---------- Export (Excel) und Druckansicht: immer die ganze Saison
if (isset($_GET['export']) || isset($_GET['druck'])) {
    $season = (int)($_GET['saison'] ?? season_year());
    $st = $pdo->prepare("SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'in') AS n_in
        FROM events e WHERE e.status <> 'cancelled' AND e.date BETWEEN ? AND ? ORDER BY e.date, e.time_text");
    $st->execute([($season - 1) . '-07-01', $season . '-06-30']);
    $sEvents = $st->fetchAll();
    $sPlan = [];
    foreach ($pdo->query("SELECT a.event_id, a.leader_id, a.status, a.note FROM leader_availability a JOIN leaders l ON l.id = a.leader_id WHERE l.role = 'leiter'") as $r) $sPlan[$r['event_id']][$r['leader_id']] = $r;
    $sGroups = [];
    foreach ($pdo->query('SELECT event_id, leader_id, group_no FROM group_leaders') as $r) $sGroups[$r['event_id']][$r['leader_id']][] = $r['group_no'];
    $team = $pdo->query("SELECT id, name FROM leaders WHERE role = 'leiter' ORDER BY name")->fetchAll();
    $label = ($season - 1) . '/' . substr((string)$season, 2);

    if (isset($_GET['export'])) {
        require dirname(__DIR__) . '/inc/xlsx.php';
        $rows = [array_merge(['ID', 'Datum', 'Zeit', 'Event', 'Kategorie', 'Status', 'Bedarf'], array_column($team, 'name'), ['Eingetragen'])];
        foreach ($sEvents as $ev) {
            $yes = 0; $cells = [];
            foreach ($team as $l) {
                $stt = $sPlan[$ev['id']][$l['id']]['status'] ?? '';
                if ($stt === 'yes') $yes++;
                $cells[] = $stt === 'yes' ? 'x' : ($stt === 'no' ? 'nein' : '');
            }
            $rows[] = array_merge([(int)$ev['id'], date('d.m.Y', strtotime($ev['date'])), $ev['time_text'], $ev['title'], category_name($ev),
                EVENT_STATUS[$ev['status']], $ev['leaders_needed'] !== null ? (int)$ev['leaders_needed'] : ''], $cells, [$yes]);
        }
        xlsx_download('leiterplan-' . str_replace('/', '-', $label) . '.xlsx', 'Leiterplan ' . $label, $rows,
            array_merge([6, 11, 12, 26, 12, 13, 8], array_fill(0, count($team), 10), [11]));
    }

    // Druckansicht: «mein» = meine Einsätze, «termine» = alle Termine mit meiner Wahl, «alle» = Gesamtplan
    $view = (string)($_GET['druck'] ?? 'mein');
    if (!$leads || !in_array($view, ['mein', 'termine', 'alle'], true)) $view = $leads ? 'mein' : 'alle';
    $all = $view === 'alle';
    $onlyMine = $view === 'mein';
    $title = $all ? 'Leiterplan' : ($onlyMine ? 'Meine Einsätze: ' : 'Alle Termine: ') . $me['name'];
    layout_head('Leiterplan drucken', admin_nav(), $leads ? 'leaders.php' : 'plan.php');
    ?>
    <div><p class="eyebrow"><?= e($config['site_name']) ?> · Saison <?= e($label) ?></p>
      <h1><?= e($title) ?></h1>
      <p class="note">Stand <?= date('d.m.Y') ?>.<?= $all ? ' x = leitet, – = kann nicht.' : ($onlyMine ? '' : ' Grün = du leitest.') ?> Änderungen bitte im Leiterplan der App eintragen.</p></div>
    <?php if ($leads): ?>
    <div class="noprint seg">
      <a class="segbtn" href="?druck=mein" <?= $onlyMine ? 'aria-current="page"' : '' ?>>Meine Einsätze</a>
      <a class="segbtn" href="?druck=termine" <?= $view === 'termine' ? 'aria-current="page"' : '' ?>>Alle Termine</a>
      <a class="segbtn" href="?druck=alle" <?= $all ? 'aria-current="page"' : '' ?>>Gesamtplan alle Leiter</a>
    </div>
    <?php endif ?>
    <?php if ($all): ?>
    <div class="tscroll"><table class="plan-table"><thead><tr><th>Datum</th><th>Event</th><?php foreach ($team as $l): ?><th class="c"><?= e($l['name']) ?></th><?php endforeach ?><th class="c">Total</th></tr></thead><tbody>
    <?php $mo = ''; foreach ($sEvents as $ev): $m = MONTHS[(int)date('n', strtotime($ev['date']))] . ' ' . date('Y', strtotime($ev['date']));
        if ($m !== $mo): $mo = $m; ?><tr class="month"><td colspan="<?= count($team) + 3 ?>"><?= e($m) ?></td></tr><?php endif;
        $need = leaders_needed($ev); $yes = 0; ?>
      <tr><td><?= fmt_date($ev['date']) ?><br><span class="note"><?= e($ev['time_text']) ?></span></td><td><?= e($ev['title']) ?></td>
        <?php foreach ($team as $l): $stt = $sPlan[$ev['id']][$l['id']]['status'] ?? ''; if ($stt === 'yes') $yes++; ?>
          <td class="c"><?= $stt === 'yes' ? '<b>x</b>' : ($stt === 'no' ? '–' : '') ?></td><?php endforeach ?>
        <td class="c"><?= $yes ?><?= $need !== null ? '/' . $need : '' ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
    <?php elseif ($onlyMine): ?>
    <div class="tscroll"><table class="plan-table"><thead><tr><th>Datum</th><th>Zeit</th><th>Event</th><th>Ort</th></tr></thead><tbody>
    <?php $mo = ''; $count = 0; foreach ($sEvents as $ev): if (($sPlan[$ev['id']][$me['id']]['status'] ?? '') !== 'yes') continue; $count++;
        $m = MONTHS[(int)date('n', strtotime($ev['date']))] . ' ' . date('Y', strtotime($ev['date']));
        if ($m !== $mo): $mo = $m; ?><tr class="month"><td colspan="4"><?= e($m) ?></td></tr><?php endif;
        $grp = $sGroups[$ev['id']][$me['id']] ?? []; ?>
      <tr><td><?= fmt_date($ev['date']) ?></td><td><?= e($ev['time_text']) ?></td><td><?= e($ev['title']) ?><?= $grp ? ' <b>· Gruppe ' . e(implode(', ', $grp)) . '</b>' : '' ?></td><td><?= e($ev['place']) ?></td></tr>
    <?php endforeach ?>
    <?php if (!$count): ?><tr><td colspan="4">Du bist noch für keine Termine eingetragen.</td></tr><?php endif ?>
    </tbody></table></div>
    <p><b><?= $count ?></b> Einsätze in der Saison <?= e($label) ?>.</p>
    <?php else: ?>
    <div class="tscroll"><table class="plan-table"><thead><tr><th>Datum</th><th>Zeit</th><th>Event</th><th>Ort</th><th class="c">Ich</th><th>Bemerkung</th></tr></thead><tbody>
    <?php $mo = ''; $count = 0; foreach ($sEvents as $ev): $mine = $sPlan[$ev['id']][$me['id']] ?? null; $stt = $mine['status'] ?? '';
        if ($stt === 'yes') $count++;
        $m = MONTHS[(int)date('n', strtotime($ev['date']))] . ' ' . date('Y', strtotime($ev['date']));
        if ($m !== $mo): $mo = $m; ?><tr class="month"><td colspan="6"><?= e($m) ?></td></tr><?php endif;
        $grp = $sGroups[$ev['id']][$me['id']] ?? []; ?>
      <tr class="<?= $stt === 'yes' ? 'mine' : '' ?>"><td><?= fmt_date($ev['date']) ?></td><td><?= e($ev['time_text']) ?></td><td><?= e($ev['title']) ?></td><td><?= e($ev['place']) ?></td>
        <td class="c"><?= $stt === 'yes' ? '✓' . ($grp ? ' Gr. ' . e(implode(', ', $grp)) : '') : ($stt === 'no' ? '–' : '') ?></td><td><?= e($mine['note'] ?? '') ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
    <p><b><?= $count ?></b> Einsätze in der Saison <?= e($label) ?>.</p>
    <?php endif ?>

    <div class="noprint stack">
      <button type="button" class="btn" data-print>Drucken / als PDF speichern</button>
      <div class="card stack" data-print-info hidden>
        <p><b>Drucken geht hier nicht.</b> In der App vom Home-Bildschirm und im Browser von WhatsApp gibt es kein Druckfenster.</p>
        <p class="note">So geht es: diese Seite in Safari oder Chrome öffnen (Adresse unten kopieren) und dort <b>Teilen → Drucken</b> wählen. Dort lässt sich der Plan auch als PDF sichern.</p>
        <input type="text" readonly value="<?= e(base_url() . '/admin/plan.php?druck=' . $view) ?>" aria-label="Adresse dieser Seite" onclick="this.select()">
      </div>
      <a class="note" href="<?= $leads ? 'leaders.php' : 'saison.php' ?>">← Zurück</a>
    </div>
    <?php
    layout_foot();
    exit;
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
        WHERE a.event_id IN ($ids) AND l.role = 'leiter' ORDER BY l.name") as $r) {
        $plan[$r['event_id']][$r['leader_id']] = $r;
    }
}
$leaders = $pdo->query("SELECT id, name, phone FROM leaders WHERE role = 'leiter' ORDER BY name")->fetchAll();

// Wer ist zur selben Zeit schon bei einem anderen Event eingetragen? event_id => leader_id => anderes Event
$busy = [];
foreach ($events as $a) {
    foreach ($events as $b) {
        if ($a['id'] === $b['id'] || !events_overlap($a, $b)) continue;
        foreach ($plan[$b['id']] ?? [] as $lid => $r) {
            if ($r['status'] === 'yes') $busy[$a['id']][$lid] = $b;
        }
    }
}

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

// Hauptleiter: die aktuelle Woche zuoberst, der Rest der Saison eingeklappt
$sections = [$events];
$weekLabel = '';
if ($isHead && !$showPast && $events) {
    [$wFrom, $wTo] = week_of(date('Y-m-d'));
    $week = array_values(array_filter($events, fn($e) => $e['date'] <= $wTo));
    if (!$week) {
        [$wFrom, $wTo] = week_of($events[0]['date']);
        $week = array_values(array_filter($events, fn($e) => $e['date'] <= $wTo));
    }
    $weekLabel = ($wFrom <= date('Y-m-d') ? 'Diese Woche' : 'Nächste Woche mit Events') . ': ' . date('d.m.', strtotime($wFrom)) . '–' . date('d.m.', strtotime($wTo));
    $sections = [$week, array_values(array_filter($events, fn($e) => $e['date'] > $wTo))];
}

$heading = $isHead ? 'Leiterplanung' : 'Meine Planung';
layout_head($heading, admin_nav(), 'plan.php');
?>
<div><p class="eyebrow"><?= e($config['site_name']) ?></p><h1><?= $heading ?></h1></div>

<?php if ($isHead): ?>
<div class="card stack">
  <?php if ($missing):
      $lines = array_map(fn($m) => '• ' . fmt_date($m['date']) . ' ' . $m['title'] . ': ' . ($m['need'] - $m['n_yes']) . ' fehlen', $missing);
      $text = "🙋 *Leiter gesucht*\n" . implode("\n", $lines) . "\n\nBitte im Leiterplan eintragen:\n" . base_url() . '/admin/plan.php'; ?>
    <p class="warnline"><b><?= count($missing) ?> Events</b> haben noch zu wenig Leiter.</p>
    <a class="btn ghost small" target="_blank" rel="noopener" href="<?= e(wa_link($text)) ?>">«Leiter gesucht» per WhatsApp</a>
  <?php else: ?>
    <p class="note">Bei allen Events mit bekanntem Bedarf sind genug Leiter eingetragen.</p>
  <?php endif ?>
</div>

<?php else: ?>
<div class="card">
  <p>Trag dich für alle Trainings ein, an denen du leiten kannst. Bis zum Anmeldeschluss des Events kannst du es ändern. Die Hauptleiter planen damit die Gruppen.</p>
  <p class="note">Du bist für <b><?= $mineCount ?></b> <?= $mineCount === 1 ? 'Event' : 'Events' ?> eingetragen.</p>
</div>
<?php endif ?>

<?php if ($isHead && $weekLabel): ?><h2 class="month"><?= e($weekLabel) ?></h2><?php endif ?>
<?php foreach ($sections as $secNo => $secEvents):
    if ($secNo === 1 && !$secEvents) continue;
    if ($secNo === 1): ?><details class="saison" id="saison"><summary class="btn ghost">Weitere Termine der Saison (<?= count($secEvents) ?>)</summary><?php endif;
    $month = $secNo === 0 && $isHead ? 'woche' : '';
foreach ($secEvents as $ev):
    $rows = $plan[$ev['id']] ?? [];
    $yes = array_filter($rows, fn($r) => $r['status'] === 'yes');
    $no = array_filter($rows, fn($r) => $r['status'] === 'no');
    $need = leaders_needed($ev);
    $my = $rows[$me['id']]['status'] ?? '';
    $closed = deadline_passed($ev);
    $myBusy = $busy[$ev['id']][$me['id']] ?? null;
    $state = $need === null ? '' : (count($yes) >= $need ? 'ok' : 'short');
    $m = MONTHS[(int)date('n', strtotime($ev['date']))] . ' ' . date('Y', strtotime($ev['date']));
    if ($month !== 'woche' && $m !== $month): $month = $m; ?><h2 class="month"><?= e($m) ?></h2><?php endif ?>
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
  <?php if ($leads && $closed): ?>
    <p class="note"><?= $my === 'yes' ? '<b>✓ Du leitest.</b> ' : ($my === 'no' ? '✗ Du kannst nicht. ' : '') ?>Anmeldeschluss vorbei, Änderungen bitte einem Hauptleiter melden.</p>
  <?php elseif ($leads): ?>
  <?php if ($myBusy && $my !== 'yes'): ?><p class="note">Zur selben Zeit leitest du schon «<?= e($myBusy['title']) ?>».</p><?php endif ?>
  <form method="post" class="toggle"><?= csrf_field() ?><input type="hidden" name="action" value="me"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
    <button class="tbtn yes" name="status" value="<?= $my === 'yes' ? 'clear' : 'yes' ?>" aria-pressed="<?= $my === 'yes' ? 'true' : 'false' ?>" <?= $myBusy && $my !== 'yes' ? 'disabled' : '' ?>>✓ Ich leite</button>
    <button class="tbtn no" name="status" value="<?= $my === 'no' ? 'clear' : 'no' ?>" aria-pressed="<?= $my === 'no' ? 'true' : 'false' ?>">✗ Kann nicht</button>
  </form><?php endif ?>
  <?php if ($isHead): ?>
  <details class="edit"><summary class="btn ghost small">Leiter ergänzen / streichen · Bedarf<?= $no ? ' · ' . count($no) . ' kann nicht' : '' ?></summary>
    <div class="stack">
      <?php foreach ($yes as $lid => $r): ?>
        <form method="post" class="row between nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>"><input type="hidden" name="leader_id" value="<?= (int)$lid ?>">
          <span>✓ <b><?= e($r['name']) ?></b><?= $r['note'] !== '' ? ' <span class="note">· ' . e($r['note']) . '</span>' : '' ?></span>
          <button class="linkbtn" name="status" value="clear">streichen</button></form>
      <?php endforeach ?>
      <?php foreach ($no as $r): ?><p class="note">✗ <?= e($r['name']) ?> kann nicht<?= $r['note'] !== '' ? ' · ' . e($r['note']) : '' ?></p><?php endforeach ?>
      <?php $others = array_filter($leaders, fn($l) => !isset($yes[$l['id']])); if ($others): ?>
      <form method="post" class="row nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>"><input type="hidden" name="status" value="yes">
        <select name="leader_id" class="grow" aria-label="Leiter eintragen"><?php foreach ($others as $l): $b = $busy[$ev['id']][$l['id']] ?? null; ?><option value="<?= (int)$l['id'] ?>" <?= $b ? 'disabled' : '' ?>><?= e($l['name']) ?><?= $b ? ' (leitet ' . e($b['title']) . ')' : (isset($no[$l['id']]) ? ' (kann nicht)' : '') ?></option><?php endforeach ?></select>
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
<?php endforeach;
    if ($secNo === 1): ?></details><?php endif;
endforeach ?>
<?php if (!$events): ?><div class="card"><p><?= $isHead ? 'Noch keine Events. Erstelle unter <a href="saison.php">Saisonplanung</a> eine Serie für die Saison.' : 'Noch keine Events geplant.' ?></p></div><?php endif ?>
<p class="note"><a href="?alle=<?= $showPast ? '0' : '1' ?>"><?= $showPast ? 'Nur kommende Events zeigen' : 'Ganze Saison zeigen (auch vergangene)' ?></a></p>
<?php layout_foot();
