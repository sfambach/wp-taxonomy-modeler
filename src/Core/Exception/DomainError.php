<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * Base for everything the domain refuses to do.
 *
 * The boundary translates these into `WP_Error`; the core never returns a bare false.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
abstract class DomainError extends \RuntimeException
{
}
