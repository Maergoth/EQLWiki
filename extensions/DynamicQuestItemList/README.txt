DynamicQuestItemList v0.8

Replace all files in:
extensions/DynamicQuestItemList/

Files:
- DynamicQuestItemList.php
- DynamicQuestItemList_body.php
- dynamicquestitemlist.js
- dynamicquestitemlist.css
- README.txt

LocalSettings.php:
require_once "$IP/extensions/DynamicQuestItemList/DynamicQuestItemList.php";

Usage:
{{Special:DynamicQuestItemList}}

Changes in v0.8:
- Restored proper table headers with <thead> and <tbody>.
- Chunk size is 500.
- The next chunk starts immediately after the previous chunk finishes appending.
- Completed chunks are cached client-side.
- On later visits, if all chunks are cached and still fresh, the table restores from cache and skips lazy/network loading.
- Cache TTL is 12 hours.
- {{Item Lore|...}} renders with equivalent Item Lore formatting in the Notes column, without adding [[Category:Lore Items]] to the dynamic page.
- {{Item Lore Missing}} is omitted from the Notes column while real notes after it are preserved.

Manual chunk test:
https://eqlwiki.com/Special:DynamicQuestItemList?dqil_action=chunk&category=Quest%20Items&offset=0&limit=500

After replacing files, purge:
https://eqlwiki.com/Category:Quest_Items?action=purge

If ResourceLoader cache is stubborn:
- purge browser cache
- touch LocalSettings.php
- reload with ?debug=true once
