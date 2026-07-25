# Windows Setup — so startest du die Skripte

Unter Windows **öffnet ein Doppelklick auf `.ps1`-Dateien oft den Editor**
(Notepad, VS Code) statt PowerShell. Das ist normales Windows-Verhalten.

## Richtig: `.bat` per Doppelklick oder aus cmd

| Aktion | Datei |
|--------|--------|
| Alles (Repo + WP + Links) | **`setup-dev.bat`** |
| Nur WordPress installieren | **`install-wordpress.bat`** |

Pfad:

`C:\devel\wordpress\source\wp-taxonomy-tree\scripts\windows\`

## Alternative: PowerShell-Terminal

```powershell
cd C:\devel\wordpress\source\wp-taxonomy-tree\scripts\windows
powershell -ExecutionPolicy Bypass -File .\setup-dev.ps1
```

Oder nur WordPress:

```powershell
powershell -ExecutionPolicy Bypass -File .\install-wordpress.ps1
```

## Nicht so

- `.ps1` per Doppelklick im Explorer
- Rechtsklick → Bearbeiten (öffnet nur den Editor)

## Nach erfolgreichem Setup

- http://devel.test
- http://devel.test/wp-admin — `admin` / `admin123`
