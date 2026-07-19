/**
 * BLT Popups — editor scripts: media picker, conditional fields, in-admin
 * live preview, and the activate confirmation. Vanilla JS + wp.media.
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
		setupPreview();
		setupActivate();
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
	}

	/* ------------------------------------------------------------------ *
	 * In-admin live preview (mirrors the front-end markup + CSS)
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
			destUrl: ( $( '#blt_popup_dest_url' ) || {} ).value || '',
			ctaEnabled: !! ( $( '#blt_popup_cta_enabled' ) || {} ).checked,
			ctaText: ( $( '#blt_popup_cta_text' ) || {} ).value || '',
			maxWidthPct: parseInt( ( $( '#blt_popup_max_width_pct' ) || {} ).value, 10 ) || 70,
			maxHeightPct: parseInt( ( $( '#blt_popup_max_height_pct' ) || {} ).value, 10 ) || 80,
			overlayColor: ( $( '#blt_popup_overlay_color' ) || {} ).value || '#000000',
			overlayOpacity: ( $( '#blt_popup_overlay_opacity' ) || {} ).value || '0.6'
		};
	}

	function closePreview() {
		var existing = $( '.blt-popup-overlay' );
		if ( existing && existing.parentNode ) {
			existing.parentNode.removeChild( existing );
		}
		document.documentElement.classList.remove( 'blt-popup-open' );
	}

	function renderPreview() {
		var v = currentValues();
		if ( ! v.imageSrc ) {
			window.alert( i18n.noImage || 'Select an image first to preview the popup.' );
			return;
		}
		closePreview();

		var overlay = document.createElement( 'div' );
		overlay.className = 'blt-popup-overlay';
		overlay.style.backgroundColor = hexToRgba( v.overlayColor, v.overlayOpacity );

		var modal = document.createElement( 'div' );
		modal.className = 'blt-popup-modal';
		var maxW = Math.min( Math.max( v.maxWidthPct, 10 ), 100 );
		var maxH = Math.min( Math.max( v.maxHeightPct, 10 ), 100 );
		modal.style.maxWidth = 'min(' + maxW + 'vw, 1400px)';
		modal.style.maxHeight = maxH + 'vh';

		var closeBtn = document.createElement( 'button' );
		closeBtn.className = 'blt-popup-close';
		closeBtn.type = 'button';
		closeBtn.setAttribute( 'aria-label', i18n.close || 'Close' );
		closeBtn.innerHTML = '&times;';
		closeBtn.addEventListener( 'click', closePreview );

		var img = document.createElement( 'img' );
		img.className = 'blt-popup-img';
		img.src = v.imageSrc;
		img.alt = '';
		img.style.maxHeight = maxH + 'vh';

		modal.appendChild( closeBtn );
		modal.appendChild( img );

		if ( v.ctaEnabled ) {
			var cta = document.createElement( 'button' );
			cta.className = 'blt-popup-cta';
			cta.type = 'button';
			cta.textContent = v.ctaText || 'Learn more';
			modal.appendChild( cta );
		}

		overlay.appendChild( modal );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				closePreview();
			}
		} );
		document.addEventListener( 'keydown', function onKey( e ) {
			if ( e.key === 'Escape' || e.keyCode === 27 ) {
				closePreview();
				document.removeEventListener( 'keydown', onKey );
			}
		} );

		document.body.appendChild( overlay );
		document.documentElement.classList.add( 'blt-popup-open' );
		closeBtn.focus();
	}

	function setupPreview() {
		var btn = $( '.blt-popup-preview-btn' );
		if ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				renderPreview();
			} );
		}
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
			if ( data.activeId && data.activeId !== data.currentId ) {
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
