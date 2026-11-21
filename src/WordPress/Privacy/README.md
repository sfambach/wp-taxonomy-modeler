# `src/WordPress/Privacy/` — wo vertrauliche Felder versiegelt werden

| Datei | Was |
|---|---|
| [`FieldSeal.php`](FieldSeal.php) | Schlüssel aus `wp-config.php`, welche Kanten «Vertraulich» tragen, versiegeln/öffnen für Repository und Chronik |
| [`FieldSealJob.php`](FieldSealJob.php) | versiegelt den Altbestand in Häppchen |

Darf nur vom Satz-Repository, der Chronik und dem Hinweis benutzt werden — kein Modul ruft es selbst. Konzept:
[`docs/pakete/vertraulich/konzept.md`](../../../docs/pakete/vertraulich/konzept.md) (D-1514).
