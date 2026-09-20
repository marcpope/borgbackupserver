-- Migration 084 fixed existing Apprise email URLs and the standalone
-- notification-services form, but the integrated Settings > Apprise form
-- retained the old URL builder. Services created there after migration 084
-- can therefore still be missing the explicit TLS mode.
--
-- Repair those rows again for existing installations. This is intentionally
-- idempotent and uses the same port convention as migration 084:
--   :465 -> ssl (implicit TLS)
--   :25  -> insecure
--   anything else (normally :587) -> starttls
UPDATE notification_services
SET apprise_url = CONCAT(
    apprise_url,
    CASE WHEN apprise_url LIKE '%?%' THEN '&' ELSE '?' END,
    'mode=',
    CASE
        WHEN apprise_url REGEXP ':465(/|\\?|$)' THEN 'ssl'
        WHEN apprise_url REGEXP ':25(/|\\?|$)'  THEN 'insecure'
        ELSE 'starttls'
    END
)
WHERE service_type IN ('mailto', 'mailtos')
  AND apprise_url NOT LIKE '%mode=%';
