<?php
/**
 * el-inspect.php — read-only survey of a WordPress + Elementor Pro install.
 *
 *   php el-inspect.php env               environment, plugins, post types, templates
 *   php el-inspect.php docs              every document that carries Elementor data
 *   php el-inspect.php tree <id>         element tree of one document
 *   php el-inspect.php settings <id> <element-id>   full settings of one element
 *   php el-inspect.php find <css-class>  every element carrying a CSS class
 *   php el-inspect.php widgets           widget types in use, with counts
 *   php el-inspect.php css               every place Custom CSS is stored, with sizes
 *   php el-inspect.php conditions        Theme Builder templates and their conditions
 *
 * Writes nothing. Add --wp=/path/to/wordpress if run from outside the site.
 *
 * @package claude-elementor-pro
 * @license MIT
 */

require __DIR__ . '/elementor-lib.php';
el_boot();

$el_argv = el_args();
$el_cmd  = strtolower( (string) ( $el_argv[0] ?? 'env' ) );

switch ( $el_cmd ) {
	case 'env':
		el_cmd_env();
		break;
	case 'docs':
		el_cmd_docs();
		break;
	case 'tree':
		el_cmd_tree( (int) ( $el_argv[1] ?? 0 ) );
		break;
	case 'settings':
		el_cmd_settings( (int) ( $el_argv[1] ?? 0 ), (string) ( $el_argv[2] ?? '' ) );
		break;
	case 'find':
		el_cmd_find( (string) ( $el_argv[1] ?? '' ) );
		break;
	case 'widgets':
		el_cmd_widgets();
		break;
	case 'css':
		el_cmd_css();
		break;
	case 'conditions':
		el_cmd_conditions();
		break;
	default:
		el_die( "Unknown command '{$el_cmd}'. Run without arguments for the environment survey." );
}

/* ------------------------------------------------------------------ */

function el_head( string $el_title ): void {
	el_say();
	el_say( $el_title );
	el_say( str_repeat( '-', strlen( $el_title ) ) );
}

function el_cmd_env(): void {
	global $wp_version;

	el_head( 'Environment' );
	el_say( 'WordPress      : ' . $wp_version );
	el_say( 'PHP            : ' . PHP_VERSION );
	el_say( 'Site URL       : ' . get_site_url() );
	el_say( 'Multisite      : ' . ( is_multisite() ? 'yes' : 'no' ) );

	$el_theme = wp_get_theme();
	el_say( 'Theme          : ' . $el_theme->get( 'Name' ) . ' ' . $el_theme->get( 'Version' ) );
	if ( $el_theme->parent() ) {
		el_say( 'Parent theme   : ' . $el_theme->parent()->get( 'Name' ) );
	}

	el_head( 'Elementor' );
	el_say( 'Elementor      : ' . ( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'NOT ACTIVE' ) );
	el_say( 'Elementor Pro  : ' . ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : 'NOT ACTIVE  <- no Theme Builder, no Loop Grid, no dynamic tags' ) );
	el_say( 'Active kit     : #' . el_kit_id() );

	$el_bp = get_option( 'elementor_experiment-additional_custom_breakpoints' );
	el_say( 'Breakpoints    : tablet <=1024, mobile <=767' . ( 'active' === $el_bp ? ' (+ custom breakpoints experiment ON)' : '' ) );

	el_head( 'Active plugins' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	foreach ( get_option( 'active_plugins', [] ) as $el_plugin ) {
		$el_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $el_plugin, false, false );
		el_say( sprintf( '  %-40s %s', $el_data['Name'] ?: $el_plugin, $el_data['Version'] ) );
	}
	el_say( '  ACF            : ' . ( class_exists( 'ACF' ) ? 'available (derived fields possible at ladder rung 5)' : 'not present' ) );
	el_say( '  WooCommerce    : ' . ( class_exists( 'WooCommerce' ) ? 'available' : 'not present' ) );

	el_head( 'Public post types' );
	foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $el_pt ) {
		$el_count = wp_count_posts( $el_pt->name );
		el_say( sprintf( '  %-20s %-28s published: %d', $el_pt->name, $el_pt->label, (int) ( $el_count->publish ?? 0 ) ) );
	}

	el_head( 'Public taxonomies' );
	foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $el_tax ) {
		el_say( sprintf( '  %-20s on %s', $el_tax->name, implode( ', ', $el_tax->object_type ) ) );
	}

	el_cmd_conditions();

	el_head( 'Next' );
	el_say( 'Map every backlog file to the template that actually renders its URL.' );
	el_say( 'Confirm by loading the URL and reading the root class, e.g. elementor-19271.' );
}

function el_cmd_conditions(): void {
	el_head( 'Theme Builder templates' );

	$el_posts = get_posts(
		[
			'post_type'      => 'elementor_library',
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		]
	);

	if ( ! $el_posts ) {
		el_say( '  (none)' );
		return;
	}

	foreach ( $el_posts as $el_post ) {
		$el_type = get_post_meta( $el_post->ID, '_elementor_template_type', true );
		$el_cond = get_post_meta( $el_post->ID, '_elementor_conditions', true );
		el_say(
			sprintf(
				'  #%-6d %-14s %-38s %s%s',
				$el_post->ID,
				$el_type ?: '?',
				mb_substr( $el_post->post_title ?: '(no title)', 0, 38 ),
				is_array( $el_cond ) && $el_cond ? implode( ' | ', $el_cond ) : '(no condition)',
				'publish' === $el_post->post_status ? '' : '  [' . $el_post->post_status . ']'
			)
		);
	}

	el_say();
	el_say( '  Two templates matching the same URL is a common cause of "my change did nothing".' );
}

function el_cmd_docs(): void {
	el_head( 'Documents with Elementor data' );
	foreach ( el_documents() as $el_id ) {
		$el_doc  = el_load( $el_id );
		$el_size = strlen( (string) get_post_meta( $el_id, '_elementor_data', true ) );
		$el_n    = 0;
		el_walk( $el_doc, function () use ( &$el_n ) { $el_n++; } );
		el_say( sprintf( '  %-52s %5d elements  %7d bytes', el_label( $el_id ), $el_n, $el_size ) );
	}
}

function el_cmd_tree( int $el_id ): void {
	if ( ! $el_id ) {
		el_die( 'Usage: php el-inspect.php tree <post-id>' );
	}
	$el_doc = el_load( $el_id );
	if ( ! $el_doc ) {
		el_die( 'No Elementor data on ' . el_label( $el_id ) );
	}

	el_head( 'Tree of ' . el_label( $el_id ) );
	el_tree_print( $el_doc, 0 );
}

function el_tree_print( array $el_els, int $el_depth ): void {
	foreach ( $el_els as $el_node ) {
		if ( ! is_array( $el_node ) ) {
			continue;
		}
		$el_type = $el_node['elType'] ?? '?';
		$el_name = 'widget' === $el_type ? ( $el_node['widgetType'] ?? '?' ) : $el_type;

		$el_classes = $el_node['settings']['_css_classes'] ?? ( $el_node['settings']['css_classes'] ?? '' );
		if ( is_array( $el_classes ) ) {
			$el_classes = implode( ' ', $el_classes );
		}

		$el_note = [];
		if ( $el_classes ) {
			$el_note[] = '.' . str_replace( ' ', ' .', trim( (string) $el_classes ) );
		}
		if ( ! empty( $el_node['settings']['__globals__'] ) ) {
			$el_note[] = 'globals:' . implode( ',', array_keys( $el_node['settings']['__globals__'] ) );
		}
		if ( ! empty( $el_node['settings']['__dynamic__'] ) ) {
			$el_note[] = 'dynamic:' . implode( ',', array_keys( $el_node['settings']['__dynamic__'] ) );
		}
		if ( ! empty( $el_node['settings']['custom_css'] ) ) {
			$el_note[] = 'element-css:' . strlen( (string) $el_node['settings']['custom_css'] ) . 'b';
		}
		$el_title = $el_node['settings']['title'] ?? ( $el_node['settings']['text'] ?? '' );
		if ( is_string( $el_title ) && '' !== trim( $el_title ) ) {
			$el_note[] = '"' . mb_substr( trim( wp_strip_all_tags( $el_title ) ), 0, 40 ) . '"';
		}

		el_say(
			str_repeat( '  ', $el_depth )
			. sprintf( '%-24s %-10s %s', $el_name, $el_node['id'] ?? '', implode( '  ', $el_note ) )
		);

		if ( ! empty( $el_node['elements'] ) && is_array( $el_node['elements'] ) ) {
			el_tree_print( $el_node['elements'], $el_depth + 1 );
		}
	}
}

function el_cmd_settings( int $el_id, string $el_target ): void {
	if ( ! $el_id || '' === $el_target ) {
		el_die( 'Usage: php el-inspect.php settings <post-id> <element-id>' );
	}
	$el_doc   = el_load( $el_id );
	$el_found = null;
	el_walk(
		$el_doc,
		function ( &$el_node ) use ( $el_target, &$el_found ) {
			if ( ( $el_node['id'] ?? '' ) === $el_target ) {
				$el_found = $el_node;
			}
		}
	);
	if ( null === $el_found ) {
		el_die( "Element '{$el_target}' not found in " . el_label( $el_id ) );
	}
	unset( $el_found['elements'] );
	el_say( json_encode( $el_found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

function el_cmd_find( string $el_class ): void {
	if ( '' === $el_class ) {
		el_die( 'Usage: php el-inspect.php find <css-class>' );
	}
	el_head( "Elements carrying .{$el_class}" );
	$el_total = 0;
	foreach ( el_documents() as $el_id ) {
		$el_doc  = el_load( $el_id );
		$el_hits = [];
		el_walk(
			$el_doc,
			function ( &$el_node ) use ( $el_class, &$el_hits ) {
				if ( el_has_class( $el_node, $el_class ) ) {
					$el_hits[] = ( 'widget' === ( $el_node['elType'] ?? '' ) ? ( $el_node['widgetType'] ?? '?' ) : ( $el_node['elType'] ?? '?' ) )
						. ' #' . ( $el_node['id'] ?? '?' );
				}
			}
		);
		if ( $el_hits ) {
			$el_total += count( $el_hits );
			el_say( '  ' . el_label( $el_id ) );
			foreach ( $el_hits as $el_hit ) {
				el_say( '      ' . $el_hit );
			}
		}
	}
	el_say();
	el_say( "  {$el_total} element(s)." );
	if ( 0 === $el_total ) {
		el_say( '  A class used by no element means any CSS targeting it is dead — but check' );
		el_say( '  markup written inside Text Editor widgets and Custom Code snippets too.' );
	}
}

function el_cmd_widgets(): void {
	el_head( 'Widget types in use' );
	$el_counts = [];
	foreach ( el_documents() as $el_id ) {
		$el_doc = el_load( $el_id );
		el_walk(
			$el_doc,
			function ( &$el_node ) use ( &$el_counts ) {
				$el_key = 'widget' === ( $el_node['elType'] ?? '' )
					? ( $el_node['widgetType'] ?? '?' )
					: ( $el_node['elType'] ?? '?' );
				$el_counts[ $el_key ] = ( $el_counts[ $el_key ] ?? 0 ) + 1;
			}
		);
	}
	arsort( $el_counts );
	foreach ( $el_counts as $el_key => $el_n ) {
		el_say( sprintf( '  %-32s %5d', $el_key, $el_n ) );
	}
}

function el_cmd_css(): void {
	el_head( 'Custom CSS locations' );

	$el_kit  = el_kit_id();
	$el_grand = 0;

	$el_kit_css = el_css_get( $el_kit );
	$el_grand  += strlen( $el_kit_css );
	el_say( sprintf( '  %-52s %7d bytes  (Site Settings)', el_label( $el_kit ), strlen( $el_kit_css ) ) );

	foreach ( el_documents() as $el_id ) {
		if ( $el_id === $el_kit ) {
			continue;
		}
		$el_page = el_css_get( $el_id );
		if ( '' !== trim( $el_page ) ) {
			$el_grand += strlen( $el_page );
			el_say( sprintf( '  %-52s %7d bytes  (page settings)', el_label( $el_id ), strlen( $el_page ) ) );
		}

		$el_doc = el_load( $el_id );
		el_walk(
			$el_doc,
			function ( &$el_node ) use ( $el_id, &$el_grand ) {
				$el_css = $el_node['settings']['custom_css'] ?? '';
				if ( is_string( $el_css ) && '' !== trim( $el_css ) ) {
					$el_grand += strlen( $el_css );
					el_say(
						sprintf(
							'  %-52s %7d bytes  (element %s #%s)',
							el_label( $el_id ),
							strlen( $el_css ),
							'widget' === ( $el_node['elType'] ?? '' ) ? ( $el_node['widgetType'] ?? '?' ) : ( $el_node['elType'] ?? '?' ),
							$el_node['id'] ?? '?'
						)
					);
				}
			}
		);
	}

	$el_snippets = get_posts(
		[
			'post_type'      => 'elementor_snippet',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
		]
	);
	foreach ( $el_snippets as $el_snip ) {
		$el_code = (string) get_post_meta( $el_snip->ID, '_elementor_code', true );
		el_say(
			sprintf(
				'  #%-6d %-45s %7d bytes  (Custom Code, %s)%s',
				$el_snip->ID,
				mb_substr( $el_snip->post_title, 0, 45 ),
				strlen( $el_code ),
				get_post_meta( $el_snip->ID, '_elementor_location', true ) ?: 'no location',
				'publish' === $el_snip->post_status ? '' : '  [' . $el_snip->post_status . ']'
			)
		);
	}

	el_say();
	el_say( sprintf( '  %d bytes of Custom CSS in total (snippets not counted).', $el_grand ) );
	el_say( '  Duplicate snippet titles usually mean an old copy is still published and running twice.' );
}
