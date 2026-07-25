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
