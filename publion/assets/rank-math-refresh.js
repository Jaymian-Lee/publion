/* global rankMathEditor */
( function ( window, document ) {
	'use strict';

	/**
	 * Rank Math runs its analysis in the WordPress editor. Wait briefly for its
	 * documented editor object so Publion never fabricates or caches a score.
	 */
	function refreshRankMathAnalysis() {
		if ( ! window.rankMathEditor || typeof window.rankMathEditor.refresh !== 'function' ) {
			return false;
		}

		try {
			window.rankMathEditor.refresh( 'title' );
			window.rankMathEditor.refresh( 'content' );
			return true;
		} catch ( error ) {
			// Rank Math remains fully usable if its editor has not finished loading.
			return false;
		}
	}

	function tryRefresh( attemptsLeft ) {
		if ( refreshRankMathAnalysis() || attemptsLeft <= 0 ) {
			return;
		}

		window.setTimeout( function () {
			tryRefresh( attemptsLeft - 1 );
		}, 500 );
	}

	function initialise() {
		window.setTimeout( function () {
			tryRefresh( 12 );
		}, 250 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initialise );
	} else {
		initialise();
	}
}( window, document ) );
