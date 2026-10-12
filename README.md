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

- **Tout est en direct** : un badge apparaît dans la plateforme en quelques secondes, sans recharger la page (voir « Fonctionnement en direct » ci-dessous).
- **Synchronisation complète** sur la même connexion (ces appareils n'acceptent souvent qu'un client à la fois) : au démarrage puis toutes les 30 minutes (`FULL_SYNC_INTERVAL`), elle relit les utilisateurs, tous les pointages et l'horloge. Rien n'est perdu pendant une coupure : les badges restent sur l'appareil et sont repris à la reconnexion.
- **Reconnexion automatique** en cas de coupure, après 10 s, 30 s, 60 s puis toutes les 5 minutes, sans bloquer les autres pointeuses. L'erreur est affichée sur la page Pointeuses.
- **Calcul global** des 7 derniers jours toutes les 5 minutes (`SYNC_INTERVAL`), dans un thread à part, en filet de sécurité.
- **Rien n'est effacé ni modifié sur les appareils**, à une exception près : chaque nuit à 03:00 (`CLOCK_SET_AT`, `off` pour désactiver), l'horloge de chaque pointeuse est remise à l'heure de la VM. L'écart avant et après est noté dans le journal du worker et dans le journal d'activité.
- **Importe les employés** depuis les pointeuses. Le nom affiché dans la plateforme se modifie ensuite par un admin, sans toucher aux appareils.
- **Calcule chaque journée** : arrivée, départ, temps de présence, retard, départ anticipé, heures supplémentaires.
- **Horaires saisis manuellement**, attribuables à tout le monde, à un groupe ou à un ou plusieurs agents, sur une période. Priorité : agent > groupe > horaire par défaut. Les horaires de nuit (qui passent minuit) sont gérés.
- **Sorties manquantes** : une journée sans badge de sortie est marquée « sortie manquante » et ne compte aucune heure tant qu'un admin ou les RH n'ont pas saisi l'heure de sortie. La correction est tracée (qui, quand, motif) et peut être annulée.
- **Accès aux salles** (NOC, IT Room) : historique des entrées, séparé du calcul de présence.
- **Contrôle des horloges** : décalage de chaque pointeuse affiché, alerte au-delà d'une minute, remise à l'heure chaque nuit.
- **Page Pointeuses** : état de la connexion, dernière synchronisation complète, « dernier badge reçu il y a … » et indicateur « en écoute » quand la connexion directe est active.
- **Page Agents** (`/agents`) : une carte par agent avec photo, titre, e-mail et téléphone (copiables d'un clic), département, groupe, **heure d'arrivée du jour** (vert : à l'heure, orange : en retard, rouge : pas arrivé) et **statut de travail** en haut à droite (au bureau, télétravail, mission, congé). Filtres par département (arbre à gauche), par état (à l'heure, en retard, pas arrivés…) et recherche instantanée. Mise à jour en direct.
- **Fiche agent depuis la carte** (Admin, RH) : nom, titre, e-mail, téléphone, département et **photo recadrée dans le navigateur** (carré 512 px, JPEG).
- **Statut de travail** : « au bureau » s'affiche automatiquement au premier badge d'entrée ; les RH peuvent saisir télétravail, mission, congé ou bureau jusqu'à une date, puis revenir à l'automatique. Un statut saisi l'emporte sur le badge jusqu'à sa date de fin.
- **Départements** (`/departements`) : arborescence (un département peut avoir des sous-départements), affectation des agents depuis la carte, la fiche employé ou en lot depuis la liste des employés.
- **Export CSV** (compatible Excel), **journal d'activité**, rôles ci-dessous.

### Rôles

| Rôle | Accès | Page d'accueil |
|---|---|---|
| Administrateur | Tout : comptes, pointeuses, journal, et tout ce que font les RH | Tableau de bord |
| Ressources humaines | Agents (fiche, photo, statut), départements, groupes, horaires, corrections ; tous les départements | Tableau de bord |
| Direction (CEO) | Toutes les pages de consultation, tous les départements, **aucune modification** | Agents |
| Manager de département | Page Agents, tableau de bord et présence **du jour** de son département et de ses sous-départements uniquement | Agents |
| Lecteur | Consultation et export, sans modification | Tableau de bord |

Le département d'un manager se choisit dans **Administration > Utilisateurs** (obligatoire pour ce rôle). Le filtrage est fait côté serveur sur chaque requête (pages, photos et `/api/direct`).

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

Les règles sont dans `app/Modules/Attendance/Services/DayCalculator.php` et testées par `php tests/DayCalculatorTest.php`. Le service de synchronisation est testé sans pointeuse par `python3 tests/test_sync.py` (faux module `zk` dans `tests/fakezk`, hors de l'image Docker).

### Fonctionnement en direct

Objectif : un badge visible dans la plateforme en moins de 10 secondes, sans recharger la page.

| Étape | Où | Délai |
|---|---|---|
| 1. Le badge est envoyé par la pointeuse sur la connexion ouverte (`live_capture`) et inséré dans `punches` ; `devices.last_event_at` est mis à jour | `worker/sync.py`, un thread par pointeuse | immédiat |
| 2. Badge de présence : l'agent est mis en file, puis sa journée (veille et jour même, pour les horaires de nuit) est recalculée. Un seul appel `php bin/compute.php --employee-device-id=…` au plus toutes les 5 s pour tous les agents en attente | thread « recalcul » du worker | 0 à 5 s |
| 3. Les pages ouvertes interrogent `GET /api/direct` toutes les 5 s (60 s si l'onglet est caché) | `public/assets/js/app.js` | 0 à 5 s |

Sur les pages **Tableau de bord**, **Présence** (journée d'aujourd'hui) et **Pointeuses** :

- indicateurs mis à jour avec une courte animation, nouveaux pointages ajoutés en haut des « Derniers pointages » avec un surlignage, retards du jour et pastilles des pointeuses à jour ;
- sur la page Présence, les lignes concernées (arrivée, départ, présence, retard, heures sup, statut) sont mises à jour ; si un nouvel agent devient concerné par les filtres, une barre « Nouveaux pointages : actualiser » s'affiche ;
- une notification discrète en bas à droite pour chaque badge (« … est entré · Entrée 5e étage »), désactivable avec l'interrupteur « Notifications » de l'en-tête (choix mémorisé dans le navigateur) ;
- l'indicateur « En direct · à jour il y a X s » de la barre du haut passe en gris « Hors ligne » si les appels échouent (nouvel essai après 10 s, 30 s puis 60 s). Si la session a expiré, retour à la page de connexion.

`/api/direct` (droit « consultation ») renvoie du JSON, jamais mis en cache : nouveaux pointages depuis `since_punch` (30 au plus), journées du jour modifiées depuis `since_day`, indicateurs (les mêmes que le tableau de bord, service `DashboardStats`), retards du jour et état des pointeuses. Il libère le verrou de session dès l'utilisateur vérifié, pour ne pas bloquer les autres onglets, et répond en quelques millisecondes (index sur `punches.id` et `attendance_days.computed_at`). `computed_at` ne change que si le résultat d'une journée change : le calcul global des 5 minutes ne renvoie donc pas toutes les lignes aux pages ouvertes.

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
| Mettre à jour | `git pull`, appliquer les migrations nouvelles (ci-dessous), puis `sudo docker compose up -d --build` |
| Réinitialiser un mot de passe admin | `sudo docker compose exec -it app php bin/create-admin.php <identifiant>` |
| Arrêter la plateforme (ELK n'est pas touché) | `sudo docker compose down` |

### Appliquer une migration sur une base existante

`database/schema.sql` n'est chargé qu'au tout premier démarrage (volume vide). Les changements de structure arrivent ensuite sous forme de fichiers `database/migrations/NNN_*.sql`, à appliquer une fois, dans l'ordre, après le `git pull` et **avant** de reconstruire les conteneurs (le nouveau code utilise les nouvelles colonnes) :

```bash
sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/001_live.sql
sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/002_agents.sql
```

Les migrations peuvent être relancées sans risque (`ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`). Une nouvelle installation n'en a pas besoin.

| Migration | Contenu |
|---|---|
| `001_live.sql` | Mode direct : `devices.last_event_at` (dernier badge reçu), `devices.live_since` (écoute en direct active), index sur `attendance_days.computed_at` |
| `002_agents.sql` | Page Agents : table `departments`, rôles `ceo` et `manager` + `users.department_id`, colonnes `employees.job_title`, `department_id`, `photo_updated_at`, `work_status*` |

Les photos des agents sont enregistrées dans `./data/storage/photos` (volume monté dans le conteneur `app`, hors du dossier public) : elles sont conservées lors des mises à jour et incluses dans la sauvegarde de `./data`.

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
    Platform/              socle commun : connexion, profil, utilisateurs, départements (Services/Departments), modules à venir
    Attendance/            module Présence
      Controllers/         une classe par écran ; AgentController (cartes, photos, statuts), LiveController (/api/direct)
      Services/            DayCalculator (règles), ScheduleResolver, AttendanceComputer, DashboardStats (indicateurs), AgentStatus (pastilles des cartes)
      Views/
  Views/                   gabarit commun, pages d'erreur
bin/                       compute.php (calcul), create-admin.php
config/config.php          configuration lue depuis .env
database/                  schema.sql, seed.sql (chargés au premier démarrage), migrations/
docker/                    images app (Apache + HTTPS) et worker
public/                    point d'entrée, CSS, JS, PWA (manifest, service worker, icônes), assets/vendor/fontawesome (Font Awesome Free 6.7.2, servi localement)
storage/                   fichiers déposés (photos) ; en production : volume ./data/storage
worker/                    sync.py (connexion permanente aux pointeuses, calculs), run.sh (lancement)
tests/                     règles de calcul (PHP) ; worker avec un faux module zk (tests/fakezk, hors image Docker)
```

Chaque futur module (Fleet, Leave) aura son dossier dans `app/Modules/` et réutilisera la table `employees`, les comptes et le gabarit.

### Sécurité

- HTTPS uniquement (port 8443), en-têtes HSTS et CSP stricte (aucun script ni style externe : Font Awesome est servi depuis `public/assets/vendor`).
- Photos hors du dossier public, servies par `/agents/{id}/photo` après contrôle du rôle et du département ; seuls des JPEG carrés de 128 à 1024 px et 1,5 Mo au plus sont acceptés.
- Mots de passe hachés, 12 caractères minimum, 5 échecs en 15 minutes bloquent la connexion.
- Jeton CSRF sur chaque formulaire, cookie de session `Secure`, `HttpOnly`, `SameSite=Strict`.
- Rôles vérifiés côté serveur sur chaque route, périmètre des managers appliqué à chaque requête, journal de toutes les modifications.
- Pointeuses en lecture seule, sauf la remise à l'heure nocturne de l'horloge ; seule la VM peut les joindre (pare-feu MikroTik).
