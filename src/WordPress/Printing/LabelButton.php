<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\FilledLabel;
use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelDocument;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Printing\LabelTemplate;
use Taxmod\WordPress\Plugin;

/**
 * «Etikett drucken» for any module: the filter {@see self::FILTER} returns a button that opens the shared dialog — choose the
 * format, then send it to the print service or open it as PDF. One button for every module, so a place, a box or a material
 * prints the same way (D-1120).
 *
 * The button brings its own form; place it outside any other form.
 *
 * @see docs/NewConcept/90-decision-log.md D-1120
 */
final class LabelButton
{
    /**
     * Filter: `(string $html, string $prefix 'O'|'M'|'E'|'K', int $id, string $title, string $subtitle, int $copies = 1, array $template = []): string`.
     *
     * D-1153: `$template` = `['name' => 'Platz', 'format' => ['key', 'w', 'h', 'roll'], 'layout' => JSON, 'values' => [field ⇒ text]]` —
     * the label is then drawn from that layout; `code`, `title` and `subtitle` are among its values.
     * D-1191: `'printer' => ['queue', 'roll', 'turn', 'options', 'turn_options', 'sizes', 'offset', 'line', 'name']` — the printer
     * whose roll the label fits; the job then carries its queue and the CUPS options for this label to the print service.
     * D-1192: `'choices' => [id => template]`, `'chosen' => id`, `'printers' => [id => printer + 'allows' => [template ids]]` —
     * the dialog offers the labels to choose from, the chosen one first, and the printers whose loaded roll allows it.
     * D-1160: with `'record' => id` the modeller reads the fields the layout names from that record itself; what the module
     * passes in `values` only fills what the record has no field for.
     */
    public const FILTER = 'taxmod_label_button';

    public const ACTION = 'taxmod_label_queue';

    private const NONCE = '_taxmod_label_nonce';

    /** The format a label of this kind starts with in the dialog. */
    private const DEFAULT_FORMAT = ['O' => 'holder62', 'K' => 'roll62', 'M' => 'material62', 'E' => 'material62'];

    /** More copies than this in one go is a typo, not a wish. */
    public const MAX_COPIES = 50;

    /** @var (\Closure(int, list<string>): array{values: array<string, string>, unknown: list<string>, node: int})|null */
    private static ?\Closure $recordValues = null;

    /** @param (\Closure(int, list<string>): array{values: array<string, string>, unknown: list<string>, node: int})|null $recordValues */
    public static function boot(?\Closure $recordValues = null): void
    {
        self::$recordValues = $recordValues;
        add_filter(self::FILTER, static fn (mixed $html, string $prefix = '', int $id = 0, string $title = '', string $subtitle = '', int $copies = 1, array $template = []): string => self::render($prefix, $id, $title, $subtitle, $copies, $template), 10, 7);
        add_action('admin_post_' . self::ACTION, self::handle(...));
    }

    /** @param array<string, mixed> $template */
    public static function render(string $prefix, int $id, string $title, string $subtitle = '', int $copies = 1, array $template = []): string
    {
        $kind = LabelPrefix::tryFrom($prefix);
        if ($kind === null || $id <= 0 || trim($title) === '' || ! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }
        do_action(Plugin::STYLE_ACTION);

        $label = LabelContent::of($kind, $id, $title, $subtitle);
        $chooser = self::chooser($label, $template);
        $filled = $chooser === null ? self::filled($label, $template) : null;
        $default = self::DEFAULT_FORMAT[$kind->value];
        $options = '';
        foreach (LabelFormat::shipped() as $format) {
            $options .= '<option value="' . esc_attr($format->key) . '"' . selected($format->key, $default, false) . '>'
                . esc_html(PrintTab::formatName($format) . ' · ' . PrintTab::size($format)) . '</option>';
        }
        $service = PrintService::isSetUp();
        $switch = 'taxmod-label-' . strtolower($kind->value) . '-' . $id;

        $body = ($chooser !== null ? $chooser['html'] : ($filled === null
                ? '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Format', 'taxmod') . '</span>'
                    . '<select name="format">' . $options . '</select></label>'
                : '<span style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Vorlage', 'taxmod') . '</span>'
                    . esc_html(trim($filled->templateName . ' · ' . PrintTab::size($filled->format) . ($filled->printer === null ? '' : ' · ' . ($filled->printer->name !== '' ? $filled->printer->name : $filled->printer->queue)), ' ·')) . '</span>'))
            // D-1152, his word 2026-11-04: «Bei etikett druck anzahl mit aufnehmen» — the module says how many to start with.
            . '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Anzahl', 'taxmod') . '</span>'
            . '<input type="number" name="copies" min="1" max="' . self::MAX_COPIES . '" step="1" value="' . self::copies($copies) . '" style="width:6em"></label>'
            . '<span style="display:block">' . esc_html($label->code . ' · ' . $label->title . ($label->subtitle === '' ? '' : ' · ' . $label->subtitle)) . '</span>'
            . ($service ? '' : '<span class="description" style="display:block;margin-top:.5em">' . esc_html__('No print service set up: the label opens as PDF to print from the browser.', 'taxmod') . '</span>');

        $foot = ($service ? '<button type="submit" class="button button-primary" name="how" value="service">' . esc_html__('Print', 'taxmod') . '</button>' : '')
            . '<button type="submit" class="button' . ($service ? '' : ' button-primary') . '" name="how" value="pdf" formtarget="_blank">' . esc_html__('Open as PDF', 'taxmod') . '</button>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . wp_nonce_field(self::ACTION, self::NONCE, true, false)
            . '<input type="hidden" name="code" value="' . esc_attr($label->code) . '">'
            . '<input type="hidden" name="title" value="' . esc_attr($label->title) . '">'
            . '<input type="hidden" name="subtitle" value="' . esc_attr($label->subtitle) . '">'
            . ($filled === null ? '' : '<input type="hidden" name="document" value="' . esc_attr($filled->toJson()) . '">')
            . ($chooser === null ? '' : $chooser['hidden'])
            . \Taxmod\Core\Renderer\DialogMarkup::of(
                $switch,
                esc_html__('Etikett drucken', 'taxmod'),
                esc_html__('Print a label', 'taxmod'),
                '<span class="taxmod-dialog-body" style="display:block;padding:.75em 1em;white-space:normal;text-align:left">' . $body . '</span>',
                $foot,
                'button taxmod-dialog-open',
                '',
                __('Cancel', 'taxmod')
            )
            . '</form>'
            . self::sendScript();
    }

    /** admin-post: capability, nonce, validate, then queue for the service or hand out the PDF. */
    public static function handle(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            self::fail(esc_html__('You cannot print labels.', 'taxmod'), 403, ['taxmod_ajax' => $_POST['taxmod_ajax'] ?? '']);
        }
        check_admin_referer(self::ACTION, self::NONCE);

        $post = wp_unslash($_POST);
        $copies = self::copies((int) ($post['copies'] ?? 1));

        // D-1192: the label and the printer chosen in the dialog; a printer whose roll does not allow the label is refused.
        if (isset($post['choice'])) {
            $choice   = absint($post['choice']);
            $document = json_decode((string) (((array) ($post['documents'] ?? []))[$choice] ?? ''), true);
            $printer  = (string) ($post['printer'] ?? '');
            if (! is_array($document)) {
                self::fail(esc_html__('Unknown label.', 'taxmod'), 400, $post ?? []);
            }
            if ($printer !== '') {
                $raw = json_decode((string) (((array) ($post['printers'] ?? []))[absint($printer)] ?? ''), true);
                if (! is_array($raw) || ! in_array($choice, array_map('intval', (array) ($raw['allows'] ?? [])), true)) {
                    self::fail(esc_html__('This printer has no roll for this label.', 'taxmod'), 400, $post ?? []);
                }
                $document['printer'] = $raw;
            }
            // D-1199: the text chosen for the variant field, as the dialog showed it for this label.
            $field = (string) ($post['variant_field'] ?? '');
            $texts = (array) (((array) ($post['variants'] ?? []))[$choice] ?? []);
            if (preg_match('/^[a-z0-9_]{1,32}$/', $field) === 1 && isset($post['variant']) && array_key_exists((string) $post['variant'], $texts)) {
                $document['values'] = (is_array($document['values'] ?? null) ? $document['values'] : []);
                $document['values'][$field] = sanitize_text_field((string) $texts[(string) $post['variant']]);
            }
            // The record's own words for the fields the layout names, read now (see chooser()); they win, as in filled().
            $record = absint($post['record'] ?? 0);
            if ($record > 0) {
                $fields = array_values(array_filter(array_map(static fn (mixed $e): string => is_array($e) ? (string) ($e['field'] ?? $e['feld'] ?? '') : '', (array) ($document['layout'] ?? []))));
                $document['values'] = self::recordValues($record, $fields)['values'] + (is_array($document['values'] ?? null) ? $document['values'] : []);
            }
            $post['document'] = (string) json_encode($document);
        }

        // D-1153: a label from a template brings its own size and layout.
        if (is_string($post['document'] ?? null) && $post['document'] !== '') {
            try {
                $filled = FilledLabel::fromJson($post['document']);
            } catch (\InvalidArgumentException $e) {
                self::fail(esc_html($e->getMessage()), 400, $post ?? []);
            }
            $label = LabelPrint::labelFrom($filled->values);
            if ($label === null) {
                self::fail(esc_html__('A label needs a known format, a code like O-123 and a title.', 'taxmod'), 400, $post ?? []);
            }
            if (($post['how'] ?? '') === 'service' && PrintService::isSetUp()) {
                $jobs = new PrintJobs();
                $id = $jobs->queue($filled->format, $label, false, $filled->toJson(), $copies);
                self::answerQueued($post, $id);
                wp_safe_redirect(add_query_arg('taxmod_label_job', $id, wp_get_referer() ?: PrintJobsScreen::url()));
                exit;
            }
            self::sendPdf($filled->render($copies), $label->code);
        }

        $formats = LabelFormat::shipped();
        $format = $formats[sanitize_key((string) ($post['format'] ?? ''))] ?? null;
        $label = LabelPrint::labelFrom($post);
        if ($format === null || $label === null) {
            self::fail(esc_html__('A label needs a known format, a code like O-123 and a title.', 'taxmod'), 400, $post ?? []);
        }

        if (($post['how'] ?? '') === 'service' && PrintService::isSetUp()) {
            $jobs = new PrintJobs();
            $id = $jobs->queue($format, $label, false, null, $copies);
            self::answerQueued($post, $id);
            wp_safe_redirect(add_query_arg('taxmod_label_job', $id, wp_get_referer() ?: PrintJobsScreen::url()));
            exit;
        }

        self::sendPdf(LabelDocument::render($format, array_fill(0, $copies, $label)), $label->code);
    }

    /**
     * His word 2026-11-06: «lädt er danach die seite neu nur um eine meldung anzuzeigen … wäre auch gut wenn er deswegen die seite
     * nicht neuladen muss» — a print sent from the dialog's script is answered with the message alone.
     *
     * @param array<string, mixed> $post
     */
    private static function answerQueued(array $post, int $job): void
    {
        if (! empty($post['taxmod_ajax'])) {
            wp_send_json_success(['message' => sprintf(/* translators: %d: job number */ __('Job %d is waiting for the print service.', 'taxmod'), $job)]);
        }
    }

    /** @param array<string, mixed> $post */
    private static function fail(string $message, int $status, array $post): never
    {
        if (! empty($post['taxmod_ajax'])) {
            wp_send_json_error(['message' => wp_strip_all_tags($message)], $status);
        }
        wp_die($message, '', ['response' => $status]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
    }

    private static function sendPdf(string $pdf, string $code): never
    {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name('label-' . $code . '.pdf') . '"');
        header('Content-Length: ' . (string) strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF, not HTML.
        exit;
    }

    /**
     * D-1153: the module's template filled with this label's values, or null for the fixed layout of the chosen format.
     *
     * @param array<string, mixed> $template
     */
    public static function filled(LabelContent $label, array $template): ?FilledLabel
    {
        if ($template === []) {
            return null;
        }
        $values = LabelTemplate::valuesOf($label);
        $record = (int) ($template['record'] ?? 0);
        if ($record > 0) {
            $layout = json_decode((string) ($template['layout'] ?? ''), true);
            $fields = is_array($layout) ? array_values(array_filter(array_map(static fn (mixed $e): string => is_array($e) ? (string) ($e['field'] ?? $e['feld'] ?? '') : '', $layout))) : [];
            $values = self::recordValues($record, $fields)['values'] + $values;
        }
        foreach (is_array($template['values'] ?? null) ? $template['values'] : [] as $field => $value) {
            if (is_scalar($value) && ! isset($values[(string) $field])) {
                $values[(string) $field] = sanitize_text_field((string) $value);
            }
        }
        try {
            return FilledLabel::fromJson((string) json_encode([
                'format'   => $template['format'] ?? [],
                'layout'   => json_decode((string) ($template['layout'] ?? ''), true),
                'values'   => $values,
                'template' => (string) ($template['name'] ?? ''),
                'printer'  => is_array($template['printer'] ?? null) ? $template['printer'] : null,
            ]));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * D-1192: the label and printer chosen in the dialog — one select each, the chosen label first, the printers its roll allows.
     * Every label is filled here, so what prints is what the dialog showed; a small script keeps the printer list to the label.
     *
     * @param array<string, mixed> $template
     * @return array{html: string, hidden: string}|null
     */
    private static function chooser(LabelContent $label, array $template): ?array
    {
        $choices = is_array($template['choices'] ?? null) ? $template['choices'] : [];
        if ($choices === []) {
            return null;
        }
        $chosen   = (int) ($template['chosen'] ?? array_key_first($choices));
        $labels   = '';
        // ⚠️ *His wish 2026-11-06 «druck button pro positon»: a list draws one dialog per row, so the record's own words are read
        // when the label is printed, not when the dialog is drawn — no query per row (CD-7).*
        $record   = absint($template['record'] ?? 0);
        $hidden   = $record > 0 ? '<input type="hidden" name="record" value="' . $record . '">' : '';
        foreach ($choices as $id => $choice) {
            $filled = is_array($choice) ? self::filled($label, array_merge($choice, ['record' => 0])) : null;
            if ($filled === null) {
                continue;
            }
            $labels .= '<option value="' . (int) $id . '"' . selected((int) $id, $chosen, false) . '>' . esc_html(trim($filled->templateName . ' · ' . PrintTab::size($filled->format), ' ·')) . '</option>';
            $hidden .= '<input type="hidden" name="documents[' . (int) $id . ']" value="' . esc_attr($filled->toJson()) . '">';
            // D-1199: the texts a label may print in one of its fields, one per choice in the dialog — the module fills them.
            foreach (self::variantsOf($choice) as $key => $variant) {
                $hidden .= '<input type="hidden" name="variants[' . (int) $id . '][' . esc_attr($key) . ']" value="' . esc_attr($variant['text']) . '">';
            }
        }
        if ($labels === '') {
            return null;
        }

        $printers = '<option value="" data-allows="*">' . esc_html__('Ohne Drucker (wie bisher)', 'taxmod') . '</option>';
        $first    = true;
        foreach (is_array($template['printers'] ?? null) ? $template['printers'] : [] as $id => $printer) {
            $allows = array_map('intval', (array) ($printer['allows'] ?? []));
            $pick   = $first && in_array($chosen, $allows, true);
            $first  = $first && ! $pick;
            $printers .= '<option value="' . (int) $id . '" data-allows="' . esc_attr(implode(' ', $allows)) . '"' . ($pick ? ' selected' : '') . '>'
                . esc_html(trim((string) ($printer['name'] ?? '') . ' · ' . (string) ($printer['roll_name'] ?? ''), ' ·')) . '</option>';
            $hidden .= '<input type="hidden" name="printers[' . (int) $id . ']" value="' . esc_attr((string) json_encode($printer)) . '">';
        }

        $html = '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Etikett', 'taxmod') . '</span>'
            . '<select name="choice" data-taxmod-label-choice>' . $labels . '</select></label>'
            . '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Drucker', 'taxmod') . '</span>'
            . '<select name="printer" data-taxmod-label-printer>' . $printers . '</select></label>'
            . self::variantChooser($template)
            . self::chooserScript();

        return ['html' => $html, 'hidden' => $hidden];
    }

    /**
     * D-1199: `variants` = {field, label, chosen, options: {key: {name, text}}} — what a module offers to print in one field.
     *
     * @param mixed $template
     * @return array<string, array{name: string, text: string}>
     */
    private static function variantsOf(mixed $template): array
    {
        $options = is_array($template) && is_array($template['variants']['options'] ?? null) ? $template['variants']['options'] : [];
        $out     = [];
        foreach ($options as $key => $option) {
            if (is_array($option) && preg_match('/^[a-z0-9_]{0,32}$/', (string) $key) === 1) {
                $out[(string) $key] = ['name' => (string) ($option['name'] ?? $key), 'text' => (string) ($option['text'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * D-1199, his choice «Auswahl im Druckdialog»: one select for what the label prints in its variant field, preselected as the
     * module says; nothing where no label offers one.
     *
     * @param array<string, mixed> $template
     */
    private static function variantChooser(array $template): string
    {
        $options = self::variantsOf($template);
        $field   = (string) ($template['variants']['field'] ?? '');
        if ($options === [] || preg_match('/^[a-z0-9_]{1,32}$/', $field) !== 1) {
            return '';
        }
        $chosen = (string) ($template['variants']['chosen'] ?? array_key_first($options));
        $html   = '';
        foreach ($options as $key => $option) {
            $html .= '<option value="' . esc_attr($key) . '"' . selected($key, $chosen, false) . '>' . esc_html($option['name']) . '</option>';
        }

        return '<input type="hidden" name="variant_field" value="' . esc_attr($field) . '">'
            . '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html((string) ($template['variants']['label'] ?? $field)) . '</span>'
            . '<select name="variant">' . $html . '</select></label>';
    }

    /**
     * Once per page: «Print» sends the form in the background, closes the dialog and hands the answer to the page's message
     * place as the event `taxmod:message` ({text, ok}); a page without one (nobody cancels the event) shows the shared
     * `taxmod-toast`. Without the script the form posts and the page comes back as before.
     */
    private static function sendScript(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;

        return '<script>(function(){function toast(t,ok){document.querySelectorAll(".taxmod-toast").forEach(function(o){o.parentNode.removeChild(o);});'
            . 'var d=document.createElement("div");d.className="taxmod-toast "+(ok?"taxmod-toast-ok":"taxmod-toast-bad");d.setAttribute("role","status");d.textContent=t;document.body.appendChild(d);}'
            . 'function say(t,ok){var ev=new CustomEvent("taxmod:message",{detail:{text:t,ok:ok},cancelable:true});if(document.dispatchEvent(ev)){toast(t,ok);}}'
            . 'document.addEventListener("submit",function(e){var f=e.target,b=e.submitter;if(!f||!b||b.name!=="how"||b.value!=="service"){return;}'
            . 'var a=f.querySelector("input[name=action]");if(!a||a.value!==' . wp_json_encode(self::ACTION) . '||!window.fetch||!window.FormData){return;}'
            . 'e.preventDefault();var data=new FormData(f);data.append("how","service");data.append("taxmod_ajax","1");b.disabled=true;'
            . 'fetch(f.getAttribute("action"),{method:"POST",body:data,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){'
            . 'say((j&&j.data&&j.data.message)||' . wp_json_encode(__('The label could not be printed.', 'taxmod')) . ',!!(j&&j.success));'
            . 'if(j&&j.success){var sw=f.querySelector(".taxmod-dialog-switch");if(sw){sw.checked=false;}}})'
            . '.catch(function(){say(' . wp_json_encode(__('The label could not be printed.', 'taxmod')) . ',false);}).then(function(){b.disabled=false;});},true);})();</script>';
    }

    /** Once per page: a printer whose loaded roll does not allow the chosen label cannot be picked. */
    private static function chooserScript(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;

        return '<script>(function(){function sync(form,pick){var l=form.querySelector("[data-taxmod-label-choice]"),p=form.querySelector("[data-taxmod-label-printer]");if(!l||!p){return;}'
            . 'var keep=null;Array.prototype.forEach.call(p.options,function(o){var a=(o.getAttribute("data-allows")||"").split(" ");o.disabled=a.indexOf("*")<0&&a.indexOf(l.value)<0;if(!o.disabled&&o.value!==""&&keep===null){keep=o;}});'
            . 'if(pick||(p.selectedOptions.length&&p.selectedOptions[0].disabled)){p.value=keep?keep.value:"";}}'
            . 'document.addEventListener("change",function(e){if(e.target.matches&&e.target.matches("[data-taxmod-label-choice]")){sync(e.target.form,true);}});'
            . 'document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("[data-taxmod-label-choice]").forEach(function(l){sync(l.form,false);});});})();</script>';
    }

    /**
     * D-1160: the record's own words for these fields; nothing where the modeller was booted without its screen.
     *
     * @param list<string> $fields
     * @return array{values: array<string, string>, unknown: list<string>, node: int}
     */
    public static function recordValues(int $record, array $fields): array
    {
        return self::$recordValues === null || $record <= 0 ? ['values' => [], 'unknown' => [], 'node' => 0] : (self::$recordValues)($record, $fields);
    }

    /** 1 … {@see self::MAX_COPIES}: what a module asked for or a person typed, held to what a printer run can mean. */
    public static function copies(int $wanted): int
    {
        return max(1, min(self::MAX_COPIES, $wanted));
    }
}
