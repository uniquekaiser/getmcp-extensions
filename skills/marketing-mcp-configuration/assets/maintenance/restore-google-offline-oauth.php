<?php
/**
 * Optional, one-time WP-CLI workaround for the reviewed Authentication-save bug.
 * Run after saving Google application credentials. This is not a plugin patch.
 * Usage: wp eval-file /path/to/restore-google-offline-oauth.php google-ads google-analytics google-search-console --user=ADMIN_LOGIN
 * Supply actual imported slugs if they differ. No credential values are printed.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    exit("Run this helper through WP-CLI eval-file on the intended WordPress installation.\n");
}
if (!current_user_can('manage_options')) {
    WP_CLI::error('Select a WordPress administrator using --user.');
}
if (!class_exists(\GetMCP\Core\ServerManager::class)) {
    WP_CLI::error('Activate a compatible GetMCP installation first.');
}

$marketing_slugs = !empty($args) ? array_values(array_unique($args)) : ['google-ads', 'google-analytics', 'google-search-console'];
$marketing_manager = new \GetMCP\Core\ServerManager();
$marketing_records = [];
$marketing_protected = ['uuid', 'slug', 'status', 'auth_type', 'auth_credentials', 'outbound_auth_type', 'outbound_auth_config', 'outbound_auth_credentials', 'test_auth_type', 'test_auth_credentials', 'settings'];

// Preflight every selected server before making any change.
foreach ($marketing_slugs as $marketing_slug) {
    $marketing_server = $marketing_manager->get_by_slug($marketing_slug);
    if (!$marketing_server) {
        WP_CLI::error('A selected server was not found. Use the actual imported slugs.');
    }
    $marketing_config = json_decode($marketing_server->auth_config ?? '', true);
    if ('oauth' !== $marketing_server->auth_type || !is_array($marketing_config)
        || 'external' !== ($marketing_config['provider'] ?? '')
        || 'https://oauth2.googleapis.com/token' !== ($marketing_config['token_url'] ?? '')) {
        WP_CLI::error('A selected server is not configured for Google External Provider OAuth.');
    }
    $marketing_extra = $marketing_config['extra_authorize_params'] ?? [];
    if (!is_array($marketing_extra)) {
        WP_CLI::error('Existing extra_authorize_params must be an object; no settings were changed.');
    }
    $marketing_config['extra_authorize_params'] = array_merge($marketing_extra, ['access_type' => 'offline', 'prompt' => 'consent']);
    $marketing_records[] = [$marketing_server, $marketing_config];
}

foreach ($marketing_records as [$marketing_before, $marketing_config]) {
    $marketing_current = $marketing_manager->get($marketing_before->id);
    if (!$marketing_current || $marketing_current->auth_config !== $marketing_before->auth_config) {
        WP_CLI::error('Settings changed after preflight. Re-read them before retrying.');
    }
    if (json_decode($marketing_current->auth_config ?? '', true) !== $marketing_config) {
        $marketing_encoded = wp_json_encode($marketing_config);
        if (false === $marketing_encoded || !$marketing_manager->update($marketing_current->id, ['auth_config' => $marketing_encoded])) {
            WP_CLI::error('Could not save the selected Google OAuth configuration.');
        }
    }
    $marketing_after = $marketing_manager->get($marketing_current->id);
    if (!$marketing_after || json_decode($marketing_after->auth_config ?? '', true) !== $marketing_config) {
        WP_CLI::error('Independent OAuth settings verification failed.');
    }
    foreach ($marketing_protected as $marketing_field) {
        if ($marketing_current->{$marketing_field} !== $marketing_after->{$marketing_field}) {
            WP_CLI::error('Protected configuration changed unexpectedly; stop and review before proceeding.');
        }
    }
    WP_CLI::success('Google offline/consent settings verified for ' . $marketing_after->slug . '; stored credentials preserved.');
}
