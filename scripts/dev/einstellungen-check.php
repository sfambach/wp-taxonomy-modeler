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
 *   G["0 · Gerüst: die Typen, kein Einstellungsast, der Bestand der Wahlen"] --> W["1 · die Wiese __es: Modell, sieben Felder, ein eigener Zahltyp"]
 *   W --> E["2 · der Vertrag erklärt: Attribute aus der Klasse, keine Einstellungskante, kein Erben vom Vater"]
 *   E --> R["3 · wählen: der Renderer aus dem Vertrag, ein Einstellungsobjekt, frisch lesen, zeichnen"]
 *   R --> S["4 · überschreiben an der Kante: dieselbe Zeile mit Kante, nur mit Haken — 5 · Konflikt: Typ-Standard"]
 *   S --> T["6 · der Einstellungsbereich der Feldzeile: aufklappen, setzen, nur was der Vertrag des Ziels erklärt"]
 *   T --> V["7 · Werte und Steuerelemente — 8 · Konverter — 9 · Vorschau"]
 *   V --> K["10 · Kantenart — 11 · Datensätze — 12 · die Seite — 13 · Umbenennen"]
 * ```
 *
 * ⚠️ **Seit Schritt 4 und 5 des Bauplans (2026-09-11) laufen die Abschnitte 2 bis 6, 8 und die Tabelle in 12 auf dem
 * neuen Einstellungsmodell** ([D-712](../../docs/NewConcept/90-decision-log.md), [`einstellungen-bauplan.md`](../../docs/einstellungen-bauplan.md)):
 * *der Vertrag der Klasse erklärt, `settings_value` trägt, die Kante überschreibt mit Haken; kein Renderer-Knoten,
 * keine Einstellungskante, keine Vererbung von Knoten zu Knoten. Die alten Zusagen zum Einstellungssatz im
 * Entwicklermodus und zur Fassung 38 sind fort; die Stellen im Lauf sagen warum.*
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
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\SeededRole;
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
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\SettingsResolver;
use Taxmod\Core\Validator\ShippedValidators;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingsRepository;
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
    $relations,
    resolver: new SettingsResolver(new WpdbSettingsRepository(), $nodes, ShippedRenderers::registry(), ShippedConverters::registry(), relations: $relations),
    records: $rows
);

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

/** Ob der letzte Akt gelang — «ok» ohne Schreiben, «Saved — n written» mit (D-683). */
function gelungen(): bool
{
    $m = letzteMeldung();

    return $m === 'ok' || str_starts_with($m, 'Saved');
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

/**
 * Die ganze Zeile zu dieser Einstellungskante — Haken vorn, Sperre, Wert und Herkunft in der Wertspalte.
 *
 * ⚠️ *Sein Wort am 2026-09-10: «das override tickfeld mal an den anfang der setting zeile und als richtige
 * spalte» — seither ist die Zeile der Rahmen, nicht mehr die Wertspalte.*
 */
function wertspalte(string $markup, int $kante): string
{
    $wo = strpos($markup, 'name="taxmod_value[' . $kante . ']"');

    if ($wo === false) {
        return '';
    }

    // Die Spalte mit dem Haken, vorn in derselben Zeile.
    $trAnfang = strrpos(substr($markup, 0, $wo), '<tr');
    $haken    = '';

    if ($trAnfang !== false && preg_match('/<td class="taxmod-field-override"[^>]*>(.*?)<\/td>/s', substr($markup, $trAnfang, $wo - $trAnfang), $h) === 1) {
        $haken = $h[1];
    }

    // Die Wertspalte: der gesperrte Rahmen um das Steuerelement, bis zur Herkunft in Worten — ohne die
    // Tafel des Renderers darunter, die in derselben Zelle steht.
    preg_match_all('/<span class="taxmod-setting-locked(?: taxmod-setting-automatic)?">/', substr($markup, 0, $wo), $treffer, PREG_OFFSET_CAPTURE);
    $letzter = end($treffer[0]);
    $anfang  = $letzter === false ? false : (int) $letzter[1];
    $zelle   = strrpos(substr($markup, 0, $wo), 'taxmod-field-value');

    if ($anfang === false || ($zelle !== false && $anfang < $zelle)) {
        return $haken . substr($markup, $wo, 400);
    }

    $ende = strpos($markup, '</em></span>', $wo);

    return $haken . substr($markup, $anfang, ($ende === false ? $wo + 400 : $ende + 12) - $anfang);
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
    $leser     = new SettingsResolver(new WpdbSettingsRepository(), $nodes, ShippedRenderers::registry(), ShippedConverters::registry());
    $node      = $nodes->find($nodeId);

    return $node === null ? '' : (string) (($leser->forNode($node)['renderer'] ?? null)?->value->text ?? '');
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

$modellAst   = $framework->rootOf(Branch::Model);
$wurzel      = $framework->root();

// ---------------------------------------------------------------------------------------------------

echo "== 0 · Das Gerüst: die Typen stehen, kein Einstellungsast mehr, der Bestand der Wahlen ==\n";

// ⚠️ **Bis zum 2026-09-11 prüfte dieser Abschnitt die Renderer-Knoten unter `Settings`, ihre Optionen, die
// Einstellungskante `renderer` an der Wurzel und den Bestand der Wahlen in `relation_records`.** *Seit Schritt 7
// des Bauplans gibt es nichts davon: Renderer, Konverter und Validatoren sind Objekte programmierter Klassen
// ([D-712](../../docs/NewConcept/90-decision-log.md)), der Ast `Settings` ist in den Schatten gewandert
// ([D-718](../../docs/NewConcept/90-decision-log.md)), die Rollen wohnen unter `Constants` ([D-719](../../docs/NewConcept/90-decision-log.md)).
// Was bleibt, ist die Einstellungskante `position` an der Wurzel — bis Modell 2.4 entschieden ist.*
check('den Ast Settings gibt es nicht mehr (D-718)', get_option('taxmod_branch_settings_id', null) === null && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name = 'Settings' AND parent_node_id = {$wurzel->id}") === 0);
check('die Rollen wohnen unter Constants (D-719, «K3c unter constants»)', (int) $wpdb->get_var('SELECT parent_node_id FROM ' . Schema::table('nodes') . ' WHERE id = ' . (int) get_option('taxmod_roles_id', 0)) === $framework->rootOf(Branch::Constants)->id);
$lebendeEinstellungskanten = $wpdb->get_col("SELECT name FROM {$p}relations_named WHERE kind = 'setting'") ?: [];
check('genau eine Einstellungskante lebt noch: position an der Wurzel — bis Modell 2.4 entschieden ist', $lebendeEinstellungskanten === ['position'], implode(',', $lebendeEinstellungskanten));
check('keine Renderer-Knoten mehr: keine Option taxmod_render_*, kein Knoten mit einer Renderer-Klasse', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'taxmod\\_render\\_%'") === 0 && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . " WHERE implemented_by LIKE '%Renderer%'") === 0);
check('keine Einstellungssätze mehr — nur die Stellen geerbter Felder (Modell 2.2) dürfen einen tragen', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records r WHERE r.record_type = 'settings' AND NOT EXISTS (SELECT 1 FROM {$p}relation_records v WHERE v.node_record_id = r.id)") === 0);
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
if (count(array_intersect_key($seeded, array_flip(['int', 'decimal', 'text', 'bool', 'email', 'datetime', 'color']))) < 7) {
    echo "\n{$passed} ok, {$failed} failed\n";
    exit(1);
}

$bestandZeichnen = $zeichner();
$traegerZahl = 0;
$mitNamen    = 0;
$unerlaubt   = [];
$merkmal     = ['slider' => 'type="range"', 'toggle' => 'taxmod-toggle', 'spinner' => 'type="number"', 'field' => 'type="text"', 'checkbox' => 'type="checkbox"'];
$daneben     = [];
// ⚠️ **Seit Schritt 4 des Bauplans (2026-09-11) ist der Bestand einer Wahl `settings_value` ([D-712](../../docs/NewConcept/90-decision-log.md)).**
// *Die alten Renderer-Wahlen in `relation_records` liest niemand mehr; sie fallen mit Schritt 7. Sein Wort zum
// Anfang: «wir beginnen leer dann können wir schön testen» ([D-717](../../docs/NewConcept/90-decision-log.md)) —
// darum verlangt die Zusage keinen Träger mehr, nur dass keiner daneben steht.*
foreach ($wpdb->get_results("SELECT DISTINCT node_id FROM {$p}settings_value WHERE attribut = 'renderer' AND node_id IS NOT NULL AND relation_id IS NULL AND wert_settings_object_id IS NOT NULL", ARRAY_A) ?: [] as $zeile) {
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
check('jeder Träger einer Wahl im Bestand löst zu einem Renderer auf', $traegerZahl === $mitNamen, ($traegerZahl - $mitNamen) . ' von ' . $traegerZahl);
check('keine gespeicherte Wahl steht ausserhalb der zulässigen Menge', $unerlaubt === [], implode(' · ', array_slice($unerlaubt, 0, 6)));
check('und die Zeichnung trägt das Merkmal des gesetzten Renderers', $daneben === [], implode(' · ', array_slice($daneben, 0, 4)));

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
$chainSays = ($rendering->settingsForUseSites([$count])[$count->id]['renderer'] ?? null)?->value->text;
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

echo "\n== 2 · Der Vertrag erklärt: die Attribute kommen aus der Klasse, nicht aus einer Einstellungskante ==\n";

// ⚠️ **Bis zum 2026-09-11 stand hier «Erklären: eine Einstellungskante, die Art über die Maske».** *Das
// ist mit [D-712](../../docs/NewConcept/90-decision-log.md) gefallen — sein Gerüst: «Einstellungen sind
// Attribute von programmierten Knotenklassen». Was ein Knoten einstellen kann, sagt der Vertrag seiner
// Klasse ([`einstellungen-anforderungen.md`](../../docs/einstellungen-anforderungen.md) §2.4); die Maske
// zeichnet ihn unter `taxmod_setting[<attribut>]` — der Name des Attributs, keine Kantennummer. **Und kein
// Knoten erbt einen Wert vom Vater** — sein Wort: «vererbung von knoten settings in knoten ist grundsätzlich
// raus»; die Klasse liefert die Vorgabe, der Knoten setzt, die Kante überschreibt (Abschnitt 4).*
$vertragInt = \Taxmod\Core\Model\NodeClass\Contracts::of(\Taxmod\Core\Model\Type\IntType::class);
check('der Vertrag von Integer erklärt min, max, step, display_size und die drei der Basisklasse', array_values(array_diff(['min', 'max', 'step', 'renderer', 'converter', 'validator', 'display_size'], array_keys($vertragInt->attributes))) === [], implode(',', array_keys($vertragInt->attributes)));
$markup = seite($zahl->id);
preg_match_all('/name="taxmod_setting\[([a-z_]+)\]"/', $markup, $treffer);
$imBereich = array_values(array_unique($treffer[1]));
check('die Seite eines Zahltyps zeichnet genau diese Attribute — und nichts, was der Vertrag nicht kennt', array_values(array_diff($imBereich, array_keys($vertragInt->attributes))) === [] && count(array_intersect(['min', 'max', 'step', 'display_size', 'renderer'], $imBereich)) === 5, implode(',', $imBereich));
check('kein read_only am Knoten (D-714), keine Kantennummer als Name', ! in_array('read_only', $imBereich, true) && ! preg_match('/name="taxmod_setting\[\d+\]"/', $markup));
check('die Vorgabe des Vertrags steht in der Maske: display_size 20', (bool) preg_match('/name="taxmod_setting\[display_size\]"[^>]*value="20"/', $markup));
$zeilenAm = static fn (int $nodeId, string $attribut = ''): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE node_id = {$nodeId}" . ($attribut === '' ? '' : " AND attribut = '{$attribut}'"));
check('und die Vorgabe hat keine Zeile — nur Gesetztes wird gespeichert (4.5.1)', $zeilenAm($zahl->id) === 0, (string) $zeilenAm($zahl->id));
$markupModell = seite($modellKnoten->id);
preg_match_all('/name="taxmod_setting\[([a-z_]+)\]"/', $markupModell, $trefferModell);
check('ein Ding unter Model kennt renderer, converter, validator — keine Grenzen, kein display_size (D-724)', array_values(array_diff(['renderer', 'converter', 'validator'], $trefferModell[1])) === [] && ! in_array('min', $trefferModell[1], true) && ! in_array('display_size', $trefferModell[1], true), implode(',', array_unique($trefferModell[1])));
$speicherbar = static fn (int $nodeId, string $attribut, string $wert): bool => speichern($nodeId, ['taxmod_setting' => [$attribut => $wert]]);
$aufgeloest  = static fn (int $nodeId): array => $zeichner()->settingsForNode($nodes->byId($nodeId));
$speicherbar($zahl->id, 'max', 'viele');
check('ein Wert, der nicht zum Typ passt, wird abgewiesen, nichts geschrieben', ! gelungen() && $zeilenAm($zahl->id) === 0, letzteMeldung());
$speicherbar($zahl->id, 'max', '999');
check('ein passender Wert wird geschrieben — eine Zeile, Adresse Klasse.Attribut, ohne Kante', gelungen() && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE node_id = {$zahl->id} AND attribut = 'max' AND klasse = '" . esc_sql(\Taxmod\Core\Model\Type\IntType::class) . "' AND wert_int = 999 AND relation_id IS NULL") === 1, letzteMeldung());
check('und die Auflösung liest ihn als hier gesetzt', ($aufgeloest($zahl->id)['max'] ?? null)?->setHere === true && $aufgeloest($zahl->id)['max']->value->int === 999);
check('die Maske zeigt ihn danach', (bool) preg_match('/name="taxmod_setting\[max\]"[^>]*value="999"/', seite($zahl->id)));
$speicherbar($zahl->id, 'display_size', '20');
check('die Vorgabe noch einmal geschickt: keine Zeile', $zeilenAm($zahl->id, 'display_size') === 0);
$speicherbar($zahl->id, 'max', '');
check('leer geschickt: die Zeile wandert in den Schatten', $zeilenAm($zahl->id, 'max') === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value_history WHERE node_id = {$zahl->id} AND attribut = 'max' AND deleted = 1") === 1);
check('ein Kind von Integer kennt dieselben Attribute — die Klasse erklärt, nicht der Vater', array_values(array_diff(['min', 'max', 'step'], array_keys(\Taxmod\Core\Model\NodeClass\Contracts::of($nodes->byId($schmal->id)->klasse)->attributes))) === []);
$speicherbar($zahl->id, 'max', '999');
check('aber nicht den Wert: das Kind sieht die 999 des Vaters nicht (D-712)', ! isset($aufgeloest($schmal->id)['max']));
$speicherbar($zahl->id, 'max', '');

// ---------------------------------------------------------------------------------------------------

echo "\n== 3 · Wählen: der Renderer aus dem Vertrag, speichern, frisch lesen, zeichnen ==\n";

/** Die Namen, die der Renderer-Wähler auf der Seite anbietet. */
$angebotDerSeite = static function (string $markup): array {
    $hinter = preg_split('/name="taxmod_setting\[renderer\]"/', $markup)[1] ?? '';
    preg_match_all('/<option value="([^"]*)"/', explode('</select>', $hinter)[0], $t);

    return array_values(array_filter($t[1] ?? [], static fn (string $n): bool => $n !== ''));
};
/** Der Renderer, wie die Auflösung ihn an einem Knoten liest. */
$gewaehlterRenderer = static fn (int $nodeId): string => (string) (($zeichner()->settingsForNode($nodes->byId($nodeId))['renderer'] ?? null)?->value->text ?? '');
/** Die Einstellungsobjekte hinter der Zeile `renderer` eines Knotens, in ihrer Reihenfolge. */
$objekte = static fn (int $nodeId): array => $wpdb->get_results("SELECT o.id, o.klasse FROM {$p}settings_object o JOIN {$p}settings_value v ON v.wert_settings_object_id = o.id WHERE v.node_id = {$nodeId} AND v.attribut = 'renderer' AND v.relation_id IS NULL ORDER BY v.position, v.id", ARRAY_A) ?: [];
$markup  = seite($zahl->id);
$angebot = $angebotDerSeite($markup);
check('die Zeile `renderer` zeichnet einen Wähler, und er hängt am Seitenformular', (bool) preg_match('/<select name="taxmod_setting\[renderer\]" form="taxmod-page-' . $zahl->id . '"/', $markup));
check('er bietet einem Zahltyp genau field, spinner, slider an', count($angebot) === 3 && array_values(array_diff($angebot, ['field', 'spinner', 'slider'])) === [], implode(',', $angebot));
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
check('unter `constants` steht `reference` zur Wahl', in_array('reference', $angebotDerSeite(seite($unterKonstanten->id)), true));
$editor->createNode('__es ein Kind', $unterKonstanten->id);
$namenMitKind = $angebotDerSeite(seite($unterKonstanten->id));
// ⚠️ *Seit D-727 (2026-09-12) ein Wähler mit Schalter `dialog` — vorher zwei Namen.*
check('mit einem Kind steht der Wähler zur Wahl', in_array('chooser', $namenMitKind, true), implode(',', $namenMitKind));
check('kein Renderer ist gewählt, solange niemand wählt — die Maske zeigt die Vorgabe, gezeichnet wird mit dem Typstandard', $gewaehlterRenderer($zahl->id) === FieldRenderer::NAME && ($zeichner()->settingsForNode($nodes->byId($zahl->id))['renderer'] ?? null)?->setHere === false && gezeichnet($zeichner(), $eins->id)[0] === FieldRenderer::NAME, $gewaehlterRenderer($zahl->id) . ' / ' . gezeichnet($zeichner(), $eins->id)[0]);
$speicherbar($zahl->id, 'renderer', SpinnerRenderer::NAME);
check('der Akt «Renderer wählen» läuft durch', gelungen(), letzteMeldung());
check('die Wahl steht nach dem Speichern da', $gewaehlterRenderer($zahl->id) === SpinnerRenderer::NAME, $gewaehlterRenderer($zahl->id));
check('sie ist ein Einstellungsobjekt der Klasse SpinnerRenderer, das eine Zeile am Knoten nennt', count($objekte($zahl->id)) === 1 && $objekte($zahl->id)[0]['klasse'] === SpinnerRenderer::class, json_encode($objekte($zahl->id)));
check('und die Maske zeigt danach, was dasteht', (bool) preg_match('/<option value="spinner" selected/', seite($zahl->id)));
$objektVorher = (int) ($objekte($zahl->id)[0]['id'] ?? 0);
$speicherbar($zahl->id, 'renderer', SpinnerRenderer::NAME);
check('zweimal dieselbe Wahl, dasselbe Objekt', $objektVorher !== 0 && (int) ($objekte($zahl->id)[0]['id'] ?? 0) === $objektVorher);
$speicherbar($zahl->id, 'renderer', 'slider');
check('eine zweite Wahl ersetzt die erste — ein Objekt, nicht zwei; das alte im Schatten', $gewaehlterRenderer($zahl->id) === 'slider' && count($objekte($zahl->id)) === 1 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_object_history WHERE id = {$objektVorher} AND deleted = 1") === 1, $gewaehlterRenderer($zahl->id) . ' / ' . count($objekte($zahl->id)));
$markups = [];
$fehler  = [];
foreach ($angebot as $rname) {
    $speicherbar($zahl->id, 'renderer', $rname);
    [$gezeichneterName, $m] = gezeichnet($zeichner(), $eins->id);
    if ($gezeichneterName !== $rname) {
        $fehler[] = "gewählt «{$rname}», gezeichnet «{$gezeichneterName}»";
    }
    $markups[$rname] = $m;
}
check('jede Wahl zeichnet das Feld danach mit dem gewählten Renderer', $fehler === [] && $markups !== [], implode('; ', $fehler));
check('keine fällt auf den Rückfall, und verschiedene Wahlen zeichnen verschieden', ! array_filter($markups, static fn (string $m): bool => str_contains($m, 'taxmod-no-renderer')) && count(array_unique(array_values($markups))) === count($markups));
/** @return array<int,string> Kanten-Id => Name des Renderers, mit dem das Feld gezeichnet wird. */
$gezeichnetFuer = static function (array $kanten) use ($zeichner): array {
    $aus = [];
    foreach ($zeichner()->fieldsFor($kanten, [], Purpose::Edit, 'taxmod_value') as $feld) {
        $aus[$feld->relation->id] = $feld->rendererName;
    }

    return $aus;
};
$speicherbar($zahl->id, 'renderer', SpinnerRenderer::NAME);
$jetzt = $gezeichnetFuer([$eins, $zwei, $tief]);
check('jede Verwendung sieht die Wahl am Zielknoten — einmal gesetzt, nicht je Verwendung', $jetzt[$eins->id] === SpinnerRenderer::NAME && $jetzt[$zwei->id] === SpinnerRenderer::NAME, implode(',', $jetzt));
check('ein Kind des Typs erbt sie NICHT (D-712): es zeichnet mit seinem Typstandard', $jetzt[$tief->id] === FieldRenderer::NAME, $jetzt[$tief->id]);
$speicherbar($schmal->id, 'renderer', 'slider');
$jetzt = $gezeichnetFuer([$eins, $tief]);
check('das Kind wählt selbst, und nur dort gilt es', $jetzt[$tief->id] === 'slider' && $jetzt[$eins->id] === SpinnerRenderer::NAME, implode(',', $jetzt));
$speicherbar($schmal->id, 'renderer', '');
$jetzt = $gezeichnetFuer([$tief]);
check('weggenommen fällt es auf den Typstandard zurück, nicht auf den Vater', $jetzt[$tief->id] === FieldRenderer::NAME, $jetzt[$tief->id]);

// ---------------------------------------------------------------------------------------------------

// ⚠️ *«erlaubte präfixe müsste multi auswahl sein … eine schalter kaskade» (D-732, 2026-09-12).*
$einheit    = $editor->createNode('__es Einheit', $framework->rootOf(Branch::Constants)->id, \Taxmod\Core\Model\NodeClass\Unit::class);
$praefixe   = $editor->childrenOf((int) \Taxmod\WordPress\Persistence\UnitScaffold::nodeId(\Taxmod\WordPress\Persistence\UnitScaffold::PREFIXES_NAME));
$kaskade    = seite($einheit->id);
check('erlaubte_praefixe ist eine Kaskade: je Präfix ein Haken, kein Auswahlfeld', $praefixe !== [] && substr_count($kaskade, 'name="taxmod_setting_set[erlaubte_praefixe][') === 2 * count($praefixe) && ! str_contains($kaskade, 'name="taxmod_setting[erlaubte_praefixe]"'), substr_count($kaskade, 'name="taxmod_setting_set[erlaubte_praefixe][') . ' Felder bei ' . count($praefixe) . ' Präfixen');
$kilo  = (int) \Taxmod\WordPress\Persistence\UnitScaffold::nodeId('kilo');
$milli = (int) \Taxmod\WordPress\Persistence\UnitScaffold::nodeId('milli');
speichern($einheit->id, ['taxmod_setting_set' => ['erlaubte_praefixe' => [(string) $kilo => '1', (string) $milli => '1']]]);
$leserKaskade = new SettingsResolver(new WpdbSettingsRepository(), $nodes, ShippedRenderers::registry(), ShippedConverters::registry());
$glieder = $leserKaskade->listOf($nodes->byId($einheit->id), 'erlaubte_praefixe');
check('zwei Haken sind zwei aktive Glieder mit Verweis', count(array_filter($glieder, static fn ($g): bool => $g->aktiv)) === 2 && in_array($kilo, array_map(static fn ($g) => $g->reference, $glieder), true), count($glieder) . ' Glieder');
speichern($einheit->id, ['taxmod_setting_set' => ['erlaubte_praefixe' => [(string) $kilo => '1', (string) $milli => '0']]]);
$leserKaskade->forget();
$glieder = $leserKaskade->listOf($nodes->byId($einheit->id), 'erlaubte_praefixe');
check('ein Haken weg: das Glied bleibt, nicht aktiv (Z3a)', count($glieder) === 2 && count(array_filter($glieder, static fn ($g): bool => $g->aktiv)) === 1, count($glieder) . ' Glieder');
check('und die Seite zeigt genau den einen Haken', substr_count(seite($einheit->id), 'value="1" checked') >= 1);

// ⚠️ *«wir müssen typ wechsel möglich machen … die einstellungen die nicht übereinstimmen gehen dabei verloren» (D-733).*
check('die Systemzeile bietet die eigene Klasse zur Wahl', str_contains(seite($einheit->id), 'name="klasse" class="taxmod-toolbar-class" form="'));
// *Geslasht wie WordPress es täte — der Rand entschlasht (CD-5).*
speichern($einheit->id, ['klasse' => wp_slash(\Taxmod\Core\Model\NodeClass\Constant::class)]);
$gewechselt = $nodes->byId($einheit->id);
check('der Wechsel auf Konstante ist geschrieben, eine Fassung weiter', $gewechselt->klasse === \Taxmod\Core\Model\NodeClass\Constant::class && $gewechselt->version === $einheit->version + 1, $gewechselt->klasse . ' v' . $gewechselt->version);
check('die Präfixglieder sind mit dem Wechsel gefallen — die Konstante erklärt sie nicht', ((new WpdbSettingsRepository())->valuesOfNodes([$einheit->id])[$einheit->id] ?? []) === []);

echo "\n== 4 · Überschreiben an der Kante: dieselbe Zeile mit Kante, nur auf Wunsch (5.1–5.5) ==\n";

/** Die Auflösung an einer Verwendungsstelle. */
$anDerKante = static fn (int $relationId): array => $zeichner()->settingsForUseSites([$relations->byId($relationId)])[$relationId] ?? [];
/** Die Zeile eines Attributs im aufgeklappten Bereich der Feldzeile. */
$zeileImBereich = static function (string $markup, int $relationId, string $attribut): string {
    $wo = strpos($markup, 'name="taxmod_field_setting[' . $relationId . '][' . $attribut . ']"');
    if ($wo === false) {
        return '';
    }
    $anfang = strrpos(substr($markup, 0, $wo), '<tr');
    $anfang = $anfang === false ? $wo : $anfang;

    return substr($markup, $anfang, ($wo - $anfang) + 600);
};
$speicherbar($zahl->id, 'max', '999');
$auf   = seite($modellKnoten->id, (string) $eins->id);
$zeile = $zeileImBereich($auf, $eins->id, 'max');
check('an der Stelle steht der Wert des Knotens als geerbt, gesperrt, mit dem Haken «hier überschreibe ich»', ($anDerKante($eins->id)['max'] ?? null)?->setHere === false && $anDerKante($eins->id)['max']->value->int === 999 && str_contains($zeile, 'taxmod-setting-locked') && str_contains($auf, 'name="taxmod_field_setting_override[' . $eins->id . '][max]"'), substr(strip_tags($zeile), 0, 120));
check('die Herkunft steht in Worten', str_contains($zeile, 'inherited from __es Zahl'), substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($zeile))), 0, 160));
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $eins->id => ['max' => '7']]]);
check('ohne Haken schreibt das Speichern die geerbte Zeile nicht', $anDerKante($eins->id)['max']->value->int === 999 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE relation_id = {$eins->id}") === 0);
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $eins->id => ['max' => '7']], 'taxmod_field_setting_override' => [(string) $eins->id => ['max' => '1']]]);
check('mit Haken steht der Wert an der Kante — eine Zeile am Zielknoten, die die Kante nennt', gelungen() && $anDerKante($eins->id)['max']->value->int === 7 && $anDerKante($eins->id)['max']->setHere && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE relation_id = {$eins->id} AND node_id = {$zahl->id} AND attribut = 'max' AND wert_int = 7") === 1, letzteMeldung());
check('der Knoten bleibt bei 999, die andere Verwendung auch', $aufgeloest($zahl->id)['max']->value->int === 999 && $anDerKante($zwei->id)['max']->value->int === 999);
$auf = seite($modellKnoten->id, (string) $eins->id);
check('die aufgeklappte Zeile zeigt 7, nicht mehr gesperrt', (bool) preg_match('/name="taxmod_field_setting\[' . $eins->id . '\]\[max\]"[^>]*value="7"/', $auf) && ! str_contains($zeileImBereich($auf, $eins->id, 'max'), 'taxmod-setting-locked'));
$speicherbar($zahl->id, 'max', '');
check('nimmt der Knoten seinen Wert weg, bleibt der der Kante', $anDerKante($eins->id)['max']->value->int === 7 && ! isset($aufgeloest($zahl->id)['max']));
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $eins->id => ['max' => '']]]);
check('leer an der Kante nimmt die Zeile heraus', ! isset($anDerKante($eins->id)['max']));
$speicherbar($zahl->id, 'renderer', SpinnerRenderer::NAME);
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $eins->id => ['renderer' => 'slider']], 'taxmod_field_setting_override' => [(string) $eins->id => ['renderer' => '1']]]);
check('ein anderer Renderer an der Kante: dort slider, am Knoten und an der anderen Verwendung spinner', ($anDerKante($eins->id)['renderer'] ?? null)?->value->text === 'slider' && $gewaehlterRenderer($zahl->id) === SpinnerRenderer::NAME && ($anDerKante($zwei->id)['renderer'] ?? null)?->value->text === SpinnerRenderer::NAME, (($anDerKante($eins->id)['renderer'] ?? null)?->value->text ?? '-') . ' / ' . $gewaehlterRenderer($zahl->id));
$jetzt = $gezeichnetFuer([$eins, $zwei]);
check('und gezeichnet wird so', $jetzt[$eins->id] === 'slider' && $jetzt[$zwei->id] === SpinnerRenderer::NAME, implode(',', $jetzt));
check('das geerbte Glied ist an der Kante abgeschaltet (5.5.3), das eigene steht davor', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE relation_id = {$eins->id} AND node_id = {$zahl->id} AND attribut = 'renderer' AND aktiv = 0") === 1 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE relation_id = {$eins->id} AND node_id = {$zahl->id} AND attribut = 'renderer' AND aktiv = 1") === 1);
$eltern  = $editor->createNode('__es Eltern', $modellAst->id);
$verweis = $editor->addField($eltern->id, $modellKnoten->id, '__es kontakt');
$speicherbar($modellKnoten->id, 'renderer', CompactRenderer::NAME);
$markupDing = seite($modellKnoten->id);
check('ein Ding wählt compact, und die Attribute des Renderers erscheinen: orientation, with_label', $gewaehlterRenderer($modellKnoten->id) === CompactRenderer::NAME && (bool) preg_match('/name="taxmod_setting\[orientation\]"/', $markupDing) && (bool) preg_match('/name="taxmod_setting\[with_label\]"/', $markupDing), $gewaehlterRenderer($modellKnoten->id));
$speicherbar($modellKnoten->id, 'orientation', 'vertical');
check('ein Attribut des Renderers wird im Objekt gespeichert, nicht am Knoten', ($aufgeloest($modellKnoten->id)['orientation'] ?? null)?->value->text === 'vertical' && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value v JOIN {$p}settings_object o ON o.id = v.settings_object_id WHERE v.attribut = 'orientation' AND o.klasse = '" . esc_sql(CompactRenderer::class) . "' AND v.wert_text = 'vertical'") >= 1 && $zeilenAm($modellKnoten->id, 'orientation') === 0, (($aufgeloest($modellKnoten->id)['orientation'] ?? null)?->value->text ?? '-'));
speichern($eltern->id, ['taxmod_field_setting' => [(string) $verweis->id => ['orientation' => 'horizontal']], 'taxmod_field_setting_override' => [(string) $verweis->id => ['orientation' => '1']]]);
check('an der Kante wird der eine Wert im geerbten Objekt überschrieben (5.4): dort horizontal, am Knoten vertical', ($anDerKante($verweis->id)['orientation'] ?? null)?->value->text === 'horizontal' && $aufgeloest($modellKnoten->id)['orientation']->value->text === 'vertical' && ($anDerKante($verweis->id)['renderer'] ?? null)?->value->text === CompactRenderer::NAME, (($anDerKante($verweis->id)['orientation'] ?? null)?->value->text ?? '-') . ' / ' . (($anDerKante($verweis->id)['renderer'] ?? null)?->value->text ?? '-'));
$speicherbar($modellKnoten->id, 'orientation', '');
$speicherbar($modellKnoten->id, 'renderer', '');

// ---------------------------------------------------------------------------------------------------

echo "\n== 5 · Konflikt: eine Wahl, die an der Stelle nicht zeichnen kann, fällt auf den Typstandard (D-687, D-688) ==\n";

$speicherbar($zahl->id, 'renderer', CompactRenderer::NAME);
check('`__es Zahl` trägt `compact` als eigene Wahl — hier gewählt ist Rat, kein Zaun (D-360)', $gewaehlterRenderer($zahl->id) === CompactRenderer::NAME, $gewaehlterRenderer($zahl->id));
$standard = $registry->defaultFor(SimpleType::Int)->name();
$jetzt    = $gezeichnetFuer([$zwei]);
check("an einer Verwendung, die `compact` nicht zeichnen kann, gilt der Typstandard `{$standard}`", $jetzt[$zwei->id] === $standard, $jetzt[$zwei->id]);
$speicherbar($zahl->id, 'renderer', SpinnerRenderer::NAME);
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $eins->id => ['renderer' => '']], 'taxmod_field_setting_override' => [(string) $eins->id => ['renderer' => '1']]]);
$jetzt = $gezeichnetFuer([$eins, $zwei]);
check('die Kante ohne eigene Wahl zeichnet wieder mit der Wahl des Knotens', $jetzt[$eins->id] === SpinnerRenderer::NAME && $jetzt[$zwei->id] === SpinnerRenderer::NAME, implode(',', $jetzt));
$stumm = $editor->createNode('__es stumm', $wurzel->id);
check('ein Knoten ohne eigene Aussage bekommt nichts von der Wurzel — nur die Vorgabe, das Formular; es gibt keine Kette mehr (D-712)', $gewaehlterRenderer($stumm->id) === FormRenderer::NAME && ($zeichner()->settingsForNode($nodes->byId($stumm->id))['renderer'] ?? null)?->setHere === false, $gewaehlterRenderer($stumm->id));

// ---------------------------------------------------------------------------------------------------

echo "\n== 6 · Der Einstellungsbereich der Feldzeile: aufklappen, setzen — die Attribute des Ziels aus dem Vertrag ==\n";

$feldName = 'taxmod_field_setting[' . $eins->id . '][' . 'min' . ']';
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
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $eins->id => ['min' => '7']]]);
check('eine Einstellung setzen: der Akt läuft durch', gelungen(), letzteMeldung());
check('der Wert steht an der Kante, nicht am Zielknoten', ($anDerKante($eins->id)['min'] ?? null)?->setHere === true && $anDerKante($eins->id)['min']->value->int === 7 && ! isset($aufgeloest($zahl->id)['min']));
check('und die aufgeklappte Zeile zeigt ihn wieder', (bool) preg_match('/name="' . preg_quote($feldName, '/') . '"[^>]*value="7"/', seite($modellKnoten->id, (string) $eins->id)));
preg_match_all('/name="taxmod_field_setting\[' . $eins->id . '\]\[([a-z_]+)\]"/', $auf, $tafelTreffer);
$angeboteneSchluessel = array_values(array_unique($tafelTreffer[1]));
$erklaert        = array_keys(\Taxmod\Core\Model\NodeClass\Contracts::of($nodes->byId($zahl->id)->klasse)->attributes);
$gewaehlteKlasse = $registry->classFor($gewaehlterRenderer($zahl->id));
if ($gewaehlteKlasse !== null) {
    $erklaert = [...$erklaert, ...array_keys(\Taxmod\Core\Model\NodeClass\Contracts::ofValueClass($gewaehlteKlasse)->attributes)];
}
$erklaert[]   = \Taxmod\Core\Model\EdgeColumn::MULTIPLICITY;
$erklaert[]   = \Taxmod\Core\Model\EdgeColumn::READ_ONLY;
$erklaert[]   = \Taxmod\Core\Model\EdgeColumn::UNIQUE;
$erklaert[]   = Rendering::KIND_KEY;
$ueberzaehlig = array_values(array_diff($angeboteneSchluessel, $erklaert));
check('der Einstellungsbereich bietet Schlüssel an, und jeder steht im Vertrag des Ziels oder seines Renderers', count($angeboteneSchluessel) >= 3 && $ueberzaehlig === [], 'nicht erklärt: ' . implode(',', $ueberzaehlig) . ' von ' . implode(',', $angeboteneSchluessel));
check('display_size ist dabei — die Typklasse erklärt es (3.6.2, D-724)', in_array('display_size', $angeboteneSchluessel, true));
$html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', seite($kind->id)) ?? '';
preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $html, $zeilenKind);
$offeneGeerbte = 0;
$geerbte       = 0;
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
$eigene          = 0;
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
$speicherbar($seeded['text']->id, 'display_size', '42');
$markup = seite($eltern->id, (string) $feldText->id);
preg_match_all('/name="taxmod_field_setting_override\[' . $feldText->id . '\]\[([^\]]+)\]"/', $markup, $haken);
$schluessel = array_values(array_unique($haken[1] ?? []));
check('die aufgeklappte Zeile trägt die gesperrte, vom Typ gesetzte Einstellung mit Haken und nennt ihre Herkunft', in_array('display_size', $schluessel, true) && (bool) preg_match('/taxmod-setting-locked.*?inherited from Text/s', $markup), implode(',', $schluessel));
speichern($eltern->id, ['taxmod_field_setting' => [(string) $feldText->id => ['display_size' => '12']]]);
$a = $anDerKante($feldText->id)['display_size'] ?? null;
check('ohne Haken bleibt `display_size` an der Stelle geerbt', $a !== null && ! $a->setHere && $a->value->int === 42, $a === null ? '-' : ($a->setHere ? 'gesetzt' : 'geerbt') . ' ' . $a->value->int);
speichern($eltern->id, ['taxmod_field_setting' => [(string) $feldText->id => ['display_size' => '12']], 'taxmod_field_setting_override' => [(string) $feldText->id => ['display_size' => '1']]]);
$a = $anDerKante($feldText->id)['display_size'] ?? null;
check('mit Haken ist `display_size` an der Stelle gesetzt', $a !== null && $a->setHere && $a->value->int === 12, $a === null ? '-' : ($a->setHere ? 'gesetzt' : 'geerbt') . ' ' . $a->value->int);
$speicherbar($seeded['text']->id, 'display_size', '');

// ⚠️ **Seit Schritt 2 des Bauplans (2026-09-11) ist `read_only` eine Spalte der Kante**
// ([D-714](../../docs/NewConcept/90-decision-log.md)): *keine Einstellungskante, kein Satz — die
// Feldzeile schreibt in die Spalte, und der Leser liest sie von dort. Die Zusagen von vorher stehen
// hier in ihrer neuen Form; die Einstellungskante an der Wurzel ist mit Fassung 48 gewandert.*
$anDerStelle = static fn (): bool => $relations->byId($count->id)->readOnly;
$editor->setReadOnly($modellKnoten->id, $count->id, true);
check('über den Dienst gesetzt, und die Spalte der Kante sagt es', $anDerStelle() === true && (int) $wpdb->get_var($wpdb->prepare('SELECT read_only FROM ' . Schema::table('relations') . ' WHERE id = %d', $count->id)) === 1);
check('und die Auflösung an der Stelle liest es aus der Spalte', ($zeichner()->settingsForUseSites([$relations->byId($count->id)])[$count->id]['read_only'] ?? null)?->value->asBool() === true);
$editor->setReadOnly($modellKnoten->id, $count->id, false);
check('zurückgenommen, und die Spalte sagt nichts mehr', $anDerStelle() === false);
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $count->id => ['read_only' => '1']]]);
check('der Akt der Feldzeile läuft durch, und die Spalte steht danach', gelungen() && $anDerStelle() === true, letzteMeldung());
speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $count->id => ['read_only' => '0']]]);
check('der Schalter auf null nimmt sie wieder heraus', gelungen() && $anDerStelle() === false);
check('kein Satz ist dabei entstanden', (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('node_records') . " WHERE relation_id = %d AND record_type = 'settings'", $count->id)) === 0);
$count = $relations->byId($count->id);

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
$mail = $editor->setReadOnly($mail->fromNodeId, $mail->id, true);
// ⚠️ *Die Spalte hängt am Kantenobjekt — wer die Liste weiterreicht, reicht die neue Fassung weiter.*
$every = array_map(static fn ($e) => $e->id === $mail->id ? $mail : $e, $every);
$rendering = $zeichner();
$closed = [];
foreach ($rendering->fieldsFor([$label, $mail], $back, Purpose::Edit, 'taxmod_value') as $field) {
    $closed[$field->relation->id] = $field;
}
check('ein verborgenes Feld wird gar nicht aufgezählt, die anderen bleiben', ! isset($closed[$label->id]) && count($closed) === 1);
// ⚠️ *Seit D-739 (2026-09-12): das Feld bleibt beim Bearbeiten, gesperrt und gefüllt — der Link gehört der Anzeige.*
check('eine nur lesbare Adresse wird beim Bearbeiten als gesperrtes Feld gezeigt (D-739)', str_starts_with($closed[$mail->id]->result->markup, '<fieldset disabled class="taxmod-read-only">'));
check('kein Feld wird zur Suche angeboten (D-217), und beim Anzeigen fällt keines weg', $rendering->fieldsFor($every, [], Purpose::Search, 'q') === [] && count($rendering->fieldsFor($every, [], Purpose::Display, '')) === count($every) - 1);

$before = $wpdb->num_queries;
$rendering->fieldsFor($every, $back, Purpose::Edit, 'taxmod_value');
$spent = $wpdb->num_queries - $before;
$before = $wpdb->num_queries;
$rendering->fieldsFor([...$every, ...$every], $back, Purpose::Edit, 'taxmod_value');
$doppelt = $wpdb->num_queries - $before;
check('das ganze Formular kostet eine feste Zahl Abfragen (CD-7), und doppelt so viele Felder nicht mehr', $spent <= 8 && $doppelt <= $spent, "{$spent} für 7, {$doppelt} für 14");

$intNode = $nodes->byId($seeded['int']->id);
$rendering = $zeichner();
$srows = [];
foreach ($rendering->settingsFor($intNode, $rendering->settingsForNode($intNode)) as $r) {
    $srows[$r->key] = $r;
}
// ⚠️ *Der Schalter `read_only` wird seit [D-714](../../docs/NewConcept/90-decision-log.md) an der
// Feldzeile gezeichnet, aus der Spalte — nicht mehr am Knoten.*
$krows = [];
foreach ($rendering->settingsFor($count, $rendering->settingsForUseSites([$count])[$count->id]) as $r) {
    $krows[$r->key] = $r;
}
check('die Spalte read_only wird an der Feldzeile als Schiebeschalter gezeichnet (R20a, D-714)', isset($krows['read_only']) && $krows['read_only']->wasDrawn() && str_contains($krows['read_only']->result->markup, 'taxmod-toggle-track'));
check('am Knoten gibt es keinen Schalter read_only mehr', ! isset($srows['read_only']));
check('ein leihender Schlüssel nimmt den Typ des Knotens', isset($srows['step']) ? $srows['step']->type === SimpleType::Int : true);
$editRows = [];
foreach ($rendering->settingsFor($intNode, $rendering->settingsForNode($intNode), Purpose::Edit) as $r) {
    $editRows[$r->key] = $r;
}
check('eine Wahl wird als Liste echter Möglichkeiten gezeichnet', isset($editRows['renderer']) && $editRows['renderer']->wasDrawn() && str_contains($editRows['renderer']->result->markup, '<select'));
// ⚠️ *Ein Text kennt keinen Konverter — der Vertrag erklärt die Zeile, die Registratur bietet nichts an (Schritt 7).*
$intLeer = $editor->createNode('__es Text leer', $seeded['text']->id);
// ⚠️ *Ein Zeichner ohne Konverter-Registratur: dann hat die Wahl nichts anzubieten — und genau das soll sie zeigen.*
$rendering = $zeichner();
$eigeneZeilen = [];
foreach ($rendering->settingsFor($nodes->byId($intLeer->id), $rendering->settingsForNode($nodes->byId($intLeer->id)), Purpose::Edit) as $r) {
    $eigeneZeilen[$r->key] = $r;
}
check('eine Wahl ohne Inhalt ist ein totes Steuerelement, kein leeres', isset($eigeneZeilen['converter']) && str_contains($eigeneZeilen['converter']->result->markup ?? '', 'disabled'));
$editor->setReadOnly($modellKnoten->id, $count->id, true);
check('ein Schalter liest sich als Wahrheitswert zurück, nicht als die Zahl eins', ($zeichner()->settingsForUseSites([$relations->byId($count->id)])[$count->id]['read_only'] ?? null)?->value->asBool() === true);
$editor->setReadOnly($modellKnoten->id, $count->id, false);
$count = $relations->byId($count->id);

$formed = $zeichner()->nodeAsForm($nodes->byId($modellKnoten->id), $every, $back, Purpose::Edit, 'taxmod_value');
$readOnlyAt = strpos($formed->markup, '__es contact');
$ordinaryAt = strpos($formed->markup, '__es count');
$boolAt     = strpos($formed->markup, '__es in stock');
check('ein Knoten wird von einem Behälter gezeichnet, nicht von einem Bildschirm (D-098): das Formular steht, nennt seine Kanten', str_contains($formed->markup, 'taxmod-form') && $formed->usedRelations !== []);
check('nur lesbare Felder vorn, gewöhnliche danach, Schalter zuletzt', $readOnlyAt !== false && $ordinaryAt !== false && $boolAt !== false && $readOnlyAt < $ordinaryAt && $boolAt > $ordinaryAt, "{$readOnlyAt} / {$ordinaryAt} / {$boolAt}");
$mail = $editor->setReadOnly($mail->fromNodeId, $mail->id, false);

// ---------------------------------------------------------------------------------------------------

echo "\n== 8 · Konverter: dieselbe Zahl, anders geschrieben, in beide Richtungen ==\n";

// ⚠️ *Die Zeile `converter` steht an einer Stelle, weil der Vertrag von Integer sie erklärt (3.6.1) — seit Schritt 4
// des Bauplans keine Einstellungskante mehr ([D-712](../../docs/NewConcept/90-decision-log.md)). Gesetzt wird
// sie an der Kante mit dem Haken (5.1), oder am Knoten für alle seine Verwendungen.*
$konverterZeile = null;
foreach ($zeichner()->settingsFor($eins, $zeichner()->settingsForUseSites([$eins])[$eins->id] ?? [], Purpose::Edit) as $one) {
    if ($one->key === 'converter') {
        $konverterZeile = $one;
    }
}
check('die Zeile `converter` ist lebendig und bietet binary, hexadecimal, octal, roman an', $konverterZeile !== null && ! str_contains($konverterZeile->result->markup, 'disabled') && count(array_filter(['binary', 'hexadecimal', 'octal', 'roman'], static fn (string $n): bool => str_contains($konverterZeile->result->markup, $n))) === 4);
$vorherFeld = $zeichner()->fieldsFor([$eins], [$eins->id => TypedValue::ofInt(12)], Purpose::Display)[0];
check('ohne Konverter steht die 12 als 12 da', str_contains($vorherFeld->result->markup, '12'));
$umschreiben = static function (string $name) use ($modellKnoten, $eins): void {
    speichern($modellKnoten->id, ['taxmod_field_setting' => [(string) $eins->id => ['converter' => $name]], 'taxmod_field_setting_override' => [(string) $eins->id => ['converter' => '1']]]);
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
check('ein Konvertername, den es nicht gibt, wird abgewiesen — der vorige bleibt, und das Formular zeichnet weiter', ! gelungen() && (($zeichner()->settingsForUseSites([$eins])[$eins->id]['converter'] ?? null)?->value->text ?? '') === 'hexadecimal' && str_contains($zeichner()->fieldsFor([$eins], [$eins->id => TypedValue::ofInt(255)], Purpose::Display)[0]->result->markup, 'FF'), letzteMeldung());
$umschreiben('');
check('leer an der Kante: kein Konverter mehr, die 12 ist wieder 12', ! isset($zeichner()->settingsForUseSites([$eins])[$eins->id]['converter']) && str_contains($zeichner()->fieldsFor([$eins], [$eins->id => TypedValue::ofInt(12)], Purpose::Display)[0]->result->markup, '12'));
speichern($zahl->id, ['taxmod_setting' => ['converter' => 'roman']]);
$beide = $zeichner()->fieldsFor([$eins, $zwei], [$eins->id => TypedValue::ofInt(12), $zwei->id => TypedValue::ofInt(4)], Purpose::Display);
check('am Knoten gesetzt gilt der Konverter an jeder Verwendung: XII und IV', str_contains($beide[0]->result->markup, 'XII') && str_contains($beide[1]->result->markup, 'IV'));
speichern($zahl->id, ['taxmod_setting' => ['converter' => '']]);

// ---------------------------------------------------------------------------------------------------

echo "\n== 9 · Vorschau: drei Seiten, keine Einstellungen darin, und zeichnen schreibt nichts ==\n";

$leerKnoten = $editor->createNode('__es Leer', $modellAst->id);
$leerFeld   = $editor->addField($leerKnoten->id, $seeded['text']->id, '__es leeres Feld');
$band       = vorschau($leerKnoten->id);
check('die Vorschau erscheint, wo Sätze möglich sind — mit zwei Seiten (Settings fiel mit Schritt 7)', $band !== '' && substr_count($band, 'taxmod-preview-side') === 2, (string) substr_count($band, 'taxmod-preview-side'));
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
$editor->setReadOnly($modellKnoten->id, $count->id, true);
$fixed = vorschau($modellKnoten->id);
check('read_only lässt die Zeile stehen und meldet sie als nur lesbar — verborgen ist sie nicht', str_contains($fixed, '__es count') && str_contains($fixed, 'read-only'));
$editor->setReadOnly($modellKnoten->id, $count->id, false);
$count = $relations->byId($count->id);

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
check('die zwei Seiten heissen Display, Admin — Settings fiel mit Schritt 7', count($seiten) === 2 && isset($nachName['Display'], $nachName['Admin']), implode(', ', array_keys($nachName)));
// ⚠️ *«Settings nennt read_only» galt bis Fassung 48 — die Einstellungskante ist gewandert
// ([D-714](../../docs/NewConcept/90-decision-log.md)); was bleibt: Admin nennt seine Felder und keine
// Einstellung, Settings nennt kein Feld.*
check('Admin nennt keine Einstellung, aber seine Felder; Settings nennt kein Feld', ! str_contains($nachName['Admin'] ?? '', 'read_only') && str_contains($nachName['Admin'] ?? '', '__es count') && ! str_contains($nachName['Settings'] ?? '', '__es count'));

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
// ⚠️ *Hier stand «Feld → Einstellung mit Benutzersätzen» — die Art `setting` ist seit Schritt 7 nicht mehr wählbar (D-715).*
$wertVon = static function (int $satzId) use ($data, $feldArt): ?string {
    foreach ($data->valuesOf($satzId) as $w) {
        if ($w->relationId === $feldArt->id) {
            return $w->value->text;
        }
    }

    return null;
};
check('die Kante bleibt ein Feld, beide Werte stehen noch', $editor->relationById($feldArt->id)?->kind === RelationKind::Composition && $wertVon($s1->id) === 'sieben' && $wertVon($s2->id) === 'acht', (string) $editor->relationById($feldArt->id)?->kind->value . ' / ' . $wertVon($s1->id) . ' / ' . $wertVon($s2->id));
// ⚠️ **Hier standen drei Zusagen zum Umstellen einer Kante auf die Art `setting`.** *Seit Schritt 7 des Bauplans
// (2026-09-11) wird keine Einstellungskante mehr angelegt ([D-712](../../docs/NewConcept/90-decision-log.md)); die
// Art bleibt nur für die geparkte Kante `position` an der Wurzel (Modell 2.4 offen, TASK-093).*

// ---------------------------------------------------------------------------------------------------

echo "\n== 11 · Datensätze: anlegen mit Art, schreiben, umstellen, löschen — und der Block auf der Seite ==\n";

$satzKnoten = $editor->createNode('__es satzknoten', $modellAst->id);
$satzFeld   = $editor->addField($satzKnoten->id, $seeded['int']->id, '__es zahl');
abschicken(['do' => 'add_record', 'id' => (string) $satzKnoten->id, 'record_type' => RecordType::Example->value, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
$satzId = (int) $wpdb->get_var("SELECT id FROM {$p}node_records WHERE node_id = {$satzKnoten->id} ORDER BY id DESC LIMIT 1");
check('«New record» legt einen Satz an, mit der gewählten Art', $satzId > 0 && (string) $wpdb->get_var("SELECT record_type FROM {$p}node_records WHERE id = {$satzId}") === RecordType::Example->value);
abschicken(['do' => 'save_record', 'id' => (string) $satzKnoten->id, 'node_record_id' => (string) $satzId, 'taxmod_value' => [(string) $satzId => [(string) $satzFeld->id => '42']], '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
check('ein eingetippter Wert steht danach im Satz, und die Seite zeigt ihn wieder', (string) $wpdb->get_var("SELECT value_int FROM {$p}relation_records WHERE node_record_id = {$satzId} AND relation_id = {$satzFeld->id}") === '42' && str_contains(seite($satzKnoten->id), 'value="42"'));

// ⚠️ **Ein zusammengesetztes Feld im Satz trägt seine inneren Felder mit Namen, und ein Wert darin kommt an und zurück**
// ([D-742](../../docs/NewConcept/90-decision-log.md)). *Sein Befund nach D-741: «Adresse bei entry immer noch leer».
// Die inneren Felder wurden gezeichnet, aber ohne `name` — die Maske schickte nichts. Der Name ist die Kette der Kanten.*
$satzAnschrift = $editor->addField($satzKnoten->id, $anschrift->id, '__es anschrift');
$gasse         = null;
foreach ($relations->fieldRelationsOf([$anschrift->id]) as $innere) {
    $gasse ??= $innere;
}
$kette = 'taxmod_value[' . $satzId . '][' . $satzAnschrift->id . '][' . $gasse->id . ']';
check('die inneren Felder eines zusammengesetzten Feldes stehen im Satz mit ihrem Namen — der Kette der Kanten', str_contains(seite($satzKnoten->id), 'name="' . $kette . '"'), $kette);
abschicken(['do' => 'save_record', 'id' => (string) $satzKnoten->id, 'node_record_id' => (string) $satzId, 'taxmod_value' => [(string) $satzId => [(string) $satzFeld->id => '42', (string) $satzAnschrift->id => [(string) $gasse->id => '__es Gasse 7']]], '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
check('ein Wert im inneren Feld wird an der innersten Kante gespeichert, und die Seite zeigt ihn wieder', gelungen() && (string) $wpdb->get_var("SELECT value_text FROM {$p}relation_records WHERE node_record_id = {$satzId} AND relation_id = {$gasse->id}") === '__es Gasse 7' && preg_match('/<input[^>]*name="' . preg_quote($kette, '/') . '"[^>]*value="__es Gasse 7"/', seite($satzKnoten->id)) === 1, letzteMeldung());
check('und die Vorschau zeigt den inneren Wert — nicht nur der Satzblock (D-743)', str_contains(vorschau($satzKnoten->id), '__es Gasse 7'));
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
// ⚠️ **Die Zusage «mit Entwicklermodus steht der Einstellungssatz da» ist seit Schritt 4 des Bauplans (2026-09-11) fort.**
// *Es gibt keinen Einstellungssatz mehr: Einstellungen wohnen in `settings_value` ([D-712](../../docs/NewConcept/90-decision-log.md)),
// die Satzart `settings` fällt mit Schritt 7 ([D-704](../../docs/NewConcept/90-decision-log.md)), und mit ihr der Haken dafür.*

// ⚠️ **Die Renderer-Diagnose, je Zelle** ([D-711](../../docs/NewConcept/90-decision-log.md), sein Wort «je zelle»).
$saetzeAmSatzknoten = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE node_id = {$satzKnoten->id} AND relation_id = 0");
$diagnoseAn  = $geliehen(NodesScreen::DEVELOPER_OPTION, '1', static fn (): string => seite($satzKnoten->id));
$diagnoseAus = $geliehen(NodesScreen::DEVELOPER_OPTION, '0', static fn (): string => seite($satzKnoten->id));
check('im Entwicklermodus steht unter dem Datensatz-Block die Renderer-Diagnose, eine Zeile je Satz', substr_count($diagnoseAn, 'taxmod-record-diagnostic-row') === $saetzeAmSatzknoten, substr_count($diagnoseAn, 'taxmod-record-diagnostic-row') . " Zeilen für {$saetzeAmSatzknoten} Sätze");
check('und je Zelle nennt sie das Feld und seinen Renderer', (bool) preg_match('/taxmod-record-diagnostic-row[^<]*<strong>#\d+<\/strong> · <code>__es zahl<\/code> — int · (field|spinner|slider)/', $diagnoseAn));
check('ohne Entwicklermodus steht sie nicht da', ! str_contains($diagnoseAus, 'taxmod-record-diagnostic'));
foreach (['taxmod_dev_writes' => ['taxmod-tree-writes', 'die Schreibzahl'], 'taxmod_dev_root_toggle' => ['taxmod_root', 'der Schalter «show the root»']] as $option => [$marke, $nameOpt]) {
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
// ⚠️ *Ein «+» mehr als Zeilen: das im Kopf der Seite (D-730, 2026-09-12); `add_child_here` ist darin aufgegangen.*
// *Gezählt je Zeilenformular des Baums, nicht über die ganze Seite — der Kopf trägt sein eigenes «+» und seinen eigenen Papierkorb.*
preg_match_all('/style="display:flex;gap:\.2em">(.*?)<\/form>/s', $markup, $zeilenFormulare);
$zeilenMitAkten = array_filter($zeilenFormulare[1], static fn (string $f): bool => str_contains($f, 'value="trash_node"'));
$vollstaendig   = array_filter($zeilenMitAkten, static fn (string $f): bool => str_contains($f, 'value="add_child"') && str_contains($f, 'value="up"') && str_contains($f, 'value="down"'));
check('der Baum wird von der Zelle gezeichnet, jede Zeile trägt dieselben vier Knöpfe, und was nicht geht, ist ausgegraut statt fort', str_contains($markup, 'taxmod-tree-node') && $zeilenMitAkten !== [] && count($vollstaendig) === count($zeilenMitAkten) && str_contains($markup, 'disabled'), count($vollstaendig) . ' von ' . count($zeilenMitAkten) . ' Zeilen');
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
check('jeder Icon-Knopf ist gekennzeichnet, nichts sagt «not defined», und 1..1 steht nirgends im sichtbaren Text (D-376)', substr_count($detail, 'taxmod-icon-button') > 0 && substr_count($detail, '<span class="taxmod-icon') >= substr_count($detail, 'taxmod-icon-button') && ! str_contains($detail, 'not defined') && ! str_contains(strip_tags($detail), '1..1') && (bool) preg_match('/value="1\.\.1"( selected)?>1</', $detail));
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
$css = (string) file_get_contents(__DIR__ . '/../../assets/admin.css');

// ⚠️ **Zeilen 118–121 vom 2026-09-12** (D-746 … D-749).
check('«with_parent» am Verweis heisst «use_parent_label», und Fassung 52 hat die Zeilen umgeschrieben (D-746)', \Taxmod\Core\Renderer\ReferenceRenderer::WITH_PARENT === 'use_parent_label' && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings_value WHERE attribut = 'with_parent'") === 0 && (int) get_option(Schema::VERSION_OPTION) >= 52, 'Fassung ' . get_option(Schema::VERSION_OPTION));
$konstanteVertrag = new ReflectionProperty(\Taxmod\Core\Model\NodeClass\Constant::class, 'with_label');
check('die Konstantenklasse erklärt «with_label», Standard aus (D-747)', $konstanteVertrag->getAttributes(\Taxmod\Core\Model\NodeClass\Attribut::class) !== [] && $konstanteVertrag->getDefaultValue() === false);
$ohneWahl = $editor->createNode('__es ohne Behälter', $modellAst->id);
$editor->addField($ohneWahl->id, $seeded['text']->id, '__es wort');
check('die Vorschau eines Knotens ohne gewählten Behälter zeichnet als Tabelle (D-748)', str_contains(vorschau($ohneWahl->id), '<table class="taxmod-table'));
$adresseKante = null;
foreach ($editor->fieldsOf($modellKnoten->id) as $kante) {
    if ($kante->name === '__es adresse') {
        $adresseKante = $kante;
    }
}
$angebot = array_map(static fn (\Taxmod\Core\Renderer\Renderer $r): string => $r->name(), $zeichner()->choicesFor($adresseKante));
check('ein Feld auf eine Kategorie bekommt Formular, Tabelle und Compact angeboten (D-749)', in_array('table', $angebot, true) && in_array('form', $angebot, true) && in_array('compact', $angebot, true), implode(',', $angebot));


// ⚠️ **Die Zusammenfassung eines verwiesenen Satzes** ([D-753](../../docs/NewConcept/90-decision-log.md)): die gewählten Felder des Ziels,
$sId = $data->create($satzKnoten->id, RecordType::User)->id;
// Vorgabe am Knoten, an der Kante überschreibbar; beim Bearbeiten ein Auswahlfeld über die Sätze des Ziels.
$lieferant     = $editor->createNode('__es Lieferant', $modellAst->id);
$lfName        = $editor->addField($lieferant->id, $seeded['text']->id, '__es lf name');
$lfLand        = $editor->addField($lieferant->id, $seeded['text']->id, '__es lf land');
$lfSatz        = $data->create($lieferant->id, RecordType::User);
$data->put($lfSatz->id, $lfName->id, TypedValue::ofText('__es Alpha'));
$data->put($lfSatz->id, $lfLand->id, TypedValue::ofText('__es Nord'));
$wer           = $editor->addField($satzKnoten->id, $lieferant->id, '__es wer', RelationKind::Aggregation);
$data->put($sId, $wer->id, TypedValue::ofRecordReference($lfSatz->id));
$einsteller    = new \Taxmod\Core\Service\SettingsEditor(new WpdbSettingsRepository(), $nodes, new SettingsResolver(new WpdbSettingsRepository(), $nodes, ShippedRenderers::registry(), ShippedConverters::registry(), relations: $relations), ShippedRenderers::registry(), ShippedConverters::registry());
$einsteller->put($nodes->byId($lieferant->id), 'renderer', \Taxmod\Core\Renderer\SummaryRenderer::NAME, $wer);
$einsteller->setMembers($nodes->byId($lieferant->id), \Taxmod\Core\Renderer\SummaryRenderer::FIELDS, [$lfName->id, $lfLand->id]);
$mitSummary = seite($satzKnoten->id);
check('der Satzblock zeigt den verwiesenen Satz als Zusammenfassung der am Knoten gewählten Felder (D-753)', str_contains($mitSummary, '<option value="' . $lfSatz->id . '" selected>__es Alpha · __es Nord</option>'));
$einsteller->setMembers($nodes->byId($lieferant->id), \Taxmod\Core\Renderer\SummaryRenderer::FIELDS, [$lfLand->id], $wer);
$anDerKante = seite($satzKnoten->id);
check('an der Kante überschrieben: nur das Land', str_contains($anDerKante, '<option value="' . $lfSatz->id . '" selected>__es Nord</option>') && ! str_contains($anDerKante, '__es Alpha · __es Nord'));
$offenWer = seite($satzKnoten->id, (string) $wer->id);
check('im Einstellungsbereich der Kante stehen die Felder des Ziels als Haken (D-752)', str_contains($offenWer, 'name="taxmod_field_setting_set[' . $wer->id . '][summary_fields][' . $lfName->id . ']"'));
abschicken(['do' => 'save_record', 'id' => (string) $satzKnoten->id, 'node_record_id' => (string) $sId, 'taxmod_value' => [(string) $sId => [(string) $satzFeld->id => '42', (string) $wer->id => (string) $lfSatz->id]], '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $satzKnoten->id)]);
check('der Wähler der Zusammenfassung schreibt einen Satzverweis', gelungen() && (string) $wpdb->get_var("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = {$sId} AND relation_id = {$wer->id} AND value_ref_kind = 'record'") === (string) $lfSatz->id, letzteMeldung());

// ⚠️ **Die Seite wird abgeschickt, wie ein Browser sie abschickt** ([D-754](../../docs/NewConcept/90-decision-log.md)) — sein Wort am
// 2026-09-12: *«read only verschwindet nach Speichern, das hatten wir jetzt schon mehrfach; kannst du das generell mal überprüfen,
// ich will das nicht bei jedem Feld erneut testen müssen».* *Gemessen: die offene Feldzeile trug `read_only` und `unique` je zweimal
// unter einem Namen — die Spalte und ihre Kopie im Einstellungsbereich —, und der Browser schickt die zweite. Darum hier: für **jedes**
// eigene Feld des Knotens den Zeilenschalter einschalten, die Seite mit offenem Bereich wie ein Browser abschicken, und kein Name
// darf in einem Formular der Seite doppelt stehen.*
require_once __DIR__ . '/lib/browser-post.php';
$roKnoten = $editor->createNode('__es nur lesen', $modellAst->id);
foreach (['int', 'text', 'bool', 'email', 'datetime', 'color'] as $typName) {
    $editor->addField($roKnoten->id, $seeded[$typName]->id, '__es ro ' . $typName);
}
$editor->addField($roKnoten->id, $anschrift->id, '__es ro teil');
$roFelder   = array_values(array_filter($editor->fieldsOf($roKnoten->id), static fn (Relation $r): bool => ! $r->isSetting() && $r->fromNodeId === $roKnoten->id));
$verloren   = [];
$doppelt    = [];
foreach ($roFelder as $roFeld) {
    $offen = seite($roKnoten->id, (string) $roFeld->id);
    foreach (array_unique(array_merge(['taxmod-page-' . $roKnoten->id], array_map(static fn (Relation $r): string => FieldRowRenderer::formFor($r), $roFelder))) as $formular) {
        foreach (taxmodDuplicateNames($offen, $formular) as $name => $n) {
            $doppelt[$formular . ' ' . $name] = $n;
        }
    }
    foreach ([\Taxmod\Core\Model\EdgeColumn::READ_ONLY, \Taxmod\Core\Model\EdgeColumn::UNIQUE] as $spalte) {
        $feldName = 'taxmod_field_setting[' . $roFeld->id . '][' . $spalte . ']';
        $wieGeklickt = taxmodTick($offen, $feldName);
        if ($wieGeklickt === $offen) {
            $verloren[] = $roFeld->name . ' ' . $spalte . ' (kein Schalter)';
            continue;
        }
        $felder      = taxmodBrowserFields($wieGeklickt, 'taxmod-page-' . $roKnoten->id);
        abschicken(['do' => 'put_setting', 'id' => (string) $roKnoten->id, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $roKnoten->id)] + $felder);
        $danach = $relations->byId($roFeld->id);
        if (! gelungen() || ! ($spalte === \Taxmod\Core\Model\EdgeColumn::READ_ONLY ? $danach->readOnly : $danach->unique)) {
            $verloren[] = $roFeld->name . ' ' . $spalte . ' (' . letzteMeldung() . ')';
        }
    }
}
check('kein Formular der Seite trägt einen Namen doppelt — sonst schickt der Browser die zweite Angabe (D-754)', $doppelt === [], json_encode($doppelt));
check('«nur lesen» und «eindeutig» überleben das Speichern mit offenem Bereich, für jeden Feldtyp — wie ein Browser abgeschickt (D-754)', $verloren === [], implode('; ', $verloren));
// ⚠️ **Der Typ «Weg»** ([D-751](../../docs/NewConcept/90-decision-log.md)) — Zeile 125: am Vater erklärt, am Kind die Kette, nie eingebbar.
check('der einfache Typ «path» steht im Baum', isset($seeded['path']));
$weg = $editor->addField($modellKnoten->id, $seeded['path']->id, '__es weg');
$amKind = seite($kind->id);
check('am Kind zeigt das Weg-Feld die Kette vom erklärenden Vater, ausgegraut (D-751)', str_contains($amKind, '__es Modell') && preg_match('/<fieldset disabled class="taxmod-read-only">[^<]*<input[^>]*value="__es Modell"/', $amKind) === 1);
$amVater = vorschau($modellKnoten->id);
check('am erklärenden Knoten selbst ist der Weg leer', ! str_contains($amVater, 'value="__es Modell"'));

// ⚠️ **Ein Feld in den Vater oder in gewählte Kinder schieben** ([D-750](../../docs/NewConcept/90-decision-log.md)) — Zeile 117.
$schieb = $editor->addField($modellKnoten->id, $seeded['text']->id, '__es schieb');
$zeile  = seite($modellKnoten->id);
check('die eigene Feldzeile trägt «To parent» und «To children», Letzteres mit Dialog (D-750)', str_contains($zeile, 'value="field_to_parent"') && str_contains($zeile, 'value="field_to_children"') && str_contains($zeile, 'id="taxmod-push-' . $schieb->id . '"') && str_contains($zeile, 'name="children[]" value="' . $kind->id . '"'));
abschicken(['do' => 'field_to_children', 'id' => (string) $modellKnoten->id, 'relation' => (string) $schieb->id, 'children' => [(string) $kind->id], '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $modellKnoten->id)]);
$beimKind = array_values(array_filter($relations->fieldRelationsOf([$kind->id]), static fn (Relation $r): bool => $r->name === '__es schieb'));
check('in die Kinder geschoben: das Kind hat eine eigene Kante, der Vater die alte geparkt', gelungen() && count($beimKind) === 1 && $beimKind[0]->id !== $schieb->id && count(array_filter($relations->fieldRelationsOf([$modellKnoten->id]), static fn (Relation $r): bool => $r->id === $schieb->id)) === 0 && count(array_filter($relations->parkedFieldRelationsOf([$modellKnoten->id]), static fn (Relation $r): bool => $r->id === $schieb->id)) === 1, letzteMeldung());
abschicken(['do' => 'field_to_parent', 'id' => (string) $kind->id, 'relation' => (string) $beimKind[0]->id, '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $kind->id)]);
$zurueck = $relations->byId($beimKind[0]->id);
check('in den Vater geschoben: dieselbe Kante, jetzt am Vater, eine Version weiter', gelungen() && $zurueck !== null && $zurueck->fromNodeId === $modellKnoten->id && $zurueck->version === $beimKind[0]->version + 1, letzteMeldung());

check('der Wähler im Fluss hält seinen Baum in der eigenen Breite — kein Überhang in die Nachbarzelle (D-745)', (bool) preg_match('/\.taxmod-chooser-open \.taxmod-chooser-tree \{[^}]*min-width: 0;/', $css) && (bool) preg_match('/\.taxmod-chooser-open \{[^}]*display: block;/', $css));
if ($style !== null) {
    $headers = @get_headers((string) $style->src, true, stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true]]));
    $status  = is_array($headers[0] ?? null) ? $headers[0][0] : ($headers[0] ?? 'no answer');
    check('und ein Browser, der danach fragt, bekommt es', str_contains((string) $status, '200'), (string) $status);
}

// ⚠️ **Seit Schritt 4 des Bauplans (2026-09-11) ist `table` eine Wahl im Vertrag des Dings, und `orientation` ein Attribut
// des gewählten Renderers** ([D-712](../../docs/NewConcept/90-decision-log.md)) — *kein Renderer-Knoten, keine Einstellungskante.*
$t63Wiese  = $editor->createNode('__es Ding', $modellAst->id);
$t63Links  = $editor->addField($t63Wiese->id, $seeded['text']->id, '__es links');
$t63Rechts = $editor->addField($t63Wiese->id, $seeded['text']->id, '__es rechts');
speichern($t63Wiese->id, ['taxmod_setting' => ['renderer' => TableRenderer::NAME]]);
check('das Ding wählt `table`, und die Maske zeichnet dazu die Zeile `orientation`', gelungen() && (bool) preg_match('/name="taxmod_setting\\[orientation\\]"/', seite($t63Wiese->id)), letzteMeldung());
$t63Zeichnen = static fn (): string => $zeichner()->recordsAsTable($nodes->byId($t63Wiese->id), [$t63Links, $t63Rechts], [['id' => 1, 'values' => [$t63Links->id => TypedValue::ofText('__es A'), $t63Rechts->id => TypedValue::ofText('__es B')], 'lead' => [], 'acts' => [], 'submits' => new Submission('', [])]], 'taxmod_value')->markup;
$waagerecht = $t63Zeichnen();
speichern($t63Wiese->id, ['taxmod_setting' => ['orientation' => 'vertical']]);
$senkrecht = $t63Zeichnen();
speichern($t63Wiese->id, ['taxmod_setting' => ['orientation' => '']]);
$koepfe = substr_count($waagerecht, 'scope="col"');
check('waagerecht ist die Vorgabe: Kopf oben, keine Zeilenköpfe, eine Zeile je Datensatz', str_contains($waagerecht, 'taxmod-table-horizontal') && str_contains($waagerecht, '<thead>') && ! str_contains($waagerecht, 'scope="row"') && substr_count($waagerecht, 'taxmod-table-row') === 1);
check('senkrecht kommt beim Umstellen an: Kopf links, keine Kopfzeile, eine Zeile je Spalte, jeder Kopf einmal', str_contains($senkrecht, 'taxmod-table-vertical') && str_contains($senkrecht, 'scope="row"') && ! str_contains($senkrecht, '<thead>') && $koepfe > 1 && substr_count($senkrecht, 'taxmod-table-row') === $koepfe && substr_count($senkrecht, 'scope="row"') === $koepfe);
$alleStuecke = true;
foreach (['__es links', '__es rechts', '__es A', '__es B'] as $stueck) {
    $alleStuecke = $alleStuecke && str_contains($waagerecht, $stueck) && str_contains($senkrecht, $stueck);
}
check('beide Lagen zeigen Köpfe und Werte', $alleStuecke);

// ---------------------------------------------------------------------------------------------------

echo "\n== 13 · Eine Umbenennung ändert nichts an dem, was gezeichnet wird ==\n";

$beschriftungen = new WpdbLabelRepository();
$benenne = static function (IdentitySpace $raum, int $id, string $n) use ($beschriftungen): void {
    $beschriftungen->put(new Label($id, $raum, SeededRole::Name, Label::BASE_NUMBER, SettingsScreen::neutralLocale(), $n));
};
$zeichnetJetzt = static function (array $idListe) use ($nodes, $zeichner): array {
    $z       = $zeichner();
    $antwort = [];
    foreach ($idListe as $id) {
        $knoten       = $nodes->find($id);
        $antwort[$id] = $knoten === null ? '(kein Knoten)' : ($z->rendererNameFor($knoten) ?? 'nichts');
    }

    return $antwort;
};
$beobachtet = array_values(array_diff($nodes->subtreeIds($wurzel->id), $nodes->subtreeIds($framework->trash()->id), [$wurzel->id]));
sort($beobachtet);
$vorherZeichnung = $zeichnetJetzt($beobachtet);
check('die Auflösung liefert vorher überhaupt etwas', count(array_filter($vorherZeichnung, static fn (string $w): bool => $w !== 'nichts' && $w !== 'plain')) > 0);
// ⚠️ *Die Träger einer Wahl sind seit Schritt 7 die Knoten mit einer Zeile `renderer` in `settings_value` —
// hier die Wiese, die Abschnitt 3 bis 5 gesetzt haben.*
$traegerIds = array_map(intval(...), $wpdb->get_col("SELECT DISTINCT node_id FROM {$p}settings_value WHERE attribut = 'renderer' AND node_id IS NOT NULL AND relation_id IS NULL") ?: []);
check('es gibt Träger einer Wahl', $traegerIds !== []);
$knotenNamenVorher = [];
foreach ($traegerIds as $id) {
    $knotenNamenVorher[$id] = (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}nodes_named WHERE id = %d", $id));
}
foreach ($knotenNamenVorher as $id => $n) {
    $benenne(IdentitySpace::Node, (int) $id, 'Etwas ganz anderes');
}
$umbenannt = 0;
foreach ($knotenNamenVorher as $id => $n) {
    $umbenannt += (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}nodes_named WHERE id = %d", $id)) === 'Etwas ganz anderes' ? 1 : 0;
}
check('die Träger heissen jetzt anders', $umbenannt === count($knotenNamenVorher), "{$umbenannt} von " . count($knotenNamenVorher));
$nachherZeichnung = $zeichnetJetzt($beobachtet);
$abweichend = [];
foreach ($beobachtet as $id) {
    if (($nachherZeichnung[$id] ?? null) !== $vorherZeichnung[$id]) {
        $abweichend[] = "#{$id}";
    }
}
check('kein Knoten zeichnet nach der Umbenennung anders (' . count($beobachtet) . ' beobachtet)', $abweichend === [], implode(', ', array_slice($abweichend, 0, 8)));
foreach ($knotenNamenVorher as $id => $n) {
    $benenne(IdentitySpace::Node, (int) $id, $n);
}
$zurueckGezaehlt = 0;
foreach ($knotenNamenVorher as $id => $n) {
    $zurueckGezaehlt += (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}nodes_named WHERE id = %d", $id)) === $n ? 1 : 0;
}
check('die Namen stehen wieder da', $zurueckGezaehlt === count($knotenNamenVorher));
echo "\n{$passed} ok, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
