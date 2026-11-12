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
 * Wires the settings page, REST routes, editor scripts and block together.
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
		( new Wiro_AI_Settings() )->init();
		( new Wiro_AI_REST() )->init();
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Image models offered in the editor.
	 *
	 * @param bool $with_custom Whether to add the custom model from the settings.
	 * @return array<string, array> Models keyed by slug.
	 */
	public static function models( $with_custom = false ) {
		/**
		 * Filters the image models offered in the editor.
		 *
		 * @param array $models Models keyed by owner/project slug. Each has a "label"
		 *                      and may have "params" sent with every request.
		 */
		$models = apply_filters( 'wiro_ai_models', Wiro_AI_Models::catalog() );
		$models = is_array( $models ) ? $models : Wiro_AI_Models::catalog();
		foreach ( $models as $slug => $model ) {
			if ( ! is_array( $model ) || empty( $model['label'] ) || Wiro_AI_Models::normalize_slug( $slug ) !== $slug ) {
				unset( $models[ $slug ] );
			}
		}
		if ( $with_custom ) {
			$custom = Wiro_AI_Settings::custom_model();
			if ( '' !== $custom && ! isset( $models[ $custom ] ) ) {
				$models[ $custom ] = array( 'label' => $custom );
			}
		}
		return $models;
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
	 * Whether the current user may generate images with the site's key.
	 *
	 * @return bool
	 */
	public static function current_user_can_generate() {
		/**
		 * Filters the capability needed to generate images.
		 *
		 * Images are billed to the site's Wiro AI project, so this decides who
		 * can spend from it. Users also need to be able to edit the post.
		 *
		 * @param string $capability Capability. Default "upload_files".
		 */
		$capability = apply_filters( 'wiro_ai_generate_capability', 'upload_files' );
		return is_string( $capability ) && '' !== $capability && current_user_can( $capability );
	}

	/**
	 * Registers the shared editor script and the sidebar script.
	 */
	public function register_assets() {
		wp_register_script(
			'wiro-ai-common',
			WIRO_AI_URL . 'assets/js/common.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			WIRO_AI_VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( 'wiro-ai-common', 'wiro-ai' );

		wp_register_script(
			'wiro-ai-sidebar',
			WIRO_AI_URL . 'assets/js/sidebar.js',
			array( 'wiro-ai-common', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			WIRO_AI_VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( 'wiro-ai-sidebar', 'wiro-ai' );
	}

	/**
	 * Registers the Wiro AI Image block.
	 */
	public function register_block() {
		register_block_type( WIRO_AI_DIR . 'blocks/image' );
	}

	/**
	 * Passes the settings to the editor scripts and adds the sidebar panel
	 * to post editors whose post type has featured images.
	 */
	public function enqueue_editor_assets() {
		wp_add_inline_script( 'wiro-ai-common', 'window.wiroAiData = ' . wp_json_encode( self::editor_data() ) . ';', 'before' );

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || empty( $screen->post_type ) ) {
			return;
		}
		if ( ! post_type_supports( $screen->post_type, 'thumbnail' ) || ! current_theme_supports( 'post-thumbnails', $screen->post_type ) ) {
			return;
		}
		if ( self::current_user_can_generate() ) {
			wp_enqueue_script( 'wiro-ai-sidebar' );
		}
	}

	/**
	 * Settings the editor scripts need. Never includes the API key.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_data() {
		$settings = Wiro_AI_Settings::get();
		$models   = array();
		foreach ( self::models( true ) as $slug => $model ) {
			$models[] = array(
				'value' => $slug,
				'label' => (string) $model['label'],
			);
		}
		$ratios = array();
		foreach ( self::ratio_labels() as $value => $label ) {
			$ratios[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		return array(
			'hasKey'         => Wiro_AI_Settings::has_key(),
			'canGenerate'    => self::current_user_can_generate(),
			'canManage'      => current_user_can( 'manage_options' ),
			'settingsUrl'    => admin_url( 'options-general.php?page=' . Wiro_AI_Settings::PAGE ),
			'models'         => $models,
			'defaultModel'   => Wiro_AI_Settings::default_model(),
			'ratios'         => $ratios,
			'defaultRatio'   => $settings['ratio'],
			'promptTemplate' => $settings['prompt_template'],
		);
	}
}
