<?php
/**
 * autosave-fix.php — stop Elementor's editor reverting scripted changes.
 *
 *   php autosave-fix.php            dry run: which documents are shadowed
 *   php autosave-fix.php apply      bump post_modified and drop stale autosaves
 *   php autosave-fix.php apply 19271 19272    only these documents
 *
 * Why this exists: update_post_meta() writes `_elementor_data` without touching
 * post_modified. Elementor compares an autosave revision's timestamp against the
 * post's own, decides the autosave is newer, and loads that instead — so the
 * front end shows your change, the editor shows the old version, and the next
 * editor save overwrites your work.
 *
 * Run this after every scripted write. el_save() calls it for you.
 *
 * @package claude-elementor-pro
 * @license MIT
 */

require __DIR__ . '/elementor-lib.php';
el_boot();

$el_mode = el_mode();
$el_only = array_map( 'intval', el_args() );

global $wpdb;

$el_rows = $wpdb->get_results(
	"SELECT p.ID, p.post_title, p.post_type, p.post_modified,
	        r.ID AS rev_id, r.post_modified AS rev_modified
	   FROM {$wpdb->posts} p
	   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
	   JOIN {$wpdb->posts} r ON r.post_parent = p.ID
	                        AND r.post_type = 'revision'
	                        AND r.post_name LIKE CONCAT(p.ID, '-autosave%')
	  WHERE p.post_type <> 'revision'
	  ORDER BY p.ID"
);

$el_shadowed = [];
foreach ( $el_rows as $el_row ) {
	if ( $el_only && ! in_array( (int) $el_row->ID, $el_only, true ) ) {
		continue;
	}
	if ( strtotime( $el_row->rev_modified ) >= strtotime( $el_row->post_modified ) ) {
		$el_shadowed[] = $el_row;
	}
}

if ( ! $el_shadowed ) {
	el_say( 'No shadowed documents. Every Elementor document is newer than its autosave.' );
	exit( 0 );
}

el_say( count( $el_shadowed ) . ' document(s) shadowed by a newer autosave:' );
el_say();
foreach ( $el_shadowed as $el_row ) {
	el_say(
		sprintf(
			'  #%-6d %-40s post %s  <  autosave %s (rev #%d)',
			$el_row->ID,
			mb_substr( $el_row->post_title ?: '(no title)', 0, 40 ),
			$el_row->post_modified,
			$el_row->rev_modified,
			$el_row->rev_id
		)
	);
}
el_say();

if ( 'apply' !== $el_mode ) {
	el_say( 'Dry run. Re-run with "apply" to bump post_modified and delete these autosaves.' );
	exit( 0 );
}

$el_fixed = 0;
foreach ( $el_shadowed as $el_row ) {
	el_autosave_fix( (int) $el_row->ID );
	$el_fixed++;
}

el_flush();

el_say( "Fixed {$el_fixed} document(s); Elementor CSS cache flushed." );
el_say( 'Open one of them in the editor to confirm it now loads the scripted version.' );
