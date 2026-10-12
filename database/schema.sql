-- Schéma de la plateforme interne (socle commun + module Attendance)
-- Chargé automatiquement par MariaDB au premier démarrage (volume vide).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Socle commun
-- ---------------------------------------------------------------------------

-- Départements (arborescence : un département peut avoir un parent).
-- Un manager ne voit que les agents de son département et de ses sous-départements.
CREATE TABLE departments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  parent_id  INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dept_parent (parent_id),
  CONSTRAINT fk_dept_parent FOREIGN KEY (parent_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE COMMENT 'en minuscules, comparé sans tenir compte de la casse',
  full_name     VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','rh','ceo','manager','lecteur') NOT NULL DEFAULT 'lecteur',
  department_id INT UNSIGNED NULL COMMENT 'département du manager',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
