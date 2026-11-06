-- Comptes, provinces et parc automobile (suite) :
--   users.email          : adresse du compte (e-mail de bienvenue, notifications) ;
--                          à défaut, l'adresse de la fiche employé reliée.
--   rôle « fleet »       : profil Parc automobile (mêmes accès que les RH, plus la
--                          gestion du parc et la validation des demandes).
--   provinces            : provinces créées par l'administrateur ; province de
--                          rattachement d'un employé (employees.province_id),
--                          assignée par les RH ou l'administrateur (« Mission hors de … »).
--   fleet_vehicle_types  : types de véhicule gérés dans l'application (au lieu d'une liste fixe).
--   fleet_vehicles.department_id : véhicule attribué à un département.
--   mail_outbox          : e-mails adressés directement à une adresse (employé sans
--                          compte : changement de disponibilité, véhicule attribué),
--                          envoyés par le worker (bin/fleet-tasks.php).
-- Déjà inclus dans schema.sql pour une nouvelle installation ; à appliquer une
-- fois sur une base existante (voir README). Peut être relancé sans risque.

SET NAMES utf8mb4;

-- Comptes (rôle fleet : users.role est en texte, voir 007_roles_texte.sql)
-- users.role en texte (rôles vérifiés en PHP par Auth::ROLES) : une migration ne
-- réduit jamais une liste ENUM, sinon la rejouer échoue (« Data truncated ») dès
-- qu'un rôle plus récent existe en base.
ALTER TABLE users
  MODIFY role VARCHAR(30) NOT NULL DEFAULT 'lecteur';
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL COMMENT 'adresse du compte (sinon : celle de la fiche employé)' AFTER full_name;

-- Provinces et province de rattachement des employés
CREATE TABLE IF NOT EXISTS provinces (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL UNIQUE,
  active     TINYINT(1)  NOT NULL DEFAULT 1,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS province_id INT UNSIGNED NULL COMMENT 'province de rattachement (missions hors province)' AFTER department_id;
ALTER TABLE employees
  ADD CONSTRAINT fk_emp_province FOREIGN KEY IF NOT EXISTS (province_id) REFERENCES provinces(id) ON DELETE SET NULL;

-- Types de véhicule
CREATE TABLE IF NOT EXISTS fleet_vehicle_types (
  code       VARCHAR(20) NOT NULL PRIMARY KEY,
  label      VARCHAR(60) NOT NULL,
  active     TINYINT(1)  NOT NULL DEFAULT 1,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO fleet_vehicle_types (code, label) VALUES
  ('berline', 'Berline'), ('suv', '4x4 / SUV'), ('pickup', 'Pick-up'),
  ('minibus', 'Minibus'), ('camion', 'Camion'), ('moto', 'Moto');

ALTER TABLE fleet_vehicles
  MODIFY vehicle_type VARCHAR(20) NOT NULL DEFAULT 'berline';
ALTER TABLE fleet_vehicles
  ADD COLUMN IF NOT EXISTS department_id INT UNSIGNED NULL COMMENT 'département auquel le véhicule est attribué' AFTER status;
ALTER TABLE fleet_vehicles
  ADD CONSTRAINT fk_vehicle_dept FOREIGN KEY IF NOT EXISTS (department_id) REFERENCES departments(id) ON DELETE SET NULL;

-- E-mails adressés directement à une adresse
CREATE TABLE IF NOT EXISTS mail_outbox (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email   VARCHAR(150) NOT NULL,
  subject    VARCHAR(160) NOT NULL,
  body       TEXT         NOT NULL,
  status     ENUM('en_attente','envoye','echec') NOT NULL DEFAULT 'en_attente',
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error      VARCHAR(255) NULL,
  sent_at    DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_outbox_status (status, id)
) ENGINE=InnoDB;
