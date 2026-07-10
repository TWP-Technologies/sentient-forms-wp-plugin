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
