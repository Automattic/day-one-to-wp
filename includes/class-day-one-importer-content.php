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

		$html       = '';
		$paragraph  = array();
		$list_items = array();

		$flush_paragraph = static function () use ( &$html, &$paragraph ) {
			if ( empty( $paragraph ) ) {
				return;
			}

			$html     .= '<p>' . implode( "<br />\n", array_map( array( 'Day_One_Importer_Content', 'escape_imported_text_fragment' ), $paragraph ) ) . '</p>' . "\n";
			$paragraph = array();
		};

		$flush_list = static function () use ( &$html, &$list_items ) {
			if ( empty( $list_items ) ) {
				return;
			}

			$html .= '<ul>' . "\n";
			foreach ( $list_items as $item ) {
				$html .= '<li>' . self::escape_imported_text_fragment( $item ) . '</li>' . "\n";
			}
			$html      .= '</ul>' . "\n";
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
				$level = strlen( $matches[1] );
				$html .= '<h' . $level . '>' . self::escape_imported_text_fragment( $matches[2] ) . '</h' . $level . '>' . "\n";
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

		$html = trim( $html );
		if ( function_exists( 'wp_kses_post' ) ) {
			$html = wp_kses_post( $html );
		}

		return $html;
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

		$section  = "\n\n" . '<h2>' . esc_html__( 'Imported photos', 'day-one-importer' ) . '</h2>' . "\n";
		$section .= '<div class="day-one-importer-photos">' . "\n";

		foreach ( $attachment_ids as $attachment_id ) {
			$image = wp_get_attachment_image( $attachment_id, 'large' );
			if ( ! $image ) {
				$url = wp_get_attachment_url( $attachment_id );
				if ( ! $url ) {
					continue;
				}
				$image = '<img src="' . esc_url( $url ) . '" alt="" />';
			}

			$section .= '<figure class="wp-block-image day-one-importer-photo">' . $image . '</figure>' . "\n";
		}

		$section .= '</div>';

		return wp_kses_post( $content . $section );
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
