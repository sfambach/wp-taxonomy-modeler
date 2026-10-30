<?php declare(strict_types=1);

namespace Taxmod\Core\Import\Mail;

/**
 * A file a mail carries — an invoice PDF — decoded, as a reader may keep it in the document store (D-1023).
 *
 * @see docs/pakete/import/module-schnitt.md
 */
final class MailAttachment
{
    public function __construct(
        public readonly string $fileName,
        public readonly string $mimeType,
        public readonly string $content,
    ) {
    }

    public function isPdf(): bool
    {
        return $this->mimeType === 'application/pdf' || str_ends_with(strtolower($this->fileName), '.pdf');
    }
}
