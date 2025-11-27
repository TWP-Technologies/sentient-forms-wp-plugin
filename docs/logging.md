# Sentient Forms Logging (WordPress-side)

## Overview
- JSONL log at `wp-content/uploads/sentient-forms/logs/sf.log`
- Rotation: size-based (default 5 MB) with up to 5 gzipped backups.
- Toggle: define `SENTIENT_FORMS_LOG_ENABLED=true` (or filter `sentient_forms_enable_logging`).
- Purpose: observable validation and after_submission execution with minimal PII for support/debug.

## PII minimization
- Logs include: hook, action_id, form_id, entry_id (if present), request/execution IDs, status.
- Masked: emails (localpart+…), IPs (/24 or shortened IPv6), user agent (hash), URLs (host only).
- Not logged: payload bodies, nonces, proxy keys, auth headers, LLM prompts/outputs.

## Support export
- WP-CLI commands (requires WP-CLI):  
  - `wp sentient-forms logs tail --lines=200`  
  - `wp sentient-forms logs bundle` (creates zip/gz in the log directory)

## Notes
- Keep logging disabled outside debugging sessions.  
- Ensure log directory is not web-served; permissions are created under uploads.  
- Correlation IDs are generated to align with CPS request IDs when available.
