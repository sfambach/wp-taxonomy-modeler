<?php declare(strict_types=1);

/**
 * Die D-Sub-Steckverbinder des PCs aus dem Amphenol-ICC-Katalog (2026-09-27).
 *
 *     php scripts/dev/dsub-aus-katalog.php            # nur zeigen
 *     php scripts/dev/dsub-aus-katalog.php --write    # übernehmen
 *
 * ⚠️ *Zu [D-906](../../docs/NewConcept/90-decision-log.md), sein Wort: «schau mal ein sub d katalog kanns du die informationen füpr zu den
 * pc steckverbindern daraus extrahieren genauso wie die masse und masszeichnungen interessant sind wohl subd 9/15/25 zweirehig sowie 15 pol
 * dreireihig», und auf die Frage nach den Maßfeldern: «An «Verbinder» (Vater)», je Bauform ein Satz.*
 *
 * Quelle ist sein PDF «io_dsub_brochure.pdf» (Amphenol ICC, D-Subminiature Product Catalog). Übernommen wird **nur**, was darin steht:
 * die Maße A (Flansch), B (Schraubenabstand) und C (Kontaktfeld) von Seite 8 bzw. 33, das Rastermaß der dreireihigen Bauform (2,285 mm)
 * und die Ströme. Das Rastermaß der zweireihigen Bauform nennt der Katalog nicht — es bleibt leer.
 *
 * Wiederholbar: ein Feld, das es gibt, wird nicht neu angelegt; ein Satz wird an seiner Bezeichnung erkannt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
// *Für die Mediathek: `media_handle_sideload()` und was es braucht stehen im Admin-Teil, den ein CLI-Lauf nicht lädt.*
require ABSPATH . 'wp-admin/includes/file.php';
require ABSPATH . 'wp-admin/includes/media.php';
require ABSPATH . 'wp-admin/includes/image.php';
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

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-27
const VERBINDER = 149000104418, STECKVERBINDER = 149000104423, MODEL = 402, QUELLENVERZEICHNIS = 149000108211;
const BEZEICHNUNG = 149000103839, BESCHREIBUNG = 149000107372, QUELLEN = 149000108186, HERKUNFT = 149000108187, HERKUNFTSHINWEIS = 149000108188;
const POLZAHL = 149000104333, REIHEN = 149000104334, RASTERMASS = 149000104335, STROM = 149000108569, SPANNUNG = 149000108570;
const STECKERTYP = 149000104337, GESCHLECHT = 149000104338, DSUB = 149000104368, MAENNLICH = 149000104362, WEIBLICH = 149000104363;
const STECKERTYPEN = 149000104367;
const EINHEITENWERT = 4232, EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236;
const MILLI = 4014, METER = 4036, AMPERE = 4042, VOLT = 4050, STUECK = 4062;

$sagen = static fn (string $satz) => print($satz . "\n");

$feldAn = static fn (int $eigner, string $name): ?int => ($id = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    $eigner,
    $name
))) === null ? null : (int) $id;

// ── Die vier Maßfelder, am Vater «Verbinder» (sein Wort auf die Frage) ─────────────────────────────
$masse = [];

foreach (['Flanschbreite', 'Schraubenabstand', 'Kontaktfeldbreite', 'Einbautiefe'] as $name) {
    $steht = $feldAn(VERBINDER, $name);

    if ($steht !== null) {
        $masse[$name] = $steht;
        continue;
    }

    $sagen("Feld «{$name}» an «Verbinder»");

    if ($schreiben) {
        $kante = $editor->addField(VERBINDER, EINHEITENWERT, $name, RelationKind::Composition);
        $editor->setMultiplicity(VERBINDER, $kante->id, Multiplicity::ZeroToOne);
        $masse[$name] = $kante->id;
    }
}

$menge = static function (int $satz, ?int $feld, float $wert, int $einheit, ?int $vorsatz) use ($data, $wpdb, $p): void {
    if ($feld === null) {
        return;
    }

    // ⚠️ *Ein Feld 1..1 trägt sein Teil schon, sobald der Satz entsteht — ein zweites anzulegen liesse das leere vorn stehen, und
    // die zusammengesetzte Bezeichnung (D-888) las daraus «#38758». Gemessen am 2026-09-27 an den ersten acht Sätzen.*
    $vorhanden = $wpdb->get_var($wpdb->prepare(
        "SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d AND value_ref_kind = 'record' ORDER BY position, id LIMIT 1",
        $satz,
        $feld
    ));

    $teil = $vorhanden === null ? $data->createPart($satz, $feld) : (object) ['id' => (int) $vorhanden];
    $data->put($teil->id, EW_WERT, TypedValue::ofDecimal(rtrim(rtrim(number_format($wert, 3, '.', ''), '0'), '.')));
    $data->put($teil->id, EW_EINHEIT, TypedValue::ofReference($einheit));
    $vorsatz === null || $data->put($teil->id, EW_PREFIX, TypedValue::ofReference($vorsatz));
};


// ── Mediathek: sein Katalog und die zwei Maßzeichnungen ────────────────────────────────────────────
$inMediathek = static function (string $pfad, string $titel, string $beschriftung) use ($wpdb, $schreiben, $sagen): ?string {
    if (! is_file($pfad)) {
        $sagen("⚠ fehlt: {$pfad}");

        return null;
    }

    $name = basename($pfad);
    $id   = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1",
        '%/' . $wpdb->esc_like($name)
    ));

    if ($id !== null) {
        return 'media:' . (int) $id;
    }

    $sagen("Mediathek: {$name}");

    if (! $schreiben) {
        return null;
    }

    $tmp = wp_tempnam($name);
    copy($pfad, $tmp);
    $neu = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, $titel);

    if (is_wp_error($neu)) {
        throw new RuntimeException($name . ': ' . $neu->get_error_message());
    }

    // ⚠️ *Ein Bild hat immer eine Beschriftung ([D-879](../../docs/NewConcept/90-decision-log.md)) — sie steht im Auszug des Anhangs.*
    wp_update_post(['ID' => $neu, 'post_excerpt' => $beschriftung]);

    return 'media:' . $neu;
};

$ordner    = (string) getenv('TAXMOD_DSUB_BILDER');
$katalog   = $inMediathek((string) getenv('TAXMOD_DSUB_PDF'), 'Amphenol ICC — D-Subminiature Product Catalog', 'Amphenol ICC, D-Subminiature Product Catalog — Quelle der Maße dieser Steckverbinder.');
$zeichnung = [
    8  => $inMediathek($ordner . '/dsub-masse-zweireihig.png', 'D-Sub Maße zweireihig', 'Maßzeichnung der zweireihigen D-Subs (9/15/25/37): A Flanschbreite, B Schraubenabstand, C Kontaktfeldbreite. Amphenol ICC, Katalogseite 8.'),
    33 => $inMediathek($ordner . '/dsub-masse-dreireihig.png', 'D-Sub Maße dreireihig', 'Maßzeichnung der dreireihigen D-Subs (15/26/44), Rastermaß 2,285 mm, Reihenabstand 2,54 mm. Amphenol ICC, Katalogseite 33.'),
];

// ── Die Quelle: sein Katalog ────────────────────────────────────────────────────────────────────────
$qFeld   = static fn (string $name): int => (int) $feldAn(QUELLENVERZEICHNIS, $name);
$qTitel  = 'Amphenol ICC — D-Subminiature Product Catalog';
$quelle  = $wpdb->get_var($wpdb->prepare(
    "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
     WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text = %s LIMIT 1",
    QUELLENVERZEICHNIS,
    BEZEICHNUNG,
    $qTitel
));
$quelle = $quelle === null ? null : (int) $quelle;

if ($quelle === null) {
    $sagen("Quelle «{$qTitel}»");

    if ($schreiben) {
        $quelle = $data->create(QUELLENVERZEICHNIS, RecordType::User)->id;
        $data->put($quelle, BEZEICHNUNG, TypedValue::ofText($qTitel));
        $data->put($quelle, $qFeld('Adresse'), TypedValue::ofText((string) $katalog));
        $data->put($quelle, $qFeld('Art'), TypedValue::ofReference((int) $wpdb->get_var(
            "SELECT id FROM {$p}nodes_named WHERE name = 'Datenblatt' AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Quellenarten' LIMIT 1)"
        )));
        $data->put($quelle, $qFeld('Abgerufen'), TypedValue::ofDate('2026-09-27 00:00:00'));
        $data->put($quelle, $qFeld('Hinweis'), TypedValue::ofText('Sein PDF, in die Mediathek übernommen. Maße auf Seite 8 (Standarddichte) und Seite 33 (dreireihig).'));
    }
}

// *Die Adresse zeigt auf die Mediathek, nicht auf seinen Rechner — ein Pfad aus einem früheren Lauf wird berichtigt.*
if ($quelle !== null && $katalog !== null) {
    $steht = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d LIMIT 1",
        $quelle,
        $qFeld('Adresse')
    ));

    if ($steht !== $katalog) {
        $sagen("Quelle #{$quelle}: Adresse → {$katalog}");
        $schreiben && $data->put($quelle, $qFeld('Adresse'), TypedValue::ofText($katalog));
    }
}
// ── Ein eigener Steckertyp für die dreireihige Bauform ─────────────────────────────────────────────
// ⚠️ *Sonst hiessen die beiden 15-poligen gleich: die Bezeichnung setzt sich aus Steckertyp, Polzahl und Geschlecht zusammen (D-888).*
$dsubHd = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", 'D-Sub HD', STECKERTYPEN));

if ($dsubHd === null) {
    $sagen('Auswahlwert «D-Sub HD» unter den Steckertypen');
    $dsubHd = $schreiben ? $editor->createNode('D-Sub HD', STECKERTYPEN, \Taxmod\Core\Model\NodeClass\Constant::class)->id : null;
}

/**
 * Die vier Bauformen. Die Maße in mm, aus den Tabellen des Katalogs:
 * Seite 8 (Standarddichte, Spalten A/B/C) und Seite 33 (dreireihig, Spalten A/B/C, Rastermaß G).
 */
const BAUFORMEN = [
    [
        'name' => 'D-Sub 9 · zweireihig', 'pole' => 9, 'reihen' => 2, 'raster' => null, 'strom' => 5.0, 'spannung' => null,
        'a' => 30.81, 'b' => 24.99, 'c' => 16.96, 'seite' => 8,
        'text' => 'Zweireihig, Schalengröße E. Am PC die serielle Schnittstelle (RS-232) und der Monitoranschluss mancher Grafikkarten (CGA, EGA).',
    ],
    [
        'name' => 'D-Sub 15 · zweireihig', 'pole' => 15, 'reihen' => 2, 'raster' => null, 'strom' => 5.0, 'spannung' => null,
        'a' => 39.14, 'b' => 33.32, 'c' => 25.18, 'seite' => 8,
        'text' => 'Zweireihig, Schalengröße A. Am PC der Gameport und die AUI-Buchse mancher Netzwerkkarten.',
    ],
    [
        'name' => 'D-Sub 25 · zweireihig', 'pole' => 25, 'reihen' => 2, 'raster' => null, 'strom' => 5.0, 'spannung' => null,
        'a' => 53.03, 'b' => 47.04, 'c' => 39.12, 'seite' => 8,
        'text' => 'Zweireihig, Schalengröße B. Am PC die parallele Schnittstelle (Centronics-Seite am Rechner) und die zweite serielle.',
    ],
    [
        'name' => 'D-Sub 15 HD · dreireihig', 'hd' => true, 'pole' => 15, 'reihen' => 3, 'raster' => 2.285, 'strom' => 2.5, 'spannung' => 250.0,
        'a' => 30.81, 'b' => 24.99, 'c' => 16.96, 'seite' => 33,
        'text' => 'Dreireihig in der Schale der neunpoligen (Schalengröße E). Am PC der VGA-Anschluss. Reihenabstand 2,54 mm, Rastermaß in der Reihe 2,285 mm.',
    ],
];

$neu    = 0;
$bilder = (int) $wpdb->get_var("SELECT id FROM {$p}relations_named WHERE name = 'Bilder' AND from_node_id = " . MODEL);

// ⚠️ **Je Bauform ein Stecker und eine Buchse** — *sein Wort auf die Frage, wie die Stückliste das Geschlecht führt:
// «Am Bauteil, also je Bauform zwei Sätze». Ein Teil, das man bestellt und einlötet, ist eines von beidem.*
foreach (BAUFORMEN as $b) {
    foreach ([MAENNLICH => 'Stecker', WEIBLICH => 'Buchse'] as $geschlecht => $wort) {
        $marke = $b['name'] . ' · ' . $wort;

        // *Die Bezeichnung setzt sich am Bauteil selbst zusammen (D-888); erkannt wird an der Beschreibung, die dieser Import schreibt.*
        $steht = $wpdb->get_var($wpdb->prepare(
            "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
             WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text LIKE %s LIMIT 1",
            STECKVERBINDER,
            BESCHREIBUNG,
            $wpdb->esc_like($marke) . '%'
        ));

        if ($steht !== null) {
            $sagen("«{$marke}» steht schon (#{$steht})");
            continue;
        }

        ++$neu;
        $sagen("{$marke}: {$b['pole']}-polig, {$b['reihen']} Reihen, A {$b['a']} · B {$b['b']} · C {$b['c']} mm");

        if (! $schreiben) {
            continue;
        }

        $satz = $data->create(STECKVERBINDER, RecordType::User)->id;
        $data->put($satz, BESCHREIBUNG, TypedValue::ofText($marke . ' — ' . $b['text']));
        $data->put($satz, STECKERTYP, TypedValue::ofReference(($b['hd'] ?? false) && $dsubHd !== null ? (int) $dsubHd : DSUB));
        $data->put($satz, GESCHLECHT, TypedValue::ofReference($geschlecht));

        $menge($satz, POLZAHL, (float) $b['pole'], STUECK, null);
        $menge($satz, REIHEN, (float) $b['reihen'], STUECK, null);
        $b['raster'] === null || $menge($satz, RASTERMASS, (float) $b['raster'], METER, MILLI);
        $menge($satz, STROM, (float) $b['strom'], AMPERE, null);
        $b['spannung'] === null || $menge($satz, SPANNUNG, (float) $b['spannung'], VOLT, null);

        $menge($satz, $masse['Flanschbreite'] ?? null, (float) $b['a'], METER, MILLI);
        $menge($satz, $masse['Schraubenabstand'] ?? null, (float) $b['b'], METER, MILLI);
        $menge($satz, $masse['Kontaktfeldbreite'] ?? null, (float) $b['c'], METER, MILLI);

        $data->appendValue($satz, HERKUNFT, TypedValue::ofReference((int) $wpdb->get_var(
            "SELECT id FROM {$p}nodes_named WHERE name = 'belegt' AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Herkunftsarten' LIMIT 1)"
        )));
        $data->put($satz, HERKUNFTSHINWEIS, TypedValue::ofText(
            "Aus seinem Katalog (Amphenol ICC, D-Subminiature Product Catalog, Seite {$b['seite']}): A = Flanschbreite, B = Schraubenabstand, C = Kontaktfeldbreite."
            . ($b['raster'] === null
                ? ' Das Rastermaß der Kontakte nennt der Katalog für die Standarddichte nicht — das Feld bleibt leer; die Zeichnungen geben nur die Rastermaße der Platinenseite (2,54 mm Europa, 2,84 mm US).'
                : ' Rastermaß in der Reihe 2,285 mm, Reihenabstand 2,54 mm (Seite 33).')
            . ' Strom und Spannung: Standarddichte 5 A (Seite 39), dreireihig 2,5 A bei 250 V (Seite 27).'
            . ' Die Werte gelten für Amphenols Teile; die Bauform ist genormt (DIN 41652, MIL-C-24308), der Strom je Hersteller nicht.'
            . ' Stecker und Buchse stehen im Katalog in denselben Zeilen (P und S) und haben dieselben Aussenmaße.'
        ));

        $quelle === null || $data->appendValue($satz, QUELLEN, TypedValue::ofRecordReference($quelle));
        ($zeichnung[$b['seite']] ?? null) === null || $bilder === 0
            || $data->appendValue($satz, $bilder, TypedValue::ofText((string) $zeichnung[$b['seite']]));
    }
}

// ── Der 23-polige des Amiga ─────────────────────────────────────────────────────────────────────────
// ⚠️ **Sein Einwurf:** *«was mir einfällt der amiga hatte auch noch ein paar spezielle subd 23 und andere»*, und auf die Frage: *«Jetzt mit
// recherchieren»*. *Im Katalog steht er nicht — Amphenol führt 9/15/25/37/50 und dreireihig 15/26/44. Die Maße kommen aus der Zeichnung
// einer Nachfertigung (iComp, 2020); eine Zeichnung von Commodore war nicht zu finden. **Die Schale ist eine eigene, keine DB-Schale:**
// Schraubenabstand 44,27 mm gegen 47,04 mm, also genau ein Rastermaß schmaler — passend zu 12+11 statt 13+12 Kontakten.*
$amigaQuellen = [
    ['url' => 'https://en.wikipedia.org/wiki/Amiga_video_connector', 'titel' => 'Amiga video connector — Wikipedia'],
    ['url' => 'https://en.wikipedia.org/wiki/D-subminiature', 'titel' => 'D-subminiature — Wikipedia'],
    ['url' => 'https://wiki.icomp.de/w/images/2/2a/DB23_male_iComp.pdf', 'titel' => 'iComp GmbH: Maßzeichnung DB23M, 2020-06-14'],
];

$amigaQuelle = static function (array $q) use ($wpdb, $p, $data, $qFeld, $schreiben, $sagen): ?int {
    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d AND value_text = %s LIMIT 1",
        $qFeld('Adresse'),
        $q['url']
    ));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Quelle «{$q['titel']}»");

    if (! $schreiben) {
        return null;
    }

    $neu = $data->create(QUELLENVERZEICHNIS, RecordType::User)->id;
    $data->put($neu, BEZEICHNUNG, TypedValue::ofText($q['titel']));
    $data->put($neu, $qFeld('Adresse'), TypedValue::ofText($q['url']));
    $data->put($neu, $qFeld('Abgerufen'), TypedValue::ofDate('2026-09-27 00:00:00'));

    return $neu;
};

const AMIGA = [
    [
        'wort' => 'Stecker', 'geschlecht' => MAENNLICH, 'kontaktfeld' => 36.19,
        'text' => 'Am Amiga der RGB-/Videoausgang (A1000 bis A4000): analoges und digitales RGB, Sync, Genlock-Takt und Versorgungsspannungen. Am Gehäuse sitzt die Stiftausführung.',
    ],
    [
        'wort' => 'Buchse', 'geschlecht' => WEIBLICH, 'kontaktfeld' => 35.61,
        'text' => 'Am Amiga der Anschluss für externe Diskettenlaufwerke, bis zu drei hintereinander. Am Gehäuse sitzt die Buchse — das Geschlecht ist der einzige äussere Unterschied zum gleich grossen Videoanschluss.',
    ],
];

foreach (AMIGA as $a) {
    $marke = 'D-Sub 23 · zweireihig · ' . $a['wort'];
    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id
         WHERE r.node_id = %d AND v.relation_id = %d AND v.value_text LIKE %s LIMIT 1",
        STECKVERBINDER,
        BESCHREIBUNG,
        $wpdb->esc_like($marke) . '%'
    ));

    if ($steht !== null) {
        $sagen("«{$marke}» steht schon (#{$steht})");
        continue;
    }

    ++$neu;
    $sagen("{$marke}: 23-polig, 2 Reihen, A 50.27 · B 44.27 · C {$a['kontaktfeld']} mm");

    if (! $schreiben) {
        continue;
    }

    $satz = $data->create(STECKVERBINDER, RecordType::User)->id;
    $data->put($satz, BESCHREIBUNG, TypedValue::ofText($marke . ' — ' . $a['text']));
    $data->put($satz, STECKERTYP, TypedValue::ofReference(DSUB));
    $data->put($satz, GESCHLECHT, TypedValue::ofReference($a['geschlecht']));

    $menge($satz, POLZAHL, 23.0, STUECK, null);
    $menge($satz, REIHEN, 2.0, STUECK, null);
    $menge($satz, RASTERMASS, 2.77, METER, MILLI);
    $menge($satz, $masse['Flanschbreite'] ?? null, 50.27, METER, MILLI);
    $menge($satz, $masse['Schraubenabstand'] ?? null, 44.27, METER, MILLI);
    $menge($satz, $masse['Kontaktfeldbreite'] ?? null, (float) $a['kontaktfeld'], METER, MILLI);

    foreach (['belegt', 'Vermutung'] as $art) {
        $data->appendValue($satz, HERKUNFT, TypedValue::ofReference((int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = (SELECT id FROM {$p}nodes_named WHERE name = 'Herkunftsarten' LIMIT 1)",
            $art
        ))));
    }

    $data->put($satz, HERKUNFTSHINWEIS, TypedValue::ofText(
        'Steht nicht in seinem Amphenol-Katalog — recherchiert am 2026-09-27. Die Schale ist **keine** DB-Schale, sondern eine eigene,'
        . ' nicht genormte: Schraubenabstand 44,27 mm gegen 47,04 mm bei 25 Polen, also genau ein Rastermaß schmaler (12+11 statt 13+12 Kontakte).'
        . ' Wikipedia führt die 23-polige Ausführung ausdrücklich als «non-standard shell size». Rastermaß 2,77 mm in der Reihe,'
        . ' Reihenabstand 2,84 mm (Standarddichte, MIL-DTL-24308), Gewinde 2× #4-40 UNC, Flanschhöhe 12,5 mm.'
        . ' Offen: alle Millimetermaße stammen aus einer Nachfertigung von 2020 (iComp), nicht von Commodore;'
        . ' die Kontaktfeldbreite unterscheidet sich zwischen Stift- (36,19 mm) und Buchsenausführung (35,61 mm), was die Zeichnung nicht erklärt.'
        . ' Der Amiga 1000 hat ausserdem 25-polige Anschlüsse in gewohnter Schale, aber mit eigener Belegung (Audio und Spannungen an der seriellen,'
        . ' +5 V auf Pol 23 der parallelen) — das ist eine Sache der Belegung, nicht der Bauform.'
    ));

    foreach ($amigaQuellen as $q) {
        $qId = $amigaQuelle($q);
        $qId === null || $data->appendValue($satz, QUELLEN, TypedValue::ofRecordReference($qId));
    }
}

$sagen("\n{$neu} Steckverbinder" . ($schreiben ? ' übernommen.' : ' — nur gezeigt. Mit --write übernehmen.'));
