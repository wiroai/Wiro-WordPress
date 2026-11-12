<?php
/**
 * Plugin Name:       Wiro AI
 * Plugin URI:        https://github.com/wiroai/Wiro-WordPress
 * Description:       Featured images, image editing, upscaling, background removal, alt text, narration and SEO suggestions in WordPress, with Wiro AI models.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Wiro AI
 * Author URI:        https://wiro.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wiro-ai
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WIRO_AI_VERSION', '1.1.0' );
define( 'WIRO_AI_FILE', __FILE__ );
define( 'WIRO_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WIRO_AI_URL', plugin_dir_url( __FILE__ ) );

require_once WIRO_AI_DIR . 'includes/class-wiro-ai-models.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-pricing.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-client.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-catalog.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-settings.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-jobs.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-media.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-runner.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-rest.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-admin.php';
require_once WIRO_AI_DIR . 'includes/class-wiro-ai-plugin.php';

Wiro_AI_Plugin::instance()->init();
