FROM python:3.11-slim

# Runtime deps for the agent plus all database CLI tools so one image
# handles filesystem, MySQL, PostgreSQL, and MongoDB backups out of the
# box. Keep everything in a single layer and clean out apt caches.
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
        borgbackup \
        openssh-client \
        ca-certificates \
        curl \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# PostgreSQL clients from the PostgreSQL apt repository, several majors at
# once: pg_dump refuses a server newer than itself, and Debian's own
# postgresql-client is one major behind whatever people run (#495). The
# agent picks the pg_dump that matches the server it is dumping.
RUN install -d /etc/apt/keyrings && \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /etc/apt/keyrings/postgresql.asc && \
    echo "deb [signed-by=/etc/apt/keyrings/postgresql.asc] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo "$VERSION_CODENAME")-pgdg main" > /etc/apt/sources.list.d/pgdg.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        postgresql-client-16 postgresql-client-17 postgresql-client-18 \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# MongoDB database tools aren't in the Debian archive anymore — pull the
# official .deb directly from MongoDB. They ship Debian debs only for
# x86_64; for arm64 we use their Ubuntu 22.04 deb (minimal libc/krb5
# deps, installs cleanly on Debian). Pinned so image builds stay
# reproducible across runs.
ARG MONGO_TOOLS_VERSION=100.10.0
RUN arch=$(dpkg --print-architecture) && \
    case "$arch" in \
        amd64) mdb_filename="mongodb-database-tools-ubuntu2204-x86_64-${MONGO_TOOLS_VERSION}.deb" ;; \
        arm64) mdb_filename="mongodb-database-tools-ubuntu2204-arm64-${MONGO_TOOLS_VERSION}.deb" ;; \
        *) echo "Unsupported arch: $arch" && exit 1 ;; \
    esac && \
    curl -fsSL -o /tmp/mdbtools.deb \
        "https://fastdl.mongodb.org/tools/db/${mdb_filename}" && \
    apt-get update && \
    apt-get install -y --no-install-recommends /tmp/mdbtools.deb && \
    rm -rf /var/lib/apt/lists/* /tmp/mdbtools.deb

# Force UTF-8 so the agent's ASCII-locale re-exec branch stays dormant.
# BORG_BASE_DIR keeps borg's cache, config, and security dir under the
# persisted /etc/bbs-agent volume — without it they live in /root and are
# lost on container recreate, forcing borg to re-chunk every file on the
# next backup (#373).
ENV LC_ALL=C.UTF-8 \
    LANG=C.UTF-8 \
    PYTHONUNBUFFERED=1 \
    BORG_BASE_DIR=/etc/bbs-agent/borg

RUN mkdir -p /opt/bbs-agent /etc/bbs-agent
COPY agent/bbs-agent.py /opt/bbs-agent/bbs-agent.py
COPY docker/agent/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# SSH key + any locally-downloaded state persist across restarts.
VOLUME ["/etc/bbs-agent"]

ENTRYPOINT ["/entrypoint.sh"]
