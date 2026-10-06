(function () {
	'use strict';

	var STORAGE_KEY = 'eql-equipment-builder-v2';
	var activeRequestController = null;
	var requestSequence = 0;

	var classes = [
		'',
		'Bard',
		'Beastlord',
		'Berserker',
		'Cleric',
		'Druid',
		'Enchanter',
		'Magician',
		'Monk',
		'Necromancer',
		'Paladin',
		'Ranger',
		'Rogue',
		'Shadow Knight',
		'Shaman',
		'Warrior',
		'Wizard'
	];

	var classLabels = {
		'': '— None —'
	};

	var stats = [
		'',
		'EFFECT',
		'AC',
		'STR',
		'STA',
		'AGI',
		'DEX',
		'CHA',
		'INT',
		'WIS',
		'HP',
		'MANA',
		'END',
		'MR',
		'FR',
		'CR',
		'PR',
		'DR'
	];

	var statLabels = {
		'': '— Any stat —',
		EFFECT: 'Effect',
		AC: 'AC',
		STR: 'Strength',
		STA: 'Stamina',
		AGI: 'Agility',
		DEX: 'Dexterity',
		CHA: 'Charisma',
		INT: 'Intelligence',
		WIS: 'Wisdom',
		HP: 'HP',
		MANA: 'Mana',
		END: 'Endurance',
		MR: 'Magic Resist',
		FR: 'Fire Resist',
		CR: 'Cold Resist',
		PR: 'Poison Resist',
		DR: 'Disease Resist'
	};

	var allWeaponTypes = [
		'1H Slashing',
		'1H Blunt',
		'1H Piercing',
		'2H Slashing',
		'2H Blunt',
		'2H Piercing'
	];

	var weaponTypeLabels = {
		'': '— Any damage type —',
		'1H Slashing': '1H Slash',
		'1H Blunt': '1H Blunt',
		'1H Piercing': '1H Piercing',
		'2H Slashing': '2H Slash',
		'2H Blunt': '2H Blunt',
		'2H Piercing': '2H Piercing'
	};

	var slots = [
		'Any',
		'Primary',
		'One-Handed',
		'Two-Handed',
		'Secondary',
		'Range',
		'Ammo',
		'Bard Instrument',
		'Head',
		'Face',
		'Ear',
		'Neck',
		'Shoulders',
		'Arms',
		'Back',
		'Wrist',
		'Hands',
		'Fingers',
		'Chest',
		'Waist',
		'Legs',
		'Feet'
	];

	function getWeaponTypesForSlot( slot ) {
		if ( slot === 'One-Handed' ) {
			return [ '1H Slashing', '1H Blunt', '1H Piercing' ];
		}

		if ( slot === 'Two-Handed' ) {
			return [ '2H Slashing', '2H Blunt', '2H Piercing' ];
		}

		if ( slot === 'Primary' || slot === 'Secondary' ) {
			return allWeaponTypes.slice();
		}

		return [];
	}

	function readSavedState() {
		try {
			return JSON.parse( window.localStorage.getItem( STORAGE_KEY ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function saveState( state ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( state ) );
		} catch ( e ) {}
	}

	function populateSelect( select, values, selected, labels ) {
		select.innerHTML = '';

		values.forEach( function ( value ) {
			var option = document.createElement( 'option' );

			option.value = value;
			option.textContent = labels && Object.prototype.hasOwnProperty.call( labels, value ) ?
				labels[ value ] :
				value;

			if ( value === selected ) {
				option.selected = true;
			}

			select.appendChild( option );
		} );
	}

	function makeSelect( id, label, values, selected, labels ) {
		var wrap = document.createElement( 'label' );
		var text = document.createElement( 'span' );
		var select = document.createElement( 'select' );

		wrap.className = 'eql-equipment-builder-field';

		text.className = 'eql-equipment-builder-label';
		text.textContent = label;

		select.id = id;
		select.className = 'eql-equipment-builder-select';

		populateSelect( select, values, selected, labels );

		wrap.appendChild( text );
		wrap.appendChild( select );

		return wrap;
	}

	function getValue( id ) {
		var el = document.getElementById( id );
		return el ? el.value : '';
	}

	function getState() {
		return {
			classA: getValue( 'eql-equipment-class-a' ),
			classB: getValue( 'eql-equipment-class-b' ),
			classC: getValue( 'eql-equipment-class-c' ),
			slot: getValue( 'eql-equipment-slot' ),
			stat: getValue( 'eql-equipment-stat' ),
			weaponType: getValue( 'eql-equipment-weapon-type' )
		};
	}

	function getSelectedClasses( state ) {
		return [ state.classA, state.classB, state.classC ].filter( function ( value ) {
			return value !== '';
		} );
	}

	function hasDuplicateClasses( state ) {
		var selected = getSelectedClasses( state );
		var unique = selected.filter( function ( value, index ) {
			return selected.indexOf( value ) === index;
		} );

		return unique.length !== selected.length;
	}

	function buildQueryUrl( state ) {
		return mw.util.getUrl( 'Special:ClassSlotEquip' ) +
			'?eqlBuilder=1' +
			'&classA=' + encodeURIComponent( state.classA ) +
			'&classB=' + encodeURIComponent( state.classB ) +
			'&classC=' + encodeURIComponent( state.classC ) +
			'&slot=' + encodeURIComponent( state.slot ) +
			'&stat=' + encodeURIComponent( state.stat ) +
			'&weaponType=' + encodeURIComponent( state.weaponType );
	}

	function syncWeaponTypeControl( preferredValue ) {
		var slot = getValue( 'eql-equipment-slot' );
		var select = document.getElementById( 'eql-equipment-weapon-type' );
		var wrap = document.getElementById( 'eql-equipment-weapon-type-field' );
		var available = getWeaponTypesForSlot( slot );
		var selected;

		if ( !select || !wrap ) {
			return;
		}

		if ( available.length === 0 ) {
			populateSelect( select, [ '' ], '', weaponTypeLabels );
			wrap.hidden = true;
			return;
		}

		selected = preferredValue !== undefined ? preferredValue : select.value;

		if ( available.indexOf( selected ) === -1 ) {
			selected = '';
		}

		populateSelect( select, [ '' ].concat( available ), selected, weaponTypeLabels );
		wrap.hidden = false;
	}

	function setStatus( text, isError ) {
		var status = document.getElementById( 'eql-equipment-builder-status' );

		if ( !status ) {
			return;
		}

		status.textContent = text || '';
		status.classList.toggle( 'eql-equipment-builder-status-error', !!isError );
	}

	function setSearchButtonBusy( isBusy ) {
		var button = document.getElementById( 'eql-equipment-search-button' );

		if ( !button ) {
			return;
		}

		button.disabled = !!isBusy;
		button.textContent = isBusy ? 'Searching…' : 'Search';
	}

	function cancelActiveSearch() {
		requestSequence++;

		if ( activeRequestController ) {
			activeRequestController.abort();
			activeRequestController = null;
		}

		setSearchButtonBusy( false );
	}

	function markFiltersChanged() {
		var results = document.getElementById( 'eql-equipment-builder-results' );

		saveState( getState() );
		cancelActiveSearch();

		if ( results ) {
			if ( results.dataset.eqlLoading === '1' ) {
				results.innerHTML = '';
				results.classList.remove( 'eql-equipment-builder-results-ready' );
				delete results.dataset.eqlLoading;
			} else if ( results.classList.contains( 'eql-equipment-builder-results-ready' ) ) {
				results.classList.add( 'eql-equipment-builder-results-stale' );
			}
		}

		setStatus( 'Filters changed. Select Search to update the results.', false );
	}

	function initSortableTables( results ) {
		if ( !window.jQuery || !results ) {
			return;
		}

		if ( window.mw && mw.loader ) {
			mw.loader.using( [ 'jquery.tablesorter' ] ).then( function () {
				jQuery( results ).find( 'table.sortable' ).each( function () {
					var $table = jQuery( this );

					try {
						if ( $table.data( 'tablesorter' ) ) {
							$table.trigger( 'update' );
						} else {
							$table.tablesorter();
						}
					} catch ( e ) {}
				} );
			} );
		} else if ( jQuery.fn.tablesorter ) {
			jQuery( results ).find( 'table.sortable' ).tablesorter();
		}
	}

	function resetListLevelControl() {
		var control = document.getElementById( 'eql-equipment-list-level-control' );
		var results = document.getElementById( 'eql-equipment-builder-results' );

		if ( control ) {
			control.innerHTML = '';
			control.hidden = true;
		}

		if ( results ) {
			delete results.dataset.eqlListSliderReady;
		}
	}

	function copyScaledStatsToTable( results ) {
		if ( !results ) {
			return;
		}

		Array.prototype.forEach.call(
			results.querySelectorAll( '.eql-equipment-item-row' ),
			function ( row ) {
				var itemCell = row.querySelector( '.eql-equipment-item-cell' );

				if ( !itemCell ) {
					return;
				}

				Array.prototype.forEach.call(
					row.querySelectorAll( '.eql-equipment-scaled-cell[data-eql-stat]' ),
					function ( cell ) {
						var statKey = cell.getAttribute( 'data-eql-stat' );
						var source = itemCell.querySelector(
							'.ils-stat[data-ils-stat="' + statKey + '"]'
						);
						var modified;

						if ( !source ) {
							return;
						}

						modified = source.classList.contains( 'ils-modified' );

						if ( statKey === 'DMG' ) {
							var delay = parseFloat( cell.getAttribute( 'data-eql-delay' ) );
							var damage = parseFloat(
								source.getAttribute( 'data-ils-current-value' ) ||
								source.textContent
							);

							if ( !isNaN( delay ) && delay > 0 && !isNaN( damage ) ) {
								var ratio = document.createElement( 'span' );
								var values = document.createElement( 'span' );

								ratio.className = 'ddb';
								ratio.textContent = '(' + ( damage / delay ).toFixed( 2 ) + ') ';
								values.textContent = source.textContent + ' / ' + delay;

								cell.textContent = '';
								cell.appendChild( ratio );
								cell.appendChild( values );
							}
						} else {
							cell.textContent = source.textContent;
						}

						cell.classList.toggle( 'ils-modified', modified );
					}
				);
			}
		);
	}

	function setupListLevelSlider( results ) {
		var control = document.getElementById( 'eql-equipment-list-level-control' );
		var sliderContainers;
		var masterContainer;
		var masterSlider;
		var framePending = false;

		if ( !results || !control ) {
			return;
		}

		if ( results.dataset.eqlListSliderReady === '1' ) {
			copyScaledStatsToTable( results );
			return;
		}

		sliderContainers = results.querySelectorAll(
			'.eql-equipment-item-cell .ils-slider-container'
		);

		if ( sliderContainers.length === 0 ) {
			return;
		}

		masterContainer = sliderContainers[0];
		masterSlider = masterContainer.querySelector( '.ils-slider' );

		if ( !masterSlider ) {
			return;
		}

		control.innerHTML = '';
		control.appendChild( masterContainer );
		control.hidden = false;
		control.classList.add( 'eql-equipment-list-level-control-ready' );
		masterContainer.classList.add( 'eql-equipment-list-level-master' );
		results.dataset.eqlListSliderReady = '1';

		function syncSlidersAndTable() {
			var masterValue = masterSlider.value;

			Array.prototype.forEach.call(
				results.querySelectorAll( '.ils-slider-container .ils-slider' ),
				function ( slider ) {
					if ( slider.value !== masterValue ) {
						slider.value = masterValue;
					}

					slider.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				}
			);

			copyScaledStatsToTable( results );
			framePending = false;
		}

		function scheduleSync() {
			if ( framePending ) {
				return;
			}

			framePending = true;

			if ( window.requestAnimationFrame ) {
				window.requestAnimationFrame( syncSlidersAndTable );
			} else {
				window.setTimeout( syncSlidersAndTable, 0 );
			}
		}

		masterSlider.addEventListener( 'input', scheduleSync );

		Array.prototype.forEach.call(
			masterContainer.querySelectorAll( '.ils-step-button' ),
			function ( button ) {
				button.addEventListener( 'click', scheduleSync );
			}
		);

		copyScaledStatsToTable( results );
	}

	function refreshDynamicEnhancements( results ) {
		if ( !results ) {
			return;
		}

		/*
		 * Tell MediaWiki that AJAX-loaded page content was inserted.
		 * This is what many MW scripts use to re-process new content.
		 */
		if ( window.mw && mw.hook && window.jQuery ) {
			mw.hook( 'wikipage.content' ).fire( jQuery( results ) );
		}

		/*
		 * Direct optional EQL refresh calls.
		 * These are intentionally guarded so this builder does not hard-depend
		 * on either script.
		 */
		if ( window.eqlItemLevelSliderRefresh ) {
			window.eqlItemLevelSliderRefresh( results );
		}

		if ( window.eqlEraFilterRefresh ) {
			window.eqlEraFilterRefresh( results );
		}

		initSortableTables( results );
		setupListLevelSlider( results );
	}

	function scheduleEnhancements( results ) {
		refreshDynamicEnhancements( results );

		/*
		 * Script/module order can vary. These retries catch:
		 * - Era filter loading just after the builder result renders
		 * - ItemLevelSlider loading after the AJAX insert
		 * - sortable module becoming available late
		 */
		window.setTimeout( function () {
			refreshDynamicEnhancements( results );
		}, 150 );

		window.setTimeout( function () {
			refreshDynamicEnhancements( results );
		}, 500 );

		window.setTimeout( function () {
			refreshDynamicEnhancements( results );
		}, 1200 );
	}

	function loadResults() {
		var state = getState();
		var results = document.getElementById( 'eql-equipment-builder-results' );
		var selectedClasses = getSelectedClasses( state );
		var requestId;
		var fetchOptions;
		var controller = null;

		if ( !results ) {
			return;
		}

		cancelActiveSearch();
		resetListLevelControl();
		saveState( state );

		if ( selectedClasses.length < 1 ) {
			results.innerHTML = '';
			results.classList.remove( 'eql-equipment-builder-results-ready' );
			results.classList.remove( 'eql-equipment-builder-results-stale' );
			setStatus( 'Select at least one class.', true );
			return;
		}

		if ( hasDuplicateClasses( state ) ) {
			results.innerHTML = '';
			results.classList.remove( 'eql-equipment-builder-results-ready' );
			results.classList.remove( 'eql-equipment-builder-results-stale' );
			setStatus( 'Please choose each class only once.', true );
			return;
		}

		requestId = ++requestSequence;
		fetchOptions = {
			credentials: 'same-origin'
		};

		if ( window.AbortController ) {
			controller = new window.AbortController();
			activeRequestController = controller;
			fetchOptions.signal = controller.signal;
		}

		setSearchButtonBusy( true );
		setStatus( 'Loading matching equipment...', false );
		results.classList.remove( 'eql-equipment-builder-results-ready' );
		results.classList.remove( 'eql-equipment-builder-results-stale' );
		results.dataset.eqlLoading = '1';
		results.innerHTML = '<div class="eql-equipment-builder-loading">Loading...</div>';

		fetch( buildQueryUrl( state ), fetchOptions )
			.then( function ( response ) {
				return response.text().then( function ( text ) {
					if ( !response.ok ) {
						console.error( 'ClassSlotEquip response:', text );
						throw new Error(
							'Request failed: ' + response.status +
							'. See browser console for server response.'
						);
					}

					return text;
				} );
			} )
			.then( function ( html ) {
				if ( requestId !== requestSequence ) {
					return;
				}

				results.innerHTML = html;
				results.classList.add( 'eql-equipment-builder-results-ready' );
				delete results.dataset.eqlLoading;
				setStatus( '', false );
				scheduleEnhancements( results );
			} )
			.catch( function ( error ) {
				if (
					requestId !== requestSequence ||
					( error && error.name === 'AbortError' )
				) {
					return;
				}

				results.innerHTML = '';
				results.classList.remove( 'eql-equipment-builder-results-ready' );
				delete results.dataset.eqlLoading;
				setStatus( 'Could not load equipment results. ' + error.message, true );
			} )
			.finally( function () {
				if ( requestId !== requestSequence ) {
					return;
				}

				activeRequestController = null;
				setSearchButtonBusy( false );
			} );
	}

	function initBuilder() {
		var root = document.getElementById( 'eql-equipment-trio-builder' );
		var saved;
		var controls;
		var classControls;
		var slotControls;
		var weaponTypeField;
		var actions;
		var searchButton;
		var levelControl;
		var results;
		var status;

		if ( !root ) {
			return;
		}

		if ( root.dataset.eqlReady === '1' ) {
			return;
		}

		root.dataset.eqlReady = '1';

		saved = readSavedState();

		root.className = 'eql-equipment-builder';

		root.innerHTML =
			'<div class="eql-equipment-builder-title">Equipment Search</div>' +
			'<div class="eql-equipment-builder-subtitle">Choose one, two, or three classes and an equipment slot. Optionally require a stat or narrow weapon results by damage type, then select Search.</div>';

		controls = document.createElement( 'div' );
		controls.className = 'eql-equipment-builder-controls';

		classControls = document.createElement( 'div' );
		classControls.className = 'eql-equipment-builder-class-row';

		classControls.appendChild(
			makeSelect(
				'eql-equipment-class-a',
				'Class 1',
				classes,
				saved.classA || '',
				classLabels
			)
		);

		classControls.appendChild(
			makeSelect(
				'eql-equipment-class-b',
				'Class 2',
				classes,
				saved.classB || '',
				classLabels
			)
		);

		classControls.appendChild(
			makeSelect(
				'eql-equipment-class-c',
				'Class 3',
				classes,
				saved.classC || '',
				classLabels
			)
		);

		slotControls = document.createElement( 'div' );
		slotControls.className = 'eql-equipment-builder-slot-row';

		slotControls.appendChild(
			makeSelect( 'eql-equipment-slot', 'Slot', slots, saved.slot || 'Primary' )
		);

		slotControls.appendChild(
			makeSelect(
				'eql-equipment-stat',
				'Required stat',
				stats,
				saved.stat || '',
				statLabels
			)
		);

		weaponTypeField = makeSelect(
			'eql-equipment-weapon-type',
			'Damage type',
			[ '' ],
			'',
			weaponTypeLabels
		);
		weaponTypeField.id = 'eql-equipment-weapon-type-field';
		slotControls.appendChild( weaponTypeField );

		controls.appendChild( classControls );
		controls.appendChild( slotControls );

		actions = document.createElement( 'div' );
		actions.className = 'eql-equipment-builder-actions';

		searchButton = document.createElement( 'button' );
		searchButton.type = 'button';
		searchButton.id = 'eql-equipment-search-button';
		searchButton.className = 'eql-equipment-search-button';
		searchButton.textContent = 'Search';

		actions.appendChild( searchButton );
		controls.appendChild( actions );

		status = document.createElement( 'div' );
		status.id = 'eql-equipment-builder-status';
		status.className = 'eql-equipment-builder-status';

		results = document.createElement( 'div' );
		results.id = 'eql-equipment-builder-results';
		results.className = 'eql-equipment-builder-results';

		levelControl = document.createElement( 'div' );
		levelControl.id = 'eql-equipment-list-level-control';
		levelControl.className = 'eql-equipment-list-level-control';
		levelControl.hidden = true;

		root.appendChild( controls );
		root.appendChild( status );
		root.appendChild( levelControl );
		root.appendChild( results );

		syncWeaponTypeControl( saved.weaponType || '' );

		controls.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.id === 'eql-equipment-slot' ) {
				syncWeaponTypeControl();
			}

			markFiltersChanged();
		} );

		searchButton.addEventListener( 'click', loadResults );
		setStatus( 'Choose your filters, then select Search.', false );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initBuilder );
	} else {
		initBuilder();
	}
}());
