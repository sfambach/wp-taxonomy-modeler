<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Exception\CannotUndoAct;
use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Service\Labels;
use Taxmod\WordPress\Admin\SettingsScreen;

/**
 * Setzt eine Zeile auf ihren vorigen Stand zurück — aus dem Schatten, vorwärts geschrieben.
 *
 * ⚠️ **[D-536](../../../docs/NewConcept/90-decision-log.md), und der ganze Grund für die
 * Schattentabellen:** *der Eigentümer: «wenn ich zurück will, dann drehe ich einfach die aktuelle
 * Version und habe die Vorgängerversion wieder verfügbar».*
 *
 * ⚠️ **Vorwärts und nicht zurückgespult** ([D-172](../../../docs/NewConcept/90-decision-log.md)):
 * *ein Zurücksetzen ist **ein neuer Schreibvorgang**, der den alten Inhalt wiederherstellt und dabei
 * die Version weiterzählt. Die Geschichte wird nie kürzer — **auch das Zurücksetzen selbst steht
 * hinterher als Version da**, und ein zweites Zurücksetzen kehrt es um.*
 *
 * ```mermaid
 * flowchart LR
 *   L["lebend v3"] --> A["v3 in den Schatten"]
 *   A --> F["v2 aus dem Schatten holen"]
 *   F --> W["v2s Inhalt lebend schreiben, als v4"]
 * ```
 *
 * ⚠️ *Ein gelöschter Stand ist kein Sonderfall: steht lebend nichts mehr, ist der oberste
 * Schattenstand der letzte gelebte, und er wird wieder eingefügt.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class Restore
{
    /**
     * Den Stand vor dem aktuellen wiederherstellen.
     *
     * @return bool `false`, wenn es nichts wiederherzustellen gibt — **kein Fehler**, sondern die
     *              ehrliche Antwort auf «diese Zeile hatte nie einen Vorgänger».
     */
    public static function previous(string $liveTable, int $id): bool
    {
        TileCounts::forget(TileCounts::MODEL);
        // D-1514: an undo may take «Vertraulich» back or bring it back; the remembered edges are read again.
        \Taxmod\WordPress\Privacy\FieldSeal::forget();

        global $wpdb;

        $schatten = self::shadowFor($liveTable);

        if ($schatten === null) {
            throw new \RuntimeException("«{$liveTable}» hat keine Geschichte.");
        }

        $lebend  = Schema::table($liveTable);
        $verlauf = Schema::table($schatten);

        $jetzige = $wpdb->get_var($wpdb->prepare("SELECT version FROM {$lebend} WHERE id = %d", $id));
        $jetzige = $jetzige === null ? null : (int) $jetzige;

        // ⚠️ **Erst den gegenwärtigen Stand aufheben, dann suchen.** *Sonst wäre er nach dem
        // Zurücksetzen verloren, und ein zweites Zurücksetzen käme nicht mehr hierher zurück.*
        if ($jetzige !== null) {
            Shadow::keepOne($liveTable, $id);
        }

        // ⚠️ *Streng **unter** der lebenden Version, weil die eben selbst in den Schatten gewandert
        // ist — ohne das «kleiner als» holte man sich den Zustand, von dem man wegwill.*
        $ziel = $jetzige === null
            ? $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$verlauf} WHERE id = %d ORDER BY version DESC LIMIT 1",
                $id
            ), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$verlauf} WHERE id = %d AND version < %d ORDER BY version DESC LIMIT 1",
                $id,
                $jetzige
            ), ARRAY_A);

        if ($ziel === null) {
            return false;
        }

        $spalten = [];

        // ⚠️ *Und die benannten Nur-im-Schatten-Spalten dieser einen Tabelle*
        // ([D-619](../../../docs/NewConcept/90-decision-log.md), TASK-013): **`parked_by_group_id`
        // gibt es lebend nicht mehr**, und sie mitzuschreiben liesse das Einfügen scheitern — an einer
        // Stelle, an der der Benutzer «zurückholen» geklickt hat.
        $nurImSchatten = [...Schema::SHADOW_ONLY, ...(Schema::SHADOW_ONLY_IN[$schatten] ?? [])];

        foreach ($ziel as $name => $wert) {
            if (! in_array($name, $nurImSchatten, true)) {
                $spalten[$name] = $wert;
            }
        }

        // ⚠️ *Die neue Version zählt weiter, statt die alte Nummer zurückzuholen — sonst gäbe es
        // zweimal dieselbe Version mit verschiedenem Inhalt, und der Schatten hätte keinen
        // eindeutigen Schlüssel mehr.*
        $spalten['version'] = ($jetzige ?? (int) $ziel['version']) + 1;

        $formate = array_fill(0, count($spalten), '%s');

        if ($jetzige === null) {
            $wpdb->insert($lebend, $spalten, $formate);
        } else {
            unset($spalten['id']);
            $wpdb->update($lebend, $spalten, ['id' => $id], array_slice($formate, 1), ['%d']);
        }

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException("«{$liveTable}» liess sich nicht zurücksetzen: " . $wpdb->last_error);
        }

        self::restoreName($liveTable, $id, (string) ($ziel['name'] ?? ''));

        return true;
    }

    /**
     * Den Namen aus der Schattenzeile zurück in die Beschriftungen (TASK-019,
     * [D-580](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Der Schatten behält `name`, die lebende Zeile hat ihn nicht mehr.** *Er steht damit in der
     * Liste der Nur-im-Schatten-Spalten und würde beim Zurückholen einfach übergangen — **dann käme
     * ein rückgängig gemachtes Umbenennen ohne seinen Namen zurück**. Er wird deshalb hier
     * geschrieben, dorthin, wo er jetzt wohnt.*
     *
     * ⚠️ *Die Standardsprache, wie überall, wo `Node::$name` gemeint ist
     * ([D-387](../../../docs/NewConcept/90-decision-log.md),
     * [D-645](../../../docs/NewConcept/90-decision-log.md)). **Übersetzungen bleiben stehen**: der
     * Schatten kennt sie nicht, und was er nicht kennt, darf er nicht überschreiben.*
     */
    private static function restoreName(string $liveTable, int $id, string $name): void
    {
        if ($name === '' || ! in_array($liveTable, ['nodes', 'relations'], true)) {
            return;
        }

        (new WpdbLabelRepository())->put(new Label(
            $id,
            $liveTable === 'relations' ? IdentitySpace::Relation : IdentitySpace::Node,
            SeededRole::Name,
            Label::BASE_NUMBER,
            SettingsScreen::neutralLocale(),
            $name
        ));
    }

    /**
     * Welche lebende Tabelle hinter einer Journalart steckt.
     *
     * ⚠️ *`installation` und `gone` haben keine — die erste ist kein Ding im Modell, die zweite
     * bezeichnet eines, das es nicht mehr gibt.*
     */
    private const TABLE_OF_KIND = ['node' => 'nodes', 'relation' => 'relations'];

    /**
     * Einen ganzen Akt zurücksetzen — jede Zeile, die er angefasst hat.
     *
     * ⚠️ **Der Wächter ist der Punkt, nicht das Zurücksetzen** ([OQ-137](../../../docs/NewConcept/91-open-questions.md)):
     * *«eine Gruppe umzukehren ist richtig, **wenn seither nichts anderes geschah** — und die Frage
     * «seither» hatte keine Antwort.» **Jetzt hat sie eine:** die Journalzeile trägt die Version, die
     * sie erzeugt hat; steht lebend eine höhere, hat jemand danach geschrieben, und diese Zeile wird
     * **verweigert statt überfahren**.*
     *
     * ⚠️ **Ohne Version wird ebenfalls verweigert.** *Die 19 968 bestehenden Journalzeilen haben
     * keine, und blind zurückzusetzen hiesse, fremde Arbeit zu treffen. **Ein Akt ist erst
     * umkehrbar, wenn er beim Schreiben gesagt hat, was er erzeugt hat** — das ist die Bedingung,
     * unter der [D-534](../../../docs/NewConcept/90-decision-log.md)s Massenakt gebaut werden darf.*
     *
     * @return array{restored: list<array{table: string, id: int}>, refused: list<array{table: string, id: int, why: string}>}
     */
    public static function group(int $changeGroupId): array
    {
        TileCounts::forget(TileCounts::MODEL);
        // D-1514: an undo may take «Vertraulich» back or bring it back; the remembered edges are read again.
        \Taxmod\WordPress\Privacy\FieldSeal::forget();

        global $wpdb;

        $zeilen = $wpdb->get_results($wpdb->prepare(
            'SELECT owner_id, owner_kind, version FROM ' . Schema::table('changelog') . '
             WHERE change_group_id = %d ORDER BY id ASC',
            $changeGroupId
        ), ARRAY_A) ?: [];

        $bericht  = ['restored' => [], 'refused' => []];
        $gesehen  = [];

        foreach ($zeilen as $zeile) {
            $tabelle = self::TABLE_OF_KIND[(string) $zeile['owner_kind']] ?? null;
            $id      = (int) $zeile['owner_id'];

            if ($tabelle === null) {
                continue;
            }

            // ⚠️ *Eine Zeile, die der Akt mehrfach angefasst hat, wird **einmal** zurückgesetzt — die
            // erste Journalzeile ist die mit der ältesten Version, und ein zweiter Schritt ginge einen
            // Stand zu weit.*
            if (isset($gesehen["{$tabelle}:{$id}"])) {
                continue;
            }

            $gesehen["{$tabelle}:{$id}"] = true;

            if ($zeile['version'] === null) {
                $bericht['refused'][] = ['table' => $tabelle, 'id' => $id, 'why' => 'die Journalzeile nennt keine Version'];

                continue;
            }

            $jetzige = $wpdb->get_var($wpdb->prepare(
                'SELECT version FROM ' . Schema::table($tabelle) . ' WHERE id = %d',
                $id
            ));

            if ($jetzige !== null && (int) $jetzige > (int) $zeile['version']) {
                $bericht['refused'][] = [
                    'table' => $tabelle,
                    'id'    => $id,
                    'why'   => "seither wurde geschrieben: lebend {$jetzige}, der Akt schuf {$zeile['version']}",
                ];

                continue;
            }

            if (self::previous($tabelle, $id)) {
                $bericht['restored'][] = ['table' => $tabelle, 'id' => $id];

                continue;
            }

            $bericht['refused'][] = ['table' => $tabelle, 'id' => $id, 'why' => 'es gibt keinen Vorgänger'];
        }

        return $bericht;
    }

    /**
     * Die Tabellen in der Reihenfolge, in der ein Undo zurückschreibt; gelöscht wird rückwärts.
     *
     * ⚠️ *Erst der Knoten, dann die Kante, die auf ihn zeigt, dann die Einstellung an der Kante — Fremdschlüssel lassen keine
     * andere Reihenfolge zu.*
     */
    private const UNDO_ORDER = ['nodes', 'relations', 'settings_object', 'settings_value', 'node_records', 'relation_records'];

    /** Was ein Satz-Schreiber zurücknehmen darf, ohne das Modell ändern zu dürfen (D-936). */
    private const RECORD_TABLES = ['node_records', 'relation_records'];

    /** Die Journalverben einer Beschriftung — sie hat keinen Schatten, ihr Vorher steht im Journal ({@see Labels}). */
    private const LABEL_VERBS = ['label set', 'label cleared'];

    /** Umbenennen schreibt den Namen am Dienst vorbei in die Beschriftung; sein Vorher steht im Journal unter `name`. */
    private const NAME_VERBS = ['renamed' => 'nodes', 'attribute renamed' => 'relations'];

    /**
     * Einen Akt über seine Änderungsnummer zurücknehmen — als neuer Akt, vorwärts geschrieben.
     *
     * ⚠️ **[D-172](../../../docs/NewConcept/90-decision-log.md), gebaut mit [D-1410](../../../docs/NewConcept/90-decision-log.md):**
     * *jede Zeile, die der Akt angelegt hat, wird entfernt; jede, die er abgelegt hat (geändert oder gelöscht), bekommt ihren
     * Stand von vorher zurück; jede Beschriftung ihren Text aus dem Journal. **Das Undo ist selbst ein Akt mit eigener Nummer** —
     * ein Undo des Undo stellt den Stand wieder her.*
     *
     * ⚠️ **Ganz oder gar nicht.** *Hat ein anderer Akt eine der Zeilen seither geschrieben, wird nichts geschrieben und der andere
     * Akt genannt ({@see CannotUndoAct::writtenSince()}). Kein Teil-Undo ohne Meldung.*
     *
     * ```mermaid
     * flowchart LR
     *   A["Akt N"] --> S["Schatten mit archived_by = N: Vorher"]
     *   A --> C["Zeilen mit created_by = N: neu"]
     *   A --> L["Journal 'label set': Vorher-Text"]
     *   S & C & L --> W{"seither ein anderer Akt?"}
     *   W -- ja --> X["verweigern, Akt nennen"]
     *   W -- nein --> U["Akt U: Vorher schreiben, Neues entfernen"]
     * ```
     *
     * @return array{act: int, undone_by: int, restored: int, removed: int, labels: int}
     */
    public static function act(int $act, Changelog $changelog, bool $recordsOnly = false): array
    {
        // D-1514: an undo may take «Vertraulich» back or bring it back; the remembered edges are read again.
        \Taxmod\WordPress\Privacy\FieldSeal::forget();
        global $wpdb;

        $journal = Schema::table('changelog');

        if ((int) Query::value('Akt suchen', $wpdb->prepare("SELECT COUNT(*) FROM {$journal} WHERE change_group_id = %d", $act)) === 0) {
            throw CannotUndoAct::unknown($act);
        }

        if ($changelog->currentAct() === $act) {
            throw CannotUndoAct::stillOpen($act);
        }

        $plan    = [];
        $spaeter = [];

        foreach (self::UNDO_ORDER as $tabelle) {
            $plan[$tabelle] = self::planFor($tabelle, $act, $spaeter);
        }

        $beschriftungen = self::labelPlan($act, $spaeter);
        $namen          = self::namePlan($act, $spaeter);

        $spaeter = self::withoutUndonePairs(array_values(array_unique($spaeter)));

        if ($spaeter !== []) {
            throw CannotUndoAct::writtenSince($act, $spaeter);
        }

        $beruehrt = array_filter($plan, static fn (array $teil): bool => $teil['restore'] !== [] || $teil['remove'] !== []);

        if ($beruehrt === [] && $beschriftungen === [] && $namen === []) {
            throw CannotUndoAct::nothingToTakeBack($act);
        }

        if ($recordsOnly && ($beschriftungen !== [] || $namen !== [] || array_diff(array_keys($beruehrt), self::RECORD_TABLES) !== [])) {
            throw CannotUndoAct::touchesTheModel($act);
        }

        TileCounts::forget(TileCounts::MODEL);

        $bericht = ['act' => $act, 'undone_by' => 0, 'restored' => 0, 'removed' => 0, 'labels' => 0];

        // ⚠️ *Keine eigene Transaktion: der Aufrufer schreibt sein Akt ganz oder gar nicht ({@see Transaction::whole()}), und ein
        // zweites `START TRANSACTION` darin schlösse seines still ab.*
        $changelog->beginAct();

        try {
            // *Zuerst die Journalzeile: ab hier hat das Undo seine Nummer, und jede Zeile, die es schreibt, trägt sie.*
            $bericht['undone_by'] = $changelog->record($act, 'installation', 'act undone', null, (string) $act, null);

            foreach (self::UNDO_ORDER as $tabelle) {
                $bericht['restored'] += self::writeBack($tabelle, $plan[$tabelle]['restore']);
            }

            foreach (array_reverse(self::UNDO_ORDER) as $tabelle) {
                $bericht['removed'] += self::removeRows($tabelle, $plan[$tabelle]['remove']);
            }

            $bericht['labels'] = self::writeLabels($beschriftungen, $changelog) + self::writeNames($namen, $changelog);
        } finally {
            $changelog->endAct();
        }

        return $bericht;
    }

    /**
     * Die späteren Akte ohne die, die samt ihrem Undo dazwischen liegen.
     *
     * ⚠️ *Schritt für Schritt zurück ist der Normalfall: erst den jüngsten Akt, dann den davor. Ein Akt und sein Undo heben sich
     * auf — der Stand ist wieder der, den der ältere hinterlassen hat. Von hinten gelesen, damit ein zurückgenommenes Undo seinen
     * Akt wieder gelten lässt.*
     *
     * @param list<int> $spaeter
     * @return list<int>
     */
    private static function withoutUndonePairs(array $spaeter): array
    {
        global $wpdb;

        $echte = array_values(array_filter($spaeter, static fn (int $a): bool => $a > 0));

        if ($echte === []) {
            return $spaeter;
        }

        $plaetze = implode(',', array_fill(0, count($echte), '%d'));
        $nimmt   = [];

        foreach (Query::rows('Undo-Akte lesen', $wpdb->prepare(
            'SELECT change_group_id, owner_id FROM ' . Schema::table('changelog') . "
             WHERE what = 'act undone' AND change_group_id IN ({$plaetze})",
            ...$echte
        )) as $zeile) {
            $nimmt[(int) $zeile['change_group_id']] = (int) $zeile['owner_id'];
        }

        $offen = array_fill_keys($spaeter, true);
        rsort($echte);

        foreach ($echte as $akt) {
            $ziel = $nimmt[$akt] ?? null;

            if (isset($offen[$akt]) && $ziel !== null && isset($offen[$ziel])) {
                unset($offen[$akt], $offen[$ziel]);
            }
        }

        return array_map('intval', array_keys($offen));
    }

    /**
     * Was ein Akt an einer Tabelle hinterlassen hat: die Vorher-Zeilen und die neuen Zeilen.
     *
     * @param list<int> $spaeter wird um die Akte ergänzt, die seither dieselben Zeilen geschrieben haben
     * @return array{restore: array<int, array<string, mixed>>, remove: list<int>}
     */
    private static function planFor(string $tabelle, int $act, array &$spaeter): array
    {
        global $wpdb;

        $schatten = self::shadowFor($tabelle) ?? throw new \RuntimeException("«{$tabelle}» hat keine Geschichte.");
        $lebend   = Schema::table($tabelle);
        $verlauf  = Schema::table($schatten);

        // *Je Zeile die erste Ablage des Akts: der Stand, bevor er sie anfasste.*
        $vorher = [];

        foreach (Query::rows('Vorher-Zeilen des Akts lesen', $wpdb->prepare(
            "SELECT * FROM {$verlauf} WHERE archived_by_group_id = %d ORDER BY id ASC, version ASC",
            $act
        )) as $zeile) {
            $vorher[(int) $zeile['id']] ??= $zeile;
        }

        $neu = array_map('intval', Query::column('Zeilen des Akts lesen', $wpdb->prepare(
            "SELECT id FROM {$lebend} WHERE created_by_group_id = %d",
            $act
        )));

        // ⚠️ *Und was der Akt angelegt hat und ein anderer seither geändert oder gelöscht — die Zeile lebt dann vielleicht gar nicht mehr.*
        foreach (Query::column('fremde Ablagen neuer Zeilen suchen', $wpdb->prepare(
            "SELECT DISTINCT archived_by_group_id FROM {$verlauf} WHERE created_by_group_id = %d AND archived_by_group_id <> %d",
            $act,
            $act
        )) as $anderer) {
            $spaeter[] = (int) $anderer;
        }

        $ids = array_values(array_unique([...array_keys($vorher), ...$neu]));

        if ($ids === []) {
            return ['restore' => [], 'remove' => []];
        }

        $plaetze = implode(',', array_fill(0, count($ids), '%d'));
        $leben   = [];

        foreach (Query::rows('lebende Zeilen lesen', $wpdb->prepare(
            "SELECT id, version, created_by_group_id FROM {$lebend} WHERE id IN ({$plaetze})",
            ...$ids
        )) as $zeile) {
            $leben[(int) $zeile['id']] = $zeile;
        }

        // ⚠️ *Ab welcher Version eine fremde Ablage «seither» heisst: die erste des Akts, sonst — eine nur angelegte Zeile — die
        // lebende.*
        $ab = [];

        foreach ($ids as $id) {
            $ab[$id] = isset($vorher[$id]) ? (int) $vorher[$id]['version'] : (int) ($leben[$id]['version'] ?? 0);
        }

        foreach (Query::rows('spätere Ablagen suchen', $wpdb->prepare(
            "SELECT id, version, archived_by_group_id FROM {$verlauf}
             WHERE id IN ({$plaetze}) AND (archived_by_group_id IS NULL OR archived_by_group_id <> %d)",
            ...[...$ids, $act]
        )) as $zeile) {
            if ((int) $zeile['version'] >= $ab[(int) $zeile['id']]) {
                $spaeter[] = (int) ($zeile['archived_by_group_id'] ?? 0);
            }
        }

        $plan = ['restore' => [], 'remove' => []];

        foreach ($ids as $id) {
            $lebt     = isset($leben[$id]);
            $erzeuger = $lebt ? (int) ($leben[$id]['created_by_group_id'] ?? 0) : 0;

            // *Vom Akt angelegt — oder schon vor ihm abwesend: das Vorher ist «gibt es nicht».*
            if (! isset($vorher[$id]) || (int) ($vorher[$id]['created_by_group_id'] ?? 0) === $act) {
                if ($lebt) {
                    $plan['remove'][] = $id;
                }

                continue;
            }

            // ⚠️ *Lebt die Zeile wieder, obwohl der Akt sie zuletzt gelöscht hat, hat sie ein anderer Akt zurückgeholt.*
            if ($lebt && $erzeuger !== 0 && $erzeuger !== $act && $erzeuger !== (int) ($vorher[$id]['created_by_group_id'] ?? 0)) {
                $spaeter[] = $erzeuger;

                continue;
            }

            $plan['restore'][$id] = $vorher[$id] + ['_lebend' => $lebt ? (int) $leben[$id]['version'] : null];
        }

        return $plan;
    }

    /**
     * Die Vorher-Stände zurückschreiben, vorwärts mit neuer Version.
     *
     * @param array<int, array<string, mixed>> $zeilen
     */
    private static function writeBack(string $tabelle, array $zeilen): int
    {
        global $wpdb;

        if ($zeilen === []) {
            return 0;
        }

        $lebend  = Schema::table($tabelle);
        $verlauf = Schema::table(self::shadowFor($tabelle) ?? '');
        $spalten = array_values(array_diff(Shadow::columnsOf($tabelle), ['id', 'version', 'created_by_group_id']));

        // ⚠️ **Plätze erst freiräumen** (`one_place` an Knoten und Kanten): *ein Undo von «verschieben» tauscht oft zwei Zeilen; wer
        // zuerst zurückschreibt, fände den Platz noch besetzt. Die Zwischenwerte liegen oberhalb jedes echten Platzes.*
        $freiraeumen = in_array('sort_order', $spalten, true);
        $zwischen    = 4_000_000_000;

        foreach ($zeilen as $id => $zeile) {
            if ($zeile['_lebend'] === null) {
                continue;
            }

            Shadow::keepOne($tabelle, $id);

            if ($freiraeumen) {
                $wpdb->query($wpdb->prepare("UPDATE {$lebend} SET sort_order = %d WHERE id = %d", $zwischen++, $id));
            }
        }

        foreach ($zeilen as $id => $zeile) {
            $werte = [];

            foreach ($spalten as $spalte) {
                $werte[$spalte] = $zeile[$spalte] ?? null;
            }

            if ($zeile['_lebend'] !== null) {
                $werte['version'] = $zeile['_lebend'] + 1;
                $wpdb->update($lebend, $werte, ['id' => $id]);
            } else {
                // *Gelöscht und zurückgeholt: angelegt von diesem Akt, damit sein eigenes Undo sie wieder entfernt.*
                $werte['id']                  = $id;
                $werte['version']             = (int) Query::value('höchste Version lesen', $wpdb->prepare("SELECT MAX(version) FROM {$verlauf} WHERE id = %d", $id)) + 1;
                $werte['created_by_group_id'] = ActStamp::for($tabelle, 'created_by_group_id');
                $wpdb->insert($lebend, $werte);
            }

            if ($wpdb->last_error !== '') {
                throw new \RuntimeException("«{$tabelle}» {$id} liess sich nicht zurückschreiben: " . $wpdb->last_error);
            }
        }

        return count($zeilen);
    }

    /** @param list<int> $ids */
    private static function removeRows(string $tabelle, array $ids): int
    {
        global $wpdb;

        foreach ($ids as $id) {
            Shadow::keepOne($tabelle, $id, true);
            $wpdb->delete(Schema::table($tabelle), ['id' => $id], ['%d']);

            if ($wpdb->last_error !== '') {
                throw new \RuntimeException("«{$tabelle}» {$id} liess sich nicht entfernen: " . $wpdb->last_error);
            }
        }

        return count($ids);
    }

    /**
     * Die Beschriftungen, die der Akt gesetzt oder geleert hat, mit ihrem Text von vorher.
     *
     * @param list<int> $spaeter
     * @return list<array{label: Label, before: ?string}>
     */
    private static function labelPlan(int $act, array &$spaeter): array
    {
        global $wpdb;

        $journal = Schema::table('changelog');
        $verben  = implode(',', array_fill(0, count(self::LABEL_VERBS), '%s'));
        $zeilen  = Query::rows('Beschriftungen des Akts lesen', $wpdb->prepare(
            "SELECT id, owner_id, owner_kind, before_state, after_state FROM {$journal}
             WHERE change_group_id = %d AND what IN ({$verben}) ORDER BY id ASC",
            $act,
            ...self::LABEL_VERBS
        ));

        $plan = [];

        foreach ($zeilen as $zeile) {
            $vorher  = FrozenState::parse($zeile['before_state'] === null ? null : (string) $zeile['before_state']);
            $nachher = FrozenState::parse($zeile['after_state'] === null ? null : (string) $zeile['after_state']);
            $form    = $vorher ?? $nachher;
            $raum    = IdentitySpace::tryFrom((string) $zeile['owner_kind']);
            $rolle   = SeededRole::tryFrom((string) ($form?->field('role') ?? ''));

            if ($form === null || $raum === null || $rolle === null) {
                continue;
            }

            $schluessel = implode('|', [$zeile['owner_id'], $raum->value, $rolle->value, $form->field('number'), $form->field('locale')]);

            $plan[$schluessel] ??= [
                'label'  => new Label((int) $zeile['owner_id'], $raum, $rolle, (string) $form->field('number'), (string) $form->field('locale'), (string) ($nachher?->field('text') ?? '')),
                'before' => $vorher?->field('text'),
                'after'  => $nachher?->field('text'),
                'from'   => (int) $zeile['id'],
            ];
        }

        // ⚠️ *Dieselbe Frage wie bei den Zeilen: hat seither ein anderer Akt diese Beschriftung geschrieben?*
        foreach ($plan as $eintrag) {
            $label = $eintrag['label'];

            foreach (Query::rows('spätere Beschriftungen suchen', $wpdb->prepare(
                "SELECT change_group_id, before_state, after_state FROM {$journal}
                 WHERE owner_id = %d AND owner_kind = %s AND what IN ({$verben}) AND id > %d AND change_group_id <> %d",
                $label->ownerId,
                $label->ownerKind->value,
                ...[...self::LABEL_VERBS, $eintrag['from'], $act]
            )) as $spaetere) {
                $form = FrozenState::parse((string) ($spaetere['before_state'] ?? $spaetere['after_state'] ?? ''));

                if ($form !== null && $form->field('role') === $label->role->value && $form->field('locale') === $label->locale && $form->field('number') === $label->number) {
                    $spaeter[] = (int) $spaetere['change_group_id'];
                }
            }
        }

        return array_values(array_map(static fn (array $e): array => ['label' => $e['label'], 'before' => $e['before']], $plan));
    }

    /**
     * Die Namen, die der Akt umbenannt hat, mit dem Namen von vorher.
     *
     * @param list<int> $spaeter
     * @return list<array{id: int, kind: string, what: string, before: string, after: string}>
     */
    private static function namePlan(int $act, array &$spaeter): array
    {
        global $wpdb;

        $journal = Schema::table('changelog');
        $verben  = array_keys(self::NAME_VERBS);
        $plan    = [];

        foreach (Query::rows('Umbenennungen des Akts lesen', $wpdb->prepare(
            "SELECT id, owner_id, owner_kind, what, before_state, after_state FROM {$journal}
             WHERE change_group_id = %d AND what IN (%s, %s) ORDER BY id ASC",
            $act,
            ...$verben
        )) as $zeile) {
            $plan[$zeile['what'] . '|' . $zeile['owner_id']] ??= [
                'id'     => (int) $zeile['owner_id'],
                'kind'   => (string) $zeile['owner_kind'],
                'what'   => (string) $zeile['what'],
                'before' => (string) $zeile['before_state'],
                'after'  => (string) $zeile['after_state'],
                'from'   => (int) $zeile['id'],
            ];
        }

        foreach ($plan as $eintrag) {
            foreach (Query::column('spätere Umbenennungen suchen', $wpdb->prepare(
                "SELECT change_group_id FROM {$journal} WHERE owner_id = %d AND what = %s AND id > %d AND change_group_id <> %d",
                $eintrag['id'],
                $eintrag['what'],
                $eintrag['from'],
                $act
            )) as $anderer) {
                $spaeter[] = (int) $anderer;
            }
        }

        return array_values(array_map(static function (array $e): array {
            unset($e['from']);

            return $e;
        }, $plan));
    }

    /**
     * Den alten Namen zurück — und ins Journal, vertauscht, damit das Undo dieses Undo ihn wieder findet.
     *
     * @param list<array{id: int, kind: string, what: string, before: string, after: string}> $plan
     */
    private static function writeNames(array $plan, Changelog $changelog): int
    {
        foreach ($plan as $eintrag) {
            $name = FrozenState::parse($eintrag['before'])?->field('name');

            if ($name === null || $name === '') {
                continue;
            }

            self::restoreName(self::NAME_VERBS[$eintrag['what']], $eintrag['id'], $name);
            $changelog->record($eintrag['id'], $eintrag['kind'], $eintrag['what'], $eintrag['after'], $eintrag['before'], null);
        }

        return count($plan);
    }

    /** @param list<array{label: Label, before: ?string}> $plan */
    private static function writeLabels(array $plan, Changelog $changelog): int
    {
        if ($plan === []) {
            return 0;
        }

        $labels = new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale(), $changelog);

        foreach ($plan as $eintrag) {
            $label = $eintrag['label'];

            if ($eintrag['before'] === null) {
                $labels->forget($label);
            } else {
                $labels->put(new Label($label->ownerId, $label->ownerKind, $label->role, $label->number, $label->locale, $eintrag['before']));
            }
        }

        return count($plan);
    }

    private static function shadowFor(string $liveTable): ?string
    {
        $stelle = array_search($liveTable, Schema::LIVE_TABLES, true);

        return $stelle === false ? null : (Schema::SHADOW_TABLES[$stelle] ?? null);
    }
}
