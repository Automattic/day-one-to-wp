( function () {
	'use strict';

	function ready( callback ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', callback );
		} else {
			callback();
		}
	}

	function postJobAction( action, jobId ) {
		var config = window.DayOneImporterJobs || {};
		var body = new window.URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce || '' );
		body.append( 'job_id', jobId || config.jobId || '' );

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( ! response.ok || ! payload || ! payload.success ) {
					var message = payload && payload.data && payload.data.message ? payload.data.message : 'Import request failed.';
					throw new Error( message );
				}
				return payload.data;
			} );
		} );
	}

	function text( selector, value ) {
		var node = document.querySelector( selector );
		if ( node ) {
			node.textContent = value || '';
		}
	}

	function countLabel( key ) {
		var config = window.DayOneImporterJobs || {};
		if ( config.countLabels && config.countLabels[ key ] ) {
			return config.countLabels[ key ];
		}

		return key.replace( /_/g, ' ' ).replace( /\b\w/g, function ( letter ) {
			return letter.toUpperCase();
		} );
	}

	function renderCounts( counts ) {
		var keys = [
			'json_files_found',
			'entries_found',
			'posts_created',
			'posts_skipped',
			'posts_resumed',
			'entries_failed',
			'tags_assigned',
			'media_found',
			'media_imported',
			'media_reused',
			'media_missing',
			'media_unsupported',
			'media_failed',
		];
		return '<ul>' + keys.map( function ( key ) {
			var value = counts && Object.prototype.hasOwnProperty.call( counts, key ) ? parseInt( counts[ key ], 10 ) || 0 : 0;
			return '<li>' + countLabel( key ) + ': ' + value + '</li>';
		} ).join( '' ) + '</ul>';
	}

	function escapeHtml( value ) {
		return String( value || '' ).replace( /[&<>"']/g, function ( character ) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			}[ character ];
		} );
	}

	function renderDetails( data ) {
		var html = '';
		[ [ 'errors', 'Errors' ], [ 'warnings', 'Warnings' ] ].forEach( function ( group ) {
			var messages = Array.isArray( data[ group[ 0 ] ] ) ? data[ group[ 0 ] ] : [];
			if ( ! messages.length ) {
				return;
			}
			html += '<p><strong>' + group[ 1 ] + '</strong></p><ul>';
			messages.forEach( function ( message ) {
				html += '<li>' + escapeHtml( message ) + '</li>';
			} );
			html += '</ul>';
		} );
		return html;
	}

	function progressLabel( data ) {
		var progress = data.progress || {};
		if ( data.phase === 'preflighting' && progress.zip_total ) {
			return 'Checked ' + progress.zip_index + ' of ' + progress.zip_total + ' ZIP members.';
		}
		if ( data.phase === 'extracting' && progress.extract_total ) {
			return 'Extracted ' + progress.extract_index + ' of ' + progress.extract_total + ' ZIP members.';
		}
		if ( data.phase === 'indexing_entries' ) {
			return 'Indexed JSON file ' + progress.json_file_index + ' of ' + progress.json_files_found + '; ' + progress.entries_total + ' entries queued.';
		}
		if ( data.phase === 'importing' && progress.entries_total ) {
			return 'Imported ' + progress.entry_index + ' of ' + progress.entries_total + ' entries. Current media: ' + progress.current_media_index + ' of ' + progress.current_media_total + '.';
		}
		return data.phase_label || data.message || '';
	}

	function updatePanel( data ) {
		var panel = document.getElementById( 'day-one-importer-job-panel' );
		if ( ! panel || ! data ) {
			return;
		}

		panel.classList.remove( 'notice-info', 'notice-success', 'notice-warning', 'notice-error' );
		if ( data.status === 'completed' ) {
			panel.classList.add( data.warnings && data.warnings.length ? 'notice-warning' : 'notice-success' );
		} else if ( data.status === 'failed' ) {
			panel.classList.add( 'notice-error' );
		} else if ( data.status === 'canceled' ) {
			panel.classList.add( 'notice-warning' );
		} else {
			panel.classList.add( 'notice-info' );
		}

		text( '#day-one-importer-job-panel .day-one-importer-job-message', data.message );
		text( '#day-one-importer-job-panel .day-one-importer-job-phase', data.phase_label );
		text( '#day-one-importer-job-panel .day-one-importer-job-progress', progressLabel( data ) );

		var counts = panel.querySelector( '.day-one-importer-job-counts' );
		if ( counts ) {
			counts.innerHTML = renderCounts( data.counts || {} );
		}
		var details = panel.querySelector( '.day-one-importer-job-details' );
		if ( details ) {
			details.innerHTML = renderDetails( data );
		}

		var retry = panel.querySelector( '.day-one-importer-job-retry' );
		if ( retry ) {
			retry.disabled = ! data.can_retry;
		}
		var cancel = panel.querySelector( '.day-one-importer-job-cancel' );
		if ( cancel ) {
			cancel.disabled = !! data.is_terminal;
		}
	}

	ready( function () {
		var form = document.getElementById( 'day-one-importer-form' );
		var submitButton = document.getElementById( 'day_one_importer_submit_button' );
		var statusRegion = document.getElementById( 'day-one-importer-status' );
		var statusMessage = statusRegion ? statusRegion.querySelector( '.day-one-importer-status-message' ) : null;
		var spinner = statusRegion ? statusRegion.querySelector( '.spinner' ) : null;
		var submitted = false;
		var config = window.DayOneImporterJobs || {};
		var labels = config.labels || {};
		var jobId = config.jobId || ( document.getElementById( 'day-one-importer-job-panel' ) || {} ).dataset && ( document.getElementById( 'day-one-importer-job-panel' ) || {} ).dataset.jobId;
		var stopped = false;

		if ( form ) {
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

				if ( statusMessage ) {
					statusMessage.textContent = statusRegion && statusRegion.dataset.startedMessage ? statusRegion.dataset.startedMessage : ( labels.uploading || 'Queuing import…' );
				}

				if ( spinner ) {
					spinner.classList.add( 'is-active' );
				}

				if ( submitButton ) {
					var runningLabel = statusRegion && statusRegion.dataset.runningLabel ? statusRegion.dataset.runningLabel : ( labels.uploading || '' );
					if ( runningLabel && 'value' in submitButton ) {
						submitButton.value = runningLabel;
					} else if ( runningLabel ) {
						submitButton.textContent = runningLabel;
					}
					submitButton.disabled = true;
					submitButton.setAttribute( 'aria-disabled', 'true' );
				}

				form.setAttribute( 'aria-busy', 'true' );
			} );
		}

		function schedule( delay ) {
			if ( stopped || ! jobId ) {
				return;
			}
			window.setTimeout( process, delay || 750 );
		}

		function handleData( data ) {
			updatePanel( data );
			if ( ! data || data.is_terminal || data.status === 'failed' ) {
				stopped = true;
				return;
			}
			schedule( data.busy ? 1500 : 300 );
		}

		function process() {
			if ( stopped || ! jobId ) {
				return;
			}
			postJobAction( 'day_one_importer_job_process', jobId ).then( handleData ).catch( function () {
				text( '#day-one-importer-job-panel .day-one-importer-job-message', labels.interrupted || 'Connection interrupted. You can safely continue this job.' );
				stopped = true;
			} );
		}

		if ( jobId ) {
			postJobAction( 'day_one_importer_job_status', jobId ).then( function ( data ) {
				updatePanel( data );
				if ( data && ! data.is_terminal && data.status !== 'failed' ) {
					schedule( 100 );
				}
			} ).catch( function () {} );
		}

		document.addEventListener( 'click', function ( event ) {
			var retry = event.target.closest ? event.target.closest( '.day-one-importer-job-retry' ) : null;
			var cancel = event.target.closest ? event.target.closest( '.day-one-importer-job-cancel' ) : null;
			if ( retry && jobId ) {
				event.preventDefault();
				stopped = false;
				postJobAction( 'day_one_importer_job_retry', jobId ).then( function ( data ) {
					updatePanel( data );
					schedule( 100 );
				} ).catch( function ( error ) {
					text( '#day-one-importer-job-panel .day-one-importer-job-message', error.message );
				} );
			}
			if ( cancel && jobId ) {
				event.preventDefault();
				stopped = true;
				postJobAction( 'day_one_importer_job_cancel', jobId ).then( updatePanel ).catch( function ( error ) {
					text( '#day-one-importer-job-panel .day-one-importer-job-message', error.message );
				} );
			}
		} );
	} );
}() );
