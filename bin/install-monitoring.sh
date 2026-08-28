#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Instalaci musí spustit root." >&2
    exit 1
fi

APP_DIR=${WFM_APP_DIR:-/var/www/wifimanager}
LISTEN_ADDRESS=${1:-}
if [ -z "$LISTEN_ADDRESS" ]; then
    echo "Použití: $0 IP_ADRESA_SERVERU" >&2
    exit 1
fi
php -r 'if (filter_var($argv[1], FILTER_VALIDATE_IP) === false) { fwrite(STDERR, "Neplatná IP adresa.\n"); exit(1); }' "$LISTEN_ADDRESS"

apt-get update
apt-get install -y --no-install-recommends rsyslog nfdump php-cli curl ca-certificates unzip rsync sqlite3 iproute2 util-linux
"$APP_DIR/bin/install-github-cli.sh"

getent group www-data >/dev/null
if ! id wifimanager-log >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/wifimanager --shell /usr/sbin/nologin --gid www-data wifimanager-log
fi

install -d -o root -g www-data -m 2750 /var/lib/wifimanager
install -d -o root -g www-data -m 2750 /var/lib/wifimanager/syslog
install -d -o wifimanager-log -g www-data -m 2750 /var/lib/wifimanager/netflow
install -d -o www-data -g www-data -m 2770 /var/lib/wifimanager/service-requests
install -d -o www-data -g www-data -m 2770 /var/lib/wifimanager/update-requests
install -d -o root -g www-data -m 2750 /var/lib/wifimanager/gh
install -d -o root -g www-data -m 0750 /var/lib/wifimanager/gh-cache
install -d -o root -g root -m 0755 /usr/local/lib/wifimanager /etc/wifimanager

install -o root -g root -m 0644 "$APP_DIR/deploy/logging/wifimanager-monitoring.env" /etc/default/wifimanager-monitoring
sed -i "s/^WFM_LISTEN_ADDRESS=.*/WFM_LISTEN_ADDRESS=$LISTEN_ADDRESS/" /etc/default/wifimanager-monitoring

SYSLOG_TCP_PORT=$(sed -n 's/^WFM_SYSLOG_TCP_PORT=//p' /etc/default/wifimanager-monitoring)
SYSLOG_UDP_PORT=$(sed -n 's/^WFM_SYSLOG_UDP_PORT=//p' /etc/default/wifimanager-monitoring)
NETFLOW_PORT=$(sed -n 's/^WFM_NETFLOW_PORT=//p' /etc/default/wifimanager-monitoring)
sed -e "s/@@LISTEN_ADDRESS@@/$LISTEN_ADDRESS/g" \
    -e "s/@@SYSLOG_TCP_PORT@@/$SYSLOG_TCP_PORT/g" \
    -e "s/@@SYSLOG_UDP_PORT@@/$SYSLOG_UDP_PORT/g" \
    "$APP_DIR/deploy/logging/rsyslog-wifimanager.conf" > /etc/rsyslog.d/30-wifimanager.conf
chmod 0644 /etc/rsyslog.d/30-wifimanager.conf

install -o root -g root -m 0644 "$APP_DIR/deploy/logging/wifimanager-nfcapd.service" /etc/systemd/system/wifimanager-nfcapd.service
install -o root -g root -m 0644 "$APP_DIR/deploy/logging/wifimanager-retention.service" /etc/systemd/system/wifimanager-retention.service
install -o root -g root -m 0644 "$APP_DIR/deploy/logging/wifimanager-retention.timer" /etc/systemd/system/wifimanager-retention.timer
install -o root -g root -m 0755 "$APP_DIR/bin/apply-system-settings.php" /usr/local/lib/wifimanager/apply-system-settings.php
install -o root -g root -m 0644 "$APP_DIR/deploy/logging/wifimanager-system-apply.service" /etc/systemd/system/wifimanager-system-apply.service
install -o root -g root -m 0644 "$APP_DIR/deploy/logging/wifimanager-system-apply.path" /etc/systemd/system/wifimanager-system-apply.path
install -o root -g root -m 0755 "$APP_DIR/bin/apply-update.sh" /usr/local/lib/wifimanager/apply-update.sh
install -o root -g root -m 0640 "$APP_DIR/deploy/update/wifimanager-update.conf" /etc/wifimanager/update.conf
install -o root -g root -m 0644 "$APP_DIR/deploy/update/wifimanager-doctor.service" /etc/systemd/system/wifimanager-doctor.service
install -o root -g root -m 0644 "$APP_DIR/deploy/update/wifimanager-update.service" /etc/systemd/system/wifimanager-update.service
install -o root -g root -m 0644 "$APP_DIR/deploy/update/wifimanager-update.path" /etc/systemd/system/wifimanager-update.path

rsyslogd -N1
systemctl daemon-reload

# Balíček nfdump na Debianu automaticky zapíná vlastní nfcapd@default na UDP
# 2055. WiFi Manager používá stejný program s vlastním úložištěm a omezeným
# systemd účtem, proto výchozí instanci vypneme dříve, než spustíme naši.
systemctl disable --now nfdump@default.service nfdump.service >/dev/null 2>&1 || true
systemctl reset-failed wifimanager-nfcapd.service >/dev/null 2>&1 || true

# rsyslog mohl být spuštěn už během instalace balíčku. Restart je nutný, aby
# načetl právě vytvořené vstupy pro TCP/5514 a UDP/514.
systemctl enable rsyslog.service >/dev/null
systemctl restart rsyslog.service
systemctl enable --now wifimanager-nfcapd.service wifimanager-retention.timer wifimanager-system-apply.path wifimanager-update.path
systemctl start wifimanager-retention.service

systemctl --quiet is-active rsyslog.service wifimanager-nfcapd.service
if [ -z "$(ss -H -lnt "sport = :$SYSLOG_TCP_PORT")" ]; then
    echo "rsyslog neposlouchá na TCP portu $SYSLOG_TCP_PORT." >&2
    exit 1
fi
if [ -z "$(ss -H -lnu "sport = :$SYSLOG_UDP_PORT")" ]; then
    echo "rsyslog neposlouchá na UDP portu $SYSLOG_UDP_PORT." >&2
    exit 1
fi
if [ -z "$(ss -H -lnu "sport = :$NETFLOW_PORT")" ]; then
    echo "nfcapd neposlouchá na UDP portu $NETFLOW_PORT." >&2
    exit 1
fi

echo "Monitoring je připravený na $LISTEN_ADDRESS: TCP/$SYSLOG_TCP_PORT, UDP/$SYSLOG_UDP_PORT a IPFIX/$NETFLOW_PORT."
echo "RouterOS šablona: $APP_DIR/deploy/logging/mikrotik-routeros.rsc.example"
