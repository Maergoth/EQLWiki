/* EQLImmersive: quick page stats + persistent on-demand diagnostics.
 *
 * Normal use:
 * - Hover/focus the cog for lightweight current-page stats.
 * - No observers, timers, computed-style scans, or WebGL checks run.
 *
 * Detailed capture:
 * - First click starts recording.
 * - Recording persists through same-tab, same-origin wiki navigation.
 * - Reproduce the problem, including navigating between pages.
 * - Click the cog again to stop, inspect the current page, and copy a report.
 */
(() => {
	'use strict';

	if ( window.eqlPerfGearInstalled ) {
		return;
	}
	window.eqlPerfGearInstalled = true;

	const STORAGE_KEY = 'eqlPerfCaptureSessionV5';
	const VERSION = 5;
	const MAX_CAPTURE_MS = 10 * 60 * 1000;
	const MAX_PAGES = 25;
	const MAX_ITEMS = 20;
	const MAX_ERRORS = 30;
	const CSS_SAMPLE_LIMIT = 1500;
	const LOOP_INTERVAL = 250;

	let runtime = null;
	let memorySession = null;
	let hideTimer = null;
	let statusTimer = null;
	let expiryTimer = null;
	let storageAvailable = testStorage();

	const perfNow = () => window.performance?.now ?
		performance.now() :
		Date.now();

	const formatDuration = value => {
		value = Number( value ) || 0;

		return value >= 1000 ?
			`${ ( value / 1000 ).toFixed( 3 ) } s` :
			`${ value.toFixed( 1 ) } ms`;
	};

	const formatBytes = value => {
		const units = [ 'B', 'KiB', 'MiB', 'GiB' ];
		let number = Number( value ) || 0;
		let unit = 0;
		const sign = number < 0 ? '-' : '';

		number = Math.abs( number );

		while ( number >= 1024 && unit < units.length - 1 ) {
			number /= 1024;
			unit++;
		}

		return `${ sign }${
			unit ?
				number.toFixed( 2 ) :
				Math.round( number )
		} ${ units[ unit ] }`;
	};

	const formatNumber = ( value, decimals = 0 ) =>
		Number.isFinite( Number( value ) ) ?
			Number( value ).toFixed( decimals ) :
			null;

	const sanitizeUrl = value => {
		try {
			const url = new URL(
				String( value || '' ),
				location.href
			);

			return url.origin + url.pathname;
		} catch ( error ) {
			return String( value || '' )
				.split( '?' )[ 0 ]
				.split( '#' )[ 0 ];
		}
	};

	const supportedTypes = () =>
		Array.isArray(
			window.PerformanceObserver?.supportedEntryTypes
		) ?
			PerformanceObserver.supportedEntryTypes.slice() :
			[];

	const navigationEntry = () =>
		performance?.getEntriesByType?.( 'navigation' )?.[ 0 ] ||
		null;

	const resourceEntries = () =>
		performance?.getEntriesByType?.( 'resource' ) ||
		[];

	const paintEntries = () =>
		performance?.getEntriesByType?.( 'paint' ) ||
		[];

	const hasType = ( page, type ) =>
		page.performanceEntryTypes?.includes( type );

	function testStorage() {
		try {
			sessionStorage.setItem(
				`${ STORAGE_KEY }.test`,
				'1'
			);

			sessionStorage.removeItem(
				`${ STORAGE_KEY }.test`
			);

			return true;
		} catch ( error ) {
			return false;
		}
	}

	function readSession() {
		if ( !storageAvailable ) {
			return memorySession;
		}

		try {
			const parsed = JSON.parse(
				sessionStorage.getItem( STORAGE_KEY ) ||
				'null'
			);

			return parsed?.version === VERSION ?
				parsed :
				null;
		} catch ( error ) {
			return null;
		}
	}

	function writeSession( session ) {
		memorySession = session;

		if ( !storageAvailable ) {
			return;
		}

		try {
			sessionStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( session )
			);
		} catch ( error ) {
			storageAvailable = false;
		}
	}

	function clearSession() {
		memorySession = null;

		if ( storageAvailable ) {
			try {
				sessionStorage.removeItem(
					STORAGE_KEY
				);
			} catch ( error ) {}
		}
	}

	function navigationSummary() {
		const navigation = navigationEntry();

		if ( !navigation ) {
			return null;
		}

		return {
			type: navigation.type || '',
			redirectCount:
				Number( navigation.redirectCount ) ||
				0,

			redirectTime: Math.max(
				0,
				navigation.redirectEnd -
					navigation.redirectStart
			),

			dns: Math.max(
				0,
				navigation.domainLookupEnd -
					navigation.domainLookupStart
			),

			tcp: Math.max(
				0,
				navigation.connectEnd -
					navigation.connectStart
			),

			tls:
				navigation.secureConnectionStart > 0 ?
					Math.max(
						0,
						navigation.connectEnd -
							navigation.secureConnectionStart
					) :
					null,

			ttfb:
				navigation.responseStart &&
				navigation.requestStart ?
					Math.max(
						0,
						navigation.responseStart -
							navigation.requestStart
					) :
					0,

			responseDownload:
				navigation.responseEnd &&
				navigation.responseStart ?
					Math.max(
						0,
						navigation.responseEnd -
							navigation.responseStart
					) :
					0,

			domInteractive:
				Number(
					navigation.domInteractive
				) ||
				0,

			domContentLoadedEnd:
				Number(
					navigation.domContentLoadedEventEnd
				) ||
				0,

			domComplete:
				Number(
					navigation.domComplete
				) ||
				0,

			loadEventEnd:
				Number(
					navigation.loadEventEnd
				) ||
				0,

			transferSize:
				Number(
					navigation.transferSize
				) ||
				0,

			encodedBodySize:
				Number(
					navigation.encodedBodySize
				) ||
				0,

			decodedBodySize:
				Number(
					navigation.decodedBodySize
				) ||
				0,

			nextHopProtocol:
				navigation.nextHopProtocol ||
				'',

			responseStatus:
				typeof navigation.responseStatus ===
					'number' ?
					navigation.responseStatus :
					null,

			serverTiming: Array.from(
				navigation.serverTiming ||
					[],

				item => ( {
					name:
						item.name ||
						'',

					duration:
						Number(
							item.duration
						) ||
						0,

					description:
						item.description ||
						''
				} )
			)
		};
	}

	function paintSummary() {
		const result = {};

		paintEntries().forEach( entry => {
			result[ entry.name ] =
				Number( entry.startTime ) ||
				0;
		} );

		return result;
	}

	function memorySnapshot() {
		const memory = performance?.memory;

		return memory ?
			{
				jsHeapSizeLimit:
					Number(
						memory.jsHeapSizeLimit
					) ||
					0,

				totalJSHeapSize:
					Number(
						memory.totalJSHeapSize
					) ||
					0,

				usedJSHeapSize:
					Number(
						memory.usedJSHeapSize
					) ||
					0
			} :
			null;
	}

	function quickTiming() {
		const navigation = navigationEntry();

		if ( navigation ) {
			return {
				finished:
					navigation.loadEventEnd ||
					performance.now(),

				usable:
					navigation.domContentLoadedEventEnd ||
					0,

				server:
					navigation.responseStart &&
					navigation.requestStart ?
						navigation.responseStart -
							navigation.requestStart :
						0
			};
		}

		const timing = performance?.timing;

		return timing ?
			{
				finished:
					timing.loadEventEnd ?
						timing.loadEventEnd -
							timing.navigationStart :
						performance.now(),

				usable:
					timing.domContentLoadedEventEnd ?
						timing.domContentLoadedEventEnd -
							timing.navigationStart :
						0,

				server:
					timing.responseStart &&
					timing.requestStart ?
						timing.responseStart -
							timing.requestStart :
						0
			} :
			null;
	}

	function resourceSummary( full = false ) {
		const entries = resourceEntries();
		const groups = {};

		let transfer = 0;
		let encoded = 0;
		let decoded = 0;
		let cached = 0;

		entries.forEach( entry => {
			const type =
				entry.initiatorType ||
				'other';

			const transferred =
				Number(
					entry.transferSize
				) ||
				0;

			const encodedSize =
				Number(
					entry.encodedBodySize
				) ||
				0;

			const decodedSize =
				Number(
					entry.decodedBodySize
				) ||
				0;

			transfer += transferred;
			encoded += encodedSize;
			decoded += decodedSize;

			if (
				transferred === 0 &&
				decodedSize > 0
			) {
				cached++;
			}

			if ( full ) {
				groups[ type ] ||= {
					count: 0,
					duration: 0,
					transfer: 0,
					decoded: 0
				};

				groups[ type ].count++;

				groups[ type ].duration +=
					Number(
						entry.duration
					) ||
					0;

				groups[ type ].transfer +=
					transferred;

				groups[ type ].decoded +=
					decodedSize;
			}
		} );

		const result = {
			count: entries.length,
			transfer,
			encoded,
			decoded,
			cached
		};

		if ( full ) {
			result.groups = groups;

			result.slowest = entries
				.slice()
				.sort(
					( first, second ) =>
						(
							second.duration ||
							0
						) -
						(
							first.duration ||
							0
						)
				)
				.slice(
					0,
					MAX_ITEMS
				)
				.map(
					entry => ( {
						url:
							sanitizeUrl(
								entry.name
							),

						type:
							entry.initiatorType ||
							'other',

						duration:
							Number(
								entry.duration
							) ||
							0,

						startTime:
							Number(
								entry.startTime
							) ||
							0,

						transferSize:
							Number(
								entry.transferSize
							) ||
							0,

						decodedBodySize:
							Number(
								entry.decodedBodySize
							) ||
							0,

						nextHopProtocol:
							entry.nextHopProtocol ||
							'',

						responseStatus:
							typeof entry.responseStatus ===
								'number' ?
								entry.responseStatus :
								null
					} )
				);
		}

		return result;
	}

	function findFooter() {
		return document.getElementById(
			'footer'
		) ||
			document.querySelector(
				'.mw-footer-container, .mw-footer, footer'
			) ||
			document.body;
	}

	function ensurePanel() {
		let panel = document.getElementById(
			'eql-perf-panel'
		);

		if ( panel ) {
			return panel;
		}

		panel = document.createElement( 'div' );
		panel.id = 'eql-perf-panel';

		const title = document.createElement( 'div' );
		title.className = 'eql-perf-title';
		title.textContent = 'Page Load Stats';

		panel.appendChild( title );
		document.body.appendChild( panel );

		panel.addEventListener(
			'mouseenter',
			() => {
				clearTimeout( hideTimer );
				hideTimer = null;
			}
		);

		panel.addEventListener(
			'mouseleave',
			scheduleHide
		);

		return panel;
	}

	function row(
		label,
		value,
		status = 'good',
		title = ''
	) {
		const element =
			document.createElement( 'div' );

		element.className =
			`eql-perf-row eql-perf-${ status }`;

		element.title = title;

		const name =
			document.createElement( 'span' );

		name.className = 'eql-perf-label';
		name.textContent = label;

		const number =
			document.createElement( 'span' );

		number.className = 'eql-perf-number';
		number.textContent = value;

		element.append(
			name,
			number
		);

		return element;
	}

	function ensureWidget() {
		let widget =
			document.getElementById(
				'eql-perf-gear-widget'
			);

		if ( widget ) {
			return widget;
		}

		widget =
			document.createElement( 'span' );

		widget.id =
			'eql-perf-gear-widget';

		const button =
			document.createElement( 'button' );

		button.id =
			'eql-perf-gear-button';

		button.type = 'button';

		const gear =
			document.createElement( 'span' );

		gear.className =
			'eql-perf-gear-symbol';

		gear.textContent = '⚙';

		const indicator =
			document.createElement( 'span' );

		indicator.className =
			'eql-perf-question';

		indicator.textContent = '?';

		button.append(
			gear,
			indicator
		);

		button.addEventListener(
			'click',
			handleClick
		);

		button.addEventListener(
			'mouseenter',
			showPanel
		);

		button.addEventListener(
			'focus',
			showPanel
		);

		button.addEventListener(
			'mouseleave',
			scheduleHide
		);

		button.addEventListener(
			'blur',
			scheduleHide
		);

		widget.appendChild( button );

		const footer = findFooter();

		footer.appendChild( widget );

		footer.classList.add(
			'eql-perf-widget-ready'
		);

		refreshButton();

		return widget;
	}

	function positionPanel() {
		const button =
			document.getElementById(
				'eql-perf-gear-button'
			);

		const panel = ensurePanel();

		if ( !button ) {
			return;
		}

		const rectangle =
			button.getBoundingClientRect();

		const width =
			panel.offsetWidth ||
			340;

		const height =
			panel.offsetHeight ||
			200;

		const left = Math.max(
			8,
			Math.min(
				rectangle.right -
					width,

				innerWidth -
					width -
					8
			)
		);

		const top =
			rectangle.top -
				height -
				8 <
			8 ?
				rectangle.bottom +
					8 :
				rectangle.top -
					height -
					8;

		panel.style.left =
			`${ left }px`;

		panel.style.top =
			`${ top }px`;
	}

	function captureNote( session ) {
		if ( session?.status === 'active' ) {
			return (
				'Detailed capture active across wiki pages. ' +
				`Pages: ${ session.pages.length }. ` +
				`Elapsed: ${ formatDuration(
					Date.now() -
						session.startedAt
				) }. ` +
				'Click the cog to stop and copy.'
			);
		}

		if ( session?.status === 'ready' ) {
			return (
				'The previous capture stopped. ' +
				'Click the cog to build and copy its report.'
			);
		}

		if ( session?.status === 'copied' ) {
			return (
				'The previous report was copied. ' +
				'Click the cog to start a new capture.'
			);
		}

		return (
			'Hover shows quick current-page stats. ' +
			'Click the cog to start a detailed capture ' +
			'that follows this tab across wiki pages.'
		);
	}

	function updatePanel( message = '' ) {
		const panel = ensurePanel();

		panel.querySelectorAll(
			'.eql-perf-row, .eql-perf-note'
		).forEach(
			element => element.remove()
		);

		const timing = quickTiming();
		const resources = resourceSummary();

		if ( timing ) {
			panel.appendChild(
				row(
					'Page finished',
					formatDuration(
						timing.finished
					),
					timing.finished <= 2000 ?
						'good' :
						timing.finished <= 4000 ?
							'okay' :
							'bad'
				)
			);

			panel.appendChild(
				row(
					'Page usable',
					formatDuration(
						timing.usable
					),
					timing.usable <= 1000 ?
						'good' :
						timing.usable <= 2500 ?
							'okay' :
							'bad'
				)
			);

			panel.appendChild(
				row(
					'Server wait',
					formatDuration(
						timing.server
					),
					timing.server <= 200 ?
						'good' :
						timing.server <= 600 ?
							'okay' :
							'bad'
				)
			);
		}

		panel.appendChild(
			row(
				'Resources',
				`${ resources.count } / ${
					formatBytes(
						resources.transfer
					)
				}`
			)
		);

		const note =
			document.createElement( 'div' );

		note.className =
			'eql-perf-note';

		note.textContent =
			message ||
			captureNote(
				readSession()
			);

		panel.appendChild( note );
	}

	function showPanel() {
		clearTimeout( hideTimer );
		hideTimer = null;

		updatePanel();

		ensurePanel().classList.add(
			'eql-perf-panel-visible'
		);

		positionPanel();
	}

	function scheduleHide() {
		clearTimeout( hideTimer );

		hideTimer = setTimeout(
			() => {
				ensurePanel().classList.remove(
					'eql-perf-panel-visible'
				);
			},
			250
		);
	}

	function setButton(
		symbol,
		className,
		label
	) {
		const button =
			document.getElementById(
				'eql-perf-gear-button'
			);

		if ( !button ) {
			return;
		}

		button.className =
			className;

		button.title =
			label;

		button.setAttribute(
			'aria-label',
			label
		);

		button.querySelector(
			'.eql-perf-question'
		).textContent = symbol;
	}

	function refreshButton() {
		const session = readSession();

		if ( session?.status === 'active' ) {
			setButton(
				'●',
				'eql-perf-bad',
				'Recording diagnostics across wiki pages. ' +
					'Click to stop and copy.'
			);

			return;
		}

		if ( session?.status === 'ready' ) {
			setButton(
				'!',
				'eql-perf-okay',
				'Capture stopped. ' +
					'Click to build and copy diagnostics.'
			);

			return;
		}

		if ( session?.status === 'copied' ) {
			setButton(
				'✓',
				'eql-perf-good',
				'Diagnostics copied. ' +
					'Click to start a new capture.'
			);

			return;
		}

		setButton(
			'?',
			'eql-perf-good',
			'Page load stats. ' +
				'Click to start persistent diagnostics.'
		);
	}

	const newId = () =>
		`${ Date.now().toString( 36 ) }-${
			Math.random()
				.toString( 36 )
				.slice( 2, 10 )
		}`;

	function createSession() {
		const startedAt = Date.now();

		return {
			version: VERSION,
			status: 'active',
			startedAt,
			expiresAt:
				startedAt +
				MAX_CAPTURE_MS,

			endedAt: null,
			autoStopped: false,
			stopReason: '',
			storageBacked:
				storageAvailable,

			startPage:
				sanitizeUrl(
					location.href
				),

			pages: []
		};
	}

	function pageRecord( source ) {
		return {
			id: newId(),
			source,
			url:
				sanitizeUrl(
					location.href
				),

			title:
				document.title,

			captureStartedAt:
				new Date().toISOString(),

			captureEndedAt:
				null,

			captureDuration:
				0,

			performanceEntryTypes:
				supportedTypes(),

			navigation:
				navigationSummary(),

			paints:
				paintSummary(),

			viewportAtStart: {
				width:
					innerWidth,

				height:
					innerHeight,

				devicePixelRatio:
					devicePixelRatio ||
					1
			},

			viewportAtEnd:
				null,

			documentAtEnd:
				null,

			memoryAtStart:
				memorySnapshot(),

			memoryAtEnd:
				null,

			resourcesAtEnd:
				null,

			metrics:
				null
		};
	}

	function addListener(
		target,
		type,
		handler,
		options
	) {
		target.addEventListener(
			type,
			handler,
			options
		);

		runtime.listeners.push( {
			target,
			type,
			handler,
			options
		} );
	}

	function observe(
		type,
		callback,
		options
	) {
		if (
			!runtime.types.includes(
				type
			)
		) {
			return;
		}

		try {
			const observer =
				new PerformanceObserver(
					callback
				);

			observer.observe(
				options ||
				{
					type
				}
			);

			runtime.observers.push(
				observer
			);
		} catch ( error ) {}
	}

	function startObservers() {
		observe(
			'longtask',
			list => {
				list.getEntries().forEach(
					entry => {
						if (
							runtime.metrics.longTasks.length <
							200
						) {
							runtime.metrics.longTasks.push( {
								startTime:
									entry.startTime ||
									0,

								duration:
									entry.duration ||
									0,

								name:
									entry.name ||
									''
							} );
						}
					}
				);
			},
			{
				type: 'longtask',
				buffered: false
			}
		);

		observe(
			'event',
			list => {
				list.getEntries().forEach(
					entry => {
						if (
							runtime.metrics.events.length <
							300
						) {
							runtime.metrics.events.push( {
								name:
									entry.name ||
									'',

								startTime:
									entry.startTime ||
									0,

								duration:
									entry.duration ||
									0,

								processingStart:
									entry.processingStart ||
									0,

								processingEnd:
									entry.processingEnd ||
									0,

								interactionId:
									entry.interactionId ||
									0
							} );
						}
					}
				);
			},
			{
				type: 'event',
				buffered: false,
				durationThreshold: 16
			}
		);

		observe(
			'layout-shift',
			list => {
				list.getEntries().forEach(
					entry => {
						if (
							runtime.metrics.layoutShifts.length <
							200
						) {
							runtime.metrics.layoutShifts.push( {
								startTime:
									entry.startTime ||
									0,

								value:
									entry.value ||
									0,

								hadRecentInput:
									!!entry.hadRecentInput
							} );
						}
					}
				);
			},
			{
				type:
					'layout-shift',

				buffered:
					false
			}
		);
	}

	function sampleScrollFrame( timestamp ) {
		if ( !runtime ) {
			return;
		}

		const scroll =
			runtime.metrics.scroll;

		if ( scroll.lastFrame ) {
			const delta =
				timestamp -
				scroll.lastFrame;

			scroll.samples++;

			scroll.worst =
				Math.max(
					scroll.worst,
					delta
				);

			if ( delta >= 34 ) {
				scroll.over34++;
				scroll.totalSlow += delta;
			}

			if ( delta >= 50 ) {
				scroll.over50++;
			}

			if ( delta >= 100 ) {
				scroll.over100++;
			}

			if ( delta >= 250 ) {
				scroll.over250++;
			}
		}

		scroll.lastFrame =
			timestamp;

		if (
			timestamp <
			scroll.until
		) {
			scroll.raf =
				requestAnimationFrame(
					sampleScrollFrame
				);
		} else {
			scroll.running = false;
			scroll.lastFrame = 0;
			scroll.raf = null;
		}
	}

	function onScroll() {
		if ( !runtime ) {
			return;
		}

		const scroll =
			runtime.metrics.scroll;

		const current =
			scrollY ||
			pageYOffset ||
			0;

		scroll.events++;

		scroll.distance +=
			Math.abs(
				current -
					scroll.lastY
			);

		scroll.lastY =
			current;

		scroll.maxY =
			Math.max(
				scroll.maxY,
				current
			);

		scroll.until =
			perfNow() +
			500;

		if (
			!scroll.running &&
			window.requestAnimationFrame
		) {
			scroll.running = true;
			scroll.lastFrame = 0;

			scroll.raf =
				requestAnimationFrame(
					sampleScrollFrame
				);
		}
	}

	function startRuntimeMonitoring() {
		startObservers();

		let expected =
			perfNow() +
			LOOP_INTERVAL;

		runtime.loopTimer =
			setInterval(
				() => {
					if ( !runtime ) {
						return;
					}

					const current =
						perfNow();

					if (
						document.visibilityState ===
						'hidden'
					) {
						expected =
							current +
							LOOP_INTERVAL;

						return;
					}

					const delay =
						Math.max(
							0,
							current -
								expected
						);

					const loop =
						runtime.metrics.eventLoop;

					loop.samples++;

					loop.worst =
						Math.max(
							loop.worst,
							delay
						);

					if ( delay >= 50 ) {
						loop.over50++;
						loop.totalOver50 += delay;
					}

					if ( delay >= 100 ) {
						loop.over100++;
					}

					if ( delay >= 250 ) {
						loop.over250++;
					}

					if ( delay >= 1000 ) {
						loop.over1000++;
					}

					expected =
						current +
						LOOP_INTERVAL;
				},
				LOOP_INTERVAL
			);

		addListener(
			window,
			'scroll',
			onScroll,
			{
				passive: true
			}
		);

		[
			'click',
			'pointerdown',
			'keydown',
			'input',
			'change',
			'wheel',
			'touchstart'
		].forEach(
			type => {
				addListener(
					document,
					type,
					event => {
						if ( runtime ) {
							runtime.metrics.interactions[
								event.type
							] = (
								runtime.metrics.interactions[
									event.type
								] ||
								0
							) + 1;
						}
					},
					{
						capture: true,
						passive:
							type === 'wheel' ||
							type === 'touchstart'
					}
				);
			}
		);

		addListener(
			document,
			'visibilitychange',
			() => {
				if ( runtime ) {
					runtime.metrics.visibility.push( {
						time:
							perfNow() -
								runtime.startedPerf,

						state:
							document.visibilityState
					} );
				}
			}
		);

		addListener(
			window,
			'resize',
			() => {
				if ( runtime ) {
					runtime.metrics.resizeCount++;
				}
			}
		);

		addListener(
			window,
			'error',
			event => {
				if ( !runtime ) {
					return;
				}

				const target =
					event.target;

				if (
					target &&
					target !== window &&
					(
						target.src ||
						target.href
					)
				) {
					if (
						runtime.metrics.resourceErrors.length <
						MAX_ERRORS
					) {
						runtime.metrics.resourceErrors.push( {
							tag:
								target.tagName ||
								'',

							url:
								sanitizeUrl(
									target.currentSrc ||
									target.src ||
									target.href ||
									''
								)
						} );
					}

					return;
				}

				if (
					runtime.metrics.jsErrors.length <
					MAX_ERRORS
				) {
					runtime.metrics.jsErrors.push( {
						message:
							String(
								event.message ||
								'Unknown error'
							).slice(
								0,
								500
							),

						file:
							sanitizeUrl(
								event.filename ||
								''
							),

						line:
							event.lineno ||
							0,

						column:
							event.colno ||
							0
					} );
				}
			},
			true
		);

		addListener(
			window,
			'unhandledrejection',
			event => {
				if (
					!runtime ||
					runtime.metrics.rejections.length >=
						MAX_ERRORS
				) {
					return;
				}

				let message;

				try {
					message =
						event.reason?.message ||
						String(
							event.reason
						);
				} catch ( error ) {
					message =
						'Unserializable rejection';
				}

				runtime.metrics.rejections.push(
					String(
						message ||
						'Unknown rejection'
					).slice(
						0,
						500
					)
				);
			}
		);

		addListener(
			document,
			'securitypolicyviolation',
			event => {
				if (
					!runtime ||
					runtime.metrics.csp.length >=
						MAX_ERRORS
				) {
					return;
				}

				runtime.metrics.csp.push( {
					directive:
						event.violatedDirective ||
						'',

					blocked:
						sanitizeUrl(
							event.blockedURI ||
							''
						),

					disposition:
						event.disposition ||
						'',

					status:
						event.statusCode ||
						0
				} );
			}
		);
	}

	function beginPageCapture( source ) {
		const session = readSession();

		if (
			!session ||
			session.status !== 'active' ||
			runtime
		) {
			return;
		}

		if (
			session.pages.length >=
			MAX_PAGES
		) {
			session.status = 'ready';
			session.endedAt = Date.now();
			session.autoStopped = true;
			session.stopReason =
				'Maximum page count reached';

			writeSession( session );
			refreshButton();

			return;
		}

		const page =
			pageRecord( source );

		session.pages.push( page );
		writeSession( session );

		runtime = {
			pageId:
				page.id,

			startedPerf:
				perfNow(),

			types:
				page.performanceEntryTypes.slice(),

			observers:
				[],

			listeners:
				[],

			loopTimer:
				null,

			metrics: {
				longTasks:
					[],

				events:
					[],

				layoutShifts:
					[],

				interactions:
					{},

				jsErrors:
					[],

				rejections:
					[],

				resourceErrors:
					[],

				csp:
					[],

				visibility:
					[],

				resizeCount:
					0,

				eventLoop: {
					samples: 0,
					over50: 0,
					over100: 0,
					over250: 0,
					over1000: 0,
					totalOver50: 0,
					worst: 0
				},

				scroll: {
					events: 0,
					distance: 0,

					maxY:
						scrollY ||
						pageYOffset ||
						0,

					lastY:
						scrollY ||
						pageYOffset ||
						0,

					samples: 0,
					over34: 0,
					over50: 0,
					over100: 0,
					over250: 0,
					totalSlow: 0,
					worst: 0,
					running: false,
					lastFrame: 0,
					until: 0,
					raf: null
				}
			}
		};

		startRuntimeMonitoring();
		scheduleExpiry();
		refreshButton();
	}

	function stopRuntime() {
		if ( !runtime ) {
			return;
		}

		runtime.observers.forEach(
			observer => {
				try {
					observer.disconnect();
				} catch ( error ) {}
			}
		);

		runtime.listeners.forEach(
			item => {
				try {
					item.target.removeEventListener(
						item.type,
						item.handler,
						item.options
					);
				} catch ( error ) {}
			}
		);

		clearInterval(
			runtime.loopTimer
		);

		if (
			runtime.metrics.scroll.raf &&
			window.cancelAnimationFrame
		) {
			cancelAnimationFrame(
				runtime.metrics.scroll.raf
			);
		}
	}

	function summary( entries, key ) {
		const sorted = entries
			.slice()
			.sort(
				( first, second ) =>
					(
						second[ key ] ||
						0
					) -
					(
						first[ key ] ||
						0
					)
			);

		return {
			count:
				entries.length,

			total:
				entries.reduce(
					( total, item ) =>
						total +
						(
							Number(
								item[ key ]
							) ||
							0
						),
					0
				),

			worst:
				sorted[ 0 ]?.[ key ] ||
				0,

			top:
				sorted.slice(
					0,
					MAX_ITEMS
				)
		};
	}

	function layoutSummary( entries ) {
		const sorted = entries
			.slice()
			.sort(
				( first, second ) =>
					(
						second.value ||
						0
					) -
					(
						first.value ||
						0
					)
			);

		return {
			count:
				entries.length,

			total:
				entries.reduce(
					( total, item ) =>
						total +
						(
							item.value ||
							0
						),
					0
				),

			unexpected:
				entries.reduce(
					( total, item ) =>
						total +
						(
							item.hadRecentInput ?
								0 :
								item.value ||
									0
						),
					0
				),

			worst:
				sorted[ 0 ]?.value ||
				0,

			top:
				sorted.slice(
					0,
					MAX_ITEMS
				)
		};
	}

	function finalizePage() {
		if ( !runtime ) {
			return;
		}

		stopRuntime();

		const session =
			readSession();

		const page =
			session?.pages.find(
				item =>
					item.id ===
					runtime.pageId
			);

		if (
			!session ||
			!page
		) {
			runtime = null;
			return;
		}

		const metrics =
			runtime.metrics;

		page.captureEndedAt =
			new Date().toISOString();

		page.captureDuration =
			Math.max(
				0,
				perfNow() -
					runtime.startedPerf
			);

		page.navigation =
			navigationSummary();

		page.paints =
			paintSummary();

		page.viewportAtEnd = {
			width:
				innerWidth,

			height:
				innerHeight,

			devicePixelRatio:
				devicePixelRatio ||
				1,

			scrollX:
				Math.round(
					scrollX ||
					0
				),

			scrollY:
				Math.round(
					scrollY ||
					0
				)
		};

		page.documentAtEnd = {
			readyState:
				document.readyState,

			visibilityState:
				document.visibilityState,

			scrollWidth:
				Math.max(
					document.documentElement.scrollWidth,
					document.body?.scrollWidth ||
						0
				),

			scrollHeight:
				Math.max(
					document.documentElement.scrollHeight,
					document.body?.scrollHeight ||
						0
				)
		};

		page.memoryAtEnd =
			memorySnapshot();

		page.resourcesAtEnd =
			resourceSummary( true );

		page.metrics = {
			longTasks:
				summary(
					metrics.longTasks,
					'duration'
				),

			events:
				summary(
					metrics.events,
					'duration'
				),

			layoutShifts:
				layoutSummary(
					metrics.layoutShifts
				),

			interactions:
				metrics.interactions,

			jsErrors:
				metrics.jsErrors,

			rejections:
				metrics.rejections,

			resourceErrors:
				metrics.resourceErrors,

			csp:
				metrics.csp,

			visibility:
				metrics.visibility,

			resizeCount:
				metrics.resizeCount,

			eventLoop:
				metrics.eventLoop,

			scroll: {
				...metrics.scroll,
				raf: undefined,
				running: undefined,
				lastFrame: undefined,
				until: undefined
			}
		};

		writeSession( session );
		runtime = null;
	}

	function scheduleExpiry() {
		clearTimeout( expiryTimer );

		const session =
			readSession();

		if (
			session?.status ===
			'active'
		) {
			expiryTimer = setTimeout(
				autoStop,
				Math.max(
					0,
					session.expiresAt -
						Date.now()
				)
			);
		}
	}

	function autoStop() {
		finalizePage();

		const session =
			readSession();

		if (
			session?.status ===
			'active'
		) {
			session.status =
				'ready';

			session.endedAt =
				Date.now();

			session.autoStopped =
				true;

			session.stopReason =
				'Maximum capture duration reached';

			writeSession( session );
			refreshButton();
			updatePanel();
			showPanel();
		}
	}

	function startCapture() {
		clearSession();

		writeSession(
			createSession()
		);

		beginPageCapture(
			'manual-start'
		);

		updatePanel(
			'Detailed capture started. ' +
				'Reproduce the problem and navigate ' +
				'through wiki pages if useful. ' +
				'Click the cog again to stop and copy.'
		);

		showPanel();
	}

	function stopCapture() {
		finalizePage();

		const session =
			readSession();

		if ( !session ) {
			return null;
		}

		session.status =
			'ready';

		session.endedAt =
			Date.now();

		session.stopReason =
			'Stopped by user';

		writeSession( session );

		clearTimeout(
			expiryTimer
		);

		refreshButton();

		return session;
	}

	function resumeCapture( source ) {
		const session =
			readSession();

		if (
			!session ||
			session.status !== 'active'
		) {
			refreshButton();
			return;
		}

		if (
			Date.now() >=
			session.expiresAt
		) {
			session.status =
				'ready';

			session.endedAt =
				Date.now();

			session.autoStopped =
				true;

			session.stopReason =
				'Maximum capture duration reached';

			writeSession( session );
			refreshButton();

			return;
		}

		beginPageCapture( source );
	}

	function webGLSnapshot() {
		try {
			const canvas =
				document.createElement(
					'canvas'
				);

			const context =
				canvas.getContext(
					'webgl',
					{
						antialias: false,
						alpha: false,
						depth: false,
						stencil: false,
						preserveDrawingBuffer:
							false
					}
				) ||
				canvas.getContext(
					'experimental-webgl'
				);

			if ( !context ) {
				return null;
			}

			const debug =
				context.getExtension(
					'WEBGL_debug_renderer_info'
				);

			const result = {
				version:
					context.getParameter(
						context.VERSION
					) ||
					'',

				shadingLanguageVersion:
					context.getParameter(
						context.SHADING_LANGUAGE_VERSION
					) ||
					'',

				vendor:
					context.getParameter(
						context.VENDOR
					) ||
					'',

				renderer:
					context.getParameter(
						context.RENDERER
					) ||
					'',

				maxTextureSize:
					context.getParameter(
						context.MAX_TEXTURE_SIZE
					) ||
					0,

				maxRenderbufferSize:
					context.getParameter(
						context.MAX_RENDERBUFFER_SIZE
					) ||
					0,

				maxViewportDims:
					Array.from(
						context.getParameter(
							context.MAX_VIEWPORT_DIMS
						) ||
						[]
					)
			};

			if ( debug ) {
				result.unmaskedVendor =
					context.getParameter(
						debug.UNMASKED_VENDOR_WEBGL
					) ||
					'';

				result.unmaskedRenderer =
					context.getParameter(
						debug.UNMASKED_RENDERER_WEBGL
					) ||
					'';
			}

			context.getExtension(
				'WEBGL_lose_context'
			)?.loseContext();

			return result;
		} catch ( error ) {
			return null;
		}
	}

	function domSnapshot() {
		const all =
			document.getElementsByTagName(
				'*'
			);

		const sampleCount =
			Math.min(
				all.length,
				CSS_SAMPLE_LIMIT
			);

		const result = {
			elementCount:
				all.length,

			sampled:
				sampleCount,

			tagCounts:
				{},

			iframes:
				document.getElementsByTagName(
					'iframe'
				).length,

			forms:
				document.forms?.length ||
				0,

			inputs:
				document.querySelectorAll(
					'input, select, textarea, button'
				).length,

			links:
				document.links?.length ||
				0,

			tables:
				document.getElementsByTagName(
					'table'
				).length,

			tableRows:
				document.getElementsByTagName(
					'tr'
				).length,

			tableCells:
				document.querySelectorAll(
					'td, th'
				).length,

			svgs:
				document.getElementsByTagName(
					'svg'
				).length,

			canvases:
				document.getElementsByTagName(
					'canvas'
				).length,

			fixed: 0,
			sticky: 0,
			filters: 0,
			backdropFilters: 0,
			boxShadows: 0,
			textShadows: 0,
			transforms: 0,
			willChange: 0,
			fixedBackgrounds: 0,
			blendModes: 0,
			opacityLayers: 0,
			contentVisibility: 0,
			contain: 0
		};

		for (
			let index = 0;
			index < all.length;
			index++
		) {
			const element =
				all[ index ];

			const tag =
				element.tagName?.toLowerCase() ||
				'unknown';

			result.tagCounts[ tag ] =
				(
					result.tagCounts[ tag ] ||
					0
				) +
				1;

			if ( index >= sampleCount ) {
				continue;
			}

			try {
				const style =
					getComputedStyle(
						element
					);

				if (
					style.position ===
					'fixed'
				) {
					result.fixed++;
				} else if (
					style.position ===
					'sticky'
				) {
					result.sticky++;
				}

				if (
					style.filter &&
					style.filter !== 'none'
				) {
					result.filters++;
				}

				if (
					(
						style.backdropFilter &&
						style.backdropFilter !==
							'none'
					) ||
					(
						style.webkitBackdropFilter &&
						style.webkitBackdropFilter !==
							'none'
					)
				) {
					result.backdropFilters++;
				}

				if (
					style.boxShadow &&
					style.boxShadow !== 'none'
				) {
					result.boxShadows++;
				}

				if (
					style.textShadow &&
					style.textShadow !== 'none'
				) {
					result.textShadows++;
				}

				if (
					style.transform &&
					style.transform !== 'none'
				) {
					result.transforms++;
				}

				if (
					style.willChange &&
					style.willChange !== 'auto'
				) {
					result.willChange++;
				}

				if (
					style.backgroundAttachment ===
					'fixed'
				) {
					result.fixedBackgrounds++;
				}

				if (
					style.mixBlendMode &&
					style.mixBlendMode !== 'normal'
				) {
					result.blendModes++;
				}

				if (
					Number(
						style.opacity
					) <
					1
				) {
					result.opacityLayers++;
				}

				if (
					style.contentVisibility &&
					style.contentVisibility !==
						'visible'
				) {
					result.contentVisibility++;
				}

				if (
					style.contain &&
					style.contain !== 'none'
				) {
					result.contain++;
				}
			} catch ( error ) {}
		}

		return result;
	}

	function imageSnapshot() {
		const result = {
			count: 0,
			complete: 0,
			broken: 0,
			lazy: 0,
			asyncDecoding: 0,
			oversized: 0,
			naturalPixelArea: 0,
			displayedPixelArea: 0,
			largest: []
		};

		Array.from(
			document.images ||
			[]
		).forEach(
			image => {
				const rectangle =
					image.getBoundingClientRect();

				const natural =
					(
						image.naturalWidth ||
						0
					) *
					(
						image.naturalHeight ||
						0
					);

				const displayed =
					Math.max(
						0,
						rectangle.width
					) *
					Math.max(
						0,
						rectangle.height
					);

				result.count++;

				if ( image.complete ) {
					result.complete++;
				}

				if (
					image.complete &&
					image.naturalWidth === 0
				) {
					result.broken++;
				}

				if (
					image.loading ===
					'lazy'
				) {
					result.lazy++;
				}

				if (
					image.decoding ===
					'async'
				) {
					result.asyncDecoding++;
				}

				if (
					displayed > 0 &&
					natural >
						displayed *
							4
				) {
					result.oversized++;
				}

				result.naturalPixelArea +=
					natural;

				result.displayedPixelArea +=
					displayed;

				result.largest.push( {
					url:
						sanitizeUrl(
							image.currentSrc ||
							image.src ||
							''
						),

					naturalWidth:
						image.naturalWidth ||
						0,

					naturalHeight:
						image.naturalHeight ||
						0,

					displayedWidth:
						Math.round(
							rectangle.width
						),

					displayedHeight:
						Math.round(
							rectangle.height
						),

					loading:
						image.loading ||
						''
				} );
			}
		);

		result.largest.sort(
			( first, second ) =>
				(
					second.naturalWidth *
					second.naturalHeight
				) -
				(
					first.naturalWidth *
					first.naturalHeight
				)
		);

		result.largest =
			result.largest.slice(
				0,
				15
			);

		return result;
	}

	function stylesheetSnapshot() {
		const result = {
			count: 0,
			disabled: 0,
			accessible: 0,
			inaccessible: 0,
			ruleCount: 0,
			items: []
		};

		Array.from(
			document.styleSheets ||
			[]
		).forEach(
			sheet => {
				const item = {
					href:
						sheet.href ?
							sanitizeUrl(
								sheet.href
							) :
							'inline',

					disabled:
						!!sheet.disabled,

					rules:
						null
				};

				result.count++;

				if ( sheet.disabled ) {
					result.disabled++;
				}

				try {
					item.rules =
						sheet.cssRules?.length ||
						0;

					result.accessible++;
					result.ruleCount +=
						item.rules;
				} catch ( error ) {
					result.inaccessible++;
				}

				result.items.push( item );
			}
		);

		return result;
	}

	function fontSnapshot() {
		if ( !document.fonts ) {
			return null;
		}

		const result = {
			status:
				document.fonts.status ||
				'',

			count: 0,
			loaded: 0,
			loading: 0,
			unloaded: 0,
			error: 0,
			families: []
		};

		try {
			document.fonts.forEach(
				font => {
					result.count++;

					if (
						Object.prototype.hasOwnProperty.call(
							result,
							font.status
						)
					) {
						result[
							font.status
						]++;
					}

					if (
						result.families.length <
						50
					) {
						result.families.push( {
							family:
								font.family ||
								'',

							status:
								font.status ||
								'',

							weight:
								font.weight ||
								'',

							style:
								font.style ||
								''
						} );
					}
				}
			);
		} catch ( error ) {}

		return result;
	}

	function animationSnapshot() {
		if ( !document.getAnimations ) {
			return null;
		}

		try {
			const animations =
				document.getAnimations();

			const result = {
				count:
					animations.length,

				running: 0,
				paused: 0,
				finished: 0,
				idle: 0,
				infinite: 0
			};

			animations.forEach(
				animation => {
					if (
						Object.prototype.hasOwnProperty.call(
							result,
							animation.playState
						)
					) {
						result[
							animation.playState
						]++;
					}

					try {
						if (
							animation.effect
								?.getComputedTiming
								?.().iterations ===
							Infinity
						) {
							result.infinite++;
						}
					} catch ( error ) {}
				}
			);

			return result;
		} catch ( error ) {
			return null;
		}
	}

	function detailedSnapshot() {
		return {
			url:
				sanitizeUrl(
					location.href
				),

			title:
				document.title,

			navigation:
				navigationSummary(),

			paints:
				paintSummary(),

			dom:
				domSnapshot(),

			images:
				imageSnapshot(),

			stylesheets:
				stylesheetSnapshot(),

			fonts:
				fontSnapshot(),

			animations:
				animationSnapshot(),

			webgl:
				webGLSnapshot(),

			resources:
				resourceSummary( true )
		};
	}

	function reportWriter() {
		const lines = [];

		return {
			lines,

			section: title => {
				lines.push(
					'',
					`[${ title }]`
				);
			},

			add: ( label, value ) => {
				if (
					value !== null &&
					value !== undefined &&
					value !== ''
				) {
					lines.push(
						`${ label }: ${ value }`
					);
				}
			},

			bool: ( label, value ) => {
				if (
					typeof value ===
					'boolean'
				) {
					lines.push(
						`${ label }: ${
							value ?
								'Yes' :
								'No'
						}`
					);
				}
			}
		};
	}

	function addNavigation( output, navigation ) {
		if ( !navigation ) {
			return;
		}

		output.add(
			'Navigation type',
			navigation.type
		);

		output.add(
			'Redirect count',
			navigation.redirectCount
		);

		output.add(
			'Redirect time',
			formatDuration(
				navigation.redirectTime
			)
		);

		output.add(
			'DNS lookup',
			formatDuration(
				navigation.dns
			)
		);

		output.add(
			'TCP connect',
			formatDuration(
				navigation.tcp
			)
		);

		if ( navigation.tls !== null ) {
			output.add(
				'TLS negotiation',
				formatDuration(
					navigation.tls
				)
			);
		}

		output.add(
			'Time to first byte',
			formatDuration(
				navigation.ttfb
			)
		);

		output.add(
			'Response download',
			formatDuration(
				navigation.responseDownload
			)
		);

		output.add(
			'DOM interactive',
			formatDuration(
				navigation.domInteractive
			)
		);

		output.add(
			'DOMContentLoaded end',
			formatDuration(
				navigation.domContentLoadedEnd
			)
		);

		output.add(
			'DOM complete',
			formatDuration(
				navigation.domComplete
			)
		);

		output.add(
			'Load event end',
			formatDuration(
				navigation.loadEventEnd
			)
		);

		output.add(
			'Document transfer size',
			formatBytes(
				navigation.transferSize
			)
		);

		output.add(
			'Document encoded size',
			formatBytes(
				navigation.encodedBodySize
			)
		);

		output.add(
			'Document decoded size',
			formatBytes(
				navigation.decodedBodySize
			)
		);

		output.add(
			'Next hop protocol',
			navigation.nextHopProtocol
		);

		output.add(
			'Document response status',
			navigation.responseStatus
		);

		navigation.serverTiming?.forEach(
			item => {
				output.add(
					`Server-Timing ${ item.name }`,
					`${ formatDuration(
						item.duration
					) }${
						item.description ?
							` | ${ item.description }` :
							''
					}`
				);
			}
		);
	}

	function addResources(
		lines,
		resources,
		indent = ''
	) {
		if ( !resources ) {
			return;
		}

		lines.push(
			`${ indent }Resource count: ${
				resources.count
			}`
		);

		lines.push(
			`${ indent }Transferred: ${
				formatBytes(
					resources.transfer
				)
			}`
		);

		lines.push(
			`${ indent }Encoded: ${
				formatBytes(
					resources.encoded
				)
			}`
		);

		lines.push(
			`${ indent }Decoded: ${
				formatBytes(
					resources.decoded
				)
			}`
		);

		lines.push(
			`${ indent }Likely cached: ${
				resources.cached
			}`
		);

		Object.entries(
			resources.groups ||
			{}
		)
			.sort()
			.forEach(
				( [ type, group ] ) => {
					lines.push(
						`${ indent }Resource group ${ type }:` +
							` count=${ group.count }` +
							` | duration=${ formatDuration(
								group.duration
							) }` +
							` | transfer=${ formatBytes(
								group.transfer
							) }` +
							` | decoded=${ formatBytes(
								group.decoded
							) }`
					);
				}
			);

		if ( resources.slowest?.length ) {
			lines.push(
				`${ indent }Slowest resources:`
			);

			resources.slowest.forEach(
				( item, index ) => {
					lines.push(
						`${ indent }  ${ index + 1 }.` +
							` duration=${ formatDuration(
								item.duration
							) }` +
							` | start=${ formatDuration(
								item.startTime
							) }` +
							` | type=${ item.type }` +
							` | protocol=${ item.nextHopProtocol }` +
							` | transfer=${ formatBytes(
								item.transferSize
							) }` +
							` | decoded=${ formatBytes(
								item.decodedBodySize
							) }` +
							(
								item.responseStatus ?
									` | status=${ item.responseStatus }` :
									''
							) +
							` | ${ item.url }`
					);
				}
			);
		}
	}

	function addPage(
		output,
		page,
		number
	) {
		const lines =
			output.lines;

		const metrics =
			page.metrics;

		output.section(
			`CAPTURED PAGE ${ number }`
		);

		output.add(
			'URL',
			page.url
		);

		output.add(
			'Title',
			page.title
		);

		output.add(
			'Capture source',
			page.source
		);

		output.add(
			'Capture started',
			page.captureStartedAt
		);

		output.add(
			'Capture ended',
			page.captureEndedAt
		);

		output.add(
			'Capture duration on page',
			formatDuration(
				page.captureDuration
			)
		);

		addNavigation(
			output,
			page.navigation
		);

		Object.entries(
			page.paints ||
			{}
		).forEach(
			( [ name, value ] ) => {
				output.add(
					name,
					formatDuration(
						value
					)
				);
			}
		);

		if ( page.viewportAtStart ) {
			output.add(
				'Viewport at capture start',
				`${ page.viewportAtStart.width } x ` +
					`${ page.viewportAtStart.height } ` +
					`@ DPR ${ page.viewportAtStart.devicePixelRatio }`
			);
		}

		if ( page.viewportAtEnd ) {
			output.add(
				'Viewport at capture end',
				`${ page.viewportAtEnd.width } x ` +
					`${ page.viewportAtEnd.height } ` +
					`@ DPR ${ page.viewportAtEnd.devicePixelRatio } ` +
					`| scroll ${ page.viewportAtEnd.scrollX }, ` +
					`${ page.viewportAtEnd.scrollY }`
			);
		}

		if ( page.documentAtEnd ) {
			output.add(
				'Document at capture end',
				`${ page.documentAtEnd.readyState } | ` +
					`${ page.documentAtEnd.visibilityState } | ` +
					`${ page.documentAtEnd.scrollWidth } x ` +
					`${ page.documentAtEnd.scrollHeight }`
			);
		}

		if ( page.memoryAtStart ) {
			output.add(
				'JS heap at page capture start',
				`${ formatBytes(
					page.memoryAtStart.usedJSHeapSize
				) } used / ` +
					`${ formatBytes(
						page.memoryAtStart.totalJSHeapSize
					) } allocated`
			);
		}

		if ( page.memoryAtEnd ) {
			output.add(
				'JS heap at page capture end',
				`${ formatBytes(
					page.memoryAtEnd.usedJSHeapSize
				) } used / ` +
					`${ formatBytes(
						page.memoryAtEnd.totalJSHeapSize
					) } allocated`
			);
		}

		if (
			page.memoryAtStart &&
			page.memoryAtEnd
		) {
			output.add(
				'JS heap used delta',
				formatBytes(
					page.memoryAtEnd.usedJSHeapSize -
						page.memoryAtStart.usedJSHeapSize
				)
			);
		}

		if ( metrics ) {
			if (
				hasType(
					page,
					'longtask'
				)
			) {
				output.add(
					'Long-task entries',
					metrics.longTasks.count
				);

				if (
					metrics.longTasks.count
				) {
					output.add(
						'Long-task total',
						formatDuration(
							metrics.longTasks.total
						)
					);

					output.add(
						'Worst long task',
						formatDuration(
							metrics.longTasks.worst
						)
					);
				}
			}

			if (
				hasType(
					page,
					'event'
				)
			) {
				output.add(
					'Event Timing entries',
					metrics.events.count
				);

				if (
					metrics.events.count
				) {
					output.add(
						'Event Timing total',
						formatDuration(
							metrics.events.total
						)
					);

					output.add(
						'Worst Event Timing',
						formatDuration(
							metrics.events.worst
						)
					);
				}
			}

			if (
				hasType(
					page,
					'layout-shift'
				)
			) {
				output.add(
					'Layout-shift entries',
					metrics.layoutShifts.count
				);

				if (
					metrics.layoutShifts.count
				) {
					output.add(
						'Layout-shift total',
						formatNumber(
							metrics.layoutShifts.total,
							5
						)
					);

					output.add(
						'Unexpected layout-shift total',
						formatNumber(
							metrics.layoutShifts.unexpected,
							5
						)
					);

					output.add(
						'Largest layout shift',
						formatNumber(
							metrics.layoutShifts.worst,
							5
						)
					);
				}
			}

			Object.entries(
				metrics.interactions ||
				{}
			)
				.sort()
				.forEach(
					( [ type, count ] ) => {
						output.add(
							`${ type } events`,
							count
						);
					}
				);

			output.add(
				'Event-loop samples',
				metrics.eventLoop.samples
			);

			output.add(
				'Event-loop delays >= 50 ms',
				metrics.eventLoop.over50
			);

			output.add(
				'Event-loop delays >= 100 ms',
				metrics.eventLoop.over100
			);

			output.add(
				'Event-loop delays >= 250 ms',
				metrics.eventLoop.over250
			);

			output.add(
				'Event-loop delays >= 1000 ms',
				metrics.eventLoop.over1000
			);

			output.add(
				'Worst event-loop delay',
				formatDuration(
					metrics.eventLoop.worst
				)
			);

			output.add(
				'Scroll events',
				metrics.scroll.events
			);

			output.add(
				'Approximate scroll distance',
				`${ Math.round(
					metrics.scroll.distance
				) } CSS px`
			);

			output.add(
				'Sampled scroll frames',
				metrics.scroll.samples
			);

			output.add(
				'Scroll frames >= 34 ms',
				metrics.scroll.over34
			);

			output.add(
				'Scroll frames >= 50 ms',
				metrics.scroll.over50
			);

			output.add(
				'Scroll frames >= 100 ms',
				metrics.scroll.over100
			);

			output.add(
				'Scroll frames >= 250 ms',
				metrics.scroll.over250
			);

			output.add(
				'Worst sampled scroll frame',
				formatDuration(
					metrics.scroll.worst
				)
			);

			output.add(
				'JavaScript errors',
				metrics.jsErrors.length
			);

			metrics.jsErrors.forEach(
				( error, index ) => {
					lines.push(
						`  JS ${ index + 1 }. ` +
							`${ error.message }` +
							(
								error.file ?
									` | ${ error.file }:` +
									`${ error.line }:` +
									`${ error.column }` :
									''
							)
					);
				}
			);

			output.add(
				'Unhandled promise rejections',
				metrics.rejections.length
			);

			metrics.rejections.forEach(
				( message, index ) => {
					lines.push(
						`  Promise ${ index + 1 }. ` +
							message
					);
				}
			);

			output.add(
				'Resource load errors',
				metrics.resourceErrors.length
			);

			metrics.resourceErrors.forEach(
				( error, index ) => {
					lines.push(
						`  Resource ${ index + 1 }. ` +
							`${ error.tag } | ${ error.url }`
					);
				}
			);

			output.add(
				'Content Security Policy violations',
				metrics.csp.length
			);

			metrics.csp.forEach(
				( item, index ) => {
					lines.push(
						`  CSP ${ index + 1 }. ` +
							`directive=${ item.directive }` +
							` | blocked=${ item.blocked }` +
							` | disposition=${ item.disposition }` +
							` | status=${ item.status }`
					);
				}
			);
		}

		addResources(
			lines,
			page.resourcesAtEnd
		);
	}

	function buildReport( session ) {
		const snapshot =
			detailedSnapshot();

		const output =
			reportWriter();

		const lines =
			output.lines;

		lines.push(
			'EQL WIKI PERFORMANCE DIAGNOSTIC',
			'================================'
		);

		output.add(
			'Generated',
			new Date().toISOString()
		);

		output.add(
			'Capture started',
			new Date(
				session.startedAt
			).toISOString()
		);

		output.add(
			'Capture ended',
			session.endedAt ?
				new Date(
					session.endedAt
				).toISOString() :
				''
		);

		output.add(
			'Total capture duration',
			formatDuration(
				(
					session.endedAt ||
					Date.now()
				) -
					session.startedAt
			)
		);

		output.add(
			'Captured pages',
			session.pages.length
		);

		output.add(
			'Stop reason',
			session.stopReason
		);

		output.bool(
			'Capture auto-stopped',
			!!session.autoStopped
		);

		output.bool(
			'Cross-page sessionStorage persistence',
			!!session.storageBacked
		);

		output.add(
			'Capture start page',
			session.startPage
		);

		output.section( 'BROWSER' );

		output.add(
			'User agent',
			navigator.userAgent
		);

		output.add(
			'Platform',
			navigator.platform
		);

		output.add(
			'Language',
			navigator.language
		);

		if (
			navigator.languages?.length
		) {
			output.add(
				'Languages',
				navigator.languages.join(
					', '
				)
			);
		}

		output.bool(
			'Online',
			navigator.onLine
		);

		output.bool(
			'Cookies enabled',
			navigator.cookieEnabled
		);

		output.add(
			'Logical processors',
			navigator.hardwareConcurrency
		);

		if ( navigator.deviceMemory ) {
			output.add(
				'Device memory',
				`${ navigator.deviceMemory } GiB`
			);
		}

		output.add(
			'Maximum touch points',
			navigator.maxTouchPoints
		);

		output.add(
			'Do Not Track',
			navigator.doNotTrack
		);

		output.section(
			'DISPLAY AND INPUT'
		);

		output.add(
			'Viewport',
			`${ innerWidth } x ${ innerHeight }`
		);

		output.add(
			'Outer window',
			`${ outerWidth } x ${ outerHeight }`
		);

		output.add(
			'Screen',
			`${ screen.width } x ${ screen.height }`
		);

		output.add(
			'Available screen',
			`${ screen.availWidth } x ${ screen.availHeight }`
		);

		output.add(
			'Device pixel ratio',
			devicePixelRatio ||
				1
		);

		output.add(
			'Screen color depth',
			`${ screen.colorDepth } bit`
		);

		output.add(
			'Screen pixel depth',
			`${ screen.pixelDepth } bit`
		);

		if ( screen.orientation ) {
			output.add(
				'Screen orientation',
				screen.orientation.type
			);

			output.add(
				'Screen orientation angle',
				screen.orientation.angle
			);
		}

		if ( window.visualViewport ) {
			output.add(
				'Visual viewport',
				`${ Math.round(
					window.visualViewport.width
				) } x ${ Math.round(
					window.visualViewport.height
				) }`
			);

			output.add(
				'Visual viewport scale',
				formatNumber(
					window.visualViewport.scale,
					3
				)
			);
		}

		[
			[
				'prefers-color-scheme: dark',
				'(prefers-color-scheme: dark)'
			],
			[
				'prefers-reduced-motion: reduce',
				'(prefers-reduced-motion: reduce)'
			],
			[
				'prefers-contrast: more',
				'(prefers-contrast: more)'
			],
			[
				'forced-colors: active',
				'(forced-colors: active)'
			],
			[
				'hover: hover',
				'(hover: hover)'
			],
			[
				'pointer: fine',
				'(pointer: fine)'
			]
		].forEach(
			( [ label, query ] ) => {
				if ( window.matchMedia ) {
					output.bool(
						label,
						window.matchMedia(
							query
						).matches
					);
				}
			}
		);

		const connection =
			navigator.connection ||
			navigator.mozConnection ||
			navigator.webkitConnection;

		if ( connection ) {
			output.section(
				'NETWORK ESTIMATE'
			);

			output.add(
				'Effective connection type',
				connection.effectiveType
			);

			if (
				typeof connection.downlink ===
				'number'
			) {
				output.add(
					'Estimated downlink',
					`${ connection.downlink } Mbps`
				);
			}

			if (
				typeof connection.rtt ===
				'number'
			) {
				output.add(
					'Estimated RTT',
					`${ connection.rtt } ms`
				);
			}

			if (
				typeof connection.saveData ===
				'boolean'
			) {
				output.bool(
					'Save-Data enabled',
					connection.saveData
				);
			}
		}

		if ( 'serviceWorker' in navigator ) {
			output.bool(
				'Service worker controlling stop page',
				!!navigator.serviceWorker.controller
			);
		}

		output.section(
			'CAPTURED PAGE LOAD SUMMARY'
		);

		session.pages
			.slice()
			.sort(
				( first, second ) =>
					(
						second.navigation
							?.loadEventEnd ||
						0
					) -
					(
						first.navigation
							?.loadEventEnd ||
						0
					)
			)
			.forEach(
				( page, index ) => {
					lines.push(
						`  ${ index + 1 }. ${ page.url }` +
							` | load=${ formatDuration(
								page.navigation
									?.loadEventEnd ||
								0
							) }` +
							` | DCL=${ formatDuration(
								page.navigation
									?.domContentLoadedEnd ||
								0
							) }` +
							` | TTFB=${ formatDuration(
								page.navigation
									?.ttfb ||
								0
							) }` +
							` | captured=${ formatDuration(
								page.captureDuration
							) }`
					);
				}
			);

		session.pages.forEach(
			( page, index ) => {
				addPage(
					output,
					page,
					index + 1
				);
			}
		);

		output.section(
			'DETAILED SNAPSHOT OF STOP PAGE'
		);

		output.add(
			'URL',
			snapshot.url
		);

		output.add(
			'Title',
			snapshot.title
		);

		addNavigation(
			output,
			snapshot.navigation
		);

		Object.entries(
			snapshot.paints ||
			{}
		).forEach(
			( [ name, value ] ) => {
				output.add(
					name,
					formatDuration(
						value
					)
				);
			}
		);

		output.section(
			'DOM AND CSS COMPLEXITY ON STOP PAGE'
		);

		output.add(
			'DOM elements',
			snapshot.dom.elementCount
		);

		output.add(
			'Computed-style sample',
			`${ snapshot.dom.sampled } of ` +
				`${ snapshot.dom.elementCount } elements`
		);

		[
			[
				'Iframes',
				'iframes'
			],
			[
				'Forms',
				'forms'
			],
			[
				'Form controls and buttons',
				'inputs'
			],
			[
				'Links',
				'links'
			],
			[
				'Tables',
				'tables'
			],
			[
				'Table rows',
				'tableRows'
			],
			[
				'Table cells',
				'tableCells'
			],
			[
				'SVG elements',
				'svgs'
			],
			[
				'Canvas elements',
				'canvases'
			],
			[
				'Fixed-position elements in sample',
				'fixed'
			],
			[
				'Sticky-position elements in sample',
				'sticky'
			],
			[
				'Elements with CSS filter in sample',
				'filters'
			],
			[
				'Elements with backdrop-filter in sample',
				'backdropFilters'
			],
			[
				'Elements with box-shadow in sample',
				'boxShadows'
			],
			[
				'Elements with text-shadow in sample',
				'textShadows'
			],
			[
				'Elements with transform in sample',
				'transforms'
			],
			[
				'Elements with will-change in sample',
				'willChange'
			],
			[
				'Elements with fixed backgrounds in sample',
				'fixedBackgrounds'
			],
			[
				'Elements with blend modes in sample',
				'blendModes'
			],
			[
				'Elements with opacity below 1 in sample',
				'opacityLayers'
			],
			[
				'Elements with content-visibility in sample',
				'contentVisibility'
			],
			[
				'Elements with CSS contain in sample',
				'contain'
			]
		].forEach(
			( [ label, key ] ) => {
				output.add(
					label,
					snapshot.dom[ key ]
				);
			}
		);

		lines.push(
			'Most common element tags:'
		);

		Object.entries(
			snapshot.dom.tagCounts
		)
			.sort(
				( first, second ) =>
					second[ 1 ] -
					first[ 1 ]
			)
			.slice(
				0,
				30
			)
			.forEach(
				( [ tag, count ] ) => {
					lines.push(
						`  ${ tag }: ${ count }`
					);
				}
			);

		output.section(
			'STYLESHEETS ON STOP PAGE'
		);

		output.add(
			'Stylesheets',
			snapshot.stylesheets.count
		);

		output.add(
			'Disabled stylesheets',
			snapshot.stylesheets.disabled
		);

		output.add(
			'Accessible stylesheets',
			snapshot.stylesheets.accessible
		);

		output.add(
			'Inaccessible stylesheets',
			snapshot.stylesheets.inaccessible
		);

		output.add(
			'Accessible CSS rule count',
			snapshot.stylesheets.ruleCount
		);

		snapshot.stylesheets.items.forEach(
			( item, index ) => {
				lines.push(
					`  ${ index + 1 }. ${ item.href }` +
						` | disabled=${
							item.disabled ?
								'yes' :
								'no'
						}` +
						` | rules=${
							item.rules === null ?
								'inaccessible' :
								item.rules
						}`
				);
			}
		);

		output.section(
			'IMAGES ON STOP PAGE'
		);

		[
			[
				'Images',
				'count'
			],
			[
				'Complete images',
				'complete'
			],
			[
				'Broken images',
				'broken'
			],
			[
				'Lazy-loaded images',
				'lazy'
			],
			[
				'Async-decoding images',
				'asyncDecoding'
			],
			[
				'Potentially oversized images',
				'oversized'
			]
		].forEach(
			( [ label, key ] ) => {
				output.add(
					label,
					snapshot.images[ key ]
				);
			}
		);

		output.add(
			'Natural image pixel area',
			`${ Math.round(
				snapshot.images.naturalPixelArea
			) } px²`
		);

		output.add(
			'Displayed image pixel area',
			`${ Math.round(
				snapshot.images.displayedPixelArea
			) } CSS px²`
		);

		lines.push(
			'Largest images:'
		);

		snapshot.images.largest.forEach(
			( image, index ) => {
				lines.push(
					`  ${ index + 1 }. ` +
						`${ image.naturalWidth }x` +
						`${ image.naturalHeight } natural` +
						` | ${ image.displayedWidth }x` +
						`${ image.displayedHeight } displayed` +
						` | loading=${ image.loading }` +
						` | ${ image.url }`
				);
			}
		);

		if ( snapshot.fonts ) {
			output.section(
				'FONTS ON STOP PAGE'
			);

			[
				[
					'FontSet status',
					'status'
				],
				[
					'Font faces',
					'count'
				],
				[
					'Loaded fonts',
					'loaded'
				],
				[
					'Loading fonts',
					'loading'
				],
				[
					'Unloaded fonts',
					'unloaded'
				],
				[
					'Font errors',
					'error'
				]
			].forEach(
				( [ label, key ] ) => {
					output.add(
						label,
						snapshot.fonts[ key ]
					);
				}
			);

			snapshot.fonts.families.forEach(
				( font, index ) => {
					lines.push(
						`  ${ index + 1 }. ${ font.family }` +
							` | status=${ font.status }` +
							` | weight=${ font.weight }` +
							` | style=${ font.style }`
					);
				}
			);
		}

		if ( snapshot.animations ) {
			output.section(
				'ANIMATIONS ON STOP PAGE'
			);

			Object.entries(
				snapshot.animations
			).forEach(
				( [ key, value ] ) => {
					output.add(
						key,
						value
					);
				}
			);
		}

		if ( snapshot.webgl ) {
			output.section(
				'WEBGL ON STOP PAGE'
			);

			output.add(
				'WebGL version',
				snapshot.webgl.version
			);

			output.add(
				'WebGL shading language',
				snapshot.webgl.shadingLanguageVersion
			);

			output.add(
				'WebGL vendor',
				snapshot.webgl.vendor
			);

			output.add(
				'WebGL renderer',
				snapshot.webgl.renderer
			);

			output.add(
				'WebGL unmasked vendor',
				snapshot.webgl.unmaskedVendor
			);

			output.add(
				'WebGL unmasked renderer',
				snapshot.webgl.unmaskedRenderer
			);

			output.add(
				'WebGL max texture size',
				snapshot.webgl.maxTextureSize
			);

			output.add(
				'WebGL max renderbuffer size',
				snapshot.webgl.maxRenderbufferSize
			);

			if (
				snapshot.webgl.maxViewportDims
					?.length
			) {
				output.add(
					'WebGL max viewport',
					snapshot.webgl.maxViewportDims.join(
						' x '
					)
				);
			}
		}

		output.section(
			'RESOURCES ON STOP PAGE'
		);

		addResources(
			lines,
			snapshot.resources
		);

		output.section( 'NOTES' );

		lines.push(
			'- Quick hover stats are calculated only when the panel is opened.'
		);

		lines.push(
			'- Detailed observers, timer-delay sampling, and scroll-frame sampling run only while capture is active.'
		);

		lines.push(
			'- Capture follows same-tab, same-origin wiki navigation through sessionStorage.'
		);

		lines.push(
			'- The script resumes an active capture as soon as this ResourceLoader module executes, before DOMContentLoaded when possible.'
		);

		lines.push(
			'- A page that never executes this JavaScript cannot add itself to the report.'
		);

		lines.push(
			'- Expensive DOM, CSS, image, font, animation, WebGL, and full resource inspection runs only after capture stops.'
		);

		lines.push(
			`- Computed-style inspection is capped at the first ${ CSS_SAMPLE_LIMIT } elements.`
		);

		lines.push(
			'- Query strings and URL fragments are removed from reported URLs.'
		);

		lines.push(
			'- No screenshots, page text, form values, keystrokes, cookies, localStorage contents, or sessionStorage contents are copied.'
		);

		lines.push(
			'- Browser-specific measurements are omitted when the browser does not expose the relevant API.'
		);

		lines.push(
			'- WebGL renderer information describes the WebGL context and does not prove general page-compositing acceleration.'
		);

		lines.push(
			'- In Firefox, the Graphics section of about:support can add compositor and driver details that webpages cannot access.'
		);

		lines.push(
			'- Resource sizes may be 0 for cached resources or cross-origin resources without Timing-Allow-Origin.'
		);

		lines.push(
			'- Event-loop delay is a timer-based approximation; native Long Tasks data appears only when exposed by the browser.'
		);

		return lines.join( '\n' );
	}

	function fallbackCopy( text ) {
		const textarea =
			document.createElement(
				'textarea'
			);

		textarea.value = text;
		textarea.readOnly = true;

		textarea.style.cssText =
			'position:fixed;left:-9999px;top:0';

		document.body.appendChild(
			textarea
		);

		textarea.select();

		let copied = false;

		try {
			copied =
				document.execCommand(
					'copy'
				);
		} catch ( error ) {}

		textarea.remove();

		return copied;
	}

	function copyReport(
		report,
		session
	) {
		const success = () => {
			session.status =
				'copied';

			writeSession( session );
			refreshButton();

			updatePanel(
				'Diagnostic report copied to the clipboard.'
			);

			showPanel();

			clearTimeout(
				statusTimer
			);

			statusTimer = setTimeout(
				() => updatePanel(),
				2500
			);
		};

		const failure = () => {
			session.status =
				'ready';

			writeSession( session );
			refreshButton();

			updatePanel(
				'Clipboard copy failed. ' +
					'Click the cog to try again.'
			);

			showPanel();
		};

		if (
			navigator.clipboard?.writeText
		) {
			navigator.clipboard
				.writeText( report )
				.then( success )
				.catch(
					() =>
						fallbackCopy( report ) ?
							success() :
							failure()
				);
		} else if (
			fallbackCopy( report )
		) {
			success();
		} else {
			failure();
		}
	}

	function buildAndCopy( session ) {
		setButton(
			'…',
			'eql-perf-okay',
			'Building and copying diagnostics.'
		);

		updatePanel(
			'Capture stopped. ' +
				'Building the detailed report and copying it now.'
		);

		showPanel();

		try {
			copyReport(
				buildReport( session ),
				session
			);
		} catch ( error ) {
			session.status =
				'ready';

			writeSession( session );
			refreshButton();

			updatePanel(
				`Report generation failed: ${
					error?.message ||
					error
				}`
			);

			showPanel();
		}
	}

	function handleClick( event ) {
		event.preventDefault();

		const session =
			readSession();

		if (
			session?.status ===
			'active'
		) {
			const stopped =
				stopCapture();

			if ( stopped ) {
				buildAndCopy(
					stopped
				);
			}

			return;
		}

		if (
			session?.status ===
			'ready'
		) {
			buildAndCopy(
				session
			);

			return;
		}

		startCapture();
	}

	function onPageHide() {
		if (
			readSession()?.status ===
			'active'
		) {
			finalizePage();
		}
	}

	function initUi() {
		ensurePanel();
		ensureWidget();

		addEventListener(
			'resize',
			() => {
				if (
					ensurePanel().classList.contains(
						'eql-perf-panel-visible'
					)
				) {
					positionPanel();
				}
			}
		);
	}

	/*
	 * Resume recording before DOMContentLoaded when
	 * ResourceLoader executes early.
	 */
	resumeCapture(
		'continued-navigation'
	);

	addEventListener(
		'pagehide',
		onPageHide
	);

	addEventListener(
		'pageshow',
		event => {
			if ( event.persisted ) {
				resumeCapture(
					'bfcache-resume'
				);
			}
		}
	);

	if (
		document.readyState ===
		'loading'
	) {
		document.addEventListener(
			'DOMContentLoaded',
			initUi
		);
	} else {
		initUi();
	}
})();