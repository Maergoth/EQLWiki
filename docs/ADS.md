# Optional ad layouts and staging previews

EQLImmersive places an **Ads X / 1 / 2** radio group immediately after **Donate**.
X is Off, **1 is Bottom** (horizontal Multiplex grid after the complete content
card), and **2 is Right sidebar** (vertical Multiplex grid beside the card).
The controls have individual accessible names, native radio-keyboard behavior,
visible focus/selection states and descriptive tooltips.

**Fresh logged-out readers in Multiplex mode default to Right (2) in landscape
and Bottom (1) in portrait or square windows.** Both grids scroll normally. The
right column moves below the article on narrower windows, without requesting
another unit. The default is computed once and resizing never changes a saved choice.
Historical display-unit configurations keep their existing desktop-aware default.
An explicit choice wins. Turning ads off says exactly **“Ads disabled. Log in
to save your preference”**. An anonymous Off, Bottom or Right choice lasts for
**24 hours from the latest selection**, using a first-party host-only cookie
(`Max-Age=86400`, `Path=/`, `SameSite=Lax`, Secure on HTTPS). It can survive closing
and reopening the browser until that deadline; reads, navigation and focus do not
extend it. The stored timestamp is also checked by the preference reader.
If cookies cannot retain the choice, a timestamped sessionStorage fallback applies
within that tab for at most 24 hours and may end earlier when the tab closes.
A present cookie is authoritative; malformed/unreadable values fail Off. No
localStorage or anonymous preference API write is used. Expiration is checked
when the preference is read; a valid expired record returns to that document's
initial orientation default. No timer refreshes an ad while someone is reading.

The existing wiki-scoped `eql_ads_session_v1_<wiki>` cookie and
`eql-ads-session-v1:<wiki>` storage keys now contain
`v2:<off|side|anchor>:<created milliseconds>`. A legacy plain `off`, `side`,
`anchor` or `on` choice is converted once on its first anonymous read, starting
a bounded 24-hour period then (`on` becomes Bottom). A failed migration stays
Off. No anonymous choice is imported into an account.

These defaults do not guarantee immediate ad visibility. Bottom remains below
the complete article, and Right also falls below it when the window is too narrow.

**Named accounts start with Off** unless their native `userjs-eql-ads` option
selects Bottom or Right. New writes use `off`, `side` or `anchor`. The current
boolean-era `on` value reads as Bottom (now numbered 1) without a background migration write;
`anchor` remains the internal Bottom value and `side` remains Right; relabeling
does not rewrite preferences. `off` remains Off for accounts and anonymous sessions.
Superseded long-lived browser preferences are still ignored. Anonymous and
account choices are never copied across login boundaries.

The account read verifies the current server identity using asserted
`userinfo` options before honoring On. Only a radio-selection or preview close-button gesture
can save through native `mw.Api.saveOption`, with CSRF and the captured account's
`assert=user` / `assertuser` checks. Writes are serialized and the latest choice
wins; stale responses cannot enable ads. Startup, focus and page restoration
perform reads only. Failed or uncertain writes are not automatically retried.
Missing/malformed account values default Off; failed reads also stay Off.

If the browser or account cannot retain an opt-out, the current document keeps
the selection and local preview Off and explains the failure. It does not reload
into a stale saved On. Close that tab to end an already loaded provider; Google's
already requested creative remains intact until navigation. Retry or reload after resolving a
save failure to establish a retained preference.

`resources/ads.js` owns the preference and Google loader. No Google advertising
script, iframe, or ad request is created until the effective preference is On
**and** the server publishes valid ad configuration for that exact environment.
Without provider configuration, an On preference creates no empty production ad area.
Selecting X removes synthetic previews immediately. When Google's script has been
requested and a changed mode can be retained, it reloads the page once to end that document's
advertising runtime. A one-use, page-bound sessionStorage record restores the
scroll offset on reload (60-second lifetime); it contains no account data or ad
preference. The same short-lived record replays the anonymous Off notification
once after a successful reload, so it remains visible. Restoration is best effort
when layout height changes. A change in
another tab is also observed. Browser ad blockers
are respected.

Each Multiplex layout uses a separate issued responsive unit, requested only when
selected. Google chooses its real ad count, arrangement and height. The bottom
wrapper is at most 1200px wide; the right wrapper is at most 300px wide and
shrinks with the available space on narrow pages. Neither grid is
sticky or fixed, clipped, refreshed or artificially constrained to a banner's
height. Both are siblings of `main#content`, outside its background/border card.
The Google loader must finish loading before an in-page unit is mounted or
requested. A blocked, failed or still-loading script therefore leaves no panel,
label or reserved column. Failure does not trigger an automatic retry.
For a requested in-page unit, `ads.js` observes Google's documented
[`data-ad-status`](https://support.google.com/adsense/answer/10762946) on that
unit's outer `ins`. A pending or unknown Multiplex unit remains visible and measurable in
normal flow below the article, without the wiki's label, margins or right-column
reservation. This avoids squeezing the article while Google is still deciding
whether an ad can show. `filled` or `unfill-optimized` enables the wiki's label,
spacing and eligible right column only when the unit has visible, positive outer geometry.
Size observation updates that layout as the unit changes. Geometry controls only
our surrounding layout: it never hides or clips Google's unit or frame.
Only `unfilled` hides the complete wrapper and releases its layout space; a later
fill can restore it. No timeout or frame inspection guesses whether an ad exists.
If Google supplies blank height while remaining pending, that space can remain
below the article, but it does not narrow the article. Observation pauses on page
exit and rechecks the same unit after BFCache restoration. This changes neither
the saved preference nor Google's creative, and never refreshes or retries an ad.
Legacy manual display units retain their prior placement after the loader is ready.
Synthetic previews and historical native anchors are unaffected.
There are no real ads on login/account/special pages, talk pages,
editing/history/diff/old-revision views, missing articles, or local clones.
Production also excludes nonpublic and noindex articles. Staging can load the
provider only through its separate explicit settings described below, preserving
its existing authentication and noindex. `includes/Ads.php` checks the actual environment, title
and view before exposing configuration. Google code is not unconditionally added
to the footer, database `MediaWiki:` scripts, or page head.

## Review manual previews on staging

Placement review is limited to **https://test.eqlwiki.com**. With its separate
provider settings blank or invalid, the server publishes
`{preview:true,manual:true,multiplex:true}`. Each choice shows a synthetic grid
of four labeled placeholders. Bottom uses four columns, or two at 700px and
below; Right uses one column. This illustrates placement only, not the number
or size of Google's eventual ads. X removes the preview, with no Google calls.

The server selects staging only when trusted `Server` and `CanonicalServer`
are both exactly `https://test.eqlwiki.com`; the browser also requires that exact
origin. Copied production settings cannot activate Google on staging. Existing
staging login/noindex policies remain intact. Eligible current main-namespace
articles include Main Page; special/talk/edit/history/diff/old-revision and missing
views have no preview. Production settings stay blank during staging review.

## Grid placement and per-reader provider selection

Right's aside is a sibling of `main#content` in `.mw-content-container`. Once
Google reports a filled or optimized Multiplex unit with visible, positive outer geometry, and with
at least 1000 CSS pixels available, the container reserves a 300px right column
and 32px gap, leaving at least 668px for the article before its own padding.
This ordinary column can also fit a sufficiently wide portrait/tablet window.
Below that threshold it stays after the article. Pending units also stay there
at the same 300px maximum width, so opening the column does not resize the ad.
The layout change is unanimated. Multiplex does not use the
legacy sticky rule, even on desktop. Only one selected unit is requested per
document; resizing changes our layout, not Google's creative or request count.

Bottom's aside always follows `main#content`, including on Main Page. The title,
body, categories and parser-output DOM remain untouched. Its own wrapper centers
the responsive unit up to 1200px. Both units use the standard `autorelaxed` tag
with `display:block`. No deprecated row/column parameters force a count. Never
reuse a display-unit ID as a Multiplex ID or force a 90px height onto the grid.

Google's [Multiplex creation guide](https://support.google.com/adsense/answer/9274644)
recommends responsive units. It suggests roughly 2:1 horizontal or 1:2 vertical
ratios if choosing a custom size. This implementation uses responsive dimensions.
The [row/column customization options](https://support.google.com/adsense/answer/7533385)
are deprecated and are intentionally omitted. The [sticky rules](https://support.google.com/adsense/answer/10734935)
permit only one sticky ad visible at a time, so these multi-ad grids stay in flow.

Two independent Multiplex slots support comparing overall revenue, page RPM and
Active View viewability by unit and device. More ad tiles do not establish a
revenue gain. Reader-selected layouts are not a randomized A/B experiment; do
not infer causality from different audiences. Keep content more prominent than
advertising, review short pages and mobile height, and assess real fills before
adding more inventory. Do not generate synthetic impressions for measurement.

Google account prerequisite: **Auto ads Off and Auto optimize Off**. Shared
root-domain/subdomain settings need owner authorization. Manual units continue
serving independently. No extra `enable_page_level_ads` initializer is used.
Historical `manual-layouts` display units remain supported pending deliberate
Multiplex activation. Their bottom banner now also sits after every article card.
Production still accepts only its independently configured legacy anchor path;
no staging format or slot can activate production.

## Configure the provider on staging

All staging provider settings default to empty strings. Install only the issued
publisher identifier and these four staging settings in the private mode-0600
file `/home/eqlwikdq/deploy/EQLWiki-staging/ads-settings.php`:

```php
$wgEQLAdSenseStagingClient = 'ca-pub-<issued publisher ID>';
$wgEQLAdSenseStagingFormat = 'multiplex-layouts';
$wgEQLAdSenseStagingMultiplexSidebarSlot = '<issued vertical Multiplex slot>';
$wgEQLAdSenseStagingMultiplexBottomSlot = '<issued horizontal Multiplex slot>';
```

Do not include production settings or account credentials, and do not reuse the
independent Talk policy file. Keep production provider settings blank.

The reviewed refresh generator conditionally includes this optional file after
the existing Talk policy include at the end of staging `LocalSettings.php`,
following its isolation settings. An absent file leaves the synthetic preview.
The generator replaces both `LocalSettings.php` and `EQLStaging.php` on refresh;
putting a manual include only in either generated file will not persist.
Deploy the staging provider source before activation, then separately install the
reviewed generator, matching include in current staging `LocalSettings.php`, and
private ad settings file under `deploy.lock`, with no `refresh-in-progress`
marker. Take fresh private backups of the installed generator, current settings
and any existing ad settings file first. Application deployment excludes `ops/`
and installs neither the private host generator nor ignored settings. Verify
syntax, staging isolation and credentialed API health without running a database
refresh as a test; see [host installation and rollback](../PROJECT.md#staging-and-contributions).

Valid staging settings replace the synthetic previews with the selected Google unit on
eligible current main-namespace article views, through the same effective Ads
preference. The server marks that configuration `environment: 'staging'`; the
browser accepts it only at the exact staging origin. Production configuration is
marked `environment: 'production'` and accepted only at its exact production
origin. Neither environment uses the other's publisher settings as a fallback.
## Legacy native-anchor compatibility

The following applies only to historical `anchor`/`layouts` settings, not the
selected `multiplex-layouts` configuration. The real provider does not open the
local preview dock or apply its CSS to Google's ads. Google owns its actual anchor, dimensions and close UI. Regular
anchors have no supported pixel-height cap; disabling dynamic anchors does not
make their size equal to the synthetic preview.

Once the legacy anchor provider is requested, `ads.js` observes the outer native
`ins.adsbygoogle[data-anchor-status]` container. A visible fixed bottom anchor
sets `--eql-ad-anchor-clearance` on our document root. `layout-reset.css` uses
that value to lift the entire wiki footer (Contribute, Donate, Ads, policy links
and diagnostics) and add matching end-of-page space. The footer's bottom edge
meets the ad container's top edge, with no extra gap. Google's collapse/reopen
tab may overlay the footer, while the ad body stays below it. A collapsed,
removed, hidden or offscreen anchor restores the normal footer position once
its body leaves the viewport. Top anchors,
ordinary in-page units and zero-sized frames do not move the footer.

A decorative, noninteractive `#eql-ad-anchor-backdrop` uses the same measured
height to extend the footer's dark background across the screen behind the ad.
It covers the exposed page background to the left and right of a centered anchor,
has no accessible content or pointer target, and shrinks to zero with the anchor
clearance. It is created only when the permitted provider is requested.

Mutation/resize observers, viewport changes and BFCache restoration remeasure
the outer geometry; pagehide suspends observation. This code never reads ad
iframe contents or resizes, clips, moves or restyles Google elements. If an Off
save fails after Google has loaded, clearance remains active for that document
until it is closed, just like the provider itself. The provider's DOM is external
and can change: verify a real fill, collapse/reopen tab, narrow layouts and the
last article links after deployment. The synthetic checks establish our geometry
handling, not a guarantee about future Google creatives.

This explicit staging path preserves its existing authentication and noindex
policies; it does not require anonymous public reading as the production path
does. It grants no access to the wiki or to Google crawlers. AdSense site
approval, applicable consent setup, the mode-appropriate account formats and Google's
ability to assess protected content remain separate requirements. A configured
loader is not proof of site approval, an ad fill, an impression, or earnings.
Do not remove the staging gate, expose private content, or supply administrator
credentials to a crawler as part of this configuration. Root `ads.txt` publication
and AdSense verification/review are separate from loading the staging code.
Do not generate artificial impressions or clicks as a test.

Verify that all other Auto ads formats and automatic optimization are off before
loading the provider; `data-overlays="collapsed-bottom"` does not disable those
formats. Before activation where required, use Google's supported consent flow
linked below. If approval or crawler access prevents serving, report that blocker
instead of weakening staging isolation. Clear either staging provider setting to
return to the synthetic preview, then reload any open provider document.

Keep `includes/Ads.php` and `resources/ads.js` outside the explicit production-URL
substitution list in `ops/staging-prepare.php`. Those files must retain their
separate production and staging allowlists. Git deployment does not install a
private host-script change; review the installed preparation script separately
if its rewrite list changes.

## Privacy and cookie disclosures

[`ops/privacy-policy.wiki`](../ops/privacy-policy.wiki) is the reviewed authored
source for the public [EQLWiki Privacy and Cookie Policy](https://eqlwiki.com/EQLWiki:Privacy_policy).
It covers site operation, public contributions, browser storage, separate
anonymous/account ad preferences, conditional Google advertising, consent choices
and privacy requests. It explicitly states that advertising is not currently
enabled on the public wiki. Disclosure publication does not activate ads, change
consent controls or publish an announcement.

The published policy's prospective anonymous ad preference still describes the
older browser-session lifetime. The test supplement documents the 24-hour test
behavior. Review and separately publish that disclosure change before any future
production ad activation; a staging source deployment does not update the live policy.

The policy is a database page. Git deployment does not publish this source.
For an approved update, read the target's current revision, take a fresh private
backup and open the [native source editor](https://www.mediawiki.org/wiki/Help:Editing_pages)
from that revision. Preview and review the changes; stop on an edit conflict and
review the newer revision rather than replacing it with a stale draft. After
saving, record the resulting revision and verify the source, rendered policy,
footer link and absence of Google ad/CMP tags and frames on the disclosure page.
Publish and verify staging independently; a staging database refresh can replace
its content. Keep original-page backups private rather than committing them as
policy source. The 9 November 2026 publication used live revision **200533** and
staging revision **200530**, with fresh private backups and native conflict protection.

The forum's [`bb/agreement.txt`](../bb/agreement.txt) has a matching cookie
disclosure and canonical policy link in its final paragraph. Deploy that reviewed
file change normally, preserving every preceding terms paragraph. Do not use the
native SMF admin agreement editor for this disclosure correction: it writes
`agreement_updated` and can require users to accept the terms again. This file-only
change does not alter registration settings, acceptance timestamps or reacceptance
prompts.

Google's CMP privacy policy URL is the public canonical
`https://eqlwiki.com/EQLWiki:Privacy_policy`. The account-side URL was separately
saved and published on 9 November 2026; the existing test message's branding,
consent choices and optimization settings were unchanged. Publishing the wiki
policy or deploying its source does not update that account setting.

[`eql-ads-test-privacy.html`](../eql-ads-test-privacy.html) remains a standalone
supplement for authorized readers of **test.eqlwiki.com** only. It contains no
JavaScript, ad/CMP tags or external resources, links to the canonical policy and
retains the existing staging access gate. Keep it as a test supplement; do not
promote this test-only page to `main`.

Google requires disclosure pages to contain neither consent-requiring scripts nor
the consent-message tag; see its [privacy policy URL instructions](https://support.google.com/adsense/answer/10961370).
Prepare the three-choice message with consent optimization off, then verify its
actual display and revocation control when serving is available. A message assigned
to the root domain also [applies to its subsites](https://support.google.com/adsense/answer/14113511);
the staging-only Google loader remains the deployment boundary. Publishing a
message does not establish site approval, ad fill or legal compliance.

## Future production activation

The current feature review is staging-only; full source promotion to production
is not authorized. Publish `ads.txt` separately if production verification is
needed, keeping the public wiki UI unchanged. Blank provider settings disable
Google loading, but do **not** hide the existing Ads selector or stop its
preference lifecycle if these feature files are later promoted. Review that UI
boundary before any separately approved full production release.

Production manual layouts are not implemented or authorized by this staging change.
The existing legacy path below is retained for reference and is not a recommendation
to switch on shared Auto ads during manual-layout testing. Source deployment alone
does not activate advertising. After separate owner
approval, the production site must be approved in AdSense and configured for
**anchor-only Auto ads**, with all other formats and automatic optimization off.
The loader uses Google's supported `data-overlays="collapsed-bottom"` setting for
regular bottom anchors. This setting enables bottom anchors and overrides their
dashboard position; it does **not** turn off other Auto ads formats. See Google's
[approved bottom-anchor code](https://support.google.com/adsense/answer/7478225).

Only then set the issued public publisher identifier in `$wgEQLAdSenseClient` and
explicitly set `$wgEQLAdSenseFormat = 'anchor'` in ignored production settings.
Both default to empty strings in `skin.json` and the sanitized example and remain
empty during staging-only review. A display-unit slot is not used or required by this
anchor integration. Existing display units need no deletion or account changes
for staging setup. Never invent live IDs or commit account/payment data.

Google controls its actual anchor and dismissal UI. The local preview's placement
above Donate/Ads does not establish that Google's anchor will leave those controls
uncovered. Verify supported-format placement and footer usability before owner
acceptance of production activation; local end padding alone cannot guarantee it.
No custom fixed-position styling is applied to Google's ads. Remove or route any
independently installed loader through the same effective-preference gate before activation; the
selector cannot stop code another extension, database script, tag manager or proxy
independently injects.

The Ads selector controls layout/display. It is not a consent management platform or
permission for personalized advertising. Configure Google's supported consent
flow where required before serving ads; see its
[CMP requirements](https://support.google.com/adsense/answer/13554116) and
[consent setup](https://support.google.com/adsense/answer/7670013).
The root [`ads.txt`](../ads.txt) contains the owner-supplied Google seller record
for publisher `pub-6539165037996565`. Once published on production, confirm
`https://eqlwiki.com/ads.txt` serves that record, then follow Google's
[verification and review steps](https://support.google.com/adsense/answer/12169212).
This public record authorizes the seller and can verify site ownership; it does
not load ads or establish site approval. Ad-serving
configuration remains empty until the separate activation steps above.

## Check traffic and estimate revenue

Use the same date range for production browser pageviews and ad reports.
cPanel [AWStats](https://docs.cpanel.net/cpanel/metrics/awstats/) and raw logs
help separate requests, resources, known crawlers and likely reading. They do
not establish a reliable anonymous human count or wiki login state. Search
Console reports Google search clicks/impressions, not all site visits, internal
pageviews or ad impressions. Its search impressions are not advertising views.

For future browser measurement, collect pageviews with a logged-out/logged-in
classification and ad preference, without names or account identifiers. No
analytics collection is added by this feature. Exclude API/resource requests,
test traffic and staging. Unknown automation and measurement blockers still
make human counts estimates.

Only views whose effective Ads preference is Bottom or Right can earn revenue. Forecast from
anonymous human article pageviews, the fraction with Ads on, the fraction delivering
ads, and measured earnings per thousand of those monetized pageviews. Google
[page RPM](https://support.google.com/adsense/answer/112030) uses AdSense-counted
pageviews; do not substitute raw HTTP requests or multiply ad-impression RPM by
pageviews. If an effective RPM already includes opt-outs/blockers/unfilled views,
do not discount them a second time. Use actual network reports for earnings;
traffic alone cannot establish this site's RPM or future share of ad-enabled visits.

## Checks and release

Run `node ops/test-ads.cjs` with the development guide's jsdom runtime and
`php ops/test-ads-config.php`. These cover synthetic storage/network lifecycles
and server eligibility, including anonymous orientation defaults and fixed
24-hour choice expiry, asserted account
reads/gesture-only writes, queue and identity races, staging-only dock dismissal, footer breakpoint/
reserve contracts, independent staging/production configuration, one-provider-style
loading, responsive Side fallback, migration from the old On value, scroll restoration
records and copied-origin isolation; they do not request real ads or prove live AdSense
approval, impressions, payment, or browser layout. Run JS/PHP syntax checks on
changed sources as well.

Browser checks should include keyboard focus and Space activation, narrow
320px/375px footers, reload/navigation persistence, a second tab, signed-in and
signed-out readers, no provider configuration, an anonymous fresh-choice On
visit, and a new account's Off default. Confirm anonymous opt-out survives
navigation/tabs/browser restarts until its 24-hour deadline without renewing on
reads, verify the deadline with a controlled test clock, and check the login notice;
confirm named-account saves survive a fresh browser without importing anonymous state.
Inspect network requests: Off must make no Google ad request. Use synthetic
provider responses for local lifecycle tests; never generate artificial clicks
or impressions against a live publisher as routine verification.
In staging's synthetic preview mode, scroll a long article to its final links,
check both sides of the 1000px column threshold plus 320px/375px widths, keyboard selection and Off, and
the unobstructed utility footer. Verify there is no Google script, iframe,
request, or `adsbygoogle` unit. With explicit staging provider configuration,
confirm synthetic placeholders stay absent and an Off preference makes no provider
request; verify actual Google placement separately when approval and access allow
serving. Synthetic CSS/DOM checks do not prove rendered
browser geometry or Google's eventual grid dimensions and fill.

Deploy the source through a feature PR to staging, preserving the staging login
gate and separate blank-by-default provider configuration. Review placement with
the synthetic box before separately configuring the staging provider; source
changes do not activate production ads. Promote only reviewed
feature commits after owner approval. Production identifiers, AdSense account setup and
consent configuration are separate activation steps; Git deployment does not
perform them. No schema migration or database-page synchronization is needed.
