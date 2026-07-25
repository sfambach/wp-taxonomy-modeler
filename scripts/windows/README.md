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

## Wenn `install-wordpress.ps1` fehlt oder git nach `y/n` fragt

Ursache: alter Stand auf `main` **oder** git pull aus `scripts\windows` (Ordner gesperrt).

**Einmal manuell in cmd** (Explorer-Fenster schließen, das Skripte offen hat):

```bat
cd /d C:\devel\wordpress\source
git -C wp-taxonomy-tree fetch origin
git -C wp-taxonomy-tree checkout main
git -C wp-taxonomy-tree pull --ff-only origin main
dir wp-taxonomy-tree\scripts\windows
```

Dann erneut **`setup-dev.bat`** doppelklicken.

Die `.bat`-Dateien wechseln jetzt vor dem git pull ins Repo-Root, damit `scripts\windows` nicht gesperrt ist.

## Nach erfolgreichem Setup

- http://devel.test
- http://devel.test/wp-admin — `admin` / `admin123`
