FROM php:8.4-apache

# Install system dependencies, apply security patches and clean up in one layer
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    git \
    curl \
    ca-certificates \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libfuse-dev \
    fuse \
    zip \
    unzip \
    cron \
    sudo \
    mariadb-server \
    mariadb-client \
    borgbackup \
    openssh-client \
    openssh-server \
    python3-pip \
    gnupg \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Install rclone from official binary (Debian package ships with outdated Go runtime)
RUN ARCH=$(dpkg --print-architecture) && \
    curl -fsSL "https://downloads.rclone.org/rclone-current-linux-${ARCH}.zip" -o /tmp/rclone.zip && \
    unzip -q /tmp/rclone.zip -d /tmp && \
    cp /tmp/rclone-*/rclone /usr/bin/rclone && \
    chmod 755 /usr/bin/rclone && \
    rm -rf /tmp/*

# Install ClickHouse (catalog engine) and clean up in one layer
RUN ARCH=$(dpkg --print-architecture) && \
    curl -fsSL -A 'Mozilla/5.0' 'https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key' | \
        gpg --dearmor -o /usr/share/keyrings/clickhouse-keyring.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg arch=${ARCH}] https://packages.clickhouse.com/deb stable main" \
        > /etc/apt/sources.list.d/clickhouse.list && \
    apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends clickhouse-server clickhouse-client && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Disable ClickHouse system log tables (heavy idle disk I/O)
COPY config/clickhouse-server-override.xml /etc/clickhouse-server/config.d/bbs-override.xml

# Install Apprise and wheel in a single pip call to avoid cache between calls
RUN pip3 install --break-system-packages --no-cache-dir apprise wheel>=0.46.2 && \
    rm -rf /root/.cache /usr/lib/python3/dist-packages/wheel*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mbstring

# PHP configuration: increase max_execution_time (default 30s is too short for
# large backup operations, catalog imports, and API calls under load)
RUN echo "max_execution_time = 300" > /usr/local/etc/php/conf.d/bbs.ini

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Enable Apache modules and configure in one layer
RUN a2enmod rewrite && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf && \
    echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/bbs/public\n\
    <Directory /var/www/bbs/public>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Configure SSH - disable password auth, only allow key-based
RUN sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config \
    && sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config \
    && echo "PermitRootLogin no" >> /etc/ssh/sshd_config

# Create directories
RUN mkdir -p /var/www/bbs /var/bbs/home /var/bbs/backups /var/bbs/cache /run/mysqld /run/sshd \
    && chown -R www-data:www-data /var/www/bbs /var/bbs \
    && chown mysql:mysql /run/mysqld

# Copy application code, install dependencies, and clean up in one layer
COPY . /var/www/bbs/
RUN cd /var/www/bbs && composer install --no-dev --optimize-autoloader --no-interaction --quiet \
    && rm -rf /root/.composer/cache \
    && chown -R www-data:www-data /var/www/bbs

# Install SSH helper and gate
RUN cp /var/www/bbs/bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper \
    && cp /var/www/bbs/bin/bbs-ssh-gate /usr/local/bin/bbs-ssh-gate \
    && chmod 755 /usr/local/bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-gate

# Configure scoped sudoers for www-data
RUN echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper, /var/www/bbs/bin/bbs-update" > /etc/sudoers.d/bbs-ssh-helper \
    && echo "www-data ALL=(bbs-*) NOPASSWD: /usr/bin/borg, /usr/local/bin/borg, /usr/bin/rclone, /usr/bin/env" > /etc/sudoers.d/bbs-borg \
    && chmod 440 /etc/sudoers.d/bbs-ssh-helper /etc/sudoers.d/bbs-borg

# Copy entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Support custom UID/GID mapping for bind mounts
ENV PUID=33
ENV PGID=33

EXPOSE 80 22

ENTRYPOINT ["/entrypoint.sh"]
