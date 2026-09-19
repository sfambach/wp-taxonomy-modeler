<?php declare(strict_types=1);

/**
 * Seitenvorlagen im Modell, und ein Template-Beitrag als erste Vorlage (2026-09-19).
 *
 *     php scripts/dev/seitenvorlagen.php <Beitrags-Id>            # nur zeigen
 *     php scripts/dev/seitenvorlagen.php <Beitrags-Id> --write    # anlegen
 *
 * ⚠️ *Sein Wort: «ja passt» auf den Vorschlag, die Gliederung ins Modell zu legen und beim neuen Beitrag als Startmuster anzubieten,
 * am Beispiel «Retro Hauptplatine» (D-870).*
 *
 * 1. Auswahl «Abschnittsarten» unter den allgemeinen Konstanten.
 * 2. Teil «Abschnitt» unter den Kompositionen: Überschrift, Ebene, Art, Hilfetext, Vorgabe.
 * 3. Knoten «Seitenvorlagen» unter «Model», gebunden an {@see \Taxmod\Core\Page\StarterPattern}: Name, Titel-Präfix, Vorspann, Beitrag,
 *    Abschnitte.
 * 4. Der Beitrag wird an seinen Überschriften-Blöcken zerlegt; was darunter steht, ist die Vorgabe des Abschnitts — so, wie er es vorgibt.
 *
 * Wiederholbar: eine Vorlage, die es schon gibt (gleicher Beitrag), wird nicht noch einmal angelegt.
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
use Taxmod\Core\Page\StarterPattern;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$beitrag   = (int) ($argv[1] ?? 0);
$post      = get_post($beitrag) ?: exit("kein Beitrag {$beitrag}\n");

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
const MODEL = 402, KOMPOSITIONEN = 404, KONSTANTEN = 410, TEXT = 1175, INTEGER = 1171;

$sagen = static function (string $satz): void {
    echo $satz, "\n";
};

$knoten = static function (string $name, int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Knoten «{$name}» unter {$vater}");

    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};

$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
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

    $sagen("Feld «{$name}» an {$eigner} ({$wieOft->value})");

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, $art);

    if ($kante->multiplicity !== $wieOft) {
        $kante = $editor->setMultiplicity($eigner, $kante->id, $wieOft);
    }

    return $kante->id;
};

// ── 1. Abschnittsarten ─────────────────────────────────────────────────────────────────────────────
$arten = $knoten('Abschnittsarten', KONSTANTEN, Choice::class);
$art   = [];

foreach (['Freitext', 'Galerie', 'Liste', 'Tabelle', 'Textbaustein', 'Modelldaten', 'Quellen', 'Logbuch', 'Verwandte Beiträge'] as $name) {
    $art[$name] = $arten === null ? null : $knoten($name, $arten, Constant::class);
}

// ── 2. Teil «Abschnitt» ────────────────────────────────────────────────────────────────────────────
$abschnitt  = $knoten('Abschnitt', KOMPOSITIONEN, Category::class);
$aUeber     = $feld($abschnitt, TEXT, StarterPattern::HEADING, RelationKind::Composition, Multiplicity::ExactlyOne);
$aEbene     = $feld($abschnitt, INTEGER, StarterPattern::LEVEL, RelationKind::Composition, Multiplicity::ZeroToOne);
$aArt       = $feld($abschnitt, $arten, 'Art', RelationKind::Aggregation, Multiplicity::ZeroToOne);
$aHilfe     = $feld($abschnitt, TEXT, StarterPattern::HINT, RelationKind::Composition, Multiplicity::ZeroToOne);
$aVorgabe   = $feld($abschnitt, TEXT, StarterPattern::PRESET, RelationKind::Composition, Multiplicity::ZeroToOne);

// ── 3. Knoten «Seitenvorlagen» ─────────────────────────────────────────────────────────────────────
$vorlagen = $knoten('Seitenvorlagen', MODEL, Category::class);

if ($vorlagen !== null && $schreiben && $editor->nodeImplementing(StarterPattern::class) === null) {
    $editor->setImplementedBy($vorlagen, StarterPattern::class);
    $sagen('«Seitenvorlagen» nennt ' . StarterPattern::class);
}

$vName      = $feld($vorlagen, TEXT, StarterPattern::NAME, RelationKind::Composition, Multiplicity::ExactlyOne);
$vPraefix   = $feld($vorlagen, TEXT, StarterPattern::PREFIX, RelationKind::Composition, Multiplicity::ZeroToOne);
$vVorspann  = $feld($vorlagen, TEXT, StarterPattern::LEAD, RelationKind::Composition, Multiplicity::ZeroToOne);
$vBeitrag   = $feld($vorlagen, INTEGER, 'Beitrag', RelationKind::Composition, Multiplicity::ZeroToOne);
$vAbschnitt = $feld($vorlagen, $abschnitt, StarterPattern::SECTIONS, RelationKind::Composition, Multiplicity::ZeroToMany);

// ── 4. Den Beitrag zerlegen ────────────────────────────────────────────────────────────────────────
$name    = trim((string) preg_replace('/\s*-?\s*Template\s*$/i', '', $post->post_title));
$praefix = $name . ' -';
$bloecke = array_values(array_filter(parse_blocks($post->post_content), static fn (array $b): bool => $b['blockName'] !== null || trim((string) $b['innerHTML']) !== ''));
$vorspann = [];
$teile    = [];
$leer     = [];

foreach ($bloecke as $block) {
    // *Eine Überschrift ohne Text ist keine Gliederung — ihr Inhalt gehört zum Abschnitt davor, und der Hinweis sagt es.*
    if ($block['blockName'] === 'core/heading' && trim(html_entity_decode(wp_strip_all_tags((string) $block['innerHTML']))) === '' && $teile !== []) {
        $leer[] = $teile[count($teile) - 1]['ueberschrift'];
        continue;
    }

    if ($block['blockName'] === 'core/heading') {
        $teile[] = [
            'ueberschrift' => trim(html_entity_decode(wp_strip_all_tags((string) $block['innerHTML']))),
            'ebene'        => (int) ($block['attrs']['level'] ?? 2),
            'bloecke'      => [],
        ];
        continue;
    }

    if ($teile === []) {
        $vorspann[] = $block;
    } else {
        $teile[count($teile) - 1]['bloecke'][] = $block;
    }
}

// *Die Art wird aus dem gelesen, was unter der Überschrift steht — eine Vermutung, darum am Satz als «abgeleitet» vermerkt.*
$artVon = static function (array $teil): string {
    $namen = array_map(static fn (array $b): string => (string) $b['blockName'], $teil['bloecke']);
    $text  = strtolower($teil['ueberschrift']);

    return match (true) {
        str_starts_with($text, 'quellen')                                                           => 'Quellen',
        str_starts_with($text, 'log')                                                               => 'Logbuch',
        in_array('core/shortcode', $namen, true) && str_contains($text, 'verwandt')                 => 'Verwandte Beiträge',
        in_array('core/gallery', $namen, true)                                                      => 'Galerie',
        in_array('tablepress/table', $namen, true) || in_array('core/table', $namen, true)          => 'Tabelle',
        $namen !== [] && array_diff($namen, ['core/block']) === []                                  => 'Textbaustein',
        in_array('core/list', $namen, true)                                                         => 'Liste',
        default                                                                                     => 'Freitext',
    };
};

$sagen("\n«{$name}» aus Beitrag {$beitrag}: " . count($teile) . ' Abschnitte');

foreach ($teile as $i => $teil) {
    $sagen(sprintf('  %2d. %s%s (h%d) — %s, %d Blöcke', $i + 1, str_repeat('  ', max(0, $teil['ebene'] - 1)), $teil['ueberschrift'], $teil['ebene'], $artVon($teil), count($teil['bloecke'])));
}

$steht = $vBeitrag === null ? null : $wpdb->get_var($wpdb->prepare("SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_int = %d", $vBeitrag, $beitrag));

if ($steht !== null) {
    exit("\nDie Vorlage aus Beitrag {$beitrag} steht schon (Satz {$steht}).\n");
}

if (! $schreiben) {
    exit("\nNur gezeigt. Mit --write anlegen.\n");
}

$satz = $data->create((int) $vorlagen, RecordType::User);
$data->put($satz->id, (int) $vName, TypedValue::ofText($name));
$data->put($satz->id, (int) $vPraefix, TypedValue::ofText($praefix));
$data->put($satz->id, (int) $vBeitrag, TypedValue::ofInt($beitrag));

if ($vorspann !== []) {
    $data->put($satz->id, (int) $vVorspann, TypedValue::ofText(trim(serialize_blocks($vorspann))));
}

foreach ($teile as $teil) {
    $neu = $data->createPart($satz->id, (int) $vAbschnitt);
    $data->put($neu->id, (int) $aUeber, TypedValue::ofText($teil['ueberschrift']));
    $data->put($neu->id, (int) $aEbene, TypedValue::ofInt($teil['ebene']));

    if (($art[$artVon($teil)] ?? null) !== null) {
        $data->put($neu->id, (int) $aArt, TypedValue::ofReference((int) $art[$artVon($teil)]));
    }

    if ($teil['bloecke'] !== []) {
        $data->put($neu->id, (int) $aVorgabe, TypedValue::ofText(trim(serialize_blocks($teil['bloecke']))));
    }
}

// *Herkunft (D-868): die Gliederung ist aus dem Beitrag abgeleitet, die Art jedes Abschnitts vermutet.*
$herkunft = $wpdb->get_var("SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = " . MODEL . " AND n.name = 'Herkunft'");
$hinweis  = $wpdb->get_var("SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = " . MODEL . " AND n.name = 'Herkunftshinweis'");
$abgeleitet = $wpdb->get_var("SELECT id FROM {$p}nodes_named WHERE name = 'abgeleitet' AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Herkunftsarten' LIMIT 1)");

if ($herkunft !== null && $abgeleitet !== null) {
    $data->appendValue($satz->id, (int) $herkunft, TypedValue::ofReference((int) $abgeleitet));
}

if ($hinweis !== null) {
    $data->put($satz->id, (int) $hinweis, TypedValue::ofText("Aus dem Beitrag «{$post->post_title}» (#{$beitrag}) an den Überschriften zerlegt; die Art jedes Abschnitts ist aus seinen Blöcken vermutet."
        . ($leer === [] ? '' : ' Leere Überschrift im Beitrag — ihr Inhalt steht jetzt unter «' . implode('», «', $leer) . '».')));
}

$sagen("\nVorlage angelegt: Satz {$satz->id}.");
