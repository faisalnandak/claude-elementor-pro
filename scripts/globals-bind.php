<?php
/**
 * globals-bind.php — bind literal colours in widget settings to Global Colors.
 *
 *   php globals-bind.php               dry run: what would bind, to which global
 *   php globals-bind.php apply
 *   php globals-bind.php restore
 *   php globals-bind.php --list        registered globals only
 *   php globals-bind.php --doc=19271   one document
 *
 * A widget that stores `#113A98` renders the same as one bound to the global
 * that holds `#113A98` — until the brand colour changes, at which point the
 * literal is one of hundreds of edits and the binding is none.
 *
 * Only settings whose value is an exact hex match are touched, and a setting
 * that already has a `__globals__` entry is left alone. Remember that a
 * `__globals__` binding beats a literal: after this runs, writing a literal
 * colour to a bound setting has no effect.
 *
 * @package claude-elementor-pro
 * @license MIT
 */

require __DIR__ . '/elementor-lib.php';
el_boot();

const GB_TOOL = 'globals-bind';

$gb_mode = el_mode();
$gb_only = 0;
$gb_list = false;
foreach ( $GLOBALS['argv'] as $gb_arg ) {
	if ( str_starts_with( (string) $gb_arg, '--doc=' ) ) {
		$gb_only = (int) substr( $gb_arg, 6 );
	}
	if ( '--list' === $gb_arg ) {
		$gb_list = true;
	}
}

/* Registered globals --------------------------------------------------- */

$gb_kit      = el_kit_id();
$gb_settings = get_post_meta( $gb_kit, '_elementor_page_settings', true );
if ( is_string( $gb_settings ) && '' !== $gb_settings ) {
	$gb_settings = json_decode( $gb_settings, true );
}
$gb_settings = is_array( $gb_settings ) ? $gb_settings : [];

$gb_globals = [];   // uppercase hex => [ id, title ]
foreach ( [ 'system_colors', 'custom_colors' ] as $gb_group ) {
	foreach ( (array) ( $gb_settings[ $gb_group ] ?? [] ) as $gb_color ) {
		$gb_hex = strtoupper( trim( (string) ( $gb_color['color'] ?? '' ) ) );
		if ( '' === $gb_hex || ! isset( $gb_color['_id'] ) ) {
			continue;
		}
		if ( ! isset( $gb_globals[ $gb_hex ] ) ) {
			$gb_globals[ $gb_hex ] = [
				'id'    => (string) $gb_color['_id'],
				'title' => (string) ( $gb_color['title'] ?? $gb_color['_id'] ),
				'group' => $gb_group,
			];
		}
	}
}

el_say( 'Kit #' . $gb_kit . ' registers ' . count( $gb_globals ) . ' distinct global colour(s):' );
foreach ( $gb_globals as $gb_hex => $gb_g ) {
	el_say( sprintf( '  %-9s %-22s id=%s  (%s)', $gb_hex, $gb_g['title'], $gb_g['id'], $gb_g['group'] ) );
}
el_say();

if ( $gb_list ) {
	exit( 0 );
}
if ( ! $gb_globals ) {
	el_die( 'No global colours registered. Register the backlog palette first (php backlog-tokens.php).' );
}

/* Restore -------------------------------------------------------------- */

if ( 'restore' === $gb_mode ) {
	$gb_done = el_restore_all( GB_TOOL );
	el_say( $gb_done ? 'Restored ' . count( $gb_done ) . ' document(s): ' . implode( ', ', $gb_done ) : 'Nothing to restore.' );
	exit( 0 );
}

/* Scan ----------------------------------------------------------------- */

$gb_docs    = $gb_only ? [ $gb_only ] : el_documents();
$gb_plan    = [];   // doc id => list of [element, setting, hex, global]
$gb_total   = 0;
$gb_skipped = 0;

foreach ( $gb_docs as $gb_id ) {
	if ( $gb_id === $gb_kit ) {
		continue;   // the kit holds the palette itself
	}
	$gb_doc  = el_load( $gb_id );
	$gb_hits = [];

	el_walk(
		$gb_doc,
		function ( &$gb_el ) use ( $gb_globals, &$gb_hits, &$gb_skipped ) {
			$gb_set = $gb_el['settings'] ?? [];
			if ( ! is_array( $gb_set ) ) {
				return;
			}
			$gb_bound = $gb_set['__globals__'] ?? [];

			foreach ( $gb_set as $gb_key => $gb_val ) {
				if ( ! is_string( $gb_val ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $gb_val ) ) {
					continue;
				}
				if ( isset( $gb_bound[ $gb_key ] ) && '' !== $gb_bound[ $gb_key ] ) {
					$gb_skipped++;   // already bound; the binding wins anyway
					continue;
				}
				$gb_hex = strtoupper( $gb_val );
				if ( ! isset( $gb_globals[ $gb_hex ] ) ) {
					continue;
				}
				$gb_hits[] = [
					'element' => ( 'widget' === ( $gb_el['elType'] ?? '' ) ? ( $gb_el['widgetType'] ?? '?' ) : ( $gb_el['elType'] ?? '?' ) )
						. ' #' . ( $gb_el['id'] ?? '?' ),
					'setting' => $gb_key,
					'hex'     => $gb_hex,
					'global'  => $gb_globals[ $gb_hex ],
				];
			}
		}
	);

	if ( $gb_hits ) {
		$gb_plan[ $gb_id ] = $gb_hits;
		$gb_total         += count( $gb_hits );
	}
}

if ( ! $gb_total ) {
	el_say( 'Nothing to bind. ' . $gb_skipped . ' setting(s) are already bound to a global.' );
	exit( 0 );
}

el_say( $gb_total . ' literal colour setting(s) in ' . count( $gb_plan ) . ' document(s) match a registered global.' );
el_say( $gb_skipped . ' setting(s) already bound, left alone.' );
el_say();

foreach ( $gb_plan as $gb_id => $gb_hits ) {
	el_say( '  ' . el_label( $gb_id ) );
	$gb_by_global = [];
	foreach ( $gb_hits as $gb_hit ) {
		$gb_by_global[ $gb_hit['global']['title'] ] = ( $gb_by_global[ $gb_hit['global']['title'] ] ?? 0 ) + 1;
	}
	foreach ( $gb_by_global as $gb_title => $gb_n ) {
		el_say( sprintf( '      %-24s %d setting(s)', $gb_title, $gb_n ) );
	}
}
el_say();

if ( 'apply' !== $gb_mode ) {
	el_say( 'Dry run. Re-run with "apply" to bind; "restore" rolls every document back.' );
	el_say();
	el_say( 'Before applying, capture the colour fingerprint of two or three representative' );
	el_say( 'pages (see skills/visual-verify). Rendering must be byte-identical afterwards.' );
	exit( 0 );
}

/* Apply ---------------------------------------------------------------- */

$gb_written = 0;
foreach ( $gb_plan as $gb_id => $gb_hits ) {
	$gb_doc = el_load( $gb_id );

	el_walk(
		$gb_doc,
		function ( &$gb_el ) use ( $gb_globals ) {
			$gb_set = $gb_el['settings'] ?? [];
			if ( ! is_array( $gb_set ) ) {
				return;
			}
			foreach ( $gb_set as $gb_key => $gb_val ) {
				if ( ! is_string( $gb_val ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $gb_val ) ) {
					continue;
				}
				if ( isset( $gb_el['settings']['__globals__'][ $gb_key ] ) && '' !== $gb_el['settings']['__globals__'][ $gb_key ] ) {
					continue;
				}
				$gb_hex = strtoupper( $gb_val );
				if ( ! isset( $gb_globals[ $gb_hex ] ) ) {
					continue;
				}
				$gb_el['settings']['__globals__'][ $gb_key ] = 'globals/colors?id=' . $gb_globals[ $gb_hex ]['id'];
			}
		}
	);

	el_save( $gb_id, $gb_doc, GB_TOOL );
	$gb_written++;
}

el_flush();

el_say( "Bound {$gb_total} setting(s) across {$gb_written} document(s)." );
el_say();
el_say( 'Verify now:' );
el_say( '  - colour fingerprint on the same pages as before — must be identical' );
el_say( '  - screenshots at 1280 / 768 / 390' );
el_say( '  - php site-sweep.php' );
el_say();
el_say( 'Counting bindings afterwards: JSON stores the tag as globals\\/colors, so a naive' );
el_say( 'grep for "globals/colors" reports zero and looks like data loss. Decode first.' );
