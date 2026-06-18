-- M7: Empfehlungen / Referrals — Code-/Link-Attribution.
--
-- Modell (bestätigt 2026-06-18): Wir speichern KEINE fremden E-Mails.
-- Ein Werber teilt seinen eindeutigen `referral_code` (bzw. den Link
-- https://.../i/{code}). Registriert sich jemand mit diesem Code, wird die
-- Beziehung über `users.referred_by` + eine `referrals`-Zeile festgehalten.
--
-- Conversion-Stufen:
--   registered  → Konto angelegt (Code beim Signup gesetzt)
--   verified    → E-Mail bestätigt   (DIE zählende Stufe)
--   activated   → erste eigene Route hochgeladen
--
-- Eindeutigkeit:
--   uq_users_referral_code   → ein Code je User
--   uq_ref_referred          → ein Geworbener zählt max. 1× (Anti-Doppelzählung)

ALTER TABLE users
  ADD COLUMN referral_code VARCHAR(16)     NULL DEFAULT NULL AFTER public_handle,
  ADD COLUMN referred_by   BIGINT UNSIGNED NULL DEFAULT NULL AFTER referral_code,
  ADD UNIQUE KEY uq_users_referral_code (referral_code),
  ADD KEY idx_users_referred_by (referred_by);

CREATE TABLE IF NOT EXISTS referrals (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id      BIGINT UNSIGNED NOT NULL,
  referred_user_id BIGINT UNSIGNED NOT NULL,
  code             VARCHAR(16)     NOT NULL,
  status           ENUM('registered','verified','activated') NOT NULL DEFAULT 'registered',
  registered_at    DATETIME        NOT NULL,
  verified_at      DATETIME        NULL,
  activated_at     DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ref_referred (referred_user_id),
  KEY idx_ref_referrer (referrer_id),
  KEY idx_ref_status (status),
  CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_referred FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
