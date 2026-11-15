# High load

Since 1.19.0 (Beacon spec 239) the settings store is built for many concurrent requests on long-running workers:
FrankenPHP worker mode with the kernel **not** reset between requests, RoadRunner, Messenger consumers.

## Table of contents

- [One unit of work per write](#one-unit-of-work-per-write)
- [Your entity manager is yours](#your-entity-manager-is-yours)
- [One section, one writer at a time](#one-section-one-writer-at-a-time)
- [Section forms keep what another editor saved](#section-forms-keep-what-another-editor-saved)
- [Side effects follow the commit](#side-effects-follow-the-commit)
- [The memo never serves what was not committed](#the-memo-never-serves-what-was-not-committed)
- [Transient failures: 503, conflicts: 409](#transient-failures-503-conflicts-409)
- [Races that no longer happen](#races-that-no-longer-happen)
- [Exact keys](#exact-keys)
- [Database settings](#database-settings)
- [Tests](#tests)

## One unit of work per write

Every write of the store runs on the bundle's own runner, `nowo_instance_settings.transaction_runner`
(`Nowo\InstanceSettingsBundle\Transaction\InstanceSettingsTransactionRunner`, built on nowo-tech/transaction-doctrine and
implementing nowo-tech/transaction-contract's `TransactionRunnerInterface`): a save, an import, a seed, a raw row write
(`SettingRowWriterInterface`), an image upload, a section test marker.

- One transaction per unit of work (a savepoint inside a transaction your code opened), on the bundle's **private**
  entity manager, flushed inside it and committed by the runner.
- After any failure no transaction of the bundle stays open: the levels it opened are rolled back, and the connection is
  closed only when that rollback fails (after a MySQL / MariaDB deadlock inside a nested unit the server dropped the
  savepoints, so `ROLLBACK TO SAVEPOINT` fails with 1305). The worker's next request never inherits a nested connection.
- After any failure nothing of the unit stays pending in the private manager: no persisted row, no change, no scheduled
  removal (audit QM-M1). A manager closed by a failed flush is created again on its next use.
- A transient failure (deadlock, lock wait timeout, serialization failure, lost savepoint) is a
  `Nowo\TransactionContract\TransientStorageFailure` with a Retry-After hint; anything else is rethrown unchanged.

HTTP writes make one attempt: a transient failure answers 503 and the client retries. `runRetrying()` replays a transient
failure (three attempts, full-jitter backoff, waited on the clock) **only when the unit is the outermost transaction**:
the console seeds use it (`nowo:instance-settings:seed`, `nowo:instance-settings:seed-from-fallbacks`, and
`FallbackSeeder` / `SeedFileSeeder` called from your own code). Inside a transaction of yours it is never replayed: the
storage may have rolled back your work as well, so the failure reaches you.

## Your entity manager is yours

The store never runs its writes through a runner your application binds to `TransactionRunnerInterface`
(DoctrineDeadlockRetryBundle's adapter, your own): such a runner drives your manager, would commit your pending work
with a settings write and reset your manager after a settings failure. The bundle's runner is injected by its class.

Since 1.11 the store reads and writes through its own private entity manager on your connection, so:

- a save never flushes, clears, closes or resets your entity manager, and never detaches one of your entities;
- a save never commits a change you left pending in your manager;
- a failed save leaves your pending changes pending and your entities managed (audit QN-M1 does not apply: no unit of
  the bundle flushes your manager).

The connection is shared: a save inside a transaction of yours is a savepoint of it, and is committed or rolled back
with it.

## One section, one writer at a time

Every writer of a section takes the section's **write lock** inside its unit of work, then reads the section's rows,
stored secrets and expiry dates again with a locking read (`SELECT … FOR UPDATE`), so it decides on what the writer
before it committed, never on an older snapshot (also inside a REPEATABLE READ transaction of yours that read the
section earlier; the expiry dates since 1.19.1, both when a save compares them and when the store decides to update or
delete a date, e.g. the date of a secret being rotated):

- the lock is a sentinel row of the settings table under the reserved section `_section_lock` (row name = the section
  name), created and locked in one statement: `INSERT … ON DUPLICATE KEY UPDATE id = id` on MySQL / MariaDB,
  `INSERT … ON CONFLICT DO NOTHING` then `SELECT … FOR UPDATE` on PostgreSQL, `INSERT … ON CONFLICT DO NOTHING` on
  SQLite (a writing transaction locks the database). No gap lock is taken first, so two first writers of a section queue
  instead of deadlocking;
- it is a row lock of the transaction: released at commit or rollback, safe behind a transaction-pooling proxy
  (PgBouncer), never held by a worker across requests (audit QG-M2);
- lock order: sections in ascending name order (an import, a seed and a raw row write lock every section they touch
  first, in that order), then the section's expiry dates and image files.

The sentinel rows are never read as settings, exported or seeded, like the section test rows of 1.18. A repository of
your own (`SettingValueRepositoryInterface`) keeps the 1.18 behaviour unless it implements
`SectionLockingRepositoryInterface`.

## Section forms keep what another editor saved

The section form carries the section as its editor opened it, in the hidden `_base` field: one fingerprint per field and
per expiry date (HMAC-SHA-256 keyed with `%kernel.secret%`, 16 hex characters; never a value). A secret is
fingerprinted from its **stored (encrypted) form**, which changes on every write, never from its plaintext: the token
cannot confirm a guessed secret, even to an editor without the reveal permission who knows `kernel.secret` (audit
QQ-L-1); under the section lock it is read with a locking read, so a secret rotated by another editor is seen also
inside a REPEATABLE READ transaction of yours (QQ-D-M-1). Without a key (`InstanceSettings` built with `snapshotKey: ''`)
no token is rendered. **Known issue:** without DoctrineEncrypt (or with a repository of your own that does not implement
`StoredSecretStateInterface`) a secret is fingerprinted by its row id and `updated_at`, which the database keeps to the
second: a rotation by another editor within the same second as the page load is not detected (the 1.18 behaviour for
that one second; audit QQ-D-L-1). `updated_at` is taken once the section lock is held, so it follows the commit order. Under the section's write
lock each submitted field is compared with that snapshot and with the stored row:

| Since the page was opened, the field was… | …and this editor | Result |
| --- | --- | --- |
| not changed by anyone else | anything | saved, as before |
| changed by another editor | left it as shown | **the other editor's value stays** (1.18 reverted it) |
| changed by another editor | set it to the same value | nothing to do |
| changed by another editor | changed it too, or acted on a secret, an image or an expiry date replaced meanwhile | **409**, an error on each such field, nothing saved |
| an expiry date, whose secret another editor replaced | typed a date (for the secret shown) | **409** (the date never lands on the new secret) |
| an expiry date cleared or changed by another editor (a rotation) | left it as shown | the other editor's state stays |

A host template that does not render `_base` (or a host route that posts without it) saves as before 1.19, with the
section lock. In PHP the same merge is `SnapshotSettingsWriterInterface::snapshot()` / `saveSince()`
(implemented by `InstanceSettings`). `save()` itself is unchanged: the values you pass are the values you want.

## Side effects follow the commit

- `SettingsChangedEvent` is dispatched after the outermost commit of the bundle's connection, outside the driver commit,
  and dropped when the transaction or the savepoint that held it rolls back (since 1.10 / 1.11.1).
- 1.19: the audit lines `Instance settings saved.` (saves, imports, seeds) and `Instance configuration imported.` follow
  the same rule: they are never written for a write your transaction then rolled back. This relies on the bundle's own
  `AfterCommitEventDispatcher` service: if you decorate it, the decorator must forward `afterCommit()`, or the lines are
  written at once as before 1.19 (events are not affected: they go through `dispatch()`).
- A replayed unit (`runRetrying()`) dispatches and logs once: a rolled-back attempt held nothing.
- A listener that fails after the commit is logged (class names only) and does not stop the other events.
- Image files of the filesystem store are written after the outermost commit and discarded on rollback (since 1.7).
- The secret reveal line and `SecretRevealedEvent` are written by a read: no transaction is involved. A refusal line is
  rate limited (1.15.1).
- The expiry reminders (`nowo:instance-settings:check-expiring-secrets`) claim a threshold with one conditional UPDATE
  before announcing it and give it back when a listener fails, so two runs never announce the same threshold twice. A
  process killed between the claim and the dispatch loses that one reminder (at most once).

## The memo never serves what was not committed

`InstanceSettings` reads every row once per request (or per console command / Messenger message) and keeps them:

- after every save, failed or not, the memo is dropped (1.18.1 kept the in-memory changes of a save that failed);
- 1.19: when a transaction or a savepoint of the bundle's connection rolls back, the memo is dropped too, so a value
  read inside a rolled-back transaction is never served afterwards (in a worker with kernel reset off this lasted until
  the end of the command or message);
- a new main request always reads again, and `WorkerBoundaryResetListener` clears the memo at every request, command and
  message boundary even without `kernel.reset`.

A value another process saves while a request is running is seen by the next request (a request reads one consistent
view of the settings).

## Transient failures: 503, conflicts: 409

On the routes of the bundle (`StorageFailureListener`, only for the bundle's controllers):

- a `TransientStorageFailure` or a DBAL `RetryableException` anywhere in the chain of previous exceptions:
  **503 Service Unavailable** with `Retry-After` (the runner's hint, else 1 s);
- a unique key violation or a `SettingsConflictException`: **409 Conflict**.

The reveal and test endpoints answer `{"error": "unavailable" | "conflict"}` (never cacheable); the pages get a
`ServiceUnavailableHttpException` / `ConflictHttpException` with a fixed message, rendered by your error page. The
storage error stays the previous exception, for your logs: no SQL, no parameter and no secret reaches the client. A host
route that embeds a section keeps your own error handling (a form conflict is answered by the embed itself: 409 with the
errors on the form).

## Races that no longer happen

| | Race (1.18.1) | Since 1.19 |
| --- | --- | --- |
| R1 | A save read the section, another save changed a field, the first save judged that field "unchanged" from its stale read and lost it | the section is read again under its lock |
| R2 | Two first saves of one field: the second failed with a unique key violation (500) | serialized by the section lock |
| R3 | A section form reverted a field another editor saved after the page was loaded | the field is kept; both changed: 409 |
| R4 | A secret stored by another editor between this request's checks and its write was overwritten (or 500) | 409 |
| R5 | An expiry date typed for the secret shown was put on the secret another editor rotated meanwhile | 409 |
| R6 | A save that failed left its values in the memo for the rest of the request / worker unit | memo dropped |
| R7 | A deadlock or lock wait on a bundle route answered 500 | 503 + Retry-After |
| R8 | An import and a concurrent first save of one of its fields: unique key violation | section locks |
| R9 | A raw row write and a concurrent first save: unique key violation | section locks |
| R10 | A value read inside a rolled-back transaction was served from the memo | memo dropped on rollback |
| R11 | The audit line of a save rolled back by your transaction was written | written after the commit only |

Already safe in 1.18.1 and kept: seeds are insert-only (re-checked under the lock now), the expiry date store resolves
two first dates (1.18.1), the section test markers and rate slots are unique-key claims (1.18), the reminder thresholds
are conditional claims (1.17).

## Exact keys

The `section` and `name` columns of the settings, expiry and file tables are keys. New schemas give them the binary NO
PAD collation on MySQL / MariaDB (`utf8mb4_0900_bin`, MariaDB `utf8mb4_nopad_bin`, MySQL 5.7 `utf8mb4_bin` with a logged
warning); PostgreSQL and SQLite compare exactly. Every read of the settings, expiry date, file and section test rows
re-checks the section (and field) name with `===`, and no read applies a LIMIT before that check. Existing tables keep their collation until the optional `ALTER TABLE` of
[UPGRADING](UPGRADING.md#optional-exact-collation-of-the-key-columns).

## Database settings

- MySQL / MariaDB: InnoDB, **READ COMMITTED recommended**. Under REPEATABLE READ (the MySQL default) the locking re-reads
  take next-key (gap) locks: a save of a section waits for the writer of the section that follows it alphabetically in
  the `(section, name)` key until that writer commits. No deadlock was reproduced, but under load the saves of
  unrelated sections queue behind each other (audit QQ-I-1). READ COMMITTED takes no gap locks.
  `innodb_lock_wait_timeout` bounds how long a save waits for the section lock (the default 50 s is long for HTTP;
  5–10 s is a better fit, the client gets a 503).
- PostgreSQL: READ COMMITTED (the default). Set `lock_timeout` for the same reason.
- DBAL 3 (3.8.2 or later) and DBAL 4 are supported. On DBAL 3 **enable savepoints** (`doctrine.dbal.use_savepoints:
  true`) when the store is called inside your transactions. Without them a nested unit is not a savepoint: a save
  refused inside your transaction (409 conflict, 422 validation, 503 transient failure) marks your transaction
  rollback-only, and your commit then fails, so your own work in that transaction is lost too. With savepoints only the
  refused save is rolled back. DBAL 4 always nests with savepoints.

## Tests

`tests/Database` plays two requests in one process on a real database (a peer kernel on its own connection, run between
a page load and its submit, or right before the first write of the other request). Every test there fails on 1.18.1.
The CI job "Load readiness" runs it on MySQL 8.4 and PostgreSQL 16, with DBAL 4 and DBAL 3:

```bash
IS_TEST_DATABASE_URL='mysql://root@127.0.0.1:3306/is_test?serverVersion=8.4.0&charset=utf8mb4' \
  vendor/bin/phpunit --no-coverage --fail-on-skipped tests/Database
```
