<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\Choosing;
use Taxmod\Core\Description\ShownValue;
use Taxmod\Core\Description\ValueKind;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Rules\RuleBook;

/**
 * The operator of a rule filter, chosen among those the rule book knows (D-1552) and stored as the rule book's own word. The
 * operators are code, so their words are the software's, handed in from the boundary (Prüftor 21.11.2026: «Operatorwörter =
 * Textdomain-Strings (Operatoren sind Code, AR-2), kein Datensatz»).
 *
 * @see docs/NewConcept/90-decision-log.md D-1552
 */
final class OperatorChoiceRenderer extends RendererNode
{
    public const NAME = 'operator-choice';

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    public function handles(): array
    {
        return [SimpleType::Text];
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $offered = $context->surroundings->options;
        $now     = (string) ($context->value->text ?? '');
        // An empty operator is «gleich» (D-1541) — shown so, and stored so on the next save.
        $shown   = $now === '' ? RuleBook::EQUALS : $now;

        if ($context->purpose === Purpose::Display || ! $context->editable) {
            return RenderResult::described(new ShownValue((string) ($offered[$shown] ?? $shown), ValueKind::Chosen));
        }

        return RenderResult::described(Choosing::offer(
            $context->fieldName,
            array_key_exists($shown, $offered) ? $offered : [$shown => $shown] + $offered,
            $shown,
            false,
            $context->surroundings->formId,
            editable: $context->editable,
        ));
    }
}
