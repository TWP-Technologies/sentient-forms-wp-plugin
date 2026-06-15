#!/usr/bin/env bash
set -euo pipefail

SLUG="sentient-forms"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
REPO="${GITHUB_REPOSITORY:-TWP-Technologies/sentient-forms-wp-plugin}"
TAG=""
MODE="dry-run"
CONFIRM=""
WORK_DIR="${RUNNER_TEMP:-/tmp}/sentient-forms-wporg-svn"
ARTIFACT_DIR=""

usage() {
  cat <<'USAGE'
Usage:
  scripts/sync-wporg-svn.sh --tag v0.3.11 --mode dry-run
  scripts/sync-wporg-svn.sh --tag v0.3.11 --mode publish --confirm sentient-forms/0.3.11

Options:
  --tag TAG             GitHub release tag, e.g. v0.3.11.
  --mode MODE           dry-run or publish. Defaults to dry-run.
  --confirm VALUE       Required for publish. Must be sentient-forms/<version>.
  --repo OWNER/REPO     GitHub repository. Defaults to GITHUB_REPOSITORY.
  --svn-url URL         WordPress.org SVN URL.
  --work-dir DIR        Temporary working directory.
  --artifact-dir DIR    Directory for status, diff, and package evidence.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --tag)
      TAG="${2:-}"
      shift 2
      ;;
    --mode)
      MODE="${2:-}"
      shift 2
      ;;
    --confirm)
      CONFIRM="${2:-}"
      shift 2
      ;;
    --repo)
      REPO="${2:-}"
      shift 2
      ;;
    --svn-url)
      SVN_URL="${2:-}"
      shift 2
      ;;
    --work-dir)
      WORK_DIR="${2:-}"
      shift 2
      ;;
    --artifact-dir)
      ARTIFACT_DIR="${2:-}"
      shift 2
      ;;
    --help|-h)
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

if [[ ! "${TAG}" =~ ^v[0-9]+(\.[0-9]+){2}$ ]]; then
  echo "Tag must match vMAJOR.MINOR.PATCH, got: ${TAG:-<empty>}" >&2
  exit 1
fi

if [[ "${MODE}" != "dry-run" && "${MODE}" != "publish" ]]; then
  echo "Mode must be dry-run or publish, got: ${MODE}" >&2
  exit 1
fi

VERSION="${TAG#v}"
EXPECTED_CONFIRM="${SLUG}/${VERSION}"

if [[ "${MODE}" == "publish" ]]; then
  if [[ "${CONFIRM}" != "${EXPECTED_CONFIRM}" ]]; then
    echo "Publish confirmation must exactly match ${EXPECTED_CONFIRM}." >&2
    exit 1
  fi

  if [[ -z "${WPORG_SVN_USERNAME:-}" || -z "${WPORG_SVN_PASSWORD:-}" ]]; then
    echo "WPORG_SVN_USERNAME and WPORG_SVN_PASSWORD are required for publish mode." >&2
    exit 1
  fi
fi

if ! command -v gh >/dev/null 2>&1; then
  echo "GitHub CLI is required." >&2
  exit 1
fi

for required in jq sha256sum unzip svn rsync diff find sort; do
  if ! command -v "${required}" >/dev/null 2>&1; then
    echo "Required command not found: ${required}" >&2
    exit 1
  fi
done

ARTIFACT_DIR="${ARTIFACT_DIR:-${WORK_DIR}/artifacts}"
RELEASE_DIR="${WORK_DIR}/release"
EXTRACT_DIR="${WORK_DIR}/extracted"
SVN_DIR="${WORK_DIR}/svn"
REMOTE_EXPORT_DIR="${WORK_DIR}/remote-tag"

ZIP_NAME="${SLUG}-${VERSION}.zip"
SHA_NAME="${ZIP_NAME}.sha256"
MANIFEST_NAME="${SLUG}-${VERSION}-wporg-manifest.json"

rm -rf "${WORK_DIR}"
mkdir -p "${RELEASE_DIR}" "${EXTRACT_DIR}" "${ARTIFACT_DIR}"

echo "Resolving GitHub release ${TAG} from ${REPO}."
gh release view "${TAG}" --repo "${REPO}" --json tagName,isDraft,isPrerelease,assets,targetCommitish,url \
  > "${ARTIFACT_DIR}/github-release.json"

jq -e --arg tag "${TAG}" '.tagName == $tag and (.isDraft | not)' "${ARTIFACT_DIR}/github-release.json" >/dev/null
jq -e --arg zip "${ZIP_NAME}" --arg sha "${SHA_NAME}" --arg manifest "${MANIFEST_NAME}" \
  '([.assets[].name] | index($zip) and index($sha) and index($manifest))' \
  "${ARTIFACT_DIR}/github-release.json" >/dev/null

gh release download "${TAG}" --repo "${REPO}" --dir "${RELEASE_DIR}" \
  --pattern "${ZIP_NAME}" \
  --pattern "${SHA_NAME}" \
  --pattern "${MANIFEST_NAME}" \
  --clobber

ZIP_PATH="${RELEASE_DIR}/${ZIP_NAME}"
SHA_PATH="${RELEASE_DIR}/${SHA_NAME}"
MANIFEST_PATH="${RELEASE_DIR}/${MANIFEST_NAME}"

EXPECTED_SHA="$(awk 'NF { print tolower($1); exit }' "${SHA_PATH}")"
ACTUAL_SHA="$(sha256sum "${ZIP_PATH}" | awk '{ print tolower($1) }')"
if [[ ! "${EXPECTED_SHA}" =~ ^[0-9a-f]{64}$ ]]; then
  echo "Invalid SHA256 file contents in ${SHA_NAME}." >&2
  exit 1
fi
if [[ "${ACTUAL_SHA}" != "${EXPECTED_SHA}" ]]; then
  echo "Release ZIP SHA256 mismatch. Expected ${EXPECTED_SHA}, got ${ACTUAL_SHA}." >&2
  exit 1
fi

MANIFEST_VERSION="$(jq -r '.version // empty' "${MANIFEST_PATH}")"
MANIFEST_STABLE_TAG="$(jq -r '.stable_tag // empty' "${MANIFEST_PATH}")"
MANIFEST_ZIP_SHA="$(jq -r '.zip_sha256 // empty' "${MANIFEST_PATH}" | tr '[:upper:]' '[:lower:]')"
MANIFEST_SOURCE_REFERENCE="$(jq -r '.source_reference // .source_url // empty' "${MANIFEST_PATH}")"

if [[ "${MANIFEST_VERSION}" != "${VERSION}" ]]; then
  echo "Manifest version ${MANIFEST_VERSION} does not match ${VERSION}." >&2
  exit 1
fi
if [[ "${MANIFEST_STABLE_TAG}" != "${VERSION}" ]]; then
  echo "Manifest stable tag ${MANIFEST_STABLE_TAG} does not match ${VERSION}." >&2
  exit 1
fi
if [[ "${MANIFEST_ZIP_SHA}" != "${EXPECTED_SHA}" ]]; then
  echo "Manifest ZIP SHA256 ${MANIFEST_ZIP_SHA} does not match ${EXPECTED_SHA}." >&2
  exit 1
fi
if [[ "${MANIFEST_SOURCE_REFERENCE}" != *"/tree/${TAG}" ]]; then
  echo "Manifest source reference must point at ${TAG}, got ${MANIFEST_SOURCE_REFERENCE}." >&2
  exit 1
fi

unzip -q "${ZIP_PATH}" -d "${EXTRACT_DIR}"
PACKAGE_DIR="${EXTRACT_DIR}/${SLUG}"

if [[ ! -f "${PACKAGE_DIR}/sentient-forms.php" || ! -f "${PACKAGE_DIR}/readme.txt" ]]; then
  echo "Release ZIP must contain ${SLUG}/sentient-forms.php and ${SLUG}/readme.txt." >&2
  exit 1
fi

PLUGIN_VERSION="$(sed -nE 's/^[[:space:]]+\*[[:space:]]+Version:[[:space:]]*([^[:space:]].*)$/\1/p' "${PACKAGE_DIR}/sentient-forms.php" | head -n 1 | xargs)"
README_STABLE_TAG="$(sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]].*)$/\1/Ip' "${PACKAGE_DIR}/readme.txt" | head -n 1 | xargs)"
SOURCE_URL="$(sed -nE "s/^const SENTIENT_FORMS_RELEASE_SOURCE_URL[[:space:]]*=[[:space:]]*'([^']+)';$/\1/p" "${PACKAGE_DIR}/sentient-forms.php" | head -n 1)"

if [[ "${PLUGIN_VERSION}" != "${VERSION}" ]]; then
  echo "Plugin header version ${PLUGIN_VERSION} does not match ${VERSION}." >&2
  exit 1
fi
if [[ "${README_STABLE_TAG}" != "${VERSION}" ]]; then
  echo "readme.txt Stable tag ${README_STABLE_TAG} does not match ${VERSION}." >&2
  exit 1
fi
if [[ "${SOURCE_URL}" != *"/tree/${TAG}" ]]; then
  echo "SENTIENT_FORMS_RELEASE_SOURCE_URL must point at ${TAG}, got ${SOURCE_URL}." >&2
  exit 1
fi

find "${PACKAGE_DIR}" -type f -printf '%P\n' | LC_ALL=C sort > "${ARTIFACT_DIR}/package-file-list.txt"
cp "${MANIFEST_PATH}" "${ARTIFACT_DIR}/${MANIFEST_NAME}"
cp "${SHA_PATH}" "${ARTIFACT_DIR}/${SHA_NAME}"
printf '%s  %s\n' "${ACTUAL_SHA}" "${ZIP_NAME}" > "${ARTIFACT_DIR}/verified-zip.sha256"

if svn ls "${SVN_URL}/tags/${VERSION}" >/dev/null 2>&1; then
  echo "WordPress.org SVN tag already exists: tags/${VERSION}" >&2
  exit 1
fi

checkout_args=(checkout --quiet "${SVN_URL}" "${SVN_DIR}")
if [[ -n "${WPORG_SVN_USERNAME:-}" && -n "${WPORG_SVN_PASSWORD:-}" ]]; then
  checkout_args+=(--username "${WPORG_SVN_USERNAME}" --password "${WPORG_SVN_PASSWORD}" --non-interactive --no-auth-cache)
fi
svn "${checkout_args[@]}"

mkdir -p "${SVN_DIR}/trunk" "${SVN_DIR}/tags"

# WordPress.org listing media lives in top-level SVN assets/. This release script
# intentionally manages only plugin code in trunk/ and tags/<version>/; listing
# icons/banners/screenshots should be published separately. If an icon is added
# later, use the Sentient Forms logomark only.
rsync -a --delete --exclude='.svn' "${PACKAGE_DIR}/" "${SVN_DIR}/trunk/"

svn add --force "${SVN_DIR}/trunk" >/dev/null

while IFS= read -r status_line; do
  missing_path="${status_line:8}"
  [[ -n "${missing_path}" ]] || continue
  svn delete "${missing_path}" >/dev/null
done < <(svn status "${SVN_DIR}/trunk" | awk '$1 == "!"')

svn copy "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${VERSION}" >/dev/null

svn status "${SVN_DIR}" > "${ARTIFACT_DIR}/svn-status.txt"
svn diff "${SVN_DIR}" > "${ARTIFACT_DIR}/svn-diff.patch"

if grep -qE '^[ACDMR!~?]' "${ARTIFACT_DIR}/svn-status.txt"; then
  echo "Prepared SVN changes:"
  cat "${ARTIFACT_DIR}/svn-status.txt"
else
  echo "No SVN changes prepared."
fi

if [[ "${MODE}" == "dry-run" ]]; then
  echo "Dry run complete. No SVN commit was made."
  exit 0
fi

if ! grep -qE '^[ACDMR]' "${ARTIFACT_DIR}/svn-status.txt"; then
  echo "No committable SVN changes found." >&2
  exit 1
fi

commit_message="Release Sentient Forms ${VERSION} to WordPress.org SVN"
svn commit "${SVN_DIR}" \
  --message "${commit_message}" \
  --username "${WPORG_SVN_USERNAME}" \
  --password "${WPORG_SVN_PASSWORD}" \
  --non-interactive \
  --no-auth-cache \
  2>&1 | tee "${ARTIFACT_DIR}/svn-commit.txt"

rm -rf "${REMOTE_EXPORT_DIR}"
svn export --quiet --force "${SVN_URL}/tags/${VERSION}" "${REMOTE_EXPORT_DIR}"
diff -qr "${PACKAGE_DIR}" "${REMOTE_EXPORT_DIR}" > "${ARTIFACT_DIR}/remote-tag-diff.txt" || {
  echo "Remote SVN tag does not match release package. See remote-tag-diff.txt." >&2
  cat "${ARTIFACT_DIR}/remote-tag-diff.txt" >&2
  exit 1
}

svn info "${SVN_URL}/tags/${VERSION}" > "${ARTIFACT_DIR}/remote-tag-info.txt"
echo "Publish complete. Remote tags/${VERSION} matches ${ZIP_NAME}."
