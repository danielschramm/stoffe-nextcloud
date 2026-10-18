#!/usr/bin/env bash
# Builds the installable app archive build/stoffe-<version>.tar.gz.
# The archive holds a single top-level folder "stoffe/" with only the files
# Nextcloud needs at runtime (no sources, no node_modules, no source maps).
set -euo pipefail

cd "$(dirname "$0")/.."
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)
if [ -z "$version" ]; then
	echo "No <version> found in appinfo/info.xml" >&2
	exit 1
fi

rm -rf js css build
npm ci
npm run build

staging=build/stoffe
mkdir -p "$staging"
cp -r appinfo lib templates img js css "$staging"/
cp LICENSE README.md INSTALLATION.md "$staging"/
find "$staging" -name '*.map' -delete

tar -czf "build/stoffe-$version.tar.gz" -C build stoffe
rm -rf "$staging"
echo "build/stoffe-$version.tar.gz"
