/* EQLImmersive: private, cross-page to-do state. @license GPL-2.0-or-later */
( function () {
	'use strict';

	var OPTION = 'userjs-eql-todo-v1';
	var MAX_BYTES = 60000;
	var MAX_ITEMS = 100;
	var MAX_NOTES = 16000;
	var SAVE_DELAY = 2000;
	var userId = Number( mw.config.get( 'wgUserId' ) ) || 0;
	var userName = mw.config.get( 'wgUserName' );
	var account = userId > 0 && typeof userName === 'string' && userName.length > 0 &&
		!mw.config.get( 'wgUserIsTemp' );
	var localKey = 'eql-todo-v1:' + encodeURIComponent( mw.config.get( 'wgWikiID' ) ||
		window.location.host + ( mw.config.get( 'wgScriptPath' ) || '' ) ) + ':anon';
	var items = [];
	var listeners = [];
	var loaded = false;
	var dirty = false;
	var conflict = false;
	var baseline = '';
	var state = 'idle';
	var message = '';
	var initialPromise = null;
	var pendingSave = null;
	var timer = null;
	var revision = 0;
	var api = null;

	function fail( code, text ) {
		var error = new Error( text );
		error.code = code;
		return error;
	}

	function clone( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	function identityMatches() {
		return ( Number( mw.config.get( 'wgUserId' ) ) || 0 ) === userId &&
			mw.config.get( 'wgUserName' ) === userName;
	}

	function status() {
		return {
			state: state, ready: loaded, dirty: dirty, account: account,
			canWrite: loaded && !conflict && identityMatches(), message: message,
			retry: state === 'error' && !conflict, conflict: conflict
		};
	}

	function snapshot() {
		return { items: clone( items ), status: status() };
	}

	function notify() {
		listeners.slice().forEach( function ( listener ) {
			// A view failure must not interrupt persistence or another subscriber.
			try {
				listener( snapshot() );
			} catch ( error ) {
				mw.log.error( 'EQL to-do view update failed', error );
			}
		} );
	}

	function setStatus( next, text ) {
		state = next;
		message = text || '';
		notify();
	}

	function textField( value, max, required ) {
		if ( typeof value !== 'string' || value.length > max ||
			/[\u0000-\u001f\u007f]/.test( value ) || ( required && !value.trim() ) ) {
			throw fail( 'invalid', 'This to-do source is not valid.' );
		}
		// Invalid surrogate sequences cannot form stable URL fragment identities.
		try {
			encodeURIComponent( value );
		} catch ( error ) {
			throw fail( 'invalid', 'This to-do source is not valid.' );
		}
		return value;
	}

	function normalizeEntry( input ) {
		if ( !input || typeof input !== 'object' || Array.isArray( input ) ) {
			throw fail( 'invalid', 'This to-do entry is not valid.' );
		}
		var title = textField( input.title, 255, true ).replace( /_/g, ' ' ).trim();
		var section = textField( input.section === undefined ? '' : input.section, 1024, false );
		var sectionTitle = textField( input.sectionTitle === undefined ? '' : input.sectionTitle, 512, false );
		var notes = input.notes === undefined ? '' : input.notes;
		if ( typeof notes !== 'string' || notes.length > MAX_NOTES ) {
			throw fail( 'limit', 'Notes can contain up to ' + MAX_NOTES + ' characters per entry.' );
		}
		if ( input.progress !== undefined &&
			( typeof input.progress !== 'number' || !Number.isFinite( input.progress ) ||
				input.progress < 0 || input.progress > 100 ) ) {
			throw fail( 'invalid', 'Progress must be between 0 and 100.' );
		}
		[ 'done', 'slider', 'collapsed' ].forEach( function ( key ) {
			if ( input[ key ] !== undefined && typeof input[ key ] !== 'boolean' ) {
				throw fail( 'invalid', 'This to-do entry is not valid.' );
			}
		} );
		var progress = input.progress === undefined ? ( input.done ? 100 : 0 ) : Math.round( input.progress );
		return {
			id: encodeURIComponent( title ) + '#' + encodeURIComponent( section ),
			title: title, section: section, sectionTitle: sectionTitle,
			// Notes remain inert strings here; the UI sanitizes before HTML insertion.
			notes: notes, progress: progress, slider: input.slider === true,
			done: progress === 100, collapsed: input.collapsed === true
		};
	}

	function serialize( candidate ) {
		var value = JSON.stringify( { version: 1, items: candidate } );
		// URI encoding measures UTF-8 bytes, including non-ASCII notes and titles.
		var bytes = encodeURIComponent( value ).replace( /%[0-9A-F]{2}/g, 'x' ).length;
		if ( bytes > MAX_BYTES ) {
			throw fail( 'limit', 'Your to-do list is full. Shorten notes or remove an entry before adding more.' );
		}
		return value;
	}

	function parse( value ) {
		if ( value === null || value === undefined || value === '' ) {
			return [];
		}
		if ( typeof value !== 'string' || value.length > MAX_BYTES ) {
			throw fail( 'invalid', 'The saved to-do list could not be read. It has not been replaced.' );
		}
		var data;
		try {
			data = JSON.parse( value );
		} catch ( error ) {
			throw fail( 'invalid', 'The saved to-do list could not be read. It has not been replaced.' );
		}
		if ( !data || data.version !== 1 || !Array.isArray( data.items ) || data.items.length > MAX_ITEMS ) {
			throw fail( 'invalid', 'The saved to-do list could not be read. It has not been replaced.' );
		}
		var seen = Object.create( null );
		var result = data.items.map( normalizeEntry );
		result.forEach( function ( entry ) {
			if ( seen[ entry.id ] ) {
				throw fail( 'invalid', 'The saved to-do list has duplicate entries. It has not been replaced.' );
			}
			seen[ entry.id ] = true;
		} );
		serialize( result );
		return result;
	}

	function rawValue( value ) {
		return value === null || value === undefined ? '' : value;
	}

	function readAccountValue() {
		api = api || new mw.Api();
		// Do not use mw.user.options: cached HTML may contain an old account list.
		return Promise.resolve( api.get( {
			action: 'query', meta: 'userinfo', uiprop: 'options',
			assert: 'user', assertuser: userName, formatversion: 2
		} ) ).then( function ( result ) {
			var info = result && !result.error && result.query && result.query.userinfo;
			if ( !info || info.id !== userId || info.name !== userName ||
				!info.options || typeof info.options !== 'object' || Array.isArray( info.options ) ||
				!identityMatches() ) {
				throw fail( 'identity', 'Your account could not be verified. Reload this page or retry.' );
			}
			return rawValue( info.options[ OPTION ] );
		} );
	}

	function markConflict() {
		conflict = true;
		if ( timer !== null ) {
			window.clearTimeout( timer );
			timer = null;
		}
		return fail( 'conflict', 'Your to-do list changed in another tab or device. ' +
			'Copy your unsaved notes, then reload this page.' );
	}

	function load() {
		if ( loaded ) {
			return Promise.resolve( snapshot() );
		}
		if ( initialPromise ) {
			return initialPromise;
		}
		setStatus( 'loading' );
		initialPromise = Promise.resolve().then( function () {
			if ( !identityMatches() ) {
				throw fail( 'identity', 'Your account changed. Reload this page before using the to-do list.' );
			}
			if ( !account ) {
				return rawValue( window.localStorage.getItem( localKey ) );
			}
			return readAccountValue();
		} ).then( function ( saved ) {
			items = parse( saved );
			baseline = saved;
			loaded = true;
			setStatus( 'saved' );
			return snapshot();
		} ).catch( function ( error ) {
			initialPromise = null;
			setStatus( 'error', error && error.code ? error.message :
				'Your to-do list could not be loaded. Retry before making changes.' );
			throw error;
		} );
		return initialPromise;
	}

	function scheduleSave( delay ) {
		if ( timer !== null ) {
			window.clearTimeout( timer );
		}
		timer = window.setTimeout( function () {
			timer = null;
			flush( true ).catch( function () {} );
		}, delay );
	}

	function flush( automatic ) {
		if ( timer !== null ) {
			window.clearTimeout( timer );
			timer = null;
		}
		if ( !loaded ) {
			return load();
		}
		if ( pendingSave ) {
			// An expired autosave must not queue an immediate write that bypasses
			// the quiet period of edits made while this request is still running.
			if ( automatic ) {
				return pendingSave;
			}
			return pendingSave.then( function () {
				return dirty ? flush() : snapshot();
			} );
		}
		if ( !dirty ) {
			return Promise.resolve( snapshot() );
		}
		if ( !identityMatches() ) {
			var identityError = fail( 'identity', 'Your account changed. Reload this page; these changes have not been saved.' );
			setStatus( 'error', identityError.message );
			return Promise.reject( identityError );
		}
		var savedRevision = revision;
		var value = serialize( items );
		setStatus( 'saving' );
		pendingSave = Promise.resolve().then( function () {
			return account ? readAccountValue() : rawValue( window.localStorage.getItem( localKey ) );
		} ).then( function ( current ) {
			// API:Options has no compare-and-swap parameter. Refuse a known stale
			// baseline rather than replacing another tab's or device's newer work.
			if ( conflict || ( current !== baseline && current !== value ) ) {
				throw markConflict();
			}
			// A lost response may have hidden a successful previous write. An exact
			// match acknowledges it safely without replacing anything.
			if ( current === value ) {
				return;
			}
			if ( !account ) {
				window.localStorage.setItem( localKey, value );
				return;
			}
			return Promise.resolve( api.postWithToken( 'csrf', {
				action: 'options', optionname: OPTION, optionvalue: value,
				assert: 'user', assertuser: userName, formatversion: 2
			} ) ).then( function ( result ) {
				if ( !result || result.error || result.warnings || result.options !== 'success' ) {
					throw fail( 'save', 'Your to-do changes could not be saved. Keep this page open and retry.' );
				}
			} );
		} ).then( function () {
			pendingSave = null;
			baseline = value;
			dirty = revision !== savedRevision;
			if ( dirty ) {
				// Each newer edit already reset its own two-second timer. If that
				// timer expired during this request, the quiet period is satisfied.
				if ( timer === null ) {
					scheduleSave( 0 );
				}
				return snapshot();
			}
			setStatus( 'saved' );
			return snapshot();
		}, function ( error ) {
			pendingSave = null;
			if ( timer !== null ) {
				window.clearTimeout( timer );
				timer = null;
			}
			setStatus( 'error', conflict ? error.message :
				'Your to-do changes could not be saved. Keep this page open and retry.' );
			throw error;
		} );
		return pendingSave;
	}

	function requireReady() {
		if ( !loaded ) {
			throw fail( 'not-ready', 'Wait for your to-do list to load, or retry the failed load.' );
		}
		if ( !identityMatches() ) {
			throw fail( 'identity', 'Your account changed. Reload this page before using the to-do list.' );
		}
		if ( conflict ) {
			throw markConflict();
		}
	}

	function commit( candidate ) {
		serialize( candidate );
		items = candidate;
		dirty = true;
		revision++;
		// Once a save fails, edits remain local until the user explicitly retries.
		if ( state !== 'error' ) {
			state = 'saving';
			message = '';
			scheduleSave( SAVE_DELAY );
		}
		notify();
	}

	window.EQLTodoStore = {
		ready: load,
		retry: function () { return loaded ? flush() : load(); },
		flush: flush,
		getItems: function () { return clone( items ); },
		getStatus: status,
		limits: { entries: MAX_ITEMS, notes: MAX_NOTES, bytes: MAX_BYTES },
		subscribe: function ( listener ) {
			listeners.push( listener );
			listener( snapshot() );
			return function () {
				listeners = listeners.filter( function ( candidate ) { return candidate !== listener; } );
			};
		},
		add: function ( source ) {
			requireReady();
			var entry = normalizeEntry( source );
			var existing = items.find( function ( item ) { return item.id === entry.id; } );
			if ( existing ) {
				return clone( existing );
			}
			if ( items.length >= MAX_ITEMS ) {
				throw fail( 'limit', 'Your to-do list can contain up to ' + MAX_ITEMS + ' entries.' );
			}
			commit( items.concat( entry ) );
			return clone( entry );
		},
		update: function ( id, patch ) {
			requireReady();
			var original = items.find( function ( entry ) { return entry.id === id; } );
			if ( !original ) {
				throw fail( 'missing', 'This entry is no longer in your to-do list.' );
			}
			var next = Object.assign( {}, original );
			[ 'notes', 'progress', 'slider', 'done', 'collapsed' ].forEach( function ( key ) {
				if ( Object.prototype.hasOwnProperty.call( patch, key ) ) {
					next[ key ] = patch[ key ];
				}
			} );
			if ( Object.prototype.hasOwnProperty.call( patch, 'done' ) &&
				!Object.prototype.hasOwnProperty.call( patch, 'progress' ) ) {
				next.progress = patch.done ? 100 : ( original.progress === 100 ? 0 : original.progress );
			}
			var updated = normalizeEntry( next );
			if ( JSON.stringify( updated ) !== JSON.stringify( original ) ) {
				commit( items.map( function ( entry ) { return entry.id === id ? updated : entry; } ) );
			}
			return clone( updated );
		},
		remove: function ( id ) {
			requireReady();
			var remaining = items.filter( function ( entry ) { return entry.id !== id; } );
			if ( remaining.length !== items.length ) {
				commit( remaining );
			}
		}
	};

	window.addEventListener( 'storage', function ( event ) {
		if ( account || !loaded || ( event.key !== localKey && event.key !== null ) ) {
			return;
		}
		var current = rawValue( event.newValue );
		if ( current === baseline ) {
			return;
		}
		if ( dirty ) {
			setStatus( 'error', markConflict().message );
			return;
		}
		try {
			items = parse( current );
			baseline = current;
			setStatus( 'saved' );
		} catch ( error ) {
			setStatus( 'error', markConflict().message );
		}
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty ) {
			// Browsers cannot guarantee an asynchronous authenticated write on unload.
			// Preserve the warning until the server has acknowledged every edit.
			event.preventDefault();
			event.returnValue = '';
		}
	} );
}() );
