<?php
/**
 * Content conversion helpers.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Day One text to safe WordPress content and titles.
 */
class Day_One_Importer_Content {
	/**
	 * Convert Day One Markdown-like text to safe HTML.
	 *
	 * Raw imported HTML is escaped. Shortcode-looking text is neutralized so it
	 * remains visible text and cannot execute on render.
	 *
	 * @param mixed $text Raw Day One text.
	 * @return string
	 */
	public static function convert_text_to_content( $text ) {
		$text  = is_scalar( $text ) ? (string) $text : '';
		$text  = self::normalize_day_one_markdown_escapes( self::normalize_line_endings( $text ) );
		$lines = explode( "\n", $text );

		$content    = '';
		$paragraph  = array();
		$list_items = array();

		$flush_paragraph = static function () use ( &$content, &$paragraph ) {
			if ( empty( $paragraph ) ) {
				return;
			}

			$content  .= self::serialize_paragraph_block( $paragraph );
			$paragraph = array();
		};

		$flush_list = static function () use ( &$content, &$list_items ) {
			if ( empty( $list_items ) ) {
				return;
			}

			$content   .= self::serialize_list_block( $list_items );
			$list_items = array();
		};

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( self::is_day_one_media_placeholder( $trimmed ) ) {
				$flush_paragraph();
				$flush_list();
				continue;
			}

			if ( '' === $trimmed ) {
				$flush_paragraph();
				$flush_list();
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trimmed, $matches ) ) {
				$flush_paragraph();
				$flush_list();
				$content .= self::serialize_heading_block( strlen( $matches[1] ), $matches[2] );
				continue;
			}

			if ( preg_match( '/^[-*+]\s+(.+)$/', $trimmed, $matches ) ) {
				$flush_paragraph();
				$list_items[] = $matches[1];
				continue;
			}

			$flush_list();
			$paragraph[] = $line;
		}

		$flush_paragraph();
		$flush_list();

		return trim( $content );
	}

	/**
	 * Convert a Day One richText payload to safe WordPress block content.
	 *
	 * Walks the decoded `contents[]` array in order. Each non-empty run is
	 * rendered as one paragraph block; runs that carry supported inline
	 * attributes own paragraph serialization here (R10 option (b)) so that
	 * inline wrapper tags survive escaping. Runs without supported inline
	 * attributes continue to delegate to convert_text_to_content() so the
	 * legacy markdown path stays byte-for-byte identical and any sigil
	 * leakage is preserved exactly as the scaffold renders it today.
	 *
	 * Empty-text items are dropped regardless of their attributes — no empty
	 * `<strong></strong>` or `<a href="…"></a>` may appear in output.
	 *
	 * Wrapper order (innermost to outermost, pinned for deterministic tests
	 * and documented in spec R11):
	 *   1. `<code>`        (inlineCode)
	 *   2. `<s>`           (strikethrough)
	 *   3. `<em>`          (italic)
	 *   4. `<strong>`      (bold)
	 *   5. `<mark style="background-color:#RRGGBB">` (highlightedColor)
	 *   6. `<a href="…">`  (linkURL / autolink)
	 *
	 * Escape contract: the run's text is escaped via
	 * escape_imported_text_fragment() BEFORE any inline wrapper is applied,
	 * so the inline tags emitted here are the only HTML in the rendered
	 * fragment. serialize_paragraph_block() is deliberately bypassed for
	 * the owning path so the wrapper tags are not re-escaped into entities.
	 *
	 * @param mixed                         $rich_text Decoded richText array or JSON-encoded string.
	 * @param Day_One_Importer_Results|null $results   Optional warning sink for inline-attribute rejection paths (R7, R9).
	 * @return string
	 */
	public static function convert_rich_text_to_content( $rich_text, ?Day_One_Importer_Results $results = null ) {
		if ( is_string( $rich_text ) ) {
			$decoded   = json_decode( $rich_text, true );
			$rich_text = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $rich_text ) ) {
			return '';
		}

		$contents = isset( $rich_text['contents'] ) && is_array( $rich_text['contents'] ) ? $rich_text['contents'] : array();
		if ( empty( $contents ) ) {
			return '';
		}

		$output = '';
		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$text = isset( $item['text'] ) && is_scalar( $item['text'] ) ? (string) $item['text'] : '';
			if ( '' === trim( $text ) ) {
				continue;
			}

			if ( "\n" === substr( $text, -1 ) ) {
				$text = substr( $text, 0, -1 );
			}

			$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();

			// Strict boolean attributes (R12 — only literal true triggers).
			$is_bold          = isset( $attributes['bold'] ) && true === $attributes['bold'];
			$is_italic        = isset( $attributes['italic'] ) && true === $attributes['italic'];
			$is_strikethrough = isset( $attributes['strikethrough'] ) && true === $attributes['strikethrough'];
			$is_code          = isset( $attributes['inlineCode'] ) && true === $attributes['inlineCode'];

			// linkURL validation (R5 / R7). autolink is observed identically when present (R6).
			$valid_href = '';
			if ( isset( $attributes['linkURL'] ) ) {
				$link_raw = $attributes['linkURL'];
				if ( is_string( $link_raw ) && '' !== $link_raw && preg_match( '#^https?://#i', $link_raw ) ) {
					$href_candidate = esc_url( $link_raw );
					if ( '' !== $href_candidate ) {
						$valid_href = $href_candidate;
					}
				}
				if ( '' === $valid_href && null !== $results ) {
					$results->add_warning( __( 'Skipped non-http(s) link inside richText payload.', 'day-one-importer' ) );
				}
			}

			// highlightedColor validation (R8 / R9). Warn on every present-but-invalid value;
			// silent only when the key is absent.
			$highlight_color_hex = '';
			if ( array_key_exists( 'highlightedColor', $attributes ) ) {
				$color_raw = $attributes['highlightedColor'];
				if ( is_string( $color_raw ) && '' !== $color_raw && 1 === preg_match( '/^0x([0-9A-Fa-f]{6})$/', $color_raw, $color_match ) ) {
					$highlight_color_hex = '#' . $color_match[1];
				} elseif ( null !== $results ) {
					$results->add_warning( __( 'Skipped invalid highlight color inside richText payload.', 'day-one-importer' ) );
				}
			}

			$has_supported = $is_bold || $is_italic || $is_strikethrough || $is_code || '' !== $valid_href || '' !== $highlight_color_hex;

			if ( ! $has_supported ) {
				// R13 delegation — byte-for-byte legacy path.
				$output .= self::convert_text_to_content( $text );
				continue;
			}

			// R10 option (b) — own paragraph serialization for runs with inline attributes.
			$lines        = explode( "\n", $text );
			$escaped_line = array_map( array( 'Day_One_Importer_Content', 'escape_imported_text_fragment' ), $lines );
			$wrapped      = implode( "<br />\n", $escaped_line );

			// R11 wrapper order — innermost to outermost. Plain `if` chain (not a generic loop)
			// so the order pin is visually obvious in the diff.
			if ( $is_code ) {
				$wrapped = '<code>' . $wrapped . '</code>';
			}
			if ( $is_strikethrough ) {
				$wrapped = '<s>' . $wrapped . '</s>';
			}
			if ( $is_italic ) {
				$wrapped = '<em>' . $wrapped . '</em>';
			}
			if ( $is_bold ) {
				$wrapped = '<strong>' . $wrapped . '</strong>';
			}
			if ( '' !== $highlight_color_hex ) {
				$wrapped = '<mark style="background-color:' . $highlight_color_hex . '">' . $wrapped . '</mark>';
			}
			if ( '' !== $valid_href ) {
				$wrapped = '<a href="' . $valid_href . '">' . $wrapped . '</a>';
			}

			$output .= self::serialize_block( 'paragraph', array(), '<p>' . $wrapped . '</p>' );
		}

		return trim( $output );
	}

	/**
	 * Render the body of a normalized entry using the appropriate path.
	 *
	 * Dispatch helper called by both runner invocation sites. When the
	 * entry carries a usable richText payload, route through the richText
	 * renderer; otherwise fall back to the legacy markdown path so that
	 * legacy entries produce byte-identical content.
	 *
	 * @param mixed                         $entry   Normalized entry array.
	 * @param Day_One_Importer_Results|null $results Optional warning sink threaded into the richText renderer.
	 * @return string
	 */
	public static function render_entry_body( $entry, ?Day_One_Importer_Results $results = null ) {
		if ( is_array( $entry ) && isset( $entry['richText'] ) && is_array( $entry['richText'] ) ) {
			return self::convert_rich_text_to_content( $entry['richText'], $results );
		}

		$text = ( is_array( $entry ) && isset( $entry['text'] ) ) ? $entry['text'] : '';
		return self::convert_text_to_content( $text );
	}

	/**
	 * Derive a title from a normalized entry, with richText fallback.
	 *
	 * When `text` is non-empty, the legacy derive_title() behavior is
	 * preserved unchanged. When `text` is empty/missing but richText is
	 * present, the first non-empty plain run inside richText.contents[]
	 * is used to feed the same derive_title() logic. Otherwise the
	 * existing date-fallback title is used.
	 *
	 * @param mixed  $entry    Normalized entry array.
	 * @param string $date_gmt Date in GMT format.
	 * @return string
	 */
	public static function derive_title_from_entry( $entry, $date_gmt = '' ) {
		$text = ( is_array( $entry ) && isset( $entry['text'] ) && is_scalar( $entry['text'] ) ) ? (string) $entry['text'] : '';
		if ( '' !== trim( $text ) ) {
			return self::derive_title( $text, $date_gmt );
		}

		if ( is_array( $entry ) && isset( $entry['richText']['contents'] ) && is_array( $entry['richText']['contents'] ) ) {
			foreach ( $entry['richText']['contents'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$run = isset( $item['text'] ) && is_scalar( $item['text'] ) ? (string) $item['text'] : '';
				if ( '' !== trim( $run ) ) {
					return self::derive_title( $run, $date_gmt );
				}
			}
		}

		return self::derive_title( '', $date_gmt );
	}

	/**
	 * Derive a safe title from text and date.
	 *
	 * @param mixed  $text Raw text.
	 * @param string $date_gmt Date in GMT format.
	 * @return string
	 */
	public static function derive_title( $text, $date_gmt = '' ) {
		$text  = is_scalar( $text ) ? (string) $text : '';
		$lines = explode( "\n", self::normalize_day_one_markdown_escapes( self::normalize_line_endings( $text ) ) );

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( self::is_day_one_media_placeholder( $trimmed ) ) {
				continue;
			}
			if ( preg_match( '/^#{1,6}\s+(.+)$/', $trimmed, $matches ) ) {
				return self::trim_title( $matches[1] );
			}
		}

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( self::is_day_one_media_placeholder( $trimmed ) ) {
				continue;
			}
			if ( '' !== $trimmed ) {
				return self::trim_title( preg_replace( '/^[-*+]\s+/', '', $trimmed ) );
			}
		}

		$date_label = $date_gmt;
		if ( $date_gmt && function_exists( 'mysql2date' ) ) {
			$date_label = mysql2date( get_option( 'date_format' ), $date_gmt );
		} elseif ( $date_gmt ) {
			$date_label = gmdate( 'Y-m-d', strtotime( $date_gmt ) );
		} else {
			$date_label = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}

		/* translators: %s: entry date. */
		$format = function_exists( '__' ) ? __( 'Day One entry — %s', 'day-one-importer' ) : 'Day One entry — %s';
		return sprintf( $format, $date_label );
	}

	/**
	 * Normalize Day One tags for wp_set_post_tags().
	 *
	 * @param mixed $tags Raw tags.
	 * @return string[]
	 */
	public static function normalize_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $tags as $tag ) {
			if ( ! is_scalar( $tag ) ) {
				continue;
			}

			$tag = day_one_importer_sanitize_text( $tag );
			if ( '' !== $tag ) {
				$key                = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag ) : strtolower( $tag );
				$normalized[ $key ] = $tag;
			}
		}

		return array_values( $normalized );
	}

	/**
	 * Normalize a Day One journal name for category assignment.
	 *
	 * @param mixed $journal Raw journal name.
	 * @return string
	 */
	public static function normalize_journal_name( $journal ) {
		if ( ! is_scalar( $journal ) ) {
			return '';
		}

		$journal = day_one_importer_sanitize_text( $journal );
		return '' === $journal ? '' : $journal;
	}

	/**
	 * Derive a journal name from a Day One entry or source JSON filename.
	 *
	 * @param array<string,mixed> $raw_entry Raw entry.
	 * @param string              $source_file Source JSON file.
	 * @return string
	 */
	public static function derive_journal_name( $raw_entry, $source_file ) {
		if ( isset( $raw_entry['journalName'] ) ) {
			$journal = self::normalize_journal_name( $raw_entry['journalName'] );
			if ( '' !== $journal ) {
				return $journal;
			}
		}

		if ( isset( $raw_entry['journal'] ) ) {
			if ( is_array( $raw_entry['journal'] ) && isset( $raw_entry['journal']['name'] ) ) {
				$journal = self::normalize_journal_name( $raw_entry['journal']['name'] );
			} else {
				$journal = self::normalize_journal_name( $raw_entry['journal'] );
			}
			if ( '' !== $journal ) {
				return $journal;
			}
		}

		$basename = basename( (string) $source_file );
		$name     = preg_replace( '/\.json$/i', '', $basename );
		return self::normalize_journal_name( $name );
	}

	/**
	 * Append an ordered imported image section.
	 *
	 * @param string $content Base content.
	 * @param int[]  $attachment_ids Attachments.
	 * @return string
	 */
	public static function append_image_section( $content, $attachment_ids ) {
		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
		if ( empty( $attachment_ids ) ) {
			return $content;
		}

		$images = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$image = self::build_attachment_image_record( $attachment_id );
			if ( $image ) {
				$images[] = $image;
			}
		}

		if ( empty( $images ) ) {
			return $content;
		}

		$section = 1 === count( $images ) ? self::serialize_image_block( $images[0] ) : self::serialize_gallery_block( $images );
		$prefix  = '' !== (string) $content ? rtrim( (string) $content ) . "\n\n" : '';

		return $prefix . trim( $section );
	}

	/**
	 * Serialize a WordPress block with escaped/sanitized inner markup.
	 *
	 * @param string $block_name Block name without core/ prefix.
	 * @param array  $attrs Block attributes.
	 * @param string $inner_html Inner HTML.
	 * @return string
	 */
	private static function serialize_block( $block_name, $attrs, $inner_html ) {
		$attrs = is_array( $attrs ) ? $attrs : array();
		$flags = 0;
		if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		if ( defined( 'JSON_UNESCAPED_UNICODE' ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}

		$encoded_attrs = '';
		if ( ! empty( $attrs ) ) {
			$encoded_attrs = wp_json_encode( $attrs, $flags );
			$encoded_attrs = is_string( $encoded_attrs ) ? ' ' . $encoded_attrs : '';
		}

		return '<!-- wp:' . $block_name . $encoded_attrs . ' -->' . "\n" . $inner_html . "\n" . '<!-- /wp:' . $block_name . ' -->' . "\n";
	}

	/**
	 * Serialize paragraph lines as a Paragraph block.
	 *
	 * @param string[] $lines Paragraph lines.
	 * @return string
	 */
	private static function serialize_paragraph_block( $lines ) {
		$escaped_lines = array_map( array( 'Day_One_Importer_Content', 'escape_imported_text_fragment' ), (array) $lines );
		return self::serialize_block( 'paragraph', array(), '<p>' . implode( "<br />\n", $escaped_lines ) . '</p>' );
	}

	/**
	 * Serialize text as a Heading block.
	 *
	 * @param int    $level Heading level.
	 * @param string $text Heading text.
	 * @return string
	 */
	private static function serialize_heading_block( $level, $text ) {
		$level = max( 1, min( 6, (int) $level ) );
		$attrs = 2 === $level ? array() : array( 'level' => $level );

		return self::serialize_block( 'heading', $attrs, '<h' . $level . '>' . self::escape_imported_text_fragment( $text ) . '</h' . $level . '>' );
	}

	/**
	 * Serialize items as a List block.
	 *
	 * @param string[] $items List item text.
	 * @return string
	 */
	private static function serialize_list_block( $items ) {
		$inner_html = '<ul>' . "\n";
		foreach ( (array) $items as $item ) {
			$inner_html .= '<li>' . self::escape_imported_text_fragment( $item ) . '</li>' . "\n";
		}
		$inner_html .= '</ul>';

		return self::serialize_block( 'list', array(), $inner_html );
	}

	/**
	 * Build an image record for block serialization.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{id:int,url:string,alt:string,class:string}|null
	 */
	private static function build_attachment_image_record( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$image_class   = 'wp-image-' . $attachment_id;
		$url           = '';
		$alt           = '';

		if ( function_exists( 'wp_get_attachment_image_src' ) ) {
			$image_src = wp_get_attachment_image_src( $attachment_id, 'large' );
			if ( is_array( $image_src ) && ! empty( $image_src[0] ) ) {
				$url = (string) $image_src[0];
			}
		}

		if ( '' === $url && function_exists( 'wp_get_attachment_image' ) ) {
			$image_html = wp_get_attachment_image( $attachment_id, 'large', false, array( 'class' => $image_class ) );
			$url        = self::extract_img_src( $image_html );
		}

		if ( '' === $url && function_exists( 'wp_get_attachment_url' ) ) {
			$url = wp_get_attachment_url( $attachment_id );
			$url = $url ? (string) $url : '';
		}

		if ( '' === $url ) {
			return null;
		}

		if ( function_exists( 'get_post_meta' ) ) {
			$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			$alt = is_scalar( $alt ) ? (string) $alt : '';
		}

		return array(
			'id'    => $attachment_id,
			'url'   => (string) $url,
			'alt'   => (string) $alt,
			'class' => $image_class,
		);
	}

	/**
	 * Extract only the src attribute from an img tag.
	 *
	 * @param string $image_html Image markup.
	 * @return string
	 */
	private static function extract_img_src( $image_html ) {
		if ( preg_match( '/<img\b[^>]*\ssrc\s*=\s*(["\'])(.*?)\1/i', (string) $image_html, $matches ) ) {
			return html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );
		}

		return '';
	}

	/**
	 * Serialize validation-compatible img markup for a block save body.
	 *
	 * @param array{id:int,url:string,alt:string,class:string} $image Image record.
	 * @return string
	 */
	private static function serialize_image_tag( $image ) {
		$src   = function_exists( 'esc_url' ) ? esc_url( $image['url'] ) : htmlspecialchars( (string) $image['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$alt   = function_exists( 'esc_attr' ) ? esc_attr( $image['alt'] ) : htmlspecialchars( (string) $image['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$class = function_exists( 'esc_attr' ) ? esc_attr( $image['class'] ) : htmlspecialchars( (string) $image['class'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		return '<img src="' . $src . '" alt="' . $alt . '" class="' . $class . '" />';
	}

	/**
	 * Serialize an Image block.
	 *
	 * @param array{id:int,url:string,alt:string,class:string} $image Image record.
	 * @return string
	 */
	private static function serialize_image_block( $image ) {
		$attachment_id = (int) $image['id'];
		$attrs         = array(
			'id'              => $attachment_id,
			'sizeSlug'        => 'large',
			'linkDestination' => 'none',
		);
		$inner_html    = '<figure class="wp-block-image size-large">' . self::serialize_image_tag( $image ) . '</figure>';

		return self::serialize_block( 'image', $attrs, $inner_html );
	}

	/**
	 * Serialize a Gallery block with nested Image blocks.
	 *
	 * @param array<int,array{id:int,url:string,alt:string,class:string}> $images Image records.
	 * @return string
	 */
	private static function serialize_gallery_block( $images ) {
		$ids = array();
		foreach ( $images as $image ) {
			$ids[] = (int) $image['id'];
		}

		$inner_html = '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . "\n";
		foreach ( $images as $image ) {
			$inner_html .= self::serialize_image_block( $image );
		}
		$inner_html .= '</figure>';

		return self::serialize_block(
			'gallery',
			array(
				'linkTo' => 'none',
				'ids'    => $ids,
			),
			$inner_html
		);
	}

	/**
	 * Parse an ISO date into WordPress post date fields.
	 *
	 * @param mixed $date_string Date string.
	 * @return array{valid:bool,gmt:string,local:string}
	 */
	public static function parse_day_one_date( $date_string ) {
		if ( ! is_scalar( $date_string ) || '' === trim( (string) $date_string ) ) {
			return array(
				'valid' => false,
				'gmt'   => '',
				'local' => '',
			);
		}

		try {
			$date = new DateTimeImmutable( (string) $date_string );
		} catch ( Exception $e ) {
			return array(
				'valid' => false,
				'gmt'   => '',
				'local' => '',
			);
		}

		$gmt = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		if ( function_exists( 'get_date_from_gmt' ) ) {
			$local = get_date_from_gmt( $gmt );
		} else {
			$local = $gmt;
		}

		return array(
			'valid' => true,
			'gmt'   => $gmt,
			'local' => $local,
		);
	}

	/**
	 * Escape an imported text fragment and neutralize shortcode brackets.
	 *
	 * @param string $text Fragment.
	 * @return string
	 */
	public static function escape_imported_text_fragment( $text ) {
		$text = htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$text = str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $text );

		return $text;
	}

	/**
	 * Normalize Markdown escape backslashes emitted by Day One plain-text export.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize_day_one_markdown_escapes( $text ) {
		return preg_replace( '/\\\\([\\\\`*_{}\[\]()#+\-.!>])/', '$1', (string) $text );
	}

	/**
	 * Detect Day One media placeholder Markdown links.
	 *
	 * Day One can include photo placeholders such as
	 * `![](dayone-moment://UUID)` in the text field while the actual media is
	 * represented separately in the `photos` array. The importer appends imported
	 * photos from that structured metadata, so these placeholders should not be
	 * used as visible content or post titles.
	 *
	 * @param string $line Trimmed line.
	 * @return bool
	 */
	public static function is_day_one_media_placeholder( $line ) {
		return (bool) preg_match( '/^!\[[^\]]*\]\(dayone-(?:moment|photo|video):\/\/[^\s)]+\)$/i', trim( (string) $line ) );
	}

	/**
	 * Normalize line endings.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_line_endings( $text ) {
		return str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
	}

	/**
	 * Sanitize and trim title length.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function trim_title( $title ) {
		$title = day_one_importer_sanitize_text( $title );
		$title = preg_replace( '/\s+/u', ' ', $title );
		$title = trim( (string) $title );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $title ) > 60 ) {
			$title = rtrim( mb_substr( $title, 0, 57 ) ) . '…';
		} elseif ( strlen( $title ) > 60 ) {
			$title = rtrim( substr( $title, 0, 57 ) ) . '...';
		}

		return $title ? $title : ( function_exists( '__' ) ? __( 'Day One entry', 'day-one-importer' ) : 'Day One entry' );
	}
}
