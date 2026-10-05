/**
 * Tax categories prototype: reviewer guide.
 * Marks new fields (dashed outline + "New" badge with a tooltip), shows a "What's new" banner with
 * how-to steps, and runs a guided tour of the new fields using WordPress's built-in pointers.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.wctcGuide;
	if ( ! cfg || ! cfg.guide ) {
		return;
	}
	var guide = cfg.guide;
	var storeKey = 'wctc-guide-collapsed-' + cfg.key;

	function all( selector ) {
		try {
			return Array.prototype.slice.call( document.querySelectorAll( selector ) );
		} catch ( e ) {
			return []; // Browsers without :has() skip that field instead of breaking the page.
		}
	}

	function esc( text ) {
		return String( text ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function makeBadge( tip ) {
		var badge = document.createElement( 'span' );
		badge.className = 'wctc-badge';
		badge.tabIndex = 0;
		badge.setAttribute( 'role', 'note' );
		badge.setAttribute( 'aria-label', 'New: ' + tip );
		badge.setAttribute( 'data-tip', tip );
		badge.textContent = 'New';
		return badge;
	}

	// 1. Outline every new field and put a "New" badge with a tooltip on its label.
	function decorate() {
		guide.fields.forEach( function ( field ) {
			all( field.outline ).forEach( function ( el ) {
				el.classList.add( 'wctc-new' );
			} );
			if ( false === field.badge ) {
				return;
			}
			all( field.label ).forEach( function ( el ) {
				if ( el.querySelector( ':scope > .wctc-badge' ) ) {
					return;
				}
				var badge = makeBadge( field.tip );
				if ( 'prepend' === field.badge ) {
					el.insertBefore( badge, el.firstChild );
				} else {
					el.appendChild( badge );
				}
			} );
		} );
	}

	// 2. "What's new on this screen" banner with how-to steps and a tour button.
	function banner() {
		if ( document.querySelector( '.wctc-banner' ) ) {
			return;
		}
		var collapsed = false;
		try {
			collapsed = '1' === window.localStorage.getItem( storeKey );
		} catch ( e ) {}

		var steps = guide.steps.map( function ( step ) {
			return '<li>' + step + '</li>';
		} ).join( '' );
		var html =
			'<div class="wctc-banner' + ( collapsed ? ' is-collapsed' : '' ) + '" role="region" aria-label="What\'s new on this screen">' +
				'<div class="wctc-banner-head">' +
					'<span class="wctc-badge wctc-badge-static">New</span>' +
					'<strong class="wctc-banner-title">' + esc( guide.title ) + '</strong>' +
					'<span class="wctc-banner-actions">' +
						'<button type="button" class="button button-primary wctc-tour-start">Take the tour</button>' +
						'<button type="button" class="button-link wctc-banner-toggle" aria-expanded="' + ( collapsed ? 'false' : 'true' ) + '">' + ( collapsed ? 'Show how to use it' : 'Hide' ) + '</button>' +
					'</span>' +
				'</div>' +
				'<div class="wctc-banner-body">' +
					'<p>' + esc( guide.intro ) + '</p>' +
					'<h4>How to use it</h4>' +
					'<ol>' + steps + '</ol>' +
					'<p class="wctc-legend"><span class="wctc-legend-swatch" aria-hidden="true"></span>Outlined fields are new in the tax categories prototype. Hover or tab to a <span class="wctc-badge wctc-badge-static">New</span> badge to see what it does.</p>' +
				'</div>' +
			'</div>';

		var $banner = $( html );
		var $anchor = $( '#mainform' ).first();
		if ( $anchor.length ) {
			$anchor.prepend( $banner );
		} else if ( $( 'hr.wp-header-end' ).length ) {
			$( 'hr.wp-header-end' ).first().after( $banner );
		} else {
			$( '#wpbody-content .wrap' ).first().prepend( $banner );
		}

		$banner.on( 'click', '.wctc-banner-toggle', function () {
			var isCollapsed = $banner.toggleClass( 'is-collapsed' ).hasClass( 'is-collapsed' );
			$( this ).attr( 'aria-expanded', isCollapsed ? 'false' : 'true' ).text( isCollapsed ? 'Show how to use it' : 'Hide' );
			try {
				window.localStorage.setItem( storeKey, isCollapsed ? '1' : '0' );
			} catch ( e ) {}
		} );
		$banner.on( 'click', '.wctc-tour-start', startTour );
	}

	// 3. Guided tour: one pointer per new field that is on screen.
	var current = null;

	function tourSteps() {
		var steps = [];
		guide.fields.forEach( function ( field ) {
			if ( false === field.tour ) {
				return;
			}
			var el = all( field.outline ).filter( function ( node ) {
				return node.offsetParent !== null;
			} )[ 0 ];
			if ( el ) {
				steps.push( { el: el, title: field.title, tip: field.tip } );
			}
		} );
		return steps;
	}

	function closeCurrent() {
		if ( current ) {
			try {
				current.pointer( 'close' );
				current.pointer( 'destroy' );
			} catch ( e ) {}
			current = null;
		}
		$( '.wctc-touring' ).removeClass( 'wctc-touring' );
	}

	function showStep( steps, i ) {
		closeCurrent();
		var step = steps[ i ];
		var last = i === steps.length - 1;
		step.el.scrollIntoView( { block: 'center', behavior: 'auto' } );
		step.el.classList.add( 'wctc-touring' );
		var $el = $( step.el );
		window.setTimeout( function () {
			$el.pointer( {
				pointerClass: 'wp-pointer wctc-pointer',
				content: '<h3>' + esc( step.title ) + '<span class="wctc-step">' + ( i + 1 ) + ' of ' + steps.length + '</span></h3><p>' + esc( step.tip ) + '</p>',
				position: { edge: 'top', align: 'left' },
				buttons: function () {
					var $wrap = $( '<div class="wctc-tour-buttons"></div>' );
					$( '<button type="button" class="button-link wctc-tour-close">Close tour</button>' ).on( 'click', closeCurrent ).appendTo( $wrap );
					if ( i > 0 ) {
						$( '<button type="button" class="button">Back</button>' ).on( 'click', function () {
							showStep( steps, i - 1 );
						} ).appendTo( $wrap );
					}
					$( '<button type="button" class="button button-primary">' + ( last ? 'Done' : 'Next' ) + '</button>' ).on( 'click', function () {
						if ( last ) {
							closeCurrent();
						} else {
							showStep( steps, i + 1 );
						}
					} ).appendTo( $wrap );
					return $wrap;
				},
			} ).pointer( 'open' );
			current = $el;
			$( '.wctc-pointer .button-primary' ).last().trigger( 'focus' );
		}, 150 );
	}

	function startTour() {
		var steps = tourSteps();
		if ( ! steps.length ) {
			return;
		}
		showStep( steps, 0 );
	}

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && current ) {
			closeCurrent();
		}
	} );

	$( function () {
		decorate();
		banner();
		// Variations and order items load after the page; mark them as they arrive.
		var timer = null;
		new MutationObserver( function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( decorate, 200 );
		} ).observe( document.body, { childList: true, subtree: true } );
	} );
}( jQuery ) );
