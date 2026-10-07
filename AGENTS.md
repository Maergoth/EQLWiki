# Working on EQLWiki

Read [README.md](README.md), [CONTRIBUTING.md](CONTRIBUTING.md), and the relevant
sections of [docs/EQL_DEVELOPMENT.md](docs/EQL_DEVELOPMENT.md) and
[docs/EQL_COMPONENTS.md](docs/EQL_COMPONENTS.md) before changing custom behavior.
Use [PROJECT.md](PROJECT.md) for deployment and host-operation details.

- The checkout includes MediaWiki core, dependencies, SMF, and historical copies.
  Locate the active custom extension/skin module before editing. Registration,
  configuration, and live database pages determine what runs; filenames and
  old comments are not sufficient evidence.
- Feature branches and contribution PRs target `staging`. Only the owner
  authorizes promotion to `main` or production data/configuration changes.
  Every main push redeploys production, even a documentation-only push.
  Do not merge the entire staging branch into production.
- Keep changes scoped to the request. Prefer the custom skin/extensions over
  edits to core or bundled vendor code; document necessary upstream patches.
- `MediaWiki:Common.js`, `MediaWiki:BlueprintLoader.js`, and
  `MediaWiki:IconFinder.js` run from the wiki database. Git deployment does not
  publish them. The tracked Sky reward file is only a section of Common.js;
  never replace the full page with that section.
- Private host scripts under `ops/` are excluded from application releases.
  Do not claim a host change is installed merely because its source was pushed.
- Preserve icon filenames/IDs and existing artwork, account/anonymous state
  boundaries, template/DOM contracts, and wiki/forum authentication behavior.
  Staging login policy is loaded only through ignored staging settings.
- Never commit or publish private settings, secrets, database/account data,
  uploads, game-file packs, or generated caches. Keep fixtures synthetic or
  private; `.local/` is ignored and blocked by the local router.
- Run checks appropriate to the changed component. Include actual observations
  and limitations in the PR; do not equate a fixture test with a live-site test.
  Database-page sync helpers require the reviewed revision and a fresh private
  backup. Do not run maintenance scripts with write flags as a routine test.
- When a component's behavior or operational contract changes, update the
  component map and relevant guide. Link normal MediaWiki mechanics to official
  documentation rather than duplicating its manual here.
