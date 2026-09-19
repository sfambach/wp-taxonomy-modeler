<?php declare(strict_types=1);

/**
 * Die Felder eines Knotens in eine gewünschte Reihenfolge bringen — über die Anordnung am Kind (D-698), Schritt für Schritt wie die Pfeile.
 *
 *     php scripts/dev/feldfolge.php <Knoten-Id> "Feld 1" "Feld 2" …            # nur zeigen
 *     php scripts/dev/feldfolge.php <Knoten-Id> "Feld 1" "Feld 2" … --write    # anordnen
 *
 * ⚠️ *Sein Befund an «Projekte» (D-882): «Projekt name und beschreibung sollten an den anfang … warum rendert das die Eingabemaske nicht
 * in der richtigen reihenfolge». Die Felder von «Model» (Titelbild, Bilder, Quellen, Herkunft, Herkunftshinweis) kommen als geerbte
 * vor den eigenen. Die genannten Felder kommen in dieser Reihenfolge nach vorn, alle übrigen behalten ihre Folge dahinter.*
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$argumente = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--write'));
$knotenId  = (int) array_shift($argumente);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);

$felder = static fn (): array => array_values(array_filter($editor->fieldsOf($knotenId), static fn ($r): bool => ! $r->isSetting() && ! $r->hide));
$namen  = static fn (): array => array_map(static fn ($r): string => $r->name, $felder());

echo 'vorher:  ', implode(' · ', $namen()), "\n";

foreach ($argumente as $ziel => $name) {
    for ($schritte = 0; $schritte < 100; $schritte++) {
        $jetzt = array_search($name, $namen(), true);

        if ($jetzt === false) {
            exit("Feld «{$name}» gibt es an {$knotenId} nicht\n");
        }

        if ($jetzt <= $ziel || ! $schreiben) {
            break;
        }

        $kante = $felder()[$jetzt];

        if (! $data->moveFieldAt($knotenId, $editor->fieldsOf($knotenId), $kante->id, -1)) {
            exit("«{$name}» liess sich nicht verschieben\n");
        }
    }
}

echo $schreiben ? 'nachher: ' . implode(' · ', $namen()) . "\n" : "— nur gezeigt. Mit --write anordnen.\n";
