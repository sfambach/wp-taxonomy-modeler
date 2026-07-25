# Windows Setup

## Cloud vs. dein PC

| | **Cloud-Umgebung (Cursor Agent)** | **Dein Windows-PC (Laragon)** |
|---|---|---|
| Wo | Linux-VM in der Cloud | `C:\devel\wordpress` bei dir |
| WordPress | `~/wordpress` + SQLite | Laragon + MySQL |
| Plugin-Quellcode | `/workspace` (Symlink) | `source\wp-taxonomy-tree` (Junction) |
| Wer richtet ein | Der Agent direkt | **Du** — per `.bat`, Agent hat kein `C:\` |

Der Agent **kann Laragon auf deinem Rechner nicht starten** — er läuft in einer
getrennten Cloud-VM. Die Skripte sind die Windows-Entsprechung dessen, was
in der Cloud schon automatisch existiert (`AGENTS.md`).

---

## Was welches Skript tut

| Skript | Aufgabe | Git? |
|--------|---------|------|
| **`setup-dev.bat`** | Laragon starten, Junctions, WordPress installieren | Nur **Clone**, wenn Repo fehlt — **kein pull** |
| **`install-wordpress.bat`** | Nur WordPress unter `C:\devel\wordpress` | Nein |
| **`recover-repo.bat`** | Repo neu klonen oder reparieren | Ja — **nur mit J-Bestätigung** |
| **`unlock-and-clone.bat`** | Laragon stoppen, Junctions entfernen, neu klonen | Ja |

### Ordner lässt sich nicht löschen (Laragon)

Laragon hält den Ordner oft über **Junctions**:
- `wp-content\plugins\wp-taxonomy-tree` → `source\wp-taxonomy-tree`
- `laragon\www\devel` → `C:\devel\wordpress`

**Laragon beenden ohne Taskleisten-Icon:**

1. **Task-Manager** (`Strg+Shift+Esc`) → Prozesse beenden:
   - `laragon.exe`
   - `httpd.exe` (Apache)
   - `nginx.exe` (falls Nginx statt Apache)
   - `mysqld.exe`
2. Oder in **cmd**:

```bat
taskkill /IM laragon.exe /F
taskkill /IM httpd.exe /F
taskkill /IM nginx.exe /F
taskkill /IM mysqld.exe /F
taskkill /IM php-cgi.exe /F
timeout /t 3
```

3. Laragon ggf. manuell starten: `C:\laragon\laragon.exe` — danach **Rechtsklick** aufs Icon → Exit (falls sichtbar).

4. Cursor/Explorer schließen, dann Junctions entfernen:

```bat
rmdir C:\devel\wordpress\wp-content\plugins\wp-taxonomy-tree
rmdir C:\laragon\www\devel
```

5. **`unlock-and-clone.bat`** — macht das automatisch und klont neu.

Dann erst den `source\`-Ordner löschen oder klonen.

### `setup-dev.bat` im Detail (Entsprechung zur Cloud)

1. Laragon starten (Apache + MySQL)
2. Repo unter `source\wp-taxonomy-tree` — **nur beim allerersten Mal** klonen
3. Junction Plugin → `wp-content\plugins\wp-taxonomy-tree`
4. Junction `laragon\www\devel` → `C:\devel\wordpress` → `http://devel.test`
5. WordPress installieren (`install-wordpress.ps1`)

**Wichtig:** Frühere Versionen haben bei jedem Start `git pull` gemacht — das
hat unter Windows `scripts\windows` zerstört, wenn die `.bat` aus dem Ordner lief.
Das ist entfernt.

---

## Starten

**Doppelklick** (nicht `.ps1`):

- `setup-dev.bat` — normales Setup
- `install-wordpress.bat` — nur WordPress
- `recover-repo.bat` — Repo bewusst neu holen

---

## Repo komplett weg?

In **cmd**:

```bat
mkdir C:\devel\wordpress\source 2>nul
git clone https://github.com/sfambach/wp-taxonomy-tree.git C:\devel\wordpress\source\wp-taxonomy-tree
C:\devel\wordpress\source\wp-taxonomy-tree\scripts\windows\setup-dev.bat
```

---

## Nach erfolgreichem Setup

- http://devel.test
- http://devel.test/wp-admin — `admin` / `admin123`
