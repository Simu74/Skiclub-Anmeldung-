<?php
// Import aus Excel/CSV: Familien & Kinder oder Leiterplan.
// Ablauf: Datei hochladen → Vorschau, was sich ändert → bestätigen.

declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/xlsx.php';

require_leader('hauptleiter');
$pdo = db();
$art = ($_GET['art'] ?? '') === 'plan' ? 'plan' : 'familien';
$back = $art === 'plan' ? 'plan.php' : 'families.php';

/** Stufe aus «Rot 3», «3» oder «rot» lesen. null = leer/ungültig. */
function parse_skill(string $v): ?int
{
    $v = mb_strtolower(trim($v));
    if ($v === '') return null;
    if (preg_match('/([1-5])/', $v, $m)) return (int)$m[1];
    return ['blau' => 1, 'rot' => 3, 'schwarz' => 5][$v] ?? null;
}

function parse_yes(string $v): ?string
{
    $v = mb_strtolower(trim($v));
    if ($v === '') return null;
    if (in_array($v, ['x', 'ja', 'j', '1', '✓', 'yes', 'leite', 'dabei'], true)) return 'yes';
    if (in_array($v, ['nein', 'n', '0', '-', '✗', 'no', 'kann nicht'], true)) return 'no';
    return 'invalid';
}

/** Familien & Kinder. Gibt Bericht zurück; schreibt nur, wenn $apply. */
function import_families(array $rows, bool $apply): array
{
    $pdo = db();
    $rep = ['fam_new' => 0, 'fam_upd' => 0, 'kid_new' => 0, 'kid_upd' => 0, 'same' => 0, 'errors' => []];
    $head = array_shift($rows) ?? [];
    $c = [
        'fam' => col_index($head, ['Familie', 'Familienname', 'Name']),
        'phone' => col_index($head, ['Telefon Eltern', 'Telefon', 'Handy', 'Handy Eltern']),
        'first' => col_index($head, ['Vorname', 'Kind']),
        'year' => col_index($head, ['Jahrgang']),
        'skill' => col_index($head, ['Stufe']),
        'emerg' => col_index($head, ['Notfallnummer', 'Notfall']),
        'health' => col_index($head, ['Hinweise', 'Gesundheit', 'Allergien']),
        'active' => col_index($head, ['Aktiv']),
    ];
    if ($c['fam'] === null) {
        $rep['errors'][] = 'Die Spalte «Familie» fehlt. Am einfachsten zuerst exportieren und diese Datei als Vorlage nehmen.';
        return $rep;
    }
    $get = fn($r, $k) => $c[$k] === null ? '' : trim((string)($r[$c[$k]] ?? ''));
    $fams = [];
    foreach ($pdo->query('SELECT * FROM families') as $f) $fams[mb_strtolower($f['name'])][] = $f;
    $kidsBy = [];
    foreach ($pdo->query('SELECT * FROM kids') as $k) $kidsBy[$k['family_id']][mb_strtolower($k['first_name'])] = $k;
    $newFams = [];   // in der Vorschau: Familien, die erst neu angelegt würden
    $phoneDone = [];

    if ($apply) $pdo->beginTransaction();
    foreach ($rows as $i => $r) {
        $line = $i + 2;
        $name = $get($r, 'fam');
        $first = $get($r, 'first');
        if ($name === '' && $first === '') continue;   // Leerzeile
        if ($name === '') { $rep['errors'][] = "Zeile $line: Familienname fehlt."; continue; }
        $phone = $get($r, 'phone');
        $key = mb_strtolower($name);

        // Familie finden: gleicher Name, bei mehreren gleicher Telefonnummer
        $cand = $fams[$key] ?? [];
        $fam = null;
        if (count($cand) === 1) $fam = $cand[0];
        elseif (count($cand) > 1) {
            foreach ($cand as $f) if ($phone !== '' && normalize_phone($f['phone']) === normalize_phone($phone)) $fam = $f;
            if (!$fam) { $rep['errors'][] = "Zeile $line: Es gibt mehrere Familien «{$name}». Bitte Telefonnummer angeben."; continue; }
        }
        if (!$fam) {
            if (isset($newFams[$key])) {
                $fam = $newFams[$key];
            } else {
                $rep['fam_new']++;
                $fid = 0;
                if ($apply) {
                    $pdo->prepare('INSERT INTO families (name, phone, token) VALUES (?, ?, ?)')->execute([$name, $phone, bin2hex(random_bytes(16))]);
                    $fid = (int)$pdo->lastInsertId();
                }
                $fam = ['id' => $fid ?: -count($newFams) - 1, 'name' => $name, 'phone' => $phone];
                $newFams[$key] = $fam;
                $fams[$key] = [$fam];
            }
        } elseif ($phone !== '' && normalize_phone($phone) !== normalize_phone($fam['phone']) && !isset($phoneDone[$fam['id']])) {
            $rep['fam_upd']++;
            if ($apply) $pdo->prepare('UPDATE families SET phone = ? WHERE id = ?')->execute([$phone, $fam['id']]);
        }
        $phoneDone[$fam['id']] = true;
        if ($first === '') continue;   // nur Familie ohne Kind

        $year = (int)$get($r, 'year');
        $skillRaw = $get($r, 'skill');
        $skill = parse_skill($skillRaw);
        if ($skillRaw !== '' && $skill === null) { $rep['errors'][] = "Zeile $line: Stufe «{$skillRaw}» unbekannt (Blau 1 bis Schwarz 5)."; continue; }
        $activeRaw = mb_strtolower($get($r, 'active'));
        $active = $activeRaw === '' ? null : (in_array($activeRaw, ['nein', 'n', '0', 'no'], true) ? 0 : 1);
        $kid = $kidsBy[$fam['id']][mb_strtolower($first)] ?? null;

        if (!$kid) {
            if ($year < 1990 || $year > (int)date('Y')) { $rep['errors'][] = "Zeile $line: Jahrgang für {$first} fehlt oder ist ungültig."; continue; }
            $rep['kid_new']++;
            if ($apply && $fam['id'] > 0) {
                $pdo->prepare('INSERT INTO kids (family_id, first_name, birth_year, skill, emergency_phone, health, active) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$fam['id'], $first, $year, $skill ?? 1, $get($r, 'emerg'), $get($r, 'health'), $active ?? 1]);
            }
            $kidsBy[$fam['id']][mb_strtolower($first)] = ['id' => 0, 'first_name' => $first];
            continue;
        }
        if (!$kid['id']) { $rep['errors'][] = "Zeile $line: {$first} {$name} kommt doppelt vor."; continue; }
        // nur ausgefüllte Zellen übernehmen, leere lassen den bisherigen Wert
        $upd = [];
        if ($year && $year !== (int)$kid['birth_year']) $upd['birth_year'] = $year;
        if ($skill !== null && $skill !== (int)$kid['skill']) $upd['skill'] = $skill;
        if (($v = $get($r, 'emerg')) !== '' && $v !== $kid['emergency_phone']) $upd['emergency_phone'] = $v;
        if (($v = $get($r, 'health')) !== '' && $v !== $kid['health']) $upd['health'] = $v;
        if ($active !== null && $active !== (int)$kid['active']) $upd['active'] = $active;
        if (!$upd) { $rep['same']++; continue; }
        $rep['kid_upd']++;
        if ($apply) {
            $pdo->prepare('UPDATE kids SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($upd))) . ' WHERE id = ?')
                ->execute([...array_values($upd), $kid['id']]);
        }
    }
    if ($apply) $pdo->commit();
    return $rep;
}

/** Leiterplan: Zeile pro Event (Spalte ID), Spalte pro Leiter. */
function import_plan(array $rows, bool $apply): array
{
    $pdo = db();
    $rep = ['set' => 0, 'cleared' => 0, 'needed' => 0, 'same' => 0, 'errors' => [], 'leaders' => []];
    $head = array_shift($rows) ?? [];
    $cId = col_index($head, ['ID']);
    $cNeed = col_index($head, ['Bedarf', 'Leiter benötigt']);
    if ($cId === null) {
        $rep['errors'][] = 'Die Spalte «ID» fehlt. Bitte den Leiterplan zuerst exportieren und diese Datei bearbeiten.';
        return $rep;
    }
    $leaders = [];
    foreach ($pdo->query("SELECT id, name FROM leaders WHERE role <> 'admin'") as $l) $leaders[mb_strtolower(trim($l['name']))] = $l;
    $cols = [];
    $skipHead = ['id', 'datum', 'zeit', 'event', 'titel', 'kategorie', 'status', 'bedarf', 'leiter benötigt', 'eingetragen', 'ort'];
    foreach ($head as $i => $h) {
        $k = mb_strtolower(trim((string)$h));
        if ($k === '' || in_array($k, $skipHead, true)) continue;
        if (isset($leaders[$k])) { $cols[$i] = $leaders[$k]; $rep['leaders'][] = $leaders[$k]['name']; }
        else $rep['errors'][] = "Spalte «{$h}»: Keine Person mit diesem Namen im Team, Spalte wird ignoriert.";
    }
    $plan = [];
    foreach ($pdo->query('SELECT event_id, leader_id, status FROM leader_availability') as $a) $plan[$a['event_id']][$a['leader_id']] = $a['status'];
    $events = array_column($pdo->query('SELECT id, leaders_needed FROM events')->fetchAll(), null, 'id');

    if ($apply) $pdo->beginTransaction();
    foreach ($rows as $i => $r) {
        $line = $i + 2;
        $eid = (int)($r[$cId] ?? 0);
        if (!$eid) continue;
        if (!isset($events[$eid])) { $rep['errors'][] = "Zeile $line: Event mit ID {$eid} gibt es nicht (mehr)."; continue; }
        foreach ($cols as $ci => $l) {
            $want = parse_yes((string)($r[$ci] ?? ''));
            if ($want === 'invalid') { $rep['errors'][] = "Zeile $line, {$l['name']}: «" . $r[$ci] . "» nicht erkannt (x = leitet, nein = kann nicht, leer = offen)."; continue; }
            $have = $plan[$eid][$l['id']] ?? null;
            if ($want === $have) { $rep['same']++; continue; }
            if ($want === null) {
                $rep['cleared']++;
                if ($apply) $pdo->prepare('DELETE FROM leader_availability WHERE event_id = ? AND leader_id = ?')->execute([$eid, $l['id']]);
            } else {
                $rep['set']++;
                if ($apply) $pdo->prepare('INSERT INTO leader_availability (event_id, leader_id, status) VALUES (?, ?, ?)
                    ON CONFLICT (event_id, leader_id) DO UPDATE SET status = excluded.status')->execute([$eid, $l['id'], $want]);
            }
        }
        if ($cNeed !== null) {
            $v = trim((string)($r[$cNeed] ?? ''));
            $need = $v === '' || !is_numeric($v) ? null : max(0, min(20, (int)$v));
            $cur = $events[$eid]['leaders_needed'];
            if (($cur === null ? null : (int)$cur) !== $need) {
                $rep['needed']++;
                if ($apply) $pdo->prepare('UPDATE events SET leaders_needed = ? WHERE id = ?')->execute([$need, $eid]);
            }
        }
    }
    if ($apply) $pdo->commit();
    return $rep;
}

$report = null;
$error = '';
if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'preview') {
        try {
            $rows = table_read($_FILES['file'] ?? []);
            if (count($rows) < 2) throw new RuntimeException('Die Datei enthält keine Daten.');
            $_SESSION['import'][$art] = $rows;
            $report = $art === 'plan' ? import_plan($rows, false) : import_families($rows, false);
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    } elseif (($_POST['action'] ?? '') === 'apply' && !empty($_SESSION['import'][$art])) {
        $rep = $art === 'plan' ? import_plan($_SESSION['import'][$art], true) : import_families($_SESSION['import'][$art], true);
        unset($_SESSION['import'][$art]);
        flash($art === 'plan'
            ? "Leiterplan importiert: {$rep['set']} Einträge gesetzt, {$rep['cleared']} entfernt."
            : "Import fertig: {$rep['fam_new']} Familien und {$rep['kid_new']} Kinder neu, {$rep['kid_upd']} Kinder aktualisiert.");
        redirect($back);
    }
}

layout_head('Import', admin_nav(), $back);
?>
<div><p class="eyebrow"><a href="<?= $back ?>"><?= $art === 'plan' ? 'Leiterplan' : 'Familien' ?></a> · Import</p>
  <h1><?= $art === 'plan' ? 'Leiterplan importieren' : 'Familien & Kinder importieren' ?></h1></div>

<?php if ($report): $nChanges = $art === 'plan' ? $report['set'] + $report['cleared'] + $report['needed'] : $report['fam_new'] + $report['fam_upd'] + $report['kid_new'] + $report['kid_upd']; ?>
<div class="card stack">
  <h3>Vorschau</h3>
  <?php if ($art === 'plan'): ?>
    <p>Leiter-Spalten erkannt: <b><?= e(implode(', ', $report['leaders']) ?: 'keine') ?></b></p>
    <ul>
      <li><b><?= $report['set'] ?></b> Einträge werden gesetzt (leitet / kann nicht)</li>
      <li><b><?= $report['cleared'] ?></b> Einträge werden entfernt (Zelle leer)</li>
      <li><b><?= $report['needed'] ?></b> Mal wird der Bedarf geändert</li>
      <li><?= $report['same'] ?> Einträge bleiben gleich</li>
    </ul>
  <?php else: ?>
    <ul>
      <li><b><?= $report['fam_new'] ?></b> Familien neu, <b><?= $report['fam_upd'] ?></b> mit neuer Telefonnummer</li>
      <li><b><?= $report['kid_new'] ?></b> Kinder neu, <b><?= $report['kid_upd'] ?></b> Kinder geändert</li>
      <li><?= $report['same'] ?> Kinder bleiben gleich</li>
    </ul>
    <p class="note">Leere Zellen ändern nichts. Es wird nichts gelöscht. Neue Familien bekommen automatisch einen persönlichen Link.</p>
  <?php endif ?>
  <?php if ($report['errors']): ?>
    <div class="stack"><p class="error">Hinweise (diese Zeilen werden übersprungen):</p>
      <ul class="note"><?php foreach (array_slice($report['errors'], 0, 30) as $er): ?><li><?= e($er) ?></li><?php endforeach ?>
      <?php if (count($report['errors']) > 30): ?><li>… und <?= count($report['errors']) - 30 ?> weitere</li><?php endif ?></ul></div>
  <?php endif ?>
  <?php if ($nChanges): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply"><button class="btn">Jetzt übernehmen</button></form>
  <?php else: ?><p>Keine Änderungen gefunden.</p><?php endif ?>
  <a class="note" href="import.php?art=<?= $art ?>">Andere Datei wählen</a>
</div>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="preview">
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif ?>
  <?php if ($art === 'plan'): ?>
    <p>So geht's:</p>
    <ol>
      <li><a href="plan.php?export=1">Leiterplan als Excel exportieren</a>.</li>
      <li>In Excel pro Termin und Leiter eintragen: <b>x</b> = leitet, <b>nein</b> = kann nicht, <b>leer</b> = offen. In der Spalte «Bedarf» die Anzahl Leiter (leer = automatisch).</li>
      <li>Datei speichern und hier hochladen. Die Spalte «ID» nicht ändern.</li>
    </ol>
  <?php else: ?>
    <p>So geht's:</p>
    <ol>
      <li><a href="families.php?export=1">Familien als Excel exportieren</a> und als Vorlage nehmen.</li>
      <li>Pro Kind eine Zeile: <b>Familie</b>, Telefon Eltern, <b>Vorname</b>, <b>Jahrgang</b>, Stufe (z.B. «Rot 3»), Notfallnummer, Hinweise, Aktiv (ja/nein).</li>
      <li>Datei speichern und hier hochladen. Bestehende Familien und Kinder werden am Namen erkannt und ergänzt.</li>
    </ol>
  <?php endif ?>
  <label for="file">Excel-Datei (.xlsx) oder CSV</label>
  <input type="file" id="file" name="file" accept=".xlsx,.csv" required>
  <button class="btn">Vorschau anzeigen</button>
  <p class="note">Vor dem Übernehmen sehen Sie, was sich ändert.</p>
</form>
<?php endif ?>
<?php layout_foot();
