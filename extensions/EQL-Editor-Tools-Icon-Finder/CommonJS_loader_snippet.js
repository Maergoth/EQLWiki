/* =====================================================
   EQL editor tools
   - Dynamic Blueprint selector on empty/new pages
   - Icon Finder on every source-edit page
   ===================================================== */

( function () {
	'use strict';

	if (
		[
			'edit',
			'submit'
		].indexOf(
			mw.config.get( 'wgAction' )
		) === -1
	) {
		return;
	}

	mw.loader.load(
		'/index.php?' +
		'title=MediaWiki:BlueprintLoader.js' +
		'&action=raw' +
		'&ctype=text/javascript'
	);
}() );
