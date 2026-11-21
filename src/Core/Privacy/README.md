# `src/Core/Privacy/` — vertrauliche Felder

`FieldCipher` versiegelt den Text eines vertraulichen Feldes und öffnet ihn wieder (D-1514). Kein WordPress (`CD-1`): der
Schlüssel kommt als Bytes herein, woher er stammt, weiß nur der Rand (`src/WordPress/Privacy/`).

Konzept: [`docs/pakete/vertraulich/konzept.md`](../../../docs/pakete/vertraulich/konzept.md).
