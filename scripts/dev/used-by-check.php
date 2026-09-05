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
use Taxmod\WordPress\Plugin;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

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

foreach ($wpdb->get_results("SELECT kind, (name <> '') AS named, COUNT(*) c FROM {$prefix}relations_named GROUP BY kind, named", ARRAY_A) as $row) {
    $kinds[$row['kind'] . ($row['named'] ? ' named' : ' unnamed')] = (int) $row['c'];
}

// ⚠️ **Eine ausgehende Kante ist entweder Vererbung (Baum und Elternchip) oder ein **benanntes**
// Attribut (die Attributtabelle). Eine dritte Art wäre eine Richtung, die auf der Seite nicht
// vorkommt — dann hält D-199 nicht mehr und der Abschnitt muss zurückwachsen.
$thirdKind = 0;

foreach ($kinds as $what => $count) {
    if (str_ends_with($what, ' unnamed')) {
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
$relation    = $editor->addField($teil->id, $einheit->id, 'ub_einheit');

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

    $editor->removeField($teil->id, $relation->id);

    $parked = $pageOf($einheit->id);

    $say(! str_contains($parked, 'ub_einheit'), 'das geparkte Attribut verschwindet aus Used by');
    $say(str_contains($parked, 'Nothing points at this node'), 'und der Knoten meldet sich als unbenutzt');

    // ── TASK-037: Loeschen fragt nach den Verwendungen (D-604) ───────────────────────────────────
    //
    // ⚠️ **Ueber die Maske und nicht ueber den Kern.** *Ein Waechter, der nur `moveToTrash($id, true)`
    // ruft, prueft die Haelfte, die nie kaputt war — die Frage ist, ob die Seite **fragt**, statt
    // stillschweigend zu loeschen. `renderer-choice-mask-check` und `move-mask-check` machen es vor:
    // zeichnen, Markup lesen, abschicken, frisch nachlesen.*
    //
    // ⚠️ **Der Gegenfall gehoert dazu:** *ein unbenutzter Knoten darf **keinen** Dialog bekommen,
    // sonst waere «es fragt» auch dann wahr, wenn es immer fragt — und D-604 verlangt die Frage genau
    // dort, wo etwas zerbricht.*
    echo "\n== Loeschen fragt nach den Verwendungen ==\n";

    $editor->restoreField($teil->id, $relation->id);

    $seite = $pageOf($einheit->id);

    $say(
        (bool) preg_match('/id="taxmod-trash-' . $einheit->id . '"/', $seite),
        'der benutzte Knoten bekommt einen Loeschdialog statt eines Knopfes'
    );

    // ⚠️ *Genau **einer**, und der steht im Dialog. Zwei hiessen: die Frage ist zu umgehen, und dann
    // ist der Dialog Zierat statt Bedingung.*
    $say(
        preg_match_all('/<button[^>]*name="do"[^>]*value="trash"/', $seite) === 1,
        'es gibt keinen zweiten Papierkorbknopf, der die Frage umgeht',
        (string) preg_match_all('/<button[^>]*name="do"[^>]*value="trash"/', $seite)
    );

    $say(str_contains($seite, 'ub_einheit'), 'der Dialog nennt die Verwendung beim Namen');
    $say(str_contains($seite, '__ub Teil'), 'und sagt, an welchem Knoten sie haengt');

    $say(
        (bool) preg_match('/<button[^>]*name="do"[^>]*value="trash_with_uses"/', $seite),
        'er bietet «ja, mit den Verwendungen» an'
    );

    $say(
        (bool) preg_match('/<button[^>]*name="do"[^>]*value="trash"/', $seite),
        'und «nein, nur den Knoten»'
    );

    // ⚠️ *Ein Knopf ausserhalb des Formulars schickt lautlos nichts mit — dieselbe Falle wie in
    // `move-mask-check`. Gemessen wird die Formulartiefe an der Stelle des Ja-Knopfes.*
    $vorJa = substr($seite, 0, (int) strpos($seite, 'value="trash_with_uses"'));

    $say(
        substr_count($vorJa, '<form') - substr_count($vorJa, '</form>') === 1,
        'die Zusage steckt in einem Formular',
        'Formulartiefe ' . (substr_count($vorJa, '<form') - substr_count($vorJa, '</form>'))
    );

    // Der Gegenfall: der benutzende Knoten wird von niemandem benutzt.
    $ohne = $pageOf($teil->id);

    $say(
        ! str_contains($ohne, 'id="taxmod-trash-' . $teil->id . '"'),
        'ein unbenutzter Knoten bekommt keinen Dialog'
    );

    $say(
        (bool) preg_match('/<button[^>]*name="do"[^>]*value="trash"/', $ohne),
        'sondern den gewoehnlichen Papierkorbknopf'
    );

    echo "\n== und die Zusage geht den Weg ueber die Maske ==\n";

    $_POST = $_REQUEST = [
        'do'            => 'trash_with_uses',
        'id'            => (string) $einheit->id,
        '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $einheit->id),
    ];

    $lief = false;
    $fang = static function () use (&$lief): string {
        $lief = true;

        throw new RuntimeException('redirect');
    };

    add_filter('wp_redirect', $fang, 1);

    try {
        $screen->handlePost();
    } catch (RuntimeException) {
        // Erwartet: der Akt ist durch und wollte weiterleiten.
    } finally {
        remove_filter('wp_redirect', $fang, 1);

        $_POST = $_REQUEST = [];
    }

    $say($lief, 'der Akt ist durchgelaufen');

    // ⚠️ **Frisch nachgelesen und nicht aus dem Gedaechtnis** — ein Dienst mit warmem Zustand haette
    // dieselbe Antwort gegeben, ob geschrieben wurde oder nicht.
    $frisch = (new \Taxmod\WordPress\Persistence\WpdbNodeRepository())->find($einheit->id);

    $say(
        $frisch !== null && $frisch->parentId() === $fw->trash()->id,
        'der Knoten liegt im Papierkorb',
        'Elternknoten ' . (string) ($frisch?->parentId() ?? 0)
    );

    $say(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}relations WHERE id = %d",
            $relation->id
        )) === 0,
        'und die Verwendung ist mitgegangen — sie steht nicht mehr lebend da'
    );

    // ⚠️ *Umkehrbar oder gar nicht: die geparkte Kante liegt im Schatten
    // ([D-535](../../docs/NewConcept/90-decision-log.md)), nicht im Nichts.*
    $say(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}relations_history WHERE id = %d",
            $relation->id
        )) > 0,
        'sie steht im Schatten, ist also zurueckzuholen'
    );
} finally {
    // Aufräumen, auch nach einer gefallenen Zusage.
    foreach ([$teil->id, $einheit->id] as $id) {
        $e   = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}relations WHERE from_node_id = %d OR to_node_id = %d",
            $id,
            $id
        )));
        $own = $e === [] ? (string) $id : $id . ',' . implode(',', $e);

        $wpdb->query("DELETE FROM {$prefix}labels WHERE owner_id IN ({$own})");
        $wpdb->query("DELETE FROM {$prefix}changelog WHERE owner_id IN ({$own})");

        if ($e !== []) {
            $wpdb->query('DELETE FROM ' . $prefix . 'relations WHERE id IN (' . implode(',', $e) . ')');
        }

        // ⚠️ *Der Schatten gehoert mit weggeraeumt, seit dieser Lauf eine Kante **parkt**: eine
        // geparkte Zeile steht nicht mehr in `relations`, sondern in `relations_history` — und wer
        // nur die lebende Tabelle raeumt, laesst genau das liegen, was er selbst erzeugt hat.*
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$prefix}relations_history WHERE from_node_id = %d OR to_node_id = %d",
            $id,
            $id
        ));
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}nodes_history WHERE id = %d", $id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}nodes WHERE id = %d", $id));
    }
}

echo "\n";
$say(
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}nodes_named WHERE name LIKE '__ub %'") === 0,
    'die Wiese ist wieder weg'
);

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
