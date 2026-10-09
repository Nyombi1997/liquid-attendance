# Cadrage — Plateforme interne (Attendance, Fleet, Leave)

Mis à jour le 9 octobre 2026 · @Beni

## Comment utiliser ce document

Colle ce document au début d'un nouveau chat, ou ajoute-le aux connaissances d'un Projet Claude : l'assistant aura tout le contexte sans que tu aies à le réexpliquer. Mets-le à jour à chaque décision importante, pour qu'il reste la source de vérité du projet.

---

## État d'avancement

| Étape | État |
|---|---|
| Accès réseau VM → pointeuses | **Fait** le 9 oct. 2026 (VLAN 3602, testé : TCP 4370 OK sur les 4 pointeuses d'étage) |
| Base du projet `Nyombi1997/liquid-attendance` | **Fait** : Docker, MVC, base, synchronisation, module Présence complet |
| Pointages en temps réel, remise à l'heure nocturne, rafraîchissement auto | **Fait** le 9 oct. 2026, testé avec un faux module `zk` ; **à valider sur la VM** avec les vraies pointeuses (migration `001_last_event_at.sql` à appliquer) |
| Déploiement sur la VM | À faire (voir README, étapes 1 à 3) |
| Réservation DHCP de la VM | À obtenir avant la publication |
| NAT 8443 et 80 + certificat Let's Encrypt | À faire après le déploiement (README, étapes 4 et 5) |
| Sécuriser Kibana (liste d'IP ou login) | À faire |
| Module Parc automobile | Priorité 2 |
| Module Congés | Priorité 3 |

---

## Infrastructure

### Routeur MikroTik central

- `ether1` : Internet, 41.174.155.178/30, passerelle 41.174.155.177. Relié à `fa21` du switch 1 (VLAN 1514, accès). **Ne pas toucher.**
- `ether2` : trunk vers `gi17` du switch 2, dans `bridge1` (vlan-filtering=yes). VLAN tagués : 102, 4040, **3602**. Le VLAN 1 non tagué correspond au VLAN natif 4040 côté switch (ancien réseau 10.125.40.0/24).
- `vlan102-elk` : 10.169.153.244/24 (réseau de la VM).
- `vlan3602-zk` : **10.0.4.250/24** (réseau des pointeuses), ajouté le 9 oct. 2026.
- Après toute modification d'une règle NAT UDP, vider les connexions suivies concernées (`/ip firewall connection remove …`).

### Switchs (Cisco Small Business, syntaxe SG/SF300)

- **Switch 1 `SW1-Core5THFR`** : porte le VLAN 3602 « vlan_FingerPrint » (tagué sur gi1-3 et autres). Il est aussi la **passerelle des pointeuses** (10.0.4.1). Relié au switch 2 par `gi1` ↔ `gi24`.
- **Switch 2 `LCD-Sw5thLevel`** : `gi17` (trunk, natif 4040) vers ether2 du MikroTik, `gi24` (trunk) vers le switch 1, `gi13` vers l'hôte Proxmox (VLAN 102). VLAN 3602 ajouté sur gi17 et gi24 le 9 oct. 2026.
- Toujours utiliser `switchport trunk allowed vlan add …` : sans `add`, la liste est remplacée et le VLAN 102 coupé.

### VM Ubuntu

- 10.169.153.144 (DHCP, MAC `bc:24:11:ba:ee:70`), passerelle 10.169.153.1, utilisateur `ubuntu01`, Ubuntu 26.04.
- Réseau géré par NetworkManager (connexion `netplan-ens18`).
- Route permanente ajoutée : `10.0.4.0/24 via 10.169.153.244`.
- Docker : `sudo` nécessaire.
- ELK dans `~/elk-mikrotik-deploy`, données dans `/data/elasticsearch`. Ne jamais lancer `docker system prune --volumes` (nettoyage hebdomadaire sans volumes déjà en place).

### Accès VM → pointeuses (fait le 9 oct. 2026)

```
# Switch 2
vlan database / vlan 3602
interface gi24 / switchport trunk allowed vlan add 3602
interface gi17 / switchport trunk allowed vlan add 3602

# MikroTik
/interface bridge vlan add bridge=bridge1 vlan-ids=3602 tagged=bridge1,ether2 comment="VLAN pointeuses ZKTeco"
/interface vlan add interface=bridge1 vlan-id=3602 name=vlan3602-zk comment="Pointeuses ZKTeco"
/ip address add address=10.0.4.250/24 interface=vlan3602-zk comment="Pointeuses ZKTeco"
/ip firewall nat add chain=srcnat src-address=10.169.153.144 out-interface=vlan3602-zk action=masquerade comment="VM -> pointeuses"
/ip firewall filter add chain=forward src-address=10.169.153.144 out-interface=vlan3602-zk action=accept comment="VM -> pointeuses"
/ip firewall filter add chain=forward out-interface=vlan3602-zk action=drop comment="Bloquer le reste vers pointeuses"
```

Le masquerade fait répondre les pointeuses au MikroTik : rien n'a été modifié sur les appareils.

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
| 514/udp | Logs MikroTik (Logstash) | VM |
| 5601/tcp | Kibana, publié via 41.174.155.178:5601 | VM + NAT |
| 9200, 9300, 5044, 5000, 9600/tcp | Elasticsearch, Logstash | VM |
| 80/tcp | Réservé au php-apache d'ELK (désactivé) | VM |
| 22/tcp | SSH | VM |
| 443, 7080, 7081/tcp | NAT vers l'ancien serveur 10.125.40.11 | Routeur |
| **8443/tcp** | **Plateforme (HTTPS)**, NAT public 8443 | VM + NAT |
| **8088/tcp** | **Validation Let's Encrypt**, NAT public 80 → 8088 | VM + NAT |
| **8090/tcp** | **phpMyAdmin**, 127.0.0.1 seulement (tunnel SSH) | VM |

---

## Le projet

Une plateforme web interne unique, avec une connexion commune, qui regroupe trois modules partageant la même liste d'employés.

| Module | Rôle | Priorité |
|---|---|---|
| Attendance (Présence) | Entrées et sorties à partir des ZKTeco, tendances | 1 — base livrée |
| Vehicle Fleet & Allocation | Parc de véhicules et attributions | 2 |
| Leave (Congés) | Demandes et suivi des congés | 3 |

### Stack retenue

| Couche | Choix |
|---|---|
| Back-end | PHP 8.3, MVC maison, un module par dossier (`app/Modules/…`) |
| Front-end | HTML, CSS, JavaScript sans dépendance externe ; PWA installable |
| Base | **MariaDB 11.4** + **phpMyAdmin** |
| Synchronisation | Service `worker` : Python/pyzk (bibliothèque testée sur nos appareils). Une connexion permanente par pointeuse : badges en temps réel (`live_capture`), synchro complète toutes les 30 min sur la même connexion, reconnexion progressive (10 s → 5 min). Calcul en PHP (mêmes règles que l'application) après chaque badge et toutes les 5 min. Horloges remises à l'heure de la VM chaque nuit à 03:00 |
| Cache navigateur | Fichiers statiques versionnés par date de modification (`asset()`, `?v=`), cache d'un an ; service worker sans numéro de version à modifier |
| Serveur | Docker Compose sur la VM, projet `liquid-attendance`, réseau Docker séparé d'ELK |
| Accès | https://41.174.155.178:8443 (et https://10.169.153.144:8443 en interne) |
| Certificat | Let's Encrypt pour l'adresse IP (profil `shortlived`, renouvelé automatiquement par acme.sh) |
| Dépôt | github.com/Nyombi1997/liquid-attendance |

---

## Décisions fonctionnelles

- **Nom des employés** : modifiable par un admin dans la plateforme uniquement ; les pointeuses ne sont jamais modifiées.
- **Seule écriture sur les pointeuses** : la remise à l'heure de l'horloge, chaque nuit à 03:00 (décidé le 9 oct. 2026). Écart avant et après journalisé.
- **Sortie non badgée** : la journée est marquée « sortie manquante » et ne compte aucune heure ; un admin ou les RH saisissent l'heure de sortie (tracée, annulable).
- **Horaires** : saisis manuellement. Attribuables à tout le monde (horaire par défaut), à un groupe ou à un ou plusieurs agents, sur une période. Priorité agent > groupe > défaut. Horaires de nuit gérés.
- Paramètres d'un horaire : début, fin, jours travaillés, pause déduite, tolérance de retard, seuil des heures supplémentaires.
- **NOC et IT Room** : accès salle, historique séparé, non comptés dans la présence.
- **Rôles** : Administrateur (tout), RH (employés, groupes, horaires, corrections), Lecteur (consultation et export).
- Horaire par défaut provisoire : 08:00–17:00, lundi–vendredi, pause 60 min, tolérance 15 min, heures sup au-delà de 30 min. **À ajuster.**

## Design

- Couleurs : **#273c88** (principale) et **#c8187d** (accent).
- Style des maquettes de référence : menu latéral coloré, cartes blanches, tableaux aérés, indicateurs en haut du tableau de bord.
- Boutons et champs : coins arrondis à 5 px maximum. Interface en français.

## Sécurité

- HTTPS obligatoire, jamais d'accès sans authentification.
- Kibana est toujours ouvert sans mot de passe : à sécuriser (liste d'adresses autorisées sur le MikroTik ou login).
- Les règles du pare-feu MikroTik ont toutes `log=yes`, ce qui gonfle les données envoyées à ELK : à limiter aux règles de drop.
- Les règles input 0 et 1 (port 5601 sur le routeur) semblent inutiles, Kibana passant par le dst-nat : à revoir avec la sécurisation de Kibana.

## Questions ouvertes

- Horaires réels à saisir, et groupes à créer (étages, NOC, équipes).
- Faut-il intégrer l'historique antérieur à 2026 ? (réglage `history_start_date`, puis recalcul)
- Logo officiel à fournir pour remplacer le logo provisoire (`public/assets/img/`).
