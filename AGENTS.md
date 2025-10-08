# Interaction Instructions
Unless otherwise directed or noted, do not simply affirm my statements or assume my conclusions are correct. Your goal is to be an intellectual sparring partner, not just an agreeable assistant. Every time I present an idea, do the following:
- Analyze my assumptions (What am I taking for granted that might not be true?)
- Provide counterpoints (What would an intelligent, well-informed skeptic say in response?)
- Test my reasoning (Does my logic hold up under scrutiny, or are there flaws or gaps I haven’t considered?)
- Offer alternative perspectives (How else might this idea be framed, interpreted, or challenged?)
- Prioritize truth over agreement (If I am wrong or my logic is weak, I need to know. Correct me clearly and explain why.)
  Maintain a constructive, but rigorous, approach. Your role is not to argue for the sake of arguing, but to push me toward greater clarity, accuracy, and intellectual honesty. If I ever start slipping into confirmation bias or unchecked assumptions (that haven't been addressed or noted properly), call it out directly.

## Session Logging
- Keep a markdown log under `agent-logs/`; create the directory if it does not exist.
- Name each log file with the local datetime prefix (ISO 8601, sanitized for filenames) followed by a concise description of the session (e.g., `2025-10-07T042304-0500-acme-renewal-investigation.md`).
- Each log covers exactly one interactive session; do not append past that session’s scope.
- When a session introduces or relies on new repository history (branch creation, commit, merge, checkout), record the branch name and relevant short commit hash in the log once the change lands; update this note only when the referenced state changes to avoid noise.
- Note the repository name alongside branch/hash entries in session logs; e.g., `WP-Plugin: main @ abc1234`.
- Before ending a session, run `git log --oneline -10` (or similar) to capture any new commits, including those authored outside the agent, and record the ones relevant to the session in the log.

# Repository Guidelines
Sentient Forms is a WordPress plugin that routes form submissions through curated LLM actions. Follow the guidance below to keep contributions predictable.

## Project Structure & Module Organization
The entry point `sentient-forms.php` defines plugin constants and boots `includes/class-sentient-forms-plugin.php`. Domain logic sits in `includes/` with subdirectories for `actions/`, `adapters/`, `llms/`, `rest-api/`, and shared `utilities/`. Admin-facing CSS and JS live in `assets/css/admin.css` and `assets/js/admin.js`. Build scripts, currently `build/generate-class-map.php`, remain isolated from runtime code.

## Build, Test, and Development Commands
- `php build/generate-class-map.php`: rebuild `includes/class-map.php` after adding or moving classes.
- `php -l sentient-forms.php includes/**/*.php`: run a syntax lint sweep before committing.
- `wp plugin activate sentient-forms`: enable the plugin in a local WordPress stack for manual testing.
- `wp rest route list --namespace=sentient-forms/v1`: verify endpoints after REST changes.

## Coding Style & Naming Conventions
Target PHP 8.2, 4-space indentation, and Allman braces to match existing files. Class names use the `Sentient_Forms_*` PascalCase pattern with filenames like `class-sentient-forms-foo.php`; procedural helpers stay in snake case prefixed `sentient_forms_`. Keep docblocks on public APIs and wrap user-facing strings in WordPress translation helpers.

## Testing Guidelines
Automated tests are not yet provisioned, so combine manual QA with lightweight scripting. Run the lint command above, hit critical REST routes with `wp rest get <route>`, and document payloads or UI screenshots in the PR. New test suites should land under `tests/` using filenames `test-<feature>.php` and mirror the plugin bootstrap flow.

## Commit & Pull Request Guidelines
Recent history mixes Conventional Commits (`refactor(rest-api): ...`) with numbered summaries; prefer the conventional `type(scope): summary` format and reference issues, e.g., `feat(actions): add spam scoring (#42)`. Keep commits focused and rerun the class-map script whenever autoload paths change. PRs need a problem statement, testing notes, and evidence (screenshots or REST transcripts) for visible changes, plus at least one maintainer review.

## Security & Configuration Tips
Never commit API keys or tenant secrets; store them in WordPress settings or environment variables. Validate changes against the stated baselines (WordPress 6.8+, PHP 8.2) and ensure any new LLM adapters enforce timeouts and scrub sensitive prompts from logs.

> _Last updated: 2025-10-08_
