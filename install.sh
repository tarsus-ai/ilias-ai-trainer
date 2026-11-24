#!/usr/bin/env bash
#
# Tarsus for ILIAS - installer.
#
# Puts the latest release of the plugin into your ILIAS and lets ILIAS find
# it. Run it on the server ILIAS runs on:
#
#   curl -fsSL https://raw.githubusercontent.com/tarsus-ai/ilias-ai-trainer/main/install.sh | sudo bash
#
# If it cannot find ILIAS by itself, add the ILIAS folder at the end:
#
#   curl -fsSL https://raw.githubusercontent.com/tarsus-ai/ilias-ai-trainer/main/install.sh | sudo bash -s /var/www/ilias
#
# The same command updates the plugin. It never deletes anything: an older
# Tarsus folder is moved to /tmp first. Afterwards ILIAS needs two clicks in
# the browser (Install and Activate, or Update) - see the README.
#
# @author  Tarsus GmbH
# @license GPL-3.0-or-later

set -euo pipefail

REPO="tarsus-ai/ilias-ai-trainer"

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✔\033[0m %s\n' "$*"; }
note() { printf '  \033[33m!\033[0m %s\n' "$*"; }
fail() { printf '\n  \033[31m✘ %s\033[0m\n\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || fail "Please run this with sudo, exactly as written in the README."
command -v php >/dev/null 2>&1 || fail "PHP was not found. Please run this on the server ILIAS runs on."

# --------------------------------------------------------------- 1. ILIAS
say "Step 1 of 4: looking for ILIAS"
ILIAS="${1:-}"
if [ -z "$ILIAS" ]; then
    for dir in /var/www/ilias /var/www/html/ilias /var/www/html /srv/www/ilias /srv/www/htdocs/ilias /srv/ilias /opt/ilias /var/www/ILIAS; do
        if [ -f "$dir/ilias.ini.php" ]; then
            ILIAS="$dir"
            break
        fi
    done
fi
if [ -z "$ILIAS" ]; then
    found="$(find / -maxdepth 6 -name ilias.ini.php -not -path '/proc/*' 2>/dev/null | head -n 1 || true)"
    if [ -n "$found" ]; then
        ILIAS="$(dirname "$found")"
    fi
fi
ILIAS="${ILIAS%/}"
if [ -z "$ILIAS" ] || [ ! -f "$ILIAS/ilias.ini.php" ]; then
    fail "ILIAS was not found. Run the command again with your ILIAS folder at the end: ... | sudo bash -s /var/www/ilias"
fi
if [ -f "$ILIAS/public/index.php" ]; then
    SLOT="$ILIAS/public/Customizing/global/plugins/Services/Repository/RepositoryObject"
    ok "Found ILIAS 10 or newer in $ILIAS"
else
    SLOT="$ILIAS/Customizing/global/plugins/Services/Repository/RepositoryObject"
    ok "Found ILIAS 9 in $ILIAS"
fi

# The plugin's files belong to whoever owns ILIAS' Customizing folder.
OWNER_OF="$(dirname "$(dirname "$(dirname "$(dirname "$(dirname "$SLOT")")")")")"
[ -d "$OWNER_OF" ] || OWNER_OF="$ILIAS"
OWNER="$(stat -c '%U' "$OWNER_OF")"
GROUP="$(stat -c '%G' "$OWNER_OF")"

# --------------------------------------------------------------- 2. Download
say "Step 2 of 4: downloading the latest Tarsus plugin"
TMP="$(mktemp -d)"
trap 'rm -rf -- "${TMP:?}"' EXIT

fetch() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$1" -o "$2"
    elif command -v wget >/dev/null 2>&1; then
        wget -q "$1" -O "$2"
    else
        php -r 'exit(@copy($argv[1], $argv[2]) ? 0 : 1);' "$1" "$2"
    fi
}

fetch "https://api.github.com/repos/$REPO/releases/latest" "$TMP/release.json" \
    || fail "GitHub could not be reached. This server needs internet access for the download."
URL="$(php -r '$r = json_decode((string) file_get_contents($argv[1]), true); foreach (($r["assets"] ?? []) as $a) { if (substr((string) $a["name"], -4) === ".zip") { echo $a["browser_download_url"]; break; } }' "$TMP/release.json")"
TAG="$(php -r '$r = json_decode((string) file_get_contents($argv[1]), true); echo $r["tag_name"] ?? "";' "$TMP/release.json")"
[ -n "$URL" ] || fail "The latest release has no plugin file. Please try again later."
fetch "$URL" "$TMP/tarsus.zip" || fail "The download failed. Please try again."
ok "Downloaded Tarsus ${TAG#v}"

# --------------------------------------------------------------- 3. Unpack
say "Step 3 of 4: putting the plugin into ILIAS"
mkdir -p "$SLOT"
if [ -e "$SLOT/Tarsus" ]; then
    BACKUP="/tmp/Tarsus-before-$(date +%Y%m%d-%H%M%S)"
    mv "$SLOT/Tarsus" "$BACKUP"
    ok "The version you had is kept in $BACKUP"
fi
php -r '$z = new ZipArchive(); if ($z->open($argv[1]) !== true || !$z->extractTo($argv[2])) { exit(1); } $z->close();' "$TMP/tarsus.zip" "$SLOT" \
    || fail "The plugin could not be unpacked. PHP's zip extension is needed (ILIAS needs it too)."
[ -f "$SLOT/Tarsus/plugin.php" ] || fail "The plugin folder is incomplete. Please run the command again."
chown -R "$OWNER:$GROUP" "$SLOT/Tarsus"
ok "The plugin is in $SLOT/Tarsus"

# --------------------------------------------------------------- 4. Composer
say "Step 4 of 4: letting ILIAS find the plugin (about a minute)"
if command -v composer >/dev/null 2>&1; then
    COMPOSER=("$(command -v composer)")
elif [ -f "$ILIAS/composer.phar" ]; then
    COMPOSER=(php "$ILIAS/composer.phar")
else
    fail "Composer was not found. Please ask whoever installed ILIAS to run 'composer du' in $ILIAS."
fi
VENDOR_OWNER="$(stat -c '%U' "$ILIAS/vendor" 2>/dev/null || echo root)"
cd "$ILIAS"
if [ "$VENDOR_OWNER" = "root" ]; then
    RUN=(env COMPOSER_ALLOW_SUPERUSER=1)
else
    RUN=(sudo -u "$VENDOR_OWNER" -H)
fi
"${RUN[@]}" "${COMPOSER[@]}" dump-autoload --no-interaction >"$TMP/composer.log" 2>&1 || {
    tail -n 20 "$TMP/composer.log"
    fail "ILIAS could not be refreshed (see the lines above)."
}
ok "ILIAS knows the plugin now"

# --------------------------------------------------------------- Cron
if ! grep -rqsF "cron.php" /etc/crontab /etc/cron.d /var/spool/cron 2>/dev/null; then
    say "One more thing for your IT"
    note "No cron job for ILIAS was found on this server. Without it, edited course material"
    note "is only sent to Tarsus again when a teacher opens the Materials tab. See the README,"
    note "section 'Keep material up to date automatically'."
fi

say "Done - Tarsus ${TAG#v} is on your server."
echo "  Now continue in your web browser (part 2 of the guide):"
echo "  Administration > Extending ILIAS > Plugins > Tarsus: Install, then Activate."
echo "  If you are updating: Update, then Refresh Languages."
echo
