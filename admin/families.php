<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

require_leader();
$pdo = db();

if (is_post()) {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $fid = (int)($_POST['family_id'] ?? 0);
    switch ($a) {
        case 'add_family':
            $name = trim((string)$_POST['name']);
            if ($name !== '') {
                $pdo->prepare('INSERT INTO families (name, phone, token) VALUES (?, ?, ?)')
                    ->execute([$name, trim((string)$_POST['phone']), bin2hex(random_bytes(16))]);
                $fid = (int)$pdo->lastInsertId();
                // Kinder zeilenweise: "Lena 2018"
                foreach (preg_split('/\R/', (string)($_POST['kids'] ?? '')) as $line) {
                    if (preg_match('/^\s*(.+?)[\s,]+(\d{4})\s*$/u', $line, $m)) {
                        $pdo->prepare('INSERT INTO kids (family_id, first_name, birth_year) VALUES (?, ?, ?)')->execute([$fid, trim($m[1]), (int)$m[2]]);
                    }
                }
                flash("Familie $name erfasst. Jetzt den Link per WhatsApp senden.");
            }
            redirect('families.php#f' . $fid);
        case 'edit_family':
            $pdo->prepare('UPDATE families SET name = ?, phone = ? WHERE id = ?')->execute([trim((string)$_POST['name']), trim((string)$_POST['phone']), $fid]);
            flash('Gespeichert.');
            break;
        case 'add_kid':
            $name = trim((string)$_POST['first_name']);
            if ($name !== '' && (int)$_POST['birth_year'] > 2000) {
                $pdo->prepare('INSERT INTO kids (family_id, first_name, birth_year, skill) VALUES (?, ?, ?, ?)')
                    ->execute([$fid, $name, (int)$_POST['birth_year'], max(1, min(5, (int)$_POST['skill']))]);
                flash("$name hinzugefügt.");
            }
            break;
        case 'edit_kid':
            $pdo->prepare('UPDATE kids SET first_name = ?, birth_year = ?, skill = ?, active = ? WHERE id = ?')->execute([
                trim((string)$_POST['first_name']), (int)$_POST['birth_year'], max(1, min(5, (int)$_POST['skill'])), empty($_POST['active']) ? 0 : 1, (int)$_POST['kid_id'],
            ]);
            flash('Gespeichert.');
            break;
        case 'skill':
            $pdo->prepare('UPDATE kids SET skill = ? WHERE id = ?')->execute([max(1, min(5, (int)$_POST['skill'])), (int)$_POST['kid_id']]);
            flash('Stufe gespeichert.');
            break;
        case 'new_link':
            $pdo->prepare('UPDATE families SET token = ? WHERE id = ?')->execute([bin2hex(random_bytes(16)), $fid]);
            flash('Neuer Link erstellt. Der alte funktioniert nicht mehr.');
            break;
        case 'delete_family':
            if (!empty($_POST['confirm'])) {
                $pdo->prepare('DELETE FROM families WHERE id = ?')->execute([$fid]);
                flash('Familie und ihre Daten gelöscht.');
                redirect('families.php');
            }
            flash('Zum Löschen bitte das Häkchen setzen.');
            break;
    }
    redirect('families.php#f' . $fid);
}

$families = $pdo->query('SELECT * FROM families ORDER BY name')->fetchAll();
$kidsBy = [];
foreach ($pdo->query('SELECT * FROM kids ORDER BY birth_year') as $k) {
    $kidsBy[$k['family_id']][] = $k;
}
$nKids = (int)$pdo->query('SELECT COUNT(*) FROM kids WHERE active = 1')->fetchColumn();

layout_head('Familien', admin_nav(), 'families.php');
?>
<div><p class="eyebrow">Leiter</p><h1>Familien &amp; Kinder</h1>
  <p class="note"><?= count($families) ?> Familien, <?= $nKids ?> aktive Kinder. Jede Familie bekommt einen persönlichen Link, kein Passwort.</p></div>

<details class="card" <?= $families ? '' : 'open' ?>><summary class="btn small">+ Familie erfassen</summary>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="add_family">
    <label for="fname">Familienname</label><input type="text" id="fname" name="name" required placeholder="z.B. Imhof">
    <label for="fphone">Handy Eltern (für WhatsApp)</label><input type="tel" id="fphone" name="phone" placeholder="079 123 45 67">
    <label for="fkids">Kinder, eines pro Zeile: Vorname Jahrgang</label>
    <textarea id="fkids" name="kids" rows="3" placeholder="Lena 2018&#10;Noah 2015"></textarea>
    <button class="btn">Speichern</button>
  </form>
</details>

<p class="note">Stufen: <span class="piste p-blue"></span> Blau 1–2 · <span class="piste p-red"></span> Rot 3–4 · <span class="piste p-black"></span> Schwarz 5. Nach Stufe werden die Gruppen eingeteilt.</p>

<?php foreach ($families as $f): $link = family_link($f); ?>
<article class="card" id="f<?= (int)$f['id'] ?>">
  <div class="row between"><h2>Familie <?= e($f['name']) ?></h2><span class="note"><?= e($f['phone']) ?></span></div>
  <?php foreach ($kidsBy[$f['id']] ?? [] as $k): ?>
    <form method="post" class="gkid<?= $k['active'] ? '' : ' inactive' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="skill">
      <input type="hidden" name="kid_id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="family_id" value="<?= (int)$f['id'] ?>">
      <span class="piste p-<?= piste((int)$k['skill']) ?>"></span>
      <span class="nm"><b><?= e($k['first_name']) ?></b> <span class="note"><?= (int)$k['birth_year'] ?> · <?= race_category((int)$k['birth_year']) ?><?= $k['active'] ? '' : ' · inaktiv' ?></span>
        <?php if ($k['health'] !== ''): ?><br><span class="health">⚕︎ <?= e($k['health']) ?></span><?php endif ?></span>
      <select name="skill" data-autosubmit aria-label="Stufe <?= e($k['first_name']) ?>">
        <?php foreach (SKILLS as $sv => $sl): ?><option value="<?= $sv ?>" <?= (int)$k['skill'] === $sv ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach ?></select>
      <noscript><button class="btn ghost small">OK</button></noscript>
    </form>
  <?php endforeach ?>

  <div class="row">
    <?php if ($f['phone'] !== ''): ?>
      <a class="btn small" target="_blank" rel="noopener" href="<?= e(wa_link("Hallo Familie {$f['name']}! Hier ist euer persönlicher Link für die Anmeldung zu Training und Rennen beim {$config['site_name']}:\n$link\n\nTipp: Link speichern oder zum Startbildschirm hinzufügen. Bitte nicht weitergeben.", $f['phone'])) ?>">Link per WhatsApp senden</a>
    <?php endif ?>
    <input type="text" id="link-<?= (int)$f['id'] ?>" value="<?= e($link) ?>" readonly class="linkfield" aria-label="Persönlicher Link">
    <button type="button" class="btn ghost small" data-copy="link-<?= (int)$f['id'] ?>">Link kopieren</button>
  </div>

  <details><summary class="note">Bearbeiten, Kind hinzufügen, löschen</summary>
    <div class="stack">
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="edit_family"><input type="hidden" name="family_id" value="<?= (int)$f['id'] ?>">
        <label for="fn-<?= (int)$f['id'] ?>">Familienname</label><input type="text" id="fn-<?= (int)$f['id'] ?>" name="name" value="<?= e($f['name']) ?>">
        <label for="fp-<?= (int)$f['id'] ?>">Handy</label><input type="tel" id="fp-<?= (int)$f['id'] ?>" name="phone" value="<?= e($f['phone']) ?>">
        <button class="btn ghost small">Speichern</button></form>

      <?php foreach ($kidsBy[$f['id']] ?? [] as $k): $kid = (int)$k['id']; ?>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="edit_kid"><input type="hidden" name="kid_id" value="<?= $kid ?>"><input type="hidden" name="family_id" value="<?= (int)$f['id'] ?>">
        <input type="text" name="first_name" value="<?= e($k['first_name']) ?>" aria-label="Vorname" class="grow">
        <input type="number" name="birth_year" value="<?= (int)$k['birth_year'] ?>" aria-label="Jahrgang" class="year">
        <input type="hidden" name="skill" value="<?= (int)$k['skill'] ?>">
        <label class="row tight"><input type="checkbox" name="active" value="1" <?= $k['active'] ? 'checked' : '' ?>> aktiv</label>
        <button class="btn ghost small">Speichern</button></form>
      <?php endforeach ?>

      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="add_kid"><input type="hidden" name="family_id" value="<?= (int)$f['id'] ?>">
        <input type="text" name="first_name" placeholder="Vorname" aria-label="Vorname neues Kind" class="grow" required>
        <input type="number" name="birth_year" placeholder="Jahrgang" aria-label="Jahrgang" class="year" required>
        <select name="skill" aria-label="Stufe"><?php foreach (SKILLS as $sv => $sl): ?><option value="<?= $sv ?>"><?= e($sl) ?></option><?php endforeach ?></select>
        <button class="btn ghost small">+ Kind</button></form>

      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="new_link"><input type="hidden" name="family_id" value="<?= (int)$f['id'] ?>">
        <button class="linkbtn">Neuen Link erstellen (falls der alte weitergegeben wurde)</button></form>

      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="delete_family"><input type="hidden" name="family_id" value="<?= (int)$f['id'] ?>">
        <label class="row tight"><input type="checkbox" name="confirm" value="1"> Familie mit allen Daten löschen</label>
        <button class="btn red small">Löschen</button></form>
    </div>
  </details>
</article>
<?php endforeach ?>
<?php layout_foot();
