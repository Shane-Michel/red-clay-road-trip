#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_TARGET="michelco@michelcollective.com:/home/michelco/redclayroadtrip.s-sites.com/"
TARGET="${DEPLOY_TARGET:-$DEFAULT_TARGET}"

if ! command -v rsync >/dev/null 2>&1; then
  echo "Error: rsync is not installed. Please install rsync and try again." >&2
  exit 1
fi

EXTRA_FLAGS=("--chmod=F644,D755")
POSITIONAL=()

usage() {
  cat <<USAGE
Usage: ${0##*/} [options]

Options:
  --dry-run           Perform a dry run without transferring files.
  --delete            Remove files in the target that no longer exist locally.
  --target <path>     Override the remote target (or set DEPLOY_TARGET).
  -h, --help          Show this help message.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)
      EXTRA_FLAGS+=("--dry-run")
      shift
      ;;
    --delete)
      EXTRA_FLAGS+=("--delete")
      shift
      ;;
    --target)
      if [[ $# -lt 2 ]]; then
        echo "Error: --target requires a value." >&2
        usage
        exit 1
      fi
      TARGET="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Error: Unknown argument '$1'." >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$TARGET" ]]; then
  echo "Error: No deployment target specified. Set DEPLOY_TARGET or use --target." >&2
  exit 1
fi

SRC_PATHS=("$PROJECT_ROOT/public" "$PROJECT_ROOT/src")

printf 'Deploying %s to %s\n' "${SRC_PATHS[*]}" "$TARGET"

rsync -avz "${EXTRA_FLAGS[@]}" "${SRC_PATHS[@]}" "$TARGET"
