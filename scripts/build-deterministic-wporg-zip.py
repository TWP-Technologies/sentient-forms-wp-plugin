#!/usr/bin/env python3
"""Create a byte-stable WP.org ZIP and canonical package-tree manifest."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import platform
import re
import stat
import sys
import zipfile
import zlib


ARCHIVE_EPOCH = (1980, 1, 1, 0, 0, 0)
REQUIRED_FILES = {
    "CHANGELOG.md",
    "assets/dist/manifest.json",
    "composer.json",
    "contracts/action-facet-policy-catalog.v1.json",
    "contracts/action-source-compatibility.v1.json",
    "includes/class-map.php",
    "readme.txt",
    "sentient-forms.php",
    "vendor/woocommerce/action-scheduler/action-scheduler.php",
}
ALLOWED_TOP_LEVEL = {
    "CHANGELOG.md",
    "assets",
    "composer.json",
    "contracts",
    "includes",
    "languages",
    "readme.txt",
    "sentient-forms.php",
    "vendor",
}


def normalized_filesystem_identity(path: Path) -> str:
    return os.path.normcase(os.path.normpath(os.path.abspath(path)))


def validate_inventory_paths(paths: list[str]) -> None:
    identities: set[str] = set()
    for relative in paths:
        pure = PurePosixPath(relative)
        normalized = str(pure)
        if (
            not relative
            or normalized != relative
            or pure.is_absolute()
            or ".." in pure.parts
            or "\\" in relative
            or any(":" in part for part in pure.parts)
        ):
            raise ValueError(f"package contains a non-normalized path: {relative}")
        identity = relative.casefold()
        if identity in identities:
            raise ValueError(f"package contains a duplicate path identity: {relative}")
        identities.add(identity)


def is_allowed_inventory_path(relative: str) -> bool:
    pure = PurePosixPath(relative)
    top = pure.parts[0]
    if len(pure.parts) == 1:
        return relative in {
            "CHANGELOG.md",
            "composer.json",
            "readme.txt",
            "sentient-forms.php",
        }
    if top in {"includes", "languages"}:
        return True
    if top == "contracts":
        return relative in {
            "contracts/action-facet-policy-catalog.v1.json",
            "contracts/action-source-compatibility.v1.json",
        }
    if top == "vendor":
        return relative.startswith("vendor/woocommerce/action-scheduler/")
    if top == "assets":
        return (
            relative in {"assets/index.php"}
            or relative.startswith("assets/css/")
            or relative.startswith("assets/js/")
            or relative.startswith("assets/dist/")
        )
    return False


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("package_dir", type=Path)
    parser.add_argument("zip_path", type=Path)
    parser.add_argument("manifest_path", type=Path)
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--source-tree", required=True)
    parser.add_argument("--composer-lock-sha256", required=True)
    parser.add_argument("--expected-inventory", required=True, type=Path)
    return parser.parse_args()


def validate_hash(value: str, label: str, lengths: set[int]) -> str:
    lowered = value.lower()
    if len(lowered) not in lengths or any(character not in "0123456789abcdef" for character in lowered):
        raise ValueError(f"{label} must be a lowercase hexadecimal identity")
    return lowered


def collect_files(package_dir: Path) -> list[tuple[str, Path, bytes]]:
    if (
        package_dir.name != "sentient-forms"
        or not package_dir.is_dir()
        or package_dir.is_symlink()
        or normalized_filesystem_identity(package_dir)
        != normalized_filesystem_identity(Path(os.path.realpath(package_dir)))
    ):
        raise ValueError("package directory must be a regular sentient-forms directory")

    collected: list[tuple[str, Path, bytes]] = []
    for directory, directories, filenames in os.walk(package_dir, followlinks=False):
        directories.sort()
        filenames.sort()
        directory_path = Path(directory)
        for name in [*directories, *filenames]:
            candidate = directory_path / name
            if (
                candidate.is_symlink()
                or normalized_filesystem_identity(candidate)
                != normalized_filesystem_identity(Path(os.path.realpath(candidate)))
            ):
                raise ValueError(f"package contains a symlink: {candidate.relative_to(package_dir)}")

        for filename in filenames:
            source = directory_path / filename
            mode = source.stat(follow_symlinks=False).st_mode
            if not stat.S_ISREG(mode):
                raise ValueError(f"package contains a non-regular file: {source.relative_to(package_dir)}")
            relative = source.relative_to(package_dir).as_posix()
            collected.append((relative, source, source.read_bytes()))

    validate_inventory_paths([relative for relative, _, _ in collected])
    unexpected = sorted(relative for relative, _, _ in collected if not is_allowed_inventory_path(relative))
    if unexpected:
        raise ValueError(f"package contains unexpected inventory: {', '.join(unexpected)}")
    paths = {relative for relative, _, _ in collected}
    missing = sorted(REQUIRED_FILES - paths)
    if missing:
        raise ValueError(f"package is missing required files: {', '.join(missing)}")
    validate_admin_manifest(package_dir, paths)
    return collected


def validate_admin_manifest(package_dir: Path, paths: set[str]) -> None:
    manifest_path = package_dir / "assets" / "dist" / "manifest.json"
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise ValueError(f"generated admin manifest is unreadable: {error}") from error
    if not isinstance(manifest, dict):
        raise ValueError("generated admin manifest must be an object")

    references: set[str] = set()
    for entry in manifest.values():
        if not isinstance(entry, dict):
            raise ValueError("generated admin manifest entries must be objects")
        file_reference = entry.get("file")
        if not isinstance(file_reference, str) or not file_reference:
            raise ValueError("generated admin manifest file references must be non-empty strings")
        references.add(file_reference)
        css_references = entry.get("css", [])
        if not isinstance(css_references, list) or any(
            not isinstance(reference, str) or not reference for reference in css_references
        ):
            raise ValueError("generated admin manifest CSS references must be non-empty strings")
        references.update(css_references)

    for reference in sorted(references):
        normalized = str(PurePosixPath(reference))
        if normalized != reference or reference.startswith("/") or ".." in PurePosixPath(reference).parts:
            raise ValueError(f"generated admin manifest contains an unsafe reference: {reference}")
        packaged_reference = f"assets/dist/{reference}"
        if packaged_reference not in paths:
            raise ValueError(f"generated admin manifest asset is missing: {reference}")


def file_inventory(files: list[tuple[str, Path, bytes]]) -> list[dict[str, object]]:
    inventory = [
        {
            "path": relative,
            "sha256": hashlib.sha256(contents).hexdigest(),
            "size": len(contents),
        }
        for relative, _, contents in files
    ]
    return sorted(inventory, key=lambda entry: str(entry["path"]))


def read_release_metadata(package_dir: Path) -> dict[str, str]:
    try:
        plugin = (package_dir / "sentient-forms.php").read_text(encoding="utf-8")
        readme = (package_dir / "readme.txt").read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as error:
        raise ValueError(f"release metadata inputs are unreadable: {error}") from error

    version_match = re.search(r"^\s*\*\s*Version:\s*(.+)$", plugin, re.MULTILINE | re.IGNORECASE)
    stable_match = re.search(r"^Stable tag:\s*(.+)$", readme, re.MULTILINE | re.IGNORECASE)
    source_match = re.search(
        r"const\s+SENTIENT_FORMS_RELEASE_SOURCE_(?:URL|REFERENCE)\s*=\s*'([^']+)';",
        plugin,
    )
    if version_match is None or stable_match is None or source_match is None:
        raise ValueError("package release metadata is incomplete")

    version = version_match.group(1).strip()
    stable_tag = stable_match.group(1).strip()
    source_reference = source_match.group(1).strip()
    if not version or stable_tag != version or not source_reference:
        raise ValueError("package release metadata is inconsistent")
    return {
        "version": version,
        "stable_tag": stable_tag,
        "source_reference": source_reference,
    }


def load_expected_inventory(inventory_path: Path) -> list[dict[str, object]]:
    if (
        not inventory_path.is_file()
        or inventory_path.is_symlink()
        or normalized_filesystem_identity(inventory_path)
        != normalized_filesystem_identity(Path(os.path.realpath(inventory_path)))
    ):
        raise ValueError("expected package inventory must be a regular file")
    try:
        decoded = json.loads(inventory_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise ValueError(f"expected package inventory is unreadable: {error}") from error
    if (
        not isinstance(decoded, dict)
        or type(decoded.get("schema_version")) is not int
        or decoded.get("schema_version") != 1
    ):
        raise ValueError("expected package inventory has an unsupported schema")
    entries = decoded.get("files")
    if not isinstance(entries, list):
        raise ValueError("expected package inventory files must be an array")

    normalized: list[dict[str, object]] = []
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {"path", "sha256", "size"}:
            raise ValueError("expected package inventory entries must contain path, sha256, and size")
        relative = entry["path"]
        digest = entry["sha256"]
        size = entry["size"]
        if not isinstance(relative, str) or not isinstance(digest, str) or type(size) is not int:
            raise ValueError("expected package inventory entry types are invalid")
        if size < 0:
            raise ValueError(f"expected package inventory size is invalid: {relative}")
        validate_hash(digest, f"expected package inventory SHA-256 for {relative}", {64})
        normalized.append({"path": relative, "sha256": digest, "size": size})

    paths = [str(entry["path"]) for entry in normalized]
    validate_inventory_paths(paths)
    if paths != sorted(paths):
        raise ValueError("expected package inventory paths must be sorted")
    return normalized


def write_zip(files: list[tuple[str, Path, bytes]], zip_path: Path) -> None:
    zip_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = zip_path.with_name(f".{zip_path.name}.tmp")
    temporary.unlink(missing_ok=True)
    try:
        with zipfile.ZipFile(
            temporary,
            mode="w",
            compression=zipfile.ZIP_DEFLATED,
            compresslevel=9,
            strict_timestamps=True,
        ) as archive:
            for relative, _, contents in files:
                info = zipfile.ZipInfo(f"sentient-forms/{relative}", ARCHIVE_EPOCH)
                info.compress_type = zipfile.ZIP_DEFLATED
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                archive.writestr(info, contents, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        temporary.replace(zip_path)
    finally:
        temporary.unlink(missing_ok=True)


def main() -> int:
    args = parse_args()
    try:
        source_commit = validate_hash(args.source_commit, "source commit", {40, 64})
        source_tree = validate_hash(args.source_tree, "source tree", {40, 64})
        lock_sha256 = validate_hash(args.composer_lock_sha256, "Composer lock SHA-256", {64})
        files = collect_files(args.package_dir.absolute())
        release_metadata = read_release_metadata(args.package_dir.absolute())
        inventory = file_inventory(files)
        expected_inventory = load_expected_inventory(args.expected_inventory.absolute())
        if inventory != expected_inventory:
            raise ValueError("package contents do not match the expected package inventory")
        canonical_inventory = json.dumps(inventory, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        package_tree_sha256 = hashlib.sha256(canonical_inventory).hexdigest()
        write_zip(files, args.zip_path)
        zip_bytes = args.zip_path.read_bytes()
        manifest = {
            "schema_version": 1,
            **release_metadata,
            "source_commit": source_commit,
            "source_tree": source_tree,
            "composer_lock_sha256": lock_sha256,
            "builder": {
                "python": platform.python_version(),
                "zlib": zlib.ZLIB_VERSION,
            },
            "archive_timestamp": "1980-01-01T00:00:00Z",
            "file_count": len(inventory),
            "package_tree_sha256": package_tree_sha256,
            "zip_size": len(zip_bytes),
            "zip_sha256": hashlib.sha256(zip_bytes).hexdigest(),
            "files": inventory,
        }
        args.manifest_path.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_path.write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
            newline="\n",
        )
        print(
            f"Deterministic package: files={len(inventory)} "
            f"tree={package_tree_sha256} zip={manifest['zip_sha256']}"
        )
        return 0
    except (OSError, ValueError, zipfile.BadZipFile) as error:
        print(f"Deterministic package failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
