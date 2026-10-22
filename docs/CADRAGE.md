# Cadrage — Plateforme interne (Attendance, Fleet, Leave)

Mis à jour le 22 octobre 2026 · @Beni

## Comment utiliser ce document

Colle ce document au début d'un nouveau chat, ou ajoute-le aux connaissances d'un Projet Claude : l'assistant aura tout le contexte sans que tu aies à le réexpliquer. Mets-le à jour à chaque décision importante, pour qu'il reste la source de vérité du projet.

---

## État d'avancement

| Étape | État |
|---|---|
| Accès réseau VM → pointeuses | **Fait** le 9 oct. 2026 par le MikroTik ; **refait** après le 14 oct. 2026 : VM reliée directement au VLAN 3602 (2e carte réseau, ens19 = 10.0.4.249/24), sans routeur ni NAT |
| Base du projet `Nyombi1997/liquid-attendance` | **Fait** : Docker, MVC, base, synchronisation, module Présence complet |
| Pointages en temps réel, remise à l'heure nocturne | **Fait** le 9 oct. 2026, testé avec un faux module `zk` |
| Plateforme « en direct » (badge visible en moins de 10 s sans recharger : API `/api/direct`, notifications, indicateur « En direct ») | **Fait** le 9 oct. 2026, testé en local (faux `zk`, MariaDB, Chrome) ; **à valider sur la VM** avec les vraies pointeuses (migration `001_live.sql` à appliquer avant la reconstruction) |
| Droits des RH réduits, recherche avec propositions, affichage des mots de passe, identifiants insensibles à la casse | **Fait** le 12 oct. 2026, testé en local ; migration `003_identifiants.sql` à appliquer sur la VM |
| Calendrier de présence (jour / semaine / mois, temps en direct, raisons d'absence, absents en rouge), cloche des notifications (« 9+ »), Employés pour les managers, photo cliquable et modifiable depuis la fiche, menus RH et managers réduits | **Fait** le 12 oct. 2026, testé en local (MariaDB, Chrome 1280 et 390 px) ; migration `004_calendrier.sql` à appliquer sur la VM |
| Tableau de bord en camemberts (présents / absents, à l'heure / en retard, en %), filtres appliqués sans bouton, filtre « Présents (même sortis) », heures « 00h…23h » du calendrier, reprise du direct au déverrouillage du téléphone, thème clair forcé | **Fait** le 12 oct. 2026, testé en local (MariaDB, Chrome 1280 et 390 px) ; aucune migration |
| Page Pointages (`/pointages`, tous les rôles, périmètre du manager), derniers pointages cliquables vers la fiche de l'agent, bouton « Voir le rapport détaillé », correctif du mode sombre forcé des navigateurs mobiles (racine `light dark`, contenu clair) | **Fait** le 12 oct. 2026, testé en local (MariaDB, rôles admin / rh / ceo / manager) ; aucune migration ; mode sombre à confirmer sur le téléphone |
| Exploitation : remise à l'heure au démarrage (écart > 30 s), F16 en délai long et journal détaillé, tentatives toutes les 30 min après 5 échecs, battement de cœur du worker, sauvegarde nocturne de la base (02:00, `./data/backups`, 14 jours), page Administration › État du service | **Fait** le 22 oct. 2026, testé en local (faux `zk`, vrai `mariadb-dump` contre MariaDB 11.4, Chrome 1280 et 390 px) ; aucune migration |
| Déploiement sur la VM | À faire (voir README, étapes 1 à 3) |
| Réservation DHCP de la VM | À obtenir avant la publication |
| Publication Internet par la DMZ (VLAN 3604) + certificat Let's Encrypt | **En attente** : sous-réseau de la DMZ à fournir par l'équipe sécurité (README, étapes 4 et 5) |
| Sécuriser Kibana (liste d'IP ou login) | À faire |
| Parc automobile, module 1 « Demande et octroi de véhicule » : directions/services et N+1, rôle `employe` et rôles Parc automobile, véhicules (documents avec alerte 30 j), chauffeurs, demandes avec ordre de mission, circuit N+1 → direction, refus motivé, urgence validée a posteriori, affectation avec frise et verrou anti-double réservation, planning, écran mission sur téléphone avec saisie hors réseau, cloche + e-mails + rappel 1 h avant, évaluation, historique, tableau de bord | **Fait** le 20 oct. 2026, testé en local (MariaDB, Chrome 1280 et 390 px, hors réseau simulé, faux SMTP) ; migration `005_flotte.sql` à appliquer sur la VM ; SMTP à configurer dans `.env` |
| Parc automobile, modules 2 à 4 (GPS, alertes de mouvement, carburant) | À faire : trackers à choisir ; colonnes déjà prévues (`fleet_vehicles.tracker_id`, `ref_consumption`, `tank_liters`) |
| Module Congés | Priorité 3 |

---

## Infrastructure

### Changement du 14 octobre 2026 (équipe sécurité)

- Les VLAN **102 et 4040 ont été retirés du port `gi17`** (trunk switch 2 → MikroTik). Le MikroTik n'est plus joignable depuis le VLAN 102 et ne sert plus au chemin VM → pointeuses.
- La VM joint désormais les pointeuses **directement**, par une deuxième carte réseau sur le VLAN 3602.
- La publication Internet passera par une **DMZ, VLAN 3604** : voir « Publication Internet » ci-dessous (**en attente**).

### Accès VM → pointeuses (depuis le 14 oct. 2026)

```
VM ens19 10.0.4.249/24 (sans passerelle)
  └─ Proxmox net1 : pont DMZ_SWITCH sur nic1, tag 3602
       └─ câble sur gi27 du switch 2 (VLAN 3602 tagué)
            └─ gi24 → switch 1 gi1 → VLAN 3602 → pointeuses 10.0.4.x (passerelle 10.0.4.1 = switch 1)
```

- Même sous-réseau que les pointeuses : ni routeur, ni NAT. Les pointeuses voient la VM sous 10.0.4.249.
- **Pas de passerelle sur ens19** : le reste du trafic sort par ens18 (10.169.153.144, VLAN 102, passerelle 10.169.153.1).
- L'ancienne route `10.0.4.0/24 via 10.169.153.244` est **supprimée** : ne pas la remettre (le MikroTik n'est plus joignable depuis le VLAN 102).
- Les conteneurs Docker (la-worker) passent par l'hôte : rien à configurer côté Docker.
- Vérification sur la VM :

```bash
ip -br addr show ens19                 # ens19 UP 10.0.4.249/24
ip route | grep 10.0.4                 # une seule ligne : 10.0.4.0/24 dev ens19 … src 10.0.4.249
ip route get 10.0.4.18                 # … dev ens19 src 10.0.4.249
for ip in 18 20 7 15 5 4; do nc -zv -w 3 10.0.4.$ip 4370; done
```

### Publication Internet par la DMZ (VLAN 3604) — EN ATTENTE

- Services à publier : Kibana (5601), plateforme (8443, et 80 → 8088 pour la validation Let's Encrypt), avis customers (7080), syslog (514/udp).
- Sous-réseau de la DMZ, adresse de la VM dans la DMZ et règles de NAT : **à fournir par l'équipe sécurité**. Rien n'est à configurer sur la VM ni sur le MikroTik d'ici là.
- Les anciens NAT du MikroTik vers la VM (5601, 514/udp) passaient par `vlan102-elk` : ils n'ont plus d'usage depuis le 14 oct.

### Routeur MikroTik central

- `ether1` : Internet, 41.174.155.178/30, passerelle 41.174.155.177. Relié à `fa21` du switch 1 (VLAN 1514, accès). **Ne pas toucher.**
- `ether2` : trunk vers `gi17` du switch 2. Depuis le 14 oct. 2026, `gi17` ne porte plus les VLAN 102 et 4040.
- Anciennes interfaces `vlan102-elk` (10.169.153.244/24) et `vlan3602-zk` (10.0.4.250/24, ajoutée le 9 oct.), masquerade et filtre « VM → pointeuses » : plus utilisés par la VM ; leur nettoyage relève de l'équipe sécurité.
- Le MikroTik n'est plus joignable depuis le VLAN 102 (donc depuis la VM).
- Après toute modification d'une règle NAT UDP, vider les connexions suivies concernées (`/ip firewall connection remove …`).

### Switchs (Cisco Small Business, syntaxe SG/SF300)

- **Switch 1 `SW1-Core5THFR`** : porte le VLAN 3602 « vlan_FingerPrint » (tagué sur gi1-3 et autres). Il est aussi la **passerelle des pointeuses** (10.0.4.1). Relié au switch 2 par `gi1` ↔ `gi24`.
- **Switch 2 `LCD-Sw5thLevel`** : `gi17` (trunk) vers ether2 du MikroTik (VLAN 102 et 4040 retirés le 14 oct. 2026), `gi24` (trunk) vers le switch 1, `gi13` vers l'hôte Proxmox (VLAN 102), **`gi27` vers `nic1` de l'hôte Proxmox (pont `DMZ_SWITCH`, VLAN 3602 tagué)**, `gi25` vers LCD-SW01-SNEL-FIH (ne pas toucher).
- Toujours utiliser `switchport trunk allowed vlan add …` : sans `add`, la liste est remplacée et le VLAN 102 coupé.

### VM Ubuntu

- `ens18` : 10.169.153.144 (DHCP, MAC `bc:24:11:ba:ee:70`), passerelle 10.169.153.1 (VLAN 102). Utilisateur `ubuntu01`, Ubuntu 26.04.
- `ens19` : **10.0.4.249/24, sans passerelle** (VLAN 3602 des pointeuses, Proxmox `net1`).
- Réseau géré par NetworkManager (connexion `netplan-ens18` pour ens18).
- Docker : `sudo` nécessaire.
- ELK dans `~/elk-mikrotik-deploy`, données dans `/data/elasticsearch`. Ne jamais lancer `docker system prune --volumes` (nettoyage hebdomadaire sans volumes déjà en place).
- Sauvegarde nocturne de la base de la plateforme : `~/liquid-attendance/data/backups` (02:00, 14 jours gardés, par le worker).

### Pointeuses ZKTeco (VLAN 3602)

| IP | Lieu | Rôle | Usage | Modèle réel |
|---|---|---|---|---|
| 10.0.4.18 | 5e étage | Entrée | Présence | SF400/ID, n° BIMW224960722 |
| 10.0.4.20 | 5e étage | Sortie | Présence | SF400/ID, n° BIMW224960724 |
| 10.0.4.7 | 4e étage | Entrée | Présence | F18/ID, n° CQQC231160203 |
| 10.0.4.15 | 4e étage | Sortie | Présence | SF400/ID, n° BIMW224960682 |
| 10.0.4.5 | NOC | Entrée seule | Accès salle | ZKTeco (sortie par bouton poussoir) |
| 10.0.4.4 | IT Room | Entrée seule | Accès salle | ZKTeco (sortie par bouton poussoir) |

- Protocole : TCP 4370, sans mot de passe de communication (Comm Key 0), firmware 6.60.
- 173 utilisateurs sur chaque appareil. Le numéro d'utilisateur est un identifiant technique, pas le matricule RH.
- Les sorties sont beaucoup moins badgées que les entrées (environ 1 sur 10) : d'où la gestion des « sorties manquantes ».
- 10.0.4.3 : autre appareil du VLAN (marque différente), non utilisé.

### Ports

| Port | Usage | Où |
|---|---|---|
| 514/udp | Logs (Logstash) ; publication par la DMZ en attente | VM |
| 5601/tcp | Kibana ; publication par la DMZ en attente | VM |
| 9200, 9300, 5044, 5000, 9600/tcp | Elasticsearch, Logstash | VM |
| 80/tcp | Réservé au php-apache d'ELK (désactivé) | VM |
| 22/tcp | SSH | VM |
| 443, 7080, 7081/tcp | Ancien serveur 10.125.40.11 (7080 : avis customers, publication par la DMZ en attente) ; ne jamais utiliser 7080 sur la VM | Routeur |
| **8443/tcp** | **Plateforme (HTTPS)** ; publication par la DMZ en attente | VM |
| **8088/tcp** | **Validation Let's Encrypt** (public 80 → 8088 par la DMZ, en attente) | VM |
| **8090/tcp** | **phpMyAdmin**, 127.0.0.1 seulement (tunnel SSH) | VM |

---

## Le projet

Une plateforme web interne unique, avec une connexion commune, qui regroupe trois modules partageant la même liste d'employés.

| Module | Rôle | Priorité |
|---|---|---|
| Attendance (Présence) | Entrées et sorties à partir des ZKTeco, tendances | 1 — base livrée |
| Vehicle Fleet & Allocation | Demandes et octroi de véhicule (module 1 livré), puis GPS, alertes, carburant | 2 |
| Leave (Congés) | Demandes et suivi des congés | 3 |

### Stack retenue

| Couche | Choix |
|---|---|
| Back-end | PHP 8.3, MVC maison, un module par dossier (`app/Modules/…`) |
| Front-end | HTML, CSS, JavaScript sans dépendance externe ; PWA installable |
| Base | **MariaDB 11.4** + **phpMyAdmin** |
| Synchronisation | Service `worker` : Python/pyzk (bibliothèque testée sur nos appareils). Une connexion permanente par pointeuse : badges en temps réel (`live_capture`), synchro complète toutes les 30 min sur la même connexion, reconnexion progressive (10 s → 5 min, puis toutes les 30 min après 5 échecs de suite ; F16 : délai de 60 s et journal détaillé). Calcul en PHP (mêmes règles que l'application) : journée de l'agent recalculée après chaque badge de présence (un appel au plus toutes les 5 s pour tous les agents en attente), et calcul global toutes les 5 min dans un thread à part. Horloges remises à l'heure de la VM au démarrage (si l'écart dépasse 30 s) et chaque nuit à 03:00. Battement de cœur en base, sauvegarde nocturne de MariaDB (02:00, 14 jours), page Administration › État du service |
| Parc automobile | Module `app/Modules/Fleet`, même socle (connexion, gabarit, CSP, audit). Pas d'application native : la PWA sert aux demandeurs et aux chauffeurs (écrans pour téléphone, saisie hors réseau de la mission dans IndexedDB, envoi au retour du réseau). Tâches planifiées (rappels, alertes d'expiration, e-mails) dans le worker : `bin/fleet-tasks.php` toutes les 60 s. E-mails par SMTP (`.env`), sans envoi si non configuré |
| Mode direct | API JSON `GET /api/direct` interrogée toutes les 5 s par les pages ouvertes (60 s onglet caché) : nouveaux pointages, journées modifiées, indicateurs, état des pointeuses. Mise à jour de la page sans rechargement. Notifications : cloche en haut à droite (badges du jour non lus, « 9+ »), `GET /api/notifications` toutes les 15 s sur toutes les pages |
| Cache navigateur | Fichiers statiques versionnés par date de modification (`asset()`, `?v=`), cache d'un an ; service worker sans numéro de version à modifier |
| Serveur | Docker Compose sur la VM, projet `liquid-attendance`, réseau Docker séparé d'ELK |
| Accès | https://10.169.153.144:8443 en interne ; publication Internet par la DMZ (VLAN 3604) en attente |
| Certificat | Let's Encrypt pour l'adresse IP (profil `shortlived`, renouvelé automatiquement par acme.sh) |
| Dépôt | github.com/Nyombi1997/liquid-attendance |

---

## Décisions fonctionnelles

- **Nom des employés** : modifiable par un admin dans la plateforme uniquement ; les pointeuses ne sont jamais modifiées.
- **Seule écriture sur les pointeuses** : la remise à l'heure de l'horloge, chaque nuit à 03:00 (décidé le 9 oct. 2026), et au démarrage du service si l'écart dépasse 30 s (22 oct. 2026). Écart avant et après journalisé.
- **Sauvegarde** : chaque nuit à 02:00 par le worker (`mariadb-dump` compressé, `./data/backups`, 14 jours). Copier régulièrement ce dossier hors de la VM.
- **Sortie non badgée** : tant que le jour n'est pas fini, la journée est « en cours » et le temps de présence avance ; une entrée badgée après une sortie (retour au bureau) annule cette sortie. Ensuite, la journée est marquée « sortie manquante » et ne compte aucune heure ; un admin ou les RH saisissent l'heure de sortie (tracée, annulable).
- **Horaires** : saisis manuellement. Attribuables à tout le monde (horaire par défaut), à un groupe ou à un ou plusieurs agents, sur une période. Priorité agent > groupe > défaut. Horaires de nuit gérés.
- Paramètres d'un horaire : début, fin, jours travaillés, pause déduite, tolérance de retard, seuil des heures supplémentaires.
- **NOC et IT Room** : accès salle, historique séparé, non comptés dans la présence.
- **Rôles** (révisés le 12 oct. 2026) : Administrateur (tout, seul à modifier fiches, employés, départements, groupes, horaires, corrections), RH (tableau de bord, Agents avec mise à jour de la disponibilité uniquement et rapport Excel, Calendrier, Employés en consultation), Direction/CEO (tout voir sauf le calendrier, rien modifier), Manager (son département : Agents, tableau de bord, présence du jour, Calendrier, Employés), Lecteur (consultation et export), Employé (ajouté le 20 oct. 2026 : parc automobile seulement). RH et managers ne voient ni les sorties manquantes ni les rubriques de consultation ; tous les comptes ont accès au parc automobile selon leur rôle Parc automobile.
- **Parc automobile** (décidé le 20 oct. 2026, cahier des charges `docs/Cahier_des_charges_Fleet.pdf`) :
  - pas d'applications natives Android/iOS : PWA existante, écrans adaptés au téléphone, saisie hors réseau du démarrage et de la clôture de mission ;
  - interface en français, anglais plus tard ;
  - organisation : départements = directions ou services, un responsable (N+1) par département, chaque employé rattaché à son service ; un compte peut être relié à une fiche employé ; rôle de base `employe` pour les comptes qui n'utilisent que le parc ;
  - rôles Parc automobile (en plus du rôle de la plateforme) : demandeur, validateur, gestionnaire de flotte, chauffeur, direction ; administrateur = gestionnaire + paramètres ;
  - circuit : N+1 puis direction si hors ville, plus de 2 jours ou week-end (seuils modifiables : Parc automobile › Paramètres) ; sans N+1, la direction directement ; refus motivé ; personne ne valide sa propre demande ; urgence : affectation avant validation, validation a posteriori tracée ;
  - ordre de mission joint (PDF ou image, 5 Mo) obligatoire pour les missions hors ville (paramètre) ; fichiers hors du dossier public ;
  - affectation bloquée en cas de conflit, de panne ou d'entretien, d'assurance, de contrôle technique ou de permis expiré, de chauffeur non autorisé ;
  - hors périmètre du module 1 : GPS, alertes de mouvement, carburant (seules les colonnes qui en dépendront sont prévues).
- **Identifiants** : insensibles à la casse, enregistrés en minuscules (le mot de passe, lui, reste sensible à la casse).
- Horaire par défaut provisoire : 08:00–17:00, lundi–vendredi, pause 60 min, tolérance 15 min, heures sup au-delà de 30 min. **À ajuster.**

## Design

- Couleurs : **#273c88** (principale) et **#c8187d** (accent).
- Style des maquettes de référence : menu latéral coloré, cartes blanches, tableaux aérés, indicateurs en haut du tableau de bord.
- Boutons et champs : coins arrondis à 5 px maximum. Interface en français.
- Thème clair uniquement, même si le téléphone est en mode sombre.

## Sécurité

- HTTPS obligatoire, jamais d'accès sans authentification.
- Kibana est toujours ouvert sans mot de passe : à sécuriser (login, ou filtrage dans la DMZ) avant sa republication.
- Les règles du pare-feu MikroTik ont toutes `log=yes`, ce qui gonfle les données envoyées à ELK : à limiter aux règles de drop.
- Anciennes règles du MikroTik pour la VM (NAT 5601 et 514, masquerade et filtre VM → pointeuses, règles input 0 et 1 du port 5601) : sans usage depuis le 14 oct., à retirer par l'équipe sécurité.

## Questions ouvertes

- **DMZ (VLAN 3604)** : sous-réseau, adresse de la VM, règles de publication (5601, 8443, 80 → 8088, 7080, 514/udp) : à fournir par l'équipe sécurité.

- Horaires réels à saisir, et groupes à créer (étages, NOC, équipes).
- Faut-il intégrer l'historique antérieur à 2026 ? (réglage `history_start_date`, puis recalcul)
- Logo officiel à fournir pour remplacer le logo provisoire (`public/assets/img/`).
- Parc automobile : nombre de véhicules et de sites ; trackers GPS installés ou à acheter (protocole, API) ; serveur SMTP à utiliser pour les e-mails (et ouverture du port 587 ou 465 en sortie de la VM) ; SMS optionnel ; budget et date de mise en service ; liste des responsables (N+1) et des membres de la direction à saisir.
