<?php
/** Synthetic provider responses only, installed exclusively in the owned Docker fixture. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_filter( 'pre_http_request', function( $before, $args, $url ) {
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! in_array( $host, array( 'graph.facebook.com', 'oauth2.googleapis.com', 'analyticsadmin.googleapis.com', 'www.googleapis.com', 'googleads.googleapis.com', 'accounts.google.com' ), true ) ) { return $before; }
    $path = wp_parse_url( $url, PHP_URL_PATH ); $query = array(); wp_parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?: '', $query );
    $body = is_array( $args['body'] ?? null ) ? $args['body'] : array(); if ( is_string( $args['body'] ?? null ) ) { wp_parse_str( $args['body'], $body ); }
    $identity = $args['headers']['Authorization'] ?? ''; $mode = get_option( 'getmcp_fixture_provider_mode', 'valid' ); $status = 200;
    if ( 'timeout' === $mode ) { return new WP_Error( 'fixture_timeout', 'Synthetic timeout.' ); }
    if ( str_ends_with( $path, '/token' ) || str_ends_with( $path, '/oauth/access_token' ) ) {
        if ( 'revoke' === $mode ) { $status = 400; $data = array( 'error' => array( 'message' => 'fixture-secret-reflection-must-not-be-returned' ) ); }
        else { $who = str_contains( $body['code'] ?? $body['fb_exchange_token'] ?? $body['refresh_token'] ?? '', '-b' ) ? 'b' : 'a'; $data = array( 'access_token' => 'fixture-user-' . $who, 'refresh_token' => 'fixture-refresh-' . $who, 'token_type' => 'Bearer', 'expires_in' => 5184000, 'scope' => 'https://www.googleapis.com/auth/analytics.readonly' ); }
    } elseif ( str_ends_with( $path, '/me/accounts' ) ) {
        $who = str_contains( $identity, '-b' ) ? 'b' : 'a'; $second = ! empty( $query['after'] ); $id = ( 'b' === $who ? 200 : 100 ) + ( $second ? 2 : 1 );
        $data = array( 'data' => array( array( 'id' => (string) $id, 'name' => 'Fixture Page ' . $id, 'access_token' => 'fixture-page-' . $who . '-' . $id, 'tasks' => array( 'MESSAGING' ), 'instagram_business_account' => array( 'id' => (string) ( $id + 1000 ), 'username' => 'fixture' . $id ) ) ) );
        if ( ! $second ) { $data['paging'] = array( 'next' => 'https://evil.invalid/?access_token=should-never-be-followed', 'cursors' => array( 'after' => 'second' ) ); }
    } elseif ( str_ends_with( $path, '/me/permissions' ) ) {
        $data = array( 'data' => array_map( static fn( $p ) => array( 'permission' => $p, 'status' => 'denied_scopes' === $mode ? 'declined' : 'granted' ), array( 'instagram_basic', 'instagram_manage_messages', 'pages_manage_metadata' ) ) );
    } elseif ( str_ends_with( $path, '/conversations' ) ) {
        $data = array( 'data' => array( array( 'id' => 'conversation-' . ( str_contains( $identity, '-b-' ) ? 'b' : 'a' ) ) ) );
        if ( 'revoke' === $mode ) { $status = 403; $data = array( 'error' => array( 'message' => 'Token revoked.' ) ); }
    } elseif ( str_ends_with( $path, '/messages' ) && 'POST' === ( $args['method'] ?? '' ) ) {
        update_option( 'getmcp_fixture_message_count', (int) get_option( 'getmcp_fixture_message_count' ) + 1 );
        if ( 'ambiguous_write' === $mode ) { return new WP_Error( 'fixture_timeout_after_write', 'Synthetic write completed but response timed out.' ); }
        $data = array( 'message_id' => 'fixture-message' );
    } elseif ( str_contains( $path, '/accountSummaries' ) ) {
        $data = array( 'accountSummaries' => array( array( 'account' => empty( $query['pageToken'] ) ? 'accounts/1' : 'accounts/2', 'propertySummaries' => array( array( 'property' => 'properties/1' ) ) ) ) ); if ( empty( $query['pageToken'] ) ) { $data['nextPageToken'] = 'next'; }
    } elseif ( str_ends_with( $path, '/sites' ) ) { $data = array( 'siteEntry' => array( array( 'siteUrl' => 'https://fixture.example', 'permissionLevel' => 'siteOwner' ) ) ); }
    elseif ( str_ends_with( $path, ':listAccessibleCustomers' ) ) { $data = array( 'resourceNames' => array( 'customers/1' ) ); }
    elseif ( str_ends_with( $path, '/googleAds:search' ) ) { $data = array( 'results' => array( array( 'customerClient' => array( 'clientCustomer' => 'customers/1', 'manager' => false, 'level' => 0 ) ), array( 'customerClient' => array( 'clientCustomer' => 'customers/2', 'manager' => true, 'level' => str_contains( $path, '/customers/2/' ) ? 0 : 1 ) ) ) ); }
    else { $data = array( 'id' => 'fixture-read', 'data' => array() ); }
    if ( 'malformed' === $mode ) { $raw = '{invalid'; } else { $raw = wp_json_encode( $data ); }
    return array( 'response' => array( 'code' => $status, 'message' => 'Fixture' ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => $raw, 'cookies' => array() );
}, -20, 3 );
