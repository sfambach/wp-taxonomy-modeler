# Paket · JLCPCB-Anbindung («tax_api_jlcpcb») — Konzeptentwurf

**Stand 2026-10-21. Nichts entschieden. Gebaut: Schritt 1 (§7) in wp_tax_api_jlcpcb 0.0.2, live seit 2026-10-21 (0.0.3 Probe-Liste der Bestellnummern live; 0.0.4 Details V2 + Preisergänzung als `EnrichingSource`, gegen JLC ungetestet); sein Wort: *«connection test erfolgreich»* — Host `open.jlcpcb.com` und Signatur damit gemessen.** Steht auf dem gemeinsamen Eingang ([`../import/konzept.md`](../import/konzept.md), [D-951](../../NewConcept/90-decision-log.md), [D-952](../../NewConcept/90-decision-log.md)): tax_api_jlcpcb wird dort die erste Quelle (`EnrichingSource`). Was er entscheiden soll, steht in §8; alles andere ist mein Vorschlag
(`INFERRED`), bis es eine `D-<nnn>` hat (`PR-3`). Eingang: [INF-082](../../neues-konzept-eingang.md#inf-082--jlcpcb-über-die-api-anbinden).
Vorbild: [`../ebay/konzept.md`](../ebay/konzept.md) und [`../mail/konzept.md`](../mail/konzept.md).

**Sein Wort, auf dem der Entwurf steht (2026-10-21):**

> *«habe jetzt jlc api key»*

⚠️ **Gelesen, nicht gemessen:** §3 stammt aus der öffentlichen Doku unter `api.jlcpcb.com/docs` (Basic Rules, API List, API
Request Signature, IP Whitelist, SDK Usage), gelesen am 2026-10-21. Die Beschreibung der einzelnen Aufrufe (Felder, Pfade) steht
dort nicht; sie liegt vermutlich in der Konsole hinter seiner Anmeldung. Kein einziger Aufruf ist gemacht.

---

## 1 · Was das Modul ist — und was nicht

```mermaid
flowchart LR
  M["JLC-Mail (tax_mail)"] -->|"Bestellnummer"| J["tax_api_jlcpcb"]
  A["JLC-API (sein Konto)"] --> J
  J -->|"write-data"| TX["Taxonomy Modeller"]
```

- **tax_api_jlcpcb holt zu seinen JLCPCB-Bestellungen Preise, Kosten und Fertigungsstand über die API** und schlägt sie als Ergänzung
  vor. Geschrieben wird erst nach seiner Auswahl, nur über `taxmod/write-data` ([D-936](../../NewConcept/90-decision-log.md)).
- **Lücke, die es schliesst:** der JLCPCB-Parser in tax_mail liest Bestellnummer, Platinen, Anzahl und Sendungsnummer, aber
  **keine Mail nennt Preise** (Docblock von `Jlcpcb.php` in wp_tax_mail).
- **Es bestellt nichts.** Bestellen, Angebote, Gerber hochladen bietet die API, das Modul nutzt es nicht (J5).
- Eigenes Plugin wie tax_mail und tax_ebay, setzt den Modeller voraus.

## 2 · Ablauf

```mermaid
flowchart LR
  P["tax_mail: Vorschlag JLC (ohne Preise)"] --> Q["tax_api_jlcpcb: Details zur Bestellnummer"]
  Q --> V["Vorschlag ergänzt (Kosten, Versand, Stand)"]
  V --> R["Prüfseite"]
  R -->|übernehmen| W["write-data"]
```

1. **Bestellnummer kommt aus der Mail** (oder von Hand im Reiter). ⚠️ *Korrigiert 2026-10-21:* die Begründung «die PCB-API
   hat **keine Bestellliste**» war falsch — die öffentliche API List nennt sie nicht, die Doku in seiner Konsole aber schon:
   `/overseas/openapi/pcb/pageBatchInfoByOrderType` (Batch-Nummern `W…` nach Erstellzeit, Typ `order_pcb`/`order_steel`, nur
   Nummer und Anzahl). Ob die API damit wie bei eBay ([D-950](../../NewConcept/90-decision-log.md)) die Wahrheit über
   *welche* Bestellungen es gibt wird, ist offen — das widerspricht der Rolle «ergänzt nur» aus
   [D-953](../../NewConcept/90-decision-log.md) und wäre seine Entscheidung. Gebaut ist die Liste nur als Probe (0.0.3).
2. **Ergänzen:** zu jeder JLC-Bestellung im Vorschlag oder im Modell ohne Preis die Details abrufen: Kosten je Posten, Versand,
   Gesamt; dazu der Fertigungsstand, solange nicht versendet.
3. **Abruf:** einmal täglich + Knopf «Jetzt abrufen», wie E7 bei eBay (INFERRED).

## 3 · Was die JLC-API bietet (gelesen, nicht gemessen)

| Bereich | Aufruf (Name aus der API List) | Nutzen fürs Modell |
|---|---|---|
| PCB | **Query order details by batch number** — Bestellung, Lieferadresse, Kosten | Preise der Bestellung ⇒ Kern dieses Moduls |
| PCB | Get PCB production process information (nach Order-ID, erst ab Fertigung) | Status «in Fertigung» |
| PCB | Create order, Online quote, Upload gerber … | nicht genutzt (J5) |
| PCB | **Paginated Query of Batch Information by Order Type** (`/overseas/openapi/pcb/pageBatchInfoByOrderType`, aus seiner Konsole) | Bestellnummern eines Zeitraums; Probe in 0.0.3 |
| 3D-Druck | Order list, Order details, Progress | nur falls er bei JLC3DP druckt |
| Components | Query Component Detail Data (nach C-Nummer) | Katalog: Daten, Preis, Bestand eines Bauteils per LCSC-/C-Nummer (später, J6) |
| Components | Query Private Component Library | seine bei JLC gelagerten Teile (für Bestückung) ⇒ tax_stock, später |
| Balance | Get Available Balance | Kontostand; Pfad nicht öffentlich, darum nicht der Verbindungstest |

- **Technik:** nur HTTPS + `POST` mit JSON; Datumsangaben in China-Zeit (GMT+8), `yyyy-MM-dd HH:mm:ss`.
- **Anmeldung:** keine Zustimmung im Browser wie bei eBay. Jede Anfrage wird mit HMAC-SHA256 über fünf Zeilen (Methode, Pfad,
  Zeitstempel, 32-Zeichen-Nonce, Body) signiert und als `Authorization: JOP appid=…, accesskey=…, nonce=…, timestamp=…,
  signature=…` gesendet. Geht mit `hash_hmac` und `wp_remote_post`, **keine fremde Bibliothek** (SDK gibt es nur für Java).
- **Ungeklärt, prüfbar erst mit seinem Schlüssel:** ob «batch number» die Nummer `W…` aus den Mails ist, und ob Bestellungen
  sichtbar sind, die er **auf der Website** aufgegeben hat (die API ist für Firmenkunden gebaut, die über sie bestellen).
  ⚠️ **Davon hängt das ganze Modul ab** — deshalb ist Schritt 2 genau dieser Test.

## 4 · Zugangsdaten — wohin der Schlüssel gehört

- Drei Werte: **App-ID**, **AccessKey**, **SecretKey** (in der Konsole «tokenizationKey» genannt). Nie in den Chat, nie ins Repo.
- **Geheimnisse immer verschlüsselt** ([D-951](../../NewConcept/90-decision-log.md)); das Formular dafür kommt mit dem Tresor des
  Eingangs ([D-952](../../NewConcept/90-decision-log.md)), keine eigene Kopie im Modul.
- **Gebaut (0.0.2):** App-ID, AccessKey, SecretKey im Reiter «JLCPCB»; der SecretKey versiegelt über `Vault::for('jlcpcb')` des
  Modellers (0.1.31), Option `taxmod_jlcpcb_keys`. Konstanten `TAXJLCPCB_APP_ID`, `TAXJLCPCB_ACCESS_KEY`, `TAXJLCPCB_SECRET_KEY`
  in `wp-config.php` gehen vor ([D-944](../../NewConcept/90-decision-log.md)); ohne Tresor (älterer Modeller) nur sie.
- **IP-Whitelist:** erst leer lassen; wenn die Verbindung steht, die ausgehende IP des Strato-Servers eintragen.

## 5 · Abbildung auf das Modell

| JLC | Modell |
|---|---|
| Bestellung (Batch) | vorhandener Satz unter «Bestellungen» ([D-933](../../NewConcept/90-decision-log.md)), Händler JLCPCB, gefunden über Plattform + Bestellnummer |
| Kostenposten (Platine, Bestückung, Versand) | Preise an den Positionen «Käufe» bzw. Versandkosten der Bestellung, Beträge als Einheitenwert ([D-929](../../NewConcept/90-decision-log.md)), Währung aus der API (meist USD) |
| Platine der Bestellung | **wählt er auf der Prüfseite:** «Platinenversion» ([D-791](../../NewConcept/90-decision-log.md)), Fertiger JLCPCB; nichts wird automatisch verknüpft |

- Ziel-Knoten und Felder in den Einstellungen, nicht im Code (wie eBay §5; Baum im Umbau, [D-940](../../NewConcept/90-decision-log.md)).
- Lieferadresse wird nicht gespeichert.

## 6 · Abgrenzung

- **tax_mail bleibt für JLC zuständig, was es *gibt*** (Bestellnummer, Platinen, Sendung); tax_api_jlcpcb ergänzt, was es *kostet*.
  Kein Abschalten des JLC-Parsers, anders als bei eBay ([D-942](../../NewConcept/90-decision-log.md)).
- Anknüpfung: Filter in tax_mail beim Bauen des Vorschlags (Name offen), oder tax_api_jlcpcb liest die Vorschlagstabelle. tax_api_jlcpcb ruft
  tax_mail nicht direkt auf.
- tax_stock: Action nach dem Schreiben (`taxapijlcpcb_order_updated`), wie bei eBay §7.

## 7 · Bauschritte

1. Gerüst, Reiter «JLCPCB», Schlüssel (Tresor oder `wp-config.php`), **«Verbindung testen»** (Bauteil C25804) — sonst nichts. **Gebaut 0.0.2.**
2. Eine bekannte Bestellnummer von Hand abfragen und die Antwort zeigen ⇒ klärt §3 «ungeklärt».
3. Ergänzung der JLC-Vorschläge von tax_mail → Prüfseite → `write-data`.
4. Später: Fertigungsstand; Bauteildaten per C-Nummer (J6); private Teile → tax_stock.

## 8 · Was er entscheiden soll

| | Frage | Mein Vorschlag |
|---|---|---|
| **J1** | tax_api_jlcpcb als eigenes Plugin (Repo [`sfambach/wp_tax_api_jlcpcb`](https://github.com/sfambach/wp_tax_api_jlcpcb), von ihm angelegt am 2026-10-21), schreibt nur über `write-data`, erst Vorschläge | **ja** |
| **J2** | Zweck zuerst: **Preise/Kosten zu Bestellungen ergänzen**, Bestellnummer aus tax_mail | **ja** |
| **J3** | Schlüssel verschlüsselt | **entschieden: [D-951](../../NewConcept/90-decision-log.md)**; bis zum Tresor nur `wp-config.php` |
| **J4** | Erst Verbindung + Test, dann Probe mit einer Bestellnummer, dann Ergänzung | **ja** |
| **J5** | Nicht über die API bestellen | **ja** |
| **J6** | Bauteildaten per C-Nummer in den Katalog — später, eigener Eingang | **später** |

## 9 · Annahmen, die das Konzept nicht sagt

- Ein JLCPCB-Konto (seins), Bestellungen über die Website; Währung USD.
- «API-Key» heisst: er hat in der Konsole eine App mit Schlüsselpaar; die Freigabe der App (Partner-Antrag) ist durch.
- Abrufintervall und Prüfseite wie bei tax_mail/tax_ebay; eine gemeinsame Prüfseite ist weiter offen.
- **Schritt 1, Annahmen beim Bau (INFERRED):** Host `https://open.jlcpcb.com` und Pfad `/overseas/openapi/component/getComponentDetailByCode`
  stammen aus einem fremden Client (KiCAD-MCP-Server), nicht aus JLCs Doku; überschreibbar mit `TAXJLCPCB_BASE_URL`. Der Test fragt
  das Bauteil C25804 ab statt «Balance» — dessen Pfad steht nicht in der öffentlichen Doku. Die Signatur ist gegen das Beispiel der
  Doku getestet.
- **0.0.4, Annahmen beim Bau (INFERRED):** Details über «Query Order Details by Batch Number V2» (`/overseas/openapi/pcb/v2/order/detail`,
  aus seiner Konsole); die Anschrift (Name, Strasse, Telefon) wird nicht gelesen. Stückpreis = `pcbOrderFeeInfo.totalFee` / `stencilCounts`
  (JLC nennt die Platine «stencil»); Zollgebühr (`tariffMoney`) fliesst in den Gesamtbetrag, weil der Vorschlag kein eigenes Feld hat;
  Bestelldatum aus der Batch-Nummer (`W` + yyyyMMdd…); Status 0 = storniert wie in V1 (V2 nennt keine Codes); Zuordnung Zeile ↔ Auftrag über
  den Gerber-Namen nach derselben Normalisierung wie im JLCPCB-Parser von tax_mail; Schablonen (`steelOrderAggInfoList`) noch nicht gelesen.
  Ergänzt werden nur leere Felder, Währung USD nur, wenn der Vorschlag noch keine Beträge hatte.
