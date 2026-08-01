/**
 * BLT Popups — editor scripts: media picker, conditional fields, destination
 * page search, the always-on sidebar live preview, and the activate
 * confirmation. Vanilla JS + wp.media.
 */
( function () {
	'use strict';

	var data = window.bltPopupsAdmin || {};
	var i18n = data.i18n || {};

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function $all( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var box = $( '.blt-popup-metabox' );
		if ( ! box ) {
			return;
		}

		setupMedia();
		setupConditionalFields();
		setupDestinationPageSearch();
		setupActivate();

		// Live preview: render once from the fields' initial state, then keep
		// it in sync with any change inside the settings box.
		setupDeviceTabs();
		renderLivePreview();
		box.addEventListener( 'input', scheduleLivePreviewUpdate );
		box.addEventListener( 'change', scheduleLivePreviewUpdate );
	} );

	/* ------------------------------------------------------------------ *
	 * Media picker
	 * ------------------------------------------------------------------ */

	function setupMedia() {
		var selectBtn = $( '.blt-popup-image-select' );
		var removeBtn = $( '.blt-popup-image-remove' );
		var hidden = $( '#blt_popup_image_id' );
		var preview = $( '.blt-popup-image-preview' );
		if ( ! selectBtn || ! hidden ) {
			return;
		}

		var frame;
		selectBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( frame ) {
				frame.open();
				return;
			}
			frame = window.wp.media( {
				title: data.mediaTitle || 'Select image',
				button: { text: data.mediaButton || 'Use this image' },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				hidden.value = att.id;
				var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
				preview.classList.remove( 'is-empty' );
				preview.innerHTML = '<img src="' + url + '" alt="" />';
				if ( removeBtn ) {
					removeBtn.style.display = '';
				}
				renderLivePreview();
			} );
			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				hidden.value = '';
				preview.innerHTML = '';
				preview.classList.add( 'is-empty' );
				removeBtn.style.display = 'none';
				renderLivePreview();
			} );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Conditional fields
	 * ------------------------------------------------------------------ */

	function setupConditionalFields() {
		var mode = $( '#blt_popup_target_mode' );
		if ( mode ) {
			var applyMode = function () {
				$all( '.blt-popup-target' ).forEach( function ( el ) {
					el.style.display = ( el.getAttribute( 'data-mode' ) === mode.value ) ? '' : 'none';
				} );
			};
			mode.addEventListener( 'change', applyMode );
			applyMode();
		}

		var freq = $( '#blt_popup_frequency' );
		var freqDays = $( '.blt-popup-frequency-days' );
		if ( freq && freqDays ) {
			var applyFreq = function () {
				freqDays.style.display = ( freq.value === 'every_n_days' ) ? '' : 'none';
			};
			freq.addEventListener( 'change', applyFreq );
			applyFreq();
		}

		var cta = $( '#blt_popup_cta_enabled' );
		var ctaText = $( '.blt-popup-cta-text' );
		if ( cta && ctaText ) {
			var applyCta = function () {
				ctaText.style.display = cta.checked ? '' : 'none';
			};
			cta.addEventListener( 'change', applyCta );
			applyCta();
		}

		var destRadios = $all( 'input[name="blt_popup_dest_type"]' );
		if ( destRadios.length ) {
			var applyDestType = function () {
				var checked = destRadios.filter( function ( r ) { return r.checked; } )[ 0 ];
				var val = checked ? checked.value : 'external';
				$all( '[data-dest]' ).forEach( function ( el ) {
					el.style.display = ( el.getAttribute( 'data-dest' ) === val ) ? '' : 'none';
				} );
			};
			destRadios.forEach( function ( r ) { r.addEventListener( 'change', applyDestType ); } );
			applyDestType();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Internal destination: predictive page search
	 *
	 * Reuses core's own /wp/v2/search endpoint — the same one the block
	 * editor's "Link" UI uses for its suggestions — rather than a bespoke
	 * REST route.
	 * ------------------------------------------------------------------ */

	function setupDestinationPageSearch() {
		var input = $( '#blt_popup_dest_page_search' );
		var hidden = $( '#blt_popup_dest_page_id' );
		var list = $( '.blt-popup-page-suggestions' );
		if ( ! input || ! hidden || ! list || ! data.restSearchUrl ) {
			return;
		}

		var debounceTimer = null;
		var requestSeq = 0;
		var items = [];
		var activeIndex = -1;

		function closeList() {
			list.hidden = true;
			list.innerHTML = '';
			items = [];
			activeIndex = -1;
			input.setAttribute( 'aria-expanded', 'false' );
		}

		function selectItem( item ) {
			hidden.value = item.id;
			input.value = item.title;
			closeList();
		}

		function renderMessage( text ) {
			list.innerHTML = '';
			var li = document.createElement( 'li' );
			li.className = 'blt-popup-page-suggestion-empty';
			li.textContent = text;
			list.appendChild( li );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function renderItems( results ) {
			list.innerHTML = '';
			items = results;
			activeIndex = -1;

			if ( ! results.length ) {
				renderMessage( i18n.noResults || 'No matching pages found.' );
				return;
			}

			results.forEach( function ( item, index ) {
				var li = document.createElement( 'li' );
				li.className = 'blt-popup-page-suggestion';
				li.textContent = item.title || '';
				li.setAttribute( 'data-index', index );
				li.setAttribute( 'role', 'option' );
				// mousedown (not click) fires before the input's blur handler,
				// so the selection registers before the list gets closed.
				li.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					selectItem( item );
				} );
				list.appendChild( li );
			} );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function highlight( index ) {
			var children = $all( 'li', list );
			children.forEach( function ( el ) { el.classList.remove( 'is-active' ); } );
			if ( index >= 0 && children[ index ] ) {
				children[ index ].classList.add( 'is-active' );
				children[ index ].scrollIntoView( { block: 'nearest' } );
			}
			activeIndex = index;
		}

		function search( term ) {
			var seq = ++requestSeq;
			renderMessage( i18n.searching || 'Searching…' );

			var url = data.restSearchUrl
				+ ( data.restSearchUrl.indexOf( '?' ) === -1 ? '?' : '&' )
				+ 'search=' + encodeURIComponent( term )
				+ '&type=post&subtype=page&per_page=8';

			fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': data.restNonce || '' }
			} )
				.then( function ( r ) { return r.ok ? r.json() : []; } )
				.then( function ( results ) {
					// Drop stale responses from a since-superseded keystroke.
					if ( seq !== requestSeq ) {
						return;
					}
					renderItems( ( results || [] ).map( function ( r ) {
						return { id: r.id, title: r.title || '' };
					} ) );
				} )
				.catch( function () {
					if ( seq === requestSeq ) {
						closeList();
					}
				} );
		}

		input.addEventListener( 'input', function () {
			// Typing invalidates whatever was previously selected until a
			// suggestion is clicked again.
			hidden.value = '';
			var term = input.value.trim();
			if ( debounceTimer ) {
				clearTimeout( debounceTimer );
			}
			if ( term.length < 2 ) {
				closeList();
				return;
			}
			debounceTimer = setTimeout( function () { search( term ); }, 300 );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( list.hidden || ! items.length ) {
				return;
			}
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				highlight( Math.min( activeIndex + 1, items.length - 1 ) );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				highlight( Math.max( activeIndex - 1, 0 ) );
			} else if ( e.key === 'Enter' ) {
				if ( activeIndex > -1 ) {
					e.preventDefault();
					selectItem( items[ activeIndex ] );
				}
			} else if ( e.key === 'Escape' ) {
				closeList();
			}
		} );

		input.addEventListener( 'blur', function () {
			// Delay so a suggestion's mousedown can run first.
			setTimeout( closeList, 150 );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Live preview (sidebar, always-on) — mirrors the front-end markup
	 * ------------------------------------------------------------------ */

	function hexToRgba( hex, opacity ) {
		var h = String( hex || '#000000' ).replace( '#', '' );
		if ( h.length === 3 ) {
			h = h[ 0 ] + h[ 0 ] + h[ 1 ] + h[ 1 ] + h[ 2 ] + h[ 2 ];
		}
		if ( ! /^[0-9a-fA-F]{6}$/.test( h ) ) { h = '000000'; }
		var o = parseFloat( opacity );
		if ( isNaN( o ) || o < 0 ) { o = 0.6; }
		if ( o > 1 ) { o = 1; }
		return 'rgba(' + parseInt( h.substr( 0, 2 ), 16 ) + ',' + parseInt( h.substr( 2, 2 ), 16 ) + ',' + parseInt( h.substr( 4, 2 ), 16 ) + ',' + o + ')';
	}

	function currentValues() {
		var img = $( '.blt-popup-image-preview img' );
		return {
			imageSrc: img ? img.getAttribute( 'src' ) : '',
			ctaEnabled: !! ( $( '#blt_popup_cta_enabled' ) || {} ).checked,
			ctaText: ( $( '#blt_popup_cta_text' ) || {} ).value || '',
			maxWidthPct: parseInt( ( $( '#blt_popup_max_width_pct' ) || {} ).value, 10 ) || 70,
			maxHeightPct: parseInt( ( $( '#blt_popup_max_height_pct' ) || {} ).value, 10 ) || 80,
			overlayColor: ( $( '#blt_popup_overlay_color' ) || {} ).value || '#000000',
			overlayOpacity: ( $( '#blt_popup_overlay_opacity' ) || {} ).value || '0.6'
		};
	}

	// A static representation of the popup, scaled to the sidebar frame
	// rather than the viewport — sizes are percentages of the frame (which
	// plays the role of "the viewport" here), not vw/vh.
	function buildPreviewCanvas( v ) {
		var canvas = document.createElement( 'div' );
		canvas.className = 'blt-popup-preview-canvas';
		canvas.style.backgroundColor = hexToRgba( v.overlayColor, v.overlayOpacity );

		var modal = document.createElement( 'div' );
		modal.className = 'blt-popup-modal';
		var maxW = Math.min( Math.max( v.maxWidthPct, 10 ), 100 );
		var maxH = Math.min( Math.max( v.maxHeightPct, 10 ), 100 );
		modal.style.maxWidth = maxW + '%';
		modal.style.maxHeight = maxH + '%';

		// Decorative only (no close/activate behaviour) — this is a mockup of
		// what the front end will render, not an interactive instance of it.
		var closeBtn = document.createElement( 'span' );
		closeBtn.className = 'blt-popup-close';
		closeBtn.setAttribute( 'aria-hidden', 'true' );
		closeBtn.innerHTML = '&times;';

		var img = document.createElement( 'img' );
		img.className = 'blt-popup-img';
		img.src = v.imageSrc;
		img.alt = '';

		modal.appendChild( closeBtn );
		modal.appendChild( img );

		if ( v.ctaEnabled ) {
			var cta = document.createElement( 'span' );
			cta.className = 'blt-popup-cta';
			cta.textContent = v.ctaText || i18n.ctaFallback || 'Learn more';
			modal.appendChild( cta );
		}

		canvas.appendChild( modal );
		return canvas;
	}

	function renderLivePreview() {
		var frame = $( '.blt-popup-preview-frame' );
		if ( ! frame ) {
			return;
		}
		var v = currentValues();
		frame.innerHTML = '';
		if ( ! v.imageSrc ) {
			var empty = document.createElement( 'p' );
			empty.className = 'blt-popup-preview-empty';
			empty.textContent = i18n.previewEmpty || 'Select an image to preview your popup.';
			frame.appendChild( empty );
			return;
		}
		frame.appendChild( buildPreviewCanvas( v ) );
	}

	var livePreviewTimer = null;
	function scheduleLivePreviewUpdate() {
		if ( livePreviewTimer ) {
			clearTimeout( livePreviewTimer );
		}
		livePreviewTimer = setTimeout( renderLivePreview, 120 );
	}

	function setupDeviceTabs() {
		var tabs = $all( '.blt-popup-device-tab' );
		var frame = $( '.blt-popup-preview-frame' );
		if ( ! tabs.length || ! frame ) {
			return;
		}
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				tabs.forEach( function ( t ) {
					t.classList.remove( 'is-active' );
					t.setAttribute( 'aria-selected', 'false' );
				} );
				tab.classList.add( 'is-active' );
				tab.setAttribute( 'aria-selected', 'true' );
				frame.setAttribute( 'data-device', tab.getAttribute( 'data-device' ) || 'desktop' );
			} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Activate
	 * ------------------------------------------------------------------ */

	function setupActivate() {
		var btn = $( '.blt-popup-activate-btn' );
		var status = $( '#blt_popup_status' );
		if ( ! btn || ! status ) {
			return;
		}
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var msg;
			// wp_localize_script casts scalars to strings, so an int 0 arrives as
			// "0" — truthy in JS. Normalise before testing/comparing.
			var activeId = parseInt( data.activeId, 10 ) || 0;
			var currentId = parseInt( data.currentId, 10 ) || 0;
			if ( activeId && activeId !== currentId ) {
				msg = ( i18n.confirmReplace || 'This will deactivate the currently live popup "%s". Continue?' )
					.replace( '%s', data.activeTitle || '' );
			} else {
				msg = i18n.confirmActivate || 'Make this popup live on the site now?';
			}
			if ( ! window.confirm( msg ) ) {
				return;
			}
			status.value = 'active';
			// Save through the normal editor flow so every field persists and
			// single-active enforcement runs server-side.
			var publish = document.getElementById( 'publish' ) || document.getElementById( 'save-post' );
			if ( publish ) {
				publish.click();
			} else if ( status.form ) {
				status.form.submit();
			}
		} );
	}
} )();
