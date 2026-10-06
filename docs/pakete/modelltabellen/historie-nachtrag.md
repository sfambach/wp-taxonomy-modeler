Nachtrag zur Historie, 2026-09-06

Zwei Commits tragen dieselbe Nachricht «Drei Regeln gegen den Zeitverlust, gemessen»:

  2ce35af  AGENTS.md            -- das sind wirklich die drei Regeln
  c2f6d72  tasks.md, TASK-062   -- das ist «die zwei Riesendateien teilen»

Wie es dazu kam: ein Agent hat seine eigene Commit-Nachricht mit `git commit --amend`
nachgebessert, waehrend im selben Arbeitsbaum ein zweiter committete. `--amend` greift auf
HEAD, und HEAD gehoerte in dem Moment dem anderen. Zweimal hintereinander.

Kein Inhalt ist verloren: beide Baeume sind bitgleich mit den Originalen 7737eee und eb7158d,
die im Reflog stehen. Repariert wird es nicht -- inzwischen liegen weitere Commits darueber,
und Geschichte umzuschreiben, an der schon weitergebaut wurde, waere der groessere Schaden.

Die Lehre steht in AGENTS.md: in einem gemeinsamen Baum nie `--amend`.
