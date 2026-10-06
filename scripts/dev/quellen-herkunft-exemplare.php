<?php declare(strict_types=1);

/**
 * Quellenverzeichnis, Herkunft am Satz und Exemplare (2026-09-19).
 *
 *     php scripts/dev/quellen-herkunft-exemplare.php            # nur zeigen, was geschähe
 *     php scripts/dev/quellen-herkunft-exemplare.php --write    # umbauen
 *
 * ⚠️ *Seine Worte: «1. ja exemplar, 2. beim übernehmen berichtigen», «generell so eine Art Markierung am Satz … Vermutung oder
 * abgeleitet … dass man die Quelle angibt», «ich will das auch nicht zu sehr aufblasen … vielleicht ein Quellverzeichnis und dann alle
 * Quellen dort eintragen und dann darauf verweisen» (D-867, D-868).*
 *
 * 1. Auswahlen unter den allgemeinen Konstanten: Herkunftsarten, Quellenarten, Zustände.
 * 2. Knoten «Quellenverzeichnis» unter «Model»: je Quelle ein Satz.
 * 3. An «Model»: «Quellen» verweist jetzt auf das Verzeichnis (vorher je Satz Adresse und Beschriftung); dazu «Herkunft» und
 *    «Herkunftshinweis». Die bisherigen Quellen wandern ins Verzeichnis — gleiche Adresse, ein Satz.
 * 4. Knoten «Exemplare» unter «Model»: das eigene Stück, mit Verweis auf sein Modell.
 *
 * Wiederholbar: jeder Schritt sucht erst, ob es das schon gibt.
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
use Taxmod\Core\Renderer\MediaRenderer;
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

// Gemessen am 2026-09-19
const MODEL = 402, KONSTANTEN = 410, MODELS = 149000103431, WAEHRUNG = 8569;
const TEXT = 1175, DECIMAL = 1173, DATUM = 1183, MEDIA = 149000105224;
const MEDIUM_ADRESSE = 149000107928, MEDIUM_BESCHRIFTUNG = 149000107929;

$sagen = static function (string $satz): void {
    echo $satz, "\n";
};

$knoten = static function (string $name, ?int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    if ($vater === null) {
        return null;
    }

    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Knoten «{$name}» unter {$vater}");

    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};

$feldAn = static function (int $eigner, string $name) use ($wpdb, $p): ?int {
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
        $eigner,
        $name
    ));

    return $id === null ? null : (int) $id;
};

$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $feldAn, $schreiben, $sagen): ?int {
    if ($eigner === null || $ziel === null) {
        return null;
    }

    $steht = $feldAn($eigner, $name);

    if ($steht !== null) {
        return $steht;
    }

    $sagen("Feld «{$name}» an {$eigner} → {$ziel} ({$wieOft->value}, {$art->value})");

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, $art);

    if ($kante->multiplicity !== $wieOft) {
        $kante = $editor->setMultiplicity($eigner, $kante->id, $wieOft);
    }

    return $kante->id;
};

$auswahl = static function (string $name, array $werte) use ($knoten): array {
    $id  = $knoten($name, KONSTANTEN, Choice::class);
    $ids = [];

    foreach ($werte as $wert) {
        $ids[$wert] = $knoten($wert, $id, Constant::class);
    }

    return [$id, $ids];
};

// ── 1. Auswahlen ────────────────────────────────────────────────────────────────────────────────────
[$herkunftsarten] = $auswahl('Herkunftsarten', ['belegt', 'gemessen', 'abgeleitet', 'Vermutung']);
[$quellenarten, $quellenart] = $auswahl('Quellenarten', ['Webseite', 'Datenblatt', 'Handbuch', 'Buch oder Zeitschrift', 'Forum', 'Eigener Beitrag', 'Eigene Messung']);
[$zustaende] = $auswahl('Zustände', ['neu', 'funktioniert', 'ungetestet', 'defekt', 'in Reparatur', 'repariert']);

// ── 2. Quellenverzeichnis ───────────────────────────────────────────────────────────────────────────
$verzeichnis = $knoten('Quellenverzeichnis', MODEL, Category::class);
$qTitel      = $feld($verzeichnis, TEXT, 'Titel', RelationKind::Composition, Multiplicity::ExactlyOne);
$qAdresse    = $feld($verzeichnis, MEDIA, 'Adresse', RelationKind::Composition, Multiplicity::ZeroToOne);
$qArt        = $feld($verzeichnis, $quellenarten, 'Art', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$qAbgerufen  = $feld($verzeichnis, DATUM, 'Abgerufen', RelationKind::Composition, Multiplicity::ZeroToOne);
$qHinweis    = $feld($verzeichnis, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne);

// ── 3. Felder am Vater aller Modelle ────────────────────────────────────────────────────────────────
$alteQuellen = null;
$steht       = $feldAn(MODEL, 'Quellen');

if ($steht !== null && (int) $wpdb->get_var($wpdb->prepare("SELECT to_node_id FROM {$p}relations WHERE id = %d", $steht)) !== (int) $verzeichnis) {
    $alteQuellen = $steht;
    $sagen('Feld «Quellen» (Adresse und Beschriftung je Satz) heisst vorübergehend «Quellen alt»');

    if ($schreiben) {
        $editor->renameField(MODEL, $alteQuellen, 'Quellen alt');
    }
} elseif ($feldAn(MODEL, 'Quellen alt') !== null) {
    $alteQuellen = $feldAn(MODEL, 'Quellen alt');
}

$quellen  = $feld(MODEL, $verzeichnis, 'Quellen', RelationKind::Aggregation, Multiplicity::ZeroToMany);
$herkunft = $feld(MODEL, $herkunftsarten, 'Herkunft', RelationKind::Aggregation, Multiplicity::ZeroToMany);
$feld(MODEL, TEXT, 'Herkunftshinweis', RelationKind::Composition, Multiplicity::ZeroToOne);

// ── 3b. Die bisherigen Quellen ins Verzeichnis ──────────────────────────────────────────────────────
if ($alteQuellen !== null) {
    $teile = $wpdb->get_results($wpdb->prepare(
        "SELECT v.node_record_id AS satz, v.value_ref AS teil, v.position,
                (SELECT value_text FROM {$p}relation_records a WHERE a.node_record_id = v.value_ref AND a.relation_id = %d LIMIT 1) AS adresse,
                (SELECT value_text FROM {$p}relation_records b WHERE b.node_record_id = v.value_ref AND b.relation_id = %d LIMIT 1) AS beschriftung
         FROM {$p}relation_records v WHERE v.relation_id = %d ORDER BY v.node_record_id, v.position",
        MEDIUM_ADRESSE,
        MEDIUM_BESCHRIFTUNG,
        $alteQuellen
    ));
    $imVerzeichnis = [];

    foreach ($teile as $teil) {
        $adresse = trim((string) $teil->adresse);

        if ($adresse === '') {
            continue;
        }

        if (! isset($imVerzeichnis[$adresse])) {
            $vorhanden = $qAdresse === null ? null : $wpdb->get_var($wpdb->prepare(
                "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1",
                $qAdresse,
                $adresse
            ));

            if ($vorhanden !== null) {
                $imVerzeichnis[$adresse] = (int) $vorhanden;
            } else {
                $titel = trim((string) $teil->beschriftung) !== '' ? trim((string) $teil->beschriftung) : MediaRenderer::describe($adresse);
                $art   = str_starts_with($adresse, 'media:') || str_contains($adresse, '/wp-content/uploads/')
                    ? (preg_match('/\.pdf$/i', $titel . ' ' . $adresse) === 1 ? 'Datenblatt' : 'Eigener Beitrag')
                    : (preg_match('~vogons|forum~i', $adresse) === 1 ? 'Forum' : 'Webseite');
                $sagen("  Quelle «{$titel}» ({$art})");

                if ($schreiben) {
                    $neu = $data->create((int) $verzeichnis, RecordType::User);
                    $data->put($neu->id, (int) $qTitel, TypedValue::ofText($titel));
                    $data->put($neu->id, (int) $qAdresse, TypedValue::ofText($adresse));

                    if (($quellenart[$art] ?? null) !== null) {
                        $data->put($neu->id, (int) $qArt, TypedValue::ofReference((int) $quellenart[$art]));
                    }

                    $imVerzeichnis[$adresse] = $neu->id;
                }
            }
        }

        if ($schreiben && isset($imVerzeichnis[$adresse])) {
            $data->appendValue((int) $teil->satz, (int) $quellen, TypedValue::ofRecordReference($imVerzeichnis[$adresse]));
        }
    }

    $sagen(count($teile) . ' bisherige Quellen, ' . count(array_unique(array_map(static fn ($t) => trim((string) $t->adresse), $teile))) . ' verschiedene Adressen');

    if ($schreiben) {
        // *Erst die Teilsätze weg, dann die Kante — sonst blieben Medium-Sätze ohne Halter liegen (der Wächter `simple-type` merkt es).*
        foreach ($teile as $teil) {
            if ($teil->teil !== null) {
                $data->removeRecord((int) $teil->teil);
            }
        }

        $editor->removeField(MODEL, $alteQuellen);
        $sagen('«Quellen alt» in den Schatten');
    }
}

// ── 4. Exemplare ────────────────────────────────────────────────────────────────────────────────────
$exemplare = $knoten('Exemplare', MODEL, Category::class);
$feld($exemplare, MODELS, 'Modell', RelationKind::Aggregation, Multiplicity::ExactlyOne);
$feld($exemplare, TEXT, 'Seriennummer', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($exemplare, TEXT, 'BIOS', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($exemplare, $zustaende, 'Zustand', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$feld($exemplare, $exemplare, 'Eingebaut in', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$feld($exemplare, DATUM, 'Gekauft am', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($exemplare, DECIMAL, 'Kaufpreis', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($exemplare, WAEHRUNG, 'Währung', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$feld($exemplare, TEXT, 'Händler', RelationKind::Composition, Multiplicity::ZeroToOne);
$feld($exemplare, TEXT, 'Hinweis', RelationKind::Composition, Multiplicity::ZeroToOne);

$sagen($schreiben ? "\nFertig." : "\nNur gezeigt. Mit --write umbauen.");
