<?php
/**
 * PHPUnit bootstrap. The model helpers are plain PHP, so the tests load them
 * without WordPress.
 *
 * @package WiroAI
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/class-wiro-ai-models.php';
require_once dirname( __DIR__ ) . '/includes/class-wiro-ai-pricing.php';
