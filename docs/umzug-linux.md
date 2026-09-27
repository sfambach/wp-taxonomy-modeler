# Umzug der Entwicklungsumgebung nach Linux

**Stand 2026-09-27.** Gemessen am laufenden Windows-Rechner, nicht geschätzt.

⚠️ **Der Kniff, der die meiste Arbeit spart: der Linux-Rechner heisst wieder `devel.test`.** Dann muss
in der Datenbank keine einzige Adresse umgeschrieben werden — weder die 3982 Anhänge noch die Beiträge
noch die Verweise im Modell.

## Was umzieht

| Stück | Grösse | Woher |
|---|---|---|
| Datenbank `wordpress`, 31 Tabellen | 74 MB | `mysqldump` |
| davon die 17 `wp_taxmod_*` | 31 MB | im selben Abzug |
| `wp-content/uploads` | 3,2 GB | einmal kopieren |
| WordPress-Kern 7.1.2 | — | neu laden |
| 5 eigene Plugins | — | aus GitHub klonen |
| Tablepress, Akismet, WordPress-Importer | — | aus dem Plugin-Verzeichnis |

**Die Umgebung heute:** WordPress 7.1.2, PHP 8.3.30, MySQL 8.4.3, Kollation `utf8mb4_unicode_520_ci`,
Theme `penguin`.

## Schritt 1 · Sichern, bevor irgendetwas kopiert wird

```
git push --all
```

⚠️ **Ohne diesen Schritt ist der Umzug ein Risiko.** *Am 2026-09-27 lagen die Arbeiten von drei Tagen nur
auf dem Windows-Rechner: der Zweig `paket7-…` ohne Gegenstück, `main` 40 Commits voraus, und in
`wp-auto-correction` und `wp-changelog` je über 500 ungesicherte Zeilen.*

## Schritt 2 · Die Datenbank abziehen

```
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe" -h 127.0.0.1 -u root --single-transaction --default-character-set=utf8mb4 --routines --events wordpress > wordpress.sql
```

## Schritt 3 · Auf Linux: PHP, MySQL, Apache

PHP 8.3 **mit `mysqli`** — ohne das Modul läuft kein einziges Wächterskript. Dazu MySQL 8.4; bei MariaDB
zuerst prüfen, ob sie `utf8mb4_unicode_520_ci` mitbringt.

```
sudo apt install apache2 php8.3 php8.3-mysql php8.3-mbstring php8.3-xml php8.3-gd php8.3-curl php8.3-zip mysql-server
```

## Schritt 4 · Einspielen

```
mysql -u root -e "CREATE DATABASE wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root wordpress < wordpress.sql
```

Dazu `wp-config.php` neu schreiben und `uploads` kopieren.

## Schritt 5 · Der Ort des Plugins

⚠️ **Das Plugin liegt nicht im Plugin-Ordner, es ist von dort verknüpft** — auf Windows zeigt
`wp-content/plugins/wp-taxonomy-tree` auf `source/wp-taxonomy-tree`. Dasselbe auf Linux:

```
ln -s ~/wordpress/source/wp-taxonomy-tree ~/wordpress/wp-content/plugins/wp-taxonomy-tree
```

## Schritt 6 · Der Name

```
echo "127.0.0.1 devel.test" | sudo tee -a /etc/hosts
```

Ein Apache-Eintrag für `devel.test` mit `DocumentRoot` auf das WordPress-Verzeichnis, und
`FollowSymLinks` muss an sein, sonst bleibt das verknüpfte Plugin unsichtbar.

## Schritt 7 · Die Pfade der Skripte

Die Skripte lesen den Ort von WordPress aus `WP_ROOT`, die KiCad-Projekte aus `PLATINEN_ROOT`:

```
export WP_ROOT=$HOME/wordpress
export PLATINEN_ROOT=$HOME/Platinen/projekte
```

## Schritt 8 · Prüfen, in dieser Reihenfolge

1. `composer dump-autoload -o` — Linux unterscheidet Gross- und Kleinschreibung, ein falsch
   geschriebener Dateiname fällt hier auf und sonst nirgends.
2. Der Kernlauf: `vendor/bin/phpunit`.
3. Der Randlauf: jedes `scripts/dev/*-check.php`.
4. Im Browser: eine Satzseite, der Baum, ein Speichern.

⚠️ **Zwei Wächter sind schon auf Windows rot** (`collapsed-default`, `superseded`) — sie sind kein
Befund des Umzugs.
