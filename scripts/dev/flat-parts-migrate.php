<?php declare(strict_types=1);
/**
 * Einmalig: flach gespeicherte innere Werte in eigene Teil-Sätze umziehen (D-759).
 *
 *     php scripts/dev/flat-parts-migrate.php [path/to/wordpress] [--dry]
 *
 * ⚠️ **Der Anlass:** *D-741/D-742 schrieben die Werte eines zusammengesetzten Feldes an der innersten Kante in den Satz des
 * Besitzers. [D-577](../../docs/NewConcept/90-decision-log.md) legt sie in einen eigenen Teil, auf den der Besitzer zeigt.
 * Sein Wort 2026-09-13: «ja, zieh die flachen Werte um».*
 *
 * ⚠️ **Über `DataEntry` und nicht über ein `UPDATE`:** *jeder Schritt steht in Schatten und Buch, ein Akt je Satz — also
 * umkehrbar ([D-601](../../docs/NewConcept/90-decision-log.md)).* Ein Wert, dessen Weg nicht eindeutig ist oder dessen Teil
 * an der Stelle schon einen Wert trägt, wird **nicht** bewegt, sondern genannt (`PR-4`).
 */

$argumente = array_slice($argv, 1);
$trocken   = in_array('--dry', $argumente, true);
$root      = null;

foreach ($argumente as $argument) {
    if (! str_starts_with($argument, '--')) {
        $root = $argument;

        break;
    }
}

$root ??= getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Service\DataEntry;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$p         = $wpdb->prefix . 'taxmod_';
$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock(), $log);

wp_set_current_user(1);

/** @return list<Relation> Die Felder eines Knotens mit Vererbung. */
$felderVon = static fn (Node $knoten): array => $relations->fieldRelationsOf($framework->inheritanceOwnersOf($knoten));

/**
 * Der Weg über Kompositionen vom Satzknoten zu dem Knoten, der das innere Feld erklärt — `null`, wenn keiner oder mehrere.
 *
 * @return list<Relation>|null
 */
$wegZu = static function (Node $start, Relation $innen, int $tiefe = 0) use (&$wegZu, $felderVon, $nodes, $framework): ?array {
    if ($tiefe > 3) {
        return null;
    }

    $gefunden = [];

    foreach ($felderVon($start) as $feld) {
        if ($feld->kind !== RelationKind::Composition || $feld->isSetting()) {
            continue;
        }

        $ziel = $nodes->find($feld->toNodeId);

        if ($ziel === null) {
            continue;
        }

        if (in_array($innen->fromNodeId, $framework->inheritanceOwnersOf($ziel), true)) {
            $gefunden[] = [$feld];

            continue;
        }

        $tiefer = $wegZu($ziel, $innen, $tiefe + 1);

        if ($tiefer !== null) {
            $gefunden[] = [$feld, ...$tiefer];
        }
    }

    return count($gefunden) === 1 ? $gefunden[0] : null;
};

/** Der erste Teil an dieser Kante, oder ein neuer. */
$teilAn = static function (int $halter, Relation $kante) use ($data, $trocken): int {
    foreach ($data->valuesOf($halter) as $zeile) {
        if ($zeile->relationId === $kante->id && $zeile->value->reference !== null && $zeile->value->referenceSpace === ReferenceSpace::Record) {
            return $zeile->value->reference;
        }
    }

    return $trocken ? -1 : $data->createPart($halter, $kante->id)->id;
};

$bewegt   = 0;
$genannt  = [];
$satzIds  = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}node_records WHERE record_type <> 'settings'") ?: []);

foreach ($satzIds as $satzId) {
    $satz   = $rows->find($satzId);
    $knoten = $satz === null ? null : $nodes->find($satz->nodeId);

    if ($knoten === null) {
        continue;
    }

    $eigene = [];

    foreach ($felderVon($knoten) as $feld) {
        $eigene[$feld->id] = true;
    }

    // ⚠️ *Kante `0` ist der eigene Wert des Knotens (D-673), kein inneres Feld.*
    $flach = array_values(array_filter($data->valuesOf($satzId), static fn ($zeile): bool => $zeile->relationId !== 0 && ! isset($eigene[$zeile->relationId])));

    if ($flach === []) {
        continue;
    }

    $log->beginAct();

    try {
        foreach ($flach as $zeile) {
            $innen = $relations->byId($zeile->relationId);
            $weg   = $innen === null ? null : $wegZu($knoten, $innen);

            if ($weg === null) {
                $genannt[] = "Satz {$satzId}: Wert an Kante {$zeile->relationId} — kein eindeutiger Weg, bleibt stehen";

                continue;
            }

            $halter = $satzId;

            foreach ($weg as $kante) {
                $halter = $halter === -1 ? -1 : $teilAn($halter, $kante);
            }

            $namen = implode(' → ', array_map(static fn (Relation $k): string => $k->name, $weg)) . ' · ' . $innen->name;

            if ($halter !== -1) {
                foreach ($data->valuesOf($halter) as $schon) {
                    if ($schon->relationId === $innen->id) {
                        $genannt[] = "Satz {$satzId}: {$namen} — der Teil {$halter} trägt schon einen Wert, bleibt stehen";

                        continue 2;
                    }
                }
            }

            echo ($trocken ? '  [trocken] ' : '  ') . "Satz {$satzId}: {$namen} → Teil " . ($halter === -1 ? 'neu' : $halter) . "\n";

            if (! $trocken) {
                $data->put($halter, $innen->id, $zeile->value);
                $data->clear($satzId, $innen->id, $zeile->locale);
            }

            $bewegt++;
        }
    } finally {
        $log->endAct();
    }
}

printf("\n%d %s, %d genannt\n", $bewegt, $trocken ? 'wären umzuziehen' : 'umgezogen', count($genannt));

foreach ($genannt as $was) {
    echo "  {$was}\n";
}
