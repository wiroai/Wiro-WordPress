<?php
/**
 * Settings page and stored options.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > Wiro AI: API key, default model, aspect ratio and prompt template.
 */
class Wiro_AI_Settings {

	/**
	 * Option that holds all settings.
	 */
	const OPTION = 'wiro_ai_settings';

	/**
	 * Slug of the settings page.
	 */
	const PAGE = 'wiro-ai';

	/**
	 * Value of the model menu that means "use the slug typed below".
	 */
	const CUSTOM_MODEL = 'custom';

	/**
	 * Hooks the settings page into the admin.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_wiro_ai_check_connection', array( $this, 'handle_check_connection' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WIRO_AI_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Default values of every setting.
	 *
	 * @return array<string, string>
	 */
	public static function defaults() {
		return array(
			'api_key'         => '',
			'api_secret'      => '',
			'model'           => Wiro_AI_Models::DEFAULT_MODEL,
			'custom_model'    => '',
			'ratio'           => Wiro_AI_Models::DEFAULT_RATIO,
			'prompt_template' => self::default_template(),
		);
	}

	/**
	 * Prompt template used until the site owner writes their own.
	 *
	 * @return string
	 */
	public static function default_template() {
		return 'A featured image for the article "{title}". {content} No text, letters or logos in the image.';
	}

	/**
	 * All settings, merged with the defaults.
	 *
	 * @return array<string, string>
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		return array_map( 'strval', $settings );
	}

	/**
	 * Whether the key is defined in wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'WIRO_AI_API_KEY' ) && is_string( WIRO_AI_API_KEY ) && '' !== trim( WIRO_AI_API_KEY );
	}

	/**
	 * API key: the WIRO_AI_API_KEY constant when set, else the saved key.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( self::key_from_constant() ) {
			return trim( WIRO_AI_API_KEY );
		}
		$settings = self::get();
		return $settings['api_key'];
	}

	/**
	 * API secret: the WIRO_AI_API_SECRET constant when the key comes from
	 * wp-config.php, else the saved secret.
	 *
	 * @return string
	 */
	public static function api_secret() {
		if ( self::key_from_constant() ) {
			return defined( 'WIRO_AI_API_SECRET' ) && is_string( WIRO_AI_API_SECRET ) ? trim( WIRO_AI_API_SECRET ) : '';
		}
		$settings = self::get();
		return $settings['api_secret'];
	}

	/**
	 * Whether an API key is available.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== self::api_key();
	}

	/**
	 * Model used when the editor does not ask for a specific one.
	 *
	 * @return string Model slug.
	 */
	public static function default_model() {
		$settings = self::get();
		if ( self::CUSTOM_MODEL === $settings['model'] ) {
			$slug = Wiro_AI_Models::normalize_slug( $settings['custom_model'] );
			if ( '' !== $slug ) {
				return $slug;
			}
		}
		$catalog = Wiro_AI_Plugin::models();
		return isset( $catalog[ $settings['model'] ] ) ? $settings['model'] : Wiro_AI_Models::DEFAULT_MODEL;
	}

	/**
	 * The custom model slug, if one is saved.
	 *
	 * @return string
	 */
	public static function custom_model() {
		$settings = self::get();
		return Wiro_AI_Models::normalize_slug( $settings['custom_model'] );
	}

	/**
	 * Adds Settings > Wiro AI.
	 */
	public function add_page() {
		add_options_page(
			__( 'Wiro AI', 'wiro-ai' ),
			__( 'Wiro AI', 'wiro-ai' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wiro-ai' ) . '</a>' );
		return $links;
	}

	/**
	 * Registers the option, its sections and fields.
	 */
	public function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'wiro_ai_account',
			__( 'Wiro AI account', 'wiro-ai' ),
			array( $this, 'render_account_section' ),
			self::PAGE
		);
		add_settings_field( 'wiro_ai_api_key', __( 'API key', 'wiro-ai' ), array( $this, 'render_api_key_field' ), self::PAGE, 'wiro_ai_account', array( 'label_for' => 'wiro_ai_api_key' ) );
		add_settings_field( 'wiro_ai_api_secret', __( 'API secret', 'wiro-ai' ), array( $this, 'render_api_secret_field' ), self::PAGE, 'wiro_ai_account', array( 'label_for' => 'wiro_ai_api_secret' ) );

		add_settings_section(
			'wiro_ai_images',
			__( 'Image defaults', 'wiro-ai' ),
			array( $this, 'render_images_section' ),
			self::PAGE
		);
		add_settings_field( 'wiro_ai_model', __( 'Model', 'wiro-ai' ), array( $this, 'render_model_field' ), self::PAGE, 'wiro_ai_images', array( 'label_for' => 'wiro_ai_model' ) );
		add_settings_field( 'wiro_ai_custom_model', __( 'Other model', 'wiro-ai' ), array( $this, 'render_custom_model_field' ), self::PAGE, 'wiro_ai_images', array( 'label_for' => 'wiro_ai_custom_model' ) );
		add_settings_field( 'wiro_ai_ratio', __( 'Aspect ratio', 'wiro-ai' ), array( $this, 'render_ratio_field' ), self::PAGE, 'wiro_ai_images', array( 'label_for' => 'wiro_ai_ratio' ) );
		add_settings_field( 'wiro_ai_prompt_template', __( 'Prompt template', 'wiro-ai' ), array( $this, 'render_template_field' ), self::PAGE, 'wiro_ai_images', array( 'label_for' => 'wiro_ai_prompt_template' ) );
	}

	/**
	 * Cleans the submitted settings.
	 *
	 * An empty key or secret field keeps the saved value, so the page never
	 * has to print the key back into the form.
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string, string>
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get();
		$clean   = $current;

		if ( ! empty( $input['remove_key'] ) ) {
			$clean['api_key']    = '';
			$clean['api_secret'] = '';
		} else {
			foreach ( array( 'api_key', 'api_secret' ) as $field ) {
				if ( isset( $input[ $field ] ) && is_string( $input[ $field ] ) ) {
					$value = preg_replace( '/\s+/', '', sanitize_text_field( $input[ $field ] ) );
					if ( '' !== $value ) {
						$clean[ $field ] = substr( $value, 0, 200 );
					}
				}
			}
		}

		if ( isset( $input['model'] ) && is_string( $input['model'] ) ) {
			$model   = sanitize_text_field( $input['model'] );
			$catalog = Wiro_AI_Plugin::models();
			if ( self::CUSTOM_MODEL === $model || isset( $catalog[ $model ] ) ) {
				$clean['model'] = $model;
			}
		}

		if ( isset( $input['custom_model'] ) && is_string( $input['custom_model'] ) ) {
			$raw  = sanitize_text_field( $input['custom_model'] );
			$slug = Wiro_AI_Models::normalize_slug( $raw );
			if ( '' !== $raw && '' === $slug ) {
				add_settings_error(
					self::OPTION,
					'wiro_ai_custom_model',
					__( 'The other model must look like owner/model, for example google/nano-banana-2-1. It was not saved.', 'wiro-ai' )
				);
			} else {
				$clean['custom_model'] = $slug;
			}
		}
		if ( self::CUSTOM_MODEL === $clean['model'] && '' === $clean['custom_model'] ) {
			$clean['model'] = Wiro_AI_Models::DEFAULT_MODEL;
		}

		if ( isset( $input['ratio'] ) && is_string( $input['ratio'] ) && Wiro_AI_Models::is_ratio( $input['ratio'] ) ) {
			$clean['ratio'] = $input['ratio'];
		}

		if ( isset( $input['prompt_template'] ) && is_string( $input['prompt_template'] ) ) {
			$template                 = trim( sanitize_textarea_field( $input['prompt_template'] ) );
			$clean['prompt_template'] = '' === $template ? self::default_template() : mb_substr( $template, 0, 1000 );
		}

		return $clean;
	}

	/**
	 * Intro of the account section.
	 */
	public function render_account_section() {
		echo '<p>';
		printf(
			wp_kses(
				/* translators: %s: URL of the page where a Wiro AI project is created. */
				__( 'Create a project at <a href="%s" target="_blank" rel="noopener noreferrer">wiro.ai/panel/project/new</a> and paste its API key here. Images are billed to that project.', 'wiro-ai' ),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_url( 'https://wiro.ai/panel/project/new' )
		);
		echo '</p>';
	}

	/**
	 * Intro of the image section.
	 */
	public function render_images_section() {
		echo '<p>' . esc_html__( 'These are the starting values in the editor. Each image can use a different model, ratio and prompt.', 'wiro-ai' ) . '</p>';
	}

	/**
	 * API key field.
	 */
	public function render_api_key_field() {
		if ( self::key_from_constant() ) {
			echo '<p>' . esc_html__( 'The key is set with the WIRO_AI_API_KEY constant in wp-config.php.', 'wiro-ai' ) . '</p>';
			return;
		}
		$settings = self::get();
		$has_key  = '' !== $settings['api_key'];
		printf(
			'<input type="password" id="wiro_ai_api_key" name="%1$s[api_key]" value="" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $has_key ? __( 'Saved. Leave empty to keep it.', 'wiro-ai' ) : '' )
		);
		if ( $has_key ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: last four characters of the saved API key. */
				esc_html__( 'A key ending in %s is saved.', 'wiro-ai' ),
				'<code>' . esc_html( substr( $settings['api_key'], -4 ) ) . '</code>'
			);
			echo '</p>';
			printf(
				'<p><label><input type="checkbox" name="%s[remove_key]" value="1" /> %s</label></p>',
				esc_attr( self::OPTION ),
				esc_html__( 'Remove the saved key and secret', 'wiro-ai' )
			);
		}
	}

	/**
	 * API secret field.
	 */
	public function render_api_secret_field() {
		if ( self::key_from_constant() ) {
			echo '<p>' . esc_html__( 'Set the secret with the WIRO_AI_API_SECRET constant, if your project uses signature authentication.', 'wiro-ai' ) . '</p>';
			return;
		}
		$settings   = self::get();
		$has_secret = '' !== $settings['api_secret'];
		printf(
			'<input type="password" id="wiro_ai_api_secret" name="%1$s[api_secret]" value="" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $has_secret ? __( 'Saved. Leave empty to keep it.', 'wiro-ai' ) : '' )
		);
		echo '<p class="description">' . esc_html__( 'Only for projects that use signature authentication. Leave it empty for API key only projects.', 'wiro-ai' ) . '</p>';
	}

	/**
	 * Default model menu.
	 */
	public function render_model_field() {
		$settings = self::get();
		printf( '<select id="wiro_ai_model" name="%s[model]">', esc_attr( self::OPTION ) );
		foreach ( Wiro_AI_Plugin::models() as $slug => $model ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $settings['model'], $slug, false ),
				esc_html( $model['label'] )
			);
		}
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( self::CUSTOM_MODEL ),
			selected( $settings['model'], self::CUSTOM_MODEL, false ),
			esc_html__( 'Other model (below)', 'wiro-ai' )
		);
		echo '</select>';
	}

	/**
	 * Custom model slug field.
	 */
	public function render_custom_model_field() {
		$settings = self::get();
		printf(
			'<input type="text" id="wiro_ai_custom_model" name="%1$s[custom_model]" value="%2$s" class="regular-text" placeholder="owner/model" spellcheck="false" />',
			esc_attr( self::OPTION ),
			esc_attr( $settings['custom_model'] )
		);
		echo '<p class="description">';
		printf(
			wp_kses(
				/* translators: %s: URL of the Wiro AI model catalog. */
				__( 'Any text-to-image model from <a href="%s" target="_blank" rel="noopener noreferrer">wiro.ai/models</a>, by the owner/model part of its address. It also shows up in the editor\'s model menu.', 'wiro-ai' ),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_url( 'https://wiro.ai/models' )
		);
		echo '</p>';
	}

	/**
	 * Default aspect ratio menu.
	 */
	public function render_ratio_field() {
		$settings = self::get();
		printf( '<select id="wiro_ai_ratio" name="%s[ratio]">', esc_attr( self::OPTION ) );
		foreach ( Wiro_AI_Plugin::ratio_labels() as $ratio => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $ratio ),
				selected( $settings['ratio'], $ratio, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Prompt template field.
	 */
	public function render_template_field() {
		$settings = self::get();
		printf(
			'<textarea id="wiro_ai_prompt_template" name="%1$s[prompt_template]" rows="3" class="large-text">%2$s</textarea>',
			esc_attr( self::OPTION ),
			esc_textarea( $settings['prompt_template'] )
		);
		echo '<p class="description">' . esc_html__( 'Used by the "From title" and "From content" buttons in the editor. {title} becomes the post title and {content} the first words of the post, or nothing for "From title".', 'wiro-ai' ) . '</p>';
	}

	/**
	 * Settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$result = get_transient( 'wiro_ai_check_' . get_current_user_id() );
		if ( false !== $result ) {
			delete_transient( 'wiro_ai_check_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wiro AI', 'wiro-ai' ); ?></h1>
			<?php if ( is_array( $result ) && isset( $result['ok'], $result['message'] ) ) : ?>
				<div class="notice <?php echo esc_attr( $result['ok'] ? 'notice-success' : 'notice-error' ); ?> is-dismissible">
					<p><?php echo esc_html( $result['message'] ); ?></p>
				</div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Generate featured images and in-post images in the block editor. Open a post, then use the Wiro AI panel in the Post sidebar, or add a Wiro AI Image block.', 'wiro-ai' ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<?php if ( self::has_key() ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Connection', 'wiro-ai' ); ?></h2>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="wiro_ai_check_connection" />
					<?php wp_nonce_field( 'wiro_ai_check_connection' ); ?>
					<p><?php esc_html_e( 'Check that Wiro AI accepts the saved key. This is free.', 'wiro-ai' ); ?></p>
					<?php submit_button( __( 'Check connection', 'wiro-ai' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handles the Check connection button.
	 */
	public function handle_check_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wiro-ai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wiro_ai_check_connection' );

		$check  = Wiro_AI_Client::from_settings()->check_key();
		$result = is_wp_error( $check )
			? array(
				'ok'      => false,
				'message' => $check->get_error_message(),
			)
			: array(
				'ok'      => true,
				'message' => __( 'Connected. Wiro AI accepted the key.', 'wiro-ai' ),
			);
		set_transient( 'wiro_ai_check_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}
}
