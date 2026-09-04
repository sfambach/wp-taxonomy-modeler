<?php declare(strict_types=1);

/**
 * Does anything own a setting or a label that no longer exists?
 *
 * ⚠️ **This is [row 28](../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s second
 * half, and it is the half that lasts.** The first half was a one-time clean-up — **892 setting rows
 * and 126 label rows** belonged to owners that were gone. *A clean-up without a guard is a clean-up
 * that has to be repeated, and the thing producing the litter was this very net.*
 *
 * ⚠️ **Measured after the sweep, check by check: exactly one leaked.** `package7-check` left **five
 * rows a run** — it deleted its scratch nodes and edges and left their settings standing. It took two
 * goes to fix, because the raw delete finds its edges by **name** while the first fix only knew them by
 * **endpoint**, and edges whose nodes had already gone had no endpoint left to be found by.
 *
 * ⚠️ **The installation identity is not an orphan and every naive query says it is.** It is an identity
 * with no node and no relation behind it ([D-079](../../docs/NewConcept/90-decision-log.md)), and its
 * rows are the declared defaults for the switches ([D-404](../../docs/NewConcept/90-decision-log.md)).
 * *A check that swept it up would take the answer to «what does `persistent` mean when nobody said» with
 * it.*
 *
 * ⚠️ *It reads and never writes. `orphans-clean.php` is the one that removes, and it is a separate file
 * for that reason — a check that repairs what it measures cannot fail twice.*
 *
 * ⚠️ **The queries are not here any more.** *They live in {@see \Taxmod\WordPress\Persistence\Residue},
 * because the `Cleanup` screen ([D-247](../../docs/NewConcept/90-decision-log.md)) asks the same three
 * questions — and two copies of one query are the way a correction reaches only one of them
 * (`CLAUDE.md`: «one place owns each piece of state»). **This file keeps its own counter-check**, which
 * is the part a shared query cannot supply.*
 *
 * Usage: php scripts/dev/orphans-check.php
 *
 * @see docs/NewConcept/97-implementation-plan.md
 */

define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Persistence\Residue;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$changelog = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $changelog);

$installation = $framework->installationId();

$residue = new Residue($framework, new WpdbSettingRepository(), new WpdbLabelRepository(), $changelog);

global $wpdb;

$p      = $wpdb->prefix . 'taxmod_';
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failed;

    if ($ok) {
        echo "  ok   {$what}\n";

        return;
    }

    $failed++;

    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

echo "\n== nichts gehoert einem Besitzer, den es nicht gibt ==\n";

// ⚠️ *Dieselbe Abfrage, die die `Cleanup`-Seite stellt — und sie prueft `$wpdb->last_error` selbst
// nach jeder Anweisung und wirft, statt eine kaputte Abfrage als «kein Rueckstand» zu melden.*
$settings = array_sum($residue->orphanedSettings());
$labels   = array_sum($residue->orphanedLabels());

check('keine Waisen-Settings', $settings === 0, "{$settings} Zeilen");
check('keine Waisen-Labels', $labels === 0, "{$labels} Zeilen");

// ⚠️ **Die anderen zwei Quellen, die [D-247](../../docs/NewConcept/90-decision-log.md) nennt** —
// gemessen und **nicht** als Fehler gewertet. *Sie sind kein Leck dieses Netzes, sondern der
// Rueckstand, den bewusstes Nicht-Aufraeumen hinterlaesst: [D-159](../../docs/NewConcept/90-decision-log.md)
// sagt ausdruecklich, dass Werte einer verschwundenen Kante **stehen bleiben**. Eine Zahl hier, ein
// Knopf auf der Seite — eine Pruefung, die daran scheitert, wuerde eine Entscheidung ueberstimmen.*
$werte  = array_sum($residue->valuesWithoutEdge());
$allein = count($residue->nodesWithoutConnections());

printf("  --   %d Werte ohne Kante, %d Knoten ohne Verbindungen (Cleanup-Seite)\n", $werte, $allein);

// ⚠️ **Die Gegenpruefung, die den zwei oben erst Bedeutung gibt.** *Ohne sie waere eine Abfrage, die
// versehentlich nichts findet, genauso gruen.*
//
// ⚠️ **Der Anker war bis zum 2026-09-01 die Installationsidentitaet — «der eine Besitzer, der
// garantiert keinen Knoten hat und trotzdem bleiben muss».** *Er traegt seit Zeile 85 der
// Arbeitsliste **null Zeilen**: alle 147 `read_only`-Zeilen sagten `false`, und `false` ist auch die
// Antwort ohne Zeile — sie trugen keine Aussage und sind entfernt ([D-505](../../docs/NewConcept/90-decision-log.md)).
// **Die Gegenpruefung war damit rot, ohne dass etwas kaputt war**, und das Aendern dieser Zusicherung
// ist der sichtbare Teil jener Aenderung ([`PR-9`](../../CLAUDE.md)).*
//
// ⚠️ **Der neue Anker ist das, was die Abfragen ueberhaupt durchsuchen.** *«Keine Waisen» wiegt nur,
// wenn es etwas zu durchsuchen gab. `settings` stirbt ([D-529](../../docs/NewConcept/90-decision-log.md)) —
// **deshalb darf der Anker nicht daran haengen**: gezaehlt werden beide Tabellen zusammen, und
// `labels` bleibt, wenn `settings` faellt.*
$durchsucht = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings")
    + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}labels");

check(
    'es gab ueberhaupt etwas zu durchsuchen',
    $durchsucht > 0,
    "{$durchsucht} Zeilen in settings und labels zusammen"
);

echo "\n", $failed === 0 ? "all green\n" : "{$failed} failed\n";

exit($failed === 0 ? 0 : 1);
