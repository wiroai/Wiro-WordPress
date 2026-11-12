<?php
/**
 * Removes the plugin's settings, usage history and caches when the plugin is
 * deleted. Images and audio it created stay in the Media Library.
 *
 * @package WiroAI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-wiro-ai-models.php';
require_once __DIR__ . '/includes/class-wiro-ai-jobs.php';

Wiro_AI_Jobs::delete_all();

foreach ( array( 'image', 'edit', 'upscale', 'background', 'narration', 'alt', 'text' ) as $wiro_ai_task ) {
	delete_transient( 'wiro_ai_models_' . $wiro_ai_task );
}
delete_transient( 'wiro_ai_gateway_models' );

delete_option( 'wiro_ai_settings' );
delete_option( 'wiro_ai_usage_totals' );
