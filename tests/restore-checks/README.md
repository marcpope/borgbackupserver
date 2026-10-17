# Restore check tests

BBS has no unit-test framework, and restore checks are mostly about real
borg, real `sudo`, real MySQL locking and real files, so these tests run inside
a disposable container built from this checkout, against its MariaDB and borg,
with synthetic repositories only.

## Run

From the repository root:

```bash
# 1. Build and start the dev stack (own project, container, ports, volume)
docker compose -p bbs-rc-dev -f tests/restore-checks/docker-compose.dev.yml up -d --build
#    wait until `docker logs bbs-rc-dev` shows "BBS Container Ready"

# 2. Stop the container's own cron so nothing runs behind the tests' back
docker exec bbs-rc-dev bash -c 'rm -f /etc/cron.d/bbs-scheduler; pkill -x cron'

# 3. Copy the working tree in, including tests/ (kept out of images by
#    .dockerignore). Re-run after every edit; no rebuild needed.
tests/restore-checks/sync-to-dev.sh

# 4. Synthetic repositories, then the tests
docker exec -e BBS_RC_TEST=1 bbs-rc-dev bash /var/www/bbs/tests/restore-checks/fixtures.sh
docker exec -e BBS_RC_TEST=1 bbs-rc-dev php /var/www/bbs/tests/restore-checks/run.php
#    optional name filter:  ... run.php "DST"

# 5. Migration on an existing (pre-feature) database
git show 2cb16ab:schema.sql > /tmp/schema-base.sql
docker cp /tmp/schema-base.sql bbs-rc-dev:/tmp/schema-base.sql
docker exec -e BBS_RC_TEST=1 bbs-rc-dev php /var/www/bbs/tests/restore-checks/upgrade-test.php /tmp/schema-base.sql
```

Both scripts exit non-zero on any failure.

## Clean up

```bash
docker exec -e BBS_RC_TEST=1 bbs-rc-dev bash /var/www/bbs/tests/restore-checks/fixtures.sh clean
docker compose -p bbs-rc-dev -f tests/restore-checks/docker-compose.dev.yml down -v
```

The second command deletes the container and its volume, including every
fixture repository and database row.

## What is covered

`run.php` (48 tests): schedule maths including both DST transitions and
catch-up; plan validation; idempotent Run now, overlap and concurrent scheduler
passes; owner revocation; queue admission (one at a time, repository
exclusion, offline client, maintenance mode, never sent to agents); archive
selection (per plan vs repository, warnings, failed jobs, checkpoints,
imported, missing, stale); literal extraction of awkward names (Unicode,
spaces, colon, leading dash, `sh:*`, markup, mode 000); hashes; full archives
with symlinks, hard links, sparse files, FIFOs, device nodes; symlink-escape
archives with an outside sentinel; helper argument and cleanup refusals;
cancellation during a silent lock wait; bounded lock waits without
break-lock; helper time limit; a killed worker; queue deadline; missing
evidence; resource limits; bounded cleanup failure; Remote SSH success and
authentication failure; passphrase and key absence from argv, DB and logs;
report digest and canonical form; evidence retention; notification
deduplication and resolution; web permissions, CSRF and HTML escaping; the UI
flow from creation to printed report; admin access to evidence of deleted
clients.

`upgrade-test.php`: migration 137 on an existing database with jobs in every
state, replay safety, and column parity with a fresh install.

Not covered here: bare-metal Ubuntu (only the Docker image), Borg 1.2/1.3 on
the server, a mounted filesystem inside staging (mounting needs privileges the
container does not have), and the database becoming unavailable at the moment
a run finishes.
