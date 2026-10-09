-- Données de départ : bureaux, pointeuses, horaire par défaut.
SET NAMES utf8mb4;

INSERT INTO offices (id, name) VALUES
  (1, '5e étage'),
  (2, '4e étage'),
  (3, 'NOC'),
  (4, 'IT Room');

INSERT INTO devices (name, ip, office_id, direction, usage_type, model) VALUES
  ('Entrée 5e étage', '10.0.4.18', 1, 'entree', 'presence', 'SF400/ID'),
  ('Sortie 5e étage', '10.0.4.20', 1, 'sortie', 'presence', 'SF400/ID'),
  ('Entrée 4e étage', '10.0.4.7',  2, 'entree', 'presence', 'F18/ID'),
  ('Sortie 4e étage', '10.0.4.15', 2, 'sortie', 'presence', 'SF400/ID'),
  ('Porte NOC',       '10.0.4.5',  3, 'entree', 'acces',    NULL),
  ('Porte IT Room',   '10.0.4.4',  4, 'entree', 'acces',    NULL);

-- Horaire provisoire : à ajuster dans Présence > Horaires
INSERT INTO schedules (name, start_time, end_time, break_minutes, late_tolerance_minutes,
                       overtime_after_minutes, workdays, is_default)
VALUES ('Horaire standard', '08:00:00', '17:00:00', 60, 15, 30, '1,2,3,4,5', 1);

INSERT INTO settings (name, value) VALUES
  ('history_start_date', '2026-01-01');
