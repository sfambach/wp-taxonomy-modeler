<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\CurrentChoice;
use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\DialogBox;
use Taxmod\Core\Description\RecordBranch;
use Taxmod\Core\Description\RecordBrowser;
use Taxmod\Core\Description\RecordChoice;
use Taxmod\Core\Description\RecordPicker;
use Taxmod\Core\Description\SharedBody;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;

/**
 * Die Zusammenfassung eines verwiesenen Satzes — ein paar seiner Felder in einer Zeile, und beim
 * Bearbeiten ein Auswahlfeld über die Sätze des Ziels.
 *
 * ⚠️ **[D-106](../../../docs/NewConcept/90-decision-log.md) nennt die drei Stufen der Anzeige eines Verweises:**
 * *«reference (label and link) · summary (a few of the target's attributes, what a parts-list row wants) ·
 * expand (the whole target)».* Das hier ist die zweite. **Welche Felder**, sagt der Zielknoten in seiner
 * Einstellung `summary_fields` — als Vorgabe am Knoten, an der Kante überschreibbar
 * ([D-753](../../../docs/NewConcept/90-decision-log.md), sein Wort: *«aber wenn wirs am Knoten haben, können
 * wirs an der Kante so übernehmen»*).
 *
 * ⚠️ *Der Renderer selbst liest keinen Satz: die Worte kommen aufgelöst mit — für den gezeigten Satz in
 * `refersTo`, für die Auswahl in `options` —, in **einer** Abfrage je Block
 * ({@see \Taxmod\Core\Service\Rendering::summariesOf()}), wie [D-363](../../../docs/NewConcept/90-decision-log.md)
 * es für die Labels vorschreibt.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class SummaryRenderer extends TypedFieldRenderer
{
    public const NAME = 'summary';

    /** Die Einstellung am Zielknoten, die sagt, welche Felder den Satz ausmachen. */
    public const FIELDS = 'summary_fields';

    public const SEPARATOR = ' · ';

    /** Kein einfacher Typ: gezeichnet wird ein Verweis auf einen Satz, und den gibt es nur an einer Aggregation. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Relation && $subject->kind === RelationKind::Aggregation;
    }

    protected function display(RenderContext $context): Describes
    {
        if ($context->value->isNothing()) {
            return new ShownValue('', ValueKind::Text);
        }

        if ($context->surroundings->refersTo === null) {
            // ⚠️ *Ein Verweis, dessen Satz nicht mehr da ist, bleibt sichtbar — als Nummer, gekennzeichnet (D-363).*
            return new ShownValue('#' . (string) $context->value->reference, ValueKind::Dangling);
        }

        // ⚠️ *Ein angezeigter Satzverweis führt zu seinem Satz (D-852) — ohne Adresse bleibt es beim Wort.*
        return new ShownValue($context->surroundings->refersTo, ValueKind::Record, (string) $context->surroundings->href);
    }

    protected function input(RenderContext $context): Describes
    {
        if ($context->surroundings->searchesRemotely) {
            return $this->remote($context);
        }

        // ⚠️ **Mit Baum ein Dialog statt einer langen Liste** ([D-791](../../../docs/NewConcept/90-decision-log.md), Zeile 149).
        if ($context->surroundings->recordTree !== []) {
            return $this->dialog($context);
        }

        $gewaehlt = $context->value->reference;
        $angebot  = [];

        foreach ($context->surroundings->options as $satzId => $wort) {
            $angebot[(int) $satzId] = (string) $wort;
        }

        // *Ein gewählter Satz, der nicht im Angebot steht, bleibt als Nummer sichtbar — sonst löschte das nächste Speichern ihn.*
        if ($gewaehlt !== null && ! isset($angebot[$gewaehlt])) {
            $angebot[$gewaehlt] = '#' . $gewaehlt;
        }

        // ⚠️ *Das eine Auswahlfeld ({@see \Taxmod\Core\Description\Choosing}) — gesperrt und ausgegraut, wo es nichts zu wählen gibt (D-380).*
        return \Taxmod\Core\Description\Choosing::offer(
            $context->fieldName,
            $angebot,
            $gewaehlt === null ? null : (string) $gewaehlt,
            $context->surroundings->mayBeNothing || $gewaehlt === null,
            $context->surroundings->formId,
            \Taxmod\Core\Description\ChoicePurpose::Summary,
            nothingWord: '—'
        );
    }

    /**
     * Die Satzauswahl über «jeden Satz unter Model»: ein Suchfeld, dessen Treffer der Rand liefert, und ein Knopf für den Baum, den das Skript erst beim Öffnen holt.
     *
     * ⚠️ *[D-958](../../../docs/NewConcept/90-decision-log.md), F3a: «Bezug Ding» mit Suche; daneben seit D-1011 ein Baum, der erst beim Öffnen lädt. Dieselbe Hülle wie die Schnellsuche vor dem
     * Dialog (D-792, D-851), damit Treffer, Mülleimer und Selbstspeichern dieselben bleiben; die Auswahlknöpfe liegen verborgen — der
     * leere für «nichts», der gewählte, und jeder genommene Treffer kommt als weiterer dazu.*
     */
    private function remote(RenderContext $context): RecordPicker
    {
        $gewaehlt = $context->value->reference;

        return RecordPicker::bySearch(
            $context->fieldName,
            $context->surroundings->formId,
            $gewaehlt,
            $gewaehlt === null ? '' : ($context->surroundings->refersTo ?? '#' . $gewaehlt),
            $context->surroundings->href,
            $context->surroundings->dialogWords
        );
    }

    /**
     * Die Satzauswahl als Dialog: der Baum der Knoten als aufklappbare Äste, darin je Satz ein Auswahlknopf.
     *
     * ⚠️ *Sein Wort: «einen baum ansicht … wo ich erst den knoten auswähle … dann … datensatz aus» und «wir brauchen einen dialog»
     * (D-791). Ohne Skript: der Dialog ist die eine Form aus {@see DialogMarkup}, die Äste sind `<details>`, die Knöpfe gehören über
     * `form="…"` zum Formular des Satzes und werden mit ihm gespeichert. Offen steht der Ast des gewählten Satzes.*
     */
    private function dialog(RenderContext $context): RecordPicker
    {
        $gewaehlt = $context->value->reference;
        $baum     = $context->surroundings->recordTree;
        $name     = $context->fieldName;
        $form     = $context->surroundings->formId;
        $knopf    = static fn (string $wert, bool $an, string $wort, string $suche = '', bool $passt = false): RecordChoice => RecordChoice::one($name, $form, $wert, $an, $wort, $suche, $passt);

        $steht = false;

        foreach ($baum as $zeile) {
            $steht = $steht || ($gewaehlt !== null && isset($zeile['records'][$gewaehlt]));
        }

        $vorn = [];

        if ($context->surroundings->mayBeNothing || $gewaehlt === null) {
            $vorn[] = $knopf('', $gewaehlt === null, '—');
        }

        // ⚠️ *Ein gespeicherter Satz ausserhalb des Baums bleibt wählbar und sichtbar (D-360) — sonst schriebe das nächste Speichern nichts.*
        if ($gewaehlt !== null && ! $steht) {
            $vorn[] = $knopf((string) (int) $gewaehlt, true, $context->surroundings->refersTo ?? '#' . $gewaehlt);
        }

        // ⚠️ **Teilt die Seite die Körper** ([D-866](../../../docs/NewConcept/90-decision-log.md)), steht hier nur ein Platzhalter: Name, Formular
        // und Wert des Feldes, dazu die aktuelle Wahl als echter Knopf — damit ein Speichern ohne Skript nichts verliert. Der Körper selbst ist
        // neutral (ohne Namen, ohne Wahl) und steht einmal je Seite als Vorlage.
        if ($context->surroundings->sharedBodies !== null) {
            $wahl = $vorn;

            if ($gewaehlt !== null && $steht) {
                $wort = null;

                foreach ($baum as $zeile) {
                    $wort ??= isset($zeile['records'][$gewaehlt]) ? (string) $zeile['records'][$gewaehlt] : null;
                }

                $wahl[] = $knopf((string) (int) $gewaehlt, true, $wort ?? '#' . $gewaehlt);
            }

            $neutral = static fn (string $wert, bool $an, string $wort, string $suche = '', bool $passt = false): RecordChoice => RecordChoice::inTemplate($wert, $wort, $suche, $passt);
            $koerper = new SharedBody(
                $context->surroundings->sharedBodies->share(self::browserBody($baum, $neutral, null, [], $context->surroundings->dialogWords)->asTemplate()),
                $name,
                $form,
                $gewaehlt === null ? '' : (string) (int) $gewaehlt,
                $wahl,
                true
            );

            return $this->dialogAround($context, $baum, $gewaehlt, $koerper);
        }

        return $this->dialogAround($context, $baum, $gewaehlt, self::browserBody($baum, $knopf, $gewaehlt, $vorn, $context->surroundings->dialogWords));
    }

    /**
     * Der Körper eines Satzdialogs: oben Suche und Schalter für den Baum, links der Baum der Knoten, rechts die Sätze je Knoten
     * ([D-805](../../../docs/NewConcept/90-decision-log.md)) — für die Einzelwahl und die Mehrfachauswahl gleich.
     *
     * ⚠️ *Seine Worte: «show the tree select a node and then see a list of datasets to select from in a list not directly in the tree»,
     * «can be switched on and off», «collapse or expand when i clicking on a non dataset node». Ein Knoten ist ein aufklappbarer Ast; ein
     * Klick auf seinen Namen zeigt rechts seine Sätze und die aller darunter. Ohne Skript stehen alle Gruppen rechts untereinander.*
     *
     * @param list<array{depth: int, name: string, records: array<int, string>, search?: array<int, string>, match?: array<int, true>, add?: string, addWord?: string, node?: int, newWord?: string, createWord?: string}> $baum
     * @param \Closure(string, bool, string, string=, bool=): RecordChoice $knopf Ein Auswahlknopf: Wert, gewählt, Wort, Suchtext, Treffer
     * @param list<RecordChoice> $vorn Vor allen Gruppen: «nichts» und ein gespeicherter Satz ausserhalb des Baums
     * @param array{ok?: string, cancel?: string, tree?: string, search?: string} $worte
     */
    public static function browserBody(array $baum, \Closure $knopf, ?int $gewaehlt, array $vorn, array $worte): RecordBrowser
    {
        // *Welche Äste den gewählten Satz oder vorbelegte Treffer enthalten — sie stehen offen, damit man sie sieht.*
        $offen = [];

        foreach ($baum as $i => $zeile) {
            if (! ($gewaehlt !== null && isset($zeile['records'][$gewaehlt])) && ($zeile['match'] ?? []) === []) {
                continue;
            }

            $tiefe = $zeile['depth'];

            for ($j = $i; $j >= 0; $j--) {
                if ($baum[$j]['depth'] <= $tiefe) {
                    $offen[$j] = true;
                    $tiefe     = $baum[$j]['depth'] - 1;
                }
            }
        }

        $aeste = [];

        foreach ($baum as $i => $zeile) {
            $wahl = [];

            foreach ($zeile['records'] as $satzId => $wort) {
                $wahl[] = $knopf((string) (int) $satzId, (int) $satzId === $gewaehlt, (string) $wort, (string) ($zeile['search'][$satzId] ?? ''), isset($zeile['match'][$satzId]));
            }

            // ⚠️ *Fehlt der Satz, legt man ihn an diesem Knoten an (D-792, Zeile 154) — seine Seite in einem neuen Reiter.* ⚠️ **Oder man
            // tippt ihn hier** ([D-1171](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «man soll auch eine neue eingeben können
            // zum beispiel in bauteil». Das Feld hat keinen Namen und schickt nichts mit dem Formular; das Skript legt den Satz an und wählt ihn.*
            $neuAm  = (string) ($zeile['newWord'] ?? '') !== '' && (int) ($zeile['node'] ?? 0) > 0 ? (int) $zeile['node'] : 0;
            $aeste[] = new RecordBranch(
                (int) $zeile['depth'],
                (string) $zeile['name'],
                $zeile['depth'] === 0 || isset($offen[$i]),
                $wahl,
                count($zeile['records']),
                (string) ($zeile['add'] ?? ''),
                (string) ($zeile['addWord'] ?? ''),
                $neuAm,
                $neuAm === 0 ? '' : (string) $zeile['newWord'],
                $neuAm === 0 ? '' : (string) ($zeile['createWord'] ?? '')
            );
        }

        // ⚠️ *Eine flache Auswahl — nur ein Knoten — zeigt keinen Baum und keinen Schalter dafür. Sein Wort am 2026-11-03: «bei flachen
        // hierarchien darf der knoten links ruhig ausgeblendet werden» (D-1137).* *Ein leeres Suchfeld ohne Wort sah aus wie ein Versehen —
        // es sagt, dass es sucht (D-1164).*
        $flach = count($baum) <= 1;

        return new RecordBrowser(
            $aeste,
            array_values($vorn),
            (string) ($baum[0]['name'] ?? ''),
            (string) ($worte['search'] ?? ''),
            $flach ? '' : (string) ($worte['tree'] ?? ''),
            $flach
        );
    }

    /**
     * Die Mehrfachauswahl ([D-806](../../../docs/NewConcept/90-decision-log.md)): derselbe Dialog mit Baum und Liste, je Satz ein Haken;
     * «bestätigen» schickt die gewählten Sätze ab, und der Rand legt je Satz eine Zeile an.
     *
     * ⚠️ *Sein Wort: «i select multiple parts and for each part a item is created … propose a button to add multiple».*
     *
     * @param list<array{depth: int, name: string, records: array<int, string>}> $baum
     * @param array{ok?: string, cancel?: string, tree?: string}                 $worte
     */
    public static function multiDialog(array $baum, string $name, string $formId, string $schalter, \Taxmod\Core\Description\Describes $oeffner, \Taxmod\Core\Description\Describes $bestaetigen, array $worte): DialogBox
    {
        $knopf = static fn (string $wert, bool $an, string $wort, string $suche = '', bool $passt = false): RecordChoice => RecordChoice::several($name, $formId, $wert, $wort, $suche, $passt);

        return new DialogBox(
            $schalter,
            $oeffner,
            $oeffner,
            self::browserBody($baum, $knopf, null, [], $worte),
            $bestaetigen,
            'button taxmod-dialog-open taxmod-record-multi-open',
            cancel: (string) ($worte['cancel'] ?? '')
        );
    }

    /** @param list<array<string, mixed>> $baum */
    private function dialogAround(RenderContext $context, array $baum, ?int $gewaehlt, Describes $koerper): RecordPicker
    {
        return self::around($context->surroundings->formId, $context->fieldName, $context->surroundings->refersTo, $context->surroundings->dialogWords, $baum, $gewaehlt, $koerper, $context->surroundings->href);
    }

    /**
     * Dieselbe Satzauswahl für ein Modul, das sein Formular selbst zeichnet: ausgegrautes Feld, Baum, Dialog mit «Neu hier …»
     * ([D-1182](../../../docs/NewConcept/90-decision-log.md)) — sein Wort am 2026-11-05 an den Bezugsquellen: «müsste tree chooser sein mehr
     * als eine ebene und man müsste neu eingeben können». *Vorher baute jedes Modul ein eigenes Auswahlfeld mit Gruppen.*
     *
     * @param list<array{depth: int, name: string, records: array<int, string>, node?: int, newWord?: string, createWord?: string}> $baum
     * @param array<string, string> $worte ok, cancel, tree, clear, search
     */
    public static function pick(string $fieldName, string $formId, array $baum, ?int $gewaehlt, array $worte, bool $mayBeNothing = true): RecordPicker
    {
        $knopf = static fn (string $wert, bool $an, string $wort, string $suche = '', bool $passt = false): RecordChoice => RecordChoice::one($fieldName, $formId, $wert, $an, $wort, $suche, $passt);

        foreach ($baum as $i => $zeile) {
            $suche = [];

            foreach ($zeile['records'] as $satzId => $wort) {
                $suche[$satzId] ??= mb_strtolower($wort . ' ' . $zeile['name']);
            }

            $baum[$i]['search'] ??= $suche;
        }

        $steht = false;

        foreach ($baum as $zeile) {
            $steht = $steht || ($gewaehlt !== null && isset($zeile['records'][$gewaehlt]));
        }

        $vorn = $mayBeNothing || $gewaehlt === null ? [$knopf('', $gewaehlt === null, '—')] : [];

        if ($gewaehlt !== null && ! $steht) {
            $vorn[] = $knopf((string) $gewaehlt, true, '#' . $gewaehlt);
        }

        return self::around($formId, $fieldName, null, $worte, $baum, $gewaehlt, self::browserBody($baum, $knopf, $gewaehlt, $vorn, $worte));
    }

    /** @param array<string, string> $worte */
    private static function around(string $formId, string $fieldName, ?string $refersTo, array $worte, array $baum, ?int $gewaehlt, Describes $koerper, ?string $href = null): RecordPicker
    {
        // ⚠️ *Ohne gewählten Satz steht nur das Zeichen «—» — dann ist der Öffner ein Zeichenknopf: randlos, und sein Name steht für
        // den Vorleser dabei, wie beim Knotenwähler ({@see ChooserRenderer}; `icon-button-check`). Der Name ist der Knoten, aus dem
        // gewählt wird — die Wurzel des Baums.*
        // ⚠️ *Ohne Wort der Umgebung das Wort aus dem Baum des Dialogs, zuletzt die Nummer — ein gewählter Satz zeigt nie «—» (D-810).*
        $wort = $refersTo;

        foreach ($gewaehlt === null || $wort !== null ? [] : $baum as $zeile) {
            $wort ??= isset($zeile['records'][$gewaehlt]) ? (string) $zeile['records'][$gewaehlt] : null;
        }

        $nichts = $gewaehlt === null;
        $jetzt  = $nichts ? CurrentChoice::nothing((string) ($baum[0]['name'] ?? '')) : CurrentChoice::named($wort ?? '#' . $gewaehlt);

        // ⚠️ **Kein Suchfeld mehr vor dem Dialog** ([D-1163](../../../docs/NewConcept/90-decision-log.md), ersetzt D-792 Zeile 153 hier) — *sein
        // Befund am 2026-11-04: «da erscheint ein Feld … weiß nicht, ob das eigentlich hidden sein soll», und sein Wunsch: «bei einfacher auswahl
        // ein ausgegrautes feld … und recht daneben … das baum symbol das öffnet den dialog». Gesucht wird im Dialog (D-791 Schritt 2).*
        // *Der Mülleimer steht immer da, ohne Wahl verborgen — eine Wahl im Dialog zeigt ihn und verbirgt das Suchfeld (D-1138).*
        return RecordPicker::inDialog(
            new DialogBox(
                'taxmod-record-dialog-' . preg_replace('/[^a-z0-9_-]/i', '', $formId . $fieldName),
                $jetzt,
                $jetzt,
                $koerper,
                '',
                'taxmod-record-dialog-open',
                ok: (string) ($worte['ok'] ?? ''),
                cancel: (string) ($worte['cancel'] ?? ''),
                tree: (string) ($worte['tree'] ?? ''),
                // *D-1436: the head shows the choice; the title says what is chosen — the tree's root.*
                title: ChooserRenderer::title((string) ($worte['choose'] ?? ''), (string) ($baum[0]['name'] ?? ''))
            ),
            ! $nichts,
            $href,
            (string) ($worte['open'] ?? ''),
            (string) ($worte['clear'] ?? '')
        );
    }
}
