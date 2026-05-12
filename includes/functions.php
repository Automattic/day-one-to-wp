<?php
/**
 * Shared helpers for Day One Importer.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
		exit;
	}
}

/**
 * Return the importer text domain in one place.
 *
 * @return string
 */
function day_one_importer_text_domain() {
	return defined( 'DAY_ONE_IMPORTER_TEXT_DOMAIN' ) ? DAY_ONE_IMPORTER_TEXT_DOMAIN : 'day-one-importer';
}

/**
 * Sanitize a scalar string with a WordPress fallback for pure helper tests.
 *
 * @param mixed $value Value to sanitize.
 * @return string
 */
function day_one_importer_sanitize_text( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';

	if ( function_exists( 'sanitize_text_field' ) ) {
		return sanitize_text_field( $value );
	}

	$value = wp_strip_all_tags( $value );
	$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );

	return trim( (string) $value );
}

/**
 * Check the shared capabilities required to run Day One imports.
 *
 * @return bool
 */
function day_one_importer_current_user_can_import() {
	if ( ! function_exists( 'current_user_can' ) ) {
		return false;
	}

	return current_user_can( 'import' ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' );
}

/**
 * Ask WordPress/PHP for limits suitable for a long-running admin import.
 *
 * Hosts may enforce hard request limits outside PHP control, so this is best
 * effort only. The importer remains resumable when a host stops the request.
 *
 * @return void
 */
function day_one_importer_prepare_long_running_import() {
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Best-effort host-dependent request limit adjustment for long-running imports; no WP wrapper extends the request time budget.
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal fallback for tests outside WordPress.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) preg_replace( '/<[^>]*>/', '', (string) $text );
	}
}
