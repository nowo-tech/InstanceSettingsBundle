# Concurrency

How the settings store stays correct when many requests, workers and commands write at once (nowo-tech
REQ-RUNTIME-003). The mechanisms are explained in detail in [HIGH-LOAD.md](HIGH-LOAD.md); this page is the summary:
write paths, conflict answers, codes, host options and residual risks.

## Table of contents

- [Rules the bundle follows](#rules-the-bundle-follows)
- [Write paths](#write-paths)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Host options](#host-options)
- [Replays](#replays)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Rules the bundle follows

- **One transaction per write unit**, on the bundle's own runner `nowo_instance_settings.transaction_runner`
  (`InstanceSettingsTransactionRunner`, built on nowo-tech/transaction-contract and nowo-tech/transaction-doctrine
  ^1.5), on the bundle's **private** entity manager. Inside a transaction of yours a unit is a savepoint of it.
- **Your entity manager is never flushed, cleared, closed or reset.** After any failure the runner rolls back to the
  entry level (and closes the connection only when that rollback fails) and clears only the private manager.
- **Side effects after the commit:** `SettingsChangedEvent`, the audit lines and the image files of the filesystem
  store follow the outermost commit and are dropped with a rollback.
- **Exact keys:** `section` and `name` compare byte-exactly (binary NO PAD collation on MySQL / MariaDB, `===`
  re-checks on every read).
- **FrankenPHP worker mode with kernel reset off:** every service is stateless per request; the commit ledger is a
  monotonic counter of the process, read by difference only.

## Write paths

| Write path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| Section save (form, embed, `InstanceSettings::save()` / `saveSince()`), raw row writes (`SettingRowWriterInterface`) | One unit of the runner (`DoctrineSettingValueRepository::transactional()`) | Section write lock (`SectionLock`: sentinel row, `INSERT … ON CONFLICT / ON DUPLICATE KEY` + `SELECT … FOR UPDATE`), sections locked in name order, locking re-read of the rows | Lock conflict: 409 `CONFLICT_001`; stale form: 409 `SETTINGS_CHANGED` with errors on the fields |
| Section form snapshot (`_base` token) | Same unit | Optimistic: per-field HMAC fingerprints compared under the section lock | 409 `SETTINGS_CHANGED`, nothing saved |
| Import (`/portability/import`, `nowo:instance-settings:import`) | One unit, all or nothing | Section locks of every section it touches, in name order | 409 `CONFLICT_001` |
| Seeds (`nowo:instance-settings:seed`, `seed-from-fallbacks`, `FallbackSeeder`, `SeedFileSeeder`) | One unit, replayed up to 3 times when outermost (`runRetrying()`) | Section locks; insert-only, unique key + catch | Console: the transient failure after the last attempt |
| Image files (`DoctrineSettingFileStore`, filesystem store) | Inside the save's unit; files written after the commit | Section lock, then the file rows | 409 `CONFLICT_001` |
| Secret expiry dates (`DoctrineSecretExpiryStore`) | Inside the save's unit | `FOR UPDATE` re-read under the section lock; first insert: unique key + catch in its own savepoint, then one conditional `UPDATE` | 409 `CONFLICT_001` |
| Expiry thresholds (`nowo:instance-settings:check-expiring-secrets`) | One conditional `UPDATE` per claim | Conditional claim (`notified_threshold IS NULL OR > ?`) | Console: the failure |
| Expiry reminders (`DoctrineExpiryNoticeStore`, 1.21) | One insert per reminder, in its own savepoint inside a caller's transaction | Unique `notice_key` claim, rollback to the savepoint on any error | Console: the failure; a duplicate is "already sent" |
| Section test markers and rate slots (`/sections/{section}/test`) | One unit per marker write | Unique key + catch (insert), conditional `UPDATE` on the read time | Busy: 409 `busy` (domain); lock conflict: 409 `CONFLICT_001`, or `CONFLICT_002` after the marker committed |
| Runtime settings (`runtime_setting.enabled`, 1.20) | One unit; refuses to run inside your transaction | Section lock; version row ensured with `INSERT … ON CONFLICT / ON DUPLICATE KEY`, then updated | 409 `CONFLICT_001` |
| Purges (`purge-files`, `purge-expiry-notices`, runtime `purgeExpired()`) | One statement, or one unit per section | Section locks in name order before the conditional `DELETE` | Console: the failure |

## Conflict answers and codes

On the routes of the bundle (`StorageFailureListener`, controllers of the bundle only; a host route that embeds a
section keeps your own error handling):

| Code | When | Status | `Retry-After` | Client should |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | Deadlock, lock wait timeout or serialization failure (a `TransientStorageFailure` of the runner, or a DBAL error anywhere in the chain) and nothing of the request was committed | 409 (`conflict_response.status`) | Yes: the runner's hint, else `conflict_response.retry_after`, else 1 s | Retry |
| `CONFLICT_002` | The same, after an earlier write unit of the same request committed (the runner's `CommitLedger` moved since the request started), or a `ConcurrencyConflict` that committed, or a lost savepoint with no deadlock behind it (outcome unknown) | 409 (`conflict_response.status`) | No | Reload, do not repeat blindly |
| `SETTINGS_CHANGED` | `SettingsConflictException`: another editor changed the same fields since the page was opened (domain conflict, not a lock conflict) | 409 | No | Reload the form |

- JSON answers (secret reveal, section test, `Accept: application/json`): `{"error": "conflict", "code": "…"}`
  (`"error": "unavailable"` when the status is 503), `Cache-Control: no-store, private`.
- Pages: an `HttpException` with a fixed, client-safe message, rendered by your error page.
- Every answer carries the code in the `X-Conflict-Code` header.
- The codes are nowo-tech/transaction-doctrine's `Nowo\TransactionDoctrine\ConflictCode` values; `SETTINGS_CHANGED` is
  `StorageFailureListener::SETTINGS_CHANGED`.
- No SQL, parameter or secret reaches the client; the storage error stays the previous exception for your logs.

## What is not a conflict

- **Unique constraint violations** are never mapped. The expected "only one" races are handled where they happen: two
  first saves of a field and an import racing a save are serialised by the section lock; seeds, expiry dates, reminders
  and section test markers catch the violation and answer the domain result ("already there", "already sent", "busy").
  A unique violation that still reaches the kernel is a bug: it is a 500.
- **Lost connections** are never mapped (the outcome of the write is unknown and nothing tells it was a lock).
- Validation errors stay 422 on the form, a refused secret reveal keeps its own answers.

## Host options

```yaml
nowo_instance_settings:
    conflict_response:
        status: null        # 409 (default) or 503
        retry_after: null   # seconds for CONFLICT_001 without a hint
```

- Unset (null), the bundle follows `nowo_transaction_doctrine.conflict_response` when nowo-tech/transaction-doctrine's
  Symfony bundle is registered, so the whole application answers the same; otherwise 409 with `Retry-After: 1`.
- `status: 503` keeps the 1.19–1.21 status; the codes and the `Retry-After` rule stay the same.
- Database settings (READ COMMITTED, `innodb_lock_wait_timeout` / `lock_timeout`, DBAL 3 savepoints):
  [HIGH-LOAD.md](HIGH-LOAD.md#database-settings).

## Replays

- HTTP writes make **one attempt**: the client retries after a `CONFLICT_001`.
- Console seeds replay a transient failure up to three times with full-jitter backoff, **only when outermost**;
  inside your transaction the failure goes to you (the storage may have rolled back your work too).
- A replayed unit loads what it changes again and holds its side effects until the outermost commit, so a replay never
  duplicates an event, a log line or a file.
- The bundle has no Messenger handler of its own. When you call the store from a handler, each `save()` is one unit: a
  replay of the message saves the same values again (idempotent for a save; an import is all or nothing).

## Tests and CI

- `tests/Database` plays two requests on two connections to a real database (`IS_TEST_DATABASE_URL`): lost updates,
  first-insert races, form conflicts, rotated secrets and dates, the stale memo, an import and a raw row write racing a
  save, the 409 `CONFLICT_001` answer with `Retry-After` (the stored data unchanged, the connection usable afterwards),
  the 503 host option, and the `CONFLICT_002` answer without `Retry-After` when the first unit of a request committed
  and the second one met a lock.
- `tests/Unit/Transaction/StorageFailureListenerTest.php` maps every case: `CONFLICT_001`, `CONFLICT_002` (ledger,
  `ConcurrencyConflict::$committed`, lost savepoint), `SETTINGS_CHANGED`, 503, and unique violations / lost connections
  left alone.
- The CI job **Load readiness** runs `tests/Database` on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, each with DBAL 4
  and DBAL 3; the six jobs are required checks of `main`.

```bash
IS_TEST_DATABASE_URL='mysql://root@127.0.0.1:3306/is_test?serverVersion=8.4.0&charset=utf8mb4' \
  vendor/bin/phpunit --no-coverage --fail-on-skipped tests/Database
```

## Residual risks

- **Commit tracking covers the bundle's runner only.** `CONFLICT_002` is answered when a unit of the bundle's runner
  committed earlier in the request. A write outside the runner on the same request (a host listener writing in
  autocommit before the bundle's controller fails) is not counted; with nowo-tech/transaction-doctrine's commit
  detection (`WriteUnit` / `#[AsWriteUnit]`) the host's own units answer through that bundle's responder.
- **Under REPEATABLE READ** (MySQL default) the locking re-reads take next-key locks: saves of unrelated sections can
  queue behind each other. READ COMMITTED is recommended ([HIGH-LOAD.md](HIGH-LOAD.md#database-settings)).
- **Lock waits are bounded by the database**, not by the bundle: set `innodb_lock_wait_timeout` / `lock_timeout` to a
  few seconds for HTTP.
- **Snapshot tokens without DoctrineEncrypt** fingerprint a secret by row id and `updated_at` (one-second precision): a
  rotation by another editor within the same second as the page load is not detected (audit QQ-D-L-1).
- **Runtime settings resolved inside a write transaction** can cache a rolled-back value until `version_ttl` (audit
  QZ-L-4): resolve them outside the transactions that write settings.
- **Expiry reminders are at most once:** a process killed between the claim and the delivery loses that one reminder.
