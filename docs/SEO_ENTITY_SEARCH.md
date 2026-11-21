# Entity-name and wiki-name search coverage

The intended searches combine an item, NPC or zone name with the names players
use for the game and wiki. The words may appear before or after the entity name.
This document records that target and the metadata design; it does **not** claim
that Google currently indexes or returns all the target pages for these queries.

## Representative query matrix

These twelve rows contain **24 representative queries**: three entity names,
four brand spellings, and both prefix and suffix order. They are a small manual
follow-up set, not instructions to issue bulk Google searches or repeated
indexing requests.

| Entity | Brand spelling | Brand first | Entity first |
| --- | --- | --- | --- |
| Bone Bladed Claymore | EQ Legends | EQ Legends Bone Bladed Claymore | Bone Bladed Claymore EQ Legends |
| Bone Bladed Claymore | EQLegends | EQLegends Bone Bladed Claymore | Bone Bladed Claymore EQLegends |
| Bone Bladed Claymore | EQLegends Wiki | EQLegends Wiki Bone Bladed Claymore | Bone Bladed Claymore EQLegends Wiki |
| Bone Bladed Claymore | EQ Legends Wiki | EQ Legends Wiki Bone Bladed Claymore | Bone Bladed Claymore EQ Legends Wiki |
| Lord Nagafen | EQ Legends | EQ Legends Lord Nagafen | Lord Nagafen EQ Legends |
| Lord Nagafen | EQLegends | EQLegends Lord Nagafen | Lord Nagafen EQLegends |
| Lord Nagafen | EQLegends Wiki | EQLegends Wiki Lord Nagafen | Lord Nagafen EQLegends Wiki |
| Lord Nagafen | EQ Legends Wiki | EQ Legends Wiki Lord Nagafen | Lord Nagafen EQ Legends Wiki |
| Nektulos Forest | EQ Legends | EQ Legends Nektulos Forest | Nektulos Forest EQ Legends |
| Nektulos Forest | EQLegends | EQLegends Nektulos Forest | Nektulos Forest EQLegends |
| Nektulos Forest | EQLegends Wiki | EQLegends Wiki Nektulos Forest | Nektulos Forest EQLegends Wiki |
| Nektulos Forest | EQ Legends Wiki | EQ Legends Wiki Nektulos Forest | Nektulos Forest EQ Legends Wiki |

Expected canonical destinations are:

- Item: [Bone Bladed Claymore](https://eqlwiki.com/Bone_Bladed_Claymore).
- NPC: [Lord Nagafen](https://eqlwiki.com/Lord_Nagafen).
- Zone: [Nektulos Forest](https://eqlwiki.com/Nektulos_Forest).

All three production pages returned HTTP 200 and these self-canonicals during
the November 21, 2026 pre-change check. That establishes the current destinations,
not their inclusion in Google's index or their rank for any query.

## Concise titles and site identity

Use a single concise **EQ Legends Wiki** site-name suffix on eligible article
titles, for example `Bone Bladed Claymore - EQ Legends Wiki`. Keep the entity name
distinctive. Do not append every brand spelling to titles, headings or paragraphs.
Google recommends descriptive titles and concise branding, while its systems
choose the title link shown in a result. See
[Google's title-link guidance](https://developers.google.com/search/docs/appearance/title-link).

The home page's `WebSite` JSON-LD should identify the preferred name as
`EQ Legends Wiki`, its production home URL as `https://eqlwiki.com/`, and these
alternate names:

- `EQLegends Wiki`
- `EverQuest Legends Wiki`
- `EQ Legends`
- `EQLegends`

Keep one consistent `WebSite` identity rather than creating a separate node for
each spelling. The data belongs on the domain home page and its duplicate home
views, not on every item or NPC as though each were a separate website. It
expresses a site-name preference, not a promise of matching or ranking for each
query. Google's site-name system also considers visible home-page information;
see [the official site-name documentation](https://developers.google.com/search/docs/appearance/site-names).

## Factual entity descriptions

Current public NPC descriptions use a concise, category-qualified reference.
Their stat tables do not contain the NPC's own name, so this implementation does
not copy those stats or spawn locations into metadata. Zone descriptions may use
their own direct, title-led introductory paragraph. Embedded item/NPC previews
and image captions are not evidence about the enclosing page's identity. Preserve
authored descriptions and omit unsupported details rather than inventing
mechanics, rarity, drop rates or current-era availability.

Descriptions must remain readable summaries. An array of alternative keyword
spellings is not a useful description. Page-specific structured facts can support
programmatic descriptions, but Google can select other on-page text for its
snippet; see [Google's snippet guidance](https://developers.google.com/search/docs/appearance/snippet).

Changes must preserve canonical destinations, entity names, visible factual
content, article links, item/tooltips and wiki/forum authentication. Staging keeps
its own hostname, access controls and `noindex` policy. Production metadata must
not publish staging entity URLs.

## Verification and the remaining indexing question

On staging, inspect the actual server-rendered title, description, canonical and
home-page JSON-LD. Check that the title has one site suffix, descriptions match
the page's own content, alternate names are valid JSON strings, and staging
protections remain active. Exercise item, NPC and zone pages plus pages outside
those entity types so metadata cannot leak from embedded cards or unrelated
content. Repeat the relevant observations on production after the reviewed
staging release is promoted.

Those checks establish the implementation and index eligibility. They do not
establish that Google indexed a page, selected its canonical, adopted the site
name, or returned it for a query in the matrix. Use fresh URL Inspection evidence
and the submitted-sitemap indexing reports to track those outcomes separately.
Record crawl dates and query observations when comparing results over time;
do not label a fixture test or a passing live fetch as recovered search coverage.
