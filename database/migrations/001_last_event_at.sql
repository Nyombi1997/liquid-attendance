-- Heure de réception du dernier badge en temps réel (worker/sync.py).
-- Déjà présent dans schema.sql pour une nouvelle installation ; à appliquer
-- une fois sur une base existante (voir README, « Mettre à jour »).
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS last_event_at DATETIME NULL COMMENT 'dernier badge reçu en temps réel' AFTER last_error;
