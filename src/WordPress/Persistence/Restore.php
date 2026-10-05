<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
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

    private static function shadowFor(string $liveTable): ?string
    {
        $stelle = array_search($liveTable, Schema::LIVE_TABLES, true);

        return $stelle === false ? null : (Schema::SHADOW_TABLES[$stelle] ?? null);
    }
}
