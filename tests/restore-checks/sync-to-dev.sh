#!/usr/bin/env bash
# Copy the working tree into the running development container without a
# rebuild, and install the helper where sudoers expects it. Host side.
set -euo pipefail
CONTAINER="${BBS_RC_CONTAINER:-bbs-rc-dev}"
cd "$(dirname "$0")/../.."
COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata --exclude=.git --exclude=vendor -cf - . | docker exec -i "$CONTAINER" tar -C /var/www/bbs -xf -
docker exec "$CONTAINER" bash -c 'chown -R www-data:www-data /var/www/bbs && install -m 755 /var/www/bbs/bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper'
