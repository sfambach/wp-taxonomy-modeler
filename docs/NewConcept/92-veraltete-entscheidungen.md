# 92 · Veraltete Entscheidungen — der Werdegang, aus dem Weg

⚠️ **Wozu diese Datei da ist, in seinen Worten:** *«wenn Du die alten Entscheidungen nicht wegwerfen willst, dann flagge sie doch als veraltet und schreibe sie auch in eine eigene Datei. Dann haben wir sie aus den entschiedenen und unentschiedenen raus, haben aber unseren **Werdegang** dokumentiert.»*

⚠️ **Sie ist nie die Autorität.** *`PR-3` sagt: entschieden ist, was in [90 Entscheidungslog](90-decision-log.md) steht. Was hier liegt, gilt **nicht** — es erklärt, wie wir zu dem kamen, was gilt.*

## Wer hierher kommt, und wer nicht

| | |
|---|---|
| **hierher** | eine Entscheidung, deren Aussage **ganz** überholt ist — nichts von ihr gilt weiter — **und** die selten zitiert wird |
| **bleibt im Log** | jede **teilweise** überholte. *Ihre Warnung steht seit dem 2026-08-28 im ersten Satz, was der billigere und wirksamere Schutz ist* |
| **bleibt im Log** | jede **viel zitierte**, auch wenn sie ganz tot ist. *`D-217` wird 59-mal zitiert, `D-133` 49-mal, `D-311` 26-mal — wer einer Id aus einem Docblock folgt, soll nicht auf einem Stummel landen und ein zweites Mal springen müssen* |

⚠️ **Und ein Stummel bleibt immer im Log.** *Code und Doku zitieren die Ids; `references-check.php` existiert, weil einmal sieben Entscheidungen in Docblocks sassen, die es im Log nicht gab. Eine Zeile ersatzlos zu entfernen wäre dieser Fehler, absichtlich begangen.*

## Warum es nur zwei sind

**Gemessen 2026-08-28: 28 Entscheidungen sind überholt, 31 Ersetzungen insgesamt.** Ein Muster sortierte davon 7 als «ganz» und 35 als «teilweise» — *und das Muster war falsch.* Beim Lesen blieben **drei** Kandidaten, und einer fiel wieder heraus:

| | |
|---|---|
| `D-078` | Aussage vollständig durchgestrichen, kein Dokument, keine Beziehung. **Ganz tot** |
| `D-398` | Aussage durchgestrichen, am selben Tag ersetzt. Was bleibt, ist ein Nachwort über den Fehler — genau der Werdegang, den er dokumentiert haben will. **Ganz tot** |
| `D-310` | **nicht tot.** *Ihre Aussage ist nicht durchgestrichen; sie enthält die Bestätigung des Vokabulars — «Attribut» und «Verwendungsstelle» sind dieselbe Relation von zwei Seiten — und die Folgerung, dass auf der Auflösungskette **alles** eine Vorgabe ist. Dazu: ihr Nachfolger [D-312](90-decision-log.md) wurde von [D-411](90-decision-log.md) selbst aufgehoben, ihr Kern ist möglicherweise wieder gültig.* **Sie bleibt im Log** |

⚠️ *Das ist der Beleg dafür, dass die Auswahl nicht automatisierbar ist: von drei automatisch vorgeschlagenen hielt einer der Lektüre nicht stand.*

---

## D-078

*Getroffen 2026-08-22 · ersetzt durch [D-084](90-decision-log.md) · als veraltet hierher verschoben am 2026-08-28*

⚠️ **Überholt durch [D-084](#)** — was hier steht, gilt **nicht mehr unverändert**; die geltende Fassung steht dort. *Welcher Teil fiel, sagt die Zeile weiter unten.* ~~Settings are one construct with two scopes — a `scope` column separating model-scope keys (`min`, `max`, `step`) from system-scope keys (`hide`, `read_only`, `renderer`, `converter`, `validators`).~~

| | |
|---|---|
| Stand damals | **superseded by [D-084](#)** — the split was on the wrong axis |
| Betraf | — |
| Beziehungen | — |

---

## D-398

*Getroffen 2026-08-26 · ersetzt durch [D-399](90-decision-log.md) · als veraltet hierher verschoben am 2026-08-28*

⚠️ **Überholt durch [D-399](#)** — was hier steht, gilt **nicht mehr unverändert**; die geltende Fassung steht dort. *Welcher Teil fiel, sagt die Zeile weiter unten.* ~~**A bounding boolean is a *choice* and not a switch, because a two-state switch cannot express a one-way bound.**~~ The owner found it: *I suspect we have a gap in the concept here — `hide` and `read_only` must be selectable*, and in the next breath the case it is for: *I would want to declare that at `Prefixes` already.* ⚠️ **The rule was already written and the control contradicted it.** [D-312](#) puts `hide` and `read_only` under **bounding** — *narrower only* — and [10 Domain core](10-domain-core.md) spells out the consequence: *a child may **hide** what the parent shows and **never reveal what it hid**; may **fix** what the parent left editable and never unfix what it computed.* **A toggle offers exactly the forbidden move**: flip it off, and the child reveals what `Prefixes` hid. ⚠️ **And it has no state for «not declared here»**, so a value declared above cannot be *seen* below — which is why declaring it at `Prefixes` was not merely unbuilt but unrepresentable. ⚠️ **The number of outcomes decides the control, which is [R28](30-renderer.md) unchanged**: with nothing declared above there are two — *not declared* and *hidden*; with `hide` declared above there is **one**, and [R30](30-renderer.md) already says what to draw then — *selected and greyed out*, stating where it came from. ⚠️ **This does not reopen the owner's own earlier rule.** *With bool there is no delete, either 0 or 1* was about a **choosing** key — a `default` of `false` is a real answer. **Bounding is the different case, and it is the direction that makes it different, not the type.** ⚠️ *So `mandatory` moves with them ([D-311](#) — bounding, and the strictest axis of the three), while `persistent` stays a switch: it is not a bound.* ⚠️ **Superseded the same day by [D-399](#), and the owner was right.** I reasoned from the *category* — `hide` sits under **bounding** in [D-312](#), therefore one-way — and never asked whether it belongs there. His answer: *`hide` and `read_only` must be settable on the node no matter what the parent has, that is a fact, otherwise the concept does not work — it is a **setting**, not an attribute.* *The pattern is the one [`PR-10`](../../CLAUDE.md) names: I read the table and not the reason under it.*

| | |
|---|---|
| Stand damals | `superseded by D-399` |
| Betraf | [30 Renderer](30-renderer.md), [10 Domain core](10-domain-core.md) |
| Beziehungen | applies [D-312](#), [D-311](#), [R28](30-renderer.md), [R30](30-renderer.md) |

---
