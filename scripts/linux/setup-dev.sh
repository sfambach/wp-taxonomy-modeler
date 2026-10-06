#!/usr/bin/env bash
# Baut die Entwicklungsumgebung auf Linux aus der Windows-Installation auf demselben Rechner nach.
# Die Windows-Platte wird nur gelesen. Jeder Schritt ist wiederholbar.
# @see docs/umzug-linux.md
set -euo pipefail

WIN_ROOT="${WIN_ROOT:-/media/stefan/F85E3B4B5E3B01C4}"
WIN_WP="${WIN_WP:-$WIN_ROOT/Devel/Wordpress}"
WIN_MYSQL_DATA="${WIN_MYSQL_DATA:-$WIN_ROOT/laragon/data/mysql-8.4}"
WP_ROOT="${WP_ROOT:-$HOME/Devel/WP/Tax}"
WORK="${WORK:-$HOME/.cache/taxmod-umzug}"
SITE_HOST="${SITE_HOST:-devel.test}"
DB_NAME="${DB_NAME:-wordpress}"
DB_USER="${DB_USER:-wordpress}"
DB_PASS="${DB_PASS:-wordpress}"
MYSQL_VERSION="8.4.3"
TEMP_PORT=3399

# Link im Plugin-Ordner -> Quellordner unter source/
declare -A PLUGINS=(
  [wp-taxonomy-tree]=wp-taxonomy-tree
  [wp-list-of-sources]=wp-list-of-sources
  [wp-changelog]=wp-changelog
  [wp-auto-correction]=wp-auto-correction
  [budget-translator]=wp-budget-translator
)

step() { printf '\n\033[1;34m== %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mXX %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -ne 0 ]] || die "Nicht als root starten; sudo wird bei Bedarf selbst gerufen."
[[ -f "$WIN_WP/wp-config.php" ]] || die "Windows-WordPress nicht gefunden: $WIN_WP (Platte eingehaengt?)"
[[ -f "$WIN_MYSQL_DATA/ibdata1" ]] || die "Laragon-Datenverzeichnis nicht gefunden: $WIN_MYSQL_DATA"
mkdir -p "$WP_ROOT" "$WORK/tmp"

step "1/9 Pakete"
sudo apt-get update -q
sudo apt-get install -y -q apache2 mysql-server php libapache2-mod-php php-cli php-mysql php-mbstring \
  php-xml php-gd php-curl php-zip php-intl php-sqlite3 composer rsync curl xz-utils libnuma1
sudo apt-get install -y -q libaio1t64 2>/dev/null || sudo apt-get install -y -q libaio1
php -m | grep -qi '^mysqli$' || die "PHP ohne mysqli"

step "2/9 WordPress-Kern, Themes, fremde Plugins, uploads (von der Windows-Platte)"
rsync -a --info=progress2 \
  --exclude='/source/' --exclude='/backups/' --exclude='/web/' \
  $(for l in "${!PLUGINS[@]}"; do printf -- "--exclude=/wp-content/plugins/%s " "$l"; done) \
  "$WIN_WP/" "$WP_ROOT/"

step "3/9 Eigene Plugins nach source/ (samt .git und nicht eingecheckter Aenderungen)"
mkdir -p "$WP_ROOT/source"
for link in "${!PLUGINS[@]}"; do
  src="${PLUGINS[$link]}"
  [[ -d "$WIN_WP/source/$src" ]] || { warn "fehlt auf Windows: source/$src"; continue; }
  rsync -a --exclude='/vendor/' --exclude='/node_modules/' "$WIN_WP/source/$src/" "$WP_ROOT/source/$src/"
  git -C "$WP_ROOT/source/$src" config core.autocrlf input
  ln -sfn "$WP_ROOT/source/$src" "$WP_ROOT/wp-content/plugins/$link"
  if [[ -f "$WP_ROOT/source/$src/composer.json" ]]; then
    (cd "$WP_ROOT/source/$src" && composer install -q --no-interaction)
  fi
  dirty=$(git -C "$WP_ROOT/source/$src" status --porcelain 2>/dev/null | wc -l)
  ahead=$(git -C "$WP_ROOT/source/$src" log --branches --not --remotes --oneline 2>/dev/null | wc -l)
  echo "  $src: $dirty geaenderte Dateien, $ahead Commits nicht auf GitHub"
done

step "4/9 MySQL $MYSQL_VERSION (Linux-Tarball) nur zum Auslesen der Laragon-Daten"
MYSQL_BASE="$WORK/mysql-$MYSQL_VERSION"
if [[ ! -x "$MYSQL_BASE/bin/mysqld" ]]; then
  for name in "mysql-$MYSQL_VERSION-linux-glibc2.17-x86_64-minimal" "mysql-$MYSQL_VERSION-linux-glibc2.28-x86_64"; do
    if curl -fL --retry 2 -o "$WORK/$name.tar.xz" "https://cdn.mysql.com/archives/mysql-8.4/$name.tar.xz"; then
      tar -xJf "$WORK/$name.tar.xz" -C "$WORK" && mv "$WORK/$name" "$MYSQL_BASE" && break
    fi
  done
  [[ -x "$MYSQL_BASE/bin/mysqld" ]] || die "MySQL-Tarball nicht ladbar"
fi
# Ubuntu 24.04 / Mint 22 benennt libaio um; der Tarball sucht libaio.so.1.
mkdir -p "$WORK/lib"
for cand in /usr/lib/x86_64-linux-gnu/libaio.so.1t64 /usr/lib/x86_64-linux-gnu/libaio.so.1; do
  [[ -e $cand ]] && { ln -sfn "$cand" "$WORK/lib/libaio.so.1"; break; }
done
# Nur fuer die Tarball-Programme: dessen lib/private bringt eigenes OpenSSL mit.
with_mysql_libs() { LD_LIBRARY_PATH="$WORK/lib:$MYSQL_BASE/lib/private" "$@"; }

step "5/9 Laragon-Daten kopieren (Original bleibt unberuehrt) und abziehen"
DUMP="$WORK/wordpress.sql"
if [[ ! -s "$DUMP" ]]; then
  rsync -a --delete --exclude='binlog.*' --exclude='*.pid' --exclude='ibtmp1' \
    --exclude='#innodb_temp/' --exclude='mysqld.log' --exclude='*.err' \
    "$WIN_MYSQL_DATA/" "$WORK/mysql-data/"
  SOCK="$WORK/mysqld-temp.sock"
  # Windows legt das Datenverzeichnis mit lower_case_table_names=1 an; es muss gleich bleiben.
  with_mysql_libs "$MYSQL_BASE/bin/mysqld" --no-defaults --basedir="$MYSQL_BASE" --datadir="$WORK/mysql-data" \
    --lower-case-table-names=1 --skip-grant-tables --skip-networking --disable-log-bin --mysqlx=OFF \
    --socket="$SOCK" --pid-file="$WORK/mysqld-temp.pid" --tmpdir="$WORK/tmp" \
    --log-error="$WORK/mysqld-temp.err" --innodb-buffer-pool-size=512M --max-allowed-packet=512M &
  for _ in $(seq 1 120); do [[ -S $SOCK ]] && break; sleep 1; done
  [[ -S $SOCK ]] || { tail -30 "$WORK/mysqld-temp.err"; die "Temporaerer MySQL startet nicht (siehe $WORK/mysqld-temp.err). Ausweg: Schritt 2 aus docs/umzug-linux.md unter Windows."; }
  with_mysql_libs "$MYSQL_BASE/bin/mysqldump" --no-defaults -S "$SOCK" -u root --single-transaction \
    --default-character-set=utf8mb4 --routines --events --max-allowed-packet=512M \
    "$DB_NAME" > "$DUMP.part"
  with_mysql_libs "$MYSQL_BASE/bin/mysqladmin" --no-defaults -S "$SOCK" -u root shutdown
  wait || true
  mv "$DUMP.part" "$DUMP"
fi
echo "  Abzug: $(du -h "$DUMP" | cut -f1)"

step "6/9 Datenbank einspielen"
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
if [[ "$(sudo mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")" == 0 ]]; then
  sudo mysql --max-allowed-packet=512M "$DB_NAME" < "$DUMP"
else
  warn "Datenbank $DB_NAME ist schon gefuellt — nicht ueberschrieben. Neu: sudo mysql -e 'DROP DATABASE $DB_NAME' und erneut starten."
fi
echo "  Tabellen: $(sudo mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")"

step "7/9 wp-config.php"
WPCLI="php $WP_ROOT/wp-cli.phar --path=$WP_ROOT"
$WPCLI config set DB_NAME "$DB_NAME" --quiet
$WPCLI config set DB_USER "$DB_USER" --quiet
$WPCLI config set DB_PASSWORD "$DB_PASS" --quiet
$WPCLI config set DB_HOST localhost --quiet

step "8/9 Apache und $SITE_HOST"
grep -qE "[[:space:]]$SITE_HOST([[:space:]]|$)" /etc/hosts || echo "127.0.0.1 $SITE_HOST" | sudo tee -a /etc/hosts >/dev/null
# Apache laeuft als der eigene Benutzer: Home ist fuer www-data gesperrt, uploads bleiben beschreibbar.
sudo sed -i "s/^export APACHE_RUN_USER=.*/export APACHE_RUN_USER=$USER/; s/^export APACHE_RUN_GROUP=.*/export APACHE_RUN_GROUP=$(id -gn)/" /etc/apache2/envvars
sudo tee /etc/apache2/sites-available/$SITE_HOST.conf >/dev/null <<CONF
<VirtualHost *:80>
    ServerName $SITE_HOST
    DocumentRoot $WP_ROOT
    <Directory $WP_ROOT>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/$SITE_HOST-error.log
    CustomLog \${APACHE_LOG_DIR}/$SITE_HOST-access.log combined
</VirtualHost>
CONF
sudo a2enmod -q rewrite
sudo a2ensite -q "$SITE_HOST"
sudo systemctl restart apache2
grep -q 'export WP_ROOT=' "$HOME/.profile" || echo "export WP_ROOT=$WP_ROOT" >> "$HOME/.profile"
export WP_ROOT

step "9/9 Pruefen"
PLUGIN="$WP_ROOT/source/wp-taxonomy-tree"
(cd "$PLUGIN" && composer dump-autoload -o -q && php vendor/bin/phpunit --no-progress | tail -3) || warn "Kernlauf rot"
code=$(curl -s -o /dev/null -w '%{http_code}' "http://$SITE_HOST/")
echo "  http://$SITE_HOST/ -> $code"
red=()
for check in "$PLUGIN"/scripts/dev/*-check.php; do
  if php "$check" "$WP_ROOT" >"$WORK/$(basename "$check").log" 2>&1; then printf '.'; else printf 'F'; red+=("$(basename "$check")"); fi
done
echo
if ((${#red[@]})); then
  warn "Rote Waechter (${#red[@]}), Logs in $WORK:"; printf '  %s\n' "${red[@]}"
  echo "  Auf Windows schon rot: collapsed-default-check.php, superseded-check.php"
else
  echo "  Alle Waechter gruen."
fi
echo
echo "Fertig: http://$SITE_HOST/wp-admin — WP_ROOT=$WP_ROOT (neue Shell oder: source ~/.profile)"
