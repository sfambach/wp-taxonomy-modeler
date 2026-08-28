<?php declare(strict_types=1);

/**
 * Abdruck aller Renderer-Ausgaben, vor und nach dem Umbau.
 *
 * Der Umbau auf createHtmlTag() ist ein reiner Umbau: dieselben Zeichen muessen herauskommen.
 * Ein Test, der nur "es enthaelt <input" prueft, wuerde ein verlorenes Attribut nicht sehen.
 */

require 'vendor/autoload.php';

use Taxmod\Core\Model\{Node, SettingKey, SimpleType, TypedValue};
use Taxmod\Core\Renderer\{Level, Purpose, RenderContext, ShippedRenderers, Surroundings};

$node = Node::create(1, 'Probe', null);

/** Werte, die etwas ausloesen: leer, harmlos, mit Sonderzeichen, Zahl, Datum, Farbe, Verweis. */
$werte = [
    'nichts'       => TypedValue::nothing(),
    'text'         => TypedValue::ofText('4700'),
    'boese'        => TypedValue::ofText('4<7 & "gross" \'einfach\''),
    'zahl'         => TypedValue::ofInt(42),
    'dezimal'      => TypedValue::ofDecimal('2.50'),
    'wahr'         => TypedValue::ofBool(true),
    'falsch'       => TypedValue::ofBool(false),
    'datum'        => TypedValue::ofDate('2026-08-28 14:30:00'),
    'farbe'        => TypedValue::ofText('#ff8800'),
    'mail'         => TypedValue::ofText('a@b.de'),
    'verweis'      => TypedValue::ofReference(4711),
];

$settings = [
    'ohne' => [],
];

$abdruck = [];
$klassen = [];

foreach (glob('src/Core/Renderer/*Renderer.php') as $datei) {
    $kurz  = basename($datei, '.php');
    $klasse = 'Taxmod\\Core\\Renderer\\' . $kurz;

    if (! class_exists($klasse)) {
        continue;
    }

    $spiegel = new ReflectionClass($klasse);

    if ($spiegel->isAbstract() || ! $spiegel->isSubclassOf('Taxmod\\Core\\Renderer\\TypedFieldRenderer')) {
        continue;
    }

    $klassen[$kurz] = $spiegel->newInstance();
}

ksort($klassen);

foreach ($klassen as $kurz => $renderer) {
    foreach ($werte as $wortname => $wert) {
        foreach ([Purpose::Display, Purpose::Edit] as $zweck) {
            foreach ([null, SimpleType::Int, SimpleType::Decimal, SimpleType::Text] as $typ) {
                $ctx = new RenderContext(
                    purpose: $zweck,
                    value: $wert,
                    locale: 'de_DE',
                    level: Level::Admin,
                    fieldName: 'v[12]',
                    type: $typ,
                    surroundings: new Surroundings(refersTo: 'Ohm'),
                );

                $schluessel = sprintf('%s|%s|%s|%s', $kurz, $wortname, $zweck->value, $typ?->value ?? 'kein-typ');

                try {
                    $abdruck[$schluessel] = $renderer->render($node, $ctx)->markup;
                } catch (Throwable $e) {
                    $abdruck[$schluessel] = 'AUSNAHME: ' . $e::class;
                }
            }
        }
    }
}

$ziel = $argv[1] ?? 'abdruck.json';

file_put_contents($ziel, json_encode($abdruck, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

printf("%d Renderer, %d Ausgaben abgedruckt -> %s\n", count($klassen), count($abdruck), $ziel);
printf("Renderer: %s\n", implode(', ', array_keys($klassen)));
