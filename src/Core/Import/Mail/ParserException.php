<?php declare(strict_types=1);

namespace Taxmod\Core\Import\Mail;

use RuntimeException;

/**
 * A mail of a known shop whose content the parser could not read — it lands with the unrecognised mails, not lost.
 *
 * @see docs/pakete/import/module-schnitt.md
 */
final class ParserException extends RuntimeException
{
}
