<?php
// Vorbereitete WhatsApp-Texte. *fett* ist WhatsApp-Formatierung.

declare(strict_types=1);

function whatsapp_group_text(array $ev, int $no, array $grp, bool $forLeader = false): string
{
    $lines = ["*Gruppe $no* · " . $ev['title'] . ', ' . fmt_date($ev['date'])];
    foreach ($grp['kids'] as $k) {
        $line = '• ' . ($forLeader ? $k['first_name'] . ' ' . $k['family_name'] : initial_name($k));
        if ($forLeader) {
            if ($k['emergency_phone'] !== '') $line .= ' · Notfall ' . $k['emergency_phone'];
            if ($k['health'] !== '') $line .= ' · ⚕︎ ' . $k['health'];
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function whatsapp_templates(array $ev, array $kidsIn, array $groups, array $drivers, array $leaderById, int $silentFamilies): array
{
    $link = base_url() . '/';
    $when = fmt_date($ev['date']) . ($ev['time_text'] !== '' ? ', ' . $ev['time_text'] : '');
    $t = [];

    $t['einladung'] = ['label' => 'Einladung', 'text' =>
        "⛷️ *{$ev['title']}*\n📅 $when\n" .
        ($ev['place'] !== '' ? "📍 {$ev['place']}\n" : '') .
        ($ev['material'] !== '' ? "🎒 {$ev['material']}\n" : '') .
        ($ev['min_skill'] > 1 ? "ℹ️ Für Kinder ab Stufe " . SKILLS[$ev['min_skill']] . "\n" : '') .
        "\nBitte bis *" . fmt_deadline($ev['deadline']) . "* anmelden, mit eurem persönlichen Link:\n$link"];

    $t['erinnerung'] = ['label' => 'Erinnerung', 'text' =>
        "⏰ Erinnerung: Anmeldeschluss für *{$ev['title']}* am " . fmt_date($ev['date']) . " ist *" . fmt_deadline($ev['deadline']) . "*.\n" .
        "Bis jetzt sind " . count($kidsIn) . " Kinder angemeldet" . ($silentFamilies ? ", $silentFamilies Familien haben sich noch nicht gemeldet" : '') . ".\n\n👉 $link"];

    if ($groups) {
        $parts = ["📋 *Gruppen {$ev['title']}, " . fmt_date($ev['date']) . "*" . ($ev['place'] !== '' ? "\n📍 {$ev['place']}" : '')];
        foreach ($groups as $no => $g) {
            $leader = $leaderById[$g['leader_id']]['name'] ?? 'offen';
            $parts[] = "*Gruppe $no* (Leiter: $leader)\n" . implode("\n", array_map(fn($k) => '• ' . initial_name($k), $g['kids']));
        }
        if ($drivers) {
            $parts[] = '🚗 Fahrer: ' . implode(', ', array_map(fn($d) => "Fam. {$d['name']} ({$d['seats']})", $drivers));
        }
        $t['gruppen'] = ['label' => 'Gruppenliste', 'text' => implode("\n\n", $parts)];
    }

    $t['indoor'] = ['label' => 'Indoor statt draussen', 'text' =>
        "🌧️ *Planänderung " . fmt_date($ev['date']) . "*\nWegen des Wetters findet das Training *in der Halle* statt" . ($ev['time_text'] !== '' ? " ({$ev['time_text']})" : '') . ".\nBitte Turnschuhe und Trinkflasche mitnehmen."];

    $t['findet_statt'] = ['label' => 'Findet statt', 'text' =>
        "✅ *{$ev['title']}* am " . fmt_date($ev['date']) . " findet statt!" . ($ev['info'] !== '' ? "\n{$ev['info']}" : '') .
        ($ev['place'] !== '' ? "\n📍 {$ev['place']}" : '') . ($ev['time_text'] !== '' ? ", {$ev['time_text']}" : '')];

    $t['absage'] = ['label' => 'Absage', 'text' =>
        "❌ *Absage {$ev['title']}, " . fmt_date($ev['date']) . "*\n" . ($ev['info'] !== '' && $ev['info'] !== 'Abgesagt.' ? $ev['info'] : 'Leider müssen wir das Training absagen.') . "\nDie nächsten Trainings seht ihr in der App:\n$link"];

    return $t;
}
