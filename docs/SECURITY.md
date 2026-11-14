# Security

Bundle-specific security analysis. The short GitHub reporting policy lives in [`.github/SECURITY.md`](../.github/SECURITY.md).

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Image fields threat model (1.4)](#image-fields-threat-model-14)
- [Brand bridge threat model (1.5)](#brand-bridge-threat-model-15)
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
reader, sealing, CLI commands), the image fields (upload pipeline, file store, file route, envelope v2 `files`, purge
command, 1.4), the brand bridge (sections, contract provider, asset reader, UiKit provider pass, 1.5), the Twig
functions and the configuration tree. It does **not** cover the host
firewall, the sections and portability providers contributed by the host or other bundles, DoctrineEncryptBundle's
own cryptography and key storage, or what consumers do with a setting value.

## Attack surface

| Input | Where | Notes |
| --- | --- | --- |
| Admin HTTP `GET` / `POST` (session) | `web_ui.path_prefix` (only when the host imports the `nowo_instance_settings` route loader) | Role / custom checker per area, Symfony Forms with CSRF. |
| Uploaded JSON file | `POST …/portability/import` | Multipart Form (`FileType`, `File` constraint), size-capped reader, schema / version / key validation, all-or-nothing transaction. |
| CLI file path | `nowo:instance-settings:import <file>` | Operator input; same reader and validation. |
| Image upload (multipart) | section form under `web_ui.path_prefix` | 1.4. Section area role + CSRF; content sniffed, limited and re-encoded with GD. See [Image fields threat model](#image-fields-threat-model-14). |
| Anonymous HTTP `GET` / `HEAD` | `files.path_prefix` (default `/_settings-file`), only when a section declares an image field | 1.4. Public image fields served to anyone; private ones need the section area; same 404 for every miss. |
| Imported image files (base64) | envelope v2 `files` (admin import, CLI) | 1.4. Strict decode, caps, hash check, whole upload pipeline, all-or-nothing. |
| Admin HTTP `POST` reveal of one secret | `<web_ui.path_prefix>/sections/{section}/secrets/{field}/reveal`, only with `web_ui.enabled` | 1.15. Opt-in per field (`revealable: true`), `security.reveal_roles` **and** the section area, this section's CSRF token, byte-exact field name, optional rate limiter, audited; answer `private, no-store`. |
| Section definitions / providers | host and bundles (services) | Trusted code; definitions validated at first use. |
| Configuration | `nowo_instance_settings.*` | Validated at compile time; security switches must be literal booleans. |
| Stored values | database | Plain values are JSON; secrets are encrypted at rest. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Unauthorized access to settings / secrets** | UI private by default (`ROLE_ADMIN`); empty role lists are refused at compile time and deny at runtime (pattern P1); per-area roles (`security.area_roles`) for sensitive sections and for import / export — when sections are restricted, `_portability` must be set explicitly (compile error otherwise), and the web export / import only covers the sections the user may open (an import touching another section is refused as a whole, from its raw keys, before any value is validated or unsealed; the CLI keeps full operator access); a listener guards every request under the prefix **and** every bundle controller wherever it is mounted (other prefix, sub-request); every action re-checks its area. The token is trusted only when a firewall matched the main request (no stale worker token). Anonymous users inside a firewall get the entry point (401 / login). |
| **CSRF** on saves, export, import | Every mutation is a Symfony Form with CSRF (`nowo_instance_settings_section_<name>`, `…_export`, `…_import`); the container refuses to compile the UI without form CSRF or a token manager (pattern P2). Export is a POST (it can contain sealed secrets). |
| **Secret disclosure in HTML** | The section form never receives a stored secret (only "stored: yes / no"); the secret input is a password field rendered empty; secrets are refused by `get()`, `getSection()` and the Twig function. Contract and functional tests assert a stored secret never appears in the page. |
| **Secret disclosure through the reveal endpoint** (1.15) | Off unless a host declares a field `revealable: true` (default false) **and** the user holds `security.reveal_roles` (`ROLE_DEVELOPER` by default, never a fallback to `access_roles`; `area_roles._reveal` is refused so there is one place to look; `[]` refuses every reveal) **and** the section's own area. The roles are checked on the endpoint, on every call, before the CSRF token and before anything is read: a hidden-until-clicked value is never rendered into the page, so the plaintext is not in the page source whatever the role check. One call answers the single named field; the section and field names from the client are matched byte for byte against the declared names before any lookup (REQ-SEC-006), so a look-alike name cannot reach another field, and a field without the opt-in is refused even when asked for directly. The answer is `private, no-store`, `nosniff`, `no-referrer`, `noindex`, and the value is never in a URL. `security.reveal_rate_limiter` consumes one token per reveal (keyed by the salted hash of the actor, or by the client IP for a caller the host cannot name — which needs `framework.trusted_proxies` behind a proxy, else every unnamed caller shares the proxy's bucket) against bulk harvesting, and it is **required**: a field declaring `revealable: true` without a configured limiter makes the section definitions fail to load (router warm-up / `cache:warmup`, else the first request), so the feature cannot be deployed without a brake (owner decision of 2026-11-13); `security.reveal_roles: []` switches every reveal off and then needs no limiter. Granularity is **per field only**: a user holding the reveal roles may reveal every `revealable: true` field of every section they can open — there is no per-section reveal role (owner decision of 2026-11-13: the per-field opt-in is finer and covers the real case), so keep the opt-in off for anything that must stay unreadable. Every reveal and every refusal is audited by name (`SecretRevealedEvent`, log line), never by value. Residual risk: a revealed secret is plaintext in a browser (screenshots, shoulder surfing, extensions); the script drops it on Hide, after a minute and when the tab is hidden, which mitigates but does not remove it — hence the per-field opt-in. | `SecretRevealTest`, `SecretRevealerTest` |
| **Secret disclosure at rest** | `#[Encrypted]` column through DoctrineEncryptBundle (default profile); the container refuses to compile without DoctrineEncrypt (secrets would be stored in clear). Every stored secret carries a terminator, so its plaintext never ends with DoctrineEncrypt's `<ENC>` marker (which would skip encryption) and is never `''` / `'0'`; a loaded value without the terminator (not decrypted: wrong or lost key) counts as no secret instead of handing ciphertext to consumers. |
| **Secret disclosure in exports** | Secrets are exported only on request (`include_secrets` / `--include-secrets`, gated by `portability.sealed_secrets` and the `_portability` area) and only **sealed** with the instance key (`enc:v2:`), each value bound to its schema and `<section>.<field>` (a sealed value moved to another field or application is refused: `secret_context_mismatch`); an import from another instance fails closed (`failed_unseal_secret`). Plain fields cannot carry secrets: secret fields are refused under `settings`, and credential-shaped keys are refused in provider data on import **and** export (keys normalised — camelCase, `-`, `.` — and matched on words such as password, passwd, passphrase, secret, token, bearer, credential, apikey, privatekey, signature, or parts such as pwd, pass, key, dsn, auth). CLI export files are written in a private 0700 directory, set to `0600` and renamed into place (no world-readable window, symbolic links refused, existing files only with `--force`); export responses are `private, no-store`. |
| **Malicious import** (type confusion, mass assignment, weakening flags) | Strict envelope (known top-level keys, schema, version), known sections / fields only, sections the user may not open refused, `notPortable()` fields refused, every value normalized and validated with the field type and constraints (non-finite floats such as `1e999` refused), provider `validate()` before anything is written, single transaction; a refused file changes nothing and the error names the key, never the value. Hosts mark security-sensitive toggles `notPortable()`. |
| **Oversized / malformed upload** | `File` constraint (`max_upload_bytes`, JSON / text MIME) plus a reader that stops at `max + 1` bytes, `json_decode` depth 64, root must be an object. |
| **Injection** | Doctrine ORM with bound parameters only; no SQL / DQL concatenation with input; JSON responses encoded with `JSON_THROW_ON_ERROR`. |
| **Accidental route exposure** | The controller is not autoconfigured, so `routing.controllers` never loads it; routes come only from the `nowo_instance_settings` loader import (REQ-SF-004 rule 7, integration test). |
| **Demo configuration in production** | `security.allow_unauthenticated: true` is refused unless `kernel.debug` is true. The demo publishes its port on `127.0.0.1` only. |
| **`security: false` firewall on the prefix** | No token is trusted; the bundle answers a plain 403 (a Security exception is used only inside an enabled firewall, where it becomes the entry point). |
| **Cross-request state leaks (FrankenPHP worker)** | The settings memo is bound to the main request (`WeakReference`) and cleared by `kernel.reset` and after saves; other services are stateless. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). |
| **XSS** | Twig auto-escaping; labels come from translation catalogues; no `|raw`. The bundle's own scripts are served files loaded with the request `csp_nonce`, with no inline handler and no `innerHTML`. |
| **A host-side XSS on the admin layout, with the reveal enabled (1.15)** | **This is an escalation, and it is not mitigated here.** Before 1.15 a script running on an admin page could tamper with settings but could **not** obtain any stored secret — the form never carried one. With `revealable: true` fields, a same-origin script can refetch every section page the victim may open, read each page's reveal URLs and CSRF token, and harvest **every revealable field of every reachable section** under the victim's roles. Token placement, shorter-lived tokens and `Origin` checks are therefore not mitigations: the script is the page. What exists is **detection**, which the one-field-per-call shape makes possible: alert on more than N reveals for one actor in a minute, or more than one distinct `key` per session, and on bursts of reveal refusals; and keep `security.reveal_rate_limiter` tight, which turns a silent harvest into `rate_limited` warnings and caps what one session can take. The structural fix is step-up re-authentication before each reveal (planned for 1.16+, not in 1.15). The real control today is the per-field opt-in: a field that is not revealable cannot be harvested this way. | `SecretRevealTest`, `RevealProbeTest` |
| **Server-side logging of the reveal answer** (our own HttpLogBundle, a reverse proxy or WAF with body capture, an APM agent) | The answer carries the plaintext in a JSON body; `no-store` instructs clients and stops nothing server-side. Such a log stores the secret in clear, for its own retention, readable by a broader group than `reveal_roles` and no longer attributable to the actor. The bundle cannot prevent it: hosts must exclude the reveal path and redact the `value` key — HttpLogBundle captures JSON response bodies by default and its default redaction paths do not cover `value` (audit F1). Documented with the configuration in [CONFIGURATION.md](CONFIGURATION.md#revealing-a-stored-secret-115) and as a required step in [UPGRADING.md](UPGRADING.md). | docs; HttpLogBundle's own defaults are being changed separately |
| **A stored secret that is not UTF-8 text** | Refused with a clean 409 `{"error": "not_text"}` carrying the same `no-store` / `nosniff` / `no-referrer` headers, never a mangled or substituted value, and the audit trail records the refusal instead of a reveal the caller never received (audit F2). | `RevealProbeTest::testANonTextSecretIsRefusedCleanlyAndAuditedAsRefused`, `SecretRevealerTest` |
| **The wrong section's secret under a row's label** (a host page embedding two sections that declare the same field name) | Each revealable row carries its section name and is bound to the Reveal root of that section (its enclosing root, else the root whose `data-section` matches), so a row never uses another section's URL map or token (audit F3). | `RevealProbeTest::testSameFieldNameRevealableInOneSectionOnly` |
| **The clipboard after Copy** | The operating system clipboard is a store the page does not own: Windows Clipboard History and macOS Universal Clipboard keep and sync it. Hide asks for it to be emptied (best effort; the browser may refuse and nothing reaches what the system already synced), and the documentation says so instead of claiming the value is never stored (audit F4). | docs; `nowo-instance-settings-reveal.js` |

## Image fields threat model (1.4)

Image fields (spec 220 slice S2) are opt-in (`files.enabled: true`; off by default, so hosts without image fields get
neither the file table nor the file route, store or purge command). They add three new surfaces: **binary uploads** through the section form, a new
**anonymous endpoint** (the file route) and **binary data in imports**. Everything below is enforced in code and
covered by tests (`tests/Unit/File/*`, `tests/Unit/Settings/ImageSettingsTest.php`,
`tests/Unit/Portability/ImagePortabilityTest.php`, `tests/Integration/Functional/ImageFieldsTest.php`,
`tests/Integration/File/DoctrineSettingFileStoreTest.php`).

### Assets and trust boundaries

| Asset | Who may change it | Who may read it |
| --- | --- | --- |
| Image of a `public: true` field (brand assets) | users of the section's area (form, CSRF), operators (CLI import), application code | anyone, through the file route |
| Image of a private field | same | users of the section's area only (checked by the file controller) |
| Replaced / removed images (orphans) | nobody (kept read-only until purged) | same as the field, until `files.orphan_retention` expires |

### Threats and mitigations

| # | Threat | Mitigation | Tests |
| --- | --- | --- | --- |
| IF1 | **Polyglot / content-type confusion** (PNG + ZIP, JPEG + HTML trailer, SVG or HTML renamed `.png`, GIF renamed) | The type comes only from the content: magic bytes **and** `getimagesizefromstring()` must agree; the client name and MIME type are never read. Every image is decoded and re-encoded with GD, so appended payloads and metadata never reach storage. Served with the stored type, `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`. A URL extension that does not match the stored type answers 404. | `ImageProcessorTest::testRefusesAnythingThatIsNotAnAcceptedRaster`, `testPolyglotTrailersAreDroppedByTheReEncode`, `testWrongDeclaredExtensionOrMimeTypeIsIrrelevant`; `ImageFieldsTest::testUploadServeReplaceAndRemove` |
| IF2 | **Stored XSS through SVG** (script, `foreignObject`, `use href`, CSS `url()`) | SVG is not an accepted type in v1 (no sanitiser, decision Q-O4); vectors only as committed config default assets (1.5). Uploads are raster only and re-encoded. Consumers must never inline upload bytes into HTML / SVG / PDF markup. | `ImageProcessorTest` (SVG, SVG behind a BOM); `ImageFieldsTest::testRefusedUploadsCsrfAndRoles` |
| IF3 | **Decompression bomb / memory exhaustion** | Bytes capped before reading whole (`maxBytes`, ≤ 5 MiB; form `File` constraint; `ImageUpload::read()` stops at `max + 1`). Dimensions from the header **before decoding**: 4096 × 4096 px hard cap (the default maximum dimensions), field limits, and a memory budget against the remaining `memory_limit` (4 B/px × 2 decoded copies, + 6 B/px for any JPEG). Variants are made largest first from the previous one (at most two decoded images alive) and never upscaled. | `testDecompressionBombIsRefusedFromTheHeaderBeforeDecoding` (30 000 × 30 000), `testMemoryBudgetRefusesBeforeDecoding`, `testFieldLimits` |
| IF4 | **Animated images / frame bombs** | Animated WebP refused (VP8X animation flag or an `ANIM` / `ANMF` chunk, found by walking the RIFF chunk list); APNG keeps its default frame only (GD decodes one). GIF is not accepted. | `testAnimatedWebpIsRefusedAndApngKeepsOneFrame` |
| IF5 | **Privacy leak through metadata** (EXIF GPS, camera serials, XMP, ICC text) | Re-encode drops every metadata chunk; the EXIF orientation is applied first (read without ext-exif, bounds-checked parser), so the result is upright. | `testExifIsStrippedAndTheJpegTurnedUpright`, `testEveryExifOrientation`, `testOrientationParserIsRobust` |
| IF6 | **Path traversal / file system writes** | No path is ever built from input: files live in the database; the route's section and field are looked up in the registry and the file by `(section, field, hash prefix, variant)` with bound parameters; route requirements allow only `[a-z0-9_]` names, 12 lower-case hex characters, a numeric variant and `png|jpg|webp`. The upload's client name is never stored nor used (the `Content-Disposition` name is `<section>-<field>.<ext>`). | `SettingFileControllerTest::testSame404ForEveryMiss` (traversal, uppercase), `FileValueObjectsTest::testImageUploadReadsWithAHardCap` (`../../etc/passwd.png` name) |
| IF7 | **Unauthorised replacement** (CSRF, missing role, API misuse) | Uploads only through the section form: multipart, CSRF token `nowo_instance_settings_section_<name>`, the section's area role (listener + controller). No separate upload endpoint, no AJAX upload. `save()` accepts only an upload (re-processed), `null` or the field's current reference: a field can never be pointed at another field's image. | `ImageFieldsTest::testRefusedUploadsCsrfAndRoles` (missing CSRF → 422, `ROLE_USER` → 403, nothing stored), `ImageSettingsTest::testRefusedInputsChangeNothing` (foreign reference, path, number) |
| IF8 | **Enumeration / disclosure of private images** | Non-public fields require the section's area in the file controller; unknown section / field / hash / variant / extension and a forbidden private image all answer the **same** empty 404 (`no-store`, `nosniff`). Images are bound to their field (unique `(section, name, sha256, variant)`): one field's URL never serves another field's image, so a public field cannot leak a private one even when the hash is known. URLs carry 48 bits of the hash (not guessable). Private responses are `private, max-age=3600`, `Cross-Origin-Resource-Policy: same-origin`. | `SettingFileControllerTest::testSame404ForEveryMiss`, `testPrivateFieldNeedsTheSectionArea`; `ImageFieldsTest::testRefusedUploadsCsrfAndRoles` |
| IF9 | **Old / unreferenced hashes** | Decision: a hash that is no longer the field's value **keeps answering while its row exists** (orphan retention, default 30 days), so e-mails already sent keep their images and an admin can undo by re-uploading; after `purge-files` it is a 404. The command's "deleted" count is computed before the guarded DELETE, so an image re-referenced in that instant is kept but still counted (output only, audit IO-I9). A row only exists for an image that was saved into that field (rows are written in the same transaction as the setting); a hash that was never this field's answers 404. Private orphans still need the area. | `SettingFileControllerTest::testAnOrphanedImageIsServedUntilPurged`, `ImageFieldsTest::testUploadServeReplaceAndRemove`, `PurgeFilesCommandTest` |
| IF10 | **Cache poisoning / stale caches** | Content-addressed immutable URLs (a new image is a new URL); strong `ETag` = full SHA-256 (+ variant); `If-None-Match` answers 304 before the bytes are read; public responses `immutable`; no `Vary` on user input. | `SettingFileControllerTest::testIfNoneMatchAnswers304WithoutReadingTheBytes`, `testHeadDoesNotReadTheBytes`, `ImageFieldsTest` (304) |
| IF11 | **Import tampering** (malformed base64, wrong hash, wrong type, oversize, smuggled files, files for a forbidden section, v1 file with `files`) | Envelope v2 only; `files` keys must be `<section>.<field>` of a portable image field present in `settings` with the same hash (`unexpected_file` otherwise); forbidden sections refused from the raw keys before any decoding; base64 length bounded **before** decoding, strict decode; per-file `maxBytes` and total `portability.max_file_bytes`; declared hash compared with the decoded bytes; declared type must equal the sniffed type; then the whole upload pipeline; all-or-nothing transaction; reason codes name `files.<section>.<field>`, never content. A reference without its file is refused (`missing_image_file`) unless it is already the current one. | `ImagePortabilityTest::testTamperedFilesAreRefused`, `testFilesForAForbiddenSectionAreRefusedBeforeAnyDecoding`, `testVersionOneFilesImportUnchanged` |
| IF12 | **Disclosure through logs / events** | Log line: field, 12-character hash prefix, type, dimensions, byte size (`logo=3f1c9a0b7e21 image/png 512x512 20480B`); `SettingsChangedEvent::$fileChanges` carries hashes only. Never content, never the client file name. | `ImageSettingsTest::testAuditRecordsHashesNeverContent` |
| IF13 | **Worker-mode leaks (FrankenPHP)** | Processor, store, controller and URL builder are stateless; the store detaches the rows it wrote (no blob in the identity map) and reads metadata with scalar DQL (the blob is read only to answer a 200); the metadata memo of `getFile()` is cleared with the settings memo (`kernel.reset`, new main request, after saves). | `DoctrineSettingFileStoreTest::testStoreLifecycle` (identity map empty), `ImageSettingsTest::testNoBlobIsKeptAcrossResets`; Igor audit |
| IF14 | **Missing GD / weaker decoder** | With `files.enabled: true` the container refuses to compile unless GD has PNG, JPEG and WebP support (`FileSupportPass`); a section declaring an image field also fails when the definitions load without it (a container compiled elsewhere), and without `files.enabled` (fail closed; never stored without re-encode). | `CompilerPassesTest::testFileSupportPassChecksGdOnlyWithTheFlag`, `ImageFieldDefinitionTest::testRegistryKnowsImageFieldsAndFailsClosedWithoutGd`, `FileTableOptInTest::testAnImageFieldWithoutTheFlagFailsClosed` |
| IF15 | **Unexpected new endpoint or schema for hosts without images** | `files.enabled` is off by default: no file entity is mapped (the schema is the one of 1.3), and no file store, file controller or purge command is registered. The file route is added only when a section declares an image field; otherwise the routes are exactly those of 1.3 and exports stay envelope version 1. The route sits outside `web_ui.path_prefix` (overlap refused at compile time) and is not guarded by the admin listener. | `ImageFieldsTest::testHostsWithoutImageFieldsGetNoFileRoute`, `InstanceSettingsRouteLoaderTest`, `NowoInstanceSettingsExtensionTest::testFilePrefixMustBeOutsideTheAdminPrefix`, `testFilesOffRegistersNoStoreRouteControllerOrPurge`, `FileTableOptInTest` |
| IF16 | **Purge deletes a live image** (a purge run racing an admin save, a section not registered during a run, rows restored outside the bundle) | Every `purge-files` run clears the orphan mark of every referenced image before marking the unreferenced ones, so a wrong mark heals on the next run. The delete re-reads the setting rows inside its transaction and skips referenced images; each delete also re-checks the mark, so an image referenced again concurrently is kept. Rows of a section the registry does not know keep their images unless `--include-unknown-sections`. | `ImageFieldsTest::testPurgeNeverDeletesTheLiveLogo`, `DoctrineSettingFileStoreTest::testStoreLifecycle`, `PurgeFilesCommandTest::testStaleMarksHealAndUnknownSectionsKeepTheirImages` |

### Residual risks (image fields)

- **GD / libpng / libjpeg / libwebp vulnerabilities.** Decoding attacker-supplied images runs native code. Only users of
  the section's area (admins) can upload, the header checks bound the work, and the re-encode output is what is
  served; keep the PHP image libraries patched.
- **Rate limiting.** The bundle does not rate-limit the anonymous file route; a 304 costs one indexed metadata query,
  a 200 one blob read (≤ 5 MiB, read whole and sent, not streamed). Public-facing hosts rate-limit `files.path_prefix`
  (or put a CDN in front: URLs are immutable).
- **CDN cache keys.** The route ignores the query string: `?bust=<random>` answers a full 200. A CDN that keys on the
  query string can be bypassed that way, so configure it to ignore the query string for `files.path_prefix`.
- **Orphan retention and withdrawal.** A removed image stays publicly reachable by its exact URL until purged (default
  30 days). Set `files.orphan_retention: 0` and run `purge-files` to withdraw an image at once. Public responses are
  `immutable` for a year, so browsers and CDNs that cached the URL keep it: purge the URL from the CDN as well.
- **Private images need the firewall.** The file controller trusts the user only when a firewall matched the request.
  A firewall that does not cover `files.path_prefix` makes every private image a 404 (fail closed, also for admins).
- **Double pipeline on import.** An imported file is processed once to validate the envelope and once when saved
  (admin or CLI only, bounded by `portability.max_file_bytes`).
- **Lossy JPEG re-encode.** Every JPEG upload or import is re-encoded at quality 90 (generation loss, new hash on each
  import of a JPEG).

## Brand bridge threat model (1.5)

The brand bridge (spec 220 slice S3) is opt-in (`brand.enabled: true`); without it nothing is registered and the admin
pages are byte for byte those of 1.4. It adds no endpoint and no new storage: the brand images are image fields
(threats IF1–IF16 above apply unchanged, the file route is not modified), the texts and colours are plain settings.
Batch audit before the tag (owner decision Q-O5): Appendix IU **Pass-Low**; IU-L1 (bridge honours UiKit `brand: off`), IU-I2 (single brand source guard), IU-I3 (`?v=` on default assets) fixed before 1.5.0; IU-I1 documented; IU-I4, IU-I5 Info.

| # | Threat | Mitigation | Tests |
| --- | --- | --- | --- |
| BB1 | **Markup or script through a brand image** (SVG upload, inlined bytes, `data:` in the admin preview) | Uploads are PNG / JPEG / WebP only and re-encoded (IF1, IF2). The admin preview prints only the stored file's route URL in an auto-escaped `<img src>` (audit IO Q3): no bytes, no `data:`, no base64, no `\|raw`. A pre-save preview is not part of this release (no client script). Uploaded assets are `BrandAssetOrigin::Uploaded`, never inline-safe. | `BrandBridgeTest::testAdminPagesUploadPreviewServeAndRead`, `GroupsPreviewAndSaveFilterTest::testImagePreviewScalesAndNeedsAUrl` |
| BB2 | **Trusted default assets pointing outside the public directory** (traversal, absolute path, symbolic link, URL) | Default asset paths are checked when the container compiles: relative, `[A-Za-z0-9._/-]` segments, no `.` / `..`, a real file whose real path is inside `public_dir`, not a symbolic link, an image extension, a readable raster. The URL is `/` + the checked path. A config error fails the build; the asset reader re-checks the SHA-256 recorded at compile time before returning bytes. | `BrandAssetsTest::testUnsafeOrInvalidPathsAreRefused`, `testSymlinksOutOfThePublicDirectoryAreRefused`, `testReaderRefusesADefaultChangedSinceCompile`, `BrandExtensionTest::testAnUnsafeDefaultAssetFailsTheBuild` |
| BB3 | **Reading another field's image through the asset reader** (forged `BrandAsset`) | Uploads are looked up by the field of the asset's slot in the `brand` section and its full hash; the returned row must carry that hash. Defaults must match the configured file of the slot and its hash. Errors name the slot and a 12-character hash prefix, never a path or content. | `BrandAssetsTest::testReaderReadsUploadsVariantsAndDefaults` |
| BB4 | **Header / manifest / e-mail injection through brand texts** (control characters, bidi overrides, line separators) | The fields refuse them (`Regex` + `Length`), the config defaults too; a stored value written outside the form that the contract refuses falls back to the configured texts. A translated default eyebrow with such characters is dropped before it reaches UiKit (audit IS-I4). Consumers keep auto-escaping. | `BrandSectionsTest::testTextConstraintsRefuseControlCharactersAndLength`, `InstanceBrandProviderTest::testAStoredTextTheContractRefusesFallsBackToTheDefaults`, `testATranslatedEyebrowWithUnsafeCharactersIsDropped`, `BrandExtensionTest::testInvalidBrandConfiguration` |
| BB5 | **CSS injection through colours** | Stored colours are `#rrggbb` only (form constraint, re-checked when read); contract colours are strict hex (non-hex UiKit preset values fall back to the kit's for the contract); UiKit's emitter sanitises again. | `BrandPalettesTest`, `BrandSectionsTest::testInvalidColourIsRefused` |
| BB6 | **Silent switch of UiKit's provider on upgrade** (UiKit's `appearance.brand: auto` reads any `BrandProviderInterface`) | `BrandProviderInterface` is registered only with `brand.enabled: true` (audit IS-I6). UiKit's provider is taken over only when UiKit's appearance is enabled, `provider_is_default` is true and UiKit's brand mode is `auto` (its brand candidate is registered or already targeted; `brand: off` keeps the config provider, audit IU-L1), in either pass order; a host provider in config or a services alias always wins. A host `BrandProviderInterface` next to the bridge fails the build (audit IU-I2). A host decorator of `config_provider` is bypassed under the bridge (documented, IU-I1). | `BrandBridgeTest::testWithoutBrandEnabledUiKitKeepsItsProvider`, `testAHostProviderWins`, `testUiKitBrandOffKeepsTheConfigProvider`, `BrandBridgePassTest` |
| BB7 | **Unauthorised brand change** | The two sections are ordinary sections: area roles (`security.area_roles.brand` / `.appearance`), CSRF per section, imports area-aware. | Existing access and CSRF tests apply (`AdminUiTest`, `ImageFieldsTest::testRefusedUploadsCsrfAndRoles`) |
| BB8 | **Disclosure through notices** | Contrast notices are fixed messages per mode and check: never a stored value. | `BrandSectionsTest::testContrastNoticesNameCodesOnly`, `BrandBridgeTest::testAppearancePageShowsContrastNotices` |
| BB9 | **Availability: brand provider failure** | A store `Exception` is logged and the configured defaults are returned (an error page or manifest never fails); programming errors (`Error`) are not swallowed. | `InstanceBrandProviderTest::testAStoreFailureIsLoggedAndGivesTheDefaults`, `testProgrammingErrorsAreNotSwallowed` |
| BB10 | **Worker-mode leaks** | Provider, palettes, sections, reader and Twig extension are stateless; values come from the request-bound memo of `InstanceSettings`. | Igor audit |
| BB12 | **Error page illustrations (1.13): path traversal, another field's image, an unsafe URL** | `BrandErrorIllustrationProvider` reads only fields derived from the configuration at compile time (`extra_error_<N>` for declared `extra_images.error_<N>`, N = 400 … 599, and the declared base id): never a path from the request or the status code beyond that map. The store confines the read (names checked again, no symbolic link, real path inside `files.directory`, ≤ 5 MiB, sniffed PNG / JPEG / WebP whose type matches the extension). The URL comes from the router (the file route) and is validated again by UiKit's `ErrorIllustration::fromUrl()`; only `brand` images, which are public by design, are referenced. | `BrandErrorIllustrationProviderTest`, `ErrorIllustrationsTest` |
| BB13 | **Error page availability (1.13)** (database or network down while an error page renders) | The provider needs no database and no HTTP: the filesystem store only (`files.store: doctrine` registers nothing and refuses a `base`); one `stat()` + metadata read per lookup; a lookup without valid metadata (fresh checkout, deployment copy) writes it after hashing, so anonymous error pages cannot force a re-hash of up to 5 MiB on every request (audit LS-L1; a read-only directory keeps re-hashing and logs at debug level only). UiKit guards every call (any `Throwable` → its map) and the host sets `illustration_provider_budget_ms`. Readonly service: nothing is kept between requests in worker mode. | `ErrorIllustrationsTest::testUploadedImagesThenUiKitMapWithoutTheDatabase` (table dropped), `testRemovedUploadFallsBackInAKeptAliveKernel` |
| BB11 | **File mapping missing** (flag prepended after this bundle, audit IO-I10) | `brand.enabled` implies the files and is read in this bundle's own prepend; a flag prepended later fails at compile time with a clear message. | `BrandExtensionTest::testAFlagPrependedAfterThisExtensionFailsClearly`, `testPrependMapsTheFilesForTheBrandAndRecordsIt` |

Residual risks: default assets are trusted, committed files (an SVG default may contain script; it is served by the web
server and only printed as `<img src>` by the kit, but a host that inlines it must trust its repository); the brand
images are public by design (do not upload anything confidential to a brand slot); colour contrast is advisory only.
An error page illustration of a file placed in `files.directory` without its setting row (never seeded) points at a
file route URL that answers 404 (the file route serves a filesystem-store file only while its row exists): a broken
image, never another file. While the database is down the file route cannot check the row either, so an existing
image also shows as broken (rather than UiKit's map) until the database is back. A hard link placed inside
`files.directory` to an image elsewhere passes the store's confinement (write access to the directory is required,
which already allows replacing any brand image).

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

- **Expiring secrets (1.17).** The expiry date is not a secret: it is stored in clear in
  `instance_setting_secret_expiry`, shown in the admin form, exported in clear (`secret_expiry`) and logged. The secret
  value never reaches the date store, the read port (`ExpiringSecret`), the threshold event, the command output, the
  warnings or the logs (a failing reminder listener is logged by exception class only, never its message). The date
  row is a plain date input; the reveal endpoint, `reveal_on_toggle` and the Turbo / `pagehide` clearing of revealed
  values are unchanged. Field labels in the warnings are translated and escaped by Twig.

## Logging

- `info` "Instance settings saved." with section, changed **field names** and origin; for image fields also `files`
  (field, 12-character hash prefix, type, dimensions, byte size — or `removed`).
- `info` "Instance settings files purged." with the counts of marked and deleted images.
- `info` "Instance configuration exported." / "… imported." with section names / changed keys and whether secrets
  were included.
- `info` "Instance settings: secret revealed" with `<section>.<field>`, the actor's user identifier, the time and
  whether a secret was stored; `warning` "Instance settings: secret reveal refused" with the same fields and the reason
  (`forbidden`, `not_revealable`, `rate_limited`, `not_text`, `ui_gate`). The PSR-14 `SecretRevealedEvent` carries
  the same names (1.15). Since 1.15.1 (audit N2 / N3) the refusal line names the resolved actor on every reason
  (`ui_gate` included), logs only declared names (anything else is `key: unknown`), and is throttled per caller by
  `security.reveal_rate_limiter` in a bucket of its own: `warning` while the caller has budget, `debug` past it. An
  unauthenticated caller can no longer choose what the line says, and its warnings are capped **per caller**: the
  budget belongs to a user identifier, else a client IP, so a caller rotating IPs gets a fresh budget for each one.
  Pair the per-actor alert with an aggregate one, for example the count of `key: unknown` refusals per minute across
  all callers. Each refused reveal (an unauthenticated `ui_gate` request included) also writes one entry to the
  limiter's storage. Writing the line never decides anything and never throws: if the limiter storage or the logger
  fails, the refusal keeps its 403 / 404 / 409 / 429 and a constant `error` line "Instance settings: secret reveal
  refusal could not be logged" (with the reason only) is written when the logger still works (audit PJ-L1, PJ-L2).
- **The actor is personal data.** `actor` is the raw user identifier the host's user provider returns — often an
  e-mail address. It has been on the reveal line and in `SecretRevealedEvent` since 1.15.0 and, since 1.15.1, is on
  every refusal line too. Cover these lines with your log retention and access rules. The identifier is passed as
  structured context (the `actor` key), never interpolated into the message, so a processor can hash or drop it
  (audit PJ-I3 / PJ-I4).
- Never logged: values, secrets, uploaded file content. Exceptions carry reason codes and keys only.
- `SettingsChangedEvent` carries field names only (plus old / new image hashes in `fileChanges`); hosts that write an
  audit trail record names and hashes, not values.

## Resource limits

- Reads: one query per request for all rows (no N+1); writes: one query per section + one flush.
- Imports: `portability.max_upload_bytes` (default 1 MiB, max 16 MiB), JSON depth 64; image files: per field
  `maxBytes` (≤ 5 MiB) and `portability.max_file_bytes` (default 8 MiB) decoded bytes per envelope, base64 length
  bounded before decoding.
- Image uploads: `maxBytes` before reading whole, 4096 × 4096 px and memory budget from the header before decoding, at most
  two decoded images alive, ≤ 8 variants.
- File route: one indexed metadata query per request; the blob is read only for a 200 (never for a 304 or `HEAD`).
- No outbound HTTP, processes or long-running work (REQ-RUNTIME-001 not applicable).

## CSRF

Applies (session-authenticated admin mutations): Symfony Forms with CSRF on every POST (section save, export, import,
and since 1.15 the secret reveal, whose token id is `nowo_instance_settings_secret_reveal_<section>`),
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
| AI security audit (REQ-SEC-004) | Passed — Appendix BY, final grade **Pass (good) / Low**; every finding fixed before 1.0.0 — see below. Image fields (1.4): immediate audit **Appendix IO**, final grade after Delta IO **Pass** (IO-L1 purge never deletes a referenced image, IO-L2 `files.enabled` opt-in, IO-I2/I3 fixed; IO-I4..I7, IO-I9, IO-I10 documented; IO-I8 pre-existing `paragonie/halite` advisory via doctrine-encrypt-bundle). Brand bridge (1.5): batch audit **Appendix IU**, Pass-Low; IU-L1, IU-I2, IU-I3 fixed and IU-I1 documented after it (diff look pending). |

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
