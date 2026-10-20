-- Données d'exemple du Parc automobile (démonstration et tests).
-- NE PAS CHARGER EN PRODUCTION : crée des employés, des comptes et des demandes fictifs.
-- À charger sur une base de test, après schema.sql et seed.sql (ou la migration 005) :
--   mariadb … < database/seed_fleet_demo.sql
-- Mot de passe de tous les comptes de démonstration : demo-flotte-2026
--   grace.mbuyi   Direction générale, responsable (N+1) de la DG       rôle flotte : direction
--   paul.kabongo  Directeur administratif et financier (N+1 de la DAF) rôle flotte : direction
--   sarah.ilunga  Responsable logistique                                rôle flotte : gestionnaire
--   jean.mukendi  Chef du service commercial (N+1 du service)           rôle flotte : validateur
--   patrick.lunda Commercial                                            rôle flotte : demandeur
--   aline.tshala  Informatique (service sans responsable : N+1 = DG)    rôle flotte : demandeur
--   didier.kasongo, eric.mbala  Chauffeurs                              rôle flotte : chauffeur
-- Dates relatives au jour du chargement.

SET NAMES utf8mb4;

-- Organisation : directions et services
INSERT INTO departments (name, kind) VALUES ('Direction générale (démo)', 'direction');
SET @dg = LAST_INSERT_ID();
INSERT INTO departments (name, parent_id, kind) VALUES ('Direction administrative et financière (démo)', @dg, 'direction');
SET @daf = LAST_INSERT_ID();
INSERT INTO departments (name, parent_id, kind) VALUES ('Service logistique (démo)', @daf, 'service');
SET @logi = LAST_INSERT_ID();
INSERT INTO departments (name, parent_id, kind) VALUES ('Service commercial (démo)', @dg, 'service');
SET @com = LAST_INSERT_ID();
INSERT INTO departments (name, parent_id, kind) VALUES ('Service informatique (démo)', @dg, 'service');
SET @it = LAST_INSERT_ID();

-- Employés fictifs (numéro de pointeuse « demo-… » : aucun badge)
INSERT INTO employees (device_user_id, name, job_title, email, phone, department_id) VALUES
  ('demo-01', 'Grace Mbuyi',    'Directrice générale',                 'grace.mbuyi@example.org',    '+243 810 000 001', @dg),
  ('demo-02', 'Paul Kabongo',   'Directeur administratif et financier', 'paul.kabongo@example.org',   '+243 810 000 002', @daf),
  ('demo-03', 'Sarah Ilunga',   'Responsable logistique',              'sarah.ilunga@example.org',   '+243 810 000 003', @logi),
  ('demo-04', 'Jean Mukendi',   'Chef du service commercial',          'jean.mukendi@example.org',   '+243 810 000 004', @com),
  ('demo-05', 'Patrick Lunda',  'Commercial',                          'patrick.lunda@example.org',  '+243 810 000 005', @com),
  ('demo-06', 'Aline Tshala',   'Technicienne réseau',                 'aline.tshala@example.org',   '+243 810 000 006', @it),
  ('demo-07', 'Didier Kasongo', 'Chauffeur',                           NULL,                         '+243 810 000 007', @logi),
  ('demo-08', 'Eric Mbala',     'Chauffeur',                           NULL,                         '+243 810 000 008', @logi);
SET @e_grace = (SELECT id FROM employees WHERE device_user_id = 'demo-01');
SET @e_paul = (SELECT id FROM employees WHERE device_user_id = 'demo-02');
SET @e_sarah = (SELECT id FROM employees WHERE device_user_id = 'demo-03');
SET @e_jean = (SELECT id FROM employees WHERE device_user_id = 'demo-04');
SET @e_patrick = (SELECT id FROM employees WHERE device_user_id = 'demo-05');
SET @e_aline = (SELECT id FROM employees WHERE device_user_id = 'demo-06');
SET @e_didier = (SELECT id FROM employees WHERE device_user_id = 'demo-07');
SET @e_eric = (SELECT id FROM employees WHERE device_user_id = 'demo-08');

-- Responsables (N+1)
UPDATE departments SET head_employee_id = @e_grace WHERE id = @dg;
UPDATE departments SET head_employee_id = @e_paul WHERE id = @daf;
UPDATE departments SET head_employee_id = @e_sarah WHERE id = @logi;
UPDATE departments SET head_employee_id = @e_jean WHERE id = @com;

-- Comptes (mot de passe : demo-flotte-2026)
SET @pwd = '$2y$10$ydDQlKOnyzPl2Zp9U2RzmuEC/fUZfpVGowMiwUOjI3K/jgv8QoVpu';
INSERT INTO users (username, full_name, password_hash, role, employee_id, fleet_role) VALUES
  ('grace.mbuyi',    'Grace Mbuyi',    @pwd, 'ceo',     @e_grace,   'direction'),
  ('paul.kabongo',   'Paul Kabongo',   @pwd, 'employe', @e_paul,    'direction'),
  ('sarah.ilunga',   'Sarah Ilunga',   @pwd, 'employe', @e_sarah,   'gestionnaire'),
  ('jean.mukendi',   'Jean Mukendi',   @pwd, 'employe', @e_jean,    'validateur'),
  ('patrick.lunda',  'Patrick Lunda',  @pwd, 'employe', @e_patrick, 'demandeur'),
  ('aline.tshala',   'Aline Tshala',   @pwd, 'employe', @e_aline,   'demandeur'),
  ('didier.kasongo', 'Didier Kasongo', @pwd, 'employe', @e_didier,  'chauffeur'),
  ('eric.mbala',     'Eric Mbala',     @pwd, 'employe', @e_eric,    'chauffeur');
SET @u_grace = (SELECT id FROM users WHERE username = 'grace.mbuyi');
SET @u_paul = (SELECT id FROM users WHERE username = 'paul.kabongo');
SET @u_sarah = (SELECT id FROM users WHERE username = 'sarah.ilunga');
SET @u_jean = (SELECT id FROM users WHERE username = 'jean.mukendi');
SET @u_patrick = (SELECT id FROM users WHERE username = 'patrick.lunda');
SET @u_aline = (SELECT id FROM users WHERE username = 'aline.tshala');
SET @u_didier = (SELECT id FROM users WHERE username = 'didier.kasongo');

-- Véhicules
INSERT INTO fleet_vehicles (plate, brand, model, year, vehicle_type, seats, fuel_type, tank_liters, ref_consumption, odometer_km, status, tracker_id) VALUES
  ('DEMO 1001 AA', 'Toyota',  'Land Cruiser 300', 2022, 'suv',     7,  'diesel',  110, 12.5, 48210, 'en_mission',   'TRK-DEMO-01'),
  ('DEMO 1002 AA', 'Toyota',  'Hilux',            2020, 'pickup',  5,  'diesel',   80, 10.8, 91540, 'disponible',   'TRK-DEMO-02'),
  ('DEMO 1003 AA', 'Toyota',  'Hiace',            2019, 'minibus', 15, 'diesel',   70, 11.9, 132870,'disponible',   NULL),
  ('DEMO 1004 AA', 'Hyundai', 'Accent',           2018, 'berline', 5,  'essence',  45,  7.2, 160320,'en_entretien', NULL);
SET @v_lc = (SELECT id FROM fleet_vehicles WHERE plate = 'DEMO 1001 AA');
SET @v_hilux = (SELECT id FROM fleet_vehicles WHERE plate = 'DEMO 1002 AA');
SET @v_hiace = (SELECT id FROM fleet_vehicles WHERE plate = 'DEMO 1003 AA');
SET @v_accent = (SELECT id FROM fleet_vehicles WHERE plate = 'DEMO 1004 AA');

-- Documents : assurance du Hilux qui expire dans 20 jours, contrôle technique de l'Accent expiré
INSERT INTO fleet_vehicle_documents (vehicle_id, kind, reference, expires_on, created_by) VALUES
  (@v_lc,     'assurance',          'POL-DEMO-1001', CURDATE() + INTERVAL 9 MONTH,  @u_sarah),
  (@v_lc,     'controle_technique', 'CT-DEMO-1001',  CURDATE() + INTERVAL 5 MONTH,  @u_sarah),
  (@v_hilux,  'assurance',          'POL-DEMO-1002', CURDATE() + INTERVAL 20 DAY,   @u_sarah),
  (@v_hilux,  'controle_technique', 'CT-DEMO-1002',  CURDATE() + INTERVAL 7 MONTH,  @u_sarah),
  (@v_hiace,  'assurance',          'POL-DEMO-1003', CURDATE() + INTERVAL 11 MONTH, @u_sarah),
  (@v_hiace,  'controle_technique', 'CT-DEMO-1003',  CURDATE() + INTERVAL 2 MONTH,  @u_sarah),
  (@v_accent, 'assurance',          'POL-DEMO-1004', CURDATE() + INTERVAL 4 MONTH,  @u_sarah),
  (@v_accent, 'controle_technique', 'CT-DEMO-1004',  CURDATE() - INTERVAL 10 DAY,   @u_sarah);

-- Chauffeurs : permis d'Eric qui expire dans 25 jours ; Eric autorisé sur le Hilux et l'Accent seulement
INSERT INTO fleet_drivers (employee_id, license_number, license_category, license_expires) VALUES
  (@e_didier, 'KIN-DEMO-0007', 'B,C,D', CURDATE() + INTERVAL 2 YEAR),
  (@e_eric,   'KIN-DEMO-0008', 'B',     CURDATE() + INTERVAL 25 DAY);
SET @d_didier = (SELECT id FROM fleet_drivers WHERE employee_id = @e_didier);
SET @d_eric = (SELECT id FROM fleet_drivers WHERE employee_id = @e_eric);
INSERT INTO fleet_driver_vehicles (driver_id, vehicle_id) VALUES (@d_eric, @v_hilux), (@d_eric, @v_accent);

-- Demandes
-- 1. Patrick, demain matin en ville : attente du N+1 (Jean)
INSERT INTO fleet_requests (requester_id, created_by, department_id, purpose, destination, depart_at, return_at, passengers,
                            with_driver, status, validation, step, n1_user_id, created_at)
VALUES (@e_patrick, @u_patrick, @com, 'Rendez-vous client (présentation de l''offre fibre)', 'Gombe, boulevard du 30 Juin',
        CURDATE() + INTERVAL 1 DAY + INTERVAL 9 HOUR, CURDATE() + INTERVAL 1 DAY + INTERVAL 12 HOUR, 2,
        1, 'en_validation', 'en_attente', 'n1', @u_jean, NOW() - INTERVAL 2 HOUR);
SET @r1 = LAST_INSERT_ID();
-- 2. Patrick, Matadi (hors ville, 3 jours) : validée par le N+1, attente de la direction
INSERT INTO fleet_requests (requester_id, created_by, department_id, purpose, destination, out_of_town, depart_at, return_at, passengers,
                            with_driver, vehicle_type, status, validation, step, direction_reasons, n1_user_id, n1_decided_by, n1_decided_at, created_at)
VALUES (@e_patrick, @u_patrick, @com, 'Installation chez un client entreprise', 'Matadi', 1,
        CURDATE() + INTERVAL 3 DAY + INTERVAL 7 HOUR, CURDATE() + INTERVAL 5 DAY + INTERVAL 18 HOUR, 3,
        1, 'pickup', 'en_validation', 'en_attente', 'direction', 'mission hors ville, durée de 3 jours (plus de 2)',
        @u_jean, @u_jean, NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 1 DAY);
SET @r2 = LAST_INSERT_ID();
-- 3. Aline, après-demain : validée, à affecter
INSERT INTO fleet_requests (requester_id, created_by, department_id, purpose, destination, depart_at, return_at, passengers,
                            with_driver, status, validation, n1_user_id, n1_decided_by, n1_decided_at, validated_at, created_at)
VALUES (@e_aline, @u_aline, @it, 'Intervention sur le site relais', 'Limete, 12e rue',
        CURDATE() + INTERVAL 2 DAY + INTERVAL 8 HOUR, CURDATE() + INTERVAL 2 DAY + INTERVAL 14 HOUR, 2,
        1, 'approuvee', 'approuvee', @u_grace, @u_grace, NOW() - INTERVAL 3 HOUR, NOW() - INTERVAL 3 HOUR, NOW() - INTERVAL 20 HOUR);
SET @r3 = LAST_INSERT_ID();
-- 4. Jean, mission en cours avec Didier sur le Land Cruiser
INSERT INTO fleet_requests (requester_id, created_by, department_id, purpose, destination, depart_at, return_at, passengers,
                            with_driver, status, validation, n1_user_id, n1_decided_by, n1_decided_at, validated_at,
                            vehicle_id, driver_id, assigned_by, assigned_at, created_at)
VALUES (@e_jean, @u_jean, @com, 'Visite des agences', 'Kintambo et Ngaliema',
        NOW() - INTERVAL 2 HOUR, NOW() + INTERVAL 3 HOUR, 2,
        1, 'en_cours', 'approuvee', @u_grace, @u_grace, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY,
        @v_lc, @d_didier, @u_sarah, NOW() - INTERVAL 20 HOUR, NOW() - INTERVAL 2 DAY);
SET @r4 = LAST_INSERT_ID();
INSERT INTO fleet_missions (request_id, vehicle_id, driver_id, start_km, start_fuel, start_condition, started_at, started_by)
VALUES (@r4, @v_lc, @d_didier, 48210, 75, 'bon', NOW() - INTERVAL 2 HOUR, @u_didier);
-- 5. Patrick, la semaine dernière : terminée, à évaluer
INSERT INTO fleet_requests (requester_id, created_by, department_id, purpose, destination, depart_at, return_at, passengers,
                            with_driver, status, validation, n1_user_id, n1_decided_by, n1_decided_at, validated_at,
                            vehicle_id, driver_id, assigned_by, assigned_at, created_at)
VALUES (@e_patrick, @u_patrick, @com, 'Livraison de matériel', 'Masina',
        CURDATE() - INTERVAL 6 DAY + INTERVAL 9 HOUR, CURDATE() - INTERVAL 6 DAY + INTERVAL 13 HOUR, 1,
        1, 'terminee', 'approuvee', @u_jean, @u_jean, CURDATE() - INTERVAL 7 DAY + INTERVAL 15 HOUR, CURDATE() - INTERVAL 7 DAY + INTERVAL 15 HOUR,
        @v_hilux, @d_eric, @u_sarah, CURDATE() - INTERVAL 7 DAY + INTERVAL 16 HOUR, CURDATE() - INTERVAL 7 DAY + INTERVAL 11 HOUR);
SET @r5 = LAST_INSERT_ID();
INSERT INTO fleet_missions (request_id, vehicle_id, driver_id, start_km, start_fuel, start_condition, started_at, started_by,
                            end_km, end_fuel, end_condition, ended_at, end_synced_at)
VALUES (@r5, @v_hilux, @d_eric, 91480, 100, 'bon', CURDATE() - INTERVAL 6 DAY + INTERVAL 9 HOUR, NULL,
        91540, 75, 'bon', CURDATE() - INTERVAL 6 DAY + INTERVAL 13 HOUR, CURDATE() - INTERVAL 6 DAY + INTERVAL 13 HOUR);
-- 6. Aline : refusée par la direction générale (N+1)
INSERT INTO fleet_requests (requester_id, created_by, department_id, purpose, destination, depart_at, return_at, passengers,
                            with_driver, status, validation, n1_user_id, n1_decided_by, n1_decided_at, refusal_reason, refused_by, refused_at, created_at)
VALUES (@e_aline, @u_aline, @it, 'Formation externe', 'Lubumbashi (avion), navette aéroport',
        CURDATE() + INTERVAL 8 DAY + INTERVAL 6 HOUR, CURDATE() + INTERVAL 8 DAY + INTERVAL 8 HOUR, 1,
        0, 'refusee', 'refusee', @u_grace, @u_grace, NOW() - INTERVAL 5 HOUR, 'Navette de l''hôtel prévue par l''organisme de formation.',
        @u_grace, NOW() - INTERVAL 5 HOUR, NOW() - INTERVAL 1 DAY);
SET @r6 = LAST_INSERT_ID();

UPDATE fleet_requests SET reference = CONCAT('DV-', YEAR(created_at), '-', LPAD(id, 4, '0')) WHERE reference IS NULL;

INSERT INTO fleet_request_events (request_id, user_id, action, details, created_at) VALUES
  (@r1, @u_patrick, 'creee', 'envoyée au N+1', NOW() - INTERVAL 2 HOUR),
  (@r2, @u_patrick, 'creee', 'envoyée au N+1 · direction requise : mission hors ville, durée de 3 jours (plus de 2)', NOW() - INTERVAL 1 DAY),
  (@r2, @u_jean, 'validee_n1', 'transmise à la direction', NOW() - INTERVAL 1 HOUR),
  (@r3, @u_aline, 'creee', 'envoyée au N+1', NOW() - INTERVAL 20 HOUR),
  (@r3, @u_grace, 'validee_n1', 'demande validée', NOW() - INTERVAL 3 HOUR),
  (@r4, @u_jean, 'creee', 'envoyée au N+1', NOW() - INTERVAL 2 DAY),
  (@r4, @u_grace, 'validee_n1', 'demande validée', NOW() - INTERVAL 1 DAY),
  (@r4, @u_sarah, 'affectee', 'DEMO 1001 AA · chauffeur Didier Kasongo', NOW() - INTERVAL 20 HOUR),
  (@r4, @u_didier, 'demarree', '48 210 km · carburant 3/4', NOW() - INTERVAL 2 HOUR),
  (@r5, @u_patrick, 'creee', 'envoyée au N+1', CURDATE() - INTERVAL 7 DAY + INTERVAL 11 HOUR),
  (@r5, @u_jean, 'validee_n1', 'demande validée', CURDATE() - INTERVAL 7 DAY + INTERVAL 15 HOUR),
  (@r5, @u_sarah, 'affectee', 'DEMO 1002 AA · chauffeur Eric Mbala', CURDATE() - INTERVAL 7 DAY + INTERVAL 16 HOUR),
  (@r5, NULL, 'demarree', '91 480 km · carburant plein', CURDATE() - INTERVAL 6 DAY + INTERVAL 9 HOUR),
  (@r5, NULL, 'cloturee', '91 540 km (60 km) · carburant 3/4 · bon état', CURDATE() - INTERVAL 6 DAY + INTERVAL 13 HOUR),
  (@r6, @u_aline, 'creee', 'envoyée au N+1', NOW() - INTERVAL 1 DAY),
  (@r6, @u_grace, 'refusee', 'par le N+1 : Navette de l''hôtel prévue par l''organisme de formation.', NOW() - INTERVAL 5 HOUR);
