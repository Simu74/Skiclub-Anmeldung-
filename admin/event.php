<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$tab = in_array($_GET['tab'] ?? '', ['anmeldungen', 'praesenz', 'gruppen', 'whatsapp'], true) ? $_GET['tab'] : 'anmeldungen';

function get_event(int $id): array
{
    $st = db()->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: redirect('index.php');
}
$ev = get_event($id);
$self = 'event.php?id=' . $id;

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $back = $self . '&tab=' . ($_POST['tab'] ?? 'anmeldungen');
    switch ($a) {
        case 'status':
            $s = $_POST['status'] ?? '';
            if (isset(EVENT_STATUS[$s])) {
                $info = $s === 'cancelled' && $ev['info'] === '' ? 'Abgesagt.' : $ev['info'];
                $pdo->prepare('UPDATE events SET status = ?, info = ? WHERE id = ?')->execute([$s, $info, $id]);
                flash(['open' => 'Freigeschaltet. Eltern sehen das Event jetzt.', 'cancelled' => 'Abgesagt. Die Vorlage «Absage» ist bereit.', 'draft' => 'Zurück auf Entwurf.'][$s]);
                if ($s === 'cancelled') $back = $self . '&tab=whatsapp&vorlage=absage';
                if ($s === 'open') $back = $self . '&tab=whatsapp&vorlage=einladung';
            }
            break;
        case 'info':
            $pdo->prepare('UPDATE events SET info = ? WHERE id = ?')->execute([trim((string)$_POST['info']), $id]);
            flash('Info gespeichert.');
            break;
        case 'regroup':
            regroup_event($id, max(1, min(10, (int)($_POST['n'] ?? 1))));
            flash('Gruppen neu eingeteilt.');
            break;
        case 'move':
            $g = max(1, min((int)$ev['group_count'], (int)$_POST['group_no']));
            $pdo->prepare('INSERT OR REPLACE INTO assignments (event_id, kid_id, group_no) VALUES (?, ?, ?)')
                ->execute([$id, (int)$_POST['kid_id'], $g]);
            flash('Verschoben.');
            break;
        case 'leader':
            $lid = (int)$_POST['leader_id'] ?: null;
            $pdo->prepare('INSERT OR REPLACE INTO group_leaders (event_id, group_no, leader_id) VALUES (?, ?, ?)')
                ->execute([$id, (int)$_POST['group_no'], $lid]);
            flash('Leiter zugewiesen.');
            break;
        case 'add_kid':
            $pdo->prepare("INSERT INTO registrations (event_id, kid_id, status, note) VALUES (?, ?, 'in', 'durch Leitung')
                ON CONFLICT(event_id, kid_id) DO UPDATE SET status = 'in', reason = '', updated_at = datetime('now')")
                ->execute([$id, (int)$_POST['kid_id']]);
            flash('Kind angemeldet.');
            break;
        case 'remove_kid':
            $pdo->prepare("UPDATE registrations SET status = 'out', reason = 'durch Leitung', updated_at = datetime('now') WHERE event_id = ? AND kid_id = ?")
                ->execute([$id, (int)$_POST['kid_id']]);
            flash('Kind abgemeldet.');
            break;
        case 'presence':
            $here = array_map('intval', (array)($_POST['here'] ?? []));
            $pdo->beginTransaction();
            $set = $pdo->prepare('UPDATE registrations SET attended = ? WHERE event_id = ? AND kid_id = ?');
            foreach (registered_kids($id) as $k) {
                $set->execute([in_array((int)$k['id'], $here, true) ? 1 : 0, $id, $k['id']]);
            }
            $pdo->prepare('UPDATE events SET attendance_taken = 1 WHERE id = ?')->execute([$id]);
            $pdo->commit();
            flash('Präsenz gespeichert: ' . count($here) . ' Kinder da.');
            break;
        case 'delete':
            if (!empty($_POST['confirm'])) {
                $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
                flash('Event gelöscht.');
                redirect('index.php');
            }
            flash('Zum Löschen bitte das Häkchen setzen.');
            break;
    }
    redirect($back);
}

$kidsIn = registered_kids($id);
$st = $pdo->prepare("SELECT k.*, f.name AS family_name, r.reason FROM registrations r JOIN kids k ON k.id = r.kid_id
    JOIN families f ON f.id = k.family_id WHERE r.event_id = ? AND r.status = 'out' ORDER BY f.name");
$st->execute([$id]);
$kidsOut = $st->fetchAll();
$st = $pdo->prepare('SELECT d.seats, f.name, f.phone FROM drivers d JOIN families f ON f.id = d.family_id WHERE d.event_id = ?');
$st->execute([$id]);
$drivers = $st->fetchAll();
$st = $pdo->prepare('SELECT * FROM families f WHERE NOT EXISTS (SELECT 1 FROM registrations r JOIN kids k ON k.id = r.kid_id
    WHERE r.event_id = ? AND k.family_id = f.id) AND EXISTS (SELECT 1 FROM kids k WHERE k.family_id = f.id AND k.active = 1) ORDER BY name');
$st->execute([$id]);
$silent = $st->fetchAll();
$leaders = $pdo->query('SELECT id, name, phone FROM leaders ORDER BY name')->fetchAll();
$leaderById = array_column($leaders, null, 'id');
$st = $pdo->prepare('SELECT note, k.first_name FROM registrations r JOIN kids k ON k.id = r.kid_id WHERE r.event_id = ? AND r.status = \'in\' AND note <> \'\'');
$st->execute([$id]);
$notes = $st->fetchAll();

layout_head($ev['title'], admin_nav(), 'index.php');
$tabs = ['anmeldungen' => 'Anmeldungen (' . count($kidsIn) . ')', 'praesenz' => 'Präsenz', 'gruppen' => 'Gruppen', 'whatsapp' => 'WhatsApp'];
?>
<div><p class="eyebrow"><a href="index.php">Events</a> · <?= EVENT_TYPES[$ev['type']] ?></p>
  <h1><?= e($ev['title']) ?></h1>
  <p class="date"><?= fmt_date($ev['date']) ?><?= $ev['time_text'] !== '' ? ' · ' . e($ev['time_text']) : '' ?> · Anmeldeschluss <?= fmt_deadline($ev['deadline']) ?></p></div>

<div class="card">
  <div class="row between"><span class="pill <?= e($ev['status']) ?>"><?= EVENT_STATUS[$ev['status']] ?></span><a class="note" href="event_edit.php?id=<?= $id ?>">Bearbeiten</a></div>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="status">
    <?php if ($ev['status'] !== 'open'): ?><button class="btn small" name="status" value="open">Freischalten</button><?php endif ?>
    <?php if ($ev['status'] === 'open'): ?><button class="btn small red" name="status" value="cancelled">Absagen</button><?php endif ?>
    <?php if ($ev['status'] !== 'draft'): ?><button class="btn ghost small" name="status" value="draft">Zurück auf Entwurf</button><?php endif ?>
  </form>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="info">
    <label for="info" class="note">Info für Eltern (oben auf der Eltern-Seite)</label>
    <div class="row nowrap"><input type="text" id="info" name="info" value="<?= e($ev['info']) ?>" placeholder="z.B. Findet statt. Indoor wegen Regen."><button class="btn ghost small">Speichern</button></div>
  </form>
</div>

<div class="seg" role="tablist">
  <?php foreach ($tabs as $k => $v): ?><a class="segbtn" href="<?= $self ?>&tab=<?= $k ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= e($v) ?></a><?php endforeach ?>
</div>

<?php if ($tab === 'anmeldungen'): ?>
  <div class="card">
    <h3>Angemeldet: <?= count($kidsIn) ?> Kinder</h3>
    <?php foreach ($kidsIn as $k): ?>
      <div class="gkid"><span class="piste p-<?= piste((int)$k['skill']) ?>"></span>
        <span class="nm"><b><?= e($k['first_name']) ?> <?= e($k['family_name']) ?></b> <span class="note"><?= (int)$k['birth_year'] ?><?= $ev['type'] === 'race' ? ' · ' . race_category((int)$k['birth_year']) : '' ?></span>
          <?php if ($k['health'] !== ''): ?><br><span class="health">⚕︎ <?= e($k['health']) ?></span><?php endif ?>
          <?php if ($k['emergency_phone'] !== ''): ?><br><span class="note">Notfall: <?= e($k['emergency_phone']) ?></span><?php endif ?></span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove_kid"><input type="hidden" name="kid_id" value="<?= (int)$k['id'] ?>">
          <button class="linkbtn" aria-label="<?= e($k['first_name']) ?> abmelden">abmelden</button></form>
      </div>
    <?php endforeach ?>
    <?php
    $allKids = $pdo->prepare("SELECT k.id, k.first_name, f.name FROM kids k JOIN families f ON f.id = k.family_id WHERE k.active = 1
        AND k.id NOT IN (SELECT kid_id FROM registrations WHERE event_id = ? AND status = 'in') ORDER BY k.first_name");
    $allKids->execute([$id]);
    $others = $allKids->fetchAll();
    if ($others): ?>
    <form method="post" class="row nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="add_kid">
      <label for="addkid" class="sr">Kind hinzufügen</label>
      <select id="addkid" name="kid_id"><?php foreach ($others as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['first_name'] . ' ' . $o['name']) ?></option><?php endforeach ?></select>
      <button class="btn ghost small">Hinzufügen</button></form>
    <?php endif ?>
  </div>
  <?php if ($notes): ?><div class="card"><h3>Bemerkungen der Eltern</h3>
    <?php foreach ($notes as $n): ?><p><b><?= e($n['first_name']) ?>:</b> <?= e($n['note']) ?></p><?php endforeach ?></div><?php endif ?>
  <div class="card"><h3>Abgemeldet: <?= count($kidsOut) ?></h3>
    <?php foreach ($kidsOut as $k): ?><p><?= e($k['first_name'] . ' ' . $k['family_name']) ?> <span class="note">· <?= e($k['reason']) ?></span></p><?php endforeach ?></div>
  <div class="card"><h3>Fahrer: <?= array_sum(array_column($drivers, 'seats')) ?> Plätze</h3>
    <?php foreach ($drivers as $d): ?><p>Familie <?= e($d['name']) ?> · <?= (int)$d['seats'] ?> Plätze</p><?php endforeach ?></div>
  <div class="card"><h3>Noch keine Antwort: <?= count($silent) ?> Familien</h3>
    <?php foreach ($silent as $f): ?><p class="row between"><span>Familie <?= e($f['name']) ?></span>
      <?php if ($f['phone'] !== ''): ?><a class="linkbtn" target="_blank" rel="noopener" href="<?= e(wa_link("Hallo Familie {$f['name']}, denkt ihr an die Anmeldung für «{$ev['title']}» am " . fmt_date($ev['date']) . "? Bis " . fmt_deadline($ev['deadline']) . ":\n" . family_link($f), $f['phone'])) ?>">per WhatsApp erinnern</a><?php endif ?></p>
    <?php endforeach ?></div>

<?php elseif ($tab === 'praesenz'): ?>
  <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="presence"><input type="hidden" name="tab" value="praesenz">
    <h3>Wer war da?</h3>
    <p class="note"><?= $ev['attendance_taken'] ? 'Präsenz ist erfasst und zählt für die Statistik.' : 'Noch nicht erfasst. Alle Angemeldeten sind vorgewählt, bitte fehlende Kinder abwählen.' ?>
      Kam ein Kind ohne Anmeldung, zuerst unter «Anmeldungen» hinzufügen.</p>
    <?php foreach ($kidsIn as $k): $here = $k['attended'] === null ? true : (bool)$k['attended']; ?>
      <label class="kid"><input type="checkbox" name="here[]" value="<?= (int)$k['id'] ?>" <?= $here ? 'checked' : '' ?>>
        <span class="piste p-<?= piste((int)$k['skill']) ?>"></span>
        <span class="nm"><?= e($k['first_name'] . ' ' . $k['family_name']) ?></span></label>
    <?php endforeach ?>
    <?php if ($kidsIn): ?><button class="btn">Präsenz speichern</button><?php else: ?><p>Keine Anmeldungen.</p><?php endif ?>
  </form>

<?php elseif ($tab === 'gruppen'):
    $groups = event_groups($ev);
    $n = (int)$ev['group_count'] ?: suggested_group_count(count($kidsIn)); ?>
  <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="regroup"><input type="hidden" name="tab" value="gruppen">
    <p><b><?= count($kidsIn) ?> Kinder</b><?= $groups ? ' in <b>' . count($groups) . '</b> Gruppen' : '' ?>.
      <span class="note">Die Einteilung sortiert nach Stufe und macht die Gruppen gleich gross. Danach können Sie Kinder verschieben.</span></p>
    <div class="row nowrap"><label for="n">Anzahl Gruppen</label>
      <select id="n" name="n"><?php for ($i = 1; $i <= 10; $i++): ?><option <?= $i === $n ? 'selected' : '' ?>><?= $i ?></option><?php endfor ?></select>
      <button class="btn small"><?= $groups ? 'Neu einteilen' : 'Automatisch einteilen' ?></button></div>
    <?php if ($groups): ?><p class="note">Achtung: «Neu einteilen» überschreibt Ihre Verschiebungen.</p><?php endif ?>
  </form>
  <?php foreach ($groups as $g => $grp): $p = piste((int)round(group_avg_skill($grp))); ?>
  <article class="card group g-<?= $p ?>">
    <div class="row between"><h3>Gruppe <?= $g ?></h3><span class="pill"><?= count($grp['kids']) ?> Kinder</span></div>
    <form method="post" class="row nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="leader"><input type="hidden" name="tab" value="gruppen"><input type="hidden" name="group_no" value="<?= $g ?>">
      <label for="ld-<?= $g ?>" class="note">Leiter</label>
      <select id="ld-<?= $g ?>" name="leader_id" data-autosubmit><option value="0">– wählen –</option>
        <?php foreach ($leaders as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$grp['leader_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach ?></select>
      <noscript><button class="btn ghost small">OK</button></noscript></form>
    <?php foreach ($grp['kids'] as $k): ?>
      <form method="post" class="gkid"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="tab" value="gruppen"><input type="hidden" name="kid_id" value="<?= (int)$k['id'] ?>">
        <span class="piste p-<?= piste((int)$k['skill']) ?>" title="<?= e(SKILLS[$k['skill']]) ?>"></span>
        <span class="nm"><?= e(initial_name($k)) ?> <span class="note"><?= (int)$k['birth_year'] ?></span><?php if ($k['health'] !== ''): ?> <span class="health">⚕︎ <?= e($k['health']) ?></span><?php endif ?></span>
        <select name="group_no" data-autosubmit aria-label="<?= e($k['first_name']) ?> verschieben">
          <?php for ($j = 1; $j <= count($groups); $j++): ?><option value="<?= $j ?>" <?= $j === $g ? 'selected' : '' ?>>Gr. <?= $j ?></option><?php endfor ?></select>
        <noscript><button class="btn ghost small">OK</button></noscript>
      </form>
    <?php endforeach ?>
  </article>
  <?php endforeach ?>
  <?php if ($groups): ?><a class="btn" href="<?= $self ?>&tab=whatsapp&vorlage=gruppen">Gruppenliste per WhatsApp senden</a><?php endif ?>

<?php else:
    require dirname(__DIR__) . '/inc/whatsapp.php';
    $groups = event_groups($ev);
    $tpls = whatsapp_templates($ev, $kidsIn, $groups, $drivers, $leaderById, count($silent));
    $cur = isset($tpls[$_GET['vorlage'] ?? '']) ? $_GET['vorlage'] : 'einladung';
    $text = $tpls[$cur]['text']; ?>
  <div class="seg"><?php foreach ($tpls as $k => $t): ?><a class="segbtn" href="<?= $self ?>&tab=whatsapp&vorlage=<?= $k ?>" <?= $cur === $k ? 'aria-current="page"' : '' ?>><?= e($t['label']) ?></a><?php endforeach ?></div>
  <div class="card stack">
    <label for="waTxt" class="note">Text (kann angepasst werden)</label>
    <textarea id="waTxt" rows="12" data-wa-source><?= e($text) ?></textarea>
    <a class="btn" id="waOpen" target="_blank" rel="noopener" href="<?= e(wa_link($text)) ?>">In WhatsApp öffnen</a>
    <button type="button" class="btn ghost" data-copy="waTxt">Text kopieren</button>
    <p class="note">WhatsApp öffnet sich, dann die Gruppe oder Person wählen. In Gruppenlisten stehen nur Vorname und Initial, keine Gesundheitsangaben.</p>
  </div>
  <?php if ($cur === 'gruppen' && $groups): ?>
  <div class="card"><h3>Direkt an die Gruppenleiter</h3>
    <?php foreach ($groups as $g => $grp): $l = $leaderById[$grp['leader_id']] ?? null; if (!$l || $l['phone'] === '') continue; ?>
      <p class="row between"><span>Gruppe <?= $g ?> · <?= e($l['name']) ?></span>
        <a class="linkbtn" target="_blank" rel="noopener" href="<?= e(wa_link(whatsapp_group_text($ev, $g, $grp, true), $l['phone'])) ?>">senden</a></p>
    <?php endforeach ?>
    <p class="note">Leiter bekommen ihre Gruppe mit Notfallnummern und Gesundheitshinweisen. Leiter ohne Handynummer fehlen hier.</p>
  </div>
  <?php endif ?>
<?php endif ?>

<details class="card danger"><summary>Event löschen</summary>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <label class="row nowrap"><input type="checkbox" name="confirm" value="1"> Ja, Event mit allen Anmeldungen löschen</label>
    <button class="btn red small">Löschen</button></form>
</details>
<?php layout_foot();
