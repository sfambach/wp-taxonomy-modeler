<?php declare(strict_types=1);

/**
 * Das Modell nach der Probe mit neuen Daten: Felder für Karten, genauere Anschlüsse, Käufe getrennt von Exemplaren (2026-09-21).
 *
 *     php scripts/dev/modell-karten-kaeufe.php            # nur zeigen
 *     php scripts/dev/modell-karten-kaeufe.php --write    # umbauen
 *
 * ⚠️ *Sein Wort ([D-897](../../docs/NewConcept/90-decision-log.md)): «vielleicht challengen wir vorher das, was wir schon haben,
 * mit neuen Daten», und auf die Befunde: «ja setz das so um».*
 *
 * 1. «Anschlüsse» und «Onboard» wandern von «Mainboards» an «Internal» — Karten haben beides. «Alternative Bezeichnungen» bekommt
 *    auch «Internal» (derselbe Teil wie bei den Bauteilen, D-873).
 * 2. An «Enhancement Cards»: Bus (Erweiterungsbusse, mehrere), Speicher, Speicher höchstens, Konfiguration, Boot-ROM, Kompatibel mit.
 * 3. Die Stecker am Netz und am Monitor: 10BASE2 (BNC), 10BASE5 (AUI), 10BASE-T, 100BASE-TX, 1000BASE-T; MDA/Hercules, CGA, EGA.
 * 4. Am Exemplar: Datecode — das Alter der Chips, nicht der Karte.
 * 5. Ein eigenes Ding «Käufe»: eine Zeile seines Wareneingangs — Anzahl, Preis je Stück, Gesamtpreis, Währung, gekauft am,
 *    eingegangen am, Plattform, Händler, und optional das Modell und die Exemplare, die daraus wurden.
 *
 * Wiederholbar: alles wird am Namen erkannt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Choice;
use Taxmod\Core\Model\NodeClass\Constant;
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

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-21 — Bezeichnungen seit D-883 an «Model» (149000103839)
const MODEL = 402, MODELS = 149000103431, INTERNAL = 32483, MAINBOARDS = 149000107691, KARTEN = 32485, EXEMPLARE = 149000108212;
const TEXT = 1175, INTEGER = 1171, DECIMAL = 1173, DATUM = 1183, EINHEITENWERT = 4232, WAEHRUNG = 8569, KONSTANTEN = 410;
const ANSCHLUESSE = 149000108718, ONBOARD = 149000108719, ALT_BEZ = 149000108637, BUSSE = 149000108006;
const NETZWERK = 149000108010, BILDSCHIRM = 149000108720, SCHNITTSTELLE_BEZ = 149000103839, SCHNITTSTELLE_HINWEIS = 149000107988;
const BEZEICHNUNG = 149000103839;

$sagen = static fn (string $satz) => print($satz . "\n");

$knoten = static function (string $name, int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Knoten «{$name}»");

    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};

$feldAn = static fn (int $eigner, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s AND r.hide = 0",
    $eigner,
    $name
))) === null ? null : (int) $id;

$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $feldAn, $schreiben, $sagen): ?int {
    if ($eigner === null || $ziel === null) {
        return null;
    }

    if (($steht = $feldAn($eigner, $name)) !== null) {
        return $steht;
    }

    $sagen("  Feld «{$name}» an {$eigner} ({$wieOft->value})");

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, $art);

    return $kante->multiplicity === $wieOft ? $kante->id : $editor->setMultiplicity($eigner, $kante->id, $wieOft)->id;
};

// ── 1. Anschlüsse und Onboard an «Internal» ────────────────────────────────────────────────────────
foreach ([ANSCHLUESSE => 'Anschlüsse', ONBOARD => 'Onboard'] as $kante => $name) {
    $besitzer = (int) $wpdb->get_var($wpdb->prepare("SELECT from_node_id FROM {$p}relations WHERE id = %d", $kante));

    if ($besitzer === MAINBOARDS) {
        $sagen("«{$name}» wandert von Mainboards an Internal");

        if ($schreiben) {
            $editor->moveFieldToParent(MAINBOARDS, $kante);
        }
    }
}

// ⚠️ *Und gleich wieder hinunter, aber nur an die zwei, die sie brauchen: Mainboards und Karten (D-897). Gemessen am 2026-09-21: an
// «Internal» erbten auch die CPUs die Anschlüsse, und ihre Seite trug eine Schnittstellen-Auswahl mehr — 63 KB, die Seitenlast wurde
// rot (1053 KB). CPUs haben keine Anschlüsse. Dieselben Teile an zwei Stellen, wie «Alternative Bezeichnungen» (D-873).*
foreach ([ANSCHLUESSE => 'Anschlüsse', ONBOARD => 'Onboard'] as $kante => $name) {
    $besitzer = (int) $wpdb->get_var($wpdb->prepare("SELECT from_node_id FROM {$p}relations WHERE id = %d AND hide = 0", $kante));

    if ($besitzer === INTERNAL) {
        $sagen("«{$name}» von Internal an Mainboards und Karten");

        if ($schreiben) {
            $editor->pushFieldToChildren(INTERNAL, $kante, [MAINBOARDS, KARTEN]);
        }
    }
}

$feld(INTERNAL, ALT_BEZ, 'Alternative Bezeichnungen', RelationKind::Composition, Multiplicity::ZeroToMany);

// ── 2. Felder der Karten ───────────────────────────────────────────────────────────────────────────
$konfigWahl = $knoten('Konfigurationsarten', KONSTANTEN, Choice::class);

foreach (['Jumper', 'DIP-Schalter', 'Software', 'Plug and Play'] as $art) {
    if ($konfigWahl !== null) {
        $knoten($art, $konfigWahl, Constant::class);
    }
}

$feld(KARTEN, BUSSE, 'Bus', RelationKind::Aggregation, Multiplicity::ZeroToMany);
$feld(KARTEN, EINHEITENWERT, 'Speicher', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld(KARTEN, EINHEITENWERT, 'Speicher höchstens', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld(KARTEN, $konfigWahl, 'Konfiguration', RelationKind::Aggregation, Multiplicity::ZeroToMany);
$feld(KARTEN, TEXT, 'Boot-ROM', RelationKind::Composition, Multiplicity::ZeroToOne);
// ⚠️ *Kompatibilität als Beziehung (D-886) ist beschlossen, ihre Form nicht (INF-062). Bis dahin ein Text — «NE1000» ist eine Karte,
// die im Modell noch nicht steht, und ein Verweis ins Leere hälfe niemandem.*
$feld(KARTEN, TEXT, 'Kompatibel mit', RelationKind::Composition, Multiplicity::ZeroToMany);

// ── 3. Stecker am Netz und am Monitor ──────────────────────────────────────────────────────────────
$stecker = [
    [NETZWERK, '10BASE2 (BNC)', 'Ethernet über dünnes Koaxialkabel, BNC-Stecker, 10 Mbit/s.'],
    [NETZWERK, '10BASE5 (AUI)', 'Ethernet über dickes Koaxialkabel, AUI-Anschluss (D-Sub 15), 10 Mbit/s.'],
    [NETZWERK, '10BASE-T (RJ45)', 'Ethernet über Twisted Pair, RJ45, 10 Mbit/s.'],
    [NETZWERK, '100BASE-TX (RJ45)', 'Fast Ethernet über Twisted Pair, RJ45, 100 Mbit/s.'],
    [NETZWERK, '1000BASE-T (RJ45)', 'Gigabit-Ethernet über Twisted Pair, RJ45, 1000 Mbit/s.'],
    [BILDSCHIRM, 'MDA / Hercules (Mono)', 'Monochrom, TTL-Signal, D-Sub 9.'],
    [BILDSCHIRM, 'CGA', 'Color Graphics Adapter, TTL-Signal, D-Sub 9.'],
    [BILDSCHIRM, 'EGA', 'Enhanced Graphics Adapter, TTL-Signal, D-Sub 9.'],
];

foreach ($stecker as [$gruppe, $name, $hinweis]) {
    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
        $gruppe,
        SCHNITTSTELLE_BEZ,
        $name
    ));

    if ($steht !== null) {
        continue;
    }

    $sagen("Schnittstelle «{$name}»");

    if ($schreiben) {
        $neu = $data->create($gruppe, RecordType::User)->id;
        $data->put($neu, SCHNITTSTELLE_BEZ, TypedValue::ofText($name));
        $data->put($neu, SCHNITTSTELLE_HINWEIS, TypedValue::ofText($hinweis));
    }
}

// ── 4. Datecode am Exemplar ────────────────────────────────────────────────────────────────────────
$feld(EXEMPLARE, TEXT, 'Datecode', RelationKind::Composition, Multiplicity::ZeroToOne);

// ── 5. Käufe ───────────────────────────────────────────────────────────────────────────────────────
$kaeufe = $knoten('Käufe', MODEL, Category::class);

$feld($kaeufe, MODELS, 'Modell', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$feld($kaeufe, TEXT, 'Art', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, TEXT, 'Anschluss', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, INTEGER, 'Anzahl', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, DECIMAL, 'Preis je Stück', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, DECIMAL, 'Preis gesamt', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, WAEHRUNG, 'Währung', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$feld($kaeufe, DATUM, 'Gekauft am', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, DATUM, 'Eingegangen am', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, TEXT, 'Plattform', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, TEXT, 'Händler', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($kaeufe, EXEMPLARE, 'Exemplare', RelationKind::Aggregation, Multiplicity::ZeroToMany);

echo $schreiben ? "fertig\n" : "— nur gezeigt. Mit --write umbauen.\n";
