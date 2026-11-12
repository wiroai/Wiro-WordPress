<?php
/**
 * Dependencies of the Wiro AI Image block's editor script.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( 'wiro-ai-common', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
	'version'      => '1.0.0',
);
