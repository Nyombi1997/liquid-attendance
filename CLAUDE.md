# CLAUDE.md — contexte du projet Liquid Présence

Lu automatiquement à chaque session. Source de vérité détaillée : `docs/CADRAGE.md` (infrastructure, décisions) et `README.md` (installation, exploitation).

# 1. Le projet en bref

Plateforme interne « Liquid Présence » : une connexion commune, trois modules partageant la même table `employees`.
- **Attendance (Présence)** : en production. Lit les pointeuses ZKTeco, calcule les journées.
- **Fleet (parc automobile)** : module 1 « Demande et octroi de véhicule » livré (20 oct. 2026) ; modules 2 à 4 (GPS, alertes de mouvement, carburant) à venir. Cahier des charges : `docs/Cahier_des_charges_Fleet.pdf`.
- **Leave (congés)** : priorité 3, pas commencé.

Stack : PHP 8.3 MVC maison (sans framework ni Composer), MariaDB 11.4, phpMyAdmin, worker Python (pyzk) + PHP, Docker Compose, PWA. Dépôt : github.com/Nyombi1997/liquid-attendance.

# 2. Où tourne quoi

| Élément | Détail |
|---|---|
| Poste de dev | Ubuntu, ~/projects/liquid-attendance, VS Code + Claude Code. Ne peut PAS joindre les pointeuses. |
| VM de production | 10.169.153.144 (ens18, VLAN 102) + 10.0.4.249 (ens19, VLAN 3602 des pointeuses) ; Ubuntu 26.04, utilisateur ubuntu01, projet dans ~/liquid-attendance. Docker nécessite sudo. Héberge aussi ELK (~/elk-mikrotik-deploy, données /data/elasticsearch). |
| Accès plateforme | https://10.169.153.144:8443 (interne) ; publication Internet par la DMZ (VLAN 3604) : **en attente** |
| phpMyAdmin | 127.0.0.1:8090 sur la VM, via tunnel : ssh -L 8090:127.0.0.1:8090 ubuntu01@10.169.153.144 |

Conteneurs (projet compose « liquid-attendance », réseau liquid-attendance-net, séparé d'ELK) : la-app (Apache+PHP, HTTPS 8443), la-worker (synchronisation + calcul), la-db (MariaDB, aucun port publié), la-phpmyadmin, la-acme (certificat Let's Encrypt, port 8088).

Volumes (dans `./data`, jamais dans git) : `data/mariadb` (base), `data/backups` (sauvegarde nocturne de la base, 14 jours, écrite par la-worker), `data/certs` (certificat), `data/acme`, `data/storage` (photos des agents `photos/`, fichiers du parc `fleet/ordres`, `fleet/documents`, `fleet/missions` ; monté sur `/var/www/app/storage` de la-app).

# 3. Réseau

Depuis le 14 oct. 2026, l'équipe sécurité a retiré les VLAN 102 et 4040 du port gi17 (trunk switch 2 → MikroTik) : le MikroTik n'est plus joignable depuis le VLAN 102 et ne sert plus au chemin VM → pointeuses.

Chemin VM → pointeuses (direct, sans routeur ni NAT) :
VM ens19 = 10.0.4.249/24, **sans passerelle** ← Proxmox net1 (pont DMZ_SWITCH sur nic1, tag 3602) → câble sur gi27 du switch 2 → gi24 → switch 1 gi1 → VLAN 3602 → pointeuses (même sous-réseau ; passerelle des pointeuses : 10.0.4.1 = switch 1). L'ancienne route 10.0.4.0/24 via 10.169.153.244 est supprimée : ne jamais la remettre.
Le reste du trafic de la VM sort par ens18 (10.169.153.144, VLAN 102, passerelle 10.169.153.1).
Vérification : `ip -br addr show ens19` ; `ip route get 10.0.4.18` (doit répondre `dev ens19 src 10.0.4.249`) ; `nc -zv -w 3 10.0.4.18 4370`.

Publication Internet (Kibana 5601, plateforme 8443 + validation Let's Encrypt, avis customers 7080, syslog 514) : passera par une DMZ, **VLAN 3604, sous-réseau encore à fournir par la sécurité : EN ATTENTE**. Aucune règle NAT ni masquerade à préparer d'ici là.

Routeur MikroTik (plus joignable depuis le VLAN 102) :
- ether1 = Internet, 41.174.155.178/30, passerelle 41.174.155.177, relié au switch 1 fa21 (VLAN 1514). NE JAMAIS TOUCHER.
- ether2 = trunk vers gi17 du switch 2 ; depuis le 14 oct., gi17 ne porte plus les VLAN 102 et 4040. Les anciennes interfaces vlan102-elk (10.169.153.244) et vlan3602-zk (10.0.4.250), le masquerade VM → pointeuses et les NAT vers la VM (5601, 514/udp) n'ont plus d'usage pour la VM ; leur nettoyage relève de la sécurité.

Switchs Cisco Small Business (syntaxe SG/SF300, pas IOS classique : `show vlan tag X`, `show interfaces switchport gi24`) :
- SW1-Core5THFR (switch 1) : cœur, porte le VLAN 3602 « vlan_FingerPrint », passerelle 10.0.4.1.
- LCD-Sw5thLevel (switch 2) : gi17 trunk vers le MikroTik (VLAN 102 et 4040 retirés le 14 oct.), gi24 trunk vers le switch 1, gi13 vers l'hôte Proxmox (VLAN 102), gi27 vers nic1 de l'hôte Proxmox (pont DMZ_SWITCH, VLAN 3602 tagué), gi25 vers LCD-SW01-SNEL-FIH (ne pas toucher).

Pointeuses (VLAN 3602, TCP 4370, Comm Key 0, 173 utilisateurs chacune) :

| IP | Lieu | Rôle | Usage | Modèle |
|---|---|---|---|---|
| 10.0.4.18 | 5e étage | entrée | présence | SF400/ID |
| 10.0.4.20 | 5e étage | sortie | présence | SF400/ID |
| 10.0.4.7 | 4e étage | entrée | présence | F18/ID |
| 10.0.4.15 | 4e étage | sortie | présence | SF400/ID |
| 10.0.4.5 | NOC | entrée seule | accès salle | F16, sans écran (sortie par bouton) |
| 10.0.4.4 | IT Room | entrée seule | accès salle | F16, sans écran (sortie par bouton) |

Particularités : les F16 ouvrent le port 4370 mais pyzk dépasse le délai (en cours d'analyse ; le worker leur donne un délai de 60 s et un journal détaillé : `SLOW_DEVICES`) ; les pointeuses d'étage avaient 77 s d'avance (VM synchronisée NTP) ; environ 1 sortie badgée pour 10 entrées.

Ports de la VM :

| Port | Usage |
|---|---|
| 22 | SSH |
| 514/udp, 5601, 9200, 9300, 5044, 5000, 9600 | ELK |
| 80 | réservé au php-apache d'ELK |
| 8443 | plateforme (HTTPS) |
| 8088 | validation Let's Encrypt (public 80 → 8088 par la DMZ, en attente) |
| 8090 | phpMyAdmin (127.0.0.1 seulement) |

Ne jamais utiliser 7080 (avis customers). Tout nouveau port doit être vérifié libre (sudo ss -tulpn).

# 4. Architecture du code

```
app/bootstrap.php            autoload App\ → app/, config, helpers
app/Core/                    Router, Database (PDO, requêtes préparées), View, Auth (rôles, périmètre), Csrf, Audit, Config, Controller, Xlsx (classeurs Excel), Mailer (SMTP sans dépendance), helpers.php
app/Modules/Platform/        connexion, profil, utilisateurs, départements, état du service, page « à venir » (Leave)
  Services/Departments.php   arbre des départements (direction/service, N+1), périmètre SQL des managers
  Services/Notifications.php notifications des comptes (cloche, file des e-mails), communes aux modules
app/Modules/Attendance/      module Présence : Controllers/, Services/, Views/
app/Modules/Fleet/           module Parc automobile (module 1) : Controllers/, Services/, Views/
app/Views/                   gabarit layout.php (menu selon le rôle), auth.php, errors/
public/index.php             contrôleur frontal : TOUTES les routes
public/assets/               app.css, app.js (sans dépendance), icons.svg, vendor/fontawesome (Free 6.7.2, local)
public/manifest.webmanifest, sw.js, offline.html   PWA (cache des fichiers versionnés ?v=, jamais /api/)
bin/compute.php              calcul des journées (--days, --from/--to, --employee-device-id)
bin/fleet-tasks.php          parc automobile : rappels avant départ, alertes d'expiration, envoi des e-mails (lancé par le worker)
bin/create-admin.php         création / réinitialisation d'un compte admin
worker/sync.py, run.sh       service de synchronisation (conteneur la-worker)
database/schema.sql, seed.sql, migrations/NNN_*.sql, seed_fleet_demo.sql (démonstration, JAMAIS en production)
tests/                       DayCalculatorTest.php, CalendarTest.php, FleetWorkflowTest.php, FleetImportTest.php ; test_sync.py + faux module zk (tests/fakezk)
```

**Rôles et droits** (`Auth::ROLES`, droit = 3e argument de la route ; POST = `manage` par défaut) :

| Droit | Rôles | Exemples de routes |
|---|---|---|
| `null` | public | `/connexion` |
| `board` | admin, rh, ceo, manager, lecteur | `/`, `/agents`, `/agents/{id}/photo`, `/api/direct`, `/api/notifications`, `/api/suggestions`, `/pointages`, `/profil` |
| `presence` | admin, ceo, manager, lecteur | `/presence` |
| `staff` | admin, rh, ceo, manager, lecteur | `/employes`, `/employes/{id}` (consultation ; manager : son département) |
| `calendar` | admin, rh, manager | `/calendrier` |
| `view` | admin, ceo, lecteur | `/groupes`, `/corrections`, `/acces`, `/horaires`, `/pointeuses`, `/presence/export`, `/flotte`, `/conges` |
| `status` | admin, rh | POST `/agents/{id}/statut` (disponibilité) |
| `export` | admin, rh | `/agents/export` (rapport Excel de la page Agents) |
| `manage` | admin | POST employés, groupes, horaires, corrections, `/agents/{id}`, `/departements` |
| `admin` | admin | `/utilisateurs`, `/journal`, `/etat-du-service`, POST `/pointeuses`, `/flotte/parametres` |
| `account` | tous, `employe` compris | `/profil`, `/deconnexion`, `/api/notifications` (+ POST `/api/notifications/lu`) |

**Parc automobile** : droits donnés par le rôle de la plateforme **ou** par `users.fleet_role` (noté `fleet:…` dans `Auth::ABILITIES`) :

| Droit | Qui | Exemples de routes |
|---|---|---|
| `fleet` | tous les comptes | `/flotte` (redirige selon le rôle), `/flotte/demandes…`, `/flotte/validations`, `/flotte/missions…` (droits fins dans le contrôleur : `Fleet::access()`) |
| `fleet_validate` | fleet:validateur, fleet:direction, tout responsable (N+1) d'un département | entrée de menu « À valider » |
| `fleet_drive` | fleet:chauffeur | entrée de menu « Mes missions » |
| `fleet_board` | admin, ceo, fleet:gestionnaire, fleet:direction | tableau de bord `/flotte`, `/flotte/registre`, `/flotte/planning`, `/flotte/vehicules`, `/flotte/chauffeurs` |
| `fleet_manage` | admin, fleet:gestionnaire | `/flotte/demandes/{id}/affecter`, POST véhicules, documents, chauffeurs, import CSV `/flotte/import…` (organisation comprise) |

- `employe` : rôle de base du module Parc automobile seulement (demandeurs, chauffeurs) ; compte relié obligatoirement à une fiche (`users.employee_id`). Accueil : `/flotte` (chauffeur : `/flotte/missions`).
- `ceo` : tout voir (sauf le calendrier), ne rien modifier. `manager` : uniquement son département (`users.department_id`) et ses sous-départements : Agents, tableau de bord, présence du jour, Calendrier, Employés en lecture. `rh` : tableau de bord, Agents (disponibilité seulement), Calendrier, Employés en lecture. RH et managers : aucune rubrique `view` (sorties manquantes, modules…), et « sortie manquante » s'affiche « Présent »/« Retard » (`status_info()`).
- Identifiants insensibles à la casse : `Auth::normalizeUsername()` (minuscules) à l'enregistrement et à la connexion.
- `app.js` ajoute un bouton « œil » à tout champ mot de passe ; `data-suggest="go|submit|filter"` sur un champ de recherche affiche les propositions (`/api/suggestions`).
- Périmètre : `Departments::sql(Auth::departmentScope())` dans toute requête accessible à un manager (pages, fiches employés, photos, calendrier, `/api/direct`, `/api/notifications`).
- Filtres : formulaires `data-auto-filter` (app.js) appliqués à chaque changement, sans bouton « Filtrer ».
- Cloche des notifications (`layout.php`, `setupNotifications()` dans `app.js`) : badges du jour via `/api/notifications` toutes les 15 s, compteur « 9+ », dernier badge lu en `localStorage`. Plus de notifications en bas à droite.
- Accueil : `Auth::home()` → `/agents` (ceo, manager), `/flotte` ou `/flotte/missions` (employe), ou `/`. Un compte sans accès à `/` y est redirigé vers son accueil (PWA).
- Cloche : messages du compte (table `notifications`, lus côté serveur) puis badges du jour (seulement avec le droit `board`).

**Tables** :

| Table | Rôle |
|---|---|
| `users`, `login_attempts`, `audit_log` | comptes (rôle, département du manager, fiche employé `employee_id`, rôle Parc automobile `fleet_role`), anti-force brute, journal |
| `departments` | arborescence (parent_id), direction ou service (`kind`), responsable N+1 (`head_employee_id`), commune à tous les modules |
| `notifications` | messages d'un compte (cloche) et état de l'e-mail (`email_status` : aucun, en_attente, envoye, echec) |
| `employee_groups` | groupes d'agents (horaires) |
| `employees` | liste commune : n° pointeuse, nom affiché, titre, contacts, groupe, département, photo, statut de travail en cours (`work_status*`) |
| `work_statuses` | historique des disponibilités saisies (télétravail, mission, congé, bureau) avec leur période : raisons d'absence du calendrier |
| `offices`, `devices` | bureaux, pointeuses (entrée/sortie, présence/accès, état de synchro, horloge, `live_since`) |
| `punches` | pointages bruts, copie fidèle, jamais modifiés |
| `schedules`, `schedule_assignments` | horaires et attributions (agent > groupe > défaut, sur période) |
| `attendance_days` | une ligne par agent et par jour (arrivée, départ, retard, heures sup, statut, correction de sortie) |
| `settings` | réglages (`history_start_date`, paramètres du parc `fleet_*`) et état du service écrit par le worker (`worker_heartbeat_at`, `worker_started_at`, `compute_last_at`, `backup_last_*`) |
| `fleet_vehicles`, `fleet_vehicle_documents` | véhicules (état, tracker_id, ref_consumption pour les modules 2 et 4), documents (assurance, contrôle technique ; le plus récent de chaque type fait foi) |
| `fleet_drivers`, `fleet_driver_vehicles` | chauffeurs (permis), véhicules autorisés (aucun : tous) |
| `fleet_requests` | demandes : `status` (en_validation, approuvee, affectee, en_cours, terminee, refusee, annulee), `validation` (en_attente, approuvee, refusee), `step` (n1, direction), affectation, urgence |
| `fleet_request_events`, `fleet_missions`, `fleet_ratings` | historique de chaque demande, démarrage et clôture (km, carburant, photo, état, `*_client_id` des saisies hors réseau), évaluations |

**Services (Attendance)** : `DayCalculator` (règles d'une journée), `ScheduleResolver`, `AttendanceComputer` (calcul et enregistrement), `DashboardStats` (indicateurs, partagés avec `/api/direct`), `AgentStatus` (pastilles de la page Agents), `AgentPhoto` (photos, page Agents et fiche employé), `WorkStatuses` (historique des disponibilités), `Calendar` (cases du calendrier : work / ongoing / open / reason / absent / pending / off).

**Règles de calcul (DayCalculator)** : arrivée = premier badge « entrée » ; départ = dernier badge « sortie » après l'arrivée, ou heure corrigée ; retard compté depuis le début si arrivée > début + tolérance ; pause déduite ; heures sup après la fin si ≥ seuil ; jour non travaillé = « repos » (ou « hors horaire » si badge) ; pas de sortie = « en cours » toute la journée (le temps avance sans attendre de sortie) puis « sortie manquante » (0 heure tant que non corrigée) ; une entrée badgée après la dernière sortie (agent revenu) annule cette sortie. Agent désactivé (`employees.active = 0`, case « Agent actif » de « Modifier la fiche » sur /agents ou de « Modifier l'employé ») : plus calculé ni affiché (pages, cloche, /api/direct, calendrier).

**Services (Fleet)** : `FleetWorkflow` (circuit pur, testé : seuils de la direction, N+1, refus, urgence, clôture), `Availability` (conflits et indisponibilités, pur, testé), `FleetImport` (import CSV pur, testé : encodage et séparateur détectés, dates JJ/MM/AAAA ou AAAA-MM-JJ, employé par nom ou n° de pointeuse, plan par ligne ; appliqué par `ImportController` en transaction après nouvelle analyse sous verrou et comparaison de l'empreinte de l'aperçu), `Timeline` (positions de la frise), `Fleet` (données, réservations, droits sur une demande `access()`, historique `event()`), `FleetNotify` (destinataires de chaque étape), `FleetFiles` (fichiers hors de public/), `FleetStats` (tableau de bord).

**Règles du circuit (FleetWorkflow)** : N+1 = responsable du service du demandeur (en remontant l'arbre si le demandeur est lui-même responsable ou si personne n'est désigné) ; direction en plus si hors ville, plus de `fleet_direction_max_days` jours (2) ou week-end ; sans N+1 avec compte : direction directement ; refus avec motif obligatoire ; personne ne valide sa propre demande ; urgence : affectation possible avant validation (`assigned_before_validation`), validation a posteriori ; un refus avant le départ libère le véhicule. Affectation en transaction avec `SELECT … FOR UPDATE` (demande, véhicule, chauffeur) et contrôles refaits sous verrou.

**Worker** (`worker/sync.py`) : un thread par pointeuse active, connexion permanente ; synchro complète au démarrage puis toutes les `FULL_SYNC_INTERVAL` s (1800) ; badges en direct (`live_capture`) insérés dans `punches` ; recalcul de l'agent via `php bin/compute.php --employee-device-id=…` (au plus toutes les 5 s) ; calcul global des `COMPUTE_DAYS` (7) derniers jours toutes les `SYNC_INTERVAL` s (300) ; reconnexion 10 s → 30 s → 60 s → 5 min, puis toutes les `FAILED_RETRY_DELAY` s (1800) après `MAX_FAILURES` (5) échecs de suite ; F16 (`SLOW_DEVICES` = 10.0.4.4, 10.0.4.5) : délai `SLOW_TIMEOUT` (60 s) et `verbose` de pyzk ; remise à l'heure des horloges au démarrage si l'écart dépasse `CLOCK_MAX_DRIFT` (30 s) puis à `CLOCK_SET_AT` (03:00), seule écriture sur les appareils (écart avant/après dans `audit_log`) ; battement de cœur `settings.worker_heartbeat_at` toutes les 60 s ; thread « sauvegarde » : `mariadb-dump` compressé dans `/backups` (= `./data/backups`) à `BACKUP_AT` (02:00), `BACKUP_KEEP_DAYS` (14) jours gardés, à la main : `python3 worker/sync.py --sauvegarde` ; thread « parc-automobile » : `php bin/fleet-tasks.php` toutes les `FLEET_TASKS_INTERVAL` s (60 ; 0 = désactivé). Arrêt propre sur SIGTERM.

**Mode direct** : les pages `data-live` (tableau de bord, présence du jour, agents, pointeuses) appellent `GET /api/direct` toutes les 5 s (60 s onglet caché) ; reprise immédiate au retour sur la page (`resume()` dans `startLive()`), requêtes coupées après 15 s. Thème clair uniquement : racine en `color-scheme: light dark` (meta + `:root`, empêche l'assombrissement forcé des navigateurs mobiles), `body` en `only light` ; ne pas remettre `only light` sur la racine.

**Parc automobile hors réseau** : formulaires `data-offline-form` (écran mission) envoyés par `app.js` ; sans réseau, la saisie (avec `client_id` et `recorded_at`) va dans IndexedDB `liquid-offline` et part dans l'ordre au retour du réseau ; le serveur ignore un `client_id` déjà reçu. Le service worker garde les pages `/flotte/missions…` (cache `liquid-pages`, effacé à la déconnexion). Balises meta `csrf-token` et `user-id` dans `layout.php`.

**Ajouter un module (Leave)** (modèle : `app/Modules/Fleet`) :
1. Dossier `app/Modules/<Module>/{Controllers,Services,Views}` (namespace `App\Modules\<Module>\…`, vues `'<Module>:nom'`).
2. Routes dans `public/index.php` avec leur droit ; remplacer la page `/conges` de `ModuleController`.
3. Réutiliser `employees` (et `departments`) par clé étrangère, les comptes et rôles existants, le gabarit `layout.php` (ajouter les entrées du menu).
4. Tables : migration `database/migrations/NNN_*.sql` + `schema.sql`.

# 5. Conventions

- Interface, messages, commentaires et commits en français.
- Couleurs #273c88 (principale) et #c8187d (accent) ; coins arrondis de 5 px maximum pour boutons et champs ; style des maquettes : menu latéral coloré, cartes blanches, tableaux aérés.
- CSP stricte : aucun script inline, aucun attribut style= ou onclick=. Tout le JS dans public/assets/js/app.js, tout le CSS dans public/assets/css/app.css.
- Requêtes SQL préparées uniquement ; échappement avec e() dans les vues ; jeton CSRF sur chaque POST ; droit vérifié dans la route.
- Toute modification de données importante passe par Audit::log() ; une demande de véhicule a en plus son historique (`Fleet::event()`).
- Notifications : `Notifications::send()` (Platform) ; jamais d'e-mail envoyé pendant une requête web (file `notifications.email_status`, envoi par le worker).
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
- Déploiement sur la VM : procédure détaillée et interdits dans README.md, section « Déploiement : procédure à suivre » (sauvegarde, git pull --ff-only, toutes les migrations dans l'ordre, sudo docker compose up -d --build app worker, vérifications, retour arrière). En résumé : cd ~/liquid-attendance && git pull --ff-only && (migrations) && sudo docker compose up -d --build app worker && sudo docker compose logs -f worker
- Le .env n'est pas dans git : il se modifie uniquement sur la VM.
- Tests locaux : php tests/DayCalculatorTest.php, php tests/CalendarTest.php, php tests/FleetWorkflowTest.php, php tests/FleetImportTest.php, php -l sur les fichiers modifiés, python3 -m py_compile worker/sync.py. Les pointeuses ne sont pas joignables depuis le poste : simule-les avec un faux module zk.
- À la fin de chaque tâche, donne-moi les commandes exactes à lancer sur la VM, dans l'ordre.

Commande de migration (sur la VM, depuis ~/liquid-attendance) :
`sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/NNN_nom.sql`
Migrations existantes : `001_live.sql` (mode direct), `002_agents.sql` (départements, rôles ceo/manager, fiche et statut des agents), `003_identifiants.sql` (identifiants en minuscules, insensibles à la casse), `004_calendrier.sql` (table `work_statuses`), `005_flotte.sql` (parc automobile : organisation, rôles, véhicules, chauffeurs, demandes, missions, notifications). Toutes rejouables sans risque.
Données d'exemple du parc : `database/seed_fleet_demo.sql` (comptes `grace.mbuyi`, `sarah.ilunga`, `jean.mukendi`, `patrick.lunda`, `didier.kasongo`…, mot de passe `demo-flotte-2026`) — base de test uniquement.

# 8. État et questions ouvertes

D'après docs/CADRAGE.md (état au 22 oct. 2026) :
- Fait : accès VM → pointeuses (VLAN 3602 ; direct par ens19 depuis le 14 oct.), base du projet et module Présence, temps réel et remise à l'heure nocturne, mode direct (`/api/direct`, à valider sur la VM avec les vraies pointeuses).
- À faire : déploiement sur la VM, réservation DHCP de la VM, publication Internet par la DMZ VLAN 3604 (en attente du sous-réseau) puis certificat Let's Encrypt, sécurisation de Kibana.
- Ajouté depuis : page Agents, départements, rôles CEO et Manager (migration `002_agents.sql`).
- 12 oct. 2026 : RH limités (tableau de bord, Agents en disponibilité seule, Employés en lecture), propositions dans les recherches, bouton « œil » des mots de passe, identifiants insensibles à la casse (migration `003_identifiants.sql`).
- 12 oct. 2026 (suite) : Calendrier (`/calendrier`, migration `004_calendrier.sql`), cloche des notifications, Employés ouverts aux managers (leur département), photo cliquable vers la fiche, photo modifiable dans « Modifier l'employé », menus RH/manager réduits (pas de sorties manquantes ni de modules).
- 12 oct. 2026 (fin) : désactivation d'un agent depuis la page Agents, temps « en cours » maintenu après un retour (entrée après sortie), bouton « Aujourd'hui » du calendrier vers la vue jour, bordures colorées d'un seul côté retirées du style.
- 12 oct. 2026 (soir) : tableau de bord en camemberts (présents/absents, à l'heure/en retard, en %), filtres appliqués sans bouton, filtre « Présents (même sortis) » (Présence `status=venus`, Calendrier `presence=venus`), heures « 00h…23h » du calendrier, reprise du direct au déverrouillage du téléphone (page hors ligne qui se recharge seule), thème clair forcé.
- 12 oct. 2026 (nuit) : page Pointages (`/pointages`, droit `board`, périmètre manager, aujourd'hui seulement pour RH/managers) au lieu du lien « Accès aux salles » du tableau de bord, derniers pointages cliquables vers la fiche de l'agent, bouton « Voir le rapport détaillé », correctif du mode sombre forcé sur téléphone.
- 13 oct. 2026 : rapport Excel (.xlsx) de la page Agents (`/agents/export`, droit `export` : admin et RH) avec période (du/au, 93 jours max), filtre (arrivés, à l'heure, en retard, pas arrivés, télétravail, mission, congé), département et agent ; une ligne par agent et par jour + récapitulatif par agent ; classeur écrit par `App\Core\Xlsx` (sans extension zip).
- 20 oct. 2026 : **Parc automobile, module 1** (migration `005_flotte.sql`) : directions/services et N+1, rôle `employe`, compte relié à une fiche, rôles Parc automobile ; véhicules, documents (alerte 30 j), chauffeurs ; demandes (ordre de mission), circuit N+1 → direction (hors ville, > 2 jours, week-end), refus motivé, urgence validée a posteriori ; affectation avec frise et verrou anti-double réservation, planning ; écran mission téléphone avec saisie hors réseau (IndexedDB) ; cloche + e-mails SMTP (`SMTP_*` dans .env) + rappel 1 h avant (worker) ; évaluation ; historique ; tableau de bord et paramètres.
- 22 oct. 2026 : **nouvelle architecture réseau** (14 oct. : VLAN 102 et 4040 retirés de gi17, MikroTik injoignable depuis le VLAN 102 ; VM reliée directement au VLAN 3602 par ens19 = 10.0.4.249/24 ; publication Internet par la DMZ VLAN 3604, en attente) ; worker : remise à l'heure au démarrage (> 30 s), F16 en délai long et journal détaillé, tentatives toutes les 30 min après 5 échecs, battement de cœur, sauvegarde nocturne de la base (02:00, `./data/backups`, 14 jours) ; page Administration › État du service (`/etat-du-service`). Aucune migration (valeurs dans `settings`).
- 22 oct. 2026 (suite) : **import CSV du parc automobile** (`/flotte/import`, droit `fleet_manage`) : organisation (direction ; service ; N+1), véhicules (avec échéances assurance et contrôle technique), chauffeurs ; modèle téléchargeable, aperçu (créations, mises à jour, erreurs ligne par ligne), rien d'enregistré avant confirmation, transaction + `Audit::log('import_…')`. Aucune migration.
- Questions ouvertes : sous-réseau de la DMZ (VLAN 3604) à fournir par la sécurité ; horaires réels et groupes à saisir ; parc automobile : nombre de véhicules et de sites, trackers GPS, serveur SMTP à fournir, anglais de l'interface ; historique antérieur à 2026 (`history_start_date`) ; logo officiel à fournir.

**Règle** : quand une tâche change l'infrastructure, les ports, le schéma ou l'état d'avancement, mettre à jour CLAUDE.md et docs/CADRAGE.md dans le même commit.

Règles de travail détaillées (périmètre des managers, page Agents, icônes, procédure de test) : @AGENTS.md
