<?php
// Ersteinrichtung: legt den ersten Admin an. Funktioniert nur, solange noch kein Leiter existiert.

declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

if ((int)db()->query('SELECT COUNT(*) FROM leaders')->fetchColumn() > 0) {
    redirect('login.php');
}

$error = '';
if (is_post()) {
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if ($name === '' || $user === '' || strlen($pass) < 10) {
        $error = 'Bitte alle Felder ausfüllen. Das Passwort braucht mindestens 10 Zeichen.';
    } else {
        db()->prepare('INSERT INTO leaders (name, phone, username, pass_hash, is_admin) VALUES (?, ?, ?, ?, 1)')
            ->execute([$name, trim((string)($_POST['phone'] ?? '')), $user, password_hash($pass, PASSWORD_DEFAULT)]);
        $_SESSION['leader_id'] = (int)db()->lastInsertId();
        flash('Eingerichtet. Willkommen!');
        redirect('index.php');
    }
}

layout_head('Einrichtung');
?>
<div><p class="eyebrow">Ersteinrichtung</p><h1>Admin anlegen</h1></div>
<form method="post" class="card stack"><?= csrf_field() ?>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif ?>
  <label for="name">Ihr Name</label><input type="text" id="name" name="name" required>
  <label for="phone">Handynummer (für WhatsApp)</label><input type="tel" id="phone" name="phone" placeholder="079 123 45 67">
  <label for="username">Benutzername</label><input type="text" id="username" name="username" required>
  <label for="password">Passwort (mind. 10 Zeichen)</label><input type="password" id="password" name="password" minlength="10" required>
  <button class="btn">Einrichten</button>
</form>
<?php layout_foot();
