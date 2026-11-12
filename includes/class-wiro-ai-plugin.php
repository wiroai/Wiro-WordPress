<?php
/**
 * Plugin bootstrap.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the settings, admin screens, REST routes, editor scripts and block
 * together.
 */
final class Wiro_AI_Plugin {

	/**
	 * Single instance.
	 *
	 * @var Wiro_AI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * The single instance.
	 *
	 * @return Wiro_AI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the hooks.
	 */
	public function init() {
		$settings = new Wiro_AI_Settings();
		$settings->init();
		( new Wiro_AI_Admin() )->init( $settings );
		( new Wiro_AI_REST() )->init();
		add_action( 'init', array( 'Wiro_AI_Jobs', 'register' ) );
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Aspect ratios with readable labels.
	 *
	 * @return array<string, string>
	 */
	public static function ratio_labels() {
		return array(
			'16:9' => __( '16:9, wide', 'wiro-ai' ),
			'3:2'  => __( '3:2, landscape', 'wiro-ai' ),
			'4:3'  => __( '4:3, landscape', 'wiro-ai' ),
			'1:1'  => __( '1:1, square', 'wiro-ai' ),
			'2:3'  => __( '2:3, portrait', 'wiro-ai' ),
			'9:16' => __( '9:16, tall', 'wiro-ai' ),
		);
	}

	/**
	 * Whether the current user may use Wiro AI with the site's key.
	 *
	 * @return bool
	 */
	public static function current_user_can_generate() {
		$settings = Wiro_AI_Settings::get();
		/**
		 * Filters the capability needed to use Wiro AI.
		 *
		 * Requests are billed to the site's Wiro AI project, so this decides
		 * who can spend from it. Users also need to be able to edit the post
		 * or image they work on.
		 *
		 * @param string $capability Capability picked in the settings. Default "upload_files".
		 */
		$capability = apply_filters( 'wiro_ai_generate_capability', $settings['access'] );
		return is_string( $capability ) && '' !== $capability && current_user_can( $capability );
	}

	/**
	 * Registers the scripts and the admin stylesheet.
	 */
	public function register_assets() {
		$scripts = array(
			'wiro-ai-common'      => array( 'assets/js/common.js', array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ) ),
			'wiro-ai-sidebar'     => array( 'assets/js/sidebar.js', array( 'wiro-ai-common', 'wp-blocks', 'wp-data', 'wp-editor', 'wp-plugins' ) ),
			'wiro-ai-image-tools' => array( 'assets/js/image-tools.js', array( 'wiro-ai-common', 'wp-block-editor', 'wp-compose', 'wp-data', 'wp-hooks' ) ),
			'wiro-ai-media-tools' => array( 'assets/js/media-tools.js', array( 'wiro-ai-common', 'wp-dom-ready' ) ),
			'wiro-ai-bulk'        => array( 'assets/js/bulk.js', array( 'wiro-ai-common', 'wp-dom-ready' ) ),
		);
		foreach ( $scripts as $handle => $script ) {
			wp_register_script( $handle, WIRO_AI_URL . $script[0], $script[1], WIRO_AI_VERSION, array( 'in_footer' => true ) );
			wp_set_script_translations( $handle, 'wiro-ai' );
		}
		wp_register_style( 'wiro-ai-admin', WIRO_AI_URL . 'assets/css/admin.css', array( 'wp-components' ), WIRO_AI_VERSION );
	}

	/**
	 * Registers the Wiro AI Image block.
	 */
	public function register_block() {
		register_block_type( WIRO_AI_DIR . 'blocks/image' );
	}

	/**
	 * Editor scripts: settings for every Wiro AI script, the sidebar on post
	 * editors, and the Image block toolbar tools in every block editor.
	 */
	public function enqueue_editor_assets() {
		self::add_data();
		if ( ! self::current_user_can_generate() ) {
			return;
		}
		wp_enqueue_script( 'wiro-ai-image-tools' );

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'post' === $screen->base && ! empty( $screen->post_type ) && post_type_supports( $screen->post_type, 'editor' ) ) {
			wp_enqueue_script( 'wiro-ai-sidebar' );
		}
	}

	/**
	 * Admin scripts for the bulk screen and the edit screen of images.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'wiro-ai_page_wiro-ai-bulk' === $hook_suffix && current_user_can( 'manage_options' ) ) {
			self::add_data();
			wp_enqueue_script( 'wiro-ai-bulk' );
			wp_enqueue_style( 'wiro-ai-admin' );
			return;
		}
		if ( in_array( $hook_suffix, array( 'toplevel_page_wiro-ai', 'wiro-ai_page_wiro-ai-usage' ), true ) ) {
			wp_enqueue_style( 'wiro-ai-admin' );
			return;
		}
		if ( 'post.php' === $hook_suffix && $screen && 'attachment' === $screen->post_type && self::current_user_can_generate() ) {
			self::add_data();
			wp_enqueue_script( 'wiro-ai-media-tools' );
			wp_enqueue_style( 'wiro-ai-admin' );
		}
	}

	/**
	 * Passes the settings to the scripts, once per page.
	 */
	private static function add_data() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_add_inline_script( 'wiro-ai-common', 'window.wiroAiData = ' . wp_json_encode( self::script_data() ) . ';', 'before' );
	}

	/**
	 * Settings the scripts need. Never includes the API key.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_data() {
		$settings = Wiro_AI_Settings::get();
		$defaults = array();
		foreach ( array_keys( Wiro_AI_Catalog::tasks() ) as $task ) {
			$defaults[ $task ] = Wiro_AI_Catalog::default_model( $task );
		}
		$ratios = array();
		foreach ( self::ratio_labels() as $value => $label ) {
			$ratios[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$thumbnails = $screen && ! empty( $screen->post_type ) && post_type_supports( $screen->post_type, 'thumbnail' ) && current_theme_supports( 'post-thumbnails', $screen->post_type );
		return array(
			'hasKey'            => Wiro_AI_Settings::has_key(),
			'thumbnails'        => (bool) $thumbnails,
			'canGenerate'       => self::current_user_can_generate(),
			'canManage'         => current_user_can( 'manage_options' ),
			'settingsUrl'       => Wiro_AI_Settings::url(),
			'defaults'          => $defaults,
			'ratios'            => $ratios,
			'defaultRatio'      => $settings['ratio'],
			'promptTemplate'    => $settings['prompt_template'],
			'narrationPosition' => $settings['narration_position'],
			'upscaleScale'      => $settings['upscale_scale'],
			'maxChars'          => 10000,
		);
	}
}
