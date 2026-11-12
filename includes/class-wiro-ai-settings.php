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
 * Wiro AI > Settings: API key, a default model for every task, prompt
 * template, narration and upscale defaults, a monthly spending limit and who
 * may use Wiro AI.
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
	 * Capabilities the access setting can pick.
	 */
	const ACCESS = array( 'upload_files', 'edit_others_posts', 'manage_options' );

	/**
	 * Hooks the settings into the admin.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_wiro_ai_check_connection', array( $this, 'handle_check_connection' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'after_update' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( WIRO_AI_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Default values of every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'api_key'            => '',
			'api_secret'         => '',
			'models'             => array(),
			'ratio'              => Wiro_AI_Models::DEFAULT_RATIO,
			'prompt_template'    => self::default_template(),
			'voice'              => '',
			'narration_position' => 'top',
			'upscale_scale'      => '2',
			'monthly_limit'      => '0',
			'access'             => 'upload_files',
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
	 * @return array<string, mixed>
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		foreach ( $settings as $key => $value ) {
			if ( 'models' === $key ) {
				$settings[ $key ] = is_array( $value ) ? array_map( 'strval', $value ) : array();
			} else {
				$settings[ $key ] = is_scalar( $value ) ? (string) $value : '';
			}
		}
		return $settings;
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
	 * URL of the settings page.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Adds a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'wiro-ai' ) . '</a>' );
		return $links;
	}

	/**
	 * Drops cached model lists when the key changes.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 */
	public function after_update( $old_value, $value ) {
		$old_key = is_array( $old_value ) && isset( $old_value['api_key'] ) ? $old_value['api_key'] : '';
		$new_key = is_array( $value ) && isset( $value['api_key'] ) ? $value['api_key'] : '';
		if ( $old_key !== $new_key ) {
			Wiro_AI_Catalog::flush();
		}
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

		add_settings_section( 'wiro_ai_account', __( 'Wiro AI account', 'wiro-ai' ), array( $this, 'render_account_section' ), self::PAGE );
		add_settings_field( 'wiro_ai_api_key', __( 'API key', 'wiro-ai' ), array( $this, 'render_api_key_field' ), self::PAGE, 'wiro_ai_account', array( 'label_for' => 'wiro_ai_api_key' ) );
		add_settings_field( 'wiro_ai_api_secret', __( 'API secret', 'wiro-ai' ), array( $this, 'render_api_secret_field' ), self::PAGE, 'wiro_ai_account', array( 'label_for' => 'wiro_ai_api_secret' ) );

		add_settings_section( 'wiro_ai_models', __( 'Default models', 'wiro-ai' ), array( $this, 'render_models_section' ), self::PAGE );
		foreach ( Wiro_AI_Catalog::tasks() as $task => $definition ) {
			add_settings_field(
				'wiro_ai_model_' . $task,
				esc_html( $definition['label'] ),
				array( $this, 'render_model_field' ),
				self::PAGE,
				'wiro_ai_models',
				array(
					'label_for' => 'wiro_ai_model_' . $task,
					'task'      => $task,
				)
			);
		}

		add_settings_section( 'wiro_ai_defaults', __( 'Image and audio defaults', 'wiro-ai' ), array( $this, 'render_defaults_section' ), self::PAGE );
		add_settings_field( 'wiro_ai_ratio', __( 'Aspect ratio', 'wiro-ai' ), array( $this, 'render_ratio_field' ), self::PAGE, 'wiro_ai_defaults', array( 'label_for' => 'wiro_ai_ratio' ) );
		add_settings_field( 'wiro_ai_prompt_template', __( 'Prompt template', 'wiro-ai' ), array( $this, 'render_template_field' ), self::PAGE, 'wiro_ai_defaults', array( 'label_for' => 'wiro_ai_prompt_template' ) );
		add_settings_field( 'wiro_ai_upscale_scale', __( 'Upscale factor', 'wiro-ai' ), array( $this, 'render_upscale_field' ), self::PAGE, 'wiro_ai_defaults', array( 'label_for' => 'wiro_ai_upscale_scale' ) );
		add_settings_field( 'wiro_ai_voice', __( 'Narration voice', 'wiro-ai' ), array( $this, 'render_voice_field' ), self::PAGE, 'wiro_ai_defaults', array( 'label_for' => 'wiro_ai_voice' ) );
		add_settings_field( 'wiro_ai_narration_position', __( 'Audio player position', 'wiro-ai' ), array( $this, 'render_position_field' ), self::PAGE, 'wiro_ai_defaults', array( 'label_for' => 'wiro_ai_narration_position' ) );

		add_settings_section( 'wiro_ai_spending', __( 'Spending and access', 'wiro-ai' ), array( $this, 'render_spending_section' ), self::PAGE );
		add_settings_field( 'wiro_ai_monthly_limit', __( 'Monthly limit (USD)', 'wiro-ai' ), array( $this, 'render_limit_field' ), self::PAGE, 'wiro_ai_spending', array( 'label_for' => 'wiro_ai_monthly_limit' ) );
		add_settings_field( 'wiro_ai_access', __( 'Who can use Wiro AI', 'wiro-ai' ), array( $this, 'render_access_field' ), self::PAGE, 'wiro_ai_spending', array( 'label_for' => 'wiro_ai_access' ) );
	}

	/**
	 * Cleans the submitted settings.
	 *
	 * An empty key or secret field keeps the saved value, so the page never
	 * has to print the key back into the form.
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::get();

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

		if ( isset( $input['models'] ) && is_array( $input['models'] ) ) {
			foreach ( array_keys( Wiro_AI_Catalog::tasks() ) as $task ) {
				if ( ! isset( $input['models'][ $task ] ) || ! is_string( $input['models'][ $task ] ) ) {
					continue;
				}
				$slug = Wiro_AI_Models::normalize_slug( sanitize_text_field( $input['models'][ $task ] ) );
				if ( '' === $slug ) {
					unset( $clean['models'][ $task ] );
				} else {
					$clean['models'][ $task ] = $slug;
				}
			}
		}

		if ( isset( $input['ratio'] ) && is_string( $input['ratio'] ) && Wiro_AI_Models::is_ratio( $input['ratio'] ) ) {
			$clean['ratio'] = $input['ratio'];
		}

		if ( isset( $input['prompt_template'] ) && is_string( $input['prompt_template'] ) ) {
			$template                 = trim( sanitize_textarea_field( $input['prompt_template'] ) );
			$clean['prompt_template'] = '' === $template ? self::default_template() : mb_substr( $template, 0, 1000 );
		}

		if ( isset( $input['upscale_scale'] ) && in_array( $input['upscale_scale'], array( '2', '4' ), true ) ) {
			$clean['upscale_scale'] = $input['upscale_scale'];
		}

		if ( isset( $input['voice'] ) && is_string( $input['voice'] ) ) {
			$clean['voice'] = mb_substr( sanitize_text_field( $input['voice'] ), 0, 100 );
		}

		if ( isset( $input['narration_position'] ) && in_array( $input['narration_position'], array( 'top', 'bottom' ), true ) ) {
			$clean['narration_position'] = $input['narration_position'];
		}

		if ( isset( $input['monthly_limit'] ) && is_scalar( $input['monthly_limit'] ) ) {
			$limit = str_replace( ',', '.', trim( (string) $input['monthly_limit'] ) );
			if ( '' === $limit ) {
				$limit = '0';
			}
			if ( is_numeric( $limit ) && (float) $limit >= 0 && (float) $limit <= 100000 ) {
				$clean['monthly_limit'] = rtrim( rtrim( number_format( (float) $limit, 2, '.', '' ), '0' ), '.' );
			} else {
				add_settings_error( self::OPTION, 'wiro_ai_monthly_limit', __( 'The monthly limit must be a number of dollars, such as 20. It was not saved.', 'wiro-ai' ) );
			}
		}

		if ( isset( $input['access'] ) && in_array( $input['access'], self::ACCESS, true ) ) {
			$clean['access'] = $input['access'];
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
				__( 'Create a project at <a href="%s" target="_blank" rel="noopener noreferrer">wiro.ai/panel/project/new</a> and paste its API key here. Everything the plugin makes is billed to that project.', 'wiro-ai' ),
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
	 * Intro of the models section.
	 */
	public function render_models_section() {
		echo '<p>';
		printf(
			wp_kses(
				/* translators: %s: URL of the Wiro AI model catalog. */
				__( 'The models come from the <a href="%s" target="_blank" rel="noopener noreferrer">Wiro AI catalog</a>, with the price of each. These are the starting choices; the editor lets you pick another model for each request.', 'wiro-ai' ),
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
		if ( ! self::has_key() ) {
			echo '<p><em>' . esc_html__( 'Save an API key to load the full model lists.', 'wiro-ai' ) . '</em></p>';
		}
	}

	/**
	 * Intro of the defaults section.
	 */
	public function render_defaults_section() {
		echo '<p>' . esc_html__( 'Starting values for featured images, upscaling and narration.', 'wiro-ai' ) . '</p>';
	}

	/**
	 * Intro of the spending section.
	 */
	public function render_spending_section() {
		$total = Wiro_AI_Jobs::month_total();
		echo '<p>';
		printf(
			/* translators: %s: amount spent this month, such as $1.20. */
			esc_html__( 'Spent this month on this site: %s.', 'wiro-ai' ),
			'<strong>' . esc_html( Wiro_AI_Pricing::format_usd( $total ) ) . '</strong>'
		);
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=wiro-ai-usage' ) ) . '">' . esc_html__( 'See the usage history', 'wiro-ai' ) . '</a></p>';
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
	 * Model menu of one task.
	 *
	 * @param array $args Field arguments, with the task key.
	 */
	public function render_model_field( $args ) {
		$task    = $args['task'];
		$current = Wiro_AI_Catalog::default_model( $task );
		$models  = Wiro_AI_Catalog::models( $task );
		self::print_model_select( 'wiro_ai_model_' . $task, self::OPTION . '[models][' . $task . ']', $models, $current );
	}

	/**
	 * Prints a model menu with "Recommended" and "All in the catalog" groups.
	 *
	 * @param string $id      Element ID.
	 * @param string $name    Field name, or empty for none.
	 * @param array  $models  Lists from Wiro_AI_Catalog::models().
	 * @param string $current Selected slug.
	 */
	public static function print_model_select( $id, $name, array $models, $current ) {
		$known = array();
		printf( '<select id="%1$s"%2$s class="wiro-ai-model-select">', esc_attr( $id ), '' !== $name ? ' name="' . esc_attr( $name ) . '"' : '' );
		echo '<optgroup label="' . esc_attr__( 'Recommended', 'wiro-ai' ) . '">';
		foreach ( $models['recommended'] as $entry ) {
			$known[] = $entry['value'];
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $entry['value'] ), selected( $current, $entry['value'], false ), esc_html( $entry['label'] ) );
		}
		echo '</optgroup>';
		if ( $models['all'] ) {
			echo '<optgroup label="' . esc_attr__( 'All in the catalog', 'wiro-ai' ) . '">';
			foreach ( $models['all'] as $entry ) {
				$known[] = $entry['value'];
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $entry['value'] ), selected( $current, $entry['value'], false ), esc_html( $entry['label'] ) );
			}
			echo '</optgroup>';
		}
		if ( '' !== $current && ! in_array( $current, $known, true ) ) {
			printf( '<option value="%1$s" selected>%1$s</option>', esc_attr( $current ) );
		}
		echo '</select>';
	}

	/**
	 * Default aspect ratio menu.
	 */
	public function render_ratio_field() {
		$settings = self::get();
		printf( '<select id="wiro_ai_ratio" name="%s[ratio]">', esc_attr( self::OPTION ) );
		foreach ( Wiro_AI_Plugin::ratio_labels() as $ratio => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $ratio ), selected( $settings['ratio'], $ratio, false ), esc_html( $label ) );
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
		echo '<p class="description">' . esc_html__( 'Used to build featured image prompts from a post. {title} becomes the post title and {content} the first words of the post, or nothing when only the title is used.', 'wiro-ai' ) . '</p>';
	}

	/**
	 * Upscale factor menu.
	 */
	public function render_upscale_field() {
		$settings = self::get();
		printf( '<select id="wiro_ai_upscale_scale" name="%s[upscale_scale]">', esc_attr( self::OPTION ) );
		foreach ( array( '2', '4' ) as $scale ) {
			/* translators: %s: upscale factor, 2 or 4. */
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $scale ), selected( $settings['upscale_scale'], $scale, false ), esc_html( sprintf( __( '%sx', 'wiro-ai' ), $scale ) ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Sent to upscale models that take a factor.', 'wiro-ai' ) . '</p>';
	}

	/**
	 * Default narration voice, from the default narration model.
	 */
	public function render_voice_field() {
		$settings = self::get();
		$slug     = Wiro_AI_Catalog::default_model( 'narration' );
		$detail   = self::has_key() ? Wiro_AI_Catalog::detail( $slug ) : null;
		$voices   = is_array( $detail ) ? Wiro_AI_Models::voices( $detail['items'] ) : array(
			'options' => array(),
			'default' => '',
		);
		if ( ! $voices['options'] ) {
			printf( '<input type="text" id="wiro_ai_voice" name="%1$s[voice]" value="%2$s" class="regular-text" />', esc_attr( self::OPTION ), esc_attr( $settings['voice'] ) );
		} else {
			$current = in_array( $settings['voice'], $voices['options'], true ) ? $settings['voice'] : $voices['default'];
			printf( '<select id="wiro_ai_voice" name="%s[voice]">', esc_attr( self::OPTION ) );
			foreach ( $voices['options'] as $voice ) {
				printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $voice ), selected( $current, $voice, false ) );
			}
			echo '</select>';
		}
		echo '<p class="description">';
		printf(
			/* translators: %s: model slug such as elevenlabs/eleven-v4-turbo. */
			esc_html__( 'Voices of %s. Save after changing the narration model to see its voices.', 'wiro-ai' ),
			'<code>' . esc_html( $slug ) . '</code>'
		);
		echo '</p>';
	}

	/**
	 * Where the narration player goes in the post.
	 */
	public function render_position_field() {
		$settings = self::get();
		printf( '<select id="wiro_ai_narration_position" name="%s[narration_position]">', esc_attr( self::OPTION ) );
		printf( '<option value="top" %1$s>%2$s</option>', selected( $settings['narration_position'], 'top', false ), esc_html__( 'At the top of the post', 'wiro-ai' ) );
		printf( '<option value="bottom" %1$s>%2$s</option>', selected( $settings['narration_position'], 'bottom', false ), esc_html__( 'At the end of the post', 'wiro-ai' ) );
		echo '</select>';
	}

	/**
	 * Monthly spending limit field.
	 */
	public function render_limit_field() {
		$settings = self::get();
		printf(
			'<input type="number" id="wiro_ai_monthly_limit" name="%1$s[monthly_limit]" value="%2$s" min="0" step="0.01" class="small-text" />',
			esc_attr( self::OPTION ),
			esc_attr( $settings['monthly_limit'] )
		);
		echo '<p class="description">' . esc_html__( 'New requests stop when this month\'s spend on this site reaches the limit. 0 means no limit.', 'wiro-ai' ) . '</p>';
	}

	/**
	 * Access level menu.
	 */
	public function render_access_field() {
		$settings = self::get();
		$labels   = array(
			'upload_files'      => __( 'Authors, editors and administrators', 'wiro-ai' ),
			'edit_others_posts' => __( 'Editors and administrators', 'wiro-ai' ),
			'manage_options'    => __( 'Administrators only', 'wiro-ai' ),
		);
		printf( '<select id="wiro_ai_access" name="%s[access]">', esc_attr( self::OPTION ) );
		foreach ( $labels as $capability => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $capability ), selected( $settings['access'], $capability, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Bulk images, usage and settings stay with administrators.', 'wiro-ai' ) . '</p>';
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
			<h1><?php esc_html_e( 'Wiro AI settings', 'wiro-ai' ); ?></h1>
			<?php settings_errors(); ?>
			<?php if ( is_array( $result ) && isset( $result['ok'], $result['message'] ) ) : ?>
				<div class="notice <?php echo esc_attr( $result['ok'] ? 'notice-success' : 'notice-error' ); ?> is-dismissible">
					<p><?php echo esc_html( $result['message'] ); ?></p>
				</div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Wiro AI works in the block editor (the Wiro AI sidebar, the featured image panel, the Wiro AI Image block and the toolbar of Image blocks), in the Media Library and on the Bulk images screen.', 'wiro-ai' ); ?></p>
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

		wp_safe_redirect( self::url() );
		exit;
	}
}
