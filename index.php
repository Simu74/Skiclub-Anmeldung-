<?php
// Eltern-Seite. Jede Familie hat einen persönlichen Link (?f=...), kein Passwort.

declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$pdo = db();
$token = (string)($_GET['f'] ?? $_COOKIE['familie'] ?? '');
$family = null;
if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
    $st = $pdo->prepare('SELECT * FROM families WHERE token = ?');
    $st->execute([$token]);
    $family = $st->fetch() ?: null;
}

if (!$family) {
    layout_head('Anmeldung');
    ?>
    <div><p class="eyebrow"><?= e($config['site_name']) ?></p><h1>Anmeldung Training &amp; Rennen</h1></div>
    <div class="card">
      <p>Bitte öffnen Sie die Seite über Ihren <b>persönlichen Link</b>, den Sie von den Leitern per WhatsApp erhalten haben.</p>
      <p class="note">Kein Link? Melden Sie sich bei der Leitung.</p>
    </div>
    <p class="note"><a href="admin/">Login für Leiter</a></p>
    <?php
    layout_foot();
    exit;
}

// Link merken, damit die Seite auch ohne Link (z.B. vom Startbildschirm) wieder aufgeht.
setcookie('familie', $family['token'], [
    'expires' => time() + 400 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

$st = $pdo->prepare('SELECT * FROM kids WHERE family_id = ? AND active = 1 ORDER BY birth_year');
$st->execute([$family['id']]);
$kids = $st->fetchAll();
$kidIds = array_map('intval', array_column($kids, 'id'));
$self = '?f=' . $family['token'];

function load_event(int $id): ?array
{
    $st = db()->prepare("SELECT * FROM events WHERE id = ? AND status <> 'draft'");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $chosen = array_values(array_intersect($kidIds, array_map('intval', (array)($_POST['kids'] ?? []))));

    if ($action === 'register' || $action === 'signoff') {
        $event = load_event((int)$_POST['event_id']);
        if (!$event || $event['status'] !== 'open') {
            flash('Dieses Event ist nicht mehr offen.');
            redirect($self);
        }
        $up = $pdo->prepare(
            "INSERT INTO registrations (event_id, kid_id, status, reason, note, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))
             ON CONFLICT(event_id, kid_id) DO UPDATE SET status = excluded.status,
               reason = excluded.reason, note = excluded.note, updated_at = excluded.updated_at"
        );
        if ($action === 'register') {
            if (deadline_passed($event)) {
                flash('Der Anmeldeschluss ist vorbei. Bitte direkt bei der Leitung melden.');
                redirect($self);
            }
            $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 300));
            $st = $pdo->prepare('SELECT kid_id, status FROM registrations WHERE event_id = ?');
            $st->execute([$event['id']]);
            $before = array_column($st->fetchAll(), 'status', 'kid_id');
            foreach ($kids as $k) {
                $id = (int)$k['id'];
                if (in_array($id, $chosen, true) && $k['skill'] >= $event['min_skill']) {
                    $up->execute([$event['id'], $id, 'in', '', $note]);
                } elseif (($before[$id] ?? '') === 'in') {
                    $up->execute([$event['id'], $id, 'out', 'Verhindert', '']);
                }
            }
            if (!empty($_POST['driver'])) {
                $seats = max(1, min(8, (int)($_POST['seats'] ?? 1)));
                $pdo->prepare('INSERT OR REPLACE INTO drivers (event_id, family_id, seats) VALUES (?, ?, ?)')
                    ->execute([$event['id'], $family['id'], $seats]);
            } else {
                $pdo->prepare('DELETE FROM drivers WHERE event_id = ? AND family_id = ?')->execute([$event['id'], $family['id']]);
            }
            flash($chosen ? 'Anmeldung gespeichert. Danke!' : 'Gespeichert: niemand angemeldet.');
        } else {
            $reason = in_array($_POST['reason'] ?? '', ['Krank', 'Verhindert', 'Anderes'], true) ? $_POST['reason'] : 'Verhindert';
            foreach ($chosen as $id) {
                $up->execute([$event['id'], $id, 'out', $reason, '']);
            }
            flash($chosen ? 'Abgemeldet. Die Leiter sehen es sofort.' : 'Bitte ein Kind auswählen.');
        }
        redirect($self);
    }

    if ($action === 'kid') {
        $id = (int)($_POST['kid_id'] ?? 0);
        if (in_array($id, $kidIds, true)) {
            $pdo->prepare('UPDATE kids SET emergency_phone = ?, health = ? WHERE id = ?')->execute([
                trim(mb_substr((string)$_POST['emergency_phone'], 0, 40)),
                trim(mb_substr((string)$_POST['health'], 0, 300)),
                $id,
            ]);
            flash('Angaben gespeichert.');
        }
        redirect($self . '&view=kinder');
    }
}

$view = $_GET['view'] ?? 'events';
$nav = [$self => 'Trainings', $self . '&view=kinder' => 'Meine Kinder'];
layout_head('Anmeldung', $nav, $view === 'kinder' ? $self . '&view=kinder' : $self);

if ($view === 'kinder'): ?>
  <div><p class="eyebrow">Einmal erfasst, immer vorgewählt</p><h1>Meine Kinder</h1></div>
  <?php foreach ($kids as $k): ?>
    <form method="post" class="card"><?= csrf_field() ?>
      <input type="hidden" name="action" value="kid"><input type="hidden" name="kid_id" value="<?= (int)$k['id'] ?>">
      <div class="row between"><h2><?= e($k['first_name']) ?> <?= e($family['name']) ?></h2>
        <span class="row tight"><span class="piste p-<?= piste((int)$k['skill']) ?>"></span><span class="note"><?= e(SKILLS[$k['skill']]) ?></span></span></div>
      <p class="note">Jahrgang <?= (int)$k['birth_year'] ?> · Rennkategorie <?= race_category((int)$k['birth_year']) ?></p>
      <label for="em-<?= (int)$k['id'] ?>">Notfallnummer</label>
      <input type="tel" id="em-<?= (int)$k['id'] ?>" name="emergency_phone" value="<?= e($k['emergency_phone']) ?>" placeholder="079 123 45 67">
      <label for="he-<?= (int)$k['id'] ?>">Allergien, Medikamente, Hinweise</label>
      <textarea id="he-<?= (int)$k['id'] ?>" name="health" rows="2" placeholder="z.B. Nussallergie"><?= e($k['health']) ?></textarea>
      <p class="note">Diese Angaben sehen nur die Leiter. Die Stufe setzt die Leitung.</p>
      <button class="btn ghost">Speichern</button>
    </form>
  <?php endforeach ?>
  <?php if (!$kids): ?><div class="card"><p>Noch keine Kinder erfasst. Bitte bei der Leitung melden.</p></div><?php endif ?>
  <p class="note">Ein Kind fehlt oder ist neu? Bitte der Leitung melden. Die Daten werden nur für das Training verwendet.</p>
<?php
    layout_foot();
    exit;
endif;

$st = $pdo->prepare("SELECT * FROM events WHERE status <> 'draft' AND date >= date('now', 'localtime') ORDER BY date, id");
$st->execute();
$events = $st->fetchAll();

$regs = [];
$drivers = [];
if ($events && $kidIds) {
    $in = implode(',', $kidIds);
    foreach ($pdo->query("SELECT event_id, kid_id, status, reason FROM registrations WHERE kid_id IN ($in)") as $r) {
        $regs[$r['event_id']][$r['kid_id']] = $r;
    }
}
$st = $pdo->prepare('SELECT event_id, seats FROM drivers WHERE family_id = ?');
$st->execute([$family['id']]);
$drivers = array_column($st->fetchAll(), 'seats', 'event_id');
?>
  <div><p class="eyebrow"><?= e($config['site_name']) ?></p><h1>Hallo Familie <?= e($family['name']) ?></h1></div>

  <?php if ($events): $next = $events[0]; ?>
    <div class="banner <?= $next['status'] === 'cancelled' ? 'cancel' : 'ok' ?>">
      <span class="dot"></span>
      <div><b><?= $next['status'] === 'cancelled' ? 'Abgesagt' : 'Als Nächstes' ?>: <?= fmt_date($next['date']) ?></b>
      <p><?= e($next['info'] !== '' ? $next['info'] : $next['title'] . ', ' . $next['place']) ?></p></div>
    </div>
  <?php else: ?>
    <div class="card"><p>Im Moment sind keine Trainings ausgeschrieben.</p></div>
  <?php endif ?>

  <?php foreach ($events as $ev):
      $r = $regs[$ev['id']] ?? [];
      $mineIn = array_filter($kids, fn($k) => ($r[$k['id']]['status'] ?? '') === 'in');
      $mineOut = array_filter($kids, fn($k) => ($r[$k['id']]['status'] ?? '') === 'out');
      $answered = (bool)$r;
      $closed = deadline_passed($ev);
      $cancelled = $ev['status'] === 'cancelled';
  ?>
  <article class="card">
    <div class="row between"><span class="date"><?= fmt_date($ev['date']) ?><?= $ev['time_text'] !== '' ? ' · ' . e($ev['time_text']) : '' ?></span>
      <span class="pill <?= e($ev['type']) ?>"><?= EVENT_TYPES[$ev['type']] ?></span></div>
    <h2><?= e($ev['title']) ?></h2>
    <dl class="meta">
      <?php if ($ev['place'] !== ''): ?><dt>Ort</dt><dd><?= e($ev['place']) ?></dd><?php endif ?>
      <?php if ($ev['material'] !== ''): ?><dt>Mitnehmen</dt><dd><?= e($ev['material']) ?></dd><?php endif ?>
      <dt>Anmelden bis</dt><dd><?= fmt_deadline($ev['deadline']) ?></dd>
    </dl>
    <?php if ($ev['info'] !== '' && $ev !== $events[0]): ?><p class="note"><?= e($ev['info']) ?></p><?php endif ?>

    <?php if ($cancelled): ?>
      <span class="pill cancelled">Abgesagt</span>
    <?php else: ?>
      <?php if ($answered): ?>
        <div class="chips">
          <?php foreach ($mineIn as $k): ?><span class="chip yes">✓ <?= e($k['first_name']) ?></span><?php endforeach ?>
          <?php foreach ($mineOut as $k): ?><span class="chip"><?= e($k['first_name']) ?>: abgemeldet</span><?php endforeach ?>
          <?php if (isset($drivers[$ev['id']])): ?><span class="chip yes">Sie fahren · <?= (int)$drivers[$ev['id']] ?> Plätze</span><?php endif ?>
        </div>
      <?php endif ?>

      <?php if (!$closed): ?>
      <details class="sheet" <?= $answered ? '' : 'open' ?>>
        <summary class="btn<?= $answered ? ' ghost' : '' ?>"><?= $answered ? 'Anmeldung ändern' : 'Anmelden' ?></summary>
        <form method="post" class="stack"><?= csrf_field() ?>
          <input type="hidden" name="action" value="register"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
          <p class="note">Ihre Kinder sind vorgewählt. Häkchen entfernen, wer nicht kommt.</p>
          <?php foreach ($kids as $k):
              $ok = $k['skill'] >= $ev['min_skill'];
              $checked = $ok && ($answered ? ($r[$k['id']]['status'] ?? '') === 'in' : true); ?>
            <label class="kid<?= $ok ? '' : ' disabled' ?>">
              <input type="checkbox" name="kids[]" value="<?= (int)$k['id'] ?>" <?= $checked ? 'checked' : '' ?> <?= $ok ? '' : 'disabled' ?>>
              <span class="piste p-<?= piste((int)$k['skill']) ?>"></span>
              <span><span class="nm"><?= e($k['first_name']) ?></span><br>
              <span class="sub">Jahrgang <?= (int)$k['birth_year'] ?><?= $ev['type'] === 'race' ? ' · Kategorie ' . race_category((int)$k['birth_year']) : '' ?><?= $ok ? '' : ' · für dieses Training noch nicht freigegeben' ?></span></span>
            </label>
          <?php endforeach ?>
          <label class="switch"><span><b>Ich kann fahren</b><br><span class="note">für Fahrgemeinschaften</span></span>
            <input type="checkbox" name="driver" value="1" data-toggle="seats-<?= (int)$ev['id'] ?>" <?= isset($drivers[$ev['id']]) ? 'checked' : '' ?>></label>
          <label class="switch" id="seats-<?= (int)$ev['id'] ?>" <?= isset($drivers[$ev['id']]) ? '' : 'hidden' ?>><b>Freie Plätze</b>
            <select name="seats"><?php for ($i = 1; $i <= 8; $i++): ?><option <?= ($drivers[$ev['id']] ?? 3) == $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor ?></select></label>
          <label for="note-<?= (int)$ev['id'] ?>" class="note">Bemerkung an die Leiter (freiwillig)</label>
          <input type="text" id="note-<?= (int)$ev['id'] ?>" name="note" maxlength="300" placeholder="z.B. wird um 15:30 abgeholt">
          <button class="btn" data-count-label="Anmeldung senden">Anmeldung senden</button>
        </form>
      </details>
      <?php else: ?>
        <p class="note">Anmeldeschluss vorbei. Änderungen bitte direkt der Leitung melden.</p>
      <?php endif ?>

      <?php if ($mineIn): ?>
      <details class="sheet">
        <summary class="btn ghost">Abmelden / krank</summary>
        <form method="post" class="stack"><?= csrf_field() ?>
          <input type="hidden" name="action" value="signoff"><input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
          <?php foreach ($mineIn as $k): ?>
            <label class="kid"><input type="checkbox" name="kids[]" value="<?= (int)$k['id'] ?>"><span class="nm"><?= e($k['first_name']) ?></span></label>
          <?php endforeach ?>
          <label for="reason-<?= (int)$ev['id'] ?>" class="note">Grund</label>
          <select id="reason-<?= (int)$ev['id'] ?>" name="reason"><option>Krank</option><option>Verhindert</option><option>Anderes</option></select>
          <button class="btn red">Abmelden</button>
        </form>
      </details>
      <?php endif ?>
    <?php endif ?>
  </article>
  <?php endforeach ?>
<?php layout_foot();
