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
	 * @param array<string,int>             $photo_map identifier → attachment_id map (#56 R12); forwarded to the media emitter.
	 * @param array<string,int>             $video_map identifier → attachment_id map for videos (#57 R7.6).
	 * @param array<string,int>             $audio_map identifier → attachment_id map for audios (#58 R7.6).
	 * @return string
	 */
	public static function convert_rich_text_to_content( $rich_text, ?Day_One_Importer_Results $results = null, array $photo_map = array(), array $video_map = array(), array $audio_map = array() ) {
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

		// Single forward pass over contents[]. Items that fail the empty-text drop
		// (R1.3) are transparent — they do NOT close the open run (R2.1). Runs are
		// flushed only when the next emitting item resolves to a different kind.
		$run    = null; // Closed run: kind=>string, items=>array.
		$output = '';
		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( self::is_line_item_dropped( $item ) ) {
				continue;
			}

			$kind = self::classify_line_item( $item );
			if ( null !== $run && $run['kind'] === $kind ) {
				$run['items'][] = $item;
				continue;
			}
			if ( null !== $run ) {
				$output .= self::flush_run( $run, $photo_map, $video_map, $audio_map, $results );
			}
			$run = array(
				'kind'  => $kind,
				'items' => array( $item ),
			);
		}
		if ( null !== $run ) {
			$output .= self::flush_run( $run, $photo_map, $video_map, $audio_map, $results );
		}

		return trim( $output );
	}

	/**
	 * Determine whether a richText content item should be dropped (R1.3 / #56 R5.1).
	 *
	 * An item is dropped iff BOTH conditions hold: (a) `text` is missing/non-scalar
	 * or trim-empty, AND (b) `embeddedObjects` is absent or an empty array. Items
	 * with empty `text` but a non-empty `embeddedObjects` array survive the drop
	 * check so the dispatcher can route them to the new `media` kind (#56 R6).
	 * Dropped items remain transparent to run-collapsing (R2.1).
	 *
	 * @param array<string,mixed> $item richText content item.
	 * @return bool
	 */
	private static function is_line_item_dropped( $item ) {
		$text       = isset( $item['text'] ) && is_scalar( $item['text'] ) ? (string) $item['text'] : '';
		$text_empty = ( '' === trim( $text ) );
		$has_embeds = isset( $item['embeddedObjects'] ) && is_array( $item['embeddedObjects'] ) && ! empty( $item['embeddedObjects'] );
		return $text_empty && ! $has_embeds;
	}

	/**
	 * Classify a richText content item into a run kind (R1, R2).
	 *
	 * Recognized kinds: paragraph, heading-1..heading-6, list-bulleted,
	 * list-numbered, list-checkbox, code, quote. Precedence when multiple
	 * line keys are present on one item (R1.4): codeBlock > quote > header >
	 * listStyle. Unknown listStyle / out-of-range header fall through to
	 * paragraph (R3.3, R4.7).
	 *
	 * @param array<string,mixed> $item richText content item.
	 * @return string
	 */
	private static function classify_line_item( $item ) {
		$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
		$line       = isset( $attributes['line'] ) && is_array( $attributes['line'] ) ? $attributes['line'] : array();

		// #56 R6 — text-empty items with embeddedObjects classify as `media`. Items
		// with non-empty `text` continue via the existing line-attribute hierarchy
		// regardless of embeddedObjects content (R5 precedence preserved).
		$text       = isset( $item['text'] ) && is_scalar( $item['text'] ) ? (string) $item['text'] : '';
		$has_embeds = isset( $item['embeddedObjects'] ) && is_array( $item['embeddedObjects'] ) && ! empty( $item['embeddedObjects'] );
		if ( '' === trim( $text ) && $has_embeds ) {
			return 'media';
		}

		if ( isset( $line['codeBlock'] ) && true === $line['codeBlock'] ) {
			return 'code';
		}
		if ( isset( $line['quote'] ) && true === $line['quote'] ) {
			return 'quote';
		}
		if ( isset( $line['header'] ) && is_int( $line['header'] ) && $line['header'] >= 1 && $line['header'] <= 6 ) {
			return 'heading-' . (int) $line['header'];
		}
		if ( isset( $line['listStyle'] ) && is_string( $line['listStyle'] ) ) {
			$style = $line['listStyle'];
			if ( 'bulleted' === $style || 'numbered' === $style || 'checkbox' === $style ) {
				return 'list-' . $style;
			}
		}

		return 'paragraph';
	}

	/**
	 * Dispatch a closed run to the matching block emitter (R2.8).
	 *
	 * `$photo_map` is forwarded only to the `media` branch (#56 R7) — other
	 * emitters keep their original signatures.
	 *
	 * @param array{kind:string,items:array} $run       Closed run.
	 * @param array<string,int>              $photo_map identifier → attachment_id map (#56 R12).
	 * @param array<string,int>              $video_map identifier → attachment_id map for videos (#57 R7.6).
	 * @param array<string,int>              $audio_map identifier → attachment_id map for audios (#58 R7.6).
	 * @param Day_One_Importer_Results|null  $results   Optional warning sink.
	 * @return string
	 */
	private static function flush_run( $run, array $photo_map, array $video_map, array $audio_map, ?Day_One_Importer_Results $results ) {
		$kind  = $run['kind'];
		$items = $run['items'];

		if ( 'media' === $kind ) {
			return self::emit_media_group( $items, $photo_map, $video_map, $audio_map, $results );
		}
		if ( 'paragraph' === $kind ) {
			return self::emit_paragraph_group( $items, $results );
		}
		if ( 'code' === $kind ) {
			return self::emit_code_group( $items, $results );
		}
		if ( 'quote' === $kind ) {
			return self::emit_quote_group( $items, $results );
		}
		if ( 0 === strpos( $kind, 'heading-' ) ) {
			$level = (int) substr( $kind, strlen( 'heading-' ) );
			return self::emit_heading_group( $items, $level, $results );
		}
		if ( 0 === strpos( $kind, 'list-' ) ) {
			$list_style = substr( $kind, strlen( 'list-' ) );
			return self::emit_list_group( $items, $list_style, $results );
		}

		return self::emit_paragraph_group( $items, $results );
	}

	/**
	 * Emit one paragraph block per item (legacy #53/#54 behavior preserved).
	 *
	 * Paragraph runs are NOT merged into a single block — each item still
	 * produces one block, matching the pre-#55 byte output (R7.2, AC12, AC14).
	 *
	 * @param array<int,array<string,mixed>> $items   Paragraph items.
	 * @param Day_One_Importer_Results|null  $results Optional warning sink.
	 * @return string
	 */
	private static function emit_paragraph_group( $items, ?Day_One_Importer_Results $results ) {
		$output = '';
		foreach ( $items as $item ) {
			$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
			$inline     = self::collect_inline_attributes( $attributes, $results );

			if ( ! $inline['has_supported'] ) {
				// R13 delegation — byte-for-byte legacy path.
				$text = isset( $item['text'] ) && is_scalar( $item['text'] ) ? (string) $item['text'] : '';
				if ( "\n" === substr( $text, -1 ) ) {
					$text = substr( $text, 0, -1 );
				}
				$output .= self::convert_text_to_content( $text );
				continue;
			}

			$wrapped = self::compute_inline_wrapped_text( $item, $results );
			$output .= self::serialize_block( 'paragraph', array(), '<p>' . $wrapped . '</p>' );
		}

		return $output;
	}

	/**
	 * Collect and validate inline attributes for a richText item (#54 R5/R7/R8/R9/R12).
	 *
	 * Emits warnings via $results when a present-but-invalid value is observed.
	 * Returns the strict-bool flags, the validated href, and the validated highlight
	 * color hex so callers can decide between the owning path and the legacy
	 * delegation (paragraph items only — heading/list/quote items always own).
	 *
	 * @param array<string,mixed>           $attributes Item attributes.
	 * @param Day_One_Importer_Results|null $results    Optional warning sink.
	 * @return array{is_bold:bool,is_italic:bool,is_strikethrough:bool,is_code:bool,valid_href:string,highlight_color_hex:string,has_supported:bool}
	 */
	private static function collect_inline_attributes( $attributes, ?Day_One_Importer_Results $results ) {
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

		return array(
			'is_bold'             => $is_bold,
			'is_italic'           => $is_italic,
			'is_strikethrough'    => $is_strikethrough,
			'is_code'             => $is_code,
			'valid_href'          => $valid_href,
			'highlight_color_hex' => $highlight_color_hex,
			'has_supported'       => $has_supported,
		);
	}

	/**
	 * Compute the inline-wrapped inner HTML fragment for a richText item.
	 *
	 * Returns the inner HTML fragment only — never block comments, never
	 * `<!-- wp:… -->` wrappers. Callers feed this into the inner of
	 * `<p>`, `<h{N}>`, `<li>`, or quote `<p>`. The text is escaped first
	 * via `escape_imported_text_fragment`, intra-text `\n` is rendered as
	 * `<br />\n`, then the #54 R11 wrapper order is applied:
	 *
	 *   1. `<code>`     (inlineCode)
	 *   2. `<s>`        (strikethrough)
	 *   3. `<em>`       (italic)
	 *   4. `<strong>`   (bold)
	 *   5. `<mark style="background-color:#RRGGBB">` (highlightedColor)
	 *   6. `<a href="…">` (linkURL / autolink)
	 *
	 * This helper is shared by paragraph (#54 owning path), heading, list-item,
	 * and quote-paragraph emitters. Code blocks deliberately do NOT call this
	 * helper (R5.3) — they escape text directly with no inline wrappers.
	 *
	 * @param array<string,mixed>           $item    richText content item.
	 * @param Day_One_Importer_Results|null $results Optional warning sink.
	 * @return string
	 */
	private static function compute_inline_wrapped_text( $item, ?Day_One_Importer_Results $results ) {
		$text = isset( $item['text'] ) && is_scalar( $item['text'] ) ? (string) $item['text'] : '';
		if ( "\n" === substr( $text, -1 ) ) {
			$text = substr( $text, 0, -1 );
		}

		$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
		$inline     = self::collect_inline_attributes( $attributes, $results );

		$lines        = explode( "\n", $text );
		$escaped_line = array_map( array( 'Day_One_Importer_Content', 'escape_imported_text_fragment' ), $lines );
		$wrapped      = implode( "<br />\n", $escaped_line );

		// R11 wrapper order — innermost to outermost. Plain `if` chain (not a generic loop)
		// so the order pin is visually obvious in the diff.
		if ( $inline['is_code'] ) {
			$wrapped = '<code>' . $wrapped . '</code>';
		}
		if ( $inline['is_strikethrough'] ) {
			$wrapped = '<s>' . $wrapped . '</s>';
		}
		if ( $inline['is_italic'] ) {
			$wrapped = '<em>' . $wrapped . '</em>';
		}
		if ( $inline['is_bold'] ) {
			$wrapped = '<strong>' . $wrapped . '</strong>';
		}
		if ( '' !== $inline['highlight_color_hex'] ) {
			$wrapped = '<mark style="background-color:' . $inline['highlight_color_hex'] . '">' . $wrapped . '</mark>';
		}
		if ( '' !== $inline['valid_href'] ) {
			$wrapped = '<a href="' . $inline['valid_href'] . '">' . $wrapped . '</a>';
		}

		return $wrapped;
	}

	/**
	 * Emit a heading block per item (R3). Headings never collapse across items —
	 * each item produces its own `core/heading` block (R2.3). Bypasses
	 * `convert_text_to_content` entirely (R7.1) — markdown-sigil handling does
	 * NOT apply inside heading inner text. Inline wrappers from #54 still apply
	 * (R3.2). Multi-line items render intra-text `\n` as `<br />\n` (R3.4).
	 *
	 * @param array<int,array<string,mixed>> $items   Heading items (kind = heading-N).
	 * @param int                            $level   Heading level (1..6).
	 * @param Day_One_Importer_Results|null  $results Optional warning sink.
	 * @return string
	 */
	private static function emit_heading_group( $items, $level, ?Day_One_Importer_Results $results ) {
		$level  = (int) $level;
		$attrs  = ( 2 === $level ) ? array() : array( 'level' => $level );
		$output = '';
		foreach ( $items as $item ) {
			$inner   = self::compute_inline_wrapped_text( $item, $results );
			$output .= self::serialize_block( 'heading', $attrs, '<h' . $level . '>' . $inner . '</h' . $level . '>' );
		}

		return $output;
	}

	/**
	 * Emit one `core/list` block for a consecutive list run (R4). Bypasses
	 * `convert_text_to_content` entirely (R7.1). Inline wrappers from #54
	 * apply inside `<li>`. Nested `core/list` blocks are spliced INSIDE the
	 * previous `<li>` (parent-child shape, R4.4). For checkbox lists each
	 * `<li>` is prefixed with the Unicode ballot-box glyph (R4.6).
	 *
	 * @param array<int,array<string,mixed>> $items      List items (one consistent style).
	 * @param string                         $list_style One of bulleted|numbered|checkbox.
	 * @param Day_One_Importer_Results|null  $results    Optional warning sink.
	 * @return string
	 */
	private static function emit_list_group( $items, $list_style, ?Day_One_Importer_Results $results ) {
		if ( empty( $items ) ) {
			return '';
		}

		// Stack frame: depth (1-based), block_attrs, outer_open, outer_close,
		// html (accumulated <!-- wp:list-item --> markup at this depth).
		$stack = array();

		foreach ( $items as $item ) {
			$depth       = self::extract_list_indent( $item );
			$stack_count = count( $stack );

			// Open levels as needed (R4.4 parent-child).
			while ( $stack_count < $depth ) {
				$is_nested = $stack_count > 0;
				$stack[]   = self::open_list_frame( $list_style, $item, $is_nested );
				++$stack_count;
			}

			// Close levels back up if depth shrank.
			while ( $stack_count > $depth ) {
				$closed = array_pop( $stack );
				--$stack_count;
				$nested                  = self::close_list_frame( $closed );
				$top_i                   = $stack_count - 1;
				$stack[ $top_i ]['html'] = self::splice_nested_into_last_list_item( $stack[ $top_i ]['html'], $nested );
			}

			$top_i                    = $stack_count - 1;
			$stack[ $top_i ]['html'] .= self::render_list_item( $item, $list_style, $results );
		}

		// Flush remaining frames bottom-up, splicing each into its parent's last list-item.
		$stack_count = count( $stack );
		while ( $stack_count > 1 ) {
			$closed = array_pop( $stack );
			--$stack_count;
			$nested                  = self::close_list_frame( $closed );
			$top_i                   = $stack_count - 1;
			$stack[ $top_i ]['html'] = self::splice_nested_into_last_list_item( $stack[ $top_i ]['html'], $nested );
		}

		return self::close_list_frame( array_pop( $stack ) );
	}

	/**
	 * Extract a normalized 1-based indentLevel from a list item (R4.4 cast rule).
	 *
	 * @param array<string,mixed> $item richText content item.
	 * @return int
	 */
	private static function extract_list_indent( $item ) {
		$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
		$line       = isset( $attributes['line'] ) && is_array( $attributes['line'] ) ? $attributes['line'] : array();
		$raw        = isset( $line['indentLevel'] ) ? $line['indentLevel'] : 1;
		$depth      = (int) $raw;
		return $depth >= 1 ? $depth : 1;
	}

	/**
	 * Open a new list frame for the stack (R4.1, R4.5).
	 *
	 * @param string              $list_style bulleted|numbered|checkbox.
	 * @param array<string,mixed> $first      First item in this frame.
	 * @param bool                $is_nested  True when opening a nested level (depth > 1).
	 * @return array{block_attrs:array,outer_open:string,outer_close:string,html:string}
	 */
	private static function open_list_frame( $list_style, $first, $is_nested ) {
		if ( 'bulleted' === $list_style ) {
			return array(
				'block_attrs' => array(),
				'outer_open'  => '<ul>',
				'outer_close' => '</ul>',
				'html'        => '',
			);
		}

		if ( 'numbered' === $list_style ) {
			$attrs = array( 'ordered' => true );
			if ( ! $is_nested ) {
				$attributes = isset( $first['attributes'] ) && is_array( $first['attributes'] ) ? $first['attributes'] : array();
				$line       = isset( $attributes['line'] ) && is_array( $attributes['line'] ) ? $attributes['line'] : array();
				$raw        = isset( $line['listIndex'] ) ? $line['listIndex'] : null;
				$candidate  = null;
				if ( is_int( $raw ) ) {
					$candidate = $raw;
				} elseif ( is_string( $raw ) && '' !== $raw && ctype_digit( ltrim( $raw, '-' ) ) ) {
					$candidate = (int) $raw;
				}
				if ( null !== $candidate && $candidate >= 2 ) {
					$attrs['start'] = $candidate;
				}
			}
			return array(
				'block_attrs' => $attrs,
				'outer_open'  => '<ol>',
				'outer_close' => '</ol>',
				'html'        => '',
			);
		}

		// checkbox.
		return array(
			'block_attrs' => array( 'className' => 'task-list' ),
			'outer_open'  => '<ul class="task-list">',
			'outer_close' => '</ul>',
			'html'        => '',
		);
	}

	/**
	 * Close a list frame: wrap accumulated list-item HTML inside outer ul/ol
	 * and serialize as a `core/list` block (R4.1).
	 *
	 * @param array{block_attrs:array,outer_open:string,outer_close:string,html:string} $frame Closed frame.
	 * @return string
	 */
	private static function close_list_frame( $frame ) {
		$inner = $frame['outer_open'] . "\n" . $frame['html'] . $frame['outer_close'];
		return self::serialize_block( 'list', $frame['block_attrs'], $inner );
	}

	/**
	 * Render one list item as a serialized `core/list-item` block (R4.2, R4.6).
	 *
	 * For checkbox lists the inner HTML is prefixed with the Unicode ballot-box
	 * glyph (`&#9745; ` checked / `&#9744; ` unchecked) before any inline wrappers
	 * (R4.6 — glyph emitted as a numeric character reference, NOT routed through
	 * the escaper).
	 *
	 * @param array<string,mixed>           $item       List item.
	 * @param string                        $list_style bulleted|numbered|checkbox.
	 * @param Day_One_Importer_Results|null $results    Optional warning sink.
	 * @return string
	 */
	private static function render_list_item( $item, $list_style, ?Day_One_Importer_Results $results ) {
		$inner = self::compute_inline_wrapped_text( $item, $results );
		if ( 'checkbox' === $list_style ) {
			$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
			$line       = isset( $attributes['line'] ) && is_array( $attributes['line'] ) ? $attributes['line'] : array();
			$checked    = isset( $line['checked'] ) && true === $line['checked'];
			$glyph      = $checked ? '&#9745; ' : '&#9744; ';
			$inner      = $glyph . $inner;
		}

		return self::serialize_block( 'list-item', array(), '<li>' . $inner . '</li>' );
	}

	/**
	 * Splice a nested `<!-- wp:list ... -->` block inside the rightmost
	 * `<!-- wp:list-item -->` of the parent frame's accumulated HTML (R4.4).
	 *
	 * Locates the last `</li>\n<!-- /wp:list-item -->` substring and inserts
	 * the nested block immediately BEFORE the `</li>`. The nested
	 * `<!-- wp:list -->...<!-- /wp:list -->` sits between the parent's `<li>`
	 * and `</li>` — the Gutenberg-native parent-child shape.
	 *
	 * @param string $parent_html Accumulated parent-frame HTML (list-item runs).
	 * @param string $nested_html Closed nested list block markup.
	 * @return string
	 */
	private static function splice_nested_into_last_list_item( $parent_html, $nested_html ) {
		$needle = "</li>\n<!-- /wp:list-item -->";
		$pos    = strrpos( $parent_html, $needle );
		if ( false === $pos ) {
			// Defensive: no list-item to nest into; append at end.
			return $parent_html . $nested_html;
		}

		return substr( $parent_html, 0, $pos ) . $nested_html . substr( $parent_html, $pos );
	}

	/**
	 * Emit one `core/code` block for a consecutive code run (R5).
	 *
	 * Strict R5.2 join algorithm: per-item, strip exactly one trailing `\n`
	 * (NOT rtrim — only one), escape via `escape_imported_text_fragment`,
	 * then join the escaped per-item results with a single `\n` byte.
	 *
	 * Inline wrappers from #54 are explicitly NOT applied (R5.3) — `<strong>`
	 * inside `<pre><code>` is not idiomatic Gutenberg markup. This helper
	 * does NOT call `compute_inline_wrapped_text` and does NOT route
	 * inline-attribute warnings (R5.3, F21) — invalid `linkURL` /
	 * `highlightedColor` on code items record no warning either way.
	 *
	 * Bypasses `convert_text_to_content` entirely (R7.1).
	 *
	 * @param array<int,array<string,mixed>> $items   Code-block items.
	 * @param Day_One_Importer_Results|null  $results Unused; accepted for signature uniformity.
	 * @return string
	 */
	private static function emit_code_group( $items, ?Day_One_Importer_Results $results ) {
		unset( $results ); // R5.3 — no warning emit inside code.
		$pieces = array();
		foreach ( $items as $item ) {
			$text = isset( $item['text'] ) && is_scalar( $item['text'] ) ? (string) $item['text'] : '';
			if ( "\n" === substr( $text, -1 ) ) {
				$text = substr( $text, 0, -1 );
			}
			$pieces[] = self::escape_imported_text_fragment( $text );
		}
		$joined = implode( "\n", $pieces );
		$inner  = '<pre class="wp-block-code"><code>' . $joined . '</code></pre>';

		return self::serialize_block( 'code', array(), $inner );
	}

	/**
	 * Emit one `core/quote` block for a consecutive quote run (R6).
	 *
	 * Each quote item becomes one child `core/paragraph` block inside the
	 * outer `<blockquote class="wp-block-quote">`. Inline wrappers from #54
	 * apply unchanged inside each child paragraph (R6.2, R7.3). Quote
	 * `indentLevel` is intentionally ignored — every quote item is a flat
	 * sibling at the same `<blockquote>` depth (R6.5). No `citation` attr
	 * is emitted (R6.4). Bypasses `convert_text_to_content` entirely (R7.1).
	 *
	 * @param array<int,array<string,mixed>> $items   Quote items.
	 * @param Day_One_Importer_Results|null  $results Optional warning sink.
	 * @return string
	 */
	private static function emit_quote_group( $items, ?Day_One_Importer_Results $results ) {
		$inner = '<blockquote class="wp-block-quote">' . "\n";
		foreach ( $items as $item ) {
			$wrapped = self::compute_inline_wrapped_text( $item, $results );
			$inner  .= self::serialize_block( 'paragraph', array(), '<p>' . $wrapped . '</p>' );
		}
		$inner .= '</blockquote>';

		return self::serialize_block( 'quote', array(), $inner );
	}

	/**
	 * Emit one image/gallery block (or nothing) for a consecutive media run (#56 R7).
	 *
	 * Walks each item's `embeddedObjects[]` in scan order. Photo embeds resolve
	 * their `identifier` against the runner-supplied `$photo_map` and contribute
	 * an attachment ID to the run-level scan-ordered list. Unsupported types
	 * (video/audio/pdfAttachment) emit nothing and route one per-entry-per-type
	 * warning (issues #57/#58/#59 — internal traceability only; warning text is
	 * issue-number-free per Risk 6). Unresolved photo identifiers emit nothing
	 * and route one warning per missing identifier (per-identifier, not deduped).
	 *
	 * Cardinality (#56 R8):
	 *   - 0 resolved IDs → no block.
	 *   - 1 resolved ID  → core/image block.
	 *   - 2+ resolved IDs → core/gallery block.
	 *
	 * @param array<int,array<string,mixed>> $items     Media-run items.
	 * @param array<string,int>              $photo_map identifier → attachment_id map (runner-built; may be empty).
	 * @param array<string,int>              $video_map identifier → attachment_id map for videos (#57 R6.1).
	 * @param array<string,int>              $audio_map identifier → attachment_id map for audios (#58 R6.1).
	 * @param Day_One_Importer_Results|null  $results   Optional warning sink.
	 * @return string
	 */
	private static function emit_media_group( array $items, array $photo_map, array $video_map, array $audio_map, ?Day_One_Importer_Results $results ) {
		$resolved     = array(); // Scan-ordered tagged records: ['type' => 'photo'|'video', 'attachment_id' => int].
		$warned_types = array(); // Per-entry-per-type dedupe for unsupported media (#56 R8 / Risk 5).

		foreach ( $items as $item ) {
			$embeds = isset( $item['embeddedObjects'] ) && is_array( $item['embeddedObjects'] ) ? $item['embeddedObjects'] : array();
			foreach ( $embeds as $embed ) {
				if ( ! is_array( $embed ) ) {
					continue;
				}
				$type       = isset( $embed['type'] ) && is_string( $embed['type'] ) ? $embed['type'] : '';
				$identifier = isset( $embed['identifier'] ) && is_scalar( $embed['identifier'] ) ? (string) $embed['identifier'] : '';

				if ( 'photo' === $type ) {
					if ( '' !== $identifier && isset( $photo_map[ $identifier ] ) ) {
						$resolved[] = array(
							'type'          => 'photo',
							'attachment_id' => (int) $photo_map[ $identifier ],
						);
					} elseif ( null !== $results ) {
						$results->add_warning(
							__( 'Skipping embedded photo in Day One entry: referenced media file is not present in the export.', 'day-one-importer' )
						);
					}
					continue;
				}

				if ( 'video' === $type ) {
					// #57 R6.2 — resolve against $video_map; missing identifier OR MIME-rejected
					// surfaces here as "not in map" (the media stage already dropped the embed).
					// Emit one warning per missing identifier (no per-type dedupe; matches the
					// photo precedent).
					if ( '' !== $identifier && isset( $video_map[ $identifier ] ) ) {
						$resolved[] = array(
							'type'          => 'video',
							'attachment_id' => (int) $video_map[ $identifier ],
						);
					} elseif ( null !== $results ) {
						$results->add_warning(
							__( 'Skipping embedded video in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
						);
					}
					continue;
				}

				if ( 'audio' === $type ) {
					if ( ! isset( $warned_types['audio'] ) ) {
						$warned_types['audio'] = true;
						if ( null !== $results ) {
							// Tracked by issue #58 (internal traceability only).
							$results->add_warning( __( 'Skipping embedded audio; audio import is not yet supported.', 'day-one-importer' ) );
						}
					}
					continue;
				}

				if ( 'pdfAttachment' === $type ) {
					if ( ! isset( $warned_types['pdfAttachment'] ) ) {
						$warned_types['pdfAttachment'] = true;
						if ( null !== $results ) {
							// Tracked by issue #59 (internal traceability only).
							$results->add_warning( __( 'Skipping embedded PDF attachment; PDF import is not yet supported.', 'day-one-importer' ) );
						}
					}
					continue;
				}

				// Unknown embed type: silent skip — no warning, no block.
			}
		}

		if ( empty( $resolved ) ) {
			return '';
		}

		// #57 R6.4 — walk the scan-ordered list. Accumulate consecutive photo
		// records into an image (n=1) or gallery (n>=2). Video records flush the
		// pending photo run and then emit one core/video block each.
		$output       = '';
		$photo_run    = array();
		$flush_photos = static function () use ( &$photo_run, &$output ) {
			if ( empty( $photo_run ) ) {
				return;
			}
			$images = array();
			foreach ( $photo_run as $attachment_id ) {
				$image = self::build_attachment_image_record( $attachment_id );
				if ( $image ) {
					$images[] = $image;
				}
			}
			if ( ! empty( $images ) ) {
				if ( 1 === count( $images ) ) {
					$output .= self::serialize_image_block( $images[0] );
				} else {
					$output .= self::serialize_gallery_block( $images );
				}
			}
			$photo_run = array();
		};

		foreach ( $resolved as $record ) {
			if ( 'photo' === $record['type'] ) {
				$photo_run[] = (int) $record['attachment_id'];
				continue;
			}
			if ( 'video' === $record['type'] ) {
				$flush_photos();
				$output .= self::serialize_video_block( (int) $record['attachment_id'] );
			}
		}
		$flush_photos();

		return $output;
	}

	/**
	 * Render the body of a normalized entry using the appropriate path.
	 *
	 * Dispatch helper called by both runner invocation sites. When the
	 * entry carries a usable richText payload, route through the richText
	 * renderer; otherwise fall back to the legacy markdown path so that
	 * legacy entries produce byte-identical content. The optional
	 * `$photo_map` is forwarded to the richText renderer (#56 R12) so the
	 * media emitter can resolve `embeddedObjects[].identifier` to an
	 * attachment ID.
	 *
	 * @param mixed                         $entry     Normalized entry array.
	 * @param Day_One_Importer_Results|null $results   Optional warning sink threaded into the richText renderer.
	 * @param array<string,int>             $photo_map identifier → attachment_id map (#56 R12).
	 * @param array<string,int>             $video_map identifier → attachment_id map for videos (#57 R7.6).
	 * @param array<string,int>             $audio_map identifier → attachment_id map for audios (#58 R7.6).
	 * @return string
	 */
	public static function render_entry_body( $entry, ?Day_One_Importer_Results $results = null, array $photo_map = array(), array $video_map = array(), array $audio_map = array() ) {
		if ( self::entry_uses_rich_text_path( $entry ) ) {
			return self::convert_rich_text_to_content( $entry['richText'], $results, $photo_map, $video_map, $audio_map );
		}

		$text = ( is_array( $entry ) && isset( $entry['text'] ) ) ? $entry['text'] : '';
		return self::convert_text_to_content( $text );
	}

	/**
	 * Predicate gating richText-vs-legacy decisions in the runner and renderer (#56 R14.1).
	 *
	 * @param mixed $entry Normalized entry array.
	 * @return bool
	 */
	public static function entry_uses_rich_text_path( $entry ) {
		return is_array( $entry ) && isset( $entry['richText'] ) && is_array( $entry['richText'] ) && ! empty( $entry['richText'] );
	}

	/**
	 * Whether a richText payload contains at least one `type=photo` embed (#56 R14 detection).
	 *
	 * Walks `contents[]` defensively; tolerates missing keys, non-array shapes,
	 * and non-scalar identifiers.
	 *
	 * @param mixed $rich_text richText payload (array or already-decoded form).
	 * @return bool
	 */
	private static function richtext_has_photo_embeds( $rich_text ) {
		if ( ! is_array( $rich_text ) ) {
			return false;
		}
		$contents = isset( $rich_text['contents'] ) && is_array( $rich_text['contents'] ) ? $rich_text['contents'] : array();
		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$embeds = isset( $item['embeddedObjects'] ) && is_array( $item['embeddedObjects'] ) ? $item['embeddedObjects'] : array();
			foreach ( $embeds as $embed ) {
				if ( is_array( $embed ) && isset( $embed['type'] ) && 'photo' === $embed['type'] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Negated wrapper for #56 R14 — runner gate for "richText with no photo embeds".
	 *
	 * @param mixed $rich_text richText payload.
	 * @return bool
	 */
	public static function richtext_has_no_photo_embeds( $rich_text ) {
		return ! self::richtext_has_photo_embeds( $rich_text );
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
	 * Serialize a core/video block for an imported Day One video attachment.
	 *
	 * Producer contract pinned by spec R6.5 — comment payload is
	 * `<!-- wp:video {"id":<id>} -->` followed by a single newline, then the
	 * <figure> wrapper, then `<!-- /wp:video -->`. Tests assert semantically via
	 * parse_blocks() + substring match, never byte-equality.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Empty string when the attachment URL is unavailable or not
	 *                served from the Day One private uploads directory.
	 */
	private static function serialize_video_block( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id || ! function_exists( 'wp_get_attachment_url' ) ) {
			return '';
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		// Defensive check (#57 R6.5): refuse to emit a block for an attachment
		// the importer did not create. The runtime wp_get_attachment_url filter
		// rewrites Day One media to an admin-ajax endpoint, so the filtered URL
		// does not contain the private uploads subdir literal — we accept it
		// only when the attachment carries the `_day_one_source = day-one-export`
		// marker. In pure-helper mode (no get_post_meta stub), we fall back to
		// checking that the URL literal contains the private uploads subdir so
		// the producer contract can still be exercised in tests.
		$is_day_one_attachment = false;
		if ( function_exists( 'get_post_meta' ) ) {
			$is_day_one_attachment = ( 'day-one-export' === (string) get_post_meta( $attachment_id, '_day_one_source', true ) );
		}
		if ( ! $is_day_one_attachment ) {
			if ( ! class_exists( 'Day_One_Importer_Media' ) || false === strpos( $url, Day_One_Importer_Media::PRIVATE_UPLOAD_SUBDIR ) ) {
				return '';
			}
		}

		$attrs      = array( 'id' => $attachment_id );
		$inner_html = '<figure class="wp-block-video"><video controls src="' . esc_url( $url ) . '"></video></figure>';

		return self::serialize_block( 'video', $attrs, $inner_html );
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
		/*
		 * Scheme suffix enumeration is intentionally closed — `dayone-foo://X`
		 * MUST NOT match (#56 R3 / AC2). Supported forms (case-insensitive,
		 * fully-trimmed line):
		 *   dayone-moment://<UUID>
		 *   dayone-moment:/{photo|video|audio|pdfAttachment}/<UUID>
		 *   dayone-{photo|video|audio|pdf}://<UUID>
		 */
		return (bool) preg_match(
			'/^!\[[^\]]*\]\(dayone-(?:'
			. 'moment:\/\/[^\s)]+'
			. '|moment:\/(?:photo|video|audio|pdfAttachment)\/[^\s)]+'
			. '|(?:photo|video|audio|pdf):\/\/[^\s)]+'
			. ')\)$/i',
			trim( (string) $line )
		);
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
