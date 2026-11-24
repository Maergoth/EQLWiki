# Optional GA4 audience measurement

This integration measures pageviews, acquisition and active engagement on eligible
public production articles, categories and Help pages. Signed-in and logged-out
readers are included independently of their Ads X / 1 / 2 choice. The separate
Analytics preference, Google consent choices, Global Privacy Control and blockers
still limit collection. Reports describe permitted, unblocked measured visits,
not every human reader or request. GA4 cannot reconstruct earlier engagement.

## Ownership and eligibility

`skins/EQLImmersive/includes/Analytics.php` owns independent measurement admission
through `Analytics::independentConfigurationForOutput($out, $publicRead)` and
first-party controls through `Analytics::preferencesForOutput($out)`. The skin
hook publishes `wgEQLAnalyticsPreferences` and loads
`skins.EQLImmersive.analyticsPreferences` wherever the configured production skin
renders, including the privacy policy and account pages. This preference-only
module makes no Google requests. It adds the **Analytics settings** footer link
and dialog; availability of that control is not permission to measure the page.

Only eligible reading views receive `wgEQLAnalytics` and the
`skins.EQLImmersive.analytics` module. Its public configuration contains
`measurementId`, `consentPublisher`, `origin`, `pageLocation`, `pageTitle` and
`pageNamespace`. The optional `EQLAnalyticsMeasurementId` and
`EQLAnalyticsConsentPublisher` settings both default to empty. Both must be valid,
and `Server` and `CanonicalServer` must exactly equal `https://eqlwiki.com`.
The browser checks that origin again. There is no staging ID, publisher or fallback.

Eligible pages are current public reading views in `NS_MAIN`, `NS_CATEGORY` or
`NS_HELP`. Admission checks anonymous read access and public visibility of revision
text, not just the current user's permissions. Missing, redirect, suppressed,
private and noindex pages are excluded, as are User, Talk, Special/account, editing,
old revision/diff, forum and nonproduction pages. The privacy policy offers the
first-party Analytics control but loads no Google measurement or consent tag.
Analytics eligibility is independent of the narrower advertising eligibility;
this change does not add ads to categories or Help pages.

`resources/analytics-preferences.js` owns the browser-only choice and publishes
`eql.analytics.state`; `resources/analytics.js` consumes that state. The absence
of an opt-out defaults audience measurement to enabled, subject to consent and
the remaining gates. Disabling it writes `eql_analytics_opt_out=1` for one year,
with host-only scope, `Path=/`, Secure and SameSite=Lax. Enabling it removes that
cookie. An unreadable preference, a present malformed opt-out or Global Privacy
Control keeps measurement off. The choice is not stored in a wiki account and
does not change on sign-in or sign-out. An optional localStorage timestamp only
notifies other tabs to reread the cookie; it is not an identifier or preference.

`resources/ads.js` remains the sole owner of ad preferences and ad loading. Its
24-hour anonymous preference, named-account defaults and explicit account saves
are unchanged. **Ads X does not disable Analytics or its consent manager.**
The Analytics module never loads `adsbygoogle` or requests an ad.

## Consent, collection and stopping

The analytics module loads the account-issued standalone Google Privacy &
messaging tag when its own browser preference allows it. It reuses an existing
runtime when present, including one supplied by AdSense. Google documents the
[messaging tag](https://support.google.com/adsense/answer/11575177) and its use for
[European regulations consent without an ad-blocking recovery message](https://support.google.com/adsense/answer/14325056).
Do not add a recovery message, error-protection script or an ad loader to obtain
analytics consent. The messaging request uses an origin-only referrer; Google's
[consent delivery guidance](https://support.google.com/adsense/answer/10960768)
requires a usable referring origin.

Default-enabled audience measurement is not itself consent. Configure analytics
consent support in AdSense's European regulations settings and preserve the
existing three-choice message and revocation entrypoint. These are account-side
actions, separate from Git deployment. See Google's
[consent-mode settings](https://support.google.com/adsense/answer/16053245) and
[Privacy & messaging API](https://developers.google.com/funding-choices/fc-api-docs).
The module waits for `CONSENT_MODE_DATA_READY` and reads
`googlefc.getGoogleConsentModeValues().analyticsStoragePurposeConsentStatus`.
Only `GRANTED` or explicitly `NOT_APPLICABLE` permits the Google Analytics tag;
`UNKNOWN`, `DENIED`, `NOT_CONFIGURED` and missing/failed APIs fail closed. There
is no geography guess or timeout that turns an unknown result into permission.
No Analytics script, pageview or cookieless Analytics ping is sent while waiting.
The consent manager itself may communicate with Google to deliver those choices.
A blocker that prevents its response leaves Analytics off.

Before loading the remote Analytics tag, the module queues global sanitized
page fields, privacy flags, cookie restrictions and `send_page_view: false`.
The GA disable flag remains set throughout bootstrap because Google's destination
can initialize before the script's `load` event and ignore a later duplicate
configuration. After loading, the module rechecks permission, configures the
destination with automatic pageviews still disabled, then enables collection and
sends one explicit canonical `page_view`. Subsequent normal engagement uses those
safe defaults. Do not move these defaults into the load callback. See Google's
[tag setup](https://developers.google.com/tag-platform/gtagjs),
[configuration precedence](https://developers.google.com/tag-platform/gtagjs/reference)
and [manual pageviews](https://developers.google.com/analytics/devguides/collection/ga4/views).

The initial consent readiness callback is not a subscription to later choices;
the module also observes consent changes. Reopening the choices or withdrawing
consent disables collection and latches it off for the remainder of that document,
preventing a late callback from restoring it. A new eligible page can evaluate a
new choice. The first completed decision and initial TCF listener snapshot are
not treated as later revocations, regardless of callback order.

Saving **Analytics settings** stops measurement first. A successfully saved choice
reloads the page; a failed save keeps it off in that tab and reports the failure,
without reloading into an older choice. Other tabs recheck on a storage signal,
focus or restoration. Already-open tabs may continue until that recheck; reload
or close them to end their loaded code. Identity changes also stop the current
document so an old context cannot resume, but signed-in readers are not excluded
on subsequent pages. Stopping collection does not erase sent data or delete
existing Analytics cookies. Ads settings and Google consent choices remain
separate controls.

Only canonical public page location/title and ordinary GA pageview/engagement
data are used. For acquisition, the external referring site's HTTP(S) origin is
sent without its path, query string, fragment or credentials. Wiki and subdomain
referrers are omitted. The integration sends no wiki username, account ID, email,
page query string or fragment. Do not add account-linked custom dimensions,
search terms or arbitrary event payloads without another privacy review.
Google Signals, user-provided data collection, granular location/device collection
and analytics advertising personalization stay disabled in the property. Keep
enhanced measurement Off so automatic form, search, link, video, download or
history-change parameters cannot bypass these exclusions.

Also disable all six options under the Google tag's **Manage automatic event
detection** and turn **Allow user-provided data capabilities** Off in its tag
settings. These are separate from GA4's Enhanced measurement and property-level
user-provided data settings; verify both layers. A new tag/container may enable
those capabilities independently. Useful basic traffic reports do not require
advertising identity features, account identifiers or those automatic fields.

GA cookies use the stable `cookie_prefix: 'eql'`, producing `eql_ga` and
`eql_ga_<stream suffix>`, with host-only scope, Secure and SameSite=Lax flags.
Both receive `cookie_expires: 2592000` and `cookie_update: false`. The browser
identifier cookie `eql_ga` retains its original 30-day expiry. Google's session
state cookie `eql_ga_<stream suffix>` can instead renew to 30 days from activity;
do not describe that provider-managed cookie as having a fixed deadline.
Production verification on 24 November 2026 observed an unchanged browser-cookie
expiry while the session-cookie expiry advanced by 50 seconds between visits.
The synthetic tests check supplied settings, not Google's cookie-update behavior.

These cookies are distinct from the one-year Analytics opt-out and the Ads
preference's fixed 24 hours. The prefix prevents reuse of existing generic
Analytics cookies. Older cookies can remain until expiry or removal; this
integration does not renew or delete them. Do not intercept or rewrite Google's
session cookie to enforce another expiry. Configure the GA4 property for
two-month user/event data retention and disable reset on new activity. This
account setting does not impose the same deletion schedule on standard aggregate
reports; see [Google's retention rules](https://support.google.com/analytics/answer/7667196).

## Configuration and release

Prepare the GA4 property/web stream, standalone messaging tag and consent settings
before activation. Use the issued values in ignored production settings:

```php
$wgEQLAnalyticsMeasurementId = 'G-<issued measurement ID>';
$wgEQLAnalyticsConsentPublisher = 'pub-<issued 16-digit publisher ID>';
```

The examples are deliberately invalid. Blank/invalid configuration leaves the
feature off. The separate publisher setting does not inherit the ad configuration
or enable ads. Source deployment alone does not create a property, accept Google's
terms, publish a consent message, set retention or install private host settings.
Do not commit credentials or report exports. Follow the existing host deployment
lock, fresh private backup and health checks for separately approved production
settings. Keep staging/local settings empty, including after production refreshes.

The reviewed [`ops/privacy-policy.wiki`](../ops/privacy-policy.wiki) is authored
source, not the live database page. Before broadening collection, merge its
independent Analytics disclosure into the freshly read live
`EQLWiki:Privacy_policy`, retaining newer live-only disclosures. Publish with the
current revision, private backup, preview and edit-conflict protection described
in [the ads guide](ADS.md#privacy-and-cookie-disclosures). Verify saved source,
rendering and the first-party-only settings control on that page. A source commit
is not proof of publication or consent-message delivery. Leave News unchanged.

## Verification and reports

Run `node ops/test-analytics.cjs`, `node ops/test-analytics-preferences.cjs`,
`php ops/test-analytics-config.php`, the existing `node ops/test-ads.cjs` and
`php ops/test-ads-config.php`, plus JS/PHP syntax checks
for changed files. Analytics fixtures cover independent public-page admission,
exact origin, Ads X and signed-in/out participation, the browser opt-out,
preference-only excluded pages, standalone CMP loading, unknown/denied/unconfigured
consent, delayed callbacks, withdrawal, Global Privacy Control, acquisition-field
sanitization and supplied cookie restrictions. The bootstrap fixture models a
destination starting before `load`, ignoring duplicate configuration and trying
its default pageview; it verifies no early events or cookies and one explicit
sanitized view. These synthetic responses do not prove live Google collection.

Before claiming activation, inspect an authorized production visit with Ads X:
permitted Analytics should send the intended events without an advertising tag or
ad request. Inspect the first request and later engagement for sanitized page and
referrer fields and disabled personalization. Confirm secure host-only cookies,
fixed browser-cookie expiry and the provider's session-cookie renewal. Verify
signed-in and anonymous public articles/categories/Help, and the first-party
control on the tag-free policy page. Analytics opt-out, Global Privacy Control,
excluded pages and unknown/declined consent must not collect. Check a late response
after opt-out, withdrawal and blocked providers. Use synthetic fixtures for
repeated scenarios; do not generate real ad impressions as a routine test.
Confirm reception in Realtime, then allow processing before standard reports.

Use GA4's traffic acquisition, Engagement overview and Pages and screens reports
for sources, page popularity, active users, sessions and reading time after
activation. Average engagement measures time the site is in focus; distinguish
per-active-user and per-session denominators. Compare consistent dates/devices
and label this as permitted, unblocked measured traffic, including Ads Off readers.
The broader cohort starts with this release; earlier Ads-enabled-only reports are
not directly comparable and cannot be backfilled. Reading time cannot be inferred
from AdSense viewability, ad request counts or server logs. See Google's
[engagement measurement](https://support.google.com/analytics/answer/11109416),
[Engagement overview](https://support.google.com/analytics/answer/13391283) and
[Pages and screens](https://support.google.com/analytics/answer/12926732).
