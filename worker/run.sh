#!/bin/sh
# Service de synchronisation. Le script Python est le processus principal et
# gère lui-même ses boucles :
#   - une connexion permanente par pointeuse, badges reçus en temps réel ;
#   - synchronisation complète toutes les FULL_SYNC_INTERVAL secondes (1800) ;
#   - recalcul des journées (PHP, mêmes règles que l'appli) après les badges
#     (un appel au plus toutes les 5 s pour tous les agents en attente),
#     et calcul global des COMPUTE_DAYS derniers jours toutes les
#     SYNC_INTERVAL secondes (300), en filet de sécurité ;
#   - remise à l'heure des pointeuses chaque nuit à CLOCK_SET_AT (03:00).
# exec : Python reçoit directement le signal d'arrêt de Docker.
exec python3 /var/www/app/worker/sync.py
