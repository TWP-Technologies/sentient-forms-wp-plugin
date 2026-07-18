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

## [ERR-20260717-004] phpunit-bat-filter-regex-quoting

**Logged**: 2026-07-17T06:35:00-05:00
**Priority**: low
**Status**: resolved
**Area**: tests

### Summary
A grouped PHPUnit filter passed through the Windows batch shim was split at the regex alternation before PHPUnit started.

### Error
```text
'attested_' is not recognized as an internal or external command
```

### Context
- The focused filter contained parentheses and a pipe character.
- PowerShell invoked `vendor\\bin\\phpunit.bat`, adding a second `cmd.exe` parsing layer.

### Suggested Fix
For regex filters on Windows, invoke `php vendor\\phpunit\\phpunit\\phpunit --filter '<regex>'` directly instead of the batch shim.

### Metadata
- Reproducible: yes
- Related Files: phpunit.xml, tests/phpunit/test-gravity-forms-adapter.php
- See Also: ERR-20260717-003

### Resolution
- **Resolved**: 2026-07-17T06:36:00-05:00
- **Notes**: The direct PHP runner executed all six intended regression tests successfully.

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
- No repository or runtime state changed.

### Suggested Fix
Invoke `php vendor/phpunit/phpunit/phpunit --filter '<class expression>'` and capture output in a PowerShell variable before selecting the summary; use a test directory or suite rather than multiple file arguments.

### Metadata
- Reproducible: yes
- Related Files: tests/phpunit/test-form-actions-controller.php
- See Also: ERR-20260710-006

### Resolution
- **Resolved**: 2026-07-10T11:10:32-05:00
- **Notes**: The class-filtered runs passed with 219 tests / 1,582 assertions and 146 tests / 1,095 assertions.

---

## [ERR-20260717-001] phpunit-polyfill-autoloader-invocation

**Logged**: 2026-07-17T02:20:00-05:00
**Priority**: low
**Status**: resolved
**Area**: tests

### Summary
The PHPUnit polyfill autoloader was invoked as though it were the test runner, producing exit code 0 while executing no tests.

### Error
```text
php vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php --filter <class>
Exit code: 0, no PHPUnit result or log file
```

### Context
- A focused rerun used the polyfill bootstrap file instead of the repository's PHPUnit executable.
- Because the bootstrap exits successfully, checking only the process exit code could have created a false green.
- No repository or runtime state changed beyond this learning entry.

### Suggested Fix
Invoke `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --filter '<class expression>'`, and require the captured log to contain the PHPUnit test summary in addition to exit code 0. Inspect the checkout's actual configuration filename rather than assuming the common `.dist` suffix.

### Metadata
- Reproducible: yes
- Related Files: phpunit.xml
- See Also: ERR-20260710-007

### Resolution
- **Resolved**: 2026-07-17T02:20:00-05:00
- **Notes**: Corrected both the runner and the checkout-specific configuration filename, then added an explicit summary-presence check to the focused rerun.

---

## [ERR-20260717-003] validation-command-assumptions

**Logged**: 2026-07-17T02:58:09-05:00
**Priority**: low
**Status**: resolved
**Area**: tests

### Summary
Three read-only validation commands assumed a source path, PHPUnit file invocation, or shell timeout behavior that this checkout does not provide.

### Error
```text
rg: includes/services/class-sentient-forms-local-execution-service.php: The system cannot find the file specified.
Class test-bundled-action-templates could not be found
command timed out after 1022 milliseconds
```

### Context
- The local execution service filename includes `-action-`, and a combined lookup failed when given the shorter guessed path.
- This WordPress PHPUnit suite discovers tests through `phpunit.xml`; passing a hyphenated test filename directly made PHPUnit derive a nonexistent class name.
- A one-second shell timeout terminates the child process; it is not an asynchronous launch mechanism.

### Suggested Fix
Resolve filenames with `rg --files` before targeting them, invoke focused WordPress tests through the configured suite plus `--filter`, and use a long child timeout with an early-yielding orchestration cell for long-running suites.

### Metadata
- Reproducible: yes
- Related Files: includes/services/class-sentient-forms-local-action-execution-service.php, phpunit.xml
- See Also: ERR-20260717-001, ERR-20260710-007

### Resolution
- **Resolved**: 2026-07-17T02:58:09-05:00
- **Notes**: Reissued each lookup/test with the checkout-resolved path and configured suite; the final full run completed with 1,191 tests and 9,828 assertions.

---

## [ERR-20260717-002] powershell-rg-alternation-quoting

**Logged**: 2026-07-17T02:24:00-05:00
**Priority**: low
**Status**: resolved
**Area**: tests

### Summary
A PowerShell-quoted ripgrep alternation lost its closing quoted branch and reached ripgrep as an invalid regular expression.

### Error
```text
rg: regex parse error: unclosed group
```

### Context
- The lookup only needed one literal action code.
- The unnecessary alternation increased quoting risk without adding evidence.

### Suggested Fix
Use `rg -F` for literal identifiers; introduce a regular expression only when matching behavior actually requires one.

### Metadata
- Reproducible: yes
- Related Files: includes/class-sentient-forms-bundled-action-templates.php

### Resolution
- **Resolved**: 2026-07-17T02:24:00-05:00
- **Notes**: Reissued the lookup with `rg -F` and obtained the canonical schema definition.

---
