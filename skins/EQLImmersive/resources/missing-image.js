/* EQLImmersive: Missing image upload prompt.
 * Replaces red File: links with styled upload prompt boxes. */
$( function () {
	$( '.new' ).each( function () {
		var $link = $( this );
		var href = $link.attr( 'href' ) || '';
		var text = $.trim( $link.text() );
		var isMissingFile = /[?&]title=(File|Image)%3A/i.test( href ) || /^File:/i.test( text ) || /^Image:/i.test( text );
		var fileName;
		var uploadUrl;
		var $box;

		if ( !isMissingFile ) {
			return;
		}

		fileName = text.replace( /^(File|Image):/i, '' );
		uploadUrl = mw.util.getUrl( 'Special:Upload' ) + '?wpDestFile=' + encodeURIComponent( fileName );

		$box = $( '<div>', { class: 'missing-image-prompt' } );

		$( '<a>', {
			class: 'missing-image-prompt-title',
			href: uploadUrl,
			text: 'Upload your image!'
		} ).appendTo( $box );

		$( '<a>', {
			class: 'missing-image-prompt-upload',
			href: uploadUrl,
			text: 'Upload ' + fileName
		} ).appendTo( $box );

		$link.replaceWith( $box );
	} );
} );