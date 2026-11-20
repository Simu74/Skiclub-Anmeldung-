<?php
// Saisonplanung: alles, was meist nur einmal pro Saison gemacht wird.
// Familien erfassen, Vorlagen erstellen, Termine als Serie, Leiterplan als Excel, Gesamtplan drucken.

declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

$me = require_leader('hauptleiter');
$pdo = db();

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'series') {
        $st = $pdo->prepare('SELECT * FROM event_templates WHERE id = ?');
        $st->execute([(int)$_POST['template_id']]);
        $t = $st->fetch();
        $from = strtotime((string)($_POST['from'] ?? ''));
        $to = strtotime((string)($_POST['to'] ?? ''));
        if (!$t || !$from || !$to || $to < $from || $to - $from > 366 * 86400) {
            flash('Bitte Vorlage und einen Zeitraum (höchstens ein Jahr) wählen.');
        } else {
            $cats = categories();
            $exists = $pdo->prepare('SELECT 1 FROM events WHERE date = ? AND category_id IS ?');
            $n = 0;
            for ($d = $from; $d <= $to; $d = strtotime('+1 day', $d)) {
                if ((int)date('w', $d) !== (int)$t['weekday']) continue;
                $date = date('Y-m-d', $d);
                $exists->execute([$date, $t['category_id']]);
                if ($exists->fetchColumn()) continue;
                $ev = event_from_template($t, $date);
                unset($ev['id']);
                $ev['type'] = $cats[$t['category_id']]['kind'] ?? 'off';
                $cols = array_keys($ev);
                $pdo->prepare('INSERT INTO events (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($ev);
                $n++;
            }
            flash($n ? "$n Entwürfe erstellt. Die Leiter können sich jetzt eintragen." : 'Keine neuen Daten, diese Events gibt es schon.');
        }
    }
    redirect('saison.php');
}

$templates = $pdo->query('SELECT id, name FROM event_templates ORDER BY sort, name')->fetchAll();
$nFamilies = (int)$pdo->query('SELECT COUNT(*) FROM families')->fetchColumn();
$nKids = (int)$pdo->query('SELECT COUNT(*) FROM kids WHERE active = 1')->fetchColumn();
$season = season_year();
$label = ($season - 1) . '/' . substr((string)$season, 2);
$st = $pdo->prepare("SELECT COUNT(*) FROM events WHERE status <> 'cancelled' AND date BETWEEN ? AND ?");
$st->execute([($season - 1) . '-07-01', $season . '-06-30']);
$nEvents = (int)$st->fetchColumn();

layout_head('Saisonplanung', admin_nav(), 'saison.php');
?>
<div><p class="eyebrow">Saison <?= e($label) ?> · meist 1× pro Saison</p><h1>Saisonplanung</h1></div>

<div class="card stack">
  <div class="row between"><h3>Familien &amp; Kinder</h3><span class="pill"><?= $nFamilies ?> Familien · <?= $nKids ?> Kinder</span></div>
  <p class="note">Familien erfassen, Kinder einstufen, persönliche Links senden. Auch als Excel.</p>
  <div class="row">
    <a class="btn small" href="families.php">Familien öffnen</a>
    <a class="btn ghost small" href="import.php?art=familien">Import Excel</a>
    <a class="btn ghost small" href="families.php?export=1">Export Excel</a>
  </div>
</div>

<div class="card stack">
  <div class="row between"><h3>Vorlagen &amp; Kategorien</h3><span class="pill"><?= count($templates) ?> Vorlagen</span></div>
  <p class="note">Vorlagen füllen neue Events vor: Zeit, Ort, Material, Anmeldeschluss.</p>
  <div class="row"><a class="btn small" href="templates.php">Vorlagen öffnen</a></div>
</div>

<form method="post" class="card stack" id="serie"><?= csrf_field() ?><input type="hidden" name="action" value="series">
  <div class="row between"><h3>Termine als Serie erstellen</h3><span class="pill"><?= $nEvents ?> Events in der Saison</span></div>
  <p class="note">Erstellt für jede Woche im Zeitraum einen Entwurf aus der Vorlage (am Wochentag der Vorlage). So können sich die Leiter schon zu Saisonbeginn eintragen.
    Eltern sehen die Termine erst, wenn du sie einzeln freischaltest.</p>
  <?php if ($templates): ?>
  <label for="sv">Vorlage</label>
  <select id="sv" name="template_id"><?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach ?></select>
  <div class="row">
    <span class="stack grow"><label for="sf">Von</label><input type="date" id="sf" name="from" required></span>
    <span class="stack grow"><label for="st">Bis</label><input type="date" id="st" name="to" required></span>
  </div>
  <button class="btn small">Serie erstellen</button>
  <?php else: ?>
  <p>Zuerst eine <a href="templates.php">Vorlage erstellen</a>.</p>
  <?php endif ?>
</form>

<div class="card stack">
  <h3>Leiterplanung als Excel</h3>
  <p class="note">Plan exportieren, in Excel bearbeiten und wieder importieren.</p>
  <div class="row"><a class="btn ghost small" href="plan.php?export=1">Export Excel</a><a class="btn ghost small" href="import.php?art=plan">Import Excel</a></div>
  <h3>Gesamtplan drucken</h3>
  <div class="row"><a class="btn ghost small" href="plan.php?druck=alle">Gesamtplan aller Leiter</a></div>
</div>
<?php layout_foot();
