<?php declare(strict_types=1);
/**
 * Die Blöcke der Knotenseite teilen nach der **Kante** ein, nicht nach dem Zielknoten.
 *
 *     php scripts/dev/page-blocks-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer hat den Widerspruch in einer einzigen Zeile gesehen:** *auf dem Knoten `form`
 * stand `with_label` **in der Tabelle Fields**, und in ihrer eigenen Spalte «Kind» stand `setting`.
 * Seine Frage war «falscher Relationstyp gewählt?» — **nein, die Art war richtig.** Die Tabelle fragte
 * die Sorte des **Zielknotens**, und `Boolean` ist ein `field`.*
 *
 * ⚠️ **Gemessen vor der Behebung: fünf von sieben Einstellungskanten lagen unter Fields** —
 * `orientation`, `exponent`, `label_role`, `with_label` und alles, dessen Ziel eine Knotensorte `field`
 * trägt. *Seit [D-526](../../docs/NewConcept/90-decision-log.md) sagt die Kante selbst, was sie ist; die
 * Knotensorte ist eine zweite Abschrift derselben Aussage — und **zwei Angaben, die dasselbe sagen
 * sollen, sind eine**, dieselbe Auflösung wie bei `mandatory` ([D-405](../../docs/NewConcept/90-decision-log.md))
 * und `persistent` ([D-538](../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Diese Prüfung zeichnet die echte Seite.** *Sie lädt WordPress, setzt den Verwalter **im Code**
 * und ruft die Schirmklasse — keine Anmeldung, kein Formular. Das ist die erste Prüfung, die den
 * Schirm selbst ansieht statt seine Bestandteile: **die vier Fehler dieses Abends waren alle
 * Verdrahtung**, und Verdrahtung sieht man nur an der fertigen Seite.*
 *
 * @see docs/NewConcept/30-renderer.md
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
require __DIR__ . '/geruest.php';

use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Plugin;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

global $wpdb;
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

/**
 * Die Seite eines Knotens, gezeichnet wie im Browser.
 *
 * ⚠️ *Der Verwalter wird im Code gesetzt. Die Schirmklasse prüft eine Fähigkeit (`CD-5`), und ohne
 * angemeldeten Benutzer bekäme dieser Lauf die Absage statt die Seite — **er umgeht keine Prüfung,
 * er erfüllt sie**.*
 */
function seiteVon(int $nodeId): string
{
    $admin = get_users(['role' => 'administrator', 'number' => 1]);

    if ($admin === []) {
        return '';
    }

    wp_set_current_user($admin[0]->ID);

    $_GET['taxmod_node'] = (string) $nodeId;
    $_GET['page']        = 'taxmod-nodes';

    // ⚠️ *Der Konstruktor ist privat, weil das Plugin über `boot()` entsteht — für einen Lauf ohne
    // Hooks bleibt der Weg über die Spiegelung. **Nur lesend**: es wird nichts registriert.*
    $bau    = new ReflectionClass(Plugin::class);
    $plugin = $bau->newInstanceWithoutConstructor();
    $bau->getProperty('file')->setValue($plugin, 'taxmod.php');

    return $plugin->screen()->render();
}

/**
 * Die Zeilen einer Tabelle unter dieser Überschrift, jede Zelle als Klartext.
 *
 * @return list<list<string>>
 */
function tabelleUnter(string $html, string $ueberschrift, string $bisUeberschrift): array
{
    // ⚠️ *Die Dialoge des Ziel-Wählers weg — sie tragen den ganzen Baum und darin steht jedes Wort.*
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    // WICHTIG: Die Ueberschrift wird als <h3> gesucht und nicht als blosses Wort. Seit der
    // Auswahldialog in der Zelle steht, tragen die Zeilen den ganzen Baum -- und darin kommt
    // "Settings" als Knotenname vor. Der Block wurde dadurch mitten in der ersten Zeile
    // abgeschnitten, und die Zusage meldete null Felder, wo drei sind.
    if (! preg_match('/<h3\b[^>]*>' . preg_quote($ueberschrift, '/') . '</', $html, $t, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $von = $t[0][1];
    $bis = preg_match('/<h3\b[^>]*>' . preg_quote($bisUeberschrift, '/') . '</', substr($html, $von + 4), $u, PREG_OFFSET_CAPTURE)
        ? $von + 4 + $u[0][1]
        : false;
    $teil = substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);

    if (! preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $teil, $zeilen)) {
        return [];
    }

    $aus = [];

    foreach ($zeilen[1] as $zeile) {
        preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/s', $zeile, $zellen);

        $aus[] = array_map(
            static fn (string $z): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($z)))),
            $zellen[1]
        );
    }

    return $aus;
}

function knotenId(string $name): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes_named') . ' WHERE name = %s ORDER BY id DESC LIMIT 1',
        $name
    ));
}

echo "\n== Keine Einstellungskante unter «Fields» ==\n";

// ⚠️ *`form` ist der Knoten, an dem er es gesehen hat; `Integer` erbt dieselben drei; `Passiv` ist der
// Gegenfall mit echten Feldern.*
foreach (['form', 'Integer', 'Passiv'] as $name) {
    $id = knotenId($name);

    if ($id === 0) {
        check("«{$name}» steht im Modell", false);

        continue;
    }

    $html = seiteVon($id);

    if ($html === '') {
        check("«{$name}»: die Seite laesst sich zeichnen", false, 'kein Administrator gefunden');

        continue;
    }

    $felder = tabelleUnter($html, 'Fields', 'Settings');

    $einstellungen = [];

    foreach ($felder as $zeile) {
        // Spalten: Name, Points at, Kind, From, How many, Akte.
        if (($zeile[2] ?? '') === 'setting') {
            $einstellungen[] = $zeile[0] ?? '?';
        }
    }

    check(
        "«{$name}»: keine setting-Zeile unter Fields",
        $einstellungen === [],
        implode(', ', $einstellungen)
    );
}

echo "\n== Und die Einstellungen stehen unter «Settings» ==\n";

// ⚠️ **Der Gegenfall, und ohne ihn ist die Prüfung wertlos.** *Ein Schirm, der die Einstellungen
// **gar nicht** mehr zeigt, hätte oben ebenfalls keine setting-Zeile unter Fields.*
$id   = knotenId('form');
$html = $id === 0 ? '' : seiteVon($id);

$settings = $html === '' ? [] : tabelleUnter($html, 'Settings', 'Preview');
$gesehen  = [];

foreach ($settings as $zeile) {
    if (($zeile[2] ?? '') === 'setting') {
        $gesehen[] = $zeile[0] ?? '?';
    }
}

// ⚠️ *Hier stand `>= 3` — die drei Einstellungen der **Wurzel**. Seit
// [D-545](../../docs/NewConcept/90-decision-log.md) erbt nichts im Settings-Ast mehr von `Root`, und
// `form` liegt darin: es zeigt jetzt seine zwei eigenen, `label_role` und `with_label`. **Die Zusage
// wollte nie eine Zahl, sondern «die Einstellungen dieses Knotens stehen unter Settings».***
check(
    '«form» zeigt seine Einstellungen unter Settings',
    count($gesehen) >= 2,
    count($gesehen) . ': ' . implode(', ', $gesehen)
);

// ⚠️ *Namentlich die, die er gemeldet hat.*
check(
    'und «with_label» ist darunter',
    in_array('with_label', $gesehen, true),
    implode(', ', $gesehen)
);

echo "\n== Ein Knoten mit echten Feldern behaelt sie ==\n";

$id     = knotenId('Passiv');
$html   = $id === 0 ? '' : seiteVon($id);
$felder = $html === '' ? [] : tabelleUnter($html, 'Fields', 'Settings');
$echte  = 0;

foreach ($felder as $zeile) {
    if (in_array($zeile[2] ?? '', ['composition', 'aggregation'], true)) {
        ++$echte;
    }
}

check('«Passiv» zeigt seine eigenen Felder unter Fields', $echte >= 3, (string) $echte);

echo "\n== «How many» ist an einer geerbten Kante gesperrt ==\n";

// ⚠️ **Er hat gefragt, ob die Umsetzung fehlt — sie fehlte.** *«How many sollte für Attribute des
// Vaterknotens nicht mehr im Kindknoten auswählbar sein, hatten wir geändert.»* *Gemessen an
// `render with label`: **keine einzige** Auswahl war gesperrt, auch nicht die drei geerbten. Die Zeile
// wusste es — ihre Spalte «From» sagte `inherited` — und gab es nicht an die Auswahl weiter; in
// `drawChoice()` stand `editable: true`, fest verdrahtet.*
//
// ⚠️ *[D-376](../../docs/NewConcept/90-decision-log.md): eine geerbte Kante gehört dem Vorfahren, und
// sie **hier** zu ändern hiesse, sie für alle zu ändern — still.*
// ⚠️ *Hier stand «render with label» — der liegt im Settings-Ast und erbt seit
// [D-545](../../docs/NewConcept/90-decision-log.md) nichts mehr von der Wurzel, hat also keine
// geerbte Zeile mehr. **Gefragt ist ein Knoten, der wirklich erbt.***
$id   = knotenId('Kontact');
$html = $id === 0 ? '' : seiteVon($id);

if ($html === '') {
    check('«render with label» steht im Modell', false);
} else {
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $html, $zeilen);

    $offeneGeerbte = 0;
    $gesperrteEigene = 0;
    $geerbte = 0;
    $eigene  = 0;

    foreach ($zeilen[1] as $zeile) {
        if (! str_contains($zeile, 'taxmod-field-many')) {
            continue;
        }

        if (! preg_match('/<select\b[^>]*class="taxmod-choice[^"]*"[^>]*>/', $zeile, $treffer)) {
            continue;
        }

        $gesperrt = str_contains($treffer[0], 'disabled');

        if (str_contains($zeile, 'inherited')) {
            ++$geerbte;

            if (! $gesperrt) {
                ++$offeneGeerbte;
            }

            continue;
        }

        ++$eigene;

        if ($gesperrt) {
            ++$gesperrteEigene;
        }
    }

    // WICHTIG: Die Zahl faellt von drei auf zwei, und das ist eine sichtbare Aenderung dieser
    // Zusage (PR-9). `Kontact` erbte von der Wurzel drei Einstellungskanten -- die Traegerkante
    // des Renderers, `validator`, `read_only`. Die erste ist mit dem Huellknoten `DisplayOption`
    // gefallen (D-604), und der Renderer haengt seit TASK-020 an `nodes.settings_record_id`
    // (D-584). Gemessen am 2026-09-05: zwei geerbte Zeilen, und das sind alle, die es gibt.
    // Der Gegenstand der Zusage ist unberuehrt -- es muessen geerbte Zeilen da sein, damit
    // «geerbte sind gesperrt» ueberhaupt etwas behauptet.
    check('geerbte Zeilen gefunden', $geerbte >= 2, (string) $geerbte);
    check('und keine davon laesst «How many» aendern', $offeneGeerbte === 0, "{$offeneGeerbte} offen");

    // ⚠️ **Der Gegenfall.** *Alles zu sperren wäre oben ebenfalls grün — und wäre schlimmer als der
    // Fehler, den diese Zusage fängt.*
    check('eigene Zeilen gefunden', $eigene >= 1, (string) $eigene);
    check('und die duerfen es weiterhin', $gesperrteEigene === 0, "{$gesperrteEigene} gesperrt");
}

echo "\n== Die Renderkette geht durch ein zusammengesetztes Feld ==\n";

// ⚠️ **Seine Beobachtung, und dann seine Diagnose:** *«Renderer ist form, Adressfeld hat mindestens 4
// Felder, gezeigt wird aber nur eines»* — *«heisst wohl Renderkette ist unterbrochen»*. **Sie war
// unterbrochen.** *Gemessen: das Feld `Address` bekam Typ «keiner» und `plain`, während `Adresse` fünf
// eigene Felder trägt. Vier von fünf waren nie zu sehen.*
//
// ⚠️ *Der Knoten wird über **die Kante** gesucht, nicht über seinen Namen: er heisst «Kontact» und
// nicht «Kontakt», und eine Prüfung, die den Namen rät, ist morgen rot, weil jemand ihn korrigiert.*
// WICHTIG: Der Waechter baut sich seine eigene Komposition, statt eine im Modell zu suchen --
// TASK-025. Der Eigentuemer hat es gefunden: "warum haben wir einen Check auf Adresse, ich hatte
// das mal so angelegt, aber das war kein Vertrag". CLAUDE.md verbietet es ausdruecklich:
// "Special-casing by display name, label, path, or a specific node". Vorher suchte diese Zusage
// "Adresse" beim Namen -- und wurde rot, als er dort eine Schachtelung einbaute, ohne dass etwas
// kaputt war. Geprueft wird die Sache: geht die Renderkette durch ein zusammengesetztes Feld.
$geruest = new Geruest('__pb');
$zusammengesetzt = $geruest->komposition('Anschrift', ['Gasse', 'Nummer', 'Postleitzahl', 'Stadt']);

if ($zusammengesetzt === null) {
    check('ein Feld zeigt auf «Adresse»', false, 'keines gefunden');
} else {
    check('ein Feld zeigt auf «Adresse»', true);

    $innen = $wpdb->get_col($wpdb->prepare(
        'SELECT rel.name FROM ' . Schema::table('relations_named') . " rel
         WHERE rel.from_node_id = %d AND rel.kind <> 'inheritance' AND rel.hide = 0 AND rel.kind <> 'setting'",
        (int) $zusammengesetzt['ziel']
    )) ?: [];

    check('«Adresse» hat mehrere eigene Felder', count($innen) >= 4, count($innen) . ': ' . implode(', ', $innen));

    $html = seiteVon((int) $zusammengesetzt['besitzer']);
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    $von  = strpos($html, '>Preview<');
    $bis  = $von === false ? false : strpos($html, '>Used by<', $von);
    $teil = $von === false ? '' : substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);

    $fehlen = [];

    foreach ($innen as $feld) {
        if (! str_contains($teil, (string) $feld)) {
            $fehlen[] = (string) $feld;
        }
    }

    check(
        'und die Vorschau nennt sie alle',
        $fehlen === [],
        'fehlt: ' . implode(', ', $fehlen)
    );

    // ⚠️ **Der Gegenfall: nicht nur Wörter, sondern Bedienelemente.** *Ein Behälter, der die Namen
    // ausgibt und keine Eingabe zeichnet, wäre oben grün — und wäre genau das, was vorher war.*
    preg_match_all('/<(?:input|select|textarea)\b[^>]*>/', $teil, $bedienung);

    check(
        'und zeichnet je Feld eine Bedienung',
        count($bedienung[0]) >= count($innen),
        count($bedienung[0]) . ' fuer ' . count($innen) . ' Felder'
    );
}

echo "\n== Die Auswahl sieht durch einen markierten Zwischenknoten hindurch ==\n";

// ⚠️ **Auf sein Wort: «table, form, compact muss wählbar bleiben, warum auch nicht?»** *Sie waren es
// nicht mehr: er hatte «render label roles» und «render with label» zu einem zusammengelegt und die
// Renderer darunter geschoben, und damit sind sie **Enkel** von `Renderer`.
// [D-540](../../docs/NewConcept/90-decision-log.md) spricht von **Kindern** — den Zwischenknoten kannte
// die Entscheidung noch nicht.*
//
// ⚠️ *Die Erweiterung folgt aus ihren eigenen Worten: «unmarkiert» ist dort die Bedingung, also heisst
// **markiert** «kein Wert, nur Struktur» — und die Auswahl steigt hindurch. Gemessen vorher: die Liste
// bot «render with label» an und weder `form` noch `table` noch `compact`.*
// WICHTIG: Das Angebot wird bei der Aufloesung erfragt und nicht mehr aus einem `<option>` der
// Seite gelesen -- eine sichtbare Aenderung dieser Zusage (PR-9). Der Grund ist gemessen: das
// Auswahlfeld wurde je Einstellungs*kante* gezeichnet, und die Kante `renderer` gibt es nicht mehr.
// Sie hing am Huellknoten `DisplayOption`, den der Eigentuemer geloescht hat (D-604); seit TASK-020
// steht die Wahl in `nodes.settings_record_id` (D-584). Gemessen am 2026-09-05 traegt `Root` nur
// noch `validator` und `read_only`, und auf keiner Knotenseite steht ein Renderer-Waehler.
//
// ⚠️ **Die Zusage selbst ist unberuehrt** — *«die Auswahl sieht durch einen markierten Zwischenknoten
// hindurch» ist eine Aussage ueber die **Menge der Moeglichkeiten**, nicht ueber das Steuerelement.
// Sie wird jetzt dort gestellt, wo diese Menge entsteht.*
//
// ⚠️ **Und der Rest ist als Befund zu melden, nicht gruen zu faerben:** *dass die Bedienung zum
// Waehlen fehlt, ist eine Luecke des Umbaus. Sie gehoert ins Eingangsblatt.*
$id   = knotenId('Kontact');
$node = $id === 0 ? null : (new \Taxmod\WordPress\Persistence\WpdbNodeRepository())->find($id);

if ($node === null) {
    check('ein Knoten mit geerbter Renderer-Einstellung', false);
} else {
    $relations     = new \Taxmod\WordPress\Persistence\WpdbRelationRepository();
    $nodesRepo = new \Taxmod\WordPress\Persistence\WpdbNodeRepository();
    $framework = new \Taxmod\WordPress\Persistence\SeededFrameworkNodes(
        $nodesRepo,
        $relations,
        new \Taxmod\WordPress\Persistence\WpdbChangelog(new \Taxmod\WordPress\SystemClock())
    );

    $rendering = new \Taxmod\Core\Service\Rendering(
        $nodesRepo,
        $framework,
        \Taxmod\Core\Renderer\ShippedRenderers::registry(),
        new \Taxmod\WordPress\Persistence\SeededTypeNodes($nodesRepo, $framework),
        new \Taxmod\Core\Service\Labels(
            new \Taxmod\WordPress\Persistence\WpdbLabelRepository(),
            \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale()
        ),
        null,
        new \Taxmod\Core\Service\ModelValues(
            new \Taxmod\WordPress\Persistence\WpdbRecordRepository(),
            $relations,
            $nodesRepo,
            $framework
        ),
        $relations
    );

    $angebot = [];

    foreach ($rendering->choicesForNode($node, \Taxmod\Core\Renderer\Purpose::Edit) as $einer) {
        $angebot[] = $einer->name();
    }

    foreach (['form', 'table', 'compact'] as $wunsch) {
        check("«{$wunsch}» steht zur Wahl", in_array($wunsch, $angebot, true), implode(', ', array_slice($angebot, 0, 20)));
    }

    // ⚠️ **Der Gegenfall: der Zwischenknoten selbst ist keine Möglichkeit.** *Ohne ihn wäre «alles
    // anbieten» ebenfalls grün — und «render with label» ist kein Renderer.*
    check(
        'und der Zwischenknoten selbst nicht',
        ! in_array('render with label', $angebot, true),
        implode(', ', $angebot)
    );
}

echo "\n== Die Vorschau hat drei Seiten, und Admin mischt keine Einstellungen ein ==\n";

// ⚠️ **Seine Entscheidung** ([D-547](../../docs/NewConcept/90-decision-log.md)), *gegen meinen
// Widerspruch: «und da du dich wehrst, entscheide ich jetzt: es gibt eine dritte Form neben Admin und
// Show, machen wir jetzt Settings.»*
//
// ⚠️ **Widerlegt hat mich seine Messung an der Seite.** *Ich hatte die Einstellungen in die
// Admin-Seite gelegt — und auf `Adresse` standen sie **zwischen** den Feldern: Street, Display Option,
// No., validator, Post Code … Er: «man sieht den Render in Settings, aber auch in der Preview vom
// Modell, und das darf nicht sein.»*
// WICHTIG: Auch hier das eigene Geruest statt seines Modells (TASK-025). Geprueft wird, dass
// Admin die Felder nennt und Settings die Einstellungen -- und keiner das des anderen. Welcher
// Knoten das zeigt, ist nicht die Sache dieser Zusage.
$eigenes = $geruest->komposition('Ansicht', ['Gasse', 'Nummer', 'Postleitzahl', 'Stadt']);
$id      = $eigenes['ziel'];
$html = $id === 0 ? '' : seiteVon($id);

if ($html === '') {
    check('der Geruestknoten steht im Modell', false);
} else {
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    $von  = strpos($html, '>Preview<');
    $bis  = $von === false ? false : strpos($html, '>Used by<', $von);
    $teil = $von === false ? '' : substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);

    preg_match_all('/<h4>([^<]*)<\/h4>(.*?)(?=<h4>|$)/s', $teil, $seiten, PREG_SET_ORDER);

    $ueberschriften = array_map(static fn (array $s): string => $s[1], $seiten);

    check('drei Seiten', count($seiten) === 3, implode(', ', $ueberschriften));

    foreach (['Display', 'Admin', 'Settings'] as $wunsch) {
        check("  · «{$wunsch}»", in_array($wunsch, $ueberschriften, true), implode(', ', $ueberschriften));
    }

    $nachName = [];

    foreach ($seiten as $s) {
        $nachName[$s[1]] = trim((string) preg_replace('/\s+/', ' ', strip_tags($s[2])));
    }

    // ⚠️ *Genau der Fall, den er gemeldet hat.*
    check(
        'Admin nennt keine Einstellung',
        ! str_contains($nachName['Admin'] ?? '', 'Display Option')
        && ! str_contains($nachName['Admin'] ?? '', 'read_only'),
        substr($nachName['Admin'] ?? '', 0, 80)
    );

    // ⚠️ **Die Gegenfälle.** *Ohne sie wären auch drei leere Seiten grün — und «Admin nennt keine
    // Einstellung» wäre am billigsten dadurch erfüllt, dass Admin gar nichts nennt.*
    check(
        'aber seine Felder',
        str_contains($nachName['Admin'] ?? '', 'Gasse'),
        substr($nachName['Admin'] ?? '', 0, 80)
    );

    // WICHTIG: Gesucht wird `read_only` und nicht mehr «Display Option» -- eine sichtbare
    // Aenderung dieser Zusage (PR-9). Der Huellknoten ist geloescht (D-604), seine Kanten mit ihm;
    // seit D-585 traegt der Renderer den `converter` selbst, und D-594 hat die 29 Wahlen in die
    // Spalte umgezogen. Gemessen am 2026-09-05 nennt die Settings-Seite `validator` und
    // `read_only` -- gewoehnliche Einstellungskanten der Wurzel, und genau das ist der Gegenstand
    // der Zusage: die Settings-Seite nennt Einstellungen. *Welche* es sind, war nie ihre Frage.
    check(
        'und Settings nennt die Einstellungen',
        str_contains($nachName['Settings'] ?? '', 'read_only'),
        substr($nachName['Settings'] ?? '', 0, 80)
    );

    check(
        'und keines seiner Felder',
        ! str_contains($nachName['Settings'] ?? '', 'Gasse'),
        substr($nachName['Settings'] ?? '', 0, 80)
    );
}

echo "\n== Der Datensatz-Block ist eine Tabelle, mit Aktionen rechts ==\n";

// ⚠️ **Auf sein Wort:** *«ich würde die Records immer als Tabelle zeigen … Action sollte rechts sein,
// Record, Version davor, sodass wir eine schmale Zeile bekommen … und zu welchem Knoten/Kante es gehört,
// würde ich auch noch vorne dran schreiben.»*
//
// ⚠️ **Gemessen an einem echten Knoten, nicht an einer Attrappe** — *der Block hat vorher **je Satz**
// eine Tabelle gezeichnet, und der Unterschied zwischen «eine Tabelle» und «23 Tabellen» ist genau die
// Zusage, die hier fehlte.*
$mitSaetzen = (int) $wpdb->get_var(
    'SELECT r.node_id FROM ' . Schema::table('node_records') . ' r
     INNER JOIN ' . Schema::table('relations_named') . " e ON e.from_node_id = r.node_id AND e.name <> '' AND e.kind <> 'setting' AND e.kind <> 'inheritance'
     GROUP BY r.node_id HAVING COUNT(DISTINCT r.id) > 2 ORDER BY COUNT(DISTINCT r.id) DESC LIMIT 1"
);

if ($mitSaetzen === 0) {
    check('ein Knoten mit mehreren Datensaetzen steht im Modell', false);
} else {
    check('ein Knoten mit mehreren Datensaetzen steht im Modell', true, (string) $mitSaetzen);

    $seite = seiteVon($mitSaetzen);
    $at    = strpos($seite, 'taxmod-record');
    $block = $at === false ? '' : substr($seite, $at);

    $saetze = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('node_records') . ' WHERE node_id = %d',
        $mitSaetzen
    ));

    check('der Block ist da', $block !== '', strlen($block) . ' Bytes');

    // ⚠️ ***Eine** Tabelle auf der äusseren Ebene. Verschachtelte zählt diese Zusage mit, darum wird
    // gegen die Zahl der Datensätze geprüft und nicht gegen 1: ein Satz mit einem zusammengesetzten Feld
    // trägt seine eigene Tabelle in einer Zelle, und das ist richtig so.*
    check(
        'es sind nicht mehr n Tabellen',
        substr_count($block, '<table class="taxmod-table"') < $saetze,
        substr_count($block, '<table class="taxmod-table"') . ' Tabellen bei ' . $saetze . ' Saetzen'
    );

    check(
        'je Satz eine Zeile mit Aktionszelle',
        substr_count($block, 'taxmod-table-acts') === $saetze,
        substr_count($block, 'taxmod-table-acts') . ' von ' . $saetze
    );

    // ⚠️ *Und je Zeile ihr **eigenes** Formular: zwei Datensätze sind zwei Dinge, und ein Speichern darf
    // nicht beide schreiben. Ein `<tr>` kann kein `<form>` umschliessen, also nennt es die Zeile.*
    check(
        'je Satz ein eigenes Formular',
        substr_count($block, 'id="taxmod-record-') === $saetze,
        substr_count($block, 'id="taxmod-record-') . ' von ' . $saetze
    );

    // ⚠️ **Drei, und die Zahl hat sich zweimal bewegt — beide Male sichtbar** (`PR-9`). *Mit
    // [D-653](../../docs/NewConcept/90-decision-log.md) kam die Satzart hinzu (drei → vier): sie wird
    // beim Anlegen **gewaehlt**, und was gewaehlt wird, muss man wiedersehen koennen. **Am 2026-09-06
    // faellt «Belongs to» wieder heraus** (vier → drei), auf sein Wort: *«die Spalte belongs to kann
    // weg»* — *der Knoten, der den Satz haelt, steht auf derselben Seite ohnehin schon.*
    //
    // ⚠️ *Was bleibt: `Record`, `Kind`, `Version`. **`Kind` ist seit heute keine Anzeige mehr,
    // sondern ein Waehler** — dass er zeichnet und speichert, misst `renderer-choice-mask-check`
    // ueber die Maske; hier zaehlt nur, dass die Vorspalte eine ist.*
    check(
        'drei Vorspalten je Zeile',
        substr_count($block, 'taxmod-table-lead') === $saetze * 3,
        substr_count($block, 'taxmod-table-lead') . ' bei ' . ($saetze * 3) . ' erwarteten'
    );

    // ⚠️ *Und die Spalte ist wirklich fort und nicht bloss leer — eine Ueberschrift ohne Inhalt
    // saehe in der Zaehlung oben genauso aus wie keine.*
    check(
        'und «Belongs to» steht nicht mehr darin',
        ! str_contains($block, 'Belongs to'),
        'die Ueberschrift steht noch im Markup'
    );

    // ⚠️ **Und keine Einstellungsspalte.** *Gemessen: null Werte an Einstellungskanten in
    // Benutzer-Datensätzen, 148 in `default`-Sätzen. Eine Einstellungsspalte im Datensatz-Block war das
    // Angebot, eine Einstellung an die falsche Stelle zu schreiben.*
    // ⚠️ *Hier stand ein `LIKE` auf `nodes.path` gegen den ersten Abschnitt desselben Pfades — es war
    // fuer jeden Knoten wahr, weil jeder Weg unter derselben Wurzel beginnt. **Die Spalte ist mit
    // Fassung 35 gefallen** (TASK-001), und die Bedingung wird ausgeschrieben, wie sie gemeint war:
    // jede benannte Einstellungskante, ob sie an diesem Knoten haengt oder anderswo.*
    $einstellungen = $wpdb->get_col($wpdb->prepare(
        'SELECT e.name FROM ' . Schema::table('relations_named') . ' e
         WHERE e.kind = %s AND e.name <> %s',
        'setting',
        ''
    ));

    if ($einstellungen === null) {
        fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
        exit(2);
    }

    if (preg_match('#<thead>.*?</thead>#s', $block, $kopf)) {
        $drin = [];

        foreach ($einstellungen as $name) {
            if (str_contains($kopf[0], '>' . $name . '<')) {
                $drin[] = $name;
            }
        }

        check(
            'keine Einstellung steht als Spalte darin',
            $drin === [],
            $drin === [] ? '' : implode(', ', $drin)
        );
    }
}

echo "\n== Erklaerungen stehen hinter dem Fragezeichen, und der Satz bleibt im Markup ==\n";

// ⚠️ **Sein Beschluss** ([D-661](../../docs/NewConcept/90-decision-log.md)): *«nett die Erklaerung,
// aber bitte dahinter mit Fragezeichen oder als Tooltip; sollte generelle Loesung sein.»*
//
// ⚠️ **Der Preis, den die Entscheidung selbst benennt, ist das, was hier wirklich geprueft wird:**
// *«der Satz muss deshalb im Markup stehen und nicht nur im Titel-Attribut, sonst verschwindet er
// fuer jeden, der nicht mit der Maus zeigt.» **Ein Fragezeichen, dessen Erklaerung nur im `title`
// steht, ist auf einem Beruehrungsbildschirm und fuer eine Vorlesehilfe keine Erklaerung** — und
// von aussen sieht es genauso aus wie eines, das beides hat. Darum wird Titel gegen Markup
// verglichen und nicht bloss gezaehlt.*

/**
 * Die Fragezeichen einer Seite: was im `title` steht und was im Markup.
 *
 * @return array{titel: list<string>, text: list<string>, huellen: int}
 */
function fragezeichen(string $html): array
{
    preg_match_all('/<span class="taxmod-hint" tabindex="0" title="([^"]*)"/', $html, $t);
    preg_match_all('/<span class="taxmod-hint-text">(.*?)<\/span>/s', $html, $x);

    $klartext = static fn (string $roh): string => trim(
        (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($roh), ENT_QUOTES))
    );

    return [
        'titel'   => array_map($klartext, $t[1]),
        'text'    => array_map($klartext, $x[1]),
        'huellen' => substr_count($html, 'class="taxmod-hint"'),
    ];
}

/** Die drei Verwaltungsbildschirme, gezeichnet wie im Browser. */
function verwaltungsseiten(): array
{
    $admin = get_users(['role' => 'administrator', 'number' => 1]);

    if ($admin === []) {
        return [];
    }

    wp_set_current_user($admin[0]->ID);

    // ⚠️ *Der Einstellungsbildschirm ruft `get_submit_button()` — eine Funktion, die erst mit den
    // Verwaltungsteilen von WordPress da ist. **`wp-load.php` laedt sie nicht**, und ohne sie faellt
    // dieser Lauf mit einem Fehler, der nach einem Fehler im Plugin aussieht und keiner ist.*
    require_once ABSPATH . 'wp-admin/includes/template.php';

    $bau    = new ReflectionClass(Plugin::class);
    $plugin = $bau->newInstanceWithoutConstructor();
    $bau->getProperty('file')->setValue($plugin, 'taxmod.php');

    $wurzel = (int) $GLOBALS['wpdb']->get_var(
        'SELECT id FROM ' . Schema::table('nodes_named') . ' ORDER BY id ASC LIMIT 1'
    );

    return [
        'Knoten'        => $wurzel === 0 ? '' : seiteVon($wurzel),
        'Einstellungen' => (new \Taxmod\WordPress\Admin\SettingsScreen())->render(),
        'Aufraeumen'    => $plugin->cleanupScreen()->render(),
    ];
}

$seiten = verwaltungsseiten();

check('die drei Verwaltungsseiten lassen sich zeichnen', count($seiten) === 3 && ! in_array('', $seiten, true));

// ⚠️ *Der Gegenfall zuerst: **ohne eine Untergrenze waere eine Seite ganz ohne Fragezeichen gruen**
// — und «keine Erklaerung mehr im Fliesstext» waere am billigsten dadurch erfuellt, dass es
// ueberhaupt keine Erklaerung mehr gibt.*
$mindestens = ['Knoten' => 3, 'Einstellungen' => 3, 'Aufraeumen' => 1];

foreach ($seiten as $name => $html) {
    if ($html === '') {
        continue;
    }

    $gefunden = fragezeichen($html);

    check(
        "«{$name}»: Fragezeichen vorhanden",
        count($gefunden['titel']) >= $mindestens[$name],
        count($gefunden['titel']) . ' von mindestens ' . $mindestens[$name]
    );

    // ⚠️ *Die Zahl der Fragezeichen und die Zahl der Erklaerungen im Markup sind dieselbe Zahl.
    // Ein Zeichen ohne Satz waere ein Versprechen ohne Inhalt.*
    check(
        "  · je Fragezeichen ein Satz im Markup",
        count($gefunden['titel']) === count($gefunden['text'])
            && $gefunden['huellen'] === count($gefunden['titel']),
        count($gefunden['titel']) . ' Zeichen, ' . count($gefunden['text']) . ' Saetze, '
            . $gefunden['huellen'] . ' Huellen'
    );

    $nurImTitel = [];
    $leer       = [];

    foreach ($gefunden['titel'] as $i => $titel) {
        $imMarkup = $gefunden['text'][$i] ?? '';

        if ($imMarkup === '') {
            $leer[] = substr($titel, 0, 40);

            continue;
        }

        if ($imMarkup !== $titel) {
            $nurImTitel[] = substr($titel, 0, 40);
        }
    }

    check("  · kein Satz ist leer", $leer === [], implode(' | ', $leer));
    check("  · keiner steht nur im title", $nurImTitel === [], implode(' | ', $nurImTitel));
}

echo "\n== Und im Fliesstext steht keine Erklaerung mehr ==\n";

// ⚠️ *Gemessen wird an der Quelle und nicht an einer gezeichneten Seite: eine `description`, die
// heute auf keinem Knoten auftaucht, ist morgen wieder da. **Was bleiben darf, ist Auskunft** —
// eine Meldung, ein Befund, eine Zahl —, und Auskunft erkennt man daran, dass etwas **eingesetzt**
// wird: ein Platzhalter oder eine Veraenderliche. Ein Satz ohne beides ist eine Erklaerung.*
$stehengeblieben = [];
$auskunft        = 0;

foreach (glob(dirname(__DIR__, 2) . '/src/WordPress/Admin/*.php') ?: [] as $datei) {
    $zeilen = file($datei, FILE_IGNORE_NEW_LINES) ?: [];

    foreach ($zeilen as $nr => $zeile) {
        // ⚠️ *Nur echtes Markup, nicht die Erwaehnung in einem Docblock.*
        if (! preg_match('/[\'"]<(?:p|span|ul|div) class="description/', $zeile)) {
            continue;
        }

        $satz = implode(' ', array_slice($zeilen, $nr, 7));

        if (preg_match('/sprintf\(|_n\(|%[ds]|\$/', $satz)) {
            ++$auskunft;

            continue;
        }

        $stehengeblieben[] = basename($datei) . ':' . ($nr + 1);
    }
}

check('keine erklaerende «description» mehr uebrig', $stehengeblieben === [], implode(', ', $stehengeblieben));

// ⚠️ *Und der Gegenfall dazu: die Auskunft ist **nicht** mitgegangen. Alles zu loeschen waere oben
// ebenfalls gruen und waere schlimmer als der Zustand vorher.*
check('und die Auskunft steht weiterhin da', $auskunft >= 4, (string) $auskunft);

// ⚠️ **`AR-2`: es sind Software-Texte.** *Ein Satz, der hart im Kode steht, ginge nicht durch die
// Uebersetzung — und das Fragezeichen waere dann ausgerechnet fuer den leer, der eine andere
// Sprache spricht.*
$hart = [];
$stellen = 0;

$quellen = array_merge(
    glob(dirname(__DIR__, 2) . '/src/WordPress/*.php') ?: [],
    glob(dirname(__DIR__, 2) . '/src/WordPress/*/*.php') ?: []
);

foreach ($quellen as $datei) {
    $zeilen = file($datei, FILE_IGNORE_NEW_LINES) ?: [];

    foreach ($zeilen as $nr => $zeile) {
        if (! str_contains($zeile, 'HintMarkup::icon(') && ! str_contains($zeile, 'HintMarkup::behind(')) {
            continue;
        }

        ++$stellen;

        // ⚠️ *Der Satz steht entweder auf derselben Zeile oder auf einer der naechsten — beides
        // zaehlt, und beides muss uebersetzt sein oder aus einer Veraenderlichen kommen.*
        $satz = implode(' ', array_slice($zeilen, $nr, 4));

        if (! preg_match('/__\(|_n\(|\$/', $satz)) {
            $hart[] = basename($datei) . ':' . ($nr + 1);
        }
    }
}

check('jedes Fragezeichen bekommt einen uebersetzten Satz', $hart === [], implode(', ', $hart));
check('und es gibt mehr als eine Stelle, die es benutzt', $stellen >= 6, (string) $stellen);

// ── Ein Fragezeichen, eine Fassung, und sie liegt im Kern (D-662) ────────────────────────────────

echo "\n== das Fragezeichen hat genau ein Zuhause, und es ist der Kern ==\n";

// ⚠️ **[D-662](../../docs/NewConcept/90-decision-log.md) verlangt dasselbe Zeichen aus einer zweiten
// Quelle** — *`help` ist **Modellinhalt**, den der Eigentuemer je Sprache schreibt, waehrend
// [D-661](../../docs/NewConcept/90-decision-log.md) Software-Texte meinte. **Dasselbe Zeichen, zwei
// Quellen**, also darf keine zweite Fassung entstehen (`CD · Prohibited`: eine Tatsache, ein Ort).*
//
// ⚠️ **Und es liegt im **Kern**, nicht mehr am Rand, weil die Renderer es rufen (`CD-1`).** *Der Kern
// darf den Rand nicht rufen; also ist das Zeichen umgezogen, statt ein zweites Mal geschrieben zu
// werden. Der Rand ruft weiterhin dieselbe Klasse — von aussen nach innen ist erlaubt.*
$fassungen = array_merge(
    glob(dirname(__DIR__, 2) . '/src/*/HintMarkup.php') ?: [],
    glob(dirname(__DIR__, 2) . '/src/*/*/HintMarkup.php') ?: []
);

check('es gibt genau eine HintMarkup-Klasse', count($fassungen) === 1, implode(', ', array_map('basename', $fassungen)));
check(
    'und sie liegt im Kern, wo die Renderer sie rufen duerfen',
    $fassungen !== [] && str_contains(str_replace('\\', '/', $fassungen[0]), '/src/Core/'),
    $fassungen === [] ? '' : str_replace('\\', '/', $fassungen[0])
);

// ⚠️ *Kein zweiter Bauplan daneben: wer `taxmod-hint` selbst zusammensetzt, hat die Klasse
// umgangen — und genau so entsteht die zweite Fassung, die D-662 ausschliesst.*
$eigenbau = [];

foreach (array_merge(
    glob(dirname(__DIR__, 2) . '/src/*/*.php') ?: [],
    glob(dirname(__DIR__, 2) . '/src/*/*/*.php') ?: []
) as $datei) {
    if (basename($datei) === 'HintMarkup.php') {
        continue;
    }

    foreach (file($datei, FILE_IGNORE_NEW_LINES) ?: [] as $nr => $zeile) {
        if (preg_match('/[\'"]<span class="taxmod-hint/', $zeile)) {
            $eigenbau[] = basename($datei) . ':' . ($nr + 1);
        }
    }
}

check('niemand baut das Fragezeichen selbst nach', $eigenbau === [], implode(', ', $eigenbau));

// ⚠️ **Die beiden Behaelter, die D-662 nennt, rufen es auch wirklich** — *das Formular je Feld, der
// kompakte waagerecht gesammelt am Ende und senkrecht je Feld.*
$form    = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Renderer/FormRenderer.php') ?: '';
$compact = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Renderer/CompactRenderer.php') ?: '';

check('das Formular setzt das Zeichen je Feld', str_contains($form, 'HintMarkup::icon('));
check('der kompakte Behaelter sammelt waagerecht', str_contains($compact, 'HintMarkup::combined('));
check('und setzt senkrecht je Feld', str_contains($compact, 'HintMarkup::icon('));

$geruest->abbauen();

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
