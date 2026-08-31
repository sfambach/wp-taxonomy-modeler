# `src/Core/Validator/` — die Fragen, die der Typ nicht stellen kann

Ein **Validator** beanstandet einen Wert, den der Typ angenommen hat. Die Grenze steht im Konzept:
[R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest)
— *der Konverter entfernt, was nicht gemeint sein kann; der Validator fragt nach dem Rest.*

| Datei | Was |
|---|---|
| [`Validator.php`](Validator.php) | der Vertrag: Name, für welche Typen, und was zu beanstanden ist |
| [`Complaint.php`](Complaint.php) | eine Beanstandung — **Schlüssel und Platzhalter**, kein Satz |
| [`ValidatorRegistry.php`](ValidatorRegistry.php) | wer existiert, wer zu einem Typ passt, und was sie zusammen sagen |
| [`RangeValidator.php`](RangeValidator.php) | liegt der Wert in `min`/`max`? |
| [`ShapeValidator.php`](ShapeValidator.php) | hat er die Form, die sein Typ verspricht? |
| [`ShippedValidators.php`](ShippedValidators.php) | der Satz, der mitkommt |

## Worauf dieser Ordner nicht zugreifen darf

* **Kein WordPress** (`CD-1`). Kein `__()`, kein `$wpdb`, kein `esc_*`.
* **Keine Datenbank.** Die Einstellungen kommen herein, sie werden nicht geholt
  ([D-445](../../../docs/NewConcept/90-decision-log.md)). *Deshalb gibt es hier keine
  Eindeutigkeitsprüfung: die braucht eine Abfrage und gehört an den Rand.*
* **Keine Worte.** Ein Validator gibt einen **Schlüssel** mit Platzhaltern zurück; den Satz baut der
  Rand über den Textbereich (`AR-2`,
  [R36b](../../../docs/NewConcept/30-renderer.md#r36b--a-validator-message-is-a-label-and-there-is-one-per-validator)).

## Drei Regeln, die leicht verletzt werden

1. **Mehrere je Feld** ([D-158](../../../docs/NewConcept/90-decision-log.md)). Anders als ein
   Konverter, von dem genau einer in Kraft ist. Die Registratur hört darum **nicht** beim ersten
   Treffer auf.
2. **Ein fehlender Wert ist keine Beanstandung.** Ob einer da sein muss, sagt die Multiplizität
   ([D-549](../../../docs/NewConcept/90-decision-log.md)) — an einer Stelle.
3. **Er sagt, was falsch ist, nicht was zu tun ist.** Die angebotene Korrektur ist Verhalten, und
   Verhalten ist Code ([D-036](../../../docs/NewConcept/90-decision-log.md)).

**Konzept:** [`30-renderer.md`](../../../docs/NewConcept/30-renderer.md), Abschnitte R36–R36c.
