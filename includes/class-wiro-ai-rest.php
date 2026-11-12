<?php
/**
 * REST routes used by the editor scripts.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /wiro-ai/v1/tasks starts an image, GET /wiro-ai/v1/tasks/{id} reports
 * its progress and saves it to the Media Library once it is ready.
 *
 * The Wiro AI task token stays on the server: the editor only sees a random
 * ID that belongs to the user who started the task.
 */
class Wiro_AI_REST {

	/**
	 * Route namespace.
	 */
	const NAMESPACE_V1 = 'wiro-ai/v1';

	/**
	 * How long a task can be looked up after it starts, in seconds.
	 */
	const TASK_TTL = 7200;

	/**
	 * Longest prompt accepted, in characters.
	 */
	const MAX_PROMPT = 4000;

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
		register_rest_route(
			self::NAMESPACE_V1,
			'/tasks',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_task' ),
				'permission_callback' => array( $this, 'can_create' ),
				'args'                => array(
					'prompt'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => array( $this, 'validate_prompt' ),
					),
					'model'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ratio'   => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), Wiro_AI_Models::RATIOS ),
					),
					'post_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'title'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'purpose' => array(
						'type'    => 'string',
						'default' => 'featured',
						'enum'    => array( 'featured', 'block' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/tasks/(?P<id>[A-Za-z0-9]{32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_task' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'id' => array(
						'type'    => 'string',
						'pattern' => '^[A-Za-z0-9]{32}$',
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may generate images.
	 *
	 * @return true|WP_Error
	 */
	public function can_generate() {
		if ( Wiro_AI_Plugin::current_user_can_generate() ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to generate images.', 'wiro-ai' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Whether the current user may start an image for the given post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_create( $request ) {
		$allowed = $this->can_generate();
		if ( true !== $allowed ) {
			return $allowed;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to edit this post.', 'wiro-ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Checks that the prompt has text and is not too long.
	 *
	 * @param mixed $value Prompt.
	 * @return true|WP_Error
	 */
	public function validate_prompt( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return new WP_Error( 'wiro_ai_empty_prompt', __( 'Write a prompt first.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $value ) > self::MAX_PROMPT ) {
			return new WP_Error(
				'wiro_ai_long_prompt',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'The prompt is too long. Keep it under %d characters.', 'wiro-ai' ), self::MAX_PROMPT ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Starts an image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_task( $request ) {
		if ( ! Wiro_AI_Settings::has_key() ) {
			return new WP_Error( 'wiro_ai_no_key', __( 'Add your Wiro AI API key in Settings > Wiro AI.', 'wiro-ai' ), array( 'status' => 400 ) );
		}

		$model = (string) $request->get_param( 'model' );
		$slug  = '' === $model ? Wiro_AI_Settings::default_model() : Wiro_AI_Models::normalize_slug( $model );
		if ( '' === $slug || ! array_key_exists( $slug, Wiro_AI_Plugin::models( true ) ) ) {
			return new WP_Error( 'wiro_ai_bad_model', __( 'Pick one of the models in the menu.', 'wiro-ai' ), array( 'status' => 400 ) );
		}

		$ratio = (string) $request->get_param( 'ratio' );
		if ( ! Wiro_AI_Models::is_ratio( $ratio ) ) {
			$settings = Wiro_AI_Settings::get();
			$ratio    = $settings['ratio'];
		}

		$prompt  = trim( (string) $request->get_param( 'prompt' ) );
		$post_id = (int) $request->get_param( 'post_id' );
		$client  = Wiro_AI_Client::from_settings();
		$catalog = Wiro_AI_Plugin::models( true );
		$preset  = isset( $catalog[ $slug ]['params'] ) && is_array( $catalog[ $slug ]['params'] ) ? $catalog[ $slug ]['params'] : array();
		$params  = Wiro_AI_Models::build_params( $this->model_items( $client, $slug ), $prompt, $ratio, $preset );

		/**
		 * Filters the parameters sent to Wiro AI for one image.
		 *
		 * @param array  $params Run parameters.
		 * @param string $slug   Model slug.
		 * @param array  $context Prompt, ratio, post ID and purpose of the image.
		 */
		$params = apply_filters(
			'wiro_ai_run_params',
			$params,
			$slug,
			array(
				'prompt'  => $prompt,
				'ratio'   => $ratio,
				'post_id' => $post_id,
				'purpose' => (string) $request->get_param( 'purpose' ),
			)
		);

		$run = $client->run( $slug, is_array( $params ) ? $params : array() );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$id   = wp_generate_password( 32, false, false );
		$task = array(
			'user_id' => get_current_user_id(),
			'post_id' => $post_id,
			'token'   => (string) $run['socketaccesstoken'],
			'task_id' => isset( $run['taskid'] ) ? (string) $run['taskid'] : '',
			'model'   => $slug,
			'prompt'  => $prompt,
			'title'   => (string) $request->get_param( 'title' ),
			'purpose' => (string) $request->get_param( 'purpose' ),
			'errors'  => 0,
			'started' => time(),
		);
		set_transient( self::task_key( $id ), $task, self::TASK_TTL );

		return rest_ensure_response(
			array(
				'id'     => $id,
				'status' => 'running',
				'stage'  => 'queued',
				'model'  => $slug,
			)
		);
	}

	/**
	 * Reports a task's progress and saves the image once it is ready.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_task( $request ) {
		$id   = (string) $request->get_param( 'id' );
		$task = get_transient( self::task_key( $id ) );
		if ( ! is_array( $task ) || get_current_user_id() !== (int) $task['user_id'] ) {
			return new WP_Error( 'wiro_ai_task_not_found', __( 'This image task was not found. It may have expired.', 'wiro-ai' ), array( 'status' => 404 ) );
		}
		if ( $task['post_id'] > 0 && ! current_user_can( 'edit_post', $task['post_id'] ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to edit this post.', 'wiro-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! empty( $task['attachment_id'] ) && wp_attachment_is_image( $task['attachment_id'] ) ) {
			return rest_ensure_response( $this->done_response( $task ) );
		}
		if ( ! empty( $task['saving'] ) && time() - (int) $task['saving'] < 180 ) {
			return rest_ensure_response( $this->running_response( 'saving', $task ) );
		}

		$detail = Wiro_AI_Client::from_settings()->task_detail( $task['token'] );
		if ( is_wp_error( $detail ) ) {
			++$task['errors'];
			set_transient( self::task_key( $id ), $task, self::TASK_TTL );
			if ( $task['errors'] >= 5 ) {
				return $this->failed_response( $id, $detail->get_error_message() );
			}
			return rest_ensure_response( $this->running_response( 'queued', $task ) );
		}

		$task['errors'] = 0;
		$status         = isset( $detail['status'] ) ? (string) $detail['status'] : '';
		if ( ! in_array( $status, Wiro_AI_Models::DONE_STATUSES, true ) ) {
			set_transient( self::task_key( $id ), $task, self::TASK_TTL );
			return rest_ensure_response( $this->running_response( Wiro_AI_Models::status_stage( $status ), $task ) );
		}

		if ( 'task_cancel' === $status ) {
			return $this->failed_response( $id, __( 'The task was cancelled on Wiro AI.', 'wiro-ai' ) );
		}
		$pexit = isset( $detail['pexit'] ) ? trim( (string) $detail['pexit'] ) : '';
		if ( '0' !== $pexit ) {
			$reason = isset( $detail['debugerror'] ) && is_scalar( $detail['debugerror'] ) ? trim( wp_strip_all_tags( (string) $detail['debugerror'] ) ) : '';
			if ( mb_strlen( $reason ) > 300 ) {
				$reason = '...' . mb_substr( $reason, -300 );
			}
			$message = __( 'The model could not make this image. Try another prompt or model.', 'wiro-ai' );
			if ( '' !== $reason ) {
				$message .= ' ' . $reason;
			}
			return $this->failed_response( $id, $message );
		}

		$output = Wiro_AI_Models::pick_image_output( isset( $detail['outputs'] ) ? $detail['outputs'] : array() );
		if ( null === $output ) {
			return $this->failed_response( $id, __( 'The model finished without an image. Pick an image model and try again.', 'wiro-ai' ) );
		}

		$task['saving'] = time();
		set_transient( self::task_key( $id ), $task, self::TASK_TTL );

		$title = '' !== $task['title'] ? $task['title'] : wp_trim_words( $task['prompt'], 10, '' );
		$alt   = 'featured' === $task['purpose'] && '' !== $task['title'] ? $task['title'] : wp_trim_words( $task['prompt'], 25, '' );
		$cost  = isset( $detail['totalcost'] ) && is_numeric( $detail['totalcost'] ) ? (string) ( $detail['totalcost'] + 0 ) : '';

		$attachment_id = Wiro_AI_Media::import(
			$output['url'],
			$output['type'],
			array(
				'post_id' => $task['post_id'],
				'title'   => $title,
				'alt'     => $alt,
				'prompt'  => $task['prompt'],
				'model'   => $task['model'],
				'task_id' => $task['task_id'],
				'cost'    => $cost,
			)
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $this->failed_response( $id, $attachment_id->get_error_message() );
		}

		$task['attachment_id'] = $attachment_id;
		$task['cost']          = $cost;
		unset( $task['saving'] );
		set_transient( self::task_key( $id ), $task, self::TASK_TTL );

		return rest_ensure_response( $this->done_response( $task ) );
	}

	/**
	 * Parameter fields of a model, cached for twelve hours.
	 *
	 * @param Wiro_AI_Client $client Client.
	 * @param string         $slug   Model slug.
	 * @return array Parameter fields, or an empty array when they could not be read.
	 */
	private function model_items( Wiro_AI_Client $client, $slug ) {
		$key   = 'wiro_ai_model_' . md5( $slug );
		$items = get_transient( $key );
		if ( is_array( $items ) ) {
			return $items;
		}
		$tool = $client->tool_detail( $slug );
		if ( is_wp_error( $tool ) ) {
			return array();
		}
		$items = Wiro_AI_Models::parameter_items( $tool );
		set_transient( $key, $items, 12 * HOUR_IN_SECONDS );
		return $items;
	}

	/**
	 * Transient key of a task.
	 *
	 * @param string $id Task ID.
	 * @return string
	 */
	private static function task_key( $id ) {
		return 'wiro_ai_task_' . $id;
	}

	/**
	 * Response for a task that is still running.
	 *
	 * @param string $stage Stage name.
	 * @param array  $task  Stored task.
	 * @return array
	 */
	private function running_response( $stage, array $task ) {
		return array(
			'status'  => 'running',
			'stage'   => $stage,
			'elapsed' => max( 0, time() - (int) $task['started'] ),
		);
	}

	/**
	 * Response for a finished task.
	 *
	 * @param array $task Stored task with its attachment.
	 * @return array
	 */
	private function done_response( array $task ) {
		return array(
			'status'     => 'done',
			'stage'      => 'done',
			'attachment' => Wiro_AI_Media::describe( (int) $task['attachment_id'] ),
			'cost'       => isset( $task['cost'] ) ? (string) $task['cost'] : '',
			'model'      => $task['model'],
		);
	}

	/**
	 * Response for a failed task. The task is forgotten.
	 *
	 * @param string $id      Task ID.
	 * @param string $message Error message.
	 * @return WP_REST_Response
	 */
	private function failed_response( $id, $message ) {
		delete_transient( self::task_key( $id ) );
		return rest_ensure_response(
			array(
				'status' => 'failed',
				'stage'  => 'failed',
				'error'  => $message,
			)
		);
	}
}
