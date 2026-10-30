<?php declare(strict_types=1);

namespace Taxmod\Core\Import\Mail;

use DateTimeImmutable;

/**
 * One mail as a parser sees it: sender, subject, date, the readable body and the attached files, already decoded.
 *
 * @see docs/pakete/import/module-schnitt.md
 */
final class MailMessage
{
    /** @param list<MailAttachment> $attachments */
    public function __construct(
        public readonly int $uid,
        public readonly string $fromAddress,
        public readonly string $subject,
        public readonly ?DateTimeImmutable $date,
        public readonly string $text,
        public readonly string $html,
        public readonly string $messageId = '',
        public readonly array $attachments = [],
    ) {
    }

    /**
     * The body as trimmed, non-empty lines; double-encoded UTF-8 («StÃ¼ck») is turned back.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach (explode("\n", $this->text) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = self::undoDoubleEncoding($line);
            }
        }

        return $lines;
    }

    private static function undoDoubleEncoding(string $line): string
    {
        if (! str_contains($line, 'Ã') && ! str_contains($line, "â\u{0080}")) {
            return $line;
        }

        foreach (['ISO-8859-1', 'Windows-1252'] as $codec) {
            $bytes = mb_convert_encoding($line, $codec, 'UTF-8');

            if (mb_check_encoding($bytes, 'UTF-8') && mb_convert_encoding($bytes, 'UTF-8', $codec) === $line) {
                return $bytes;
            }
        }

        return $line;
    }

    /** @return list<MailAttachment> */
    public function pdfs(): array
    {
        return array_values(array_filter($this->attachments, static fn (MailAttachment $file): bool => $file->isPdf()));
    }

    /** The part after «@», lower case — shops are recognised by it, as the Python importer did. */
    public function senderDomain(): string
    {
        $at = strrpos($this->fromAddress, '@');

        // Without «@» the whole name counts, as in Python's parseaddr() — a forwarded «From: JLCPCB» still says jlcpcb.
        return strtolower($at === false ? $this->fromAddress : substr($this->fromAddress, $at + 1));
    }
}
