<?php
// Teilnahmestatistik, nur für Admins.
// Teilnahme = «da» laut Präsenzkontrolle; ohne Präsenzkontrolle zählt die Anmeldung.

declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

require_leader(true);
$pdo = db();

$season = (int)($_GET['saison'] ?? season_year());
$type = array_key_exists($_GET['art'] ?? '', EVENT_TYPES) ? $_GET['art'] : '';
$from = ($season - 1) . '-07-01';
$to = min($season . '-06-30', date('Y-m-d'));

$sql = "SELECT e.*,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'in') AS n_in,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'in' AND r.attended = 1) AS n_here,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'out') AS n_out,
    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'out' AND r.reason = 'Krank') AS n_sick
  FROM events e WHERE e.status = 'open' AND e.date BETWEEN :from AND :to" . ($type ? ' AND e.type = :type' : '') . ' ORDER BY e.date';
$st = $pdo->prepare($sql);
$st->execute(['from' => $from, 'to' => $to] + ($type ? ['type' => $type] : []));
$events = $st->fetchAll();
foreach ($events as &$ev) {
    $ev['n_part'] = $ev['attendance_taken'] ? (int)$ev['n_here'] : (int)$ev['n_in'];
}
unset($ev);
$eventIds = array_column($events, 'id');
$nEvents = count($events);

// Pro Kind: Teilnahme je Event
$kids = $pdo->query('SELECT k.*, f.name AS family_name FROM kids k JOIN families f ON f.id = k.family_id ORDER BY k.first_name, f.name')->fetchAll();
$byKid = [];
if ($eventIds) {
    $in = implode(',', array_map('intval', $eventIds));
    foreach ($pdo->query("SELECT r.*, e.attendance_taken FROM registrations r JOIN events e ON e.id = r.event_id WHERE r.event_id IN ($in)") as $r) {
        $part = $r['status'] === 'in' && ($r['attendance_taken'] ? (int)$r['attended'] === 1 : true);
        $byKid[$r['kid_id']][$r['event_id']] = [
            'part'   => $part,
            'noshow' => $r['status'] === 'in' && $r['attendance_taken'] && (int)$r['attended'] === 0,
            'out'    => $r['status'] === 'out',
            'sick'   => $r['status'] === 'out' && $r['reason'] === 'Krank',
        ];
    }
}
$rows = [];
foreach ($kids as $k) {
    $d = $byKid[$k['id']] ?? [];
    if (!$k['active'] && !$d) continue;
    $part = count(array_filter($d, fn($x) => $x['part']));
    $rows[] = $k + [
        'part'   => $part,
        'noshow' => count(array_filter($d, fn($x) => $x['noshow'])),
        'out'    => count(array_filter($d, fn($x) => $x['out'])),
        'sick'   => count(array_filter($d, fn($x) => $x['sick'])),
        'quote'  => $nEvents ? (int)round($part / $nEvents * 100) : 0,
        'events' => $d,
    ];
}
$sort = $_GET['sort'] ?? 'quote';
usort($rows, $sort === 'name'
    ? fn($a, $b) => [$a['first_name'], $a['family_name']] <=> [$b['first_name'], $b['family_name']]
    : fn($a, $b) => [$b['quote'], $a['first_name']] <=> [$a['quote'], $b['first_name']]);

// CSV-Export (Semikolon + BOM, damit Excel in der Schweiz es direkt öffnet)
if (isset($_GET['csv'])) {
    $name = 'teilnahme-' . ($season - 1) . '-' . $season . ($type ? '-' . $type : '') . '-' . $_GET['csv'] . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($_GET['csv'] === 'liste') {
        // Anwesenheitsliste: Kinder × Daten, x = teilgenommen (z.B. für J+S)
        fputcsv($out, array_merge(['Vorname', 'Name', 'Jahrgang'], array_map(fn($e) => date('d.m.Y', strtotime($e['date'])) . ' ' . $e['title'], $events), ['Total']), ';');
        foreach ($rows as $r) {
            fputcsv($out, array_merge([$r['first_name'], $r['family_name'], $r['birth_year']],
                array_map(fn($e) => !empty($r['events'][$e['id']]['part']) ? 'x' : '', $events), [$r['part']]), ';');
        }
    } elseif ($_GET['csv'] === 'events') {
        fputcsv($out, ['Datum', 'Art', 'Titel', 'Angemeldet', 'Teilgenommen', 'Abgemeldet', 'davon krank', 'Präsenz erfasst'], ';');
        foreach ($events as $e) {
            fputcsv($out, [date('d.m.Y', strtotime($e['date'])), EVENT_TYPES[$e['type']], $e['title'], $e['n_in'], $e['n_part'], $e['n_out'], $e['n_sick'], $e['attendance_taken'] ? 'ja' : 'nein'], ';');
        }
    } else {
        fputcsv($out, ['Vorname', 'Name', 'Jahrgang', 'Stufe', 'Teilnahmen', 'von Events', 'Quote %', 'Abgemeldet', 'davon krank', 'Angemeldet, nicht erschienen'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [$r['first_name'], $r['family_name'], $r['birth_year'], SKILLS[$r['skill']], $r['part'], $nEvents, $r['quote'], $r['out'], $r['sick'], $r['noshow']], ';');
        }
    }
    exit;
}

$totalPart = array_sum(array_column($events, 'n_part'));
$avg = $nEvents ? round($totalPart / $nEvents, 1) : 0;
$untaken = count(array_filter($events, fn($e) => !$e['attendance_taken']));
$q = fn(array $extra) => '?' . http_build_query(array_merge(['saison' => $season, 'art' => $type, 'sort' => $sort], $extra));

layout_head('Statistik', admin_nav(), 'stats.php');
?>
<div><p class="eyebrow">Nur für Admins</p><h1>Teilnahmestatistik</h1></div>

<form method="get" class="card"><div class="row"><label for="saison" class="note">Saison</label>
  <select id="saison" name="saison" data-autosubmit><?php for ($y = season_year() + 0; $y >= season_year() - 4; $y--): ?><option value="<?= $y ?>" <?= $y === $season ? 'selected' : '' ?>><?= $y - 1 ?>/<?= substr((string)$y, 2) ?></option><?php endfor ?></select>
  <label for="art" class="note">Art</label>
  <select id="art" name="art" data-autosubmit><option value="">Alle</option><?php foreach (EVENT_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach ?></select>
  <input type="hidden" name="sort" value="<?= e($sort) ?>"><noscript><button class="btn ghost small">Zeigen</button></noscript></div>
</form>

<div class="card"><div class="row stats">
  <div><div class="count"><?= $nEvents ?></div><div class="note">durchgeführte Events</div></div>
  <div><div class="count"><?= $avg ?></div><div class="note">Ø Kinder pro Event</div></div>
  <div><div class="count"><?= $totalPart ?></div><div class="note">Teilnahmen total</div></div>
</div>
<?php if ($untaken): ?><p class="note"><?= $untaken ?> Event(s) ohne Präsenzkontrolle: dort zählt die Anmeldung als Teilnahme.</p><?php endif ?>
<p class="note">Abgesagte Events und Entwürfe zählen nicht.</p></div>

<div class="card">
  <h3>Export (Excel)</h3>
  <div class="row">
    <a class="btn ghost small" href="<?= e($q(['csv' => 'kinder'])) ?>">Pro Kind</a>
    <a class="btn ghost small" href="<?= e($q(['csv' => 'events'])) ?>">Pro Event</a>
    <a class="btn ghost small" href="<?= e($q(['csv' => 'liste'])) ?>">Anwesenheitsliste (J+S)</a>
  </div>
</div>

<div class="card">
  <div class="row between"><h3>Pro Kind</h3>
    <span class="note">Sortieren: <a href="<?= e($q(['sort' => 'quote'])) ?>">Quote</a> · <a href="<?= e($q(['sort' => 'name'])) ?>">Name</a></span></div>
  <?php foreach ($rows as $r): ?>
    <div class="gkid"><span class="piste p-<?= piste((int)$r['skill']) ?>"></span>
      <span class="nm"><b><?= e($r['first_name'] . ' ' . $r['family_name']) ?></b> <span class="note"><?= (int)$r['birth_year'] ?></span><br>
        <span class="note"><?= $r['part'] ?> von <?= $nEvents ?><?= $r['out'] ? ' · ' . $r['out'] . '× abgemeldet' . ($r['sick'] ? ' (' . $r['sick'] . '× krank)' : '') : '' ?><?= $r['noshow'] ? ' · ' . $r['noshow'] . '× nicht erschienen' : '' ?></span></span>
      <span class="quote"><span class="bar"><span style="width:<?= $r['quote'] ?>%"></span></span><b><?= $r['quote'] ?>%</b></span>
    </div>
  <?php endforeach ?>
  <?php if (!$rows): ?><p>Noch keine Kinder erfasst.</p><?php endif ?>
</div>

<div class="card">
  <h3>Pro Event</h3>
  <?php foreach (array_reverse($events) as $e): ?>
    <div class="gkid"><span class="nm"><a href="event.php?id=<?= (int)$e['id'] ?>&amp;tab=praesenz"><?= fmt_date($e['date']) ?> · <?= e($e['title']) ?></a><br>
      <span class="note"><?= EVENT_TYPES[$e['type']] ?> · <?= (int)$e['n_in'] ?> angemeldet · <?= (int)$e['n_out'] ?> abgemeldet<?= $e['attendance_taken'] ? '' : ' · Präsenz fehlt' ?></span></span>
      <span class="count small"><?= $e['n_part'] ?></span></div>
  <?php endforeach ?>
  <?php if (!$events): ?><p>In dieser Saison gibt es noch keine durchgeführten Events.</p><?php endif ?>
</div>
<?php layout_foot();
