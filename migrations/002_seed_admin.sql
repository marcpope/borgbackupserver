-- Migration 002 used to insert a default admin with the password "admin".
-- schema.sql and the installers create the admin now. Kept as a no-op so the
-- numbering stays intact; it must never recreate that account.
DO 0;
