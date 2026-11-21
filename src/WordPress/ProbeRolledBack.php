<?php declare(strict_types=1);

namespace Taxmod\WordPress;

/**
 * Thrown at the end of a dry run of `taxmod/apply`, so the transaction rolls back what the run wrote — never an error.
 *
 * @see src/WordPress/ModelAbilities.php apply()
 */
final class ProbeRolledBack extends \RuntimeException
{
}
