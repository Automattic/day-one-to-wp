<?php
/**
 * Shared helpers for Day One Importer.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
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

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal fallback for tests outside WordPress.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return strip_tags( (string) $text );
	}
}
