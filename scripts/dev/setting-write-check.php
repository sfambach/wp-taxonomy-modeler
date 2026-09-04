<?php declare(strict_types=1);
/**
 * Eine Angabe des Modells wird dort geschrieben, wo sie gelesen wird.
 *
 *     php scripts/dev/setting-write-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer hat es an der Oberfläche gesehen:** *«den Render kann ich noch nicht setzen.»*
 * *Und gemessen war es kein fehlender Renderer, sondern ein **Schreiber an der alten Stelle**: der
 * Wähler am Knoten schrieb in die `settings`-Tabelle, und {@see \Taxmod\Core\Service\ModelValues} liest
 * aus Datensätzen und **gewinnt** ({@see \Taxmod\Core\Service\Rendering::withModelValues()} verteilt das
 * Modell zuletzt). **Also blieb jede Wahl ohne Wirkung** — an 26 Knoten, die ihren Renderer im
 * Datensatz tragen, spurlos.*
 *
 * ⚠️ **Vierter Fall derselben Sache an einem Tag**: *Daten umgezogen, Schreiber stehengeblieben. Deshalb
 * heisst die Reihenfolge in diesem Projekt **Wächter, Leser, Daten** — und deshalb steht diese Prüfung
 * hier, bevor der Schirm umgestellt wird.*
 *
 * ⚠️ **Sein Zuschnitt, und er ist der richtige:** *«auch Settings sind etwas, das gerendert werden kann,
 * und die Renderer dafür existieren schon — der Unterschied: Eingabe geschieht im Modell und nicht im
 * Frontend.» Darum prüft dieser Lauf **keine neue Speicherform**, sondern einen Rundlauf durch die
 * vorhandene: schreiben, lesen, dasselbe herausbekommen.*
 *
 * ⚠️ *Der Lauf legt sich einen eigenen Knoten an und räumt ihn samt Datensätzen wieder weg, auch bei
 * einem Absturz. **Keine Prüfung darf ihren Müll liegen lassen** — sechs ältere tun es und werden
 * nachgezogen.*
 *
 * @see docs/NewConcept/02-field-and-setting.md
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

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

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

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $edges, $nodes, $framework, new SystemClock(), $settings);

/** @var list<int> Was dieser Lauf angelegt hat — Knoten und Datensätze. */
$meineKnoten  = [];
$meineSaetze  = [];

// ⚠️ *Vor der ersten Änderung angemeldet, nicht danach.*
register_shutdown_function(static function () use (&$meineKnoten, &$meineSaetze): void {
    global $wpdb;

    foreach ($meineSaetze as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values_history') . ' WHERE record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records_history') . ' WHERE id = %d', $id));
    }

    foreach ($meineKnoten as $id) {
        // ⚠️ *Auch die Datensätze, die der Lauf nicht selbst notiert hat — ein Teil entsteht innen
        // drin, und ein Teil ohne Besitzer wäre genau der Müll, den diese Prüfung nicht machen darf.*
        foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::table('records') . ' WHERE node_id = %d', $id)) ?: [] as $satzId) {
            // ⚠️ **Und die Teile mit, denn sie gehören einem **anderen** Knoten.** *Gemessen: nach den
            // Läufen dieses Abends standen **36** Teil-Sätze von `DisplayOption` ohne Besitzer da. Der
            // Aufräumer löschte nur, was `node_id = <mein Knoten>` trug — ein Teil trägt aber die Id
            // des Zielknotens. **Der Verweis verschwand, der Satz blieb.***
            foreach ($wpdb->get_col($wpdb->prepare('SELECT value_ref FROM ' . Schema::table('record_values') . ' WHERE record_id = %d AND value_ref IS NOT NULL', (int) $satzId)) ?: [] as $teilId) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', (int) $teilId));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', (int) $teilId));
            }

            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', (int) $satzId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', (int) $satzId));
        }

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relations') . ' WHERE from_id = %d OR to_id = %d', $id, $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
    }
});

echo "\n== Die Saat hat die zwei Kanten aufgeschrieben ==\n";

$aussen = $framework->settingEdgeId(SettingKey::Renderer);
$innen  = $framework->settingValueEdgeId(SettingKey::Renderer);

check('die Traegerkante steht da', $aussen !== 0, (string) $aussen);
check('die Wertkante steht da', $innen !== 0, (string) $innen);

echo "\n== Ein Renderer wird geschrieben und wieder gelesen ==\n";

// ⚠️ *Ein eigener Knoten und kein vorhandener: an einem echten Knoten wäre die Prüfung entweder
// zerstörerisch oder sie müsste einen Wert überschreiben und zurückstellen — und der Zwischenzustand
// wäre in der Datenbank sichtbar.*
// ⚠️ *Über den Editor und nicht mit rohem SQL: Ids kommen aus dem Identitätenverzeichnis
// ([D-339](../../docs/NewConcept/90-decision-log.md)), nicht aus `AUTO_INCREMENT` — ein `INSERT` von
// Hand bekam `insert_id = 0` zurück und legte nichts an.*
$modellWurzel = $framework->rootOf(\Taxmod\Core\Model\Branch::Model);
$editor       = new \Taxmod\Core\Service\ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, $log);

$knoten        = $editor->createNode('Pruefknoten Einstellung', $modellWurzel->id);
$knotenId      = $knoten->id;
$meineKnoten[] = $knotenId;

check('der Pruefknoten steht im Modell', $knoten !== null, 'nicht angelegt');

if ($knoten === null) {
    echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

    exit(1);
}

// Ein echter Renderer-Knoten als Wert — der Wert ist ein **Verweis**, nicht ein Name.
$rendererKnoten = $wpdb->get_row($wpdb->prepare(
    'SELECT k.id, k.name FROM ' . Schema::table('relations') . ' e
     INNER JOIN ' . Schema::table('nodes') . ' k ON k.id = e.to_id
     INNER JOIN ' . Schema::table('nodes') . ' v ON v.id = e.from_id
     WHERE v.name = %s AND e.kind = %s AND k.name = %s LIMIT 1',
    'Renderer',
    'inheritance',
    'spinner'
), ARRAY_A);

if ($rendererKnoten === null) {
    check('«spinner» steht als Knoten unter «Renderer»', false);
} else {
    check('«spinner» steht als Knoten unter «Renderer»', true);

    $geschrieben = true;

    try {
        $data->putSettingValue($knotenId, SettingKey::Renderer, TypedValue::ofReference((int) $rendererKnoten['id']));
    } catch (NotYetStorable $e) {
        $geschrieben = false;
        check('der Renderer laesst sich schreiben', false, $e->getMessage());
    }

    if ($geschrieben) {
        check('der Renderer laesst sich schreiben', true);

        // ⚠️ **Ein frischer Leser.** *{@see ModelValues} merkt sich seine Funde je Instanz (`CD-7`) —
        // ein wiederverwendeter würde die Antwort von vorher zurückgeben und den Rundlauf grün lügen.*
        $gelesen = (new ModelValues($records, $edges, $nodes, $framework))->forNode($knoten);

        check(
            'und der Leser gibt ihn zurueck',
            ($gelesen['renderer']->value->text ?? null) === 'spinner',
            $gelesen['renderer']->value->text ?? 'nichts'
        );

        // ⚠️ *Und er liegt im **default**-Satz, nicht in einem Benutzersatz
        // ([D-026](../../docs/NewConcept/90-decision-log.md): «at model level there are no values,
        // only defaults»).*
        $arten = $wpdb->get_col($wpdb->prepare(
            'SELECT kind FROM ' . Schema::table('records') . ' WHERE node_id = %d',
            $knotenId
        )) ?: [];

        check(
            'und zwar im default-Satz',
            $arten === ['default'],
            implode(', ', $arten) ?: 'kein Satz',
        );

        // ⚠️ **Zweimal schreiben legt keinen zweiten Satz an.** *Sonst stünden am Ende zwei Antworten
        // auf eine Frage da, und der Leser nähme die erste — ein Fehler, der erst beim zweiten Ändern
        // auffällt.*
        $data->putSettingValue($knotenId, SettingKey::Renderer, TypedValue::ofReference((int) $rendererKnoten['id']));

        $wieViele = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table('records') . ' WHERE node_id = %d',
            $knotenId
        ));

        check('zweimal geschrieben, ein Satz', $wieViele === 1, (string) $wieViele);
    }
}

echo "\n== Und derselbe Weg ueber die Seite, wie ein Mensch ihn geht ==\n";

// ⚠️ **Das ist die Zusage, die zaehlt.** *Der Eigentümer: «gut, ich sehe die Settings, kann sie aber
// nicht einstellen» — und danach, als ich daneben einen eigenen Wähler baute: «das ist genau dafür da,
// und das ist glaube ich das, was du am Konzept vorbei machst». **Also prüft dieser Abschnitt den Weg,
// den das Konzept nennt**: die Wertspalte des Einstellungsblocks, ein Speichern für die Seite.*
//
// ⚠️ *Es wird wirklich abgeschickt — `handlePost()` mit Nonce und Fähigkeit, kein Umweg um die
// Prüfungen des Randes (`CD-5`). Die Weiterleitung am Ende wird abgefangen, sonst endete der Lauf hier.*
$rendererId = $rendererKnoten === null ? 0 : (int) $rendererKnoten['id'];

if ($rendererId === 0 || $aussen === 0 || $innen === 0) {
    check('die Zutaten fuer den Seitenweg stehen bereit', false, "renderer={$rendererId} aussen={$aussen} innen={$innen}");
} else {
    check('die Zutaten fuer den Seitenweg stehen bereit', true);

    $verwalter = get_users(['role' => 'administrator', 'number' => 1]);

    if ($verwalter === []) {
        check('ein Administrator ist da', false);
    } else {
        wp_set_current_user($verwalter[0]->ID);

        add_filter('wp_redirect', static function ($ziel) {
            throw new RuntimeException('__weitergeleitet__' . (string) $ziel);
        }, 10, 1);

        $_POST = [
            'action'        => 'taxmod_node',
            'id'            => (string) $knotenId,
            'do'            => 'put_setting',
            '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $knotenId),
            // Genau die Adresse, die die Wertspalte zeichnet.
            'taxmod_value'  => [(string) $aussen => [(string) $innen => (string) $rendererId]],
        ];
        $_REQUEST = $_POST;

        $meldung = '';

        try {
            $bau    = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
            $plugin = $bau->newInstanceWithoutConstructor();
            $bau->getProperty('file')->setValue($plugin, 'taxmod.php');
            $plugin->screen()->handlePost();
        } catch (RuntimeException $e) {
            $meldung = str_starts_with($e->getMessage(), '__weitergeleitet__')
                ? urldecode((string) preg_replace('/^.*taxmod_message=/', '', $e->getMessage()))
                : $e->getMessage();
        }

        check('der Akt laeuft durch', $meldung === 'ok', $meldung);

        $nachher = (new ModelValues($records, $edges, $nodes, $framework))->forNode($knoten);

        check(
            'und der Renderer steht danach im Modell',
            ($nachher['renderer']->value->text ?? null) === 'spinner',
            $nachher['renderer']->value->text ?? 'nichts'
        );

        // ⚠️ **Der Gegenfall: der Teildatensatz ist entstanden, obwohl vorher keiner da war.** *Die
        // Multiplizität von `Display Option` ist `1..*` — der Eigentümer hat darauf bestanden. Angelegt
        // wird er beim **Speichern**, nicht beim Ansehen: eine Seite zu zeichnen darf nichts schreiben.*
        $teile = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table('records') . ' r
             INNER JOIN ' . Schema::table('record_values') . ' v ON v.value_ref = r.id
             WHERE v.path = %s',
            (string) $aussen
        ));

        check('ein Teildatensatz ist dabei entstanden', $teile >= 1, (string) $teile);
    }
}

echo "\n== Die Wertspalte zeigt, was gespeichert ist ==\n";

// ⚠️ **Er hat den Mangel gefunden, bevor ich ihn zugab:** *«auch bezweifle ich, dass dies Datensätze
// sind, die wir hier sehen — bitte überrasch mich, dass es doch so ist.» **Gemessen hatte er recht:**
// gezeichnet wurden die **Kanten** des Teils, kein einziger seiner Kanten-Datensätze wurde gelesen. Im
// Teil von `Passiv` stand `render = form`, der Auswahlkasten zeigte nichts.*
//
// ⚠️ **Und die Adresse ist seine Lehre:** *zweimal «arbeitest auf einmal mit Pfaden anstatt mit den Ids,
// die wir haben», und als es dastand: «siehst du, Satz-Id». Ein Teil wird über
// `taxmod_part[<Satz-Id>][<Kanten-Id>]` angesprochen — eindeutig auch bei mehreren Teilen
// ([D-548](../../docs/NewConcept/90-decision-log.md)).*
$verwalter = get_users(['role' => 'administrator', 'number' => 1]);

if ($verwalter === []) {
    check('ein Administrator ist da', false);
} else {
    wp_set_current_user($verwalter[0]->ID);

    $passiv = (int) $wpdb->get_var(
        'SELECT id FROM ' . Schema::table('nodes') . " WHERE name = 'Passiv' LIMIT 1"
    );

    $gilt = $passiv === 0
        ? null
        : (new ModelValues($records, $edges, $nodes, $framework))->forNode($nodes->byId($passiv))['renderer']->value->text ?? null;

    check('«Passiv» traegt einen Renderer im Datensatz', $gilt !== null, (string) ($gilt ?? 'nichts'));

    // ⚠️ *Das $_POST des Aktes von oben steht noch da und wuerde die Zeichnung stoeren — eine
    // Seite ansehen ist kein Akt.*
    $_POST    = [];
    $_REQUEST = [];
    $_GET['taxmod_node'] = (string) $passiv;
    $_GET['page']        = 'taxmod-nodes';

    $bau    = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
    $plugin = $bau->newInstanceWithoutConstructor();
    $bau->getProperty('file')->setValue($plugin, 'taxmod.php');
    $html = (string) $plugin->screen()->render();

    // ⚠️ *Die Adresse muss die Satz-Id tragen — sonst könnte bei mehreren Teilen niemand sagen, welcher
    // gemeint ist.*
    check(
        'die Bedienung wird ueber die Satz-Id angesprochen',
        preg_match('/name="' . Rendering::PART_FIELD . '\[\d+\]\[\d+\]"/', $html) === 1,
        'keine Teil-Adresse im Formular'
    );

    // ⚠️ **Der Kern der Zusage: sie zeigt den gespeicherten Wert.** *Vorher stand dort «nichts», und ein
    // Speichern hätte einen Wert überschrieben, den niemand gesehen hat.*
    $zeigt = null;

    if (preg_match_all('/<select[^>]*name="' . Rendering::PART_FIELD . '\[\d+\]\[\d+\]"[^>]*>(.*?)<\/select>/s', $html, $treffer)) {
        foreach ($treffer[1] as $inhalt) {
            if (preg_match('/<option value="[^"]*"\s+selected>([^<]+)<\/option>/', $inhalt, $gewaehlt)) {
                $zeigt = trim($gewaehlt[1]);

                break;
            }
        }
    }

    check('und sie zeigt den gespeicherten Renderer', $zeigt === $gilt, (string) ($zeigt ?? 'nichts') . ' gegen ' . (string) ($gilt ?? 'nichts'));

    // ⚠️ **Jeder gespeicherte Renderer zeigt in den Renderer-Ast — und diese Zusage steht hier, weil ich
    // sie am eigenen Fehler gelernt habe.**
    //
    // ⚠️ *Ich wollte `Passiv` nach einem Test auf `form` zurückstellen und suchte den Knoten nach
    // **Namen** mit `LIMIT 1`. **Es gibt zwei namens `form`**: die Label-Rolle `#733` und den Renderer
    // `#43511` ([D-022](../../docs/NewConcept/90-decision-log.md): Knotennamen sind absichtlich nicht
    // eindeutig). Geschrieben wurde die Rolle. Der Leser meldete weiter «form», weil er den **Namen**
    // zurückgibt — und der Auswahlkasten zeigte nichts, weil `#733` nicht unter seinen Möglichkeiten
    // ist. **Ein Wert, der richtig heisst und falsch zeigt, ist schlimmer als ein leerer.***
    //
    // ⚠️ *Gemessen danach: 59 Werte, alle richtig; meiner war der einzige falsche.*
    $rendererPfad = (string) $wpdb->get_var(
        'SELECT path FROM ' . Schema::table('nodes') . " WHERE name = 'Renderer' LIMIT 1"
    );

    // WICHTIG: Zwei Formen sind erlaubt, und das ist eine sichtbare Aenderung dieser Zusage
    // (PR-9). Seit D-583 legt die Wahl eines Renderers einen *Datensatz* an -- value_ref zeigt
    // dann auf einen Datensatz, dessen node_id den Renderer nennt (D-584). Der alte Knotenverweis
    // bleibt gueltig, solange die vorhandenen Daten ihn tragen; er faellt mit TASK-024.
    // Falsch ist nur, was ueber *keinen* der beiden Wege im Renderer-Ast landet.
    $daneben = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('record_values') . ' w
         LEFT JOIN ' . Schema::table('nodes') . ' k ON k.id = w.value_ref
         LEFT JOIN ' . Schema::table('records') . ' r ON r.id = w.value_ref
         LEFT JOIN ' . Schema::table('nodes') . ' rk ON rk.id = r.node_id
         WHERE w.edge_id = %d AND w.value_ref IS NOT NULL
           AND COALESCE(k.path, %s) NOT LIKE %s
           AND COALESCE(rk.path, %s) NOT LIKE %s',
        $innen,
        '',
        $wpdb->esc_like($rendererPfad . '.') . '%',
        '',
        $wpdb->esc_like($rendererPfad . '.') . '%'
    ));

    $gesamt = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('record_values') . ' WHERE edge_id = %d AND value_ref IS NOT NULL',
        $innen
    ));

    check('jeder gespeicherte Renderer zeigt in den Renderer-Ast', $daneben === 0, "{$daneben} von {$gesamt} daneben");

    // ⚠️ *Der Gegenfall: es gibt überhaupt gespeicherte Renderer.*
    check('und es gibt gespeicherte Renderer', $gesamt > 20, (string) $gesamt);
}

echo "\n== «Nichts» ist eine Wahl, und sie loescht ==\n";

// ⚠️ **Auf seinen Befund vom 2026-08-31:** *«wenn ich `0..1` wähle, müsste ich auch nichts im Value
// wählen können — kann ich auch auswählen, wird aber nicht speichern, müsste eigentlich den Datensatz
// dahinter löschen.»*
//
// ⚠️ **Gemessen war es ein Rücksprung am Rand:** *`putOneSettingValue()` kehrte bei einem leeren Wert um,
// mit der Begründung «ein leeres Feld löscht nicht, sonst räumte jedes Speichern alles ab, was nicht
// gezeichnet wurde». **Was nicht gezeichnet wurde, schickt aber auch nichts** — und damit war «nichts»
// die einzige Wahl der ganzen Seite, die sich nicht speichern liess.*
//
// ⚠️ *Der Wächter läuft über denselben Weg wie der Abschnitt darüber: `handlePost()` mit Nonce, und
// derselbe Knoten, dem gerade ein Renderer gesetzt wurde. **Erst löschen, dann zurückschreiben** — ein
// Lauf, der etwas wegnimmt und nicht zurücklegt, verändert das Modell des Eigentümers.*
if ($rendererId !== 0 && $aussen !== 0 && $innen !== 0 && $verwalter !== []) {
    $lesen = static function () use ($records, $edges, $nodes, $framework, $knoten): ?string {
        $gelesen = (new ModelValues($records, $edges, $nodes, $framework))->forNode($knoten);

        return $gelesen['renderer']->value->text ?? null;
    };

    check('vorher steht ein Renderer da', $lesen() !== null, $lesen() ?? 'nichts');

    $schicken = static function (string $wert) use ($knotenId, $aussen, $innen): string {
        $_POST = [
            'action'        => 'taxmod_node',
            'id'            => (string) $knotenId,
            'do'            => 'put_setting',
            '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $knotenId),
            'taxmod_value'  => [(string) $aussen => [(string) $innen => $wert]],
        ];
        $_REQUEST = $_POST;

        try {
            $bau    = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
            $plugin = $bau->newInstanceWithoutConstructor();
            $bau->getProperty('file')->setValue($plugin, 'taxmod.php');
            $plugin->screen()->handlePost();
        } catch (RuntimeException $e) {
            return str_starts_with($e->getMessage(), '__weitergeleitet__')
                ? urldecode((string) preg_replace('/^.*taxmod_message=/', '', $e->getMessage()))
                : $e->getMessage();
        }

        return '';
    };

    check('der leere Akt laeuft durch', $schicken('') === 'ok');

    check(
        'und danach steht dort nichts mehr',
        $lesen() === null,
        $lesen() ?? 'nichts'
    );

    // ⚠️ *Zurückgelegt, sonst hinterlässt der Lauf einen Knoten ohne Renderer — und «jeder Knoten muss
    // einen Renderer haben» ist eine Regel des Eigentümers.*
    check('zurueckgeschrieben laeuft auch durch', $schicken((string) $rendererId) === 'ok');

    check(
        'und der Wert ist wieder da',
        $lesen() !== null,
        $lesen() ?? 'nichts'
    );
}

echo "\n== Eine Zeile hinzufuegen legt einen zweiten Teil an ==\n";

// ⚠️ **Auf sein Bestehen, dass die Multiplizität `1..*` ist und nicht `0..*`:** *«somit muss ich Zeilen
// hinzufügen können».* *Und der Grund ist seiner ([D-548](../../docs/NewConcept/90-decision-log.md)):
// mehrere `DisplayOption`s sind mehrere Renderer, für das Farbschema.*
//
// ⚠️ *Am eigenen Knoten dieses Laufs, nicht an einem echten — der Teil bliebe sonst stehen.*
$vorher = count($data->settingPartsOf($knotenId, [$aussen])[$aussen] ?? []);

check('der Pruefknoten hat einen Teil', $vorher === 1, (string) $vorher);

$_POST = [
    'action'        => 'taxmod_node',
    'id'            => (string) $knotenId,
    'do'            => 'add_part',
    'edge'          => (string) $aussen,
    '_taxmod_nonce' => wp_create_nonce('taxmod_node_' . $knotenId),
];
$_REQUEST = $_POST;

$meldung = '';

try {
    $bau    = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
    $plugin = $bau->newInstanceWithoutConstructor();
    $bau->getProperty('file')->setValue($plugin, 'taxmod.php');
    $plugin->screen()->handlePost();
} catch (RuntimeException $e) {
    $meldung = str_starts_with($e->getMessage(), '__weitergeleitet__')
        ? urldecode((string) preg_replace('/^.*taxmod_message=/', '', $e->getMessage()))
        : $e->getMessage();
}

check('der Akt laeuft durch', $meldung === 'ok', $meldung);

$nachher = $data->settingPartsOf($knotenId, [$aussen])[$aussen] ?? [];

check('jetzt sind es zwei Teile', count($nachher) === 2, (string) count($nachher));

// ⚠️ **Und zwei Teile sind zwei Zeilen** ([D-546](../../docs/NewConcept/90-decision-log.md)). *Ohne
// diese Zusage wäre der zweite Teil da und unsichtbar — genau der Zustand, den er vorher gefunden hat.*
$_POST    = [];
$_REQUEST = [];
$_GET['taxmod_node'] = (string) $knotenId;
$_GET['page']        = 'taxmod-nodes';

$bau2    = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin2 = $bau2->newInstanceWithoutConstructor();
$bau2->getProperty('file')->setValue($plugin2, 'taxmod.php');
$seite = (string) $plugin2->screen()->render();

$saetze = [];

if (preg_match_all('/name="' . Rendering::PART_FIELD . '\[(\d+)\]\[\d+\]"/', $seite, $treffer)) {
    $saetze = array_values(array_unique($treffer[1]));
}

check(
    'und die Tabelle zeigt beide',
    count($saetze) === 2,
    count($saetze) . ': ' . implode(', ', $saetze)
);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
