#!/usr/bin/env bash
# =============================================================================
#  Zero-downtime deployment with instant rollback (Linux).
#
#  Layout:
#    /var/www/my-shop/releases/20261020-0200/   one folder per release
#    /var/www/my-shop/shared/.env               shared secrets
#    /var/www/my-shop/shared/storage/           shared logs, sessions, uploads, backups
#    /var/www/my-shop/current -> releases/...   the web server points here (public/ inside)
#
#  Deploy:    ./deploy/deploy.sh /path/to/new/code
#  Rollback:  ./deploy/deploy.sh --rollback
# =============================================================================
set -euo pipefail

APP=/var/www/my-shop
KEEP=5

if [[ "${1:-}" == "--rollback" ]]; then
    previous=$(ls -1d "$APP"/releases/* | sort | tail -n 2 | head -n 1)
    ln -sfn "$previous" "$APP/current"
    echo "Rolled back to $(basename "$previous"). If the release ran migrations, run: php console migrate:rollback"
    exit 0
fi

SOURCE=${1:?usage: deploy.sh /path/to/code}
RELEASE="$APP/releases/$(date +%Y%m%d-%H%M%S)"

mkdir -p "$RELEASE" "$APP/shared/storage/logs" "$APP/shared/storage/app/private" "$APP/shared/uploads"
rsync -a --exclude .env --exclude storage --exclude backup --exclude .git "$SOURCE"/ "$RELEASE"/

ln -sfn "$APP/shared/.env" "$RELEASE/.env"
rm -rf "$RELEASE/storage" && ln -sfn "$APP/shared/storage" "$RELEASE/storage"
rm -rf "$RELEASE/public/uploads" && ln -sfn "$APP/shared/uploads" "$RELEASE/public/uploads"
cp "$SOURCE/public/uploads/.htaccess" "$APP/shared/uploads/.htaccess" 2>/dev/null || true

cd "$RELEASE"
php console lint
php console security:check || { echo "Fix the HIGH security issues before deploying."; exit 1; }
php console db:backup
php console down --secret="$(openssl rand -hex 12)" --retry=30
php console migrate --force
php console optimize:clear

ln -sfn "$RELEASE" "$APP/current"         # atomic switch
php "$APP/current/console" up
sudo supervisorctl restart 'my-shop-queue:*' || true

ls -1d "$APP"/releases/* | sort | head -n -"$KEEP" | xargs -r rm -rf
echo "Deployed $(basename "$RELEASE")."

# Prove the live release works: config, migrations, worker/scheduler heartbeats, backups, external scan.
sleep 70   # give the restarted worker and the scheduler time to report in
php "$APP/current/console" deploy:verify --url="$(grep -E '^APP_URL=' "$APP/shared/.env" | cut -d= -f2-)" \
    || echo "WARNING: deploy:verify reported problems above. Fix them, or roll back with: $0 --rollback"
