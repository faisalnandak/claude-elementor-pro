<?php
/**
 * el-widget-controls.php — print a widget's real controls.
 *
 *   php el-widget-controls.php                 list every registered widget
 *   php el-widget-controls.php loop-grid       every control: name, type, default, selectors
 *   php el-widget-controls.php button --grep=icon    only controls matching a substring
 *   php el-widget-controls.php icon-list --selectors only controls that print CSS
 *
 * Read this before configuring a widget from a script. Control names and shapes
 * are frequently not what the panel's labels suggest: a spacing box may be a
 * SLIDER (feeding it {top,right,bottom,left} leaves {{SIZE}} empty and no rule is
 * printed), a switcher is on for any non-empty value including 'none', and
 * typography controls sometimes print to a selector your markup never matches.
 *
 * The values come from Elementor's live control stack, not from parsing source,
 * so they are correct for the version installed here.
 *
 * Note for Elementor 4.x: style-tab controls live in a SEPARATE stack,
 * $stack['style_controls'], and the public get_controls() only merges them when
 * Performance::is_use_style_controls() says so. Outside the editor that is
 * usually false, so get_controls() silently omits every typography, colour and
 * spacing control. This script reads get_stack() and merges both.
 *
 * @package claude-elementor-pro
 * @license MIT
 */

require __DIR__ . '/elementor-lib.php';
el_boot();

if ( ! class_exists( '\Elementor\Plugin' ) ) {
	el_die( 'Elementor is not active on this install.' );
}

$el_argv   = el_args();
$el_name   = (string) ( $el_argv[0] ?? '' );
$el_grep   = '';
$el_only_s = false;
foreach ( $GLOBALS['argv'] as $el_arg ) {
	if ( str_starts_with( (string) $el_arg, '--grep=' ) ) {
		$el_grep = strtolower( substr( $el_arg, 7 ) );
	}
	if ( '--selectors' === $el_arg ) {
		$el_only_s = true;
	}
}

$el_manager = \Elementor\Plugin::$instance->widgets_manager;
$el_types   = $el_manager->get_widget_types();

if ( '' === $el_name ) {
	el_say( 'Registered widgets (' . count( $el_types ) . '):' );
	el_say();
	$el_names = array_keys( $el_types );
	sort( $el_names );
	foreach ( array_chunk( $el_names, 3 ) as $el_row ) {
		el_say( '  ' . implode( '', array_map( fn( $el_n ) => str_pad( $el_n, 30 ), $el_row ) ) );
	}
	el_say();
	el_say( 'Pass a name to see its controls, e.g. php el-widget-controls.php loop-grid' );
	exit( 0 );
}

$el_widget = $el_types[ $el_name ] ?? null;
if ( ! $el_widget ) {
	$el_near = array_values(
		array_filter( array_keys( $el_types ), fn( $el_k ) => false !== strpos( $el_k, $el_name ) )
	);
	el_say( "No widget named '{$el_name}'." );
	if ( $el_near ) {
		el_say( 'Did you mean: ' . implode( ', ', $el_near ) );
	}
	exit( 1 );
}

el_say( 'Widget   : ' . $el_widget->get_name() . '  (' . $el_widget->get_title() . ')' );
$el_ref = new ReflectionClass( $el_widget );
el_say( 'Class    : ' . $el_ref->getName() );
el_say( 'Source   : ' . str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $el_ref->getFileName() ) ) );

$el_stack    = $el_widget->get_stack();
$el_content  = $el_stack['controls'] ?? [];
$el_style    = $el_stack['style_controls'] ?? [];
$el_controls = $el_content + $el_style;
$el_shown    = 0;

el_say( 'Controls : ' . count( $el_content ) . ' content stack + ' . count( $el_style ) . ' style stack' );
el_say();

foreach ( $el_controls as $el_key => $el_ctrl ) {
	if ( '' !== $el_grep && false === strpos( strtolower( $el_key ), $el_grep ) ) {
		continue;
	}
	if ( $el_only_s && empty( $el_ctrl['selectors'] ) ) {
		continue;
	}

	$el_type = $el_ctrl['type'] ?? '?';

	if ( 'section' === $el_type ) {
		el_say();
		el_say( '=== ' . ( $el_ctrl['label'] ?? $el_key ) . '  [' . ( $el_ctrl['tab'] ?? '' ) . ']' );
		continue;
	}
	if ( in_array( $el_type, [ 'tabs', 'tab' ], true ) ) {
		continue;
	}

	$el_shown++;

	$el_line = sprintf( '  %-34s %-14s', $el_key, $el_type );

	if ( array_key_exists( 'default', $el_ctrl ) && '' !== $el_ctrl['default'] && null !== $el_ctrl['default'] ) {
		$el_line .= ' default=' . el_compact( $el_ctrl['default'] );
	}
	if ( ! empty( $el_ctrl['options'] ) && is_array( $el_ctrl['options'] ) ) {
		$el_line .= ' options=' . implode( '|', array_slice( array_keys( $el_ctrl['options'] ), 0, 8 ) );
	}
	if ( ! empty( $el_ctrl['return_value'] ) ) {
		$el_line .= ' return_value=' . $el_ctrl['return_value'];
	}
	if ( ! empty( $el_ctrl['condition'] ) ) {
		$el_line .= ' when ' . el_compact( $el_ctrl['condition'] );
	}

	el_say( $el_line );

	if ( ! empty( $el_ctrl['selectors'] ) && is_array( $el_ctrl['selectors'] ) ) {
		foreach ( $el_ctrl['selectors'] as $el_sel => $el_decl ) {
			el_say( '        -> ' . str_replace( '{{WRAPPER}}', '', $el_sel ) . ' { ' . $el_decl . ' }' );
		}
	}
}

el_say();
el_say( $el_shown . ' control(s).' );
el_say();
el_say( 'Reminders: SLIDER wants {unit,size}, DIMENSIONS wants {top,right,bottom,left,unit};' );
el_say( 'responsive controls add _tablet and _mobile suffixes; a switcher is on for ANY' );
el_say( 'non-empty value; and a selector using ">" only reaches a DIRECT child, so any extra' );
el_say( 'wrapper in your markup means the control prints CSS that never matches.' );

/** One-line rendering of a control value. */
function el_compact( $el_value ): string {
	if ( is_scalar( $el_value ) ) {
		return (string) $el_value;
	}
	$el_json = json_encode( $el_value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return mb_strlen( (string) $el_json ) > 90 ? mb_substr( (string) $el_json, 0, 87 ) . '...' : (string) $el_json;
}
