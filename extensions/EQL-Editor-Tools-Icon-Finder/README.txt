EQL Editor Tools / Icon Finder

FILES
-----
1. MediaWiki_BlueprintLoader.js
   Full replacement for MediaWiki:BlueprintLoader.js

2. MediaWiki_IconFinder.js
   New page: MediaWiki:IconFinder.js

3. CommonJS_loader_snippet.js
   Replace the old BlueprintLoader loader block in MediaWiki:Common.js with this.

BEHAVIOR
--------
- New empty source-edit page:
  Blueprint selector on the left, Icon Finder on the right.

- Existing source-edit page:
  Icon Finder remains visible; Blueprint selector is not shown.

- Icon Finder is lazy:
  No Icon_List API request and no icon image downloads occur until the user
  opens the tool and pastes an image.

- First use in a browser:
  Builds a perceptual fingerprint index from the uploaded images referenced
  by Icon List. This is the intentionally heavier step.

- Later uses:
  The fingerprint index is loaded from IndexedDB and keyed to the current
  Icon List revision. If Icon List changes, it rebuilds automatically.

MATCHING
--------
Uses a 64-bit difference hash plus a 12x12 RGB pixel grid. The pasted image
is also tested with small centered crop variants to tolerate a 1-2 pixel
screen-capture border.

The tool returns the ten closest possible matches and NEVER edits the page.
For each match it shows the image and icon number, with clipboard buttons for:
- ID only
- the appropriate template parameter (lucy_img_ID / spellicon)
- raw file wikicode
- fixed 32x32 wikicode
- scaled frameless wikicode
