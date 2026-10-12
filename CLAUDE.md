# CLAUDE.md — contexte du projet Liquid Présence

Lu automatiquement à chaque session. Source de vérité détaillée : `docs/CADRAGE.md` (infrastructure, décisions) et `README.md` (installation, exploitation).

# 1. Le projet en bref

Plateforme interne « Liquid Présence » : une connexion commune, trois modules partageant la même table `employees`.
- **Attendance (Présence)** : en production. Lit les pointeuses ZKTeco, calcule les journées.
- **Fleet (parc automobile)** : priorité 2, pas commencé.
- **Leave (congés)** : priorité 3, pas commencé.

Stack : PHP 8.3 MVC maison (sans framework ni Composer), MariaDB 11.4, phpMyAdmin, worker Python (pyzk) + PHP, Docker Compose, PWA. Dépôt : github.com/Nyombi1997/liquid-attendance.

# 2. Où tourne quoi

| Élément | Détail |
|---|---|
| Poste de dev | Ubuntu, ~/projects/liquid-attendance, VS Code + Claude Code. Ne peut PAS joindre les pointeuses. |
| VM de production | 10.169.153.144 (Ubuntu 26.04, utilisateur ubuntu01), projet dans ~/liquid-attendance. Docker nécessite sudo. Héberge aussi ELK (~/elk-mikrotik-deploy, données /data/elasticsearch). |
| Accès plateforme | https://10.169.153.144:8443 (interne) ; public prévu https://41.174.155.178:8443 |
| phpMyAdmin | 127.0.0.1:8090 sur la VM, via tunnel : ssh -L 8090:127.0.0.1:8090 ubuntu01@10.169.153.144 |

Conteneurs (projet compose « liquid-attendance », réseau liquid-attendance-net, séparé d'ELK) : la-app (Apache+PHP, HTTPS 8443), la-worker (synchronisation + calcul), la-db (MariaDB, aucun port publié), la-phpmyadmin, la-acme (certificat Let's Encrypt, port 8088).

Volumes (dans `./data`, jamais dans git) : `data/mariadb` (base), `data/certs` (certificat), `data/acme`, `data/storage` (photos des agents, monté sur `/var/www/app/storage` de la-app).

# 3. Réseau

Chemin VM → pointeuses :
VM 10.169.153.144 (passerelle par défaut 10.169.153.1) → route statique 10.0.4.0/24 via 10.169.153.244 (MikroTik, VLAN 102) → MikroTik vlan3602-zk 10.0.4.250 (masquerade) → ether2 trunk → switch 2 gi17 → gi24 → switch 1 gi1 → VLAN 3602 → pointeuses (passerelle des pointeuses : 10.0.4.1 = switch 1).

Routeur MikroTik :
- ether1 = Internet, 41.174.155.178/30, passerelle 41.174.155.177, relié au switch 1 fa21 (VLAN 1514). NE JAMAIS TOUCHER.
- ether2 = trunk dans bridge1 (vlan-filtering=yes), VLAN tagués 102, 4040, 3602. VLAN natif côté switch : 4040 (ancien réseau 10.125.40.0/24, 10.125.40.1).
- vlan102-elk = 10.169.153.244/24 ; vlan3602-zk = 10.0.4.250/24.
- Pare-feu : seule la VM (10.169.153.144) peut joindre vlan3602-zk ; masquerade VM → pointeuses.
- NAT existants : 5601 → VM (Kibana), 514/udp → VM (logs), 443/7080/7081 → 10.125.40.11 (autre serveur).
- La VM n'utilise pas le MikroTik comme passerelle : tout service publié vers la VM nécessite un masquerade de retour sur out-interface=vlan102-elk (comme Kibana).

Switchs Cisco Small Business (syntaxe SG/SF300, pas IOS classique : `show vlan tag X`, `show interfaces switchport gi24`) :
- SW1-Core5THFR (switch 1) : cœur, porte le VLAN 3602 « vlan_FingerPrint », passerelle 10.0.4.1.
- LCD-Sw5thLevel (switch 2) : gi17 trunk vers le MikroTik (natif 4040), gi24 trunk vers le switch 1, gi13 vers l'hôte Proxmox (VLAN 102), gi25 vers LCD-SW01-SNEL-FIH (ne pas toucher).

Pointeuses (VLAN 3602, TCP 4370, Comm Key 0, 173 utilisateurs chacune) :

| IP | Lieu | Rôle | Usage | Modèle |
|---|---|---|---|---|
| 10.0.4.18 | 5e étage | entrée | présence | SF400/ID |
| 10.0.4.20 | 5e étage | sortie | présence | SF400/ID |
| 10.0.4.7 | 4e étage | entrée | présence | F18/ID |
| 10.0.4.15 | 4e étage | sortie | présence | SF400/ID |
| 10.0.4.5 | NOC | entrée seule | accès salle | F16, sans écran (sortie par bouton) |
| 10.0.4.4 | IT Room | entrée seule | accès salle | F16, sans écran (sortie par bouton) |

Particularités : les F16 ouvrent le port 4370 mais pyzk dépasse le délai (en cours d'analyse) ; les pointeuses d'étage avaient 77 s d'avance (VM synchronisée NTP) ; environ 1 sortie badgée pour 10 entrées.

Ports de la VM :

| Port | Usage |
|---|---|
| 22 | SSH |
| 514/udp, 5601, 9200, 9300, 5044, 5000, 9600 | ELK |
| 80 | réservé au php-apache d'ELK |
| 8443 | plateforme (HTTPS) |
| 8088 | validation Let's Encrypt (NAT public 80 → 8088) |
| 8090 | phpMyAdmin (127.0.0.1 seulement) |

Ne jamais utiliser 7080. Tout nouveau port doit être vérifié libre (sudo ss -tulpn).

# 4. Architecture du code

```
app/bootstrap.php            autoload App\ → app/, config, helpers
app/Core/                    Router, Database (PDO, requêtes préparées), View, Auth (rôles, périmètre), Csrf, Audit, Config, Controller, helpers.php
app/Modules/Platform/        connexion, profil, utilisateurs, départements, pages « à venir » (Fleet, Leave)
  Services/Departments.php   arbre des départements, périmètre SQL des managers
app/Modules/Attendance/      module Présence : Controllers/, Services/, Views/
app/Views/                   gabarit layout.php (menu selon le rôle), auth.php, errors/
public/index.php             contrôleur frontal : TOUTES les routes
public/assets/               app.css, app.js (sans dépendance), icons.svg, vendor/fontawesome (Free 6.7.2, local)
public/manifest.webmanifest, sw.js, offline.html   PWA (cache des fichiers versionnés ?v=, jamais /api/)
bin/compute.php              calcul des journées (--days, --from/--to, --employee-device-id)
bin/create-admin.php         création / réinitialisation d'un compte admin
worker/sync.py, run.sh       service de synchronisation (conteneur la-worker)
database/schema.sql, seed.sql, migrations/NNN_*.sql
tests/                       DayCalculatorTest.php ; test_sync.py + faux module zk (tests/fakezk)
```

**Rôles et droits** (`Auth::ROLES`, droit = 3e argument de la route ; POST = `manage` par défaut) :

| Droit | Rôles | Exemples de routes |
|---|---|---|
| `null` | public | `/connexion` |
| `board` | admin, rh, ceo, manager, lecteur | `/`, `/agents`, `/agents/{id}/photo`, `/api/direct`, `/api/suggestions`, `/profil` |
| `presence` | admin, ceo, manager, lecteur | `/presence` |
| `staff` | admin, rh, ceo, lecteur | `/employes`, `/employes/{id}` (consultation) |
| `view` | admin, ceo, lecteur | `/groupes`, `/corrections`, `/acces`, `/horaires`, `/pointeuses`, `/presence/export`, `/flotte`, `/conges` |
| `status` | admin, rh | POST `/agents/{id}/statut` (disponibilité) |
| `manage` | admin | POST employés, groupes, horaires, corrections, `/agents/{id}`, `/departements` |
| `admin` | admin | `/utilisateurs`, `/journal`, POST `/pointeuses` |

- `ceo` : tout voir, ne rien modifier. `manager` : uniquement son département (`users.department_id`) et ses sous-départements, présence du jour seulement. `rh` : tableau de bord, Agents (disponibilité seulement), Employés en lecture.
- Identifiants insensibles à la casse : `Auth::normalizeUsername()` (minuscules) à l'enregistrement et à la connexion.
- `app.js` ajoute un bouton « œil » à tout champ mot de passe ; `data-suggest="go|submit|filter"` sur un champ de recherche affiche les propositions (`/api/suggestions`).
- Périmètre : `Departments::sql(Auth::departmentScope())` dans toute requête accessible à un manager (pages, photos, `/api/direct`).
- Accueil : `Auth::home()` → `/agents` (ceo, manager) ou `/`.

**Tables** :

| Table | Rôle |
|---|---|
| `users`, `login_attempts`, `audit_log` | comptes (rôle, département du manager), anti-force brute, journal |
| `departments` | arborescence (parent_id), commune à tous les modules |
| `employee_groups` | groupes d'agents (horaires) |
| `employees` | liste commune : n° pointeuse, nom affiché, titre, contacts, groupe, département, photo, statut de travail (`work_status*`) |
| `offices`, `devices` | bureaux, pointeuses (entrée/sortie, présence/accès, état de synchro, horloge, `live_since`) |
| `punches` | pointages bruts, copie fidèle, jamais modifiés |
| `schedules`, `schedule_assignments` | horaires et attributions (agent > groupe > défaut, sur période) |
| `attendance_days` | une ligne par agent et par jour (arrivée, départ, retard, heures sup, statut, correction de sortie) |
| `settings` | réglages (`history_start_date`) |

**Services (Attendance)** : `DayCalculator` (règles d'une journée), `ScheduleResolver`, `AttendanceComputer` (calcul et enregistrement), `DashboardStats` (indicateurs, partagés avec `/api/direct`), `AgentStatus` (pastilles de la page Agents).

**Règles de calcul (DayCalculator)** : arrivée = premier badge « entrée » ; départ = dernier badge « sortie » après l'arrivée, ou heure corrigée ; retard compté depuis le début si arrivée > début + tolérance ; pause déduite ; heures sup après la fin si ≥ seuil ; jour non travaillé = « repos » (ou « hors horaire » si badge) ; pas de sortie = « en cours » le jour même puis « sortie manquante » (0 heure tant que non corrigée).

**Worker** (`worker/sync.py`) : un thread par pointeuse active, connexion permanente ; synchro complète au démarrage puis toutes les `FULL_SYNC_INTERVAL` s (1800) ; badges en direct (`live_capture`) insérés dans `punches` ; recalcul de l'agent via `php bin/compute.php --employee-device-id=…` (au plus toutes les 5 s) ; calcul global des `COMPUTE_DAYS` (7) derniers jours toutes les `SYNC_INTERVAL` s (300) ; reconnexion 10 s → 30 s → 60 s → 5 min ; remise à l'heure des horloges à `CLOCK_SET_AT` (03:00), seule écriture sur les appareils.

**Mode direct** : les pages `data-live` (tableau de bord, présence du jour, agents, pointeuses) appellent `GET /api/direct` toutes les 5 s (60 s onglet caché).

**Ajouter un module (Fleet, Leave)** :
1. Dossier `app/Modules/<Module>/{Controllers,Services,Views}` (namespace `App\Modules\<Module>\…`, vues `'<Module>:nom'`).
2. Routes dans `public/index.php` avec leur droit ; remplacer les pages `/flotte` ou `/conges` de `ModuleController`.
3. Réutiliser `employees` (et `departments`) par clé étrangère, les comptes et rôles existants, le gabarit `layout.php` (ajouter les entrées du menu).
4. Tables : migration `database/migrations/NNN_*.sql` + `schema.sql`.

# 5. Conventions

- Interface, messages, commentaires et commits en français.
- Couleurs #273c88 (principale) et #c8187d (accent) ; coins arrondis de 5 px maximum pour boutons et champs ; style des maquettes : menu latéral coloré, cartes blanches, tableaux aérés.
- CSP stricte : aucun script inline, aucun attribut style= ou onclick=. Tout le JS dans public/assets/js/app.js, tout le CSS dans public/assets/css/app.css.
- Requêtes SQL préparées uniquement ; échappement avec e() dans les vues ; jeton CSRF sur chaque POST ; droit vérifié dans la route.
- Toute modification de données importante passe par Audit::log().
- Modification de schéma : nouveau fichier database/migrations/NNN_nom.sql + mise à jour de schema.sql + commande d'application dans le README.
- Icônes : Font Awesome Free servi localement, helper `fa('fa-solid fa-…')` ; sprite `icon('nom')` pour le menu. Aucun CDN.

# 6. Règles de sécurité et d'exploitation (impératives)

- Pointeuses en LECTURE SEULE : ne jamais effacer ni modifier pointages ou utilisateurs sur les appareils.
- Ne jamais toucher ether1 ni fa21 (coupure Internet du site).
- Sur les switchs : toujours `switchport trunk allowed vlan add …` (sans add, la liste est remplacée et le VLAN 102 coupé, donc ELK et le serveur).
- MikroTik : toujours proposer le Safe Mode (Ctrl+X) avant une modification.
- VM : jamais `docker system prune --volumes`, jamais `docker compose down -v` (données dans ./data et /data/elasticsearch).
- Ne jamais arrêter ni modifier les conteneurs ELK.
- Toute commande réseau ou serveur proposée doit être expliquée, réversible si possible, et accompagnée de la commande de vérification.

# 7. Workflow

- Développement sur le poste → commit → je fais moi-même git push.
- Déploiement sur la VM : cd ~/liquid-attendance && git pull && (migration éventuelle) && sudo docker compose up -d --build app worker && sudo docker compose logs -f worker
- Le .env n'est pas dans git : il se modifie uniquement sur la VM.
- Tests locaux : php tests/DayCalculatorTest.php, php -l sur les fichiers modifiés, python3 -m py_compile worker/sync.py. Les pointeuses ne sont pas joignables depuis le poste : simule-les avec un faux module zk.
- À la fin de chaque tâche, donne-moi les commandes exactes à lancer sur la VM, dans l'ordre.

Commande de migration (sur la VM, depuis ~/liquid-attendance) :
`sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/NNN_nom.sql`
Migrations existantes : `001_live.sql` (mode direct), `002_agents.sql` (départements, rôles ceo/manager, fiche et statut des agents), `003_identifiants.sql` (identifiants en minuscules, insensibles à la casse). Toutes rejouables sans risque.

# 8. État et questions ouvertes

D'après docs/CADRAGE.md (état au 9 oct. 2026) :
- Fait : accès VM → pointeuses (VLAN 3602), base du projet et module Présence, temps réel et remise à l'heure nocturne, mode direct (`/api/direct`, à valider sur la VM avec les vraies pointeuses).
- À faire : déploiement sur la VM, réservation DHCP de la VM, NAT 8443/80 + certificat Let's Encrypt, sécurisation de Kibana.
- Ajouté depuis : page Agents, départements, rôles CEO et Manager (migration `002_agents.sql`).
- 12 oct. 2026 : RH limités (tableau de bord, Agents en disponibilité seule, Employés en lecture), propositions dans les recherches, bouton « œil » des mots de passe, identifiants insensibles à la casse (migration `003_identifiants.sql`).
- Questions ouvertes : horaires réels et groupes à saisir ; historique antérieur à 2026 (`history_start_date`) ; logo officiel à fournir.

**Règle** : quand une tâche change l'infrastructure, les ports, le schéma ou l'état d'avancement, mettre à jour CLAUDE.md et docs/CADRAGE.md dans le même commit.

Règles de travail détaillées (périmètre des managers, page Agents, icônes, procédure de test) : @AGENTS.md
