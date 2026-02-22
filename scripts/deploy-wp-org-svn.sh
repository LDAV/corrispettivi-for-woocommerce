#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

usage() {
  cat <<'EOF'
Deploy plugin files to WordPress.org SVN.

Usage:
  scripts/deploy-wp-org-svn.sh --slug <plugin-slug> [options]

Options:
  --slug <slug>           WordPress.org plugin slug (required)
  --version <version>     Release version (defaults to readme.txt Stable tag)
  --svn-dir <path>        Local SVN checkout path (default: .wporg-svn/<slug>)
  --svn-username <user>   SVN username (default: WPORG_SVN_USERNAME env)
  --svn-password <pass>   SVN password (default: WPORG_SVN_PASSWORD env)
  --message <text>        SVN commit message (optional)
  --commit                Run svn commit after staging changes
  --allow-retag           Allow updating an existing tags/<version> directory
  --dry-run               Show actions without writing to SVN checkout
  -h, --help              Show this help
EOF
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1" >&2
    exit 1
  }
}

extract_plugin_version() {
  sed -nE 's@^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*$@\1@p' "${ROOT_DIR}/corrispettivi-for-woocommerce.php" | head -n1
}

extract_stable_tag() {
  sed -nE 's@^[[:space:]]*Stable[[:space:]]+tag:[[:space:]]*([^[:space:]]+).*$@\1@Ip' "${ROOT_DIR}/readme.txt" | head -n1
}

SLUG=""
VERSION=""
SVN_DIR=""
COMMIT=0
ALLOW_RETAG=0
DRY_RUN=0
MESSAGE=""
SVN_USERNAME="${WPORG_SVN_USERNAME:-}"
SVN_PASSWORD="${WPORG_SVN_PASSWORD:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --slug)
      SLUG="${2:-}"
      shift 2
      ;;
    --version)
      VERSION="${2:-}"
      shift 2
      ;;
    --svn-dir)
      SVN_DIR="${2:-}"
      shift 2
      ;;
    --message)
      MESSAGE="${2:-}"
      shift 2
      ;;
    --svn-username)
      SVN_USERNAME="${2:-}"
      shift 2
      ;;
    --svn-password)
      SVN_PASSWORD="${2:-}"
      shift 2
      ;;
    --commit)
      COMMIT=1
      shift
      ;;
    --allow-retag)
      ALLOW_RETAG=1
      shift
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ -z "${SLUG}" ]]; then
  echo "--slug is required" >&2
  usage >&2
  exit 1
fi

if [[ -z "${SVN_DIR}" ]]; then
  SVN_DIR="${ROOT_DIR}/.wporg-svn/${SLUG}"
fi

PLUGIN_VERSION="$(extract_plugin_version)"
STABLE_TAG="$(extract_stable_tag)"
if [[ -z "${VERSION}" ]]; then
  VERSION="${STABLE_TAG}"
fi

if [[ -z "${PLUGIN_VERSION}" || -z "${STABLE_TAG}" || -z "${VERSION}" ]]; then
  echo "Unable to read versions from plugin header/readme or --version." >&2
  exit 1
fi

if [[ "${PLUGIN_VERSION}" != "${STABLE_TAG}" || "${VERSION}" != "${STABLE_TAG}" ]]; then
  echo "Version mismatch detected:" >&2
  echo "  Plugin header Version: ${PLUGIN_VERSION}" >&2
  echo "  readme.txt Stable tag: ${STABLE_TAG}" >&2
  echo "  Requested version:      ${VERSION}" >&2
  echo "Align versions before deploy." >&2
  exit 1
fi

for cmd in svn rsync sed; do
  require_cmd "${cmd}"
done

if [[ -n "${SVN_PASSWORD}" && -z "${SVN_USERNAME}" ]]; then
  echo "SVN password was provided but username is empty." >&2
  echo "Set --svn-username or WPORG_SVN_USERNAME." >&2
  exit 1
fi

SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
TRUNK_DIR="${SVN_DIR}/trunk"
TAG_DIR="${SVN_DIR}/tags/${VERSION}"
DISTIGNORE_FILE="${ROOT_DIR}/.distignore"
SVN_AUTH_ARGS=()

if [[ -n "${SVN_USERNAME}" ]]; then
  SVN_AUTH_ARGS+=(--username "${SVN_USERNAME}")
fi
if [[ -n "${SVN_PASSWORD}" ]]; then
  SVN_AUTH_ARGS+=(--password "${SVN_PASSWORD}" --non-interactive)
fi

if [[ ! -d "${SVN_DIR}/.svn" ]]; then
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "[dry-run] svn checkout ${SVN_URL} ${SVN_DIR}"
  else
    mkdir -p "$(dirname "${SVN_DIR}")"
    svn checkout "${SVN_URL}" "${SVN_DIR}" "${SVN_AUTH_ARGS[@]}"
  fi
else
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "[dry-run] svn update ${SVN_DIR}"
  else
    svn update "${SVN_DIR}" "${SVN_AUTH_ARGS[@]}"
  fi
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
  echo "[dry-run] rsync source -> ${TRUNK_DIR}"
else
  mkdir -p "${TRUNK_DIR}"
fi

RSYNC_ARGS=(
  -a
  --delete
  --exclude
  ".svn/"
)

if [[ -f "${DISTIGNORE_FILE}" ]]; then
  RSYNC_ARGS+=(--exclude-from "${DISTIGNORE_FILE}")
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
  RSYNC_ARGS+=(--dry-run)
fi

rsync "${RSYNC_ARGS[@]}" "${ROOT_DIR}/" "${TRUNK_DIR}/"

if [[ -d "${TAG_DIR}" && "${ALLOW_RETAG}" -ne 1 ]]; then
  echo "Tag directory already exists: ${TAG_DIR}" >&2
  echo "Use --allow-retag only if you intentionally want to overwrite it." >&2
  exit 1
fi

if [[ ! -d "${TAG_DIR}" ]]; then
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "[dry-run] svn copy ${TRUNK_DIR} ${TAG_DIR}"
  else
    mkdir -p "${SVN_DIR}/tags"
    svn copy "${TRUNK_DIR}" "${TAG_DIR}"
  fi
else
  if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "[dry-run] rsync source -> ${TAG_DIR}"
  fi
  rsync "${RSYNC_ARGS[@]}" "${ROOT_DIR}/" "${TAG_DIR}/"
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
  if [[ -d "${SVN_DIR}/.svn" ]]; then
    echo "[dry-run] svn status ${SVN_DIR}"
    svn status "${SVN_DIR}" || true
  else
    echo "[dry-run] svn status skipped (checkout does not exist yet)"
  fi
  exit 0
fi

while IFS= read -r line; do
  status="${line:0:1}"
  path="${line:8}"
  case "${status}" in
    "?")
      svn add --parents "${path}"
      ;;
    "!")
      svn rm --force "${path}"
      ;;
  esac
done < <(svn status "${SVN_DIR}")

echo "SVN status after staging:"
svn status "${SVN_DIR}" || true

if [[ "${COMMIT}" -eq 1 ]]; then
  if [[ -z "${MESSAGE}" ]]; then
    MESSAGE="Release ${VERSION}"
  fi
  svn commit "${SVN_DIR}" -m "${MESSAGE}" "${SVN_AUTH_ARGS[@]}"
else
  echo "Commit not executed. Run with --commit to publish."
fi
