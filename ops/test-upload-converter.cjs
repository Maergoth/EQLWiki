'use strict';

// DOM regression checks with synthetic file headers. Image decoding and canvas
// encoding are browser mocks; real pixel conversion needs a browser check.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );
const sourcePath = process.argv[ 2 ] || path.join( __dirname,
	'../skins/EQLImmersive/resources/upload-converter.js' );
const source = fs.readFileSync( sourcePath, 'utf8' );
const jquery = fs.readFileSync( path.join( __dirname,
	'../resources/lib/jquery/jquery.js' ), 'utf8' );
const nativeUpload = fs.readFileSync( path.join( __dirname,
	'../resources/src/mediawiki.special.upload/upload.js' ), 'utf8' );
const headers = {
	'image/png': [ 137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 0 ],
	'image/jpeg': [ 255, 216, 255, 224, 0, 16, 74, 70, 73, 70, 0, 1 ],
	'image/gif': Array.from( Buffer.from( 'GIF89a123456' ) ),
	'image/webp': Array.from( Buffer.from( 'RIFF1234WEBP' ) ),
	'image/bmp': Array.from( Buffer.from( 'BM1234567890' ) ),
	'image/avif': [ 0, 0, 0, 28, ...Buffer.from( 'ftypavif' ) ],
	'image/svg+xml': Array.from( Buffer.from( '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="16"></svg>' ) )
};

async function waitFor( predicate, label ) {
	for ( let i = 0; i < 100; i++ ) {
		if ( predicate() ) {
			return;
		}
		await new Promise( resolve => setTimeout( resolve, 5 ) );
	}
	assert.fail( 'Timed out: ' + label );
}

async function fixture( options = {} ) {
	const dom = new JSDOM( `<!doctype html><html><body>
		<form id="searchform"><input name="search"></form>
		<form id="mw-upload-form" enctype="multipart/form-data" method="post">
			<input id="wpUploadFile" name="wpUploadFile" type="file">
			<input id="wpDestFile" name="wpDestFile" value="npc_a_mimic.png">
			<textarea name="wpUploadDescription">Synthetic upload regression</textarea>
			<input type="hidden" name="wpEditToken" value="synthetic-token">
			<button type="submit" name="wpUpload" value="Upload file">Upload file</button>
			<button type="submit" name="wpUploadIgnoreWarning" value="Ignore warning">Ignore warning</button>
			<button type="submit" name="wpCancelUpload" value="Cancel">Cancel</button>
		</form></body></html>`, {
		url: options.nativeAutofill ? 'https://example.invalid/Special:Upload' :
			'https://example.invalid/Special:Upload?wpDestFile=npc_a_mimic.png&eqlReturnTo=A_mimic&eqlAlsoPurge=Synthetic_zone',
		runScripts: 'outside-only'
	} );
	const { window } = dom;
	const form = window.document.getElementById( 'mw-upload-form' );
	const input = window.document.getElementById( 'wpUploadFile' );
	const destination = window.document.getElementById( 'wpDestFile' );
	const accepted = [];
	const decoded = [];
	const encoded = [];
	const fills = [];
	const revoked = [];
	const imageLoads = [];
	const urls = new Map();
	if ( options.nativeAutofill ) {
		destination.value = '';
	}
	let selected = [];
	let serial = 0;
	let encodesDone = 0;
	Object.defineProperty( input, 'files', {
		configurable: true,
		get: () => selected,
		set: files => { selected = Array.from( files ); }
	} );
	// Mirror the browser's read-only fakepath for the bundled core source-change
	// handler. Clearing the input, as core does for forbidden types, clears files.
	Object.defineProperty( input, 'value', {
		configurable: true,
		get: () => selected.length ? 'C:\\fakepath\\' + selected[ 0 ].name : '',
		set: value => {
			assert.equal( value, '', 'Only clearing a file input value is permitted' );
			selected = [];
		}
	} );
	window.DataTransfer = class {
		constructor() {
			if ( options.failTransfer ) {
				throw new Error( 'Synthetic FileList replacement unavailable' );
			}
			this.files = [];
			this.items = { add: file => this.files.push( file ) };
		}
	};
	const NativeFileReader = window.FileReader;
	window.FileReader = class extends NativeFileReader {
		readAsArrayBuffer( blob ) {
			if ( options.failRead ) {
				window.setTimeout( () => this.onerror( new window.Event( 'error' ) ), 0 );
			} else {
				super.readAsArrayBuffer( blob );
			}
		}
	};
	window.URL.createObjectURL = file => {
		const url = 'blob:synthetic-' + ++serial;
		urls.set( url, file );
		return url;
	};
	window.URL.revokeObjectURL = url => {
		revoked.push( url );
		urls.delete( url );
	};
	window.Image = class {
		constructor() {
			this.width = this.naturalWidth = options.width || 24;
			this.height = this.naturalHeight = options.height || 16;
		}
		set src( url ) {
			decoded.push( urls.get( url ) );
			const finish = () => {
				if ( options.failDecode ) {
					this.onerror( new window.Event( 'error' ) );
				} else {
					this.onload( new window.Event( 'load' ) );
				}
			};
			if ( options.pauseDecode ) {
				imageLoads.push( finish );
			} else {
				window.setTimeout( finish, 0 );
			}
		}
	};
	window.HTMLCanvasElement.prototype.getContext = function ( kind ) {
		assert.equal( kind, '2d' );
		const context = {
			drawImage: () => {},
			clearRect: () => {},
			rotate: () => {},
			fillRect: ( ...args ) => fills.push( { color: context.fillStyle, args } ),
			fillStyle: '',
			getImageData: ( x, y, width, height ) => {
				const data = new window.Uint8ClampedArray( width * height * 4 );
				let random = 42;
				for ( let i = 0; i < width * height; i++ ) {
					if ( options.rareTransparency ) {
						random = ( Math.imul( random, 1664525 ) + 1013904223 ) >>> 0;
						data.set( [ random & 255, ( random >>> 8 ) & 255, ( random >>> 16 ) & 255, 255 ], i * 4 );
					} else if ( options.alphaPixels ) {
						const alpha = options.alphaPixels[ i % options.alphaPixels.length ];
						data.set( [ alpha === 0 ? 0 : 255, 0, 0, alpha ], i * 4 );
					} else {
						data.set( [ i * 31 % 256, i * 67 % 256, i * 97 % 256,
							i % 3 === 0 ? 0 : 255 ], i * 4 );
					}
				}
				if ( options.rareTransparency ) {
					data.fill( 0, 0, 4 );
				}
				return { data, width, height };
			}
		};
		return context;
	};
	window.HTMLCanvasElement.prototype.toBlob = function ( callback, type ) {
		encoded.push( { type, width: this.width, height: this.height } );
		window.setTimeout( () => {
			const actualType = options.outputType || type;
			callback( options.failEncode ? null : new window.Blob( options.emptyEncode ? [] : [
				new window.Uint8Array( headers[ actualType ] ), 'synthetic-encoded-pixels'
			], { type: actualType } ) );
			encodesDone++;
		}, 0 );
	};
	window.eval( jquery );
	const config = {
		wgCanonicalSpecialPageName: 'Upload', wgPageName: 'Special:Upload',
		wgDBname: 'synthetic', wgUserId: 1,
		wgMaxUploadSize: { file: 128 * 1024 * 1024, '*': 128 * 1024 * 1024 },
		wgNamespaceIds: { file: 6 }, wgFormattedNamespaces: { 6: 'File' },
		wgUploadAutoFill: Boolean( options.nativeAutofill ),
		wgUploadSourceIds: [ 'wpUploadFile' ],
		wgCheckFileExtensions: true, wgStrictFileExtensions: true,
		wgFileExtensions: [ 'png', 'gif', 'jpg', 'jpeg', 'webp' ]
	};
	let gifExports;
	function requireModule( name ) {
		assert.equal( name, 'skins.EQLImmersive.gifEncoder' );
		if ( !gifExports ) {
			// Evaluate the actual package in the browser realm, as ResourceLoader
			// does; gifenc intentionally checks its typed-array input classes.
			const vendor = fs.readFileSync( path.join( __dirname,
				'../skins/EQLImmersive/resources/vendor/gifenc/gifenc.js' ), 'utf8' );
			const factory = window.eval( '(function(exports,require,module){\n' + vendor + '\n})' );
			const module = { exports: {} };
			factory( module.exports, () => assert.fail( 'Unexpected vendor dependency' ), module );
			gifExports = module.exports;
		}
		return gifExports;
	}
	window.mw = {
		config: { get: ( key, fallback ) => key in config ? config[ key ] : fallback },
		loader: { using: modules => {
			const deferred = window.jQuery.Deferred();
			if ( options.failGifLoad && modules.includes( 'skins.EQLImmersive.gifEncoder' ) ) {
				deferred.reject( new Error( 'Synthetic GIF dependency unavailable' ) );
			} else {
				deferred.resolve( requireModule );
			}
			return deferred.promise();
		} },
		util: { getUrl: title => '/wiki/' + encodeURIComponent( title ) },
		Api: function () {
			this.post = () => Promise.resolve( {} );
			this.get = () => Promise.resolve( { query: { pages: [ {} ] } } );
		},
		Title: {
			makeTitle: ( namespace, name ) => ( { getMain: () => name[ 0 ].toUpperCase() + name.slice( 1 ) } ),
			newFromText: name => ( { getNamespaceId: () => 6, getPrefixedText: () => 'File:' + name } )
		},
		hook: () => ( { fire: () => {} } ),
		msg: ( key, ...params ) => key + ' ' + params.join( ' ' ),
		template: { get: () => ( { render: () => window.jQuery( '<div id="mw-upload-thumbnail"><div><span class="filename"></span><span class="fileinfo"></span></div></div>' ) } ) },
		user: { options: { get: () => false } }
	};
	window.jQuery.createSpinner = () => window.jQuery( '<span>' );
	// Model native requestSubmit: emit a cancelable submit event, then record the
	// selected File and successful submitter only when no handler prevented it.
	form.requestSubmit = function ( submitter ) {
		const event = new window.SubmitEvent( 'submit', {
			bubbles: true, cancelable: true, submitter: submitter || null
		} );
		if ( form.dispatchEvent( event ) ) {
			accepted.push( {
				file: input.files[ 0 ], destination: destination.value,
				submitter, button: submitter && submitter.name,
				token: form.elements.namedItem( 'wpEditToken' ).value,
				description: form.elements.namedItem( 'wpUploadDescription' ).value
			} );
		}
	};
	form.submit = () => assert.fail( 'Conversion must preserve native submit events and submitter' );
	if ( options.nativeSourceHandling ) {
		window.eval( nativeUpload );
	}
	window.eval( source );
	window.document.dispatchEvent( new window.Event( 'DOMContentLoaded' ) );
	await new Promise( resolve => window.jQuery( resolve ) );
	await waitFor( () => form.dataset.eqlUploadConverterBound === '1', 'upload converter initialization' );
	return {
		window, form, input, destination, accepted, decoded, encoded, revoked, fills, config,
		encodingDone: () => encodesDone,
		async evaluateAgain() {
			window.eval( source );
			await new Promise( resolve => setTimeout( resolve, 0 ) );
		},
		select( mime = 'image/jpeg', type = mime, name = 'screenshot.jpg' ) {
			const file = new window.File( [ new window.Uint8Array( headers[ mime ] ),
				'synthetic-original-pixels' ], name, { type } );
			input.files = [ file ];
			input.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
			return file;
		},
		submit( name = 'wpUpload' ) {
			const button = form.querySelector( '[name="' + name + '"]' );
			form.requestSubmit( button );
			return button;
		},
		error() {
			const status = window.document.getElementById( 'eql-upload-conversion-status' );
			return status && status.classList.contains( 'mw-message-box-error' ) ? status : null;
		},
		async failed() {
			await waitFor( () => this.error(), 'visible conversion error' );
			assert.ok( this.error().textContent.trim(), 'Failure gives a readable explanation' );
			assert.equal( accepted.length, 0, 'Failure must not submit original mismatched bytes' );
			assert.equal( window.sessionStorage.getItem( 'eqlPendingUploadPurge' ), null,
				'Failed conversion does not seed return/purge context' );
		},
		async submitted() {
			await waitFor( () => accepted.length || this.error(), 'native upload submission' );
			assert.ok( accepted.length, this.error() && this.error().textContent );
		},
		async releaseDecode() {
			await waitFor( () => imageLoads.length, 'pending image decode' );
			imageLoads.shift()();
		},
		close: () => window.close()
	};
}

async function withFixture( options, callback ) {
	const test = await fixture( options );
	try {
		await callback( test );
	} finally {
		test.close();
	}
}

function bytes( window, file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new window.FileReader();
		reader.onload = () => resolve( Array.from( new Uint8Array( reader.result ) ) );
		reader.onerror = reject;
		reader.readAsArrayBuffer( file );
	} );
}

// Independent first-frame GIF decoding for the alpha regression. Reading the
// emitted LZW indices and graphic-control extension verifies actual GIF pixels,
// rather than inspecting the encoder's palette or mocking its output.
function gifAlphas( data ) {
	assert.match( Buffer.from( data.slice( 0, 6 ) ).toString(), /^GIF8[79]a$/ );
	let offset = 13;
	let transparent = -1;
	const globalFlags = data[ 10 ];
	if ( globalFlags & 128 ) {
		offset += 3 * ( 1 << ( ( globalFlags & 7 ) + 1 ) );
	}
	function subBlocks() {
		const block = [];
		while ( data[ offset ] ) {
			const length = data[ offset++ ];
			block.push( ...data.slice( offset, offset + length ) );
			offset += length;
		}
		offset++;
		return block;
	}
	while ( offset < data.length ) {
		const separator = data[ offset++ ];
		if ( separator === 33 ) {
			const label = data[ offset++ ];
			const extension = subBlocks();
			if ( label === 249 ) {
				transparent = extension[ 0 ] & 1 ? extension[ 3 ] : -1;
			}
			continue;
		}
		assert.equal( separator, 44, 'GIF contains a first image frame' );
		const width = data[ offset + 4 ] + 256 * data[ offset + 5 ];
		const height = data[ offset + 6 ] + 256 * data[ offset + 7 ];
		const imageFlags = data[ offset + 8 ];
		assert.equal( imageFlags & 64, 0, 'Test decoder expects the encoder noninterlaced frame' );
		offset += 9;
		if ( imageFlags & 128 ) {
			offset += 3 * ( 1 << ( ( imageFlags & 7 ) + 1 ) );
		}
		const minimumSize = data[ offset++ ];
		const compressed = subBlocks();
		const clear = 1 << minimumSize;
		const end = clear + 1;
		let dictionary, next, codeSize, previous;
		let bit = 0;
		const indices = [];
		function reset() {
			dictionary = Array.from( { length: clear }, ( _, i ) => [ i ] );
			next = end + 1;
			codeSize = minimumSize + 1;
			previous = null;
		}
		reset();
		while ( bit + codeSize <= compressed.length * 8 ) {
			let code = 0;
			for ( let i = 0; i < codeSize; i++, bit++ ) {
				code |= ( ( compressed[ bit >> 3 ] >> ( bit & 7 ) ) & 1 ) << i;
			}
			if ( code === clear ) {
				reset();
				continue;
			}
			if ( code === end ) {
				break;
			}
			const entry = dictionary[ code ] || ( code === next && previous ? previous.concat( previous[ 0 ] ) : null );
			assert.ok( entry, 'GIF contains valid LZW indices' );
			indices.push( ...entry );
			if ( previous && next < 4096 ) {
				dictionary[ next++ ] = previous.concat( entry[ 0 ] );
				if ( next === 1 << codeSize && codeSize < 12 ) {
					codeSize++;
				}
			}
			previous = entry;
		}
		assert.equal( indices.length, width * height, 'Decoded GIF has a complete frame' );
		return indices.map( index => index === transparent ? 0 : 255 );
	}
	assert.fail( 'GIF has no image frame' );
}

async function main() {
	await withFixture( {}, async test => {
		const original = test.select();
		const button = test.submit();
		await test.submitted();
		const sent = test.accepted[ 0 ];
		assert.notEqual( sent.file, original, 'JPEG bytes must be converted for a PNG destination' );
		assert.equal( sent.file.type, 'image/png' );
		assert.equal( sent.destination, 'npc_a_mimic.png' );
		assert.equal( sent.file.name, 'npc_a_mimic.png' );
		assert.deepEqual( ( await bytes( test.window, sent.file ) ).slice( 0, 8 ), headers[ 'image/png' ].slice( 0, 8 ) );
		assert.deepEqual( test.encoded, [ { type: 'image/png', width: 24, height: 16 } ] );
		assert.equal( sent.submitter, button, 'Original upload button is retained' );
		assert.equal( sent.button, 'wpUpload' );
		assert.equal( sent.token, 'synthetic-token' );
		assert.equal( sent.description, 'Synthetic upload regression' );
		assert.equal( test.accepted.length, 1 );
		assert.equal( test.revoked.length, 1, 'Image object URL is released' );
	} );
	await withFixture( {}, async test => {
		test.select();
		test.destination.value = 'edited_target.webp';
		test.submit( 'wpUploadIgnoreWarning' );
		await test.submitted();
		assert.equal( test.accepted[ 0 ].file.type, 'image/webp', 'Uses edited destination extension' );
		assert.equal( test.accepted[ 0 ].button, 'wpUploadIgnoreWarning' );
		const pending = JSON.parse( test.window.sessionStorage.getItem( 'eqlPendingUploadPurge' ) );
		assert.equal( pending.destFile, 'edited target.webp', 'Purge follows the submitted filename' );
		assert.equal( pending.returnTo, 'A mimic' );
		assert.deepEqual( pending.alsoPurge, [ 'Synthetic zone' ] );
	} );
	for ( const [ mime, extension ] of [ [ 'image/png', 'png' ], [ 'image/jpeg', 'jpeg' ],
		[ 'image/gif', 'gif' ], [ 'image/webp', 'webp' ] ] ) {
		await withFixture( {}, async test => {
			test.destination.value = 'matching.' + extension;
			const original = test.select( mime );
			test.submit();
			await test.submitted();
			if ( mime === 'image/png' ) {
				assert.notEqual( test.accepted[ 0 ].file, original, 'Matching PNG is normalized to repair server decoder compatibility' );
				assert.equal( test.encoded.length, 1 );
			} else {
				assert.equal( test.accepted[ 0 ].file, original, mime + ': matching image bytes stay intact' );
				assert.equal( test.encoded.length, 0, mime + ': no unnecessary re-encoding' );
			}
		} );
	}
	await withFixture( {}, async test => {
		test.select( 'image/png' );
		test.destination.value = 'transparent_to_jpeg.JPG';
		test.submit();
		await test.submitted();
		assert.equal( test.accepted[ 0 ].file.type, 'image/jpeg', 'Destination matching ignores extension case' );
		assert.deepEqual( test.fills, [ { color: '#000', args: [ 0, 0, 24, 16 ] } ],
			'JPEG conversion preserves the historical black backdrop for transparent pixels' );
	} );
	await withFixture( {}, async test => {
		test.select( 'image/gif', 'image/gif', 'animation.gif' );
		test.submit();
		await test.submitted();
		assert.equal( test.accepted[ 0 ].file.type, 'image/png', 'GIF can be decoded to the requested static PNG' );
		assert.equal( test.encoded.length, 1 );
	} );
	for ( const mime of [ 'image/bmp', 'image/avif', 'image/svg+xml' ] ) {
		await withFixture( { nativeSourceHandling: true }, async test => {
			test.select( mime, mime, 'source.' + mime.split( '/' )[ 1 ] );
			assert.equal( test.input.files.length, 1, mime + ': preset destination preserves source selection' );
			assert.equal( test.destination.value, 'npc_a_mimic.png' );
			test.submit();
			await test.submitted();
			assert.equal( test.accepted[ 0 ].file.type, 'image/png', mime + ': browser decoder can supply PNG output' );
			assert.equal( test.encoded.length, 1 );
		} );
	}
	await withFixture( { nativeSourceHandling: true, nativeAutofill: true }, async test => {
		const original = test.select( 'image/jpeg', '', 'native_screenshot.jpg' );
		assert.equal( test.destination.value, 'Native_screenshot.jpg', 'Generic upload keeps core filename autofill' );
		test.submit();
		await test.submitted();
		assert.equal( test.accepted[ 0 ].file, original, 'Generic JPEG keeps native bytes' );
		assert.equal( test.accepted[ 0 ].destination, 'Native_screenshot.jpg' );
		assert.equal( test.encoded.length, 0 );
	} );
	await withFixture( { nativeSourceHandling: true, nativeAutofill: true }, async test => {
		test.select( 'image/bmp', '', 'not_allowed_without_target.bmp' );
		assert.equal( test.input.files.length, 0, 'Generic uploads without a target retain native extension filtering' );
		assert.equal( test.destination.value, '' );
		assert.equal( test.decoded.length, 0 );
	} );
	for ( const fileType of [ '', 'image/png' ] ) {
		await withFixture( {}, async test => {
			test.select( 'image/jpeg', fileType );
			test.submit();
			await test.submitted();
			assert.equal( test.encoded.length, 1, 'Actual JPEG header wins over missing/misleading File.type' );
			assert.equal( test.accepted[ 0 ].file.type, 'image/png' );
		} );
	}
	await withFixture( {}, async test => {
		test.select();
		test.destination.value = 'converted_output.gif';
		test.submit();
		await test.submitted();
		const sent = test.accepted[ 0 ].file;
		assert.equal( sent.type, 'image/gif' );
		const output = await bytes( test.window, sent );
		assert.equal( Buffer.from( output.slice( 0, 6 ) ).toString(), 'GIF89a', 'Actual bundled GIF encoder emits GIF bytes' );
		assert.equal( output[ 6 ] + 256 * output[ 7 ], 24 );
		assert.equal( output[ 8 ] + 256 * output[ 9 ], 16 );
		assert.equal( output.at( -1 ), 59, 'GIF has an end-of-stream trailer' );
		assert.equal( test.encoded.length, 0, 'GIF uses its own encoder rather than unsupported canvas GIF output' );
	} );
	await withFixture( { failGifLoad: true }, async test => {
		const original = test.select();
		test.destination.value = 'unavailable_encoder.gif';
		test.submit();
		await test.failed();
		assert.equal( test.input.files[ 0 ], original, 'GIF dependency failure preserves source and supports retry' );
	} );
	await withFixture( { alphaPixels: [ 100, 127, 128, 255, 0 ] }, async test => {
		test.select( 'image/png' );
		test.destination.value = 'alpha_threshold.gif';
		test.submit();
		await test.submitted();
		const output = await bytes( test.window, test.accepted[ 0 ].file );
		assert.deepEqual( gifAlphas( output ).slice( 0, 5 ), [ 0, 0, 255, 255, 0 ],
			'Actual GIF pixels keep alpha 100/127 transparent and alpha 128/255 opaque beside transparent black' );
	} );
	await withFixture( { width: 32, height: 32, rareTransparency: true }, async test => {
		test.select( 'image/png' );
		test.destination.value = 'rare_transparency.gif';
		test.submit();
		await test.submitted();
		const alphas = gifAlphas( await bytes( test.window, test.accepted[ 0 ].file ) );
		assert.equal( alphas[ 0 ], 0, 'Palette reduction reserves even a single transparent pixel among more than256 colors' );
		assert.ok( alphas.slice( 1 ).every( alpha => alpha === 255 ), 'Opaque pixels remain opaque under palette pressure' );
	} );
	await withFixture( { alphaPixels: [ 0 ] }, async test => {
		test.select( 'image/png' );
		test.destination.value = 'entirely_transparent.gif';
		test.submit();
		await test.submitted();
		const alphas = gifAlphas( await bytes( test.window, test.accepted[ 0 ].file ) );
		assert.ok( alphas.every( alpha => alpha === 0 ), 'An entirely transparent image emits a valid transparent GIF' );
	} );
	await withFixture( { outputType: 'image/png' }, async test => {
		const original = test.select();
		test.destination.value = 'unsupported_encoder.webp';
		test.submit();
		await test.failed();
		assert.equal( test.input.files[ 0 ], original, 'Encoder fallback must not replace the selected file' );
	} );
	const retryOptions = { failDecode: true };
	await withFixture( retryOptions, async test => {
		const original = test.select();
		test.submit();
		await test.failed();
		assert.equal( test.input.files[ 0 ], original );
		retryOptions.failDecode = false;
		test.submit();
		await test.submitted();
		assert.equal( test.accepted.length, 1, 'Decode failure leaves upload retryable' );
		assert.equal( test.accepted[ 0 ].file.type, 'image/png' );
	} );
	await withFixture( { failEncode: true }, async test => {
		const original = test.select();
		test.submit();
		await test.failed();
		assert.equal( test.input.files[ 0 ], original, 'Canvas failure preserves the source' );
	} );
	await withFixture( { emptyEncode: true }, async test => {
		const original = test.select();
		test.submit();
		await test.failed();
		assert.equal( test.input.files[ 0 ], original, 'Empty encoder output must not replace the source' );
	} );
	await withFixture( { failRead: true }, async test => {
		const original = test.select();
		test.submit();
		await test.failed();
		assert.equal( test.input.files[ 0 ], original, 'File read failure preserves the source' );
		assert.equal( test.decoded.length, 0 );
	} );
	await withFixture( { failTransfer: true }, async test => {
		const original = test.select();
		test.submit();
		await test.failed();
		assert.equal( test.input.files[ 0 ], original, 'Unsupported FileList replacement preserves the source' );
	} );
	await withFixture( {}, async test => {
		await test.evaluateAgain();
		test.select();
		test.submit();
		await test.submitted();
		assert.equal( test.encoded.length, 1, 'Repeated module initialization binds conversion once' );
		assert.equal( test.accepted.length, 1 );
	} );
	await withFixture( { pauseDecode: true }, async test => {
		test.select();
		test.submit();
		test.submit();
		await test.releaseDecode();
		await test.submitted();
		assert.equal( test.accepted.length, 1, 'Double submit starts only one native upload' );
		assert.equal( test.encoded.length, 1 );
	} );
	await withFixture( { pauseDecode: true }, async test => {
		const original = test.select();
		test.submit();
		await waitFor( () => test.decoded.length, 'conversion started before cancellation' );
		test.submit( 'wpCancelUpload' );
		await test.releaseDecode();
		await waitFor( () => test.encodingDone(), 'canceled encoder completion' );
		assert.equal( test.accepted.length, 1, 'Canceled pending work cannot submit a late upload' );
		assert.equal( test.accepted[ 0 ].button, 'wpCancelUpload' );
		assert.equal( test.input.files[ 0 ], original, 'Cancel does not replace the selected file' );
		assert.equal( test.window.sessionStorage.getItem( 'eqlPendingUploadPurge' ), null );
	} );
	await withFixture( { pauseDecode: true }, async test => {
		const original = test.select();
		test.submit();
		await waitFor( () => test.decoded.length, 'conversion started before account switch' );
		test.config.wgUserId = 2;
		await test.releaseDecode();
		await test.failed();
		assert.equal( test.input.files[ 0 ], original, 'Old account conversion cannot replace source or upload' );
	} );
	for ( const change of [ 'destination', 'source' ] ) {
		await withFixture( { pauseDecode: true }, async test => {
			const original = test.select();
			test.submit();
			await waitFor( () => test.decoded.length, 'conversion started before mutation' );
			if ( change === 'destination' ) {
				test.destination.value = 'changed_while_decoding.webp';
			} else {
				test.select( 'image/png', 'image/png', 'replacement.png' );
			}
			const selected = test.input.files[ 0 ];
			await test.releaseDecode();
			await test.failed();
			assert.equal( test.input.files[ 0 ], selected, 'Pending conversion must not overwrite current selection' );
			if ( change === 'destination' ) {
				assert.equal( selected, original );
			}
		} );
	}
	await withFixture( {}, async test => {
		test.select();
		test.input.disabled = true;
		test.submit();
		assert.equal( test.accepted.length, 1, 'Disabled file source leaves URL upload flow alone' );
		assert.equal( test.decoded.length, 0 );
	} );
	await withFixture( {}, async test => {
		test.submit();
		assert.equal( test.accepted.length, 1, 'Empty file source leaves stash/native validation alone' );
	} );
	await withFixture( {}, async test => {
		test.select();
		test.destination.value = 'unsupported.constructor';
		test.submit();
		assert.equal( test.accepted.length, 1, 'Unsupported destination suffix uses native validation' );
		assert.equal( test.decoded.length, 0, 'Only explicit supported output formats enter conversion' );
	} );
	await withFixture( {}, async test => {
		test.select();
		test.submit( 'wpCancelUpload' );
		assert.equal( test.accepted.length, 1, 'Cancel is not intercepted for conversion' );
		assert.equal( test.accepted[ 0 ].button, 'wpCancelUpload' );
		assert.equal( test.decoded.length, 0 );
	} );
	console.log( 'Upload converter DOM regressions passed (synthetic decode/encode mocks; no live upload).' );
}

main().catch( error => {
	console.error( error );
	process.exitCode = 1;
} );
