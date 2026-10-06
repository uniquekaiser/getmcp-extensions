<?php
/** Shared native OAuth user directory and allowlist API. @package GetMCP */
namespace GetMCP\Auth;

use GetMCP\Core\ServerManager;
use WP_Error;

class OAuthUserAccess {

	private static function permission( int $actor_id ): true|WP_Error {
		if ( ! $actor_id || ! user_can( $actor_id, 'getmcp_manage_servers' ) ) {
			return new WP_Error( 'getmcp_forbidden', 'You cannot manage OAuth server access.', array( 'status' => 403 ) );
		}
		return true;
	}

	/** Bounded searches; include hydrates selected IDs without listing the directory. */
	public static function search( array $args, ?int $actor_id = null ): array|WP_Error {
		$permission = self::permission( $actor_id ?? get_current_user_id() );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$term = trim( str_replace( '*', '', sanitize_text_field( (string) ( $args['search'] ?? '' ) ) ) );
		$ids = $args['include'] ?? array();
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) || count( $ids ) > 100 ) {
			return new WP_Error( 'getmcp_invalid_user_lookup', 'include must contain at most 100 positive user IDs.', array( 'status' => 400 ) );
		}
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id <= 0 ) {
				return new WP_Error( 'getmcp_invalid_user_lookup', 'include must contain positive user IDs.', array( 'status' => 400 ) );
			}
		}
		if ( ! $ids && ( strlen( $term ) < 2 || strlen( $term ) > 100 ) ) {
			return array( 'users' => array(), 'page' => 1, 'total_pages' => 0, 'has_more' => false );
		}
		$limit = max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) );
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$query_args = array( 'number' => $limit, 'offset' => ( $page - 1 ) * $limit, 'orderby' => array( 'display_name' => 'ASC', 'ID' => 'ASC' ) );
		if ( $ids ) {
			$query_args['include'] = array_values( array_unique( $ids ) );
			$query_args['number'] = count( $query_args['include'] );
			$query_args['offset'] = 0;
			$page = 1;
			$limit = $query_args['number'];
		} else {
			// A leading wildcard is supplied by us; user wildcards are not operators.
			$query_args['search'] = '*' . $term . '*';
			$query_args['search_columns'] = array( 'user_login', 'display_name' );
		}
		$query = new \WP_User_Query( $query_args );
		$users = array();
		foreach ( $query->get_results() as $user ) {
			$users[] = array( 'id' => (int) $user->ID, 'name' => $user->display_name, 'login' => $user->user_login, 'eligible' => user_can( $user, 'read' ) );
		}
		$pages = (int) ceil( $query->get_total() / $limit );
		return array( 'users' => $users, 'page' => $page, 'total_pages' => $pages, 'has_more' => $page < $pages );
	}

	public static function validate_ids( $ids ): true|WP_Error {
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			return new WP_Error( 'getmcp_invalid_oauth_users', 'allowed_user_ids must be an array of eligible positive user IDs.', array( 'status' => 400 ) );
		}
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id <= 0 || ! get_userdata( $id ) || ! user_can( $id, 'read' ) ) {
				return new WP_Error( 'getmcp_invalid_oauth_users', 'allowed_user_ids must be an array of eligible positive user IDs.', array( 'status' => 400 ) );
			}
		}
		return true;
	}

	public static function get( int $server_id, ?int $actor_id = null ): array|WP_Error {
		$permission = self::permission( $actor_id ?? get_current_user_id() );
		if ( is_wp_error( $permission ) ) { return $permission; }
		$server = ( new ServerManager() )->get( $server_id );
		if ( ! $server ) { return new WP_Error( 'getmcp_not_found', 'Server not found.', array( 'status' => 404 ) ); }
		if ( ! FirstPartyOAuth::is_custom( $server ) ) {
			return new WP_Error( 'getmcp_not_native_oauth', 'This endpoint requires a custom server using GetMCP / WordPress login.', array( 'status' => 400 ) );
		}
		$config = json_decode( $server->auth_config, true );
		return array( 'server_id' => $server_id, 'allowed_user_ids' => array_values( array_unique( $config['allowed_user_ids'] ?? array() ) ) );
	}

	/** Replace the list, preserving all credentials/config keys. Optional optimistic lock. */
	public static function set( int $server_id, $ids, ?array $expected = null, ?int $actor_id = null ): array|WP_Error {
		global $wpdb;
		$actor_id = $actor_id ?? get_current_user_id();
		$current = self::get( $server_id, $actor_id );
		if ( is_wp_error( $current ) ) { return $current; }
		$valid = self::validate_ids( $ids );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$ids = array_values( array_unique( $ids ) );
		$old_ids = $current['allowed_user_ids'];
		if ( null !== $expected && $expected !== $old_ids ) {
			return new WP_Error( 'getmcp_access_conflict', 'The allowlist changed. Fetch it again before updating.', array( 'status' => 409 ) );
		}
		$server = ( new ServerManager() )->get( $server_id );
		$config = json_decode( $server->auth_config, true );
		// Recheck against the snapshot used by the compare-and-swap, including provider.
		if ( ! FirstPartyOAuth::is_custom( $server ) || array_values( array_unique( $config['allowed_user_ids'] ?? array() ) ) !== $old_ids ) {
			return new WP_Error( 'getmcp_access_conflict', 'Server authentication changed. Fetch it again before updating.', array( 'status' => 409 ) );
		}
		if ( $ids === $old_ids ) { return $current; }
		$config['allowed_user_ids'] = $ids;
		$result = $wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_config' => wp_json_encode( $config ) ), array( 'id' => $server_id, 'auth_type' => 'oauth', 'auth_config' => $server->auth_config ), array( '%s' ), array( '%d', '%s', '%s' ) );
		if ( false === $result ) { return new WP_Error( 'getmcp_access_update_failed', 'Could not save server access.', array( 'status' => 500 ) ); }
		if ( 1 !== $result ) { return new WP_Error( 'getmcp_access_conflict', 'Server authentication changed. Fetch it again before updating.', array( 'status' => 409 ) ); }
		/** Observe successful changes; this action never bypasses the saved allowlist. */
		do_action( 'getmcp_oauth_allowed_users_updated', $server_id, $ids, $old_ids, $actor_id );
		return array( 'server_id' => $server_id, 'allowed_user_ids' => $ids );
	}
}
