<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

require_leader('hauptleiter');
$pdo = db();
$cats = categories();

function next_weekday(int $dow): string
{
    $t = strtotime('today');
    while ((int)date('w', $t) !== $dow) {
        $t = strtotime('+1 day', $t);
    }
    return date('Y-m-d', $t);
}

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    $ev = $st->fetch() ?: redirect('index.php');
} else {
    $st = $pdo->prepare('SELECT * FROM event_templates WHERE id = ?');
    $st->execute([(int)($_GET['vorlage'] ?? 0)]);
    $t = $st->fetch() ?: ['category_id' => array_key_first($cats), 'title' => '', 'weekday' => 6, 'time_text' => '', 'place' => '',
        'material' => '', 'details' => '', 'min_skill' => 1, 'deadline_days' => 2];
    $ev = event_from_template($t, next_weekday((int)$t['weekday']));
}

$error = '';
if (is_post()) {
    csrf_check();
    $catId = isset($cats[(int)($_POST['category_id'] ?? 0)]) ? (int)$_POST['category_id'] : (int)array_key_first($cats);
    $data = [
        'category_id' => $catId,
        'type'        => $cats[$catId]['kind'] ?? 'off',
        'title'       => trim((string)($_POST['title'] ?? '')),
        'date'        => (string)($_POST['date'] ?? ''),
        'time_text'   => trim((string)($_POST['time_text'] ?? '')),
        'place'       => trim((string)($_POST['place'] ?? '')),
        'material'    => trim((string)($_POST['material'] ?? '')),
        'details'     => trim(mb_substr((string)($_POST['details'] ?? ''), 0, 5000)),
        'deadline'    => str_replace('T', ' ', (string)($_POST['deadline'] ?? '')),
        'info'        => trim((string)($_POST['info'] ?? '')),
        'min_skill'   => max(1, min(5, (int)($_POST['min_skill'] ?? 1))),
    ];
    if ($data['title'] === '' || !strtotime($data['date']) || !strtotime($data['deadline'])) {
        $error = 'Bitte Titel, Datum und Anmeldeschluss ausfüllen.';
        $ev = array_merge($ev, $data);
    } else {
        $cols = array_keys($data);
        if ($id) {
            $set = implode(', ', array_map(fn($c) => "$c = :$c", $cols));
            $pdo->prepare("UPDATE events SET $set WHERE id = :id")->execute($data + ['id' => $id]);
        } else {
            $pdo->prepare('INSERT INTO events (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($data);
            $id = (int)$pdo->lastInsertId();
        }
        flash('Event gespeichert.');
        redirect('event.php?id=' . $id);
    }
}

layout_head($id ? 'Event bearbeiten' : 'Neues Event', admin_nav(), 'index.php');
?>
<div><p class="eyebrow"><?= $id ? 'Event bearbeiten' : 'Neues Event (Entwurf)' ?></p><h1><?= e($ev['title'] !== '' ? $ev['title'] : 'Neues Event') ?></h1></div>
<form method="post" class="card stack"><?= csrf_field() ?>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif ?>
  <label for="category_id">Kategorie</label>
  <select id="category_id" name="category_id"><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$ev['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select>
  <label for="title">Titel</label><input type="text" id="title" name="title" value="<?= e($ev['title']) ?>" required>
  <label for="date">Datum</label><input type="date" id="date" name="date" value="<?= e($ev['date']) ?>" required>
  <label for="time_text">Zeit</label><input type="text" id="time_text" name="time_text" value="<?= e($ev['time_text']) ?>" placeholder="14:00–16:00">
  <label for="place">Ort / Treffpunkt</label><input type="text" id="place" name="place" value="<?= e($ev['place']) ?>">
  <label for="material">Mitnehmen</label><textarea id="material" name="material" rows="2"><?= e($ev['material']) ?></textarea>
  <label for="details">Infos für Eltern (Freitext)</label>
  <div class="toolbar" data-format-for="details">
    <button type="button" data-wrap="*" title="Fett"><b>F</b></button>
    <button type="button" data-wrap="_" title="Kursiv"><i>K</i></button>
    <button type="button" data-list title="Aufzählung">• Liste</button>
  </div>
  <textarea id="details" name="details" rows="7" placeholder="z.B. Programm, Startliste, Kosten, Anfahrt …"><?= e($ev['details']) ?></textarea>
  <p class="note">Formatierung wie in WhatsApp: *fett*, _kursiv_, Zeilen mit «- » werden zur Aufzählung, Links sind klickbar. Ausschreibungen als PDF können Sie nach dem Speichern anhängen.</p>
  <label for="deadline">Anmeldeschluss</label><input type="datetime-local" id="deadline" name="deadline" value="<?= e(str_replace(' ', 'T', substr($ev['deadline'], 0, 16))) ?>" required>
  <label for="min_skill">Wer darf sich anmelden?</label>
  <select id="min_skill" name="min_skill"><?php foreach (SKILLS as $k => $v): ?><option value="<?= $k ?>" <?= (int)$ev['min_skill'] === $k ? 'selected' : '' ?>><?= $k === 1 ? 'Alle Kinder' : 'Ab Stufe ' . e($v) ?></option><?php endforeach ?></select>
  <label for="info">Kurzinfo (z.B. «Findet statt, wir gehen biken»)</label><input type="text" id="info" name="info" value="<?= e($ev['info']) ?>">
  <button class="btn">Speichern</button>
  <a class="note" href="<?= $id ? 'event.php?id=' . $id : 'index.php' ?>">Abbrechen</a>
</form>
<?php layout_foot();
