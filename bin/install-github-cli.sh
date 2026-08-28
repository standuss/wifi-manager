#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Instalaci GitHub CLI musí spouštět root." >&2
    exit 1
fi

KEYRING_URL=https://cli.github.com/packages/githubcli-archive-keyring.gpg
KEYRING_SHA256=6084d5d7bd8e288441e0e94fc6275570895da18e6751f70f057485dc2d1a811b
KEYRING_PATH=/etc/apt/keyrings/githubcli-archive-keyring.gpg
SOURCE_PATH=/etc/apt/sources.list.d/github-cli.list
WORK=$(mktemp -d)

cleanup() {
    rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

install -d -o root -g root -m 0755 /etc/apt/keyrings /etc/apt/sources.list.d
curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 \
    --output "$WORK/githubcli-archive-keyring.gpg" "$KEYRING_URL"
printf '%s  %s\n' "$KEYRING_SHA256" "$WORK/githubcli-archive-keyring.gpg" | sha256sum -c -
install -o root -g root -m 0644 "$WORK/githubcli-archive-keyring.gpg" "$KEYRING_PATH"

ARCHITECTURE=$(dpkg --print-architecture)
printf 'deb [arch=%s signed-by=%s] https://cli.github.com/packages stable main\n' \
    "$ARCHITECTURE" "$KEYRING_PATH" > "$WORK/github-cli.list"
install -o root -g root -m 0644 "$WORK/github-cli.list" "$SOURCE_PATH"

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends gh

if ! /usr/bin/gh attestation verify --help >/dev/null 2>&1; then
    echo "Nainstalovaná GitHub CLI nepodporuje ověřování artifact attestations." >&2
    exit 1
fi

echo "GitHub CLI s podporou artifact attestations je připravená."
