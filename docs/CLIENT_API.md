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

## Source and authentication

The active extension is `extensions/EQLClientData/`, registered by the actual
environment configuration. Version 1.2.0 source introduces `ApiObservations`,
`ObservationPayload`, `ObservationRepository`, schema hooks and three own tables.
Collection is **disabled by default**. Source deployment alone neither installs
the tables nor enables collection or publishes API_Documentation.

The module requires a named, unblocked account, the ordinary
`eoz-submit-observations` right (granted to the user group), confirmed email by
default, POST and a CSRF token. Actor identity is acquired on the server from the
authenticated user; submitted actor/account fields are rejected. Optional public
character attribution requires explicit consent, described below. Clients
also send `assert=user` and `assertuser` in the same cookie session. There is no
shared bot credential. Query current login requests and module parameters; the
read-only baseline on 2026-10-24 exposed login/clientlogin/edit/upload and normal
password/remember-me requests. This is not a successful real-account login test.
Scoped bot passwords can request the separate `eozobservations` grant; this grants
only the observation right, never article editing or elevated wiki permissions.

## Observation schema 1 and backlog delivery

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
`sessionToken`, `sequence`, `catalogVersion`, and optional `characterName`.

`limits.optionalEventFields:["characterName"]` advertises optional public
character attribution. Clients must omit this field unless the contributor opted
in and explicitly configured their character identity. Never silently publish a
log-derived name or wiki account. Names are 1–120 Unicode characters excluding
controls, paths and URLs. Absence remains absence during normalization, preserving
historical event hashes. Do not retrofit names onto old records or rewrite frozen
batches. Character names are self-reported, not proof of account ownership.

The per-request limits are not a backlog limit. Send further bounded batches,
newest observed timestamps first, until the pending queue is empty. Complete an
already prepared immutable batch before selecting new records. Login inside EoZ
is required; website login is a different session. Timeouts preserve the exact
batch for idempotent retry, and disabled collection must remain opted out.

- Kills require npc, no item, quantity 1 and sourceKind `kill-line`.
- Loot requires item and quantity 1–1000; npc/zone may be unknown. Source kinds
  are `explicit-corpse`, `explicit-source`, `recent-kill`, `encounter-target`
  and `unknown`. Attributed source kinds require npc. Inference remains visible.
- Instance is normal/raid/unknown; nullable tier is an integer 0–10. Canonical
  optional titles are main namespace only, without markup. Text/identifier caps
  are specified by the validator and public page, not a free-form log archive.
- UTC observedAt permits seconds or three fractional digits and no more than
  five minutes of future drift. Old offline data has no arbitrary past expiry.
  Store observedAt and server receivedAt separately. Apart from explicitly opted-in
  character attribution, no player names, raw chat,
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
unique actor/key indexes prevent cross-account or retry overwrites. Raw records
and contributor identities remain private. Version 1.3 adds the aggregate-only
browser and read API described below.

Default pacing is 120 new batches/account/hour. There is no default lifetime
entry or batch-receipt quota: `EQLClientDataObservationMaxEvents`,
`ActorMaxEvents`, `MaxBatches` and `ActorMaxBatches` are zero (unlimited).
Operators can still configure positive emergency capacity bounds; a positive
bound rejects explicitly and never evicts saved data. Exact retries do not consume
another receipt. The rate window and small requests protect normal wiki traffic
while the client continues automatically after backoff. No automatic expiry,
drop-probability calculation or destructive compaction is implemented. Operators must review
capacity/retention before changing quotas or removing data; preserve receipts
for retry semantics. Multiple accounts can report the same real encounter.

## Browsing observed loot (1.5, read schema 2)

`Special:DropDatabase` browses existing accepted observations without changing
articles or installing tables. Its filters cover zone, NPC, item,
instance and literal free-text search, with 50 aggregate entries per page.
The read-only GET API `action=eqldrops&format=json` exposes the same projection,
with optional `zone`, `npc`, `item`, `instance`, `q`, `offset` and
`limit` (1–500). Source filters use exact reported/canonical title identities.
These title claims are not an editorial verification of the reports.

Only explicit-corpse loot with a zone, NPC and item qualifies. Provider wording
can describe merchants; recent-kill/target inference and unknown sources are
excluded. Capability-message pseudo-zones (`a/an area where…`) are excluded
from both raw zone and zone-title claims without rewriting stored observations
or guessing the missing zone. A source/item entry appears with its first eligible
accepted event; an ingestion replay contributes no new event. Server and tier
are combined before counting; zone, NPC and instance remain separate.
Contributor counts do not establish independent encounters.
No actor, event, batch, source or session identifiers leave the projection.

`reportShare` is the item's percentage of all eligible loot-event reports for
the same zone/NPC/instance. The denominator includes every server and tier and
single-report items. It counts reports, not item
quantity or kills. The logs lack complete corpse coverage, zero-drop encounters
and shared encounter IDs, so this is **not drop chance**. `reports`, `quantity`,
`contributors` and `totalReports` are separate API fields. The browser shows
Times reported and moves item quantity into the expanded submission history.

Each aggregate has an opaque `entryId`; the response carries a `snapshot` token.
GET `action=eqldrops&detail=ENTRY&snapshot=TOKEN&offset=0&limit=25` returns
one bounded history page (maximum 50 rows). Only currently published groups can
be expanded, including entries with one eligible report; entries outside the
summary's public membership and stale snapshots are refused. Details
contain only observed time, server-received submission time, quantity,
explicit-corpse category and an optional consented character name. Historical
names are null and shown as Not provided; wiki account names are never inferred.
The browser loads history on expansion with a normal history-page fallback.

With the separately installed and reconciled cumulative schema, `historyMode`
is `cumulative`, `cumulativeStatus` is `ready`, `windowed` is false and
`windowLimit` is null. Persistent aggregate tables include all eligible accepted
history without a lifetime entry cutoff; raw observations and receipt hashes
remain unchanged. Public reads use indexed aggregate and `LIMIT`/`OFFSET`
submission queries, never a full-history payload scan. Offset traversal is linear
within the matching index; it is not keyset paging. Page size and native-integer
offsets remain bounded; out-of-range offsets skip the SQL page query. Normalized
public filters allow 256 codepoints so first-letter case expansion of a valid
230-codepoint input stays exactly filterable. Ingestion's 230-input limit and
legacy hashes are unchanged.

Summary `snapshot` and detail requests retain read schema 2. The shared WAN cache
stores at most 500 displayed entries' count/revision boundaries per snapshot for
five minutes, with no per-submission member list. It must persist across PHP
requests. Cache write failure returns `detailsAvailable:false` with usable counts;
the browser disables history links. An evicted/expired snapshot returns
`eqldrops-detail-stale`. New accepted rows do not change an open snapshot's history.
The recent-window read cache includes the publication minimum, so an old
minimum-two cache cannot hide first reports after a source update. New snapshot
tokens include that minimum. Existing cumulative history manifests retain their
namespace and remain usable until normal expiry; a refreshed recent-window
snapshot can require the usual stale-token reload.

Before installation and while backfill is incomplete, `historyMode` remains
`recent-window`; `cumulativeStatus` is `not-installed` or `backfilling`. This
fallback reads at most 100,000 most recently received loot candidates in 500-row
chunks and uses the existing five-minute bounded member lookup. `windowed`
and the browser warning disclose excluded older candidates. Partial derived
totals are never presented as cumulative. Absence of the original observation
schema returns `available:false`; reads never install tables. See the
[separate schema/backfill/rollback contract](CUMULATIVE_DROPS.md). Source-only
deployment does not activate cumulative storage or publish public documentation.

On EQLImmersive NPC articles using `.eql-mobpage`, the conditional ResourceLoader
module annotates manual item links with grey percentages and appends unmatched
items in grey. Matching uses the same-origin link destination, including links
generated by item transclusions, independently of display labels or rarity notes
such as `[[Item Link]] (Common)`. Internal title case stays exact; underscores and
the wiki's first letter are normalized. A source selector appears only for
multiple zone/instance contexts. A single eligible report can annotate a matching
item or append an unmatched item. The test presentation shows item name, one grey
percentage and optional rarity, with no share-explanation disclosure or extra
database link in the loot table. Duplicate authored `.ddb` rates and inline stat
cards are hidden in the rendered loot list; native hover data and original
article/template source remain intact. A failed read leaves the manual list
usable. No database-page JavaScript/template publication is needed. **NPC
presentation remains test-only until separately approved.** Production browser
promotion must exclude the NPC Hook/module activation.

Checks: `php ops/test-drop-cumulative.php` exercises more than 100,000 legacy
records, exact cumulative counters, resumable/late-ID backfill, atomic ingestion,
Unicode title expansion, privacy and revision-bound paging. The optional fixed
loopback MariaDB fixture checks actual InnoDB DDL and repeatable-read behavior;
it is separate from a real wiki or host activation. `php ops/test-drop-database.php` exercises synthetic aggregates,
privacy, thresholds, denominators, pagination, caching and bounded reads.
`node ops/test-drop-browser.cjs` checks lazy expansion, timestamps, optional names,
pagination and failures. `node ops/test-drop-overlay.cjs` (jsdom) exercises actual renderer matching,
notes, append behavior, source scopes and failures. These do not establish live
authentication or uploads. Verify real article integration separately on staging.

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
