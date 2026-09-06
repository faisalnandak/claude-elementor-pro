<?php
/**
 * css-audit.php — audit the Custom CSS of an Elementor Pro project.
 *
 *   php css-audit.php inventory     every place CSS is stored, with sizes
 *   php css-audit.php blocks        marker-delimited blocks inside the kit's CSS
 *   php css-audit.php rules         every rule, with its declarations
 *   php css-audit.php classify      per-rule verdict: panel-able / superseded / keep
 *   php css-audit.php selectors     JSON list of selectors, for the dead-check in a browser
 *   php css-audit.php colors        hex values used, and whether a global already holds them
 *
 * The goal is not less CSS. The goal is that every rule left in Custom CSS is
 * there because Elementor's panel genuinely cannot express it — and that the
 * reason is written down.
 *
 * Read-only. Deleting rules is a job for a per-change tool with a backup.
 *
 * @package claude-elementor-pro
 * @license MIT
 */

require __DIR__ . '/elementor-lib.php';
el_boot();

$ca_argv = el_args();
$ca_cmd  = strtolower( (string) ( $ca_argv[0] ?? 'inventory' ) );
$ca_kit  = el_kit_id();

switch ( $ca_cmd ) {
	case 'inventory':
		ca_inventory();
		break;
	case 'blocks':
		ca_blocks( el_css_get( $ca_kit ) );
		break;
	case 'rules':
		ca_rules( el_css_get( $ca_kit ) );
		break;
	case 'classify':
		ca_classify( el_css_get( $ca_kit ) );
		break;
	case 'selectors':
		ca_selectors( el_css_get( $ca_kit ) );
		break;
	case 'colors':
		ca_colors( el_css_get( $ca_kit ), $ca_kit );
		break;
	default:
		el_die( "Unknown command '{$ca_cmd}'." );
}

/* ------------------------------------------------------------------ */
/* Parsing                                                             */
/* ------------------------------------------------------------------ */

/**
 * Flatten a stylesheet into rules.
 *
 * Returns a list of [ 'at' => '@media …' or '', 'selector' => …,
 * 'declarations' => [ prop => value ], 'order' => n ].
 *
 * Deliberately simple: Custom CSS blocks are hand-written, not minified
 * frameworks. Anything it cannot parse is reported rather than skipped
 * silently.
 */
function ca_parse( string $ca_css, string $ca_at = '', int &$ca_order = 0 ): array {
	$ca_css   = preg_replace( '!/\*.*?\*/!s', '', $ca_css );
	$ca_out   = [];
	$ca_len   = strlen( (string) $ca_css );
	$ca_i     = 0;
	$ca_buf   = '';

	while ( $ca_i < $ca_len ) {
		$ca_ch = $ca_css[ $ca_i ];

		if ( '{' === $ca_ch ) {
			$ca_sel   = trim( preg_replace( '/\s+/', ' ', $ca_buf ) );
			$ca_depth = 1;
			$ca_body  = '';
			$ca_i++;
			while ( $ca_i < $ca_len && $ca_depth > 0 ) {
				$ca_c = $ca_css[ $ca_i ];
				if ( '{' === $ca_c ) {
					$ca_depth++;
				} elseif ( '}' === $ca_c ) {
					$ca_depth--;
					if ( 0 === $ca_depth ) {
						$ca_i++;
						break;
					}
				}
				$ca_body .= $ca_c;
				$ca_i++;
			}

			if ( str_starts_with( $ca_sel, '@' ) && false !== strpos( $ca_body, '{' ) ) {
				$ca_out = array_merge( $ca_out, ca_parse( $ca_body, trim( $ca_at . ' ' . $ca_sel ), $ca_order ) );
			} else {
				$ca_decls = [];
				foreach ( explode( ';', $ca_body ) as $ca_pair ) {
					if ( false === strpos( $ca_pair, ':' ) ) {
						continue;
					}
					[ $ca_prop, $ca_val ] = explode( ':', $ca_pair, 2 );
					$ca_prop              = trim( $ca_prop );
					if ( '' === $ca_prop ) {
						continue;
					}
					$ca_decls[ $ca_prop ] = trim( preg_replace( '/\s+/', ' ', $ca_val ) );
				}
				foreach ( explode( ',', $ca_sel ) as $ca_one ) {
					$ca_one = trim( $ca_one );
					if ( '' === $ca_one ) {
						continue;
					}
					$ca_out[] = [
						'at'           => $ca_at,
						'selector'     => $ca_one,
						'declarations' => $ca_decls,
						'order'        => $ca_order++,
					];
				}
			}
			$ca_buf = '';
			continue;
		}

		$ca_buf .= $ca_ch;
		$ca_i++;
	}

	return $ca_out;
}

/** Properties a native Elementor control owns on a normal widget or container. */
function ca_panelable_property( string $ca_prop ): ?string {
	static $ca_map = [
		'padding'               => 'Advanced > Layout > Padding',
		'margin'                => 'Advanced > Layout > Margin',
		'gap'                   => 'Layout > Gap (container)',
		'row-gap'               => 'Layout > Gap (container)',
		'column-gap'            => 'Layout > Gap (container)',
		'display'               => 'Layout > Direction, or Responsive > Visibility for none',
		'flex-direction'        => 'Layout > Direction',
		'flex-wrap'             => 'Layout > Wrap',
		'align-items'           => 'Layout > Align Items',
		'justify-content'       => 'Layout > Justify Content',
		'grid-template-columns' => 'Layout > Grid Columns (accepts a custom value)',
		'max-width'             => 'Layout > Content Width / Advanced > Width',
		'width'                 => 'Advanced > Width (only when content_width is full)',
		'text-align'            => 'the widget\'s Alignment control',
		'font-size'             => 'Style > Typography',
		'font-weight'           => 'Style > Typography',
		'line-height'           => 'Style > Typography',
		'letter-spacing'        => 'Style > Typography',
		'text-transform'        => 'Style > Typography',
		'font-family'           => 'Style > Typography (bind a Global Font)',
		'color'                 => 'Style > Color (bind a Global Color)',
		'background'            => 'Style > Background',
		'background-color'      => 'Style > Background',
		'border-radius'         => 'Style > Border > Radius',
		'border'                => 'Style > Border',
		'border-width'          => 'Style > Border',
		'border-color'          => 'Style > Border',
		'box-shadow'            => 'Style > Box Shadow',
		'z-index'               => 'Advanced > Z-Index',
	];
	return $ca_map[ strtolower( $ca_prop ) ] ?? null;
}

/** Selector features that put a rule beyond the panel. */
function ca_beyond_panel( string $ca_sel ): ?string {
	if ( preg_match( '/::?(before|after|first-letter|first-line|placeholder|marker|selection)/i', $ca_sel ) ) {
		return 'pseudo-element';
	}
	if ( preg_match( '/:nth-|:not\(|:first-child|:last-child|:only-child/i', $ca_sel ) ) {
		return 'structural pseudo-class';
	}
	if ( preg_match( '/[+~]/', $ca_sel ) ) {
		return 'sibling combinator';
	}
	if ( preg_match( '/:(hover|focus|focus-within|active)/i', $ca_sel ) && preg_match( '/\s/', $ca_sel ) ) {
		return 'state on a descendant';
	}
	if ( preg_match( '/\[[^\]]+\]/', $ca_sel ) ) {
		return 'attribute selector';
	}
	return null;
}

/* ------------------------------------------------------------------ */
/* Commands                                                            */
/* ------------------------------------------------------------------ */

function ca_inventory(): void {
	el_say( 'Run "php el-inspect.php css" for the full storage inventory.' );
	el_say();
	$ca_kit = el_kit_id();
	$ca_css = el_css_get( $ca_kit );
	$ca_rules = ca_parse( $ca_css );
	el_say( 'Kit #' . $ca_kit . ' Custom CSS: ' . strlen( $ca_css ) . ' bytes, ' . count( $ca_rules ) . ' rules.' );
	ca_blocks( $ca_css );
}

function ca_blocks( string $ca_css ): void {
	el_say();
	el_say( 'Owned blocks' );
	el_say( '------------' );

	if ( ! preg_match_all( '!/\*+\s*([^*]+?)\s*\*+/!', $ca_css, $ca_m, PREG_OFFSET_CAPTURE ) ) {
		el_say( '  no comments at all — nothing in this stylesheet has an owner' );
		return;
	}

	// An owned block is a comment X followed later by its closing twin: the same
	// text plus a terminator word. Anything outside such a pair belongs to no
	// tool, which means no script can safely rewrite it.
	$ca_open   = [];
	$ca_owned  = [];
	$ca_ranges = [];

	foreach ( $ca_m[1] as $ca_i => $ca_hit ) {
		$ca_label = trim( $ca_hit[0] );
		$ca_pos   = $ca_m[0][ $ca_i ][1];
		$ca_len   = strlen( $ca_m[0][ $ca_i ][0] );

		if ( preg_match( '/^(.*?)\s+(end|selesai|fin|ende|done)$/i', $ca_label, $ca_close ) ) {
			$ca_key = strtolower( trim( $ca_close[1] ) );
			if ( isset( $ca_open[ $ca_key ] ) ) {
				$ca_owned[]  = [
					'label' => trim( $ca_close[1] ),
					'bytes' => ( $ca_pos + $ca_len ) - $ca_open[ $ca_key ],
				];
				$ca_ranges[] = [ $ca_open[ $ca_key ], $ca_pos + $ca_len ];
				unset( $ca_open[ $ca_key ] );
			}
			continue;
		}

		$ca_open[ strtolower( $ca_label ) ] = $ca_pos;
	}

	if ( $ca_owned ) {
		foreach ( $ca_owned as $ca_b ) {
			el_say( sprintf( '  %-52s %6d bytes', mb_substr( $ca_b['label'], 0, 52 ), $ca_b['bytes'] ) );
		}
	} else {
		el_say( '  none — no comment has a matching closing marker' );
	}

	$ca_inside = 0;
	foreach ( $ca_ranges as $ca_r ) {
		$ca_inside += $ca_r[1] - $ca_r[0];
	}
	$ca_total = strlen( $ca_css );

	el_say();
	el_say( sprintf( '  %d bytes owned, %d bytes unowned, %d total.', $ca_inside, $ca_total - $ca_inside, $ca_total ) );
	el_say();
	el_say( '  Unowned CSS is the part no script can rewrite safely: give each future change' );
	el_say( '  its own marker pair. Both markers must be complete comments — a stray word' );
	el_say( '  outside a comment swallows the first rule of the block that follows.' );
}

function ca_rules( string $ca_css ): void {
	$ca_rules = ca_parse( $ca_css );
	el_say( count( $ca_rules ) . ' rules' );
	el_say();
	foreach ( $ca_rules as $ca_r ) {
		el_say( sprintf( '  %-58s %s', $ca_r['selector'], $ca_r['at'] ) );
		foreach ( $ca_r['declarations'] as $ca_p => $ca_v ) {
			el_say( sprintf( '        %-24s %s', $ca_p, mb_substr( $ca_v, 0, 60 ) ) );
		}
	}
}

function ca_classify( string $ca_css ): void {
	$ca_rules = ca_parse( $ca_css );

	// Superseded: a later rule with the same at-rule + selector that redeclares
	// every property of an earlier one. Compare sorted arrays — PHP's !== on
	// arrays compares key ORDER too, and an unsorted comparison quietly reports
	// that nothing is superseded.
	$ca_by_sel = [];
	foreach ( $ca_rules as $ca_i => $ca_r ) {
		$ca_by_sel[ $ca_r['at'] . '|' . $ca_r['selector'] ][] = $ca_i;
	}

	$ca_superseded = [];
	foreach ( $ca_by_sel as $ca_key => $ca_idxs ) {
		if ( count( $ca_idxs ) < 2 ) {
			continue;
		}
		for ( $ca_a = 0; $ca_a < count( $ca_idxs ) - 1; $ca_a++ ) {
			$ca_earlier = $ca_rules[ $ca_idxs[ $ca_a ] ]['declarations'];
			for ( $ca_b = $ca_a + 1; $ca_b < count( $ca_idxs ); $ca_b++ ) {
				$ca_later = $ca_rules[ $ca_idxs[ $ca_b ] ]['declarations'];
				$ca_left  = array_keys( $ca_earlier );
				$ca_right = array_keys( $ca_later );
				sort( $ca_left );
				sort( $ca_right );
				if ( ! array_diff( $ca_left, $ca_right ) ) {
					$ca_superseded[ $ca_idxs[ $ca_a ] ] = $ca_idxs[ $ca_b ];
					break;
				}
			}
		}
	}

	$ca_counts = [ 'superseded' => 0, 'panel' => 0, 'keep' => 0, 'mixed' => 0 ];

	el_say( 'Verdicts' );
	el_say( '--------' );

	foreach ( $ca_rules as $ca_i => $ca_r ) {
		if ( isset( $ca_superseded[ $ca_i ] ) ) {
			$ca_counts['superseded']++;
			el_say(
				sprintf(
					'  SUPERSEDED  %-52s by the later rule at position %d',
					$ca_r['selector'],
					$ca_superseded[ $ca_i ]
				)
			);
			continue;
		}

		$ca_beyond = ca_beyond_panel( $ca_r['selector'] );
		if ( null !== $ca_beyond ) {
			$ca_counts['keep']++;
			continue;
		}

		$ca_panel = [];
		$ca_other = [];
		foreach ( array_keys( $ca_r['declarations'] ) as $ca_prop ) {
			$ca_where = ca_panelable_property( $ca_prop );
			if ( null !== $ca_where ) {
				$ca_panel[ $ca_prop ] = $ca_where;
			} else {
				$ca_other[] = $ca_prop;
			}
		}

		if ( $ca_panel && ! $ca_other ) {
			$ca_counts['panel']++;
			el_say( sprintf( '  PANEL-ABLE  %-52s %s', $ca_r['selector'], $ca_r['at'] ) );
			foreach ( $ca_panel as $ca_prop => $ca_where ) {
				el_say( sprintf( '              %-24s -> %s', $ca_prop, $ca_where ) );
			}
		} elseif ( $ca_panel ) {
			$ca_counts['mixed']++;
			el_say( sprintf( '  MIXED       %-52s %s', $ca_r['selector'], $ca_r['at'] ) );
			el_say( '              panel: ' . implode( ', ', array_keys( $ca_panel ) ) );
			el_say( '              keep : ' . implode( ', ', $ca_other ) );
		} else {
			$ca_counts['keep']++;
		}
	}

	el_say();
	el_say( sprintf( '  %d superseded, %d fully panel-able, %d mixed, %d keep.', $ca_counts['superseded'], $ca_counts['panel'], $ca_counts['mixed'], $ca_counts['keep'] ) );
	el_say();
	el_say( '  This is a heuristic on selectors and property names, not proof.' );
	el_say( '  A "panel-able" rule is only really panel-able when a single element owns the' );
	el_say( '  selector — check with php el-inspect.php find <class> before moving it.' );
	el_say( '  Dead rules need a browser: php css-audit.php selectors, then test each' );
	el_say( '  selector against one URL of every template type.' );
}

function ca_selectors( string $ca_css ): void {
	$ca_rules = ca_parse( $ca_css );
	$ca_sels  = [];
	foreach ( $ca_rules as $ca_r ) {
		$ca_sels[ $ca_r['selector'] ] = true;
	}
	$ca_list = array_keys( $ca_sels );
	sort( $ca_list );
	el_say( json_encode( $ca_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}

function ca_colors( string $ca_css, int $ca_kit ): void {
	$ca_settings = get_post_meta( $ca_kit, '_elementor_page_settings', true );
	if ( is_string( $ca_settings ) && '' !== $ca_settings ) {
		$ca_settings = json_decode( $ca_settings, true );
	}
	$ca_settings = is_array( $ca_settings ) ? $ca_settings : [];

	$ca_globals = [];
	foreach ( [ 'system_colors', 'custom_colors' ] as $ca_group ) {
		foreach ( (array) ( $ca_settings[ $ca_group ] ?? [] ) as $ca_c ) {
			$ca_globals[ strtoupper( (string) ( $ca_c['color'] ?? '' ) ) ] = (string) ( $ca_c['title'] ?? $ca_c['_id'] ?? '' );
		}
	}

	preg_match_all( '/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $ca_css, $ca_m );
	$ca_counts = [];
	foreach ( $ca_m[0] as $ca_hex ) {
		$ca_up               = strtoupper( $ca_hex );
		$ca_counts[ $ca_up ] = ( $ca_counts[ $ca_up ] ?? 0 ) + 1;
	}
	arsort( $ca_counts );

	el_say( 'Hex colours in the kit Custom CSS' );
	el_say( '---------------------------------' );
	foreach ( $ca_counts as $ca_hex => $ca_n ) {
		el_say(
			sprintf(
				'  %-9s %4d use(s)   %s',
				$ca_hex,
				$ca_n,
				isset( $ca_globals[ $ca_hex ] ) ? 'global: ' . $ca_globals[ $ca_hex ] : 'no global holds this value'
			)
		);
	}
	el_say();
	el_say( '  CSS cannot read an Elementor global, so these stay as literals or as var()' );
	el_say( '  references to the backlog :root block. What binding buys you is on the widget' );
	el_say( '  side: php globals-bind.php.' );
}
