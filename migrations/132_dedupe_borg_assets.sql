-- A version sync briefly re-inserted assets whose glibc_version is NULL
-- (macOS, FreeBSD): the unique key treats NULLs as distinct. Keep the first
-- row of each asset.
DELETE a FROM borg_version_assets a
JOIN borg_version_assets b
  ON a.borg_version_id = b.borg_version_id AND a.asset_name = b.asset_name AND a.id > b.id;
