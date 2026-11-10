-- Fin des disponibilités saisies (congé, mission, télétravail) : quand la date de
-- fin d'une période est passée, le worker (bin/fleet-tasks.php,
-- StatusNotify::ended()) prévient les comptes RH et la personne qui l'avait
-- saisie, une seule fois : work_statuses.end_notified_at.
-- Les périodes déjà terminées ou interrompues avant cette migration sont marquées
-- comme signalées (pas de message pour le passé).
-- Déjà inclus dans schema.sql pour une nouvelle installation. Rejouable sans risque.

ALTER TABLE work_statuses
  ADD COLUMN IF NOT EXISTS end_notified_at DATETIME NULL
    COMMENT 'fin de période signalée aux RH (ou période interrompue par un nouveau statut)' AFTER created_by;

UPDATE work_statuses SET end_notified_at = NOW()
 WHERE end_notified_at IS NULL AND date_to IS NOT NULL AND date_to < CURDATE();
