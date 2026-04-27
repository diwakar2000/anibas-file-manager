#!/bin/bash

set -e

PLUGIN_FILE="anibas-file-manager.php"
README_FILE="README.md"
README_TXT_FILE="readme.txt"
PACKAGE_FILE="package.json"
PACKAGE_LOCK_FILE="package-lock.json"
WP_VERSION_FILE="../../../wp-includes/version.php"

if [ ! -f "$README_TXT_FILE" ] && [ -f "README.txt" ]; then
    README_TXT_FILE="README.txt"
fi

if [ ! -f "$PLUGIN_FILE" ]; then
    echo "Error: $PLUGIN_FILE not found. Run this from the plugin root."
    exit 1
fi

CURRENT_VERSION=$(grep -m1 '^ \* Version:' "$PLUGIN_FILE" \
    | sed 's/.*Version:[[:space:]]*//' \
    | tr -d '[:space:]')

if [ -z "$CURRENT_VERSION" ]; then
    echo "Error: Could not read plugin version from $PLUGIN_FILE."
    exit 1
fi

README_TESTED_FALLBACK=$(grep "^Tested up to:" "$README_TXT_FILE" 2>/dev/null \
    | sed 's/Tested up to: //' \
    | tr -d '[:space:]')

if [ -f "$WP_VERSION_FILE" ]; then
    WP_VERSION=$(grep -m1 "^\$wp_version = " "$WP_VERSION_FILE" \
        | sed "s/.*= '\(.*\)'.*/\1/" \
        | cut -d'.' -f1,2)
    echo "Local WordPress version: $WP_VERSION"
else
    WP_VERSION="$README_TESTED_FALLBACK"
    echo "Warning: Could not find $WP_VERSION_FILE. Using readme.txt Tested up to value: $WP_VERSION"
fi

echo ""
echo "Versioning"
echo "Current plugin header version: $CURRENT_VERSION"
echo -n "Enter new version number (press Enter to keep current): "

while true; do
    read VERSION

    if [ -z "$VERSION" ]; then
        VERSION="$CURRENT_VERSION"
        echo "Keeping version: $VERSION"
        break
    fi

    if ! echo "$VERSION" | grep -qE '^[0-9]+(\.[0-9]+){1,2}$'; then
        echo -n "Warning: '$VERSION' is not a standard WordPress-style version like 1.0 or 1.0.0. Continue? [y/N/r]: "
        read CONFIRM
        case "$CONFIRM" in
            [Yy]* )
                break
                ;;
            [Rr]* )
                echo -n "Enter new version number: "
                continue
                ;;
            * )
                echo "Aborting."
                exit 1
                ;;
        esac
    fi

    break
done

echo ""
echo "Updating version metadata to $VERSION..."

# Main plugin header.
sed -i '' "s/^\( \* Version:\)[[:space:]]*.*/\1           $VERSION/" "$PLUGIN_FILE"

# Runtime constant. This runs even when VERSION is unchanged, so a previous
# header/constant mismatch is repaired instead of silently preserved.
sed -i '' "s/define( 'ANIBAS_FILE_MANAGER_VERSION', '[^']*' )/define( 'ANIBAS_FILE_MANAGER_VERSION', '$VERSION' )/" "$PLUGIN_FILE"
sed -i '' "s/define('ANIBAS_FILE_MANAGER_VERSION', '[^']*')/define('ANIBAS_FILE_MANAGER_VERSION', '$VERSION')/" "$PLUGIN_FILE"

if [ -f "$README_FILE" ]; then
    sed -i '' "s/^\*\*Version:\*\*[[:space:]]*.*/\*\*Version:\*\* $VERSION  /" "$README_FILE"
fi

if [ -f "$README_TXT_FILE" ]; then
    sed -i '' "s/^Stable tag:.*/Stable tag: $VERSION/" "$README_TXT_FILE"

    if [ -n "$WP_VERSION" ]; then
        sed -i '' "s/^Tested up to:.*/Tested up to: $WP_VERSION/" "$README_TXT_FILE"
    fi

    if grep -q '^= [0-9][0-9.]* =' "$README_TXT_FILE"; then
        ANIBAS_FM_VERSION="$VERSION" perl -0pi -e 's/^= [0-9][0-9.]* =/= $ENV{"ANIBAS_FM_VERSION"} =/m' "$README_TXT_FILE"
    fi
fi

if [ -f "$PACKAGE_FILE" ]; then
    ANIBAS_FM_VERSION="$VERSION" node -e '
const fs = require("fs");
const version = process.env.ANIBAS_FM_VERSION;
const file = "package.json";
const pkg = JSON.parse(fs.readFileSync(file, "utf8"));
pkg.version = version;
fs.writeFileSync(file, JSON.stringify(pkg, null, 2) + "\n");
'
fi

if [ -f "$PACKAGE_LOCK_FILE" ]; then
    ANIBAS_FM_VERSION="$VERSION" node -e '
const fs = require("fs");
const version = process.env.ANIBAS_FM_VERSION;
const file = "package-lock.json";
const lock = JSON.parse(fs.readFileSync(file, "utf8"));
lock.version = version;
if (lock.packages && lock.packages[""]) {
  lock.packages[""].version = version;
}
fs.writeFileSync(file, JSON.stringify(lock, null, 2) + "\n");
'
fi

echo "Done."
echo "Plugin version: $VERSION"
if [ -n "$WP_VERSION" ]; then
    echo "Tested up to: $WP_VERSION"
fi
