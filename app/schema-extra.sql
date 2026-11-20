-- Tables propres à l'édition PHP.
--
-- L'édition Node tient ses plafonds de requêtes en mémoire du processus. Sur un
-- hébergement mutualisé, chaque requête est un processus neuf : le compte doit
-- donc vivre en base, sinon il repart de zéro à chaque appel et ne plafonne
-- plus rien.
CREATE TABLE IF NOT EXISTS rate_limits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  bucket TEXT NOT NULL,
  ip TEXT NOT NULL,
  hits INTEGER NOT NULL DEFAULT 0,
  window_start INTEGER NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_rate_limits_bucket_ip ON rate_limits(bucket, ip);
