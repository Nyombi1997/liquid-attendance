-- Migration 003 : identifiants insensibles à la casse.
-- L'application enregistre et compare désormais les identifiants en minuscules
-- (Auth::normalizeUsername). On aligne les comptes existants et on fixe une
-- collation insensible à la casse sur la colonne (l'index unique est conservé).
-- Rejouable sans risque.

SET NAMES utf8mb4;

UPDATE users SET username = LOWER(TRIM(username)) WHERE BINARY username <> BINARY LOWER(TRIM(username));

ALTER TABLE users
  MODIFY username VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'en minuscules, comparé sans tenir compte de la casse';
