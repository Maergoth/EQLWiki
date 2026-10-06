/* EQLImmersive: Ajax cache library + item hover tooltips.
 * Creates #itemHoverContainer, fetches item HTML via AjaxHoverHelper, caches responses.
 * Also handles Magelo profile hover positioning. */

var requestStorage = function () {
	var storage = {};
	var keys = [];

	return {
		length: 0,
		key: function ( index ) {
			return typeof index === 'number' && keys.length >= index && index >= 0 ? keys[ index ] : null;
		},
		getItem: function ( key ) {
			return Object.prototype.hasOwnProperty.call( storage, key ) ? storage[ key ] : null;
		},
		setItem: function ( key, value ) {
			if ( !Object.prototype.hasOwnProperty.call( storage, key ) ) {
				this.length++;
				keys.push( key );
			}

			storage[ key ] = value;
		},
		removeItem: function ( key ) {
			var i;

			if ( Object.prototype.hasOwnProperty.call( storage, key ) ) {
				this.length--;

				for ( i = 0; i < keys.length; i++ ) {
					if ( keys[ i ] === key ) {
						keys.splice( i, 1 );
						break;
					}
				}
			}

			delete storage[ key ];
		},
		clear: function () {
			storage = {};
			keys = [];
			this.length = 0;
		}
	};
}();

(function ( $ ) {
	var item = {
		timeStamp: '__timeStamp__',
		responseText: '__responseText__',
		hasResponseXML: '__hasResponseXML__',
		responseHeaders: '__responseHeaders__'
	};

	$.ajaxCacheResponse = {
		storage: window.requestStorage
	};

	function cacheKeyFromOptions( options ) {
		var key = '';
		var type;

		for ( var name in options ) {
			if ( !Object.prototype.hasOwnProperty.call( options, name ) ) {
				continue;
			}

			type = typeof options[ name ];

			if ( type === 'string' || type === 'number' || type === 'boolean' ) {
				key += name + '=' + options[ name ] + ',';
			} else if ( type === 'object' ) {
				key += name + '=' + $.param( options[ name ] ) + ',';
			}
		}

		return key;
	}

	function isCacheValid( cacheKey, options ) {
		var timestamp;
		var now;
		var valid = true;

		if ( $.ajaxCacheResponse.storage.getItem( cacheKey + item.timeStamp ) === null ) {
			return false;
		}

		if ( typeof options.cacheResponseTimer === 'number' ) {
			timestamp = parseInt( $.ajaxCacheResponse.storage.getItem( cacheKey + item.timeStamp ), 10 );

			if ( typeof timestamp === 'number' ) {
				now = ( new Date() ).getTime();
				valid = timestamp + options.cacheResponseTimer > now;

				if ( valid === true ) {
					options.cacheTimeRemaining = options.cacheResponseTimer - ( now - timestamp );
				}
			}
		}

		if ( typeof options.cacheResponseValid === 'function' ) {
			valid = options.cacheResponseValid.call( this, options );
		}

		return valid;
	}

	$.ajaxPrefilter( function ( options, originalOptions, jqXHR ) {
		var cacheKey;
		var cached;
		var name;

		if ( options.cacheResponse !== true ) {
			return;
		}

		if ( $.ajaxCacheResponse.storage === undefined ) {
			throw 'No valid storage defined for the Ajax Cache Response plugin';
		}

		cacheKey = cacheKeyFromOptions( originalOptions );
		options.cacheResponseId = cacheKey;

		if ( isCacheValid( cacheKey, options ) === true ) {
			cached = $.extend( {}, item );

			for ( name in cached ) {
				if ( Object.prototype.hasOwnProperty.call( cached, name ) ) {
					cached[ name ] = $.ajaxCacheResponse.storage.getItem( cacheKey + cached[ name ] );
				}
			}

			options.xhr = function () {
				return {
					open: $.noop,
					setRequestHeader: $.noop,
					send: $.noop,
					abort: $.noop,
					onreadystatechange: $.noop,
					getResponseHeader: $.noop,
					getAllResponseHeaders: function () {
						return cached.responseHeaders;
					},
					readyState: 4,
					status: 200,
					statusText: 'success',
					responseText: cached.responseText,
					responseXML: cached.hasResponseXML === true ? $.parseXML( cached.responseText ) : undefined
				};
			};

			jqXHR.responseFromCache = true;
			jqXHR.cacheTimeRemaining = options.cacheTimeRemaining;
		} else {
			jqXHR.responseFromCache = false;
		}
	} );

	$( document ).ajaxSuccess( function ( event, xhr, options ) {
		var cacheKey;
		var storage;

		if ( options.cacheResponse !== true ) {
			return;
		}

		cacheKey = options.cacheResponseId;

		if ( isCacheValid( cacheKey, options ) === false ) {
			storage = $.ajaxCacheResponse.storage;
			storage.setItem( cacheKey + item.responseText, xhr.responseText );
			storage.setItem( cacheKey + item.responseHeaders, xhr.getAllResponseHeaders() );
			storage.setItem( cacheKey + item.timeStamp, ( new Date() ).getTime() );
			storage.setItem( cacheKey + item.hasResponseXML, xhr.responseXML !== undefined );
		}
	} );
}( jQuery ) );

$( document ).ready( function () {
	$( function () {
		var hideDelay = 0;
		var hideTimer = null;
		var ajax = null;
		var container = $( '<div id="itemHoverContainer"><div id="itemHoverContent"></div></div>' );

		$( 'body' ).append( container );

		$( document ).on( 'mouseover', 'span.ih a', function () {
			var itemname = $( this ).attr( 'title' );

			if ( itemname === '' || itemname === 'undefined' ) {
				return;
			}

			if ( hideTimer ) {
				clearTimeout( hideTimer );
			}

			$( this ).trigger( 'mousemove' );
			$( '#itemHoverContent' ).html( '&nbsp;' );

			if ( ajax ) {
				ajax.abort();
				ajax = null;
			}

			ajax = $.ajax( {
				url: 'https://www.eqlwiki.com/index.php/Special:AjaxHoverHelper/' + itemname,
				cacheResponse: true,
				success: function ( html ) {
					$( '#itemHoverContent' ).html( html );
				}
			} );

			container.css( 'display', 'block' );
		} );

		$( document ).on( 'mouseout', 'span.ih a', function () {
			if ( hideTimer ) {
				clearTimeout( hideTimer );
			}

			hideTimer = setTimeout( function () {
				container.css( 'display', 'none' );
			}, hideDelay );
		} );

		$( document ).on( 'mousemove', 'span.ih a', function ( e ) {
			var mousex = e.pageX + 20;
			var mousey = e.pageY + 20;
			var tipWidth = container.width();
			var tipHeight = container.height();
			var tipVisX = $( window ).width() - ( mousex + tipWidth );
			var tipVisY = $( window ).height() - ( mousey + tipHeight );

			if ( tipVisX < 20 ) {
				if ( tipWidth > e.pageX - 20 ) {
					mousex = 0;
				} else {
					mousex = e.pageX - tipWidth - 20;
				}
			}

			if ( tipVisY < 20 ) {
				mousey = e.pageY - tipHeight - 20;
			}

			container.css( {
				top: mousey,
				left: mousex
			} );
		} );

		$( '#itemHoverContainer' ).mouseover( function () {
			if ( hideTimer ) {
				clearTimeout( hideTimer );
			}
		} );

		$( '#itemHoverContainer' ).mouseout( function () {
			if ( hideTimer ) {
				clearTimeout( hideTimer );
			}

			hideTimer = setTimeout( function () {
				container.css( 'display', 'none' );
			}, hideDelay );
		} );

		$( '.magelohb' ).mousemove( function ( e ) {
			var childContainer = $( this ).children( 'span.hb' );
			var tipWidth = childContainer.width();
			var tipHeight = childContainer.height();
			var mousex = e.pageX + 20;
			var mousey = e.pageY + 20;
			var tipVisX = $( window ).width() - ( mousex + tipWidth - 20 );
			var tipVisY = $( window ).height() - ( mousey + tipHeight - 20 );

			if ( tipVisX < 20 ) {
				if ( tipWidth > e.pageX - 20 ) {
					mousex = 0;
				} else {
					mousex = e.pageX - tipWidth - 20;
				}
			}

			if ( tipVisY < 20 ) {
				mousey = e.pageY - tipHeight - 20;
			}

			childContainer.css( {
				top: mousey,
				left: mousex,
				'z-index': '999'
			} );
		} );

		$( '.magelohb span.hb' ).each( function () {
			$( this ).css( { position: 'fixed' } );
		} );
	} );
} );
