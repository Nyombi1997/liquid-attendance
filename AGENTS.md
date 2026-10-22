# AGENTS.md — guide de travail sur Liquid Présence

À lire avant toute modification. Le contexte métier complet est dans `README.md` et `docs/CADRAGE.md`.

## Le projet en bref

Plateforme web interne (présence via pointeuses ZKTeco, parc automobile — module 1 livré —, puis congés).
PHP 8.3 en MVC **sans framework**, MariaDB 11, Docker Compose, PWA. Worker Python (`worker/sync.py`) relié aux pointeuses.
**Interface, code commenté, messages et commits en français.**

```
app/Core/                    routeur, base, vues, Auth (rôles), CSRF, Audit, helpers.php
app/Modules/Platform/        connexion, profil, utilisateurs, départements (Services/Departments.php)
app/Modules/Attendance/      présence : Controllers/, Services/, Views/
app/Modules/Fleet/           parc automobile : Controllers/, Services/, Views/
public/index.php             toutes les routes
public/assets/               app.css (feuille unique), app.js (sans dépendance), icons.svg, vendor/fontawesome
database/schema.sql          installation neuve ; database/migrations/NNN_*.sql pour les bases existantes
storage/                     fichiers déposés (photos, fleet/…) ; en production volume ./data/storage
```

## Règles à respecter

### Rôles, droits et périmètre
- Rôles (`users.role`) : `admin`, `rh`, `ceo`, `manager`, `lecteur`, `employe` (parc automobile seulement). Libellés dans `Auth::ROLES`. Rôle Parc automobile (`users.fleet_role`) : `demandeur`, `validateur`, `gestionnaire`, `chauffeur`, `direction` (`Auth::FLEET_ROLES`).
- Droits vérifiés par le routeur (3e argument de `$r->get/post`) :
  - `board` : page Agents, tableau de bord, pointages (`/pointages`), `/api/direct`, `/api/notifications`, `/api/suggestions`, profil — **tous les rôles** ;
  - `presence` : présence du jour — tous sauf `rh` ;
  - `staff` : liste et fiche des employés (consultation) — **tous les rôles** (manager : son département) ;
  - `calendar` : calendrier de présence — `admin`, `rh`, `manager` ;
  - `view` : autres pages de consultation (défaut des routes GET) — `admin`, `ceo`, `lecteur` ;
  - `status` : disponibilité des agents (`POST /agents/{id}/statut`) — `admin`, `rh` ;
  - `export` : rapport Excel de la page Agents (`/agents/export`) — `admin`, `rh` ;
  - `manage` : toutes les autres modifications — `admin` seul (défaut des routes POST) ;
  - `admin` : comptes, pointeuses, journal, état du service (`/etat-du-service`), paramètres du parc automobile ;
  - `account` : profil, déconnexion, cloche (`/api/notifications`) — tous les comptes, `employe` compris ;
  - parc automobile (rôle de la plateforme **ou** `fleet:<fleet_role>` dans `Auth::ABILITIES`) : `fleet` (tous), `fleet_validate`, `fleet_drive`, `fleet_board`, `fleet_manage`. Les droits fins sur une demande (demandeur, validateur de l'étape, chauffeur, gestionnaire) viennent de `Fleet::access()` : toute nouvelle page ou action sur une demande doit l'appeler.
- **CEO** : tout voir (sauf le calendrier), ne rien modifier. **Manager** : uniquement son département et ses sous-départements (Agents, tableau de bord, présence du jour, Calendrier, Employés). **RH** : tableau de bord, Agents (disponibilité seulement), Calendrier, Employés en lecture.
- RH et managers ne voient **rien** de ce qui demande `view` (sorties manquantes, accès, horaires, modules…) : pas de lien, pas de carte. « Sortie manquante » leur est affiché « Présent » / « Retard » : passer les minutes de retard à `status_badge($status, $late)` / `status_info()`.
- **Toute requête qui lit des agents, des journées ou des pointages et qui est accessible à un manager doit appliquer le périmètre** :
  `Departments::sql(Auth::departmentScope())` (renvoie `''`, `' AND 0'` ou `' AND e.department_id IN (…)'`). Ne jamais l'oublier dans une nouvelle page `board` ni dans `/api/direct`.
- Dans les vues, masquer liens et boutons que le rôle ne peut pas suivre : `can('presence')`, `can('staff')`, `can('calendar')`, `can('view')`, `can('status')`, `can('manage')`, `can('admin')`. Un manager ou un RH ne doit voir aucun lien vers une page qui lui renverrait 403.
- Le menu de `layout.php` liste chaque entrée avec son droit (5e élément) : ajouter une page = ajouter l'entrée avec le même droit que sa route.
- Identifiants : toujours passer par `Auth::normalizeUsername()` (minuscules) avant d'enregistrer ou de comparer.
- Accueil après connexion : `Auth::home()` (`/agents` pour `ceo` et `manager`, `/` sinon). Le menu de `app/Views/layout.php` dépend du rôle.

### Parc automobile (`app/Modules/Fleet`)
- **Règles dans les services purs** `FleetWorkflow` (circuit) et `Availability` (conflits) : les contrôleurs lisent la demande, appellent la règle, enregistrent les champs renvoyés. Toute nouvelle règle y va, avec son test dans `tests/FleetWorkflowTest.php`. Pas de règle métier en JavaScript.
- **Transitions** d'une demande dans une transaction, ligne verrouillée (`SELECT … FOR UPDATE`, voir `RequestController::transition()`) ; affectation : verrous demande → véhicule → chauffeur, dans cet ordre, contrôles refaits sous verrou.
- Chaque étape : `Fleet::event()` (historique de la demande) **et** `Audit::log()` ; notification par `FleetNotify` (jamais d'e-mail envoyé pendant la requête : file `notifications`, envoi par `bin/fleet-tasks.php` dans le worker).
- Fichiers (ordres de mission, documents, photos des compteurs) : `FleetFiles` (contrôle du contenu, 5 Mo, hors de `public/`), servis par un contrôleur après `Fleet::access()` ; PDF toujours en téléchargement.
- Écran mission : formulaires `data-offline-form` traités par `app.js` (file IndexedDB `liquid-offline`, `client_id` unique, `recorded_at`) ; le contrôleur répond en JSON si `Accept: application/json` et accepte un `client_id` déjà reçu sans rien refaire.
- Frises (affectation, planning) : positions en % calculées par `Timeline`, écrites en `data-span-left` / `data-span-width` (CSP : pas de `style=`).
- Un validateur ne valide jamais sa propre demande ; un refus exige un motif.

### Composants automatiques (app.js)
- Formulaire de filtres : `<form class="filters card" method="get" data-auto-filter>` sans bouton « Filtrer » ; listes et dates appliquées à chaque changement, recherche à l'Entrée ou à la sortie du champ.
- Tout `input[type=password]` reçoit un bouton « œil » (afficher / masquer) : rien à ajouter dans les vues.
- Barre de recherche d'agents : ajouter `data-suggest="go"` (ouvre la fiche), `"submit"` (remplit et envoie le filtre) ou `"filter"` (filtre instantané de la page Agents). Les propositions viennent de `/api/suggestions` (périmètre appliqué côté serveur).

### Sécurité (CSP stricte, ne pas l'assouplir)
- `default-src 'self'`, `img-src 'self' data:`, `style-src 'self'`, `script-src 'self'` (`docker/app/apache-site.conf`).
- Donc : **aucun CDN**, aucun `<script>` inline, aucun attribut `style="…"` ni `onclick="…"` dans le HTML. Les styles vont dans `app.css` (classes), le JS dans `app.js`. Modifier `element.style` depuis `app.js` est permis. Images locales en `data:` (pas de `blob:`).
- Échapper toute sortie avec `e()` ; requêtes préparées (`Database::all/one/value/run/insert`) ; `csrf_field()` dans chaque formulaire POST.
- Journaliser chaque modification avec `Audit::log('action_en_snake_case', [...])`.
- Fichiers déposés : hors de `public/`, servis par un contrôleur qui vérifie droits et périmètre (modèle : `AgentController::photo`).

### Icônes
- **Font Awesome Free 6.7.2** est servi localement (`public/assets/vendor/fontawesome`, CSS chargé dans `layout.php`). Helper PHP : `fa('fa-solid fa-plane')` → `<i class="…" aria-hidden="true">`. En JS, créer un `<i>` avec les mêmes classes.
- N'utiliser que les styles gratuits : `fa-solid`, `fa-regular` (jeu réduit), `fa-brands`. Vérifier qu'une icône existe en version Free avant de l'employer.
- Le sprite `icons.svg` (`icon('nom')`, traits fins) reste utilisé pour le menu et les boutons existants ; ne pas mélanger les deux dans un même groupe de boutons.
- Un bouton qui ne contient qu'une icône a un `aria-label` (et un `title` si utile).

### Base de données
- Tout changement de structure = **deux endroits** : `database/schema.sql` (installation neuve) **et** une nouvelle migration `database/migrations/NNN_nom.sql` **rejouable sans risque** (`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`, `ADD CONSTRAINT … FOREIGN KEY IF NOT EXISTS`). Ajouter la migration au tableau et à la commande du README.
- Les tables `punches` (copie fidèle des pointeuses) ne sont jamais modifiées par l'application.

### Interface et design
- Couleurs : principale `#273c88`, accent `#c8187d` (variables CSS dans `:root` d'`app.css`, ne pas coder de couleur en dur ailleurs). Coins des boutons/champs : 5 px max ; cartes : 8–10 px.
- Réutiliser les composants existants : `.card`, `.btn-*`, `.badge-*`, `.pill-*`, `.chip`, `.field`, `.dialog`, `.filters`, `.kpi`, `.agent-card`.
- Tester l'affichage à 1280 px **et** à 390 px (mobile, sans défilement horizontal).
- Libellés et dates en français (`date_fr()`, `time_label()`, `minutes_label()`).

### Page Agents (`/agents`)
- Contrôleur `AgentController`, vue `Attendance/Views/agents.php`, règles dans `Services/AgentStatus.php` :
  - statut de travail (icône en haut à droite) : statut saisi par les RH tant que `work_status_until` n'est pas passé, sinon « au bureau » dès le premier badge du jour, sinon « pas encore au bureau » ;
  - arrivée (en bas à droite) : `ontime` / `late` / `expected` / `missing` / `off`, d'après `attendance_days` ;
  - puces de filtre : `AgentStatus::FILTERS` et `AgentStatus::filters()`.
- `/api/direct` renvoie l'état déjà calculé de chaque carte (`days[].card`) : **pas de règle métier en JavaScript**, `app.js` ne fait qu'afficher.
- Un clic sur la photo d'une carte ouvre `/employes/{id}` ; le bouton appareil photo (admin) reste un bouton à part, au-dessus du lien.
- Photo : recadrée dans le navigateur (`createCropper` dans `app.js`, JPEG 512×512), gabarit partagé `Views/_photo_cropper.php` (page Agents et « Modifier l'employé », où `$cropperPhoto` l'initialise seul), envoyée dans `photo_data`, contrôlée et enregistrée par `Services/AgentPhoto.php` (pas d'extension GD dans le conteneur). URL versionnée : `photo_url($emp)` ; pastille ronde : `avatar($nom, photo_url($emp))`.
- Rapport Excel (`AgentController::export`, fenêtre `#export-dialog`, `setupExport()` dans `app.js`) : paramètres `du`, `au` (93 jours max), `filtre` (clé de `AgentStatus::FILTERS`), `dep`, `q`. Chaque journée est classée avec `AgentStatus::arrival()` / `filters()` comme la page ; jours passés : disponibilité d'après `WorkStatuses::reasons()`. Le JS ne fait que préremplir la fenêtre (puce et recherche en cours). Fichier écrit par `App\Core\Xlsx` (pas d'extension zip dans le conteneur) ; exports Excel uniquement en `.xlsx`.
- Disponibilité saisie (`AgentController::status`) : mettre à jour `employees.work_status*` **et** l'historique `WorkStatuses::record()` (lu par le calendrier).

### Calendrier (`/calendrier`)
- `CalendarController` + vue `calendar.php` ; l'état de chaque case vient de `Services/Calendar.php` (testé par `php tests/CalendarTest.php`), les raisons d'absence de `WorkStatuses::reasons()`.
- Vue jour : barres placées par `app.js` (`setupCalendar`) d'après `data-from-min` / `data-to-min` (minutes depuis minuit) ; `data-since` / `data-elapsed` : temps des journées en cours qui avance. La CSP interdit `style=` : jamais de position écrite dans le HTML.
- En direct : la page porte `data-live="calendar"` ; quand `/api/direct` signale un agent affiché, la grille est relue depuis le serveur (pas de règle en JS).

### Mode direct
- Une page en direct porte `data-live="<page>"` et les attributs `data-live-since-*` (voir `dashboard.php`, `agents.php`). Le traitement est dans `startLive()` de `app.js` (`apply()` → fonction de mise à jour de la page).
- Toute nouvelle donnée ajoutée à `/api/direct` doit rester légère (appel toutes les 5 s) et respecter le périmètre du manager.
- Notifications : uniquement la cloche de `layout.php` (`setupNotifications()`, `/api/notifications`) ; ne pas réintroduire de notifications flottantes.

## Vérifier avant de rendre la main

```bash
for f in $(git ls-files '*.php') ; do php -l "$f" | grep -v '^No syntax'; done   # syntaxe PHP
node --check public/assets/js/app.js                                           # syntaxe JS
php tests/DayCalculatorTest.php                                               # règles de calcul
php tests/CalendarTest.php                                                    # cases du calendrier
php tests/FleetWorkflowTest.php                                               # circuit et conflits du parc
python3 tests/test_sync.py                                                    # worker (faux module zk)
```

Test de bout en bout sans Docker Compose (base jetable) :

```bash
docker run -d --rm --name la-test-db -e MARIADB_ROOT_PASSWORD=test -e MARIADB_DATABASE=la \
  -p 127.0.0.1:33099:3306 mariadb:11.4 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
docker exec -i la-test-db mariadb -uroot -ptest la < database/schema.sql
docker exec -i la-test-db mariadb -uroot -ptest la < database/seed.sql
DB_HOST=127.0.0.1 DB_PORT=33099 DB_NAME=la DB_USER=root DB_PASSWORD=test SESSION_SECURE=0 \
  STORAGE_PATH=/tmp/la-storage php -S 127.0.0.1:8099 -t public <routeur qui sert les fichiers statiques puis public/index.php>
docker stop la-test-db
```

- Tester chaque rôle concerné (au moins `admin`, `rh`, `ceo`, `manager`) : codes HTTP attendus (200 / 403), périmètre du manager dans la page **et** dans `/api/direct`. Parc automobile : charger `database/seed_fleet_demo.sql` (base de test seulement) et tester avec ses comptes (gestionnaire, validateur, direction, demandeur, chauffeur ; mot de passe `demo-flotte-2026`).
- Pour une migration : l'appliquer sur une base créée avec l'ancien `schema.sql` (`git show HEAD:database/schema.sql`), deux fois de suite.
- Tout fichier temporaire va dans le dossier de travail temporaire, jamais dans le dépôt.

## Documentation
- Mettre à jour `README.md` (fonctionnalités, rôles, migrations, structure) quand le comportement change, et ce fichier quand une règle de travail change.
- Ne pas committer ni pousser sans demande explicite.
