#!/usr/bin/env bash
#
# RailTime — Deployment
#
# Aufruf:
#   bash scripts/deploy.sh
#
# Fuer Plesk (Git-Erweiterung -> "Zusaetzliche Bereitstellungsaktionen"):
#   cd /var/www/vhosts/app.rail-time.de/rail-time-app && bash scripts/deploy.sh
#
# Der Ablauf ist idempotent: mehrfaches Ausfuehren schadet nicht.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Einstellungen
# ---------------------------------------------------------------------------

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

# Die Plesk-PHP-Version, unter der Web und Queue laufen. NICHT /usr/bin/php:
# das ist eine andere Version mit anderen Extensions.
PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"

COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || echo /usr/local/bin/composer)}"

# Node: Plesk bringt neuere Versionen mit als das System (18.x ist EOL).
# Die hoechste vorhandene wird bevorzugt.
for NODE_DIR in /opt/plesk/node/26/bin /opt/plesk/node/24/bin; do
    [ -x "$NODE_DIR/npm" ] && export PATH="$NODE_DIR:$PATH" && break
done

# Migrationen mitlaufen lassen? Auf 0 setzen, wenn Sie sie getrennt fahren.
RUN_MIGRATIONS="${RUN_MIGRATIONS:-1}"

# Wartungsseite waehrend Migrationen. 0 = ohne Unterbrechung deployen.
USE_MAINTENANCE="${USE_MAINTENANCE:-1}"

# Konfiguration nach dem Deploy cachen? BEWUSST AUS.
# Mit gecachter Konfiguration wirkt eine spaetere .env-Aenderung erst nach
# erneutem `config:cache` — das ueberrascht zuverlaessig. Anrufeinstellungen
# kommen ohnehin aus der Datenbank und sind davon unabhaengig.
CACHE_CONFIG="${CACHE_CONFIG:-0}"

cd "$APP_DIR"

# ---------------------------------------------------------------------------
# Hilfsfunktionen
# ---------------------------------------------------------------------------

step() { printf '\n\033[1;36m▶ %s\033[0m\n' "$1"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$1"; }

# Der Eigentuemer, dem die Anwendung gehoeren MUSS. Wird aus einer Datei
# gelesen, die der Webserver ohnehin ausliefert – so bleibt es richtig,
# auch wenn sich der Plesk-Systembenutzer einmal aendert.
APP_OWNER="$(stat -c '%U:%G' public/index.php)"

# Laeuft das Skript als root, entstehen Dateien, die der Webserver spaeter
# nicht mehr schreiben kann (Cache, Logs, Sessions). Das ist die haeufigste
# Ursache fuer eine Anwendung, die nach einem Deployment "grundlos" 500er
# wirft. Deshalb wird am Ende IMMER der Eigentuemer geradegezogen.
IS_ROOT=0
[ "$(id -u)" -eq 0 ] && IS_ROOT=1

restore_ownership() {
    if [ "$IS_ROOT" -eq 1 ]; then
        # node_modules MUSS mit: Vite legt dort .vite-temp an. Bleibt das
        # root-eigen, scheitert jeder spaetere Build des Plesk-Benutzers mit
        # "EACCES: permission denied" — genau so am 29.07.2026 passiert.
        chown -R "$APP_OWNER" \
            storage bootstrap/cache public/build vendor node_modules \
            app resources routes config lang scripts 2>/dev/null || true
    fi
}

# Bricht das Skript ab, darf die Wartungsseite nicht stehen bleiben.
cleanup() {
    local code=$?
    if [ "$USE_MAINTENANCE" -eq 1 ] && [ -f storage/framework/down ]; then
        "$PHP_BIN" artisan up >/dev/null 2>&1 || true
        warn "Wartungsmodus wurde beendet."
    fi
    restore_ownership
    [ $code -ne 0 ] && printf '\n\033[1;31m✗ Deployment abgebrochen (Code %s)\033[0m\n' "$code"
    exit $code
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
printf '\033[1mRailTime-Deployment\033[0m  %s\n' "$(date '+%d.%m.%Y %H:%M:%S')"
printf '  Verzeichnis : %s\n  PHP         : %s (%s)\n  Node        : %s\n  Eigentuemer : %s\n' \
    "$APP_DIR" "$PHP_BIN" "$("$PHP_BIN" -r 'echo PHP_VERSION;')" \
    "$(node -v 2>/dev/null || echo 'nicht gefunden')" "$APP_OWNER"
[ "$IS_ROOT" -eq 1 ] && warn "Laeuft als root – Eigentuemer werden am Ende korrigiert."

# ---------------------------------------------------------------------------
step "PHP-Abhaengigkeiten"
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --prefer-dist
ok "composer install"

# ---------------------------------------------------------------------------
step "E-Mail-Kompatibilitaetskatalog"
# Der Editor und jede Veroeffentlichung arbeiten fail-closed. Deshalb wird der
# reale, versionierte Katalog vor Frontend-Build, Wartungsmodus und Migrationen
# ueber denselben Loader wie im Webprozess geprueft. Ein unvollstaendiger
# Checkout kann die laufende Vorversion so nicht erst im Editor blockieren.
"$PHP_BIN" artisan mail:compatibility-catalog:check --no-interaction
ok "Kompatibilitaetskatalog erreichbar und gueltig"

# ---------------------------------------------------------------------------
step "Frontend bauen"
if [ ! -d node_modules ] || [ package-lock.json -nt node_modules ]; then
    npm ci --no-audit --no-fund
    ok "npm ci (Abhaengigkeiten waren nicht aktuell)"
else
    ok "node_modules aktuell – npm ci uebersprungen"
fi
npm run build
ok "npm run build"

# Aufraeumen NACH dem Build: vite.config.js setzt bewusst emptyOutDir=false
# (public/build enthaelt handgepflegte Ordner libs/, css/, fonts/, icons/),
# weshalb jeder Build seine Vorgaenger liegen laesst. Erst nach dem Build zu
# raeumen heisst: Es gibt kein Zeitfenster ohne auslieferbare Dateien.
node scripts/prune-build-assets.mjs || warn "Aufraeumen der Assets fehlgeschlagen"

# ---------------------------------------------------------------------------
if [ "$RUN_MIGRATIONS" -eq 1 ]; then
    step "Datenbank"
    if [ "$USE_MAINTENANCE" -eq 1 ]; then
        "$PHP_BIN" artisan down --render="errors::503" --retry=15 >/dev/null 2>&1 || true
        ok "Wartungsseite aktiv"
    fi
    "$PHP_BIN" artisan migrate --force
    ok "migrate"
fi

# ---------------------------------------------------------------------------
step "Caches leeren"
"$PHP_BIN" artisan optimize:clear
ok "optimize:clear (Konfiguration, Routen, Views, Kompiliertes, Cache)"
"$PHP_BIN" artisan cache:clear
ok "cache:clear"

# ---------------------------------------------------------------------------
step "Autoloader"
"$COMPOSER_BIN" dump-autoload --optimize --no-interaction
ok "dump-autoload"
"$COMPOSER_BIN" clear-cache --no-interaction >/dev/null
ok "composer clear-cache"

# ---------------------------------------------------------------------------
step "Caches aufbauen"
"$PHP_BIN" artisan route:cache
ok "route:cache"

if [ "$CACHE_CONFIG" -eq 1 ]; then
    "$PHP_BIN" artisan config:cache
    ok "config:cache"
else
    ok "config:cache uebersprungen (CACHE_CONFIG=1 aktiviert es)"
fi

# view:cache laeuft hier bewusst NICHT: resources/views/teams/show.blade.php
# bricht die Kompilierung ab und wuerde das ganze Deployment scheitern lassen.
# Siehe .lmzdev/befunde-und-empfehlungen/BEFUNDE.md, Punkt C2.
warn "view:cache uebersprungen (bekannter Fehler in teams/show.blade.php)"

# ---------------------------------------------------------------------------
if [ "$USE_MAINTENANCE" -eq 1 ] && [ -f storage/framework/down ]; then
    step "Anwendung freigeben"
    "$PHP_BIN" artisan up
    ok "up"
fi

# ---------------------------------------------------------------------------
step "Dienste neu starten"
# Queue-Worker halten den alten Code im Speicher – ohne Neustart laufen sie
# nach dem Deployment mit der Vorversion weiter.
"$PHP_BIN" artisan queue:restart >/dev/null
ok "queue:restart"

# Reverb ebenso. Schlaegt fehl, wenn der Dienst gerade nicht laeuft – das ist
# kein Grund, das Deployment abzubrechen.
if "$PHP_BIN" artisan reverb:restart >/dev/null 2>&1; then
    ok "reverb:restart"
else
    warn "reverb:restart nicht moeglich (laeuft der Dienst?)"
fi

# ---------------------------------------------------------------------------
step "Abschluss"
restore_ownership
ok "Dateieigentuemer geprueft"

WRONG=$(find . -path ./vendor -prune -o -path ./node_modules -prune -o ! -user "${APP_OWNER%%:*}" -print 2>/dev/null | wc -l)
if [ "$WRONG" -gt 0 ]; then
    warn "$WRONG Datei(en) mit abweichendem Eigentuemer – bitte pruefen"
else
    ok "Alle Dateien gehoeren $APP_OWNER"
fi

printf '\n\033[1;32m✓ Deployment abgeschlossen\033[0m  %s\n\n' "$(date '+%H:%M:%S')"
