# Optional display ads

EQLImmersive places an **Ads** checkbox immediately after **Donate** in the
fixed footer. Ads are **off by default**, including for signed-in readers.
Checking it saves an explicit browser preference; unchecking it saves Off.
The preference applies to this wiki in this browser across navigation and login.
It is not saved to a wiki account or synchronized across devices, and does not
copy or change any existing anonymous/account feature state. Clearing browser
storage returns to Off. If persistent storage is unavailable, a session fallback
can retain the choice in that tab. A verified, first-party Off-only cookie can
veto stale opt-ins if storage rejects an opt-out. It carries no account or
tracking identifier; a later explicit opt-in must clear it successfully.
Unreadable state cannot establish an opt-in. If every storage mechanism rejects
an opt-out, the current document stays Off and explains that the choice could
not be saved; close that tab to end an already loaded provider. It does not
reload into the old opt-in.

`resources/ads.js` owns the preference and Google loader. No Google advertising
script, iframe, or ad request is created until the reader opts in **and** the
server publishes valid production ad configuration. The checkbox can remember
an opt-in before a provider is configured, without creating an empty ad area.
Unchecking hides the unit immediately and, when Off can be retained, reloads the
page if the Google script has been requested, ending that document's advertising
runtime. A change
in another tab is also observed. Browser ad blockers are respected.

The first integration is one labelled, responsive Google AdSense display unit
after the article. There are no ads on login/account/special pages, talk pages,
editing/history/diff/old-revision views, missing or nonpublic articles, staging,
or local clones. `includes/Ads.php` checks the actual environment, title and
view before exposing configuration. Google code is not placed in the footer,
database `MediaWiki:` scripts, or a global page head.

![Synthetic preview of the Ads checkbox beside Donate](images/ads-footer-preview.png)

## Activate an approved ad unit

Source deployment alone does not activate advertising. The site needs an approved
AdSense account/site and an owner-configured responsive display ad unit. See
Google's [display unit setup](https://support.google.com/adsense/answer/9274025/)
and [responsive parameters](https://support.google.com/adsense/answer/9183460).
Add the issued public publisher and slot identifiers to ignored production
settings using `$wgEQLAdSenseClient` and `$wgEQLAdSenseSlot`. Both default to empty
strings in `skin.json` and the sanitized settings example. Never invent live IDs,
commit account/payment data, or enable the configuration on staging.

Keep AdSense Auto ads disabled for this site so placements remain limited to the
explicit unit. Remove or route any independently installed ad loader through the
same opt-in before activation; the checkbox cannot stop code another extension,
database script, tag manager or proxy independently injects.

The Ads checkbox controls display. It is not a consent management platform or
permission for personalized advertising. Configure Google's supported consent
flow where required before serving ads; see its
[CMP requirements](https://support.google.com/adsense/answer/13554116) and
[consent setup](https://support.google.com/adsense/answer/7670013).
Use the account's actual instructions for site verification and `ads.txt`; do
not publish a fabricated seller record.

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

With default-off ads, only opted-in views can earn revenue. Forecast from
anonymous human article pageviews, the fraction opted in, the fraction delivering
ads, and measured earnings per thousand of those monetized pageviews. Google
[page RPM](https://support.google.com/adsense/answer/112030) uses AdSense-counted
pageviews; do not substitute raw HTTP requests or multiply ad-impression RPM by
pageviews. If an effective RPM already includes opt-outs/blockers/unfilled views,
do not discount them a second time. Use actual network reports for earnings;
traffic alone cannot establish this site's RPM or future opt-in rate.

## Checks and release

Run `node ops/test-ads.cjs` with the development guide's jsdom runtime and
`php ops/test-ads-config.php`. These cover synthetic storage/network lifecycles
and server eligibility; they do not request real ads or prove live AdSense
approval, impressions, payment, or browser layout. Run JS/PHP syntax checks on
changed sources as well.

Browser checks should include keyboard focus and Space activation, narrow
320px/375px footers, reload/navigation persistence, a second tab, signed-in and
signed-out readers, no provider configuration, and a clean-storage Off visit.
Inspect network requests: Off must make no Google ad request. Use synthetic
provider responses for local lifecycle tests; never generate artificial clicks
or impressions against a live publisher as routine verification.

Deploy the source through a feature PR to staging, preserving the staging login
gate and disabled ad configuration. Promote only reviewed feature commits after
owner approval. The private production identifiers, AdSense account setup and
consent configuration are separate activation steps; Git deployment does not
perform them. No schema migration or database-page synchronization is needed.
