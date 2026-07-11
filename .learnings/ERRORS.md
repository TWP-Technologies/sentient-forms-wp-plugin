# Errors

## [ERR-20260710-001] dynamic-apply-patch-block-removal

**Logged**: 2026-07-10T09:42:36-05:00
**Priority**: low
**Status**: resolved
**Area**: backend

### Summary
A read-only helper failed to locate a PHP block because it assumed CRLF line endings after `apply_patch` had normalized that region.

### Error
`Legacy validation block markers not found.`

### Context
- Attempted to construct an `apply_patch` deletion from exact marker offsets.
- The marker used CRLF while the edited file region used LF.

### Suggested Fix
Normalize raw text to LF before calculating marker offsets for generated patches.

### Metadata
- Reproducible: yes
- Related Files: includes/adapters/forms/class-sentient-forms-gravity-forms-adapter.php

### Resolution
- **Resolved**: 2026-07-10T09:42:36-05:00
- **Notes**: Retry normalizes line endings before marker lookup.

---
## [ERR-20260711-004] stale_class_map_generator_reference

**Logged**: 2026-07-11T15:26:00-05:00
**Priority**: medium
**Status**: resolved
**Area**: config

### Summary
The generated class-map header referenced a nonexistent filename, and the real generator emitted tab-indented output rejected by the changed-file quality gate.

### Error
```text
Could not open input file: build/generate-classmap.php
Generic.WhiteSpace.DisallowTabIndent: 133 errors after regeneration
```

### Context
- The actual generator is `build/generate-class-map.php`.
- Regenerating after adding a class reintroduced whitespace defects that the deterministic quality guard correctly rejects.
- The generated map remained semantically valid, but could not be handed off in a quality-green state.

### Suggested Fix
Keep the generated header and usage examples aligned with the real filename, and have the generator emit space indentation.

### Resolution
- **Resolved**: 2026-07-11T15:26:00-05:00
- **Notes**: Corrected the generator references/output indentation and regenerated the class map before rerunning the real guard.

### Metadata
- Reproducible: yes
- Related Files: build/generate-class-map.php, includes/class-map.php, scripts/check-changed-file-quality.php

---
## [ERR-20260711-003] powershell_reserved_error_variable

**Logged**: 2026-07-11T15:08:00-05:00
**Priority**: low
**Status**: resolved
**Area**: config

### Summary
A contract-hash verification attempted to assign to PowerShell's reserved automatic `$Error` variable.

### Error
```text
Cannot overwrite variable Error because it is read-only or constant.
```

### Context
- The intended schema copies completed before the hash-variable assignment failed.
- The command used `$error` for the execute-error schema digest; PowerShell variable names are case-insensitive, so this collided with `$Error`.
- No unintended product or runtime state changed.

### Suggested Fix
Use descriptive non-reserved names such as `$errorSchemaHash` for PowerShell verification variables.

### Resolution
- **Resolved**: 2026-07-11T15:08:00-05:00
- **Notes**: Reran exact-copy hash verification with non-reserved variable names.

### Metadata
- Reproducible: yes
- Related Files: contracts/cps-v2/managed/execute-error.schema.json, contracts/cps-v2/snapshot-hashes.json

---

## [ERR-20260710-009] phpunit-suite-timeout

**Logged**: 2026-07-11T00:06:00-05:00
**Priority**: medium
**Status**: resolved
**Area**: tests

### Summary
The complete WordPress integration suite exceeded the shell runner's five-minute timeout before PHPUnit emitted a terminal result.

### Error
```text
command timed out after 300764 milliseconds
```

### Context
- Command: `php vendor/phpunit/phpunit/phpunit --testsuite 'Sentient Forms'`.
- Focused suites, syntax, PHPCS, encoding, and readme validation were already green.
- The timeout is not a passing or failing PHPUnit result, so it cannot support a green claim.

### Suggested Fix
Rerun the exact suite once with a ten-minute bound and preserve PHPUnit's process exit code and terminal summary.

### Metadata
- Reproducible: unknown
- Related Files: phpunit.xml.dist

### Resolution
- **Resolved**: 2026-07-11T00:06:00-05:00
- **Notes**: Retried once with the same command and a bounded ten-minute timeout.

---

## [ERR-20260710-008] powershell-select-string-last

**Logged**: 2026-07-10T23:58:00-05:00
**Priority**: low
**Status**: resolved
**Area**: tests

### Summary
The local PowerShell `Select-String` command does not support a `-Last` parameter, so a PHPUnit summary wrapper failed after launching the test.

### Error
```text
A parameter cannot be found that matches parameter name 'Last'.
```

### Context
- The wrapper piped PHPUnit output directly into `Select-String -Last 8`.
- The wrapper obscured the test exit result; this was not a product-code failure.
- No product or runtime state changed.

### Suggested Fix
Capture command output first, filter with `Select-String`, then use `Select-Object -Last 8`; preserve the underlying process exit code separately when it matters.

### Metadata
- Reproducible: yes
- Related Files: tests/phpunit/test-telemetry-service.php

### Resolution
- **Resolved**: 2026-07-10T23:58:00-05:00
- **Notes**: Replaced the unsupported parameter with a compatible output-capture wrapper.

---

## [ERR-20260710-008] powershell-rg-wildcard-path

**Logged**: 2026-07-10T13:04:56-05:00
**Priority**: low
**Status**: resolved
**Area**: tests

### Summary
An unquoted wildcard path passed directly to `rg` was treated as an invalid Windows filename.

### Error
```text
rg: phpcs.xml*: The filename, directory name, or volume label syntax is incorrect. (os error 123)
```

### Context
- A validation discovery command passed `phpcs.xml*` as a path argument under PowerShell.
- Other read-only discovery output succeeded, but `rg` returned a nonzero status.

### Suggested Fix
Resolve wildcard paths with PowerShell first or pass explicit filenames/directories to `rg` on Windows.

### Metadata
- Reproducible: yes
- Related Files: composer.json, phpcs-rulesets/SentientForms.xml

### Resolution
- **Resolved**: 2026-07-10T13:04:56-05:00
- **Notes**: Subsequent validation uses explicit paths.

---

## [ERR-20260710-006] opaque_parallel_read_failure

**Logged**: 2026-07-10T10:20:06-05:00
**Priority**: low
**Status**: resolved
**Area**: tests

### Summary
A parallel reconnaissance batch containing nested Docker quoting failed without returning the successful sibling outputs or identifying the failing child.

### Error
```text
Script error: Exit code 1 with no child output
```

### Context
- The batch mixed local file reads with a nested `docker exec ... sh -lc` grep.
- No repository or runtime state changed.

### Suggested Fix
Run quoting-sensitive Docker inspection separately from deterministic local reads.

### Resolution
- **Resolved**: 2026-07-10T10:20:06-05:00
- **Notes**: Split the inspection into individual commands.

### Metadata
- Reproducible: unknown
- Related Files: tests/phpunit/test-contact-form-7-adapter.php
- See Also: none

---

## [ERR-20260710-005] optional_context_file_read

**Logged**: 2026-07-10T10:20:06-05:00
**Priority**: low
**Status**: resolved
**Area**: docs

### Summary
A reconnaissance batch attempted to read optional `CONTEXT.md` without first checking whether it existed.

### Error
```text
Cannot find path 'CONTEXT.md' because it does not exist.
```

### Context
- The TDD skill recommends reading `CONTEXT.md` only when present.
- The repository's Form Source architecture document was present and remained the applicable source.

### Suggested Fix
Guard optional project-context reads with `Test-Path` before `Get-Content`.

### Resolution
- **Resolved**: 2026-07-10T10:20:06-05:00
- **Notes**: Continued with existence-checked reads.

### Metadata
- Reproducible: yes
- Related Files: docs/architecture/form-source-adapter-boundaries.md
- See Also: none

---

## [ERR-20260710-004] docker-exec-nested-grep-quoting

**Logged**: 2026-07-10T09:42:36-05:00
**Priority**: low
**Status**: resolved
**Area**: infra

### Summary
A read-only Docker source grep failed because PowerShell-to-container nested quoting produced a trailing backslash.

### Error
`grep: Trailing backslash`

### Context
- The WordPress container was healthy and confirmed mounted to the current plugin checkout.
- Only the nested `sh -lc` grep failed.

### Suggested Fix
Invoke `grep` directly through `docker compose exec` when no shell expansion is required.

### Metadata
- Reproducible: yes
- Related Files: docker-compose.yml

### Resolution
- **Resolved**: 2026-07-10T09:42:36-05:00
- **Notes**: Replaced nested shell quoting with a direct container command.

---

## [ERR-20260710-003] diff-line-phpcs-range-parser

**Logged**: 2026-07-10T09:42:36-05:00
**Priority**: medium
**Status**: resolved
**Area**: tests

### Summary
A PowerShell diff-line PHPCS helper emitted non-terminating range arithmetic errors and incorrectly exited zero.

### Error
`System.Object[] does not contain a method named op_Subtraction.`

### Context
- The helper appended nested arrays using an ambiguous inline arithmetic expression.
- PowerShell continued after the errors, making the apparent green result invalid.

### Suggested Fix
Set `ErrorActionPreference=Stop`, calculate range endpoints separately, and store ranges as explicit objects.

### Metadata
- Reproducible: yes
- Related Files: includes/adapters/forms/class-sentient-forms-gravity-forms-adapter.php, tests/phpunit/test-gravity-forms-adapter.php

### Resolution
- **Resolved**: 2026-07-10T09:42:36-05:00
- **Notes**: Replaced nested array arithmetic with explicit `Start` and `End` properties.

---

## [ERR-20260710-002] functions-exec-base64-decoding

**Logged**: 2026-07-10T09:42:36-05:00
**Priority**: low
**Status**: resolved
**Area**: backend

### Summary
The `functions.exec` V8 isolate does not expose the browser `atob` helper.

### Error
`ReferenceError: atob is not defined`

### Context
- Base64 transport was used to carry a dynamically extracted block into `apply_patch`.

### Suggested Fix
Use PowerShell JSON string serialization and `JSON.parse` instead of browser base64 helpers.

### Metadata
- Reproducible: yes
- Related Files: includes/adapters/forms/class-sentient-forms-gravity-forms-adapter.php
- See Also: ERR-20260710-001

### Resolution
- **Resolved**: 2026-07-10T09:42:36-05:00
- **Notes**: Switched the helper transport to JSON.

---

## [ERR-20260710-007] phpunit-windows-file-argument

**Logged**: 2026-07-10T11:10:32-05:00
**Priority**: low
**Status**: resolved
**Area**: tests

### Summary
PHPUnit 9 on Windows interpreted an individual test-file argument as a class name, and its batch wrapper obscured the useful error behind PHP 8.4 deprecation output.

### Error
```text
Class test-form-actions-controller could not be found in ...\tests\phpunit\test-form-actions-controller.php
```

### Context
- The supervisor attempted to pass several individual test files to `vendor/bin/phpunit`, then repeated the pattern with one file.
- The `.bat` wrapper also passed pipe characters in a filter expression through `cmd.exe` unless the PHP entry point was invoked directly.
- The pattern recurred when a later focused review tried to pass multiple test files as one PHPUnit suite; the authoritative serial full suite was used instead.
- No repository or runtime state changed.

### Suggested Fix
Invoke `php vendor/phpunit/phpunit/phpunit --filter '<class expression>'` and capture output in a PowerShell variable before selecting the summary; use a test directory or suite rather than multiple file arguments.

### Metadata
- Reproducible: yes
- Recurrence-Count: 2
- Last-Seen: 2026-07-11
- Related Files: tests/phpunit/test-form-actions-controller.php
- See Also: ERR-20260710-006

### Resolution
- **Resolved**: 2026-07-10T11:10:32-05:00
- **Notes**: The class-filtered runs passed with 219 tests / 1,582 assertions and 146 tests / 1,095 assertions.

---
## [ERR-20260711-001] concurrent_phpunit_shared_database_collision

**Logged**: 2026-07-11T01:34:55-05:00
**Priority**: medium
**Status**: resolved
**Area**: tests

### Summary
Parallel targeted PHPUnit processes used the same WordPress test database and clobbered the shared `wptests_` tables.

### Error
```text
WordPress test bootstrap failed because `wptests_options` disappeared while sibling PHPUnit suites were running concurrently.
```

### Context
- A read-only independent review launched multiple targeted PHP suites in parallel against the same configured WordPress test database.
- WordPress core test bootstrap installs and tears down the shared prefixed schema, so these suites are not concurrency-safe without isolated databases or prefixes.
- The failure is test-harness contention, not a product assertion failure; no product or runtime state changed.

### Suggested Fix
Run WordPress PHPUnit suites serially by default. Parallelize them only when every process has an isolated database or unique table prefix and independent bootstrap lifecycle.

### Resolution
- **Resolved**: 2026-07-11T01:34:55-05:00
- **Notes**: The reviewer stopped parallel PHP execution; subsequent verification must use one serial suite at a time.

### Metadata
- Reproducible: yes
- Related Files: tests/bootstrap.php, tests/wp-tests-config.php, phpunit.xml
- See Also: ERR-20260710-006

---
## [ERR-20260711-002] powershell_get_item_multiple_literal_paths

**Logged**: 2026-07-11T03:55:00-05:00
**Priority**: low
**Status**: resolved
**Area**: config

### Summary
A projection-source diagnostic passed multiple paths to `Get-Item` as positional arguments instead of one `-LiteralPath` array.

### Error
```text
A positional parameter cannot be found that accepts argument 'includes/services/class-sentient-forms-action-policy-resolver.php'.
```

### Context
- The earlier status and source-search output was still useful, but the final mtime inventory failed.
- The projection verifier itself was unaffected and later proved source/snapshot equality on a frozen source set.
- No product or runtime state changed.

### Suggested Fix
Assign paths to an array and call `Get-Item -LiteralPath $paths` when reading more than one explicit path in PowerShell.

### Resolution
- **Resolved**: 2026-07-11T03:55:00-05:00
- **Notes**: The agent captured all 19 source mtimes with a proper array and froze the source set before the final full suite.

### Metadata
- Reproducible: yes
- Related Files: scripts/check-action-source-compatibility-snapshot.php, contracts/action-source-compatibility.v1.json
- See Also: ERR-20260710-005

---
