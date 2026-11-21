<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\ActsForm;
use Taxmod\Core\Description\AddChosen;
use Taxmod\Core\Description\ChoicePurpose;
use Taxmod\Core\Description\ComplexView;
use Taxmod\Core\Description\Choosing;
use Taxmod\Core\Description\Carried;
use Taxmod\Core\Description\CurrentChoice;
use Taxmod\Core\Description\NodeInTree;
use Taxmod\Core\Description\PartCount;
use Taxmod\Core\Description\PickableNode;
use Taxmod\Core\Description\RecordBrowser;
use Taxmod\Core\Description\RecordChoice;
use Taxmod\Core\Description\RecordPicker;
use Taxmod\Core\Description\FixedUnit;
use Taxmod\Core\Description\InheritedMark;
use Taxmod\Core\Description\Words;
use Taxmod\Core\Description\PartAdd;
use Taxmod\Core\Description\PickOpener;
use Taxmod\Core\Description\OverrideTick;
use Taxmod\Core\Description\InheritedValue;
use Taxmod\Core\Description\KindConfirm;
use Taxmod\Core\Description\SettingList;
use Taxmod\Core\Description\ShownList;
use Taxmod\Core\Description\ValueChain;
use Taxmod\Core\Description\AddonChain;
use Taxmod\Core\Description\AddonEntry;
use Taxmod\Core\Description\ReferenceList;
use Taxmod\Core\Description\RecordView;
use Taxmod\Core\Description\SettingOrigin;
use Taxmod\Core\Description\SettingRow;
use Taxmod\Core\Description\SettingsPanel;
use Taxmod\Core\Description\SharedBody;
use Taxmod\Core\Description\TableView;
use Taxmod\Core\Description\TreeChoice;
use Taxmod\Core\Description\TreeLine;
use Taxmod\Core\Description\TreeView;
use Taxmod\Core\Description\UnfoldingSummary;
use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\OrderedChoice;
use Taxmod\Core\Description\RepeatableList;
use Taxmod\Core\Description\PageView;
use Taxmod\Core\Description\HeadView;
use Taxmod\Core\Description\CompactView;
use Taxmod\Core\Description\DialogBox;
use Taxmod\Core\Description\FieldRow;
use Taxmod\Core\Description\FormView;
use Taxmod\Core\Description\Hint;
use Taxmod\Core\Description\Icon;
use Taxmod\Core\Description\Drawer;
use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\LabelsPanel;
use Taxmod\Core\Description\Locked;
use Taxmod\Core\Description\MediaButtons;
use Taxmod\Core\Description\MediaLink;
use Taxmod\Core\Description\MediaLook;
use Taxmod\Core\Description\MediaPicker;
use Taxmod\Core\Description\Sequence;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueInput;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Renderer\Control;

/**
 * The edge drawer: one way of drawing per kind of description (D-1424, D-1429 A14). The markup is the one the core drew
 * before, character for character, so every check and every screen stays as it was.
 *
 * ⚠️ *Escaping is plain `htmlspecialchars`, not `esc_html()`: the drawer is called in the core run too, which loads no
 * WordPress, and the two agree on every character this draws.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class EdgeDrawer implements Drawer
{
    private const NOTHING = '<span class="taxmod-nothing">—</span>';

    /** How far one level of a tree indents, in em. */
    private const TREE_STEP = 1.4;

    public function draw(Describes $piece): string
    {
        return match (true) {
            $piece instanceof ShownValue => $this->shown($piece),
            $piece instanceof ValueInput => $this->input($piece),
            $piece instanceof Locked     => '<fieldset disabled class="taxmod-read-only">' . $this->draw($piece->inner) . '</fieldset>',
            $piece instanceof Sequence   => implode('', array_map($this->draw(...), $piece->pieces)),
            $piece instanceof Choosing   => $this->choosing($piece),
            $piece instanceof Icon       => $piece->character ? IconMarkup::glyph($piece->symbol, $piece->label) : IconMarkup::dashicon($piece->symbol, $piece->label),
            $piece instanceof Hint       => $piece->beside ? '<span class="taxmod-tree-help">' . self::e($piece->text) . '</span>' : HintMarkup::icon($piece->text),
            $piece instanceof MediaLink  => $piece->asValue ? self::valueSpan(self::mediaLink($piece)) : self::mediaLink($piece),
            $piece instanceof MediaButtons => self::mediaButtons($piece),
            $piece instanceof MediaPicker => $this->mediaPicker($piece),
            $piece instanceof Control    => ControlMarkup::button($piece),
            $piece instanceof ActsForm   => ControlMarkup::actsForm($piece->id, $piece->submits, $piece->controls),
            $piece instanceof Carried    => ControlMarkup::carried($piece),
            $piece instanceof DialogBox  => DialogMarkup::of($piece->switch, $this->part($piece->opener), $this->part($piece->head), $this->part($piece->body), $this->part($piece->foot), $piece->openerClass, $piece->ok, $piece->cancel, $piece->open, $piece->tree, title: $piece->title),
            $piece instanceof AddChosen  => SelectMarkup::add($piece, $piece->purpose === ChoicePurpose::AddonCandidate ? 'taxmod-addon-add' : 'taxmod-list-add'),
            $piece instanceof NodeInTree => $this->nodeInTree($piece),
            $piece instanceof PickableNode => $this->pickable($piece),
            $piece instanceof TreeView   => $this->tree($piece),
            $piece instanceof TreeChoice => $this->treeChoice($piece),
            $piece instanceof CurrentChoice => self::current($piece),
            $piece instanceof SharedBody => $this->sharedBody($piece),
            $piece instanceof OrderedChoice => $this->orderedChoice($piece),
            $piece instanceof CompactView => $this->compact($piece),
            $piece instanceof HeadView   => $piece->rows === [] ? '' : '<table class="taxmod-head"><tbody>' . implode('', array_map(fn (array $r): string => '<tr>' . ($r['title'] === '' ? '<td colspan="2"' : '<th scope="row" class="taxmod-head-label">'
                . self::e($r['title']) . '</th><td') . ' class="taxmod-head-' . self::e($r['key']) . '">' . $this->part($r['body']) . '</td></tr>', $piece->rows)) . '</tbody></table>',
            $piece instanceof PageView   => $piece->blocks === [] ? '' : '<div class="taxmod-page">' . implode('', array_map($this->pageBlock(...), $piece->blocks)) . '</div>',
            $piece instanceof RepeatableList => '<ul class="taxmod-repeatable">' . implode('', array_map(fn (array $e): string => '<li class="taxmod-repeatable-entry">'
                . '<span class="taxmod-repeatable-value">' . $this->draw($e['value']) . '</span>'
                . ($e['remove'] === null ? '' : '<span class="taxmod-repeatable-act">' . $this->draw($e['remove']) . '</span>') . '</li>', $piece->entries)) . '</ul>'
                . ($piece->add === null ? '' : '<div class="taxmod-repeatable-add">' . $this->draw($piece->add) . '</div>'),
            $piece instanceof FormView   => $piece->lines === [] ? '' : '<div class="taxmod-form">' . implode('', array_map(fn (array $zeile): string => $this->formLine($zeile, $piece->addWords), $piece->lines)) . $this->formAdding($piece) . '</div>',
            $piece instanceof RecordView => $this->record($piece),
            $piece instanceof ComplexView => $this->complex($piece),
            $piece instanceof UnfoldingSummary => '<details class="taxmod-complex-link"><summary>' . self::e($piece->words) . '</summary>' . $this->draw($piece->part) . '</details>',
            $piece instanceof TableView  => $this->table($piece),
            $piece instanceof PartCount  => $piece->href === '' ? '<span class="taxmod-part-count">' . $piece->count . '</span>'
                : '<a class="taxmod-part-count" href="' . self::e($piece->href) . '">' . $piece->count . '</a>',
            $piece instanceof LabelsPanel => $this->labels($piece),
            $piece instanceof FieldRow   => $this->fieldRow($piece),
            $piece instanceof SettingsPanel => $this->settings($piece),
            $piece instanceof SettingRow => $this->setting($piece),
            $piece instanceof RecordChoice => self::recordChoice($piece),
            $piece instanceof RecordBrowser => $this->recordBrowser($piece),
            $piece instanceof RecordPicker => $this->recordPicker($piece),
            $piece instanceof FixedUnit  => '<span class="taxmod-unit-fixed">' . self::e($piece->sign) . '</span><input type="hidden" name="' . self::e($piece->name) . '" value="' . $piece->unit . '"'
                . ($piece->form === '' ? '' : ' form="' . self::e($piece->form) . '"') . '>',
            $piece instanceof InheritedMark => '<em class="taxmod-inherited"' . ($piece->word === '' ? '' : ' title="' . self::e($piece->word) . '"') . '>↑</em>',
            $piece instanceof Words      => self::e($piece->text),
            $piece instanceof PartAdd    => '<div class="taxmod-part-add">' . ($piece->add === null ? '' : $this->draw($piece->add)) . ($piece->several === null ? '' : $this->draw($piece->several)) . '</div>',
            $piece instanceof PickOpener => PickOpenerMarkup::of($piece),
            $piece instanceof OverrideTick => '<label class="taxmod-override-act"><input type="checkbox" class="taxmod-override" name="' . self::e($piece->name) . '" value="1"'
                . ($piece->ticked ? ' checked' : '') . ($piece->form === '' ? '' : ' form="' . self::e($piece->form) . '"') . ' title="' . self::e($piece->title) . '"></label>',
            $piece instanceof InheritedValue => '<span class="taxmod-setting-locked' . ($piece->automatic ? ' taxmod-setting-automatic' : '') . '">'
                . '<span class="taxmod-setting-locked-control">' . $this->draw($piece->control) . '</span>'
                . ' <em class="' . ($piece->automatic ? 'taxmod-automatic' : 'taxmod-inherited') . '">' . self::e($piece->sentence) . '</em></span>',
            $piece instanceof KindConfirm => '<label class="taxmod-kind-confirm"><input type="checkbox" name="' . self::e($piece->name) . '" value="1"'
                . ($piece->form === '' ? '' : ' form="' . self::e($piece->form) . '"') . '> ' . self::e($piece->confirmWord) . '</label>'
                . '<em class="taxmod-kind-pending description">' . self::e($piece->pending) . '</em>',
            $piece instanceof SettingList => $this->settingList($piece),
            $piece instanceof ShownList  => $this->shownList($piece),
            $piece instanceof ValueChain => $this->valueChain($piece),
            $piece instanceof AddonChain => $this->addonChain($piece),
            $piece instanceof AddonEntry => $this->addonEntry($piece),
            $piece instanceof ReferenceList => $this->referenceList($piece),
            default                      => throw new \InvalidArgumentException('Nothing draws a ' . $piece::class . '.'),
        };
    }

    /** A part that is still drawn markup (A15) stands as it is. */
    private function part(Describes|string $part): string
    {
        return is_string($part) ? $part : $this->draw($part);
    }

    private static function recordChoice(RecordChoice $wahl): string
    {
        $knopf = $wahl->several
            ? '<input type="checkbox"' . ($wahl->forRows ? ' class="taxmod-ref-check"' : '')
            : '<input type="radio"';
        $knopf .= ($wahl->name === null ? '' : ' name="' . self::e($wahl->name) . '"') . ' value="' . self::e($wahl->value) . '"'
            . ($wahl->chosen ? ' checked' : '') . ($wahl->form === '' ? '' : ' form="' . self::e($wahl->form) . '"') . '>';

        return '<label class="taxmod-record-choice' . ($wahl->match ? ' taxmod-record-match' : '') . '"'
            . ($wahl->search === '' ? '' : ' data-taxmod-search="' . self::e($wahl->search) . '"') . '>'
            . $knopf . ' ' . self::e($wahl->word) . '</label>';
    }

    /** Branches nest as `<details>` by depth, so they open and close without a script; the groups on the right stay flat, marked with their depth. */
    private function recordBrowser(RecordBrowser $b): string
    {
        $werkzeuge = '<div class="taxmod-record-tools">'
            . '<input type="search" class="taxmod-record-search" autocomplete="off" aria-label="' . self::e($b->rootName) . '"'
            . ($b->searchWord === '' ? '' : ' placeholder="' . self::e($b->searchWord) . '"') . '>'
            . ($b->treeWord === '' ? '' : '<label class="taxmod-record-treeswitch" title="' . self::e($b->treeWord) . '">'
                . '<input type="checkbox" class="taxmod-record-treetoggle" checked>' . IconMarkup::dashicon('networking', $b->treeWord) . '</label>')
            . '</div>';

        $knoten = '';
        $liste  = implode('', array_map(self::recordChoice(...), $b->first));
        $tiefe  = -1;

        foreach ($b->branches as $i => $ast) {
            while ($tiefe >= $ast->depth) {
                $knoten .= '</details>';
                $tiefe--;
            }

            $knoten .= '<details class="taxmod-record-branch"' . ($ast->open ? ' open' : '') . '>'
                . '<summary><span class="taxmod-record-node" data-taxmod-group="' . $i . '">' . self::e($ast->name)
                . ($ast->count === 0 ? '' : ' <span class="taxmod-record-count">(' . $ast->count . ')</span>')
                . '</span>'
                . ($ast->addAt === '' ? '' : ' <a class="' . ControlMarkup::ICON_ONLY . ' taxmod-record-add" href="' . self::e($ast->addAt) . '" target="_blank" rel="noopener" title="' . self::e($ast->addWord) . '">'
                    . IconMarkup::dashicon('plus-alt2', $ast->addWord) . '</a>')
                . '</summary>';

            $tiefe = $ast->depth;

            // An empty group too, so the script finds where a branch ends by its depth.
            $liste .= '<div class="taxmod-record-group" data-taxmod-group="' . $i . '" data-taxmod-depth="' . $ast->depth . '">';

            if ($ast->choices !== []) {
                $liste .= '<div class="taxmod-record-group-name">' . self::e($ast->name) . '</div>' . implode('', array_map(self::recordChoice(...), $ast->choices));
            }

            if ($ast->newAt > 0) {
                $neu    = self::e($ast->newWord);
                $liste .= '<div class="taxmod-record-new" data-taxmod-node="' . $ast->newAt . '">'
                    . '<input type="text" class="taxmod-record-new-name" autocomplete="off" placeholder="' . $neu . '" aria-label="' . $neu . '">'
                    . '<button type="button" class="button taxmod-record-new-go">' . self::e($ast->createWord) . '</button>'
                    . '</div>';
            }

            $liste .= '</div>';
        }

        while ($tiefe >= 0) {
            $knoten .= '</details>';
            $tiefe--;
        }

        $koerper = $werkzeuge
            . ($b->flat ? '<div class="taxmod-record-notree">' : '')
            . '<div class="taxmod-record-columns">'
            . '<div class="taxmod-record-nodes">' . $knoten . '</div>'
            . '<div class="taxmod-record-list">' . $liste . '</div>'
            . '</div>'
            . ($b->flat ? '</div>' : '');

        return $b->inDialog ? '<div class="taxmod-record-tree">' . $koerper . '</div>' : $koerper;
    }

    private function referenceList(ReferenceList $l): string
    {
        $knopf = fn (string $art, string $farbe, string $symbol, string $wort): string => '<button type="button" class="button taxmod-icon-button taxmod-ref-' . $art . '" style="color:' . $farbe . '"'
            . ($wort === '' ? '' : ' title="' . self::e($wort) . '"') . '>' . $this->draw(Icon::named($symbol, $wort)) . '</button>';
        $html  = '<table class="taxmod-ref-rows"><tbody>'
            . '<input type="hidden" name="' . self::e($l->name) . '" value=""' . ($l->form === '' ? '' : ' form="' . self::e($l->form) . '"') . '>';

        foreach ($l->lines as $zeile) {
            $html .= '<tr class="taxmod-ref-row"><td>' . $this->draw($zeile) . '</td><td class="taxmod-table-acts">'
                . $knopf('add', '#1d2327', 'plus-alt2', $l->insertWord) . $knopf('remove', '#b32d2e', 'trash', $l->removeWord) . '</td></tr>';
        }

        $html .= '</tbody></table>';

        if ($l->browser !== null) {
            $html .= $this->draw(new DialogBox(
                'taxmod-ref-dialog-' . preg_replace('/[^a-z0-9_-]/i', '', $l->form . $l->name),
                Icon::named('search', $l->searchWord === '' ? $l->fieldName : $l->searchWord),
                self::e($l->fieldName),
                $l->browser,
                '<button type="button" class="button button-primary taxmod-ref-take">' . self::e($l->okWord) . '</button>',
                'button taxmod-icon-button taxmod-dialog-open taxmod-ref-browse',
                cancel: $l->cancelWord
            ));
        }

        return '<div class="taxmod-ref-field">' . $html . '</div>';
    }

    private function addonChain(AddonChain $k): string
    {
        $form = $k->form === '' ? '' : ' form="' . self::e($k->form) . '"';
        $html = '<span class="taxmod-switch-picker taxmod-addon-picker">'
            . '<input type="hidden" name="' . self::e($k->name . '[present]') . '" value="1"' . $form . '>';

        if ($k->inherited !== []) {
            $html .= '<ul class="taxmod-addon-inherited description">'
                . implode('', array_map(fn (string $titel): string => '<li>' . self::e($titel) . ' <em>' . self::e($k->inheritedWord) . '</em></li>', $k->inherited))
                . '</ul>';
        }

        // *Die Vorlage trägt ihre Adressen nur als `data-taxmod-name` — ein Name in einer Vorlage stünde für jede Funktion gleich da;
        // das Skript macht beim Einfügen Namen daraus.*
        $vorlagen = implode('', array_map(fn (AddonEntry $e): string => '<template class="taxmod-addon-template" data-taxmod-addon="' . self::e($e->addon) . '">'
            . str_replace(' name="', ' data-taxmod-name="', $this->addonEntry($e)) . '</template>', $k->templates));

        return $html . '<ol class="taxmod-switch-cascade">' . implode('', array_map($this->addonEntry(...), $k->entries))
            . '</ol><span class="taxmod-switch-add">' . $this->draw($k->candidates) . $this->draw($k->add) . '</span>' . $vorlagen . '</span>';
    }

    private function addonEntry(AddonEntry $e): string
    {
        $form = $e->form === '' ? '' : ' form="' . self::e($e->form) . '"';
        $html = '<li class="taxmod-switch-chosen taxmod-addon-entry" data-taxmod-id="' . self::e($e->addon) . '">'
            . '<input type="hidden" name="' . self::e($e->name . '[name]') . '" value="' . self::e($e->addon) . '"' . $form . '>'
            . '<span class="taxmod-switch-name">' . self::e($e->title) . '</span> ';

        foreach ($e->fields as $feld) {
            $name  = self::e($feld->name);
            $html .= '<label class="taxmod-addon-field">' . self::e($feld->label) . ' '
                . match (true) {
                    $feld->value instanceof Choosing => $this->draw($feld->value),
                    is_bool($feld->value)            => '<input type="hidden" name="' . $name . '" value="0"' . $form . '><input type="checkbox" name="' . $name . '" value="1"' . ($feld->value ? ' checked' : '') . $form . '>',
                    default                          => '<input type="text" size="10" name="' . $name . '" value="' . self::e($feld->value) . '"' . $form . '>',
                }
                . '</label> ';
        }

        return $html . $this->chainButtons($e->title, $e->first, $e->last) . '</li>';
    }

    /** Up, down and the bin of one link in a chain; the end that cannot move is greyed (D-370). */
    private function chainButtons(string $wort, bool $erstes, bool $letztes): string
    {
        $knopf = fn (string $art, string $symbol, string $zusatz, string $farbe): string => '<button type="button" class="button taxmod-icon-button taxmod-list-' . $art . '"' . $zusatz . ' style="color:' . $farbe . '">'
            . $this->draw(Icon::named($symbol, $wort)) . '</button>';

        return $knopf('move', 'arrow-up-alt2', ' data-taxmod-move="up"' . ($erstes ? ' disabled' : ''), $erstes ? '#1d2327;opacity:.35' : '#1d2327')
            . $knopf('move', 'arrow-down-alt2', ' data-taxmod-move="down"' . ($letztes ? ' disabled' : ''), $letztes ? '#1d2327;opacity:.35' : '#1d2327')
            . $knopf('remove', 'trash', '', '#b32d2e');
    }

    private function shownList(ShownList $l): string
    {
        $einer = fn (MediaLink|ShownValue $w): string => $w instanceof MediaLink ? self::mediaLink($w)
            : ($w->target === '' ? self::e($w->text) : '<a class="taxmod-record-link" href="' . self::e($w->target) . '">' . self::e($w->text) . '</a>');

        if ($l->count !== null) {
            return '<span class="taxmod-value taxmod-media-count">' . ($l->pictures === [] ? '' : self::mediaLink($l->pictures[0]) . ' ')
                . $this->draw(Icon::named('format-gallery')) . ' ' . $l->count . '</span>';
        }

        if ($l->media) {
            return '<span class="taxmod-value taxmod-media-list">' . implode(' ', array_map($einer, $l->pictures))
                . ($l->pictures !== [] && $l->items !== [] ? '<br>' : '') . implode('<br>', array_map($einer, $l->items)) . '</span>';
        }

        return '<span class="taxmod-value taxmod-ref-list">' . implode('<br>', array_map($einer, $l->items)) . '</span>';
    }

    private function valueChain(ValueChain $k): string
    {
        $form = $k->form === '' ? '' : ' form="' . self::e($k->form) . '"';
        $html = '<span class="taxmod-switch-picker taxmod-value-picker"' . ($k->pictures ? ' data-taxmod-pictures="1"' : '') . '><ol class="taxmod-switch-cascade">';
        $zahl = count($k->entries);

        foreach ($k->entries as $platz => $glied) {
            $html .= '<li class="taxmod-switch-chosen" data-taxmod-id="' . self::e($glied['value']) . '">'
                . '<input type="hidden" class="taxmod-switch-member" name="' . self::e($k->name) . '" value="' . self::e($glied['value']) . '"' . $form . '>'
                . '<span class="taxmod-switch-name">' . self::e($glied['word']) . '</span> '
                . $this->chainButtons($glied['word'], $platz === 0, $platz === $zahl - 1) . '</li>';
        }

        $html .= '</ol><span class="taxmod-switch-add">';
        $html .= $k->candidates !== null ? $this->draw($k->candidates)
            : '<input type="text" class="taxmod-value-new" name="' . self::e($k->name) . '" data-taxmod-name="' . self::e($k->name) . '"' . $form . ' size="30">'
                . ($k->buttons === null ? '' : $this->draw($k->buttons));

        return $html . $this->draw($k->add) . '</span></span>';
    }

    private function settingList(SettingList $l): string
    {
        $form = $l->form === '' ? '' : ' form="' . self::e($l->form) . '"';

        return '<ul class="taxmod-setting-list">' . implode('', array_map(static fn (array $z): string => '<li class="taxmod-setting-list-entry' . ($z['inherited'] ? ' taxmod-setting-list-inherited' : '') . '">'
            . '<input type="hidden" name="' . self::e($z['name']) . '[aktiv]" value="0"' . $form . '>'
            . '<label><input type="checkbox" name="' . self::e($z['name']) . '[aktiv]" value="1"' . ($z['active'] ? ' checked' : '') . $form . '> '
            . self::e($z['word']) . '</label> '
            . '<input type="number" class="taxmod-setting-list-position" name="' . self::e($z['name']) . '[position]" value="' . $z['position'] . '" min="0" step="1"' . $form . '>'
            . '</li>', $l->entries)) . '</ul>';
    }

    private function recordPicker(RecordPicker $p): string
    {
        $offen = $p->taken && $p->href !== null && $p->href !== '' && $p->openWord !== ''
            ? '<a class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-open" href="' . self::e($p->href) . '" target="_blank" rel="noopener" title="' . self::e($p->openWord) . '">'
                . IconMarkup::dashicon('visibility', $p->openWord) . '</a>'
            : '';
        $ende = '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-clear"' . ($p->taken ? '' : ' hidden') . ' style="color:#b32d2e" title="' . self::e($p->clearWord) . '">'
                . IconMarkup::dashicon('trash', $p->clearWord) . '</button>'
            . '<span class="taxmod-record-hits" hidden></span>'
            . '</span>';

        if ($p->dialog !== null) {
            // *A node dialog brings no empty radio of its own; the bin needs one to choose «nothing» (aroundNodeDialog).*
            $leer = $p->name === '' ? '' : '<input type="radio" class="taxmod-pick-empty" hidden name="' . self::e($p->name) . '" value=""'
                . ($p->form === '' ? '' : ' form="' . self::e($p->form) . '"') . '>';

            return '<span class="taxmod-record-pick' . ($p->taken ? ' taxmod-record-taken' : '') . '">' . $leer . $this->draw($p->dialog) . $offen . $ende;
        }

        $nichts = ! $p->taken;
        $knopf  = fn (string $wert, bool $an): string => '<label class="taxmod-record-choice"><input type="radio" name="' . self::e($p->name) . '" value="' . $wert . '"'
            . ($an ? ' checked' : '') . ($p->form === '' ? '' : ' form="' . self::e($p->form) . '"') . '></label>';

        // *Ohne Wort steht die Nummer — ein gewählter Satz ohne Namen zeigte sonst nur den Mülleimer (his screenshot 19.11. 20:51).*
        $wort = trim($p->word) === '' && $p->chosen !== null ? '#' . $p->chosen : $p->word;
        $zeigt = $p->opens === ''
            ? '<span class="taxmod-record-dialog-open taxmod-record-word"' . ($nichts ? ' hidden' : '') . '>' . self::e($wort) . '</span>'
            : '<label class="taxmod-record-dialog-open taxmod-record-word taxmod-record-opens" for="' . self::e($p->opens) . '"' . ($nichts ? ' hidden' : '') . '>' . self::e($wort) . '</label>';

        return '<span class="taxmod-record-pick taxmod-record-remote' . ($nichts ? '' : ' taxmod-record-taken') . ($p->wrap ? ' taxmod-record-wrap' : '') . '">'
            . '<span class="taxmod-record-choices" hidden>' . $knopf('', $nichts) . ($nichts ? '' : $knopf((string) $p->chosen, true)) . '</span>'
            . '<input type="search" class="taxmod-record-quick" autocomplete="off" placeholder="' . self::e($p->searchWord) . '" aria-label="' . self::e($p->searchWord) . '"' . ($nichts ? '' : ' hidden') . '>'
            . ($p->treeWord === '' ? '' : '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-browse"' . ($nichts ? '' : ' hidden') . ' title="' . self::e($p->treeWord) . '">'
                . IconMarkup::dashicon('networking', $p->treeWord) . '</button>')
            . $zeigt
            . $offen . $ende;
    }

    private function settings(SettingsPanel $panel): string
    {
        $markup = '';

        foreach ($panel->groups as $gruppe) {
            $innen = '';

            foreach ($gruppe['sections'] as $abschnitt) {
                $schmal = implode('', array_map($this->setting(...), $abschnitt['switches']));
                $breit  = '';
                $band   = null;

                foreach ($abschnitt['fields'] as $zeile) {
                    if ($zeile->band !== $band) {
                        $breit .= $band === null ? '' : '</div>';
                        $breit .= $zeile->band === null ? '' : '<div class="taxmod-setting-band"><code class="taxmod-setting-band-name">' . self::e($zeile->band) . '</code>';
                        $band   = $zeile->band;
                    }

                    $breit .= $this->setting($zeile);
                }

                $breit .= $band === null ? '' : '</div>';
                $innen .= ($abschnitt['heading'] === null ? '' : '<div class="taxmod-settings-subheading">' . self::e($abschnitt['heading']) . '</div>')
                    . ($schmal === '' ? '' : '<div class="taxmod-settings-switches">' . $schmal . '</div>')
                    . ($breit === '' ? '' : '<div class="taxmod-settings-fields">' . $breit . '</div>');
            }

            $markup .= '<div class="taxmod-settings-group taxmod-settings-' . self::e($gruppe['group']) . '">'
                . '<div class="taxmod-settings-heading description">' . self::e($gruppe['heading']) . '</div>'
                . $innen
                . '</div>';
        }

        return $panel->formId === ''
            ? '<div class="taxmod-settings">' . $markup . '</div>'
            : '<form method="post" id="' . self::e($panel->formId) . '" action="' . self::e($panel->action) . '" class="taxmod-settings">'
                . ($panel->carried === null ? '' : $this->draw($panel->carried)) . $markup . '</form>';
    }

    private function setting(SettingRow $r): string
    {
        $wort  = self::e($r->overrideWord);
        $haken = $r->overrideName === '' ? '' : '<label class="taxmod-override-act" title="' . $wort . '">'
            . '<input type="checkbox" class="taxmod-override" name="' . self::e($r->overrideName) . '" value="1"'
            . ($r->automatic ? ' checked' : '') . ($r->form === '' ? '' : ' form="' . self::e($r->form) . '"')
            . '><span class="screen-reader-text">' . $wort . '</span></label>';
        $feld  = $r->control === null ? '<code class="taxmod-no-renderer">' . self::e($r->undrawn) . '</code>' : $this->draw($r->control);
        $her   = match ($r->origin) {
            SettingOrigin::Unmarked      => '',
            SettingOrigin::Automatic     => '<em class="taxmod-automatic">' . self::e($r->originText) . '</em>',
            SettingOrigin::InheritedFrom => '<em class="taxmod-inherited">' . self::e($r->originText) . '</em>',
            SettingOrigin::Inherited     => '<em class="taxmod-inherited" title="' . self::e($r->originText) . '">↑</em>',
        };

        return '<div class="taxmod-setting' . ($r->locked ? ' taxmod-setting-locked' : '') . ($r->automatic ? ' taxmod-setting-automatic' : '') . '">'
            . '<span class="taxmod-setting-override">' . $haken . '</span>'
            . '<span class="taxmod-setting-name"><code class="taxmod-setting-key">' . self::e($r->key) . '</code>' . HintMarkup::icon($r->hint) . '</span>'
            . '<span class="taxmod-setting-value">' . ($r->locked ? '<span class="taxmod-setting-locked-control">' . $feld . '</span>' : $feld) . '</span>'
            . '<span class="taxmod-setting-from description">' . $her . '</span>'
            . ($r->acts === null ? '' : '<span class="taxmod-setting-acts">' . implode('', array_map($this->draw(...), $r->acts)) . '</span>')
            . '</div>';
    }

    private function fieldRow(FieldRow $f): string
    {
        $zelle  = static fn (string $innen, string $klasse): string => '<td class="' . $klasse . '">' . $innen . '</td>';
        $nichts = self::NOTHING;
        $flex   = '<span style="display:flex;align-items:center;gap:.3em">';
        $ziel   = $f->targetHref === null || $f->target === '—'
            ? $flex . Html::tag('input', ['type' => 'text', 'class' => 'taxmod-chosen', 'readonly' => true, 'tabindex' => '-1', 'value' => $f->target, 'style' => 'flex:1;min-width:8em'])
                . $this->part($f->targetChooser) . '</span>'
            : $flex . Html::tag('a', ['href' => $f->targetHref, 'class' => 'taxmod-field-target-link taxmod-chosen', 'style' => 'flex:1;min-width:8em'])
                . self::e($f->target) . '</a>' . $this->part($f->targetChooser) . '</span>';
        // ⚠️ *D-1453, sein Wort 18.11.: «wenn man … vorne auf den Namen [klickt], dass man auf dem Knoten landet, der es definiert».*
        $name   = $f->renameAs === null
            ? ($f->ownerHref === null ? '<strong>' . self::e($f->name) . '</strong>'
                : Html::tag('a', ['href' => $f->ownerHref, 'class' => 'taxmod-field-owner-link', 'title' => $f->ownerName]) . '<strong>' . self::e($f->name) . '</strong></a>')
            : Html::tag('input', ['type' => 'text', 'class' => 'taxmod-field-rename', 'name' => $f->renameAs, 'value' => $f->name, 'aria-required' => 'true', 'style' => 'width:100%', 'form' => $f->renameForm]);
        $schloss = ! $f->locked ? '' : ' ' . Html::tag('span', ['class' => 'taxmod-locked', 'title' => $f->lockedReason]) . '&#128274; ' . self::e($f->lockedWord) . '</span>';
        $knoepfe = $f->actsForm === '' ? '' : '<form method="post" id="' . self::e($f->actsForm) . '" action="' . self::e($f->actsAt) . '" class="taxmod-acts">'
            . $this->draw(new Carried($f->carried)) . implode('', array_map($this->draw(...), $f->acts)) . '</form>';

        $zeile = '<tr class="taxmod-field' . ($f->locked ? ' taxmod-field-locked' : '') . '" data-taxmod-relation="' . $f->relation . '">'
            . ($f->override === null ? '' : $zelle($this->part($f->override), 'taxmod-field-override'))
            . $zelle($name, 'taxmod-field-name')
            . $zelle($ziel, 'taxmod-field-target')
            . ($f->kind === null ? $zelle('<code>' . self::e($f->kindText) . '</code>', 'taxmod-field-kind') : $zelle($this->draw($f->kind), 'taxmod-field-kind'))
            . $zelle(($f->own ? self::e($f->ownWord) : '<em>' . self::e($f->inheritedWord) . '</em>') . $schloss, 'taxmod-field-from')
            . $zelle($f->multiplicity === null ? $nichts : $this->draw($f->multiplicity), 'taxmod-field-many')
            . $zelle($f->readOnly === null ? $nichts : $this->draw($f->readOnly), 'taxmod-field-switch')
            . $zelle($f->unique === null ? $nichts : $this->draw($f->unique), 'taxmod-field-switch')
            . (! $f->valueColumn ? '' : $zelle($f->value === null ? $nichts : $this->part($f->value), 'taxmod-field-value'))
            . $zelle($knoepfe, 'taxmod-field-acts')
            . '</tr>';
        $leiste = $f->fold === null ? '' : '<tr class="taxmod-field-fold"><td colspan="' . $f->columns . '">' . $this->draw($f->fold) . '</td></tr>';

        return $zeile . $leiste . ($f->settings === null ? ''
            : '<tr class="taxmod-field-settings-row" id="' . self::e($f->settingsRow) . '"><td colspan="' . $f->columns . '">' . $this->part($f->settings) . '</td></tr>');
    }

    private function labels(LabelsPanel $l): string
    {
        $kurz = '';
        $lang = '';

        foreach ($l->slots as $slot) {
            $rolle = '<code style="font-size:.9em">' . self::e($slot->role) . '</code>';

            if ($slot->isLong) {
                $lang .= '<div class="taxmod-labels-long" style="display:flex;flex-direction:column;gap:.15em">' . $rolle
                    . (! $l->editing ? '<span>' . self::e($slot->shown) . '</span>'
                        : '<textarea name="' . self::e($slot->fieldName) . '" rows="2" placeholder="' . self::e($slot->shown) . '"'
                            . ($l->formId === '' ? '' : ' form="' . self::e($l->formId) . '"')
                            . ' style="width:100%">' . self::e($slot->stored ?? '') . '</textarea>')
                    . '</div>';

                continue;
            }

            $kurz .= '<label style="display:flex;flex-direction:column;gap:.15em;' . ($slot->translatable ? 'flex:1 1 8em;min-width:7em' : 'flex:0 0 5em') . '">' . $rolle
                . (! $l->editing ? '<span>' . self::e($slot->shown) . '</span>'
                    : Html::tag('input', [
                        'type' => 'text', 'name' => $slot->fieldName, 'value' => $slot->stored ?? '', 'placeholder' => $slot->shown,
                        'title' => $slot->note === '' ? null : $slot->note, 'form' => $l->formId, 'style' => 'width:100%',
                    ]))
                . '</label>';
        }

        $sprache = $l->localeTitle === null ? '' : '<div class="taxmod-labels-locale" style="display:flex;flex-direction:column;gap:.15em;flex:0 0 8em">'
            . '<code style="font-size:.9em">' . self::e($l->localeTitle) . '</code>' . $this->part($l->localePicker ?? '') . '</div>';
        $innen = ($kurz === '' ? '' : '<div class="taxmod-labels-short" style="display:flex;gap:.8em;align-items:flex-end;flex-wrap:wrap;margin:.4em 0">' . $sprache . $kurz . '</div>')
            . ($kurz !== '' && $lang !== '' ? '<div style="height:.7em"></div>' : '')
            . $lang;
        $knoepfe = $l->acts === [] ? '' : '<div style="display:flex;gap:.2em;margin-top:.5em">' . implode('', array_map($this->draw(...), $l->acts)) . '</div>';

        $eingabe = match (true) {
            $l->action === null => $innen,
            $l->formId !== ''   => $this->draw(new Carried($l->carried, $l->formId)) . $innen . $knoepfe,
            default             => '<form method="post" action="' . self::e($l->action) . '">' . $this->draw(new Carried($l->carried)) . $innen . $knoepfe . '</form>',
        };

        return '<div class="taxmod-labels">' . $eingabe . '</div>';
    }

    private function table(TableView $t): string
    {
        $zeilen = [];

        foreach ($t->rows as $zeile) {
            $zeilen[] = [
                array_map(fn (Describes|string $z): string => $this->part($z), $zeile['lead']),
                array_map(fn (Describes|string $z): string => $this->part($z), $zeile['cells']),
                $zeile['acts'] === null ? null : $this->part($zeile['acts']),
            ];
        }

        $spalten = array_keys($t->columnHeads);

        // An empty column is left out when showing (D-954); when editing the empty cell is where the value goes.
        if ($t->dropEmptyColumns) {
            $spalten = array_values(array_filter($spalten, static function (int $i) use ($zeilen): bool {
                foreach ($zeilen as $zeile) {
                    if (self::shows($zeile[1][$i])) {
                        return true;
                    }
                }

                return false;
            }));

            if ($spalten === [] && $t->leadHeads === [] && ! $t->withActs) {
                return '';
            }
        }

        $koepfe = array_map(self::e(...), $t->leadHeads);

        foreach ($spalten as $i) {
            $koepfe[] = self::e($t->columnHeads[$i]['name']) . HintMarkup::icon($t->columnHeads[$i]['hint']);
        }

        if ($t->withActs) {
            $koepfe[] = '';
        }

        $zellen = [];

        foreach ($zeilen as [$vorne, $mitte, $acts]) {
            $reihe = array_map(static fn (string $m): array => ['taxmod-table-lead', $m], $vorne);

            foreach ($spalten as $i) {
                $reihe[] = ['taxmod-table-cell', $mitte[$i]];
            }

            if ($t->withActs) {
                $reihe[] = ['taxmod-table-acts', (string) $acts];
            }

            $zellen[] = $reihe;
        }

        $rumpf = '';

        if ($t->orientation->isVertical()) {
            foreach ($koepfe as $stelle => $name) {
                $reihe = $t->withHeads ? '<th class="taxmod-table-head" scope="row">' . $name . '</th>' : '';

                foreach ($zellen as $zeile) {
                    $reihe .= '<td class="' . $zeile[$stelle][0] . '">' . $zeile[$stelle][1] . '</td>';
                }

                $rumpf .= '<tr class="taxmod-table-row">' . $reihe . '</tr>';
            }

            $innen = '<tbody>' . $rumpf . '</tbody>';
        } else {
            foreach ($zellen as $zeile) {
                $rumpf .= '<tr class="taxmod-table-row">' . implode('', array_map(static fn (array $z): string => '<td class="' . $z[0] . '">' . $z[1] . '</td>', $zeile)) . '</tr>';
            }

            $innen = (! $t->withHeads ? '' : '<thead><tr>' . implode('', array_map(static fn (string $k): string => '<th class="taxmod-table-head" scope="col">' . $k . '</th>', $koepfe)) . '</tr></thead>')
                . '<tbody>' . $rumpf . '</tbody>';
        }

        return '<table class="taxmod-table taxmod-table-' . $t->orientation->value . '">' . $innen . '</table>';
    }

    /** Whether drawn markup shows anything: text, or a picture. */
    private static function shows(string $markup): bool
    {
        return ! (trim(html_entity_decode(strip_tags($markup))) === '' && ! str_contains($markup, '<img'));
    }

    /** A block that draws nothing is left out, and so is the whole when nothing is left. */
    private function complex(ComplexView $k): string
    {
        $markup = '';

        foreach ($k->fixed as $wort => $wert) {
            $markup .= '<span class="taxmod-complex-fixed-item"><span class="taxmod-complex-fixed-label">' . self::e((string) $wort) . '</span> ' . $this->part($wert) . '</span>';
        }

        $markup = $markup === '' ? '' : '<div class="taxmod-complex-fixed">' . $markup . '</div>';

        foreach ($k->blocks as $block) {
            $innen = implode('', array_map(fn (Describes|string $d): string => $this->part($d), $block['body']));

            $markup .= $block['heading'] === null || $innen === '' ? $innen
                : '<div class="taxmod-complex-block"><h4 class="taxmod-complex-heading">' . self::e($block['heading']) . HintMarkup::icon($block['hint']) . '</h4>' . $innen . '</div>';
        }

        return $markup === '' ? '' : '<div class="taxmod-node">' . $markup . '</div>';
    }

    /**
     * @param array{name: string, field: ?Describes, hint: string, relation?: int, addable?: bool} $zeile
     * @param array<string, string> $words D-1571: `remove` names the ✕ of an optional field
     */
    private function formLine(array $zeile, array $words = []): string
    {
        if ($zeile['field'] === null) {
            return '<div class="taxmod-form-group"><span class="taxmod-form-group-name">' . self::e($zeile['name']) . '</span></div>';
        }

        // D-1571: an optional field of a compact form carries its relation; empty, it waits hidden for «+», and ✕ takes it away.
        $optional = isset($zeile['addable'], $zeile['relation']);

        return '<div class="taxmod-form-row"' . ($optional ? ' data-taxmod-optional="' . (int) $zeile['relation'] . '"' . ($zeile['addable'] ? ' hidden' : '') : '') . '>'
            . '<span class="taxmod-form-label">' . self::e($zeile['name']) . '</span>'
            . '<span class="taxmod-form-field">' . $this->draw($zeile['field'])
            . ($optional ? ' <button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-field-remove">'
                . IconMarkup::dashicon('trash', $words['remove'] ?? '') . '</button>' : '')
            . '</span>' . HintMarkup::icon($zeile['hint']) . '</div>';
    }

    /** D-1571: «+ Festlegung» — the standard choice of the empty optional fields with the standard «+» beside it (D-1511 rule 3). */
    private function formAdding(FormView $form): string
    {
        $offered = $form->adding();

        if ($offered === []) {
            return '';
        }

        return '<div class="taxmod-form-row taxmod-form-adding"><span class="taxmod-form-label">' . self::e($form->addWords['add'] ?? '') . '</span>'
            . '<span class="taxmod-form-field">' . SelectMarkup::select(Choosing::offer('', array_map('strval', $offered), null, true, nothingWord: $form->addWords['choose'] ?? ''), ['class' => 'taxmod-field-choice'])
            . SelectMarkup::add(new \Taxmod\Core\Description\AddChosen($form->addWords['add'] ?? '', true), 'taxmod-field-add') . '</span></div>';
    }

    private function record(RecordView $r): string
    {
        $innen = ($r->title === '' ? '' : '<div class="taxmod-record-head"><strong>' . self::e($r->title) . '</strong></div>')
            . '<div class="taxmod-record-form">' . $this->part($r->form) . '</div>'
            . ($r->diagnostic === null ? '' : '<div class="taxmod-record-diagnostic">' . $this->part($r->diagnostic) . '</div>');
        $knoepfe = implode('', array_map($this->draw(...), $r->acts));

        return '<div class="taxmod-record">' . ($r->action === null ? $innen
            : '<form method="post" action="' . self::e($r->action) . '">' . $this->draw(new Carried($r->carried))
                . ($knoepfe === '' ? '' : '<div class="taxmod-record-acts taxmod-toolbar" role="toolbar">' . $knoepfe . '</div>')
                . $innen . '</form>') . '</div>';
    }

    /** Shown without labels and nothing in any field: an empty value, not an empty frame (D-869). */
    private function compact(CompactView $c): string
    {
        if ($c->parts === []) {
            return '';
        }

        $felder = array_map(fn (array $p): string => $this->draw($p['field']), $c->parts);

        if ($c->emptyIsAValue && array_filter($felder, static fn (string $m): bool => trim(strip_tags($m)) !== '' || str_contains($m, '<img') || str_contains($m, '<input')) === []) {
            return '<span class="taxmod-value"></span>';
        }

        $innen = '';

        foreach ($c->parts as $i => $p) {
            // D-1538: a part keeps together and the line breaks between parts, never inside one — a size of three unit values stood
            // cut off at the right edge (Versand, 1300 px).
            $innen .= '<span class="taxmod-compact-part" style="flex:0 0 auto">'
                . ($c->withLabel ? '<span class="taxmod-compact-label">' . self::e($p['name']) . '</span>' : '')
                . '<span class="taxmod-compact-field">' . $felder[$i] . '</span>' . HintMarkup::icon($p['hint']) . '</span>';
        }

        // `.25em` is the width of a space in this font; `.5em` looked like columns, which compact is not. Wrapping, a broken line keeps
        // the same `.25em` between its rows.
        return '<div class="taxmod-compact taxmod-compact-' . ($c->vertical ? \Taxmod\Core\Renderer\Orientation::Vertical : \Taxmod\Core\Renderer\Orientation::Horizontal)->value . '" style="display:flex;gap:.25em;'
            . ($c->vertical ? 'flex-direction:column;align-items:flex-start;flex-wrap:wrap' : 'flex-direction:row;align-items:baseline;flex-wrap:wrap;max-width:100%') . '">'
            . $innen . ($c->gathered === null ? '' : $this->draw($c->gathered)) . '</div>';
    }

    /** @param array{title: string, body: Describes|string, collapsed: bool} $b */
    private function pageBlock(array $b): string
    {
        return $b['collapsed']
            ? '<details class="taxmod-page-block" style="margin-top:1em"><summary style="cursor:pointer;font-weight:600">' . self::e($b['title']) . '</summary>' . $this->part($b['body']) . '</details>'
            : '<div class="taxmod-page-block">' . ($b['title'] === '' ? '' : '<h3 style="margin:1em 0 .4em">' . self::e($b['title']) . '</h3>') . $this->part($b['body']) . '</div>';
    }

    /** The arrows move a line by script and never submit; the first cannot go up, the last not down. Removing is always the bin (D-828). */
    private function orderedChoice(OrderedChoice $o): string
    {
        $form    = $o->form === '' ? '' : ' form="' . self::e($o->form) . '"';
        $letzter = count($o->chosen) - 1;
        $pfeil   = static fn (string $wohin, bool $aus, string $wort): string => '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-move" data-taxmod-move="' . $wohin . '"'
            . ($aus ? ' disabled style="color:#1d2327;opacity:.35"' : ' style="color:#1d2327"') . '>' . IconMarkup::dashicon('arrow-' . $wohin . '-alt2', $wort) . '</button>';
        $html    = '<span class="taxmod-switch-picker"><ol class="taxmod-switch-cascade">';

        foreach ($o->chosen as $platz => $g) {
            $html .= '<li class="taxmod-switch-chosen" data-taxmod-id="' . $g['id'] . '">'
                . '<input type="hidden" class="taxmod-switch-member" name="' . self::e($g['name']) . '" value="1"' . $form . '>'
                . '<span class="taxmod-switch-name">' . self::e($g['word']) . '</span>'
                . ($g['positionName'] === null ? ''
                    : '<input type="hidden" class="taxmod-setting-list-position" name="' . self::e($g['positionName']) . '" value="' . $g['position'] . '"' . $form . '>'
                        . ' ' . $pfeil('up', $platz === 0, $g['word']) . $pfeil('down', $platz === $letzter, $g['word']))
                . '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-list-remove" style="color:#b32d2e">' . IconMarkup::dashicon('trash', $g['word']) . '</button>'
                . '</li>';
        }

        return $html . '</ol><span class="taxmod-switch-add">' . $this->draw($o->offer) . $this->draw($o->add) . '</span></span>';
    }

    private function sharedBody(SharedBody $platz): string
    {
        $daten = ' data-taxmod-body="' . self::e($platz->key) . '" data-taxmod-name="' . self::e($platz->name) . '"'
            . ' data-taxmod-form="' . self::e($platz->form) . '" data-taxmod-value="' . self::e($platz->value) . '">'
            . implode('', array_map($this->draw(...), $platz->kept));

        return $platz->ofRecords
            ? '<div class="taxmod-record-tree"><div class="taxmod-record-stub"' . $daten . '</div></div>'
            : '<span class="taxmod-record-stub"' . $daten . '</span>';
    }

    private function nodeInTree(NodeInTree $n): string
    {
        // ⚠️ *Die Klasse steht im Tooltip, nicht in der Zeile (D-1450): sein Wort «wenn das nicht zu sehen ist und nur im Tooltip, ist auch nicht schlimm».*
        $klasse = $n->classWord === '' ? '' : ' title="' . self::e($n->classWord) . '" data-taxmod-class="' . self::e($n->classWord) . '"';
        $named  = ($n->icon === null ? '' : $this->draw($n->icon) . ' ') . '<span class="taxmod-tree-label"' . $klasse . '>' . self::e($n->name) . '</span>';

        if ($n->href !== null) {
            $named = '<a href="' . self::e($n->href) . '" class="taxmod-tree-select" style="text-decoration:none;color:inherit">' . $named . '</a>';
        }

        // A `div`, not a `span`: the controls are a form, and a form may not sit inside phrasing content.
        $markup = '<div class="taxmod-tree-node' . ($n->hidden ? ' taxmod-tree-node-hidden' : '') . '" id="' . self::e($n->rowPrefix) . $n->id . '">' . $named;

        $acts = $n->actsAt === null || $n->actions === [] ? '' : '<form method="post" action="' . self::e($n->actsAt) . '" style="display:flex;gap:.2em">'
            . ($n->carried === null ? '' : $this->draw($n->carried)) . implode('', array_map($this->draw(...), $n->actions)) . '</form>';
        $writes = $n->writes === null ? '' : '<span class="taxmod-tree-writes" style="opacity:.55">' . $n->writes . '</span>';

        if ($acts === '' && $writes === '') {
            return $markup . '</div>';
        }

        // Right-aligned, the owner's ask: the names stay a readable column and the controls line up.
        return $markup . '<span class="taxmod-tree-tail" style="margin-left:auto;display:flex;gap:.4em;align-items:center">' . $acts . $writes . '</span></div>';
    }

    private function pickable(PickableNode $p): string
    {
        $knopf = self::tag('input', ['hidden' => $p->unseen, 'type' => 'radio', 'name' => $p->name, 'value' => (string) $p->value, 'checked' => $p->chosen, 'form' => $p->form]);

        return match (true) {
            $p->unseen   => $knopf,
            ! $p->offered => '<span class="taxmod-chooser-row taxmod-chooser-fixed">' . $this->draw($p->node) . '</span>',
            default      => '<label class="taxmod-chooser-row">' . $knopf . ' ' . $this->draw($p->node) . '</label>',
        };
    }

    /** One column at varying depth, not a table: a table forces every row to the height of its tallest cell. */
    private function tree(TreeView $tree): string
    {
        $markup = '';

        foreach ($tree->lines as $line) {
            // The constant shape is in the stylesheet (D-816); inline stays only what differs per line, and the script switches `display`.
            $stil = array_filter([$line->shut ? 'display:none' : '', $line->highlighted ? 'background:#e8f0fb' : '']);

            $markup .= '<div class="taxmod-tree-row" data-depth="' . $line->depth . '"' . ($stil === [] ? '' : ' style="' . implode(';', $stil) . '"') . '>'
                . '<span class="taxmod-tree-indent" style="width:' . number_format($line->depth * self::TREE_STEP, 2, '.', '') . 'em"></span>'
                . self::fold($line)
                . '<span class="taxmod-tree-cell">' . $this->draw($line->cell) . '</span>'
                . '</div>';
        }

        // A sign, not a word (AR-2).
        $suche = '<div class="taxmod-tree-search"><span aria-hidden="true">&#128269;</span>'
            . self::tag('input', ['type' => 'search', 'class' => 'taxmod-tree-filter', 'name' => $tree->searchName, 'value' => $tree->searchValue, 'form' => $tree->searchForm])
            . '</div>';

        return '<div class="taxmod-tree">' . $suche . $markup . '</div>';
    }

    /** A leaf keeps the space of the fold, or the depth stops being readable. */
    private static function fold(TreeLine $line): string
    {
        $zeichen = $line->collapsed ? '&#9656;' : '&#9662;';

        return match ($line->fold) {
            null    => '<span class="taxmod-tree-fold-box"></span>',
            '#'     => '<a href="#" class="taxmod-tree-fold taxmod-tree-fold-box" data-fold="' . ($line->collapsed ? 'zu' : 'auf') . '">' . $zeichen . '</a>',
            default => '<a href="' . self::e($line->fold) . '" class="taxmod-tree-fold-box">' . $zeichen . '</a>',
        };
    }

    private function treeChoice(TreeChoice $wahl): string
    {
        if ($wahl->tree === null) {
            return '<span class="taxmod-unsatisfiable">' . self::e($wahl->why) . '</span>';
        }

        if ($wahl->inDialog) {
            return '<span class="taxmod-chooser-tree">' . $this->part($wahl->tree) . '</span>';
        }

        return '<div class="taxmod-chooser taxmod-chooser-open"' . ($wahl->width === null ? '' : ' style="width:' . $wahl->width . 'ch"') . '>'
            . '<div class="taxmod-chooser-tree">' . $this->part($wahl->tree) . '</div></div>';
    }

    private static function current(CurrentChoice $jetzt): string
    {
        return match (true) {
            $jetzt->word !== null     => self::e($jetzt->word),
            $jetzt->dangling !== null => '<span class="taxmod-dangling">' . self::e('#' . $jetzt->dangling) . '</span>',
            default                   => self::NOTHING . '<span class="screen-reader-text">' . self::e($jetzt->forReader) . '</span>',
        };
    }

    private function shown(ShownValue $value): string
    {
        $text = $value->text;

        return match ($value->kind) {
            ValueKind::Unchosen => '<span class="taxmod-value taxmod-no-renderer">' . self::e($text) . '</span>',
            ValueKind::Dangling => '<span class="taxmod-value taxmod-dangling">' . self::e($text) . '</span>',
            ValueKind::Record   => self::valueSpan($value->target === '' ? self::e($text) : '<a class="taxmod-record-link" href="' . self::e($value->target) . '">' . self::e($text) . '</a>'),
            ValueKind::Link     => self::valueSpan($value->target === '' ? self::e($text) : '<a class="taxmod-field-link" href="' . esc_url($value->target, ['http', 'https']) . '" target="_blank" rel="noopener">' . self::e($text) . '</a>'),
            ValueKind::LongText => '<span class="taxmod-value" style="white-space:pre-wrap">' . self::e($text) . '</span>',
            ValueKind::Code     => '<pre class="taxmod-value taxmod-code" data-taxmod-code="' . self::e($value->target) . '">' . self::e($text) . '</pre>',
            ValueKind::Text     => self::valueSpan(self::e($text)),
            ValueKind::Colour   => self::valueSpan($text === '' ? '' : self::patch($text) . self::e($text)),
            ValueKind::Email    => self::valueSpan($text === '' ? '' : '<a href="mailto:' . self::e($text) . '">' . self::e($text) . '</a>'),
            ValueKind::Jump     => self::valueSpan($value->target === '' ? '' : '<a class="taxmod-jump" href="' . self::e($value->target) . '">'
                . IconMarkup::dashicon('external', $text) . '</a>'),
            ValueKind::Flag     => self::valueSpan($text === '' ? '' : self::tag('input', ['type' => 'checkbox', 'disabled' => true, 'checked' => $text === '1'])),
            ValueKind::Switch   => self::valueSpan($text === '' ? '' : ToggleMarkup::track($text === '1', false)),
            ValueKind::Chosen   => $text === '' ? self::NOTHING : '<span class="taxmod-choice">' . self::e($text) . '</span>',
            ValueKind::NotOffered => '<span class="taxmod-choice taxmod-unknown-choice">' . self::e($text) . '</span>',
            ValueKind::ChosenColour => $text === '' ? self::NOTHING
                : '<span class="taxmod-choice taxmod-color-choice">' . self::swatch($value->target) . self::e($text) . '</span>',
        };
    }

    private function input(ValueInput $in): string
    {
        return self::control($in);
    }

    /**
     * D-1501: the markup of one input as the form renderer draws it — the single place every page form and module form
     * reaches through `ListPage::field`, so no page writes its own `<input>`.
     */
    public static function control(ValueInput $in): string
    {
        $required = $in->required ? 'true' : null;

        return match ($in->kind) {
            InputKind::Unchosen => self::tag('input', ['type' => 'text', 'class' => 'taxmod-no-renderer', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value]),
            InputKind::Line     => self::tag('input', [
                'type' => 'text', 'id' => $in->id, 'aria-label' => $in->ariaLabel, 'placeholder' => $in->placeholder, 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'size' => $in->size, 'pattern' => $in->pattern, 'inputmode' => $in->inputMode, 'list' => $in->suggestions,
            ]),
            InputKind::Number   => self::tag('input', [
                'type' => 'number', 'id' => $in->id, 'aria-label' => $in->ariaLabel, 'placeholder' => $in->placeholder, 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'min' => $in->min, 'max' => $in->max, 'step' => $in->step,
            ]),
            InputKind::Decimal  => self::tag('input', [
                'type' => 'text', 'id' => $in->id, 'aria-label' => $in->ariaLabel, 'placeholder' => $in->placeholder, 'inputmode' => 'decimal', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required,
                'value' => $in->value, 'size' => $in->size,
            ]),
            InputKind::Secret   => self::tag('input', [
                'type' => 'password', 'id' => $in->id, 'aria-label' => $in->ariaLabel, 'placeholder' => $in->placeholder, 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => '',
                'autocomplete' => 'new-password', 'size' => $in->size,
            ]),
            InputKind::Upload   => self::tag('input', [
                'type' => 'file', 'id' => $in->id, 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required,
                'accept' => $in->note, 'multiple' => str_ends_with($in->name, '[]'),
            ]),
            InputKind::Range    => self::tag('input', [
                'type' => 'range', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'min' => $in->min, 'max' => $in->max, 'step' => $in->step,
            ]) . self::valueSpan(self::e($in->shown ?? '')),
            InputKind::Colour   => $in->value !== '' && ! self::isPatchable($in->value)
                ? self::tag('input', ['type' => 'text', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value])
                : self::tag('input', ['type' => 'color', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value]),
            InputKind::Email    => self::tag('input', ['type' => 'email', 'id' => $in->id, 'aria-label' => $in->ariaLabel, 'placeholder' => $in->placeholder, 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value]),
            InputKind::LongText => '<textarea' . self::attribute('id', $in->id) . self::attribute('placeholder', $in->placeholder) . self::attribute('name', $in->name) . self::attribute('form', $in->form)
                . ($in->required ? ' aria-required="true"' : '') . self::attribute('cols', $in->columns) . self::attribute('rows', $in->rows)
                . '>' . self::e($in->value) . '</textarea>',
            InputKind::Code     => '<textarea class="large-text code taxmod-code" spellcheck="false"' . self::attribute('data-taxmod-code', $in->note)
                . self::attribute('name', $in->name) . self::attribute('form', $in->form) . ($in->required ? ' aria-required="true"' : '')
                . self::attribute('rows', $in->rows) . '>' . self::e($in->value) . '</textarea>',
            // The hidden field is what makes *unticked* mean false rather than absent; PHP keeps the last of two equal names.
            InputKind::Flag     => self::tag('input', ['type' => 'hidden', 'name' => $in->name, 'form' => $in->form, 'value' => '0'])
                . self::tag('input', ['type' => 'checkbox', 'name' => $in->name, 'form' => $in->form, 'value' => '1', 'checked' => $in->value === '1']),
            InputKind::Switch   => ToggleMarkup::input($in->name, $in->value === '1', $in->form),
            InputKind::Tick     => self::tag('input', ['type' => 'checkbox', 'id' => $in->id, 'aria-label' => $in->ariaLabel, 'name' => $in->name, 'form' => $in->form, 'value' => $in->value, 'checked' => $in->checked]),
            InputKind::Choice   => self::choice($in),
            InputKind::Moment   => self::tag('input', [
                'type' => self::momentType($in->note), 'id' => $in->id, 'aria-label' => $in->ariaLabel, 'placeholder' => $in->placeholder, 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'pattern' => $in->pattern, 'min' => $in->min, 'max' => $in->max,
            ]),
            InputKind::Fixed    => self::tag('input', ['type' => 'text', 'class' => 'taxmod-locked', 'value' => $in->shown ?? '', 'disabled' => true, 'title' => $in->note])
                . self::tag('input', ['type' => 'hidden', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value]),
        };
    }

    private function choosing(Choosing $wahl): string
    {
        $merkmale = match ($wahl->purpose) {
            ChoicePurpose::Value, ChoicePurpose::Colour => [],
            ChoicePurpose::Summary        => ['class' => 'taxmod-summary-choice'],
            ChoicePurpose::Candidate      => ['class' => 'taxmod-switch-candidates'],
            ChoicePurpose::ValueCandidate => ['class' => 'taxmod-switch-candidates taxmod-value-candidates', 'data-taxmod-name' => $wahl->name],
            ChoicePurpose::AddonCandidate => ['class' => 'taxmod-addon-candidates'],
            ChoicePurpose::ReferenceRow   => ['class' => 'taxmod-ref-select'],
        };

        if ($wahl->width !== null && $wahl->width > 0) {
            $merkmale['style'] = 'width:' . $wahl->width . 'ch';
        }

        if ($wahl->hint !== null) {
            $merkmale['title'] = $wahl->hint;
        }

        // D-1552: a filter's value follows the field chosen in its row — the page asks for that field's values.
        if ($wahl->valueOf > 0) {
            $merkmale['data-taxmod-value-of'] = (string) $wahl->valueOf;
        }

        // D-1550: a field choice refills itself when the node it reads from is chosen anew — no save needed in between.
        if ($wahl->fieldsOf > 0) {
            $merkmale['data-taxmod-fields-of']  = (string) $wahl->fieldsOf;
            $merkmale['data-taxmod-fields-url'] = add_query_arg(['action' => \Taxmod\WordPress\Admin\NodesScreen::NODE_FIELDS_ACTION, 'nonce' => wp_create_nonce(\Taxmod\WordPress\Admin\NodesScreen::NODE_FIELDS_ACTION)], admin_url('admin-ajax.php'));

            // D-1552: the row's kind decides whose fields are offered; the page sends it with the question.
            if ($wahl->kindField > 0) {
                $merkmale['data-taxmod-kind-field'] = (string) $wahl->kindField;
                $merkmale['data-taxmod-kind-key']   = (string) $wahl->kindKey;
                $merkmale['data-taxmod-kind-from']  = (string) $wahl->kindFieldsFrom;
            }
        }

        if ($wahl->purpose !== ChoicePurpose::Colour) {
            return SelectMarkup::select($wahl, $merkmale);
        }

        $merkmale['data-taxmod-swatches'] = (string) json_encode($wahl->swatches);

        return '<span class="taxmod-color-choice">' . self::swatch($wahl->swatches[(int) $wahl->now] ?? '') . SelectMarkup::select($wahl, $merkmale) . '</span>';
    }

    /** The patch of a chosen colour; dashed where the colour is not one a browser draws. */
    private static function swatch(string $hex): string
    {
        return '<span class="taxmod-swatch" style="display:inline-block;width:1em;height:1em;vertical-align:middle;margin-right:.3em;'
            . (self::isPatchable($hex) ? 'border:1px solid #8c8f94;background:' . self::e($hex) : 'border:1px dashed #8c8f94')
            . '"></span>';
    }

    private static function mediaLink(MediaLink $link): string
    {
        $auf  = $link->newTab ? ' target="_blank" rel="noopener"' : '';
        $wort = self::e($link->word);

        return match ($link->look) {
            MediaLook::Private           => '<a class="taxmod-media taxmod-media-private" href="' . self::e($link->url) . '">' . $wort . '</a>',
            MediaLook::PrivateUnreadable => '<span class="taxmod-media taxmod-media-private">' . $wort . '</span>',
            MediaLook::Missing           => '<span class="taxmod-media taxmod-media-missing">' . $wort . '</span>',
            MediaLook::Image             => '<a class="taxmod-media taxmod-media-image" href="' . self::e($link->url) . '" title="' . $wort . '"' . $auf . '>'
                . '<img class="taxmod-media-thumb" src="' . self::e($link->thumbnail) . '" alt="' . $wort . '" loading="lazy"></a>',
            MediaLook::File              => '<a class="taxmod-media" href="' . self::e($link->url) . '"' . $auf . '>' . $wort . '</a>',
            MediaLook::Address           => '<a class="taxmod-media" href="' . self::e($link->url) . '"' . $auf . '>' . $wort . '</a>'
                . ($link->state === '' ? '' : ' <span class="taxmod-media-state">' . self::e($link->state) . '</span>'),
        };
    }

    private static function mediaButtons(MediaButtons $knoepfe): string
    {
        $daten = ($knoepfe->addressField === '' ? '' : ' data-taxmod-address="' . self::e($knoepfe->addressField) . '"')
            . ($knoepfe->captionField === '' ? '' : ' data-taxmod-caption="' . self::e($knoepfe->captionField) . '"')
            . ' data-taxmod-newtab="' . ($knoepfe->newTab ? '1' : '0') . '"';
        $knopf = static fn (string $klasse, string $symbol, string $wort): string => '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' ' . $klasse . '" style="color:#1d2327"'
            . ($wort === '' ? '' : ' title="' . self::e($wort) . '"') . $daten . '>' . IconMarkup::dashicon($symbol, $wort) . '</button>';

        return $knopf('taxmod-media-library', 'media-default', $knoepfe->libraryWord) . $knopf('taxmod-media-wplink', 'admin-links', $knoepfe->linkWord);
    }

    private function mediaPicker(MediaPicker $feld): string
    {
        $link = '<input type="text" inputmode="url" class="taxmod-media-link" name="' . self::e($feld->name) . '"'
            . ' value="' . self::e($feld->address) . '" size="30"' . ($feld->form === '' ? '' : ' form="' . self::e($feld->form) . '"') . '>';

        return $feld->buttons === null
            ? $link
            : '<span class="taxmod-media-input">' . $link . self::mediaButtons($feld->buttons) . ($feld->current === null ? '' : ' ' . $this->draw($feld->current)) . '</span>';
    }

    private static function momentType(string $precision): string
    {
        return match ($precision) {
            'year'  => 'number',
            'month' => 'month',
            'date'  => 'date',
            'time'  => 'time',
            'any'   => 'text',
            default => 'datetime-local',
        };
    }

    private static function valueSpan(string $markup): string
    {
        return '<span class="taxmod-value">' . $markup . '</span>';
    }

    private static function isPatchable(string $colour): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1;
    }

    private static function patch(string $colour): string
    {
        return self::isPatchable($colour)
            ? '<span class="taxmod-swatch" style="display:inline-block;width:1em;height:1em;vertical-align:middle;margin-right:.3em;border:1px solid #8c8f94;background:'
                . self::e($colour) . '"></span>'
            : '';
    }

    /** @param array<string, string|bool|null> $attributes empty, null and false leave the attribute out; true writes it bare */
    /** D-1504: the radio buttons of a page's Choice, each inside its label so the word is the target. */
    private static function choice(ValueInput $in): string
    {
        $markup = '';

        foreach ($in->choices as $value => $word) {
            $value   = (string) $value;
            $markup .= '<label' . ($in->lines ? ' style="display:block"' : '') . '>' . self::tag('input', [
                'type' => 'radio', 'name' => $in->name, 'form' => $in->form, 'value' => $value, 'checked' => $in->value === $value,
                'disabled' => in_array($value, array_map('strval', $in->locked), true), 'aria-required' => $in->required ? 'true' : null,
            ]) . ' ' . self::e($word) . '</label>' . ($in->lines ? '' : ' ');
        }

        return $in->lines ? $markup : rtrim($markup);
    }

    private static function tag(string $name, array $attributes): string
    {
        $markup = '<' . $name;

        foreach ($attributes as $attribute => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }

            $markup .= $value === true ? ' ' . $attribute : ' ' . $attribute . '="' . self::e($value) . '"';
        }

        return $markup . '>';
    }

    private static function attribute(string $name, ?string $value): string
    {
        return $value === null || $value === '' ? '' : ' ' . $name . '="' . self::e($value) . '"';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
