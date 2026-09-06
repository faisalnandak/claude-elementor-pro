<?php
/**
 * backlog-tokens.php — harvest design tokens from a folder of HTML mockups.
 *
 *   php backlog-tokens.php /path/to/product-backlog
 *   php backlog-tokens.php /path/to/product-backlog --json
 *
 * Reads every .html file, collects the :root custom properties, reports any
 * token whose value disagrees between files, and prints a mapping proposal for
 * Elementor Global Colors and Global Fonts.
 *
 * Register the tokens as Elementor Globals early. Widgets bind to globals
 * natively, so a brand colour becomes one edit instead of hundreds; leaving it
 * until late means rebinding hundreds of already-saved literal values.
 *
 * Standalone: no WordPress needed. Writes nothing.
 *
 * @package claude-elementor-pro
 * @license MIT
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "CLI only.\n" );
}

$bt_args = array_slice( $argv, 1 );
$bt_json = in_array( '--json', $bt_args, true );
$bt_dir  = '';
foreach ( $bt_args as $bt_arg ) {
	if ( ! str_starts_with( $bt_arg, '--' ) ) {
		$bt_dir = $bt_arg;
		break;
	}
}

if ( '' === $bt_dir || ! is_dir( $bt_dir ) ) {
	fwrite( STDERR, "Usage: php backlog-tokens.php <backlog-folder> [--json]\n" );
	exit( 1 );
}

$bt_files = glob( rtrim( $bt_dir, '/\\' ) . '/*.html' ) ?: [];
if ( ! $bt_files ) {
	fwrite( STDERR, "No .html files in {$bt_dir}\n" );
	exit( 1 );
}

/* Collect ------------------------------------------------------------ */

$bt_tokens = [];   // name => [ value => [files] ]
$bt_fonts  = [];   // family => count
$bt_hex    = [];   // hex => count

foreach ( $bt_files as $bt_file ) {
	$bt_src  = (string) file_get_contents( $bt_file );
	$bt_name = basename( $bt_file );

	// :root blocks. Mockups exported as self-unpacking bundles keep their CSS
	// in plain <style> text, so a regex over the raw file still finds them even
	// though the markup itself is unreachable without a browser.
	if ( preg_match_all( '/:root\s*\{([^}]*)\}/s', $bt_src, $bt_blocks ) ) {
		foreach ( $bt_blocks[1] as $bt_block ) {
			if ( preg_match_all( '/(--[A-Za-z0-9_-]+)\s*:\s*([^;]+);/', $bt_block, $bt_pairs, PREG_SET_ORDER ) ) {
				foreach ( $bt_pairs as $bt_pair ) {
					$bt_key = trim( $bt_pair[1] );
					// Self-unpacking bundles keep the CSS inside a JS string, so quotes
					// arrive escaped. Unescape before comparing values across files.
					$bt_val = trim( preg_replace( '/\s+/', ' ', $bt_pair[2] ) );
					$bt_val = str_replace( [ '\\"', "\'" ], [ '"', "'" ], $bt_val );
					$bt_tokens[ $bt_key ][ $bt_val ][] = $bt_name;
				}
			}
		}
	}

	if ( preg_match_all( '/font-family\s*:\s*([^;}"\']+)/i', $bt_src, $bt_ff ) ) {
		foreach ( $bt_ff[1] as $bt_stack ) {
			$bt_first = trim( explode( ',', $bt_stack )[0], " \t'\"" );
			if ( '' !== $bt_first && ! str_starts_with( $bt_first, 'var(' ) ) {
				$bt_fonts[ $bt_first ] = ( $bt_fonts[ $bt_first ] ?? 0 ) + 1;
			}
		}
	}

	if ( preg_match_all( '/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $bt_src, $bt_cols ) ) {
		foreach ( $bt_cols[0] as $bt_col ) {
			$bt_up                = strtoupper( $bt_col );
			$bt_hex[ $bt_up ] = ( $bt_hex[ $bt_up ] ?? 0 ) + 1;
		}
	}
}

ksort( $bt_tokens );
arsort( $bt_fonts );
arsort( $bt_hex );

/* JSON ---------------------------------------------------------------- */

if ( $bt_json ) {
	$bt_flat = [];
	foreach ( $bt_tokens as $bt_key => $bt_values ) {
		$bt_flat[ $bt_key ] = array_keys( $bt_values );
	}
	echo json_encode(
		[
			'files'  => array_map( 'basename', $bt_files ),
			'tokens' => $bt_flat,
			'fonts'  => $bt_fonts,
			'colors' => array_slice( $bt_hex, 0, 30, true ),
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
	exit( 0 );
}

/* Report --------------------------------------------------------------- */

function bt_say( string $bt_msg = '' ): void {
	fwrite( STDOUT, $bt_msg . PHP_EOL );
}

function bt_head( string $bt_t ): void {
	bt_say();
	bt_say( $bt_t );
	bt_say( str_repeat( '-', strlen( $bt_t ) ) );
}

bt_say( count( $bt_files ) . ' mockup file(s) in ' . $bt_dir );

bt_head( 'Design tokens (:root)' );
if ( ! $bt_tokens ) {
	bt_say( '  none found — the backlog may keep its palette in a stylesheet or inline styles' );
}
$bt_conflicts = [];
foreach ( $bt_tokens as $bt_key => $bt_values ) {
	if ( count( $bt_values ) === 1 ) {
		bt_say( sprintf( '  %-26s %s', $bt_key, array_key_first( $bt_values ) ) );
	} else {
		$bt_conflicts[ $bt_key ] = $bt_values;
		bt_say( sprintf( '  %-26s DISAGREES between files', $bt_key ) );
		foreach ( $bt_values as $bt_val => $bt_in ) {
			bt_say( sprintf( '  %-26s   %-24s in %s', '', $bt_val, implode( ', ', array_unique( $bt_in ) ) ) );
		}
	}
}

if ( $bt_conflicts ) {
	bt_head( 'Decide these before building' );
	bt_say( '  ' . count( $bt_conflicts ) . ' token(s) have more than one value across the backlog.' );
	bt_say( '  Majority is not automatically right — the page context usually settles it.' );
	bt_say( '  Put them in a Decision Questionnaire rather than picking silently.' );
}

bt_head( 'Proposed Elementor Global Colors' );
$bt_color_tokens = array_filter(
	$bt_tokens,
	fn( $bt_v, $bt_k ) => (bool) preg_match( '/^(#|rgb|hsl)/i', (string) array_key_first( $bt_v ) ),
	ARRAY_FILTER_USE_BOTH
);
if ( $bt_color_tokens ) {
	foreach ( $bt_color_tokens as $bt_key => $bt_values ) {
		bt_say(
			sprintf(
				'  %-26s %-12s  slug: %s',
				$bt_key,
				array_key_first( $bt_values ),
				preg_replace( '/[^a-z0-9]/', '', strtolower( ltrim( $bt_key, '-' ) ) )
			)
		);
	}
} else {
	bt_say( '  no colour-valued tokens; the ten most frequent literal colours instead:' );
	foreach ( array_slice( $bt_hex, 0, 10, true ) as $bt_col => $bt_n ) {
		bt_say( sprintf( '  %-10s used %d time(s)', $bt_col, $bt_n ) );
	}
}

bt_head( 'Proposed Elementor Global Fonts' );
foreach ( array_slice( $bt_fonts, 0, 8, true ) as $bt_family => $bt_n ) {
	bt_say( sprintf( '  %-30s %d declaration(s)', $bt_family, $bt_n ) );
}

bt_head( 'Next' );
bt_say( '  1. Register these in Site Settings > Global Colors / Global Fonts.' );
bt_say( '  2. Bind widget settings to them: php globals-bind.php' );
bt_say( '  3. Keep the :root block in Custom CSS as well — ported component CSS cannot read' );
bt_say( '     Elementor globals. Globals are for widgets; :root is for the ported CSS.' );
