#!/bin/sh
# Boucle du service de synchronisation :
#   1. récupère pointages et employés des pointeuses (Python)
#   2. recalcule les journées des derniers jours (PHP, mêmes règles que l'appli)
INTERVAL="${SYNC_INTERVAL:-300}"
DAYS="${COMPUTE_DAYS:-7}"

echo "[worker] démarrage, intervalle ${INTERVAL}s, recalcul sur ${DAYS} jours"
while true; do
  python3 /var/www/app/worker/sync.py
  php /var/www/app/bin/compute.php --days="$DAYS"
  sleep "$INTERVAL"
done
