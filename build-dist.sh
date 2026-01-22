#!/bin/bash

# Starter Dashboard - Distribution Build Script
# Creates a clean zip file for plugin distribution

set -e

PLUGIN_SLUG="starter-dashboard"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="/tmp/${PLUGIN_SLUG}-build"
DIST_DIR="${PLUGIN_DIR}/dist"

echo "🚀 Building Starter Dashboard for distribution..."

# Clean previous builds
rm -rf "${BUILD_DIR}"
rm -rf "${DIST_DIR}"
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}"
mkdir -p "${DIST_DIR}"

# Build command-menu React app
echo "📦 Building command-menu React app..."
cd "${PLUGIN_DIR}/command-menu"
npm run build

# Copy main plugin files
echo "📁 Copying plugin files..."
cp "${PLUGIN_DIR}/starter-dashboard.php" "${BUILD_DIR}/${PLUGIN_SLUG}/"
cp "${PLUGIN_DIR}/readme.txt" "${BUILD_DIR}/${PLUGIN_SLUG}/"
cp "${PLUGIN_DIR}/dashboard.css" "${BUILD_DIR}/${PLUGIN_SLUG}/"
cp "${PLUGIN_DIR}/dashboard.js" "${BUILD_DIR}/${PLUGIN_SLUG}/"
cp "${PLUGIN_DIR}/settings.css" "${BUILD_DIR}/${PLUGIN_SLUG}/"
cp "${PLUGIN_DIR}/settings.js" "${BUILD_DIR}/${PLUGIN_SLUG}/"
cp "${PLUGIN_DIR}/sortable.min.js" "${BUILD_DIR}/${PLUGIN_SLUG}/"

# Copy addons directory
echo "📁 Copying addons..."
cp -r "${PLUGIN_DIR}/addons" "${BUILD_DIR}/${PLUGIN_SLUG}/"

# Copy lib directory if exists
if [ -d "${PLUGIN_DIR}/lib" ]; then
    echo "📁 Copying lib..."
    cp -r "${PLUGIN_DIR}/lib" "${BUILD_DIR}/${PLUGIN_SLUG}/"
fi

# Copy docs directory
if [ -d "${PLUGIN_DIR}/docs" ]; then
    echo "📁 Copying docs..."
    cp -r "${PLUGIN_DIR}/docs" "${BUILD_DIR}/${PLUGIN_SLUG}/"
fi

# Copy command-menu dist only (no source files or node_modules)
echo "📁 Copying command-menu build..."
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}/command-menu/dist"
cp -r "${PLUGIN_DIR}/command-menu/dist/"* "${BUILD_DIR}/${PLUGIN_SLUG}/command-menu/dist/"

# Remove unnecessary files from build
echo "🧹 Cleaning up..."
find "${BUILD_DIR}" -name ".DS_Store" -delete
find "${BUILD_DIR}" -name "*.map" -delete
find "${BUILD_DIR}" -name ".git*" -delete
find "${BUILD_DIR}" -name "README.md" -delete
find "${BUILD_DIR}" -name "composer.json" -delete
find "${BUILD_DIR}" -name "license.txt" -delete

# Clean up plugin-update-checker - remove unnecessary files
if [ -d "${BUILD_DIR}/${PLUGIN_SLUG}/lib/plugin-update-checker" ]; then
    rm -rf "${BUILD_DIR}/${PLUGIN_SLUG}/lib/plugin-update-checker/languages"
    rm -rf "${BUILD_DIR}/${PLUGIN_SLUG}/lib/plugin-update-checker/css"
    rm -rf "${BUILD_DIR}/${PLUGIN_SLUG}/lib/plugin-update-checker/js"
fi

# Get version from plugin header
VERSION=$(grep -m 1 "Version:" "${PLUGIN_DIR}/starter-dashboard.php" | sed 's/.*Version: *//' | tr -d ' \r')
echo "📋 Plugin version: ${VERSION}"

# Create zip file
echo "📦 Creating zip file..."
cd "${BUILD_DIR}"
zip -r "${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}" -x "*.DS_Store"

# Also create a generic named zip for easy updates
cp "${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip" "${DIST_DIR}/${PLUGIN_SLUG}.zip"

# Cleanup
rm -rf "${BUILD_DIR}"

echo ""
echo "✅ Build complete!"
echo "📦 Distribution files created in: ${DIST_DIR}"
echo "   - ${PLUGIN_SLUG}-${VERSION}.zip"
echo "   - ${PLUGIN_SLUG}.zip"
echo ""
echo "📊 Zip size: $(du -h "${DIST_DIR}/${PLUGIN_SLUG}.zip" | cut -f1)"
