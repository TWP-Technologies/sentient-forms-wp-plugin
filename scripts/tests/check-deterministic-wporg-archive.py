#!/usr/bin/env python3
"""Exercise deterministic WP.org archive creation through its CLI boundary."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time


PLUGIN_ROOT = Path(__file__).resolve().parents[2]
ARCHIVER = PLUGIN_ROOT / "scripts" / "build-deterministic-wporg-zip.py"
FIXTURE_ROOT = PLUGIN_ROOT / ".item14-archive-test"
SOURCE_COMMIT = "1" * 40
SOURCE_TREE = "2" * 40
LOCK_SHA256 = "3" * 64


def populate(package: Path, reverse: bool) -> None:
    files = {
        "CHANGELOG.md": b"# Changelog\n",
        "composer.json": b"{}\n",
        "readme.txt": b"=== Sentient Forms ===\nStable tag: 1.2.3\n",
        "sentient-forms.php": (
            b"<?php\n/**\n * Version: 1.2.3\n */\n"
            b"const SENTIENT_FORMS_RELEASE_SOURCE_URL = "
            b"'https://github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v1.2.3';\n"
        ),
        "contracts/action-facet-policy-catalog.v1.json": b"{}\n",
        "contracts/action-source-compatibility.v1.json": b"{}\n",
        "assets/dist/manifest.json": (
            b'{"entry":{"file":"_app/a.js","css":["_app/a.css"]}}\n'
        ),
        "assets/dist/_app/a.css": b".sf-test{}\n",
        "assets/dist/_app/a.js": b"",
        "includes/class-map.php": b"<?php return [];\n",
        "vendor/woocommerce/action-scheduler/action-scheduler.php": b"<?php\n",
    }
    items = list(files.items())
    if reverse:
        items.reverse()
    for relative, contents in items:
        target = package / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(contents)
        os.utime(target, (time.time() - (1000 if reverse else 10),) * 2)


def write_expected_inventory(package: Path, inventory_path: Path) -> None:
    files = []
    for source in sorted(path for path in package.rglob("*") if path.is_file()):
        contents = source.read_bytes()
        files.append(
            {
                "path": source.relative_to(package).as_posix(),
                "sha256": hashlib.sha256(contents).hexdigest(),
                "size": len(contents),
            }
        )
    files.sort(key=lambda entry: str(entry["path"]))
    inventory_path.parent.mkdir(parents=True, exist_ok=True)
    inventory_path.write_text(
        json.dumps({"schema_version": 1, "files": files}, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def run_archiver(
    package: Path, zip_path: Path, manifest: Path, expected_inventory: Path
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [
            sys.executable,
            str(ARCHIVER),
            str(package),
            str(zip_path),
            str(manifest),
            "--source-commit",
            SOURCE_COMMIT,
            "--source-tree",
            SOURCE_TREE,
            "--composer-lock-sha256",
            LOCK_SHA256,
            "--expected-inventory",
            str(expected_inventory),
        ],
        cwd=PLUGIN_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )


def create_directory_link(link: Path, target: Path) -> None:
    if os.name == "nt":
        result = subprocess.run(
            ["cmd.exe", "/d", "/c", "mklink", "/J", str(link), str(target)],
            capture_output=True,
            text=True,
            check=False,
        )
        if result.returncode != 0:
            raise OSError(result.stderr.strip() or result.stdout.strip())
        return
    link.symlink_to(target, target_is_directory=True)


def main() -> int:
    if not ARCHIVER.is_file():
        print(
            "Deterministic archive test failed:\n"
            "- Missing scripts/build-deterministic-wporg-zip.py.",
            file=sys.stderr,
        )
        return 1

    shutil.rmtree(FIXTURE_ROOT, ignore_errors=True)
    try:
        first = FIXTURE_ROOT / "first" / "sentient-forms"
        second = FIXTURE_ROOT / "second" / "sentient-forms"
        populate(first, reverse=False)
        populate(second, reverse=True)
        first_inventory = FIXTURE_ROOT / "first-inventory.json"
        second_inventory = FIXTURE_ROOT / "second-inventory.json"
        write_expected_inventory(first, first_inventory)
        write_expected_inventory(second, second_inventory)

        first_result = run_archiver(
            first, FIXTURE_ROOT / "first.zip", FIXTURE_ROOT / "first.json", first_inventory
        )
        second_result = run_archiver(
            second, FIXTURE_ROOT / "second.zip", FIXTURE_ROOT / "second.json", second_inventory
        )
        errors: list[str] = []
        for label, result in (("first", first_result), ("second", second_result)):
            if result.returncode != 0:
                errors.append(f"{label} archive failed: {result.stderr.strip()}")

        if not errors:
            first_bytes = (FIXTURE_ROOT / "first.zip").read_bytes()
            second_bytes = (FIXTURE_ROOT / "second.zip").read_bytes()
            if first_bytes != second_bytes:
                errors.append("independent archive bytes differ")
            first_manifest = json.loads((FIXTURE_ROOT / "first.json").read_text(encoding="utf-8"))
            second_manifest = json.loads((FIXTURE_ROOT / "second.json").read_text(encoding="utf-8"))
            if first_manifest["package_tree_sha256"] != second_manifest["package_tree_sha256"]:
                errors.append("independent package-tree identities differ")
            if first_manifest["zip_sha256"] != hashlib.sha256(first_bytes).hexdigest():
                errors.append("manifest ZIP identity differs from archive bytes")
            if first_manifest["file_count"] != 11:
                errors.append("manifest file count is not exact")
            expected_release_metadata = {
                "version": "1.2.3",
                "stable_tag": "1.2.3",
                "source_reference": (
                    "https://github.com/TWP-Technologies/"
                    "sentient-forms-wp-plugin/tree/v1.2.3"
                ),
            }
            for key, expected in expected_release_metadata.items():
                if first_manifest.get(key) != expected:
                    errors.append(f"manifest release metadata is missing or invalid: {key}")

        malformed_schema = json.loads(first_inventory.read_text(encoding="utf-8"))
        malformed_schema["schema_version"] = True
        malformed_schema_path = FIXTURE_ROOT / "malformed-schema.json"
        malformed_schema_path.write_text(json.dumps(malformed_schema), encoding="utf-8")
        schema_result = run_archiver(
            first,
            FIXTURE_ROOT / "malformed-schema.zip",
            FIXTURE_ROOT / "malformed-schema-manifest.json",
            malformed_schema_path,
        )
        if schema_result.returncode == 0:
            errors.append("archive accepted a boolean inventory schema version")

        malformed_size = json.loads(first_inventory.read_text(encoding="utf-8"))
        empty_entry = next(
            entry for entry in malformed_size["files"] if entry["path"] == "assets/dist/_app/a.js"
        )
        empty_entry["size"] = False
        malformed_size_path = FIXTURE_ROOT / "malformed-size.json"
        malformed_size_path.write_text(json.dumps(malformed_size), encoding="utf-8")
        size_result = run_archiver(
            first,
            FIXTURE_ROOT / "malformed-size.zip",
            FIXTURE_ROOT / "malformed-size-manifest.json",
            malformed_size_path,
        )
        if size_result.returncode == 0:
            errors.append("archive accepted a boolean inventory file size")

        manifest_path = second / "assets" / "dist" / "manifest.json"
        manifest_path.write_text(
            '{"entry":{"file":5,"css":["_app/a.css"]}}\n',
            encoding="utf-8",
            newline="\n",
        )
        write_expected_inventory(second, second_inventory)
        invalid_manifest_file = run_archiver(
            second,
            FIXTURE_ROOT / "invalid-manifest-file.zip",
            FIXTURE_ROOT / "invalid-manifest-file.json",
            second_inventory,
        )
        if invalid_manifest_file.returncode == 0:
            errors.append("archive accepted a non-string generated manifest file reference")
        manifest_path.write_text(
            '{"entry":{"file":"_app/a.js","css":["_app/a.css"]}}\n',
            encoding="utf-8",
            newline="\n",
        )

        (second / "contracts" / "action-facet-policy-catalog.v1.json").unlink()
        write_expected_inventory(second, second_inventory)
        missing = run_archiver(
            second, FIXTURE_ROOT / "missing.zip", FIXTURE_ROOT / "missing.json", second_inventory
        )
        if missing.returncode == 0 or "missing required files" not in missing.stderr:
            errors.append("archive accepted a missing item-13 contract")

        (second / "contracts" / "action-facet-policy-catalog.v1.json").write_bytes(b"{}\n")
        (second / "assets" / "dist" / "_app" / "a.js").unlink()
        write_expected_inventory(second, second_inventory)
        stale_assets = run_archiver(
            second,
            FIXTURE_ROOT / "stale-assets.zip",
            FIXTURE_ROOT / "stale-assets.json",
            second_inventory,
        )
        if stale_assets.returncode == 0:
            errors.append("archive accepted a missing generated manifest asset")

        (second / "assets" / "dist" / "_app" / "a.js").write_bytes(b"")
        unexpected_path = second / "private" / "unexpected.php"
        unexpected_path.parent.mkdir(parents=True, exist_ok=True)
        unexpected_path.write_bytes(b"<?php\n")
        write_expected_inventory(second, second_inventory)
        unexpected = run_archiver(
            second,
            FIXTURE_ROOT / "unexpected.zip",
            FIXTURE_ROOT / "unexpected.json",
            second_inventory,
        )
        if unexpected.returncode == 0 or "unexpected inventory" not in unexpected.stderr:
            errors.append("archive accepted unexpected nested inventory")
        unexpected_path.unlink()
        unexpected_path.parent.rmdir()

        root_link = FIXTURE_ROOT / "linked" / "sentient-forms"
        root_link.parent.mkdir(parents=True, exist_ok=True)
        try:
            create_directory_link(root_link, second)
        except OSError as error:
            errors.append(f"archive root symlink fixture could not be created: {error}")
        else:
            linked = run_archiver(
                root_link,
                FIXTURE_ROOT / "linked.zip",
                FIXTURE_ROOT / "linked.json",
                second_inventory,
            )
            if linked.returncode == 0:
                errors.append("archive accepted a symlinked package root")

        spec = importlib.util.spec_from_file_location("item14_archiver", ARCHIVER)
        if spec is None or spec.loader is None:
            errors.append("archive path validator could not be loaded")
        else:
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            validator = getattr(module, "validate_inventory_paths", None)
            if validator is None:
                errors.append("archive has no canonical inventory path validator")
            else:
                for unsafe in (
                    "../escape.php",
                    "/absolute.php",
                    "includes/foo\\bar.php",
                    "includes/foo:bar.php",
                ):
                    try:
                        validator([unsafe])
                    except ValueError:
                        pass
                    else:
                        errors.append(f"archive accepted unsafe inventory identity: {unsafe}")
                try:
                    validator(["includes/Case.php", "includes/case.php"])
                except ValueError:
                    pass
                else:
                    errors.append("archive accepted a case-colliding inventory identity")

        if errors:
            print("Deterministic archive test failed:", file=sys.stderr)
            for error in errors:
                print(f"- {error}", file=sys.stderr)
            return 1

        print("Deterministic archive test passed: 2 byte-identical builds and 12 fail-closed cases.")
        return 0
    finally:
        shutil.rmtree(FIXTURE_ROOT, ignore_errors=True)


if __name__ == "__main__":
    raise SystemExit(main())
