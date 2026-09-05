<?php declare(strict_types=1);

namespace Taxmod\Core\Port;

/**
 * Was der Kern über Benutzer eines fremden Systems wissen darf — **gereicht, nie geholt**.
 *
 * ⚠️ **Sein Wort** ([D-649](../../../docs/NewConcept/90-decision-log.md)): *«bei Anzeigen muss der
 * Wert aus dem Datensatz genommen werden, aber bei Anlegen gibt es noch keinen Datensatz — dann muss
 * hier automatisch die Benutzer-Id hinterlegt werden, damit sie beim Speichern in den Datensatz
 * kommt.»* **Und der Grund, warum das nicht der Kern tut:** *«wer ist gerade angemeldet» ist eine
 * Frage an WordPress. Beantwortete der Kern sie, bräche das `CD-1`. **Er nimmt eine Vorbelegung
 * entgegen, er beschafft sie nicht.***
 *
 * ```mermaid
 * flowchart LR
 *   R["der Rand · WpUsers"] -->|"signedIn() · namesFor()"| P["diese Naht"]
 *   P --> K["der Kern · zeichnet und schreibt"]
 *   K -.->|"nie"| W["get_userdata()"]
 * ```
 *
 * ⚠️ **Zwei Fragen und nicht eine, weil es zwei Wege sind** ([D-649](../../../docs/NewConcept/90-decision-log.md)):
 * *beim **Anlegen** steht kein Datensatz da und die Id des Angemeldeten wird vorgelegt; beim
 * **Anzeigen** steht eine Id im Datensatz und der Rand macht daraus den Namen. Derselbe Rand
 * beantwortet beides, also ist es eine Naht.*
 *
 * ⚠️ **Die Id ist eine Zeichenkette und keine Zahl.** *`user_ref` wird als Text abgelegt
 * ([D-171](../../../docs/NewConcept/90-decision-log.md), `P4d`): «a reference to a WordPress user,
 * **stored as text**, like every opaque key of a foreign system». Ein anderer Rand hätte
 * `okta|4711`, und der Kern deutet keines von beidem.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
interface Users
{
    /**
     * Die Id des angemeldeten Benutzers, oder `null`, wenn niemand angemeldet ist.
     *
     * ⚠️ *`null` ist eine echte Antwort und kein Fehler — ein Lauf ohne Anmeldung (WP-CLI, ein
     * Wächter) hat keinen, und dann gibt es auch keine Vorbelegung.*
     */
    public function signedIn(): ?string;

    /**
     * Die Namen zu diesen Ids — was fehlt, fehlt.
     *
     * ⚠️ **Eine Frage für alle Ids und nicht eine je Zeile** (`CD-7`). *Dieselbe Bauart wie
     * {@see \Taxmod\Core\Service\Labels::forNodes()}: der Abstieg löst vorher auf, der Renderer
     * bekommt nur noch den fertigen Namen.*
     *
     * ⚠️ *Eine Id, zu der nichts zurückkommt, ist **nicht** durch ihre Ziffern zu ersetzen — ein
     * roher Schlüssel auf dem Bildschirm ist genau das, was [D-363](../../../docs/NewConcept/90-decision-log.md)
     * verbietet. Der Renderer zeigt sie als ungelöst.*
     *
     * @param  list<string>          $ids
     * @return array<string, string> Id ⇒ Name, nur für die, zu denen es einen gibt.
     */
    public function namesFor(array $ids): array;
}
