<?php
/**
 * Custom post type for imported Day One journal entries.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	// `return` rather than `exit` keeps direct web access harmless while
	// allowing tooling to require this file standalone, without WordPress.
	return;
}

/**
 * Owns everything related to the imported journal entry custom post type.
 *
 * Single source of truth for the post type identifier, its registration
 * arguments, the versioned rewrite-flush guard, the per-import post type
 * choice allowlist, and the list of post types an imported entry may carry.
 * Declaring the class executes no WordPress functions; sanitize_choice() and
 * entry_post_types() are pure PHP and run without WordPress loaded.
 */
class Day_One_Importer_Post_Type {

	/** Post type identifier for imported journal entries. */
	const POST_TYPE = 'day_one_entry';

	/**
	 * Current version of the post type's rewrite output.
	 *
	 * This counter is bumped manually, and only, when the registration
	 * arguments change in a way that alters rewrite output (slug, archive,
	 * permastruct), so maybe_flush_rewrite_rules() regenerates rules exactly
	 * once after such a change ships — never on every request.
	 */
	const REWRITE_VERSION = '1';

	/** Option name persisting the last rewrite version that was flushed. */
	const REWRITE_VERSION_OPTION = 'day_one_importer_rewrite_version';

	/**
	 * Register the journal entry custom post type.
	 *
	 * @return void
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'Journal Entries', 'day-one-importer' ),
			'singular_name'      => __( 'Journal Entry', 'day-one-importer' ),
			'menu_name'          => __( 'Journal Entries', 'day-one-importer' ),
			'add_new'            => __( 'Add New', 'day-one-importer' ),
			'add_new_item'       => __( 'Add New Journal Entry', 'day-one-importer' ),
			'edit_item'          => __( 'Edit Journal Entry', 'day-one-importer' ),
			'new_item'           => __( 'New Journal Entry', 'day-one-importer' ),
			'view_item'          => __( 'View Journal Entry', 'day-one-importer' ),
			'view_items'         => __( 'View Journal Entries', 'day-one-importer' ),
			'search_items'       => __( 'Search Journal Entries', 'day-one-importer' ),
			'not_found'          => __( 'No journal entries found.', 'day-one-importer' ),
			'not_found_in_trash' => __( 'No journal entries found in Trash.', 'day-one-importer' ),
			'all_items'          => __( 'All Journal Entries', 'day-one-importer' ),
			'item_published'     => __( 'Journal entry published.', 'day-one-importer' ),
			'item_updated'       => __( 'Journal entry updated.', 'day-one-importer' ),
		);

		// Privacy is enforced by `post_status => private` on every imported
		// entry — exactly as it is for regular posts today — never by
		// registration flags. `public => true` gives the type the same admin
		// UI and authorized front-end queryability that `post` has.
		$args = array(
			'labels'          => $labels,
			'description'     => __( 'Journal entries imported from Day One.', 'day-one-importer' ),
			'public'          => true,
			'has_archive'     => false,
			'show_in_rest'    => true,
			'supports'        => array( 'title', 'editor', 'author', 'revisions' ),
			'taxonomies'      => array( 'category', 'post_tag' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'rewrite'         => array(
				'slug'       => 'day-one-entry',
				'with_front' => false,
			),
			'menu_icon'       => 'dashicons-book-alt',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Flush rewrite rules once when the registered rewrite output changes.
	 *
	 * Short-circuits while the persisted option already matches
	 * REWRITE_VERSION, so rules are flushed only after activation cleared the
	 * option or after a release bumped the version — never on every request.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( self::REWRITE_VERSION === get_option( self::REWRITE_VERSION_OPTION ) ) {
			return;
		}
		flush_rewrite_rules();
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Sanitize a per-import post type choice against the allowlist.
	 *
	 * Pure string comparison with no WordPress calls. Only the two allowed
	 * choices pass through unchanged; anything else — missing/null values,
	 * empty strings, non-scalars, free-form post type names, wrong casing —
	 * falls back to the default.
	 *
	 * @param mixed $value Raw choice value (form input, job record, caller argument).
	 * @return string Either 'post' or POST_TYPE; 'post' for any other input.
	 */
	public static function sanitize_choice( $value ) {
		if ( 'post' === $value || self::POST_TYPE === $value ) {
			return $value;
		}
		return 'post';
	}

	/**
	 * List every post type an imported journal entry may carry.
	 *
	 * Pure PHP with no WordPress calls. The order is fixed and part of the
	 * contract: existing-entry lookups query exactly these types.
	 *
	 * @return string[] Exactly array( 'post', POST_TYPE ), in that order.
	 */
	public static function entry_post_types() {
		return array( 'post', self::POST_TYPE );
	}
}
