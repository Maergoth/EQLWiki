# Optional GA4 engagement measurement

This integration measures pageviews and active engagement for a limited sample:
eligible public production articles whose readers have Ads enabled and whose
analytics consent permits collection. It is not a count of all wiki readers,
logged-out humans, or all visits. Named accounts default Ads Off; declined consent,
blockers, excluded pages and staging are absent from this sample. GA4 cannot
reconstruct engagement before collection started.

## Ownership and eligibility

`skins/EQLImmersive/includes/Analytics.php` owns
`Analytics::configurationForOutput($out, $adConfig, $publicRead)`. The skin hook
publishes `wgEQLAnalytics` and loads the `skins.EQLImmersive.analytics` module only
when configuration and page eligibility pass. Its public configuration contains
`measurementId`, `origin`, `pageLocation` and `pageTitle`.

The optional `$wgEQLAnalyticsMeasurementId` defaults to an empty string. Only the
exact production `Server` and `CanonicalServer`, `https://eqlwiki.com`, can enable
this feature, and the browser checks that origin again. There is no staging
analytics ID or fallback. Real production ad configuration and public reading
must also be available; previews and a copied production ID on another host do
not qualify. Current public main-namespace article views use the existing ad
exclusions: no category, special/account, Talk, editing, old revision/diff,
missing/redirect, nonpublic or noindex pages. The privacy disclosure remains free
of Google tags. This change does not expand advertising eligibility.

`resources/ads.js` remains the sole reader/writer of the anonymous and named-user
ad preference. Its `eql.ads.state` hook publishes the effective enabled boolean;
`resources/analytics.js` consumes that state rather than duplicating cookie or
account-option logic. Ads X, account default-Off, identity changes, unreadable
preferences and a browser Global Privacy Control signal veto analytics. The
existing 24-hour anonymous preference and account save boundaries are unchanged.

## Consent, collection and stopping

The existing conditional AdSense loader supplies Google's Privacy & messaging
runtime. An enabled ad style does not grant analytics consent. The analytics
module uses Google's consent-mode values and waits for a known analytics result
before loading the Google tag. Only `GRANTED` or explicitly `NOT_APPLICABLE`
permits loading; `UNKNOWN`, `DENIED`, `NOT_CONFIGURED` and missing/failed APIs
fail closed. No analytics script, pageview or cookieless analytics ping is sent
while waiting for that decision. An ad blocker that prevents the CMP from
loading also leaves analytics off.

Before loading the remote tag, the module queues global sanitized page fields,
privacy flags, cookie restrictions and `send_page_view: false`. The GA disable
flag remains set throughout bootstrap because Google's destination can initialize
before the script's `load` event and ignore a later duplicate configuration.
After loading, the module rechecks permission, configures the destination with
automatic pageviews still disabled, then enables collection and sends one explicit
canonical `page_view`. Subsequent normal engagement uses those safe defaults.
Do not move these defaults into the load callback. See Google's
[tag setup](https://developers.google.com/tag-platform/gtagjs),
[configuration precedence](https://developers.google.com/tag-platform/gtagjs/reference)
and [manual pageviews](https://developers.google.com/analytics/devguides/collection/ga4/views).

Configure analytics consent support in AdSense's European regulations settings;
this is an account-side action, separate from Git deployment. Preserve the
existing three-choice message and revocation entrypoint. See Google's
[consent-mode settings](https://support.google.com/adsense/answer/16053245) and
[Privacy & messaging API](https://developers.google.com/funding-choices/fc-api-docs).
The initial readiness callback alone is not a subscription to later choices;
the module also observes consent changes. Reopening the consent choices or
withdrawing consent disables collection and latches it off for the remainder of
that document, preventing a late callback from restoring it. A new eligible page
can evaluate a new consent choice. The first completed consent decision and the
initial TCF listener snapshot are not treated as later revocations, regardless
of which readiness callback arrives first.

An Off or invalid preference disables GA collection immediately. A successfully
saved Off keeps the existing reload boundary for an already-loaded ad provider.
Other open tabs recheck their own preference state. Stopping collection does not
erase sent data or delete existing cookies; the disclosure explains browser
cookie removal and Google consent choices separately.

Only canonical public article location/title and ordinary GA pageview/engagement
data are used. The integration sends no wiki username, user ID, email address,
URL query string, fragment or referrer address. Do not add account-linked custom
dimensions, search terms or arbitrary event payloads without another privacy
review. Google Signals, user-provided data collection, granular location/device
collection and analytics advertising personalization stay disabled in the
property. Keep enhanced measurement Off so automatic form, search, link, video,
download or history-change parameters cannot bypass these exclusions.

Also disable all six options under the Google tag's **Manage automatic event
detection** and turn **Allow user-provided data capabilities** Off in its tag
settings. These tag-level settings are separate from GA4's Enhanced measurement
and property-level user-provided data settings; verify both layers. A newly
created tag/container may enable those capabilities independently. The skin
does not enable them or send user-provided data.

GA cookies use a host-only scope and a fixed 30-day lifetime
(`cookie_expires: 2592000`, `cookie_update: false`), distinct from the Ads
preference's 24 hours. Configure the GA4 property for two-month user/event data
retention and disable reset on new activity. This account setting does not impose
the same deletion schedule on standard aggregate reports; see
[Google's retention rules](https://support.google.com/analytics/answer/7667196).

## Configuration and release

Prepare the GA4 property/web stream and consent settings before activation. Use
the issued measurement ID in ignored production settings:

```php
$wgEQLAnalyticsMeasurementId = 'G-<issued measurement ID>';
```

The example is deliberately not a valid live ID. Blank/invalid configuration
leaves analytics off. Source deployment alone does not create a property, accept
Google's terms, enable consent-mode settings, set retention or install this
private host setting. Do not commit account credentials or report exports.
Follow the existing host deployment lock, fresh private backup and health-check
procedure for the separately approved production setting. Keep staging/local
settings empty, including after production data refreshes.

The reviewed [`ops/privacy-policy.wiki`](../ops/privacy-policy.wiki) is authored
source, not the live database page. Before activation, merge its analytics
disclosure into the freshly read live `EQLWiki:Privacy_policy`, retaining newer
live-only disclosures. Publish with the current revision, private backup,
preview and edit-conflict protection described in [the ads guide](ADS.md#privacy-and-cookie-disclosures).
Verify both the saved source and rendered policy. A source commit is not proof
of publication or consent-message delivery. No News announcement is required
by this integration.

## Verification and reports

Run `node ops/test-analytics.cjs`, `php ops/test-analytics-config.php`, the existing
`node ops/test-ads.cjs` and `php ops/test-ads-config.php`, plus JS/PHP syntax checks
for changed files. The analytics fixtures cover eligibility/configuration, exact
origin, consent/state races, Off/default-Off, delayed callbacks, withdrawal,
single initialization, Global Privacy Control and the outgoing configuration's
data/cookie restrictions. The bootstrap fixture models a destination starting
before `load`, ignoring duplicate configuration and trying its default pageview;
it verifies no early events or cookies, one explicit sanitized view, and Off or
withdrawal during bootstrap. This local model is not Google's runtime.
They use synthetic provider responses and do not prove live Google collection.

Before claiming activation, inspect one authorized production visit: a valid
consented enabled state should send the intended events with sanitized page
fields and disabled personalization; inspect the first request as well as later
engagement and confirm host-only fixed-expiry cookies. Off and excluded pages
should send none. Verify initial unknown/declined
consent, a late response after Off, withdrawal, named-user defaults and blocked
providers. Use synthetic fixtures for repeated scenarios; do not generate real
ad impressions as a routine test. Confirm the actual property receives data in
Realtime, then allow processing before reading standard reports.

For reading time, use GA4's Engagement overview or Pages and screens report and
select the period after activation. Average engagement time measures time the
site is in focus; distinguish per-active-user and per-session denominators.
Compare consistent dates/devices and label the measured ads-enabled sample.
It cannot be inferred from AdSense viewability, ad request counts or server logs.
See Google's [engagement measurement](https://support.google.com/analytics/answer/11109416)
and [Engagement overview](https://support.google.com/analytics/answer/13391283).
