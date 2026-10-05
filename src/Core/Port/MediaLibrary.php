<?php declare(strict_types=1);

namespace Taxmod\Core\Port;

/**
 * Was der Kern über Dateien einer Mediathek wissen darf — **gereicht, nie geholt**.
 *
 * ⚠️ **Sein Wort** ([D-865](../../../docs/NewConcept/90-decision-log.md)): *«id, am vater zu allen sollte es bilder geben, ja quellen mit
 * ziehen»* — eine Datei aus der Mediathek wird mit ihrer Id gespeichert, nicht mit ihrer Adresse. *Die Adresse hängt am Rechner
 * (`devel.test` hier, `fambach.net` dort); die Id überlebt den Umzug. Was zu einer Id gehört — Adresse, Titel, Vorschaubild —, weiss
 * nur der Rand; der Kern fragt, er holt nicht (`CD-1`).*
 *
 * ```mermaid
 * flowchart LR
 *   W["Wert · media:4711"] --> K["der Kern · MediaRenderer"]
 *   K -->|"filesFor([4711])"| P["diese Naht"]
 *   P --> R["der Rand · WpMediaLibrary"]
 *   R -->|"Adresse, Titel, Vorschau"| K
 * ```
 *
 * @see \Taxmod\Core\Model\Type\MediaType
 */
interface MediaLibrary
{
    /**
     * Die Dateien zu diesen Ids — was fehlt, fehlt.
     *
     * ⚠️ *Eine Frage für viele Ids (`CD-7`); der Rand darf beim ersten Mal alles laden, was die Seite brauchen kann.*
     *
     * @param  list<int>             $ids
     * @return array<int, MediaFile> Id ⇒ Datei, nur für die, die es gibt.
     */
    public function filesFor(array $ids): array;
}
