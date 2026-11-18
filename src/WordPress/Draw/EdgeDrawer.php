<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Description\ActsForm;
use Taxmod\Core\Description\AddChosen;
use Taxmod\Core\Description\ChoicePurpose;
use Taxmod\Core\Description\Choosing;
use Taxmod\Core\Description\Carried;
use Taxmod\Core\Description\CurrentChoice;
use Taxmod\Core\Description\NodeInTree;
use Taxmod\Core\Description\PickableNode;
use Taxmod\Core\Description\RecordBrowser;
use Taxmod\Core\Description\RecordChoice;
use Taxmod\Core\Description\RecordPicker;
use Taxmod\Core\Description\SharedBody;
use Taxmod\Core\Description\TreeChoice;
use Taxmod\Core\Description\TreeLine;
use Taxmod\Core\Description\TreeView;
use Taxmod\Core\Description\Describes;
use Taxmod\Core\Description\DialogBox;
use Taxmod\Core\Description\Drawn;
use Taxmod\Core\Description\Hint;
use Taxmod\Core\Description\Icon;
use Taxmod\Core\Description\Drawer;
use Taxmod\Core\Description\InputKind;
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
            $piece instanceof Drawn      => $piece->markup,
            $piece instanceof ShownValue => $this->shown($piece),
            $piece instanceof ValueInput => $this->input($piece),
            $piece instanceof Locked     => '<fieldset disabled class="taxmod-read-only">' . $this->draw($piece->inner) . '</fieldset>',
            $piece instanceof Sequence   => implode('', array_map($this->draw(...), $piece->pieces)),
            $piece instanceof Choosing   => $this->choosing($piece),
            $piece instanceof Icon       => $piece->character ? IconMarkup::glyph($piece->symbol, $piece->label) : IconMarkup::dashicon($piece->symbol, $piece->label),
            $piece instanceof Hint       => HintMarkup::icon($piece->text),
            $piece instanceof MediaLink  => $piece->asValue ? self::valueSpan(self::mediaLink($piece)) : self::mediaLink($piece),
            $piece instanceof MediaButtons => self::mediaButtons($piece),
            $piece instanceof MediaPicker => $this->mediaPicker($piece),
            $piece instanceof Control    => ControlMarkup::button($piece),
            $piece instanceof ActsForm   => ControlMarkup::actsForm($piece->id, $piece->submits, $piece->controls),
            $piece instanceof Carried    => ControlMarkup::carried($piece),
            $piece instanceof DialogBox  => DialogMarkup::of($piece->switch, $this->part($piece->opener), $this->part($piece->head), $this->part($piece->body), $this->part($piece->foot), $piece->openerClass, $piece->ok, $piece->cancel, $piece->open, $piece->tree),
            $piece instanceof AddChosen  => SelectMarkup::add($piece, $piece->purpose === ChoicePurpose::AddonCandidate ? 'taxmod-addon-add' : 'taxmod-list-add'),
            $piece instanceof NodeInTree => $this->nodeInTree($piece),
            $piece instanceof PickableNode => $this->pickable($piece),
            $piece instanceof TreeView   => $this->tree($piece),
            $piece instanceof TreeChoice => $this->treeChoice($piece),
            $piece instanceof CurrentChoice => self::current($piece),
            $piece instanceof SharedBody => $this->sharedBody($piece),
            $piece instanceof RecordChoice => self::recordChoice($piece),
            $piece instanceof RecordBrowser => $this->recordBrowser($piece),
            $piece instanceof RecordPicker => $this->recordPicker($piece),
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

    private function recordPicker(RecordPicker $p): string
    {
        $offen = $p->taken && $p->href !== null && $p->href !== '' && $p->openWord !== ''
            ? '<a class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-open" href="' . self::e($p->href) . '" target="_blank" rel="noopener" title="' . self::e($p->openWord) . '">'
                . IconMarkup::dashicon('edit', $p->openWord) . '</a>'
            : '';
        $ende = '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-clear"' . ($p->taken ? '' : ' hidden') . ' style="color:#b32d2e" title="' . self::e($p->clearWord) . '">'
                . IconMarkup::dashicon('trash', $p->clearWord) . '</button>'
            . '<span class="taxmod-record-hits" hidden></span>'
            . '</span>';

        if ($p->dialog !== null) {
            return '<span class="taxmod-record-pick' . ($p->taken ? ' taxmod-record-taken' : '') . '">' . $this->draw($p->dialog) . $offen . $ende;
        }

        $nichts = ! $p->taken;
        $knopf  = fn (string $wert, bool $an): string => '<label class="taxmod-record-choice"><input type="radio" name="' . self::e($p->name) . '" value="' . $wert . '"'
            . ($an ? ' checked' : '') . ($p->form === '' ? '' : ' form="' . self::e($p->form) . '"') . '></label>';

        return '<span class="taxmod-record-pick taxmod-record-remote' . ($nichts ? '' : ' taxmod-record-taken') . '">'
            . '<span class="taxmod-record-choices" hidden>' . $knopf('', $nichts) . ($nichts ? '' : $knopf((string) $p->chosen, true)) . '</span>'
            . '<input type="search" class="taxmod-record-quick" autocomplete="off" placeholder="' . self::e($p->searchWord) . '" aria-label="' . self::e($p->searchWord) . '"' . ($nichts ? '' : ' hidden') . '>'
            . ($p->treeWord === '' ? '' : '<button type="button" class="button ' . ControlMarkup::ICON_ONLY . ' taxmod-record-browse"' . ($nichts ? '' : ' hidden') . ' title="' . self::e($p->treeWord) . '">'
                . IconMarkup::dashicon('networking', $p->treeWord) . '</button>')
            . '<span class="taxmod-record-dialog-open taxmod-record-word"' . ($nichts ? ' hidden' : '') . '>' . self::e($p->word) . '</span>'
            . $offen . $ende;
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
        $named = ($n->icon === null ? '' : $this->draw($n->icon) . ' ') . '<span class="taxmod-tree-label">' . self::e($n->name) . '</span>';

        if ($n->classWord !== '') {
            $named .= ' <span class="taxmod-tree-class">' . self::e($n->classWord) . '</span>';
        }

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
        $required = $in->required ? 'true' : null;

        return match ($in->kind) {
            InputKind::Unchosen => self::tag('input', ['type' => 'text', 'class' => 'taxmod-no-renderer', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value]),
            InputKind::Line     => self::tag('input', [
                'type' => 'text', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'size' => $in->size, 'pattern' => $in->pattern, 'inputmode' => $in->inputMode,
            ]),
            InputKind::Number   => self::tag('input', [
                'type' => 'number', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'min' => $in->min, 'max' => $in->max, 'step' => $in->step,
            ]),
            InputKind::Range    => self::tag('input', [
                'type' => 'range', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
                'min' => $in->min, 'max' => $in->max, 'step' => $in->step,
            ]) . self::valueSpan(self::e($in->shown ?? '')),
            InputKind::Colour   => $in->value !== '' && ! self::isPatchable($in->value)
                ? self::tag('input', ['type' => 'text', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value])
                : self::tag('input', ['type' => 'color', 'name' => $in->name, 'form' => $in->form, 'value' => $in->value]),
            InputKind::Email    => self::tag('input', ['type' => 'email', 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value]),
            InputKind::LongText => '<textarea' . self::attribute('name', $in->name) . self::attribute('form', $in->form)
                . ($in->required ? ' aria-required="true"' : '') . self::attribute('cols', $in->columns) . self::attribute('rows', $in->rows)
                . '>' . self::e($in->value) . '</textarea>',
            InputKind::Code     => '<textarea class="large-text code taxmod-code" spellcheck="false"' . self::attribute('data-taxmod-code', $in->note)
                . self::attribute('name', $in->name) . self::attribute('form', $in->form) . ($in->required ? ' aria-required="true"' : '')
                . self::attribute('rows', $in->rows) . '>' . self::e($in->value) . '</textarea>',
            // The hidden field is what makes *unticked* mean false rather than absent; PHP keeps the last of two equal names.
            InputKind::Flag     => self::tag('input', ['type' => 'hidden', 'name' => $in->name, 'form' => $in->form, 'value' => '0'])
                . self::tag('input', ['type' => 'checkbox', 'name' => $in->name, 'form' => $in->form, 'value' => '1', 'checked' => $in->value === '1']),
            InputKind::Switch   => ToggleMarkup::input($in->name, $in->value === '1', $in->form),
            InputKind::Moment   => self::tag('input', [
                'type' => self::momentType($in->note), 'name' => $in->name, 'form' => $in->form, 'aria-required' => $required, 'value' => $in->value,
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
