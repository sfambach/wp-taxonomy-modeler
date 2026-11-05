<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * The language a code field holds — read by the code editor for its highlighting and by the
 * syntax validator for its check, so the two never disagree about what the text is.
 *
 * ⚠️ *JSON first ([D-1180](../../../docs/NewConcept/90-decision-log.md)); XML, CSS and ZPL are offered
 * for highlighting already, a check for them comes later.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum CodeLanguage: string
{
    case Json = 'json';
    case Xml  = 'xml';
    case Css  = 'css';
    case Zpl  = 'zpl';

    public const KEY = 'language';

    public static function default(): self
    {
        return self::Json;
    }

    /** @param array<string, \Taxmod\Core\Model\TypedValue> $settings */
    public static function fromSettings(array $settings): self
    {
        return self::tryFrom(strtolower(($settings[self::KEY] ?? null)?->text ?? '')) ?? self::default();
    }

    public static function fromContext(RenderContext $context): self
    {
        return self::tryFrom(strtolower($context->setting(self::KEY)?->text ?? '')) ?? self::default();
    }
}
