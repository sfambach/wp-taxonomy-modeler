<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Type\MediaType;

/**
 * Schreibt einen gespeicherten Text um, wenn eine Sicherung auf eine andere Website zieht ([D-908](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   V["value_text der Sicherung"] --> M{"media:&lt;alte Id&gt;?"}
 *   M -->|ja| N["media:&lt;neue Id&gt;"]
 *   M -->|nein| U["Adresse der alten Website → neue"]
 * ```
 *
 * ⚠️ **Zwei Dinge und kein drittes.** *Eine Mediathek-Id gilt nur auf der Website, die sie vergeben hat — auf
 * einer anderen ist dieselbe Nummer eine andere Datei. Und ein Verweis auf die alte Adresse zeigt nach dem Umzug
 * ins Leere. Alles andere im Text ist Inhalt und bleibt, wie es ist.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class BackupRewrite
{
    /**
     * @param array<int, int> $mediaIds Alte Mediathek-Id ⇒ neue.
     */
    public function __construct(
        private readonly array $mediaIds,
        private readonly string $fromUrl,
        private readonly string $toUrl,
    ) {
    }

    public function text(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $id = MediaType::libraryIdOf($value);

        if ($id !== null) {
            return isset($this->mediaIds[$id]) ? MediaType::libraryAddress($this->mediaIds[$id]) : $value;
        }

        return $this->url($value);
    }

    /**
     * ⚠️ *Auch die maskierte Form `http:\/\/…`, weil ein Wert JSON tragen kann — und ohne Schrägstrich am Ende,
     * damit `https://alt.test/a` nicht zu `https://neu.test//a` wird, wenn nur eine Seite einen hat.*
     */
    private function url(string $value): string
    {
        $from = rtrim($this->fromUrl, '/');
        $to   = rtrim($this->toUrl, '/');

        if ($from === '' || $from === $to) {
            return $value;
        }

        return str_replace(
            [$from, str_replace('/', '\/', $from)],
            [$to, str_replace('/', '\/', $to)],
            $value
        );
    }
}
