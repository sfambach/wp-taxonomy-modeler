<?php declare(strict_types=1);

/**
 * Does `2.7 kΩ` come out of the descent?
 *
 * The owner asked for exactly this: *put value + optional prefix + unit into the tree once, and then
 * a preview render, so that we see whether that works too.* It is a **boundary** check because the
 * labels, the settings and the constants all have to be really stored for the question to mean
 * anything — a core test with a double would only prove that the double agrees with me.
 *
 * ⚠️ **A preview needs no record**, which is what makes this checkable today: the descent takes
 * values keyed by relation id, so example values can be handed in directly. *Storing one is a separate
 * matter and is refused on purpose — see the last section of the output.*
 *
 * Usage: php scripts/dev/unitvalue-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/30-renderer.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

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

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$labels    = new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale());
$editor    = new ModelEditor($nodes, $relations, $framework, $log);
$data      = new DataEntry(new \Taxmod\WordPress\Persistence\WpdbRecordRepository(), $relations, $nodes, $framework, new SystemClock());
$rendering = new Rendering($nodes, $framework, ShippedRenderers::registry(), new SeededTypeNodes($nodes, $framework), $labels,
    model: new ModelValues(new WpdbRecordRepository(), new WpdbRelationRepository(), new WpdbNodeRepository(), $framework)
);

echo "\n== the type is in the tree ==\n";

// ⚠️ **Über die Id und nicht über den Namen** — *sein Wort am 2026-09-07: «checks sind ok gut das
// due prüfst ob das noch geht, köntest aber über id gehen 😉». Es ist
// [D-510](../../docs/NewConcept/90-decision-log.md), zum zweiten Mal am selben Tag: der Name ist
// eine Beschriftung, und wo der Knoten hängt, ist seine Sache.*
//
// ⚠️ **Was der Namensweg gekostet hat:** *hier stand «steht unter `Compositions`», und die Zusage
// fiel, als er den Knoten nach `Combined` hängte — **richtig** hängte, nach
// [D-677](../../docs/NewConcept/90-decision-log.md). Am Kode war nichts falsch; die Prüfung mass
// seinen Bestand statt der Sache (`INF-070`).*
//
// ⚠️ *Die Namenssuche bleibt als Notnagel für Bestände, die vor der Option gesät wurden — und
// erst, wenn auch der nichts findet, ist es ein Befund.*
$unitValue = null;
$bekannt   = \Taxmod\WordPress\Persistence\UnitScaffold::unitValueId();

if ($bekannt !== null) {
    $unitValue = $editor->find($bekannt);
}

if ($unitValue === null) {
    foreach ([Branch::Combined, Branch::Compositions] as $ast) {
        foreach ($editor->childrenOf($framework->rootOf($ast)->id) as $child) {
            if ($child->name === 'Einheitenwert' && $unitValue === null) {
                $unitValue = $child;
            }
        }
    }
}

check('der gesäte Einheitenwert ist auffindbar', $unitValue !== null, $unitValue === null ? '' : "#{$unitValue->id} «{$unitValue->name}»");

if ($unitValue === null) {
    echo "\nNothing more can be checked. Run the unit scaffold first.\n";

    exit(1);
}

// ⚠️ **Was die Wurzel erklärt, gehört keinem Knoten weiter unten** — und diese Zeile gibt es, weil
// diese Prüfung am 2026-08-29 rot wurde, ohne dass jemand sie oder ihr Versprechen angefasst hat.
// *Der Eigentümer hat an den Wurzelknoten `renderer` und `validator` gehängt
// ([D-514](../../docs/NewConcept/90-decision-log.md)). `ModelEditor::fieldsOf()` sammelt
// `[...ancestorIds(), id]` — **also erben alle 124 Knoten sie**, und jede Zusicherung der Form
// «dieser Knoten hat genau diese Felder» wurde falsch.*
//
// ⚠️ **Abgezogen, nicht abgeschwächt.** *«Nicht mehr und nicht weniger» bleibt eine echte Zusage:
// eigene Felder plus genau das, was die Wurzel erklärt. Ein Feld, das von irgendwo sonst kommt,
// lässt die Prüfung weiter fallen. **Was hier fehlt, ist nicht Strenge, sondern D-508s Angabe «wo
// liegt der Wert» — Datensatz oder Modell.** Solange die fehlt, liegen Autoren- und Benutzerdaten in
// **einer** Liste, und das ist die Lücke, die der Eigentümer selbst benannt hat: «die Felder, die wir
// hier definieren, definieren Daten des Modells und nicht Daten, die durch den Benutzer eingegeben
// werden».*
$vonDerWurzel = [];

foreach ($editor->fieldsOf($framework->root()->id) as $relation) {
    $vonDerWurzel[$relation->name] = true;
}

$members = [];

foreach ($editor->fieldsOf($unitValue->id) as $relation) {
    if (isset($vonDerWurzel[$relation->name])) {
        continue;
    }

    $members[$relation->name] = $relation;
}

echo '  (von der Wurzel geerbt und darum nicht gezählt: '
    . (implode(', ', array_keys($vonDerWurzel)) ?: 'nichts') . ")\n";

// D-039: a unit value is value + optional prefix + unit. Three members, no more and no fewer.
check('it has exactly the three members of D-039', count($members) === 3, implode(', ', array_keys($members)));
check('  · wert', isset($members['wert']));
check('  · prefix', isset($members['prefix']));
check('  · einheit', isset($members['einheit']));

echo "\n== the prefix is optional, because D-039 says so ==\n";

$resolved = $rendering->settingsForUseSites(array_values($members));

$many = ($resolved[$members['prefix']->id][\Taxmod\Core\Model\EdgeColumn::MULTIPLICITY] ?? null)?->value->text;

check('prefix is 0..1 and not mandatory', $many === Multiplicity::ZeroToOne->value, (string) $many);

echo "\n== the constants carry their symbols as labels ==\n";

// ⚠️ *Über die Notiz des Gerüsts, nicht über den Namen (D-709, TASK-049): das Gerüst merkt sich jede Id.*
$notiert = static fn (string $name) => \Taxmod\WordPress\Persistence\UnitScaffold::nodeId($name) === null
    ? null
    : $editor->find((int) \Taxmod\WordPress\Persistence\UnitScaffold::nodeId($name));
$prefixes = $notiert('Prefixes');
$base     = $notiert('Base units');

check('Prefixes and Base units are there — über ihre notierte Id', $prefixes !== null && $base !== null);

$kilo = $notiert('kilo');
$ohm  = $notiert('Ohm');

check('kilo and Ohm are there — über ihre notierte Id', $kilo !== null && $ohm !== null);

if ($kilo === null || $ohm === null) {
    echo "\nStopping: the units are missing.\n";

    exit(1);
}

$symbols = $labels->forNodes([$kilo, $ohm], SeededRole::Symbol);

check('kilo has the symbol k', ($symbols[$kilo->id] ?? '') === 'k', $symbols[$kilo->id] ?? '—');
check('Ohm has the symbol Ω', ($symbols[$ohm->id] ?? '') === 'Ω', $symbols[$ohm->id] ?? '—');

// ⚠️ The counter-check that gives the one above its meaning: the **form** role still answers with
// the node's own name, so the two roles are genuinely different rows and not one row read twice.
$plain = $labels->forNodes([$kilo, $ohm], SeededRole::Form);

check('the form role still says kilo', ($plain[$kilo->id] ?? '') === 'kilo', $plain[$kilo->id] ?? '—');

echo "\n== the preview: 2.7 kΩ ==\n";

$fields = $rendering->fieldsFor(
    [$members['wert'], $members['prefix'], $members['einheit']],
    [
        $members['wert']->id    => TypedValue::ofDecimal('2.7'),
        $members['prefix']->id  => TypedValue::ofReference($kilo->id),
        $members['einheit']->id => TypedValue::ofReference($ohm->id),
    ],
    Purpose::Display
);

check('all three members were drawn', count($fields) === 3, (string) count($fields));

$drawn = [];

foreach ($fields as $field) {
    $drawn[$field->relation->name] = $field->result->markup;
}

check(
    'the value reads 2.7',
    isset($drawn['wert']) && str_contains($drawn['wert'], '2.7'),
    $drawn['wert'] ?? '—'
);

// ⚠️ **This is the whole point of the exercise.** Before the label role became a setting the descent
// resolved every reference with `SeededRole::Form`, so this cell read *kilo* and the preview of
// `2k7` came out as `2.7 kilo Ohm`.
// ⚠️ **Bis zum 2026-09-04 stand hier «k» und «Ω», und jetzt steht «kilo» und «Ohm» — das ist der
// Verlust aus [D-579](../../docs/NewConcept/90-decision-log.md), nicht ein Fehler.** *Die drei
// `label_role`-Zeilen lagen in der gestrichenen `settings`-Tabelle; der Eigentuemer hat zwischen
// Umzug und Neueingabe gewaehlt («B») und die Folge vorher benannt: «Kiloohm» statt «kΩ», bis
// `label_role` seinen neuen Ort hat (`OQ-134`). **Die Zusage wird mitgezogen und nicht abgeschaltet**
// (`PR-9`) — sie misst den Zustand, der gilt, und wird wieder rot, wenn `OQ-134` gebaut ist und die
// Rolle trotzdem nicht ankommt.*
check(
    'der Praefix liest sich als «kilo» — die Rolle «symbol» ist mit D-579 verloren',
    isset($drawn['prefix']) && str_contains($drawn['prefix'], 'kilo'),
    strip_tags($drawn['prefix'] ?? '—')
);

check(
    'die Einheit liest sich als «Ohm» — dieselbe Ursache',
    isset($drawn['einheit']) && str_contains($drawn['einheit'], 'Ohm'),
    strip_tags($drawn['einheit'] ?? '—')
);

echo "\n  as rendered: " . trim(preg_replace('/\s+/', ' ', strip_tags(implode(' ', $drawn)))) . "\n";

echo "\n== and the prefix may be left out, which is what 0..1 means ==\n";

$withoutPrefix = $rendering->fieldsFor(
    [$members['wert'], $members['prefix'], $members['einheit']],
    [
        $members['wert']->id    => TypedValue::ofDecimal('12'),
        $members['einheit']->id => TypedValue::ofReference($ohm->id),
    ],
    Purpose::Display
);

$second = [];

foreach ($withoutPrefix as $field) {
    $second[$field->relation->name] = strip_tags($field->result->markup);
}

// ⚠️ *Nothing is nothing* (D-232): an unanswered reference is not an empty string and not a zero.
check(
    'the missing prefix draws as nothing rather than as a dangling reference',
    ! str_contains($second['prefix'] ?? '', 'k'),
    $second['prefix'] ?? '—'
);

echo "\n  as rendered: " . trim(preg_replace('/\s+/', ' ', implode(' ', $second))) . "\n";


echo "\n== a composed value is stored, and read back ==\n";

// ⚠️ **The whole reason the unit value exists**: `2.7 kΩ` has to survive a save.
//
// ⚠️ *Reused, like everything else this check touches. Purging does not exist (D-123 second stage), so
// a check that makes a record on every run fills the screen with its own leftovers — which is exactly
// what the owner opened and found.*
$held    = $data->recordsOf($unitValue->id);
$holder  = $held === [] ? $data->create($unitValue->id) : $held[0];

check('a record can be started against a composed type', $holder->id > 0);

// ⚠️ **A refusal from the core is a finding, not a crash.** This died with an uncaught
// `NotYetStorable` on 2026-08-26 — *«einheit» is not persistent* — because the **owner** had set
// `persistent = 0` on `Base units` while experimenting, and `Base units` is the *target* of that
// attribute, so the chain carried it down to the relation. The core was right and the check was brittle.
//
// ⚠️ *That is worth knowing rather than hiding: a setting put on a **constant** reaches every
// attribute that points at it. Setting `persistent = 0` there means «nothing typed as a base unit is
// stored», which is a coherent thing to say and a surprising thing to have said by accident.*
foreach ([
    ['wert', TypedValue::ofDecimal('2.7')],
    ['prefix', TypedValue::ofReference($kilo->id)],
    ['einheit', TypedValue::ofReference($ohm->id)],
] as [$member, $value]) {
    try {
        $data->put($holder->id, $members[$member]->id, $value);
        check("«{$member}» is written", true);
    } catch (Taxmod\Core\Exception\NotYetStorable $refused) {
        // ⚠️ **A stated refusal passes; a silent one would not.** The core declining with a reason is
        // it working, and the reason is printed so a person can see *which* setting did it. *Asserting
        // «the write succeeded» would turn a deliberate model choice into a red run and teach whoever
        // sees it to loosen the check — which is how a bug gets written down as a rule.*
        check("«{$member}» is written, or refused with a reason", true, 'refused: ' . $refused->getMessage());
    }
}

$held = [];

foreach ($data->valuesOf($holder->id) as $value) {
    $held[$value->relationId] = $value->value;
}

check('all three members were kept', count($held) === 3, (string) count($held));

// ⚠️ **The padding comes off on read** (D-394). `decimal(30,10)` returns `2.7000000000`, and it was
// reaching the screen: `2.7 kΩ` read back as `2.7000000000 k Ω`. *Found by storing a real value —
// no test with a double would have shown it, because no double pads.*
check(
    'a decimal reads back as it was written and not as ten zeros',
    ($held[$members['wert']->id] ?? null)?->decimal === '2.7',
    ($held[$members['wert']->id] ?? null)?->decimal ?? '—'
);

$readBack = [];

foreach ($rendering->fieldsFor(array_values($members), $held, Purpose::Display) as $field) {
    $readBack[$field->relation->name] = trim(strip_tags($field->result->markup));
}

check('und es zeichnet sich als 2.7 kilo Ohm — bis `OQ-134` steht', ($readBack['wert'] ?? '') === '2.7'
    && ($readBack['prefix'] ?? '') === 'kilo'
    && ($readBack['einheit'] ?? '') === 'Ohm',
    implode(' ', $readBack));

echo "\n== a holder points at a part with a record of its own ==\n";

// ⚠️ **D-232, and it supersedes D-133**: a target in `Compositions` gets **its own records**. So a
// model attribute pointing at `Einheitenwert` cannot hold the value inline — it holds a reference to
// a record that belongs to it.
// ⚠️ **Idempotent, and the first attempt at that was not enough.** The check reused the scratch **node**
// but still added an attribute, a part and a record on **every run** — the owner opened the screen and
// found `__uv Resistor` carrying three identical `resistance` attributes and nine records. *Reusing one
// thing and creating three others is not idempotence; it is the same bug with a smaller footprint.*
//
// ⚠️ *Purging does not exist yet (D-123's second stage), so a check that cannot clean up after itself
// must not make anything it would have to clean up. **Everything below is found first and made only if
// it is absent.***
$thing = null;

foreach ($editor->childrenOf($framework->rootOf(Branch::Model)->id) as $candidate) {
    if ($candidate->name === '__uv Resistor') {
        $thing = $candidate;
    }
}

$thing ??= $editor->createNode('__uv Resistor', $framework->rootOf(Branch::Model)->id);

$has = null;

foreach ($editor->fieldsOf($thing->id) as $relation) {
    if ($relation->name === 'resistance') {
        $has = $relation;
    }
}

$has ??= $editor->addField($thing->id, $unitValue->id, 'resistance');

$existing = $data->recordsOf($thing->id);
$owner    = $existing === [] ? $data->create($thing->id) : $existing[0];

// ⚠️ *A reference whose record is gone counts as absent.* The first run after I cleared the litter by
// hand found a dangling `value_ref` and handed `null` on to `put()`. **A check that trusts its own
// leftovers is a check that fails once and then hides it by healing itself.**
$parts = $data->partsOf($owner->id);
$part  = $parts === [] ? null : $data->find((int) reset($parts));
$part ??= $data->createPart($owner->id, $has->id);

check('a part is a record of the composed type', $part->nodeId === $unitValue->id);
check('and the holder points at it', in_array($part->id, $data->partsOf($owner->id), true));

// ⚠️ **Asking again makes another part**, which is the point of it having an identity: two positions
// on an order are two positions. *Checked without keeping the second one — the act is what is being
// checked, not the state, and the state would pile up.*
//
// ⚠️ **Zwei Teile an einer Kante sind zwei Zeilen, nicht zwei Pfade** ([D-530](../../docs/NewConcept/90-decision-log.md),
// Fassung 39, TASK-002). *Hier stand `<Kante>.1` als zweite Adresse — die einzige Stelle im ganzen
// Bestand, die den Pfad je mehrteilig benutzt hat, und sie tat es nur innerhalb dieses Laufes.
// **Gezählt wird deshalb an den Wertzeilen des Halters** und nicht mehr an einer Liste, die je Kante
// einen Eintrag hat.*
$before = count($data->valuesOf($owner->id));
$second = $data->createPart($owner->id, $has->id);

check('asking again makes another one', $second->id !== $part->id);
check('and both are reachable', count($data->valuesOf($owner->id)) === $before + 1);

// ⚠️ **Only the second one goes back, and the first is left alone.** My first attempt cleared the
// holder by **relation** — which removes *both* rows — and then tried to write the reference back
// with `put()`, which refuses a composed target on purpose. *{@see \Taxmod\Core\Service\DataEntry::removeRecord()}
// nimmt den Teil **und genau die Zeile, die ihn hält**, über deren Id — der Weg, den es dafür gibt.*
$data->removeRecord($second->id);

$data->put($part->id, $members['wert']->id, TypedValue::ofDecimal('4.7'));
check('a part holds its own values', count($data->valuesOf($part->id)) === 1);
// ⚠️ *One value on the holder: the reference to its part. Nothing of the part's own leaks upward,
// which is what «a record of its own» means.*
check(
    'and the holder keeps only the reference',
    count($data->valuesOf($owner->id)) === 1,
    (string) count($data->valuesOf($owner->id))
);

// ⚠️ **Refused where it is not a composition**, because anywhere else the value belongs *in* the
// record and a part would be a second home for it.
try {
    $data->createPart($holder->id, $members['wert']->id);
    check('a part is refused where the branch is not Compositions', false, 'it was allowed');
} catch (Throwable $e) {
    check('a part is refused where the branch is not Compositions', true);
}

echo "\n== clearing up ==\n";

// ⚠️ **Hier stand «bleibt absichtlich stehen, es geht, wenn Endgültig-Löschen existiert» — und das war
// die falsche Entschuldigung.** *Der Eigentümer hat den Knoten in seinem Modell gefunden: «`__uv
// Resistor` ist übrigens ein Überbleibsel von dir, ich brauche den nicht.» **Für den eigenen Müll
// braucht eine Prüfung kein Endgültig-Löschen im Kern** — sie kennt ihre Ids und darf sie wegräumen,
// genau wie `path-check.php` und `multiplicity-check.php` es tun.*
//
// ⚠️ **Und es sind mehr Leichen als der Knoten.** *Gemessen am 2026-08-31: zwei Datensätze (#2412 als
// Benutzersatz, #2924 als `default`), und daran **zwei Teile** — einer an `Einheitenwert`, einer an
// `DisplayOption`. Der Teil an `Einheitenwert` stand in dessen Datensatz-Block und sah dort aus wie
// dessen eigener Satz. **Ein Teil ohne Halter ist eine Waise, die niemand als Waise erkennt.***
//
// ⚠️ *Nur die eigenen Ids und **nie** `clearTrash()`: das räumt auch weg, was ein Mensch dort geparkt
// hat.*
check('der Schmierknoten ist gefunden', $thing->name === '__uv Resistor');

$wpdb    = $GLOBALS['wpdb'];
$tabelle = static fn (string $name): string => \Taxmod\WordPress\Persistence\Schema::table($name);

/** Ein Datensatz und alles, was nur an ihm hängt — Teile zuerst, damit keine Waise bleibt. */
$satzWeg = static function (int $satzId) use ($wpdb, $tabelle, &$satzWeg): int {
    $weg = 0;

    $verweise = $wpdb->get_col($wpdb->prepare(
        'SELECT value_ref FROM ' . $tabelle('relation_records') . ' WHERE node_record_id = %d AND value_ref IS NOT NULL',
        $satzId
    ));

    // ⚠️ *`null` heisst «Abfrage kaputt» und nicht «keine Verweise» — und hier wird danach gelöscht.*
    if ($verweise === null) {
        fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
        exit(2);
    }

    foreach ($verweise as $ref) {
        $istSatz = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $tabelle('node_records') . ' WHERE id = %d',
            (int) $ref
        ));

        if ($istSatz > 0) {
            $weg += $satzWeg((int) $ref);
        }
    }

    // ⚠️ **Aufheben, dann löschen** ([D-535](../../docs/NewConcept/90-decision-log.md)). *Auf sein
    // Wort: «löschen tun wir ja eh nicht, wir schieben es in die Schattentabelle.» Auch der eigene
    // Müll einer Prüfung geht diesen Weg — sonst gibt es zwei Arten, etwas wegzunehmen, und die
    // zweite ist die, die niemand nachlesen kann.*
    \Taxmod\WordPress\Persistence\Shadow::keep('relation_records', 'node_record_id = %d', [$satzId], true);
    \Taxmod\WordPress\Persistence\Shadow::keep('node_records', 'id = %d', [$satzId], true);

    $wpdb->query($wpdb->prepare('DELETE FROM ' . $tabelle('relation_records') . ' WHERE node_record_id = %d', $satzId));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . $tabelle('node_records') . ' WHERE id = %d', $satzId));

    return $weg + 1;
};

$saetze = $wpdb->get_col($wpdb->prepare(
    'SELECT id FROM ' . $tabelle('node_records') . ' WHERE node_id = %d',
    $thing->id
));

if ($saetze === null) {
    fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
    exit(2);
}

$weggeraeumt = 0;

foreach ($saetze as $satzId) {
    $weggeraeumt += $satzWeg((int) $satzId);
}

\Taxmod\WordPress\Persistence\Shadow::keep('relations', 'from_node_id = %d OR to_node_id = %d', [$thing->id, $thing->id], true);
\Taxmod\WordPress\Persistence\Shadow::keep('nodes', 'id = %d', [$thing->id], true);

$wpdb->query($wpdb->prepare(
    'DELETE FROM ' . $tabelle('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
    $thing->id,
    $thing->id
));
$wpdb->query($wpdb->prepare('DELETE FROM ' . $tabelle('nodes') . ' WHERE id = %d', $thing->id));

echo "  --   {$weggeraeumt} Datensaetze samt Teilen weggeraeumt\n";

check(
    'und er raeumt sich selbst weg',
    (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $tabelle('nodes') . ' WHERE id = %d', $thing->id)) === 0
);

// ⚠️ *Der Gegenfall: keine Waise zurück. Ein Teil trägt die Id des **Zielknotens**, also findet man ihn
// nicht über den Schmierknoten — sondern daran, dass niemand mehr auf ihn zeigt.*
$waisen = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . $tabelle('node_records') . ' r
     WHERE NOT EXISTS (SELECT 1 FROM ' . $tabelle('nodes') . ' n WHERE n.id = r.node_id)'
);

check('kein Datensatz ohne Knoten', $waisen === 0, (string) $waisen);
echo "\n== what is honestly not there yet ==\n";

// ⚠️ **What was missing here this morning is built** (D-394): a composed value gets its own
// record, and `2.7 kΩ` survives a save. *The note that stood here cited D-133 against the code and
// was wrong twice over — D-232 supersedes it, and the own record turned out to be the real gap.*
// ⚠️ **Diese Notiz sagte «Deleting a record does not exist at all» und war überholt.** *Der Eigentümer
// hat den Weg genannt: «löschen tun wir ja eh nicht, wir schieben es in die Schattentabelle» — und
// `Shadow::keep(…, deleted: true)` steht seit [D-535](../../docs/NewConcept/90-decision-log.md), samt
// `forgetNodes()`, das ihn benutzt. **Eine Prüfung, die «gibt es nicht» ausgibt, obwohl es das gibt, ist
// eine Falschmeldung im Bericht** — und dieser Bericht wird gelesen.*
echo "  Was fehlt, ist nicht der Weg, sondern die Entscheidung: darf ein Teil einzeln weg?\n";
echo "  Ein Teil traegt die Id des Zielknotens und haengt am Satz eines anderen — OQ-143.\n";

echo "\n" . ($failed === 0 ? "all {$passed} checks passed\n" : "{$passed} passed, {$failed} FAILED\n");

exit($failed === 0 ? 0 : 1);
