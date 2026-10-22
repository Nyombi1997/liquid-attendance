# Liquid Présence — plateforme interne

Plateforme web interne avec une connexion commune et trois modules :

| Module | État |
|---|---|
| **Présence** (pointeuses ZKTeco) | Disponible |
| **Parc automobile** | Module 1 « Demande et octroi de véhicule » disponible ; GPS, alertes de mouvement et carburant à venir |
| Congés | Prévu (priorité 3) |

Stack : PHP 8.3 en MVC sans framework, MariaDB 11, phpMyAdmin, Docker Compose. Application installable sur téléphone (PWA). Interface en français.

Le contexte complet du projet (réseau, décisions, questions ouvertes) est dans [`docs/CADRAGE.md`](docs/CADRAGE.md).

> **Mettre à jour ou déplacer le serveur** (assistant IA compris) : suivre la section [« Déploiement : procédure à suivre »](#déploiement--procédure-à-suivre-assistant-ia-ou-humain), étape par étape.

---

## Ce que fait le module Présence

- **Tout est en direct** : un badge apparaît dans la plateforme en quelques secondes, sans recharger la page (voir « Fonctionnement en direct » ci-dessous).
- **Synchronisation complète** sur la même connexion (ces appareils n'acceptent souvent qu'un client à la fois) : au démarrage puis toutes les 30 minutes (`FULL_SYNC_INTERVAL`), elle relit les utilisateurs, tous les pointages et l'horloge. Rien n'est perdu pendant une coupure : les badges restent sur l'appareil et sont repris à la reconnexion.
- **Reconnexion automatique** en cas de coupure, après 10 s, 30 s, 60 s puis toutes les 5 minutes, sans bloquer les autres pointeuses ; après 5 échecs de suite (`MAX_FAILURES`), une tentative toutes les 30 minutes (`FAILED_RETRY_DELAY`). L'erreur est affichée sur la page Pointeuses.
- **Pointeuses lentes** (F16 du NOC et de l'IT Room, `SLOW_DEVICES=10.0.4.4,10.0.4.5`) : délai d'attente de 60 s (`SLOW_TIMEOUT`) et journal détaillé (durée de chaque essai TCP/UDP, mode `verbose` de pyzk) dans `sudo docker compose logs worker`.
- **Calcul global** des 7 derniers jours toutes les 5 minutes (`SYNC_INTERVAL`), dans un thread à part, en filet de sécurité.
- **Rien n'est effacé ni modifié sur les appareils**, à une exception près : l'horloge de chaque pointeuse est remise à l'heure de la VM au démarrage du service si l'écart dépasse 30 s (`CLOCK_MAX_DRIFT`), puis chaque nuit à 03:00 (`CLOCK_SET_AT`, `off` pour désactiver). L'écart avant et après est noté dans le journal du worker et dans le journal d'activité.
- **Importe les employés** depuis les pointeuses. Le nom affiché dans la plateforme se modifie ensuite par un admin, sans toucher aux appareils.
- **Calcule chaque journée** : arrivée, départ, temps de présence, retard, départ anticipé, heures supplémentaires.
- **Horaires saisis manuellement**, attribuables à tout le monde, à un groupe ou à un ou plusieurs agents, sur une période. Priorité : agent > groupe > horaire par défaut. Les horaires de nuit (qui passent minuit) sont gérés.
- **Sorties manquantes** : une journée sans badge de sortie est marquée « sortie manquante » et ne compte aucune heure tant qu'un admin ou les RH n'ont pas saisi l'heure de sortie. La correction est tracée (qui, quand, motif) et peut être annulée.
- **Pointages** (`/pointages`, tous les rôles) : tous les badges d'une journée (entrées, sorties, accès aux salles), filtrables par type, pointeuse et agent ; un clic sur l'agent ouvre sa fiche. Manager : son département ; RH et managers : aujourd'hui seulement. Les « Derniers pointages » du tableau de bord y mènent et chaque ligne ouvre la fiche de l'agent.
- **Rapport détaillé** : bouton de la carte « Répartition du jour » du tableau de bord, vers la Présence du jour (Calendrier pour les RH).
- **Accès aux salles** (NOC, IT Room) : historique des entrées, séparé du calcul de présence.
- **Contrôle des horloges** : décalage de chaque pointeuse affiché, alerte au-delà d'une minute, remise à l'heure chaque nuit.
- **État du service** (Administration › État du service, `/etat-du-service`, administrateur) : dernier passage du worker (battement de cœur en base toutes les 60 s ; alerte s'il ne répond plus), pointeuses en écoute, dernier badge et écart d'horloge de chaque pointeuse, taille de la base et des plus grosses tables, dernière sauvegarde (date, taille, erreur éventuelle).
- **Sauvegarde nocturne** de la base par le worker, chaque nuit à 02:00 (`BACKUP_AT`) : `mariadb-dump` compressé dans `./data/backups` (`liquid_AAAA-MM-JJ.sql.gz`), 14 jours gardés (`BACKUP_KEEP_DAYS`).
- **Page Pointeuses** : état de la connexion, dernière synchronisation complète, « dernier badge reçu il y a … » et indicateur « en écoute » quand la connexion directe est active.
- **Page Agents** (`/agents`) : une carte par agent avec photo, titre, e-mail et téléphone (copiables d'un clic), département, groupe, **heure d'arrivée du jour** (vert : à l'heure, orange : en retard, rouge : pas arrivé) et **statut de travail** en haut à droite (au bureau, télétravail, mission, congé). Filtres par département (arbre à gauche), par état (à l'heure, en retard, pas arrivés…) et recherche instantanée. Mise à jour en direct.
- **Fiche agent depuis la carte** (Administrateur) : nom, titre, e-mail, téléphone, département et **photo recadrée dans le navigateur** (carré 512 px, JPEG). Un clic sur la **photo** d'une carte ouvre la page de détails de l'agent (`/employes/{id}`) ; le formulaire « Modifier l'employé » propose aussi de changer ou retirer la photo.
- **Tableau de bord** : indicateurs du jour et deux **camemberts en pourcentage**, mis à jour en direct : présents / absents (agents attendus) et à l'heure / en retard (agents arrivés). Un clic sur « Présents aujourd'hui » ouvre la présence filtrée sur les agents venus.
- **Filtres appliqués aussitôt** (Présence, Calendrier, Employés, Accès aux salles) : pas de bouton « Filtrer » ; une liste ou une date s'applique dès qu'elle change, la recherche à l'Entrée, à la sortie du champ ou au choix d'une proposition. Filtre **« Présents (même sortis) »** : tous les agents qui ont badgé une entrée ce jour-là (Présence → Statut, Calendrier → Présence).
- **Calendrier** (`/calendrier`, Administrateur, RH et managers pour leur département) : une ligne par agent. Vue **Jour** façon planning (colonnes des heures « 00h » à « 23h », barre de l'arrivée au départ sur fond de l'horaire prévu, trait de l'heure actuelle) ; vues **Semaine** et **Mois** (une case par jour, total de la période). Le temps passé au bureau avance **en direct** pour les agents présents ; les raisons d'absence saisies (télétravail, mission, congé, avec date de fin et note) s'affichent sur leurs jours, y compris passés (historique `work_statuses`) ; un agent **absent sans raison est en rouge**. Retard signalé en orange, départ non badgé en barre estompée.
- **Notifications** : cloche en haut à droite sur toutes les pages (avec, au-dessus, les messages du parc automobile), avec le nombre de badges du jour non lus (« 9+ » au-delà de 9). Un clic ouvre la liste des derniers badges (lien vers la fiche de l'agent) et les marque comme lus ; « Tout marquer comme lu » remet le compteur à zéro. Badges limités au périmètre de l'utilisateur (`/api/notifications`, toutes les 15 s) ; dernier badge lu mémorisé dans le navigateur.
- **Statut de travail** : « au bureau » s'affiche automatiquement au premier badge d'entrée ; les RH et l'administrateur peuvent saisir la disponibilité (télétravail, mission, congé ou bureau) jusqu'à une date, puis revenir à l'automatique ; c'est la seule modification ouverte aux RH. Un statut saisi l'emporte sur le badge jusqu'à sa date de fin. Chaque saisie est conservée avec sa période (table `work_statuses`) pour le calendrier.
- **Désactivation d'un agent** : case « Agent actif » dans « Modifier la fiche » (page Agents, administrateur) ou « Modifier l'employé ». Un agent désactivé n'est plus calculé ni affiché (Agents, tableau de bord, calendrier, cloche, derniers badges) ; son historique est conservé et il se réactive depuis Employés → filtre « Inactifs ».
- **Départements** (`/departements`) : arborescence (un département peut avoir des sous-départements), affectation des agents depuis la carte, la fiche employé ou en lot depuis la liste des employés.
- **Recherche avec propositions** : dans toutes les barres de recherche, les agents correspondants s'affichent dès la première lettre (nom, titre, matricule ou n° de pointeuse) ; un clic (ou flèches + Entrée) ouvre la fiche ou applique le filtre. Propositions limitées au périmètre de l'utilisateur (`/api/suggestions`).
- **Mots de passe** : bouton « œil » sur chaque champ pour afficher ou masquer la saisie.
- **Identifiants insensibles à la casse** : `Admin`, `ADMIN` et `admin` désignent le même compte (enregistré en minuscules).
- **Rapport Excel de la page Agents** (administrateur et RH) : bouton « Exporter en Excel » : choix de la période (aujourd'hui, hier, cette semaine, ce mois, mois dernier ou dates libres, 93 jours au plus), des agents (tous, arrivés, à l'heure, en retard, pas arrivés, télétravail, mission, congé), du département et d'un agent. Fichier `.xlsx` : une ligne par agent et par jour (arrivée, retard, départ, présence, disponibilité d'après l'historique), récapitulatif par agent et synthèse par filtre.
- **Export CSV** de la présence (compatible Excel), **journal d'activité**, rôles ci-dessous.

### Rôles

| Rôle | Accès | Page d'accueil |
|---|---|---|
| Administrateur | Tout, et **seul rôle qui modifie** : fiches et photos des agents, employés, départements, groupes, horaires, corrections, comptes, pointeuses | Tableau de bord |
| Ressources humaines | Tableau de bord, Agents (**mise à jour de la disponibilité uniquement**, rapport Excel), Calendrier, Employés (consultation) ; tous les départements. Ni sorties manquantes ni modules | Tableau de bord |
| Direction (CEO) | Toutes les pages de consultation (sauf le calendrier), tous les départements, **aucune modification** | Agents |
| Manager de département | Agents, tableau de bord, présence **du jour**, Calendrier et Employés (consultation), pour son département et ses sous-départements uniquement. Ni sorties manquantes ni modules | Agents |
| Lecteur | Consultation et export, sans modification | Tableau de bord |
| Employé | **Parc automobile seulement** : ses demandes de véhicule et, pour un chauffeur, ses missions. Compte relié à une fiche employé | Parc automobile (Mes missions pour un chauffeur) |

Le département d'un manager se choisit dans **Administration > Utilisateurs** (obligatoire pour ce rôle). Le filtrage est fait côté serveur sur chaque requête (pages, fiches, photos, calendrier, `/api/direct` et `/api/notifications`).

Pour les RH et les managers, la notion de « sortie manquante » n'apparaît nulle part : une journée sans sortie badgée s'affiche « Présent » (ou « Retard ») et la carte « Sorties manquantes » du tableau de bord est masquée.

### Règles de calcul

| Élément | Règle |
|---|---|
| Arrivée | Premier pointage sur une pointeuse « entrée » du jour |
| Départ | Dernier pointage sur une pointeuse « sortie » après l'arrivée, ou heure corrigée |
| Retard | Si l'arrivée dépasse début + tolérance, retard compté depuis l'heure de début |
| Pause | Déduite du temps de présence les jours travaillés |
| Heures sup | Dépassement après la fin de l'horaire, compté s'il atteint le seuil de l'horaire |
| Jour non travaillé | « Repos », ou « hors horaire » si l'agent a pointé (tout compté en heures sup) |
| Pas de sortie | « En cours » toute la journée (le temps de présence avance), puis « sortie manquante » |
| Entrée après la dernière sortie | L'agent est revenu : la sortie est ignorée, la journée redevient « en cours » (puis « sortie manquante » s'il ne ressort pas) |

Les règles sont dans `app/Modules/Attendance/Services/DayCalculator.php` et testées par `php tests/DayCalculatorTest.php`. Le service de synchronisation est testé sans pointeuse par `python3 tests/test_sync.py` (faux module `zk` dans `tests/fakezk`, hors de l'image Docker).

### Fonctionnement en direct

Objectif : un badge visible dans la plateforme en moins de 10 secondes, sans recharger la page.

| Étape | Où | Délai |
|---|---|---|
| 1. Le badge est envoyé par la pointeuse sur la connexion ouverte (`live_capture`) et inséré dans `punches` ; `devices.last_event_at` est mis à jour | `worker/sync.py`, un thread par pointeuse | immédiat |
| 2. Badge de présence : l'agent est mis en file, puis sa journée (veille et jour même, pour les horaires de nuit) est recalculée. Un seul appel `php bin/compute.php --employee-device-id=…` au plus toutes les 5 s pour tous les agents en attente | thread « recalcul » du worker | 0 à 5 s |
| 3. Les pages ouvertes interrogent `GET /api/direct` toutes les 5 s (60 s si l'onglet est caché) | `public/assets/js/app.js` | 0 à 5 s |

Sur les pages **Tableau de bord**, **Présence** (journée d'aujourd'hui), **Agents**, **Calendrier** (période qui contient aujourd'hui) et **Pointeuses** :

- indicateurs mis à jour avec une courte animation, nouveaux pointages ajoutés en haut des « Derniers pointages » avec un surlignage, retards du jour et pastilles des pointeuses à jour ;
- sur la page Présence, les lignes concernées (arrivée, départ, présence, retard, heures sup, statut) sont mises à jour ; si un nouvel agent devient concerné par les filtres, une barre « Nouveaux pointages : actualiser » s'affiche ;
- sur le calendrier, la grille est relue depuis le serveur (au plus toutes les 20 s) quand un agent affiché a badgé, et le temps des journées en cours avance chaque 30 s ;
- les badges arrivent aussi dans la cloche des notifications (en haut à droite, toutes les pages) ;
- l'indicateur « En direct · à jour il y a X s » de la barre du haut passe en gris « Hors ligne » si les appels échouent deux fois de suite (nouvel essai après 2 s, 10 s, 30 s puis 60 s). Un échec pendant que la page est cachée (téléphone verrouillé) n'affiche rien ; au retour sur la page (déverrouillage, onglet visible, réseau revenu), la requête restée bloquée est abandonnée et le direct repart aussitôt. Si la session a expiré, retour à la page de connexion.
- si une page est demandée sans réseau, le service worker réessaie deux fois avant d'afficher la page hors ligne, qui se recharge d'elle-même dès le retour du réseau (`public/assets/js/offline.js`).

L'application reste toujours en **thème clair**, même quand le téléphone est en mode sombre. Les navigateurs mobiles (Chrome « assombrir les sites », Samsung Internet…) n'assombrissent de force que les pages qui ne gèrent pas le mode sombre et ignorent souvent `only light` : la racine annonce donc `color-scheme: light dark` (balise meta et `:root`), et `body` est rendu en `only light` avec un fond explicite sur `html`.

`/api/direct` (droit `board` : tous les rôles de la plateforme, périmètre du manager appliqué) renvoie du JSON, jamais mis en cache : nouveaux pointages depuis `since_punch` (30 au plus), journées du jour modifiées depuis `since_day`, indicateurs (les mêmes que le tableau de bord, service `DashboardStats`), retards du jour et état des pointeuses. Il libère le verrou de session dès l'utilisateur vérifié, pour ne pas bloquer les autres onglets, et répond en quelques millisecondes (index sur `punches.id` et `attendance_days.computed_at`). `computed_at` ne change que si le résultat d'une journée change : le calcul global des 5 minutes ne renvoie donc pas toutes les lignes aux pages ouvertes.

---

## Ce que fait le module Parc automobile (module 1 : demande et octroi de véhicule)

Cahier des charges : [`docs/Cahier_des_charges_Fleet.pdf`](docs/Cahier_des_charges_Fleet.pdf) (sections 2, 3 et 7). Aucun véhicule ne sort sans demande validée et affectée.

- **Organisation** (Administration › Départements) : chaque département est une **direction** ou un **service**, avec un **responsable (N+1)** choisi parmi les employés. Les employés sont rattachés à leur service (fiche, carte ou action groupée de la liste des employés).
- **Comptes** (Administration › Utilisateurs) : un compte peut être **relié à une fiche employé** (obligatoire pour demander un véhicule) et reçoit un **rôle Parc automobile**. Le rôle de base **« Employé »** donne accès au seul Parc automobile (demandeurs et chauffeurs sans accès à la présence).
- **Demande** (`/flotte/demandes/nouvelle`, web et téléphone) : motif, destination, mission hors ville, départ et retour, passagers, chauffeur souhaité ou « je conduis », type de véhicule, **ordre de mission** (PDF ou image, 5 Mo au plus, stocké hors du dossier public ; obligatoire selon le paramètre), urgence motivée. Le demandeur suit le statut, voit les étapes et l'historique, **annule** tant que la mission n'a pas commencé et **évalue la course** (ponctualité, conduite, commentaire).
- **Circuit de validation** : le **N+1** (responsable du service ; si le demandeur est lui-même responsable ou si personne n'est désigné, on remonte l'arborescence) valide d'abord ; la **direction** valide ensuite si la mission est **hors ville**, dure **plus de 2 jours** ou touche un **week-end** (seuils dans Parc automobile › Paramètres). Sans N+1, la demande va directement à la direction. **Refus avec motif obligatoire**, renvoyé au demandeur. Personne ne valide sa propre demande. Page **À valider** pour les validateurs.
- **Demande urgente** : le gestionnaire peut **affecter avant la fin de la validation** ; la validation se fait **a posteriori** et reste tracée (filtre « Urgences à régulariser »). Un refus a posteriori libère le véhicule si la mission n'a pas commencé.
- **Affectation** (gestionnaire de flotte) : frise des **véhicules et des chauffeurs** sur la période de la demande (réservations existantes, créneau demandé), avec les **conflits** et indisponibilités : véhicule en panne ou en entretien, assurance ou contrôle technique expiré, permis expiré, chauffeur non autorisé sur le véhicule (bloquant) ; places, catégorie de permis, document expirant pendant la mission (avertissement). L'enregistrement se fait **en transaction, avec verrou** sur la demande, le véhicule et le chauffeur : deux affectations simultanées du même véhicule ne peuvent pas réussir. **Planning** jour / semaine du parc.
- **Mission** (`/flotte/missions`, écran pour téléphone) : le chauffeur (ou le demandeur qui conduit) **démarre** puis **clôture** la mission avec le **kilométrage**, le **niveau de carburant**, la **photo du compteur** (réduite dans le téléphone) et l'**état du véhicule**. Le kilométrage de retour doit être supérieur à celui du départ. Un véhicule déclaré en panne à la clôture passe « en panne » et les gestionnaires sont prévenus.
- **Sans réseau** : la saisie de démarrage ou de clôture est **gardée dans le téléphone** (IndexedDB) puis envoyée dans l'ordre **dès le retour du réseau** (avec l'heure réelle de la saisie ; un double envoi est ignoré). L'écran de mission déjà ouvert une fois reste consultable hors réseau (service worker). Une pastille dans l'en-tête indique les saisies en attente.
- **Référentiels** : **véhicules** (immatriculation, marque, modèle, année, type, places, carburant, réservoir, consommation de référence en L/100 km, kilométrage, état, identifiant du tracker GPS pour le module 2), **documents** (assurance, contrôle technique : date d'expiration, fichier, **alerte 30 jours avant**), **chauffeurs** (employé, permis, catégories, expiration avec alerte, véhicules autorisés, note moyenne).
- **Notifications** : dans la **cloche** de l'en-tête (au-dessus des badges du jour) et **par e-mail** si un serveur SMTP est configuré dans `.env` (sinon rien n'est envoyé) : demande reçue, approuvée, refusée, véhicule affecté, **rappel 1 h avant le départ**, mission terminée, panne, documents et permis à renouveler. Les e-mails, rappels et alertes sont envoyés par le worker (`bin/fleet-tasks.php`, toutes les 60 s).
- **Historique complet** de chaque demande (qui a fait quoi et quand), en plus du journal d'activité.
- **Tableau de bord** (`/flotte`) : véhicules disponibles, en mission, en panne, en entretien ; demandes en attente par étape ; **délais moyens création → validation → affectation** (90 jours) ; départs du jour ; documents et permis à renouveler ; kilomètres et note du mois.

### Rôles du parc automobile

Ils s'ajoutent au rôle de la plateforme (champ « Rôle Parc automobile » du compte).

| Rôle Parc automobile | Ce qu'il peut faire |
|---|---|
| Demandeur (par défaut) | Créer une demande, la suivre, l'annuler, évaluer la course (compte relié à une fiche employé) |
| Validateur (N+1) | Valider ou refuser les demandes de son équipe ; tout responsable désigné dans Départements valide aussi, quel que soit son rôle |
| Gestionnaire de flotte | Affecter véhicule et chauffeur, gérer véhicules, documents et chauffeurs, tableau de bord, registre, planning |
| Chauffeur | Voir ses missions, les démarrer et les clôturer (téléphone, avec ou sans réseau) |
| Direction | Valider les demandes exceptionnelles (hors ville, longues, week-end, sans N+1), tableau de bord, registre et planning en consultation |

L'**administrateur** a les droits du gestionnaire et règle les **paramètres** du circuit ; le **CEO** consulte le tableau de bord, le registre, le planning, les véhicules et les chauffeurs.

Les règles du circuit et des conflits sont dans `app/Modules/Fleet/Services/FleetWorkflow.php` et `Availability.php`, testées sans base par `php tests/FleetWorkflowTest.php`. Données d'exemple (comptes, véhicules, demandes à tous les stades) : `database/seed_fleet_demo.sql`, **à ne jamais charger en production**.

---

## Déploiement : procédure à suivre (assistant IA ou humain)

Cette section se suit **telle quelle, étape par étape**, sans improviser. Chaque étape donne la commande, le résultat attendu et quoi faire si le résultat est différent. En cas de doute : **s'arrêter et demander** à Beni, ne jamais « réparer » au hasard.

### Contexte fixe

| Élément | Valeur |
|---|---|
| Serveur | VM Ubuntu 26.04, `10.169.153.144` (carte `ens18`, VLAN 102) ; utilisateur `ubuntu01` ; accès `ssh ubuntu01@10.169.153.144` |
| Accès aux pointeuses | carte `ens19` = `10.0.4.249/24`, **sans passerelle** (VLAN 3602) ; pointeuses 10.0.4.18, .20, .7, .15, .5, .4 sur TCP 4370 |
| Dossier du projet | `~/liquid-attendance` (toutes les commandes se lancent depuis ce dossier) |
| Docker | toujours avec `sudo` ; projet compose `liquid-attendance` |
| Conteneurs | `la-app` (HTTPS 8443), `la-worker` (pointeuses, calculs, sauvegarde), `la-db` (MariaDB, aucun port publié), `la-phpmyadmin` (127.0.0.1:8090), `la-acme` (8088) |
| Base | `liquid_attendance` ; mot de passe root disponible dans le conteneur : `$MARIADB_ROOT_PASSWORD` |
| Données | `./data` (`mariadb`, `storage`, `backups`, `certs`, `acme`) : **jamais dans git, jamais supprimées** |
| Configuration | `.env` : **uniquement sur la VM**, jamais dans git ; modèle : `.env.example` |
| Code | dépôt `github.com/Nyombi1997/liquid-attendance`, branche `main`. Beni pousse lui-même ; la VM ne fait que `git pull` |
| Voisin | ELK (`~/elk-mikrotik-deploy`, conteneurs ELK, `/data/elasticsearch`) tourne sur la même VM : **ne jamais y toucher** |

### Interdits absolus

- `docker system prune --volumes`, `docker volume rm`, `docker compose down -v`, `rm -rf data` ou toute suppression dans `./data` (sauf anciennes sauvegardes de `~/sauvegardes-maj`).
- Arrêter, redémarrer ou modifier un conteneur ELK ; lancer `docker compose` ailleurs que dans `~/liquid-attendance`.
- Recharger `database/schema.sql` ou `database/seed.sql` sur une base existante ; charger `database/seed_fleet_demo.sql` (données fictives) en production.
- Modifier, commiter ou pousser du code depuis la VM (`git commit`, `git push`, `git reset --hard`, `git stash` sur des fichiers suivis) : signaler toute modification locale à Beni.
- Écrire sur les pointeuses (effacer pointages ou utilisateurs) : le worker les lit seulement, et règle seulement l'horloge.
- Toucher au réseau : routes de la VM (pas de route `10.0.4.0/24 via 10.169.153.244`, supprimée le 14 oct. 2026), MikroTik, switchs, pare-feu. Toute commande réseau se propose à Beni, elle ne se lance pas.
- Utiliser un nouveau port sans vérifier qu'il est libre (`sudo ss -tulpn`) ; ne jamais utiliser 7080.
- Lancer `python3 worker/sync.py` sans l'option `--sauvegarde` à côté du worker en marche (deux connexions aux pointeuses).

### A. Mise à jour du serveur (cas habituel)

À utiliser quand `~/liquid-attendance/data/mariadb` existe déjà (`test -d ~/liquid-attendance/data/mariadb && echo existe`). Sinon, voir « Installation sur la VM » plus bas.

**Étape 1 : contrôles avant de commencer.**

```bash
cd ~/liquid-attendance
git status --short                      # attendu : rien (aucune modification locale)
git branch --show-current               # attendu : main
sudo docker compose ps                  # attendu : la-app, la-worker, la-db… « Up » (la-db « healthy »)
df -h / | tail -1                       # attendu : au moins 2 Go libres
ip route get 10.0.4.18                  # attendu : … dev ens19 src 10.0.4.249
```

Si `git status` affiche des fichiers, si la branche n'est pas `main` ou s'il reste moins de 2 Go : **s'arrêter et signaler**. Un conteneur arrêté n'empêche pas la mise à jour, mais le noter dans le compte rendu.

**Étape 2 : sauvegarde de sécurité de la base (obligatoire).**

Cette commande marche quelle que soit la version du code (elle ne passe pas par le worker) :

```bash
mkdir -p ~/sauvegardes-maj
sudo docker compose exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction liquid_attendance' \
  | gzip > ~/sauvegardes-maj/avant-maj_$(date +%F_%H%M).sql.gz
gzip -t ~/sauvegardes-maj/avant-maj_*.sql.gz && ls -lh ~/sauvegardes-maj/ | tail -3
```

Attendu : `gzip -t` silencieux et un fichier de plus de quelques Ko. Sinon : **s'arrêter**, ne pas mettre à jour sans sauvegarde.

**Étape 3 : noter la version en place (pour un retour arrière).**

```bash
git rev-parse --short HEAD              # noter cette valeur : ANCIEN
```

**Étape 4 : récupérer le code.**

```bash
git pull --ff-only
git log --oneline ANCIEN..HEAD          # remplacer ANCIEN : liste des changements reçus
git diff --stat ANCIEN..HEAD -- database/migrations docker-compose.yml docker .env.example
```

Si `git pull --ff-only` échoue (historique divergent) : **s'arrêter et signaler**, ne pas forcer. La dernière commande montre ce qui demande une attention : nouvelles migrations, nouveaux réglages, images Docker modifiées.

**Étape 5 : nouveaux réglages du `.env`.**

```bash
comm -23 <(grep -oE '^[A-Z_]+' .env.example | sort -u) <(grep -oE '^[A-Z_]+' .env | sort -u)
```

Affiche les réglages présents dans `.env.example` mais absents du `.env`. Ils ont tous une valeur par défaut dans le code : **ne rien ajouter** sans instruction, sauf si le message de commit ou Beni le demande. Ne jamais afficher le contenu du `.env` (mots de passe) dans un compte rendu.

**Étape 6 : migrations de la base (avant de reconstruire).**

Toutes les migrations sont rejouables sans risque : on les applique **toutes, dans l'ordre**, à chaque mise à jour. La boucle s'arrête à la première erreur.

```bash
mkdir -p data/backups
for f in $(ls database/migrations/*.sql | sort); do
  echo "== $f"
  sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < "$f" \
    || { echo "ÉCHEC : $f"; break; }
done
```

Attendu : une ligne `== …` par fichier et aucun `ÉCHEC`. En cas d'échec : **ne pas reconstruire**, ne pas modifier la migration, copier le message d'erreur et le signaler (l'application en place continue de tourner).

**Étape 7 : reconstruire et relancer l'application et le worker.**

```bash
sudo docker compose up -d --build app worker
```

Ne relance que `la-app` et `la-worker` ; la base n'est pas redémarrée. Si `docker-compose.yml` a changé pour `db`, `phpmyadmin` ou `acme` (étape 4), lancer ensuite `sudo docker compose up -d` (sans `down`).

**Étape 8 : vérifications.**

```bash
sudo docker compose ps                                                     # tous « Up », la-db « healthy »
curl -sk -o /dev/null -w '%{http_code}\n' https://127.0.0.1:8443/connexion  # attendu : 200
sleep 90; sudo docker compose logs --since 3m worker | grep -E "synchro complète|ERREUR" | tail -20
```

Attendu dans le journal du worker : une ligne « synchro complète » pour chaque pointeuse d'étage (10.0.4.18, .20, .7, .15). Des erreurs sur 10.0.4.4 et 10.0.4.5 (F16, NOC et IT Room) sont connues et ne bloquent rien. Des erreurs « base de données » ou sur les pointeuses d'étage : passer au retour arrière ou signaler.

Contrôle dans le navigateur (compte admin) : https://10.169.153.144:8443/etat-du-service : service « En marche », pointeuses d'étage « en écoute ».

**Étape 9 : compte rendu à Beni.** Indiquer : version avant (ANCIEN) et après (`git rev-parse --short HEAD`), nom du fichier de sauvegarde de l'étape 2, migrations appliquées, résultat de chaque vérification de l'étape 8, réglages `.env` manquants signalés à l'étape 5, et toute anomalie.

### B. Retour arrière

Les migrations ne font qu'ajouter (colonnes, tables, index) : l'ancien code fonctionne avec la base migrée. On revient donc d'abord sur **le code seul**.

```bash
cd ~/liquid-attendance
git checkout ANCIEN                     # version notée à l'étape 3 (« detached HEAD » est normal)
sudo docker compose up -d --build app worker
```

Puis refaire les vérifications de l'étape 8 et prévenir Beni. Pour revenir ensuite sur la branche : `git checkout main`.

Restaurer la base de l'étape 2 **seulement si Beni le demande** (les badges reçus depuis la sauvegarde seront repris des pointeuses à la synchro suivante, mais les saisies faites dans la plateforme depuis seront perdues) :

```bash
sudo docker compose stop app worker
gunzip -c ~/sauvegardes-maj/avant-maj_AAAA-MM-JJ_HHMM.sql.gz \
  | sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance'
sudo docker compose start app worker
```

### C. Changer de serveur (migration vers une nouvelle machine)

Seulement sur instruction de Beni. Le nouveau serveur doit avoir : Docker et le plugin compose, accès au VLAN 3602 (carte dédiée, adresse en 10.0.4.x **sans passerelle** ; à demander à l'équipe réseau, ne pas le configurer soi-même) et les ports 8443, 8088 et 8090 libres (`sudo ss -tulpn`).

1. **Ancien serveur** : étapes 1 et 2 de la partie A (sauvegarde), puis arrêter la plateforme pour figer les données : `sudo docker compose stop app worker` (la base reste en marche) ; refaire l'étape 2 pour avoir une sauvegarde finale.
2. **Copier** vers le nouveau serveur, par `scp` : la sauvegarde finale, le fichier `.env`, et les dossiers `data/storage` (photos et fichiers du parc) et `data/certs`. Ne pas copier `data/mariadb` (les fichiers bruts de la base dépendent de la version : on passe par la sauvegarde).
3. **Nouveau serveur** : `git clone https://github.com/Nyombi1997/liquid-attendance.git ~/liquid-attendance`, y placer `.env` et les dossiers `data/storage`, `data/certs`, puis `mkdir -p data/backups`.
4. Démarrer la base seule et attendre « healthy » : `sudo docker compose up -d db` puis `sudo docker compose ps db`. Elle se crée vide avec `schema.sql` et `seed.sql`.
5. Charger la sauvegarde (elle remplace les tables créées à l'étape 4) : commande `gunzip -c … | … mariadb …` de la partie B.
6. Appliquer les migrations (étape 6 de la partie A), puis `sudo docker compose up -d --build`, puis les vérifications de l'étape 8.
7. **Ancien serveur** : laisser la plateforme arrêtée (deux workers connectés aux mêmes pointeuses se gênent). Ne supprimer ses données qu'après accord écrit de Beni.
8. Mettre à jour l'adresse du serveur dans `README.md`, `CLAUDE.md` et `docs/CADRAGE.md` (à faire sur le poste de développement, pas sur le serveur).

---

## Installation sur la VM (10.169.153.144)

Première installation seulement (aucun dossier `data/mariadb`). Pour une mise à jour, voir la partie A ci-dessus.

La plateforme cohabite avec ELK sans réutiliser aucun de ses ports :

| Port | Usage | Exposé |
|---|---|---|
| 8443/tcp | Application HTTPS | VM ; publication Internet par la DMZ (en attente) |
| 8088/tcp | Validation du certificat Let's Encrypt | VM ; port public 80 → 8088 par la DMZ (en attente) |
| 8090/tcp | phpMyAdmin | `127.0.0.1` uniquement (tunnel SSH) |
| — | MariaDB | Réseau Docker interne, aucun port publié |

### 0. Prérequis réseau : accès aux pointeuses

Depuis le 14 oct. 2026, la VM joint les pointeuses directement par sa deuxième carte réseau (`ens19` = 10.0.4.249/24, **sans passerelle**, VLAN 3602 ; détails dans `docs/CADRAGE.md`). Aucune route ni règle du MikroTik n'est nécessaire. Vérifier :

```bash
ip -br addr show ens19                 # ens19 UP 10.0.4.249/24
ip route | grep 10.0.4                 # une seule ligne : 10.0.4.0/24 dev ens19 … src 10.0.4.249
ip route get 10.0.4.18                 # … dev ens19 src 10.0.4.249
for ip in 18 20 7 15 5 4; do nc -zv -w 3 10.0.4.$ip 4370; done
```

### 1. Récupérer le code et configurer

```bash
cd ~
git clone https://github.com/Nyombi1997/liquid-attendance.git
cd liquid-attendance
cp .env.example .env
openssl rand -base64 24   # à lancer deux fois : un mot de passe pour DB_PASSWORD, un pour DB_ROOT_PASSWORD
nano .env
```

Pour les e-mails du parc automobile, renseigner aussi `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASSWORD` et `SMTP_FROM` (laisser `SMTP_HOST` vide : notifications dans l'application seulement). La VM doit pouvoir joindre ce serveur (port 587 ou 465 en sortie).

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

### 4. Publier sur Internet (DMZ, en attente)

La publication passera par une **DMZ (VLAN 3604)** mise en place par l'équipe sécurité, avec Kibana (5601), avis customers (7080) et syslog (514). Le sous-réseau, l'adresse de la VM dans la DMZ et les règles de publication sont **encore à fournir** : rien n'est à configurer d'ici là. Il faudra publier le port 8443 et rediriger le port public 80 vers 8088 (validation Let's Encrypt).

Avant la publication, obtenir aussi la réservation DHCP de la VM (MAC `bc:24:11:ba:ee:70` → 10.169.153.144).

### 5. Certificat HTTPS gratuit pour l'adresse IP (Let's Encrypt)

À faire une fois la DMZ en place ; remplacer 41.174.155.178 par l'adresse publique retenue si elle change. Let's Encrypt délivre des certificats pour une adresse IP avec le profil `shortlived` (validité d'environ 6 jours). Le conteneur `acme` les renouvelle automatiquement, et l'application recharge le nouveau certificat dans les 10 minutes.

```bash
sudo docker compose exec acme acme.sh --register-account -m ton-adresse@email --server letsencrypt
sudo docker compose exec acme acme.sh --issue --server letsencrypt -d 41.174.155.178 \
     --standalone --httpport 8088 --cert-profile shortlived --days 3
sudo docker compose exec acme acme.sh --install-cert -d 41.174.155.178 --ecc \
     --key-file /certs/privkey.pem --fullchain-file /certs/fullchain.pem
```

Vérifier ensuite **https://41.174.155.178:8443** : plus d'avertissement, et le téléphone propose « Installer l'application ».

> Ne pas retirer la redirection du port public 80 vers 8088 : elle sert à chaque renouvellement.

---

## Utilisation courante

| Besoin | Commande |
|---|---|
| Accéder à phpMyAdmin | depuis ton poste : `ssh -L 8090:127.0.0.1:8090 ubuntu01@10.169.153.144`, puis http://localhost:8090 |
| Voir la synchronisation | `sudo docker compose logs -f worker`, ou Administration › État du service |
| Sauvegarder la base tout de suite | `sudo docker compose exec worker python3 worker/sync.py --sauvegarde` (fichier dans `./data/backups`) |
| Tâches du parc automobile (rappels, alertes, e-mails) à la main | `sudo docker compose exec worker php bin/fleet-tasks.php` (lancé seul toutes les 60 s par le worker) |
| Recalculer une période | page Horaires > « Recalculer une période », ou `sudo docker compose exec worker php bin/compute.php --from=2026-01-01 --to=2026-10-31` |
| Mettre à jour | procédure complète : « Déploiement : procédure à suivre », partie A (sauvegarde, `git pull --ff-only`, migrations, `up -d --build app worker`, vérifications) |
| Réinitialiser un mot de passe admin | `sudo docker compose exec -it app php bin/create-admin.php <identifiant>` |
| Arrêter la plateforme (ELK n'est pas touché) | `sudo docker compose down` |

### Appliquer une migration sur une base existante

Dans une mise à jour, c'est l'étape 6 de la partie A (boucle sur tous les fichiers). `database/schema.sql` n'est chargé qu'au tout premier démarrage (volume vide). Les changements de structure arrivent ensuite sous forme de fichiers `database/migrations/NNN_*.sql`, à appliquer une fois, dans l'ordre, après le `git pull` et **avant** de reconstruire les conteneurs (le nouveau code utilise les nouvelles colonnes) :

```bash
sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/001_live.sql
sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/002_agents.sql
sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/003_identifiants.sql
sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/004_calendrier.sql
sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance' < database/migrations/005_flotte.sql
```

Les migrations peuvent être relancées sans risque (`ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`). Une nouvelle installation n'en a pas besoin.

| Migration | Contenu |
|---|---|
| `001_live.sql` | Mode direct : `devices.last_event_at` (dernier badge reçu), `devices.live_since` (écoute en direct active), index sur `attendance_days.computed_at` |
| `002_agents.sql` | Page Agents : table `departments`, rôles `ceo` et `manager` + `users.department_id`, colonnes `employees.job_title`, `department_id`, `photo_updated_at`, `work_status*` |
| `003_identifiants.sql` | Identifiants insensibles à la casse : comptes existants passés en minuscules, collation `utf8mb4_unicode_ci` sur `users.username` |
| `004_calendrier.sql` | Calendrier : table `work_statuses` (historique des disponibilités avec leur période), reprise des statuts en cours |
| `005_flotte.sql` | Parc automobile : `departments.kind` et `head_employee_id` (N+1), rôle `employe`, `users.employee_id` et `fleet_role`, tables `notifications`, `fleet_vehicles`, `fleet_vehicle_documents`, `fleet_drivers`, `fleet_driver_vehicles`, `fleet_requests`, `fleet_request_events`, `fleet_missions`, `fleet_ratings`, paramètres `fleet_*` |

Les photos des agents sont enregistrées dans `./data/storage/photos` et les fichiers du parc automobile (ordres de mission, documents des véhicules, photos des compteurs) dans `./data/storage/fleet` (volume monté dans le conteneur `app`, hors du dossier public) : ils sont conservés lors des mises à jour et inclus dans la sauvegarde de `./data`.

### Sauvegarde de la base

Automatique : chaque nuit à 02:00 (`BACKUP_AT`, `off` pour désactiver), le worker écrit `./data/backups/liquid_AAAA-MM-JJ.sql.gz` (`mariadb-dump --single-transaction`, compressé) et supprime les fichiers de plus de 14 jours (`BACKUP_KEEP_DAYS`). Résultat et erreurs : Administration › État du service, et `sudo docker compose logs worker | grep sauvegarde`. Copier régulièrement `./data/backups` hors de la VM.

Restaurer une sauvegarde (remplace le contenu de la base) :

```bash
sudo docker compose stop app worker
gunzip -c data/backups/liquid_2026-10-22.sql.gz \
  | sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" liquid_attendance'
sudo docker compose start app worker
```

Les données sont dans `./data` : ne jamais lancer `docker system prune --volumes`.

---

## Structure du code

```
app/
  Core/                    noyau MVC : routeur, base, vues, authentification, CSRF, journal
  Modules/
    Platform/              socle commun : connexion, profil, utilisateurs, départements (Services/Departments), état du service,
                           notifications des comptes (Services/Notifications), modules à venir
    Attendance/            module Présence
      Controllers/         une classe par écran ; AgentController (cartes, photos, statuts), CalendarController, LiveController (/api/direct, /api/notifications)
      Services/            DayCalculator (règles), ScheduleResolver, AttendanceComputer, DashboardStats (indicateurs), AgentStatus (pastilles des cartes),
                           AgentPhoto (photos), WorkStatuses (historique des disponibilités), Calendar (cases du calendrier)
      Views/
    Fleet/                 module Parc automobile
      Controllers/         Dashboard, Request (demandes, validation, registre), Assignment (affectation, planning),
                           Mission (écran chauffeur), Vehicle, Driver, Settings
      Services/            FleetWorkflow (circuit, testé), Availability (conflits, testé), Timeline (frise), Fleet (données, droits),
                           FleetNotify (destinataires), FleetFiles (fichiers hors de public/), FleetStats (tableau de bord)
      Views/
  Core/Mailer.php          envoi SMTP sans dépendance (utilisé par le worker)
  Views/                   gabarit commun, pages d'erreur
bin/                       compute.php (calcul), fleet-tasks.php (rappels, alertes, e-mails du parc), create-admin.php
config/config.php          configuration lue depuis .env
database/                  schema.sql, seed.sql (chargés au premier démarrage), migrations/, seed_fleet_demo.sql (démonstration, jamais en production)
docker/                    images app (Apache + HTTPS) et worker
public/                    point d'entrée, CSS, JS, PWA (manifest, service worker, icônes), assets/vendor/fontawesome (Font Awesome Free 6.7.2, servi localement)
storage/                   fichiers déposés (photos) ; en production : volume ./data/storage
worker/                    sync.py (connexion permanente aux pointeuses, calculs, battement de cœur, sauvegarde nocturne), run.sh (lancement)
tests/                     règles de calcul, cases du calendrier, circuit du parc automobile (PHP) ; worker avec un faux module zk (tests/fakezk, hors image Docker)
```

Le module Leave aura son dossier dans `app/Modules/` et réutilisera, comme Fleet, la table `employees`, les comptes et le gabarit.

### Sécurité

- HTTPS uniquement (port 8443), en-têtes HSTS et CSP stricte (aucun script ni style externe : Font Awesome est servi depuis `public/assets/vendor`).
- Photos hors du dossier public, servies par `/agents/{id}/photo` après contrôle du rôle et du département ; seuls des JPEG carrés de 128 à 1024 px et 1,5 Mo au plus sont acceptés.
- Parc automobile : ordres de mission et documents (PDF, JPEG, PNG, WebP contrôlés par leur contenu, 5 Mo au plus) et photos des compteurs (JPEG, 2 Mo) hors du dossier public, servis après contrôle des droits sur la demande ; PDF toujours téléchargés, jamais affichés dans la page. Transitions d'une demande verrouillées en base (pas de double validation ni de double réservation).
- Mots de passe hachés, 12 caractères minimum, 5 échecs en 15 minutes bloquent la connexion.
- Jeton CSRF sur chaque formulaire, cookie de session `Secure`, `HttpOnly`, `SameSite=Strict`.
- Rôles vérifiés côté serveur sur chaque route, périmètre des managers appliqué à chaque requête, journal de toutes les modifications.
- Pointeuses en lecture seule, sauf la remise à l'heure de l'horloge (au démarrage si l'écart dépasse 30 s, et chaque nuit) ; la VM les joint par sa carte dédiée au VLAN 3602 (`ens19`, sans passerelle).
