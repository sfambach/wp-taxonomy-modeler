<?php declare(strict_types=1);
// D-876 / Plan 238: Dateien an der Revision als Teil «Datei» (Datei + Kategorie). «Gerber-Dateien» zieht um und
// geht in den Schatten; der Schaltplan wandert aus «Bilder» der Platine an ihre Revision. Dazu Bestückungsplan und
// KiCad-Projekt der zwei BT2PS2-Platinen, und das Projekt steht «im Bau». Ohne --write wird nur gezeigt.
define('WP_USE_THEMES', false);
require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Choice;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;
wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$rc = new ReflectionClass(Plugin::class); $plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
global $wpdb; $p = $wpdb->prefix . 'taxmod_';

const REVISION = 149000105262, GERBER_ALT = 149000105899, P_REVISIONEN = 149000107282, BILDER = 149000108057;
const HW_KONSTANTEN = 149000104441, KOMPOSITIONEN = 404, MEDIA = 149000105224;
const PROJEKT = 30753, STATUS = 149000108054, IM_BAU = 149000108066;
const QUELLE = 'C:/Devel/Platinen/projekte';
// Platine → [Ordner, Bestückungspläne, KiCad-Projekt]
$bt2ps2 = [
    33073 => ['esp32-bt2ps2', ['fertigung/bestueckung-oben.pdf' => 'bt2ps2-bestueckung-oben.pdf', 'fertigung/bestueckung-unten.pdf' => 'bt2ps2-bestueckung-unten.pdf'], 'fertigung/bt2ps2-kicad-projekt.zip'],
    33075 => ['esp32-bt2ps2-tht', ['fertigung/bestueckung-oben.pdf' => 'bt2ps2-tht-bestueckung-oben.pdf'], 'fertigung/bt2ps2-tht-kicad-projekt.zip'],
];

$sagen = static fn (string $s) => print($s . "\n");
$knoten = static function (string $name, ?int $vater, string $klasse) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    if ($vater === null) return null;
    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $name, $vater));
    if ($steht !== null) return (int) $steht;
    $sagen("Knoten «{$name}» unter {$vater}");
    return $schreiben ? $editor->createNode($name, $vater, $klasse)->id : null;
};
$feldAn = static function (int $eigner, string $name) use ($wpdb, $p): ?int {
    $id = $wpdb->get_var($wpdb->prepare("SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s", $eigner, $name));
    return $id === null ? null : (int) $id;
};
$feld = static function (?int $eigner, ?int $ziel, string $name, RelationKind $art, Multiplicity $wieOft) use ($editor, $feldAn, $schreiben, $sagen): ?int {
    if ($eigner === null || $ziel === null) return null;
    if (($steht = $feldAn($eigner, $name)) !== null) return $steht;
    $sagen("Feld «{$name}» an {$eigner} → {$ziel} ({$wieOft->value}, {$art->value})");
    if (! $schreiben) return null;
    $kante = $editor->addField($eigner, $ziel, $name, $art);
    if ($kante->multiplicity !== $wieOft) $kante = $editor->setMultiplicity($eigner, $kante->id, $wieOft);
    return $kante->id;
};
$mediathek = static function (string $pfad, string $name) use ($wpdb, $schreiben, $sagen): string {
    if (! is_file($pfad)) throw new RuntimeException("Datei fehlt: $pfad");
    $id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1", '%/' . $wpdb->esc_like($name)));
    if ($id !== null) return 'media:' . $id;
    $sagen("  Mediathek neu: $name");
    if (! $schreiben) return 'media:?';
    $tmp = wp_tempnam($name); copy($pfad, $tmp);
    $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, pathinfo($name, PATHINFO_FILENAME));
    if (is_wp_error($id)) throw new RuntimeException("$name: " . $id->get_error_message());
    return 'media:' . $id;
};

// ── 1. Modell ──────────────────────────────────────────────────────────────────────────────────────
$kategorien = $knoten('Dateikategorien', HW_KONSTANTEN, Choice::class);
$kat = [];
foreach (['Schaltplan', 'Gerber', 'Bestückungsplan', 'KiCad-Projekt'] as $k) $kat[$k] = $knoten($k, $kategorien, Constant::class);
$teil = $knoten('Datei', KOMPOSITIONEN, Category::class);
$fDatei = $feld($teil, MEDIA, 'Datei', RelationKind::Composition, Multiplicity::ExactlyOne);
$fKategorie = $feld($teil, $kategorien, 'Kategorie', RelationKind::Aggregation, Multiplicity::ExactlyOne);
$fDateien = $feld(REVISION, $teil, 'Dateien', RelationKind::Composition, Multiplicity::ZeroToMany);

$anlegen = static function (int $rev, string $media, string $kategorie) use ($data, $fDateien, $fDatei, $fKategorie, $kat, $wpdb, $p, $schreiben, $sagen): void {
    // schon da? (gleiche Datei an dieser Revision)
    if ($fDateien !== null && $fDatei !== null && $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$p}relation_records d JOIN {$p}relation_records f ON f.node_record_id = d.value_ref AND f.relation_id = %d
         WHERE d.node_record_id = %d AND d.relation_id = %d AND f.value_text = %s", $fDatei, $rev, $fDateien, $media))) return;
    $sagen("  Revision $rev: $kategorie ← $media");
    if (! $schreiben) return;
    $t = $data->createPart($rev, $fDateien);
    $data->put($t->id, $fDatei, TypedValue::ofText($media));
    $data->put($t->id, $fKategorie, TypedValue::ofReference($kat[$kategorie]));
};

// ── 2. «Gerber-Dateien» zieht um, dann in den Schatten ────────────────────────────────────────────
$gerberAlt = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}relations WHERE id = %d", GERBER_ALT));
if ($gerberAlt !== null) {
    $werte = $wpdb->get_results($wpdb->prepare("SELECT node_record_id AS rev, value_text AS media FROM {$p}relation_records WHERE relation_id = %d AND value_text IS NOT NULL", GERBER_ALT));
    $sagen(count($werte) . " Wert(e) in «Gerber-Dateien»");
    foreach ($werte as $w) {
        $anlegen((int) $w->rev, $w->media, 'Gerber');
        if ($schreiben) $data->clear((int) $w->rev, GERBER_ALT);
    }
    $sagen('«Gerber-Dateien» in den Schatten');
    if ($schreiben) $editor->removeField(REVISION, GERBER_ALT);
}

// ── 3. BT2PS2: Schaltplan aus «Bilder» an die Revision, Bestückungsplan und KiCad-Projekt dazu ──────
foreach ($bt2ps2 as $platine => [$ordner, $plaene, $projekt]) {
    $rev = (int) $wpdb->get_var($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d ORDER BY position LIMIT 1", $platine, P_REVISIONEN));
    $bilder = $wpdb->get_col($wpdb->prepare("SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d ORDER BY position", $platine, BILDER));
    $bleiben = [];
    foreach ($bilder as $b) {
        $mime = get_post_mime_type((int) substr($b, 6));
        if ($mime === 'application/pdf') $anlegen($rev, $b, 'Schaltplan'); else $bleiben[] = $b;
    }
    if (count($bleiben) !== count($bilder)) {
        $sagen("  Platine $platine: «Bilder» behält " . count($bleiben) . " von " . count($bilder));
        if ($schreiben) { $data->clear($platine, BILDER); foreach ($bleiben as $b) $data->appendValue($platine, BILDER, TypedValue::ofText($b)); }
    }
    foreach ($plaene as $pfad => $name) $anlegen($rev, $mediathek(QUELLE . "/$ordner/$pfad", $name), 'Bestückungsplan');
    $anlegen($rev, $mediathek(QUELLE . "/$ordner/$projekt", basename($projekt)), 'KiCad-Projekt');
}

// ── 4. Projektstatus ───────────────────────────────────────────────────────────────────────────────
$status = $wpdb->get_var($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d", PROJEKT, STATUS));
if ((int) $status !== IM_BAU) {
    $sagen('Projekt ' . PROJEKT . ': Status «im Bau»');
    if ($schreiben) $data->put(PROJEKT, STATUS, TypedValue::ofReference(IM_BAU));
}
echo $schreiben ? "Fertig.\n" : "Nur gezeigt.\n";
