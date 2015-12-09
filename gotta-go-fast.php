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


/**
 * IGNORE BELOW HERE FOR NOW
 */


/**
 * Update a post with new post data.
 *
 * The date does not have to be set for drafts. You can set the date and it will
 * not be overridden.
 *
 * @since 1.0.0
 *
 * @param array|object $postarr  Optional. Post data. Arrays are expected to be escaped,
 *                               objects are not. Default array.
 * @param bool         $wp_error Optional. Allow return of WP_Error on failure. Default false.
 * @return int|WP_Error The value 0 or WP_Error on failure. The post ID on success.
 */
function action_scheduler_update_post( $postarr = array(), $wp_error = false ) {
	if ( is_object($postarr) ) {
		// Non-escaped post was passed.
		$postarr = get_object_vars($postarr);
		$postarr = wp_slash($postarr);
	}
	// First, get all of the original fields.
	$post = get_post($postarr['ID'], ARRAY_A);
	if ( is_null( $post ) ) {
		if ( $wp_error )
			return new WP_Error( 'invalid_post', __( 'Invalid post ID.' ) );
		return 0;
	}
	// Escape data pulled from DB.
	$post = wp_slash($post);
	// Passed post category list overwrites existing category list if not empty.
	if ( isset($postarr['post_category']) && is_array($postarr['post_category'])
			 && 0 != count($postarr['post_category']) )
		$post_cats = $postarr['post_category'];
	else
		$post_cats = $post['post_category'];
	// Drafts shouldn't be assigned a date unless explicitly done so by the user.
	if ( isset( $post['post_status'] ) && in_array($post['post_status'], array('draft', 'pending', 'auto-draft')) && empty($postarr['edit_date']) &&
			 ('0000-00-00 00:00:00' == $post['post_date_gmt']) )
		$clear_date = true;
	else
		$clear_date = false;
	// Merge old and new fields with new fields overwriting old ones.
	$postarr = array_merge($post, $postarr);
	$postarr['post_category'] = $post_cats;
	if ( $clear_date ) {
		$postarr['post_date'] = current_time('mysql');
		$postarr['post_date_gmt'] = '';
	}
	if ($postarr['post_type'] == 'attachment')
		return wp_insert_attachment($postarr);
	return action_scheduler_insert_post( $postarr, $wp_error ); //CHANGE
}


/**
 * Insert or update a post.
 *
 * If the $postarr parameter has 'ID' set to a value, then post will be updated.
 *
 * You can set the post date manually, by setting the values for 'post_date'
 * and 'post_date_gmt' keys. You can close the comments or open the comments by
 * setting the value for 'comment_status' key.
 *
 * @since 1.0.0
 * @since 4.2.0 Support was added for encoding emoji in the post title, content, and excerpt.
 * @since 4.4.0 A 'meta_input' array can now be passed to `$postarr` to add post meta data.
 *
 * @see sanitize_post()
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param array $postarr {
 *     An array of elements that make up a post to update or insert.
 *
 *     @type int    $ID                    The post ID. If equal to something other than 0,
 *                                         the post with that ID will be updated. Default 0.
 *     @type int    $post_author           The ID of the user who added the post. Default is
 *                                         the current user ID.
 *     @type string $post_date             The date of the post. Default is the current time.
 *     @type string $post_date_gmt         The date of the post in the GMT timezone. Default is
 *                                         the value of `$post_date`.
 *     @type mixed  $post_content          The post content. Default empty.
 *     @type string $post_content_filtered The filtered post content. Default empty.
 *     @type string $post_title            The post title. Default empty.
 *     @type string $post_excerpt          The post excerpt. Default empty.
 *     @type string $post_status           The post status. Default 'draft'.
 *     @type string $post_type             The post type. Default 'post'.
 *     @type string $comment_status        Whether the post can accept comments. Accepts 'open' or 'closed'.
 *                                         Default is the value of 'default_comment_status' option.
 *     @type string $ping_status           Whether the post can accept pings. Accepts 'open' or 'closed'.
 *                                         Default is the value of 'default_ping_status' option.
 *     @type string $post_password         The password to access the post. Default empty.
 *     @type string $post_name             The post name. Default is the sanitized post title.
 *     @type string $to_ping               Space or carriage return-separated list of URLs to ping.
 *                                         Default empty.
 *     @type string $pinged                Space or carriage return-separated list of URLs that have
 *                                         been pinged. Default empty.
 *     @type string $post_modified         The date when the post was last modified. Default is
 *                                         the current time.
 *     @type string $post_modified_gmt     The date when the post was last modified in the GMT
 *                                         timezone. Default is the current time.
 *     @type int    $post_parent           Set this for the post it belongs to, if any. Default 0.
 *     @type int    $menu_order            The order the post should be displayed in. Default 0.
 *     @type string $post_mime_type        The mime type of the post. Default empty.
 *     @type string $guid                  Global Unique ID for referencing the post. Default empty.
 *     @type array  $tax_input             Array of taxonomy terms keyed by their taxonomy name. Default empty.
 *     @type array  $meta_input            Array of post meta values keyed by their post meta key. Default empty.
 * }
 * @param bool  $wp_error Optional. Whether to allow return of WP_Error on failure. Default false.
 * @return int|WP_Error The post ID on success. The value 0 or WP_Error on failure.
 */
function action_scheduler_insert_post( $postarr, $wp_error = false ) {
	global $wpdb;
	$user_id = get_current_user_id();
	$defaults = array(
		'post_author' => $user_id,
		'post_content' => '',
		'post_content_filtered' => '',
		'post_title' => '',
		'post_excerpt' => '',
		'post_status' => 'draft',
		'post_type' => 'post',
		'comment_status' => '',
		'ping_status' => '',
		'post_password' => '',
		'to_ping' =>  '',
		'pinged' => '',
		'post_parent' => 0,
		'menu_order' => 0,
		'guid' => '',
		'import_id' => 0,
		'context' => '',
	);
	$postarr = wp_parse_args($postarr, $defaults);
	unset( $postarr[ 'filter' ] );
	$postarr = sanitize_post($postarr, 'db');
	// Are we updating or creating?
	$post_ID = 0;
	$update = false;
	$guid = $postarr['guid'];
	if ( ! empty( $postarr['ID'] ) ) {
		$update = true;
		// Get the post ID and GUID.
		$post_ID = $postarr['ID'];
		$post_before = get_post( $post_ID );
		if ( is_null( $post_before ) ) {
			if ( $wp_error ) {
				return new WP_Error( 'invalid_post', __( 'Invalid post ID.' ) );
			}
			return 0;
		}
		$guid = get_post_field( 'guid', $post_ID );
		$previous_status = get_post_field('post_status', $post_ID );
	} else {
		$previous_status = 'new';
	}
	$post_type = empty( $postarr['post_type'] ) ? 'post' : $postarr['post_type'];
	$post_title = $postarr['post_title'];
	$post_content = $postarr['post_content'];
	$post_excerpt = $postarr['post_excerpt'];
	if ( isset( $postarr['post_name'] ) ) {
		$post_name = $postarr['post_name'];
	}
	$maybe_empty = 'attachment' !== $post_type
		&& ! $post_content && ! $post_title && ! $post_excerpt
		&& post_type_supports( $post_type, 'editor' )
		&& post_type_supports( $post_type, 'title' )
		&& post_type_supports( $post_type, 'excerpt' );
	/**
	 * Filter whether the post should be considered "empty".
	 *
	 * The post is considered "empty" if both:
	 * 1. The post type supports the title, editor, and excerpt fields
	 * 2. The title, editor, and excerpt fields are all empty
	 *
	 * Returning a truthy value to the filter will effectively short-circuit
	 * the new post being inserted, returning 0. If $wp_error is true, a WP_Error
	 * will be returned instead.
	 *
	 * @since 3.3.0
	 *
	 * @param bool  $maybe_empty Whether the post should be considered "empty".
	 * @param array $postarr     Array of post data.
	 */
	if ( apply_filters( 'wp_insert_post_empty_content', $maybe_empty, $postarr ) ) {
		if ( $wp_error ) {
			return new WP_Error( 'empty_content', __( 'Content, title, and excerpt are empty.' ) );
		} else {
			return 0;
		}
	}
	$post_status = empty( $postarr['post_status'] ) ? 'draft' : $postarr['post_status'];
	if ( 'attachment' === $post_type && ! in_array( $post_status, array( 'inherit', 'private', 'trash' ) ) ) {
		$post_status = 'inherit';
	}
	if ( ! empty( $postarr['post_category'] ) ) {
		// Filter out empty terms.
		$post_category = array_filter( $postarr['post_category'] );
	}
	// Make sure we set a valid category.
	if ( empty( $post_category ) || 0 == count( $post_category ) || ! is_array( $post_category ) ) {
		// 'post' requires at least one category.
		if ( 'post' == $post_type && 'auto-draft' != $post_status ) {
			$post_category = array( get_option('default_category') );
		} else {
			$post_category = array();
		}
	}
	// Don't allow contributors to set the post slug for pending review posts.
	if ( 'pending' == $post_status && !current_user_can( 'publish_posts' ) ) {
		$post_name = '';
	}
	/*
	 * Create a valid post name. Drafts and pending posts are allowed to have
	 * an empty post name.
	 */
	if ( empty($post_name) ) {
		if ( !in_array( $post_status, array( 'draft', 'pending', 'auto-draft' ) ) ) {
			$post_name = sanitize_title($post_title);
		} else {
			$post_name = '';
		}
	} else {
		// On updates, we need to check to see if it's using the old, fixed sanitization context.
		$check_name = sanitize_title( $post_name, '', 'old-save' );
		if ( $update && strtolower( urlencode( $post_name ) ) == $check_name && get_post_field( 'post_name', $post_ID ) == $check_name ) {
			$post_name = $check_name;
		} else { // new post, or slug has changed.
			$post_name = sanitize_title($post_name);
		}
	}
	/*
	 * If the post date is empty (due to having been new or a draft) and status
	 * is not 'draft' or 'pending', set date to now.
	 */
	if ( empty( $postarr['post_date'] ) || '0000-00-00 00:00:00' == $postarr['post_date'] ) {
		if ( empty( $postarr['post_date_gmt'] ) || '0000-00-00 00:00:00' == $postarr['post_date_gmt'] ) {
			$post_date = current_time( 'mysql' );
		} else {
			$post_date = get_date_from_gmt( $postarr['post_date_gmt'] );
		}
	} else {
		$post_date = $postarr['post_date'];
	}
	// Validate the date.
	$mm = substr( $post_date, 5, 2 );
	$jj = substr( $post_date, 8, 2 );
	$aa = substr( $post_date, 0, 4 );
	$valid_date = wp_checkdate( $mm, $jj, $aa, $post_date );
	if ( ! $valid_date ) {
		if ( $wp_error ) {
			return new WP_Error( 'invalid_date', __( 'Whoops, the provided date is invalid.' ) );
		} else {
			return 0;
		}
	}
	if ( empty( $postarr['post_date_gmt'] ) || '0000-00-00 00:00:00' == $postarr['post_date_gmt'] ) {
		if ( ! in_array( $post_status, array( 'draft', 'pending', 'auto-draft' ) ) ) {
			$post_date_gmt = get_gmt_from_date( $post_date );
		} else {
			$post_date_gmt = '0000-00-00 00:00:00';
		}
	} else {
		$post_date_gmt = $postarr['post_date_gmt'];
	}
	if ( $update || '0000-00-00 00:00:00' == $post_date ) {
		$post_modified     = current_time( 'mysql' );
		$post_modified_gmt = current_time( 'mysql', 1 );
	} else {
		$post_modified     = $post_date;
		$post_modified_gmt = $post_date_gmt;
	}
	if ( 'attachment' !== $post_type ) {
		if ( 'publish' == $post_status ) {
			$now = gmdate('Y-m-d H:i:59');
			if ( mysql2date('U', $post_date_gmt, false) > mysql2date('U', $now, false) ) {
				$post_status = 'future';
			}
		} elseif ( 'future' == $post_status ) {
			$now = gmdate('Y-m-d H:i:59');
			if ( mysql2date('U', $post_date_gmt, false) <= mysql2date('U', $now, false) ) {
				$post_status = 'publish';
			}
		}
	}
	// Comment status.
	if ( empty( $postarr['comment_status'] ) ) {
		if ( $update ) {
			$comment_status = 'closed';
		} else {
			$comment_status = get_default_comment_status( $post_type );
		}
	} else {
		$comment_status = $postarr['comment_status'];
	}
	// These variables are needed by compact() later.
	$post_content_filtered = $postarr['post_content_filtered'];
	$post_author = isset( $postarr['post_author'] ) ? $postarr['post_author'] : $user_id;
	$ping_status = empty( $postarr['ping_status'] ) ? get_default_comment_status( $post_type, 'pingback' ) : $postarr['ping_status'];
	$to_ping = isset( $postarr['to_ping'] ) ? sanitize_trackback_urls( $postarr['to_ping'] ) : '';
	$pinged = isset( $postarr['pinged'] ) ? $postarr['pinged'] : '';
	$import_id = isset( $postarr['import_id'] ) ? $postarr['import_id'] : 0;
	/*
	 * The 'wp_insert_post_parent' filter expects all variables to be present.
	 * Previously, these variables would have already been extracted
	 */
	if ( isset( $postarr['menu_order'] ) ) {
		$menu_order = (int) $postarr['menu_order'];
	} else {
		$menu_order = 0;
	}
	$post_password = isset( $postarr['post_password'] ) ? $postarr['post_password'] : '';
	if ( 'private' == $post_status ) {
		$post_password = '';
	}
	if ( isset( $postarr['post_parent'] ) ) {
		$post_parent = (int) $postarr['post_parent'];
	} else {
		$post_parent = 0;
	}
	/**
	 * Filter the post parent -- used to check for and prevent hierarchy loops.
	 *
	 * @since 3.1.0
	 *
	 * @param int   $post_parent Post parent ID.
	 * @param int   $post_ID     Post ID.
	 * @param array $new_postarr Array of parsed post data.
	 * @param array $postarr     Array of sanitized, but otherwise unmodified post data.
	 */
	$post_parent = apply_filters( 'wp_insert_post_parent', $post_parent, $post_ID, compact( array_keys( $postarr ) ), $postarr );
	//$post_name = wp_unique_post_slug( $post_name, $post_ID, $post_status, $post_type, $post_parent ); //CHANGE
	$post_name = sanitize_title($post_title);
	// Don't unslash.
	$post_mime_type = isset( $postarr['post_mime_type'] ) ? $postarr['post_mime_type'] : '';
	// Expected_slashed (everything!).
	$data = compact( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_title', 'post_excerpt', 'post_status', 'post_type', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_mime_type', 'guid' );
	$emoji_fields = array( 'post_title', 'post_content', 'post_excerpt' );
	foreach ( $emoji_fields as $emoji_field ) {
		if ( isset( $data[ $emoji_field ] ) ) {
			$charset = $wpdb->get_col_charset( $wpdb->posts, $emoji_field );
			if ( 'utf8' === $charset ) {
				$data[ $emoji_field ] = wp_encode_emoji( $data[ $emoji_field ] );
			}
		}
	}
	if ( 'attachment' === $post_type ) {
		/**
		 * Filter attachment post data before it is updated in or added to the database.
		 *
		 * @since 3.9.0
		 *
		 * @param array $data    An array of sanitized attachment post data.
		 * @param array $postarr An array of unsanitized attachment post data.
		 */
		$data = apply_filters( 'wp_insert_attachment_data', $data, $postarr );
	} else {
		/**
		 * Filter slashed post data just before it is inserted into the database.
		 *
		 * @since 2.7.0
		 *
		 * @param array $data    An array of slashed post data.
		 * @param array $postarr An array of sanitized, but otherwise unmodified post data.
		 */
		$data = apply_filters( 'wp_insert_post_data', $data, $postarr );
	}
	$data = wp_unslash( $data );
	$where = array( 'ID' => $post_ID );
	if ( $update ) {
		/**
		 * Fires immediately before an existing post is updated in the database.
		 *
		 * @since 2.5.0
		 *
		 * @param int   $post_ID Post ID.
		 * @param array $data    Array of unslashed post data.
		 */
		do_action( 'pre_post_update', $post_ID, $data );
		if ( false === $wpdb->update( $wpdb->posts, $data, $where ) ) {
			if ( $wp_error ) {
				return new WP_Error('db_update_error', __('Could not update post in the database'), $wpdb->last_error);
			} else {
				return 0;
			}
		}
	} else {
		// If there is a suggested ID, use it if not already present.
		if ( ! empty( $import_id ) ) {
			$import_id = (int) $import_id;
			if ( ! $wpdb->get_var( $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d", $import_id) ) ) {
				$data['ID'] = $import_id;
			}
		}
		if ( false === $wpdb->insert( $wpdb->posts, $data ) ) {
			if ( $wp_error ) {
				return new WP_Error('db_insert_error', __('Could not insert post into the database'), $wpdb->last_error);
			} else {
				return 0;
			}
		}
		$post_ID = (int) $wpdb->insert_id;
		// Use the newly generated $post_ID.
		$where = array( 'ID' => $post_ID );
	}
	if ( empty( $data['post_name'] ) && ! in_array( $data['post_status'], array( 'draft', 'pending', 'auto-draft' ) ) ) {
		$data['post_name'] = wp_unique_post_slug( sanitize_title( $data['post_title'], $post_ID ), $post_ID, $data['post_status'], $post_type, $post_parent );
		$wpdb->update( $wpdb->posts, array( 'post_name' => $data['post_name'] ), $where );
		clean_post_cache( $post_ID );
	}
	if ( is_object_in_taxonomy( $post_type, 'category' ) ) {
		wp_set_post_categories( $post_ID, $post_category );
	}
	if ( isset( $postarr['tags_input'] ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
		wp_set_post_tags( $post_ID, $postarr['tags_input'] );
	}
	// New-style support for all custom taxonomies.
	if ( ! empty( $postarr['tax_input'] ) ) {
		foreach ( $postarr['tax_input'] as $taxonomy => $tags ) {
			$taxonomy_obj = get_taxonomy($taxonomy);
			if ( ! $taxonomy_obj ) {
				/* translators: %s: taxonomy name */
				_doing_it_wrong( __FUNCTION__, sprintf( __( 'Invalid taxonomy: %s.' ), $taxonomy ), '4.4.0' );
				continue;
			}
			// array = hierarchical, string = non-hierarchical.
			if ( is_array( $tags ) ) {
				$tags = array_filter($tags);
			}
			if ( current_user_can( $taxonomy_obj->cap->assign_terms ) ) {
				wp_set_post_terms( $post_ID, $tags, $taxonomy );
			}
		}
	}
	if ( ! empty( $postarr['meta_input'] ) ) {
		foreach ( $postarr['meta_input'] as $field => $value ) {
			update_post_meta( $post_ID, $field, $value );
		}
	}
	$current_guid = get_post_field( 'guid', $post_ID );
	// Set GUID.
	if ( ! $update && '' == $current_guid ) {
		$wpdb->update( $wpdb->posts, array( 'guid' => get_permalink( $post_ID ) ), $where );
	}
	if ( 'attachment' === $postarr['post_type'] ) {
		if ( ! empty( $postarr['file'] ) ) {
			update_attached_file( $post_ID, $postarr['file'] );
		}
		if ( ! empty( $postarr['context'] ) ) {
			add_post_meta( $post_ID, '_wp_attachment_context', $postarr['context'], true );
		}
	}
	clean_post_cache( $post_ID );
	$post = get_post( $post_ID );
	if ( ! empty( $postarr['page_template'] ) && 'page' == $data['post_type'] ) {
		$post->page_template = $postarr['page_template'];
		$page_templates = wp_get_theme()->get_page_templates( $post );
		if ( 'default' != $postarr['page_template'] && ! isset( $page_templates[ $postarr['page_template'] ] ) ) {
			if ( $wp_error ) {
				return new WP_Error('invalid_page_template', __('The page template is invalid.'));
			}
			update_post_meta( $post_ID, '_wp_page_template', 'default' );
		} else {
			update_post_meta( $post_ID, '_wp_page_template', $postarr['page_template'] );
		}
	}
	if ( 'attachment' !== $postarr['post_type'] ) {
		wp_transition_post_status( $data['post_status'], $previous_status, $post );
	} else {
		if ( $update ) {
			/**
			 * Fires once an existing attachment has been updated.
			 *
			 * @since 2.0.0
			 *
			 * @param int $post_ID Attachment ID.
			 */
			do_action( 'edit_attachment', $post_ID );
			$post_after = get_post( $post_ID );
			/**
			 * Fires once an existing attachment has been updated.
			 *
			 * @since 4.4.0
			 *
			 * @param int     $post_ID      Post ID.
			 * @param WP_Post $post_after   Post object following the update.
			 * @param WP_Post $post_before  Post object before the update.
			 */
			do_action( 'attachment_updated', $post_ID, $post_after, $post_before );
		} else {
			/**
			 * Fires once an attachment has been added.
			 *
			 * @since 2.0.0
			 *
			 * @param int $post_ID Attachment ID.
			 */
			do_action( 'add_attachment', $post_ID );
		}
		return $post_ID;
	}
	if ( $update ) {
		/**
		 * Fires once an existing post has been updated.
		 *
		 * @since 1.2.0
		 *
		 * @param int     $post_ID Post ID.
		 * @param WP_Post $post    Post object.
		 */
		do_action( 'edit_post', $post_ID, $post );
		$post_after = get_post($post_ID);
		/**
		 * Fires once an existing post has been updated.
		 *
		 * @since 3.0.0
		 *
		 * @param int     $post_ID      Post ID.
		 * @param WP_Post $post_after   Post object following the update.
		 * @param WP_Post $post_before  Post object before the update.
		 */
		do_action( 'post_updated', $post_ID, $post_after, $post_before);
	}
	/**
	 * Fires once a post has been saved.
	 *
	 * The dynamic portion of the hook name, `$post->post_type`, refers to
	 * the post type slug.
	 *
	 * @since 3.7.0
	 *
	 * @param int     $post_ID Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	do_action( "save_post_{$post->post_type}", $post_ID, $post, $update );
	/**
	 * Fires once a post has been saved.
	 *
	 * @since 1.5.0
	 *
	 * @param int     $post_ID Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	do_action( 'save_post', $post_ID, $post, $update );
	/**
	 * Fires once a post has been saved.
	 *
	 * @since 2.0.0
	 *
	 * @param int     $post_ID Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	do_action( 'wp_insert_post', $post_ID, $post, $update );
	return $post_ID;
}


function get_completed_payment_count_short( $order ) {

	$completed_payment_count = ( false !== $order->order && ( isset( $order->order->paid_date ) || $order->order->has_status( $order->get_paid_order_statuses() ) ) ) ? 1 : 0;
	// not all gateways will call $order->payment_complete() so we need to find renewal orders with a paid status rather than just a _paid_date

	$paid_status_renewal_orders = get_posts( array(
		'posts_per_page' => 2,
		'post_status'    => $order->get_paid_order_statuses(),
		'post_type'      => 'shop_order',
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'desc',
		'meta_key'       => '_subscription_renewal',
		'meta_compare'   => '='
		'meta_value_num' => $order->id,
	) );

	// because some stores may be using custom order status plugins, we also can't rely on order status to find paid orders, so also check for a _paid_date
	$renewal_orders = get_posts( array(
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'post_type'      => 'shop_order',
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'desc',
		'meta_key'       => '_subscription_renewal',
		'meta_compare'   => '='
		'meta_value_num' => $order->id,
	) );

	// its more efficient (maybe? this is what I am testing) on large sites to compute this separately and then get the intersection using php
	$paid_date_orders = get_posts( array(
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'post_type'      => 'shop_order',
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'desc',
		'meta_key'       => '_paid_date',
		'meta_compare'   => 'EXISTS'
	) );

	$paid_date_renewal_orders = array_intersect( $renewal_orders, $paid_date_orders );

	$paid_renewal_orders = array_unique( array_merge( $paid_date_renewal_orders, $paid_status_renewal_orders ) );

	if ( ! empty( $paid_renewal_orders ) ) {
		$completed_payment_count += count( $paid_renewal_orders );
	}

	return apply_filters( 'woocommerce_subscription_payment_completed_count', $completed_payment_count, $order );
}
