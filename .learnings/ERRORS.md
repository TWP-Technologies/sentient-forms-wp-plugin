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
