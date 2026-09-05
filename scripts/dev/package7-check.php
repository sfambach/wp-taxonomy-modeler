<?php declare(strict_types=1);
/**
 * Package 7 acceptance check — the fields look like their type, against a real database.
 *
 *     php scripts/dev/package7-check.php [path/to/wordpress]
 *
 * The owner's test: a date is a date field, a switch is a switch, and a value typed into either
 * comes back unchanged after a reload.
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

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

use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Exception\CannotWiden;
use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\DateTimeRenderer;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\PlainRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SliderRenderer;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\ToggleRenderer;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;
    if ($passed) { $ok++; echo "  OK   $what\n"; }
    else { $bad++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$framework->seed();

$editor    = new ModelEditor($nodes, $edges, $framework, $log);
$data      = new DataEntry(new WpdbRecordRepository(), $edges, $nodes, $framework, new SystemClock());
$labels    = new Labels(new WpdbLabelRepository(), $framework);
$registry  = ShippedRenderers::registry();
$types     = new SeededTypeNodes($nodes, $framework);
$rendering = new Rendering($nodes, $framework, $registry, $types, $labels,
    model: new ModelValues(new WpdbRecordRepository(), new WpdbRelationRepository(), new WpdbNodeRepository(), $framework)
);


// ⚠️ **Eine Angabe des Modells setzen und wieder wegnehmen — so, wie das Modell sie ablegt.**
// *Hier stand `$settings->put(...)`. Die `settings`-Tabelle ist mit
// [D-579](../../docs/NewConcept/90-decision-log.md) gestrichen; eine Einstellung **ist** eine Kante
// ([D-529](../../docs/NewConcept/90-decision-log.md)), und ihr Wert steht im Datensatz ihres
// Besitzers unter der Adresse der Kante. **Einen Schreiber fuer eine Einstellung an einer
// Verwendungsstelle gibt es im Kern noch nicht** — {@see \Taxmod\Core\Service\DataEntry::putSettingAt()}
// schreibt am Knoten. Das steht als `INF-011` im Eingang und wird hier nicht nebenbei entschieden.*
$satzVon = static function (int $knotenId) use ($nodes): int {
    $records   = new WpdbRecordRepository();
    $vorhanden = $records->ofNode($knotenId);

    if ($vorhanden !== []) {
        return $vorhanden[0]->id;
    }

    return $records->add(new \Taxmod\Core\Model\NodeRecord(0, $knotenId, $nodes->byId($knotenId)->version, gmdate('Y-m-d H:i:s')));
};

// ⚠️ **Was diese Pruefung selbst anlegt, raeumt sie am Ende weg** (TASK-047). *Gemessen am
// 2026-09-05: jeder Lauf liess zwei Einstellungskanten auf den **Zweigkopf «Constants»** stehen —
// `Decimal --renderer--> Constants` und `Integer --read_only--> Constants`. Der Eigentuemer fand sie
// in seinem Modell, ohne Eintrag im Aenderungsbuch. **Der Zweigkopf ist hier nur ein Platzhalter fuer
// ein Ziel, das der Schluessel gar nicht braucht** — er darf nur nicht liegenbleiben.*
$selbstGelegteKanten = [];

$kanteFuer = static function (int $traegerId, string $key) use ($edges, $framework, &$selbstGelegteKanten): \Taxmod\Core\Model\Relation {
    foreach ($edges->fieldEdgesOf([$traegerId]) as $eine) {
        if ($eine->kind === \Taxmod\Core\Model\RelationKind::Setting && $eine->name === $key) {
            return $eine;
        }
    }

    $neu = $edges->add(\Taxmod\Core\Model\Relation::attribute(
        0,
        $traegerId,
        $framework->rootOf(Branch::Constants)->id,
        \Taxmod\Core\Model\RelationKind::Setting,
        $key,
        $edges->nextFieldPositionUnder($traegerId)
    ));

    $selbstGelegteKanten[$neu->id] = $neu->id;

    return $neu;
};

/** Eine Angabe an einem Knoten oder an einer Verwendungsstelle. */
$angabe = static function (\Taxmod\Core\Model\Node|\Taxmod\Core\Model\Relation $wer, string $key, \Taxmod\Core\Model\TypedValue $wert) use ($satzVon, $kanteFuer, $data): void {
    $traegerId = $wer instanceof \Taxmod\Core\Model\Node ? $wer->id : $wer->fromNodeId;
    $kante     = $kanteFuer($traegerId, $key);

    // ⚠️ **An einer Verwendungsstelle geht es jetzt ueber den Kern.** *Hier stand ein **Behelf** —
    // der Waechter legte die Zeile selbst ueber die Speicher an, weil es fuer eine Verwendungsstelle
    // keinen Schreiber gab ([`INF-011`](../../docs/pakete/modelltabellen/inbox.md)). **Den gibt es
    // jetzt**, und ein Waechter, der am Kode vorbei schreibt, misst seinen eigenen Behelf.*
    if ($wer instanceof \Taxmod\Core\Model\Relation) {
        $data->putSettingAtUseSite($wer->id, $kante->id, $wert);

        return;
    }

    $satzId = $satzVon($traegerId);

    $records = new WpdbRecordRepository();

    // ⚠️ *Erst die alte Zeile weg — `putValue()` ohne Id legt **an** statt zu ersetzen, und zwei
    // Zeilen auf demselben Pfad liessen die erste gewinnen.*
    $records->forgetValue($satzId, (string) $kante->id, '');
    $records->putValue(new \Taxmod\Core\Model\EdgeRecord($satzId, (string) $kante->id, $kante->id, '', $wert));
};

/** Dieselbe Angabe wieder wegnehmen. */
$ohneAngabe = static function (\Taxmod\Core\Model\Node|\Taxmod\Core\Model\Relation $wer, string $key) use ($satzVon, $kanteFuer, $data): void {
    $traegerId = $wer instanceof \Taxmod\Core\Model\Node ? $wer->id : $wer->fromNodeId;
    $kante     = $kanteFuer($traegerId, $key);

    if ($wer instanceof \Taxmod\Core\Model\Relation) {
        $data->clearSettingAtUseSite($wer->id, $kante->id);

        return;
    }

    (new WpdbRecordRepository())->forgetValue($satzVon($traegerId), (string) $kante->id, '');
};

/** Der Zeichner neu — {@see \Taxmod\Core\Service\ModelValues} merkt sich die Saetze beim ersten Lesen. */
$zeichnerNeu = static fn (): Rendering => new Rendering($nodes, $framework, $registry, $types, $labels,
    model: new ModelValues(new WpdbRecordRepository(), new WpdbRelationRepository(), new WpdbNodeRepository(), $framework)
);

$dataTypes = $framework->rootOf(Branch::DataTypes)->id;

// ⚠️ The seeded simple types are found rather than made again — they are content that ships once
// (D-119), and a second `int` beside the real one would be a different node.
//
// ⚠️ **Über die Id, die die Saat notiert hat** ([D-510](../../docs/NewConcept/90-decision-log.md)),
// nicht über den Namen. *Das war bis 2026-08-29 ein `fromNodeName($child->name)` mit «der letzte
// Treffer gewinnt» — und **es ist genau daran umgefallen**: sechs Knoten namens `Integer` aus einem
// abgestürzten Prüflauf lagen unter `Data Types`, und `$seeded['int']` zeigte auf den letzten davon.
// Vier Zusagen wurden rot und eine fünfte starb mit einem `valueFrom() on null`. **Die Bindung war
// der Fehler, nicht der Müll:** [D-022](../../docs/NewConcept/90-decision-log.md) sagt, dass
// Knotennamen absichtlich nicht eindeutig sind.*
$byId = [];
foreach ($nodes->childrenOf($framework->rootOf(Branch::DataTypes)) as $child) {
    $byId[$child->id] = $child;
}

$seeded = [];
foreach (SimpleType::cases() as $type) {
    $id = $types->nodeId($type);
    if ($id !== null && isset($byId[$id])) { $seeded[$type->value] = $byId[$id]; }
}

$part = $editor->createNode('__p7 Part', $framework->rootOf(Branch::Model)->id);

echo "\n== 0. The base scaffold shipped the simple types ==\n";
foreach (['int', 'decimal', 'text', 'bool', 'email', 'datetime', 'color'] as $name) {
    // ⚠️ Braces, not bare interpolation: PHP takes the bytes of `»` as part of the variable name.
    check("«{$name}» is in the tree", isset($seeded[$name]));
}

if (count($seeded) < 7) {
    echo "\nThe simple types are not seeded — run the base scaffold first.\n";
    echo "\n---- $ok passed, $bad failed ----\n";
    exit(1);
}

$count  = $editor->addField($part->id, $seeded['int']->id, '__p7 count');
$weight = $editor->addField($part->id, $seeded['decimal']->id, '__p7 weight');
$label  = $editor->addField($part->id, $seeded['text']->id, '__p7 label');
$stock  = $editor->addField($part->id, $seeded['bool']->id, '__p7 in stock');
$mail   = $editor->addField($part->id, $seeded['email']->id, '__p7 contact');
$when   = $editor->addField($part->id, $seeded['datetime']->id, '__p7 checked');
$colour = $editor->addField($part->id, $seeded['color']->id, '__p7 body colour');

$every = [$count, $weight, $label, $stock, $mail, $when, $colour];

echo "\n== 1. Every attribute finds the renderer of its type ==\n";
$fields = [];
foreach ($rendering->fieldsFor($every, [], Purpose::Edit, 'taxmod_value') as $field) {
    $fields[$field->edge->id] = $field;
}

check('seven fields drawn', count($fields) === 7, (string) count($fields));
// ⚠️ **Umgeschrieben 2026-08-26 auf das, was diese Pruefung wirklich meint — Zeile 23.**
// Sie behauptete *an int gets the plain field* und wurde rot, als der Eigentuemer am `Integer`-Knoten
// `renderer = spinner` einstellte. **Das war kein Fehler im Code, sondern die Pruefung, die sich an
// bearbeitbare Daten lehnt** — derselbe Mangel, den `unitvalue-check` mit `persistent` hatte.
// *Was hier zaehlt, sind zwei Tatsachen, und keine davon haengt an seiner Wahl: der **Standard** fuer
// `int` ist das schlichte Feld ([R33c](../../docs/NewConcept/30-renderer.md)), und gezeichnet wird,
// **was die Kette sagt** — was sein `spinner` gerade beweist.*
check(
    'the type default for int is the plain field',
    $registry->defaultFor(SimpleType::Int, Purpose::Edit)->name() === FieldRenderer::NAME,
    $registry->defaultFor(SimpleType::Int, Purpose::Edit)->name()
);
$chainSays = ($rendering->settingsForUseSites([$count])[$count->id][SettingKey::Renderer->value] ?? null)?->value->text;
check(
    'and an int is drawn by whatever its chain says',
    $fields[$count->id]->rendererName === ($chainSays ?? FieldRenderer::NAME),
    $fields[$count->id]->rendererName . ' vs ' . ($chainSays ?? '(nichts gesetzt)')
);
check('a bool gets the sliding switch', $fields[$stock->id]->rendererName === ToggleRenderer::NAME, $fields[$stock->id]->rendererName);
check('a datetime gets the date renderer', $fields[$when->id]->rendererName === DateTimeRenderer::NAME, $fields[$when->id]->rendererName);
check('and none of them is the fallback', count(array_filter($fields, static fn ($f): bool => $f->hasNoRenderer())) === 0);

echo "\n== 2. The controls are the controls, not text boxes ==\n";
check('the switch is a checkbox', str_contains($fields[$stock->id]->result->markup, 'type="checkbox"'));
check('the switch submits false when unticked', str_contains($fields[$stock->id]->result->markup, 'type="hidden"'));
check('the date is a date control', str_contains($fields[$when->id]->result->markup, 'type="datetime-local"'));
check('the address is an address control', str_contains($fields[$mail->id]->result->markup, 'type="email"'));
check('the colour is a picker', str_contains($fields[$colour->id]->result->markup, 'type="color"'));
check(
    'every field is keyed by its edge, never by position',
    str_contains($fields[$label->id]->result->markup, 'name="taxmod_value[' . $label->id . ']"')
);

// ⚠️ R28: a control offers only real choices. The browser carries the **same** rule the core
// applies, so a field cannot accept what the save will refuse (D-356).
// ⚠️ **Umgeschrieben 2026-08-26 auf die Regel statt auf eine Schreibweise — Zeile 23.** Sie verlangte
// wortwoertlich `pattern="…"` und wurde rot, als der Eigentuemer `renderer = spinner` einstellte: ein
// `type="number"` traegt dieselbe Regel als `min`/`max`/`step`. *Der Spinner war richtig, die Zusicherung
// zu eng — sie pruefte die Vokabel eines Renderers, wo [D-356](../../docs/NewConcept/90-decision-log.md)
// eine Tatsache verlangt: **das Steuerelement traegt die Regel, die der Kern anwendet.** Welche Form es
// dafuer hat, ist die Sache des Steuerelements.*
$intMarkup = $fields[$count->id]->result->markup;

// WICHTIG: Eine dritte Schreibweise dazugenommen, und zwar aus demselben Grund wie die zweite
// (D-356, PR-9): der Eigentuemer hat am gesaeten `Integer` `renderer = slider` gewaehlt, und seit
// D-602 erreicht diese Wahl am Typ jede Verwendung. Ein `type="range"` traegt die Regel als
// min/max/step und **kann keine Buchstaben annehmen** -- die Zusage der Zeile ist erfuellt, nur
// nicht in einer der zwei Formen, die hier bisher aufgezaehlt waren. Aufgezaehlt wird weiter, statt
// die Zusage zu weiten: ein nacktes Textfeld faellt nach wie vor durch.
check(
    'an int field does not offer letters it will then refuse',
    str_contains($intMarkup, 'pattern="' . SimpleType::Int->pattern() . '"')
        || (str_contains($intMarkup, 'type="number"') && str_contains($intMarkup, 'step='))
        || (str_contains($intMarkup, 'type="range"') && str_contains($intMarkup, 'step=')),
    $intMarkup
);
check(
    'and a text field is given no pattern it has no business having',
    ! str_contains($fields[$label->id]->result->markup, 'pattern')
);

echo "
== 3. A choice at the use site beats the type default ==
";
$angabe($count, SettingKey::Renderer->value, TypedValue::ofText(SpinnerRenderer::NAME));
$rendering = $zeichnerNeu();

$chosen = $rendering->fieldsFor([$count], [], Purpose::Edit, 'taxmod_value')[0];
check('the spinner was chosen', $chosen->rendererName === SpinnerRenderer::NAME, $chosen->rendererName);

// WICHTIG: Hier standen die Grenzen und die Verweigerung des Weitens (D-312). min und max wurden am
// Typ gesetzt, an der Stelle enger gesetzt, und ein Weiten musste CannotWiden werfen. **Die Regel
// lebte in Settings::put(), und der Dienst ist mit der settings-Tabelle gestrichen (D-579)** — es
// gibt heute keine Stelle mehr, die ein Weiten pruefen koennte. **Das ist ein Verlust und keine
// Vereinfachung**, und er steht als INF-012 im Eingang: wer prueft kuenftig, dass eine Grenze nur
// enger wird.

echo "
== 4. Eine Wahl am Typ erreicht jede Verwendung ==
";
// WICHTIG: Wieder eine echte Zusage seit D-602. Sie hing an der Aufloesungskette der
// settings-Tabelle, stand seit deren Streichung (D-579) als INF-010 offen und ist nicht
// abgeschwaecht worden: geprueft wird die ganze Kette, jede Stufe einzeln und die Reihenfolge.
//
// WICHTIG: Gebaut wird auf eigenen __p7-Knoten unter dem gesaeten int und nicht am gesaeten Typ
// selbst. Eine Wahl dort waere eine Aenderung an den Daten des Eigentuemers, und ein Wegnehmen
// danach wuerde seine eigene Wahl mitnehmen -- der Lauf hat am gesaeten decimal schon einmal
// aufgeraeumt, was ihm gehoerte.
$eigenerTyp = $editor->createNode('__p7 Int', $seeded['int']->id);
$unterTyp   = $editor->createNode('__p7 Int schmal', $eigenerTyp->id);

$eins = $editor->addField($part->id, $eigenerTyp->id, '__p7 erste Zahl');
$zwei = $editor->addField($part->id, $eigenerTyp->id, '__p7 zweite Zahl');
$tief = $editor->addField($part->id, $unterTyp->id, '__p7 tiefe Zahl');

/** @return array<int,string> Kanten-Id => Name des Renderers, mit frischem Gedaechtnis gezeichnet. */
$gezeichnet = static function (array $kanten) use (&$zeichnerNeu): array {
    $aus = [];
    foreach ($zeichnerNeu()->fieldsFor($kanten, [], Purpose::Edit, 'taxmod_value') as $feld) {
        $aus[$feld->edge->id] = $feld->rendererName;
    }

    return $aus;
};

$angabe($eigenerTyp, SettingKey::Renderer->value, TypedValue::ofText(SpinnerRenderer::NAME));
$jetzt = $gezeichnet([$eins, $zwei, $tief]);

check('Stufe 2: die Verwendung sieht die Wahl am Zielknoten', $jetzt[$eins->id] === SpinnerRenderer::NAME, $jetzt[$eins->id]);
check('einmal gesetzt, nicht je Verwendung', $jetzt[$zwei->id] === SpinnerRenderer::NAME, $jetzt[$zwei->id]);
check('Stufe 3: ein Nachfahre des Typs erbt sie', $jetzt[$tief->id] === SpinnerRenderer::NAME, $jetzt[$tief->id]);

// WICHTIG: naeher schlaegt ferner (D-602). Der Untertyp sagt etwas anderes als sein Vorfahr, und
// er gewinnt -- fuer sich, ohne die Geschwister zu aendern.
$angabe($unterTyp, SettingKey::Renderer->value, TypedValue::ofText(FieldRenderer::NAME));
$jetzt = $gezeichnet([$eins, $tief]);

check('naeher schlaegt ferner', $jetzt[$tief->id] === FieldRenderer::NAME, $jetzt[$tief->id]);
check('und nur dort', $jetzt[$eins->id] === SpinnerRenderer::NAME, $jetzt[$eins->id]);

// WICHTIG: Stufe 1 schlaegt alles -- die Wahl an der Verwendungsstelle steht vor der ganzen Kette.
$angabe($eins, SettingKey::Renderer->value, TypedValue::ofText(FieldRenderer::NAME));
$jetzt = $gezeichnet([$eins, $zwei]);

check('Stufe 1: die Kante schlaegt den Typ', $jetzt[$eins->id] === FieldRenderer::NAME, $jetzt[$eins->id]);
check('und die Nachbarkante bleibt, was der Typ sagt', $jetzt[$zwei->id] === SpinnerRenderer::NAME, $jetzt[$zwei->id]);

// WICHTIG: Wird die eigene Angabe wieder weggenommen, faellt die Verwendung auf das zurueck, was
// weiter oben in der Kette steht -- hier der gesaete Typ, an dem der Eigentuemer selbst gewaehlt hat.
// **Stufe 4, der Rueckfall im Kode, ist an dieser Stelle nicht zu zeigen**, weil oberhalb etwas
// steht; sie ist im Kernlauf gepruefte Sache (RendererRegistry::defaultFor).
$ohneAngabe($eins, SettingKey::Renderer->value);
$ohneAngabe($unterTyp, SettingKey::Renderer->value);
$ohneAngabe($eigenerTyp, SettingKey::Renderer->value);
$jetzt = $gezeichnet([$eins, $zwei, $tief]);

$obenInDerKette = ($zeichnerNeu()->settingsForNode($seeded['int'])[SettingKey::Renderer->value] ?? null)?->value->text
    ?? $registry->defaultFor(SimpleType::Int, Purpose::Edit)->name();

check('weggenommen faellt sie auf die Kette darueber zurueck', $jetzt[$eins->id] === $obenInDerKette, $jetzt[$eins->id] . ' vs ' . (string) $obenInDerKette);
check('auch tief unten', $jetzt[$tief->id] === $obenInDerKette, $jetzt[$tief->id]);

$rendering = $zeichnerNeu();

echo "\n== 5. Values go in as their type and come back unchanged ==\n";
$record = $data->create($part->id);

$typed = [
    [$count,  '42',                 static fn ($v): bool => $v->int === 42],
    // ⚠️ **This line used to expect `2.5000000000`, which was the bug written down as a rule.**
    // `decimal(30,10)` pads on read, and the padding was reaching the screen — `2.7 kΩ` drew as
    // `2.7000000000 k Ω`. It comes off in the repository now (D-394), so a decimal reads back as a
    // number. *That `2.50` returns as `2.5` and not as typed is a real loss and a different question:
    // once written, `2.50` and `2.5` are one row — OQ-085 asks how much precision a decimal has.*
    [$weight, '2.50',               static fn ($v): bool => $v->decimal === '2.5'],
    [$label,  '4k7',                static fn ($v): bool => $v->text === '4k7'],
    [$stock,  '1',                  static fn ($v): bool => $v->asBool() === true],
    [$mail,   'a@b.example',        static fn ($v): bool => $v->text === 'a@b.example'],
    [$when,   '2026-08-25T14:32',   static fn ($v): bool => $v->date === '2026-08-25 14:32:00'],
    [$colour, '#663399',            static fn ($v): bool => $v->text === '#663399'],
];

$types = $rendering->typesFor($every);

foreach ($typed as [$edge, $characters, $expected]) {
    $data->put($record->id, $edge->id, $types[$edge->id]->valueFrom($characters));
}

$back = [];
foreach ($data->valuesOf($record->id) as $value) { $back[$value->edgeId] = $value->value; }

foreach ($typed as [$edge, $characters, $expected]) {
    check(
        "«{$edge->name}» survives a round trip",
        isset($back[$edge->id]) && $expected($back[$edge->id]),
        isset($back[$edge->id]) ? $back[$edge->id]->describe() : 'missing'
    );
}

echo "\n== 6. Each value is in the column its type says (D-071) ==\n";
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT value_int, value_decimal, value_text, value_date FROM ' . Schema::table('record_values')
    . ' WHERE record_id = %d AND edge_id = %d',
    $record->id,
    $when->id
), ARRAY_A);
check('a datetime lands in value_date and nowhere else',
    $row['value_date'] !== null && $row['value_int'] === null && $row['value_text'] === null,
    json_encode($row));

$row = $wpdb->get_row($wpdb->prepare(
    'SELECT value_int, value_text FROM ' . Schema::table('record_values')
    . ' WHERE record_id = %d AND edge_id = %d',
    $record->id,
    $stock->id
), ARRAY_A);
check('a bool lands in value_int as 1 (D-315)', (int) $row['value_int'] === 1 && $row['value_text'] === null, json_encode($row));

echo "\n== 7. What was stored is what the control shows again ==\n";
$reloaded = [];
foreach ($rendering->fieldsFor($every, $back, Purpose::Edit, 'taxmod_value') as $field) {
    $reloaded[$field->edge->id] = $field->result->markup;
}
check('the switch comes back ticked', str_contains($reloaded[$stock->id], 'checked'));
check('the date comes back in the control format', str_contains($reloaded[$when->id], 'value="2026-08-25T14:32"'), $reloaded[$when->id]);
check('the address comes back', str_contains($reloaded[$mail->id], 'value="a@b.example"'));
check('the colour comes back', str_contains($reloaded[$colour->id], 'value="#663399"'));

echo "\n== 8. Nothing is coerced ==\n";
try { $types[$count->id]->valueFrom('abc'); check('a word is not stored as the number zero', false); }
catch (NotAValueOfThatType $e) { check('a word is not stored as the number zero', true); }
try { $types[$when->id]->valueFrom('25.08.2026'); check('a date in another notation is refused, not guessed', false); }
catch (NotAValueOfThatType $e) { check('a date in another notation is refused, not guessed', true); }
check('an empty field is unanswered rather than zero', $types[$count->id]->valueFrom('')->isNothing());

echo "\n== 9. A subtype of a type is still that type ==\n";
$description = $editor->createNode('__p7 Description', $seeded['text']->id);
$notes       = $editor->addField($part->id, $description->id, '__p7 notes');
$sub         = $rendering->fieldsFor([$notes], [], Purpose::Edit, 'taxmod_value')[0];
check('an authored subtype inherits its type', $sub->type === SimpleType::Text, $sub->type?->value ?? 'null');
check('and therefore its renderer', $sub->rendererName === FieldRenderer::NAME, $sub->rendererName);

echo "\n== 10. A constant is drawn as its name, not as its id (D-105, D-232) ==\n";
$gram = $editor->createNode('__p7 Gramm', $framework->rootOf(Branch::Constants)->id);
$unit = $editor->addField($part->id, $gram->id, '__p7 unit');

$named = $rendering->fieldsFor(
    [$unit],
    [$unit->id => TypedValue::ofReference($gram->id)],
    Purpose::Display,
    ''
)[0];

check('the branch decides it is a reference', $named->type === SimpleType::NodeRef, $named->type?->value ?? 'null');
check('drawn by the reference renderer', $named->rendererName === 'reference', $named->rendererName);
check('showing the name', str_contains($named->result->markup, '__p7 Gramm'), $named->result->markup);
check('and not the id', ! str_contains($named->result->markup, (string) $gram->id));

// ⚠️ **Hier stand «die Luecke bleibt sichtbar: der Waehler ist entschieden (D-244) und nicht gebaut».
// Den Waehler gibt es.** *Der Eigentümer: «`ChoiceRenderer` war genau das, was ich mit Select-Renderer
// meinte, also gibt es ihn schon.» Ein Verweis beim Bearbeiten ist damit das, was er immer war: eine
// **Auswahl**.*
//
// ⚠️ **Die Absicht der Zusage bleibt und ist strenger:** *der gespeicherte Wert muss zu **sehen** sein.
// `__p7 Gramm` hat keine Kinder, also gibt es genau **einen** Ausgang — den gespeicherten
// ([D-360](../../docs/NewConcept/90-decision-log.md)) — und [R30](../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)
// sagt: vorausgewaehlt und ausgegraut. **Eine leere gesperrte Auswahl haette den Wert verschwinden
// lassen, und das naechste Speichern haette «nichts» geschrieben.***
$editing = $rendering->fieldsFor(
    [$unit],
    [$unit->id => TypedValue::ofReference($gram->id)],
    Purpose::Edit,
    'taxmod_value'
)[0];
check('editing a reference is a choice now', ! $editing->hasNoRenderer(), $editing->rendererName);
check('drawn by the choice renderer', str_contains($editing->result->markup, 'taxmod-choice'));
check('the stored value is visible', str_contains($editing->result->markup, '__p7 Gramm'));
check('and one outcome is not a decision (R30)', str_contains($editing->result->markup, 'disabled'));

echo "\n== 11. Hide and read-only close a field wherever it is drawn ==\n";
// ⚠️ *Die Spalte, nicht die Einstellung ([D-457]): `hide` ist eine Eigenschaft der Platzierung,
// und der Abstieg **bricht ab** statt ein leeres Feld zu zeichnen ([D-450]).*
$edges->save($label->withHide(true), $label->version);

// ⚠️ *Frisch holen, und zwar **alle**: eine Kante ist unveraenderlich, also haelt jede aeltere
// Kopie weiter `hide = false`. Genau das hat diese Pruefung beim Umbau gefangen.*
$frisch = [];
foreach ($edges->fieldEdgesOf([$part->id]) as $one) { $frisch[$one->id] = $one; }
$label = $frisch[$label->id] ?? $label;
$every = array_map(static fn ($e) => $frisch[$e->id] ?? $e, $every);
$angabe($mail, SettingKey::ReadOnly->value, TypedValue::ofBool(true));
$rendering = $zeichnerNeu();

$closed = [];
foreach ($rendering->fieldsFor([$label, $mail], $back, Purpose::Edit, 'taxmod_value') as $field) {
    $closed[$field->edge->id] = $field;
}
// ⚠️ **Beide Behauptungen sind 2026-08-28 gedreht** ([D-450], [D-452], [D-457]): `hide` ist ein
// **Abbruch**. Das Feld wird nicht leer gezeichnet, es wird **nicht aufgezaehlt** — «und schaut auch
// nicht mehr auf die Kinder» ist die Formulierung des Eigentuemers, und eine Listenzeile mit leerem
// Markup erfuellt sie nicht.
check('a hidden field is not enumerated at all', ! isset($closed[$label->id]));
check('and the others are still there', count($closed) === 1);
check('a read-only address is shown, not offered', ! str_contains($closed[$mail->id]->result->markup, '<input'));
check('and it is still a link', str_contains($closed[$mail->id]->result->markup, 'mailto:'));

echo "\n== 12. Not searchable is a missing capability, not a special case ==\n";
check('no attribute is offered for search yet (D-217)', $rendering->fieldsFor($every, [], Purpose::Search, 'q') === []);
// ⚠️ *Sechs von sieben: eines ist seit Abschnitt 11 versteckt, und `hide` ist ein **Abbruch** —
// es wird nicht aufgezaehlt ([D-450]). Die Aussage der Zeile bleibt dieselbe: **ein Wert wird nicht
// stillschweigend weggelassen**, nur weil ein Zweck nichts damit anfangen kann.*
check('a value is never dropped the same way', count($rendering->fieldsFor($every, [], Purpose::Display, '')) === count($every) - 1);

echo "\n== 13. The whole form costs a fixed number of queries (CD-7) ==\n";
$before = $wpdb->num_queries;
$rendering->fieldsFor($every, $back, Purpose::Edit, 'taxmod_value');
$spent = $wpdb->num_queries - $before;
// ⚠️ **Die Grenze stand auf 4 und steht jetzt auf 8, und der Grund gehoert dazu.** *Seit dem
// 2026-08-30 geht der Zeichenlauf in zusammengesetzte Felder hinein — seine Diagnose: «heisst wohl
// Renderkette ist unterbrochen». Der Unterbau wird dabei **je Stufe** geladen, nicht je Feld
// ([D-159](../../docs/NewConcept/90-decision-log.md)), also kommen hoechstens drei Abfragen dazu.*
//
// ⚠️ **Was `CD-7` verbietet, ist «eine Abfrage je Zeile», nicht «mehr als vier».** *Deshalb steht
// darunter die Zusage, die die Regel wirklich prueft: **doppelt so viele Felder kosten nicht mehr.***
check('seven fields do not cost seven walks', $spent <= 8, "$spent queries for 7 fields");

$before   = $wpdb->num_queries;
$rendering->fieldsFor([...$every, ...$every], $back, Purpose::Edit, 'taxmod_value');
$doppelt  = $wpdb->num_queries - $before;

check(
    'and twice as many fields cost no more',
    $doppelt <= $spent,
    "{$doppelt} for 14 fields against {$spent} for 7"
);

echo "\n== 14. The renderer is chosen, never typed (D-358) ==\n";
$offeredForInt = array_map(
    static fn ($r): string => $r->name(),
    $rendering->choicesForNode($nodes->byId($seeded['int']->id))
);
sort($offeredForInt);
check('an int is offered exactly its three ways', $offeredForInt === ['field', 'slider', 'spinner'], implode(', ', $offeredForInt));
// ⚠️ This read *nothing rather than everything* until the form renderer existed. A thing wants a
// **structural** renderer — chosen for what it is — and now there is one. Offering a spinner for a
// supplier is still the mistake it always was.
// All three structural renderers, and all three legitimate for a thing: `form` stacks its attributes
// (D-098), `compact` puts them on one line or in one column (D-471), `node` draws it as a whole page
// (D-256). A typed one is still refused.
$offeredForThing = array_map(static fn ($r): string => $r->name(), $rendering->choicesForNode($nodes->byId($part->id)));
sort($offeredForThing);
check(
    'a thing under Model is offered the structural renderers only',
    $offeredForThing === ['compact', 'form', 'node', 'table'],
    implode(', ', $offeredForThing)
);
check(
    'a bool is not offered a spinner',
    ! in_array('spinner', array_map(
        static fn ($r): string => $r->name(),
        $rendering->choicesForNode($nodes->byId($seeded['bool']->id))
    ), true)
);

// ⚠️ Offered and allowed are two questions (D-360). The list is what makes sense; a deliberate
// exception is somebody's special case, and only a name nothing answers to is an error.
check('what is not offered is still not forbidden', $rendering->knowsRenderer('checkbox'));
check('a name nothing answers to is refused', ! $rendering->knowsRenderer('__p7 no such renderer'));
check('and the fallback is not choosable at all', ! $rendering->knowsRenderer('plain'));

echo "\n== 15. The settings side is drawn, not printed (R20a) ==\n";
$intNode = $nodes->byId($seeded['int']->id);
// ⚠️ `mandatory` was the switch here until [D-405] and `hide` until [D-457] — `read_only` makes the
// same point and is the one that stays a setting ([D-461]).
$angabe($intNode, SettingKey::ReadOnly->value, TypedValue::ofBool(true));
$rendering = $zeichnerNeu();

$rows = [];
foreach ($rendering->settingsFor($intNode, $rendering->settingsForNode($intNode)) as $row) {
    $rows[$row->key] = $row;
}

check('a boolean setting is drawn as a sliding switch',
    isset($rows['read_only']) && $rows['read_only']->wasDrawn()
        && str_contains($rows['read_only']->result->markup, 'taxmod-toggle-track'),
    isset($rows['read_only']) ? ($rows['read_only']->result->markup ?? 'undrawn') : 'missing');
check('a borrowing key takes the type of the node it sits on',
    isset($rows['step']) ? $rows['step']->type === SimpleType::Int : true);
// ⚠️ **This check used to assert the opposite, and the old reason was honest at the time:** a
// choice wanted a chooser and none was built, so a text box would have been the second way to draw
// a field (R20a). **The chooser exists** (R28-R32 implemented in full), so the assertion is
// rewritten rather than deleted — a check that no longer matches the decision is worse than none.
$editRows = [];
foreach ($rendering->settingsFor($intNode, $rendering->settingsForNode($intNode), Purpose::Edit) as $row) {
    $editRows[$row->key] = $row;
}

check('a choice is drawn as a set of real possibilities',
    isset($editRows['renderer']) && $editRows['renderer']->wasDrawn()
        && str_contains($editRows['renderer']->result->markup, '<select'),
    isset($editRows['renderer']) ? substr($editRows['renderer']->result->markup ?? 'undrawn', 0, 90) : 'missing');

// ⚠️ **R31**: nothing to choose means the control is disabled rather than an empty box that looks
// fillable. `converter` is the honest live case - D-219 decided them and none is built.
check('a choice with nothing in it is a dead control, not an empty one',
    isset($editRows['converter']) && str_contains($editRows['converter']->result->markup ?? '', 'disabled'),
    isset($editRows['converter']) ? substr($editRows['converter']->result->markup ?? 'undrawn', 0, 90) : 'missing');

// ⚠️ The last guesser: a setting now reads back as the type its key declares, not by regex.
//
// ⚠️ **Gemessen am **eigenen** Knoten und nicht mehr am gesaeten `Integer`.** *Der traegt echte
// Saetze des Eigentuemers, und seit die Angabe im **Datensatz** steht (D-529/D-579) gewinnt der
// erste Satz, der etwas dazu sagt — eine Zusage, die dort schreibt, misst danach die Unordnung der
// Installation und nicht die Regel. **Auf der eigenen Wiese gehoert die Antwort dem Waechter.***
$eigenerSchalter = $nodes->byId($part->id);

$angabe($eigenerSchalter, SettingKey::ReadOnly->value, TypedValue::ofBool(true));
$rendering = $zeichnerNeu();

check('a switch reads back as a boolean, not as the number one',
    ($rendering->settingsForNode($eigenerSchalter)['read_only'] ?? null)?->value->asBool() === true);

$ohneAngabe($eigenerSchalter, SettingKey::ReadOnly->value);
$ohneAngabe($intNode, SettingKey::ReadOnly->value);

echo "\n== 16. A node is drawn by a container, not by a screen (D-098, R46, R75) ==\n";
$formed = $rendering->nodeAsForm(
    $nodes->byId($part->id),
    $every,
    $back,
    Purpose::Edit,
    'taxmod_value'
);

check('the form drew something', str_contains($formed->markup, 'taxmod-form'));
check('every member is in it', count(array_filter(
    $every,
    static fn ($e): bool => str_contains($formed->markup, $e->name)
)) === count($every) - 1, 'one is hidden by the column from section 11');
check('and it says which edges went into it (D-021)', $formed->usedEdges !== []);

// R75: read-only values first. `__p7 contact` was made read-only in section 11.
$readOnlyAt = strpos($formed->markup, '__p7 contact');
$ordinaryAt = strpos($formed->markup, '__p7 count');
check('read-only comes before the ordinary fields', $readOnlyAt !== false && $readOnlyAt < $ordinaryAt, "$readOnlyAt vs $ordinaryAt");

// R75: booleans collected after the ordinary fields.
$boolAt = strpos($formed->markup, '__p7 in stock');
check('booleans are collected after them', $boolAt !== false && $boolAt > $ordinaryAt, "$boolAt vs $ordinaryAt");

echo "\n== 17. The screen renders at all ==\n";

// ⚠️ **The check that was missing.** A fatal error reached the screen today — a control group handed
// in as a string where a list was expected — and nothing guarded it: the boundary runs exercise
// services, and `render()` had no check of any kind. `PR-9` asks every package to add to the net.
// `Plugin` is constructed by `boot()` alone, so the screen is wired here the way it wires it —
// which is itself worth having: if the two ever drift, this check says so.
try {
    $screen = new Taxmod\WordPress\Admin\NodesScreen(
        $editor,
        new Taxmod\Core\Service\Tree($nodes, $edges),
        $labels,
        $data,
        $framework,
        $rendering,
        // ⚠️ **Dieser Check hat sich selbst bewiesen.** *Sein Kommentar oben sagt «wenn die beiden je
        // auseinanderlaufen, sagt dieser Check es» — und genau das ist am 2026-08-28 passiert, als der
        // Screen einen Changelog bekam ([Zeile 45](../../docs/NewConcept/97-implementation-plan.md#the-working-list)).
        // **Dasselbe Exemplar wie oben**, denn die Klammer nuetzt nur, wenn Schreiber und Klammer ein
        // Objekt teilen.*
        $log
    );

    $markup = $screen->render();

    check('render() returns markup rather than dying', str_starts_with($markup, '<div class="wrap"'));
    // ⚠️ **This line used to look for the literal `<div class="wrap">`** and broke the moment
    // [D-397](../../docs/NewConcept/90-decision-log.md) put the chosen sizes on the wrapper as custom
    // properties. *It was testing the string rather than the thing; a check that pins markup it does
    // not care about fails for reasons that teach nobody anything.* **So it now asserts what it meant
    // — the screen opens with its wrapper — and the sizes get a check of their own.**
    check(
        'the chosen sizes ride on the wrapper',
        (bool) preg_match('#^<div class="wrap" style="--taxmod-icon:\d+px;--taxmod-font:\d+px">#', $markup)
    );
    check('the tree is drawn by the cell', str_contains($markup, 'taxmod-tree-node'));
    check('a row carries its controls', str_contains($markup, 'value="add_child_here"'));
    // ⚠️ **The same four controls on every row** (D-370). What cannot be done is greyed, not gone —
    // so the counts must match exactly, and a disabled one must appear.
    check(
        'every row carries the same four controls',
        substr_count($markup, 'value="trash_node"') === substr_count($markup, 'value="add_child_here"')
            && substr_count($markup, 'value="up"') === substr_count($markup, 'value="add_child_here"')
    );
    check('and what cannot be done is disabled rather than absent', str_contains($markup, 'disabled'));
    // ⚠️ Off by default: the write count is a diagnostic and waits for developer mode (D-248).
    check('the write count is off unless developer mode says otherwise', ! str_contains($markup, 'taxmod-tree-writes'));

    // ⚠️ **And now with a node *selected*, because that is the half the check was missing.** The
    // fatal it was written for happened in the tree; the very next one happened in the **detail**
    // pane — `RenderResult` has `markup` and the attribute renderer asked it for `html` — and this
    // check sailed past it, because with nothing selected the detail pane draws *nothing selected*
    // and no attribute row is ever built. *A smoke check that only exercises the empty state is a
    // smoke check for the empty state.*
    $withAttributes = null;

    // ⚠️ **Gesucht wird ein Knoten mit einem *eigenen* Feld, nicht mit irgendeinem.** *Bis zum
    // 2026-09-05 nahm diese Schleife den **letzten** Knoten unter `Compositions`, der überhaupt
    // Felder zeigt — und das war ein `__Test` aus einem abgestürzten Geruestlauf, der **nur geerbte**
    // trug. Die Zusage darunter heisst «an own attribute's name is editable»; ein geerbtes Feld hat
    // dort **richtigerweise** kein Eingabefeld ([D-376](../../docs/NewConcept/90-decision-log.md)),
    // also mass die Auswahl das Gegenteil ihrer eigenen Zusage.*
    //
    // ⚠️ *Das ist keine Abschwächung: die Zusage bleibt Wort für Wort dieselbe, sie bekommt nur den
    // Gegenstand, von dem sie redet. **Findet sich kein Knoten mit eigenem Feld, wird sie rot** — die
    // Zeile darunter sagt es dann statt still durchzulaufen.*
    foreach ($editor->childrenOf($framework->rootOf(Branch::Compositions)->id) as $candidate) {
        foreach ($editor->fieldsOf($candidate->id) as $edge) {
            if ($edge->fromNodeId === $candidate->id) {
                $withAttributes = $candidate;

                break;
            }
        }
    }

    if ($withAttributes === null) {
        check('a node with an own attribute exists to select', false, 'nothing under Compositions declares one');
    } else {
        $_GET['taxmod_node'] = (string) $withAttributes->id;

        $detail = $screen->render();

        unset($_GET['taxmod_node']);

        check('render() survives a node that has attributes', str_starts_with($detail, '<div class="wrap"'));
        check('the field table is drawn by the field-row renderer', str_contains($detail, 'taxmod-field"'));
        // R1: the name is a field, not text — which is the thing the owner asked for.
        check('an own attribute\'s name is editable', str_contains($detail, 'taxmod-field-rename'));
        // ⚠️ The multiplicity comes from the settings side through the choice renderer, so a select
        // in this cell is also the proof that no second control was built beside it (D-376).
        check('the multiplicity is a real chooser', str_contains($detail, 'taxmod-choice'));
        // ⚠️ **Der Name trägt jetzt die Kanten-Id, und diese Zusage ist rot geworden, wie sie soll.**
        // *`taxmod_setting[multiplicity]` war **ein** Name für jede Zeile der Tabelle — solange nur die
        // Diskette der eigenen Zeile ihn abschickte, ging das. Seit alles am Seitenformular hängt (sein
        // Befund: «kann es aber nicht mit dem Speichern-Knopf in der Seite speichern») wäre es eine
        // Angabe für sechzig Zeilen. **`PR-12`: der Wächter ist mit dem Leser gewandert.***
        check(
            'and it posts to the field the handler reads',
            (bool) preg_match('/taxmod_field_setting\[\d+\]\[multiplicity\]/', $detail)
        );

        // ⚠️ **The settings panel is one form and the save button is outside it** (D-392). *Checked
        // because the two halves are in different files: the renderer gives the form its id, the
        // screen puts a `form="…"` button in the head, and neither notices if the other changes.*
        // ⚠️ **A panel per subject, and every id distinct** — the node has one and so does every
        // attribute row (D-381). *A fixed id looked right and was wrong: `form="…"` finds the first
        // match, so the head button would have saved whichever panel came earliest in the document.
        // This check found that within a minute of the id being written.*
        // ⚠️ **Die Tafel je Feldzeile ist entfallen** ([D-520](../../docs/NewConcept/90-decision-log.md)),
        // *also gibt es keine Panels mehr, deren Ids sich unterscheiden müssten. **Was von der Zusage
        // übrig bleibt und weiter gilt**: keine zwei Elemente auf der Seite teilen eine Id — daran
        // scheiterte damals ein fester Wert, und `form="…"` nimmt den ersten Treffer.*
        preg_match_all('#\sid="([^"]+)"#', $detail, $alleIds);
        $doppelt = array_keys(array_filter(array_count_values($alleIds[1]), static fn (int $n): bool => $n > 1));

        check('keine Id kommt zweimal vor', $doppelt === [], implode(', ', $doppelt));
        check('a row is a row and no longer a form', ! str_contains($detail, 'class="taxmod-setting" style'));
        // ⚠️ **Der Knopf im Kopf nennt jetzt das Formular der **Seite**, nicht das eines Blocks**
        // ([D-517](../../docs/NewConcept/90-decision-log.md)). *Bis zum 2026-08-29 lieh sich die Seite
        // das `<form>` des Einstellungsblocks; als der Block ging, zeigte der Knopf ins Leere. **Das ist
        // die Abhängigkeit, die diese Zusicherung eigentlich meint**: der Knopf steht ausserhalb seines
        // Formulars und muss es benennen — welches, entscheidet die Seite.*
        check('and a button outside the form names it', (bool) preg_match('#form="taxmod-(?:page|settings)-\d+"#', $detail));
        // ⚠️ *Die Zeilen-Akte `empty_setting` und `reset_setting` sind mit der Tafel gegangen
        // ([D-520](../../docs/NewConcept/90-decision-log.md)), also gibt es kein `do[<key>]` mehr auf
        // der Seite. **Die Form bleibt richtig und der Annahme-Pfad liest sie weiter** — was fehlt,
        // ist das Steuerelement, und das steht auf [Zeile 82](../../docs/NewConcept/97-implementation-plan.md#the-working-list).*
        check('kein Zeilen-Akt ohne eigenen Schlüssel', ! (bool) preg_match('#name="do\[\]"#', $detail));
        // ⚠️ **No boxes round the icons, anywhere** — the owner said it twice because the first fix
        // reached only the tree. The renderers mark such a button now, so this counts the mark.
        check('every icon button is marked so no surface has to guess',
            substr_count($detail, 'taxmod-icon-button') > 0
                // ⚠️ *Gezählt wird seit [D-495](../../docs/NewConcept/90-decision-log.md) **unsere**
                // Klasse und nicht mehr `dashicons`. Das ist die bessere Zusage: `dashicons` ist
                // die Zeichentabelle von WordPress, `taxmod-icon` ist die Stelle, die den Kasten
                // setzt — und ein Icon, das sie nicht trägt, ist genau das, was schiefgeht.*
                && substr_count($detail, '<span class="taxmod-icon') >= substr_count($detail, 'taxmod-icon-button'));
        // ⚠️ *not defined* stood on almost every row and said what an empty control already says.
        check('nothing is said where nothing was said', ! str_contains($detail, 'not defined'));
        // ⚠️ **`1..1` reads `1`** — shown, never stored: the option's value keeps the stored form.
        //
        // ⚠️ **Und die Zusage gilt für den **ganzen sichtbaren Text**, nicht nur für diese eine
        // Option** ([D-496](../../docs/NewConcept/90-decision-log.md)). *Der Eigentümer hat am
        // 2026-08-29 gefragt, warum `1..1` «immer wieder auftaucht». Nachgemessen: **im Produkt
        // nirgends** — es stand in meinen Berichten. **Damit das eine Messung bleibt und nicht eine
        // Erinnerung wird, prüft es diese Zeile:** Attribute weg, nur der Text zählt, denn
        // `value="1..1"` ist die Speicherform und darf dastehen.*
        check(
            'die Speicherform 1..1 steht nirgends im sichtbaren Text',
            ! str_contains(strip_tags($detail), '1..1'),
            'D-376: ein Mensch liest 1'
        );
        check('exactly one reads as 1 while storing 1..1', str_contains($detail, 'value="1..1">1<'));
        // The owner's ask: the settings half scrolls on its own so the tree stays put.
        check('the detail half has its own scrollbar', str_contains($detail, 'taxmod-detail-pane'));
    }
} catch (Throwable $e) {
    check('render() returns markup rather than dying', false, $e->getMessage());
}

echo "\n== 17b. The stylesheet actually reaches a browser ==\n";

// ⚠️ **The check that was missing, and the reason it is worded this way.** The screen's paint moved
// into `assets/admin.css` (D-391) and then **never loaded**: this repository reaches
// `wp-content/plugins` through a Windows **junction**, which is not a symlink, so `plugins_url()`
// could not relate the real path to the plugins directory and concatenated it —
// `…/wp-content/plugins/C:/Devel/Wordpress/source/wp-taxonomy-tree/assets/admin.css`.
//
// ⚠️ *I had "verified" it by building the path by hand and asking whether **that** URL answered. It
// did. **A check that constructs what it is verifying verifies nothing** — so this one asks the plugin
// what it enqueued and fetches exactly that. The owner said the screen still looked wrong twice while
// I told him to reload.*
require_once ABSPATH . 'wp-admin/includes/plugin.php';

do_action('admin_menu');
do_action('admin_print_styles-toplevel_page_taxmod');

$style = $GLOBALS['wp_styles']->registered['taxmod-admin'] ?? null;

check('the stylesheet is enqueued on the screen', $style !== null);

if ($style !== null) {
    check(
        'and its URL is under wp-content/plugins, not a filesystem path',
        (bool) preg_match('#^https?://[^/]+/wp-content/plugins/[^:]+/assets/admin\.css$#', (string) $style->src),
        (string) $style->src
    );

    $headers = @get_headers((string) $style->src, true, stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true],
    ]));
    $status = is_array($headers[0] ?? null) ? $headers[0][0] : ($headers[0] ?? 'no answer');

    check('and a browser asking for it gets it', str_contains((string) $status, '200'), (string) $status);

    // ⚠️ Versioned by the file's own change time while the screen is being built, so every save is a
    // new URL — the owner reloaded three times on a stylesheet that was correct and cached.
    check('and every save is a new URL', (string) $style->ver !== '');
}

echo "\n== 18. Clearing up ==\n";
foreach ($data->recordsOf($part->id) as $r) {
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', $r->id));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $r->id));
}

// ⚠️ The settings written onto the seeded types must go too, or the next run inherits a slider
// on every decimal in the installation.
$ohneAngabe($nodes->byId($seeded['decimal']->id), SettingKey::Renderer->value);

// ⚠️ **Und die Einstellungskanten, die dieser Lauf selbst gelegt hat** (TASK-047). *Sie stehen an
// **gesaeten** Knoten — `Decimal`, `Integer` — und lassen sich darum nicht ueber `__p7%` finden; die
// Aufraeumung unten geht an ihnen vorbei. **Aufheben, dann loeschen**
// ([D-535](../../docs/NewConcept/90-decision-log.md)): die Schattenzeile ist der Rueckweg.*
if ($selbstGelegteKanten !== []) {
    $liste = implode(',', array_map('intval', $selbstGelegteKanten));

    \Taxmod\WordPress\Persistence\Shadow::keep('relations', 'id IN (' . $liste . ')', [], true);

    $wpdb->query('DELETE FROM ' . Schema::table('record_values') . ' WHERE edge_id IN (' . $liste . ')');
    $wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE id IN (' . $liste . ')');
}

// ⚠️ **By name, not by the ids of this run.** A run that dies before this point — one did, on a
// `range_min` the owner had set by hand — leaves its scratch nodes behind, and the next run then
// reports them as its own failure. Cleaning up by name makes the check self-healing.
$scratchIds = $wpdb->get_col(
    'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p7%" ORDER BY LENGTH(path) DESC'
);

// ⚠️ **What hangs off them goes first, and measuring is what found this.** After the 892 orphaned
// setting rows were cleared, the whole net was run check by check and the counter watched: **this file
// was the only one still leaking, five rows a run.** *It deleted the nodes and the edges and left their
// settings behind — [row 28](../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s fault
// in its purest form, produced by the very net that is supposed to catch it.*
// ⚠️ **By endpoint *and* by name, and the second half took a second measurement.** The first fix
// gathered edges through `edgesTouching()` — their endpoints — and **five rows still leaked**: three
// edge owners survived because their nodes had already gone in an earlier run, so no endpoint pointed
// at them any more. *The raw delete below finds those edges by **name**, so the cleanup has to look
// them up the same way, or it clears the settings of exactly the edges it can still see.*
$ownersToClear = array_map('intval', $scratchIds);

foreach ($edges->edgesTouching($ownersToClear) as $edge) {
    $ownersToClear[] = $edge->id;
}

foreach ($wpdb->get_col('SELECT id FROM ' . Schema::table('relations') . ' WHERE name LIKE "__p7%"') as $named) {
    $ownersToClear[] = (int) $named;
}

if ($ownersToClear !== []) {
    (new WpdbLabelRepository())->forgetOwners($ownersToClear);
}

// WICHTIG: Auch die Datensaetze, und das ist derselbe Fehler wie bei den Beschriftungen eine Stufe
// hoeher. Der Lauf legt an jedem Knoten, an dem er eine Angabe macht, einen Datensatz an ($satzVon)
// -- und loeschte bisher nur die Knoten. Gemessen am 2026-09-04: 125 Datensaetze ohne Knoten allein
// von diesem Tag, und id-space-check und package6-check melden sie beide als Waisen.
(new WpdbRecordRepository())->forgetNodes(array_map('intval', $scratchIds));

foreach ($scratchIds as $scratch) {
    $node = $nodes->find((int) $scratch);
    if ($node !== null) { $edges->purgeEdgesTouching($node->id); $nodes->purgeSubtree($node); }
}

$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "__p7%"');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__p7%"');

$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p7%"');
check('scratch nodes are gone', $left === 0, "$left left");

// ⚠️ **Rewritten 2026-08-26 for [D-423](../../docs/NewConcept/90-decision-log.md), not loosened.** It
// used to assert that `decimal` holds **no setting rows at all** — a fair reading of *did this check
// litter?* while settings were sparse ([D-015](../../docs/NewConcept/90-decision-log.md)). **Now every
// node legitimately carries its own rows**, so the old form failed with *3 left* the moment the
// backfill ran, and those three are `persistent`, `hide` and `read_only` doing exactly what they
// should.
//
// ⚠️ *What the check actually needs to know is narrower and was always the point: **the key this run
// wrote is gone.** Counting every row was a proxy that stopped being equivalent — and a proxy that
// fails for the right reason still has to be replaced by the thing it stood for.*
// ⚠️ *Gefragt wird jetzt das Modell und nicht mehr die gestrichene Tabelle (D-579): traegt der
// gesaete Typ noch die Wahl, die dieser Lauf gesetzt hat?*
$stray = ($zeichnerNeu()->settingsForNode($nodes->byId($seeded['decimal']->id))[SettingKey::Renderer->value] ?? null)?->value->text;
check('no renderer choice is left on a seeded type', $stray !== SliderRenderer::NAME, (string) $stray);

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
