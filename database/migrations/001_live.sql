-- Mode « en direct » (badges en temps réel, API /api/direct).
-- Déjà inclus dans schema.sql pour une nouvelle installation ; à appliquer
-- une fois sur une base existante (voir README). Peut être relancé sans risque.

-- Heure de réception du dernier badge en temps réel (worker/sync.py)
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS last_event_at DATETIME NULL COMMENT 'dernier badge reçu en temps réel' AFTER last_error;

-- Écoute en direct active depuis (NULL : pas de connexion directe)
ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS live_since DATETIME NULL COMMENT 'écoute en direct active depuis' AFTER last_event_at;

-- Journées modifiées depuis le dernier appel de /api/direct
CREATE INDEX IF NOT EXISTS idx_day_computed ON attendance_days (computed_at);
