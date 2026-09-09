<?php declare(strict_types=1);
/**
 * Eine Einstellungskante nimmt keinen Benutzerwert an.
 *
 *     php scripts/dev/setting-relation-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-538](../../docs/NewConcept/90-decision-log.md): «nicht speichernd» und «ist eine
 * Einstellung» sind dieselbe Aussage.** *Der Eigentümer hat es hergeleitet: «für den Benutzer werden
 * ja nur die **Felder** gespeichert, nicht die Settings, weil die Settings Eigenschaften des Modells
 * sind.» **Zwei Angaben, die nie widersprechen können, sind eine** — und der Schlüssel `persistent`
 * fällt zugunsten der Relationsart.*
 *
 * ⚠️ **Diese Prüfung hält fest, was dabei gleich bleiben muss**, *und sie ist vor der Umstellung
 * geschrieben: der Exponent der Präfixe verweigert einen Benutzerwert — heute über eine Setting-Zeile,
 * danach über die Art seiner Kante. **Ändert sich das Verhalten, wird sie rot; ändert sich nur der
 * Weg, bleibt sie grün.***
 *
 * ⚠️ *Und der Gegenfall gehört dazu: ein **gewöhnliches** Feld nimmt seinen Wert an. Eine Prüfung, die
 * nur Verweigerungen kennt, wäre auch grün, wenn gar nichts mehr ginge.*
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

require __DIR__ . '/geruest.php';

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Renderer\FieldRowRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

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

$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $relations, $nodes, $framework, new SystemClock());
$geruest   = new Geruest('__se');

/** Ein Feld eines Knotens über seinen Namen. */
function feldVon(string $knotenName, string $feldName): ?\Taxmod\Core\Model\Relation
{
    global $wpdb, $nodes, $relations;

    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes_named') . ' WHERE name = %s LIMIT 1',
        $knotenName
    ));

    $knoten = $id === 0 ? null : $nodes->find($id);

    if ($knoten === null) {
        return null;
    }

    foreach ($relations->fieldRelationsOf([...$knoten->ancestorIds(), $knoten->id]) as $eine) {
        if ($eine->name === $feldName) {
            return $eine;
        }
    }

    return null;
}

/**
 * Der erste Knoten unter einem gegebenen — über die Id gefunden, nie über einen Namen.
 *
 * ⚠️ *Das ist die Ersetzung für ein halbes Dutzend `WHERE name = …` in dieser Datei
 * ([D-613](../../docs/NewConcept/90-decision-log.md)). Wessen Kinder gemeint sind, sagt die Id des
 * Elternteils; **wie sie heissen, geht die Prüfung nichts an.***
 */
function ersterUnter(int $elternId): int
{
    global $wpdb, $nodes;

    $eltern = $nodes->find($elternId);

    if ($eltern === null) {
        return 0;
    }

    // ⚠️ *Seit Fassung 35 fragt der Speicher, was unter einem Knoten haengt (TASK-001) — der Weg ist
    // keine Spalte mehr, und ein `LIKE` darauf liefe still leer.*
    $unten = array_values(array_diff($nodes->subtreeIds($eltern->id), [$eltern->id]));

    sort($unten);

    return $unten[0] ?? 0;
}

/** @var list<int> Was dieser Lauf angelegt hat. */
$meine = [];

register_shutdown_function(static function () use (&$meine): void {
    global $wpdb;

    foreach ($meine as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records_history') . ' WHERE node_record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records_history') . ' WHERE id = %d', $id));
    }
});

echo "\n== Der Exponent nimmt keinen Benutzerwert an ==\n";

$exponent = feldVon('Prefixes', 'exponent');

if ($exponent === null) {
    check('das Feld «Prefixes.exponent» steht im Modell', false, 'nicht gefunden');
} else {
    check('das Feld «Prefixes.exponent» steht im Modell', true);

    // ⚠️ **Nicht «kilo», sondern «irgendein Träger dieses Feldes»**
    // ([D-613](../../docs/NewConcept/90-decision-log.md), vollzieht [D-022](../../docs/NewConcept/90-decision-log.md)).
    // *`kilo` ist sein Inhalt und darf sich jederzeit ändern; **`Prefixes` ist Rahmenwerk** und
    // steht hier als Besitzer der Kante, nicht als gesuchter Name. Gefragt wird der Baum: welcher
    // Knoten erbt dieses Feld? Der erste, den es gibt, beantwortet die Frage genauso gut.*
    $traegerId = ersterUnter($exponent->fromNodeId);

    if ($traegerId === 0) {
        check('ein Knoten erbt «Prefixes.exponent»', false);
    } else {
        check('ein Knoten erbt «Prefixes.exponent»', true, '#' . $traegerId);

        $satz    = $data->create($traegerId, RecordType::User);
        $meine[] = $satz->id;

        $verweigert = false;

        try {
            $data->put($satz->id, $exponent->id, TypedValue::ofInt(99));
        } catch (NotYetStorable) {
            $verweigert = true;
        }

        check('ein Benutzerwert am Exponenten wird verweigert', $verweigert);

        // ⚠️ **Der Gegenfall.** *Eine Prüfung, die nur Verweigerungen kennt, wäre auch dann grün, wenn
        // überhaupt nichts mehr gespeichert werden könnte.*
        //
        // ⚠️ *Und das gewöhnliche Feld wird **gebaut, nicht gesucht** ([D-613](../../docs/NewConcept/90-decision-log.md)):
        // hier stand `Passiv.Tolerance` — sein Modellinhalt, den er jederzeit umbenennen darf.*
        $gebaut = $geruest->feldMit('Vergleich', 'gewoehnlich', '1');
        $normal = null;

        foreach ($relations->fieldRelationsOf([$gebaut['von']]) as $eine) {
            if ($eine->id === $gebaut['kante']) {
                $normal = $eine;
            }
        }

        if ($normal === null) {
            check('ein gewoehnliches Feld zum Vergleich gefunden', false, 'das Geruest hat keines geliefert');
        } else {
            check('ein gewoehnliches Feld zum Vergleich gefunden', true);

            $satz2    = $data->create($normal->fromNodeId, RecordType::User);
            $meine[]  = $satz2->id;
            $ging     = true;

            try {
                $data->put($satz2->id, $normal->id, TypedValue::ofText('5%'));
            } catch (NotYetStorable) {
                $ging = false;
            }

            check('und es nimmt seinen Wert an', $ging);
        }
    }
}

echo "\n== Und die Vorgabe bleibt lesbar ==\n";

// ⚠️ *Verweigert heisst «kein **Benutzer**wert» und nicht «kein Wert»
// ([D-538](../../docs/NewConcept/90-decision-log.md)). Der Exponent steht als Vorgabe da und muss es
// bleiben — sonst hätte die Verweigerung zu viel weggenommen.*
//
// ⚠️ **Hier stand «`kilo` trägt seinen Exponenten 3»** ([D-613](../../docs/NewConcept/90-decision-log.md)).
// *Das war zweimal sein Inhalt: der Name **und** die Zahl. Die Zusage, um die es geht, ist keine von
// beiden, sondern: **die Vorgaben unter `Prefixes` überleben die Verweigerung.** Wie viele es sind
// und welche Zahl darin steht, entscheidet er.*
$mitVorgabe = 0;

if ($exponent !== null && ($eltern = $nodes->find($exponent->fromNodeId)) !== null) {
    // ⚠️ *Wie oben: der Ast kommt aus dem Speicher, nicht aus der gefallenen Spalte (TASK-001).*
    $kinder = array_values(array_diff($nodes->subtreeIds($eltern->id), [$eltern->id]));

    foreach ($kinder as $kindId) {
        foreach ($records->ofNode($kindId) as $satz) {
            if ($satz->recordType !== RecordType::Default) {
                continue;
            }

            foreach ($records->valuesOf($satz->id) as $wert) {
                if ($wert->relationId === $exponent->id && $wert->value->int !== null) {
                    ++$mitVorgabe;
                }
            }
        }
    }
}

check('die Exponenten stehen als Vorgabe da', $mitVorgabe > 0, (string) $mitVorgabe);

echo "\n== Die Vorschau zeigt keine Einstellungen ==\n";

// ⚠️ **Der Eigentümer hat es am Knoten `Kontakt` gesehen, und ich hatte es zweimal übersehen.**
// *Dort stand «Fields: None yet» und die Vorschau zeigte trotzdem drei Zeilen — die geerbten
// Einstellungskanten der Wurzel, zwei davon als nacktes Textfeld mit `taxmod-no-renderer`, über der
// Zeile «nothing has been entered against this node yet».*
//
// ⚠️ **Seine Diagnose war die richtige:** *«du renderst die Settings, und dort solltest du eigentlich
// die Settings nicht rendern — also haben wir das im Grunde schon, es ist nur fehlgeleitet.»*
$rendering = new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    null,
    new ModelValues($records, $relations, $nodes, $framework)
);

// ⚠️ **Drei gebaute Knoten statt `Passiv`, `Dimension`, `Integer`**
// ([D-613](../../docs/NewConcept/90-decision-log.md)). *Was die Zusage braucht, ist ein Knoten, der
// die Einstellungskanten der Wurzel **erbt** — und das tut jeder, der im Modell hängt. Seine drei
// waren nur zufällig zur Hand, und zwei von ihnen sind sein Inhalt.*
$vorschauKnoten = [
    $geruest->feldMit('Vorschau eins', 'a', '1')['von'],
    $geruest->feldMit('Vorschau zwei', 'b', '1')['von'],
    $geruest->feldMit('Vorschau drei', 'c', '1')['von'],
];

foreach ($vorschauKnoten as $id) {
    $name   = '#' . $id;
    $knoten = $nodes->byId($id);
    $kanten = $relations->fieldRelationsOf([...$knoten->ancestorIds(), $knoten->id]);
    $sicht  = $rendering->previewVisibilityFor($kanten, $rendering->settingsForUseSites($kanten));

    $einstellungen = 0;

    foreach ($sicht['shown'] as $kante) {
        if ($kante->isSetting()) {
            ++$einstellungen;
        }
    }

    check(
        "«{$name}»: keine Einstellungskante in der Vorschau",
        $einstellungen === 0,
        "{$einstellungen} von " . count($sicht['shown'])
    );

    // ⚠️ *Und der Gegenfall: **echte Felder bleiben.** Eine Prüfung, die nur wegnimmt, wäre auch dann
    // grün, wenn die Vorschau gar nichts mehr zeigte.*
    $eigene = 0;

    foreach ($kanten as $kante) {
        if (! $kante->isSetting() && ! $kante->hide) {
            ++$eigene;
        }
    }

    check(
        "«{$name}»: seine {$eigene} echten Felder stehen noch da",
        count($sicht['shown']) === $eigene,
        count($sicht['shown']) . ' statt ' . $eigene
    );
}

echo "\n== Woher die Auskunft kommt ==\n";

// ⚠️ *Hier wurde gezaehlt, ob die `settings`-Tabelle noch `persistent`-Zeilen haelt. **Es gibt sie
// nicht mehr** (D-579) — geblieben ist die Frage, ob die Art der Kante es sagt.*
{
    // ⚠️ *Nach dem Umzug muss die Art der Kante es sagen — sonst wäre die Verweigerung oben aus einem
    // Grund grün, den es nicht mehr gibt.*
    check(
        'die Tabelle sagt nichts mehr, und die Kante ist eine Einstellung',
        $exponent !== null && $exponent->kind === RelationKind::Setting,
        $exponent?->kind->value ?? 'keine Kante'
    );

    // ⚠️ *Seit [D-639](../../docs/NewConcept/90-decision-log.md) ist das kein Satz ueber die
    // Aufzaehlung mehr, sondern Verhalten der Kante — und die Aussage gilt den **Datensaetzen**.*
    check(
        'und eine Einstellung nimmt ihren Datensatz mit (D-526, D-639)',
        $exponent !== null && $exponent->deletesRecordWithOwner()
    );
}

echo "\n== Die Einstellungsbereich einer Stelle fragt das Ziel, nicht den Besitzer (D-668) ==\n";

// ⚠️ **Sein Befund am 2026-09-06, mit Bild:** *«zu viel oder display size in display size?»* — an
// `Text --display_size--> display size` bot die aufgeklappte Einstellungsbereich `display_size` selbst an. Der
// Grund war, dass {@see ModelValues::declaredSettingKeys()} **zwei** Ketten fragte, die des Ziels
// und die des Besitzers; die Kante bot sich damit selbst als eigene Einstellung an
// ([D-668](../../docs/NewConcept/90-decision-log.md)).
//
// ⚠️ **Gebaut statt gesucht** ([D-613](../../docs/NewConcept/90-decision-log.md)): `display_size`
// ist sein Inhalt und darf sich ändern — die Zusage gilt der **Gestalt** «eine Einstellungskante
// zeigt auf einen Einstellungsknoten».
{
    $modell = new ModelValues($records, $relations, $nodes, $framework);

    $gebaut  = $geruest->einstellung('Einstellungsbereich Ziel', 'tafelmass');
    $eigene  = null;

    foreach ($relations->fieldRelationsOf([$gebaut['traeger']]) as $eine) {
        if ($eine->name === $gebaut['feld']) {
            $eigene = $eine;
        }
    }

    if ($eigene === null || ! $eigene->isSetting()) {
        check('die gebaute Einstellungskante steht', false, $eigene === null ? 'nicht gefunden' : 'keine Einstellung');
    } else {
        check('die gebaute Einstellungskante steht', true, '#' . $eigene->id);

        $ihre = $modell->declaredSettingKeys($eigene);

        // ⚠️ **Der Fall aus dem Bild.** *Die Kante darf sich nicht selbst anbieten.*
        check(
            'eine Einstellungskante bietet sich selbst nicht an',
            ! in_array($gebaut['feld'], $ihre, true),
            implode(', ', $ihre)
        );

        // ⚠️ **Der Gegenfall, und ohne ihn wäre die Zusage auch dann grün, wenn der Einstellungsbereich gar
        // nichts mehr anböte.** *Eine gewöhnliche Verwendungsstelle, deren **Ziel** die Einstellung
        // erklärt — die Gestalt von `Address --Country--> Text`: `Text` erklärt `display_size`,
        // also steht sie an der Stelle. Genau das darf die Verengung nicht mitnehmen.*
        $stelle = $geruest->feldMit('Einstellungsbereich Nutzer', 'zeigtauf', '1', $gebaut['traeger']);
        $nutzt  = null;

        foreach ($relations->fieldRelationsOf([$stelle['von']]) as $eine) {
            if ($eine->id === $stelle['kante']) {
                $nutzt = $eine;
            }
        }

        $seine = $nutzt === null ? [] : $modell->declaredSettingKeys($nutzt);

        check(
            'eine Stelle, deren Ziel die Einstellung erklärt, bietet sie weiter an',
            in_array($gebaut['feld'], $seine, true),
            implode(', ', $seine)
        );

        // ⚠️ **Die beiden Richtungsregeln bleiben, wie sie sind** — sie hängen nicht an der Kette:
        // *«wie oft» ist eine **Spalte** an der Kante ([D-351](../../docs/NewConcept/90-decision-log.md))
        // und käme aus dem Modell nie zurück; der Renderer gehört dem **Knoten** und nicht der
        // Verwendungsstelle ([D-643](../../docs/NewConcept/90-decision-log.md)).*
        $gezeichnet = [];

        if ($nutzt !== null) {
            foreach ($rendering->settingsFor($nutzt, []) as $eine) {
                $gezeichnet[] = $eine->key;
            }
        }

        check(
            'der Einstellungsbereich einer Kante zeigt «wie oft» (D-351)',
            in_array(SettingKey::Multiplicity->value, $gezeichnet, true),
            implode(', ', $gezeichnet)
        );

        check(
            'und keinen Renderer (D-643)',
            ! in_array(SettingKey::Renderer->value, $gezeichnet, true),
            implode(', ', $gezeichnet)
        );
    }
}

$geruest->abbauen();

// ============================================================================
// Umgezogen am 2026-09-06, weil zwei Laeufe gestrichen wurden
// ============================================================================
//
// ⚠️ **Die Zusagen hierunter standen bis zum 2026-09-06 in `setting-kind-check.php` und
// `setting-self-inherit-check.php`.** *Beide fragen dasselbe wie dieser Lauf — **was eine
// Einstellungskante ist, seit `nodes.field_type` gefallen ist** — und beide fragten es aus derselben
// Quelle. Sie gehoerten in einen Lauf; gestrichen wurde die Datei, nicht die Zusage
// ([`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md), auf sein Wort
// «checks mein ja»). `PR-9`: sichtbar umgezogen, nichts entschaerft.*

echo "\n== umgezogen: der Einstellungsast, und jeder Knoten darin ==\n";

// ⚠️ **Der Ast wird ueber seine **Rolle** geholt, nicht ueber den Namen «Settings»**
// ([D-613](../../docs/NewConcept/90-decision-log.md)). *Das war der Handgriff, der `setting-kind`
// am 2026-09-05 vom Namen geloest hat; er zieht hier unveraendert mit ein.*
$astKnoten = $framework->rootOf(Branch::Settings);
$astGeladen = $nodes->find($astKnoten->id);

// ⚠️ *Der Weg ist seit Fassung 35 keine Spalte mehr (TASK-001); «direkt unter der Wurzel» heisst
// jetzt, was es immer hiess — der Vater hat selbst keinen Vater.*
check(
    'die Astwurzel des Einstellungsastes steht direkt unter der Wurzel',
    $astGeladen !== null
        && $astGeladen->parentNodeId !== null
        && $nodes->byId($astGeladen->parentNodeId)->parentNodeId === null
);

if ($astGeladen !== null) {
    $nodesNamed     = Schema::table('nodes_named');
    $relationsTable = Schema::table('relations');

    $unten   = array_values(array_diff($nodes->subtreeIds($astGeladen->id), [$astGeladen->id]));
    $plaetze = implode(',', array_fill(0, max(1, count($unten)), '%d'));

    $astZeilen = $unten === [] ? [] : $wpdb->get_results($wpdb->prepare(
        "SELECT id, name FROM {$nodesNamed} WHERE id IN ({$plaetze}) ORDER BY id",
        ...$unten
    ), ARRAY_A);

    $wegeImAst = [];

    foreach ($nodes->byIds($unten) as $einer) {
        $wegeImAst[$einer->id] = $einer->path;
    }

    check('und traegt Knoten', $astZeilen !== [], (string) count($astZeilen));

    // ⚠️ *Eine Abfrage fuer alle zusammen (`CD-7`) — der Lauf ueber die Kanten ist gebuendelt.*
    $sorten = $nodes->resolvedFieldTypes(array_map(static fn (array $r): int => (int) $r['id'], $astZeilen));

    $tiefe   = substr_count($astGeladen->path, '.') + 2;
    $fehlend = [];
    $rest    = [];

    foreach ($astZeilen as $zeile) {
        $id   = (int) $zeile['id'];
        $sorte = ($sorten[$id] ?? null)?->value;

        if ($sorte === FieldType::Setting->value) {
            continue;
        }

        // Rest im Sinne von D-606: direktes Astkind, auf das keine Kante zeigt und das selbst keine
        // haelt. Zaehlt nicht als Fehler, wird aber genannt.
        if (substr_count($wegeImAst[$id] ?? '', '.') + 1 === $tiefe) {
            $hinein = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$relationsTable} WHERE to_node_id = %d AND kind <> 'inheritance'",
                $id
            ));
            $hinaus = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$relationsTable} WHERE from_node_id = %d",
                $id
            ));

            if ($hinein === 0 && $hinaus === 0) {
                $rest[] = $zeile['name'] . " ({$id})";

                continue;
            }
        }

        $fehlend[] = $zeile['name'] . " ({$id})"
            . ($sorte === null || $sorte === '' ? '' : ", traegt statt dessen `{$sorte}`");
    }

    check(
        'kein Knoten im Ast, den die Kante nicht als Einstellung ausweist',
        $fehlend === [],
        implode('; ', $fehlend)
    );

    if ($rest !== []) {
        printf("  HINWEIS Rest im Ast, auf den nichts zeigt: %s\n", implode('; ', $rest));
    }
}

// ⚠️ **Zwei Zusagen, und keine genuegt allein** ([D-607](../../docs/NewConcept/90-decision-log.md),
// [D-608](../../docs/NewConcept/90-decision-log.md)). *Die Sperre allein liesse den Knoten ohne
// Antwort auf «warum hat `read_only` kein `read_only`»; die Anzeige allein waere eine Sperre, die
// nichts sperrt.*
//
// ⚠️ **Der Gegenfall traegt hier mehr als die Zusage selbst.** *Die erste Fassung der Regel («ein
// Knoten mit `kind = setting` erbt nichts», [D-605](../../docs/NewConcept/90-decision-log.md)) waere
// bei einer Pruefung, die nur das Sperren misst, **gruen** gewesen — und haette `render with label`
// den geerbten `converter` genommen. Der Eigentuemer hat es gesehen, bevor es gebaut war.*
$selbstEditor = new ModelEditor($nodes, $relations, $framework, $log);

/** @var list<int> $selbstGebaut */
$selbstGebaut = [];

$selbstAbbauen = static function () use (&$selbstGebaut, $wpdb, $records): void {
    foreach (array_reverse($selbstGebaut) as $id) {
        foreach ($records->ofNode($id) as $satz) {
            $records->forgetRecord($satz->id);
        }

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
            $id,
            $id
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
    }

    $selbstGebaut = [];
};

echo "\n== umgezogen: ein Vorfahr, seine Einstellung als Kind, ein Geschwister ==\n";

try {
    $vorfahr        = $selbstEditor->createNode('__selbsterbe Vorfahr', $framework->rootOf(Branch::Model)->id);
    $selbstGebaut[] = $vorfahr->id;

    // ⚠️ *Das Ziel liegt **unter** dem Vorfahren — genau die Lage von `Root --read_only--> read_only`.*
    $ziel           = $selbstEditor->createNode('__selbsterbe ziel', $vorfahr->id);
    $selbstGebaut[] = $ziel->id;

    // ⚠️ *Das Geschwister ist der Gegenfall: es erbt dieselbe Kante und muss sie behalten.*
    $geschwister    = $selbstEditor->createNode('__selbsterbe Geschwister', $vorfahr->id);
    $selbstGebaut[] = $geschwister->id;

    $selbstKante = $selbstEditor->addField($vorfahr->id, $ziel->id, '__selbsterbe_feld');
    $selbstEditor->markAsSetting($vorfahr->id, $selbstKante->id, true);

    // ⚠️ **Ohne Wert misst die Kette gar nichts.** *Eine Einstellung ohne Wert fehlt in beiden
    // Faellen, und die Pruefung waere gruen, weil nichts da ist — nicht, weil etwas greift.*
    $data->putSettingAt($vorfahr->id, $selbstKante->id, 0, TypedValue::ofText('__selbsterbe_wert'));

    check('der Aufbau steht', true);

    echo "\n== umgezogen: die Sperre (D-607) ==\n";

    $amZiel = (new ModelValues($records, $relations, $nodes, $framework))->forNode($nodes->byId($ziel->id));

    check(
        'der Zielknoten erbt seine eigene Einstellungskante nicht',
        ! isset($amZiel['__selbsterbe_feld']),
        'sie steht trotzdem da'
    );

    echo "\n== umgezogen: der Gegenfall — ohne ihn waere die Sperre auch gruen, wenn nichts mehr erbt ==\n";

    $amGeschwister = (new ModelValues($records, $relations, $nodes, $framework))->forNode($nodes->byId($geschwister->id));

    // ⚠️ **Ein Geschwister des Ziels erbt sie seit [D-686](../../docs/NewConcept/90-decision-log.md)
    // ebenfalls nicht.** *Sein Wort: «ich würde das gerne erweitern auf sich selbst und alle seine
    // geschwister». **Gemessen an seinem Befund «min kann ich nicht auf 0 stellen»:** `min`, `max`
    // und `Schrittweite` liegen als Spezialisierungen unter `Integer`, also erbte `min` dessen
    // `step = 50` — und das Feld, in dem er `min` einstellt, sprang in Fünfzigerschritten.*
    check(
        'ein Geschwister des Ziels erbt sie ebenfalls nicht',
        ! isset($amGeschwister['__selbsterbe_feld']),
        'sie steht trotzdem da'
    );

    // ⚠️ **Der Gegenfall, damit die Sperre nicht alles nimmt** — *das war der Sinn dieser Zeile,
    // und er bleibt: ein Knoten, der weder das Ziel noch sein Geschwister ist, erbt weiter.
    // **Ohne ihn wäre [D-605](../../docs/NewConcept/90-decision-log.md) durch die Hintertür
    // zurück** — «markierte Knoten erben nichts», zu breit und zurückgenommen.*
    $fremder        = $selbstEditor->createNode('__selbsterbe Fremder', $ziel->id);
    $selbstGebaut[] = $fremder->id;

    $amFremden = (new ModelValues($records, $relations, $nodes, $framework))->forNode($nodes->byId($fremder->id));

    check(
        'ein Knoten ausserhalb der Geschwisterreihe erbt sie weiterhin',
        isset($amFremden['__selbsterbe_feld']),
        'die Vererbung ist mit gesperrt worden — das ist die zurueckgenommene Fassung D-605'
    );

    $amVorfahr = (new ModelValues($records, $relations, $nodes, $framework))->forNode($nodes->byId($vorfahr->id));

    check(
        'der erklaerende Vorfahr behaelt seine Angabe',
        isset($amVorfahr['__selbsterbe_feld']),
        'auch der Erklaerer hat sie verloren'
    );

    echo "\n== umgezogen: die Regel selbst, an ihrer einen Stelle ==\n";

    $gelesen = $relations->byId($selbstKante->id);

    check('sie greift am Ziel', ModelValues::inheritanceBlocked($gelesen, $ziel->id));
    check('sie greift nicht am Geschwister', ! ModelValues::inheritanceBlocked($gelesen, $geschwister->id));
    // ⚠️ *Es geht ums **Erben**, nicht ums Haben: eine Kante, die jemand absichtlich von einem
    // Knoten auf sich selbst legt, bleibt erlaubt ([D-608](../../docs/NewConcept/90-decision-log.md)).*
    check('sie greift nicht am Erklaerer selbst', ! ModelValues::inheritanceBlocked($gelesen, $vorfahr->id));

    echo "\n== umgezogen: die Anzeige (D-608) — die Zeile bleibt und sagt, dass sie gesperrt ist ==\n";

    $zeilenRenderer = new FieldRowRenderer();

    $gesperrt = $zeilenRenderer->render($gelesen, new RenderContext(
        purpose: Purpose::Edit,
        value: TypedValue::nothing(),
        editable: false,
        surroundings: new Surroundings(
            refersTo: '__selbsterbe ziel',
            sections: [FieldRowRenderer::VALUE => new Section('', '<input name="x">')],
            locked: true
        ),
    ))->markup;

    $offen = $zeilenRenderer->render($gelesen, new RenderContext(
        purpose: Purpose::Edit,
        value: TypedValue::nothing(),
        editable: false,
        surroundings: new Surroundings(
            refersTo: '__selbsterbe ziel',
            sections: [FieldRowRenderer::VALUE => new Section('', '<input name="x">')],
            locked: false
        ),
    ))->markup;

    check('die gesperrte Zeile wird ueberhaupt gezeichnet', str_contains($gesperrt, '<tr'));
    check('sie ist als gesperrt gekennzeichnet', str_contains($gesperrt, 'taxmod-field-locked'));
    check('«gesperrt» steht sichtbar in der Zeile', str_contains($gesperrt, '>locked<') || str_contains($gesperrt, 'taxmod-locked'));
    check('ein Hinweistext nennt den Grund', str_contains($gesperrt, 'title="'));
    check('sie traegt kein Eingabefeld mehr', ! str_contains($gesperrt, '<input name="x">'));
    check('eine ungesperrte Zeile traegt ihres weiterhin', str_contains($offen, '<input name="x">'));
    check('und sie ist nicht gekennzeichnet', ! str_contains($offen, 'taxmod-field-locked'));
} finally {
    $selbstAbbauen();
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
