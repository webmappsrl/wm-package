#!/bin/bash

# Script per configurare Apache come reverse proxy per Kibana
# Basato su wm-package/docs/KIBANA_SETUP.md

set -e

# Colori per output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# File di configurazione Apache
APACHE_HTTP_CONF="/etc/apache2/sites-available/camminiditalia.maphub.it.conf"
APACHE_HTTPS_CONF="/etc/apache2/sites-available/camminiditalia.maphub.it-le-ssl.conf"
KIBANA_PORT="${DOCKER_KIBANA_PORT:-5601}"

echo -e "${GREEN}=== Configurazione Apache per Kibana ===${NC}\n"

# Verifica che lo script sia eseguito come root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Errore: Questo script deve essere eseguito come root o con sudo${NC}"
    exit 1
fi

# 1. Verifica e abilita moduli Apache necessari
echo -e "${YELLOW}1. Verifica e abilita moduli Apache...${NC}"
MODULES=("proxy" "proxy_http" "rewrite" "headers")

for module in "${MODULES[@]}"; do
    if ! apache2ctl -M | grep -q "^ ${module}_module"; then
        echo -e "  Abilitazione modulo: ${module}"
        a2enmod "$module" > /dev/null 2>&1 || {
            echo -e "${RED}Errore: Impossibile abilitare il modulo ${module}${NC}"
            exit 1
        }
    else
        echo -e "  Modulo ${module} già abilitato"
    fi
done

# 2. Verifica che i file di configurazione Apache esistano
echo -e "\n${YELLOW}2. Verifica file di configurazione Apache...${NC}"
if [ ! -f "$APACHE_HTTP_CONF" ]; then
    echo -e "${RED}Errore: File ${APACHE_HTTP_CONF} non trovato${NC}"
    exit 1
fi

if [ ! -f "$APACHE_HTTPS_CONF" ]; then
    echo -e "${RED}Errore: File ${APACHE_HTTPS_CONF} non trovato${NC}"
    exit 1
fi

echo -e "  File di configurazione trovati"

# 3. Funzione per aggiungere configurazione Kibana HTTP (porta 80)
add_kibana_http_config() {
    local file="$1"
    
    # Verifica se la configurazione Kibana esiste già
    if grep -q "# Proxy per Kibana" "$file"; then
        echo -e "  Configurazione Kibana HTTP già presente, aggiornamento..."
        # Rimuove la vecchia configurazione Kibana
        sed -i '/# Proxy per Kibana/,/RewriteRule.*kibana/ d' "$file"
    fi
    
    # Trova la posizione prima del redirect HTTPS
    if grep -q "RewriteRule.*https://" "$file"; then
        # Inserisce prima del redirect HTTPS
        sed -i '/RewriteRule.*https:\/\/.*SERVER_NAME/ i\
    # Proxy per Kibana\
    ProxyPreserveHost On\
    ProxyPass /kibana/ http://localhost:'"$KIBANA_PORT"'/\
    ProxyPassReverse /kibana/ http://localhost:'"$KIBANA_PORT"'/\
    \
    # Redirect /kibana a /kibana/ per gestire entrambi i casi\
    RewriteEngine On\
    RewriteRule ^/kibana$ /kibana/ [R=301,L]\
' "$file"
    else
        # Aggiunge alla fine del VirtualHost
        sed -i '/<\/VirtualHost>/ i\
    # Proxy per Kibana\
    ProxyPreserveHost On\
    ProxyPass /kibana/ http://localhost:'"$KIBANA_PORT"'/\
    ProxyPassReverse /kibana/ http://localhost:'"$KIBANA_PORT"'/\
    \
    # Redirect /kibana a /kibana/ per gestire entrambi i casi\
    RewriteEngine On\
    RewriteRule ^/kibana$ /kibana/ [R=301,L]\
' "$file"
    fi
}

# 4. Funzione per aggiungere configurazione Kibana HTTPS (porta 443)
add_kibana_https_config() {
    local file="$1"
    
    # Verifica se la configurazione Kibana esiste già
    if grep -q "# Redirect /kibana a /kibana/" "$file" && grep -q "# Proxy per Kibana" "$file"; then
        echo -e "  Configurazione Kibana HTTPS già presente, aggiornamento..."
        # Rimuove la vecchia configurazione Kibana
        # Trova l'inizio e la fine della sezione Kibana
        sed -i '/# Redirect \/kibana a \/kibana\//,/<\/LocationMatch>.*kibana/ d' "$file"
        # Rimuove anche eventuali righe vuote multiple
        sed -i '/^$/N;/^\n$/d' "$file"
    fi
    
    # Trova la posizione prima della configurazione SSL (se presente)
    # Cerca il tag </VirtualHost> all'interno di <IfModule mod_ssl.c>
    if grep -q "</VirtualHost>" "$file"; then
        # Inserisce prima di </VirtualHost> ma dopo altre configurazioni
        # Trova l'ultimo </VirtualHost> prima di </IfModule>
        sed -i '/<\/VirtualHost>/ i\
    # Redirect /kibana a /kibana/ per gestire entrambi i casi\
    RewriteEngine On\
    RewriteRule ^/kibana$ /kibana/ [R=301,L]\
    \
    # Proxy per Kibana\
    ProxyPreserveHost On\
    ProxyPass /kibana/ http://localhost:'"$KIBANA_PORT"'/\
    ProxyPassReverse /kibana/ http://localhost:'"$KIBANA_PORT"'/\
    \
    # Configurazioni specifiche per Kibana\
    <LocationMatch "^/kibana">\
        # Rimuovi header che potrebbero interferire\
        Header unset X-Frame-Options\
        Header unset X-Content-Type-Options\
        \
        # Permetti iframe embedding\
        Header always set X-Frame-Options "SAMEORIGIN"\
        \
        # CORS headers per Kibana\
        Header always set Access-Control-Allow-Origin "*"\
        Header always set Access-Control-Allow-Methods "*"\
        Header always set Access-Control-Allow-Headers "*"\
        Header always set Access-Control-Allow-Credentials "true"\
    </LocationMatch>\
' "$file"
    else
        echo -e "${RED}Errore: Impossibile trovare la fine del VirtualHost in ${file}${NC}"
        exit 1
    fi
}

# 5. Aggiungi configurazione HTTP
echo -e "\n${YELLOW}3. Configurazione Apache HTTP (porta 80)...${NC}"
add_kibana_http_config "$APACHE_HTTP_CONF"
echo -e "  ${GREEN}✓${NC} Configurazione HTTP aggiunta"

# 6. Aggiungi configurazione HTTPS
echo -e "\n${YELLOW}4. Configurazione Apache HTTPS (porta 443)...${NC}"
add_kibana_https_config "$APACHE_HTTPS_CONF"
echo -e "  ${GREEN}✓${NC} Configurazione HTTPS aggiunta"

# 7. Verifica sintassi Apache
echo -e "\n${YELLOW}5. Verifica sintassi Apache...${NC}"
if apache2ctl configtest > /dev/null 2>&1; then
    echo -e "  ${GREEN}✓${NC} Sintassi corretta"
else
    echo -e "${RED}Errore: Sintassi Apache non valida${NC}"
    echo "Esegui: sudo apache2ctl configtest per vedere i dettagli"
    exit 1
fi

# 8. Riavvia Apache
echo -e "\n${YELLOW}6. Riavvio Apache...${NC}"
systemctl reload apache2 > /dev/null 2>&1 || {
    echo -e "${YELLOW}  Tentativo con restart...${NC}"
    systemctl restart apache2 > /dev/null 2>&1 || {
        echo -e "${RED}Errore: Impossibile riavviare Apache${NC}"
        exit 1
    }
}
echo -e "  ${GREEN}✓${NC} Apache riavviato"

# 9. Verifica che Kibana sia in esecuzione
echo -e "\n${YELLOW}7. Verifica container Kibana...${NC}"
if docker ps | grep -q kibana; then
    echo -e "  ${GREEN}✓${NC} Container Kibana in esecuzione"
else
    echo -e "${YELLOW}  ⚠${NC} Container Kibana non trovato. Assicurati che sia in esecuzione."
fi

# 10. Test di connessione
echo -e "\n${YELLOW}8. Test connessione Kibana...${NC}"
if curl -s -o /dev/null -w "%{http_code}" "http://localhost:${KIBANA_PORT}/" | grep -q "200\|301\|302"; then
    echo -e "  ${GREEN}✓${NC} Kibana risponde su localhost:${KIBANA_PORT}"
else
    echo -e "${YELLOW}  ⚠${NC} Kibana non risponde su localhost:${KIBANA_PORT}"
fi

echo -e "\n${GREEN}=== Configurazione completata! ===${NC}\n"
echo -e "Kibana dovrebbe essere accessibile tramite:"
echo -e "  - HTTP:  http://camminiditalia.dev.maphub.it/kibana/"
echo -e "  - HTTPS: https://camminiditalia.dev.maphub.it/kibana/"
echo -e "\n${YELLOW}Credenziali di accesso:${NC}"
echo -e "  Username: ${GREEN}elastic${NC}"
echo -e "  Password: valore di ${GREEN}ELASTICSEARCH_PASSWORD${NC} nel file .env"
echo -e "\nPer verificare:"
echo -e "  curl -I https://camminiditalia.dev.maphub.it/kibana/"

