/**
 * Viewport → responsive breakpoint helpers (mirrors PHP `SimpleThemeOptions\ViewportOptions`).
 *
 * Localized as `stoViewport` when the theme enables `sto_enqueue_viewport_script`.
 */
(function ( w ) {
	'use strict';

	function resolveBreakpoint( cfg, width ) {
		cfg = cfg || {};
		var floor = parseInt( cfg.mobileFloor, 10 );
		if ( ! isFinite( floor ) || floor < 0 ) {
			floor = 320;
		}
		width = parseInt( width, 10 );
		if ( ! isFinite( width ) || width < 1 ) {
			width = 1;
		}
		if ( width < floor ) {
			return 'mobile';
		}
		var tiers = cfg.tiers || [];
		for ( var i = 0; i < tiers.length; i++ ) {
			var row = tiers[ i ];
			var m = parseInt( row && row.min, 10 );
			if ( ! isFinite( m ) || m < 1 ) {
				continue;
			}
			if ( width >= m ) {
				return String( row.bp || 'mobile' );
			}
		}
		return 'mobile';
	}

	function isBreakpointMap( stored, legacyKey ) {
		if ( ! stored || typeof stored !== 'object' || Array.isArray( stored ) ) {
			return false;
		}
		var keys = Object.keys( stored );
		if ( ! keys.length ) {
			return false;
		}
		var allowed = {
			xxl: 1,
			xl: 1,
			lg: 1,
			md: 1,
			sm: 1,
			xs: 1,
			mobile: 1,
		};
		allowed[ legacyKey || 'phone' ] = 1;
		for ( var i = 0; i < keys.length; i++ ) {
			if ( ! allowed.hasOwnProperty( keys[ i ] ) ) {
				return false;
			}
		}
		return true;
	}

	function getValueForWidth( options, id, width, localized ) {
		options = options || {};
		localized = localized || w.stoViewport || {};
		var cfg = localized.config || {};
		var legacyKey = localized.legacyPhoneKey || 'phone';
		var evalBp = localized.evalBp || 'xxl';

		id = String( id || '' ).replace( /[^a-z0-9_-]/gi, '' );
		if ( ! id || ! Object.prototype.hasOwnProperty.call( options, id ) ) {
			return undefined;
		}
		var stored = options[ id ];
		var bp = resolveBreakpoint( cfg, width );

		if ( isBreakpointMap( stored, legacyKey ) ) {
			if ( Object.prototype.hasOwnProperty.call( stored, bp ) ) {
				return stored[ bp ];
			}
			if ( bp === 'mobile' && Object.prototype.hasOwnProperty.call( stored, legacyKey ) ) {
				return stored[ legacyKey ];
			}
			if ( Object.prototype.hasOwnProperty.call( stored, evalBp ) ) {
				return stored[ evalBp ];
			}
			for ( var k in stored ) {
				if ( Object.prototype.hasOwnProperty.call( stored, k ) ) {
					return stored[ k ];
				}
			}
			return undefined;
		}
		return stored;
	}

	w.STOViewport = {
		resolveBreakpoint: resolveBreakpoint,
		getValueForWidth: getValueForWidth,
	};
})( window );
