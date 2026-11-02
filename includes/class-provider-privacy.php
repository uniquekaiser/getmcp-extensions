<?php
/** Keep personal health data out of shared caches, hooks and replay logs. */
namespace GetMCPExtensions;
final class ProviderPrivacy {
	/** @wp-test feature=upgrade.180 scenario=oura-personal-privacy */
	public static function sensitive( $tool, $server ): bool {
		return ProviderTokens::is_oura( $server ) || 'api.ouraring.com' === strtolower( (string) wp_parse_url( $tool->endpoint_url ?? '', PHP_URL_HOST ) );
	}
}
