# EQLWiki client API and optional observations

The public endpoint is `https://eqlwiki.com/api.php`. The reviewed public page
source is [API_Documentation](../ops/api-documentation.wiki). It documents normal
MediaWiki login/query/parse/edit/upload and the optional EQLClientData observation
contract. Other tools need no EoZ secret, privileged proxy or special User-Agent.
Keep ordinary account attribution, email/block/protection/challenge rules and
CSRF/account assertions. Standard [MediaWiki edits](https://www.mediawiki.org/wiki/API:Edit)
already support page creation and updates; do not build a second write API.

`action=eqlmetadata` remains a bounded read-only POST. Its response adds a
documentation URL without removing existing fields. `action=eozobservations`
is a distinct, authenticated data-only POST. It does not edit wiki pages, infer
drop rates or supply an encounter denominator.

## Shared item scaling rules

The separately registered ItemLevelSlider extension exposes public, read-only
`GET action=eqlitemscaling&format=json&formatversion=2`, returning
`{eqlitemscaling:{schemaVersion:1,engineVersion:1,revision,rules}}`. It reads the
tracked JSON coefficient document used by the wiki slider, with no account,
token, database migration or EQLClientData observation dependency. See the
[portable engine contract](../extensions/ItemLevelSlider/README.md) for source
ownership, strict validation, offline fallback and compatible-version rules.
Clients refresh data only, never execute downloaded formula code, and retain a
single rules revision throughout any contribution review/reverse calculation.
The tracked public API_Documentation source also describes this endpoint;
publishing that database page remains a separate guarded operation.

## Source and authentication

The active extension is `extensions/EQLClientData/`, registered by the actual
environment configuration. Version 1.2.0 source introduces `ApiObservations`,
`ObservationPayload`, `ObservationRepository`, schema hooks and three own tables.
Collection is **disabled by default**. Source deployment alone neither installs
the tables nor enables collection or publishes API_Documentation.

The module requires a named, unblocked account, the ordinary
`eoz-submit-observations` right (granted to the user group), confirmed email by
default, POST and a CSRF token. Actor identity is acquired on the server from the
authenticated user; submitted actor/account/player fields are rejected. Clients
also send `assert=user` and `assertuser` in the same cookie session. There is no
shared bot credential. Query current login requests and module parameters; the
read-only baseline on 2026-10-24 exposed login/clientlogin/edit/upload and normal
password/remember-me requests. This is not a successful real-account login test.
Scoped bot passwords can request the separate `eozobservations` grant; this grants
only the observation right, never article editing or elevated wiki permissions.

## Frozen observation schema 1

Form parameters: `action=eozobservations`, `operation=status|submit`, CSRF
`token`, account assertions and `format=json&formatversion=2`. Status returns
`{eozobservations:{schemaVersion:1,enabled,available,account:{id,name},limits}}`.
Disabled status avoids database access. It never accepts an observation.

Submit adds `batch`, a JSON object with `schemaVersion:1`, `batchId`,
`clientVersion` and 1–100 `events`. The UTF-8 batch limit is 262144 bytes; a
normalized event is limited to 4096 bytes. Batch/event identifiers match
`[A-Za-z0-9_-]{8,120}`. Unknown envelope/event fields reject. Events permit only:

`id`, `kind`, `observedAt`, `server`, `zone`, `zoneTitle`, `npc`, `npcTitle`,
`item`, `itemTitle`, `quantity`, `tier`, `instance`, `sourceKind`, `sourceToken`,
`sessionToken`, `sequence`, `catalogVersion`.

- Kills require npc, no item, quantity 1 and sourceKind `kill-line`.
- Loot requires item and quantity 1–1000; npc/zone may be unknown. Source kinds
  are `explicit-corpse`, `explicit-source`, `recent-kill`, `encounter-target`
  and `unknown`. Attributed source kinds require npc. Inference remains visible.
- Instance is normal/raid/unknown; nullable tier is an integer 0–10. Canonical
  optional titles are main namespace only, without markup. Text/identifier caps
  are specified by the validator and public page, not a free-form log archive.
- UTC observedAt permits seconds or three fractional digits and no more than
  five minutes of future drift. Old offline data has no arbitrary past expiry.
  Store observedAt and server receivedAt separately. No player names, raw chat,
  paths, coordinates, screenshots or credentials belong in these records.

The inner receipt has schemaVersion, batchId, server-derived account, accepted,
duplicates and conflicts arrays of IDs, rejected `[{id,code}]`, receivedAt and
limits. A valid input ID appears in exactly one partition. Invalid ID can be
reported as null; clients validate IDs before submitting. A repeated actor/event
ID with identical canonical normalized payload is duplicate; a changed payload
is conflict. Exact batch replay changes accepted IDs to duplicates, without
re-insertion. Reusing a batch ID for changed content is `eoz-batch-conflict`.

Clients acknowledge only accepted/duplicate IDs after verifying exhaustive,
disjoint partitions and exact batch/account identity. Preserve pending data on
timeouts, disabled/schema/capacity/rate errors, conflicts or malformed receipts.
There is no page-edit transaction across events and articles.

## Storage, quotas and retention

MySQL/MariaDB tables are `eql_eoz_state`, `eql_eoz_batches` and
`eql_eoz_observations`. A locked single state row serializes quotas and actor/event
deduplication; all event/receipt/counter writes occur in one transaction. Separate
unique actor/key indexes prevent cross-account or retry overwrites. No public
read/list endpoint exposes stored data in this release.

Defaults: 12 new batches/account/hour, 100000 events globally/10000 per actor,
20000 batch receipts globally/2000 per actor. These are configured hard bounds,
not coverage guarantees. Exact retries do not consume another receipt. Capacity
rejects explicitly and never evicts saved data. No automatic expiry, aggregation,
probability or destructive compaction is implemented. Operators must review
capacity/retention before changing quotas or removing data; preserve receipts
for retry semantics. Multiple accounts can report the same real encounter.

## Separate, narrow activation

1. Review/deploy exact extension source through staging, then scoped production
   promotion. Confirm API health/active registration and keep collection false.
   Follow PROJECT shared deployment lock and data-refresh exclusions.
2. Review the fixed `extensions/EQLClientData/sql/mysql/observations.sql` hash.
   Run `php maintenance/run.php ./ops/install-eoz-observations.php --expected-db
   EXACT_DB --expected-sha256 REVIEWED_SHA` in the intended configured wiki.
   Dry-run is the default. It refuses a wrong database, enabled collection,
   unsupported dialect or partial existing tables.
3. Only after review, repeat with `--apply --backup FRESH_PRIVATE_FILE`. The
   receipt is created exclusively, verified and mode 0600 outside the served root
   (or in blocked `.local`). A DB advisory lock prevents concurrent installers.
   Only three absent tables are created; never run a blind core update, DROP,
   ALTER or counter reset. MySQL DDL can partially commit on failure: retain the
   receipt, keep collection disabled and inspect; do not auto-retry a partial set.
4. Existing tables are never rewritten. Review their full DDL/indexes/counters
   against the SQL source before enabling; the helper checks required fields
   and state presence but is not a full structural migration engine.
5. Separately activate the reviewed collection flag/quotas in ignored environment
   configuration. Confirm authenticated status. Real observation writes require
   explicit activation acceptance, not a routine smoke test under the owner.
   The owner-approved `test-eoz-observations-staging.php` is hard-bound to
   `https://test.eqlwiki.com` plus an exact database and existing operator. Its
   explicit `--transaction-test` runs real repository accept/replay/conflict checks
   inside a cancelable outer transaction, always rolls back, then verifies all
   three table counts/counters and absence of its temporary ID. This is separate
   from a real authenticated HTTP login test and must never run on production.
6. Publish the reviewed public page with `sync-api-documentation.php`, current
   `--expected-page REV` (0=create only), dry-run, then explicit `--apply` plus a
   fresh verified private backup. PageUpdater uses compare-and-swap and preserves
   the recovery receipt. Git deployment does not publish this database page.

The ordinary schema hook supports future deliberately invoked MediaWiki updates
on MySQL; other dialects skip this optional feature. This does not authorize
running all core/extension migrations as a feature test.

## Focused verification

`php ops/test-eoz-observations.php` exercises pure validation, transactional
repository rollback/dedup/quotas and API authorization with synthetic doubles.
It does not create a database, account or real authenticated HTTP session. Lint
the new PHP/JSON and run the existing metadata/skin checks for touched contracts.
The desktop independently tests named-session transport, exhaustive receipt
validation, unknown-write retries and persistent outbox semantics.

For article drafts, preserve current Help blueprint revisions, canonical identity,
normal/raid selection, item onlyinclude and guarded per-page partial retry.
Monster locations keep the wiki/log convention **Y, X, Z**, not internal viewer
world X,Y,Z. Unknown fields stay unknown; do not fabricate narrative or classes.
Real screenshots are explicit user-selected PNGs with reviewed caption/license,
unique file names, SHA/account reconciliation, then dependent article edits.
Refer to [the official upload API](https://www.mediawiki.org/wiki/API:Upload).
