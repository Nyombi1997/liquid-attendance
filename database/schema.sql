-- Schéma de la plateforme interne (socle commun + modules Attendance et Fleet)
-- Chargé automatiquement par MariaDB au premier démarrage (volume vide).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Socle commun
-- ---------------------------------------------------------------------------

-- Départements (arborescence : un département peut avoir un parent).
-- Un manager ne voit que les agents de son département et de ses sous-départements.
-- kind : direction ou service ; head_employee_id : responsable (N+1), qui valide
-- les demandes de véhicule de son équipe (clé étrangère ajoutée après employees).
CREATE TABLE departments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  parent_id  INT UNSIGNED NULL,
  kind       ENUM('direction','service') NOT NULL DEFAULT 'service',
  head_employee_id INT UNSIGNED NULL COMMENT 'responsable (N+1) : valide les demandes de véhicule',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dept_parent (parent_id),
  CONSTRAINT fk_dept_parent FOREIGN KEY (parent_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- role       : droits de la plateforme (employe : module Parc automobile seulement)
-- employee_id: fiche employé du titulaire du compte (demandes de véhicule, chauffeur, N+1)
-- fleet_role : rôle dans le module Parc automobile
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE COMMENT 'en minuscules, comparé sans tenir compte de la casse',
  full_name     VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','rh','ceo','manager','lecteur','employe') NOT NULL DEFAULT 'lecteur',
  department_id INT UNSIGNED NULL COMMENT 'département du manager',
  employee_id   INT UNSIGNED NULL COMMENT 'fiche employé du titulaire du compte',
  fleet_role    ENUM('demandeur','validateur','gestionnaire','chauffeur','direction') NOT NULL DEFAULT 'demandeur'
                COMMENT 'rôle dans le module Parc automobile',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_employee (employee_id),
  CONSTRAINT fk_user_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(60) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  success      TINYINT(1)  NOT NULL,
  attempted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attempts (username, ip, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(80)  NOT NULL,
  details    TEXT         NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_date (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Groupes d'agents (utilisés pour les horaires, et plus tard les congés)
CREATE TABLE employee_groups (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Liste commune des employés (partagée par Attendance, Fleet et Leave).
-- device_user_id : numéro technique enregistré sur les pointeuses.
-- name           : nom affiché dans la plateforme (modifiable par un admin).
-- device_name    : nom tel qu'il figure sur la pointeuse (lecture seule).
-- photo_updated_at : photo présente (storage/photos/<id>.jpg) si renseigné.
-- work_status    : statut saisi par les RH (NULL = automatique : « au bureau »
--                  dès le premier badge du jour), valable jusqu'à work_status_until.
CREATE TABLE employees (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_user_id    VARCHAR(20)  NOT NULL UNIQUE,
  name              VARCHAR(120) NOT NULL,
  job_title         VARCHAR(120) NULL,
  device_name       VARCHAR(120) NULL,
  matricule         VARCHAR(40)  NULL,
  email             VARCHAR(150) NULL,
  phone             VARCHAR(40)  NULL,
  group_id          INT UNSIGNED NULL,
  department_id     INT UNSIGNED NULL,
  photo_updated_at  DATETIME     NULL,
  work_status       ENUM('bureau','teletravail','mission','conge') NULL,
  work_status_until DATE         NULL COMMENT 'fin du statut saisi (incluse), NULL = sans fin',
  work_status_note  VARCHAR(255) NULL,
  work_status_by    INT UNSIGNED NULL,
  work_status_at    DATETIME     NULL,
  active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_emp_name (name),
  CONSTRAINT fk_emp_group FOREIGN KEY (group_id) REFERENCES employee_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_status_by FOREIGN KEY (work_status_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Liens créés après la table employees (tables définies plus haut)
ALTER TABLE departments
  ADD CONSTRAINT fk_dept_head FOREIGN KEY (head_employee_id) REFERENCES employees(id) ON DELETE SET NULL;
ALTER TABLE users
  ADD CONSTRAINT fk_user_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL;

-- Historique des disponibilités (statuts saisis par les RH, avec leur période) :
-- affiché par le calendrier de présence pour les jours passés.
CREATE TABLE work_statuses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  status      ENUM('bureau','teletravail','mission','conge') NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NULL COMMENT 'incluse, NULL = jusqu''à nouvel ordre',
  note        VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ws_emp_dates (employee_id, date_from, date_to),
  CONSTRAINT fk_ws_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_ws_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Module Attendance
-- ---------------------------------------------------------------------------

CREATE TABLE offices (
  id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- direction : entree / sortie
-- usage     : presence (compte dans les heures) / acces (contrôle d'accès salle)
CREATE TABLE devices (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  ip               VARCHAR(45)  NOT NULL UNIQUE,
  port             SMALLINT UNSIGNED NOT NULL DEFAULT 4370,
  comm_key         INT UNSIGNED NOT NULL DEFAULT 0,
  office_id        INT UNSIGNED NOT NULL,
  direction        ENUM('entree','sortie') NOT NULL,
  usage_type       ENUM('presence','acces') NOT NULL DEFAULT 'presence',
  model            VARCHAR(60)  NULL,
  serial_number    VARCHAR(60)  NULL,
  firmware         VARCHAR(60)  NULL,
  active           TINYINT(1)   NOT NULL DEFAULT 1,
  last_sync_at     DATETIME     NULL,
  last_sync_ok     TINYINT(1)   NULL,
  last_error       VARCHAR(255) NULL,
  last_event_at    DATETIME     NULL COMMENT 'dernier badge reçu en temps réel',
  live_since       DATETIME     NULL COMMENT 'écoute en direct active depuis',
  clock_offset_sec INT          NULL COMMENT 'heure pointeuse - heure serveur',
  user_count       INT UNSIGNED NULL,
  log_count        INT UNSIGNED NULL,
  CONSTRAINT fk_dev_office FOREIGN KEY (office_id) REFERENCES offices(id)
) ENGINE=InnoDB;

-- Pointages bruts, jamais modifiés (copie fidèle des pointeuses)
CREATE TABLE punches (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id      INT UNSIGNED NOT NULL,
  device_user_id VARCHAR(20)  NOT NULL,
  punched_at     DATETIME     NOT NULL,
  verify_status  TINYINT      NULL,
  punch_type     TINYINT      NULL,
  imported_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_punch (device_id, device_user_id, punched_at),
  KEY idx_punch_user_time (device_user_id, punched_at),
  KEY idx_punch_time (punched_at),
  CONSTRAINT fk_punch_device FOREIGN KEY (device_id) REFERENCES devices(id)
) ENGINE=InnoDB;

-- Horaires saisis manuellement.
-- workdays : jours ISO séparés par des virgules (1 = lundi … 7 = dimanche)
-- Si end_time < start_time, l'horaire passe minuit (équipe de nuit).
CREATE TABLE schedules (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                   VARCHAR(100) NOT NULL,
  start_time             TIME NOT NULL,
  end_time               TIME NOT NULL,
  break_minutes          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  late_tolerance_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  overtime_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0
                         COMMENT 'heures sup comptées si le dépassement atteint ce seuil',
  workdays               VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
  is_default             TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'horaire de tout le monde',
  color                  VARCHAR(7)  NOT NULL DEFAULT '#273c88',
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Attribution d'un horaire à un agent ou à un groupe, sur une période.
-- Priorité : agent > groupe > horaire par défaut.
CREATE TABLE schedule_assignments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT UNSIGNED NOT NULL,
  target_type ENUM('employee','group') NOT NULL,
  target_id   INT UNSIGNED NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NULL,
  note        VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assign_target (target_type, target_id, date_from),
  CONSTRAINT fk_assign_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Journées calculées (une ligne par agent et par jour)
CREATE TABLE attendance_days (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id         INT UNSIGNED NOT NULL,
  work_date           DATE NOT NULL,
  schedule_id         INT UNSIGNED NULL,
  expected_start      DATETIME NULL,
  expected_end        DATETIME NULL,
  first_in            DATETIME NULL,
  last_out            DATETIME NULL,
  worked_minutes      INT NOT NULL DEFAULT 0,
  late_minutes        INT NOT NULL DEFAULT 0,
  early_leave_minutes INT NOT NULL DEFAULT 0,
  overtime_minutes    INT NOT NULL DEFAULT 0,
  status              ENUM('present','retard','absent','sortie_manquante','en_cours','repos','hors_horaire')
                      NOT NULL,
  corrected_out       DATETIME NULL COMMENT 'heure de sortie saisie par un admin',
  corrected_by        INT UNSIGNED NULL,
  corrected_at        DATETIME NULL,
  correction_note     VARCHAR(255) NULL,
  computed_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'dernier changement du calcul',
  UNIQUE KEY uq_day (employee_id, work_date),
  KEY idx_day_date_status (work_date, status),
  KEY idx_day_computed (computed_at),
  CONSTRAINT fk_day_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_day_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE SET NULL,
  CONSTRAINT fk_day_corrector FOREIGN KEY (corrected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE settings (
  name  VARCHAR(60) PRIMARY KEY,
  value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Notifications (cloche de l'en-tête et e-mails)
-- ---------------------------------------------------------------------------

CREATE TABLE notifications (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  module         VARCHAR(20)  NOT NULL DEFAULT 'fleet',
  kind           VARCHAR(40)  NOT NULL,
  title          VARCHAR(160) NOT NULL,
  body           VARCHAR(500) NULL,
  url            VARCHAR(255) NULL,
  read_at        DATETIME     NULL,
  email_status   ENUM('aucun','en_attente','envoye','echec') NOT NULL DEFAULT 'aucun',
  email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  email_error    VARCHAR(255) NULL,
  emailed_at     DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, read_at, id),
  KEY idx_notif_email (email_status, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Référentiels du parc
-- ---------------------------------------------------------------------------

-- tracker_id et ref_consumption : utilisés plus tard par le suivi GPS et le carburant
CREATE TABLE fleet_vehicles (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plate           VARCHAR(20)  NOT NULL UNIQUE COMMENT 'immatriculation',
  brand           VARCHAR(60)  NOT NULL,
  model           VARCHAR(60)  NOT NULL,
  year            SMALLINT UNSIGNED NULL,
  vehicle_type    ENUM('berline','suv','pickup','minibus','camion','moto') NOT NULL DEFAULT 'berline',
  seats           TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'places, chauffeur compris',
  fuel_type       ENUM('essence','diesel','hybride','electrique') NOT NULL DEFAULT 'diesel',
  tank_liters     DECIMAL(6,1) NULL COMMENT 'capacité du réservoir',
  ref_consumption DECIMAL(5,2) NULL COMMENT 'consommation de référence, L/100 km',
  odometer_km     INT UNSIGNED NULL COMMENT 'dernier kilométrage connu (clôture de mission)',
  status          ENUM('disponible','en_mission','en_panne','en_entretien') NOT NULL DEFAULT 'disponible',
  tracker_id      VARCHAR(60)  NULL COMMENT 'identifiant du tracker GPS (module 2)',
  notes           VARCHAR(255) NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_vehicle_status (active, status)
) ENGINE=InnoDB;

-- Documents d'un véhicule ; le plus récent de chaque type fait foi.
-- Fichier dans <storage>/fleet/documents/<stored_name> (hors de public/).
CREATE TABLE fleet_vehicle_documents (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vehicle_id    INT UNSIGNED NOT NULL,
  kind          ENUM('assurance','controle_technique','autre') NOT NULL,
  reference     VARCHAR(80)  NULL COMMENT 'n° de police, de certificat…',
  expires_on    DATE         NULL,
  file_name     VARCHAR(160) NULL COMMENT 'nom d''origine',
  stored_name   VARCHAR(80)  NULL,
  file_mime     VARCHAR(60)  NULL,
  file_size     INT UNSIGNED NULL,
  alerted_at    DATETIME     NULL COMMENT 'alerte d''expiration envoyée',
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_doc_vehicle (vehicle_id, kind, id),
  KEY idx_doc_expiry (expires_on),
  CONSTRAINT fk_doc_vehicle FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE fleet_drivers (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT UNSIGNED NOT NULL UNIQUE,
  license_number   VARCHAR(40)  NULL,
  license_category VARCHAR(20)  NULL COMMENT 'catégories séparées par des virgules : A,B,C,D,E',
  license_expires  DATE         NULL,
  license_alerted_at DATETIME   NULL COMMENT 'alerte d''expiration envoyée',
  notes            VARCHAR(255) NULL,
  active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_driver_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Véhicules autorisés d'un chauffeur (aucune ligne : tous les véhicules)
CREATE TABLE fleet_driver_vehicles (
  driver_id  INT UNSIGNED NOT NULL,
  vehicle_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (driver_id, vehicle_id),
  CONSTRAINT fk_dv_driver FOREIGN KEY (driver_id) REFERENCES fleet_drivers(id) ON DELETE CASCADE,
  CONSTRAINT fk_dv_vehicle FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Demandes de véhicule
-- ---------------------------------------------------------------------------

-- status     : cycle de vie (en_validation → approuvee → affectee → en_cours → terminee ;
--              refusee, annulee)
-- validation : en_attente / approuvee / refusee ; step = étape en attente (n1, direction).
-- Urgence : le gestionnaire peut affecter avant la fin de la validation
-- (status affectee, validation en_attente) : validation a posteriori, tracée.
CREATE TABLE fleet_requests (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference           VARCHAR(20)  NULL UNIQUE COMMENT 'DV-AAAA-NNNN',
  requester_id        INT UNSIGNED NOT NULL COMMENT 'employé demandeur',
  created_by          INT UNSIGNED NULL,
  department_id       INT UNSIGNED NULL COMMENT 'service du demandeur au moment de la demande',
  purpose             VARCHAR(255) NOT NULL COMMENT 'motif',
  destination         VARCHAR(160) NOT NULL,
  out_of_town         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'mission hors ville',
  depart_at           DATETIME     NOT NULL,
  return_at           DATETIME     NOT NULL,
  passengers          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  with_driver         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 : le demandeur conduit',
  preferred_driver_id INT UNSIGNED NULL,
  vehicle_type        VARCHAR(20)  NULL,
  urgent              TINYINT(1)   NOT NULL DEFAULT 0,
  urgent_reason       VARCHAR(255) NULL,
  order_file_name     VARCHAR(160) NULL COMMENT 'ordre de mission : nom d''origine',
  order_stored_name   VARCHAR(80)  NULL,
  order_mime          VARCHAR(60)  NULL,
  order_size          INT UNSIGNED NULL,
  status              ENUM('en_validation','approuvee','affectee','en_cours','terminee','refusee','annulee')
                      NOT NULL DEFAULT 'en_validation',
  validation          ENUM('en_attente','approuvee','refusee') NOT NULL DEFAULT 'en_attente',
  step                ENUM('n1','direction') NULL,
  direction_reasons   VARCHAR(255) NULL COMMENT 'raisons de la validation de la direction',
  n1_user_id          INT UNSIGNED NULL COMMENT 'N+1 chargé de valider',
  n1_decided_by       INT UNSIGNED NULL,
  n1_decided_at       DATETIME     NULL,
  direction_decided_by INT UNSIGNED NULL,
  direction_decided_at DATETIME    NULL,
  validated_at        DATETIME     NULL COMMENT 'fin de la validation (approuvée)',
  refusal_reason      VARCHAR(500) NULL,
  refused_by          INT UNSIGNED NULL,
  refused_at          DATETIME     NULL,
  cancel_reason       VARCHAR(255) NULL,
  cancelled_at        DATETIME     NULL,
  vehicle_id          INT UNSIGNED NULL,
  driver_id           INT UNSIGNED NULL,
  assigned_by         INT UNSIGNED NULL,
  assigned_at         DATETIME     NULL,
  assigned_before_validation TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'urgence : validation a posteriori',
  reminder_sent_at    DATETIME     NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_req_status (status, depart_at),
  KEY idx_req_requester (requester_id, created_at),
  KEY idx_req_n1 (n1_user_id, validation),
  KEY idx_req_vehicle (vehicle_id, depart_at, return_at),
  KEY idx_req_driver (driver_id, depart_at, return_at),
  CONSTRAINT fk_req_requester FOREIGN KEY (requester_id) REFERENCES employees(id),
  CONSTRAINT fk_req_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_req_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_req_pref_driver FOREIGN KEY (preferred_driver_id) REFERENCES fleet_drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_req_n1 FOREIGN KEY (n1_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_req_vehicle FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(id),
  CONSTRAINT fk_req_driver FOREIGN KEY (driver_id) REFERENCES fleet_drivers(id)
) ENGINE=InnoDB;

-- Historique complet d'une demande : qui a fait quoi et quand
CREATE TABLE fleet_request_events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(40)  NOT NULL,
  details    VARCHAR(500) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event_req (request_id, id),
  CONSTRAINT fk_event_req FOREIGN KEY (request_id) REFERENCES fleet_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Mission : démarrage et clôture par le chauffeur (écran mobile, saisie hors réseau possible).
-- *_at : heure de la saisie sur le téléphone ; *_synced_at : réception par le serveur.
-- *_client_id : identifiant de la saisie (envoi en double ignoré).
-- Photos du compteur : <storage>/fleet/missions/<request_id>-depart.jpg / -retour.jpg
CREATE TABLE fleet_missions (
  request_id      INT UNSIGNED PRIMARY KEY,
  vehicle_id      INT UNSIGNED NOT NULL,
  driver_id       INT UNSIGNED NULL,
  start_km        INT UNSIGNED NOT NULL,
  start_fuel      TINYINT UNSIGNED NOT NULL COMMENT 'niveau de carburant en %',
  start_condition ENUM('bon','a_signaler','en_panne') NOT NULL DEFAULT 'bon',
  start_notes     VARCHAR(500) NULL,
  start_photo     TINYINT(1)   NOT NULL DEFAULT 0,
  started_at      DATETIME     NOT NULL,
  start_synced_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_by      INT UNSIGNED NULL,
  start_client_id VARCHAR(40)  NULL,
  end_km          INT UNSIGNED NULL,
  end_fuel        TINYINT UNSIGNED NULL,
  end_condition   ENUM('bon','a_signaler','en_panne') NULL,
  end_notes       VARCHAR(500) NULL,
  end_photo       TINYINT(1)   NOT NULL DEFAULT 0,
  ended_at        DATETIME     NULL,
  end_synced_at   DATETIME     NULL,
  ended_by        INT UNSIGNED NULL,
  end_client_id   VARCHAR(40)  NULL,
  KEY idx_mission_vehicle (vehicle_id, started_at),
  CONSTRAINT fk_mission_req FOREIGN KEY (request_id) REFERENCES fleet_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_mission_vehicle FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(id),
  CONSTRAINT fk_mission_driver FOREIGN KEY (driver_id) REFERENCES fleet_drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_mission_start_user FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_mission_end_user FOREIGN KEY (ended_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Évaluation de la course par le demandeur (notes de 1 à 5)
CREATE TABLE fleet_ratings (
  request_id  INT UNSIGNED PRIMARY KEY,
  punctuality TINYINT UNSIGNED NOT NULL,
  driving     TINYINT UNSIGNED NOT NULL,
  comment     VARCHAR(500) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rating_req FOREIGN KEY (request_id) REFERENCES fleet_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_rating_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Paramètres du circuit (table settings commune), modifiables dans Parc automobile › Paramètres
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO settings (name, value) VALUES
  ('fleet_city', 'Kinshasa'),
  ('fleet_direction_out_of_town', '1'),
  ('fleet_direction_max_days', '2'),
  ('fleet_direction_weekend', '1'),
  ('fleet_order_required', 'hors_ville'),
  ('fleet_reminder_minutes', '60'),
  ('fleet_doc_alert_days', '30');
