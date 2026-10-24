<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

require_leader();
$pdo = db();

function next_weekday(int $dow): string
{
    $t = strtotime('today');
    while ((int)date('w', $t) !== $dow) {
        $t = strtotime('+1 day', $t);
    }
    return date('Y-m-d', $t);
}

$templates = [
    'off'   => ['type' => 'off', 'title' => 'Off Snow Training', 'dow' => 6, 'time_text' => '14:00–16:00', 'place' => 'Turnhalle',
                'material' => 'Turnschuhe, Trinkflasche. Bei schönem Wetter draussen: Bike + Helm', 'min_skill' => 1],
    'on_sa' => ['type' => 'on', 'title' => 'On Snow Training', 'dow' => 6, 'time_text' => '09:30–12:30', 'place' => 'Tschentenalp, Trainingshang',
                'material' => 'Skis, Helm, Rückenpanzer, Skipass, Lunch', 'min_skill' => 3],
    'on_mi' => ['type' => 'on', 'title' => 'On Snow Mittwoch', 'dow' => 3, 'time_text' => '13:30–16:30', 'place' => 'Tschentenalp, Trainingshang',
                'material' => 'Skis, Helm, Rückenpanzer, Skipass', 'min_skill' => 1],
    'race'  => ['type' => 'race', 'title' => 'Rennen', 'dow' => 6, 'time_text' => 'Start 10:00', 'place' => '',
                'material' => 'Rennanzug, Rückenpanzer, Skipass', 'min_skill' => 1],
];

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $st->execute([$id]);
    $ev = $st->fetch() ?: redirect('index.php');
} else {
    $t = $templates[$_GET['vorlage'] ?? 'off'] ?? $templates['off'];
    $date = next_weekday($t['dow']);
    $ev = $t + ['id' => 0, 'date' => $date, 'deadline' => date('Y-m-d', strtotime($date . ' -2 days')) . ' 20:00', 'info' => '', 'status' => 'draft'];
}

$error = '';
if (is_post()) {
    csrf_check();
    $data = [
        'type'      => array_key_exists($_POST['type'] ?? '', EVENT_TYPES) ? $_POST['type'] : 'off',
        'title'     => trim((string)($_POST['title'] ?? '')),
        'date'      => (string)($_POST['date'] ?? ''),
        'time_text' => trim((string)($_POST['time_text'] ?? '')),
        'place'     => trim((string)($_POST['place'] ?? '')),
        'material'  => trim((string)($_POST['material'] ?? '')),
        'deadline'  => str_replace('T', ' ', (string)($_POST['deadline'] ?? '')),
        'info'      => trim((string)($_POST['info'] ?? '')),
        'min_skill' => max(1, min(5, (int)($_POST['min_skill'] ?? 1))),
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
<div><p class="eyebrow"><?= $id ? 'Event bearbeiten' : 'Neues Event (Entwurf)' ?></p><h1><?= e($ev['title']) ?></h1></div>
<form method="post" class="card stack"><?= csrf_field() ?>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif ?>
  <label for="type">Art</label>
  <select id="type" name="type"><?php foreach (EVENT_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $ev['type'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach ?></select>
  <label for="title">Titel</label><input type="text" id="title" name="title" value="<?= e($ev['title']) ?>" required>
  <label for="date">Datum</label><input type="date" id="date" name="date" value="<?= e($ev['date']) ?>" required>
  <label for="time_text">Zeit</label><input type="text" id="time_text" name="time_text" value="<?= e($ev['time_text']) ?>" placeholder="14:00–16:00">
  <label for="place">Ort / Treffpunkt</label><input type="text" id="place" name="place" value="<?= e($ev['place']) ?>">
  <label for="material">Mitnehmen</label><textarea id="material" name="material" rows="2"><?= e($ev['material']) ?></textarea>
  <label for="deadline">Anmeldeschluss</label><input type="datetime-local" id="deadline" name="deadline" value="<?= e(str_replace(' ', 'T', substr($ev['deadline'], 0, 16))) ?>" required>
  <label for="min_skill">Wer darf sich anmelden?</label>
  <select id="min_skill" name="min_skill"><?php foreach (SKILLS as $k => $v): ?><option value="<?= $k ?>" <?= (int)$ev['min_skill'] === $k ? 'selected' : '' ?>><?= $k === 1 ? 'Alle Kinder' : 'Ab Stufe ' . e($v) ?></option><?php endforeach ?></select>
  <label for="info">Info für Eltern (z.B. «Findet statt, wir gehen biken»)</label><input type="text" id="info" name="info" value="<?= e($ev['info']) ?>">
  <button class="btn">Speichern</button>
  <a class="note" href="<?= $id ? 'event.php?id=' . $id : 'index.php' ?>">Abbrechen</a>
</form>
<?php layout_foot();
