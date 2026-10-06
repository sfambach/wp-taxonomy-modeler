<?php declare(strict_types=1);

/**
 * Mainboards in drei Stufen: Reihe → Modell → Revision ([D-853](../../docs/NewConcept/90-decision-log.md)).
 *
 *     php scripts/dev/mainboard-reihen.php            # nur zeigen
 *     php scripts/dev/mainboard-reihen.php --write    # umbauen
 *
 * ⚠️ *Sein Wort: «evtl sollten wir oktek hersteller, board jaguar als oberbegriff also eigene tabelle mit verschiedenen versionen zum
 * beispiel IV-Q 386 nehmen», und zu seinem Exemplar: «auf meinem board ist fest ein dx33 verlötet». The Retro Web führt eine Seite je
 * Modell; Revisionen stehen dort nur in Bildunterschriften.*
 *
 * *Die zwei vorhandenen Sätze bleiben, wo sie sind — sie sind die **Modelle**. Darüber kommt die Reihe, darunter die Revision. Die acht
 * Jumperzeilen wandern vom Modell an seine Revision 1.2; am Modell steht, was das Board kann, an der Revision, was sein Exemplar ist.*
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
/** @var \Taxmod\Core\Service\DataEntry $data */
$data    = (new ReflectionProperty($screen, 'data'))->getValue($screen);
$records = new WpdbRecordRepository();

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

const INTERNAL = 32483, MAINBOARDS = 149000107691, JUMPERTEIL = 149000107690, CPUS = 149000103845;
const TEXT = 1175, BOOLEAN = 1179, MEDIA = 149000105224, BEZEICHNUNG = 149000103839, HERSTELLER = 149000102544;
const CPU_386DX33 = 22915;

$sagen = static function (string $s): void { echo $s, "\n"; };

$knoten = static function (string $name, int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Knoten «{$name}»");

    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};

$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft, bool $eindeutig = false) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    if ($eigner === null || $ziel === null) {
        return null;
    }

    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
        $eigner,
        $name
    ));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Feld «{$name}» an {$eigner} → {$ziel} ({$wieOft->value}, {$art->value}" . ($eindeutig ? ', eindeutig' : '') . ')');

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, $art);
    $kante = $kante->multiplicity === $wieOft ? $kante : $editor->setMultiplicity($eigner, $kante->id, $wieOft);

    if ($eindeutig) {
        $editor->setUnique($eigner, $kante->id, true);
    }

    return $kante->id;
};

$feldAn = static fn (int $knotenId, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    $knotenId,
    $name
))) === null ? null : (int) $id;

$satzMit = static fn (int $knotenId, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s",
    $knotenId,
    BEZEICHNUNG,
    $name
))) === null ? null : (int) $id;

$neuerSatz = static function (?int $knotenId, string $name) use ($data, $satzMit, $schreiben, $sagen): ?int {
    if ($knotenId === null) {
        return null;
    }

    $steht = $satzMit($knotenId, $name);

    if ($steht !== null) {
        return $steht;
    }

    $sagen("Satz «{$name}» am Knoten {$knotenId}");

    if (! $schreiben) {
        return null;
    }

    $neu = $data->create($knotenId, RecordType::User);
    $data->put($neu->id, BEZEICHNUNG, TypedValue::ofText($name));

    return $neu->id;
};

// ── Die zwei neuen Stufen ──────────────────────────────────────────────────────────────────────
$revisionen = $knoten('Mainboard-Revisionen', INTERNAL, Category::class);
$revCpu     = $feld($revisionen, CPUS, 'Verbaute CPU', RelationKind::Aggregation, Multiplicity::ZeroToOne);
// *Ein Schalter ist immer 1..1 (Modell 1.2.3) — «nichts» gibt es bei ja/nein nicht.*
$revLoet    = $feld($revisionen, BOOLEAN, 'CPU fest verlötet', RelationKind::Composition, Multiplicity::ExactlyOne);
$revJumper  = $feld($revisionen, JUMPERTEIL, 'Jumper', RelationKind::Composition, Multiplicity::ZeroToMany);
$revBios    = $feld($revisionen, TEXT, 'BIOS', RelationKind::Composition, Multiplicity::ZeroToOne);
$revLinks   = $feld($revisionen, MEDIA, 'Links', RelationKind::Composition, Multiplicity::ZeroToMany);
$revHinweis = $feld($revisionen, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne);

$reihen  = $knoten('Mainboard-Reihen', INTERNAL, Category::class);
$modelle = $feld($reihen, MAINBOARDS, 'Modelle', RelationKind::Aggregation, Multiplicity::ZeroToMany, true);

// ⚠️ *Eindeutig: eine Revision gehört genau einem Modell (wie Platine → Revision, D-838).*
$mbRevisionen = $feld(MAINBOARDS, $revisionen, 'Revisionen', RelationKind::Aggregation, Multiplicity::ZeroToMany, true);

// ── Die Reihe «Jaguar» über den zwei Modellen ──────────────────────────────────────────────────
$jaguar = $neuerSatz($reihen, 'Jaguar');
$octek  = $wpdb->get_var($wpdb->prepare("SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id WHERE r.node_id = 149000102677 AND v.relation_id = 149000102666 AND v.value_text = %s", 'Octek'));

if ($jaguar !== null && $octek !== null && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $jaguar, HERSTELLER)) === 0) {
    $sagen('  Hersteller = Octek');

    if ($schreiben) {
        $data->put($jaguar, HERSTELLER, TypedValue::ofRecordReference((int) $octek));
    }
}

$vier = $satzMit(MAINBOARDS, 'Jaguar IV 386');
$q    = $satzMit(MAINBOARDS, 'Jaguar IV-Q 386DX');

foreach ([$vier, $q] as $modell) {
    if ($jaguar === null || $modelle === null || $modell === null) {
        continue;
    }

    $schon = array_map(intval(...), $wpdb->get_col($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $jaguar, $modelle)));

    if (! in_array($modell, $schon, true)) {
        $sagen("  Modell {$modell} an die Reihe");

        if ($schreiben) {
            $data->appendValue($jaguar, $modelle, TypedValue::ofRecordReference($modell));
        }
    }
}

// ── Seine Revision 1.2, mit seinem Exemplar ────────────────────────────────────────────────────
$revision = $neuerSatz($revisionen, '1.2');

if ($revision !== null) {
    foreach ([
        [$revCpu, TypedValue::ofRecordReference(CPU_386DX33), 'Verbaute CPU = 80386DX-33'],
        [$revLoet, TypedValue::ofBool(true), 'CPU fest verlötet = ja'],
        [$revHinweis, TypedValue::ofText('Sein Exemplar: ICs HT321 B und HT322 B, 386DX-33 fest verlötet. Zu dieser Revision gibt es keine Unterlage; die Jumper stammen von der Jaguar IV 386 (Stason) und von der 486DLC (MicroHouse) und sind ungeprüft.'), 'Hinweis'],
    ] as [$feldId, $inhalt, $wofuer]) {
        if ($feldId !== null && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $revision, $feldId)) === 0) {
            $sagen("  {$wofuer}");

            if ($schreiben) {
                $data->put($revision, $feldId, $inhalt);
            }
        }
    }
}

// Die Revisionen aus den Bildunterschriften bei The Retro Web.
foreach (['1.3' => 'EP40041R13, bei The Retro Web nur als Bildunterschrift', '1.4' => 'EP40041R14, bei The Retro Web nur als Bildunterschrift'] as $name => $woher) {
    $weitere = $neuerSatz($revisionen, $name);

    if ($weitere !== null && $revHinweis !== null && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $weitere, $revHinweis)) === 0) {
        $sagen("  Hinweis zu {$name}");

        if ($schreiben) {
            $data->put($weitere, $revHinweis, TypedValue::ofText($woher));
        }
    }

    if ($weitere !== null && $q !== null && $mbRevisionen !== null) {
        $schon = array_map(intval(...), $wpdb->get_col($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $q, $mbRevisionen)));

        if (! in_array($weitere, $schon, true)) {
            $sagen("  Revision {$name} an das Modell IV-Q");

            if ($schreiben) {
                $data->appendValue($q, $mbRevisionen, TypedValue::ofRecordReference($weitere));
            }
        }
    }
}

if ($revision !== null && $q !== null && $mbRevisionen !== null) {
    $schon = array_map(intval(...), $wpdb->get_col($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $q, $mbRevisionen)));

    if (! in_array($revision, $schon, true)) {
        $sagen('  Revision 1.2 an das Modell IV-Q');

        if ($schreiben) {
            $data->appendValue($q, $mbRevisionen, TypedValue::ofRecordReference($revision));
        }
    }
}

// ── Die Jumper wandern vom Modell an die Revision ──────────────────────────────────────────────
$mbJumper = $feldAn(MAINBOARDS, 'Jumper');
$juName   = $feldAn(JUMPERTEIL, 'Bezeichnung');
$juFunk   = $feldAn(JUMPERTEIL, 'Funktion');
$juStell  = $feldAn(JUMPERTEIL, 'Stellung');
$juQuelle = $feldAn(JUMPERTEIL, 'Quelle');

if ($revision !== null && $revJumper !== null && $mbJumper !== null && $q !== null) {
    $dort = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $revision, $revJumper));

    foreach ($dort > 0 ? [] : $wpdb->get_col($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d ORDER BY position, id", $q, $mbJumper)) as $alt) {
        $wortVon = static fn (?int $rel): string => $rel === null ? '' : (string) $wpdb->get_var($wpdb->prepare("SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", (int) $alt, $rel));
        $sagen('  Jumper an die Revision: ' . $wortVon($juName) . ' ' . $wortVon($juFunk));

        if ($schreiben) {
            $neu = $data->createPart($revision, $revJumper);

            foreach ([$juName, $juFunk, $juStell, $juQuelle] as $rel) {
                if ($rel !== null) {
                    $data->put($neu->id, $rel, TypedValue::ofText($wortVon($rel)));
                }
            }
        }
    }
}

// Und am Modell fallen sie weg — eine Tatsache steht an einer Stelle.
foreach ([$vier, $q] as $modell) {
    if ($modell === null || $mbJumper === null) {
        continue;
    }

    foreach ($wpdb->get_results($wpdb->prepare("SELECT id, value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $modell, $mbJumper)) as $zeile) {
        $sagen("  Jumperzeile {$zeile->value_ref} am Modell {$modell} entfernen");

        if ($schreiben) {
            $records->forgetValueById((int) $zeile->id);
            $records->forgetRecord((int) $zeile->value_ref);
        }
    }
}

// Die Revision am Modell war die falsche Stelle — sie steht jetzt als eigener Satz.
$mbRevisionText = $feldAn(MAINBOARDS, 'Revision');

foreach ([$vier, $q] as $modell) {
    if ($modell === null || $mbRevisionText === null) {
        continue;
    }

    foreach ($wpdb->get_results($wpdb->prepare("SELECT id, value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", $modell, $mbRevisionText)) as $zeile) {
        $sagen("  Revisionstext «{$zeile->value_text}» am Modell {$modell} entfernen");

        if ($schreiben) {
            $records->forgetValueById((int) $zeile->id);
        }
    }
}

echo "\n", $schreiben ? "geschrieben\n" : "trocken — nichts geschrieben\n";
