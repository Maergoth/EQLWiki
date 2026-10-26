# Incremental application deployment

The deployment workflow asks the receiver for its actual installed revision,
then packages the difference between that commit and the new commit. The base
is **not** the previous GitHub push: failed deployments, queued jobs, retries and
rollback can leave the host on another revision.

This reduces deployment disk activity on the shared hosting account. A small
release transfers and extracts only its changed application files, backs up only
the existing files it will replace or delete, and leaves unrelated application
files untouched. It does not reduce independent database refresh, wiki export,
upload, cache-builder or normal request I/O. It does not guarantee that the
hosting account will never reach its I/O limit.

## Ownership and installation order

[`build-release.py`](../ops/build-release.py) and the GitHub workflow run on the
Actions runner. The receiver, [`deploy-files.php`](../ops/deploy-files.php),
[`rollback.sh`](../ops/rollback.sh) and staging preparation/command scripts run
from the host's **private deployment directories**. `ops/` is excluded from
application archives, so pushing their source does not install those scripts.

Before enabling the new workflow for either environment:

1. Review the currently installed private scripts against the proposed versions,
   retaining unrelated host-specific changes. Save fresh private recovery copies
   and record their hashes and ownership/modes.
2. Install the reviewed helper and receiver, plus the corresponding rollback and
   staging preparation/command changes, with the environment idle and its locks
   respected. Keep existing restricted SSH keys, environment roots, authentication
   and credentials unchanged. Replace private scripts by rename after syntax
   validation; do not run a deployment while replacing them.
3. Read `status` through that environment's normal restricted deployment command.
   It must return JSON with `protocol: 2` and a valid installed `revision` or null.
   The updated receiver still accepts older complete application archives, so it
   can be installed before switching the workflow.
4. Enable and exercise the workflow on staging first. Confirm the changed-file
   summary, API health and rollback receipt. Owner-authorized production
   installation and a scoped promotion follow separately; never merge all of
   staging into production.

An old receiver rejects `status`, making the new workflow stop before uploading
an archive. Do not bypass that failure by feeding it an incremental archive.

See [PROJECT.md](../PROJECT.md) for the host paths, access boundaries and existing
health/rollback procedures. Neither this source change nor its CI tests establish
that the private host scripts have been installed.

## Package and receiver contract

`python3 ops/build-release.py --output release.tar.gz` creates a complete repair
bundle. `--base <full-commit-SHA>` creates a delta from that exact available Git
commit. Both require a clean tracked checkout. Git compares both content and file
mode, with renames represented as removal plus addition. Private paths and the
generated asset-marker source are excluded by the normal release filter.

Protocol-2 archives contain:

- `.eql-deployment-manifest`: the complete desired managed file list.
- `.eql-deployment-payload`: only the files actually carried in this archive.
- `.eql-deployment.json`: protocol, target revision and base revision; a null base
  identifies a complete bundle.
- The payload files, with the generated deployment-version marker last in both
  lists. Control metadata is private and is not copied into the public tree.

The receiver validates archive paths and regular file types before extraction,
then validates manifest/payload completeness. Under the deployment lock it
refuses a delta whose base no longer matches `current-sha`. A stale-base failure
requires rerunning the workflow to obtain fresh status and rebuild the package;
it never silently applies the delta to another release. The usual staging
refresh exclusion, protected settings/data/cache paths and API health check stay
in effect.

For files carried by the payload, the receiver compares prepared bytes and modes
with the live file and skips an exact no-op. For a delta it does not read all
unchanged application contents. This assumes unchanged managed files have not
been edited directly on the host. It is not a filesystem integrity audit.
Affected existing files are backed up from their **actual live contents**, so
manual changes to a touched file are recoverable.

Staging transformations apply only to supplied files. Existing transformed files
are retained. A changed private staging preparer/access-rule implementation needs
a **full repair** after installation, since a Git source diff cannot detect a
change to the private transformation itself. The saved `.htaccess` base changes
only when the archive supplies a new `.htaccess`.

The receiver removes retired managed files and applies application changes before
activating the new [asset cache marker](DEPLOYMENT_ASSETS.md). Health failure
restores touched files, newly created paths and previous deployment state.
Catchable interruptions run the same recovery. A durable pending-transaction
record blocks status and further deployments after an uncatchable interruption;
the operator must recover its exact prepared backup before continuing. A
multi-file release is still not globally atomic; incompatible coordinated changes
need the existing maintenance-window procedure.

## Repair and rollback

The manual **Deploy EQLWiki** workflow has a `full` checkbox. Use it to reconcile
out-of-band changes, after installing a changed staging transformation, or when
the installed revision is unavailable in Git history. The normal workflow also
uses a full bundle for a host with no baseline or a baseline commit unavailable
in its checkout. Invalid status is an error, not a reason to guess a base.
File-to-directory and directory-to-file transitions are rejected even by full
repair; review and remove their conflicting managed paths manually before retrying.

Incremental backup directories contain only the files touched by that deployment
under `files/`, a `plan.json` recovery plan, and copies of previous deployment
state. They do not recompress unchanged application files into a full archive.
They are reverse patches, not standalone snapshots of the whole application.
Rollback therefore checks that the current deployed revision matches the backup's
target before restoring it. To go farther back, walk the intact chain in reverse
order. Do not apply an old delta backup directly to an unrelated newer release.
Legacy complete `files.tar.gz` backups remain supported with the same
current-target guard.

Backups retain their normal private location and are not a substitute for database
or uploaded-file backups. This change does not delete historical backups or alter
retention.

## Verification

Run `python3 ops/test-release-assets.py`, `python3 ops/test-incremental-deploy.py`,
PHP lint for the changed private PHP files, shell syntax checks, and
`git diff --check`. The new integration fixtures create disposable synthetic Git
repositories and filesystem trees; they never load wiki settings, touch a database,
connect to the host or publish a page. CI runs them on Linux with real PHP/Bash
tools. Distinguish those results from a staging or production installation check.

Operational verification should record transferred payload bytes, backed-up bytes
and changed/deleted/skipped file counts for a small release, as well as native API
health and the actual installed receiver hashes. Compare hosting fault times to
deployment/export times before attributing an I/O warning to a particular job.
