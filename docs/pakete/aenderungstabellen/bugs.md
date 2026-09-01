# Paket · Änderungstabellen — Fehlerliste

```text
BUG-001
Status: OFFEN, aber ungeprüft
Beschreibung: records_history — version und deleted tragen je einen einzigen Wert über 151 Zeilen.
```

*Legt nahe, dass der Schattenmechanismus für `records` nie wirklich gelaufen ist. **Vermutlich
richtig**, weil kein Prüflauf einen Knoten **mit** Datensätzen geleert hat — aber ungeprüft.
Verschoben aus dem Paket `Modelltabellen` am 2026-09-01.*
