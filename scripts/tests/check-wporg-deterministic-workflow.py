#!/usr/bin/env python3
"""Semantically verify deterministic package and release workflow contracts."""

from __future__ import annotations

from fnmatch import fnmatchcase
from pathlib import Path
import re
import sys

import yaml


ROOT = Path(__file__).resolve().parents[2]
PACKAGE_WORKFLOW = ROOT / ".github" / "workflows" / "wporg-package.yml"
RELEASE_WORKFLOW = ROOT / ".github" / "workflows" / "release-please.yml"


def step_by_name(job: dict[str, object], name: str) -> dict[str, object]:
    matches = [step for step in job.get("steps", []) if step.get("name") == name]
    if len(matches) != 1:
        raise ValueError(f"expected exactly one step named {name}")
    return matches[0]


def path_is_triggered(path: str, patterns: list[str]) -> bool:
    return any(fnmatchcase(path, pattern) for pattern in patterns)


def invoked_repository_paths(job: dict[str, object]) -> set[str]:
    invoked: set[str] = set()
    for step in job.get("steps", []):
        uses = step.get("uses", "")
        if isinstance(uses, str) and uses.startswith("./"):
            invoked.add(uses[2:].rstrip("/") + "/action.yml")
        run = step.get("run", "")
        if not isinstance(run, str):
            continue
        invoked.update(
            match.group(1)
            for match in re.finditer(
                r"^(?:php|python3|node)\s+([A-Za-z0-9_./][A-Za-z0-9_./-]*)",
                run,
                re.MULTILINE,
            )
        )
    return invoked


def verify_archive_order(job: dict[str, object], step_name: str, upload_name: str) -> list[str]:
    errors: list[str] = []
    steps = job.get("steps", [])
    names = [step.get("name") for step in steps]
    try:
        archive_index = names.index(step_name)
        upload_index = names.index(upload_name)
    except ValueError as error:
        return [str(error)]
    if archive_index >= upload_index:
        errors.append(f"{step_name} does not precede {upload_name}")
    run = steps[archive_index].get("run", "")
    markers = [
        'python3 scripts/build-deterministic-wporg-zip.py "$package_dir_a"',
        'python3 scripts/build-deterministic-wporg-zip.py "$package_dir_b"',
        'cmp --silent "$zip_path_a" "$zip_path_b"',
        'cp "$zip_path_a"',
    ]
    positions = [run.find(marker) for marker in markers]
    if any(position < 0 for position in positions) or positions != sorted(positions):
        errors.append(f"{step_name} does not build twice and compare before publication copy")
    for suffix in ("", "-b"):
        variable = "inventory_a" if suffix == "" else "inventory_b"
        if f"steps.package.outputs.inventory{suffix}" not in run:
            errors.append(f"{step_name} does not consume package inventory output {suffix or 'a'}")
        if f'--expected-inventory "${variable}"' not in run:
            errors.append(f"{step_name} does not verify package inventory {suffix or 'a'}")
    return errors


def verify_locked_generated_build(job: dict[str, object], package_step_name: str) -> list[str]:
    errors: list[str] = []
    steps = job.get("steps", [])
    names = [step.get("name") for step in steps]
    required_names = [
        "Set up Bun",
        "Install locked admin dependencies",
        "Build and verify generated admin assets",
        package_step_name,
    ]
    try:
        positions = [names.index(name) for name in required_names]
    except ValueError as error:
        return [str(error)]
    if positions != sorted(positions):
        errors.append("locked admin setup/build does not precede package construction")
    setup = steps[positions[0]]
    if setup.get("uses") != "oven-sh/setup-bun@v2":
        errors.append("generated build does not use the pinned Bun setup action")
    install = steps[positions[1]]
    if install.get("working-directory") != "admin-app" or install.get("run") != "bun install --frozen-lockfile":
        errors.append("generated build does not install the locked admin dependency graph")
    generated = steps[positions[2]]
    generated_run = generated.get("run", "")
    if generated.get("working-directory") != "admin-app":
        errors.append("generated build verification does not run from admin-app")
    for command in ("bun run generated:freshness:check", "bun run generated:whitespace:check"):
        if command not in generated_run:
            errors.append(f"generated build verification misses: {command}")
    return errors


def main() -> int:
    errors: list[str] = []
    package = yaml.safe_load(PACKAGE_WORKFLOW.read_text(encoding="utf-8"))
    release = yaml.safe_load(RELEASE_WORKFLOW.read_text(encoding="utf-8"))
    package_job = package.get("jobs", {}).get("wporg-package", {})
    release_job = release.get("jobs", {}).get("publish-release", {})

    triggers = package.get("on", {})
    push_paths = (triggers.get("push") or {}).get("paths", [])
    pull_paths = (triggers.get("pull_request") or {}).get("paths", [])
    if push_paths != pull_paths:
        errors.append("push and pull-request package trigger inputs differ")
    invoked = invoked_repository_paths(package_job)
    for path in sorted(invoked):
        if not path_is_triggered(path, push_paths):
            errors.append(f"package trigger does not cover invoked input: {path}")
    for required in {
        "scripts/check-action-facet-policy-snapshot.php",
        "scripts/check-action-source-compatibility-snapshot.php",
        "scripts/tests/check-class-map-generator.php",
        "scripts/tests/check-deterministic-wporg-archive.py",
        "scripts/tests/check-wporg-package-builder-process.php",
        "scripts/tests/check-wporg-deterministic-workflow.py",
        ".github/actions/run-plugin-check/action.yml",
    }:
        if not path_is_triggered(required, push_paths):
            errors.append(f"package trigger misses required producer input: {required}")

    try:
        package_build = step_by_name(package_job, "Build two independent WordPress.org packages")
        if package_build.get("id") != "package":
            errors.append("package build step does not expose package outputs")
        package_upload = step_by_name(package_job, "Upload package artifact")
        upload_paths = package_upload.get("with", {}).get("path", "")
        if "steps.package-artifacts.outputs.zip-path" not in upload_paths:
            errors.append("package upload is not wired to deterministic artifact outputs")
    except ValueError as error:
        errors.append(str(error))
    errors.extend(
        verify_archive_order(
            package_job,
            "Create and compare deterministic package artifacts",
            "Upload package artifact",
        )
    )
    errors.extend(verify_locked_generated_build(package_job, "Build two independent WordPress.org packages"))

    if release_job.get("needs") != "release-please":
        errors.append("publish-release does not depend on release-please")
    if release_job.get("if") != "${{ github.ref == 'refs/heads/production' }}":
        errors.append("publish-release is not restricted to production")
    try:
        release_build = step_by_name(release_job, "Build two independent WordPress.org packages")
        if release_build.get("id") != "package":
            errors.append("release package build step does not expose outputs")
        release_zip = step_by_name(release_job, "Create release zip")
        if release_zip.get("id") != "release-zip":
            errors.append("release ZIP step does not expose deterministic outputs")
    except ValueError as error:
        errors.append(str(error))
    errors.extend(verify_archive_order(release_job, "Create release zip", "Upload package artifact"))
    errors.extend(verify_locked_generated_build(release_job, "Build two independent WordPress.org packages"))

    if errors:
        print("Deterministic workflow semantic contract failed:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        return 1
    print("Deterministic package/release semantic workflow contract passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
