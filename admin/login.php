<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

if ((int)db()->query('SELECT COUNT(*) FROM leaders')->fetchColumn() === 0) {
    redirect('install.php');
}

$error = '';
if (is_post()) {
    csrf_check();
    $_SESSION['login_tries'] = ($_SESSION['login_tries'] ?? 0) + 1;
    if ($_SESSION['login_tries'] > 10) {
        sleep(2);
    }
    $st = db()->prepare('SELECT * FROM leaders WHERE username = ?');
    $st->execute([trim((string)($_POST['username'] ?? ''))]);
    $l = $st->fetch();
    if ($l && password_verify((string)($_POST['password'] ?? ''), $l['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['leader_id'] = (int)$l['id'];
        $_SESSION['login_tries'] = 0;
        redirect('index.php');
    }
    $error = 'Benutzername oder Passwort stimmt nicht.';
}

layout_head('Login Leiter');
?>
<div><p class="eyebrow"><?= e($config['site_name']) ?></p><h1>Login für Leiter</h1></div>
<form method="post" class="card stack"><?= csrf_field() ?>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif ?>
  <label for="username">Benutzername</label>
  <input type="text" id="username" name="username" autocomplete="username" required autofocus>
  <label for="password">Passwort</label>
  <input type="password" id="password" name="password" autocomplete="current-password" required>
  <button class="btn">Anmelden</button>
</form>
<?php layout_foot();
