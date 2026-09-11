<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * Eine Einstellungszeile, die eine der vier Zusagen aus Anforderung 4.4 bricht.
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class MalformedSettingsValue extends DomainError
{
    public static function notOneCarrier(): self
    {
        return new self('A settings value hangs on exactly one carrier: a node or a settings object.');
    }

    public static function relationIsNoCarrier(): self
    {
        return new self('A relation is never the carrier of a settings value; it only narrows where the value applies.');
    }

    public static function noAddress(): self
    {
        return new self('A settings value names its attribute as class and attribute name.');
    }

    public static function notOneValue(): self
    {
        return new self('A settings value holds exactly one value: one typed column, or one settings object.');
    }

    public static function typeNotASetting(string $type): self
    {
        return new self(sprintf('A value of type «%s» is not a setting.', $type));
    }
}
