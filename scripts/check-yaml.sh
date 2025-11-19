#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
TARGET_DIR="$REPO_ROOT/.github"

if [[ ! -d "$TARGET_DIR" ]]; then
  exit 0
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "[yaml-check] python3 is required but not found." >&2
  exit 1
fi

if ! python3 - <<'PY' >/dev/null 2>&1; then
import importlib.util
import sys
if importlib.util.find_spec('yaml') is None:
    sys.exit(1)
PY
  cat >&2 <<'MSG'
[yaml-check] PyYAML not found. Install it via `pip install pyyaml` to enable YAML validation.
MSG
  exit 1
fi

mapfile -t yaml_files < <(find "$TARGET_DIR" -type f \( -name '*.yml' -o -name '*.yaml' \))

if [[ ${#yaml_files[@]} -eq 0 ]]; then
  exit 0
fi

python3 - "${yaml_files[@]}" <<'PY'
import pathlib
import sys
import yaml

files = sys.argv[1:]
failed = False
for file_path in files:
    path = pathlib.Path(file_path)
    try:
        with path.open('r', encoding='utf-8') as fh:
            yaml.safe_load(fh)
    except Exception as exc:
        failed = True
        print(f"[yaml-check] {path}: {exc}", file=sys.stderr)

if failed:
    sys.exit(1)
PY
