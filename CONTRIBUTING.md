# Contributing to EQLWiki

Start with the [README](README.md), [development guide](docs/EQL_DEVELOPMENT.md),
and [custom component map](docs/EQL_COMPONENTS.md). These explain the EQL layer;
standard MediaWiki behavior is linked to its official documentation.

## Pull request workflow

1. Fork the repository. Add `https://github.com/Maergoth/EQLWiki.git` as your
   `upstream` remote, fetch it, and create a feature branch from `upstream/staging`.
2. Find the component that owns the behavior. Check whether it runs from Git,
   a `MediaWiki:` script page, template/Lua content, or a private host script.
3. Make a focused change. Include a meaningful regression check for a behavior
   fix where practical, and describe manual checks for interactive changes.
4. Push to your fork and open a PR **against `Maergoth/EQLWiki:staging`**.
5. The owner reviews and accepts the change. Staging deploys after merge;
   promotion to `main` is a separate owner-approved release.

```sh
git remote add upstream https://github.com/Maergoth/EQLWiki.git
git fetch upstream
git switch -c fix/my-change upstream/staging
# Edit and run checks appropriate to the component.
git add path/to/changed-file
git commit -m "Describe the resulting behavior"
git push -u origin fix/my-change
```

Only @Maergoth controls updates to `main`. Every push to `main` triggers a live
deployment, including documentation-only pushes. Do not merge all of `staging`
into `main`: staging contains environment-specific operational changes. Promote
the reviewed feature commits, with any database-page/host installation steps
called out separately.

The public repository does not grant access to private host credentials or data.
Staging access is limited to live wiki administrators/bureaucrats through the
live-login gate. A contributor can submit source changes and isolated tests
without being given a production database or staging login.

## What a useful PR contains

- A concrete reproduction and the expected behavior; link an existing issue
  when there is one. Distinguish application defects from article-data problems.
- The component affected and any template classes, parser parameters, API fields,
  or persisted storage keys whose contracts change.
- Validation results. For UI changes include a screenshot and check narrow
  layouts, keyboard access, and relevant anonymous/account behavior.
- Deployment details if the feature also needs a `MediaWiki:` page update,
  private configuration change, cache rebuild, generated bundle, or host job.
  Include a recovery plan for data writes or schema changes.
- Documentation updates when behavior, setup, or component ownership changes.

Do not claim a live/staging check if you only ran a unit fixture. State missing
data, game files, accounts, or environment access that limits verification.

## Preserve existing contracts

Existing uploaded icon names and IDs, article references, persisted account
state, and wiki/forum identity are compatibility boundaries. Do not renumber
icons, overwrite old artwork, replace user state from anonymous browser storage,
or change login behavior as an incidental cleanup.

Keep EQL changes in the custom skin/extensions when possible. Bundled MediaWiki,
Vector, SMF, and vendor code have their own upstream behavior and licenses.
Separate dependency upgrades or core patches from unrelated feature work.
Do not enable staged extensions or historical backup copies just because they
appear in the repository.

Never commit database dumps, uploads, private account information, real
`LocalSettings.php`/forum settings, bridge secrets, tokens, deployment keys,
game-file packs, or generated caches. Use the sanitized `.example.php` files for
reviewable configuration changes. Avoid posting private logs or data in issues.

Follow the [Code of Conduct](CODE_OF_CONDUCT.md). Preserve license notices;
report security-sensitive findings privately to the owner instead of publishing
credentials or exploit details in a public issue.
