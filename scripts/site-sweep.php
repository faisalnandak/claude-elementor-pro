<?php
/**
 * site-sweep.php — fetch every public URL and look for damage.
 *
 *   php site-sweep.php                       every public post type + archives
 *   php site-sweep.php --type=product        one post type
 *   php site-sweep.php --limit=20
 *   php site-sweep.php --verbose             print every URL, not only problems
 *
 * Run this after every change that touches more than one document. A tool that
 * exits 0 has not proved anything; a sweep that reports zero problems across
 * every page has.
 *
 * Checks per URL: HTTP status, PHP notices and fatals in the output, an empty
 * or suspiciously short render, unbalanced div markup, and which Elementor
 * document rendered the page.
 *
 * @package claude-elementor-pro
 * @license MIT
 */

require __DIR__ . '/elementor-lib.php';
el_boot();

$ss_limit   = 0;
$ss_type    = '';
$ss_verbose = false;
foreach ( $GLOBALS['argv'] as $ss_arg ) {
	if ( str_starts_with( (string) $ss_arg, '--limit=' ) ) {
		$ss_limit = (int) substr( $ss_arg, 8 );
	}
	if ( str_starts_with( (string) $ss_arg, '--type=' ) ) {
		$ss_type = substr( $ss_arg, 7 );
	}
	if ( '--verbose' === $ss_arg ) {
		$ss_verbose = true;
	}
}

/* Collect URLs --------------------------------------------------------- */

$ss_urls = [ home_url( '/' ) => 'home' ];

$ss_types = $ss_type ? [ $ss_type ] : array_keys( get_post_types( [ 'public' => true ] ) );
foreach ( $ss_types as $ss_pt ) {
	if ( in_array( $ss_pt, [ 'attachment', 'elementor_library', 'e-floating-buttons' ], true ) ) {
		continue;
	}
	$ss_posts = get_posts(
		[
			'post_type'      => $ss_pt,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);
	foreach ( $ss_posts as $ss_post_id ) {
		$ss_urls[ get_permalink( $ss_post_id ) ] = $ss_pt . ' #' . $ss_post_id;
	}

	$ss_archive = get_post_type_archive_link( $ss_pt );
	if ( $ss_archive ) {
		$ss_urls[ $ss_archive ] = 'archive ' . $ss_pt;
	}
}

foreach ( get_taxonomies( [ 'public' => true ], 'names' ) as $ss_tax ) {
	if ( 'post_format' === $ss_tax ) {
		continue;
	}
	foreach ( get_terms( [ 'taxonomy' => $ss_tax, 'hide_empty' => true ] ) as $ss_term ) {
		if ( is_wp_error( $ss_term ) ) {
			continue;
		}
		$ss_link = get_term_link( $ss_term );
		if ( ! is_wp_error( $ss_link ) ) {
			$ss_urls[ $ss_link ] = 'term ' . $ss_tax . '/' . $ss_term->slug;
		}
	}
}

if ( $ss_limit > 0 ) {
	$ss_urls = array_slice( $ss_urls, 0, $ss_limit, true );
}

el_say( 'Sweeping ' . count( $ss_urls ) . ' URL(s).' );
el_say();

/* Fetch ---------------------------------------------------------------- */

$ss_problems = 0;
$ss_checked  = 0;

foreach ( $ss_urls as $ss_url => $ss_what ) {
	$ss_res = wp_remote_get(
		add_query_arg( 'sweep', time(), $ss_url ),
		[
			'timeout'   => 45,
			'sslverify' => false,
		]
	);
	$ss_checked++;

	if ( is_wp_error( $ss_res ) ) {
		$ss_problems++;
		el_say( sprintf( '  FAIL  %-18s %s', $ss_what, $ss_url ) );
		el_say( '        ' . $ss_res->get_error_message() );
		continue;
	}

	$ss_code = (int) wp_remote_retrieve_response_code( $ss_res );
	$ss_body = (string) wp_remote_retrieve_body( $ss_res );
	$ss_bad  = [];

	if ( $ss_code >= 400 ) {
		$ss_bad[] = 'HTTP ' . $ss_code;
	}
	if ( preg_match( '/(Fatal error|Parse error)\s*:/i', $ss_body ) ) {
		$ss_bad[] = 'PHP fatal';
	}
	if ( preg_match( '/\b(Warning|Notice|Deprecated)\s*:\s*[A-Z]/', $ss_body, $ss_m ) ) {
		$ss_bad[] = 'PHP ' . strtolower( $ss_m[1] );
	}
	if ( strlen( $ss_body ) < 2000 ) {
		$ss_bad[] = 'suspiciously short (' . strlen( $ss_body ) . ' bytes)';
	}

	$ss_open  = substr_count( strtolower( $ss_body ), '<div' );
	$ss_close = substr_count( strtolower( $ss_body ), '</div>' );
	if ( $ss_open !== $ss_close ) {
		$ss_bad[] = sprintf( 'div imbalance %+d', $ss_open - $ss_close );
	}

	// Every Elementor document on the page, header and footer included — the
	// first match is usually the header, not the template you are looking for.
	$ss_doc = 'not Elementor';
	if ( preg_match_all( '/\belementor-(\d+)\b/', $ss_body, $ss_dm ) ) {
		$ss_ids = array_values( array_unique( $ss_dm[1] ) );
		$ss_doc = '#' . implode( ',#', array_slice( $ss_ids, 0, 4 ) );
	}

	if ( $ss_bad ) {
		$ss_problems++;
		el_say( sprintf( '  PROB  %-18s %s', $ss_what, $ss_url ) );
		el_say( '        ' . implode( '; ', $ss_bad ) );
	} elseif ( $ss_verbose ) {
		el_say( sprintf( '  ok    %-18s %-24s %7d bytes  %s', $ss_what, $ss_doc, strlen( $ss_body ), $ss_url ) );
	}
}

el_say();
if ( 0 === $ss_problems ) {
	el_say( "Swept {$ss_checked} URL(s): zero problems." );
	el_say( 'Numbers are not the whole check — take screenshots at 1280 / 768 / 390 and look.' );
} else {
	el_say( "Swept {$ss_checked} URL(s): {$ss_problems} with problems." );
	el_say( 'A div imbalance usually means unclosed markup in post_content, which silently' );
	el_say( 're-parents everything after it — that is what makes a button stop being clickable.' );
	exit( 1 );
}
