<?php
// Kategorien und Event-Vorlagen verwalten (Hauptleiter und Admin).

declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

require_leader('hauptleiter');
$pdo = db();

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $kind = array_key_exists($_POST['kind'] ?? '', EVENT_TYPES) ? $_POST['kind'] : 'off';
    switch ($a) {
        case 'add_cat':
            $name = trim((string)$_POST['name']);
            if ($name !== '') {
                $pdo->prepare('INSERT INTO event_categories (name, kind, sort) VALUES (?, ?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM event_categories))')
                    ->execute([mb_substr($name, 0, 60), $kind]);
                flash("Kategorie «{$name}» erstellt.");
            }
            break;
        case 'edit_cat':
            $name = trim((string)$_POST['name']);
            if ($name !== '') {
                $pdo->prepare('UPDATE event_categories SET name = ?, kind = ? WHERE id = ?')->execute([mb_substr($name, 0, 60), $kind, (int)$_POST['id']]);
                // Grundart auch bei bestehenden Events nachführen (Farbe, Rennkategorie)
                $pdo->prepare('UPDATE events SET type = ? WHERE category_id = ?')->execute([$kind, (int)$_POST['id']]);
                flash('Kategorie gespeichert.');
            }
            break;
        case 'delete_cat':
            $used = $pdo->prepare('SELECT COUNT(*) FROM events WHERE category_id = ?');
            $used->execute([(int)$_POST['id']]);
            if (count(categories()) <= 1) {
                flash('Es braucht mindestens eine Kategorie.');
            } elseif ($used->fetchColumn() > 0) {
                flash('Diese Kategorie wird von Events verwendet und kann nicht gelöscht werden. Sie können sie umbenennen.');
            } else {
                $pdo->prepare('DELETE FROM event_categories WHERE id = ?')->execute([(int)$_POST['id']]);
                flash('Kategorie gelöscht.');
            }
            break;
        case 'save_tpl':
            $data = [
                'name'          => trim(mb_substr((string)$_POST['name'], 0, 60)),
                'category_id'   => (int)$_POST['category_id'] ?: null,
                'title'         => trim((string)$_POST['title']),
                'weekday'       => max(0, min(6, (int)$_POST['weekday'])),
                'time_text'     => trim((string)$_POST['time_text']),
                'place'         => trim((string)$_POST['place']),
                'material'      => trim((string)$_POST['material']),
                'details'       => trim(mb_substr((string)$_POST['details'], 0, 5000)),
                'min_skill'     => max(1, min(5, (int)$_POST['min_skill'])),
                'deadline_days' => max(0, min(30, (int)$_POST['deadline_days'])),
            ];
            if ($data['name'] === '' || $data['title'] === '') {
                flash('Bitte Name der Vorlage und Titel ausfüllen.');
                break;
            }
            $cols = array_keys($data);
            if ($tid = (int)($_POST['id'] ?? 0)) {
                $pdo->prepare('UPDATE event_templates SET ' . implode(', ', array_map(fn($c) => "$c = :$c", $cols)) . ' WHERE id = :id')->execute($data + ['id' => $tid]);
            } else {
                $data['sort'] = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM event_templates')->fetchColumn();
                $cols[] = 'sort';
                $pdo->prepare('INSERT INTO event_templates (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($data);
            }
            flash('Vorlage gespeichert.');
            break;
        case 'delete_tpl':
            $pdo->prepare('DELETE FROM event_templates WHERE id = ?')->execute([(int)$_POST['id']]);
            flash('Vorlage gelöscht.');
            break;
    }
    redirect('templates.php');
}

$cats = categories();
$tpls = $pdo->query('SELECT * FROM event_templates ORDER BY sort, name')->fetchAll();
$blank = ['id' => 0, 'name' => '', 'category_id' => array_key_first($cats), 'title' => '', 'weekday' => 6, 'time_text' => '', 'place' => '',
    'material' => '', 'details' => '', 'min_skill' => 1, 'deadline_days' => 2];

function tpl_form(array $t, array $cats): void
{
    $p = 't' . (int)$t['id'];
    ?>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="save_tpl"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <label for="<?= $p ?>n">Name der Vorlage (Knopf «Neues Event»)</label><input type="text" id="<?= $p ?>n" name="name" value="<?= e($t['name']) ?>" required>
      <label for="<?= $p ?>c">Kategorie</label>
      <select id="<?= $p ?>c" name="category_id"><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$t['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select>
      <label for="<?= $p ?>t">Titel des Events</label><input type="text" id="<?= $p ?>t" name="title" value="<?= e($t['title']) ?>" required>
      <div class="row">
        <span class="stack grow"><label for="<?= $p ?>w">Wochentag</label>
          <select id="<?= $p ?>w" name="weekday"><?php foreach (WEEKDAY_NAMES as $d => $dn): ?><option value="<?= $d ?>" <?= (int)$t['weekday'] === $d ? 'selected' : '' ?>><?= $dn ?></option><?php endforeach ?></select></span>
        <span class="stack grow"><label for="<?= $p ?>z">Zeit</label><input type="text" id="<?= $p ?>z" name="time_text" value="<?= e($t['time_text']) ?>"></span>
      </div>
      <label for="<?= $p ?>o">Ort / Treffpunkt</label><input type="text" id="<?= $p ?>o" name="place" value="<?= e($t['place']) ?>">
      <label for="<?= $p ?>m">Mitnehmen</label><input type="text" id="<?= $p ?>m" name="material" value="<?= e($t['material']) ?>">
      <label for="<?= $p ?>d">Infos für Eltern (Freitext)</label>
      <textarea id="<?= $p ?>d" name="details" rows="3"><?= e($t['details']) ?></textarea>
      <div class="row">
        <span class="stack grow"><label for="<?= $p ?>s">Wer darf sich anmelden?</label>
          <select id="<?= $p ?>s" name="min_skill"><?php foreach (SKILLS as $k => $v): ?><option value="<?= $k ?>" <?= (int)$t['min_skill'] === $k ? 'selected' : '' ?>><?= $k === 1 ? 'Alle Kinder' : 'Ab ' . e($v) ?></option><?php endforeach ?></select></span>
        <span class="stack grow"><label for="<?= $p ?>a">Anmeldeschluss</label>
          <select id="<?= $p ?>a" name="deadline_days"><?php foreach ([0, 1, 2, 3, 4, 5, 7, 10, 14, 21] as $dd): ?><option value="<?= $dd ?>" <?= (int)$t['deadline_days'] === $dd ? 'selected' : '' ?>><?= $dd === 0 ? 'am selben Tag' : $dd . ' Tage vorher' ?>, 20:00</option><?php endforeach ?></select></span>
      </div>
      <button class="btn small"><?= $t['id'] ? 'Vorlage speichern' : 'Vorlage erstellen' ?></button>
    </form>
    <?php
}

layout_head('Vorlagen', admin_nav(), 'templates.php');
?>
<div><p class="eyebrow">Hauptleiter</p><h1>Kategorien &amp; Vorlagen</h1></div>

<div class="card">
  <h2>Kategorien</h2>
  <p class="note">Kategorien gruppieren die Events, z.B. für die Statistik. Die Grundart bestimmt die Farbe und ob Rennkategorien (U10–U16) angezeigt werden.</p>
  <?php foreach ($cats as $c): ?>
    <form method="post" class="row nowrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <input type="text" name="name" value="<?= e($c['name']) ?>" aria-label="Name der Kategorie" class="grow" required>
      <select name="kind" aria-label="Grundart"><?php foreach (EVENT_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $c['kind'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach ?></select>
      <button class="btn ghost small" name="action" value="edit_cat">OK</button>
      <button class="linkbtn" name="action" value="delete_cat" aria-label="<?= e($c['name']) ?> löschen">✕</button>
    </form>
  <?php endforeach ?>
  <form method="post" class="row nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="add_cat">
    <input type="text" name="name" placeholder="Neue Kategorie, z.B. Konditionstraining" aria-label="Neue Kategorie" class="grow" required>
    <select name="kind" aria-label="Grundart"><?php foreach (EVENT_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach ?></select>
    <button class="btn small">+</button>
  </form>
</div>

<h2>Vorlagen für neue Events</h2>
<?php foreach ($tpls as $t): ?>
<details class="card"><summary class="row between"><b><?= e($t['name']) ?></b><span class="note"><?= e($cats[$t['category_id']]['name'] ?? '–') ?> · <?= WEEKDAY_NAMES[$t['weekday']] ?></span></summary>
  <?php tpl_form($t, $cats) ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_tpl"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="linkbtn">Vorlage löschen</button></form>
</details>
<?php endforeach ?>
<details class="card" <?= $tpls ? '' : 'open' ?>><summary class="btn small">+ Neue Vorlage</summary>
  <?php tpl_form($blank, $cats) ?>
</details>
<?php layout_foot();
