#!/usr/bin/env bash
#
# Build a release zip of the Multi Digital Agent plugin, ready to serve through
# the platform's remote-update channel.
#
# The zip's top-level folder MUST be "multioto-agent" so WordPress installs/updates
# it into the right directory. Upload the resulting file to the platform's plugin
# disk as {AGENT_PLUGIN_PATH}/{version}.zip (default: agent-plugin/<version>.zip)
# and set AGENT_PLUGIN_VERSION to the same version.
#
# Usage: wordpress-plugin/build.sh [output-dir]
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
src="$here/multioto-agent"
out_dir="${1:-$here/dist}"

version="$(grep -oiP "Version:\s*\K[0-9]+\.[0-9]+\.[0-9]+" "$src/multioto-agent.php" | head -1)"
if [ -z "$version" ]; then
  echo "Could not read plugin version from multioto-agent.php" >&2
  exit 1
fi

mkdir -p "$out_dir"
zip_path="$out_dir/$version.zip"
rm -f "$zip_path"

# Zip from the plugin's parent so the archive contains multioto-agent/... at the root.
( cd "$here" && zip -rq "$zip_path" "multioto-agent" -x '*.DS_Store' )

echo "Built $zip_path"
echo "Upload to the platform plugin disk as: agent-plugin/$version.zip"
