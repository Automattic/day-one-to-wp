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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import Day One', 'day-one-importer' ) . '</h1>';

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' === $request_method ) {
			check_admin_referer( self::NONCE_ACTION );
			if ( isset( $_POST['day_one_importer_submit'] ) ) {
				$this->handle_submission();
			}
		}

		$this->render_intro();
		$this->render_form();
		echo '</div>';
	}

	/**
	 * Handle form submission.
	 *
	 * @return void
	 */
	private function handle_submission() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->current_user_can_import() ) {
			wp_die( esc_html__( 'You do not have permission to import Day One exports.', 'day-one-importer' ) );
		}

		$file   = isset( $_FILES['day_one_export'] ) ? $_FILES['day_one_export'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$runner = new Day_One_Importer_Runner();
		$result = $runner->run_upload( $file );

		$this->render_results( $result );
	}

	/**
	 * Render explanatory copy.
	 *
	 * @return void
	 */
	private function render_intro() {
		echo '<p>' . esc_html__( 'Upload a Day One export ZIP. The importer will create one private WordPress post per Day One entry and will attempt to import supported photos from the export.', 'day-one-importer' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Privacy note: imported posts are private by default, but Media Library files may still be accessible by direct URL depending on your WordPress and hosting configuration.', 'day-one-importer' ) . '</p></div>';
		echo '<p>' . esc_html__( 'For best results, export your journal from Day One as JSON in its original ZIP format and upload that ZIP without editing it.', 'day-one-importer' ) . '</p>';
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
			<p class="description"><?php esc_html_e( 'After submitting, keep this tab open until the import summary appears.', 'day-one-importer' ); ?></p>
			<div id="day-one-importer-status" class="notice notice-info inline screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-running-label="<?php echo esc_attr__( 'Importing…', 'day-one-importer' ); ?>" data-started-message="<?php echo esc_attr__( 'Import started. This can take a while for large exports; keep this tab open.', 'day-one-importer' ); ?>">
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
	private function render_results( Day_One_Importer_Results $result ) {
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
		foreach ( $this->count_labels() as $key => $label ) {
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
	private function count_labels() {
		return array(
			'json_files_found' => __( 'Journal JSON files found', 'day-one-importer' ),
			'entries_found'    => __( 'Entries found', 'day-one-importer' ),
			'posts_created'    => __( 'Posts created', 'day-one-importer' ),
			'posts_skipped'    => __( 'Existing complete posts skipped', 'day-one-importer' ),
			'posts_resumed'    => __( 'Incomplete posts resumed', 'day-one-importer' ),
			'entries_failed'   => __( 'Entries failed', 'day-one-importer' ),
			'tags_assigned'    => __( 'Entries with tags assigned', 'day-one-importer' ),
			'media_found'      => __( 'Media items found', 'day-one-importer' ),
			'media_imported'   => __( 'Media items imported', 'day-one-importer' ),
			'media_reused'     => __( 'Media items reused', 'day-one-importer' ),
			'media_missing'    => __( 'Media items missing', 'day-one-importer' ),
			'media_unsupported'=> __( 'Media items unsupported', 'day-one-importer' ),
			'media_failed'     => __( 'Media items failed', 'day-one-importer' ),
		);
	}

	/**
	 * Check required capabilities.
	 *
	 * @return bool
	 */
	private function current_user_can_import() {
		return current_user_can( 'import' ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' );
	}
}
