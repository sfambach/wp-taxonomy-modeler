<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Die Körper der Satzauswahl-Dialoge einer Seite — jeder gleiche Körper einmal, als Vorlage; die Felder tragen nur einen Platzhalter.
 *
 * ⚠️ **Sein Wort** zum Vorschlag, den Dialog je Ziel nur einmal zu zeichnen: *«3. ja»* ([D-866](../../../docs/NewConcept/90-decision-log.md)).
 * *Gemessen am 2026-09-19 an «Mikrocontroller»: «Nachfolger → Models» stand zweimal auf der Seite, 120 und 68 KB, dazu gleiche
 * Einheiten-Dialoge — die Seite lag mit 1071 KB über der Decke von 1 MB ([D-818](../../../docs/NewConcept/90-decision-log.md)). Dieselbe
 * Bauart wie der eine Auswahlbaum der Seite ([D-815](../../../docs/NewConcept/90-decision-log.md)); und wie dort braucht das Einsetzen
 * Skript ([D-821](../../../docs/NewConcept/90-decision-log.md)). Die aktuelle Wahl steht immer im Platzhalter, also verliert ein Speichern
 * ohne Skript nichts.*
 *
 * ```mermaid
 * flowchart LR
 *   F1["Feld A · Platzhalter"] -->|"data-taxmod-body"| V["eine Vorlage je Körper"]
 *   F2["Feld B · Platzhalter"] --> V
 *   V -->|"beim Öffnen geklont, Name und Formular gesetzt"| D["Dialog von A oder B"]
 * ```
 *
 * ⚠️ *Ein Exemplar für die ganze Seite: die Kopien der Zeichnung ({@see \Taxmod\Core\Service\Rendering::withSharedRecordBodies()}) teilen es.*
 */
final class SharedBodies
{
    /** @var array<string, \Taxmod\Core\Description\Describes|string> Schlüssel ⇒ Körper */
    private array $koerper = [];

    /**
     * Den Körper ablegen und seinen Schlüssel geben — gleicher Körper, gleicher Schlüssel.
     *
     * ⚠️ *Ein beschriebener Körper bekommt den Schlüssel aus seiner Beschreibung, nicht aus seinem Markup: der Kern zeichnet nicht (A18).
     * Gleiche Beschreibung, gleiches Markup — der Schlüssel bleibt ein Hash des Inhalts (Review 2026-11-17, E8).*
     */
    public function share(\Taxmod\Core\Description\Describes|string $koerper): string
    {
        $schluessel = 'b' . substr(md5(is_string($koerper) ? $koerper : (string) json_encode($koerper->toArray())), 0, 12);
        $this->koerper[$schluessel] ??= $koerper;

        return $schluessel;
    }

    /**
     * Die Körper, einmal je Schlüssel — der Rand gibt sie als Vorlagen ausserhalb jedes Formulars aus, denn ihre Knöpfe haben noch keinen
     * Namen; ein grosser darf er auslagern ([D-898](../../../docs/NewConcept/90-decision-log.md), INF-065).
     *
     * @return array<string, \Taxmod\Core\Description\Describes|string>
     */
    public function bodies(): array
    {
        return $this->koerper;
    }
}
