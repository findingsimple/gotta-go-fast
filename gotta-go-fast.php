<?php
/**
 * Plugin Name: Gotta go fast
 * Plugin URI: 
 * Description: Prototyping performance tweaks
 * Author: 
 * Author URI: 
 * Version:
 *
 */

// remove the all action https://support.woothemes.com/hc/en-us/articles/205214466-Subscriptions-2-0-Query-Monitor-Warning-The-all-action-is-extremely-resource-intensive-
add_action( 'plugins_loaded', 'wcs_remove_deprecation_handlers', 0 );

// prototying
add_action( 'init', 'gotta_go_fast' );

function wcs_remove_deprecation_handlers() {
	add_filter( 'woocommerce_subscriptions_load_deprecation_handlers', '__return_false' );
}

function gotta_go_fast() {

	// Remove default action scheduler comment clause filter
	remove_action( 'pre_get_comments', array( ActionScheduler_Logger::instance(), 'filter_comment_queries' ), 10, 1 );

	// Add our own filter
	add_action( 'pre_get_comments', 'the_magic', 10, 1);
}

function the_magic( $query ) {

	// Don't slow down queries that wouldn't include action_log comments anyway
	foreach ( array('ID', 'parent', 'post_author', 'post_name', 'post_parent', 'type', 'post_type', 'post_id', 'post_ID') as $key ) {
		if ( !empty($query->query_vars[$key]) ) {
			return; 
		}
	}

	// Variable to check for later
	$query->query_vars['action_log_filter'] = TRUE;

	// Remove default wc order and webhooks comment clause filter
	remove_filter( 'comments_clauses', 'WC_Comments::exclude_order_comments', 10, 1 );
	remove_filter( 'comments_clauses', 'WC_Comments::exclude_webhook_comments', 10, 1 );

	// Now add an optimized version
	add_filter( 'comments_clauses', 'filter_comment_query_clauses', 10, 2 );
}


/**
 * Instead of joining with the wp_posts table (which can be massive) we should be able to just exclude comment types we don't to include
 */
function filter_comment_query_clauses( $clauses, $query ) {

	// Only apply to queries we want
	if ( !empty($query->query_vars['action_log_filter']) ) {
		global $wpdb;

		// Comment types we want to exclude
		$comment_types = array(
			'order_note',
			'webhook_delivery',
			'action_log'
		);

		if ( $clauses['where'] ) {
			$clauses['where'] .= ' AND ';
		}

		// Exclude away
		$clauses['where'] .= " {$wpdb->comments}.comment_type NOT IN ('" . implode( "','", $comment_types ) . "') ";
	}

	return $clauses; 
}

