<?php declare(strict_types=1);

/**
 * Ein Lauf durch die Einstellungen — wie ein Mensch ihn geht.
 *
 *     php scripts/dev/einstellungen-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-706](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«ich glaube wir müssen mal die
 * sinnhaftigkeit der vielen wächter hinterfragen wenn zwei auf der gleichen stelle arbeiten wäre es dann
 * nicht einer?»* — und *«1 ja zusammen».* **Dieser Lauf ersetzt elf Wächter** (`package7`,
 * `renderer-choice-mask`, `page-blocks`, `setting-write`, `setting-relation`, `preview`, `setting-lock`,
 * `field-kind`, `converter`, `setting-branch-relation`, `rendering-scaffold`); wohin jede ihrer Zusagen
 * gegangen ist, steht in [`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md).
 *
 * ```mermaid
 * flowchart LR
 *   G["0 · Gerüst: Renderer-Knoten ↔ Kode, Wurzelkanten, Bestand"] --> W["1 · die Wiese __es: Modell, sieben Felder, ein eigener Zahltyp"]
 *   W --> E["2 · erklären: eine Einstellungskante, die Art über die Maske"]
 *   E --> R["3 · wählen: der Renderer-Kasten, speichern, frisch lesen, zeichnen"]
 *   R --> S["4 · erben: gesperrt, Haken, überschreiben — 5 · Konflikt: automatisch, Typ-Standard"]
 *   S --> T["6 · die Tafel der Feldzeile: aufklappen, setzen, nur was die Kette des Ziels erklärt"]
 *   T --> V["7 · Werte und Steuerelemente — 8 · Konverter — 9 · Vorschau"]
 *   V --> K["10 · Kantenart — 11 · Datensätze — 12 · die Seite — 13 · Umbenennen — 14 · Fassung 38"]
 * ```
 *
 * Die Klammer `lib/no-write.php` dreht am Ende alles zurück; die Wiese trägt das Präfix `__es`.
 *
 * @see docs/NewConcept/20-interaction.md
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\DateTimeRenderer;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\FieldRowRenderer;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\Orientation;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RendererChoiceRenderer;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Renderer\TableRenderer;
use Taxmod\Core\Renderer\ToggleRenderer;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Validator\ShippedValidators;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\RenderingScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => Schema::forgetOrphanLabels());

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ok   {$what}\n";

        return;
    }

    $failed++;
    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

global $wpdb;

$p         = $wpdb->prefix . 'taxmod_';
$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock(), $log);
$types     = new SeededTypeNodes($nodes, $framework);
$registry  = ShippedRenderers::registry();
$labels    = new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale());

/** Ein frischer Zeichner je Frage — er merkt sich Ketten und Sätze. */
$zeichner = static fn (): Rendering => new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    ShippedConverters::registry(),
    new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $framework),
    $relations
);
$modell = static fn (): ModelValues => new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $framework);

wp_set_current_user(1);

function seite(int $nodeId, ?string $offen = null): string
{
    $_GET['page']        = 'taxmod-nodes';
    $_GET['taxmod_node'] = (string) $nodeId;

    if ($offen === null) {
        unset($_GET['taxmod_open_rows']);
    } else {
        $_GET['taxmod_open_rows'] = $offen;
    }

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    $markup = $plugin->screen()->render();
    unset($_GET['taxmod_open_rows']);

    return $markup;
}

/** Schickt einen Akt an die Knotenseite; gibt zurück, ob umgeleitet wurde, und merkt sich die Adresse. */
function abschicken(array $post): bool
{
    $_POST    = $post;
    $_REQUEST = $post;

    $gewandert = false;
    $GLOBALS['taxmod_letzte_adresse'] = '';

    $fang = static function (string $ort) use (&$gewandert): string {
        $gewandert = true;
        $GLOBALS['taxmod_letzte_adresse'] = $ort;
        throw new RuntimeException('redirect');
    };

    add_filter('wp_redirect', $fang, 1);

    $rc     = new ReflectionClass(Plugin::class);
    $plugin = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    try {
        $plugin->screen()->handlePost();
    } catch (RuntimeException) {
    } finally {
        remove_filter('wp_redirect', $fang, 1);
        $_POST    = [];
        $_REQUEST = [];
    }

    return $gewandert;
}

function letzteMeldung(): string
{
    parse_str((string) parse_url((string) $GLOBALS['taxmod_letzte_adresse'], PHP_URL_QUERY), $teile);

    return rawurldecode((string) ($teile['taxmod_message'] ?? ''));
}

function speichern(int $nodeId, array $angaben): bool
{
    return abschicken([
        'do'            => 'put_setting',
        'id'            => (string) $nodeId,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $nodeId),
        ...$angaben,
    ]);
}

/** Die Wertspalte der Zeile zu dieser Einstellungskante — mit Sperre, Haken und Herkunft, wenn sie da sind. */
function wertspalte(string $markup, int $kante): string
{
    $wo = strpos($markup, 'name="taxmod_value[' . $kante . ']"');

    if ($wo === false) {
        return '';
    }

    preg_match_all('/<span class="taxmod-setting-locked(?: taxmod-setting-automatic)?">/', substr($markup, 0, $wo), $treffer, PREG_OFFSET_CAPTURE);
    $letzter = end($treffer[0]);
    $anfang  = $letzter === false ? false : (int) $letzter[1];
    $zelle   = strrpos(substr($markup, 0, $wo), 'taxmod-field-value');

    if ($anfang === false || ($zelle !== false && $anfang < $zelle)) {
        return substr($markup, $wo, 400);
    }

    $ende = strpos($markup, '</em></span>', $wo);

    return substr($markup, $anfang, ($ende === false ? $wo + 400 : $ende + 12) - $anfang);
}

/** @return array<int,string> Knoten-Id => Name der angebotenen Renderer in der Zeile `renderer`. */
function angebotDerZeile(string $markup, int $kante): array
{
    global $wpdb, $p;
    $hinter = preg_split('/name="taxmod_value\[' . $kante . '\]"/', $markup)[1] ?? '';
    preg_match_all('/<option value="([^"]*)"/', explode('</select>', $hinter)[0], $treffer);
    $aus = [];

    foreach ($treffer[1] ?? [] as $roh) {
        if ($roh === '') {
            continue;
        }

        $id       = (int) $roh;
        $aus[$id] = (string) $wpdb->get_var("SELECT name FROM {$p}nodes_named WHERE id = {$id}");
    }

    return $aus;
}

/** @return array<int,string> Knoten-Id => Beschriftung des Eintrags. */
function beschriftungenDerZeile(string $markup, int $kante): array
{
    $hinter = preg_split('/name="taxmod_value\[' . $kante . '\]"/', $markup)[1] ?? '';
    preg_match_all('/<option value="([^"]*)"[^>]*>([^<]*)</', explode('</select>', $hinter)[0], $treffer, PREG_SET_ORDER);
    $aus = [];

    foreach ($treffer as $eins) {
        if ($eins[1] !== '') {
            $aus[(int) $eins[1]] = html_entity_decode($eins[2], ENT_QUOTES, 'UTF-8');
        }
    }

    return $aus;
}

function gespeicherterRenderer(int $nodeId): string
{
    $nodes     = new WpdbNodeRepository();
    $relations = new WpdbRelationRepository();
    $fw        = new SeededFrameworkNodes($nodes, $relations, new WpdbChangelog(new SystemClock()));
    $model     = new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $fw);
    $node      = $nodes->find($nodeId);

    return $node === null ? '' : (string) (($model->forNode($node)[SettingKey::Renderer->value] ?? null)?->value->text ?? '');
}

/** @return array{0:string,1:string} Renderer-Name und Markup, mit denen ein Feld gezeichnet wird. */
function gezeichnet(Rendering $zeichner, int $relationId): array
{
    $relation = (new WpdbRelationRepository())->byId($relationId);

    if ($relation === null) {
        return ['', ''];
    }

    $felder = $zeichner->fieldsFor([$relation], [], Purpose::Edit, 'taxmod_value');

    return $felder === [] ? ['', ''] : [$felder[0]->rendererName, $felder[0]->result->markup];
}

/** Die Zeilen einer Tabelle unter einer Überschrift, je Zelle der Klartext (ein gewählter Eintrag zählt als Text). */
function tabelleUnter(string $html, string $ueberschrift, string $bisUeberschrift): array
{
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    if (! preg_match('/<h3\b[^>]*>' . preg_quote($ueberschrift, '/') . '</', $html, $t, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $von = $t[0][1];
    $bis = preg_match('/<h3\b[^>]*>' . preg_quote($bisUeberschrift, '/') . '</', substr($html, $von + 4), $u, PREG_OFFSET_CAPTURE)
        ? $von + 4 + $u[0][1]
        : false;
    $teil = substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);

    if (! preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $teil, $zeilen)) {
        return [];
    }

    $aus = [];

    foreach ($zeilen[1] as $zeile) {
        preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/s', $zeile, $zellen);
        $aus[] = array_map(
            static function (string $z): string {
                if (preg_match('/<option value="([^"]*)"[^>]*\bselected\b/', $z, $gewaehlt)) {
                    $z = $gewaehlt[1];
                }

                return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($z))));
            },
            $zellen[1]
        );
    }

    return $aus;
}

/** Das Vorschau-Band der Knotenseite. */
function vorschau(int $nodeId): string
{
    $html = seite($nodeId);
    $at   = strpos($html, 'taxmod-preview');

    if ($at === false) {
        return '';
    }

    $band = substr($html, $at);
    $end  = strpos($band, 'taxmod-page-block');

    return $end === false ? $band : substr($band, 0, $end);
}

/** Der Einstellungssatz eines Knotens (relation_id 0), notfalls angelegt. */
$satzVon = static function (int $knotenId) use ($nodes, $rows): int {
    foreach ($rows->ofNode($knotenId) as $vorhanden) {
        if ($vorhanden->recordType === RecordType::Settings && $vorhanden->relationId === 0) {
            return $vorhanden->id;
        }
    }

    return $rows->add(new NodeRecord(0, $knotenId, $nodes->byId($knotenId)->version, gmdate('Y-m-d H:i:s'), RecordType::Settings));
};

/** Die Einstellungskante mit diesem Namen an einem Träger, notfalls angelegt (Ziel: der Konstantenast). */
$kanteFuer = static function (int $traegerId, string $key) use ($relations, $framework): Relation {
    foreach ($relations->fieldRelationsOf([$traegerId]) as $eine) {
        if ($eine->kind === RelationKind::Setting && $eine->name === $key) {
            return $eine;
        }
    }

    return $relations->add(Relation::attribute(
        0,
        $traegerId,
        $framework->rootOf(Branch::Constants)->id,
        RelationKind::Setting,
        $key,
        $relations->nextFieldPositionUnder($traegerId)
    ));
};

/** Eine Angabe an einem Knoten (eigener Satz) oder an einer Stelle (Satz der Kante) setzen. */
$angabe = static function (Node|Relation $wer, string $key, TypedValue $wert) use ($satzVon, $kanteFuer, $data, $rows): void {
    $traegerId = $wer instanceof Node ? $wer->id : $wer->fromNodeId;
    $kante     = $kanteFuer($traegerId, $key);

    if ($wer instanceof Relation) {
        $data->putSettingAtUseSite($wer->id, $kante->id, $wert);

        return;
    }

    $satzId = $satzVon($traegerId);
    $rows->forgetValue($satzId, $kante->id, '');
    $rows->putValue(new RelationRecord($satzId, $kante->id, '', $wert));
};

$ohneAngabe = static function (Node|Relation $wer, string $key) use ($satzVon, $kanteFuer, $data, $rows): void {
    $traegerId = $wer instanceof Node ? $wer->id : $wer->fromNodeId;
    $kante     = $kanteFuer($traegerId, $key);

    if ($wer instanceof Relation) {
        $data->clearSettingAtUseSite($wer->id, $kante->id);

        return;
    }

    $rows->forgetValue($satzVon($traegerId), $kante->id, '');
};

$renderKante = $framework->settingRelationId(SettingKey::Renderer);
$modellAst   = $framework->rootOf(Branch::Model);
$wurzel      = $framework->root();

// ---------------------------------------------------------------------------------------------------

echo "== 0 · Das Gerüst: Renderer-Knoten und Kode, die Wurzelkanten, der Bestand ==\n";

$konverter  = ShippedConverters::registry();
$validators = ShippedValidators::registry();
$scaffold   = new RenderingScaffold($editor, $framework, $registry, $konverter, $validators);
$scaffold->import();

$heimat      = $framework->rootOf(Branch::Settings);
$unterHeimat = [];
foreach ($editor->childrenOf($heimat->id) as $child) {
    $unterHeimat[$child->id] = $child;
}
$behaelter = [];
foreach (RenderingScaffold::CONTAINERS as $name) {
    $id = (int) get_option(RenderingScaffold::optionForContainer($name), 0);
    check("der Behälter «{$name}» hängt unter Settings, seine Id ist notiert", $id > 0 && isset($unterHeimat[$id]) && $unterHeimat[$id]->name === $name, $id === 0 ? 'keine Option' : "Id {$id}");
    if ($id > 0 && isset($unterHeimat[$id])) {
        $behaelter[$name] = $unterHeimat[$id];
    }
}

$klasseVon = [
    'Renderer'  => static fn (string $n): ?string => $registry->classFor($n),
    'Converter' => static fn (string $n): ?string => $konverter->classFor($n),
    'Validator' => static fn (string $n): ?string => $validators->classFor($n),
];
$erwartet = [
    'Renderer'  => $registry->namesForNodes(),
    'Converter' => $konverter->namesForNodes(),
    'Validator' => $validators->namesForNodes(),
];
foreach ($erwartet as $behaelterName => $namen) {
    if (! isset($behaelter[$behaelterName])) {
        check($behaelterName . ': Behälter fehlt', false);
        continue;
    }
    $kinder = [];
    foreach ($nodes->subtreeOf($behaelter[$behaelterName]) as $child) {
        $kinder[$child->id] = $child;
    }
    $fehlend = [];
    foreach ($namen as $name) {
        $klasse = $klasseVon[$behaelterName]($name);
        $node   = $klasse === null ? null : $editor->nodeImplementing($klasse);
        if ($node === null || ! isset($kinder[$node->id])) {
            $fehlend[] = $name;
        }
    }
    check("{$behaelterName}: alle " . count($namen) . ' Namen des Kodes liegen als Knoten, über ihre Klasse gefunden', $fehlend === [], implode(', ', $fehlend));
    $ueberzaehlig = [];
    foreach ($nodes->subtreeOf($behaelter[$behaelterName]) as $child) {
        if (! in_array($child->name, $namen, true) && $editor->childrenOf($child->id) === []) {
            $ueberzaehlig[] = $child->name . ' (' . $child->id . ')';
        }
    }
    check("{$behaelterName}: kein Blatt, das der Kode nicht kennt", $ueberzaehlig === [], implode(', ', $ueberzaehlig));
}

$knotenNamen = $registry->namesForNodes();
$drinnen     = array_values(array_intersect(['tree', 'tree-node', 'labels', 'chooser-node', 'record', 'head', 'settings', 'choice', 'field-row'], $knotenNamen));
check('keiner der neun Oberflächen-Renderer wird gesät', $drinnen === [], implode(', ', $drinnen));
check('der Rückfall `plain` wird gesät', in_array(PlainRenderer::NAME, $knotenNamen, true));
check('ein zweiter Lauf des Gerüsts legt nichts an', $scaffold->import() === []);
check('der Renderer-Wähler steht in der Registratur, aber nicht in der Wahl', in_array(RendererChoiceRenderer::NAME, $registry->namesForSurfaces(), true) && ! in_array(RendererChoiceRenderer::NAME, $knotenNamen, true));

$interneKlassen = [];
foreach ($registry->namesForSurfaces() as $kennung) {
    $klasse = $registry->classFor($kennung);
    if ($klasse !== null) {
        $interneKlassen[$klasse] = $kennung;
    }
}
$internMitKnoten = [];
foreach ($nodes->byImplementations(array_keys($interneKlassen)) as $klasse => $einer) {
    $internMitKnoten[] = ($interneKlassen[$klasse] ?? $klasse) . ' => ' . $einer->name;
}
check('und kein interner Renderer hat einen Knoten', $internMitKnoten === [], implode(', ', $internMitKnoten));

$romanKlasse = $konverter->classFor('roman');
$romanZaehlen = static function () use ($editor, $behaelter): int {
    $wieviele = 0;
    foreach (isset($behaelter['Converter']) ? $editor->childrenOf($behaelter['Converter']->id) : [] as $child) {
        if ($child->name === 'roman') {
            $wieviele++;
        }
    }

    return $wieviele;
};
if (isset($behaelter['Converter']) && $romanKlasse !== null && ($echt = $editor->nodeImplementing($romanKlasse)) !== null) {
    $editor->setImplementedBy($echt->id, null);
    $scaffold->import();
    $wieder = $editor->nodeImplementing($romanKlasse);
    check('der Notnagel trägt eine verlorene Klassenangabe nach, am selben Knoten', $wieder !== null && $wieder->id === $echt->id);
    check('und `roman` liegt weiterhin genau einmal', $romanZaehlen() === 1, (string) $romanZaehlen());
    $muell = $framework->trash();
    $editor->setImplementedBy($echt->id, null);
    $editor->setImplementedBy($muell->id, $romanKlasse);
    $scaffold->import();
    $wieder = $editor->find($echt->id);
    check('eine Angabe an einem Knoten im Müll wird nicht geglaubt — sie steht wieder am echten', $wieder !== null && $wieder->implementedBy === $romanKlasse);
    check('und es wurde kein zweiter `roman` angelegt', $romanZaehlen() === 1, (string) $romanZaehlen());
    $editor->setImplementedBy($muell->id, null);
    $editor->setImplementedBy($echt->id, $romanKlasse);
}

check('die Einstellungskante `renderer` ist an der Wurzel aufgeschrieben', $renderKante !== 0, (string) $renderKante);
check('und die innere Wertkante gibt es nicht mehr', $framework->settingValueRelationId(SettingKey::Renderer) === 0);
foreach ([['nodes', 'settings_record_id'], ['relations', 'settings_record_id'], ['relations', 'target_settings_record_id']] as [$tabelle, $spalte]) {
    check("die Spalte «{$tabelle}.{$spalte}» ist weg und bleibt weg", $wpdb->get_var('SHOW COLUMNS FROM ' . Schema::table($tabelle) . " LIKE '{$spalte}'") === null);
}

$seeded = [];
foreach (SimpleType::cases() as $typ) {
    $id = $types->nodeId($typ);
    if ($id !== null) {
        $seeded[$typ->value] = $nodes->byId($id);
    }
}
foreach (['int', 'decimal', 'text', 'bool', 'email', 'datetime', 'color'] as $name) {
    check("der einfache Typ «{$name}» steht im Baum", isset($seeded[$name]));
}
if ($renderKante === 0 || count(array_intersect_key($seeded, array_flip(['int', 'decimal', 'text', 'bool', 'email', 'datetime', 'color']))) < 7) {
    echo "\n{$passed} ok, {$failed} failed\n";
    exit(1);
}

$anStelle = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v JOIN ' . Schema::table('node_records') . ' s ON s.id = v.node_record_id WHERE s.relation_id > 0 AND v.relation_id = %d', $renderKante));
check('im Bestand hängt keine Renderer-Wahl an einer Verwendungsstelle (D-643)', $anStelle === 0, (string) $anStelle);
$insLeere = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' v LEFT JOIN ' . Schema::table('node_records') . " s ON s.id = v.value_ref WHERE v.relation_id = %d AND v.value_ref_kind = 'record' AND s.id IS NULL", $renderKante));
check('kein Träger zeigt ins Leere', $insLeere === 0, (string) $insLeere);

$bestandZeichnen = $zeichner();
$traegerZahl = 0;
$mitNamen    = 0;
$unerlaubt   = [];
$merkmal     = ['slider' => 'type="range"', 'toggle' => 'taxmod-toggle', 'spinner' => 'type="number"', 'field' => 'type="text"', 'checkbox' => 'type="checkbox"'];
$daneben     = [];
foreach ($wpdb->get_results($wpdb->prepare('SELECT DISTINCT halter.node_id FROM ' . Schema::table('node_records') . ' halter JOIN ' . Schema::table('relation_records') . " wert ON wert.node_record_id = halter.id WHERE wert.relation_id = %d AND wert.value_ref_kind = 'node'", $renderKante), ARRAY_A) ?: [] as $zeile) {
    $knoten = $nodes->find((int) $zeile['node_id']);
    if ($knoten === null) {
        continue;
    }
    ++$traegerZahl;
    $name = $bestandZeichnen->rendererNameFor($knoten);
    if ($name === null) {
        continue;
    }
    ++$mitNamen;
    $erlaubt = array_map(static fn ($r): string => $r->name(), $bestandZeichnen->choicesForNode($knoten));
    if (! in_array($name, $erlaubt, true)) {
        $unerlaubt[] = $knoten->name . ' → ' . $name;
    }
    if (isset($merkmal[$name]) && ($zeichnung = $bestandZeichnen->valueOfType($knoten, Purpose::Edit)) !== null && ! str_contains($zeichnung->markup, $merkmal[$name])) {
        $daneben[] = "{$knoten->name}: sagt «{$name}», zeichnet ohne «{$merkmal[$name]}»";
    }
}
check('jeder Träger einer Wahl im Bestand löst zu einem Renderer auf', $traegerZahl > 0 && $traegerZahl === $mitNamen, ($traegerZahl - $mitNamen) . ' von ' . $traegerZahl);
check('keine gespeicherte Wahl steht ausserhalb der zulässigen Menge', $unerlaubt === [], implode(' · ', array_slice($unerlaubt, 0, 6)));
check('und die Zeichnung trägt das Merkmal des gesetzten Renderers', $daneben === [], implode(' · ', array_slice($daneben, 0, 4)));

/** Kanten in den Einstellungsast, die keine Einstellungskanten sind. */
$abweichungen = static function () use ($wpdb, $nodes, $framework): array {
    $treffer = [];
    foreach ($wpdb->get_results('SELECT id, kind, to_node_id FROM ' . Schema::table('relations_named')) as $zeile) {
        $ziel = $nodes->find((int) $zeile->to_node_id);
        if ($ziel instanceof Node && $framework->branchOf($ziel) === Branch::Settings && $zeile->kind !== RelationKind::Setting->value) {
            $treffer[] = (int) $zeile->id;
        }
    }

    return $treffer;
};
check('jede Kante in den Einstellungsast ist eine Einstellungskante', $abweichungen() === [], count($abweichungen()) . ' Abweichungen');

$astUnten = array_values(array_diff($nodes->subtreeIds($heimat->id), [$heimat->id]));
$sorten   = $nodes->resolvedFieldTypes($astUnten);
$fehlend  = [];
$tiefe    = substr_count($heimat->path, '.') + 2;
foreach ($nodes->byIds($astUnten) as $einer) {
    if (($sorten[$einer->id] ?? null) === FieldType::Setting) {
        continue;
    }
    if (substr_count($einer->path, '.') + 1 === $tiefe) {
        $hinein = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('relations') . " WHERE to_node_id = %d AND kind <> 'inheritance'", $einer->id));
        $hinaus = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d', $einer->id));
        if ($hinein === 0 && $hinaus === 0) {
            continue;
        }
    }
    $fehlend[] = $einer->name . " ({$einer->id})";
}
check('kein Knoten im Einstellungsast, den die Kante nicht als Einstellung ausweist', $fehlend === [], implode('; ', $fehlend));

// ---------------------------------------------------------------------------------------------------

echo "\n== 1 · Die Wiese: ein Modell, sieben Felder, ein eigener Zahltyp ==\n";

$modellKnoten = $editor->createNode('__es Modell', $modellAst->id);
$count  = $editor->addField($modellKnoten->id, $seeded['int']->id, '__es count');
$weight = $editor->addField($modellKnoten->id, $seeded['decimal']->id, '__es weight');
$label  = $editor->addField($modellKnoten->id, $seeded['text']->id, '__es label');
$stock  = $editor->addField($modellKnoten->id, $seeded['bool']->id, '__es in stock');
$mail   = $editor->addField($modellKnoten->id, $seeded['email']->id, '__es contact');
$when   = $editor->addField($modellKnoten->id, $seeded['datetime']->id, '__es checked');
$colour = $editor->addField($modellKnoten->id, $seeded['color']->id, '__es body colour');
$every  = [$count, $weight, $label, $stock, $mail, $when, $colour];

$zahl   = $editor->createNode('__es Zahl', $seeded['int']->id);
$schmal = $editor->createNode('__es Zahl schmal', $zahl->id);
$eins   = $editor->addField($modellKnoten->id, $zahl->id, '__es erste Zahl');
$zwei   = $editor->addField($modellKnoten->id, $zahl->id, '__es zweite Zahl');
$tief   = $editor->addField($modellKnoten->id, $schmal->id, '__es tiefe Zahl');
$kind   = $editor->createNode('__es Kind', $modellKnoten->id);

$rendering = $zeichner();
$fields    = [];
foreach ($rendering->fieldsFor($every, [], Purpose::Edit, 'taxmod_value') as $field) {
    $fields[$field->relation->id] = $field;
}
check('sieben Felder gezeichnet', count($fields) === 7, (string) count($fields));
check('der Typ-Standard für int ist das einfache Feld', $registry->defaultFor(SimpleType::Int, Purpose::Edit)->name() === FieldRenderer::NAME);
$chainSays = ($rendering->settingsForUseSites([$count])[$count->id][SettingKey::Renderer->value] ?? null)?->value->text;
check('und ein int wird gezeichnet, wie seine Kette es sagt', $fields[$count->id]->rendererName === ($chainSays ?? FieldRenderer::NAME), $fields[$count->id]->rendererName);
check('ein bool bekommt den Schiebeschalter', $fields[$stock->id]->rendererName === ToggleRenderer::NAME, $fields[$stock->id]->rendererName);
check('ein datetime den Datumsrenderer', $fields[$when->id]->rendererName === DateTimeRenderer::NAME, $fields[$when->id]->rendererName);
check('und keines fällt auf den Rückfall', count(array_filter($fields, static fn ($f): bool => $f->hasNoRenderer())) === 0);
check('der Schalter ist ein Kästchen, das ungehakt «falsch» schickt', str_contains($fields[$stock->id]->result->markup, 'type="checkbox"') && str_contains($fields[$stock->id]->result->markup, 'type="hidden"'));
check('das Datum ist ein Datumsfeld', str_contains($fields[$when->id]->result->markup, 'type="datetime-local"'));
check('die Adresse ein Adressfeld, die Farbe ein Wähler', str_contains($fields[$mail->id]->result->markup, 'type="email"') && str_contains($fields[$colour->id]->result->markup, 'type="color"'));
check('jedes Feld ist über seine Kante benannt, nie über die Stelle', str_contains($fields[$label->id]->result->markup, 'name="taxmod_value[' . $label->id . ']"'));
$intMarkup = $fields[$count->id]->result->markup;
check('ein Zahlfeld bietet keine Buchstaben an, die es dann verweigert', str_contains($intMarkup, 'pattern="' . SimpleType::Int->pattern() . '"') || (str_contains($intMarkup, 'type="number"') && str_contains($intMarkup, 'step=')) || (str_contains($intMarkup, 'type="range"') && str_contains($intMarkup, 'step=')));
check('und ein Textfeld bekommt kein Muster, das es nichts angeht', ! str_contains($fields[$label->id]->result->markup, 'pattern'));

$description = $editor->createNode('__es Description', $seeded['text']->id);
$notes       = $editor->addField($modellKnoten->id, $description->id, '__es notes');
$sub         = $rendering->fieldsFor([$notes], [], Purpose::Edit, 'taxmod_value')[0];
check('ein eigener Untertyp ist noch sein Typ — und bekommt dessen Renderer', $sub->type === SimpleType::Text && $sub->rendererName === FieldRenderer::NAME, ($sub->type?->value ?? 'null') . ' / ' . $sub->rendererName);

// ---------------------------------------------------------------------------------------------------

echo "\n== 2 · Erklären: eine Einstellungskante, die Art über die Maske ==\n";

$hoechstens = $editor->createNode('__es hoechstens', $heimat->id);
$markup     = seite($zahl->id);
check('die Seite zeichnet einen Wähler für die Kantenart', (bool) preg_match('/<select[^>]*name="relation_kind"/', $markup));
preg_match('/<select[^>]*name="relation_kind".*?<\/select>/s', $markup, $waehler);
$angeboten = preg_match_all('/<option value="([^"]*)"/', $waehler[0] ?? '', $arten);
check('er bietet genau die drei Arten an', $angeboten === count(RelationKind::cases()) && array_values(array_diff(array_map(static fn (RelationKind $k): string => $k->value, RelationKind::cases()), $arten[1])) === [], implode(',', $arten[1]));
$vorWaehler = substr($markup, 0, (int) strpos($markup, 'name="relation_kind"'));
check('und steckt im selben Formular wie der Anlegen-Knopf', substr_count($vorWaehler, '<form') - substr_count($vorWaehler, '</form>') === 1);

$lief = abschicken([
    'do'            => 'add_field',
    'id'            => (string) $zahl->id,
    'field_target'  => (string) $hoechstens->id,
    'name'          => '__es_hoechstens',
    'relation_kind' => 'setting',
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $zahl->id),
]);
check('der Akt «Feld anlegen» läuft durch', $lief);
$nurAmZiel = null;
foreach ($relations->fieldRelationsOf([$zahl->id]) as $eine) {
    if ($eine->name === '__es_hoechstens') {
        $nurAmZiel = $eine;
    }
}
check('die Maske hat eine Einstellungskante angelegt, mit der angegebenen Art', $nurAmZiel !== null && $nurAmZiel->kind === RelationKind::Setting, $nurAmZiel?->kind->value ?? 'keine Kante');
check('eine Einstellung nimmt ihren Datensatz mit (D-526, D-639)', $nurAmZiel !== null && $nurAmZiel->deletesRecordWithOwner());

$falsch = $editor->addField($modellKnoten->id, $hoechstens->id, '__es_falsch', RelationKind::Aggregation);
check('eine Kante in den Einstellungsast, die keine Einstellungskante ist, fällt der Regel auf', in_array($falsch->id, $abweichungen(), true));
$editor->markAsSetting($modellKnoten->id, $falsch->id, true);
check('dieselbe Kante als Einstellungskante geht durch', ! in_array($falsch->id, $abweichungen(), true));
$editor->removeField($modellKnoten->id, $falsch->id);

$felder = tabelleUnter(seite($zahl->id), 'Fields', 'Settings');
$einstellungenUnterFields = [];
foreach ($felder as $zeile) {
    if (($zeile[2] ?? '') === 'setting') {
        $einstellungenUnterFields[] = $zeile[0] ?? '?';
    }
}
check('keine Einstellungskante steht unter «Fields»', $einstellungenUnterFields === [], implode(', ', $einstellungenUnterFields));
$seiteZahl = preg_replace('/<dialog\b.*?<\/dialog>/s', '', seite($zahl->id)) ?? '';
$vonS = strpos($seiteZahl, '>Settings<');
$bisS = $vonS === false ? false : strpos($seiteZahl, '>Preview<', $vonS);
$settingsBlock = $vonS === false ? '' : substr($seiteZahl, $vonS, ($bisS === false ? strlen($seiteZahl) : $bisS) - $vonS);
$vorS = $vonS === false ? '' : substr($seiteZahl, 0, $vonS);
check('und die eigene steht unter «Settings», nicht davor', str_contains($settingsBlock, '__es_hoechstens') && ! str_contains($vorS, '__es_hoechstens'));

$markup = seite($modellKnoten->id);
$name   = 'taxmod_field_setting[' . $count->id . '][kind]';
check('die eigene Feldzeile zeigt die Art als Auswahlfeld, mit drei Werten', str_contains($markup, 'name="' . $name . '"') && substr_count(explode('</select>', explode('name="' . $name . '"', $markup)[1] ?? '')[0], '<option') === 3);
check('und `composition` steht als gewählt', (bool) preg_match('/name="' . preg_quote($name, '/') . '"[^>]*>.*?<option value="composition"[^>]*selected/s', $markup));
$geerbt = seite($kind->id);
check('die geerbte Zeile am Kind zeigt die Art als Wort', ! str_contains($geerbt, 'name="' . $name . '"') && str_contains($geerbt, '<td class="taxmod-field-kind"><code>composition</code></td>'));

if ($nurAmZiel !== null) {
    $satz       = $data->create($zahl->id, RecordType::User);
    $verweigert = false;
    try {
        $data->put($satz->id, $nurAmZiel->id, TypedValue::ofInt(99));
    } catch (NotYetStorable) {
        $verweigert = true;
    }
    check('eine Einstellungskante nimmt keinen Benutzerwert an', $verweigert);
    $data->removeRecord($satz->id);

    $stelle2 = $editor->addField($modellKnoten->id, $zahl->id, '__es stelle');
    $amBesitzer = false;
    foreach ($relations->fieldRelationsOf($framework->inheritanceOwnersOf($nodes->byId($modellKnoten->id))) as $kante) {
        if ($kante->id === $nurAmZiel->id) {
            $amBesitzer = true;
        }
    }
    check('die Einstellungskante steht nicht an der Kette des Besitzers', ! $amBesitzer);
    $gefunden = $data->settingRelationAtUseSite($stelle2, '__es_hoechstens');
    check('der Schreiber findet sie trotzdem — an der Kette des Ziels (D-611)', $gefunden !== null && $gefunden->id === $nurAmZiel->id);
    $ihre = $modell()->declaredSettingKeys($nurAmZiel);
    check('eine Einstellungskante bietet sich selbst nicht an', ! in_array('__es_hoechstens', $ihre, true), implode(', ', $ihre));
    check('eine Stelle, deren Ziel sie erklärt, bietet sie an (D-668)', in_array('__es_hoechstens', $modell()->declaredSettingKeys($stelle2), true));
    $gezeichnetKeys = array_map(static fn ($e) => $e->key, $zeichner()->settingsFor($stelle2, []));
    check('der Einstellungsbereich einer Kante zeigt «wie oft» (D-351) und keinen Renderer (D-643)', in_array(SettingKey::Multiplicity->value, $gezeichnetKeys, true) && ! in_array(SettingKey::Renderer->value, $gezeichnetKeys, true), implode(', ', $gezeichnetKeys));
    if ($gefunden !== null) {
        $anDerStelle2 = static function () use ($modell, $stelle2): ?int {
            $a = $modell()->forUseSite($stelle2)['__es_hoechstens'] ?? null;

            return $a === null || $a->setHere !== true ? null : $a->value->int;
        };
        $data->putSettingAtUseSite($stelle2->id, $gefunden->id, TypedValue::ofInt(120));
        check('geschrieben, und der Leser findet den Wert an der Stelle', $anDerStelle2() === 120, (string) $anDerStelle2());
        check('und zwar im Satz der Verwendungsstelle', (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' w JOIN ' . Schema::table('node_records') . ' r ON r.id = w.node_record_id WHERE r.relation_id = %d AND w.relation_id = %d', $stelle2->id, $nurAmZiel->id)) === 1);
        $data->clearSettingAtUseSite($stelle2->id, $gefunden->id);
        check('herausgenommen, und die Stelle sagt nichts mehr', $anDerStelle2() === null);
    }
}

// Selbstvererbung (D-607, D-608): ein Vorfahr erklärt eine Einstellung, deren Ziel sein Kind ist.
$vorfahr     = $editor->createNode('__es Vorfahr', $modellAst->id);
$ziel        = $editor->createNode('__es ziel', $vorfahr->id);
$geschwister = $editor->createNode('__es Geschwister', $vorfahr->id);
$selbstKante = $editor->addField($vorfahr->id, $ziel->id, '__es_selbst');
$editor->markAsSetting($vorfahr->id, $selbstKante->id, true);
$data->putSettingAt($vorfahr->id, $selbstKante->id, 0, TypedValue::ofText('__es_wert'));
check('der Zielknoten erbt seine eigene Einstellungskante nicht (D-607)', ! isset($modell()->forNode($nodes->byId($ziel->id))['__es_selbst']));
check('ein Geschwister des Ziels erbt sie ebenfalls nicht', ! isset($modell()->forNode($nodes->byId($geschwister->id))['__es_selbst']));
$fremder = $editor->createNode('__es Fremder', $ziel->id);
check('ein Knoten ausserhalb der Geschwisterreihe erbt sie weiterhin', isset($modell()->forNode($nodes->byId($fremder->id))['__es_selbst']));
check('der erklärende Vorfahr behält seine Angabe', isset($modell()->forNode($nodes->byId($vorfahr->id))['__es_selbst']));
$gelesen = $relations->byId($selbstKante->id);
check('die Regel greift am Ziel, nicht am Geschwister, nicht am Erklärer', ModelValues::inheritanceBlocked($gelesen, $ziel->id) && ! ModelValues::inheritanceBlocked($gelesen, $geschwister->id) && ! ModelValues::inheritanceBlocked($gelesen, $vorfahr->id));
$zeilenRenderer = new FieldRowRenderer();
$umgebung = static fn (bool $locked): Surroundings => new Surroundings(refersTo: '__es ziel', sections: [FieldRowRenderer::VALUE => new Section('', '<input name="x">')], locked: $locked);
$gesperrt = $zeilenRenderer->render($gelesen, new RenderContext(purpose: Purpose::Edit, value: TypedValue::nothing(), editable: false, surroundings: $umgebung(true)))->markup;
$offen    = $zeilenRenderer->render($gelesen, new RenderContext(purpose: Purpose::Edit, value: TypedValue::nothing(), editable: false, surroundings: $umgebung(false)))->markup;
check('die gesperrte Zeile bleibt, ist gekennzeichnet, nennt den Grund und trägt kein Eingabefeld (D-608)', str_contains($gesperrt, '<tr') && str_contains($gesperrt, 'taxmod-field-locked') && str_contains($gesperrt, 'title="') && ! str_contains($gesperrt, '<input name="x">'));
check('eine ungesperrte Zeile trägt ihres weiterhin, ohne Kennzeichen', str_contains($offen, '<input name="x">') && ! str_contains($offen, 'taxmod-field-locked'));

// ---------------------------------------------------------------------------------------------------

echo "\n== 3 · Wählen: der Renderer-Kasten, speichern, frisch lesen, zeichnen ==\n";

$markup = seite($zahl->id);
check('die Zeile `renderer` zeichnet einen Wähler, und er hängt am Seitenformular', (bool) preg_match('/<select name="taxmod_value\[' . $renderKante . '\]" form="taxmod-page-' . $zahl->id . '"/', $markup));
check('und der alte eigene Renderer-Block kommt nicht mehr vor', ! str_contains($markup, 'taxmod_setting[renderer]'));
$angebot = angebotDerZeile($markup, $renderKante);
check('er bietet einem Zahltyp genau field, spinner, slider an', count($angebot) === 3 && array_values(array_diff(array_values($angebot), ['field', 'spinner', 'slider'])) === [], implode(',', $angebot));
$offeredForInt = array_map(static fn ($r): string => $r->name(), $rendering->choicesForNode($nodes->byId($seeded['int']->id)));
sort($offeredForInt);
check('der Kern sagt dasselbe für `Integer`', $offeredForInt === ['field', 'slider', 'spinner'], implode(', ', $offeredForInt));
$offeredForThing = array_map(static fn ($r): string => $r->name(), $rendering->choicesForNode($nodes->byId($modellKnoten->id)));
sort($offeredForThing);
check('einem Ding unter Model werden nur die Behälter angeboten: compact, form, table', $offeredForThing === ['compact', 'form', 'table'], implode(', ', $offeredForThing));
check('einem bool kein spinner', ! in_array('spinner', array_map(static fn ($r): string => $r->name(), $rendering->choicesForNode($nodes->byId($seeded['bool']->id))), true));
check('was nicht angeboten wird, ist noch nicht verboten — `checkbox` ist bekannt', $rendering->knowsRenderer('checkbox'));
check('ein Name, auf den nichts antwortet, wird verweigert, und der Rückfall ist nicht wählbar', ! $rendering->knowsRenderer('__es no such renderer') && ! $rendering->knowsRenderer(PlainRenderer::NAME));
$unterKonstanten = $editor->createNode('__es unter constants', $framework->rootOf(Branch::Constants)->id);
check('unter `constants` steht `reference` zur Wahl', in_array('reference', array_values(angebotDerZeile(seite($unterKonstanten->id), $renderKante)), true));
$editor->createNode('__es ein Kind', $unterKonstanten->id);
$namenMitKind = array_values(angebotDerZeile(seite($unterKonstanten->id), $renderKante));
check('mit einem Kind stehen die Wähler zur Wahl', array_values(array_diff(['chooser-dialog', 'chooser-inline'], $namenMitKind)) === [], implode(',', $namenMitKind));
$formKnoten = $editor->nodeImplementing(FormRenderer::class);
if ($formKnoten !== null) {
    $blind = $editor->createNode('__es ohne registratur', $formKnoten->parentId() ?? 0);
    $namen = angebotDerZeile(seite($modellKnoten->id), $renderKante);
    check('ein Knoten neben den Renderern ohne Klasse wird nicht angeboten, der Zwischenknoten auch nicht', ! isset($namen[$blind->id]) && ! in_array('render with label', array_values($namen), true));
}
$gezeichnetTexte = beschriftungenDerZeile($markup, $renderKante);
$erwarteteTexte  = $labels->forNodes(array_values($nodes->byIds(array_keys($gezeichnetTexte))), SeededRole::Select, SettingsScreen::neutralLocale());
$abweichend = [];
foreach ($gezeichnetTexte as $id => $text) {
    if (($erwarteteTexte[$id] ?? null) !== $text) {
        $abweichend[] = $id;
    }
}
check('jeder Eintrag zeigt seine `select`-Beschriftung', $gezeichnetTexte !== [] && $abweichend === [], implode(',', $abweichend));

$ids    = array_keys($angebot);
$vorher = gespeicherterRenderer($zahl->id);
$wahlId = $angebot[$ids[0]] === $vorher ? $ids[1] : $ids[0];
$wahl   = $angebot[$wahlId];
check('der Akt «Einstellung speichern» läuft durch', speichern($zahl->id, ['taxmod_value' => [(string) $renderKante => (string) $wahlId], 'taxmod_value_override' => [(string) $renderKante => '1']]));
check('die Wahl steht nach dem Speichern da', gespeicherterRenderer($zahl->id) === $wahl, gespeicherterRenderer($zahl->id));
$verweis = (int) $wpdb->get_var("SELECT v.value_ref FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id WHERE r.node_id = {$zahl->id} AND r.record_type = 'settings' AND v.relation_id = {$renderKante} AND v.value_ref_kind = 'node'");
check('sie hängt als Verweis auf den Renderer-Knoten an der Kante, ohne Hülle', $verweis !== 0 && (string) $wpdb->get_var("SELECT name FROM {$p}nodes_named WHERE id = {$verweis}") === $wahl);
check('und die Maske zeigt danach, was dasteht', (bool) preg_match('/<option value="' . $wahlId . '" selected/', seite($zahl->id)));
$zweiteId = $ids[0] === $wahlId ? $ids[1] : $ids[0];
speichern($zahl->id, ['taxmod_value' => [(string) $renderKante => (string) $zweiteId], 'taxmod_value_override' => [(string) $renderKante => '1']]);
check('eine zweite Wahl gewinnt gegen die erste', gespeicherterRenderer($zahl->id) === $angebot[$zweiteId]);
check('und der Knoten hält genau eine Wahl — ersetzt, nicht dazu', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records r JOIN {$p}relation_records v ON v.node_record_id = r.id WHERE r.node_id = {$zahl->id} AND r.record_type = 'settings' AND v.relation_id = {$renderKante} AND v.value_ref_kind = 'node'") === 1);
$satzVorher = (int) $wpdb->get_var("SELECT r.id FROM {$p}node_records r WHERE r.node_id = {$zahl->id} AND r.record_type = 'settings' AND r.relation_id = 0");
speichern($zahl->id, ['taxmod_value' => [(string) $renderKante => (string) $zweiteId], 'taxmod_value_override' => [(string) $renderKante => '1']]);
check('zweimal dieselbe Wahl, derselbe Satz', $satzVorher !== 0 && (int) $wpdb->get_var("SELECT r.id FROM {$p}node_records r WHERE r.node_id = {$zahl->id} AND r.record_type = 'settings' AND r.relation_id = 0") === $satzVorher);

$markups = [];
$fehler  = [];
foreach ($angebot as $id => $rname) {
    speichern($zahl->id, ['taxmod_value' => [(string) $renderKante => (string) $id], 'taxmod_value_override' => [(string) $renderKante => '1']]);
    [$gezeichneterName, $m] = gezeichnet($zeichner(), $eins->id);
    if ($gezeichneterName !== $rname) {
        $fehler[] = "gewählt «{$rname}», gezeichnet «{$gezeichneterName}»";
    }
    $markups[$rname] = $m;
}
check('jede Wahl zeichnet das Feld danach mit dem gewählten Renderer', $fehler === [] && $markups !== [], implode('; ', $fehler));
check('keine fällt auf den Rückfall, und verschiedene Wahlen zeichnen verschieden', ! array_filter($markups, static fn (string $m): bool => str_contains($m, 'taxmod-no-renderer')) && count(array_unique(array_values($markups))) === count($markups));

// Die Stufen der Kette (D-602): Wahl am Typ erreicht jede Verwendung, näher schlägt ferner.
$gezeichnetFuer = static function (array $kanten) use ($zeichner): array {
    $aus = [];
    foreach ($zeichner()->fieldsFor($kanten, [], Purpose::Edit, 'taxmod_value') as $feld) {
        $aus[$feld->relation->id] = $feld->rendererName;
    }

    return $aus;
};
$spinnerId = (int) array_search(SpinnerRenderer::NAME, $angebot, true);
$fieldId   = (int) array_search(FieldRenderer::NAME, $angebot, true);
speichern($zahl->id, ['taxmod_value' => [(string) $renderKante => (string) $spinnerId], 'taxmod_value_override' => [(string) $renderKante => '1']]);
$jetzt = $gezeichnetFuer([$eins, $zwei, $tief]);
check('Stufe 2: jede Verwendung sieht die Wahl am Zielknoten — einmal gesetzt, nicht je Verwendung', $jetzt[$eins->id] === SpinnerRenderer::NAME && $jetzt[$zwei->id] === SpinnerRenderer::NAME, implode(',', $jetzt));
check('Stufe 3: ein Nachfahre des Typs erbt sie', $jetzt[$tief->id] === SpinnerRenderer::NAME, $jetzt[$tief->id]);
speichern($schmal->id, ['taxmod_value' => [(string) $renderKante => (string) $fieldId], 'taxmod_value_override' => [(string) $renderKante => '1']]);
$jetzt = $gezeichnetFuer([$eins, $tief]);
check('näher schlägt ferner, und nur dort', $jetzt[$tief->id] === FieldRenderer::NAME && $jetzt[$eins->id] === SpinnerRenderer::NAME, implode(',', $jetzt));
speichern($schmal->id, ['taxmod_value' => [(string) $renderKante => ''], 'taxmod_value_override' => [(string) $renderKante => '1']]);
$jetzt = $gezeichnetFuer([$tief]);
check('weggenommen fällt sie auf die Kette darüber zurück', $jetzt[$tief->id] === SpinnerRenderer::NAME, $jetzt[$tief->id]);

// ---------------------------------------------------------------------------------------------------

echo "\n== 4 · Erben: gesperrt, in Worten, mit Haken — und überschreiben (D-687, D-689) ==\n";

$compact = $editor->nodeImplementing(CompactRenderer::class);
$eltern  = $editor->createNode('__es Eltern', $modellAst->id);
$erbe    = $editor->createNode('__es Erbe', $eltern->id);
speichern($eltern->id, ['taxmod_value' => [(string) $renderKante => (string) $compact->id]]);
check('die Eltern tragen `compact` als eigenen Wert', isset($data->settingValuesOf($eltern->id, [$renderKante])[$renderKante]));
$zeile = wertspalte(seite($erbe->id), $renderKante);
check('das Kind zeigt die Zeile gesperrt, nicht automatisch, mit dem Haken «hier überschreibe ich», ungesetzt', str_contains($zeile, 'taxmod-setting-locked') && ! str_contains($zeile, 'taxmod-setting-automatic') && str_contains($zeile, 'name="taxmod_value_override[' . $renderKante . ']"') && ! str_contains($zeile, 'value="1" checked'), substr($zeile, 0, 160));
check('die Herkunft steht in Worten, ohne Pfeil', str_contains($zeile, 'inherited from __es Eltern') && ! str_contains($zeile, '↑'), substr(strip_tags($zeile), -120));
check('das Steuerelement zeigt `compact` als gewählt', (bool) preg_match('/<option value="' . $compact->id . '"[^>]*selected/', $zeile));
speichern($erbe->id, ['taxmod_value' => [(string) $renderKante => (string) $compact->id]]);
check('ohne Haken schreibt das Speichern die geerbte Zeile nicht', ! isset($data->settingValuesOf($erbe->id, [$renderKante])[$renderKante]));
speichern($erbe->id, ['taxmod_value' => [(string) $renderKante => (string) $compact->id], 'taxmod_value_override' => [(string) $renderKante => '1']]);
check('mit Haken ist der Wert ein eigener', isset($data->settingValuesOf($erbe->id, [$renderKante])[$renderKante]));
$zeile = wertspalte(seite($erbe->id), $renderKante);
check('und die Zeile ist nicht mehr gesperrt und trägt keinen Haken mehr', ! str_contains($zeile, 'taxmod-setting-locked') && ! str_contains($zeile, 'taxmod_value_override'));

// Die Einstellungen des Renderers stehen auch, wo er nur geerbt ist.
$vater        = $editor->createNode('__es Vater', $modellAst->id);
$angebotVater = angebotDerZeile(seite($vater->id), $renderKante);
$innereKanten = static function (int $rendererKnotenId) use ($nodes, $relations, $framework): array {
    $knoten = $nodes->find($rendererKnotenId);
    $aus    = [];
    foreach ($knoten === null ? [] : $relations->fieldRelationsOf($framework->inheritanceOwnersOf($knoten)) as $e) {
        $aus[$e->name] = $e->id;
    }

    return $aus;
};
$vaterWahlId = 0;
$vaterKanten = [];
foreach (array_keys($angebotVater) as $einer) {
    $kanten = $innereKanten($einer);
    if (isset($kanten['with_label'], $kanten['label_role'], $kanten['converter'])) {
        $vaterWahlId = $einer;
        $vaterKanten = $kanten;
        break;
    }
}
check('ein angebotener Renderer trägt converter, label_role und with_label', $vaterWahlId !== 0, implode(',', $angebotVater));
if ($vaterWahlId !== 0) {
    speichern($vater->id, ['taxmod_value' => [(string) $renderKante => (string) $vaterWahlId], 'taxmod_value_override' => [(string) $renderKante => '1']]);
    speichern($vater->id, ['taxmod_value' => [(string) $renderKante => [(string) $vaterKanten['with_label'] => '1']]]);
    $eigenerWert = static fn (int $nodeId, int $innen) => $wpdb->get_var("SELECT v.value_int FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id WHERE r.node_id = {$nodeId} AND r.record_type = 'settings' AND v.relation_id = {$innen}");
    check('der Vater trägt seine Wahl, und `with_label` steht auf «an»', gespeicherterRenderer($vater->id) === $angebotVater[$vaterWahlId] && (int) $eigenerWert($vater->id, (int) $vaterKanten['with_label']) === 1);
    $sohn = $editor->createNode('__es Sohn', $vater->id);
    check('das Kind erbt den Renderer des Vaters', gespeicherterRenderer($sohn->id) === $angebotVater[$vaterWahlId]);
    $markupSohn = seite($sohn->id, (string) $renderKante);
    $alleDa = true;
    foreach (['converter', 'label_role', 'with_label'] as $welche) {
        $alleDa = $alleDa && str_contains($markupSohn, 'name="taxmod_value[' . $renderKante . '][' . $vaterKanten[$welche] . ']"');
    }
    check('die Einstellungen des geerbten Renderers stehen im Markup des Kindes, als geerbt gekennzeichnet', $alleDa && str_contains($markupSohn, 'taxmod-inherited'));
    check('und kein `taxmod_part` daneben, weil es keinen eigenen Satz gibt', ! preg_match('/name="taxmod_part\[\d+\]\[' . $vaterKanten['with_label'] . '\]"/', $markupSohn));
    check('das Ansehen hat keinen Teil angelegt', $eigenerWert($sohn->id, (int) $vaterKanten['with_label']) === null);
    speichern($sohn->id, ['taxmod_value' => [(string) $renderKante => [(string) $vaterKanten['with_label'] => '1']]]);
    check('ein Speichern ohne Änderung legt keinen Teil an', $eigenerWert($sohn->id, (int) $vaterKanten['with_label']) === null);
    speichern($sohn->id, ['taxmod_value' => [(string) $renderKante => [(string) $vaterKanten['with_label'] => '0']]]);
    $sohnAus = $eigenerWert($sohn->id, (int) $vaterKanten['with_label']);
    check('die Änderung steht am Knoten selbst, der Renderer bleibt der geerbte, der Vater bleibt auf «an»', $sohnAus !== null && (int) $sohnAus === 0 && gespeicherterRenderer($sohn->id) === $angebotVater[$vaterWahlId] && (int) $eigenerWert($vater->id, (int) $vaterKanten['with_label']) === 1);

    // ⚠️ **Sein Fund am 2026-09-10 an `Prefixes`:** *`label_role` — ein **Verweis**, kein Schalter — am geerbten
    // Renderer gesetzt, Antwort «Field … does not belong to Prefixes». Die innere Kante gehört dem Renderer,
    // nicht der Kette des Knotens; adressiert wird über die Kante (D-667).*
    $rollenKante = $relations->byId((int) $vaterKanten['label_role']);
    $rollen      = $rollenKante === null ? [] : $nodes->childrenOf($nodes->byId($rollenKante->toNodeId));
    $rolle       = $rollen[0] ?? null;
    check('die Kante `label_role` zeigt auf einen Knoten mit Rollen darunter', $rolle !== null);
    if ($rolle !== null) {
        $lief = speichern($sohn->id, ['taxmod_value' => [(string) $renderKante => [(string) $vaterKanten['label_role'] => (string) $rolle->id]]]);
        $gesetzt = $wpdb->get_var("SELECT v.value_ref FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id WHERE r.node_id = {$sohn->id} AND r.record_type = 'settings' AND v.relation_id = {$vaterKanten['label_role']}");
        check('ein Verweis am geerbten Renderer — `label_role` — lässt sich am Kind setzen und steht am Knoten selbst', $lief && (int) $gesetzt === $rolle->id, letzteMeldung() . ' / ' . var_export($gesetzt, true));
        check('und der Vater trägt keine Rolle', $wpdb->get_var("SELECT v.value_ref FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id WHERE r.node_id = {$vater->id} AND r.record_type = 'settings' AND v.relation_id = {$vaterKanten['label_role']}") === null);
    }
}

// ---------------------------------------------------------------------------------------------------

echo "\n== 5 · Konflikt: ein geerbter Renderer, der hier nicht zulässig ist, wird automatisch ersetzt (D-687, D-688) ==\n";

$zahlKind = $editor->createNode('__es Zahl Kind', $zahl->id);
speichern($zahl->id, ['taxmod_value' => [(string) $renderKante => (string) $compact->id], 'taxmod_value_override' => [(string) $renderKante => '1']]);
check('`__es Zahl` trägt `compact` als eigene Wahl und zeichnet damit — hier gewählt ist Rat, kein Zaun (D-360)', isset($data->settingValuesOf($zahl->id, [$renderKante])[$renderKante]) && $zeichner()->rendererNameFor($nodes->byId($zahl->id)) === CompactRenderer::NAME);
$zeile = wertspalte(seite($zahlKind->id), $renderKante);
check('das Kind zeigt die Zeile als automatisch, nicht gesperrt, mit gesetztem Haken', str_contains($zeile, 'taxmod-setting-automatic') && ! str_contains($zeile, 'taxmod-setting-locked"') && str_contains($zeile, 'value="1" checked'), substr($zeile, 0, 160));
check('und der Satz nennt, was ersetzt wurde', str_contains($zeile, 'chosen automatically') && str_contains($zeile, 'compact'));
$standard = $registry->defaultFor(SimpleType::Int)->name();
preg_match('/<select\b[^>]*>.*?<\/select>/s', $zeile, $wahlBox);
preg_match('/<option value="[^"]*"[^>]*\bselected\b[^>]*>([^<]*)</', $wahlBox[0] ?? '', $gewaehlt);
check("als gewählt steht der Typ-Standard `{$standard}`, und er gilt", ($gewaehlt[1] ?? '') === $standard && $zeichner()->rendererNameFor($nodes->byId($zahlKind->id)) === $standard, ($gewaehlt[1] ?? 'nichts') . ' / ' . (string) $zeichner()->rendererNameFor($nodes->byId($zahlKind->id)));
speichern($zahl->id, ['taxmod_value' => [(string) $renderKante => (string) $spinnerId], 'taxmod_value_override' => [(string) $renderKante => '1']]);

// Die Wurzel ist der letzte Halt, nicht die Regel für alles (D-617).
$wurzelWahlId = $ids[0];
speichern($wurzel->id, ['taxmod_value' => [(string) $renderKante => (string) $wurzelWahlId], 'taxmod_value_override' => [(string) $renderKante => '1']]);
$stumm = $editor->createNode('__es stumm', $wurzel->id);
check('ein Knoten ohne eigene Aussage bekommt die Wahl der Wurzel', gespeicherterRenderer($stumm->id) === $angebot[$wurzelWahlId], gespeicherterRenderer($stumm->id));
check('ein Knoten mit eigener Aussage behält sie gegen die Wurzel, und die Maske markiert die eigene', gespeicherterRenderer($zahl->id) === SpinnerRenderer::NAME && (bool) preg_match('/<option value="' . $spinnerId . '" selected/', seite($zahl->id)));
check('und gezeichnet wird mit der eigenen Wahl', gezeichnet($zeichner(), $eins->id)[0] === SpinnerRenderer::NAME);
speichern($wurzel->id, ['taxmod_value' => [(string) $renderKante => ''], 'taxmod_value_override' => [(string) $renderKante => '1']]);

// ---------------------------------------------------------------------------------------------------

echo "\n== 6 · Die Tafel der Feldzeile: aufklappen, setzen, nur was die Kette des Ziels erklärt (D-666, D-529, D-668) ==\n";

$feldName = 'taxmod_field_setting[' . $eins->id . '][' . SettingKey::Min->value . ']';
$zu  = seite($modellKnoten->id);
$auf = seite($modellKnoten->id, (string) $eins->id);
check('eine zugeklappte Zeile zeichnet keine ihrer Einstellungen', ! str_contains($zu, $feldName) && ! str_contains($zu, 'taxmod-field-settings-row'));
check('die aufgeklappte zeichnet sie unter ihrer Zeile, am Seitenformular', str_contains($auf, 'taxmod-field-settings-row') && (bool) preg_match('/name="' . preg_quote($feldName, '/') . '"[^>]*form="taxmod-page-' . $modellKnoten->id . '"/', $auf));
check('die Zeile trägt einen Knopf zum Aufklappen', str_contains($zu, 'value="toggle_field_settings"'));
$gewandert = abschicken(['do' => 'toggle_field_settings', 'id' => (string) $modellKnoten->id, 'relation' => (string) $eins->id, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $modellKnoten->id)]);
check('und er führt auf dieselbe Seite mit der Zeile offen', $gewandert && str_contains(urldecode((string) $GLOBALS['taxmod_letzte_adresse']), 'taxmod_open_rows=' . $eins->id));
$_GET['taxmod_open_rows'] = (string) $eins->id;
abschicken(['do' => 'toggle_field_settings', 'id' => (string) $modellKnoten->id, 'relation' => (string) $eins->id, 'taxmod_open_rows' => (string) $eins->id, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $modellKnoten->id)]);
unset($_GET['taxmod_open_rows']);
check('und derselbe Knopf klappt sie wieder zu', ! str_contains(urldecode((string) $GLOBALS['taxmod_letzte_adresse']), 'taxmod_open_rows='));
$rc        = new ReflectionClass(Plugin::class);
$pluginObj = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($pluginObj, __FILE__);
$nachgeholt = $pluginObj->screen()->fieldSettingsFragment($editor->find($modellKnoten->id), $relations->byId($eins->id));
check('der nachgeholte Bereich ist derselbe wie der auf der Seite', $nachgeholt !== '' && str_contains($auf, $nachgeholt));

check('eine Einstellung setzen: der Akt läuft durch', speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $eins->id => [SettingKey::Min->value => '7']], 'taxmod_field_setting_override' => [(string) $eins->id => [SettingKey::Min->value => '1']]]));
$anDerKante = static function (int $relationId, string $key) use ($modell, $relations): string {
    $relation = $relations->byId($relationId);
    $a = $relation === null ? null : ($modell()->forUseSite($relation)[$key] ?? null);

    return $a === null || ! $a->setHere ? '' : ($a->value->int === null ? (string) $a->value->text : (string) $a->value->int);
};
check('der Wert steht an der Kante, nicht am Zielknoten', $anDerKante($eins->id, SettingKey::Min->value) === '7', $anDerKante($eins->id, SettingKey::Min->value));
check('und die aufgeklappte Zeile zeigt ihn wieder', (bool) preg_match('/name="' . preg_quote($feldName, '/') . '"[^>]*value="7"/', seite($modellKnoten->id, (string) $eins->id)));

preg_match_all('/name="taxmod_field_setting\[' . $eins->id . '\]\[([a-z_]+)\]"/', $auf, $tafelTreffer);
$angeboteneSchluessel = array_values(array_unique($tafelTreffer[1]));
$kette = [];
$lauf  = $zahl->id;
while ($lauf !== 0) {
    $kette[] = $lauf;
    $lauf    = $nodes->find($lauf)?->parentId() ?? 0;
}
$erklaert   = $wpdb->get_col("SELECT DISTINCT name FROM {$p}relations_named WHERE kind = 'setting' AND name <> '' AND from_node_id IN (" . implode(',', $kette) . ')') ?: [];
$erklaert[] = SettingKey::Multiplicity->value;
$erklaert[] = Rendering::KIND_KEY;
$ueberzaehlig = array_values(array_diff($angeboteneSchluessel, $erklaert));
check('der Einstellungsbereich bietet Schlüssel an, und jeder ist an der Kette des Ziels erklärt', count($angeboteneSchluessel) >= 3 && $ueberzaehlig === [], 'nicht erklärt: ' . implode(',', $ueberzaehlig));
check('ein zeichenbarer, aber nicht erklärter Schlüssel fehlt', in_array(SettingKey::DisplaySize->value, $erklaert, true) || ! in_array(SettingKey::DisplaySize->value, $angeboteneSchluessel, true));

$html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', seite($kind->id)) ?? '';
preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $html, $zeilenKind);
$offeneGeerbte = 0;
$geerbte = 0;
foreach ($zeilenKind[1] as $z) {
    if (str_contains($z, 'taxmod-field-many') && preg_match('/<select\b[^>]*class="taxmod-choice[^"]*"[^>]*>/', $z, $tr) && str_contains($z, '>inherited<')) {
        ++$geerbte;
        if (! str_contains($tr[0], 'disabled')) {
            ++$offeneGeerbte;
        }
    }
}
check('«How many» ist an geerbten Zeilen gesperrt', $geerbte >= 2 && $offeneGeerbte === 0, "{$offeneGeerbte} offen von {$geerbte}");
$htmlEigen = preg_replace('/<dialog\b.*?<\/dialog>/s', '', seite($modellKnoten->id)) ?? '';
preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $htmlEigen, $zeilenEigen);
$gesperrteEigene = 0;
$eigene = 0;
foreach ($zeilenEigen[1] as $z) {
    if (str_contains($z, 'taxmod-field-many') && preg_match('/<select\b[^>]*class="taxmod-choice[^"]*"[^>]*>/', $z, $tr) && str_contains($z, '>own<')) {
        ++$eigene;
        if (str_contains($tr[0], 'disabled')) {
            ++$gesperrteEigene;
        }
    }
}
check('und an eigenen Zeilen änderbar', $eigene >= 1 && $gesperrteEigene === 0, "{$gesperrteEigene} gesperrt von {$eigene}");

$feldText = $editor->addField($eltern->id, $seeded['text']->id, '__es Feld', RelationKind::Composition);
$markup   = seite($eltern->id, (string) $feldText->id);
preg_match_all('/name="taxmod_field_setting_override\[' . $feldText->id . '\]\[([^\]]+)\]"/', $markup, $haken);
$schluessel = array_values(array_unique($haken[1] ?? []));
check('die aufgeklappte Zeile trägt gesperrte Einstellungen mit Haken, die erste nennt ihre Herkunft', $schluessel !== [] && (bool) preg_match('/taxmod-setting-locked.*?inherited from [^<]+/s', $markup));
if ($schluessel !== []) {
    $erster = $schluessel[0];
    $wert   = match ($erster) { 'display_size' => '42', default => '1' };
    speichern($eltern->id, ['taxmod_field_setting' => [(string) $feldText->id => [$erster => $wert]]]);
    $a = $zeichner()->settingsForUseSites([$feldText])[$feldText->id][$erster] ?? null;
    check("ohne Haken bleibt `{$erster}` an der Stelle geerbt", $a !== null && ! $a->setHere);
    speichern($eltern->id, ['taxmod_field_setting' => [(string) $feldText->id => [$erster => $wert]], 'taxmod_field_setting_override' => [(string) $feldText->id => [$erster => '1']]]);
    $a = $zeichner()->settingsForUseSites([$feldText])[$feldText->id][$erster] ?? null;
    check("mit Haken ist `{$erster}` an der Stelle gesetzt", $a !== null && $a->setHere);
}

$leseKante = $data->settingRelationAtUseSite($count, SettingKey::ReadOnly->value);
check('die Einstellungskante «read_only» ist an einer Stelle zu finden', $leseKante !== null);
if ($leseKante !== null) {
    $anDerStelle = static fn (): ?bool => ($modell()->forUseSite($count)[SettingKey::ReadOnly->value] ?? null)?->setHere;
    $data->putSettingAtUseSite($count->id, $leseKante->id, TypedValue::ofBool(true));
    check('über den Dienst geschrieben, und der Leser findet sie an der Stelle, im Satz der Verwendungsstelle', $anDerStelle() === true && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('relation_records') . ' w JOIN ' . Schema::table('node_records') . ' r ON r.id = w.node_record_id WHERE r.relation_id = %d AND w.relation_id = %d', $count->id, $leseKante->id)) === 1);
    $data->clearSettingAtUseSite($count->id, $leseKante->id);
    check('herausgenommen, und die Stelle sagt nichts mehr', $anDerStelle() !== true);
    speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $count->id => ['read_only' => '1']], 'taxmod_field_setting_override' => [(string) $count->id => ['read_only' => '1']]]);
    check('der Akt der Feldzeile läuft durch, und die Angabe steht danach an der Stelle', letzteMeldung() === 'ok' && $anDerStelle() === true, letzteMeldung());
    speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $count->id => ['read_only' => '']], 'taxmod_field_setting_override' => [(string) $count->id => ['read_only' => '1']]]);
    check('der leere Akt nimmt sie wieder heraus', letzteMeldung() === 'ok' && $anDerStelle() !== true);
}

// ---------------------------------------------------------------------------------------------------

echo "\n== 7 · Werte und Steuerelemente: hinein als ihr Typ, zurück unverändert ==\n";

$rendering = $zeichner();
$record    = $data->create($modellKnoten->id);
$typed = [
    [$count,  '42',               static fn ($v): bool => $v->int === 42],
    [$weight, '2.50',             static fn ($v): bool => $v->decimal === '2.5'],
    [$label,  '4k7',              static fn ($v): bool => $v->text === '4k7'],
    [$stock,  '1',                static fn ($v): bool => $v->asBool() === true],
    [$mail,   'a@b.example',      static fn ($v): bool => $v->text === 'a@b.example'],
    [$when,   '2026-08-25T14:32', static fn ($v): bool => $v->date === '2026-08-25 14:32:00'],
    [$colour, '#663399',          static fn ($v): bool => $v->text === '#663399'],
];
$typen = $rendering->typesFor($every);
foreach ($typed as [$relation, $characters, $expected]) {
    $data->put($record->id, $relation->id, $typen[$relation->id]->valueFrom($characters));
}
$back = [];
foreach ($data->valuesOf($record->id) as $value) {
    $back[$value->relationId] = $value->value;
}
$ueberlebt = [];
foreach ($typed as [$relation, $characters, $expected]) {
    if (! isset($back[$relation->id]) || ! $expected($back[$relation->id])) {
        $ueberlebt[] = $relation->name;
    }
}
check('sieben Werte überleben den Weg hinein und zurück', $ueberlebt === [], implode(', ', $ueberlebt));
$row = $wpdb->get_row($wpdb->prepare('SELECT value_int, value_decimal, value_text, value_date FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND relation_id = %d', $record->id, $when->id), ARRAY_A);
check('ein datetime landet in value_date und nirgends sonst (D-071)', $row['value_date'] !== null && $row['value_int'] === null && $row['value_text'] === null);
$row = $wpdb->get_row($wpdb->prepare('SELECT value_int, value_text FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND relation_id = %d', $record->id, $stock->id), ARRAY_A);
check('ein bool landet in value_int als 1 (D-315)', (int) $row['value_int'] === 1 && $row['value_text'] === null);
$reloaded = [];
foreach ($rendering->fieldsFor($every, $back, Purpose::Edit, 'taxmod_value') as $field) {
    $reloaded[$field->relation->id] = $field->result->markup;
}
check('was gespeichert ist, zeigt das Steuerelement wieder: Haken, Datum, Adresse, Farbe', str_contains($reloaded[$stock->id], 'checked') && str_contains($reloaded[$when->id], 'value="2026-08-25T14:32"') && str_contains($reloaded[$mail->id], 'value="a@b.example"') && str_contains($reloaded[$colour->id], 'value="#663399"'));
$verweigert = 0;
foreach ([[$count, 'abc'], [$when, '25.08.2026']] as [$rel, $roh]) {
    try {
        $typen[$rel->id]->valueFrom($roh);
    } catch (NotAValueOfThatType) {
        ++$verweigert;
    }
}
check('nichts wird zurechtgebogen: ein Wort ist keine Null, ein fremdes Datum wird verweigert, leer ist unbeantwortet', $verweigert === 2 && $typen[$count->id]->valueFrom('')->isNothing());

$gram = $editor->createNode('__es Gramm', $framework->rootOf(Branch::Constants)->id);
$unit = $editor->addField($modellKnoten->id, $gram->id, '__es unit');
$named = $rendering->fieldsFor([$unit], [$unit->id => TypedValue::ofReference($gram->id)], Purpose::Display, '')[0];
check('eine Konstante wird als ihr Name gezeichnet, nicht als Nummer (D-105, D-232)', $named->type === SimpleType::NodeRef && $named->rendererName === 'reference' && str_contains($named->result->markup, '__es Gramm') && ! str_contains($named->result->markup, (string) $gram->id));
$editing = $rendering->fieldsFor([$unit], [$unit->id => TypedValue::ofReference($gram->id)], Purpose::Edit, 'taxmod_value')[0];
check('bearbeiten ist eine Wahl, mit sichtbarem Wert, und ein einziger Ausgang ist keine Entscheidung (R30)', ! $editing->hasNoRenderer() && str_contains($editing->result->markup, 'taxmod-choice') && str_contains($editing->result->markup, '__es Gramm') && str_contains($editing->result->markup, 'disabled'));

$relations->save($label->withHide(true), $label->version);
$frisch = [];
foreach ($relations->fieldRelationsOf([$modellKnoten->id]) as $one) {
    $frisch[$one->id] = $one;
}
$label = $frisch[$label->id] ?? $label;
$every = array_map(static fn ($e) => $frisch[$e->id] ?? $e, $every);
$angabe($mail, SettingKey::ReadOnly->value, TypedValue::ofBool(true));
$rendering = $zeichner();
$closed = [];
foreach ($rendering->fieldsFor([$label, $mail], $back, Purpose::Edit, 'taxmod_value') as $field) {
    $closed[$field->relation->id] = $field;
}
check('ein verborgenes Feld wird gar nicht aufgezählt, die anderen bleiben', ! isset($closed[$label->id]) && count($closed) === 1);
check('eine nur lesbare Adresse wird gezeigt, nicht angeboten — und bleibt ein Link', ! str_contains($closed[$mail->id]->result->markup, '<input') && str_contains($closed[$mail->id]->result->markup, 'mailto:'));
check('kein Feld wird zur Suche angeboten (D-217), und beim Anzeigen fällt keines weg', $rendering->fieldsFor($every, [], Purpose::Search, 'q') === [] && count($rendering->fieldsFor($every, [], Purpose::Display, '')) === count($every) - 1);

$before = $wpdb->num_queries;
$rendering->fieldsFor($every, $back, Purpose::Edit, 'taxmod_value');
$spent = $wpdb->num_queries - $before;
$before = $wpdb->num_queries;
$rendering->fieldsFor([...$every, ...$every], $back, Purpose::Edit, 'taxmod_value');
$doppelt = $wpdb->num_queries - $before;
check('das ganze Formular kostet eine feste Zahl Abfragen (CD-7), und doppelt so viele Felder nicht mehr', $spent <= 8 && $doppelt <= $spent, "{$spent} für 7, {$doppelt} für 14");

$intNode = $nodes->byId($seeded['int']->id);
$angabe($intNode, SettingKey::ReadOnly->value, TypedValue::ofBool(true));
$rendering = $zeichner();
$srows = [];
foreach ($rendering->settingsFor($intNode, $rendering->settingsForNode($intNode)) as $r) {
    $srows[$r->key] = $r;
}
check('eine boolesche Einstellung wird als Schiebeschalter gezeichnet (R20a)', isset($srows['read_only']) && $srows['read_only']->wasDrawn() && str_contains($srows['read_only']->result->markup, 'taxmod-toggle-track'));
check('ein leihender Schlüssel nimmt den Typ des Knotens', isset($srows['step']) ? $srows['step']->type === SimpleType::Int : true);
$editRows = [];
foreach ($rendering->settingsFor($intNode, $rendering->settingsForNode($intNode), Purpose::Edit) as $r) {
    $editRows[$r->key] = $r;
}
check('eine Wahl wird als Liste echter Möglichkeiten gezeichnet', isset($editRows['renderer']) && $editRows['renderer']->wasDrawn() && str_contains($editRows['renderer']->result->markup, '<select'));
$intLeer = $editor->createNode('__es Int leer', $seeded['int']->id);
$editor->addField($intLeer->id, $gram->id, 'converter', RelationKind::Setting);
// ⚠️ *Ein Zeichner ohne Konverter-Registratur: dann hat die Wahl nichts anzubieten — und genau das soll sie zeigen.*
$rendering = new Rendering($nodes, $framework, $registry, $types, $labels, null, $modell(), $relations);
$eigeneZeilen = [];
foreach ($rendering->settingsFor($nodes->byId($intLeer->id), $rendering->settingsForNode($nodes->byId($intLeer->id)), Purpose::Edit) as $r) {
    $eigeneZeilen[$r->key] = $r;
}
check('eine Wahl ohne Inhalt ist ein totes Steuerelement, kein leeres', isset($eigeneZeilen['converter']) && str_contains($eigeneZeilen['converter']->result->markup ?? '', 'disabled'));
$angabe($nodes->byId($modellKnoten->id), SettingKey::ReadOnly->value, TypedValue::ofBool(true));
check('ein Schalter liest sich als Wahrheitswert zurück, nicht als die Zahl eins', ($zeichner()->settingsForNode($nodes->byId($modellKnoten->id))['read_only'] ?? null)?->value->asBool() === true);
$ohneAngabe($nodes->byId($modellKnoten->id), SettingKey::ReadOnly->value);
$ohneAngabe($intNode, SettingKey::ReadOnly->value);

$formed = $zeichner()->nodeAsForm($nodes->byId($modellKnoten->id), $every, $back, Purpose::Edit, 'taxmod_value');
$readOnlyAt = strpos($formed->markup, '__es contact');
$ordinaryAt = strpos($formed->markup, '__es count');
$boolAt     = strpos($formed->markup, '__es in stock');
check('ein Knoten wird von einem Behälter gezeichnet, nicht von einem Bildschirm (D-098): das Formular steht, nennt seine Kanten', str_contains($formed->markup, 'taxmod-form') && $formed->usedRelations !== []);
check('nur lesbare Felder vorn, gewöhnliche danach, Schalter zuletzt', $readOnlyAt !== false && $ordinaryAt !== false && $boolAt !== false && $readOnlyAt < $ordinaryAt && $boolAt > $ordinaryAt, "{$readOnlyAt} / {$ordinaryAt} / {$boolAt}");
$ohneAngabe($mail, SettingKey::ReadOnly->value);

// ---------------------------------------------------------------------------------------------------

echo "\n== 8 · Konverter: dieselbe Zahl, anders geschrieben, in beide Richtungen ==\n";

// ⚠️ *Die Zeile `converter` steht an einer Stelle nur, wenn die Kette des Ziels sie erklärt — also erklärt `__es Zahl` sie.*
$kanteFuer($zahl->id, SettingKey::Converter->value);
$konverterZeile = null;
foreach ($zeichner()->settingsFor($eins, $zeichner()->settingsForUseSites([$eins])[$eins->id] ?? [], Purpose::Edit) as $one) {
    if ($one->key === SettingKey::Converter->value) {
        $konverterZeile = $one;
    }
}
check('die Zeile `converter` ist lebendig und bietet binary, hexadecimal, octal, roman an', $konverterZeile !== null && ! str_contains($konverterZeile->result->markup, 'disabled') && count(array_filter(['binary', 'hexadecimal', 'octal', 'roman'], static fn (string $n): bool => str_contains($konverterZeile->result->markup, $n))) === 4);
$vorherFeld = $zeichner()->fieldsFor([$eins], [$eins->id => TypedValue::ofInt(12)], Purpose::Display)[0];
check('ohne Konverter steht die 12 als 12 da', str_contains($vorherFeld->result->markup, '12'));
$umschreiben = static function (string $name) use ($data, $eins, $zahl, $kanteFuer): void {
    $data->putSettingAtUseSite($eins->id, $kanteFuer($zahl->id, SettingKey::Converter->value)->id, TypedValue::ofText($name));
};
$umschreiben('roman');
$nachher = $zeichner()->fieldsFor([$eins], [$eins->id => TypedValue::ofInt(12)], Purpose::Display)[0];
check('mit roman wird sie XII, ohne den Renderer zu wechseln', str_contains($nachher->result->markup, 'XII') && $nachher->rendererName === $vorherFeld->rendererName);
check('und XII kommt als 12 zurück, auch klein geschrieben', ($zeichner()->valuesFrom([$eins], [$eins->id => 'XII'])[$eins->id]->int ?? null) === 12 && ($zeichner()->valuesFrom([$eins], [$eins->id => 'xii'])[$eins->id]->int ?? null) === 12);
$hinUndZurueck = [];
foreach ([['hexadecimal', 255, 'FF'], ['binary', 12, '1100'], ['octal', 493, '755']] as [$k, $zahlWert, $zeichen]) {
    $umschreiben($k);
    $g = $zeichner()->fieldsFor([$eins], [$eins->id => TypedValue::ofInt($zahlWert)], Purpose::Display)[0];
    if (! str_contains($g->result->markup, $zeichen) || ($zeichner()->valuesFrom([$eins], [$eins->id => $zeichen])[$eins->id]->int ?? null) !== $zahlWert) {
        $hinUndZurueck[] = $k;
    }
}
check('hexadecimal, binary und octal in beide Richtungen', $hinUndZurueck === [], implode(', ', $hinUndZurueck));
$umschreiben('binary');
$abgelehnt = 0;
try {
    $zeichner()->valuesFrom([$eins], [$eins->id => '2']);
} catch (NotAValueOfThatType) {
    ++$abgelehnt;
}
$umschreiben('hexadecimal');
try {
    $zeichner()->valuesFrom([$eins], [$eins->id => 'zz']);
} catch (NotAValueOfThatType) {
    ++$abgelehnt;
}
check('eine Ziffer, die es in der Basis nicht gibt, und Unlesbares werden verweigert — nicht als 0 gespeichert', $abgelehnt === 2);
$umschreiben('gibt-es-nicht');
check('ein Konvertername, den es nicht gibt, nimmt kein Formular mit runter', str_contains($zeichner()->fieldsFor([$eins], [$eins->id => TypedValue::ofInt(12)], Purpose::Display)[0]->result->markup, '12'));
$data->clearSettingAtUseSite($eins->id, $kanteFuer($zahl->id, SettingKey::Converter->value)->id);

// ---------------------------------------------------------------------------------------------------

echo "\n== 9 · Vorschau: drei Seiten, keine Einstellungen darin, und zeichnen schreibt nichts ==\n";

$leerKnoten = $editor->createNode('__es Leer', $modellAst->id);
$leerFeld   = $editor->addField($leerKnoten->id, $seeded['text']->id, '__es leeres Feld');
$band       = vorschau($leerKnoten->id);
check('die Vorschau erscheint, wo Sätze möglich sind — mit drei Seiten', $band !== '' && substr_count($band, 'taxmod-preview-side') === 3, (string) substr_count($band, 'taxmod-preview-side'));
check('mit nichts eingetragen werden die Vorgaben genannt', str_contains($band, 'Filled from the defaults'));
$beispiel = $data->create($leerKnoten->id, RecordType::Example);
$nurBeispiel = vorschau($leerKnoten->id);
check('ein Beispielsatz zeichnet, wo kein echter ist, und sagt es', str_contains($nurBeispiel, 'record #' . $beispiel->id) && str_contains($nurBeispiel, 'marked as test data'));
$echt = $data->create($leerKnoten->id, RecordType::User);
$beide = vorschau($leerKnoten->id);
check('echte Daten schlagen den Beispielsatz, und die Vorschau nennt sich nicht mehr Testdaten', str_contains($beide, 'record #' . $echt->id) && ! str_contains($beide, 'record #' . $beispiel->id) && ! str_contains($beide, 'marked as test data'));
check('ohne verborgenes Feld ist nichts als ausgelassen gemeldet', ! str_contains($band, 'Left out by hide'));

$bandVoll = vorschau($modellKnoten->id);
check('das Modell mit seinem Satz: die Vorschau sagt, woher die Werte kommen, und zeichnet «__es count»', str_contains($bandVoll, 'Filled from') && str_contains($bandVoll, '__es count'));
check('ein verborgenes Feld wird als ausgelassen gemeldet, mit Namen', (bool) preg_match('#Left out by hide:[^<]*__es label#', $bandVoll));
$angabe($count, SettingKey::ReadOnly->value, TypedValue::ofBool(true));
$fixed = vorschau($modellKnoten->id);
check('read_only lässt die Zeile stehen und meldet sie als nur lesbar — verborgen ist sie nicht', str_contains($fixed, '__es count') && str_contains($fixed, 'read-only'));
$ohneAngabe($count, SettingKey::ReadOnly->value);

$rendererControl = static function (int $node): string {
    $_GET['taxmod_hidden'] = '1';
    preg_match('#<select[^>]*name="[^"]*\[renderer\][^"]*"[^>]*>#', seite($node), $m);
    unset($_GET['taxmod_hidden']);

    return $m[0] ?? '';
};
$wpdb->query($wpdb->prepare("UPDATE {$p}nodes SET hide = 1 WHERE id = %d", $zahl->id));
check('ein verborgener Knoten behält seinen Renderer-Wähler (D-399)', ! str_contains($rendererControl($zahl->id), 'disabled'));
$wpdb->query($wpdb->prepare("UPDATE {$p}nodes SET hide = 0 WHERE id = %d", $zahl->id));

$kantenModell = $relations->fieldRelationsOf($framework->inheritanceOwnersOf($nodes->byId($modellKnoten->id)));
$sicht = $zeichner()->previewVisibilityFor($kantenModell, $zeichner()->settingsForUseSites($kantenModell));
$einstellungenDrin = count(array_filter($sicht['shown'], static fn (Relation $k): bool => $k->isSetting()));
$echteFelder = count(array_filter($kantenModell, static fn (Relation $k): bool => ! $k->isSetting() && ! $k->hide));
check('keine Einstellungskante in der Vorschau, und alle echten Felder stehen da', $einstellungenDrin === 0 && count($sicht['shown']) === $echteFelder, count($sicht['shown']) . ' statt ' . $echteFelder);

$html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', seite($modellKnoten->id)) ?? '';
$von  = strpos($html, '>Preview<');
$bis  = $von === false ? false : strpos($html, '>Used by<', $von);
$teil = $von === false ? '' : substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);
preg_match_all('/<h4>([^<]*)<\/h4>(.*?)(?=<h4>|$)/s', $teil, $seiten, PREG_SET_ORDER);
$nachName = [];
foreach ($seiten as $s) {
    $nachName[$s[1]] = trim((string) preg_replace('/\s+/', ' ', strip_tags($s[2])));
}
check('die drei Seiten heissen Display, Admin, Settings', count($seiten) === 3 && isset($nachName['Display'], $nachName['Admin'], $nachName['Settings']), implode(', ', array_keys($nachName)));
check('Admin nennt keine Einstellung, aber seine Felder; Settings nennt read_only und kein Feld', ! str_contains($nachName['Admin'] ?? '', 'read_only') && str_contains($nachName['Admin'] ?? '', '__es count') && str_contains($nachName['Settings'] ?? '', 'read_only') && ! str_contains($nachName['Settings'] ?? '', '__es count'));

$whole = seite($seeded['text']->id);
check('ein Datentyp zeigt sich selbst als Feld, mit zwei Seiten', substr_count($whole, 'taxmod-preview-side') === 2 && ! str_contains($whole, 'Nothing to preview here'));
$seitenTyp = preg_split('/<div class="taxmod-preview-side">/', $whole) ?: [];
$leserSeite = explode('</div>', $seitenTyp[1] ?? '')[0];
$editorSeite = $seitenTyp[2] ?? '';
$editorSeite = substr($editorSeite, 0, strpos($editorSeite, '</form>') !== false ? strpos($editorSeite, '</form>') + 7 : strlen($editorSeite));
check('«Add as example» steht in der Bearbeiter-Seite und nicht in der Leser-Seite', str_contains($editorSeite, 'value="add_example"') && ! str_contains($leserSeite, 'add_example'));

$anschrift = $editor->createNode('__es Anschrift', $framework->rootOf(Branch::Compositions)->id);
foreach (['Gasse', 'Nummer', 'Postleitzahl', 'Stadt'] as $innen) {
    $editor->addField($anschrift->id, $seeded['text']->id, '__es ' . $innen);
}
$editor->addField($modellKnoten->id, $anschrift->id, '__es adresse');
$html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', seite($modellKnoten->id)) ?? '';
$von  = strpos($html, '>Preview<');
$bis  = $von === false ? false : strpos($html, '>Used by<', $von);
$teil = $von === false ? '' : substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);
$fehlen = array_values(array_filter(['__es Gasse', '__es Nummer', '__es Postleitzahl', '__es Stadt'], static fn (string $f): bool => ! str_contains($teil, $f)));
check('die Renderkette geht durch ein zusammengesetztes Feld: die Vorschau nennt alle vier Teile', $fehlen === [], implode(', ', $fehlen));
preg_match_all('/<(?:input|select|textarea)\b[^>]*>/', $teil, $bedienung);
check('und zeichnet je Teil eine Bedienung', count($bedienung[0]) >= 4, count($bedienung[0]) . ' für 4 Teile');

$vorherZahlen = [(int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records"), (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records")];
vorschau($leerKnoten->id);
vorschau($modellKnoten->id);
check('zeichnen schreibt nichts — kein Satz, kein Wert', $vorherZahlen === [(int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records"), (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records")]);

$satzText = 'Dieser Satz steht nur während der Prüfung da.';
$sprache  = SettingsScreen::neutralLocale();
$vorherBand = vorschau($modellKnoten->id);
$zeichenVorher = substr_count($vorherBand, 'taxmod-hint-icon');
$labels->put(new Label($seeded['int']->id, IdentitySpace::Node, SeededRole::Help, Label::BASE_NUMBER, $sprache, $satzText));
$nachherBand = vorschau($modellKnoten->id);
check('eine help-Beschriftung setzt ein Fragezeichen mehr, und der Satz steht im Markup', substr_count($nachherBand, 'taxmod-hint-icon') > $zeichenVorher && str_contains($nachherBand, '<span class="taxmod-hint-text">' . esc_html($satzText) . '</span>'));
$labels->forget(new Label($seeded['int']->id, IdentitySpace::Node, SeededRole::Help, Label::BASE_NUMBER, $sprache, ''));
check('und nach dem Wegnehmen ist es wieder fort', ! str_contains(vorschau($modellKnoten->id), $satzText));

// ---------------------------------------------------------------------------------------------------

echo "\n== 10 · Die Kantenart umstellen — und mit Benutzersätzen warnen (D-699) ==\n";

$artSetzen = static function (int $nodeId, int $feldId, string $art): string {
    speichern($nodeId, ['taxmod_field_setting' => [(string) $feldId => ['kind' => $art]]]);

    return letzteMeldung();
};
$feldArt = $editor->addField($modellKnoten->id, $seeded['text']->id, '__es Artfeld', RelationKind::Composition);
$artSetzen($modellKnoten->id, $feldArt->id, 'aggregation');
$nachAgg = $editor->relationById($feldArt->id)?->kind;
$artSetzen($modellKnoten->id, $feldArt->id, 'composition');
$zurueck = $editor->relationById($feldArt->id)?->kind;
$artSetzen($modellKnoten->id, $feldArt->id, 'unfug');
check('ohne Werte wechselt die Art einfach, hin und zurück; ein unbekanntes Wort ist keine Angabe', $nachAgg === RelationKind::Aggregation && $zurueck === RelationKind::Composition && $editor->relationById($feldArt->id)?->kind === RelationKind::Composition);
$s1 = $data->create($modellKnoten->id);
$data->put($s1->id, $feldArt->id, TypedValue::ofText('sieben'));
$s2 = $data->create($modellKnoten->id);
$data->put($s2->id, $feldArt->id, TypedValue::ofText('acht'));
$antwort = $artSetzen($modellKnoten->id, $feldArt->id, 'setting');
check('Feld → Einstellung mit Benutzersätzen: die Antwort nennt die Sätze und verlangt den Haken', str_contains($antwort, '2 entries') && str_contains($antwort, 'tick the confirmation'), $antwort);
$wertVon = static function (int $satzId) use ($data, $feldArt): ?string {
    foreach ($data->valuesOf($satzId) as $w) {
        if ($w->relationId === $feldArt->id) {
            return $w->value->text;
        }
    }

    return null;
};
check('die Kante bleibt ein Feld, beide Werte stehen noch', $editor->relationById($feldArt->id)?->kind === RelationKind::Composition && $wertVon($s1->id) === 'sieben' && $wertVon($s2->id) === 'acht', (string) $editor->relationById($feldArt->id)?->kind->value . ' / ' . $wertVon($s1->id) . ' / ' . $wertVon($s2->id));
$_GET['taxmod_kind_pending'] = $feldArt->id . ':setting:2:2';
$markup = seite($modellKnoten->id);
unset($_GET['taxmod_kind_pending']);
$nameArt = 'taxmod_field_setting[' . $feldArt->id . '][kind]';
check('die Zeile zeigt `setting` vorgewählt, mit dem Haken «ich bestätige» und dem Satz, was er kostet', (bool) preg_match('/name="' . preg_quote($nameArt, '/') . '"[^>]*>.*?<option value="setting"[^>]*selected/s', $markup) && str_contains($markup, 'name="taxmod_field_setting[' . $feldArt->id . '][kind_confirm]"') && str_contains($markup, '2 entries with 2 values go to the shadow'));
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $feldArt->id => ['kind' => 'setting', 'kind_confirm' => '1']]]);
check('mit Haken: die Kante ist eine Einstellung, die Sätze liegen im Schatten, die Einstellung beginnt leer', $editor->relationById($feldArt->id)?->kind === RelationKind::Setting && $data->find($s1->id) === null && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id IN ({$s1->id}, {$s2->id})") === 2 && ($data->settingValuesOf($modellKnoten->id, [$feldArt->id])[$feldArt->id] ?? null) === null);
$einstellungssatz = $rows->add(new NodeRecord(0, $modellKnoten->id, $editor->find($modellKnoten->id)?->version ?? 1, '2026-09-10 00:00:00', RecordType::Settings));
$rows->putValue(new RelationRecord($einstellungssatz, $feldArt->id, '', TypedValue::ofText('neun')));
$artSetzen($modellKnoten->id, $feldArt->id, 'composition');
check('Einstellung → Feld: was im Einstellungssatz steht, bleibt dort (D-699, Satz 3)', $editor->relationById($feldArt->id)?->kind === RelationKind::Composition && ($data->settingValuesOf($modellKnoten->id, [$feldArt->id])[$feldArt->id] ?? null)?->text === 'neun');

// ---------------------------------------------------------------------------------------------------

echo "\n== 11 · Datensätze: anlegen mit Art, schreiben, umstellen, löschen — und der Block auf der Seite ==\n";

$satzKnoten = $editor->createNode('__es satzknoten', $modellAst->id);
$satzFeld   = $editor->addField($satzKnoten->id, $seeded['int']->id, '__es zahl');
abschicken(['do' => 'add_record', 'id' => (string) $satzKnoten->id, 'record_type' => RecordType::Example->value, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
$satzId = (int) $wpdb->get_var("SELECT id FROM {$p}node_records WHERE node_id = {$satzKnoten->id} ORDER BY id DESC LIMIT 1");
check('«New record» legt einen Satz an, mit der gewählten Art', $satzId > 0 && (string) $wpdb->get_var("SELECT record_type FROM {$p}node_records WHERE id = {$satzId}") === RecordType::Example->value);
abschicken(['do' => 'save_record', 'id' => (string) $satzKnoten->id, 'node_record_id' => (string) $satzId, 'taxmod_value' => [(string) $satzId => [(string) $satzFeld->id => '42']], '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
check('ein eingetippter Wert steht danach im Satz, und die Seite zeigt ihn wieder', (string) $wpdb->get_var("SELECT value_int FROM {$p}relation_records WHERE node_record_id = {$satzId} AND relation_id = {$satzFeld->id}") === '42' && str_contains(seite($satzKnoten->id), 'value="42"'));
$seiteSatz = seite($satzKnoten->id);
$hinterWaehler = preg_split('/name="record_type" form="taxmod-record-' . $satzId . '"/', $seiteSatz)[1] ?? '';
check('die Zeile zeichnet einen Wähler für die Art, und er steht auf der Art des Satzes', (bool) preg_match('/<select name="record_type" form="taxmod-record-' . $satzId . '"/', $seiteSatz) && (bool) preg_match('/<option value="' . RecordType::Example->value . '" selected/', explode('</select>', $hinterWaehler)[0]));
check('die Satzart-Auswahl bietet `settings` nicht an (D-704)', ! str_contains(explode('</select>', $hinterWaehler)[0], 'value="settings"'));
$versionVorher  = (int) $wpdb->get_var("SELECT version FROM {$p}node_records WHERE id = {$satzId}");
$schattenVorDem = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$satzId}");
abschicken(['do' => 'save_record', 'id' => (string) $satzKnoten->id, 'node_record_id' => (string) $satzId, 'record_type' => RecordType::Default->value, 'taxmod_value' => [(string) $satzId => [(string) $satzFeld->id => '42']], '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
check('umgestellt: die neue Art steht da, die Version zählt hoch, der Zustand davor liegt im Schatten, das Buch kennt es', (string) $wpdb->get_var("SELECT record_type FROM {$p}node_records WHERE id = {$satzId}") === RecordType::Default->value && (int) $wpdb->get_var("SELECT version FROM {$p}node_records WHERE id = {$satzId}") > $versionVorher && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$satzId}") > $schattenVorDem && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$satzId} AND owner_kind = 'record' AND what = 'record retyped'") > 0);
$versionNach = (int) $wpdb->get_var("SELECT version FROM {$p}node_records WHERE id = {$satzId}");
abschicken(['do' => 'save_record', 'id' => (string) $satzKnoten->id, 'node_record_id' => (string) $satzId, 'taxmod_value' => [(string) $satzId => [(string) $satzFeld->id => '42']], '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
abschicken(['do' => 'save_record', 'id' => (string) $satzKnoten->id, 'node_record_id' => (string) $satzId, 'record_type' => RecordType::Default->value, 'taxmod_value' => [(string) $satzId => [(string) $satzFeld->id => '42']], '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
check('ohne Angabe bleibt die Art; dieselbe Art noch einmal zählt keine Version hoch', (string) $wpdb->get_var("SELECT record_type FROM {$p}node_records WHERE id = {$satzId}") === RecordType::Default->value && (int) $wpdb->get_var("SELECT version FROM {$p}node_records WHERE id = {$satzId}") === $versionNach);
$schattenVorher = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$satzId}");
abschicken(['do' => 'delete_record', 'id' => (string) $satzKnoten->id, 'node_record_id' => (string) $satzId, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
check('gelöscht: aus der lebenden Tabelle weg mit seinen Werten, im Schatten mit ihnen, und das Buch kennt den Akt', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE id = {$satzId}") === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = {$satzId}") === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records_history WHERE id = {$satzId}") > $schattenVorher && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records_history WHERE node_record_id = {$satzId}") > 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}changelog WHERE owner_id = {$satzId} AND owner_kind = 'record' AND what = 'record removed'") > 0);

for ($i = 0; $i < 3; $i++) {
    $data->create($satzKnoten->id);
}
$seiteSatz = seite($satzKnoten->id);
$at    = strpos($seiteSatz, 'taxmod-record');
$block = $at === false ? '' : substr($seiteSatz, $at);
$saetze = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE node_id = {$satzKnoten->id} AND relation_id = 0");
check('der Datensatz-Block ist eine Tabelle: nicht n Tabellen, je Satz eine Zeile mit Aktionszelle, eigenem Formular und drei Vorspalten', $block !== '' && substr_count($block, '<table class="taxmod-table"') < $saetze && substr_count($block, 'taxmod-table-acts') === $saetze && substr_count($block, 'id="taxmod-record-') === $saetze && substr_count($block, 'taxmod-table-lead') === $saetze * 3, "{$saetze} Sätze");
check('«Belongs to» steht nicht mehr darin', ! str_contains($block, 'Belongs to'));
$einstellungsNamen = $wpdb->get_col("SELECT e.name FROM {$p}relations_named e WHERE e.kind = 'setting' AND e.name <> ''") ?: [];
$drin = [];
if (preg_match('#<thead>.*?</thead>#s', $block, $kopf)) {
    foreach ($einstellungsNamen as $n) {
        if (str_contains($kopf[0], '>' . $n . '<')) {
            $drin[] = $n;
        }
    }
}
check('keine Einstellung steht als Spalte darin', $drin === [], implode(', ', $drin));

$mitEinstellungssatz = $zahl->id;
$vorherDev = get_option(NodesScreen::DEVELOPER_OPTION, false);
update_option(NodesScreen::DEVELOPER_OPTION, 0, false);
$aus = seite($mitEinstellungssatz);
check('ohne Entwicklermodus steht kein Einstellungssatz im Block', ! str_contains($aus, 'taxmod-settings-record') && ! preg_match('/<option value="default"[^>]*selected/', $aus));
update_option(NodesScreen::DEVELOPER_OPTION, 1, false);
$geliehen = static function (string $option, string $wert, callable $tue): mixed {
    $vorher = get_option($option, null);
    try {
        update_option($option, $wert, false);

        return $tue();
    } finally {
        if ($vorher === null) {
            delete_option($option);
        } else {
            update_option($option, $vorher, false);
        }
    }
};
$an = $geliehen('taxmod_dev_settings_record', '1', static fn (): string => seite($mitEinstellungssatz));
check('mit Entwicklermodus steht er da, mit Marke, nicht als umstellbare Art, und nennt den Renderer', str_contains($an, 'taxmod-settings-record') && ! preg_match('/<option value="default"[^>]*selected/', $an) && (bool) preg_match('/taxmod-settings-record-values[^>]*>.*?renderer = /s', $an));

// ⚠️ **Die Renderer-Diagnose, je Zelle** ([D-711](../../docs/NewConcept/90-decision-log.md), sein Wort «je zelle»).
$saetzeAmSatzknoten = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE node_id = {$satzKnoten->id} AND relation_id = 0");
$diagnoseAn  = $geliehen(NodesScreen::DEVELOPER_OPTION, '1', static fn (): string => seite($satzKnoten->id));
$diagnoseAus = $geliehen(NodesScreen::DEVELOPER_OPTION, '0', static fn (): string => seite($satzKnoten->id));
check('im Entwicklermodus steht unter dem Datensatz-Block die Renderer-Diagnose, eine Zeile je Satz', substr_count($diagnoseAn, 'taxmod-record-diagnostic-row') === $saetzeAmSatzknoten, substr_count($diagnoseAn, 'taxmod-record-diagnostic-row') . " Zeilen für {$saetzeAmSatzknoten} Sätze");
check('und je Zelle nennt sie das Feld und seinen Renderer', (bool) preg_match('/taxmod-record-diagnostic-row[^<]*<strong>#\d+<\/strong> · <code>__es zahl<\/code> — int · (field|spinner|slider)/', $diagnoseAn));
check('ohne Entwicklermodus steht sie nicht da', ! str_contains($diagnoseAus, 'taxmod-record-diagnostic'));
foreach (['taxmod_dev_settings_record' => ['taxmod-settings-record', 'der Einstellungssatz'], 'taxmod_dev_writes' => ['taxmod-tree-writes', 'die Schreibzahl'], 'taxmod_dev_root_toggle' => ['taxmod_root', 'der Schalter «show the root»']] as $option => [$marke, $nameOpt]) {
    $anM  = $geliehen($option, '1', static fn (): string => seite($mitEinstellungssatz));
    $ausM = $geliehen($option, '0', static fn (): string => seite($mitEinstellungssatz));
    check("{$nameOpt} steht da, wenn sein Haken an ist, und ist weg, wenn er aus ist (D-705)", str_contains($anM, $marke) && ! str_contains($ausM, $marke));
}
update_option(NodesScreen::DEVELOPER_OPTION, $vorherDev, false);

// ---------------------------------------------------------------------------------------------------

echo "\n== 12 · Die Seite: sie zeichnet, ihre Blöcke, das Fragezeichen, das Stylesheet, die Tabelle in zwei Lagen ==\n";

$_GET = ['page' => 'taxmod-nodes'];
$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, __FILE__);
$screen = $plugin->screen();
$markup = $screen->render();
check('render() liefert Markup, mit den gewählten Grössen am Rahmen', (bool) preg_match('#^<div class="wrap" style="--taxmod-icon:\d+px;--taxmod-font:\d+px">#', $markup));
check('der Baum wird von der Zelle gezeichnet, jede Zeile trägt dieselben vier Knöpfe, und was nicht geht, ist ausgegraut statt fort', str_contains($markup, 'taxmod-tree-node') && substr_count($markup, 'value="trash_node"') === substr_count($markup, 'value="add_child_here"') && substr_count($markup, 'value="up"') === substr_count($markup, 'value="add_child_here"') && str_contains($markup, 'disabled'));
$entwickler = SettingsScreen::developerShows('taxmod_dev_writes');
$papierkorb = SettingsScreen::showsTrash();
check('die Schreibzahl und der Papierkorb stehen genau dann da, wenn ihre Einstellung es sagt', str_contains($markup, 'taxmod-tree-writes') === $entwickler && str_contains($markup, 'class="taxmod-trash"') === $papierkorb);
$_GET['taxmod_search'] = 'zzz-nichts-das-es-gibt-zzz';
$leer = $screen->render();
unset($_GET['taxmod_search']);
check('eine Suche ohne Treffer behält ihr Suchfeld, trägt den Begriff weiter und sagt, dass nichts passt', str_contains($leer, 'taxmod-tree-filter') && str_contains($leer, 'zzz-nichts-das-es-gibt-zzz') && str_contains($leer, 'Nothing matches that.'));
$detail = seite($modellKnoten->id);
check('die Feldtabelle wird vom Zeilenrenderer gezeichnet, der eigene Name ist änderbar, «wie oft» ist ein echter Wähler am richtigen Feld', str_contains($detail, 'taxmod-field"') && str_contains($detail, 'taxmod-field-rename') && str_contains($detail, 'taxmod-choice') && (bool) preg_match('/taxmod_field_setting\[\d+\]\[multiplicity\]/', $detail));
preg_match_all('#\sid="([^"]+)"#', $detail, $alleIds);
$doppelt = array_keys(array_filter(array_count_values($alleIds[1]), static fn (int $n): bool => $n > 1));
check('keine Id kommt zweimal vor', $doppelt === [], implode(', ', $doppelt));
check('eine Zeile ist eine Zeile und kein Formular; ein Knopf ausserhalb nennt seines; kein Zeilen-Akt ohne Schlüssel', ! str_contains($detail, 'class="taxmod-setting" style') && (bool) preg_match('#form="taxmod-(?:page|settings)-\d+"#', $detail) && ! preg_match('#name="do\[\]"#', $detail));
check('jeder Icon-Knopf ist gekennzeichnet, nichts sagt «not defined», und 1..1 steht nirgends im sichtbaren Text (D-376)', substr_count($detail, 'taxmod-icon-button') > 0 && substr_count($detail, '<span class="taxmod-icon') >= substr_count($detail, 'taxmod-icon-button') && ! str_contains($detail, 'not defined') && ! str_contains(strip_tags($detail), '1..1') && str_contains($detail, 'value="1..1">1<'));
check('die Detailhälfte hat ihre eigene Bildlaufleiste', str_contains($detail, 'taxmod-detail-pane'));

// Geerbte und eigene Felder stehen gruppiert, Geerbtes vorn (D-376).
$rangEltern = $editor->createNode('__es Rang Eltern', $modellAst->id);
foreach (['E1', 'E2', 'E3'] as $n) {
    $editor->addField($rangEltern->id, $seeded['text']->id, '__es ' . $n);
}
$rangKind = $editor->createNode('__es Rang Kind', $rangEltern->id);
foreach (['K1', 'K2', 'K3'] as $n) {
    $editor->addField($rangKind->id, $seeded['text']->id, '__es ' . $n);
}
$herkunft = [];
foreach (tabelleUnter(seite($rangKind->id), 'Fields', 'Settings') as $zeile) {
    $woher = $zeile[3] ?? '';
    if ($woher === 'own' || $woher === 'inherited') {
        $herkunft[] = $woher;
    }
}
$wechsel = 0;
for ($i = 1, $n = count($herkunft); $i < $n; $i++) {
    if ($herkunft[$i] !== $herkunft[$i - 1]) {
        ++$wechsel;
    }
}
check('drei geerbte und drei eigene Felder stehen in je einem Block, das Geerbte vorn', count(array_filter($herkunft, static fn (string $w): bool => $w === 'inherited')) === 3 && count(array_filter($herkunft, static fn (string $w): bool => $w === 'own')) === 3 && $wechsel === 1 && ($herkunft[0] ?? '') === 'inherited', implode(' ', $herkunft));

// Das Fragezeichen.
$fragezeichen = static function (string $html): array {
    preg_match_all('/<span class="taxmod-hint" tabindex="0" title="([^"]*)"/', $html, $t);
    preg_match_all('/<span class="taxmod-hint-text">(.*?)<\/span>/s', $html, $x);
    $klartext = static fn (string $roh): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($roh), ENT_QUOTES)));

    return ['titel' => array_map($klartext, $t[1]), 'text' => array_map($klartext, $x[1]), 'huellen' => substr_count($html, 'class="taxmod-hint"')];
};
require_once ABSPATH . 'wp-admin/includes/template.php';
foreach (['Knoten' => [seite($wurzel->id), 3], 'Aufräumen' => [$plugin->cleanupScreen()->render(), 1]] as $seitenName => [$html, $mindestens]) {
    $gefunden = $fragezeichen($html);
    $nurImTitel = 0;
    foreach ($gefunden['titel'] as $i => $titel) {
        if (($gefunden['text'][$i] ?? '') !== $titel) {
            ++$nurImTitel;
        }
    }
    check("«{$seitenName}»: Fragezeichen vorhanden, je eines ein Satz im Markup, keiner leer, keiner nur im title", count($gefunden['titel']) >= $mindestens && count($gefunden['titel']) === count($gefunden['text']) && $gefunden['huellen'] === count($gefunden['titel']) && $nurImTitel === 0, count($gefunden['titel']) . ' Zeichen, ' . count($gefunden['text']) . ' Sätze');
}
$stehengeblieben = [];
$auskunft        = 0;
foreach (glob(dirname(__DIR__, 2) . '/src/WordPress/Admin/*.php') ?: [] as $datei) {
    $zeilen = file($datei, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($zeilen as $nr => $z) {
        if (! preg_match('/[\'"]<(?:p|span|ul|div) class="description/', $z)) {
            continue;
        }
        if (preg_match('/sprintf\(|_n\(|%[ds]|\$/', implode(' ', array_slice($zeilen, $nr, 7)))) {
            ++$auskunft;
            continue;
        }
        $stehengeblieben[] = basename($datei) . ':' . ($nr + 1);
    }
}
check('im Fliesstext steht keine Erklärung mehr, die Auskunft steht weiterhin da', $stehengeblieben === [] && $auskunft >= 4, implode(', ', $stehengeblieben));
$hart = [];
$stellen = 0;
foreach (array_merge(glob(dirname(__DIR__, 2) . '/src/WordPress/*.php') ?: [], glob(dirname(__DIR__, 2) . '/src/WordPress/*/*.php') ?: []) as $datei) {
    $zeilen = file($datei, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($zeilen as $nr => $z) {
        if (! str_contains($z, 'HintMarkup::icon(') && ! str_contains($z, 'HintMarkup::behind(')) {
            continue;
        }
        ++$stellen;
        if (! preg_match('/__\(|_n\(|\$/', implode(' ', array_slice($zeilen, $nr, 4)))) {
            $hart[] = basename($datei) . ':' . ($nr + 1);
        }
    }
}
check('jedes Fragezeichen bekommt einen übersetzten Satz, an mehr als einer Stelle', $hart === [] && $stellen >= 6, implode(', ', $hart));
$fassungen = array_merge(glob(dirname(__DIR__, 2) . '/src/*/HintMarkup.php') ?: [], glob(dirname(__DIR__, 2) . '/src/*/*/HintMarkup.php') ?: []);
$eigenbau = [];
foreach (array_merge(glob(dirname(__DIR__, 2) . '/src/*/*.php') ?: [], glob(dirname(__DIR__, 2) . '/src/*/*/*.php') ?: []) as $datei) {
    if (basename($datei) === 'HintMarkup.php') {
        continue;
    }
    foreach (file($datei, FILE_IGNORE_NEW_LINES) ?: [] as $nr => $z) {
        if (preg_match('/[\'"]<span class="taxmod-hint/', $z)) {
            $eigenbau[] = basename($datei) . ':' . ($nr + 1);
        }
    }
}
$formQuelle    = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Renderer/FormRenderer.php') ?: '';
$compactQuelle = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Renderer/CompactRenderer.php') ?: '';
check('das Fragezeichen hat genau ein Zuhause im Kern, niemand baut es nach, Formular und Kompaktbehälter rufen es', count($fassungen) === 1 && str_contains(str_replace('\\', '/', $fassungen[0]), '/src/Core/') && $eigenbau === [] && str_contains($formQuelle, 'HintMarkup::icon(') && str_contains($compactQuelle, 'HintMarkup::combined(') && str_contains($compactQuelle, 'HintMarkup::icon('), implode(', ', $eigenbau));

require_once ABSPATH . 'wp-admin/includes/plugin.php';
do_action('admin_menu');
do_action('admin_print_styles-toplevel_page_taxmod');
$style = $GLOBALS['wp_styles']->registered['taxmod-admin'] ?? null;
check('das Stylesheet ist auf der Seite eingereiht, unter wp-content/plugins, mit Version', $style !== null && (bool) preg_match('#^https?://[^/]+/wp-content/plugins/[^:]+/assets/admin\.css$#', (string) $style->src) && (string) $style->ver !== '', (string) ($style->src ?? 'keins'));
if ($style !== null) {
    $headers = @get_headers((string) $style->src, true, stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true]]));
    $status  = is_array($headers[0] ?? null) ? $headers[0][0] : ($headers[0] ?? 'no answer');
    check('und ein Browser, der danach fragt, bekommt es', str_contains((string) $status, '200'), (string) $status);
}

$tabelle = $editor->nodeImplementing(TableRenderer::class);
$t63Kante = null;
foreach ($tabelle === null ? [] : $relations->fieldRelationsOf([$tabelle->id]) as $eine) {
    if ($eine->kind === RelationKind::Setting && $eine->name === Orientation::KEY) {
        $t63Kante = $eine;
    }
}
check('der Renderer-Knoten table steht im Modell und trägt die Einstellungskante orientation', $tabelle !== null && $t63Kante !== null);
if ($tabelle !== null && $t63Kante !== null) {
    $t63Wiese  = $editor->createNode('__es Ding', $modellAst->id);
    $t63Links  = $editor->addField($t63Wiese->id, $seeded['text']->id, '__es links');
    $t63Rechts = $editor->addField($t63Wiese->id, $seeded['text']->id, '__es rechts');
    $t63Traeger = $satzVon($t63Wiese->id);
    $rows->forgetValue($t63Traeger, $renderKante, '');
    $rows->putValue(new RelationRecord($t63Traeger, $renderKante, '', TypedValue::ofRecordReference($satzVon($tabelle->id))));
    $t63Zeichnen = static fn (): string => $zeichner()->recordsAsTable($nodes->byId($t63Wiese->id), [$t63Links, $t63Rechts], [['id' => 1, 'values' => [$t63Links->id => TypedValue::ofText('__es A'), $t63Rechts->id => TypedValue::ofText('__es B')], 'lead' => [], 'acts' => [], 'submits' => new Submission('', [])]], 'taxmod_value')->markup;
    $waagerecht = $t63Zeichnen();
    $angabe($nodes->byId($tabelle->id), Orientation::KEY, TypedValue::ofText('vertical'));
    $senkrecht = $t63Zeichnen();
    $ohneAngabe($nodes->byId($tabelle->id), Orientation::KEY);
    $koepfe = substr_count($waagerecht, 'scope="col"');
    check('waagerecht ist die Vorgabe: Kopf oben, keine Zeilenköpfe, eine Zeile je Datensatz', str_contains($waagerecht, 'taxmod-table-horizontal') && str_contains($waagerecht, '<thead>') && ! str_contains($waagerecht, 'scope="row"') && substr_count($waagerecht, 'taxmod-table-row') === 1);
    check('senkrecht kommt beim Umstellen an: Kopf links, keine Kopfzeile, eine Zeile je Spalte, jeder Kopf einmal', str_contains($senkrecht, 'taxmod-table-vertical') && str_contains($senkrecht, 'scope="row"') && ! str_contains($senkrecht, '<thead>') && $koepfe > 1 && substr_count($senkrecht, 'taxmod-table-row') === $koepfe && substr_count($senkrecht, 'scope="row"') === $koepfe);
    $alleStuecke = true;
    foreach (['__es links', '__es rechts', '__es A', '__es B'] as $stueck) {
        $alleStuecke = $alleStuecke && str_contains($waagerecht, $stueck) && str_contains($senkrecht, $stueck);
    }
    check('beide Lagen zeigen Köpfe und Werte', $alleStuecke);
}

// ---------------------------------------------------------------------------------------------------

echo "\n== 13 · Eine Umbenennung ändert nichts an dem, was gezeichnet wird ==\n";

$beschriftungen = new WpdbLabelRepository();
$benenne = static function (IdentitySpace $raum, int $id, string $n) use ($beschriftungen): void {
    $beschriftungen->put(new Label($id, $raum, SeededRole::Name, Label::BASE_NUMBER, SettingsScreen::neutralLocale(), $n));
};
$zeichnetJetzt = static function (array $idListe) use ($nodes, $modell, $registry): array {
    $werte   = $modell();
    $antwort = [];
    foreach ($idListe as $id) {
        $knoten       = $nodes->find($id);
        $antwort[$id] = $knoten === null ? '(kein Knoten)' : ($registry->chosenFor($knoten, $werte->forNode($knoten), Purpose::Edit)?->name() ?? 'nichts');
    }

    return $antwort;
};
$beobachtet = array_values(array_diff($nodes->subtreeIds($wurzel->id), $nodes->subtreeIds($framework->trash()->id), [$wurzel->id]));
sort($beobachtet);
$vorherZeichnung = $zeichnetJetzt($beobachtet);
check('die Auflösung liefert vorher überhaupt etwas', count(array_filter($vorherZeichnung, static fn (string $w): bool => $w !== 'nichts' && $w !== 'plain')) >= 1);
$traegerIds = array_map(intval(...), $wpdb->get_col($wpdb->prepare('SELECT DISTINCT r.node_id FROM ' . Schema::table('relation_records') . ' v JOIN ' . Schema::table('node_records') . " r ON r.id = v.node_record_id WHERE v.relation_id = %d AND v.value_ref_kind = 'node' ORDER BY r.node_id", $renderKante)));
$knotenNamenVorher = [];
foreach ($traegerIds as $id) {
    $knotenNamenVorher[$id] = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}nodes_named WHERE id = %d", $id));
}
$kantenNameVorher = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}relations_named WHERE id = %d", $renderKante));
foreach ($knotenNamenVorher as $id => $n) {
    $benenne(IdentitySpace::Node, (int) $id, 'Etwas ganz anderes');
}
$benenne(IdentitySpace::Relation, $renderKante, 'Etwas ganz anderes');
check('die Träger und die Kante `renderer` heissen jetzt anders', (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}relations_named WHERE id = %d", $renderKante)) === 'Etwas ganz anderes');
$nachherZeichnung = $zeichnetJetzt($beobachtet);
$abweichend = [];
foreach ($beobachtet as $id) {
    if (($nachherZeichnung[$id] ?? null) !== $vorherZeichnung[$id]) {
        $abweichend[] = "#{$id}";
    }
}
check('kein Knoten zeichnet nach der Umbenennung anders (' . count($beobachtet) . ' beobachtet)', $abweichend === [], implode(', ', array_slice($abweichend, 0, 10)));
foreach ($knotenNamenVorher as $id => $n) {
    $benenne(IdentitySpace::Node, (int) $id, $n);
}
$benenne(IdentitySpace::Relation, $renderKante, $kantenNameVorher);
$zurueckGezaehlt = 0;
foreach ($knotenNamenVorher as $id => $n) {
    $zurueckGezaehlt += (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}nodes_named WHERE id = %d", $id)) === $n ? 1 : 0;
}
check('die Namen stehen wieder da', $zurueckGezaehlt === count($knotenNamenVorher) && (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}relations_named WHERE id = %d", $renderKante)) === $kantenNameVorher);

// ---------------------------------------------------------------------------------------------------

echo "\n== 14 · Fassung 38: die Wanderung räumt weg, was unzulässig geworden ist ==\n";

$mgProbe   = $editor->createNode('__es probe', $seeded['int']->id);
$mgErlaubt = array_map(static fn ($r): string => $r->name(), $zeichner()->choicesForNode($mgProbe));
check('`form` zeichnet keinen Int-Knoten, ist also eine unzulässige Wahl', $formKnoten !== null && ! in_array($formKnoten->name, $mgErlaubt, true));
if ($formKnoten !== null) {
    speichern($mgProbe->id, ['taxmod_value' => [(string) $renderKante => (string) $formKnoten->id], 'taxmod_value_override' => [(string) $renderKante => '1']]);
    $mgZeile = (int) $wpdb->get_var("SELECT v.id FROM {$p}relation_records v JOIN {$p}node_records r ON r.id = v.node_record_id WHERE r.node_id = {$mgProbe->id} AND v.relation_id = {$renderKante}");
    check('die unzulässige Wahl steht als Wertzeile da und wirkt', $mgZeile !== 0 && gespeicherterRenderer($mgProbe->id) === $formKnoten->name);
    $mgGefallen = Schema::dropRendererChoicesOutsideTheEligibleSet();
    $mgOption   = get_option('taxmod_renderer_choice_drop');
    check('die Wanderung meldet Knoten und Renderer, auch in der Option', in_array($mgProbe->name . ' → ' . $formKnoten->name, $mgGefallen, true) && is_array($mgOption) && in_array($mgProbe->name . ' → ' . $formKnoten->name, (array) ($mgOption['gefallen'] ?? []), true));
    check('die Wertzeile ist weg, liegt im Schatten, und das Buch nennt Knoten und Renderer', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records WHERE id = {$mgZeile}") === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records_history WHERE id = {$mgZeile}") >= 1 && (string) $wpdb->get_var("SELECT before_state FROM {$p}changelog WHERE owner_kind = 'record_value' AND owner_id = {$mgZeile} AND what = 'renderer choice not eligible dropped' ORDER BY id DESC LIMIT 1") === $mgProbe->name . ' → ' . $formKnoten->name);
    check('der Knoten zeichnet wieder mit einer zulässigen Wahl, und ein zweiter Lauf findet nichts', in_array(gespeicherterRenderer($mgProbe->id), $mgErlaubt, true) && Schema::dropRendererChoicesOutsideTheEligibleSet() === []);
}

echo "\n{$passed} ok, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
