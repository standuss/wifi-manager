#!/bin/sh
set -eu

REQUEST=/var/lib/wifimanager/update-requests/install.json
CONFIG=/etc/wifimanager/update.conf
STATUS=/var/lib/wifimanager/update-status.json
LOCK=/var/lib/wifimanager/update.lock

write_status() {
    /usr/bin/php -r '$path=$argv[1];$data=["state"=>$argv[2],"message"=>$argv[3],"version"=>$argv[4],"updated_at"=>date(DATE_ATOM)];$tmp=$path.".tmp-".getmypid();file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n",LOCK_EX);chmod($tmp,0640);rename($tmp,$path);chgrp($path,"www-data");' "$STATUS" "$1" "$2" "${3:-}"
}

if [ "$(id -u)" -ne 0 ]; then
    echo "Aktualizaci musí spouštět root." >&2
    exit 1
fi
[ -r "$CONFIG" ] || { echo "Chybí $CONFIG." >&2; exit 1; }
[ -r "$REQUEST" ] || exit 0

exec 9>"$LOCK"
/usr/bin/flock -n 9 || { echo "Jiná aktualizace už běží." >&2; exit 1; }

WFM_UPDATE_REPOSITORY=
WFM_APP_DIR=/var/www/wifimanager
. "$CONFIG"

REPOSITORY=$(/usr/bin/php -r '$j=json_decode(file_get_contents($argv[1]),true);echo is_array($j)?($j["repository"]??""):"";' "$REQUEST")
TAG=$(/usr/bin/php -r '$j=json_decode(file_get_contents($argv[1]),true);echo is_array($j)?($j["tag"]??""):"";' "$REQUEST")
VERSION=$(/usr/bin/php -r '$j=json_decode(file_get_contents($argv[1]),true);echo is_array($j)?($j["version"]??""):"";' "$REQUEST")
ASSET=$(/usr/bin/php -r '$j=json_decode(file_get_contents($argv[1]),true);echo is_array($j)?($j["asset_name"]??""):"";' "$REQUEST")

case "$REPOSITORY" in *[!A-Za-z0-9_.\/-]*|/*|*/*/*|'') write_status failed "Neplatný repozitář v požadavku." "$VERSION"; rm -f "$REQUEST"; exit 1;; esac
[ "$REPOSITORY" = "$WFM_UPDATE_REPOSITORY" ] || { write_status failed "Repozitář neodpovídá povolené root konfiguraci." "$VERSION"; rm -f "$REQUEST"; exit 1; }
case "$VERSION" in *[!0-9A-Za-z.+-]*|'') write_status failed "Neplatná verze." "$VERSION"; rm -f "$REQUEST"; exit 1;; esac
[ "$TAG" = "v$VERSION" ] || { write_status failed "Tag vydání neodpovídá verzi." "$VERSION"; rm -f "$REQUEST"; exit 1; }
[ "$ASSET" = "wifi-manager-$VERSION.zip" ] || { write_status failed "Neočekávaný název release balíčku." "$VERSION"; rm -f "$REQUEST"; exit 1; }
case "$TAG" in *[!0-9A-Za-z._+-]*|'') write_status failed "Neplatný release tag." "$VERSION"; rm -f "$REQUEST"; exit 1;; esac

WORK=$(/usr/bin/mktemp -d /var/lib/wifimanager/update-work.XXXXXX)
MAINTENANCE="$WFM_APP_DIR/storage/update-in-progress"
ROLLBACK_REQUIRED=0
FAILURE_MESSAGE="Aktualizace $VERSION selhala. Podrobnosti jsou v systémovém journalu."
cleanup() {
    rm -rf "$WORK"
}
rollback() {
    if [ "$ROLLBACK_REQUIRED" -eq 1 ]; then
        /usr/bin/rsync -a --delete "$WORK/backup/" "$WFM_APP_DIR/"
        if [ -f "$WORK/database.sqlite" ]; then
            DB_PATH=$(/usr/bin/php -r '$c=require $argv[1]."/app/bootstrap.php";echo $c["config"]->get("database.path");' "$WFM_APP_DIR")
            rm -f "$DB_PATH" "$DB_PATH-wal" "$DB_PATH-shm"
            cp "$WORK/database.sqlite" "$DB_PATH"
            chown www-data:www-data "$DB_PATH"
            chmod 0660 "$DB_PATH"
        fi
    fi
    rm -f "$MAINTENANCE"
    rm -f "$REQUEST"
    /usr/bin/systemctl start wifimanager-worker.service >/dev/null 2>&1 || true
}
on_exit() {
    EXIT_STATUS=$?
    trap - EXIT INT TERM
    if [ "$EXIT_STATUS" -ne 0 ]; then
        write_status failed "$FAILURE_MESSAGE" "$VERSION" || true
    fi
    rollback
    cleanup
    exit "$EXIT_STATUS"
}
trap on_exit EXIT INT TERM

write_status running "Stahuji a ověřuji release $VERSION." "$VERSION"
FAILURE_MESSAGE="Nainstalovaná GitHub CLI nepodporuje ověření dokladu původu. Nainstalujte aktuální oficiální balíček gh."
if ! /usr/bin/gh attestation verify --help >/dev/null 2>&1; then
    echo "$FAILURE_MESSAGE" >&2
    exit 1
fi

mkdir -p "$WORK/download" "$WORK/extract" "$WORK/backup"
ATTESTATION="$ASSET.attestation.jsonl"
RELEASE_URL="https://github.com/$REPOSITORY/releases/download/$TAG"
FAILURE_MESSAGE="Stažení release $VERSION z GitHubu selhalo."
/usr/bin/curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 --retry 3 \
    --output "$WORK/download/$ASSET" "$RELEASE_URL/$ASSET"
/usr/bin/curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 --retry 3 \
    --output "$WORK/download/$ATTESTATION" "$RELEASE_URL/$ATTESTATION"
FAILURE_MESSAGE="Ověření původu release $VERSION selhalo."
/usr/bin/gh attestation verify "$WORK/download/$ASSET" \
    --bundle "$WORK/download/$ATTESTATION" \
    --repo "$REPOSITORY" \
    --signer-workflow "github.com/$REPOSITORY/.github/workflows/release.yml" \
    --source-ref "refs/tags/$TAG" \
    --deny-self-hosted-runners
FAILURE_MESSAGE="Kontrola obsahu release $VERSION selhala."
/usr/bin/unzip -q "$WORK/download/$ASSET" -d "$WORK/extract"
[ -d "$WORK/extract/wifi-manager" ] || { write_status failed "Release neobsahuje adresář wifi-manager." "$VERSION"; exit 1; }
[ "$(tr -d '\r\n' < "$WORK/extract/wifi-manager/VERSION")" = "$VERSION" ] || { write_status failed "Verze uvnitř balíčku nesouhlasí." "$VERSION"; exit 1; }

find "$WORK/extract/wifi-manager" -type f -name '*.php' -print0 | xargs -0 -n1 /usr/bin/php -l >/dev/null
FAILURE_MESSAGE="Záloha současné instalace před aktualizací $VERSION selhala."
/usr/bin/rsync -a "$WFM_APP_DIR/" "$WORK/backup/"
DB_PATH=$(/usr/bin/php -r '$c=require $argv[1]."/app/bootstrap.php";echo $c["config"]->get("database.path");' "$WFM_APP_DIR")
if [ -f "$DB_PATH" ]; then /usr/bin/sqlite3 "$DB_PATH" ".backup '$WORK/database.sqlite'"; fi

touch "$MAINTENANCE"
/usr/bin/systemctl stop wifimanager-worker.service >/dev/null 2>&1 || true
ROLLBACK_REQUIRED=1
FAILURE_MESSAGE="Instalace release $VERSION selhala; obnovuji předchozí verzi."
/usr/bin/rsync -a --delete --exclude=config/local.php --exclude=storage/ "$WORK/extract/wifi-manager/" "$WFM_APP_DIR/"
chown -R root:www-data "$WFM_APP_DIR"
chmod 2770 "$WFM_APP_DIR/storage" "$WFM_APP_DIR/config"
install -d -o www-data -g www-data -m 2770 /var/lib/wifimanager/backups
chmod 0750 "$WFM_APP_DIR/bin/"*.php "$WFM_APP_DIR/bin/"*.sh
install -o root -g root -m 0755 "$WFM_APP_DIR/bin/apply-system-settings.php" /usr/local/lib/wifimanager/apply-system-settings.php
install -o root -g root -m 0755 "$WFM_APP_DIR/bin/apply-update.sh" /usr/local/lib/wifimanager/apply-update.sh
if [ ! -f /etc/wifimanager/update.conf ]; then
    install -o root -g root -m 0640 "$WFM_APP_DIR/deploy/update/wifimanager-update.conf" /etc/wifimanager/update.conf
fi
for UNIT in \
    wifimanager-worker.service \
    logging/wifimanager-nfcapd.service \
    logging/wifimanager-retention.service \
    logging/wifimanager-retention.timer \
    logging/wifimanager-system-apply.service \
    logging/wifimanager-system-apply.path \
    update/wifimanager-doctor.service \
    update/wifimanager-update.service \
    update/wifimanager-update.path
do
    install -o root -g root -m 0644 "$WFM_APP_DIR/deploy/$UNIT" "/etc/systemd/system/$(basename "$UNIT")"
done
/usr/bin/systemctl daemon-reload

# Debianí balíček nfdump může po instalaci automaticky spustit vlastní
# nfcapd@default na UDP/2055. Při aktualizaci ze starších verzí jej musíme
# stejně jako čistý instalátor vypnout, jinak náš omezený kolektor nenastartuje.
/usr/bin/systemctl disable --now nfdump@default.service nfdump.service >/dev/null 2>&1 || true
/usr/bin/systemctl reset-failed wifimanager-nfcapd.service >/dev/null 2>&1 || true

# Jednotky i rsyslog konfigurace už mohou existovat ze starší instalace.
# Restart zajistí jejich načtení a enable --now současně opraví případ, kdy
# předchozí konflikt nechal některou službu zastavenou.
/usr/bin/systemctl enable rsyslog.service >/dev/null
/usr/bin/systemctl restart rsyslog.service
/usr/bin/systemctl enable --now \
    wifimanager-nfcapd.service \
    wifimanager-retention.timer \
    wifimanager-system-apply.path \
    wifimanager-update.path
/usr/bin/systemctl start wifimanager-retention.service
FAILURE_MESSAGE="Kontrola aplikace po instalaci release $VERSION selhala; obnovuji předchozí verzi."
if ! /usr/bin/systemctl start wifimanager-doctor.service; then
    /usr/bin/journalctl -u wifimanager-doctor.service -n 50 --no-pager >&2 || true
    exit 1
fi

ROLLBACK_REQUIRED=0
rm -f "$MAINTENANCE" "$REQUEST" /var/lib/wifimanager/system-apply-status.json
/usr/bin/systemctl restart wifimanager-worker.service
write_status done "Aktualizace $VERSION byla úspěšně nainstalována." "$VERSION"
trap - EXIT INT TERM
cleanup
