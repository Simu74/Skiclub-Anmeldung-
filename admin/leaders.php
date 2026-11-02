<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader();
$isAdmin = has_role($me, 'admin');
$pdo = db();

function admin_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM leaders WHERE role = 'admin'")->fetchColumn();
}

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $role = array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : 'leiter';
    $lid = (int)($_POST['leader_id'] ?? 0);
    if ($a === 'password') {
        $new = (string)($_POST['password'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), $me['pass_hash'])) {
            flash('Das aktuelle Passwort stimmt nicht.');
        } elseif (strlen($new) < 10) {
            flash('Das neue Passwort braucht mindestens 10 Zeichen.');
        } else {
            $pdo->prepare('UPDATE leaders SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            flash('Passwort geändert.');
        }
    } elseif ($a === 'phone') {
        $pdo->prepare('UPDATE leaders SET phone = ? WHERE id = ?')->execute([trim((string)$_POST['phone']), $me['id']]);
        flash('Handynummer gespeichert.');
    } elseif ($isAdmin && $a === 'add') {
        $user = trim((string)$_POST['username']);
        $pass = (string)$_POST['password'];
        $exists = $pdo->prepare('SELECT 1 FROM leaders WHERE username = ?');
        $exists->execute([$user]);
        if (trim((string)$_POST['name']) === '' || $user === '' || strlen($pass) < 10) {
            flash('Bitte Name, Benutzername und ein Passwort mit mind. 10 Zeichen angeben.');
        } elseif ($exists->fetchColumn()) {
            flash('Diesen Benutzernamen gibt es schon.');
        } else {
            $pdo->prepare('INSERT INTO leaders (name, phone, username, pass_hash, is_admin, role) VALUES (?, ?, ?, ?, ?, ?)')->execute([
                trim((string)$_POST['name']), trim((string)$_POST['phone']), $user, password_hash($pass, PASSWORD_DEFAULT), $role === 'admin' ? 1 : 0, $role,
            ]);
            flash(ROLES[$role] . ' hinzugefügt.');
        }
    } elseif ($isAdmin && $a === 'role') {
        if ($lid === (int)$me['id'] && $role !== 'admin' && admin_count() <= 1) {
            flash('Es braucht mindestens einen Admin.');
        } else {
            $pdo->prepare('UPDATE leaders SET role = ?, is_admin = ? WHERE id = ?')->execute([$role, $role === 'admin' ? 1 : 0, $lid]);
            flash('Rolle geändert.');
        }
    } elseif ($isAdmin && $a === 'reset') {
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 10) {
            flash('Das neue Passwort braucht mindestens 10 Zeichen.');
        } else {
            $pdo->prepare('UPDATE leaders SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $lid]);
            flash('Passwort zurückgesetzt. Bitte persönlich mitteilen.');
        }
    } elseif ($isAdmin && $a === 'delete' && $lid !== (int)$me['id']) {
        $pdo->prepare('DELETE FROM leaders WHERE id = ?')->execute([$lid]);
        flash('Person entfernt.');
    }
    redirect('leaders.php');
}

$leaders = $pdo->query("SELECT * FROM leaders ORDER BY CASE role WHEN 'admin' THEN 1 WHEN 'hauptleiter' THEN 2 ELSE 3 END, name")->fetchAll();
layout_head('Team', admin_nav(), 'leaders.php');
?>
<div><p class="eyebrow">Team</p><h1>Leiter &amp; Rollen</h1></div>

<div class="card">
  <?php foreach ($leaders as $l): ?>
    <div class="gkid"><span class="nm"><b><?= e($l['name']) ?></b> <span class="note"><?= e($l['username']) ?><?= $l['phone'] !== '' ? ' · ' . e($l['phone']) : '' ?></span></span>
      <?php if ($isAdmin): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="leader_id" value="<?= (int)$l['id'] ?>">
          <select name="role" data-autosubmit aria-label="Rolle von <?= e($l['name']) ?>"><?php foreach (ROLES as $rk => $rv): ?><option value="<?= $rk ?>" <?= $l['role'] === $rk ? 'selected' : '' ?>><?= $rv ?></option><?php endforeach ?></select>
          <noscript><button class="btn ghost small">OK</button></noscript></form>
      <?php else: ?>
        <span class="pill"><?= ROLES[$l['role']] ?></span>
      <?php endif ?>
    </div>
    <?php if ($isAdmin && (int)$l['id'] !== (int)$me['id']): ?>
      <details class="note"><summary>Passwort zurücksetzen / entfernen</summary>
        <form method="post" class="row nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="leader_id" value="<?= (int)$l['id'] ?>">
          <input type="text" name="password" minlength="10" placeholder="Neues Passwort (mind. 10 Zeichen)" aria-label="Neues Passwort" required>
          <button class="btn ghost small">Setzen</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="leader_id" value="<?= (int)$l['id'] ?>"><button class="linkbtn"><?= e($l['name']) ?> entfernen</button></form>
      </details>
    <?php endif ?>
  <?php endforeach ?>
</div>

<div class="card">
  <h3>Was darf welche Rolle?</h3>
  <p><b>Leiter:</b> sieht Events und Gruppen, meldet sich als Gruppenleiter, erfasst Präsenz und Rückmeldungen zu Kindern.</p>
  <p><b>Hauptleiter:</b> zusätzlich Events erstellen, freischalten und absagen, Kategorien und Vorlagen, Gruppen einteilen, Leiter zuweisen, Familien erfassen, WhatsApp-Nachrichten.</p>
  <p><b>Admin:</b> alles, zusätzlich Team und Rollen verwalten, Statistik, Events löschen.</p>
</div>

<?php if ($isAdmin): ?>
<details class="card"><summary class="btn small">+ Person hinzufügen</summary>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label for="ln">Name</label><input type="text" id="ln" name="name" required>
    <label for="lp">Handy (für WhatsApp)</label><input type="tel" id="lp" name="phone">
    <label for="lr">Rolle</label>
    <select id="lr" name="role"><?php foreach (ROLES as $rk => $rv): ?><option value="<?= $rk ?>"><?= $rv ?></option><?php endforeach ?></select>
    <label for="lu">Benutzername</label><input type="text" id="lu" name="username" required>
    <label for="lpw">Startpasswort (mind. 10 Zeichen, bitte persönlich mitteilen)</label><input type="text" id="lpw" name="password" minlength="10" required>
    <button class="btn">Hinzufügen</button>
  </form>
</details>
<?php endif ?>

<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="phone">
  <h3>Meine Handynummer</h3>
  <label for="myphone" class="note">Damit Gruppenlisten direkt an dich gesendet werden können</label>
  <input type="tel" id="myphone" name="phone" value="<?= e($me['phone']) ?>"><button class="btn ghost small">Speichern</button>
</form>

<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="password">
  <h3>Mein Passwort ändern</h3>
  <label for="cur">Aktuelles Passwort</label><input type="password" id="cur" name="current" autocomplete="current-password" required>
  <label for="np">Neues Passwort (mind. 10 Zeichen)</label><input type="password" id="np" name="password" minlength="10" autocomplete="new-password" required>
  <button class="btn ghost small">Ändern</button>
</form>
<?php layout_foot();
