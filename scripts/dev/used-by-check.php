<?php declare(strict_types=1);

/**
 * `Used by` — der Abschnitt, der die eine Richtung hält, die sonst nirgends steht.
 *
 * ⚠️ **[D-199](../../docs/NewConcept/90-decision-log.md) und die Bedingung, unter der sie gilt.**
 * Der Eigentümer: *«everything going out of the current node is in the attributes. As long as that
 * stays so, we do not need to show them in the relations.»* **Die Bedingung wird hier gemessen und
 * nicht angenommen**: erscheint je eine ausgehende Kante, die weder Attribut noch Vererbung ist,
 * muss der Abschnitt zurückwachsen — *«or it quietly stops being complete»*.
 *
 * ⚠️ *Gegen den echten Screen geprüft: der Kern kann keine URL bauen (`CD-1`), und ob der Abschnitt
 * überhaupt im Rahmen landet, entscheidet {@see \Taxmod\Core\Renderer\PageSlot} — beides sieht ein
 * Kerntest nicht.*
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\RelationKind;
use Taxmod\WordPress\Plugin;

$failed = 0;

$say = static function (bool $ok, string $what, string $saw = ''): void {
    global $failed;

    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $saw === '' ? '' : "  — {$saw}");

    if (! $ok) {
        ++$failed;
    }
};

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionClass(Plugin::class))->getProperty('file')
    ->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');

$screen = (new ReflectionMethod(Plugin::class, 'screen'))->invoke($plugin);
$editor = (new ReflectionMethod(Plugin::class, 'editor'))->invoke($plugin);
$fw     = (new ReflectionMethod(Plugin::class, 'frameworkNodes'))->invoke($plugin);

/** Renders one node's page. */
$pageOf = static function (int $id) use ($screen): string {
    $_GET['taxmod_node'] = (string) $id;

    return $screen->render();
};

global $wpdb;

$prefix = $wpdb->prefix . 'taxmod_';

// ── D-199s Bedingung, gemessen statt angenommen ──────────────────────────────────────────────────

echo "== die Bedingung, unter der eine Richtung reicht ==\n";

$kinds = [];

foreach ($wpdb->get_results("SELECT kind, (name <> '') AS named, COUNT(*) c FROM {$prefix}relations GROUP BY kind, named", ARRAY_A) as $row) {
    $kinds[$row['kind'] . ($row['named'] ? ' named' : ' unnamed')] = (int) $row['c'];
}

// ⚠️ **Eine ausgehende Kante ist entweder Vererbung (Baum und Elternchip) oder ein **benanntes**
// Attribut (die Attributtabelle). Eine dritte Art wäre eine Richtung, die auf der Seite nicht
// vorkommt — dann hält D-199 nicht mehr und der Abschnitt muss zurückwachsen.
$thirdKind = 0;

foreach ($kinds as $what => $count) {
    if (str_ends_with($what, ' unnamed') && ! str_starts_with($what, RelationKind::Inheritance->value)) {
        $thirdKind += $count;
    }
}

$say(
    $thirdKind === 0,
    'jede ausgehende Kante ist Vererbung oder ein benanntes Attribut',
    json_encode($kinds, JSON_UNESCAPED_UNICODE)
);

// ── Der Abschnitt an einer eigens gebauten Wiese ─────────────────────────────────────────────────

echo "\n== der Abschnitt nennt, wer hierher zeigt ==\n";

$model = $fw->rootOf(Branch::Model)->id;

$einheit = $editor->createNode('__ub Einheit', $model);
$teil    = $editor->createNode('__ub Teil', $model);
$edge    = $editor->addField($teil->id, $einheit->id, 'ub_einheit');

try {
    $page = $pageOf($einheit->id);

    $say(str_contains($page, 'class="taxmod-used-by"'), 'der Abschnitt ist gezeichnet');
    $say(str_contains($page, 'ub_einheit'), 'der Name des Attributs steht darin');

    // ⚠️ **Auf die Id im href geprüft und nicht auf den Namen** — derselbe Grund wie in
    // `target-link-check.php`: ein abgebrochener Lauf hinterlässt Knoten mit denselben Namen.
    $say(
        (bool) preg_match('/<a href="[^"]*taxmod_node=' . $teil->id . '[^"]*" class="taxmod-used-by-link"/', $page),
        sprintf('der Sprunglink wählt den benutzenden Knoten %d aus', $teil->id)
    );

    $say(str_contains($page, '__ub Teil</a>'), 'und sein Name steht im Link');

    // ⚠️ **Die Gegenrichtung ist die Probe, die zählt.** *Der Abschnitt am **benutzenden** Knoten
    // darf sein eigenes Attribut nicht aufzählen — sonst wäre er eine zweite Attributtabelle und
    // D-199s «eine Richtung» wäre nur ein Wort.*
    $other = $pageOf($teil->id);

    $say(
        str_contains($other, 'Nothing points at this node'),
        'am benutzenden Knoten steht nichts unter Used by'
    );

    // ⚠️ *Und die leere Antwort ist gesagt, nicht weggelassen: «nichts verweist hierher» und «ich
    // habe nicht nachgesehen» sehen in einem leeren Kasten gleich aus.*
    $say(str_contains($other, 'class="taxmod-used-by"') === false, 'und zwar als Satz statt als leere Liste');

    // ── Eine geparkte Kante ist keine Benutzung (D-128) ──────────────────────────────────────────
    echo "\n== eine geparkte Kante zählt nicht ==\n";

    $editor->removeField($teil->id, $edge->id);

    $parked = $pageOf($einheit->id);

    $say(! str_contains($parked, 'ub_einheit'), 'das geparkte Attribut verschwindet aus Used by');
    $say(str_contains($parked, 'Nothing points at this node'), 'und der Knoten meldet sich als unbenutzt');
} finally {
    // Aufräumen, auch nach einer gefallenen Zusage.
    foreach ([$teil->id, $einheit->id] as $id) {
        $e   = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}relations WHERE from_id = %d OR to_id = %d",
            $id,
            $id
        )));
        $own = $e === [] ? (string) $id : $id . ',' . implode(',', $e);

        $wpdb->query("DELETE FROM {$prefix}labels WHERE owner_id IN ({$own})");
        $wpdb->query("DELETE FROM {$prefix}changelog WHERE owner_id IN ({$own})");

        if ($e !== []) {
            $wpdb->query('DELETE FROM ' . $prefix . 'relations WHERE id IN (' . implode(',', $e) . ')');
        }

        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}nodes WHERE id = %d", $id));
    }
}

echo "\n";
$say(
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}nodes WHERE name LIKE '__ub %'") === 0,
    'die Wiese ist wieder weg'
);

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
