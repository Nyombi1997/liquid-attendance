#!/bin/sh
# Démarrage du conteneur application.
# 1. Si aucun certificat n'existe encore, en crée un auto-signé provisoire
#    (remplacé par celui de Let's Encrypt dès qu'il est émis).
# 2. Surveille le certificat et recharge Apache quand acme.sh le renouvelle.
# 3. Prépare le dossier des fichiers déposés (photos), monté depuis ./data/storage.
set -e

mkdir -p /var/www/app/storage/photos
chown -R www-data:www-data /var/www/app/storage

CERT=/certs/fullchain.pem
KEY=/certs/privkey.pem
mkdir -p /certs

if [ ! -s "$CERT" ] || [ ! -s "$KEY" ]; then
  echo "[app] Aucun certificat : création d'un certificat auto-signé provisoire"
  openssl req -x509 -nodes -newkey rsa:2048 -days 30 \
    -keyout "$KEY" -out "$CERT" \
    -subj "/CN=${PUBLIC_IP:-localhost}" \
    -addext "subjectAltName=IP:${PUBLIC_IP:-127.0.0.1},IP:10.169.153.144" >/dev/null 2>&1
fi

(
  last=$(md5sum "$CERT" 2>/dev/null | cut -d' ' -f1)
  while true; do
    sleep 600
    now=$(md5sum "$CERT" 2>/dev/null | cut -d' ' -f1)
    if [ -n "$now" ] && [ "$now" != "$last" ]; then
      echo "[app] Nouveau certificat détecté, rechargement d'Apache"
      apachectl -k graceful || true
      last="$now"
    fi
  done
) &

exec docker-php-entrypoint "$@"
