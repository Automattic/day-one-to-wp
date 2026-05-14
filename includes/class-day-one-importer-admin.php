<?php
/**
 * Admin importer screen.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Tools -> Import integration.
 */
class Day_One_Importer_Admin {
	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'day_one_importer_import';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_importer' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register WordPress importer.
	 *
	 * @return void
	 */
	public function register_importer() {
		if ( ! function_exists( 'register_importer' ) ) {
			require_once ABSPATH . 'wp-admin/includes/import.php';
		}

		if ( ! function_exists( 'register_importer' ) ) {
			return;
		}

		register_importer(
			'day-one',
			__( 'Day One', 'day-one-importer' ),
			__( 'Import Day One journal exports into private WordPress posts.', 'day-one-importer' ),
			array( $this, 'render_importer' )
		);
	}

	/**
	 * Enqueue importer screen assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check for asset scoping.
		$importer = isset( $_GET['import'] ) ? sanitize_key( wp_unslash( $_GET['import'] ) ) : '';
		if ( 'day-one' !== $importer || ! $this->current_user_can_import() ) {
			return;
		}

		wp_enqueue_script(
			'day-one-importer-admin-status',
			DAY_ONE_IMPORTER_URL . 'assets/admin-import-status.js',
			array(),
			DAY_ONE_IMPORTER_VERSION,
			true
		);

		$store = new Day_One_Importer_Job_Store();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only job selection for localized polling config; sanitize_job_id() is the custom sanitizer.
		$requested_job_id = isset( $_GET['day_one_importer_job'] ) ? Day_One_Importer_Job_Store::sanitize_job_id( wp_unslash( $_GET['day_one_importer_job'] ) ) : '';
		$job              = $store->get_user_job( get_current_user_id(), $requested_job_id );

		wp_localize_script(
			'day-one-importer-admin-status',
			'DayOneImporterJobs',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Day_One_Importer_Jobs_Controller::NONCE_ACTION ),
				'jobId'       => $job ? $job['id'] : '',
				'countLabels' => self::count_labels(),
				'labels'      => array(
					'uploading'        => __( 'Queuing import…', 'day-one-importer' ),
					'uploading_zip'    => __( 'Uploading ZIP…', 'day-one-importer' ),
					'queuing'          => __( 'Queuing import…', 'day-one-importer' ),
					'upload_failed'    => __( 'Upload failed. Please retry.', 'day-one-importer' ),
					'percent_format'   => __( '%d%% complete', 'day-one-importer' ),
					'processing'       => __( 'Processing import…', 'day-one-importer' ),
					'interrupted'      => __( 'Connection interrupted. You can safely continue this job.', 'day-one-importer' ),
					'continue'         => __( 'Retry / Continue', 'day-one-importer' ),
					'cancel'           => __( 'Cancel import', 'day-one-importer' ),
					'errors'           => __( 'Errors', 'day-one-importer' ),
					'warnings'         => __( 'Warnings', 'day-one-importer' ),
					'progress_pending' => __( 'Progress will update as the job runs.', 'day-one-importer' ),
				),
			)
		);
	}

	/**
	 * Render and dispatch importer screen.
	 *
	 * @return void
	 */
	public function render_importer() {
		if ( ! $this->current_user_can_import() ) {
			wp_die( esc_html__( 'You do not have permission to import Day One exports.', 'day-one-importer' ) );
		}

		$submission_results = null;
		$queued_job          = null;
		$request_method      = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified inside handle_submission() via check_admin_referer().
		if ( 'POST' === $request_method && isset( $_POST['day_one_importer_submit'] ) ) {
			$submission_results = $this->handle_submission();
			if ( is_array( $submission_results ) ) {
				$queued_job          = $submission_results;
				$submission_results = null;
			}
		}

		$store = new Day_One_Importer_Job_Store();
		$store->cleanup_stale_jobs();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import Day One', 'day-one-importer' ) . '</h1>';

		if ( $submission_results instanceof Day_One_Importer_Results ) {
			self::render_results( $submission_results );
		}

		$this->render_job_panel( $queued_job );
		$this->render_intro();
		$this->render_form();
		echo '</div>';
	}

	/**
	 * Handle form submission.
	 *
	 * @return Day_One_Importer_Results|array<string,mixed>|null Results on setup failure; queued job on success.
	 */
	private function handle_submission() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->current_user_can_import() ) {
			wp_die( esc_html__( 'You do not have permission to import Day One exports.', 'day-one-importer' ) );
		}

		$results = new Day_One_Importer_Results();
		$run_dir = Day_One_Importer_Cleanup::create_run_directory();
		if ( ! $run_dir ) {
			$results->add_error( __( 'A protected temporary directory could not be created.', 'day-one-importer' ) );
			return $results;
		}

		$file = isset( $_FILES['day_one_export'] ) ? $_FILES['day_one_export'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! class_exists( 'ZipArchive' ) ) {
			Day_One_Importer_Cleanup::remove( $run_dir );
			$runner = new Day_One_Importer_Runner();
			return $runner->run_upload( $file );
		}

		$uploader = new Day_One_Importer_Uploader();
		$zip_path = $uploader->handle_upload( $file, $run_dir, $results );
		if ( ! $zip_path ) {
			Day_One_Importer_Cleanup::remove( $run_dir );
			return $results;
		}

		$store = new Day_One_Importer_Job_Store();
		$job   = $store->create_job( get_current_user_id(), $run_dir, $zip_path, $results );
		if ( ! $job ) {
			Day_One_Importer_Cleanup::remove( $run_dir );
			$results->add_error( __( 'The import job could not be created.', 'day-one-importer' ) );
			return $results;
		}

		// Reload the importer screen at the new job's URL so the cached job pointer,
		// enqueued config, and rendered panel are not derived from a previous (canceled)
		// job that was still active when this request started.
		if ( ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'import'               => 'day-one',
						'day_one_importer_job' => $job['id'],
						'queued'               => 1,
					),
					admin_url( 'import.php' )
				)
			);
			exit;
		}

		return $job;
	}

	/**
	 * Render explanatory copy.
	 *
	 * @return void
	 */
	private function render_intro() {
		echo '<p>' . esc_html__( 'Upload a Day One export ZIP. The importer will create one private WordPress post per Day One entry and will attempt to import supported photos from the export.', 'day-one-importer' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Privacy note: imported posts and imported Day One media are protected and served only to authorized WordPress users, but review your hosting backups and filesystem access policies for private journals.', 'day-one-importer' ) . '</p></div>';
		echo '<p>' . esc_html__( 'For best results, export your journal from Day One as JSON in its original ZIP format and upload that ZIP without editing it.', 'day-one-importer' ) . '</p>';
		echo '<p>' . esc_html__( 'Large imports run as a resumable job advanced by short browser requests with a cron fallback, so refreshing the page or continuing after a network interruption is safe.', 'day-one-importer' ) . '</p>';

		if ( ! class_exists( 'ZipArchive' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The PHP ZipArchive extension is not available on this host, so the importer will fall back to a single-request synchronous import. Small and medium exports should still work, but very large or photo-heavy exports may exceed your server or proxy timeout. Ask your host to enable the PHP zip extension for resumable batched imports.', 'day-one-importer' ) . '</p></div>';
		}
	}

	/**
	 * Render the active/recent job panel.
	 *
	 * @param array<string,mixed>|null $job Recently queued job, if available.
	 * @return void
	 */
	private function render_job_panel( $job = null ) {
		$store = new Day_One_Importer_Job_Store();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only job selection for display; sanitize_job_id() is the custom sanitizer.
		$requested_job_id = isset( $_GET['day_one_importer_job'] ) ? Day_One_Importer_Job_Store::sanitize_job_id( wp_unslash( $_GET['day_one_importer_job'] ) ) : '';
		$job              = is_array( $job ) ? $job : $store->get_user_job( get_current_user_id(), $requested_job_id );
		if ( ! $job ) {
			// Hidden scaffold so the upload XHR has a panel to populate before the first job exists.
			echo '<div id="day-one-importer-job-panel" class="notice notice-info inline" data-job-id="" role="status" aria-live="polite" hidden>';
			echo '<p><strong>' . esc_html__( 'Current import job', 'day-one-importer' ) . '</strong></p>';
			echo '<p class="day-one-importer-job-message"></p>';
			echo '<p class="day-one-importer-job-phase" hidden></p>';
			echo '<p class="day-one-importer-job-progress"></p>';
			/* translators: %d: percentage complete. */
			echo '<p class="day-one-importer-job-progress-bar"><progress max="100" value="0"></progress> <span class="day-one-importer-job-progress-percent">' . esc_html( sprintf( __( '%d%% complete', 'day-one-importer' ), 0 ) ) . '</span></p>';
			echo '<div class="day-one-importer-job-counts"></div>';
			echo '<div class="day-one-importer-job-details"></div>';
			echo '<p class="day-one-importer-job-actions">';
			echo '<button type="button" class="button day-one-importer-job-retry" disabled>' . esc_html__( 'Retry / Continue', 'day-one-importer' ) . '</button> ';
			echo '<button type="button" class="button day-one-importer-job-cancel" disabled>' . esc_html__( 'Cancel import', 'day-one-importer' ) . '</button>';
			echo '</p>';
			echo '</div>';
			return;
		}

		$status       = Day_One_Importer_Job_State::status_response( $job );
		$notice_class = 'notice notice-info inline';
		if ( Day_One_Importer_Job_State::STATUS_COMPLETED === $status['status'] ) {
			$notice_class = 'notice notice-success inline';
		} elseif ( Day_One_Importer_Job_State::STATUS_FAILED === $status['status'] ) {
			$notice_class = 'notice notice-error inline';
		} elseif ( Day_One_Importer_Job_State::STATUS_CANCELED === $status['status'] ) {
			$notice_class = 'notice notice-error inline';
		}

		echo '<div id="day-one-importer-job-panel" class="' . esc_attr( $notice_class ) . '" data-job-id="' . esc_attr( $job['id'] ) . '" role="status" aria-live="polite">';
		echo '<p><strong>' . esc_html__( 'Current import job', 'day-one-importer' ) . '</strong></p>';
		echo '<p class="day-one-importer-job-message">' . esc_html( $status['message'] ) . '</p>';
		echo '<p class="day-one-importer-job-phase"' . ( '' === (string) $status['phase_label'] ? ' hidden' : '' ) . '>' . esc_html( $status['phase_label'] ) . '</p>';
		echo '<p class="day-one-importer-job-progress">' . esc_html( $this->format_progress_label( $status ) ) . '</p>';
		/* translators: %d: percentage complete. */
		echo '<p class="day-one-importer-job-progress-bar"><progress max="100" value="' . esc_attr( (int) $status['progress_percent'] ) . '"></progress> <span class="day-one-importer-job-progress-percent">' . esc_html( sprintf( __( '%d%% complete', 'day-one-importer' ), (int) $status['progress_percent'] ) ) . '</span></p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_counts_html() returns pre-escaped markup.
		echo '<div class="day-one-importer-job-counts">' . $this->render_counts_html( $status['counts'] ) . '</div>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_details_html() returns pre-escaped markup.
		echo '<div class="day-one-importer-job-details">' . $this->render_details_html( $status ) . '</div>';
		echo '<p class="day-one-importer-job-actions">';
		echo '<button type="button" class="button day-one-importer-job-retry"' . ( $status['can_retry'] ? '' : ' disabled' ) . '>' . esc_html__( 'Retry / Continue', 'day-one-importer' ) . '</button> ';
		echo '<button type="button" class="button day-one-importer-job-cancel"' . ( $status['is_terminal'] ? ' disabled' : '' ) . '>' . esc_html__( 'Cancel import', 'day-one-importer' ) . '</button>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Format a short progress label from a status payload.
	 *
	 * @param array<string,mixed> $status Status payload.
	 * @return string
	 */
	private function format_progress_label( $status ) {
		$progress = isset( $status['progress'] ) && is_array( $status['progress'] ) ? $status['progress'] : array();
		$phase    = isset( $status['phase'] ) ? (string) $status['phase'] : '';

		if ( 'preflighting' === $phase && ! empty( $progress['zip_total'] ) ) {
			return sprintf(
				/* translators: 1: current ZIP member, 2: total ZIP members. */
				__( 'Checked %1$d of %2$d ZIP members.', 'day-one-importer' ),
				(int) $progress['zip_index'],
				(int) $progress['zip_total']
			);
		}
		if ( 'extracting' === $phase && ! empty( $progress['extract_total'] ) ) {
			return sprintf(
				/* translators: 1: current ZIP member, 2: total ZIP members. */
				__( 'Extracted %1$d of %2$d ZIP members.', 'day-one-importer' ),
				(int) $progress['extract_index'],
				(int) $progress['extract_total']
			);
		}
		if ( 'indexing_entries' === $phase ) {
			return sprintf(
				/* translators: 1: current JSON file, 2: total JSON files, 3: entries indexed. */
				__( 'Indexed JSON file %1$d of %2$d; %3$d entries queued.', 'day-one-importer' ),
				(int) $progress['json_file_index'],
				(int) $progress['json_files_found'],
				(int) $progress['entries_total']
			);
		}
		if ( 'importing' === $phase && ! empty( $progress['entries_total'] ) ) {
			return sprintf(
				/* translators: 1: current entry, 2: total entries, 3: current media item, 4: total media items for current entry. */
				__( 'Imported %1$d of %2$d entries. Current media: %3$d of %4$d.', 'day-one-importer' ),
				(int) $progress['entry_index'],
				(int) $progress['entries_total'],
				(int) $progress['current_media_index'],
				(int) $progress['current_media_total']
			);
		}

		return __( 'Progress will update as the job runs.', 'day-one-importer' );
	}

	/**
	 * Render counts HTML for a status payload.
	 *
	 * @param array<string,int> $counts Counts.
	 * @return string
	 */
	private function render_counts_html( $counts ) {
		$html = '<ul>';
		foreach ( self::count_labels() as $key => $label ) {
			$html .= '<li>' . esc_html( $label ) . ': ' . esc_html( number_format_i18n( isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0 ) ) . '</li>';
		}
		$html .= '</ul>';

		return $html;
	}

	/**
	 * Render warning/error details HTML for a status payload.
	 *
	 * @param array<string,mixed> $status Status payload.
	 * @return string
	 */
	private function render_details_html( $status ) {
		$html = '';
		foreach ( array( 'errors' => __( 'Errors', 'day-one-importer' ), 'warnings' => __( 'Warnings', 'day-one-importer' ) ) as $key => $label ) {
			$messages = isset( $status[ $key ] ) && is_array( $status[ $key ] ) ? $status[ $key ] : array();
			if ( empty( $messages ) ) {
				continue;
			}

			$html .= '<p><strong>' . esc_html( $label ) . '</strong></p><ul class="day-one-importer-job-' . esc_attr( $key ) . '">';
			foreach ( $messages as $message ) {
				$html .= '<li>' . esc_html( $message ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Render upload form.
	 *
	 * @return void
	 */
	private function render_form() {
		?>
		<form method="post" enctype="multipart/form-data" id="day-one-importer-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="day_one_importer_submit" value="1" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="day-one-export"><?php esc_html_e( 'Day One export ZIP', 'day-one-importer' ); ?></label>
					</th>
					<td>
						<input type="file" id="day-one-export" name="day_one_export" accept=".zip,application/zip" required />
						<p class="description"><?php esc_html_e( 'Choose the ZIP file exported by Day One. JSON files and a photos folder will be detected inside the archive.', 'day-one-importer' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'After submitting, the ZIP is queued quickly and the browser advances the import in short requests. You can refresh and continue the same job safely.', 'day-one-importer' ); ?></p>
			<div id="day-one-importer-status" class="notice notice-info inline screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-running-label="<?php echo esc_attr__( 'Queuing…', 'day-one-importer' ); ?>" data-started-message="<?php echo esc_attr__( 'Upload submitted. The import job is being queued.', 'day-one-importer' ); ?>">
				<p>
					<span class="spinner" aria-hidden="true"></span>
					<span class="day-one-importer-status-message"></span>
				</p>
			</div>
			<?php submit_button( __( 'Import Day One export', 'day-one-importer' ), 'primary', 'day_one_importer_submit_button' ); ?>
		</form>
		<?php
	}

	/**
	 * Render results and warnings.
	 *
	 * @param Day_One_Importer_Results $result Results.
	 * @return void
	 */
	public static function render_results( Day_One_Importer_Results $result ) {
		$warnings = $result->get_warnings();

		if ( $result->has_errors() ) {
			$notice_class = 'notice notice-error';
			$headline     = __( 'Day One import did not complete.', 'day-one-importer' );
		} elseif ( ! empty( $warnings ) ) {
			$notice_class = 'notice notice-warning';
			$headline     = __( 'Day One import complete with warnings.', 'day-one-importer' );
		} else {
			$notice_class = 'notice notice-success';
			$headline     = __( 'Day One import complete.', 'day-one-importer' );
		}

		echo '<div class="' . esc_attr( $notice_class ) . ' inline"><p><strong>';
		echo esc_html( $headline );
		echo '</strong></p>';

		$counts = $result->get_counts();
		echo '<ul>';
		foreach ( self::count_labels() as $key => $label ) {
			echo '<li>' . esc_html( $label ) . ': ' . esc_html( number_format_i18n( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) ) . '</li>';
		}
		echo '</ul></div>';

		if ( $result->has_errors() ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Errors', 'day-one-importer' ) . '</strong></p><ul>';
			foreach ( $result->get_errors() as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( ! empty( $warnings ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Warnings', 'day-one-importer' ) . '</strong></p><ul>';
			foreach ( $warnings as $warning ) {
				echo '<li>' . esc_html( $warning ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * Count labels.
	 *
	 * @return array<string,string>
	 */
	public static function count_labels() {
		return array(
			'json_files_found'    => __( 'Journal JSON files found', 'day-one-importer' ),
			'entries_found'       => __( 'Entries found', 'day-one-importer' ),
			'posts_created'       => __( 'Posts created', 'day-one-importer' ),
			'posts_skipped'       => __( 'Existing complete posts skipped', 'day-one-importer' ),
			'posts_resumed'       => __( 'Incomplete posts resumed', 'day-one-importer' ),
			'entries_failed'      => __( 'Entries failed', 'day-one-importer' ),
			'tags_assigned'       => __( 'Entries with tags assigned', 'day-one-importer' ),
			'categories_assigned' => __( 'Entries with journal categories assigned', 'day-one-importer' ),
			'media_found'         => __( 'Media items found', 'day-one-importer' ),
			'media_imported'      => __( 'Media items imported', 'day-one-importer' ),
			'media_reused'        => __( 'Media items reused', 'day-one-importer' ),
			'media_missing'       => __( 'Media items missing', 'day-one-importer' ),
			'media_unsupported'   => __( 'Media items unsupported', 'day-one-importer' ),
			'media_failed'        => __( 'Media items failed', 'day-one-importer' ),
		);
	}

	/**
	 * Check required capabilities.
	 *
	 * @return bool
	 */
	private function current_user_can_import() {
		return day_one_importer_current_user_can_import();
	}
}
