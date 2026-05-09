<?php
/**
 * Content conversion helpers.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
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
				$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag ) : strtolower( $tag );
				$normalized[ $key ] = $tag;
			}
		}

		return array_values( $normalized );
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
			$encoded_attrs = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs, $flags ) : json_encode( $attrs, $flags );
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
	 * @return array{id:int,html:string}|null
	 */
	private static function build_attachment_image_record( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$image_html    = '';
		$image_class   = 'wp-image-' . $attachment_id;

		if ( function_exists( 'wp_get_attachment_image' ) ) {
			$image_html = wp_get_attachment_image( $attachment_id, 'large', false, array( 'class' => $image_class ) );
		}

		if ( $image_html ) {
			$image_html = self::ensure_image_html_class( $image_html, $image_class );
		} elseif ( function_exists( 'wp_get_attachment_url' ) ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				$url        = function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( (string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				$image_html = '<img class="' . $image_class . '" src="' . $url . '" alt="" />';
			}
		}

		if ( ! $image_html ) {
			return null;
		}

		if ( function_exists( 'wp_kses_post' ) ) {
			$image_html = wp_kses_post( $image_html );
		}

		return array(
			'id'   => $attachment_id,
			'html' => $image_html,
		);
	}

	/**
	 * Ensure an img tag contains the expected wp-image-{id} class.
	 *
	 * @param string $image_html Image markup.
	 * @param string $class Class name.
	 * @return string
	 */
	private static function ensure_image_html_class( $image_html, $class ) {
		$replaced = 0;
		$result   = preg_replace_callback(
			'/<img\b([^>]*)>/i',
			static function ( $matches ) use ( $class ) {
				$attrs = $matches[1];

				if ( preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $class_matches ) ) {
					$classes = preg_split( '/\s+/', trim( $class_matches[2] ) );
					$classes = is_array( $classes ) ? array_filter( $classes ) : array();
					if ( ! in_array( $class, $classes, true ) ) {
						$classes[] = $class;
					}
					$new_class_attr = ' class=' . $class_matches[1] . implode( ' ', $classes ) . $class_matches[1];
					$attrs          = preg_replace( '/\sclass\s*=\s*(["\']).*?\1/i', $new_class_attr, $attrs, 1 );

					return '<img' . $attrs . '>';
				}

				$self_closing = '';
				if ( preg_match( '/\s*\/\s*$/', $attrs ) ) {
					$attrs        = preg_replace( '/\s*\/\s*$/', '', $attrs );
					$self_closing = ' /';
				}

				return '<img' . rtrim( $attrs ) . ' class="' . $class . '"' . $self_closing . '>';
			},
			(string) $image_html,
			1,
			$replaced
		);

		return $replaced ? $result : (string) $image_html;
	}

	/**
	 * Serialize an Image block.
	 *
	 * @param array{id:int,html:string} $image Image record.
	 * @return string
	 */
	private static function serialize_image_block( $image ) {
		$attachment_id = (int) $image['id'];
		$attrs         = array(
			'id'              => $attachment_id,
			'sizeSlug'        => 'large',
			'linkDestination' => 'none',
		);
		$inner_html    = '<figure class="wp-block-image size-large">' . $image['html'] . '</figure>';

		return self::serialize_block( 'image', $attrs, $inner_html );
	}

	/**
	 * Serialize a Gallery block with nested Image blocks.
	 *
	 * @param array<int,array{id:int,html:string}> $images Image records.
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
