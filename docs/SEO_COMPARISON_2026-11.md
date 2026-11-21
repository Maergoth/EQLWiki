# Indexed and excluded page comparison, November 2026

This comparison uses the November 15, 2026 Search Console Page indexing snapshot
and public production HTML fetched on November 21. The report contained **11
indexed URLs** and **18,216 crawled, currently unindexed URLs**. Eleven indexed
examples and eleven excluded examples were sampled, including one historical
indexed `www` alias that now redirects to its apex article. This is a small observational
sample, not an explanation of Google's internal indexing decisions.

## Method and limits

The comparison counted readable text and links inside the server-rendered
`.mw-parser-output`, inspected canonical and robots metadata, and checked for
visible unfinished content. Counts exclude scripts, styles, navigation and most
page chrome. They are approximate markup measurements, **not minimum word or link
requirements**. Dynamic interfaces and images can provide useful content without
much server-rendered prose.

All twenty-one directly sampled apex articles returned HTTP 200, one HTTPS
self-canonical, and no `noindex` meta directive or response header. The additional
historical `www` alias returned HTTP 308 to an apex article with the same successful
indexability signals. No directly sampled page included JSON-LD. Bone Bladed
Claymore and the current Legionnaire's Mancatcher destination had explicit item
descriptions. These signals did not separate the indexed and excluded cohorts.

The samples were current ordinary public requests. They do not reproduce the
historical HTML received by Googlebot, prove that Google's latest report reflects
today's content, or establish a ranking penalty. Google recommends inspecting
important canonical URLs rather than trying to index every URL variant; see the
[Page indexing report documentation](https://support.google.com/webmasters/answer/7440203).

## Representative observations

The link column counts distinct crawlable internal article destinations inside
the sampled article body. It does not measure incoming links or link quality.

| Report cohort | Public page | Approximate server text words | Distinct article links | Observation |
| --- | --- | ---: | ---: | --- |
| Indexed | [Plane of Sky](https://eqlwiki.com/Plane_of_Sky) | 24,047 | 635 | Extensive progression and quest material; some unfinished description text also appears. |
| Indexed | [Befallen](https://eqlwiki.com/Befallen) | 6,815 | 178 | Substantial zone material and loot links. |
| Indexed | [Class Race Quest List](https://eqlwiki.com/Class_Race_Quest_List) | 1,093 | 209 | Structured quest index with an introduction and an unfinished contribution request. |
| Indexed | [The froglok king](https://eqlwiki.com/The_froglok_king) | 191 | 11 | Compact stat block, loot and related information. |
| Indexed | [Sentinel Veldrendyr](https://eqlwiki.com/Sentinel_Veldrendyr) | 70 | 6 | Out-of-era notice, unknown stat values, and image/description placeholders. |
| Indexed | [Equipment By Class](https://eqlwiki.com/Equipment_By_Class) | 24 | 1 | Two short introductory paragraphs plus the client-rendered equipment builder. |
| Indexed | [Zone Connection World](https://eqlwiki.com/Zone_Connection_World) | 0 | 0 | A server-rendered connection-map image with descriptive alt text; JavaScript enhances map sizing and magnification. Zero counted prose does not mean an empty page. |
| Indexed historical alias | [Legionnaire's Mancatcher, www](https://www.eqlwiki.com/Legionnaire%27s_Mancatcher) | Not counted | Not counted | Search Console's example was last crawled July 15. It now redirects with HTTP 308 to the HTTPS apex item, which returns 200 with a correct canonical, an item description and no missing-lore instructions. |
| Excluded | [Bone Bladed Claymore](https://eqlwiki.com/Bone_Bladed_Claymore) | 129 | 6 | Factual item introduction, stats and drop sources; also exposes missing-lore editing instructions. |
| Excluded | [Character Classes](https://eqlwiki.com/Character_Classes) | 177 | 19 | Clear Legends-specific introduction and links to the sixteen classes. |
| Excluded | [Updated Zones](https://eqlwiki.com/Updated_Zones) | 449 | 38 | Legends-specific update explanation and dated zone changes. |
| Excluded | [Nektulos Forest](https://eqlwiki.com/Nektulos_Forest) | 5,922 | 228 | Substantial zone content, including the same kind of unfinished description marker seen on indexed zone pages. |
| Excluded | [Patch Notes](https://eqlwiki.com/Patch_Notes) | 20,020 | 404 | Extensive dated material with explicit official-source attribution. |
| Excluded | [Alanna's Race Unlock Guide](https://eqlwiki.com/User:Alanna/Alanna%27s_Race_Unlock_Guide) | 9,849 | 334 | Detailed Legends-specific achievement and faction instructions. |
| Excluded | [A phantom](https://eqlwiki.com/A_phantom) | 483 | 19 | Out-of-era notice and image/description placeholders, alongside substantial stat and loot data. |

The other indexed samples were Main Page, The Torrid Corruptor and Zimel's Blades
(SoulFire). The other excluded samples were Zones, Something Fishy, Ak'Anon and
Steamfont Mountains. All had the same successful public fetch and indexability
signals described above.

The Legionnaire's Mancatcher row also illustrates report age: a historical indexed
example can now be a redirect. Its current destination is indexable, but that does
not prove the historical alias remains the URL Google will serve. The text/link
counts above cover the ten directly sampled indexed apex articles and eleven
excluded articles; the alias check covered the current redirect and destination
metadata separately.

These counterexamples rule out a simple diagnosis based on article length,
presence of a placeholder, raw link count, absence of structured data, or an
explicit mention of the game name. Short and unfinished pages occur in the
indexed sample, while substantial, useful guides occur in the excluded sample.

## Existing content links

Additional article-body link checks found that indexed **Main Page** already links
directly to excluded Zones, Character Classes, Updated Zones and Patch Notes.
Indexed **Befallen** contains five direct links to Bone Bladed Claymore. Thus the
sample does not support treating these particular pages as orphaned articles.
This observation does not establish that every wiki article is well connected.

One concrete discovery limitation appears on **Equipment By Class**. The compared
page revision, 162217, contains two introductory paragraphs, a link to
Category:Items, and the `eql-equipment-trio-builder` container. Its class-combination
search is supplied by JavaScript, while the article source has no ordinary links
to the individual class equipment categories. The page being indexed does not
make its interactive search a substitute for a browseable article-link path.

## Scoped improvements

The practical changes selected from this review address reader accuracy and crawl
hygiene, rather than trying to imitate whichever pages happen to be indexed:

- The reviewed [`Equipment By Class` source](../ops/equipment-by-class.wiki)
  preserves the existing introduction, Category:Items link and builder container,
  then adds sixteen ordinary links to the existing class equipment categories.
  Four compact text rows can wrap naturally on narrow screens. This gives readers
  and crawlers a class-based browsing path without running or changing the
  equipment search. It does not assert that previously linked articles were
  orphaned or that adding the links guarantees indexing.
- Empty optional item-template fields should communicate that information is
  unknown. A blank source field alone does not establish that an item cannot be
  purchased, has no related quests, or has no other relationships. Preserve
  explicit authored information and existing template/DOM contracts.
- Missing item lore should have compact, honest reader copy. Raw template editing
  instructions do not help someone looking up an item. Keep the missing-lore
  maintenance category and an appropriate contribution path without inventing
  lore or marking the item complete.
- Custom page-action, sidebar and toolbox links to edit/history and other utility
  views preserve or add appropriate `nofollow` behavior. Native account menus
  retain core rendering because their final conversion filters relation
  attributes. Normal article, category,
  source and related-content links must remain crawlable. Do not apply a blanket
  `noindex` policy to useful articles or change staging's separate protections.
- The reviewed map-label patch replaces three misspelled labels with the names
  already used by their linked pages. These labels sit in `opacity: 0` spans over
  the map image; correcting them improves anchor/accessibility text while leaving
  the image, link targets, positions and display behavior intact.

These are independently reviewable improvements, not evidence that any one of
them caused exclusion. Template and article changes require a freshly reviewed database
revision, a private backup and the guarded publishing procedure; a Git release
alone does not publish a wiki template or article. Test application and template behavior on
staging before promoting the specific reviewed changes.

The map-label changes do not explain the excluded-guide cohort as a whole.

## Verification after release

Verify actual rendered item text, explicit field overrides, article links,
tooltips, authentication boundaries and staging isolation. For Equipment By Class,
check all sixteen category links and the existing builder on staging before
publishing the same reviewed page source live. Record staging and
production observations separately from fixture results. Then compare fresh
Search Console inspections and sitemap-filtered indexing results after Google
recrawls. Successful publication, a passing live test or sitemap processing does
not itself prove that indexing or rankings improved; see
[Google's recrawl guidance](https://developers.google.com/search/docs/crawling-indexing/ask-google-to-recrawl).
