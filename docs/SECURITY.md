# Security

Bundle-specific security analysis. The short GitHub reporting policy lives in [`.github/SECURITY.md`](../.github/SECURITY.md).

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Admin UI exposure and allow_unauthenticated](#admin-ui-exposure-and-allow_unauthenticated)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Resource limits](#resource-limits)
- [CSRF](#csrf)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Residual risks and host guidance](#residual-risks-and-host-guidance)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## Scope

Covers the settings store (`InstanceSettings`, repository, entity), the encrypted secret fields, the admin UI
(controller, forms, access listener / checker, route loader), config portability (export / import service, upload
reader, sealing, CLI commands), the Twig function and the configuration tree. It does **not** cover the host
firewall, the sections and portability providers contributed by the host or other bundles, DoctrineEncryptBundle's
own cryptography and key storage, or what consumers do with a setting value.

## Attack surface

| Input | Where | Notes |
| --- | --- | --- |
| Admin HTTP `GET` / `POST` (session) | `web_ui.path_prefix` (only when the host imports the `nowo_instance_settings` route loader) | Role / custom checker per area, Symfony Forms with CSRF. |
| Uploaded JSON file | `POST …/portability/import` | Multipart Form (`FileType`, `File` constraint), size-capped reader, schema / version / key validation, all-or-nothing transaction. |
| CLI file path | `nowo:instance-settings:import <file>` | Operator input; same reader and validation. |
| Section definitions / providers | host and bundles (services) | Trusted code; definitions validated at first use. |
| Configuration | `nowo_instance_settings.*` | Validated at compile time; security switches must be literal booleans. |
| Stored values | database | Plain values are JSON; secrets are encrypted at rest. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Unauthorized access to settings / secrets** | UI private by default (`ROLE_ADMIN`); empty role lists are refused at compile time and deny at runtime (pattern P1); per-area roles (`security.area_roles`) for sensitive sections and for import / export — when sections are restricted, `_portability` must be set explicitly (compile error otherwise), and the web export / import only covers the sections the user may open (an import touching another section is refused as a whole, from its raw keys, before any value is validated or unsealed; the CLI keeps full operator access); a listener guards every request under the prefix **and** every bundle controller wherever it is mounted (other prefix, sub-request); every action re-checks its area. The token is trusted only when a firewall matched the main request (no stale worker token). Anonymous users inside a firewall get the entry point (401 / login). |
| **CSRF** on saves, export, import | Every mutation is a Symfony Form with CSRF (`nowo_instance_settings_section_<name>`, `…_export`, `…_import`); the container refuses to compile the UI without form CSRF or a token manager (pattern P2). Export is a POST (it can contain sealed secrets). |
| **Secret disclosure in HTML** | The section form never receives a stored secret (only "stored: yes / no"); the secret input is a password field rendered empty; secrets are refused by `get()`, `getSection()` and the Twig function. Contract and functional tests assert a stored secret never appears in the page. |
| **Secret disclosure at rest** | `#[Encrypted]` column through DoctrineEncryptBundle (default profile); the container refuses to compile without DoctrineEncrypt (secrets would be stored in clear). Every stored secret carries a terminator, so its plaintext never ends with DoctrineEncrypt's `<ENC>` marker (which would skip encryption) and is never `''` / `'0'`; a loaded value without the terminator (not decrypted: wrong or lost key) counts as no secret instead of handing ciphertext to consumers. |
| **Secret disclosure in exports** | Secrets are exported only on request (`include_secrets` / `--include-secrets`, gated by `portability.sealed_secrets` and the `_portability` area) and only **sealed** with the instance key (`enc:v2:`), each value bound to its schema and `<section>.<field>` (a sealed value moved to another field or application is refused: `secret_context_mismatch`); an import from another instance fails closed (`failed_unseal_secret`). Plain fields cannot carry secrets: secret fields are refused under `settings`, and credential-shaped keys are refused in provider data on import **and** export (keys normalised — camelCase, `-`, `.` — and matched on words such as password, passwd, passphrase, secret, token, bearer, credential, apikey, privatekey, signature, or parts such as pwd, pass, key, dsn, auth). CLI export files are written in a private 0700 directory, set to `0600` and renamed into place (no world-readable window, symbolic links refused, existing files only with `--force`); export responses are `private, no-store`. |
| **Malicious import** (type confusion, mass assignment, weakening flags) | Strict envelope (known top-level keys, schema, version), known sections / fields only, sections the user may not open refused, `notPortable()` fields refused, every value normalized and validated with the field type and constraints (non-finite floats such as `1e999` refused), provider `validate()` before anything is written, single transaction; a refused file changes nothing and the error names the key, never the value. Hosts mark security-sensitive toggles `notPortable()`. |
| **Oversized / malformed upload** | `File` constraint (`max_upload_bytes`, JSON / text MIME) plus a reader that stops at `max + 1` bytes, `json_decode` depth 64, root must be an object. |
| **Injection** | Doctrine ORM with bound parameters only; no SQL / DQL concatenation with input; JSON responses encoded with `JSON_THROW_ON_ERROR`. |
| **Accidental route exposure** | The controller is not autoconfigured, so `routing.controllers` never loads it; routes come only from the `nowo_instance_settings` loader import (REQ-SF-004 rule 7, integration test). |
| **Demo configuration in production** | `security.allow_unauthenticated: true` is refused unless `kernel.debug` is true. The demo publishes its port on `127.0.0.1` only. |
| **`security: false` firewall on the prefix** | No token is trusted; the bundle answers a plain 403 (a Security exception is used only inside an enabled firewall, where it becomes the entry point). |
| **Cross-request state leaks (FrankenPHP worker)** | The settings memo is bound to the main request (`WeakReference`) and cleared by `kernel.reset` and after saves; other services are stateless. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). |
| **XSS** | Twig auto-escaping; labels come from translation catalogues; no `|raw`. |

## Admin UI exposure and allow_unauthenticated

The admin UI edits instance-wide behaviour and secrets: keep it behind the firewall (`access_control` on
`web_ui.path_prefix`) **and** the bundle checker. `security.allow_unauthenticated: true` is a demo / local switch only;
the container refuses it without `kernel.debug`, but never set it in a shared or staging environment either. Restrict
the import / export page (`security.area_roles._portability`); its web export / import covers only the sections the
user may open, but a sealed export plus the instance key reveals the secrets of those sections.

## Secrets and cryptography

- Encryption at rest and sealing use DoctrineEncryptBundle (Halite by default). Keep the key out of the repository
  (`secret_key_env_var` or a key file under `var/` with mode `0600`; DoctrineEncrypt hardens key file permissions).
  Rotate with `doctrine:encrypt:rotate-keys`.
- Sealed values: `enc:v2:` + base64 of the DoctrineEncrypt ciphertext of `<schema>|<section>.<field>\n<plaintext>`;
  unsealing refuses values without the marker, any decryption failure, a "decryption" that returns its input (no
  plaintext pass-through) and a context mismatch. The sealer refuses the deprecated, unauthenticated MysqlAes (AES-ECB) profile
  (`unsupported_encryptor`): use Halite or Defuse.
- Fixtures and the demo use obvious placeholders (`REDACTED-TEST`, `REDACTED-DEMO`).

## Logging

- `info` "Instance settings saved." with section, changed **field names** and origin.
- `info` "Instance configuration exported." / "… imported." with section names / changed keys and whether secrets
  were included.
- Never logged: values, secrets, uploaded file content. Exceptions carry reason codes and keys only.
- `SettingsChangedEvent` carries field names only; hosts that write an audit trail record names, not values.

## Resource limits

- Reads: one query per request for all rows (no N+1); writes: one query per section + one flush.
- Imports: `portability.max_upload_bytes` (default 1 MiB, max 16 MiB), JSON depth 64.
- No outbound HTTP, processes or long-running work (REQ-RUNTIME-001 not applicable).

## CSRF

Applies (session-authenticated admin mutations): Symfony Forms with CSRF on every POST (section save, export, import),
fail-closed container check, no `csrf_protection: false` on bundle forms. The CLI has no session and no CSRF.

## Dependencies and updates

- Runtime dependencies: Symfony components, Doctrine ORM / DoctrineBundle, DoctrineEncryptBundle, UiKit, FormKit, Twig
  Extra. CI runs `composer audit --locked` on every matrix cell; Dependabot groups Composer and GitHub Actions updates.
- Security fixes are released as patch versions and noted in the changelog.

## Release security checklist (12.4.1)

| Item | How it is satisfied |
| --- | --- |
| SECURITY.md | This document + `.github/SECURITY.md`. |
| `.env` in `.gitignore` | Root and demo `.gitignore` exclude `.env`, `.env.local`, `.env.dev`, key files. |
| No secrets | Placeholders only in fixtures and demo; the demo key is generated at start and ignored. |
| Safe config / recipe | Recipe ships `ROLE_ADMIN`, `allow_unauthenticated: false` and the `access_control` reminder. |
| Input / output | Strict import validation, size cap, Twig escaping, JSON encoding. |
| Dependencies | `composer audit --locked` in CI. |
| No-secret logs | See [Logging](#logging). |
| Cryptography | DoctrineEncrypt (Halite / Defuse); sealing fails closed. |
| Permissions / exposure | Opt-in routes, roles + area roles, firewall-only token trust, fail-closed compile checks. |
| Limits / DoS | One query per request, upload cap. |
| AI security audit (REQ-SEC-004) | Passed — Appendix BY, final grade **Pass (good) / Low**; every finding fixed before 1.0.0 — see below. |

## AI security audit (REQ-SEC-004)

| Pass | Date | Revision | Grade | Findings | Fixed in |
| --- | --- | --- | --- | --- | --- |
| First pass | 2026-10-31 | `94a1569` (PR #1) | **Pass (conditional) / Medium** | M1 portability bypassed per-section `area_roles`; L1 secrets ending with `<ENC>` stored in clear; L2 sealed values not bound to their field; L3 credential-key filter bypassable and export unfiltered; L4 CLI export file briefly world-readable / symlinks followed; Info I1–I8 | PR #3 (`1fec7a3`), before 1.0.0 |
| Final (re-verification) | 2026-10-31 | `1fec7a3` (PR #3) | **Pass (good) / Low** | Residuals R1 (forbidden sections checked after value validation), R2 (credential filter heuristic, documented below), R4 (DoctrineEncrypt 2.10 options, MysqlAes) | PR #4 (`610110f`), before 1.0.0; shipped unchanged in 1.1.0 |

Both passes are recorded in the monorepo `BUNDLES_SECURITY_ANALYSIS.md` (Appendix BY). The accepted residuals are listed
under [Residual risks and host guidance](#residual-risks-and-host-guidance).

## Residual risks and host guidance

- **I1 — endpoint retargeting.** Whoever may edit a section can change its plain endpoint fields (host, base URL,
  webhook) and have the stored secret of the same section sent there without reading it. Put an endpoint and its
  credential in one secret field (as a mailer DSN does), or restrict such sections with `area_roles` and mark endpoint
  fields `notPortable()`, or clear the secret when its endpoint changes (`SettingsChangedEvent`).
- **I2 — debug switch.** `allow_unauthenticated` is refused unless `kernel.debug`; `APP_ENV=prod` with `APP_DEBUG=1`
  is still accepted — never run production with debug on.
- **I5 — memo outside HTTP.** CLI commands and Messenger messages keep the values (and decrypted secrets) read at their
  start until `kernel.reset` (between messages) or the end of the command.
- **I6 — section names.** An unknown section answers 404, a known but denied one 403; only users who already pass the UI
  gate see the difference.
- **DoctrineEncrypt key.** Keep the key in a persistent, backed-up location; with the terminator check an undecryptable
  value reads as "not set" whatever `on_decrypt_failure` is, and sealed imports fail closed.
- **Credential-key filter (R2) is a heuristic.** It normalises keys and matches English credential words / key parts
  (password, secret, token, bearer, credential, apikey, privatekey, signature, pwd, pass, key, dsn, auth, otp, pin,
  salt, session, jwt, mfa, seed, webhook…). Keys in other languages, homoglyphs or unusual names pass: providers must
  still export no secrets — the filter is a safety net, not the contract.
- **DoctrineEncrypt profile.** Use Halite or Defuse for the default profile (the sealer refuses MysqlAes with
  `unsupported_encryptor`), set `on_decrypt_failure: 'null'` (or `throw`) and leave `generate_missing_key` off in
  production (DoctrineEncrypt 2.10).
- **Demo tooling.** The Playwright screenshot converter (`sharp`, dev only, not shipped) may carry upstream image-library
  advisories; it never processes untrusted images.

## Reporting a vulnerability

Please follow [`.github/SECURITY.md`](../.github/SECURITY.md) — do not open a public issue.
