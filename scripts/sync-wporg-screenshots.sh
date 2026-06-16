#!/usr/bin/env bash
set -euo pipefail

WPORG_SVN_URL="${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/sentient-forms}"
EXPECTED_CONFIRM="sentient-forms/assets/screenshots"

mode=""
confirm=""
release_tag=""
asset_name=""
screenshot_zip_sha256=""
readme_sha256=""
artifact_dir=""
work_dir=""

usage() {
  cat <<'USAGE'
Usage: sync-wporg-screenshots.sh --mode dry-run|publish --confirm VALUE \
  --release-tag vX.Y.Z --asset-name FILE.zip --screenshot-zip-sha256 SHA256 \
  --readme-sha256 SHA256 --artifact-dir DIR --work-dir DIR
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --mode)
      mode="${2:-}"
      shift 2
      ;;
    --confirm)
      confirm="${2:-}"
      shift 2
      ;;
    --release-tag)
      release_tag="${2:-}"
      shift 2
      ;;
    --asset-name)
      asset_name="${2:-}"
      shift 2
      ;;
    --screenshot-zip-sha256)
      screenshot_zip_sha256="${2:-}"
      shift 2
      ;;
    --readme-sha256)
      readme_sha256="${2:-}"
      shift 2
      ;;
    --artifact-dir)
      artifact_dir="${2:-}"
      shift 2
      ;;
    --work-dir)
      work_dir="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ "${mode}" != "dry-run" && "${mode}" != "publish" ]]; then
  echo "mode must be dry-run or publish." >&2
  exit 2
fi

if [[ "${mode}" == "publish" && "${confirm}" != "${EXPECTED_CONFIRM}" ]]; then
  echo "publish requires confirm='${EXPECTED_CONFIRM}'." >&2
  exit 2
fi

for required in release_tag asset_name screenshot_zip_sha256 readme_sha256 artifact_dir work_dir; do
  if [[ -z "${!required}" ]]; then
    echo "Missing required argument: --${required//_/-}" >&2
    usage >&2
    exit 2
  fi
done

if [[ ! -f readme.txt ]]; then
  echo "Run this script from the wp-plugin repository root." >&2
  exit 2
fi

mkdir -p "${artifact_dir}" "${work_dir}"
source_zip="${work_dir}/${asset_name}"
source_dir="${work_dir}/screenshots"
svn_dir="${work_dir}/svn"
remote_dir="${work_dir}/remote"
mkdir -p "${source_dir}" "${remote_dir}"

sha_upper() {
  tr '[:lower:]' '[:upper:]' <<<"$1"
}

expected_zip_sha="$(sha_upper "${screenshot_zip_sha256}")"
expected_readme_sha="$(sha_upper "${readme_sha256}")"
actual_readme_sha="$(sha256sum readme.txt | awk '{print toupper($1)}')"
if [[ "${actual_readme_sha}" != "${expected_readme_sha}" ]]; then
  echo "readme.txt SHA-256 mismatch: expected ${expected_readme_sha}, got ${actual_readme_sha}" >&2
  exit 1
fi

readme_bytes="$(python3 - <<'PY'
import pathlib
print(len(pathlib.Path("readme.txt").read_text(encoding="utf-8").encode("utf-8")))
PY
)"
if [[ "${readme_bytes}" -gt 10000 ]]; then
  echo "readme.txt is ${readme_bytes} UTF-8 bytes, above the WordPress.org hard gate." >&2
  exit 1
fi
if [[ "${readme_bytes}" -gt 9700 ]]; then
  echo "::warning::readme.txt is ${readme_bytes} UTF-8 bytes, above the documented 9700 byte comfort target."
fi

gh release download "${release_tag}" --pattern "${asset_name}" --output "${source_zip}"
actual_zip_sha="$(sha256sum "${source_zip}" | awk '{print toupper($1)}')"
if [[ "${actual_zip_sha}" != "${expected_zip_sha}" ]]; then
  echo "${asset_name} SHA-256 mismatch: expected ${expected_zip_sha}, got ${actual_zip_sha}" >&2
  exit 1
fi

unzip -oq "${source_zip}" -d "${source_dir}"

SCREENSHOT_SOURCE_DIR="${source_dir}" ARTIFACT_DIR="${artifact_dir}" python3 - <<'PY'
import hashlib
import os
import pathlib
import struct

source_dir = pathlib.Path(os.environ["SCREENSHOT_SOURCE_DIR"])
artifact_dir = pathlib.Path(os.environ["ARTIFACT_DIR"])
expected_dimensions = (2880, 1840)
lines = []

for index in range(1, 7):
    filename = f"screenshot-{index}.png"
    path = source_dir / filename
    if not path.is_file():
        raise SystemExit(f"Missing {filename}")

    data = path.read_bytes()
    if not data.startswith(b"\x89PNG\r\n\x1a\n"):
        raise SystemExit(f"{filename} is not a PNG file.")

    ihdr_length = struct.unpack(">I", data[8:12])[0]
    ihdr_type = data[12:16]
    if ihdr_type != b"IHDR" or ihdr_length != 13:
        raise SystemExit(f"{filename} has an invalid PNG IHDR chunk.")

    width, height = struct.unpack(">II", data[16:24])
    if (width, height) != expected_dimensions:
        raise SystemExit(
            f"{filename} dimensions mismatch: expected "
            f"{expected_dimensions[0]}x{expected_dimensions[1]}, got {width}x{height}"
        )

    sha = hashlib.sha256(data).hexdigest().upper()
    lines.append(f"{filename}: {width}x{height}, bytes={len(data)}, sha256={sha}")

(artifact_dir / "screenshot-validation.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
PY

mkdir -p "${svn_dir}"
svn checkout --depth infinity "${WPORG_SVN_URL}/assets" "${svn_dir}/assets"
svn checkout --depth infinity "${WPORG_SVN_URL}/trunk" "${svn_dir}/trunk"

for index in 1 2 3 4 5 6; do
  cp "${source_dir}/screenshot-${index}.png" "${svn_dir}/assets/screenshot-${index}.png"
done
cp readme.txt "${svn_dir}/trunk/readme.txt"

svn add --force "${svn_dir}/assets" "${svn_dir}/trunk" >/dev/null
{
  svn status "${svn_dir}/assets"
  svn status "${svn_dir}/trunk/readme.txt"
} > "${artifact_dir}/svn-status.txt"
{
  svn diff --summarize "${svn_dir}/assets" || true
  svn diff --summarize "${svn_dir}/trunk/readme.txt" || true
} > "${artifact_dir}/svn-diff-summary.txt"
{
  echo "mode=${mode}"
  echo "release_tag=${release_tag}"
  echo "asset_name=${asset_name}"
  echo "screenshot_zip_sha256=${actual_zip_sha}"
  echo "readme_sha256=${actual_readme_sha}"
  echo "readme_bytes=${readme_bytes}"
} > "${artifact_dir}/publish-inputs.txt"

if [[ "${mode}" == "dry-run" ]]; then
  exit 0
fi

if [[ -z "${WPORG_SVN_USERNAME:-}" || -z "${WPORG_SVN_PASSWORD:-}" ]]; then
  echo "WPORG_SVN_USERNAME and WPORG_SVN_PASSWORD are required for publish." >&2
  exit 1
fi

: > "${artifact_dir}/svn-commit.txt"
svn commit \
  "${svn_dir}/assets" \
  --message "Add WordPress.org screenshots." \
  --username "${WPORG_SVN_USERNAME}" \
  --password "${WPORG_SVN_PASSWORD}" \
  --non-interactive \
  --no-auth-cache \
  --trust-server-cert-failures=unknown-ca,cn-mismatch,expired,not-yet-valid,other \
  >> "${artifact_dir}/svn-commit.txt"

svn commit \
  "${svn_dir}/trunk/readme.txt" \
  --message "Add WordPress.org screenshot captions." \
  --username "${WPORG_SVN_USERNAME}" \
  --password "${WPORG_SVN_PASSWORD}" \
  --non-interactive \
  --no-auth-cache \
  --trust-server-cert-failures=unknown-ca,cn-mismatch,expired,not-yet-valid,other \
  >> "${artifact_dir}/svn-commit.txt"

for index in 1 2 3 4 5 6; do
  svn export --force "${WPORG_SVN_URL}/assets/screenshot-${index}.png" "${remote_dir}/screenshot-${index}.png" >/dev/null
done
svn export --force "${WPORG_SVN_URL}/trunk/readme.txt" "${remote_dir}/readme.txt" >/dev/null

REMOTE_DIR="${remote_dir}" SOURCE_DIR="${source_dir}" ARTIFACT_DIR="${artifact_dir}" EXPECTED_README_SHA="${actual_readme_sha}" python3 - <<'PY'
import hashlib
import os
import pathlib

remote_dir = pathlib.Path(os.environ["REMOTE_DIR"])
source_dir = pathlib.Path(os.environ["SOURCE_DIR"])
artifact_dir = pathlib.Path(os.environ["ARTIFACT_DIR"])
expected_readme_sha = os.environ["EXPECTED_README_SHA"]
lines = []

for index in range(1, 7):
    filename = f"screenshot-{index}.png"
    source_sha = hashlib.sha256((source_dir / filename).read_bytes()).hexdigest().upper()
    remote_sha = hashlib.sha256((remote_dir / filename).read_bytes()).hexdigest().upper()
    if source_sha != remote_sha:
        raise SystemExit(f"Remote {filename} hash mismatch: expected {source_sha}, got {remote_sha}")
    lines.append(f"{filename}: {remote_sha}")

remote_readme_sha = hashlib.sha256((remote_dir / "readme.txt").read_bytes()).hexdigest().upper()
if remote_readme_sha != expected_readme_sha:
    raise SystemExit(f"Remote readme.txt hash mismatch: expected {expected_readme_sha}, got {remote_readme_sha}")
lines.append(f"readme.txt: {remote_readme_sha}")

(artifact_dir / "remote-verification.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
PY
