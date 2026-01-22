#!/bin/bash

# Starter Dashboard - Release Script
# Automates version bump, build, commit, tag, push, and GitHub release creation

set -e

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_FILE="${PLUGIN_DIR}/starter-dashboard.php"
README_FILE="${PLUGIN_DIR}/readme.txt"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get current version
CURRENT_VERSION=$(grep -m 1 "Version:" "${PLUGIN_FILE}" | sed 's/.*Version: *//' | tr -d ' \r')

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Starter Dashboard - Release Script${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "Current version: ${YELLOW}${CURRENT_VERSION}${NC}"
echo ""

# Check for uncommitted changes
if [[ -n $(git status --porcelain) ]]; then
    echo -e "${YELLOW}⚠️  You have uncommitted changes:${NC}"
    git status --short
    echo ""
    read -p "Continue anyway? (y/N): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${RED}Aborted.${NC}"
        exit 1
    fi
fi

# Get new version
if [[ -n "$1" ]]; then
    NEW_VERSION="$1"
else
    echo -e "Enter new version number (e.g., 4.2.0):"
    read -p "> " NEW_VERSION
fi

# Validate version format
if [[ ! $NEW_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo -e "${RED}Error: Invalid version format. Use X.Y.Z (e.g., 4.2.0)${NC}"
    exit 1
fi

# Check if version already exists as tag
if git tag -l | grep -q "^v${NEW_VERSION}$"; then
    echo -e "${RED}Error: Tag v${NEW_VERSION} already exists${NC}"
    exit 1
fi

echo ""
echo -e "New version: ${GREEN}${NEW_VERSION}${NC}"
echo ""

# Get release notes
echo -e "Enter release notes (end with empty line):"
echo -e "${YELLOW}Tip: Start lines with '- ' for bullet points${NC}"
RELEASE_NOTES=""
while IFS= read -r line; do
    [[ -z "$line" ]] && break
    RELEASE_NOTES="${RELEASE_NOTES}${line}"$'\n'
done

if [[ -z "$RELEASE_NOTES" ]]; then
    RELEASE_NOTES="Release v${NEW_VERSION}"
fi

echo ""
echo -e "${BLUE}━━━ Step 1/6: Updating version numbers ━━━${NC}"

# Update version in starter-dashboard.php
sed -i '' "s/Version: ${CURRENT_VERSION}/Version: ${NEW_VERSION}/" "${PLUGIN_FILE}"
echo -e "  ✓ Updated ${PLUGIN_FILE}"

# Update version in readme.txt
sed -i '' "s/Stable tag: ${CURRENT_VERSION}/Stable tag: ${NEW_VERSION}/" "${README_FILE}"
echo -e "  ✓ Updated ${README_FILE}"

echo ""
echo -e "${BLUE}━━━ Step 2/6: Building distribution ━━━${NC}"

# Run build script
"${PLUGIN_DIR}/build-dist.sh"

ZIP_FILE="${PLUGIN_DIR}/dist/starter-dashboard-${NEW_VERSION}.zip"

if [[ ! -f "$ZIP_FILE" ]]; then
    echo -e "${RED}Error: Build failed - ZIP file not created${NC}"
    exit 1
fi

echo -e "  ✓ Created ${ZIP_FILE}"

echo ""
echo -e "${BLUE}━━━ Step 3/6: Creating git commit ━━━${NC}"

git add -A
git commit -m "Release v${NEW_VERSION}

${RELEASE_NOTES}"

echo -e "  ✓ Commit created"

echo ""
echo -e "${BLUE}━━━ Step 4/6: Creating git tag ━━━${NC}"

git tag -a "v${NEW_VERSION}" -m "Version ${NEW_VERSION}"
echo -e "  ✓ Tag v${NEW_VERSION} created"

echo ""
echo -e "${BLUE}━━━ Step 5/6: Pushing to GitHub ━━━${NC}"

git push origin main --tags
echo -e "  ✓ Pushed to origin/main with tags"

echo ""
echo -e "${BLUE}━━━ Step 6/6: Creating GitHub Release ━━━${NC}"

# Check if gh CLI is available
if command -v gh &> /dev/null; then
    # Check if authenticated
    if gh auth status &> /dev/null; then
        gh release create "v${NEW_VERSION}" \
            "${ZIP_FILE}" \
            --title "v${NEW_VERSION}" \
            --notes "${RELEASE_NOTES}"
        echo -e "  ✓ GitHub Release created with ZIP attached"
    else
        echo -e "${YELLOW}  ⚠️  GitHub CLI not authenticated. Run 'gh auth login' first.${NC}"
        echo -e "  Manual step needed: Create release at:"
        echo -e "  ${BLUE}https://github.com/aleksanderem/starter-dashboard/releases/new?tag=v${NEW_VERSION}${NC}"
        echo -e "  And attach: ${ZIP_FILE}"
    fi
else
    echo -e "${YELLOW}  ⚠️  GitHub CLI (gh) not installed.${NC}"
    echo -e "  Manual step needed: Create release at:"
    echo -e "  ${BLUE}https://github.com/aleksanderem/starter-dashboard/releases/new?tag=v${NEW_VERSION}${NC}"
    echo -e "  And attach: ${ZIP_FILE}"
fi

echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  ✅ Release v${NEW_VERSION} complete!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "WordPress sites with this plugin will now see the update available."
echo ""
