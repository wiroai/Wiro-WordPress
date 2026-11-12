<?php
/**
 * Removes the plugin's settings and caches when the plugin is deleted.
 * Generated images stay in the Media Library.
 *
 * @package WiroAI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-wiro-ai-models.php';

$wiro_ai_settings = get_option( 'wiro_ai_settings', array() );
$wiro_ai_slugs    = array_keys( Wiro_AI_Models::catalog() );
if ( is_array( $wiro_ai_settings ) && ! empty( $wiro_ai_settings['custom_model'] ) && is_string( $wiro_ai_settings['custom_model'] ) ) {
	$wiro_ai_slugs[] = $wiro_ai_settings['custom_model'];
}
foreach ( $wiro_ai_slugs as $wiro_ai_slug ) {
	delete_transient( 'wiro_ai_model_' . md5( $wiro_ai_slug ) );
}

delete_option( 'wiro_ai_settings' );
