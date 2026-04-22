#!/bin/bash

set -e

PLUGIN_FILE="anibas-file-manager.php"
README_FILE="README.md"
README_TXT_FILE="README.txt"

# ─── Read current version ────────────────────────────────────────────────────
CURRENT_VERSION=$(grep -m1 '^ \* Version:' "$PLUGIN_FILE" \
    | sed 's/.*Version:[[:space:]]*//' \
    | tr -d '[:space:]')

# ─── Read WordPress version from local wp-includes/version.php ───────────────
WP_VERSION_FILE="../../wp-includes/version.php"
# Get fallback from current README.txt "Tested up to" value
WP_VERSION_FALLBACK=$(grep "^Tested up to:" "$README_TXT_FILE" 2>/dev/null | sed 's/Tested up to: //' | tr -d '[:space:]')

if [ -f "$WP_VERSION_FILE" ]; then
    WP_VERSION=$(grep -m1 "^\$wp_version = " "$WP_VERSION_FILE" \
        | sed "s/.*= '\(.*\)'.*/\1/" \
        | cut -d'.' -f1,2)  # Extract MAJOR.MINOR (e.g., 6.9.4 → 6.9)
    echo "Local WordPress version: $WP_VERSION"
else
    echo "⚠ Warning: Could not find wp-includes/version.php. Using current README.txt value: $WP_VERSION_FALLBACK"
    WP_VERSION="$WP_VERSION_FALLBACK"
fi

# ─── Ask for version ─────────────────────────────────────────────────────────
echo ""
echo "━━━ Versioning ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Current version: $CURRENT_VERSION"
echo -n "Enter new version number (press Enter to keep current): "

while true; do
    read VERSION

    # Default to current version if empty
    if [ -z "$VERSION" ]; then
        VERSION="$CURRENT_VERSION"
        echo "Keeping version: $VERSION"
        break
    fi

    # Validate semver format
    if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
        echo -n "⚠ Warning: '$VERSION' is not standard semver (x.y.z). WordPress requires standard format. Continue? [y/N/r (retry)]: "
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

if [ "$VERSION" = "$CURRENT_VERSION" ]; then
    echo "Version unchanged: $VERSION"
else
    echo "Updating version $CURRENT_VERSION → $VERSION ..."

    # Plugin header: " * Version:           1.0.0"
    sed -i '' "s/^\( \* Version:\)[[:space:]]*.*/\1           $VERSION/" "$PLUGIN_FILE"

    # PHP constant: define('ANIBAS_FILE_MANAGER_VERSION', '1.0.0')
    sed -i '' "s/define('ANIBAS_FILE_MANAGER_VERSION', '[^']*')/define('ANIBAS_FILE_MANAGER_VERSION', '$VERSION')/" "$PLUGIN_FILE"

    # README.md version line: **Version:** 1.0.0
    if [ -f "$README_FILE" ]; then
        sed -i '' "s/^\*\*Version:\*\*[[:space:]]*.*/\*\*Version:\*\* $VERSION  /" "$README_FILE"
    fi

    echo "✓ Version updated."
fi

# ─── Update README.txt (WordPress plugin readme format) ───────────────────────
if [ -f "$README_TXT_FILE" ]; then
    echo ""
    echo "━━━ Updating $README_TXT_FILE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    # Update Stable tag to always match VERSION
    echo "Updating Stable tag: $VERSION"
    sed -i '' "s/^Stable tag:.*/Stable tag: $VERSION/" "$README_TXT_FILE"

    # Update Tested up to if local WordPress version is higher
    CURRENT_TESTED=$(grep "^Tested up to:" "$README_TXT_FILE" | sed 's/Tested up to: //' | tr -d '[:space:]')
    echo "Current 'Tested up to': $CURRENT_TESTED"
    echo "Local WordPress version: $WP_VERSION"

    if [ "$WP_VERSION" != "$CURRENT_TESTED" ]; then
        echo "Updating 'Tested up to': $CURRENT_TESTED → $WP_VERSION"
        sed -i '' "s/^Tested up to:.*/Tested up to: $WP_VERSION/" "$README_TXT_FILE"
    else
        echo "'Tested up to' is already up to date."
    fi

    echo "✓ $README_TXT_FILE updated."
else
    echo "⚠ Warning: $README_TXT_FILE not found."
fi

# ─── Build ────────────────────────────────────────────────────────────────────

echo ""
echo "━━━ Composer Install/Optimize ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
composer install --no-dev --optimize-autoloader

echo ""
echo "━━━ NPM Build ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
npm run build
echo "✓ Build successful."

# ─── Package ─────────────────────────────────────────────────────────────────
ZIP_NAME="anibas-file-manager.zip"
echo ""
echo "━━━ Packaging $ZIP_NAME ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Remove any existing zip with same name
rm -f "$ZIP_NAME"

zip -r "$ZIP_NAME" . \
    -x "node_modules/*" \
    -x ".git/*" \
    -x ".gitignore" \
    -x ".gitattributes" \
    -x "src/*" \
    -x "*.sh" \
    -x "*.zip" \
    -x "package.json" \
    -x "package-lock.json" \
    -x "composer.json" \
    -x "composer.lock" \
    -x "*.yml" \
    -x "*.yaml" \
    -x "vite.config.*" \
    -x "tsconfig.*" \
    -x ".DS_Store" \
    -x "tests/*" \
    -x "benchmarks-copy.php" \
    -x "i18n-replace.js" \
    -x "assets/*"

echo ""
echo "✓ Created: $ZIP_NAME"
echo "  Size: $(du -sh "$ZIP_NAME" | cut -f1)"
