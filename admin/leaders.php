<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader();
$pdo = db();

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
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
    } elseif ($me['is_admin'] && $a === 'add') {
        $user = trim((string)$_POST['username']);
        $pass = (string)$_POST['password'];
        $exists = $pdo->prepare('SELECT 1 FROM leaders WHERE username = ?');
        $exists->execute([$user]);
        if (trim((string)$_POST['name']) === '' || $user === '' || strlen($pass) < 10) {
            flash('Bitte Name, Benutzername und ein Passwort mit mind. 10 Zeichen angeben.');
        } elseif ($exists->fetchColumn()) {
            flash('Diesen Benutzernamen gibt es schon.');
        } else {
            $pdo->prepare('INSERT INTO leaders (name, phone, username, pass_hash, is_admin) VALUES (?, ?, ?, ?, ?)')->execute([
                trim((string)$_POST['name']), trim((string)$_POST['phone']), $user, password_hash($pass, PASSWORD_DEFAULT), empty($_POST['is_admin']) ? 0 : 1,
            ]);
            flash('Leiter hinzugefügt.');
        }
    } elseif ($me['is_admin'] && $a === 'delete' && (int)$_POST['leader_id'] !== (int)$me['id']) {
        $pdo->prepare('DELETE FROM leaders WHERE id = ?')->execute([(int)$_POST['leader_id']]);
        flash('Leiter entfernt.');
    }
    redirect('leaders.php');
}

$leaders = $pdo->query('SELECT * FROM leaders ORDER BY name')->fetchAll();
layout_head('Leiter', admin_nav(), 'leaders.php');
?>
<div><p class="eyebrow">Team</p><h1>Leiter</h1></div>

<div class="card">
  <?php foreach ($leaders as $l): ?>
    <div class="gkid"><span class="nm"><b><?= e($l['name']) ?></b> <span class="note"><?= e($l['username']) ?><?= $l['is_admin'] ? ' · Admin' : '' ?><?= $l['phone'] !== '' ? ' · ' . e($l['phone']) : '' ?></span></span>
      <?php if ($me['is_admin'] && (int)$l['id'] !== (int)$me['id']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="leader_id" value="<?= (int)$l['id'] ?>"><button class="linkbtn">entfernen</button></form>
      <?php endif ?></div>
  <?php endforeach ?>
</div>

<?php if ($me['is_admin']): ?>
<details class="card"><summary class="btn small">+ Leiter hinzufügen</summary>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label for="ln">Name</label><input type="text" id="ln" name="name" required>
    <label for="lp">Handy (für WhatsApp)</label><input type="tel" id="lp" name="phone">
    <label for="lu">Benutzername</label><input type="text" id="lu" name="username" required>
    <label for="lpw">Startpasswort (mind. 10 Zeichen, bitte persönlich mitteilen)</label><input type="text" id="lpw" name="password" minlength="10" required>
    <label class="row tight"><input type="checkbox" name="is_admin" value="1"> Admin (darf Leiter verwalten)</label>
    <button class="btn">Hinzufügen</button>
  </form>
</details>
<?php endif ?>

<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="phone">
  <h3>Meine Handynummer</h3>
  <label for="myphone" class="note">Damit Gruppenlisten direkt an Sie gesendet werden können</label>
  <input type="tel" id="myphone" name="phone" value="<?= e($me['phone']) ?>"><button class="btn ghost small">Speichern</button>
</form>

<form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="action" value="password">
  <h3>Mein Passwort ändern</h3>
  <label for="cur">Aktuelles Passwort</label><input type="password" id="cur" name="current" autocomplete="current-password" required>
  <label for="np">Neues Passwort (mind. 10 Zeichen)</label><input type="password" id="np" name="password" minlength="10" autocomplete="new-password" required>
  <button class="btn ghost small">Ändern</button>
</form>
<p class="note"><a href="logout.php">Abmelden</a></p>
<?php layout_foot();
