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

	function renderCounts( counts ) {
		var keys = [
			'json_files_found',
			'entries_found',
			'posts_created',
			'posts_skipped',
			'posts_resumed',
			'entries_failed',
			'tags_assigned',
			'categories_assigned',
			'media_found',
			'media_imported',
			'media_reused',
			'media_missing',
			'media_unsupported',
			'media_failed',
		];
		return '<ul>' + keys.map( function ( key ) {
			var value = counts && Object.prototype.hasOwnProperty.call( counts, key ) ? parseInt( counts[ key ], 10 ) || 0 : 0;
			return '<li>' + escapeHtml( countLabel( key ) ) + ': ' + value + '</li>';
		} ).join( '' ) + '</ul>';
	}

	function renderDetails( data ) {
		var config = window.DayOneImporterJobs || {};
		var labels = config.labels || {};
		var html = '';
		[ [ 'errors', labels.errors || 'Errors' ], [ 'warnings', labels.warnings || 'Warnings' ] ].forEach( function ( group ) {
			var messages = Array.isArray( data[ group[ 0 ] ] ) ? data[ group[ 0 ] ] : [];
			if ( ! messages.length ) {
				return;
			}
			html += '<p><strong>' + escapeHtml( group[ 1 ] ) + '</strong></p><ul>';
			messages.forEach( function ( message ) {
				html += '<li>' + escapeHtml( message ) + '</li>';
			} );
			html += '</ul>';
		} );
		return html;
	}

	function progressLabel( data ) {
		var config = window.DayOneImporterJobs || {};
		var labels = config.labels || {};
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
		return labels.progress_pending || 'Progress will update as the job runs.';
	}

	function updatePanel( data ) {
		var panel = document.getElementById( 'day-one-importer-job-panel' );
		if ( ! panel || ! data ) {
			return;
		}

		if ( data.job_id && panel.dataset ) {
			panel.dataset.jobId = data.job_id;
		}

		panel.classList.remove( 'notice-info', 'notice-success', 'notice-warning', 'notice-error' );
		if ( data.status === 'completed' ) {
			panel.classList.add( 'notice-success' );
		} else if ( data.status === 'failed' ) {
			panel.classList.add( 'notice-error' );
		} else if ( data.status === 'canceled' ) {
			panel.classList.add( 'notice-error' );
		} else {
			panel.classList.add( 'notice-info' );
		}

		text( '#day-one-importer-job-panel .day-one-importer-job-message', data.message );
		var phaseNode = panel.querySelector( '.day-one-importer-job-phase' );
		if ( phaseNode ) {
			var phaseLabel = data.phase_label || '';
			phaseNode.textContent = phaseLabel;
			phaseNode.hidden = '' === phaseLabel;
		}
		text( '#day-one-importer-job-panel .day-one-importer-job-progress', progressLabel( data ) );

		var percent = Math.max( 0, Math.min( 100, parseInt( data.progress_percent, 10 ) || 0 ) );
		var progressBar = panel.querySelector( '.day-one-importer-job-progress-bar progress' );
		if ( progressBar ) {
			progressBar.value = percent;
		}
		text( '#day-one-importer-job-panel .day-one-importer-job-progress-percent', percent + '% complete' );

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

	function resetPanelForUpload( message ) {
		var config = window.DayOneImporterJobs || {};
		var labels = config.labels || {};
		updatePanel( {
			status: 'queued',
			phase: 'uploaded',
			phase_label: '',
			message: message || labels.uploading || 'Queuing import…',
			progress_percent: 0,
			counts: {},
			warnings: [],
			errors: [],
			can_retry: false,
			is_terminal: true,
		} );
		// Suppress the generic "Progress will update as the job runs." sub-label so the panel only shows the upload percentage line.
		text( '#day-one-importer-job-panel .day-one-importer-job-progress', '' );
	}

	ready( function () {
		var form = document.getElementById( 'day-one-importer-form' );
		var submitButton = document.getElementById( 'day_one_importer_submit_button' );
		var submitted = false;
		var config = window.DayOneImporterJobs || {};
		var labels = config.labels || {};
		var panel = document.getElementById( 'day-one-importer-job-panel' );
		var panelJobId = panel && panel.dataset ? panel.dataset.jobId : '';
		var jobId = panelJobId || config.jobId || '';
		var stopped = false;

		if ( jobId && window.history && window.history.replaceState && window.URL ) {
			try {
				var currentUrl = new window.URL( window.location.href );
				if ( currentUrl.searchParams.get( 'day_one_importer_job' ) !== jobId ) {
					currentUrl.searchParams.set( 'day_one_importer_job', jobId );
					currentUrl.searchParams.set( 'queued', '1' );
					window.history.replaceState( null, '', currentUrl.toString() );
				}
			} catch ( error ) {}
		}

		function setPanelPercent( pct ) {
			var panelNode = document.getElementById( 'day-one-importer-job-panel' );
			if ( ! panelNode ) {
				return;
			}
			var clamped = Math.max( 0, Math.min( 100, parseInt( pct, 10 ) || 0 ) );
			var bar = panelNode.querySelector( '.day-one-importer-job-progress-bar progress' );
			if ( bar ) {
				bar.value = clamped;
			}
			var fmt = labels.percent_format || '%d%% complete';
			text( '#day-one-importer-job-panel .day-one-importer-job-progress-percent', fmt.replace( '%d', clamped ).replace( '%%', '%' ) );
		}

		function revealPanel() {
			var panelNode = document.getElementById( 'day-one-importer-job-panel' );
			if ( panelNode ) {
				panelNode.hidden = false;
				panelNode.removeAttribute( 'hidden' );
			}
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( form.checkValidity && ! form.checkValidity() ) {
					return;
				}

				if ( submitted ) {
					event.preventDefault();
					return;
				}

				event.preventDefault();
				submitted = true;
				stopped = true;

				if ( statusRegion ) {
					statusRegion.classList.remove( 'screen-reader-text' );
					statusRegion.removeAttribute( 'hidden' );
					statusRegion.setAttribute( 'aria-busy', 'true' );
				}

				var uploadingLabel = labels.uploading_zip || 'Uploading ZIP…';
				if ( statusMessage ) {
					statusMessage.textContent = uploadingLabel + ' 0%';
				}

				revealPanel();
				resetPanelForUpload( uploadingLabel + ' 0%' );
				setPanelPercent( 0 );

				if ( spinner ) {
					spinner.classList.add( 'is-active' );
				}

				if ( submitButton ) {
					var runningLabel = statusRegion && statusRegion.dataset.runningLabel ? statusRegion.dataset.runningLabel : uploadingLabel;
					if ( runningLabel && 'value' in submitButton ) {
						submitButton.value = runningLabel;
					} else if ( runningLabel ) {
						submitButton.textContent = runningLabel;
					}
					submitButton.disabled = true;
					submitButton.setAttribute( 'aria-disabled', 'true' );
				}

				form.setAttribute( 'aria-busy', 'true' );

				var xhr = new window.XMLHttpRequest();
				xhr.open( 'POST', form.getAttribute( 'action' ) || window.location.href, true );
				xhr.upload.addEventListener( 'progress', function ( ev ) {
					if ( ! ev.lengthComputable ) {
						return;
					}
					var pct = Math.round( ( ev.loaded / ev.total ) * 100 );
					setPanelPercent( pct );
					text( '#day-one-importer-job-panel .day-one-importer-job-message', uploadingLabel + ' ' + pct + '%' );
					if ( statusMessage ) {
						statusMessage.textContent = uploadingLabel + ' ' + pct + '%';
					}
				} );
				xhr.upload.addEventListener( 'load', function () {
					setPanelPercent( 100 );
					var queuingLabel = labels.queuing || labels.uploading || 'Queuing import…';
					text( '#day-one-importer-job-panel .day-one-importer-job-message', queuingLabel );
					if ( statusMessage ) {
						statusMessage.textContent = queuingLabel;
					}
				} );
				xhr.addEventListener( 'load', function () {
					if ( xhr.status >= 200 && xhr.status < 400 ) {
						window.location.href = xhr.responseURL || window.location.href;
						return;
					}
					var failed = labels.upload_failed || 'Upload failed.';
					text( '#day-one-importer-job-panel .day-one-importer-job-message', failed + ' (' + xhr.status + ')' );
				} );
				xhr.addEventListener( 'error', function () {
					text( '#day-one-importer-job-panel .day-one-importer-job-message', labels.upload_failed || 'Upload failed.' );
				} );
				xhr.send( new window.FormData( form ) );
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
