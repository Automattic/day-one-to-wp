<?php
/**
 * Shared helpers for Day One Importer.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * Sanitize a scalar string.
 *
 * @param mixed $value Value to sanitize.
 * @return string
 */
function day_one_importer_sanitize_text( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';

	return sanitize_text_field( $value );
}

/**
 * Return a scalar request value after unslashing, or an empty string.
 *
 * @param mixed $value Raw request value.
 * @return string
 */
function day_one_importer_unslash_scalar( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}

	if ( function_exists( 'wp_unslash' ) ) {
		return (string) wp_unslash( $value );
	}

	return stripslashes( (string) $value );
}

/**
 * Sanitize a MIME type with a fallback for non-WordPress test contexts.
 *
 * @param mixed $value Raw MIME type.
 * @return string
 */
function day_one_importer_sanitize_mime_type( $value ) {
	$value = day_one_importer_unslash_scalar( $value );

	if ( function_exists( 'sanitize_mime_type' ) ) {
		return sanitize_mime_type( $value );
	}

	return (string) preg_replace( '/[^A-Za-z0-9.+_-\/]/', '', $value );
}

/**
 * Check the shared capabilities required to run Day One imports.
 *
 * @return bool
 */
function day_one_importer_current_user_can_import() {
	if ( ! function_exists( 'get_current_user_id' ) ) {
		return false;
	}

	return day_one_importer_user_can_import( get_current_user_id() );
}

/**
 * Check whether a specific user can run Day One imports.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function day_one_importer_user_can_import( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id || ! function_exists( 'user_can' ) ) {
		return false;
	}

	return user_can( $user_id, 'import' ) && user_can( $user_id, 'upload_files' ) && user_can( $user_id, 'edit_posts' );
}

/**
 * Ask WordPress for memory suitable for an admin import.
 *
 * Hosts may enforce hard request limits outside PHP control, so the importer
 * remains resumable when a host stops the request.
 *
 * @return void
 */
function day_one_importer_prepare_long_running_import() {
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}
}
