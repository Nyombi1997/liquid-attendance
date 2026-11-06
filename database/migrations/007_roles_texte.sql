-- Rôle des comptes en texte : users.role passe d'une liste ENUM à VARCHAR(30).
-- Les rôles valides sont vérifiés en PHP (Auth::ROLES, à l'enregistrement d'un
-- compte) : ajouter un rôle ne demande plus de migration, et aucune migration ne
-- risque de réduire la liste (incident du 6 nov. 2026 : 002_agents.sql rejouée,
-- « Data truncated for column 'role' »). Les valeurs existantes sont conservées.
-- Déjà inclus dans schema.sql pour une nouvelle installation. Rejouable sans risque.

ALTER TABLE users
  MODIFY role VARCHAR(30) NOT NULL DEFAULT 'lecteur' COMMENT 'rôle de la plateforme, voir Auth::ROLES';
