<?php
/**
 * REST routes used by the editor and admin scripts.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under wiro-ai/v1:
 *
 * - POST jobs: starts a job (image, edit, upscale, background, narration, alt, text).
 * - GET jobs/{id}: reports a job and saves its result when it is ready.
 * - GET models: the models offered for a task.
 * - GET model: the voices of a text-to-speech model.
 * - POST quote: the price line for a planned job.
 *
 * Wiro AI task tokens stay on the server. A job can only be read by the user
 * who started it, or by an administrator.
 */
class Wiro_AI_REST {

	/**
	 * Route namespace.
	 */
	const NAMESPACE_V1 = 'wiro-ai/v1';

	/**
	 * Registers the routes.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes() {
		$tasks = array_keys( Wiro_AI_Catalog::tasks() );

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_job' ),
				'permission_callback' => array( $this, 'can_start' ),
				'args'                => array(
					'task'          => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => $tasks,
					),
					'model'         => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_id'       => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'attachment_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'prompt'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => array( $this, 'validate_prompt' ),
					),
					'text'          => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'title'         => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ratio'         => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), Wiro_AI_Models::RATIOS ),
					),
					'voice'         => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'scale'         => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', '2', '4' ),
					),
					'source'        => array(
						'type'    => 'string',
						'default' => 'title',
						'enum'    => array( 'title', 'content' ),
					),
					'purpose'       => array(
						'type'    => 'string',
						'default' => 'featured',
						'enum'    => array( 'featured', 'block', 'bulk', 'media', 'editor' ),
					),
					'apply'         => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_models' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'task' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => $tasks,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/model',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_model' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'task'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => $tasks,
					),
					'model' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_quote' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'task'   => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => $tasks,
					),
					'model'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ratio'  => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), Wiro_AI_Models::RATIOS ),
					),
					'voice'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'scale'  => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', '2', '4' ),
					),
					'chars'  => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'images' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may use Wiro AI.
	 *
	 * @return true|WP_Error
	 */
	public function can_generate() {
		if ( Wiro_AI_Plugin::current_user_can_generate() ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to use Wiro AI on this site.', 'wiro-ai' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Whether the current user may start this job: Wiro AI access, plus edit
	 * rights on the post and on the source image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_start( $request ) {
		$allowed = $this->can_generate();
		if ( true !== $allowed ) {
			return $allowed;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to edit this post.', 'wiro-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}
		$attachment_id = (int) $request->get_param( 'attachment_id' );
		if ( $attachment_id > 0 ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
				return new WP_Error( 'wiro_ai_bad_source', __( 'Pick an image from the Media Library.', 'wiro-ai' ), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				return new WP_Error( 'rest_forbidden', __( 'You are not allowed to edit this image.', 'wiro-ai' ), array( 'status' => rest_authorization_required_code() ) );
			}
		}
		if ( 'bulk' === $request->get_param( 'purpose' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Only administrators can run bulk jobs.', 'wiro-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Checks the prompt length.
	 *
	 * @param mixed $value Prompt.
	 * @return true|WP_Error
	 */
	public function validate_prompt( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'wiro_ai_bad_prompt', __( 'The prompt must be text.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $value ) > Wiro_AI_Runner::MAX_PROMPT ) {
			return new WP_Error(
				'wiro_ai_long_prompt',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'The prompt is too long. Keep it under %d characters.', 'wiro-ai' ), Wiro_AI_Runner::MAX_PROMPT ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Starts a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( $request ) {
		$input = array();
		foreach ( array( 'task', 'model', 'prompt', 'text', 'title', 'ratio', 'voice', 'scale', 'source', 'purpose' ) as $key ) {
			$input[ $key ] = trim( (string) $request->get_param( $key ) );
		}
		$input['post_id']       = (int) $request->get_param( 'post_id' );
		$input['attachment_id'] = (int) $request->get_param( 'attachment_id' );
		$input['apply']         = (bool) $request->get_param( 'apply' );

		$result = Wiro_AI_Runner::start( $input );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Reports a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( $request ) {
		$job = Wiro_AI_Jobs::get( (int) $request->get_param( 'id' ) );
		if ( null === $job || ( get_current_user_id() !== $job['author'] && ! current_user_can( 'manage_options' ) ) ) {
			return new WP_Error( 'wiro_ai_job_not_found', __( 'This job was not found.', 'wiro-ai' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( Wiro_AI_Runner::poll( $job ) );
	}

	/**
	 * Models of a task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_models( $request ) {
		$task   = (string) $request->get_param( 'task' );
		$models = Wiro_AI_Catalog::models( $task );
		return rest_ensure_response(
			array(
				'default'     => Wiro_AI_Catalog::default_model( $task ),
				'recommended' => $models['recommended'],
				'all'         => $models['all'],
				'complete'    => $models['complete'],
			)
		);
	}

	/**
	 * Settings of one model the scripts need: its voices.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_model( $request ) {
		$task = (string) $request->get_param( 'task' );
		$slug = Wiro_AI_Models::normalize_slug( (string) $request->get_param( 'model' ) );
		if ( '' === $slug || ! Wiro_AI_Catalog::is_allowed( $task, $slug ) ) {
			return new WP_Error( 'wiro_ai_bad_model', __( 'Pick one of the models in the menu.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		$definition = Wiro_AI_Catalog::task( $task );
		if ( 'gateway' === $definition['source'] ) {
			return rest_ensure_response( array( 'voices' => array() ) );
		}
		$detail = Wiro_AI_Catalog::detail( $slug );
		if ( is_wp_error( $detail ) ) {
			return $detail;
		}
		$voices   = Wiro_AI_Models::voices( $detail['items'] );
		$settings = Wiro_AI_Settings::get();
		$default  = Wiro_AI_Catalog::default_model( 'narration' ) === $slug && in_array( $settings['voice'], $voices['options'], true ) ? $settings['voice'] : $voices['default'];
		return rest_ensure_response(
			array(
				'voices'        => $voices['options'],
				'default_voice' => $default,
				'max_chars'     => isset( $definition['max_chars'] ) ? (int) $definition['max_chars'] : 0,
			)
		);
	}

	/**
	 * Price line for a planned job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_quote( $request ) {
		$task  = (string) $request->get_param( 'task' );
		$model = (string) $request->get_param( 'model' );
		$slug  = '' !== $model ? Wiro_AI_Models::normalize_slug( $model ) : Wiro_AI_Catalog::default_model( $task );
		if ( '' === $slug || ! Wiro_AI_Catalog::is_allowed( $task, $slug ) ) {
			return new WP_Error( 'wiro_ai_bad_model', __( 'Pick one of the models in the menu.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		$values = array();
		foreach ( array( 'ratio', 'voice', 'scale' ) as $key ) {
			$value = (string) $request->get_param( $key );
			if ( '' !== $value ) {
				$values[ $key ] = $value;
			}
		}
		$quote = Wiro_AI_Catalog::quote(
			$task,
			$slug,
			$values,
			array(
				'chars'  => (int) $request->get_param( 'chars' ),
				'images' => (int) $request->get_param( 'images' ),
			)
		);
		return is_wp_error( $quote ) ? $quote : rest_ensure_response( $quote );
	}
}
