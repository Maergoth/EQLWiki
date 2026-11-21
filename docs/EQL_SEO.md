# Search discovery and item descriptions

The public wiki and staging share application code, but must publish different
discovery signals. These controls do not change wiki/forum permissions or publish
database pages. Search ranking and Google's indexing decisions still require
Google Search Console evidence.

## Application ownership

- Root `.htaccess` upgrades HTTP requests for the explicit production and staging
  hosts to their own HTTPS origins. `www.eqlwiki.com` uses the production apex.
  Permanent 308 redirects preserve request methods, paths and queries, including
  wiki/forum POSTs. Local/unknown hosts and HTTP certificate challenges are exempt.
  Redirect paths come from the raw request line: rewrite `REQUEST_URI` is decoded,
  which would otherwise turn an escaped `#` in an article name into a fragment.
- `SeoConfig.php` runs from the existing EQLImmersive registration callback after
  private environment settings load. It enables MediaWiki's native canonical
  links. Canonicals use configured wiki URLs, never an incoming Host header;
  a configured staging server is explicitly kept on `https://test.eqlwiki.com`.
- The same callback enables the native MediaWiki 1.45 sitemap only for the HTTPS
  production server and canonical origin, unless the default robot policy says
  `noindex`. Its namespace scope is main articles (`0`), excluding account, file
  and interface pages. Staging/local/other hosts have the sitemap disabled.
  Existing sitemap cache/page-size options are retained.
- `/robots.txt` routes to lightweight `robots.php`. Production permits ordinary
  search crawling, opts the named OpenAI/Anthropic crawlers and fetchers out, and
  advertises `https://eqlwiki.com/rest.php/site/v1/sitemap/0`. Staging and
  unknown hosts return `Disallow: /` without a sitemap. Staging's existing access
  gate still applies to this route. Production `/sitemap.xml` redirects to the
  native sitemap index; sitemap generation needs no host cron or write command.
- EQLImmersive `ItemSeo.php` supplies item-specific descriptions and a short
  visible introduction from the current rendered item card and documented drop
  zone. It uses the `Items` category and a matching title/card, avoiding unrelated
  pages and embedded item previews. It does not assert current-era availability,
  invent rarity, alter item stats, or save revisions. Existing descriptions are
  retained. See its fixtures for action/revision/permission eligibility.
- The skin uses MediaWiki's single responsive viewport declaration instead of
  appending a second conflicting tag.

Normal mechanics are documented in MediaWiki's
[canonical link setting](https://www.mediawiki.org/wiki/Manual:$wgEnableCanonicalServerLink)
and [sitemap manual](https://www.mediawiki.org/wiki/Manual:Sitemap), and Google's
[canonical URL](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls)
and [sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview)
guides. A missing robots file with a 404 was not a Google crawl block; this route
provides an explicit discovery policy.

## AI crawler preferences and contact guidance

Production's explicit `Disallow: /` group names `GPTBot`, `OAI-SearchBot`,
`ChatGPT-User`, `ClaudeBot`, `Claude-SearchBot`, and `Claude-User`. The generic
`User-agent: *` group still permits ordinary search crawling and the production
sitemap remains advertised. The named opt-outs cover AI training, AI search
indexing and user-directed retrieval as applicable; blocking AI search can reduce
visibility in ChatGPT/Claude answers. See the official
[OpenAI crawler documentation](https://developers.openai.com/api/docs/bots) and
[Anthropic crawler documentation](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler).

Readable comments ask AI assistants and agents to avoid direct wiki interaction
and tell the user to contact **@maergoth on Discord** for permission or assistance.
These comments are advisory prose, not robots directives or a guarantee of an
assistant's response. `robots.txt` cannot make scraping impossible: it is a
voluntary crawler preference, not authentication or access control, as specified
in [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309.html#section-3). OpenAI states
that robots rules may not apply to user-initiated `ChatGPT-User` requests. Browser
agents and unidentified scrapers may use other clients. These preferences add no
API restrictions, firewall rules or changes to wiki/forum authentication.

Edit the production text in `SeoConfig::robotsText()`, not a new static root
`robots.txt` file or the repository's contributor `AGENTS.md`. Keep the
nonproduction deny-all return, ordinary production search allowance and native
sitemap. Changes use the normal application release; no database-page publication
or private host-script installation is required. After owner-approved production
promotion, read `/robots.txt` on the public origin to confirm the named group and
Discord comments; staging must still return its existing deny-all policy through
the access gate. Crawler cache/recrawl timing is separate from file deployment.

## Checks and release

### Reader copy and equipment browsing

The [November 2026 comparison](SEO_COMPARISON_2026-11.md) includes both sparse
indexed pages and substantial excluded guides. It does not establish a minimum
word count or a single cause for Google's exclusions.

`NavigationCrawl.php` preserves native navigation attributes and adds `nofollow`
to recognized local action, revision and utility links in EQL's page-action menus,
custom sidebar and native toolbox. Article, Talk, category and content-source
links remain ordinary links. Native account-menu rendering retains core behavior:
its final conversion filters relation attributes. This is a link hint, not a crawl
block or an indexing policy; existing `noindex` handling and staging protections
remain separate.

Four reviewed database content changes live under `ops/`:

- `itempage.wiki` describes empty optional source/quest/crafting fields as
  undocumented, preserving authored values, headings, classes and the hover path.
- `item-lore-missing.wiki` keeps the maintenance categories and contribution link,
  with compact reader copy; template-editing instructions stay in documentation.
- `equipment-by-class.wiki` retains the JavaScript builder and adds sixteen
  ordinary links to the existing class equipment categories.
- `zone-map-labels.json` corrects three map-link labels without changing map
  geometry, destinations or display behavior.

Git deployment does not publish these pages. Install the reviewed
`ops/sync-item-reader-copy.php` privately and use its fixed target, reviewed
source, freshly checked `--expected-revision` and a fresh private `--backup`.
The fixed target names are `itempage`, `item-lore-missing`,
`equipment-by-class` and `zones-map-labels`.
The helper refuses unrelated content changes and concurrent revisions. Run its
`--help` first. Publish and verify each target on staging before the same reviewed
change goes live; do not use write commands as routine tests. Recovery uses the
private original content and normal revision/conflict protection.

Verify missing, explicitly blank and authored item fields, missing-lore categories
and editor links, item/spell/NPC hovers, and the unchanged equipment builder plus
all sixteen category links. Map checks must preserve the image and positioned
link targets. Record actual staging and production results separately from parser
fixtures. Successful releases and sitemap ingestion do not prove Google indexing.

Run `php ops/test-navigation-crawl.php` and `php ops/test-item-reader-copy.php`
for source-only navigation and publisher scope/recovery checks. With a configured
wiki, `php maintenance/run.php ./ops/test-item-reader-copy.php` also parses
synthetic articles with unsaved template overlays; it saves no pages. These
parser fixtures supplement the actual staging checks required above.

Run `python ops/test-seo-routing.py`, `php ops/test-seo-config.php`,
`php ops/test-item-seo.php`, and the existing
`php ops/test-deployment-assets.php`. They use synthetic requests/content and do
not prove live Apache/LiteSpeed routing, authentication or database integration.
The SEO config checks include the named AI opt-outs, ordinary search allowance,
Discord referral comments, sitemap advertisement and nonproduction deny-all.
The local PHP router implements `/robots.txt` but does not execute `.htaccess`.

Deploy to staging first. Check a current item article, a guide, and a nonarticle
page using the normal staging access path. Verify one responsive viewport, one
canonical on the staging hostname, item description/intro only where eligible,
working item controls/links, and the existing staging `noindex` response header.
The staging sitemap must remain disabled and production must remain unchanged.
Check HTTP redirects with automatic redirect following disabled, plus an encoded
wiki title with query parameters. Verify the wiki and forum login entry points
still load; do not create accounts or edits as a smoke test.

`ops/staging.htaccess` is prepended from an owner-installed private host copy.
Normal application deployment does **not** update that copy. Install the reviewed
source with a fresh private recovery copy, then rebuild the staging access rules
using the existing host access helper. Preserve the login allowlist and
`X-Robots-Tag`. The shared application rules independently retain the staging
hostname; never redirect staging to production or relax its indexing policy.
Keep cPanel's separate **Force HTTPS Redirect** toggle off for these two hosts:
its earlier 301 takes precedence over the reviewed method-preserving rules.
The `.htaccess` rules continue to enforce HTTPS with that toggle off.

After owner-approved promotion of the specific feature commits, verify production
HTTP redirects, canonical links, descriptions and sitemap XML, and recheck staging
isolation. Submit the native sitemap in Search Console and inspect representative
items such as `Bone_Bladed_Claymore`; this account-side step is separate from code
deployment. Google recrawling and ranking changes are not immediate release checks.
No template or `MediaWiki:` page synchronization is required for these changes.
