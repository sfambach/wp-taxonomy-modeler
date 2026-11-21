# `src/WordPress/Privacy/` — wo vertrauliche Felder versiegelt werden

| Datei | Was |
|---|---|
| [`FieldSeal.php`](FieldSeal.php) | Schlüssel (Tresor-Option oder `wp-config.php`), welche Kanten «Vertraulich» tragen, versiegeln/öffnen für Repository und Chronik |
| [`FieldSealJob.php`](FieldSealJob.php) | versiegelt den Altbestand in Häppchen |
| [`DataKeyTab.php`](DataKeyTab.php) | Reiter «Datenschlüssel»: Schlüssel eingeben, mit dem Tresor (D-951) gespeichert |

Darf nur vom Satz-Repository, der Chronik und dem Hinweis benutzt werden — kein Modul ruft es selbst. Konzept:
[`docs/pakete/vertraulich/konzept.md`](../../../docs/pakete/vertraulich/konzept.md) (D-1514).
