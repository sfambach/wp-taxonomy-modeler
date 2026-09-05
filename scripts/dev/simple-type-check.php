<?php declare(strict_types=1);
/**
 * **Das Inventar der einfachen Typen — eine Zahl, an einer Stelle geprüft** ([D-484](../../docs/NewConcept/90-decision-log.md)).
 *
 *     php scripts/dev/simple-type-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer wollte die Klassen genau dafür:** *«ich hätte gerne spezialisierte Klassen,
 * weil dann auch klar ist, wie viele spezialisierte Typen wir haben.»* **Und der Grund war eine
 * Messung:** *am 2026-08-28 nannte die Aufzählung elf, gesät waren elf, und die Registratur band
 * einen Renderer an zehn — `user_ref` war der Typ, den niemand zeichnet.* **Diese Prüfung ist die
 * Zusage, dass die drei Zahlen nicht wieder auseinanderlaufen.**
 *
 * ⚠️ *Sie meldet den Renderer-Rückstand als **Befund**, nicht als Fehler: welche Renderer für einen
 * Typ da sind, ist die Sache des Renderers ([D-481](../../docs/NewConcept/90-decision-log.md),
 * [D-483](../../docs/NewConcept/90-decision-log.md)) und ausdrücklich nicht die der Typklasse. Was
 * D-484 verlangt, ist die **Sichtbarkeit** — und die ist jetzt eine Zeile.*
 *
 * @see docs/pakete/modelltabellen/tasks.md
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

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\Type\SpecialisedType;
use Taxmod\Core\Model\Type\SpecialisedTypes;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Validator\RangeValidator;
use Taxmod\Core\Validator\ShapeValidator;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

// ⚠️ *Und wo doch etwas durchschlägt, endet dieser Lauf als **Meldung** und nicht als Absturz —
// ein roter Wächter, den niemand lesen kann, ist so gut wie keiner.*
set_exception_handler(static function (\Throwable $fehler): void {
    fwrite(STDERR, "\n  FAIL abgebrochen — " . $fehler->getMessage() . "\n");
    exit(1);
});

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$types     = new SeededTypeNodes($nodes, $framework);

echo "\n== 1. Die drei Zahlen sind eine Zahl ==\n";

$klassen = SpecialisedTypes::all();
$faelle  = SimpleType::cases();
$gesaet  = 0;

// ⚠️ **Erst zählen, dann fragen — und die Frage überlebt einen Fall ohne Klasse.** *Eine Prüfung,
// die an dem Zustand stirbt, den sie melden soll, sagt nichts über ihn aus; genau das ist beim Bauen
// passiert, als `user_ref` versuchsweise aus dem Inventar genommen wurde.*
foreach ($faelle as $fall) {
    try {
        if ($types->nodeId($fall) !== null) {
            $gesaet++;
        }
    } catch (\Throwable $fehler) {
        echo '       ' . $fall->value . ': ' . $fehler->getMessage() . "\n";
    }
}

check(
    'Klassen und Aufzählungsfälle: ' . count($klassen) . ' und ' . count($faelle),
    count($klassen) === count($faelle),
    count($klassen) . ' ≠ ' . count($faelle)
);

check(
    'und gesäte Typknoten: ' . $gesaet,
    $gesaet === count($klassen),
    $gesaet . ' ≠ ' . count($klassen)
);

echo "\n== 2. Je Fall genau eine Klasse, und keine steht für zwei ==\n";

$proFall = [];

foreach ($klassen as $klasse) {
    check(
        $klasse->type()->value . ' → ' . $klasse::class,
        SpecialisedTypes::for($klasse->type())::class === $klasse::class
            && SpecialisedTypes::ofClass($klasse::class) === $klasse->type()
    );

    $proFall[$klasse->type()->value] = ($proFall[$klasse->type()->value] ?? 0) + 1;
}

check(
    'kein Fall wird von zwei Klassen beansprucht',
    array_filter($proFall, static fn (int $n): bool => $n > 1) === []
);

echo "\n== 3. Die Liste ist vollständig — keine Klasse im Ordner fehlt in ihr ==\n";

// ⚠️ *Die Liste wird von Hand geführt (siehe {@see SpecialisedTypes::CLASSES}), also muss jemand
// messen, ob sie stimmt. **Genau hier** und nicht in der Liste selbst: eine Liste, die sich aus dem
// Ordner füllt, kann nicht falsch sein und sagt darum nichts.*
$imOrdner = [];

foreach (glob(dirname(__DIR__, 2) . '/src/Core/Model/Type/*.php') ?: [] as $datei) {
    $name   = 'Taxmod\\Core\\Model\\Type\\' . basename($datei, '.php');
    $spiegel = class_exists($name) ? new ReflectionClass($name) : null;

    if ($spiegel !== null && ! $spiegel->isAbstract() && $spiegel->isSubclassOf(SpecialisedType::class)) {
        $imOrdner[] = $name;
    }
}

$fehlend = array_diff($imOrdner, SpecialisedTypes::CLASSES);

check(
    'alle ' . count($imOrdner) . ' Klassen im Ordner stehen im Inventar',
    $fehlend === [],
    implode(' · ', $fehlend)
);

echo "\n== 4. Der Knoten sagt selbst, welcher Typ er ist — keine Option mehr (AR-1, TASK-009) ==\n";

$dataTypes = $framework->rootOf(Branch::DataTypes);

/** @var array<int, \Taxmod\Core\Model\Node> $kinder */
$kinder = [];

foreach ($nodes->childrenOf($dataTypes) as $kind) {
    $kinder[$kind->id] = $kind;
}

foreach ($faelle as $fall) {
    $id   = $types->nodeId($fall);
    $node = $id === null ? null : ($kinder[$id] ?? null);

    check(
        $fall->value . ' steht in nodes.implemented_by',
        $node !== null && $node->implementedBy === SeededTypeNodes::classFor($fall),
        $node === null ? 'kein Kind von Data Types' : (string) $node->implementedBy
    );
}

foreach ($faelle as $fall) {
    check(
        $fall->value . ' hält keine Option mehr',
        get_option('taxmod_type_' . $fall->value . '_id', false) === false,
        (string) get_option('taxmod_type_' . $fall->value . '_id')
    );
}

echo "\n== 5. Rückrichtung, und ein Doppelgänger antwortet nicht (D-022) ==\n";

foreach ($faelle as $fall) {
    $id = $types->nodeId($fall);

    check($fall->value . ' zurück', $id !== null && $types->typeOf($id) === $fall);
}

echo "\n== 6. Was die Klassen tragen, liest jeder ab statt es zu wiederholen ==\n";

check(
    'der Bereichsvalidator nimmt die Typen mit Grenzen',
    (new RangeValidator())->handles() === SpecialisedTypes::withBounds(),
    implode(', ', array_map(static fn (SimpleType $t): string => $t->value, SpecialisedTypes::withBounds()))
);

check(
    'der Formvalidator nimmt die Typen mit einer versprochenen Form',
    (new ShapeValidator())->handles() === SpecialisedTypes::withAShape(),
    implode(', ', array_map(static fn (SimpleType $t): string => $t->value, SpecialisedTypes::withAShape()))
);

foreach ($klassen as $klasse) {
    check(
        $klasse->type()->value . ': Spalte, Knotenname und Lesart stehen in der Klasse',
        $klasse->column() === $klasse->type()->column()
            && $klasse->nodeName() === $klasse->type()->nodeName()
            && $klasse->humanName() === $klasse->type()->humanName()
            && $klasse->pattern() === $klasse->type()->pattern()
    );
}

echo "\n== 7. Befund, kein Fehler: welche Typen zeichnet niemand (D-481, D-483) ==\n";

$renderer = ShippedRenderers::registry();
$ohne     = [];

foreach ($faelle as $fall) {
    // ⚠️ *Der Auffang ist die Antwort für «niemand hat einen gesetzt» (`R14b`) — also ist er hier
    // die Messung und nicht `null`.*
    if ($renderer->defaultFor($fall)->name() === $renderer->defaultFor(null)->name()) {
        $ohne[] = $fall->value;
    }
}

printf("       %d von %d Typen ohne eigenen Vorgaberenderer: %s\n", count($ohne), count($faelle), implode(', ', $ohne) ?: '—');

echo "\n" . ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
