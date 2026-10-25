<?php
// Gruppeneinteilung: nach Können sortiert, in gleich grosse Gruppen geteilt.

declare(strict_types=1);

/**
 * Teilt Kinder in $n Gruppen. Die Grössen unterscheiden sich höchstens um 1.
 * Gruppe 1 bekommt die stärksten Kinder.
 *
 * @param array<array{id:int,skill:int,birth_year:int}> $kids
 * @return array<int, int> kid_id => group_no (1-basiert)
 */
function balance_groups(array $kids, int $n): array
{
    $n = max(1, $n);
    usort($kids, fn($a, $b) => [$b['skill'], $a['birth_year'], $a['id']] <=> [$a['skill'], $b['birth_year'], $b['id']]);
    $base = intdiv(count($kids), $n);
    $extra = count($kids) % $n;
    $out = [];
    $i = 0;
    for ($g = 1; $g <= $n; $g++) {
        $size = $base + ($g <= $extra ? 1 : 0);
        for ($k = 0; $k < $size; $k++, $i++) {
            $out[(int)$kids[$i]['id']] = $g;
        }
    }
    return $out;
}

/** Vorschlag für die Anzahl Gruppen: etwa 6–8 Kinder pro Gruppe. */
function suggested_group_count(int $kidCount): int
{
    return max(1, (int)round($kidCount / 7));
}

function registered_kids(int $eventId): array
{
    $st = db()->prepare(
        "SELECT k.*, f.name AS family_name, r.attended FROM registrations r
         JOIN kids k ON k.id = r.kid_id JOIN families f ON f.id = k.family_id
         WHERE r.event_id = ? AND r.status = 'in' ORDER BY k.skill DESC, k.birth_year"
    );
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function regroup_event(int $eventId, int $n): void
{
    $pdo = db();
    $kids = registered_kids($eventId);
    $map = balance_groups($kids, $n);
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM assignments WHERE event_id = ?')->execute([$eventId]);
    $ins = $pdo->prepare('INSERT INTO assignments (event_id, kid_id, group_no) VALUES (?, ?, ?)');
    foreach ($map as $kidId => $g) {
        $ins->execute([$eventId, $kidId, $g]);
    }
    $pdo->prepare('DELETE FROM group_leaders WHERE event_id = ? AND group_no > ?')->execute([$eventId, $n]);
    $pdo->prepare('UPDATE events SET group_count = ? WHERE id = ?')->execute([$n, $eventId]);
    $pdo->commit();
}

/**
 * Liefert die Gruppen eines Events. Neu angemeldete Kinder ohne Gruppe kommen
 * in die kleinste Gruppe mit ähnlichem Können, abgemeldete fallen weg.
 *
 * @return array<int, array{leader_id:?int, kids:array}>
 */
function event_groups(array $event): array
{
    $n = (int)$event['group_count'];
    $kids = registered_kids((int)$event['id']);
    if ($n === 0) {
        return [];
    }
    $st = db()->prepare('SELECT kid_id, group_no FROM assignments WHERE event_id = ?');
    $st->execute([$event['id']]);
    $assigned = array_column($st->fetchAll(), 'group_no', 'kid_id');

    $groups = [];
    for ($g = 1; $g <= $n; $g++) {
        $groups[$g] = ['leader_id' => null, 'kids' => []];
    }
    $st = db()->prepare('SELECT group_no, leader_id FROM group_leaders WHERE event_id = ?');
    $st->execute([$event['id']]);
    foreach ($st->fetchAll() as $row) {
        if (isset($groups[$row['group_no']])) {
            $groups[$row['group_no']]['leader_id'] = $row['leader_id'];
        }
    }

    $unassigned = [];
    foreach ($kids as $k) {
        $g = $assigned[$k['id']] ?? null;
        if ($g && isset($groups[$g])) {
            $groups[$g]['kids'][] = $k;
        } else {
            $unassigned[] = $k;
        }
    }
    foreach ($unassigned as $k) {
        $best = 1;
        $bestScore = PHP_INT_MAX;
        foreach ($groups as $g => $grp) {
            $avg = $grp['kids'] ? array_sum(array_column($grp['kids'], 'skill')) / count($grp['kids']) : $k['skill'];
            $score = count($grp['kids']) * 10 + (int)round(abs($avg - $k['skill']) * 10);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $g;
            }
        }
        $groups[$best]['kids'][] = $k;
        db()->prepare('INSERT OR REPLACE INTO assignments (event_id, kid_id, group_no) VALUES (?, ?, ?)')
            ->execute([$event['id'], $k['id'], $best]);
    }
    return $groups;
}

function group_avg_skill(array $group): float
{
    return $group['kids'] ? array_sum(array_column($group['kids'], 'skill')) / count($group['kids']) : 0.0;
}
