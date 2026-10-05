<?php declare(strict_types=1);

/**
 * Die Teildatensätze der Renderer-Einstellung werden aufgelöst — der Satz spiegelt die Kante.
 *
 * ⚠️ **Sein Wort, und es ist die Diagnose des ganzen Fehlerbündels von heute:** *«naja der fehler
 * kommt daher das du das daten modell nicht richtig benutzt knot -> relation typ setting -> type ,
 * knoten record -> relation_record parallel zum konstrukt des knotens.»*
 * ([D-684](../../docs/NewConcept/90-decision-log.md))
 *
 * ⚠️ **Was hier steht und was daraus wird:**
 *
 * ```text
 * vorher   node_record(Parts List)
 *            └─ relation_record  renderer -> SATZ 10446   (ein Teil, an Knoten «form»)
 *                                              └─ relation_record  with_label = 1
 *
 * nachher  node_record(Parts List)
 *            ├─ relation_record  renderer   -> KNOTEN «form»
 *            └─ relation_record  with_label = 1
 * ```
 *
 * ⚠️ **Warum das die drei Fehler zugleich schliesst:** *ein Rendererwechsel ist danach eine
 * geänderte Wertzeile und kein neuer Satz — **also keine Waisen** (`INF-071`), **kein
 * wiederkehrender leerer `default`** (`INF-073`) und **keine Satznummer im Formular**, die derselbe
 * Aufruf ersetzt (`INF-072`).*
 *
 * ⚠️ **Gemessen vor dem Bau:** *alle 14 Zeilen mit `value_ref_kind = 'record'` sind
 * `renderer`-Kanten. **Kein zusammengesetzter Datenwert hängt daran** — die Wanderung fasst also
 * nichts an, was [D-541](../../docs/NewConcept/90-decision-log.md) als eigenen Teil braucht.*
 *
 * ⚠️ *Sammelt der Besitzer eine Wertzeile, die er an derselben Kante schon hat, **gewinnt die des
 * Teils**: sie ist die, die auf dem Bildschirm stand.*
 *
 * Usage: php scripts/dev/setting-part-migrate.php [--dry] [C:/Devel/Wordpress]
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

$argumente = array_slice($argv, 1);
$trocken   = in_array('--dry', $argumente, true);
$root      = null;

foreach ($argumente as $argument) {
    if (! str_starts_with($argument, '--')) {
        $root = $argument;

        break;
    }
}

$root ??= getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require rtrim($root, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;

global $wpdb;

$nodes = new WpdbNodeRepository();
$recs  = new WpdbRecordRepository();

/** @var list<array<string,string|null>> $zeilen */
$zeilen = $wpdb->get_results(
    'SELECT v.id AS zeile, v.node_record_id AS besitzer, v.relation_id, v.locale, v.value_ref AS teil
     FROM ' . Schema::table('relation_records') . " v
     WHERE v.value_ref_kind = 'record'",
    ARRAY_A
);

printf("%d Wertzeilen mit einem Satzverweis%s\n\n", count($zeilen), $trocken ? ' (Probelauf)' : '');

$umgehaengt = 0;
$geloest    = 0;
$verworfen  = 0;

foreach ($zeilen as $z) {
    $teilId    = (int) $z['teil'];
    $besitzer  = (int) $z['besitzer'];
    $teil      = $recs->find($teilId);

    if ($teil === null) {
        printf("  Zeile #%s zeigt auf Satz %d, den es nicht gibt — Verweis wird geleert\n", $z['zeile'], $teilId);

        if (! $trocken) {
            $wpdb->update(
                Schema::table('relation_records'),
                ['value_ref' => null, 'value_ref_kind' => null],
                ['id' => (int) $z['zeile']]
            );
        }

        continue;
    }

    $gewaehlt = $nodes->find($teil->nodeId);

    printf(
        "  Satz #%d an «%s» -> Knoten «%s»",
        $teilId,
        $nodes->find((int) $wpdb->get_var($wpdb->prepare(
            'SELECT node_id FROM ' . Schema::table('node_records') . ' WHERE id = %d',
            $besitzer
        )))?->name ?? '?',
        $gewaehlt?->name ?? '?'
    );

    // ⚠️ *Die Werte des Teils in den Satz des Besitzers — **an derselben Kante**, denn die Kante ist
    // die Adresse ([D-667](../../docs/NewConcept/90-decision-log.md)).*
    $mit = 0;

    foreach ($recs->valuesOf($teilId) as $wert) {
        $schon = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND relation_id = %d AND locale = %s',
            $besitzer,
            $wert->relationId,
            $wert->locale
        ));

        if (! $trocken) {
            if ($schon !== null) {
                $wpdb->delete(Schema::table('relation_records'), ['id' => (int) $schon]);
                ++$verworfen;
            }

            $recs->putValue(new RelationRecord(
                $besitzer,
                $wert->relationId,
                $wert->locale,
                $wert->value,
                0,
                $wert->position
            ));
        }

        ++$mit;
    }

    // ⚠️ *Und der Verweis zeigt jetzt auf den **Knoten**, nicht auf den Satz — dieselbe Spalte, ein
    // anderer Raum ([D-597](../../docs/NewConcept/90-decision-log.md)).*
    if (! $trocken && $gewaehlt !== null) {
        $wpdb->update(
            Schema::table('relation_records'),
            ['value_ref' => $gewaehlt->id, 'value_ref_kind' => IdentitySpace::Node->value],
            ['id' => (int) $z['zeile']]
        );

        $recs->forgetRecord($teilId);
    }

    printf(", %d Werte mitgenommen\n", $mit);

    ++$umgehaengt;
    $geloest += $mit;
}

printf(
    "\n%d Teile aufgeloest, %d Werte umgehaengt, %d doppelte verworfen%s\n",
    $umgehaengt,
    $geloest,
    $verworfen,
    $trocken ? ' — nichts geschrieben' : ''
);
