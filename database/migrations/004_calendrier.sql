-- Calendrier de présence : historique des disponibilités saisies par les RH
-- (télétravail, mission, congé, au bureau), pour afficher les raisons
-- d'absence des jours passés. employees.work_status garde le statut en cours.
-- Déjà inclus dans schema.sql pour une nouvelle installation ; à appliquer
-- une fois sur une base existante (voir README). Peut être relancé sans risque.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS work_statuses (
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

-- Reprise des statuts en cours (une seule fois par agent)
INSERT INTO work_statuses (employee_id, status, date_from, date_to, note, created_by, created_at)
SELECT e.id, e.work_status, DATE(COALESCE(e.work_status_at, NOW())), e.work_status_until, e.work_status_note,
       e.work_status_by, COALESCE(e.work_status_at, NOW())
  FROM employees e
 WHERE e.work_status IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM work_statuses w WHERE w.employee_id = e.id);
