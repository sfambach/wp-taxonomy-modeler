<?php declare(strict_types=1);

/**
 * Platine › Revision mit Positionen; Projekt als eigener Satz mit Link; alte Leiterplatte weg ([D-833](../../docs/NewConcept/90-decision-log.md),
 * [D-834](../../docs/NewConcept/90-decision-log.md), [D-835](../../docs/NewConcept/90-decision-log.md), [D-836](../../docs/NewConcept/90-decision-log.md)).
 *
 *     php scripts/dev/revision-migrate.php            # nur zeigen, was geschähe
 *     php scripts/dev/revision-migrate.php --write    # umbauen
 *
 * ⚠️ *Sein Wort: «1c, ja es gibt projekt mit mehreren unterschiedlichen platinen 3. ja» und «link des projektes ist immer die original
 * github seite nicht meine». Alles über die Dienste des Kerns, damit jede Änderung im Buch steht; nichts wird endgültig gelöscht — Knoten
 * gehen in den Papierkorb, Werte in den Schatten.*
 *
 * ⚠️ **Wiederholbar.** *Der erste Lauf brach nach dem ersten Projektsatz ab (ein bestehender Teil liess sich nicht umhängen). Jeder Schritt
 * sieht darum zuerst nach, ob er schon getan ist: Felder werden gefunden statt doppelt angelegt, Projektsätze am Namen erkannt, Verweise
 * nur gesetzt, wo sie fehlen.*
 *
 * ```mermaid
 * flowchart LR
 *   V["vorher: Stückliste (= Projekt-Satz) → Platinenversion → Platine"] --> N["nachher: Projekt › Platinen › Revisionen › Positionen"]
 * ```
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
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
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
/** @var \Taxmod\Core\Service\SettingsEditor $attributes */
$attributes = (new ReflectionProperty($screen, 'attributes'))->getValue($screen);
/** @var \Taxmod\Core\Repository\SettingsRepository $settings */
$settings  = (new ReflectionProperty($attributes, 'settings'))->getValue($attributes);
$relations = new Taxmod\WordPress\Persistence\WpdbRelationRepository();

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Die Knoten und Kanten von heute (gemessen am 2026-09-15)
const PROJEKTE = 149000104440, PLATINE = 149000105261, VERSION = 149000105262, PARTS_LIST = 27, POSITION_KNOTEN = 3463,
    LEITERPLATTE = 149000104429, TEXT = 1175, ELECTRONIC_PARTS = 3634;
const PROJECT_NAME = 149000104347, BETRIEBSSPANNUNG = 149000104454;
const PLATINE_NAME = 149000105893, PLATINE_BESCHREIBUNG = 149000105895;
const VERSION_PLATINE = 149000105896, VERSION_VERSION = 149000105897, VERSION_BESTUECKUNG = 149000105898;
const BOM_POSITION = 3521, BOM_QUELLE = 149000104346, BOM_VERSION_VERWEIS = 149000105903, PART = 8264;

$schritt = static function (string $was) use ($schreiben): bool {
    echo ($schreiben ? '  tue   ' : '  würde ') . $was . "\n";

    return $schreiben;
};
$erledigt = static function (string $was): void {
    echo '  schon  ' . $was . "\n";
};
$wert = static function (int $satz, int $kante) use ($wpdb, $p): ?array {
    return $wpdb->get_row("SELECT * FROM {$p}relation_records WHERE node_record_id=$satz AND relation_id=$kante ORDER BY position, id LIMIT 1", ARRAY_A) ?: null;
};
$zeiger = static function (int $satz, int $kante) use ($wpdb, $p): array {
    return array_map('intval', $wpdb->get_col("SELECT value_ref FROM {$p}relation_records WHERE node_record_id=$satz AND relation_id=$kante AND value_ref_kind='record' ORDER BY position, id"));
};
// *Ein Feld am Besitzer mit diesem Ziel und Namen — gefunden statt doppelt angelegt.*
$feld = static function (int $besitzer, int $ziel, string $name) use ($relations): int {
    foreach ($relations->fieldRelationsOf([$besitzer]) as $r) {
        if ($r->fromNodeId === $besitzer && $r->toNodeId === $ziel && $r->name === $name) {
            return $r->id;
        }
    }

    return 0;
};
$feldSicher = static function (int $besitzer, int $ziel, string $name, Multiplicity $wieOft, string $was, RelationKind $art = RelationKind::Composition) use ($feld, $schritt, $erledigt, $editor): int {
    $id = $feld($besitzer, $ziel, $name);

    if ($id !== 0) {
        // ⚠️ *Eine Kante auf einen Knoten mit eigenen Sätzen ist ein Verweis, keine Komposition (D-837) — der erste Lauf legte sie falsch an.*
        if ($editor->relationById($id)?->kind !== $art && $schritt("«{$name}» wird «{$art->value}»")) {
            $editor->setKind($besitzer, $id, $art);
        }
        $erledigt($was);

        return $id;
    }

    if (! $schritt($was)) {
        return 0;
    }

    $id = $editor->addField($besitzer, $ziel, $name, $art)->id;
    $editor->setMultiplicity($besitzer, $id, $wieOft);

    return $id;
};

echo "== 1 · Modell ==\n";
if ($editor->find(VERSION)?->name === 'Revision') {
    $erledigt('Platinenversion heisst «Revision»');
} elseif ($schritt('Platinenversion heisst «Revision»')) {
    $editor->rename(VERSION, 'Revision');
}
$kantePositionen = $feldSicher(VERSION, POSITION_KNOTEN, 'Positionen', Multiplicity::ZeroToMany, 'Revision bekommt «Positionen» → Part List Item, Komposition 0..*');
// ⚠️ *Sein Nachsatz: «link des projektes ist immer die original github seite nicht meine» (D-836) — das Feld gehört ans Projekt.*
$kanteLink       = $feldSicher(PROJEKTE, TEXT, 'Link', Multiplicity::ZeroToOne, 'Projekte bekommt «Link» → Text, 0..1 — die originale GitHub-Seite');
// ⚠️ *Platine und Revision sind eigene Knoten mit eigenen Sätzen — die Kanten auf sie sind Satzverweise (Aggregation), nicht Teile (D-837).*
$kanteRevisionen = $feldSicher(PLATINE, VERSION, 'Revisionen', Multiplicity::OneToMany, 'Platine bekommt «Revisionen» → Revision, Verweis 1..*', RelationKind::Aggregation);
$kantePlatinen   = $feldSicher(PROJEKTE, PLATINE, 'Platinen', Multiplicity::ZeroToMany, 'Projekte bekommt «Platinen» → Platine, Verweis 0..*', RelationKind::Aggregation);

echo "\n== 2 · Einstellungen ==\n";
$knoten = static fn (int $id) => $editor->find($id);
$kante  = static fn (int $id) => $editor->relationById($id);

if ($kantePositionen !== 0 && $schritt('«Add several» an den Positionen der Revision: pick_field = Part')) {
    $attributes->setMembers($knoten(POSITION_KNOTEN), 'pick_field', [PART], $kante($kantePositionen));
}
if ($schritt('Vorbelegung am Part: Bestückung der Revision statt Stückliste › Platinenversion › Bestückung')) {
    $attributes->setMembers($knoten(ELECTRONIC_PARTS), 'preset_source', [VERSION_BESTUECKUNG], $kante(PART));
    foreach ($wpdb->get_col("SELECT id FROM {$p}settings_value WHERE attribut='preset_source' AND wert_kante_id=" . BOM_VERSION_VERWEIS) as $alt) {
        $settings->forgetValue((int) $alt);
    }
}
if ($schritt('Zusammenfassung der Revision: nur noch Version (das Feld Platine fällt)')) {
    $attributes->setMembers($knoten(VERSION), 'summary_fields', [VERSION_VERSION]);
    foreach ($wpdb->get_col("SELECT id FROM {$p}settings_value WHERE wert_kante_id=" . VERSION_PLATINE) as $alt) {
        $settings->forgetValue((int) $alt);
    }
}

echo "\n== 3 · Daten ==\n";
$bomSaetze     = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}node_records WHERE node_id=" . PARTS_LIST . " AND record_type='user' ORDER BY id"));
$leiterplatten = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}node_records WHERE node_id=" . LEITERPLATTE));
$projektNamens = static function (string $name) use ($wpdb, $p): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT r.id FROM {$p}node_records r JOIN {$p}relation_records v ON v.node_record_id=r.id WHERE r.node_id=%d AND r.record_type='user' AND v.relation_id=%d AND v.value_text=%s ORDER BY r.id LIMIT 1",
        PROJEKTE,
        PROJECT_NAME,
        $name
    ));
};

foreach ($bomSaetze as $bom) {
    $name           = $wert($bom, PROJECT_NAME)['value_text'] ?? null;
    $positionen     = $zeiger($bom, BOM_POSITION);
    $versionVerweis = (int) ($wert($bom, BOM_VERSION_VERWEIS)['value_ref'] ?? 0);

    if ($name === null && $positionen === [] && $versionVerweis === 0) {
        echo "  Stückliste #$bom ist leer oder schon umgezogen — bleibt mit dem Knoten im Papierkorb\n";
        continue;
    }

    echo "  Stückliste #$bom «{$name}»:\n";

    $projekt = $name === null ? 0 : $projektNamens((string) $name);
    if ($projekt !== 0) {
        $erledigt("Projekt-Satz «{$name}» ist #$projekt");
    } elseif ($schritt("Projekt-Satz «{$name}» anlegen")) {
        $projekt = $data->create(PROJEKTE, RecordType::User)->id;
        $data->put($projekt, PROJECT_NAME, TypedValue::ofText((string) $name));
    }

    if ($schritt('Betriebsspannung an das Projekt')) {
        foreach ($zeiger($bom, BETRIEBSSPANNUNG) as $teil) {
            $data->attachPart($projekt, BETRIEBSSPANNUNG, $teil);
        }
    }

    $platine  = $versionVerweis === 0 ? 0 : (int) ($wert($versionVerweis, VERSION_PLATINE)['value_ref'] ?? 0);
    $revision = $versionVerweis;

    if ($platine === 0 && $schritt("keine Platine: Platine «{$name}» und Revision «1» anlegen (Art bleibt leer)")) {
        $platine  = $data->create(PLATINE, RecordType::User)->id;
        $data->put($platine, PLATINE_NAME, TypedValue::ofText((string) $name));
        $revision = $data->create(VERSION, RecordType::User)->id;
        $data->put($revision, VERSION_VERSION, TypedValue::ofText('1'));
    }

    if ($schritt("Projekt hält Platine #$platine, Platine hält Revision #$revision")) {
        foreach ([[$projekt, $kantePlatinen, $platine], [$platine, $kanteRevisionen, $revision]] as [$halter, $verweisKante, $ziel]) {
            if (! in_array($ziel, $zeiger($halter, $verweisKante), true)) {
                $data->appendValue($halter, $verweisKante, TypedValue::ofRecordReference($ziel));
            }
        }
    }

    // *Nur eine GitHub-Adresse ist der Link des Projekts; eine andere bleibt im Satz der Stückliste (Papierkorb) und wird genannt (D-836).*
    $quelle = $wert($bom, BOM_QUELLE)['value_text'] ?? null;
    if ($quelle !== null && str_contains((string) $quelle, 'github.com') && $schritt("Link «{$quelle}» ans Projekt")) {
        $data->put($projekt, $kanteLink, TypedValue::ofText((string) $quelle));
    } elseif ($quelle !== null) {
        echo "  nicht übernommen: Quelle «{$quelle}» ist keine GitHub-Seite — bleibt im Satz der Stückliste\n";
    }

    foreach ($positionen as $position) {
        $teil = (int) ($wert($position, PART)['value_ref'] ?? 0);

        if (in_array($teil, $leiterplatten, true)) {
            $alt = $wpdb->get_var("SELECT value_text FROM {$p}relation_records WHERE node_record_id=$teil AND value_text IS NOT NULL AND value_text NOT LIKE '%PCB%' AND value_text <> 'Platine' LIMIT 1");
            if ($schritt("Position #$position zeigt auf die alte Leiterplatte #$teil — fällt" . ($alt ? "; ihre Beschreibung «{$alt}» an die Platine" : ''))) {
                if ($alt && $wert($platine, PLATINE_BESCHREIBUNG) === null) {
                    $data->put($platine, PLATINE_BESCHREIBUNG, TypedValue::ofText((string) $alt));
                }
                $data->removeRecord($position);
            }
            continue;
        }

        if ($schritt("Position #$position an die Revision #$revision")) {
            $data->attachPart($revision, $kantePositionen, $position);
        }
    }

    // *Erst nachdem alles am neuen Halter hängt, verliert die Stückliste ihre Verweise — sonst hätte ein Abbruch Teile ohne Halter hinterlassen.*
    if ($schreiben) {
        $data->clear($bom, BOM_POSITION);
        $data->clear($bom, BETRIEBSSPANNUNG);
        $data->clear($bom, BOM_VERSION_VERWEIS);
        $data->clear($bom, PROJECT_NAME);
    }
}

echo "\n== 4 · Aufräumen ==\n";
if ($feld(VERSION, PLATINE, 'Platine') === 0) {
    $erledigt('das Feld «Platine» an der Revision ist weg');
} elseif ($schritt('das Feld «Platine» an der Revision (Verweis nach oben) entfernen — geparkt')) {
    $editor->removeField(VERSION, VERSION_PLATINE);
}
if ($schritt('Katalogknoten «Leiterplatte» in den Papierkorb')) {
    $editor->moveToTrash(LEITERPLATTE);
}
if ($schritt('Knoten «Parts List» in den Papierkorb')) {
    $editor->moveToTrash(PARTS_LIST);
}

if (! $schreiben) {
    echo "\nNichts geschrieben (ohne --write).\n";
    exit(0);
}

echo "\n== Ergebnis ==\n";
foreach (array_map('intval', $wpdb->get_col("SELECT id FROM {$p}node_records WHERE node_id=" . PROJEKTE . " AND record_type='user' ORDER BY id")) as $projekt) {
    echo "Projekt #$projekt «", $wert($projekt, PROJECT_NAME)['value_text'] ?? '?', "» · Betriebsspannung-Teile ", count($zeiger($projekt, BETRIEBSSPANNUNG)), "\n";
    foreach ($zeiger($projekt, $kantePlatinen) as $platine) {
        echo "  Platine #$platine «", $wert($platine, PLATINE_NAME)['value_text'] ?? '?', "» · Beschreibung «", $wert($platine, PLATINE_BESCHREIBUNG)['value_text'] ?? '', "»\n";
        foreach ($zeiger($platine, $kanteRevisionen) as $revision) {
            echo "    Revision #$revision Version ", $wert($revision, VERSION_VERSION)['value_text'] ?? '?', " · Positionen ", count($zeiger($revision, $kantePositionen)), "\n";
        }
    }
}
echo "Kanten: Platinen $kantePlatinen · Revisionen $kanteRevisionen · Positionen $kantePositionen · Link $kanteLink\n";
