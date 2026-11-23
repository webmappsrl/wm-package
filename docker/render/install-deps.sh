#!/usr/bin/env bash
# Installa le dipendenze di sistema per la compilazione dei PDF di stampa (XeLaTeX).
# Pensato per immagini Debian 13 (trixie), es. php:8.4-fpm. Idempotente.
# Legge VERSION dalla propria cartella e scrive il marcatore controllato da XelatexCompiler.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MARKER_FILE="/usr/local/share/wm-print/version"

# Solo Debian >= 13
if [ ! -r /etc/os-release ]; then
    echo "install-deps.sh: /etc/os-release non trovato, sistema non supportato (serve Debian 13 o successivo)." >&2
    exit 1
fi
# shellcheck disable=SC1091
. /etc/os-release
if [ "${ID:-}" != "debian" ]; then
    echo "install-deps.sh: sistema '${ID:-sconosciuto}' non supportato, serve Debian 13 o successivo." >&2
    exit 1
fi
DEBIAN_MAJOR="${VERSION_ID:-0}"
DEBIAN_MAJOR="${DEBIAN_MAJOR%%.*}"
if ! [[ "$DEBIAN_MAJOR" =~ ^[0-9]+$ ]] || [ "$DEBIAN_MAJOR" -lt 13 ]; then
    echo "install-deps.sh: Debian ${VERSION_ID:-sconosciuta} non supportata, serve Debian 13 o successivo." >&2
    exit 1
fi

if [ ! -r "$SCRIPT_DIR/VERSION" ]; then
    echo "install-deps.sh: file VERSION non trovato in $SCRIPT_DIR." >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends \
    texlive-xetex \
    texlive-fonts-recommended \
    fontconfig
rm -rf /var/lib/apt/lists/*

fc-cache -f

# Marcatore della versione delle dipendenze
mkdir -p "$(dirname "$MARKER_FILE")"
tr -d '[:space:]' < "$SCRIPT_DIR/VERSION" > "$MARKER_FILE"

if [ ! -x /usr/bin/xelatex ]; then
    echo "install-deps.sh: xelatex non trovato in /usr/bin/xelatex dopo l'installazione." >&2
    exit 1
fi
