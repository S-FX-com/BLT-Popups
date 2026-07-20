/**
 * BLT Popups — front-end render, close, frequency, and tracking.
 *
 * Vanilla JS, no jQuery, no external dependencies. The server has already
 * decided (via page targeting) that a popup *may* apply here; this script
 * makes the volatile, cache-unsafe decisions client-side: the per-visitor
 * frequency cookie, and the fresh date/time check via the REST endpoint.
 */
( function () {
	'use strict';

	var cfg = window.bltPopups;
	if ( ! cfg ) {
		return;
	}

	var PX_CAP = 1400; // Sensible hard cap so % sizing doesn't overscale on huge screens.
	var lastFocused = null;
	var keyHandler = null;

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Cookies
	 * ------------------------------------------------------------------ */

	function getCookie( name ) {
		var parts = document.cookie ? document.cookie.split( ';' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			var c = parts[ i ].replace( /^\s+/, '' );
			if ( c.indexOf( name + '=' ) === 0 ) {
				return decodeURIComponent( c.substring( name.length + 1 ) );
			}
		}
		return null;
	}

	function setCookie( name, days ) {
		var suffix = '; path=/; SameSite=Lax';
		if ( location.protocol === 'https:' ) {
			suffix += '; Secure';
		}
		if ( days === null ) {
			// Session cookie (cleared when the browser closes).
			document.cookie = name + '=1' + suffix;
			return;
		}
		var d = new Date();
		d.setTime( d.getTime() + days * 24 * 60 * 60 * 1000 );
		document.cookie = name + '=1; expires=' + d.toUTCString() + suffix;
	}

	// Days of persistence per frequency mode; null = session; false = never set.
	function cookieDays( frequency, frequencyDays ) {
		switch ( frequency ) {
			case 'always':
				return false;
			case 'session':
				return null;
			case 'daily':
				return 1;
			case 'every_n_days':
				return Math.max( 1, parseInt( frequencyDays, 10 ) || 1 );
			case 'once':
				return 3650; // ~10 years ≈ "once ever".
			default:
				return null;
		}
	}

	// Record that this visitor has seen the popup, per the frequency rule.
	// No-op in preview mode and for "always" (which never caps).
	function markSeen( popup ) {
		if ( cfg.previewMode || ! popup ) {
			return;
		}
		// Use the frequency the server actually served with this popup, not a
		// value baked into (possibly stale) cached HTML.
		var days = cookieDays( popup.frequency, popup.frequencyDays );
		if ( days !== false ) {
			setCookie( cfg.cookiePrefix + popup.id, days );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Tracking
	 * ------------------------------------------------------------------ */

	function track( id, type ) {
		if ( cfg.previewMode || ! cfg.restTrack ) {
			return;
		}
		// fetch(keepalive) rather than sendBeacon: it survives the page unload
		// on a click-through AND can carry X-WP-Nonce, which lets the (uncached)
		// /track endpoint recognise a logged-in admin and exclude them from the
		// counts. sendBeacon cannot set headers, so the server couldn't tell an
		// admin from a visitor.
		try {
			fetch( cfg.restTrack, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
				body: JSON.stringify( { id: id, type: type } ),
				keepalive: true,
				credentials: 'same-origin'
			} ).catch( function () {} );
		} catch ( e ) {}
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	function hexToRgba( hex, opacity ) {
		var h = String( hex || '#000000' ).replace( '#', '' );
		if ( h.length === 3 ) {
			h = h[ 0 ] + h[ 0 ] + h[ 1 ] + h[ 1 ] + h[ 2 ] + h[ 2 ];
		}
		if ( ! /^[0-9a-fA-F]{6}$/.test( h ) ) {
			h = '000000';
		}
		var r = parseInt( h.substring( 0, 2 ), 16 );
		var g = parseInt( h.substring( 2, 4 ), 16 );
		var b = parseInt( h.substring( 4, 6 ), 16 );
		var o = parseFloat( opacity );
		if ( isNaN( o ) || o < 0 ) { o = 0.6; }
		if ( o > 1 ) { o = 1; }
		return 'rgba(' + r + ',' + g + ',' + b + ',' + o + ')';
	}

	function close( overlay, popup ) {
		if ( ! overlay || ! overlay.parentNode ) {
			return;
		}
		overlay.parentNode.removeChild( overlay );
		document.documentElement.classList.remove( 'blt-popup-open' );
		if ( keyHandler ) {
			document.removeEventListener( 'keydown', keyHandler );
			keyHandler = null;
		}
		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			lastFocused.focus();
		}
		// Closing also records the view (idempotent with markSeen at render).
		markSeen( popup );
	}

	function render( popup ) {
		if ( ! popup || ! popup.image || ! popup.image.src ) {
			return;
		}
		// Never inject twice.
		if ( document.querySelector( '.blt-popup-overlay' ) ) {
			return;
		}

		lastFocused = document.activeElement;

		var overlay = document.createElement( 'div' );
		overlay.className = 'blt-popup-overlay';
		overlay.setAttribute( 'data-popup-id', popup.id );
		overlay.style.backgroundColor = hexToRgba( popup.overlayColor, popup.overlayOpacity );

		var modal = document.createElement( 'div' );
		modal.className = 'blt-popup-modal';
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-label', popup.image.alt || 'Promotion' );
		var maxW = Math.min( Math.max( parseInt( popup.maxWidthPct, 10 ) || 70, 10 ), 100 );
		var maxH = Math.min( Math.max( parseInt( popup.maxHeightPct, 10 ) || 80, 10 ), 100 );
		modal.style.maxWidth = 'min(' + maxW + 'vw, ' + PX_CAP + 'px)';
		modal.style.maxHeight = maxH + 'vh';

		var closeBtn = document.createElement( 'button' );
		closeBtn.className = 'blt-popup-close';
		closeBtn.setAttribute( 'type', 'button' );
		closeBtn.setAttribute( 'aria-label', 'Close' );
		closeBtn.innerHTML = '&times;';
		closeBtn.addEventListener( 'click', function () {
			close( overlay, popup );
		} );

		var img = document.createElement( 'img' );
		img.className = 'blt-popup-img';
		img.src = popup.image.src;
		img.alt = popup.image.alt || '';
		if ( popup.image.srcset ) {
			img.setAttribute( 'srcset', popup.image.srcset );
		}
		if ( popup.image.width ) { img.setAttribute( 'width', popup.image.width ); }
		if ( popup.image.height ) { img.setAttribute( 'height', popup.image.height ); }
		img.style.maxHeight = maxH + 'vh';

		var navigate = function ( e ) {
			if ( e ) { e.preventDefault(); }
			track( popup.id, 'click' );
			if ( ! popup.destUrl ) {
				return;
			}
			if ( popup.newTab ) {
				window.open( popup.destUrl, '_blank', 'noopener' );
			} else {
				window.location.href = popup.destUrl;
			}
		};

		var media;
		if ( popup.destUrl ) {
			media = document.createElement( 'a' );
			media.className = 'blt-popup-link';
			media.href = popup.destUrl;
			if ( popup.newTab ) {
				media.target = '_blank';
				media.rel = 'noopener';
			}
			media.appendChild( img );
			media.addEventListener( 'click', navigate );
		} else {
			media = img;
		}

		modal.appendChild( closeBtn );
		modal.appendChild( media );

		if ( popup.cta && popup.cta.enabled && popup.destUrl ) {
			var cta = document.createElement( 'button' );
			cta.className = 'blt-popup-cta';
			cta.setAttribute( 'type', 'button' );
			cta.textContent = popup.cta.text || 'Learn more';
			cta.addEventListener( 'click', navigate );
			modal.appendChild( cta );
		}

		overlay.appendChild( modal );
		overlay.addEventListener( 'click', function ( e ) {
			// Outside-click only: ignore clicks that bubbled from the modal.
			if ( e.target === overlay ) {
				close( overlay, popup );
			}
		} );

		keyHandler = function ( e ) {
			if ( e.key === 'Escape' || e.keyCode === 27 ) {
				close( overlay, popup );
				return;
			}
			// Trap Tab focus inside the modal so keyboard users can't reach the
			// obscured background while the dialog is open (aria-modal alone
			// doesn't affect focus order).
			if ( e.key === 'Tab' || e.keyCode === 9 ) {
				var focusable = modal.querySelectorAll( 'button, a[href], [tabindex]:not([tabindex="-1"])' );
				if ( ! focusable.length ) {
					return;
				}
				var first = focusable[ 0 ];
				var last = focusable[ focusable.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				} else if ( ! modal.contains( document.activeElement ) ) {
					e.preventDefault();
					first.focus();
				}
			}
		};
		document.addEventListener( 'keydown', keyHandler );

		document.body.appendChild( overlay );
		document.documentElement.classList.add( 'blt-popup-open' );
		closeBtn.focus();

		// Record the view now (per spec §7.3) so a reload within the frequency
		// window won't re-show it even if the visitor navigates away without
		// closing. Closing records it again (idempotent).
		markSeen( popup );
		track( popup.id, 'impression' );
	}

	/* ------------------------------------------------------------------ *
	 * Bootstrap
	 * ------------------------------------------------------------------ */

	ready( function () {
		// Preview bypass: render immediately from the inline config.
		if ( cfg.previewMode && cfg.preview ) {
			render( cfg.preview );
			return;
		}

		if ( ! cfg.restActive ) {
			return;
		}

		// Ask the (uncached) endpoint which popup — if any — is eligible right
		// now. This is the single source of truth for identity, schedule and
		// frequency, so a switched or rescheduled popup can't go stale on a
		// cached page. The frequency cookie is then checked against the id the
		// server actually returned, never a value baked into the HTML.
		var url = cfg.restActive
			+ ( cfg.restActive.indexOf( '?' ) === -1 ? '?' : '&' )
			+ 'url=' + encodeURIComponent( location.href )
			+ '&path=' + encodeURIComponent( location.pathname + location.search )
			+ '&_=' + Date.now(); // Cache-buster for cache-everything edges.

		fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.restNonce || '' } } )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( data ) {
				var popup = data && data.popup;
				if ( ! popup ) {
					return;
				}
				// Frequency cap: skip if this visitor has already seen THIS popup.
				if ( popup.frequency !== 'always' && getCookie( cfg.cookiePrefix + popup.id ) ) {
					return;
				}
				render( popup );
			} )
			.catch( function () {} );
	} );
} )();
