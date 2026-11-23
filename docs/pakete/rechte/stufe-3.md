# Module prüfen ihre Rechte — Stufe 3

**Entscheidung:** [D-1585](../../NewConcept/90-decision-log.md) (1)–(2), [D-1586](../../NewConcept/90-decision-log.md). **Wächter:** `rights-check` (Decke je Modul, nur fallend).
Je Modul ein PR. Die Decke im Wächter fällt im selben Modeller-PR.

## Lager (Decke 77 → 0)

| Teil | Recht |
|---|---|
| Logistik/Cockpit, Logistik-Modulseiten (Rahmen), Benutzereinstellungen, Lookups (Material-, Posten-, Platzsuche), Lese-Abilities | view |
| Einkauf | purchase |
| Wareneingang (Seite, Einlagern, Nicht einlagern, Aufteilen, Angekommen …) | `taxmod_stock_inbound` = purchase **oder** stock_book (C1) |
| Lager: Bestand, Inventur, Verbrauch, Reservieren, Umbuchen, Stornos; Transfer- und Korrektur-Ability | stock_book |
| Material anlegen/ändern, verschieben, Varianten, Übernehmen, Kopieren, Bilder, Quellen; Kontakte | write_data (Prüftor 23.11.: Stammdaten sind Sätze) |
| Lagerstruktur, Platzarten, Gruppe anlegen, Grundeinstellungen, Auswahllisten, Regeln, Testregeln, Gruppeneigenschaften, Regelvorschläge | configure |
| Materialgruppen, Feldkatalog | model (Knoten und Felder) |
| Dienste für die Fertigung (Bauen, Zuschnitt, Ausgabe, Ausschuss, Rückbuchen, Posten verschieben, Teststufen) | stock_book **oder** manufacture (C5) |
| Dienste für Verkauf/Versand (Reservieren, Freigeben, Verkauf buchen/stornieren, Kommissionieren) | stock_book **oder** sell **oder** ship (C5) |
| Ausbuchen/Verlust | stock_book |

Die Navigation der LogisticsShell zeigt nur Punkte, deren Recht die Person hat. Gelesen wird das Recht am Punkt, sonst das seines Reiters, sonst das des Menüeintrags bzw. des Moduls. Dasselbe Recht prüft der Handler (`StockRights::ofKind/ofTab`).

## Etiketten (Decke 19 → 0)

| Teil | Recht |
|---|---|
| Etikett drucken (Knopf, Dialog, Status), Porto-Marke drucken, Vorschau, Etiketten lesen, Arbeitsplatz wählen; Abilities `labels`, `preview`, `print` | view (Drucken ist Kreuzfunktion, Stefan 23.11.) |
| Seiten Etiketten, Druckregeln, Drucker, Druckmedium, Allgemein, Arbeitsplätze; Etiketten-Editor (REST); Abilities `label-save`, `label-remove` | configure |

«Arbeitsplätze verwalten» in der Werkzeugleiste sieht nur, wer configure hat (D-1586). Wächter: `wp_tax_label/scripts/dev/rights-check.php`.

## Angenommen (nicht im Beschluss)

- **C1** Wareneingang gehört Einkauf **und** Lager. Ein abgeleitetes Recht `taxmod_stock_inbound` (über `user_has_cap`) statt eines eigenen Rollenrechts.
- **C2** Die Reiter Lagerstruktur und Platzarten der Seite «Lager» sind configure. Ohne dieses Recht zeigt die Seite «Keine Berechtigung».
- **C3** Jede Buchungsart des Lagerformulars, die nicht ausdrücklich zugeordnet ist, gilt als stock_book.
- **C4** «Nur lesen» sieht im Lager vorerst nur das Cockpit und die Benutzereinstellungen. Bestandslisten ohne Buchungsknöpfe brauchen Knopf-für-Knopf-Rechte. Das ist **offen** und folgt eigens.
- **C5** Dienste, die andere Module aufrufen, lassen das Recht des aufrufenden Bereichs gelten (manufacture bzw. sell/ship) — die Fertigungsrolle hat kein stock_book («Entnahme nur über Fertigung»).
- **C6** Grundeinstellungen zeigt ohne configure die eigenen Benutzereinstellungen; «Meine Einstellungen» zeigt direkt dorthin.
- **C7** Das Lager braucht den Modeller ab 0.2.238.
- **C8** Etiketten: Porto-Marke drucken ist Drucken (view), nicht Versand (ship) — gekauft wird die Marke im Versand. Das Etiketten-Modul braucht den Modeller ab 0.2.237.
