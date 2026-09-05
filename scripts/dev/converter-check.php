<?php declare(strict_types=1);

/** Row 7: the converter runs both ways, on the real database. */

define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\{Purpose, ShippedRenderers};
use Taxmod\Core\Service\{Labels, ModelEditor, Rendering, Settings};
use Taxmod\WordPress\Persistence\{SeededFrameworkNodes, SeededTypeNodes, WpdbChangelog, WpdbLabelRepository, WpdbNodeRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

$nodes = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log   = new WpdbChangelog(new SystemClock());
$fw    = new SeededFrameworkNodes($nodes, $relations, $log);

$editor    = new ModelEditor($nodes, $relations, $fw, $log);

// ⚠️ *Frisch gebaut nach jeder gesetzten Angabe: {@see \Taxmod\Core\Service\ModelValues} merkt sich
// die Saetze eines Knotens beim ersten Lesen. Ein Waechter, der erst zeichnet, dann setzt und wieder
// zeichnet, saehe sonst den alten Stand — **und genau so ist dieser hier gebaut.***
$zeichner = static fn (): Rendering => new Rendering(
    $nodes,
    $fw,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $fw),
    new Labels(new WpdbLabelRepository(), $fw),
    ShippedConverters::registry(),
    model: new ModelValues(new WpdbRecordRepository(), new WpdbRelationRepository(), new WpdbNodeRepository(), $fw)
);

$rendering = $zeichner();

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

// A scratch model, so nothing the owner is editing is touched (row 23).
$holder  = $editor->createNode('__cv Messwert', $fw->rootOf(Branch::Model)->id);

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

// ⚠️ **Über die notierte Id** ([D-510](../../docs/NewConcept/90-decision-log.md)). *Hier stand
// `WHERE name = 'Integer' LIMIT 1` — ohne Zweig, ohne Reihenfolge, über den ganzen Baum. Ein
// beliebiger Knoten namens `Integer` irgendwo im Modell hätte geantwortet, und
// [D-022](../../docs/NewConcept/90-decision-log.md) sagt, dass es solche geben darf. **Gemessen:
// genau das ist am 2026-08-29 passiert**, als sechs Leichen dieses Namens unter `Data Types`
// lagen.*
$integerId = (new SeededTypeNodes($nodes, $fw))->nodeId(SimpleType::Int);

if ($integerId === null) {
    fwrite(STDERR, "Der Datentyp «int» ist nicht gesät — erst scripts/dev/scaffold-check.php laufen lassen.\n");
    exit(2);
}

$relation = $editor->addField($holder->id, $integerId, 'zaehler');

// ⚠️ **Eine Angabe an einer Verwendungsstelle setzen — jetzt ueber den Kern.**
// *Hier stand `$settings->put($settings->chainForUseSite($relation), …)`, und danach stand hier ein
// **Behelf**: der Waechter legte die Zeile selbst ueber die Speicher an, weil
// {@see \Taxmod\Core\Service\DataEntry::putSettingAt()} am **Knoten** schreibt und es fuer eine
// Verwendungsstelle keinen Schreiber gab ([`INF-011`](../../docs/pakete/modelltabellen/inbox.md)).
// **Den gibt es jetzt** — {@see \Taxmod\Core\Service\DataEntry::putSettingAtUseSite()} —, und ein
// Waechter, der an der Pruefung vorbei schreibt, misst seinen eigenen Behelf statt den Kode.*
//
// ⚠️ *Was hier bleibt, ist das Anlegen der Einstellungskante: der Kern **erfindet** keine Kante
// (`CD-5`), und die Spielwiese dieses Laufs bringt sie nicht mit.*
$einstellung = static function (\Taxmod\Core\Model\Relation $stelle, string $key, \Taxmod\Core\Model\TypedValue $wert) use ($nodes, $relations, $fw, $zeichner, &$rendering): void {
    $traeger = $nodes->byId($stelle->fromNodeId);
    $data    = new \Taxmod\Core\Service\DataEntry(
        new WpdbRecordRepository(),
        $relations,
        $nodes,
        $fw,
        new \Taxmod\WordPress\SystemClock()
    );

    $kante = $data->settingRelationAtUseSite($stelle, $key);

    if ($kante === null) {
        $kante = $relations->add(\Taxmod\Core\Model\Relation::attribute(
            0,
            $traeger->id,
            $fw->rootOf(Branch::Constants)->id,
            \Taxmod\Core\Model\RelationKind::Setting,
            $key,
            $relations->nextFieldPositionUnder($traeger->id)
        ));
    }

    $data->putSettingAtUseSite($stelle->id, $kante->id, $wert);

    $rendering = $zeichner();
};

echo "== der Schluessel war ein totes Steuerelement, jetzt nicht mehr ==\n";

// ⚠️ **Am Attribut gemessen, nicht am Knoten.** Ein Knoten unter `Model` hat keinen einfachen Typ,
// also auch keine passenden Konverter — die Kante bekommt ihren Typ vom Ziel (`Integer`), und dort
// ist die Frage «welche Abbildung darf dieser Wert bekommen» ueberhaupt gestellt.
$drawn = $rendering->settingsFor(
    $relation,
    $rendering->settingsForUseSites([$relation])[$relation->id] ?? [],
    Purpose::Edit
);

// ⚠️ *`settingsFor()` gibt eine **Liste** zurueck und keine Zuordnung nach Schluessel — die Zeile
// traegt ihren Schluessel selbst.*
$row = null;

foreach ($drawn as $one) {
    if ($one->key === SettingKey::Converter->value) {
        $row = $one;
    }
}

if ($row === null) {
    $say(false, 'die converter-Zeile wurde ueberhaupt gezeichnet');
} else {
    $say(! str_contains($row->result->markup, 'disabled'), 'das Steuerelement ist lebendig');

    // ⚠️ **Alle vier, weil [R34](../../docs/NewConcept/30-renderer.md) alle vier nennt** — *«show a
    // number as binary, hexadecimal, octal or in Roman numerals»*
    // ([D-523](../../docs/NewConcept/90-decision-log.md)).
    foreach (['binary', 'hexadecimal', 'octal', 'roman'] as $angeboten) {
        $say(str_contains($row->result->markup, $angeboten), "und bietet «{$angeboten}» an");
    }
}

echo "\n== ohne Konverter steht der Wert wie gespeichert ==\n";

$before = $rendering->fieldsFor([$relation], [$relation->id => TypedValue::ofInt(12)], Purpose::Display)[0];

$say(str_contains($before->result->markup, '12'), 'die 12 steht als 12 da');

echo "\n== mit roman wird sie XII, ohne den Renderer zu wechseln ==\n";

$einstellung($relation, SettingKey::Converter->value, TypedValue::ofText('roman'));

$after = $rendering->fieldsFor([$relation], [$relation->id => TypedValue::ofInt(12)], Purpose::Display)[0];

$say(str_contains($after->result->markup, 'XII'), 'XII steht auf dem Schirm');
$say($after->rendererName === $before->rendererName, 'derselbe Renderer wie vorher — die Abbildung wechselte, nicht die Form');

echo "\n== und XII kommt als 12 zurueck ==\n";

$read = $rendering->valuesFrom([$relation], [$relation->id => 'XII']);

$say(($read[$relation->id]->int ?? null) === 12, 'die Eingaberichtung liest XII als 12');

$read = $rendering->valuesFrom([$relation], [$relation->id => 'xii']);

$say(($read[$relation->id]->int ?? null) === 12, 'auch klein geschrieben');

echo "\n== hexadecimal genauso ==\n";

$einstellung($relation, SettingKey::Converter->value, TypedValue::ofText('hexadecimal'));

$hex = $rendering->fieldsFor([$relation], [$relation->id => TypedValue::ofInt(255)], Purpose::Display)[0];

$say(str_contains($hex->result->markup, 'FF'), '255 steht als FF da');

$read = $rendering->valuesFrom([$relation], [$relation->id => 'FF']);

$say(($read[$relation->id]->int ?? null) === 255, 'und FF kommt als 255 zurueck');

echo "\n== binary und octal auch, in beide Richtungen ==\n";

// ⚠️ *Am selben Weg gemessen wie `roman` und `hexadecimal` — durch die Einstellung, die Kette und die
// Zeichenkette auf dem Schirm, nicht durch einen direkten Aufruf des Konverters. **Der Kern prüft die
// Abbildung; hier steht die Frage, ob sie über Einstellung und Auflösung überhaupt ankommt.***
foreach ([['binary', 12, '1100'], ['octal', 493, '755']] as [$konverter, $zahl, $zeichen]) {
    $einstellung($relation, SettingKey::Converter->value, TypedValue::ofText($konverter));

    $gezeichnet = $rendering->fieldsFor([$relation], [$relation->id => TypedValue::ofInt($zahl)], Purpose::Display)[0];

    $say(str_contains($gezeichnet->result->markup, $zeichen), "{$konverter}: {$zahl} steht als {$zeichen} da");

    $zurueck = $rendering->valuesFrom([$relation], [$relation->id => $zeichen]);

    $say(($zurueck[$relation->id]->int ?? null) === $zahl, "und {$zeichen} kommt als {$zahl} zurueck");
}

echo "\n== eine Ziffer, die es in dieser Basis nicht gibt, wird verweigert ==\n";

// ⚠️ *`bindec('2')` ist `0` und `octdec('9')` ist `0` — dieselbe stille Null, die
// [D-071](../../docs/NewConcept/90-decision-log.md) verbietet.*
$einstellung($relation, SettingKey::Converter->value, TypedValue::ofText('binary'));

try {
    $rendering->valuesFrom([$relation], [$relation->id => '2']);
    $say(false, 'binary lehnt die Ziffer 2 ab');
} catch (\Taxmod\Core\Exception\NotAValueOfThatType) {
    $say(true, 'binary lehnt die Ziffer 2 ab');
}

echo "\n== was nicht lesbar ist, wird verweigert und nicht als 0 gespeichert ==\n";

$einstellung($relation, SettingKey::Converter->value, TypedValue::ofText('hexadecimal'));

try {
    $rendering->valuesFrom([$relation], [$relation->id => 'zz']);
    $say(false, 'zz wurde abgelehnt');
} catch (\Taxmod\Core\Exception\NotAValueOfThatType) {
    $say(true, 'zz wurde abgelehnt');
}

echo "\n== ein Konvertername, den es nicht gibt, nimmt kein Formular mit runter ==\n";

$einstellung($relation, SettingKey::Converter->value, TypedValue::ofText('gibt-es-nicht'));

$stale = $rendering->fieldsFor([$relation], [$relation->id => TypedValue::ofInt(12)], Purpose::Display)[0];

$say(str_contains($stale->result->markup, '12'), 'der Wert steht gespeichert da');

// aufraeumen
$in  = (string) $holder->id;
$all = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_node_id = {$in} OR to_node_id = {$in}"));
$own = $all === [] ? $in : $in . ',' . implode(',', $all);

(new WpdbRecordRepository())->forgetNodes([$holder->id]);
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$own})");

if ($all) {
    $wpdb->query('DELETE FROM ' . $p . 'relations WHERE id IN (' . implode(',', $all) . ')');
}

$wpdb->query("DELETE FROM {$p}nodes WHERE id = {$in}");

$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE name LIKE '__cv %'");

echo "\n";
$say($left === 0, 'die Spielwiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
