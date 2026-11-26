# Item indexing investigation, November 26, 2026

## What Google reports

The November 22 Search Console report showed **10 indexed pages**, **17,337
crawled but currently unindexed pages**, and **19,979 discovered but currently
unindexed pages**. These totals include URL types beyond canonical item articles.
The validation for the crawled/unindexed group had failed. That status does not
identify a particular application bug or prove a ranking penalty.

Ghoulbane's index inspection still described its August 26 crawl: successful
fetch, crawling and indexing permitted, Google-selected canonical equal to the
inspected URL, and no user-declared canonical in that historical response.
Google's November 26 live test fetched and rendered the current page correctly,
including its branded title and visible item summary. The live result said the
page could be indexed; it did not say the page was indexed.

Bone Bladed Claymore had a newer recorded crawl, November 21 at 10:22:49 AM
America/New_York, after the preceding release. It remained excluded despite a
successful fetch, permitted indexing and the correct explicit self-canonical.
Therefore deployment and crawl success have **not** established recovery.
Both the Manual actions and Security issues reports showed no issues detected.

See Google's [Page indexing report guidance](https://support.google.com/webmasters/answer/7440203)
for the distinction between exclusion reasons and evidence of a particular cause.

## Findings that do not explain a universal exclusion

Ghoulbane's public response returns HTTP 200 with one own item card, an accurate
title and self-canonical, and no `noindex`. Its complete stat/effect/source/quest
content is present before JavaScript runs. Actual rendered links from Upper Guk,
the froglok shin lord and The Fiery Avenger point to it normally. The public API
lists eleven incoming main-namespace pages. Its sitemap child is processed
successfully and contains the article.

Compact item references are not inherently empty: Ghoulbane, Claymore and Flowing
Black Silk Sash contain approximately 134, 122 and 100 readable article words.
The earlier indexed sample includes a 70-word Sentinel Veldrendyr reference with
unknown fields. The [earlier cohort comparison](SEO_COMPARISON_2026-11.md) also
contains substantial excluded guides. These observations do not establish a
preferred word count, a universal orphaning problem, or an explanation based on
the mere presence of missing-data text. Do not invent game facts or add prose
solely to reach an assumed length threshold; see Google's
[helpful-content guidance](https://developers.google.com/search/docs/fundamentals/creating-helpful-content).

A private, read-only aggregate of the current November 26 HTTPS access log
identified 407 requests from Googlebot addresses verified against Google's
published IP ranges: 402 returned HTTP 200 and five returned redirects. None in
that window returned 4xx or 5xx. Googlebot fetched Ghoulbane again at 21:37:05 UTC
with HTTP 200, after the priority request. This is a limited log window, not proof
that historical errors never occurred or that the fetched article was accepted
into the index. Raw visitor records and IP addresses are not part of this report.

## Measurable discovery and response gaps

The native sitemap XML is valid: UTF-8, correct namespace, absolute HTTPS apex
URLs, appropriate escaping, and files below the protocol limits. Last-modified
values reflect transcluded-template invalidation through `page_touched`, so the
claim that template changes never update sitemap dates is not supported.

However, the native REST generator includes redirect pages by default. The
November 26 sitemap advertised **4,545 redirect aliases among 28,576 URLs**,
approximately 15.9%. Its REST handler did not request the generator's existing
`skipRedirects()` option. These are additional noncanonical URLs, not additional
articles. Google's [sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap)
recommends listing preferred canonical URLs. Filtering redirects improves this
signal without deleting aliases or changing their normal redirects.

Several active custom special-page endpoints bypass normal output or return
before native special-page header handling. Their bare tooltip/list/builder
responses can consequently lack explicit indexing exclusion. They are auxiliary
responses rather than independent article destinations. An HTTP indexing header
can preserve their existing markup and clients while excluding these standalone
responses. This gap is not evidence that Google selected them instead of the
article: the inspected item canonicals did not show that outcome, and the current
verified log sample did not establish tooltip crawl waste.

Native REST article HTML is another duplicate representation without an explicit
canonical/noindex response in the sampled request. It remains native platform
behavior in this release; do not replace an unrelated REST module or block
rendering resources as an incidental SEO change.

## Reader summary gap

The generated item description previously prioritized generic wording, skill,
slot, damage/delay and one linked zone. It omitted distinctive documented facts:
Flowing Black Silk Sash's explicit haste, and Ghoulbane's effect and class.
Using those unambiguous facts from the matching own card makes the summary more
useful. It does not provide new evidence of game mechanics, and it must never
borrow values from linked spell cards, unrelated item previews or ambiguous
fields. Google's [snippet guidance](https://developers.google.com/search/docs/appearance/snippet)
permits accurate page-specific programmatic descriptions; Google may choose a
different snippet.

## Release and search-account verification

Test the reviewed code in isolation and against actual staging output before
promoting only these changes to production. Keep staging access restrictions,
its own canonical hostname, `noindex` and disabled public sitemap intact. An
isolated read-only staging database check can exercise sitemap filtering without
enabling public staging discovery. File deployment does not publish wiki pages
or install private host helpers.

After production verification, resubmit the sitemap index and nonempty article
sitemaps through Search Console. This requests broad discovery/refresh; it cannot
force Google to crawl or index every article immediately. Do not submit thousands
of repeated URL Inspection requests or use the restricted Indexing API for
ordinary wiki articles. See Google's [recrawl guidance](https://developers.google.com/search/docs/crawling-indexing/ask-google-to-recrawl).

Record actual staging, production and Search Console observations in the release
PR. Passing tests, cleaner sitemap contents, an accepted submission, a fresh crawl
and recovered search coverage are separate outcomes. Google's underlying reason
for continued article exclusion remains unconfirmed by the available evidence.
