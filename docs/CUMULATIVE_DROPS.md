# Cumulative drop totals and activation

EQLClientData 1.5.0's `CumulativeDropStore` maintains all-history source/item counts and a private
submission index. It has no lifetime contribution or entry limit. The existing
schema-2 `eqldrops` response, browser columns, grey report-share metric and
NPC display module remain compatible. These are loot-report shares, not
per-kill drop chances; coverage, overlapping encounters and unlooted items remain
unknown. Server and tier combine before counting. Invalid capability-message
zones and inferred/provider sources remain excluded, including legacy rows.

Entries and bounded submission history publish from their first eligible report.
Existing cumulative tables already contain single-report entries and every
eligible report in their scope totals, so this publication change needs no DDL,
backfill or raw-data changes. Percentages retain the same full reported-loot
denominator. The database and API keep numeric percentages for every public
entry. Only an automatic NPC percentage waits for ten reports of that item in
the same zone/NPC/instance; the item itself remains visible from its first
report. An authored loot-rate annotation always stays as written and suppresses
the automatic percentage, regardless of sample count. This display floor does
not change counts, history, stored projections or snapshots, and does not
establish statistical confidence or distinct kill coverage. Recent-window read
caches include the publication
minimum to avoid reusing minimum-two results; new snapshot tokens include it
too. Existing cumulative detail snapshots retain their namespace and
count/revision boundaries until normal expiry. A recent-window policy refresh may require a stale-token
reload, as with any refreshed recent snapshot.

## Transaction and read contracts

Five optional InnoDB tables belong to this feature:

| Table | Derived state |
| --- | --- |
| `eql_drop_state` | Projection revision, resumable scan cursor and readiness |
| `eql_drop_scopes` | Total eligible reports per zone/NPC/instance, including singleton items |
| `eql_drop_entries` | Item reports, quantities, private distinct-contributor count and latest revision |
| `eql_drop_records` | One classification per raw observation ID; eligible rows contain only the public detail allowlist |
| `eql_drop_contributors` | Private entry/actor membership used for exact contributor counts |

The ingestion transaction inserts accepted raw events, updates projections, saves
its batch receipt and updates counters together. A projection failure cancels all
of these writes. Duplicate events/batches never increment totals. The unique
classified raw ID also makes resumed backfill and concurrent ingestion idempotent.
Existing raw payloads, receipt records and their hashes are never rewritten.
Classification rules are versioned source behavior: changing admission or title
normalization later requires a separately reviewed reprojection plan. Existing
processed markers must not be silently reused to claim a new rule was backfilled.

Both paths lock the existing ingestion state before projection state. All
projection mutation inputs and backfill anti-joins use `FOR UPDATE` current
reads, including under MySQL `REPEATABLE READ`; a pre-lock consistent snapshot
cannot overwrite newer counters. The cursor accelerates a forward scan but is
never proof of completion. Before readiness, a final anti-join checks every
historical loot ID, including holes below the cursor. This final fence can scan
the full loot index while holding the ingestion lock. Measure it separately from
ordinary chunks; chunk size bounds mutations and returned payloads, not that
final database scan's work.

Once ready, public requests query persistent aggregate tables and return at most
500 entries or 50 submission rows. They never reparse raw history or cache one
member ID per submission. Paging retains indexed `LIMIT`/`OFFSET` for the current
schema-2 consumers; deep offsets require linear index traversal and are not
keyset paging. All retained eligible reports contribute to cumulative totals;
bounded page sizes and native-integer offset limits remain. Out-of-range offsets
return empty pages before issuing the SQL page query. Each summary
`snapshot` caches only the displayed entries' report counts and revision
boundaries for five minutes, so new submissions or a later lower-ID reconciliation
cannot change an already-open history's membership.

History requires a persistent shared WAN cache across PHP requests (the reviewed
hosts use `SqlBagOStuff`). Cache write failure leaves counts available and returns
`detailsAvailable:false`; the browser disables expansion. Expired/evicted snapshots
return `eqldrops-detail-stale` and can be reloaded. Never substitute account IDs,
event IDs, raw logs or unbounded history into the cache or response.

Before schema installation and during incomplete backfill, the existing recent
view remains available: `historyMode:recent-window`, `cumulativeStatus:not-installed`
or `backfilling`. Its 100,000 most recently received loot-candidate boundary and
warning still apply. Partial derived totals are never presented as cumulative.
After a fully reconciled transaction commits, reads automatically use
`historyMode:cumulative`, `cumulativeStatus:ready`, `windowed:false` and
`windowLimit:null`. `detailsAvailable` describes history-cache availability.

## Separate reviewed activation

Source deployment alone does not install these tables or run backfill. `ops/`
is excluded from releases; the reviewed maintenance files must be separately
installed on the host. Do not run core updates or write-enabled maintenance as
a routine test. Follow [PROJECT.md](../PROJECT.md) for deployment locks and private
backups; production activation requires separate owner approval.

1. Review the exact feature commit, schema SHA256, migration code and rollback
   plan. Take the normal fresh private database/settings backups. Confirm modern
   InnoDB supports the 2,952-byte binary ordering index (3,072-byte index limit).
   First validate this procedure against staging.
2. Under the shared deployment/maintenance lock, disable observation ingestion
   in its ignored environment settings, prove `enabled:false` through an
   authenticated HTTP capability check, and let existing old-code requests finish.
   Deploy the new writer everywhere before installing the schema. Mixed old/new
   ingestion code during activation is not supported. Pending desktop batches
   remain local and retry; no client preference or opt-out change is required.
3. Separately install a private reviewed bundle outside the served root, preserving
   this layout (the SQL is a reviewed copy, not a generated cache):

   ```text
   PRIVATE_BUNDLE/ops/DropSummaryMaintenance.php
   PRIVATE_BUNDLE/ops/install-drop-summaries.php
   PRIVATE_BUNDLE/ops/backfill-drop-summaries.php
   PRIVATE_BUNDLE/extensions/EQLClientData/sql/mysql/drop-summaries.sql
   ```

   `schema()` resolves the SQL relative to these private commands. Compare both
   the bundled SQL and deployed extension SQL to the reviewed hash; application
   deployment installs neither the private commands nor this bundle. Run the narrow
   schema installer in dry-run mode with the exact target database and fixed SQL
   hash. Repeat with `--apply --backup FRESH_PRIVATE_RECEIPT` only after review:

   ```text
   php maintenance/run.php /PRIVATE_BUNDLE/ops/install-drop-summaries.php --expected-db EXACT_DB --expected-sha256 REVIEWED_HASH
   ```

   It creates only five absent derived tables, never alters/drops existing or raw
   tables, and refuses partial installation. MySQL DDL may partially commit:
   retain the receipt, keep ingestion disabled and inspect rather than retrying
   over a partial schema. The receipt records the absent derived schema; it is
   not a replacement for the normal private database backup.
4. Prove the reviewed new writer is active in every PHP worker (reload/drain old
   workers under the host's reviewed procedure). Keep the receiver paused through
   the first final reconciliation/readiness fence. Run bounded
   backfill dry-run, then explicitly applied chunks with a fresh private derived
   state receipt per invocation:

   ```text
   php maintenance/run.php /PRIVATE_BUNDLE/ops/backfill-drop-summaries.php --expected-db EXACT_DB --expected-sha256 REVIEWED_HASH
   php maintenance/run.php /PRIVATE_BUNDLE/ops/backfill-drop-summaries.php --expected-db EXACT_DB --expected-sha256 REVIEWED_HASH --apply --backup FRESH_PRIVATE_RECEIPT --batches 10 --batch-size 100 --yield-ms 100
   ```

   Each chunk commits through MediaWiki's maintenance transaction-round API,
   waits for replicas and yields outside the lock. Defaults are one 100-row chunk
   and a 100ms pause; upper bounds are 100 chunks/invocation and 1,000 rows/chunk.
   Tune from staging/host observations. On failure, raw data and prior committed
   chunks remain intact; resume the same command with a new receipt. Once the
   reconciliation fence finds no missing rows, readiness switches atomically.
5. Restore the previous reviewed receiver setting, confirm authenticated HTTP
   capability and verify a separately approved accepted batch updates raw and
   derived counts atomically. Verify classified-record count equals raw loot
   count and eligible record/source/item sums agree, without exporting records.
   Verify all-history counts, excluded pseudo-zones, earliest legacy reports,
   two distinct HTTP summary/detail requests and native fallback, paging and
   anonymous/account behavior. Record actual chunk/fence durations and cache
   persistence. Keep raw/account data out of logs, PRs and public fixtures.

Rollback must preserve ingestion/projection consistency: pause the receiver and
prove the paused HTTP capability before reverting to old writer code. While the
new maintenance code remains installed, run the reviewed backfill command with
`--deactivate --apply --backup FRESH_PRIVATE_RECEIPT` to set readiness false. It
refuses an enabled receiver and changes no counter or raw record. Confirm the
recent-view fallback, then revert/reload files and restore the reviewed receiver
setting. Existing private observations/receipts remain
the authority. Do not drop tables, restore an older raw database or reset derived
counters automatically. Never leave readiness true while old raw-only writers
are active. Retain derived tables for inspection; the recent-view
reader in old code ignores them. Before reactivating cumulative code, reconcile
all raw IDs again; newly written low-ID gaps cannot be assumed absent. Publishing
the tracked public API documentation is a separate guarded database-page change.

## Verification and limits

`php ops/test-drop-cumulative.php` uses an in-memory SQLite SQL adapter with more
than 100,000 synthetic legacy observations. `--mysql-fixture` is hard-bound to a
disposable `eql_cumulative_fixture` database on loopback port 3319 with synthetic
fixture credentials; it never reads application settings. This additionally tests
the actual InnoDB DDL and stale repeatable-read snapshots using two connections.
The fixture intentionally recreates only that fixed disposable database. Neither
mode installs a real wiki schema or establishes live authentication/host capacity.
Existing observation, browser and NPC fixtures remain required. Native staging
installation, HTTP cache persistence and operator timing are separate acceptance.

The development fixture on MariaDB 11.4.12/InnoDB `REPEATABLE READ` passed with
more than 100,000 legacy rows, stale snapshots across two connections and Unicode
first-letter expansion. Its measured maximum 1,000-row chunk was 508.3ms and final
completeness anti-join 117.6ms; the first 25 history rows took 1.3ms and the page at
offset 100004 took 17.5ms. These local fixture timings are not production capacity
evidence. The current SQLite 3.51.3 fixture also checks incomplete/schema-absent
fallback, writer/backfill interleaving, cache failure, short-circuited invalid
offsets and maintenance/deactivation guards.
