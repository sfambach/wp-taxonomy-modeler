<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

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

    public function name(): string
    {
        return self::NAME;
    }

    /** Kein einfacher Typ: gezeichnet wird ein Verweis auf einen Satz, und den gibt es nur an einer Aggregation. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return $subject instanceof Relation && $subject->kind === RelationKind::Aggregation;
    }

    protected function display(RenderContext $context): string
    {
        if ($context->value->isNothing()) {
            return $this->createHtmlValueSpan('');
        }

        if ($context->surroundings->refersTo === null) {
            // ⚠️ *Ein Verweis, dessen Satz nicht mehr da ist, bleibt sichtbar — als Nummer, gekennzeichnet (D-363).*
            return '<span class="taxmod-value taxmod-dangling">'
                . RenderResult::escape('#' . (string) $context->value->reference)
                . '</span>';
        }

        $wort = RenderResult::escape($context->surroundings->refersTo);
        $ziel = $context->surroundings->href;

        // ⚠️ *Ein angezeigter Satzverweis führt zu seinem Satz (D-852) — ohne Adresse bleibt es beim Wort.*
        return $this->createHtmlValueSpan($ziel === null || $ziel === ''
            ? $wort
            : '<a class="taxmod-record-link" href="' . RenderResult::escape($ziel) . '">' . $wort . '</a>');
    }

    protected function input(RenderContext $context): string
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

        // ⚠️ *Das eine Auswahlfeld ({@see SelectMarkup}) — gesperrt und ausgegraut, wo es nichts zu wählen gibt (D-380).*
        return SelectMarkup::of(
            $context->fieldName,
            $angebot,
            $gewaehlt === null ? null : (string) $gewaehlt,
            $context->surroundings->mayBeNothing || $gewaehlt === null,
            $context->surroundings->formId,
            ['class' => 'taxmod-summary-choice'],
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
    private function remote(RenderContext $context): string
    {
        $gewaehlt = $context->value->reference;
        $name     = RenderResult::escape($context->fieldName);
        $form     = $context->surroundings->formId === '' ? '' : ' form="' . RenderResult::escape($context->surroundings->formId) . '"';
        $nichts   = $gewaehlt === null;
        $wort     = $nichts ? '' : ($context->surroundings->refersTo ?? '#' . $gewaehlt);
        $knopf    = static fn (string $wert, bool $an): string => '<label class="taxmod-record-choice"><input type="radio" name="' . $name . '" value="' . $wert . '"'
            . ($an ? ' checked' : '') . $form . '></label>';
        $loeschen = (string) ($context->surroundings->dialogWords['clear'] ?? '');
        $suchwort = (string) ($context->surroundings->dialogWords['search'] ?? '');
        $baumwort = (string) ($context->surroundings->dialogWords['tree'] ?? '');

        return '<span class="taxmod-record-pick taxmod-record-remote' . ($nichts ? '' : ' taxmod-record-taken') . '">'
            . '<span class="taxmod-record-choices" hidden>' . $knopf('', $nichts) . ($nichts ? '' : $knopf((string) $gewaehlt, true)) . '</span>'
            . '<input type="search" class="taxmod-record-quick" autocomplete="off" placeholder="' . RenderResult::escape($suchwort) . '" aria-label="' . RenderResult::escape($suchwort) . '"' . ($nichts ? '' : ' hidden') . '>'
            // ⚠️ **Neben der Suche der Baum** ([D-1011](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «Erst Text, dann Baum». Der
            // Knopf trägt keinen Baum: Knoten und Sätze holt das Skript erst, wenn er gedrückt und ein Knoten angeklickt wird.*
            . ($baumwort === '' ? '' : '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-browse"' . ($nichts ? '' : ' hidden') . ' title="' . RenderResult::escape($baumwort) . '">'
                . IconMarkup::dashicon('networking', $baumwort) . '</button>')
            . '<span class="taxmod-record-dialog-open taxmod-record-word"' . ($nichts ? ' hidden' : '') . '>' . RenderResult::escape($wort) . '</span>'
            . self::openChosen($context->surroundings->href, $context->surroundings->dialogWords, $nichts)
            // *Der Mülleimer steht immer da, ohne Wahl verborgen — eine Wahl aus Suche oder Baum zeigt ihn (D-1011).*
            . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-clear"' . ($nichts ? ' hidden' : '') . ' style="color:#b32d2e" title="' . RenderResult::escape($loeschen) . '">'
                . IconMarkup::dashicon('trash', $loeschen) . '</button>'
            . '<span class="taxmod-record-hits" hidden></span>'
            . '</span>';
    }

    /**
     * Die Satzauswahl als Dialog: der Baum der Knoten als aufklappbare Äste, darin je Satz ein Auswahlknopf.
     *
     * ⚠️ *Sein Wort: «einen baum ansicht … wo ich erst den knoten auswähle … dann … datensatz aus» und «wir brauchen einen dialog»
     * (D-791). Ohne Skript: der Dialog ist die eine Form aus {@see DialogMarkup}, die Äste sind `<details>`, die Knöpfe gehören über
     * `form="…"` zum Formular des Satzes und werden mit ihm gespeichert. Offen steht der Ast des gewählten Satzes.*
     */
    private function dialog(RenderContext $context): string
    {
        $gewaehlt = $context->value->reference;
        $baum     = $context->surroundings->recordTree;
        $name     = RenderResult::escape($context->fieldName);
        $form     = $context->surroundings->formId === '' ? '' : ' form="' . RenderResult::escape($context->surroundings->formId) . '"';
        $knopf    = static fn (string $wert, bool $an, string $wort, string $suche = '', bool $passt = false): string => '<label class="taxmod-record-choice' . ($passt ? ' taxmod-record-match' : '') . '"'
            . ($suche === '' ? '' : ' data-taxmod-search="' . RenderResult::escape($suche) . '"') . '>'
            . '<input type="radio" name="' . $name . '" value="' . $wert . '"' . ($an ? ' checked' : '') . $form . '> ' . $wort . '</label>';

        $steht = false;

        foreach ($baum as $zeile) {
            $steht = $steht || ($gewaehlt !== null && isset($zeile['records'][$gewaehlt]));
        }

        $vorn = '';

        if ($context->surroundings->mayBeNothing || $gewaehlt === null) {
            $vorn .= $knopf('', $gewaehlt === null, '—');
        }

        // ⚠️ *Ein gespeicherter Satz ausserhalb des Baums bleibt wählbar und sichtbar (D-360) — sonst schriebe das nächste Speichern nichts.*
        if ($gewaehlt !== null && ! $steht) {
            $vorn .= $knopf((string) (int) $gewaehlt, true, RenderResult::escape($context->surroundings->refersTo ?? '#' . $gewaehlt));
        }

        // ⚠️ **Teilt die Seite die Körper** ([D-866](../../../docs/NewConcept/90-decision-log.md)), steht hier nur ein Platzhalter: Name, Formular
        // und Wert des Feldes, dazu die aktuelle Wahl als echter Knopf — damit ein Speichern ohne Skript nichts verliert. Der Körper selbst ist
        // neutral (ohne Namen, ohne Wahl) und steht einmal je Seite als Vorlage.
        if ($context->surroundings->sharedBodies !== null) {
            $neutral = static fn (string $wert, bool $an, string $wort, string $suche = '', bool $passt = false): string => '<label class="taxmod-record-choice' . ($passt ? ' taxmod-record-match' : '') . '"'
                . ($suche === '' ? '' : ' data-taxmod-search="' . RenderResult::escape($suche) . '"') . '>'
                . '<input type="radio" value="' . $wert . '"> ' . $wort . '</label>';
            $wahl    = $vorn;

            if ($gewaehlt !== null && $steht) {
                $wort = null;

                foreach ($baum as $zeile) {
                    $wort ??= isset($zeile['records'][$gewaehlt]) ? (string) $zeile['records'][$gewaehlt] : null;
                }

                $wahl .= $knopf((string) (int) $gewaehlt, true, RenderResult::escape($wort ?? '#' . $gewaehlt));
            }

            $schluessel = $context->surroundings->sharedBodies->share(self::browserBody($baum, $neutral, null, '', $context->surroundings->dialogWords));
            $koerper    = '<div class="taxmod-record-stub" data-taxmod-body="' . $schluessel . '" data-taxmod-name="' . $name . '"'
                . ' data-taxmod-form="' . RenderResult::escape($context->surroundings->formId) . '"'
                . ' data-taxmod-value="' . ($gewaehlt === null ? '' : (int) $gewaehlt) . '">' . $wahl . '</div>';

            return $this->dialogAround($context, $baum, $gewaehlt, $koerper);
        }

        $koerper = self::browserBody($baum, $knopf, $gewaehlt, $vorn, $context->surroundings->dialogWords);

        return $this->dialogAround($context, $baum, $gewaehlt, $koerper);
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
     * @param \Closure(string, bool, string, string=, bool=): string $knopf Ein Auswahlknopf: Wert, gewählt, Wort, Suchtext, Treffer
     * @param array{ok?: string, cancel?: string, tree?: string, search?: string} $worte
     */
    public static function browserBody(array $baum, \Closure $knopf, ?int $gewaehlt, string $vorn, array $worte): string
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

        $wurzel    = RenderResult::escape((string) ($baum[0]['name'] ?? ''));
        // ⚠️ *Eine flache Auswahl — nur ein Knoten — zeigt keinen Baum und keinen Schalter dafür. Sein Wort am 2026-11-03: «bei flachen
        // hierarchien darf der knoten links ruhig ausgeblendet werden» (D-1137).*
        $flach     = count($baum) <= 1;
        $baumWort  = $flach ? '' : (string) ($worte['tree'] ?? '');
        $werkzeuge = '<div class="taxmod-record-tools">'
            // *Ein leeres Feld ohne Wort sah aus wie ein Versehen — es sagt, dass es sucht (D-1164).*
            . '<input type="search" class="taxmod-record-search" autocomplete="off" aria-label="' . $wurzel . '"'
            . ((string) ($worte['search'] ?? '') === '' ? '' : ' placeholder="' . RenderResult::escape((string) $worte['search']) . '"') . '>'
            . ($baumWort === '' ? '' : '<label class="taxmod-record-treeswitch" title="' . RenderResult::escape($baumWort) . '">'
                . '<input type="checkbox" class="taxmod-record-treetoggle" checked>' . IconMarkup::dashicon('networking', $baumWort) . '</label>')
            . '</div>';

        $knoten = '';
        $liste  = $vorn;
        $tiefe  = -1;

        foreach ($baum as $i => $zeile) {
            while ($tiefe >= $zeile['depth']) {
                $knoten .= '</details>';
                $tiefe--;
            }

            $knoten .= '<details class="taxmod-record-branch"' . ($zeile['depth'] === 0 || isset($offen[$i]) ? ' open' : '') . '>'
                . '<summary><span class="taxmod-record-node" data-taxmod-group="' . (int) $i . '">' . RenderResult::escape($zeile['name'])
                . ($zeile['records'] === [] ? '' : ' <span class="taxmod-record-count">(' . count($zeile['records']) . ')</span>')
                . '</span>'
                // ⚠️ *Fehlt der Satz, legt man ihn an diesem Knoten an (D-792, Zeile 154) — seine Seite in einem neuen Reiter.*
                . ((string) ($zeile['add'] ?? '') === '' ? '' : ' <a class="' . ControlMarkup::ICON_ONLY . ' taxmod-record-add" href="' . RenderResult::escape((string) $zeile['add']) . '" target="_blank" rel="noopener" title="' . RenderResult::escape((string) ($zeile['addWord'] ?? '')) . '">'
                    . IconMarkup::dashicon('plus-alt2', (string) ($zeile['addWord'] ?? '')) . '</a>')
                . '</summary>';

            $tiefe = $zeile['depth'];

            // *Je Knoten eine Gruppe rechts — auch eine leere, damit das Skript die Grenzen eines Astes an der Tiefe erkennt.*
            $liste .= '<div class="taxmod-record-group" data-taxmod-group="' . (int) $i . '" data-taxmod-depth="' . (int) $zeile['depth'] . '">';

            if ($zeile['records'] !== []) {
                $liste .= '<div class="taxmod-record-group-name">' . RenderResult::escape($zeile['name']) . '</div>';

                foreach ($zeile['records'] as $satzId => $wort) {
                    $liste .= $knopf((string) (int) $satzId, (int) $satzId === $gewaehlt, RenderResult::escape((string) $wort), (string) ($zeile['search'][$satzId] ?? ''), isset($zeile['match'][$satzId]));
                }
            }

            // ⚠️ **Fehlt der Satz, tippt man ihn hier** ([D-1171](../../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «man soll auch eine
            // neue eingeben können zum beispiel in bauteil». Das Feld hat keinen Namen und schickt nichts mit dem Formular; das Skript legt den
            // Satz an und wählt ihn.*
            if ((string) ($zeile['newWord'] ?? '') !== '' && (int) ($zeile['node'] ?? 0) > 0) {
                $neu    = RenderResult::escape((string) $zeile['newWord']);
                $liste .= '<div class="taxmod-record-new" data-taxmod-node="' . (int) $zeile['node'] . '">'
                    . '<input type="text" class="taxmod-record-new-name" autocomplete="off" placeholder="' . $neu . '" aria-label="' . $neu . '">'
                    . '<button type="button" class="button taxmod-record-new-go">' . RenderResult::escape((string) ($zeile['createWord'] ?? '')) . '</button>'
                    . '</div>';
            }

            $liste .= '</div>';
        }

        while ($tiefe >= 0) {
            $knoten .= '</details>';
            $tiefe--;
        }

        return $werkzeuge
            . ($flach ? '<div class="taxmod-record-notree">' : '')
            . '<div class="taxmod-record-columns">'
            . '<div class="taxmod-record-nodes">' . $knoten . '</div>'
            . '<div class="taxmod-record-list">' . $liste . '</div>'
            . '</div>'
            . ($flach ? '</div>' : '');
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
    public static function multiDialog(array $baum, string $name, string $formId, string $schalter, string $oeffner, string $bestaetigen, array $worte): string
    {
        $form  = $formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"';
        $knopf = static fn (string $wert, bool $an, string $wort, string $suche = '', bool $passt = false): string => '<label class="taxmod-record-choice' . ($passt ? ' taxmod-record-match' : '') . '"'
            . ($suche === '' ? '' : ' data-taxmod-search="' . RenderResult::escape($suche) . '"') . '>'
            . '<input type="checkbox" name="' . RenderResult::escape($name) . '" value="' . $wert . '"' . $form . '> ' . $wort . '</label>';

        return DialogMarkup::of(
            $schalter,
            $oeffner,
            $oeffner,
            '<div class="taxmod-record-tree">' . self::browserBody($baum, $knopf, null, '', $worte) . '</div>',
            $bestaetigen,
            'button taxmod-dialog-open taxmod-record-multi-open',
            cancel: (string) ($worte['cancel'] ?? '')
        );
    }

    /** @param list<array<string, mixed>> $baum */
    private function dialogAround(RenderContext $context, array $baum, ?int $gewaehlt, string $koerper, ?string $href = null): string
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
    public static function pick(string $fieldName, string $formId, array $baum, ?int $gewaehlt, array $worte, bool $mayBeNothing = true): string
    {
        $name  = RenderResult::escape($fieldName);
        $form  = $formId === '' ? '' : ' form="' . RenderResult::escape($formId) . '"';
        $knopf = static fn (string $wert, bool $an, string $wort, string $suche = '', bool $passt = false): string => '<label class="taxmod-record-choice' . ($passt ? ' taxmod-record-match' : '') . '"'
            . ($suche === '' ? '' : ' data-taxmod-search="' . RenderResult::escape($suche) . '"') . '>'
            . '<input type="radio" name="' . $name . '" value="' . $wert . '"' . ($an ? ' checked' : '') . $form . '> ' . $wort . '</label>';

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

        $vorn = $mayBeNothing || $gewaehlt === null ? $knopf('', $gewaehlt === null, '—') : '';

        if ($gewaehlt !== null && ! $steht) {
            $vorn .= $knopf((string) $gewaehlt, true, RenderResult::escape('#' . $gewaehlt));
        }

        return self::around($formId, $fieldName, null, $worte, $baum, $gewaehlt, self::browserBody($baum, $knopf, $gewaehlt, $vorn, $worte));
    }

    /** @param array<string, string> $worte */
    private static function around(string $formId, string $fieldName, ?string $refersTo, array $worte, array $baum, ?int $gewaehlt, string $koerper, ?string $href = null): string
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
        $jetzt  = $nichts
            ? '<span class="taxmod-nothing">—</span><span class="screen-reader-text">' . RenderResult::escape((string) ($baum[0]['name'] ?? '')) . '</span>'
            : RenderResult::escape($wort ?? '#' . $gewaehlt);

        $dialog = DialogMarkup::of(
            'taxmod-record-dialog-' . preg_replace('/[^a-z0-9_-]/i', '', $formId . $fieldName),
            $jetzt,
            $jetzt,
            '<div class="taxmod-record-tree">' . $koerper . '</div>',
            '',
            'taxmod-record-dialog-open',
            ok: (string) ($worte['ok'] ?? ''),
            cancel: (string) ($worte['cancel'] ?? ''),
            tree: (string) ($worte['tree'] ?? '')
        );

        // ⚠️ **Kein Suchfeld mehr vor dem Dialog** ([D-1163](../../../docs/NewConcept/90-decision-log.md), ersetzt D-792 Zeile 153 hier) — *sein
        // Befund am 2026-11-04: «da erscheint ein Feld … weiß nicht, ob das eigentlich hidden sein soll», und sein Wunsch: «bei einfacher auswahl
        // ein ausgegrautes feld … und recht daneben … das baum symbol das öffnet den dialog». Gesucht wird im Dialog (D-791 Schritt 2).*
        return '<span class="taxmod-record-pick' . ($nichts ? '' : ' taxmod-record-taken') . '">'
            . $dialog
            . self::openChosen($href, $worte, $nichts)
            // *Der Mülleimer steht immer da, ohne Wahl verborgen — eine Wahl im Dialog zeigt ihn und verbirgt das Suchfeld (D-1138).*
            . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-clear"' . ($nichts ? ' hidden' : '') . ' style="color:#b32d2e" title="' . RenderResult::escape((string) ($worte['clear'] ?? '')) . '">'
                . IconMarkup::dashicon('trash', (string) ($worte['clear'] ?? '')) . '</button>'
            . '<span class="taxmod-record-hits" hidden></span>'
            . '</span>';
    }

    /** The chosen record, opened in a new tab to complete it (D-1255); nothing without a choice or an address. */
    /** @param array<string, string> $worte */
    private static function openChosen(?string $ziel, array $worte, bool $nichts): string
    {
        $wort = (string) ($worte['open'] ?? '');

        return $nichts || $ziel === null || $ziel === '' || $wort === '' ? ''
            : '<a class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-open" href="' . RenderResult::escape($ziel) . '" target="_blank" rel="noopener" title="' . RenderResult::escape($wort) . '">'
                . IconMarkup::dashicon('edit', $wort) . '</a>';
    }
}
