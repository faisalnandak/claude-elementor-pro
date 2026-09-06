<?php
/**
 * elementor-lib.php — shared helpers for Elementor Pro maintenance scripts.
 *
 * Load WordPress, read and write `_elementor_data` safely, keep per-document
 * backups, survive the autosave trap, and manage marked Custom CSS blocks.
 *
 * Usage from a tool script:
 *
 *   require __DIR__ . '/elementor-lib.php';
 *   el_boot();
 *   $mode = el_mode();                       // 'dry' | 'apply' | 'restore'
 *   $doc  = el_load( 19271 );
 *   el_walk( $doc, function ( &$el ) { ... } );
 *   el_save( 19271, $doc, 'my-tool' );       // backup + slash + autosave fix + cache flush
 *
 * Every function is prefixed `el_` and every internal variable `$el_*` on
 * purpose: a CLI script shares global scope with WordPress's own globals, and
 * clobbering `$acf`, `$wp` or `$post` produces errors far from their cause.
 *
 * @package claude-elementor-pro
 * @license MIT
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "CLI only.\n" );
}

/* ------------------------------------------------------------------ */
/* Bootstrap                                                           */
/* ------------------------------------------------------------------ */

/**
 * Locate and load wp-load.php.
 *
 * Order: --wp=PATH on the command line, then WP_ROOT in the environment, then
 * a walk upwards from the current directory and from this file.
 */
function el_boot( ?string $el_root = null ): void {
	if ( defined( 'ABSPATH' ) ) {
		return;
	}

	if ( null === $el_root ) {
		foreach ( $GLOBALS['argv'] ?? [] as $el_arg ) {
			if ( str_starts_with( (string) $el_arg, '--wp=' ) ) {
				$el_root = substr( $el_arg, 5 );
			}
		}
	}
	if ( null === $el_root && getenv( 'WP_ROOT' ) ) {
		$el_root = getenv( 'WP_ROOT' );
	}

	$el_candidates = [];
	if ( $el_root ) {
		$el_candidates[] = rtrim( $el_root, '/\\' ) . '/wp-load.php';
	}
	foreach ( [ getcwd(), __DIR__ ] as $el_start ) {
		$el_dir = $el_start;
		for ( $el_i = 0; $el_i < 8; $el_i++ ) {
			$el_candidates[] = $el_dir . '/wp-load.php';
			$el_parent       = dirname( $el_dir );
			if ( $el_parent === $el_dir ) {
				break;
			}
			$el_dir = $el_parent;
		}
	}

	foreach ( $el_candidates as $el_file ) {
		if ( is_file( $el_file ) ) {
			define( 'WP_USE_THEMES', false );
			require_once $el_file;
			return;
		}
	}

	fwrite( STDERR, "wp-load.php not found. Pass --wp=/path/to/wordpress or set WP_ROOT.\n" );
	exit( 1 );
}

/**
 * Read the run mode from the command line.
 *
 * Dry run is the default so that running the wrong file changes nothing.
 */
function el_mode(): string {
	foreach ( array_slice( $GLOBALS['argv'] ?? [], 1 ) as $el_arg ) {
		$el_arg = strtolower( (string) $el_arg );
		if ( in_array( $el_arg, [ 'apply', 'restore', 'pulihkan' ], true ) ) {
			return 'pulihkan' === $el_arg ? 'restore' : $el_arg;
		}
	}
	return 'dry';
}

/** Positional arguments, with modes and --flags removed. */
function el_args(): array {
	$el_out = [];
	foreach ( array_slice( $GLOBALS['argv'] ?? [], 1 ) as $el_arg ) {
		if ( str_starts_with( (string) $el_arg, '--' ) ) {
			continue;
		}
		if ( in_array( strtolower( (string) $el_arg ), [ 'apply', 'restore', 'pulihkan', 'dry' ], true ) ) {
			continue;
		}
		$el_out[] = $el_arg;
	}
	return $el_out;
}

function el_say( string $el_msg = '' ): void {
	fwrite( STDOUT, $el_msg . PHP_EOL );
}

function el_die( string $el_msg ): void {
	fwrite( STDERR, $el_msg . PHP_EOL );
	exit( 1 );
}

/* ------------------------------------------------------------------ */
/* Documents                                                           */
/* ------------------------------------------------------------------ */

/** Decoded `_elementor_data` for a document, or [] when there is none. */
function el_load( int $el_id ): array {
	$el_raw = get_post_meta( $el_id, '_elementor_data', true );
	if ( is_array( $el_raw ) ) {
		return $el_raw;
	}
	if ( ! is_string( $el_raw ) || '' === $el_raw ) {
		return [];
	}
	$el_data = json_decode( $el_raw, true );
	return is_array( $el_data ) ? $el_data : [];
}

/**
 * Every post that carries Elementor data.
 *
 * @return int[] post IDs
 */
function el_documents(): array {
	global $wpdb;
	$el_ids = $wpdb->get_col(
		"SELECT pm.post_id
		   FROM {$wpdb->postmeta} pm
		   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		  WHERE pm.meta_key = '_elementor_data'
		    AND p.post_status IN ('publish','draft','private','inherit')
		    AND p.post_type <> 'revision'
		  ORDER BY pm.post_id"
	);
	return array_map( 'intval', $el_ids );
}

/** The Elementor kit (Site Settings) post ID. */
function el_kit_id(): int {
	return (int) get_option( 'elementor_active_kit' );
}

/**
 * Walk every element in a document, depth first, by reference.
 *
 * The callback receives each element as a reference and may modify it in place.
 * It must not add or remove siblings — use el_path()/el_ref() for that.
 */
function el_walk( array &$el_els, callable $el_fn, array $el_path = [] ): void {
	foreach ( $el_els as $el_i => &$el_node ) {
		if ( ! is_array( $el_node ) ) {
			continue;
		}
		$el_fn( $el_node, array_merge( $el_path, [ $el_i ] ) );
		if ( ! empty( $el_node['elements'] ) && is_array( $el_node['elements'] ) ) {
			el_walk( $el_node['elements'], $el_fn, array_merge( $el_path, [ $el_i, 'elements' ] ) );
		}
	}
	unset( $el_node );
}

/**
 * Index path to the first element matching a predicate, or null.
 *
 * Paths are used instead of references because a by-reference parameter cannot
 * be rebound inside a function without breaking the caller's link — the usual
 * symptom being "array_splice(): Argument #1 must be of type array, null given".
 */
function el_path( array $el_els, callable $el_pred, array $el_base = [] ): ?array {
	foreach ( $el_els as $el_i => $el_node ) {
		if ( ! is_array( $el_node ) ) {
			continue;
		}
		$el_here = array_merge( $el_base, [ $el_i ] );
		if ( $el_pred( $el_node ) ) {
			return $el_here;
		}
		if ( ! empty( $el_node['elements'] ) && is_array( $el_node['elements'] ) ) {
			$el_deep = el_path( $el_node['elements'], $el_pred, array_merge( $el_here, [ 'elements' ] ) );
			if ( null !== $el_deep ) {
				return $el_deep;
			}
		}
	}
	return null;
}

/** All index paths matching a predicate. */
function el_paths( array $el_els, callable $el_pred, array $el_base = [] ): array {
	$el_out = [];
	foreach ( $el_els as $el_i => $el_node ) {
		if ( ! is_array( $el_node ) ) {
			continue;
		}
		$el_here = array_merge( $el_base, [ $el_i ] );
		if ( $el_pred( $el_node ) ) {
			$el_out[] = $el_here;
		}
		if ( ! empty( $el_node['elements'] ) && is_array( $el_node['elements'] ) ) {
			$el_out = array_merge( $el_out, el_paths( $el_node['elements'], $el_pred, array_merge( $el_here, [ 'elements' ] ) ) );
		}
	}
	return $el_out;
}

/** Reference to the element (or container array) at an index path. */
function &el_ref( array &$el_doc, array $el_path ) {
	$el_ref = &$el_doc;
	foreach ( $el_path as $el_key ) {
		$el_ref = &$el_ref[ $el_key ];
	}
	return $el_ref;
}

/** True when an element carries a CSS class, checking the key Elementor reads. */
function el_has_class( array $el_node, string $el_class ): bool {
	foreach ( [ '_css_classes', 'css_classes' ] as $el_key ) {
		$el_val = $el_node['settings'][ $el_key ] ?? '';
		if ( is_array( $el_val ) ) {
			$el_val = implode( ' ', $el_val );
		}
		if ( '' !== $el_val && in_array( $el_class, preg_split( '/\s+/', trim( (string) $el_val ) ), true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Set CSS classes on an element.
 *
 * Writes both keys: Elementor's Advanced > CSS Classes control is `_css_classes`
 * with a leading underscore, and writing only `css_classes` silently does
 * nothing.
 */
function el_set_class( array &$el_node, string $el_classes ): void {
	$el_node['settings']['_css_classes'] = $el_classes;
	$el_node['settings']['css_classes']  = $el_classes;
}

/* ------------------------------------------------------------------ */
/* Backup and save                                                     */
/* ------------------------------------------------------------------ */

function el_backup_key( string $el_tool ): string {
	return '_el_backup_' . preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $el_tool ) );
}

/**
 * Store the current `_elementor_data` for one document, once per tool.
 *
 * Per document, in post meta, and written before the change — a single
 * site-wide option overflows MySQL's max_allowed_packet and fails silently.
 * The metadata_exists() guard means re-running apply never overwrites the
 * original state with an already-modified one.
 */
function el_backup( int $el_id, string $el_tool ): void {
	$el_key = el_backup_key( $el_tool );
	if ( ! metadata_exists( 'post', $el_id, $el_key ) ) {
		update_post_meta( $el_id, $el_key, get_post_meta( $el_id, '_elementor_data', true ) );
	}
}

/** Restore one document from this tool's backup. Returns true when restored. */
function el_restore( int $el_id, string $el_tool, bool $el_keep = false ): bool {
	$el_key = el_backup_key( $el_tool );
	if ( ! metadata_exists( 'post', $el_id, $el_key ) ) {
		return false;
	}
	$el_old = get_post_meta( $el_id, $el_key, true );
	update_post_meta( $el_id, '_elementor_data', wp_slash( is_array( $el_old ) ? wp_json_encode( $el_old ) : (string) $el_old ) );
	if ( ! $el_keep ) {
		delete_post_meta( $el_id, $el_key );
	}
	el_autosave_fix( $el_id );
	return true;
}

/** Every document this tool has a backup for. */
function el_restore_all( string $el_tool ): array {
	global $wpdb;
	$el_key = el_backup_key( $el_tool );
	$el_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", $el_key ) );
	$el_done = [];
	foreach ( $el_ids as $el_id ) {
		if ( el_restore( (int) $el_id, $el_tool ) ) {
			$el_done[] = (int) $el_id;
		}
	}
	el_flush();
	return $el_done;
}

/**
 * Write a document back: backup, slash, save, defeat the autosave, flush CSS.
 *
 * wp_slash() matters — without it wp_unslash() on the way in eats the JSON's
 * backslashes and the document becomes invalid.
 */
function el_save( int $el_id, array $el_doc, string $el_tool ): void {
	el_backup( $el_id, $el_tool );
	update_post_meta( $el_id, '_elementor_data', wp_slash( wp_json_encode( $el_doc ) ) );
	el_autosave_fix( $el_id );
	el_flush();
}

/**
 * Defeat the autosave trap for one document.
 *
 * update_post_meta() does not touch post_modified, so Elementor keeps loading
 * a newer autosave revision and the editor silently reverts the change the next
 * time it is opened. Bump the timestamp and drop the stale autosave.
 */
function el_autosave_fix( int $el_id ): void {
	$el_now = current_time( 'mysql' );
	wp_update_post(
		[
			'ID'                => $el_id,
			'post_modified'     => $el_now,
			'post_modified_gmt' => get_gmt_from_date( $el_now ),
		]
	);

	global $wpdb;
	$el_autosaves = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' AND post_name LIKE %s",
			$el_id,
			$el_id . '-autosave%'
		)
	);
	foreach ( $el_autosaves as $el_rev ) {
		wp_delete_post_revision( (int) $el_rev );
	}
}

/** Flush Elementor's generated CSS so panel settings reach a served stylesheet. */
function el_flush(): void {
	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}

/** Rebuild Theme Builder condition matching after writing `_elementor_conditions`. */
function el_rebuild_conditions(): bool {
	if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
		return false;
	}
	$el_mod = \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'theme-builder' );
	if ( ! $el_mod ) {
		return false;
	}
	$el_mod->get_conditions_manager()->get_cache()->regenerate();
	return true;
}

/* ------------------------------------------------------------------ */
/* Custom CSS                                                          */
/* ------------------------------------------------------------------ */

/** Custom CSS of a document (pass el_kit_id() for Site Settings). */
function el_css_get( int $el_id ): string {
	$el_settings = get_post_meta( $el_id, '_elementor_page_settings', true );
	if ( is_string( $el_settings ) && '' !== $el_settings ) {
		$el_settings = json_decode( $el_settings, true );
	}
	return is_array( $el_settings ) ? (string) ( $el_settings['custom_css'] ?? '' ) : '';
}

function el_css_set( int $el_id, string $el_css ): void {
	$el_settings = get_post_meta( $el_id, '_elementor_page_settings', true );
	if ( is_string( $el_settings ) && '' !== $el_settings ) {
		$el_settings = json_decode( $el_settings, true );
	}
	if ( ! is_array( $el_settings ) ) {
		$el_settings = [];
	}
	$el_settings['custom_css'] = $el_css;
	$el_slashed = function_exists( 'wp_slash_strings_only' )
		? wp_slash_strings_only( $el_settings )
		: wp_slash( $el_settings );
	update_post_meta( $el_id, '_elementor_page_settings', $el_slashed );
	el_flush();
}

/**
 * Replace (or insert) this tool's own marked block inside a CSS string.
 *
 * Both markers are complete comments: a stray word outside a comment swallows
 * the first rule of whatever follows. The tool owns exactly one marker pair and
 * never appends a second copy — pass an empty body to remove the block.
 */
function el_css_block( string $el_css, string $el_tool, string $el_body ): string {
	$el_start = '/* ' . $el_tool . ' */';
	$el_end   = '/* ' . $el_tool . ' end */';

	$el_pattern = '/' . preg_quote( $el_start, '/' ) . '.*?' . preg_quote( $el_end, '/' ) . '\s*/s';
	$el_css     = preg_replace( $el_pattern, '', $el_css );
	$el_css     = rtrim( (string) $el_css );

	if ( '' === trim( $el_body ) ) {
		return $el_css . "\n";
	}

	return $el_css . "\n\n" . $el_start . "\n" . trim( $el_body ) . "\n" . $el_end . "\n";
}

/* ------------------------------------------------------------------ */
/* Misc                                                                */
/* ------------------------------------------------------------------ */

/** Give the current process unfiltered_html, needed to save <script> from CLI. */
function el_become_admin(): int {
	$el_admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	$el_id     = (int) ( $el_admins[0] ?? 0 );
	if ( $el_id ) {
		wp_set_current_user( $el_id );
	}
	return $el_id;
}

/** Human-readable label for a document. */
function el_label( int $el_id ): string {
	$el_post = get_post( $el_id );
	if ( ! $el_post ) {
		return "#{$el_id} (missing)";
	}
	$el_type = get_post_meta( $el_id, '_elementor_template_type', true );
	return sprintf(
		'#%d %s [%s%s]',
		$el_id,
		$el_post->post_title ?: '(no title)',
		$el_post->post_type,
		$el_type ? '/' . $el_type : ''
	);
}
