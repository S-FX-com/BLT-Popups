/**
 * BLT Popups — editor scripts: media picker, conditional fields, destination
 * page search, the always-on sidebar live preview, and the publish
 * confirmation — plus the list-table quick-toggle switch. Vanilla JS + wp.media.
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
		// List-table quick toggle — runs on the popup list screen, which has
		// no .blt-popup-metabox, so this must not be gated on it below.
		setupStatusToggles();

		var box = $( '.blt-popup-metabox' );
		if ( ! box ) {
			return;
		}

		setupMedia();
		setupConditionalFields();
		setupDestinationPageSearch();
		setupPublishConfirm();

		// Live preview: render once from the fields' initial state, then keep
		// it in sync with any change inside the settings box.
		setupDeviceTabs();
		renderLivePreview();
		box.addEventListener( 'input', scheduleLivePreviewUpdate );
		box.addEventListener( 'change', scheduleLivePreviewUpdate );

		// Last: relies on WP core's own DOM structure (h1.wp-heading-inline
		// etc.), so it runs after everything load-bearing has already set up,
		// and is wrapped defensively in case that structure ever differs.
		try {
			setupTopbar();
		} catch ( err ) {
			if ( window.console && window.console.error ) {
				window.console.error( 'BLT Popups: sticky header setup failed', err );
			}
		}
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
		var ctaFields = $all( '.blt-popup-cta-conditional' );
		var ctaStyleRadios = $all( 'input[name="blt_popup_cta_style"]' );
		if ( cta && ctaFields.length ) {
			var applyCta = function () {
				var checkedStyle = ctaStyleRadios.filter( function ( r ) { return r.checked; } )[ 0 ];
				var style = checkedStyle ? checkedStyle.value : 'automatic';
				ctaFields.forEach( function ( el ) {
					var wantsStyle = el.getAttribute( 'data-cta-style' );
					el.style.display = ( cta.checked && ( ! wantsStyle || wantsStyle === style ) ) ? '' : 'none';
				} );
			};
			cta.addEventListener( 'change', applyCta );
			ctaStyleRadios.forEach( function ( r ) { r.addEventListener( 'change', applyCta ); } );
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

	var ANIMATIONS = [ 'none', 'fade', 'slide', 'zoom' ];
	function animationClass( animation ) {
		return 'blt-popup-anim-' + ( ANIMATIONS.indexOf( animation ) !== -1 ? animation : 'zoom' );
	}

	function currentValues() {
		var img = $( '.blt-popup-image-preview img' );
		var animInput = $( 'input[name="blt_popup_animation"]:checked' );
		return {
			imageSrc: img ? img.getAttribute( 'src' ) : '',
			ctaEnabled: !! ( $( '#blt_popup_cta_enabled' ) || {} ).checked,
			ctaText: ( $( '#blt_popup_cta_text' ) || {} ).value || '',
			ctaStyle: ( $( 'input[name="blt_popup_cta_style"]:checked' ) || {} ).value || 'automatic',
			ctaBgColor: ( $( '#blt_popup_cta_bg_color' ) || {} ).value || '#111111',
			ctaTextColor: ( $( '#blt_popup_cta_text_color' ) || {} ).value || '#ffffff',
			maxWidthPct: parseInt( ( $( '#blt_popup_max_width_pct' ) || {} ).value, 10 ) || 70,
			maxHeightPct: parseInt( ( $( '#blt_popup_max_height_pct' ) || {} ).value, 10 ) || 80,
			overlayColor: ( $( '#blt_popup_overlay_color' ) || {} ).value || '#000000',
			overlayOpacity: ( $( '#blt_popup_overlay_opacity' ) || {} ).value || '0.6',
			animation: animInput ? animInput.value : 'zoom'
		};
	}

	// A static representation of the popup, scaled to the sidebar frame
	// rather than the viewport — sizes are percentages of the frame (which
	// plays the role of "the viewport" here), not vw/vh. Rebuilt from scratch
	// on every change (see renderLivePreview), so the entrance animation
	// naturally replays each time — including when the animation choice
	// itself changes.
	function buildPreviewCanvas( v ) {
		var animCls = animationClass( v.animation );

		var canvas = document.createElement( 'div' );
		canvas.className = 'blt-popup-preview-canvas ' + animCls;
		canvas.style.backgroundColor = hexToRgba( v.overlayColor, v.overlayOpacity );

		var modal = document.createElement( 'div' );
		modal.className = 'blt-popup-modal ' + animCls;
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
			// "Automatic" relies on theme CSS (.btn--primary/.btn--secondary)
			// this sidebar mockup never loads, so it's left showing the same
			// default look as before; only "Custom color" has anything this
			// preview can accurately show.
			if ( 'custom' === v.ctaStyle ) {
				cta.style.backgroundColor = v.ctaBgColor;
				cta.style.color = v.ctaTextColor;
			}
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
	 * Publish confirm (editor) — publishing IS activating, natively
	 * ------------------------------------------------------------------ */

	function setupPublishConfirm() {
		var publishBtn = document.getElementById( 'publish' );
		if ( ! publishBtn ) {
			return;
		}
		// wp_localize_script casts scalars to strings, so an int 0 arrives as
		// "0" — truthy in JS. Normalise before testing/comparing.
		var activeId = parseInt( data.activeId, 10 ) || 0;
		var currentId = parseInt( data.currentId, 10 ) || 0;
		var alreadyLive = data.currentPostStatus === 'publish';

		// Only a popup that isn't already live, and only when some other
		// popup currently is, needs a heads-up before the click proceeds —
		// clicking "Update" on an already-live popup doesn't change anything.
		if ( alreadyLive || ! activeId || activeId === currentId ) {
			return;
		}

		publishBtn.addEventListener( 'click', function ( e ) {
			var msg = ( i18n.confirmReplace || 'This will deactivate the currently live popup "%s". Continue?' )
				.replace( '%s', data.activeTitle || '' );
			if ( ! window.confirm( msg ) ) {
				e.preventDefault();
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * List-table quick toggle
	 * ------------------------------------------------------------------ */

	function setupStatusToggles() {
		var toggles = $all( '.blt-popup-toggle-input' );
		if ( ! toggles.length || ! data.ajaxUrl ) {
			return;
		}

		toggles.forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var activate = input.checked;
				var cell = input.closest( '.blt-popup-status-cell' );
				var postId = cell ? cell.getAttribute( 'data-post-id' ) : '';
				if ( ! postId ) {
					return;
				}

				var msg;
				if ( activate ) {
					var activeId = parseInt( data.activeId, 10 ) || 0;
					if ( activeId && String( activeId ) !== postId ) {
						msg = ( i18n.confirmReplace || 'This will deactivate the currently live popup "%s". Continue?' )
							.replace( '%s', data.activeTitle || '' );
					} else {
						msg = i18n.confirmActivate || 'Make this popup live on the site now?';
					}
					if ( ! window.confirm( msg ) ) {
						input.checked = false;
						return;
					}
				}

				input.disabled = true;

				var body = 'action=blt_popups_toggle_status'
					+ '&nonce=' + encodeURIComponent( data.toggleNonce || '' )
					+ '&post_id=' + encodeURIComponent( postId )
					+ '&activate=' + ( activate ? '1' : '' );

				fetch( data.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						input.disabled = false;
						if ( ! res || ! res.success ) {
							input.checked = ! activate;
							window.alert( ( res && res.data && res.data.message ) || i18n.toggleError || 'Could not update this popup. Please try again.' );
							return;
						}

						data.activeId = res.data.activeId;
						data.activeTitle = res.data.activeTitle;
						updateStatusCell( cell, res.data.isLive );

						// Any other row previously showing as live is now
						// drafted server-side — reflect that without a reload.
						$all( '.blt-popup-status-cell' ).forEach( function ( otherCell ) {
							if ( otherCell === cell ) {
								return;
							}
							if ( String( res.data.activeId ) !== otherCell.getAttribute( 'data-post-id' ) ) {
								updateStatusCell( otherCell, false );
							}
						} );
					} )
					.catch( function () {
						input.disabled = false;
						input.checked = ! activate;
						window.alert( i18n.toggleError || 'Could not update this popup. Please try again.' );
					} );
			} );
		} );
	}

	function updateStatusCell( cell, isLive ) {
		if ( ! cell ) {
			return;
		}
		var input = cell.querySelector( '.blt-popup-toggle-input' );
		var badge = cell.querySelector( '.blt-popup-badge' );
		if ( input ) {
			input.checked = isLive;
		}
		if ( badge ) {
			badge.textContent = isLive ? ( i18n.live || 'Live' ) : ( i18n.draft || 'Draft' );
			badge.classList.remove( 'blt-popup-badge-active', 'blt-popup-badge-draft' );
			badge.classList.add( isLive ? 'blt-popup-badge-active' : 'blt-popup-badge-draft' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Sticky header bar
	 *
	 * Builds the custom top bar by relocating WP's own title heading and
	 * "Add New" link into it (real nodes, not clones, so nothing about them
	 * changes) and proxy-clicking the real #save-post/#publish buttons.
	 * Nothing about how WP saves/publishes is touched; the native Publish box
	 * is left in place as a fallback, just visually superseded by this bar.
	 * ------------------------------------------------------------------ */

	function setupTopbar() {
		var wrap = $( '.wrap' );
		var heading = wrap ? wrap.querySelector( ':scope > h1.wp-heading-inline' ) : null;
		if ( ! wrap || ! heading ) {
			return;
		}
		var addNew = wrap.querySelector( ':scope > a.page-title-action' );

		var bar = document.createElement( 'div' );
		bar.className = 'blt-popup-topbar';

		if ( data.popupListUrl ) {
			var back = document.createElement( 'a' );
			back.className = 'blt-popup-topbar-back';
			back.href = data.popupListUrl;
			back.textContent = '← ' + ( i18n.allPopups || 'All Popups' );
			bar.appendChild( back );
		}

		var titleWrap = document.createElement( 'div' );
		titleWrap.className = 'blt-popup-topbar-title';
		titleWrap.appendChild( heading ); // Relocates the real node — nothing left behind to hide.
		bar.appendChild( titleWrap );

		var spacer = document.createElement( 'div' );
		spacer.className = 'blt-popup-topbar-spacer';
		bar.appendChild( spacer );

		if ( addNew ) {
			addNew.classList.add( 'blt-popup-topbar-addnew', 'button' );
			bar.appendChild( addNew ); // Relocates the real node.
		}

		var actions = document.createElement( 'div' );
		actions.className = 'blt-popup-topbar-actions';

		// Proxy buttons call .click() on the real (still-present, just
		// visually superseded) native buttons rather than reimplementing
		// save/publish — so autosave, revisions and locking all keep
		// working exactly as WP core wired them.
		var saveDraft = document.getElementById( 'save-post' );
		if ( saveDraft ) {
			var draftBtn = document.createElement( 'button' );
			draftBtn.type = 'button';
			draftBtn.className = 'button blt-popup-topbar-save';
			draftBtn.textContent = saveDraft.value || i18n.saveDraft || 'Save Draft';
			draftBtn.addEventListener( 'click', function () {
				saveDraft.click();
			} );
			actions.appendChild( draftBtn );
		}

		var publish = document.getElementById( 'publish' );
		if ( publish ) {
			var publishBtn = document.createElement( 'button' );
			publishBtn.type = 'button';
			publishBtn.className = 'button button-primary blt-popup-topbar-publish';
			publishBtn.textContent = publish.value || i18n.publish || 'Publish';
			publishBtn.addEventListener( 'click', function () {
				publish.click();
			} );
			actions.appendChild( publishBtn );
		}

		bar.appendChild( actions );
		wrap.insertBefore( bar, wrap.firstChild );
	}
} )();
