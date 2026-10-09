# Liquid Présence — plateforme interne

Plateforme web interne avec une connexion commune et trois modules :

| Module | État |
|---|---|
| **Présence** (pointeuses ZKTeco) | Disponible |
| Parc automobile | Prévu (priorité 2) |
| Congés | Prévu (priorité 3) |

Stack : PHP 8.3 en MVC sans framework, MariaDB 11, phpMyAdmin, Docker Compose. Application installable sur téléphone (PWA). Interface en français.

Le contexte complet du projet (réseau, décisions, questions ouvertes) est dans [`docs/CADRAGE.md`](docs/CADRAGE.md).

---

## Ce que fait le module Présence

- **Récupère les pointages** des 6 pointeuses toutes les 5 minutes, en lecture seule : rien n'est modifié ni effacé sur les appareils.
- **Importe les employés** depuis les pointeuses. Le nom affiché dans la plateforme se modifie ensuite par un admin, sans toucher aux appareils.
- **Calcule chaque journée** : arrivée, départ, temps de présence, retard, départ anticipé, heures supplémentaires.
- **Horaires saisis manuellement**, attribuables à tout le monde, à un groupe ou à un ou plusieurs agents, sur une période. Priorité : agent > groupe > horaire par défaut. Les horaires de nuit (qui passent minuit) sont gérés.
- **Sorties manquantes** : une journée sans badge de sortie est marquée « sortie manquante » et ne compte aucune heure tant qu'un admin ou les RH n'ont pas saisi l'heure de sortie. La correction est tracée (qui, quand, motif) et peut être annulée.
- **Accès aux salles** (NOC, IT Room) : historique des entrées, séparé du calcul de présence.
- **Contrôle des horloges** : décalage de chaque pointeuse affiché, alerte au-delà d'une minute.
- **Export CSV** (compatible Excel), **journal d'activité**, rôles **Administrateur / RH / Lecteur**.

### Règles de calcul

| Élément | Règle |
|---|---|
| Arrivée | Premier pointage sur une pointeuse « entrée » du jour |
| Départ | Dernier pointage sur une pointeuse « sortie » après l'arrivée, ou heure corrigée |
| Retard | Si l'arrivée dépasse début + tolérance, retard compté depuis l'heure de début |
| Pause | Déduite du temps de présence les jours travaillés |
| Heures sup | Dépassement après la fin de l'horaire, compté s'il atteint le seuil de l'horaire |
| Jour non travaillé | « Repos », ou « hors horaire » si l'agent a pointé (tout compté en heures sup) |
| Pas de sortie | « En cours » le jour même, puis « sortie manquante » |

Les règles sont dans `app/Modules/Attendance/Services/DayCalculator.php` et testées par `php tests/DayCalculatorTest.php`.

---

## Installation sur la VM (10.169.153.144)

La plateforme cohabite avec ELK sans réutiliser aucun de ses ports :

| Port | Usage | Exposé |
|---|---|---|
| 8443/tcp | Application HTTPS | VM + NAT public 41.174.155.178:8443 |
| 8088/tcp | Validation du certificat Let's Encrypt | VM + NAT public 41.174.155.178:80 |
| 8090/tcp | phpMyAdmin | `127.0.0.1` uniquement (tunnel SSH) |
| — | MariaDB | Réseau Docker interne, aucun port publié |

### 1. Récupérer le code et configurer

```bash
cd ~
git clone https://github.com/Nyombi1997/liquid-attendance.git
cd liquid-attendance
cp .env.example .env
openssl rand -base64 24   # à lancer deux fois : un mot de passe pour DB_PASSWORD, un pour DB_ROOT_PASSWORD
nano .env
```

### 2. Démarrer

```bash
mkdir -p data
sudo docker compose up -d --build
sudo docker compose ps
sudo docker compose logs -f worker   # Ctrl+C pour quitter
```

Au premier passage, le service importe les 173 employés et tous les pointages, puis calcule l'historique depuis le 1er janvier 2026 (réglage `history_start_date` dans la table `settings`).

### 3. Créer le premier compte administrateur

```bash
sudo docker compose exec -it app php bin/create-admin.php beni "Beni Ekwema"
```

La plateforme est alors accessible sur **https://10.169.153.144:8443**. Le navigateur affiche un avertissement, car le certificat est provisoire et auto-signé tant que l'étape 5 n'est pas faite.

### 4. Publier sur Internet (MikroTik)

**Avant tout**, obtenir la réservation DHCP de la VM (MAC `bc:24:11:ba:ee:70` → 10.169.153.144). Ensuite :

```
/ip firewall nat add chain=dstnat dst-address=41.174.155.178 protocol=tcp dst-port=8443 action=dst-nat to-addresses=10.169.153.144 to-ports=8443 comment="Plateforme interne"
/ip firewall nat add chain=dstnat dst-address=41.174.155.178 protocol=tcp dst-port=80 action=dst-nat to-addresses=10.169.153.144 to-ports=8088 comment="Plateforme: validation Let's Encrypt"
/ip firewall nat add chain=srcnat dst-address=10.169.153.144 protocol=tcp dst-port=8088,8443 out-interface=vlan102-elk action=masquerade comment="Plateforme: retour via MikroTik"
```

Le masquerade est nécessaire, car la VM a sa propre passerelle (10.169.153.1), comme pour Kibana. Il permet aussi aux employés du bureau d'utiliser la même adresse publique.

### 5. Certificat HTTPS gratuit pour l'adresse IP (Let's Encrypt)

Let's Encrypt délivre des certificats pour une adresse IP avec le profil `shortlived` (validité d'environ 6 jours). Le conteneur `acme` les renouvelle automatiquement, et l'application recharge le nouveau certificat dans les 10 minutes.

```bash
sudo docker compose exec acme acme.sh --register-account -m ton-adresse@email --server letsencrypt
sudo docker compose exec acme acme.sh --issue --server letsencrypt -d 41.174.155.178 \
     --standalone --httpport 8088 --cert-profile shortlived --days 3
sudo docker compose exec acme acme.sh --install-cert -d 41.174.155.178 --ecc \
     --key-file /certs/privkey.pem --fullchain-file /certs/fullchain.pem
```

Vérifier ensuite **https://41.174.155.178:8443** : plus d'avertissement, et le téléphone propose « Installer l'application ».

> Ne pas retirer la règle NAT du port 80 : elle sert à chaque renouvellement.

---

## Utilisation courante

| Besoin | Commande |
|---|---|
| Accéder à phpMyAdmin | depuis ton poste : `ssh -L 8090:127.0.0.1:8090 ubuntu01@10.169.153.144`, puis http://localhost:8090 |
| Voir la synchronisation | `sudo docker compose logs -f worker` |
| Recalculer une période | page Horaires > « Recalculer une période », ou `sudo docker compose exec worker php bin/compute.php --from=2026-01-01 --to=2026-10-31` |
| Mettre à jour | `git pull && sudo docker compose up -d --build` |
| Réinitialiser un mot de passe admin | `sudo docker compose exec -it app php bin/create-admin.php <identifiant>` |
| Arrêter la plateforme (ELK n'est pas touché) | `sudo docker compose down` |

### Sauvegarde de la base

```bash
mkdir -p ~/backups
sudo docker compose exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' \
  | gzip > ~/backups/liquid_$(date +%F).sql.gz
```

À planifier chaque nuit avec `crontab -e`. Les données sont dans `./data` : ne jamais lancer `docker system prune --volumes`.

---

## Structure du code

```
app/
  Core/                    noyau MVC : routeur, base, vues, authentification, CSRF, journal
  Modules/
    Platform/              socle commun : connexion, profil, utilisateurs, modules à venir
    Attendance/            module Présence
      Controllers/         une classe par écran
      Services/            DayCalculator (règles), ScheduleResolver, AttendanceComputer
      Views/
  Views/                   gabarit commun, pages d'erreur
bin/                       compute.php (calcul), create-admin.php
config/config.php          configuration lue depuis .env
database/                  schema.sql, seed.sql (chargés au premier démarrage)
docker/                    images app (Apache + HTTPS) et worker
public/                    point d'entrée, CSS, JS, PWA (manifest, service worker, icônes)
worker/                    sync.py (lecture des pointeuses), run.sh (boucle)
tests/                     tests des règles de calcul
```

Chaque futur module (Fleet, Leave) aura son dossier dans `app/Modules/` et réutilisera la table `employees`, les comptes et le gabarit.

### Sécurité

- HTTPS uniquement (port 8443), en-têtes HSTS et CSP stricte (aucun script externe).
- Mots de passe hachés, 12 caractères minimum, 5 échecs en 15 minutes bloquent la connexion.
- Jeton CSRF sur chaque formulaire, cookie de session `Secure`, `HttpOnly`, `SameSite=Strict`.
- Rôles vérifiés côté serveur sur chaque route, journal de toutes les modifications.
- Pointeuses en lecture seule ; seule la VM peut les joindre (pare-feu MikroTik).
