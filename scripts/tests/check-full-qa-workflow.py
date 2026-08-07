#!/usr/bin/env python3
"""Semantically verify the Full QA classifier and required-gate job graph."""

from __future__ import annotations

from pathlib import Path
import sys

import yaml


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "full-qa.yml"


def main() -> int:
    errors: list[str] = []
    document = yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))
    triggers = document.get("on", {})
    if any("paths-ignore" in (triggers.get(event) or {}) for event in ("push", "pull_request")):
        errors.append("top-level path filtering can omit required producers")

    jobs = document.get("jobs", {})
    changes = jobs.get("changes", {})
    full_qa = jobs.get("full_qa", {})
    required = jobs.get("required_full_qa", {})
    expected_outputs = {
        "classification": "${{ steps.classify.outputs.classification }}",
        "full_qa": "${{ steps.classify.outputs.full_qa }}",
        "docs_only": "${{ steps.classify.outputs.docs_only }}",
    }
    if changes.get("outputs") != expected_outputs:
        errors.append("classifier outputs do not match the executable contract")
    if full_qa.get("needs") != "changes":
        errors.append("Full QA does not depend on its classifier")
    if full_qa.get("if") != "${{ needs.changes.outputs.full_qa == 'true' }}":
        errors.append("Full QA is not conditioned on the classifier output")
    if required.get("needs") != ["changes", "full_qa"]:
        errors.append("required gate does not depend on both producers")
    if required.get("if") != "${{ always() }}":
        errors.append("required gate is not always evaluated")

    change_steps = {step.get("name"): step for step in changes.get("steps", [])}
    contract_run = change_steps.get("Validate workflow and classifier contracts", {}).get("run", "")
    expected_contracts = {
        "php scripts/tests/check-full-qa-classifier.php",
        "php scripts/tests/check-full-qa-required-gate.php",
        "python3 scripts/tests/check-full-qa-workflow.py",
    }
    if not expected_contracts.issubset(set(contract_run.splitlines())):
        errors.append("classifier job does not execute every local contract")
    classify_run = change_steps.get("Classify required QA", {}).get("run", "")
    if classify_run.count("git diff --no-renames --name-only") != 2:
        errors.append("push and pull-request classification do not both include rename endpoints")

    required_steps = required.get("steps", [])
    gate_steps = [step for step in required_steps if "scripts/require-full-qa-gates.php" in step.get("run", "")]
    if len(gate_steps) != 1:
        errors.append("required gate does not execute exactly once")
    else:
        environment = gate_steps[0].get("env", {})
        expected_environment = {
            "CLASSIFIER_RESULT": "${{ needs.changes.result }}",
            "FULL_QA_REQUIRED": "${{ needs.changes.outputs.full_qa }}",
            "FULL_QA_RESULT": "${{ needs.full_qa.result }}",
        }
        if environment != expected_environment:
            errors.append("required gate producer environment is incomplete")

    if errors:
        print("Full QA workflow contract failed:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        return 1
    print("Full QA semantic workflow contract passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
