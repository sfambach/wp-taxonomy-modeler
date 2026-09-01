# Bekannte Fallen

**Keine Regeln — Techniken.** Das Arbeitsmodell sagt es selbst
([`arbeitsmodell.md`](arbeitsmodell.md) §12.1): *«Implementierungsdetails sollen nicht unnötig zu
globalen Regeln werden.»*

Jede Falle hier hat mindestens einen Abend gekostet. Sie stehen nicht im Regelsatz, weil sie nichts
über das Modell sagen und kein Konzept verbauen können — sie sagen nur, wo das Werkzeug beisst.

⚠️ *`PR-11` war bis 2026-09-01 eine eigene Regel und ist hierher verschoben. Der Inhalt ist
unverändert; nur der Anspruch, eine globale Regel zu sein, entfällt.*

---

## `preg_replace` gibt `null` zurück, und `null` landet auf der Platte

**Kosten: eine Quelldatei, auf null Byte geleert, vor dem ersten Commit.**

Am 2026-08-25 scheiterte ein `preg_replace` an einer unmaskierten Klammer, gab `null` zurück, und
`file_put_contents($f, null)` leerte `AttributeRenderer.php` vollständig. **`php -l` meldete nichts —
eine leere Datei hat keine Syntaxfehler.**

**Was hilft:** vor dem Schreiben prüfen, dass die Ersetzung überhaupt gegriffen hat
(`if (! str_contains($s, $alt)) { exit(1); }`), und niemals einen möglicherweise leeren Wert an
`file_put_contents` geben. Für Quelltext die Editierwerkzeuge nehmen statt regulärer Ausdrücke.

---

## `sed` frisst PHP-Namensräume

**Kosten: zweimal in einer Sitzung — einmal die PSR-4-Zuordnung in `composer.json`, einmal die
Importe in `NodesScreen.php`.**

GNU sed liest `\C`, `\w`, `\M` als Escape-Sequenzen. Die Backslashes verschwinden aus Muster **und**
Ersetzung: `use Taxmod\Core\Model\Branch;` wird zu `use TaxmodCoreModelBranch;`.

**Der Schaden ist still.** `php -l` läuft durch, weil `use TaxmodCoreModelBranch;` gültiges PHP ist —
es scheitert erst zur Laufzeit, wenn die Klasse nicht gefunden wird.

**Was hilft:** für jede Ersetzung mit einem Backslash — PHP-Namensräume, Windows-Pfade — das
Editierwerkzeug. In `sed` den Trenner mit `.` treffen und als `\x5c` zurückschreiben. Nach jedem
`sed` auf eine PHP-Datei `git diff --stat` ansehen.

---

## `$wpdb` schweigt bei einer kaputten Abfrage

**Kosten: eine falsche Messung, die als Befund in einen Commit, eine offene Frage und eine
Arbeitszeile wanderte.**

Am 2026-08-26 wurde gemeldet, es gebe **null** `hide`-Zeilen in der Datenbank, das Auge im Baum sei
also nie benutzt worden. Die Abfrage wählte `value_bool` — eine Spalte, die es nicht gibt.
**`$wpdb` beantwortete die kaputte Abfrage mit einem leeren Feld statt mit einem Fehler**, und der
Irrtum kam in der Autorität einer Messung an. Die Wahrheit war das Gegenteil: sechs Präfixe waren
verborgen. Dieselbe Falle zehn Minuten später noch einmal, weil `WHERE kind = 'attribute'` nichts
lieferte — die Arten heissen `inheritance`, `composition` und `aggregation`.

**Was hilft:** vor einer Abfrage mit geratener Spalte oder geratenem Wert `SHOW COLUMNS` oder ein
`GROUP BY` laufen lassen. Danach `if ($wpdb->last_error !== '')` prüfen und laut aussteigen.
**Ein leeres Ergebnis und eine gescheiterte Abfrage sehen gleich aus und bedeuten das Gegenteil.**
Wo möglich die Repositories nehmen — eine typisierte Methode kann keine Spalte benennen, die es
nicht gibt.

---

## `datetime` gegen `'0'` vergleichen meldet jede Zeitspalte als leer

**Kosten: ein Befund im Tabellen-Review, der falsch war und im selben Lauf korrigiert werden musste.**

Am 2026-09-01 meldete eine Spaltenmessung `changelog.at`, `records.created_at` und alle vier
`archived_at` als «nie gefüllt». Der Test lautete `<> '' AND <> '0'` — **MySQL wandelt für den
Vergleich um, und jedes Datum wird dabei gleich `'0'`.** Nachgemessen war keine einzige Spalte leer.

**Was hilft:** Zeitspalten mit `IS NOT NULL` prüfen und das Nulldatum `'0000-00-00 00:00:00'`
getrennt zählen. Und der allgemeine Teil: **ein Test, der für einen Spaltentyp gebaut ist, gilt nicht
für alle** — die Zahl «verschiedene Werte» hätte den Widerspruch sofort gezeigt und tat es auch,
sobald jemand hinsah.

---

## `git checkout <datei>` wirft nicht committete Arbeit weg

**Kosten: eine halbe Stunde Umbau an `CLAUDE.md`, am 2026-09-01, durch mich selbst.**

Ein Wächter sollte gegengeprüft werden: eine Datei künstlich aufblähen, sehen, ob er rot wird, dann
zurücksetzen. Das Zurücksetzen war `git checkout CLAUDE.md` — **und die Datei enthielt eine noch
nicht committete Änderung.** Der Wächter hatte richtig gemeldet, die Gegenprobe war richtig gedacht,
und trotzdem war die Arbeit weg.

**Was hilft:** vor einer Gegenprobe, die eine Datei verändert, eine Kopie in den Arbeitsordner legen
und **daraus** zurückstellen, nicht aus git. Oder die Gegenprobe an einer Wegwerfdatei führen statt
an der echten.

⚠️ *Das allgemeine Muster: **ein Rückgängig, das weiter reicht als die Änderung.** `git checkout`
kennt nur den letzten Commit, nicht die letzte Absicht.*
