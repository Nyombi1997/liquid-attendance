-- Page « Agents » (cartes avec photo), départements, rôles CEO et Manager,
-- statut de travail (bureau, télétravail, mission, congé).
-- Déjà inclus dans schema.sql pour une nouvelle installation ; à appliquer
-- une fois sur une base existante (voir README). Peut être relancé sans risque.

SET NAMES utf8mb4;

-- Départements (arborescence : un département peut avoir un parent)
CREATE TABLE IF NOT EXISTS departments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  parent_id  INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dept_parent (parent_id),
  CONSTRAINT fk_dept_parent FOREIGN KEY (parent_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Comptes : rôles CEO (tout voir, rien modifier) et Manager (son département)
ALTER TABLE users
  MODIFY role ENUM('admin','rh','ceo','manager','lecteur') NOT NULL DEFAULT 'lecteur';
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS department_id INT UNSIGNED NULL COMMENT 'département du manager' AFTER role;
ALTER TABLE users
  ADD CONSTRAINT fk_user_dept FOREIGN KEY IF NOT EXISTS (department_id) REFERENCES departments(id) ON DELETE SET NULL;

-- Fiche agent : titre, département, photo, statut de travail
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS job_title VARCHAR(120) NULL AFTER name;
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS department_id INT UNSIGNED NULL AFTER group_id;
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS photo_updated_at DATETIME NULL COMMENT 'photo présente si renseigné (storage/photos)' AFTER department_id;
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS work_status ENUM('bureau','teletravail','mission','conge') NULL
      COMMENT 'statut saisi par les RH ; NULL = automatique (bureau au premier badge)' AFTER photo_updated_at;
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS work_status_until DATE NULL COMMENT 'fin du statut saisi (incluse), NULL = sans fin' AFTER work_status;
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS work_status_note VARCHAR(255) NULL AFTER work_status_until;
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS work_status_by INT UNSIGNED NULL AFTER work_status_note;
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS work_status_at DATETIME NULL AFTER work_status_by;
ALTER TABLE employees
  ADD CONSTRAINT fk_emp_dept FOREIGN KEY IF NOT EXISTS (department_id) REFERENCES departments(id) ON DELETE SET NULL;
ALTER TABLE employees
  ADD CONSTRAINT fk_emp_status_by FOREIGN KEY IF NOT EXISTS (work_status_by) REFERENCES users(id) ON DELETE SET NULL;
