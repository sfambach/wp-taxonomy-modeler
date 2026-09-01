# Paket · Datenbank — Historie

**Keine Quelle für aktuelle Entscheidungen** ([`arbeitsmodell.md`](../../arbeitsmodell.md) §3).
Hier steht der Weg, nicht der Stand.

---

## 2026-09-01 · `path` fällt, `identities` fällt

Der Eigentümer, beim Durchgehen der Kerntabellen: *«`path` fliegt raus überall, den brauchen wir
nicht. Dann jeder Nodes und Relations bekommen jeweils ihre eigene Id. Identity stirbt.»*

**Was vorher galt und warum es fiel:**

`nodes.path` trug punktseparierte Id-Ketten und war seit [D-082](../../NewConcept/90-decision-log.md)
als «materialised ancestor path — derived and rebuildable» gedacht: eine vorgerechnete Abkürzung für
die Vorfahrensuche. **Sie stand bis zuletzt auf `agreed (proposal)`** und wurde nie bestätigt.
`50-wordpress-persistence.md` zitierte dafür ausserdem die falsche Nummer (`D-014`).

`identities` kam mit [D-339](../../NewConcept/90-decision-log.md): *«identities(id), append-only …
nodes and relations take the number and use it as their own primary key.»* Sie war der Anker, an dem
alle sieben Fremdschlüssel hingen, und **genau das trug die Schattentabellen** aus
[D-537](../../NewConcept/90-decision-log.md).

**Die Messungen, die den Ausschlag gaben, am 2026-09-01 an der laufenden Datenbank:**

- `nodes.path`: **127 von 127** mehrgliedrigen Pfaden aus `relations` herleitbar, Abweichung **null**.
- **18 Knoten** haben mehr als eine eingehende Kante — ein Pfad kann davon nur einen Weg nennen.
- `record_values.path`: **183 Zeilen, 12 verschiedene Werte** — genau wie `edge_id` daneben.
- `labels.path` und `settings.path`: **0 von 47, 0 von 3** gefüllt.
- `identities`: **65 593 vergeben, rund 680 in Gebrauch — 1,1 %.**
