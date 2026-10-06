# 92 · Veraltete Entscheidungen — der Werdegang, aus dem Weg

⚠️ **Wozu diese Datei da ist, in seinen Worten:** *«wenn Du die alten Entscheidungen nicht wegwerfen willst, dann flagge sie doch als veraltet und schreibe sie auch in eine eigene Datei. Dann haben wir sie aus den entschiedenen und unentschiedenen raus, haben aber unseren **Werdegang** dokumentiert.»*

⚠️ **Sie ist nie die Autorität.** *`PR-3` sagt: entschieden ist, was in [90 Entscheidungslog](90-decision-log.md) steht. Was hier liegt, gilt **nicht** — es erklärt, wie wir zu dem kamen, was gilt.*

## Wer hierher kommt, und wer nicht

⚠️ **Geändert am 2026-08-29, und die alte Regel war meine und hat das Falsche optimiert.**
*Sie sagte: «eine viel zitierte bleibt im Log, auch wenn sie ganz tot ist» — damit ein Leser, der
einer Id folgt, **Inhalt** findet statt eines Zeigers. Der Eigentümer hat das zerlegt: «wenn Du die
Dokumente durchguckst, findest Du die zuerst und behauptest dann, es wär so. **Das ist die
Gefahr, die ich sehe.**» **Falscher Inhalt ist schlimmer als ein Zeiger** — und genau darauf bin
ich an diesem Tag mehrfach hereingefallen, zuletzt an `D-217`, das seine Warnung seit dem Vortag
trug und aus dessen Text darunter ich trotzdem argumentiert habe.*

⚠️ **Und die zweite Änderung: es wird geteilt, nicht verschoben.** *Gemessen war **keine** der
betroffenen Entscheidungen **ganz** falsch — jede hatte eine überlebende Hälfte, und `D-409`s
überlebende («ein Attribut **ist** die Kante») war schärfer als alles, was danach kam. Ein ganzes
Verschieben hätte sie mitgenommen.*

**Die Regel, wie sie jetzt gilt:**


| | |
|---|---|
| **hierher** | eine Entscheidung, deren Aussage **ganz** überholt ist — nichts von ihr gilt weiter — **und** die selten zitiert wird |
| **bleibt im Log** | jede **teilweise** überholte. *Ihre Warnung steht seit dem 2026-08-28 im ersten Satz, was der billigere und wirksamere Schutz ist* |
| ~~**bleibt im Log** \| jede **viel zitierte**~~ | ⚠️ **Zurückgenommen 2026-08-29.** *Wie oft eine Entscheidung zitiert wird, sagt nichts darüber, ob ihr Inhalt stimmt. Was ein Leser braucht, ist ein **wahrer Stumpf**, nicht viel Inhalt.* |

⚠️ **Und ein Stummel bleibt immer im Log.** *Code und Doku zitieren die Ids; `references-check.php` existiert, weil einmal sieben Entscheidungen in Docblocks sassen, die es im Log nicht gab. Eine Zeile ersatzlos zu entfernen wäre dieser Fehler, absichtlich begangen.*

## Warum es nur drei sind

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

## D-224

**Der Dekorator als Konfigurationsbegriff.**

**Überholt durch [D-236](90-decision-log.md)**, das den Dekorator durch die geordnete Renderer-Liste
ersetzt. ⚠️ **Es trug bis 2026-08-29 gar keinen Vermerk und las sich als gültig** — gefunden, als der
Eigentümer fragte, wozu es die Liste überhaupt gibt ([D-501](90-decision-log.md)).

⚠️ *Was davon **weiterlebt**, steht in D-236 selbst und nicht hier: «a decorator remains available as
an **implementation** pattern — a renderer wrapping another is still just a renderer — but it is no
longer something a user configures.»*

Der ursprüngliche Wortlaut:

> **A decorator is a renderer, and one layer of decoration is allowed.** Raised by the owner as a *maybe*: the decorator pattern, and *I could allow a renderer combination — if I would like a colour renderer in addition here, just add it*.
> ⚠️ **It breaks nothing**, which was his worry: a decorator **is** a renderer, so [D-217](#)'s *one node, one renderer* stands untouched — the chosen renderer simply wraps another.
> And the concept already delegates in exactly this shape, as the owner noticed himself: the composite renderer and the table renderer set an outer form and hand each member down to the member's own renderer.
> **The performance worry does not apply here:** what costs is queries, and decoration adds none — the data is fully loaded before the descent begins ([D-159](#)).
> Building a string twice is nothing.
> ⚠️ **The limit: one layer, not an arbitrary stack.** Stackable decorators are a small programming language living in the configuration — order, nesting, and failures nobody reads.
> One layer covers every case named: a value with a colour dot, with a traffic light, with stars.

## D-409

**Die Multiplizität einer Einstellung.**

**Überholt durch [D-505](90-decision-log.md) und [D-506](90-decision-log.md)** am 2026-08-29:
es gibt keine Einstellungen mehr, nur Felder mit einem Merkmal. *Ein Stumpf mit der geltenden
Hälfte steht im [Log](90-decision-log.md).*

Der ursprüngliche Wortlaut:

> **A setting has no multiplicity.
> One key, one answer, per place — and «edge-only» stops being a property of `multiplicity` because an attribute *is* the edge.** The owner, reviewing the truth table: *with attributes you have «now and only on the edge» — but an attribute **is** an edge, that is the edge being described.
> **Settings has no multiplicity.** Treat that as decided.* ⚠️ **Two corrections in one sentence.** Saying `multiplicity` is *edge-only* was true and said nothing: **an attribute is the edge**, so «only on the edge» is «only on an attribute», which is where every attribute-shaped statement lives anyway.
> ⚠️ **And a setting having no multiplicity is the sharper half**: one key holds one answer at one place, which is what `UNIQUE (owner_id, setting_key)` already enforces.
> *So [OQ-092](91-open-questions.md)'s `path` column is **not** a multiplicity for settings — it is an **address**, and the difference matters: several rows for one key at one place would be a multiplicity; several places for one key is a chain.*

## D-423

**Die Materialisierung beim Erben.**

**Überholt durch [D-505](90-decision-log.md) und [D-506](90-decision-log.md)** am 2026-08-29:
es gibt keine Einstellungen mehr, nur Felder mit einem Merkmal. *Ein Stumpf mit der geltenden
Hälfte steht im [Log](90-decision-log.md).*

Der ursprüngliche Wortlaut:

> **Settings are materialised into the inheriting node and into the attribute, `reset` becomes a *pull*, and — measured — this changes nothing on the reading side.
> Answers [OQ-097](91-open-questions.md).** The owner's three rules, in his words: *on inheriting, the settings are **written into** the inheriting node, where they can be changed*; *if a parent changes its settings it **asks** whether that should change in the children and in the attribute too — both ticked by default*; *when an attribute is created, all settings of the node are taken into the attribute.* ⚠️ **«All» is 15 at a node and 16 at an attribute**, measured — `multiplicity` is edge-only ([D-351](#)) — so the word was already precise before anything was copied.
> ⚠️ **What the owner won, and it was my objection that fell.** [D-311](#)'s *every bird has a name* was the strongest argument against materialising, and it turned out to rest on `mandatory`, **a key that should never have been on a node at all** ([D-405](#) removed it).
> *The invariant I was defending was held up by the one thing that did not belong in the discussion.* ⚠️ **`reset` stops meaning *remove my row* and starts meaning *fetch the value from above into my row*** — the owner: *I could of course say Reset on the node or on the attribute, and then it fetches it from the next higher node's setting.* **This is what keeps materialising from being a one-way door**: without it a child that once said *no* is permanently detached with no way back, which was objection 2 and is now closed.
> *Same gesture as [D-266](#), different mechanic — and it has to be, because with every row present there is no walk left to fall back through.*
