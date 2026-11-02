<?php
/** Run the existing disposable health suite under the isolated 1.8 QA profile. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) {
	throw new RuntimeException( 'Isolated 1.8 fixture required.' );
}
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/auth/class-oauth-user-access.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-manager.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-module.php';
\GetMCP\Gateway\FeatureModule::register();
\GetMCPExtensions\MarketingTemplates::boot();
\GetMCPExtensions\MarketingModule::boot();
require GETMCP_EXTENSIONS_PATH . 'tests/google-health-wordpress.php';
