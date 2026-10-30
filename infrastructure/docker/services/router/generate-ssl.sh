#!/usr/bin/env bash

# Script used in dev to generate a basic SSL cert, for the domains given as
# arguments, the root domain first (castor docker:generate-certificates passes
# root_domain and extra_domains):
#
#   generate-ssl.sh app.test www.app.test

set -e

BASE=$(dirname "$0")

CERTS_DIR=$BASE/certs

ROOT_DOMAIN=${1:-app.test}

rm -rf "$CERTS_DIR"
mkdir -p "$CERTS_DIR"
touch "$CERTS_DIR/.gitkeep"

# The subdomains too (e.g. the dashboards of the services), as mkcert does
CONFIG=$(mktemp)
trap 'rm -f "$CONFIG"' EXIT
{
    sed "s/^CN=.*/CN=${ROOT_DOMAIN}/" "$BASE/openssl.cnf"
    i=1
    for domain in "$ROOT_DOMAIN" "*.$ROOT_DOMAIN" "${@:2}"; do
        echo "DNS.$i = $domain"
        i=$((i + 1))
    done
} > "$CONFIG"

openssl req -x509 -sha256 -newkey rsa:4096 \
    -keyout "$CERTS_DIR/key.pem" \
    -out "$CERTS_DIR/cert.pem" \
    -days 3650 -nodes -config \
    "$CONFIG"
