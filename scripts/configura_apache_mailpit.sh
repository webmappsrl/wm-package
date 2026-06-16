#!/bin/bash

# Script per configurare Apache come reverse proxy per Mailpit
# Basato su wm-package/docs/MAILPIT_SETUP.md
#
# Esempio shard dev:
#   export MAPHUB_PUBLIC_FQDN=<shard-fqdn>             # es. myproject.dev.maphub.it
#   export MAPHUB_APACHE_CONF_BASENAME=<conf-basename> # es. myproject.maphub.it
#   sudo bash wm-package/scripts/configura_apache_mailpit.sh

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

MAPHUB_PUBLIC_FQDN="${MAPHUB_PUBLIC_FQDN:-www.maphub.it}"
MAPHUB_APACHE_CONF_BASENAME="${MAPHUB_APACHE_CONF_BASENAME:-maphub.it}"
MAILPIT_PORT="${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}"
MAILPIT_WEBROOT="${MAILPIT_WEBROOT:-mailpit}"

APACHE_HTTP_CONF="/etc/apache2/sites-available/${MAPHUB_APACHE_CONF_BASENAME}.conf"
APACHE_HTTPS_CONF="/etc/apache2/sites-available/${MAPHUB_APACHE_CONF_BASENAME}-le-ssl.conf"

echo -e "${GREEN}=== Configurazione Apache per Mailpit ===${NC}\n"
echo -e "  FQDN: ${YELLOW}${MAPHUB_PUBLIC_FQDN}${NC}  |  path: ${YELLOW}/${MAILPIT_WEBROOT}/${NC}\n"

if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Errore: eseguire come root o con sudo${NC}"
    exit 1
fi

echo -e "${YELLOW}1. Verifica e abilita moduli Apache...${NC}"
MODULES=("proxy" "proxy_http" "proxy_wstunnel" "rewrite" "headers")

for module in "${MODULES[@]}"; do
    if ! apache2ctl -M | grep -q "^ ${module}_module"; then
        echo -e "  Abilitazione modulo: ${module}"
        a2enmod "$module" > /dev/null 2>&1
    else
        echo -e "  Modulo ${module} già abilitato"
    fi
done

for file in "$APACHE_HTTP_CONF" "$APACHE_HTTPS_CONF"; do
    if [ ! -f "$file" ]; then
        echo -e "${RED}Errore: file ${file} non trovato${NC}"
        exit 1
    fi
done

remove_mailpit_config() {
    local file="$1"
    sed -i '/# Redirect \/mailpit a \/mailpit\//,/<\/LocationMatch>.*mailpit/ d' "$file"
}

add_mailpit_https_config() {
    local file="$1"

    remove_mailpit_config "$file"

    sed -i '/<\/VirtualHost>/ i\
    # Redirect /mailpit a /mailpit/ per gestire entrambi i casi\
    RewriteRule ^/mailpit$ /mailpit/ [R=301,L]\
    \
    # WebSocket support per Mailpit (DEVE essere prima del proxy normale)\
    RewriteCond %{HTTP:Upgrade} =websocket [NC]\
    RewriteCond %{HTTP:Connection} =upgrade [NC]\
    RewriteRule ^/mailpit/(.*) ws://localhost:'"$MAILPIT_PORT"'/'"$MAILPIT_WEBROOT"'/$1 [P,L]\
    \
    # Proxy HTTP normale per Mailpit\
    ProxyPass /mailpit/ http://localhost:'"$MAILPIT_PORT"'/'"$MAILPIT_WEBROOT"'/\
    ProxyPassReverse /mailpit/ http://localhost:'"$MAILPIT_PORT"'/'"$MAILPIT_WEBROOT"'/\
    \
    <LocationMatch "^/mailpit">\
        Header unset X-Frame-Options\
        Header always set X-Frame-Options "SAMEORIGIN"\
    </LocationMatch>\
' "$file"
}

add_mailpit_http_config() {
    local file="$1"

    remove_mailpit_config "$file"

    if grep -q "RewriteRule.*https://" "$file"; then
        sed -i '/RewriteRule.*https:\/\/.*SERVER_NAME/ i\
    # Redirect /mailpit a /mailpit/ per gestire entrambi i casi\
    RewriteRule ^/mailpit$ /mailpit/ [R=301,L]\
    \
    # Proxy per Mailpit\
    ProxyPreserveHost On\
    ProxyPass /mailpit/ http://localhost:'"$MAILPIT_PORT"'/'"$MAILPIT_WEBROOT"'/\
    ProxyPassReverse /mailpit/ http://localhost:'"$MAILPIT_PORT"'/'"$MAILPIT_WEBROOT"'/\
' "$file"
    else
        sed -i '/<\/VirtualHost>/ i\
    # Redirect /mailpit a /mailpit/ per gestire entrambi i casi\
    RewriteRule ^/mailpit$ /mailpit/ [R=301,L]\
    \
    # Proxy per Mailpit\
    ProxyPreserveHost On\
    ProxyPass /mailpit/ http://localhost:'"$MAILPIT_PORT"'/'"$MAILPIT_WEBROOT"'/\
    ProxyPassReverse /mailpit/ http://localhost:'"$MAILPIT_PORT"'/'"$MAILPIT_WEBROOT"'/\
' "$file"
    fi
}

echo -e "\n${YELLOW}2. Configurazione Apache HTTP (porta 80)...${NC}"
add_mailpit_http_config "$APACHE_HTTP_CONF"
echo -e "  ${GREEN}✓${NC} Configurazione HTTP aggiunta"

echo -e "\n${YELLOW}3. Configurazione Apache HTTPS (porta 443)...${NC}"
add_mailpit_https_config "$APACHE_HTTPS_CONF"
echo -e "  ${GREEN}✓${NC} Configurazione HTTPS aggiunta"

echo -e "\n${YELLOW}4. Verifica sintassi Apache...${NC}"
apache2ctl configtest

echo -e "\n${YELLOW}5. Riavvio Apache...${NC}"
systemctl reload apache2

echo -e "\n${GREEN}=== Configurazione completata ===${NC}\n"
echo -e "  URL: ${YELLOW}https://${MAPHUB_PUBLIC_FQDN}/${MAILPIT_WEBROOT}/${NC}"
echo -e "  Verifica: curl -I https://${MAPHUB_PUBLIC_FQDN}/${MAILPIT_WEBROOT}/"
