#!/usr/bin/env sh
# tools/run-plugin-check.sh
#
# Run WordPress Plugin Check (PCP) against day-one-importer inside this
# repository's wp-env environment. Intended for pre-submission verification
# only — it is NOT wired into normal lint/test loops. Invoke explicitly via
# `composer plugin-check` or by running this script directly.
#
# What it does:
#   1. Verifies prerequisites (npx, docker, wp-env).
#   2. Starts wp-env if it is not already running (idempotent).
#   3. Installs and activates the `plugin-check` plugin if not already active
#      (idempotent).
#   4. Runs `wp plugin check day-one-importer --checks=all` and prints a
#      tabular report.
#   5. Exits non-zero when PCP reports any error or warning so this script
#      can gate a release.
#
# Exit codes:
#   0  PCP completed and reported zero findings.
#   1  PCP completed and reported one or more findings (error or warning).
#   2  Environment / setup failure (Docker not running, port conflict,
#      wp-env unavailable, etc.). A helpful diagnostic message is printed.

set -eu

SCRIPT_NAME="run-plugin-check.sh"
PLUGIN_SLUG="day-one-importer"

log() {
	printf '[%s] %s\n' "$SCRIPT_NAME" "$1"
}

fail_env() {
	# Setup / environment failure — distinct from PCP findings.
	printf '[%s] ERROR: %s\n' "$SCRIPT_NAME" "$1" >&2
	if [ "${2:-}" != "" ]; then
		printf '[%s] hint: %s\n' "$SCRIPT_NAME" "$2" >&2
	fi
	exit 2
}

require_cmd() {
	# require_cmd <binary> <hint>
	if ! command -v "$1" >/dev/null 2>&1; then
		fail_env "required command not found: $1" "$2"
	fi
}

require_cmd npx "Install Node.js (which ships npx) — https://nodejs.org/"
require_cmd docker "Install Docker Desktop and make sure the Docker daemon is running — https://www.docker.com/products/docker-desktop"

# Confirm the Docker daemon is reachable. wp-env shells out to docker compose
# and will fail late with a long stack trace otherwise.
if ! docker info >/dev/null 2>&1; then
	fail_env "Docker is installed but the daemon is not responding." "Start Docker Desktop (or your Docker service) and rerun this script."
fi

# Resolve the repository root from this script's location, so the script can
# be invoked from any working directory (e.g. via composer scripts).
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/.." && pwd)
cd "$REPO_ROOT"

if [ ! -f "$REPO_ROOT/.wp-env.json" ] && [ ! -f "$REPO_ROOT/package.json" ]; then
	fail_env "wp-env config not found at $REPO_ROOT (no .wp-env.json or package.json)." "Run this script from a clone of the day-one-importer repository."
fi

# ---------------------------------------------------------------------------
# 1. Start wp-env (idempotent).
# ---------------------------------------------------------------------------
# `wp-env start` is itself idempotent — running it on an already-started
# environment is a no-op aside from cycling the containers. Still, to make
# the common "already running" path fast and to surface port-conflict errors
# clearly, we probe `wp-env run cli wp core is-installed` first.

is_wp_env_running() {
	npx wp-env run cli wp core is-installed >/dev/null 2>&1
}

if is_wp_env_running; then
	log "wp-env already running; skipping start."
else
	log "Starting wp-env (this may take a minute on first run)..."
	if ! npx wp-env start --debug; then
		fail_env "wp-env start failed." "Check for a port conflict on 8888/8889, confirm Docker has enough resources, and review the wp-env --debug output above."
	fi
fi

# ---------------------------------------------------------------------------
# 2. Install + activate plugin-check (idempotent).
# ---------------------------------------------------------------------------

is_plugin_active() {
	npx wp-env run cli wp plugin is-active plugin-check >/dev/null 2>&1
}

if is_plugin_active; then
	log "plugin-check already active; skipping install."
else
	log "Installing and activating plugin-check..."
	if ! npx wp-env run cli wp plugin install plugin-check --activate; then
		fail_env "Failed to install plugin-check." "Confirm the wp-env container has network access to wordpress.org and rerun."
	fi
fi

# ---------------------------------------------------------------------------
# 3. Run Plugin Check.
# ---------------------------------------------------------------------------

log "Running Plugin Check against '$PLUGIN_SLUG'..."
log "(Findings, if any, are printed below. Zero findings = pass.)"
printf '\n'

# Capture output so we can both display it and exit non-zero when findings
# are present. PCP prints a "Checks complete. No errors found." style line
# on a clean run; on a dirty run it prints a table whose rows are findings.
PCP_OUTPUT_FILE=$(mktemp -t day-one-importer-pcp.XXXXXX)
trap 'rm -f "$PCP_OUTPUT_FILE"' EXIT

set +e
npx wp-env run cli wp plugin check "$PLUGIN_SLUG" \
	--checks=all \
	--format=table \
	--fields=check,file,line,column,type,code,message \
	2>&1 | tee "$PCP_OUTPUT_FILE"
PCP_EXIT=$?
set -e

if [ "$PCP_EXIT" -ne 0 ]; then
	# wp-cli itself failed (bad plugin slug, runtime error, etc.).
	fail_env "wp plugin check exited with status $PCP_EXIT before completing." "Inspect the output above; common causes: plugin-check plugin not registered, plugin slug mismatch, PHP fatal in the target plugin."
fi

# PCP prints findings as table rows containing 'ERROR' or 'WARNING' in the
# `type` column. The "Checks complete." trailer is only printed on success.
# Grep for either marker — any hit means findings were reported.
if grep -Eq '(\bERROR\b|\bWARNING\b)' "$PCP_OUTPUT_FILE"; then
	printf '\n'
	log "Plugin Check reported one or more findings (see table above)."
	log "Address each finding (or document a deliberate exception) before submitting to WordPress.org."
	exit 1
fi

printf '\n'
log "Plugin Check reported zero findings. Ready for submission review."
exit 0
