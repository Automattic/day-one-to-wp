( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'day-one-importer-form' );

		if ( ! form ) {
			return;
		}

		var submitButton = document.getElementById( 'day_one_importer_submit_button' );
		var statusRegion = document.getElementById( 'day-one-importer-status' );
		var statusMessage = statusRegion ? statusRegion.querySelector( '.day-one-importer-status-message' ) : null;
		var spinner = statusRegion ? statusRegion.querySelector( '.spinner' ) : null;
		var submitted = false;

		form.addEventListener( 'submit', function ( event ) {
			if ( form.checkValidity && ! form.checkValidity() ) {
				return;
			}

			if ( submitted ) {
				event.preventDefault();
				return;
			}

			submitted = true;

			if ( statusRegion ) {
				statusRegion.classList.remove( 'screen-reader-text' );
				statusRegion.removeAttribute( 'hidden' );
				statusRegion.setAttribute( 'aria-busy', 'true' );
			}

			if ( statusMessage && statusRegion && statusRegion.dataset.startedMessage ) {
				statusMessage.textContent = statusRegion.dataset.startedMessage;
			}

			if ( spinner ) {
				spinner.classList.add( 'is-active' );
			}

			if ( submitButton ) {
				var runningLabel = statusRegion && statusRegion.dataset.runningLabel ? statusRegion.dataset.runningLabel : '';

				if ( runningLabel && 'value' in submitButton ) {
					submitButton.value = runningLabel;
				} else if ( runningLabel ) {
					submitButton.textContent = runningLabel;
				}

				submitButton.setAttribute( 'aria-disabled', 'true' );
				submitButton.disabled = true;
			}

			form.setAttribute( 'aria-busy', 'true' );
		} );
	} );
}() );
