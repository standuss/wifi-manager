#!/bin/sh
set -eu

APP_DIR=/var/www/wifimanager
BASE_PATH=/wifimanager
SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

if [ "$(id -u)" -ne 0 ]; then
    echo "Instalaci spusťte jako root: sudo ./install.sh" >&2
    exit 1
fi

if [ ! -r /etc/os-release ] || ! grep -q '^ID=debian$' /etc/os-release || ! grep -q '^VERSION_ID="\?13"\?$' /etc/os-release; then
    echo "Automatická instalace je určená pro Debian 13." >&2
    exit 1
fi

echo "[1/7] Instaluji systémové balíčky…"
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    apache2 libapache2-mod-php php-cli php-sqlite3 php-mbstring php-curl \
    ca-certificates curl rsync unzip sqlite3 rsyslog nfdump iproute2 util-linux

LISTEN_ADDRESS=${WFM_LISTEN_ADDRESS:-}
if [ -z "$LISTEN_ADDRESS" ]; then
    LISTEN_ADDRESS=$(ip -o -4 address show scope global 2>/dev/null | awk 'NR == 1 { split($4, address, "/"); print address[1] }')
fi
if [ -z "$LISTEN_ADDRESS" ]; then
    printf 'IP adresa tohoto serveru: '
    read -r LISTEN_ADDRESS
fi
php -r 'if (filter_var($argv[1], FILTER_VALIDATE_IP) === false) { fwrite(STDERR, "Neplatná IP adresa.\n"); exit(1); }' "$LISTEN_ADDRESS"

echo "[2/7] Kopíruji WiFi Manager…"
install -d -o root -g www-data -m 2770 "$APP_DIR" "$APP_DIR/config" "$APP_DIR/storage"
install -d -o www-data -g www-data -m 2770 /var/lib/wifimanager/backups
if [ "$SOURCE_DIR" != "$APP_DIR" ]; then
    rsync -a --delete \
        --exclude .git/ --exclude dist/ --exclude config/local.php --exclude storage/ \
        "$SOURCE_DIR/" "$APP_DIR/"
fi
touch "$APP_DIR/storage/.gitkeep"
chown -R root:www-data "$APP_DIR"
chmod 2770 "$APP_DIR/config" "$APP_DIR/storage"
chmod 0750 "$APP_DIR/install.sh" "$APP_DIR/bin/"*.php "$APP_DIR/bin/"*.sh

echo "[3/7] Připravuji databázi a administrátorský účet…"
runuser -u www-data -- env WFM_BASE_PATH="$BASE_PATH" php "$APP_DIR/bin/install.php"
runuser -u www-data -- php "$APP_DIR/bin/doctor.php"
chmod 0750 "$APP_DIR/config"

echo "[4/7] Nastavuji Apache…"
install -o root -g root -m 0644 "$APP_DIR/deploy/apache.conf.example" /etc/apache2/conf-available/wifimanager.conf
a2enmod rewrite >/dev/null
a2enconf wifimanager >/dev/null
apache2ctl configtest
systemctl reload apache2

echo "[5/7] Zapínám synchronizaci RouterOS…"
install -o root -g root -m 0644 "$APP_DIR/deploy/wifimanager-worker.service" /etc/systemd/system/wifimanager-worker.service
systemctl daemon-reload
systemctl enable --now wifimanager-worker.service

echo "[6/7] Instaluji syslog, CEF, IPFIX a aktualizace…"
"$APP_DIR/bin/install-monitoring.sh" "$LISTEN_ADDRESS"
runuser -u www-data -- php -r '
$container = require $argv[1] . "/app/bootstrap.php";
$statement = $container["database"]->pdo()->prepare("INSERT INTO app_settings (key,value,updated_at) VALUES (\"monitor_listen_address\",:value,CURRENT_TIMESTAMP) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP");
$statement->execute(["value" => $argv[2]]);
' "$APP_DIR" "$LISTEN_ADDRESS"

echo "[7/7] Provádím závěrečnou kontrolu…"
runuser -u www-data -- php "$APP_DIR/bin/doctor.php"
systemctl --quiet is-active apache2 wifimanager-worker rsyslog wifimanager-nfcapd wifimanager-retention.timer

echo
echo "WiFi Manager je připravený: http://$LISTEN_ADDRESS$BASE_PATH"
echo "V administraci už stačí zadat připojení k MikroTiku a otestovat ho."
echo "Aktualizace používají pevně nastavený veřejný repozitář standuss/wifi-manager bez GitHub tokenu."
