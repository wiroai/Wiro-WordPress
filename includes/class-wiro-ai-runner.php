<?php
/**
 * Starts, follows and finishes Wiro AI jobs.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image, edit, upscale, background and narration jobs run as Wiro AI tasks:
 * they start with Run, the editor polls them, and the result is saved to the
 * Media Library. Alt text and text suggestions are answered right away by the
 * LLM gateway.
 */
class Wiro_AI_Runner {

	/**
	 * Longest prompt accepted, in characters.
	 */
	const MAX_PROMPT = 4000;

	/**
	 * Longest post text sent for text suggestions, in characters.
	 */
	const MAX_TEXT = 6000;

	/**
	 * Starts a job.
	 *
	 * @param array $input Validated request values: task, model, post_id,
	 *                     attachment_id, prompt, text, title, ratio, voice,
	 *                     scale, source, purpose, apply.
	 * @return array|WP_Error Response for the scripts.
	 */
	public static function start( array $input ) {
		$task       = (string) $input['task'];
		$definition = Wiro_AI_Catalog::task( $task );
		if ( null === $definition ) {
			return new WP_Error( 'wiro_ai_bad_task', __( 'Unknown task.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		if ( ! Wiro_AI_Settings::has_key() ) {
			return new WP_Error( 'wiro_ai_no_key', __( 'Add your Wiro AI API key in Wiro AI > Settings.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		$limit = Wiro_AI_Jobs::limit_error();
		if ( null !== $limit ) {
			return $limit;
		}

		$slug = '' !== $input['model'] ? Wiro_AI_Models::normalize_slug( $input['model'] ) : Wiro_AI_Catalog::default_model( $task );
		if ( '' === $slug || ! Wiro_AI_Catalog::is_allowed( $task, $slug ) ) {
			return new WP_Error( 'wiro_ai_bad_model', __( 'Pick one of the models in the menu.', 'wiro-ai' ), array( 'status' => 400 ) );
		}

		if ( 'alt' === $task ) {
			return self::run_alt( $slug, $input );
		}
		if ( 'text' === $task ) {
			return self::run_text( $slug, $input );
		}
		return self::start_run( $task, $slug, $input, $definition );
	}

	/**
	 * Checks a running job and finishes it when its task is done.
	 *
	 * @param array $job Job record.
	 * @return array Response for the scripts.
	 */
	public static function poll( array $job ) {
		if ( 'done' === $job['state'] ) {
			return self::done_response( $job );
		}
		if ( 'failed' === $job['state'] ) {
			return self::failed_response( $job );
		}
		if ( ! empty( $job['saving'] ) && time() - (int) $job['saving'] < 180 ) {
			return self::running_response( $job, 'saving' );
		}

		$detail = Wiro_AI_Client::from_settings()->task_detail( isset( $job['token'] ) ? (string) $job['token'] : '' );
		if ( is_wp_error( $detail ) ) {
			$errors = isset( $job['errors'] ) ? (int) $job['errors'] + 1 : 1;
			if ( $errors >= 5 ) {
				return self::fail( $job, $detail->get_error_message() );
			}
			Wiro_AI_Jobs::update( $job['id'], array( 'errors' => $errors ) );
			return self::running_response( $job, isset( $job['stage'] ) ? $job['stage'] : 'queued' );
		}

		$status = isset( $detail['status'] ) ? (string) $detail['status'] : '';
		if ( ! in_array( $status, Wiro_AI_Models::DONE_STATUSES, true ) ) {
			return self::running_response( $job, Wiro_AI_Models::status_stage( $status ) );
		}
		if ( 'task_cancel' === $status ) {
			return self::fail( $job, __( 'The task was cancelled on Wiro AI.', 'wiro-ai' ) );
		}
		$pexit = isset( $detail['pexit'] ) ? trim( (string) $detail['pexit'] ) : '';
		if ( '0' !== $pexit ) {
			$reason = isset( $detail['debugerror'] ) && is_scalar( $detail['debugerror'] ) ? trim( wp_strip_all_tags( (string) $detail['debugerror'] ) ) : '';
			if ( mb_strlen( $reason ) > 300 ) {
				$reason = '...' . mb_substr( $reason, -300 );
			}
			$message = __( 'The model could not finish this task. Try another model or prompt.', 'wiro-ai' );
			return self::fail( $job, '' !== $reason ? $message . ' ' . $reason : $message );
		}

		$definition = Wiro_AI_Catalog::task( $job['task'] );
		$kind       = null !== $definition && isset( $definition['output'] ) ? $definition['output'] : 'image';
		$output     = Wiro_AI_Models::pick_output( isset( $detail['outputs'] ) ? $detail['outputs'] : array(), $kind );
		if ( null === $output ) {
			return self::fail(
				$job,
				'audio' === $kind
					? __( 'The model finished without an audio file. Pick a text-to-speech model and try again.', 'wiro-ai' )
					: __( 'The model finished without an image. Pick an image model and try again.', 'wiro-ai' )
			);
		}

		Wiro_AI_Jobs::update( $job['id'], array( 'saving' => time() ) );
		$cost   = isset( $detail['totalcost'] ) && is_numeric( $detail['totalcost'] ) ? (float) $detail['totalcost'] : 0.0;
		$import = self::import_details( $job, $kind, $cost );

		$attachment_id = Wiro_AI_Media::import( $output['url'], $output['type'], $import );
		if ( is_wp_error( $attachment_id ) ) {
			return self::fail( $job, $attachment_id->get_error_message(), $cost );
		}

		if ( 'image' === $job['task'] && ! empty( $job['apply'] ) && ! empty( $job['post_id'] ) ) {
			set_post_thumbnail( (int) $job['post_id'], $attachment_id );
		}

		$job = Wiro_AI_Jobs::finish(
			$job['id'],
			'done',
			array(
				'cost'   => $cost,
				'result' => $attachment_id,
				'saving' => 0,
			)
		);
		return null === $job ? array(
			'status' => 'failed',
			'error'  => __( 'The job record is missing.', 'wiro-ai' ),
		) : self::done_response( $job );
	}

	/**
	 * Builds the prompt of an image from a post, with the prompt template.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $source  "title" or "content".
	 * @return string
	 */
	public static function prompt_from_post( $post_id, $source = 'title' ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return '';
		}
		$settings = Wiro_AI_Settings::get();
		$title    = self::title_of( $post->ID );
		$content  = '';
		if ( 'content' === $source ) {
			$content = wp_trim_words( self::plain_text( $post->post_content ), 60, '...' );
		}
		if ( '' === $title && '' === $content ) {
			return '';
		}
		$prompt = str_replace( array( '{title}', '{content}' ), array( $title, $content ), $settings['prompt_template'] );
		$prompt = preg_replace( '/\s+([.,;:!?])/', '$1', preg_replace( '/\s+/', ' ', $prompt ) );
		return trim( $prompt );
	}

	/**
	 * A post's title as plain text: no tags, no HTML entities such as &#8217;.
	 *
	 * @param int $post_id Post or attachment ID.
	 * @return string
	 */
	public static function title_of( $post_id ) {
		$title = (string) get_post_field( 'post_title', (int) $post_id, 'raw' );
		return trim( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Plain text of post content: no blocks, shortcodes or tags.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function plain_text( $content ) {
		$content = strip_shortcodes( (string) $content );
		$content = preg_replace( '/<!--.*?-->/s', ' ', $content );
		$content = preg_replace( '#</(p|h[1-6]|li|div|blockquote|figcaption|td|th)>#i', '$0 ', $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $content ) );
	}

	/**
	 * Starts a Wiro AI task.
	 *
	 * @param string $task       Task key.
	 * @param string $slug       Model slug.
	 * @param array  $input      Request values.
	 * @param array  $definition Task definition.
	 * @return array|WP_Error
	 */
	private static function start_run( $task, $slug, array $input, array $definition ) {
		$post_id       = (int) $input['post_id'];
		$attachment_id = (int) $input['attachment_id'];
		$values        = array();
		$stored        = '';
		$title         = (string) $input['title'];

		if ( ! empty( $definition['input'] ) ) {
			if ( $attachment_id <= 0 ) {
				return new WP_Error( 'wiro_ai_no_image', __( 'Pick an image first.', 'wiro-ai' ), array( 'status' => 400 ) );
			}
			$source = Wiro_AI_Media::source_file( $attachment_id );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			// Wiro AI refuses a second upload with the same file name, so each upload gets its own.
			$extension = strtolower( pathinfo( $source['name'], PATHINFO_EXTENSION ) );
			$name      = sprintf( 'wordpress-%d-%s.%s', $attachment_id, strtolower( wp_generate_password( 10, false, false ) ), $extension );
			$url       = Wiro_AI_Client::from_settings()->upload_file( $source['path'], $name, $source['mime'] );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$values['image_url'] = $url;
			if ( '' === $title ) {
				$title = self::title_of( $attachment_id );
			}
		}

		switch ( $task ) {
			case 'image':
				$prompt = (string) $input['prompt'];
				if ( '' === $prompt && $post_id > 0 ) {
					$prompt = self::prompt_from_post( $post_id, (string) $input['source'] );
				}
				if ( '' === $prompt ) {
					return new WP_Error( 'wiro_ai_empty_prompt', __( 'Write a prompt first.', 'wiro-ai' ), array( 'status' => 400 ) );
				}
				if ( '' === $title && $post_id > 0 ) {
					$title = self::title_of( $post_id );
				}
				$values['prompt'] = $prompt;
				$values['ratio']  = Wiro_AI_Models::is_ratio( $input['ratio'] ) ? $input['ratio'] : Wiro_AI_Settings::get()['ratio'];
				$stored           = $prompt;
				/* translators: %s: post title or the start of the prompt. */
				$label = sprintf( __( 'Image: %s', 'wiro-ai' ), '' !== $title ? $title : wp_trim_words( $prompt, 8, '...' ) );
				break;

			case 'edit':
				$prompt = (string) $input['prompt'];
				if ( '' === $prompt ) {
					return new WP_Error( 'wiro_ai_empty_prompt', __( 'Describe the change first.', 'wiro-ai' ), array( 'status' => 400 ) );
				}
				$values['prompt'] = $prompt;
				$stored           = $prompt;
				/* translators: %s: image title. */
				$label = sprintf( __( 'Edit: %s', 'wiro-ai' ), $title );
				break;

			case 'upscale':
				$values['scale'] = in_array( (string) $input['scale'], array( '2', '4' ), true ) ? (string) $input['scale'] : Wiro_AI_Settings::get()['upscale_scale'];
				/* translators: %s: image title. */
				$label = sprintf( __( 'Upscale: %s', 'wiro-ai' ), $title );
				break;

			case 'background':
				/* translators: %s: image title. */
				$label = sprintf( __( 'Remove background: %s', 'wiro-ai' ), $title );
				break;

			case 'narration':
				$text = self::plain_text( (string) $input['text'] );
				if ( '' === $text && $post_id > 0 ) {
					$post = get_post( $post_id );
					$text = $post ? trim( self::title_of( $post->ID ) . '. ' . self::plain_text( $post->post_content ) ) : '';
				}
				$text = self::cap_text( $text, isset( $definition['max_chars'] ) ? (int) $definition['max_chars'] : 10000 );
				if ( '' === $text ) {
					return new WP_Error( 'wiro_ai_no_text', __( 'There is no text to read yet.', 'wiro-ai' ), array( 'status' => 400 ) );
				}
				if ( '' === $title && $post_id > 0 ) {
					$title = self::title_of( $post_id );
				}
				$values['prompt'] = $text;
				$values['voice']  = (string) $input['voice'];
				$stored           = mb_substr( $text, 0, 500 );
				/* translators: %s: post title. */
				$label = sprintf( __( 'Narration: %s', 'wiro-ai' ), '' !== $title ? $title : wp_trim_words( $text, 8, '...' ) );
				break;

			default:
				return new WP_Error( 'wiro_ai_bad_task', __( 'Unknown task.', 'wiro-ai' ), array( 'status' => 400 ) );
		}

		$detail = Wiro_AI_Catalog::detail( $slug );
		$items  = is_wp_error( $detail ) ? array() : $detail['items'];
		$params = Wiro_AI_Models::build_run_params( $items, $values, Wiro_AI_Catalog::presets( $task, $slug ) );

		/**
		 * Filters the parameters sent to Wiro AI for one task.
		 *
		 * @param array  $params  Run parameters.
		 * @param string $slug    Model slug.
		 * @param array  $context Task, post ID, attachment ID and purpose.
		 */
		$params = apply_filters(
			'wiro_ai_run_params',
			$params,
			$slug,
			array(
				'task'          => $task,
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'purpose'       => (string) $input['purpose'],
			)
		);

		$run = Wiro_AI_Client::from_settings()->run( $slug, is_array( $params ) ? $params : array() );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$job_id = Wiro_AI_Jobs::create(
			$label,
			array(
				'task'      => $task,
				'model'     => $slug,
				'token'     => (string) $run['socketaccesstoken'],
				'task_id'   => isset( $run['taskid'] ) ? (string) $run['taskid'] : '',
				'object_id' => $post_id > 0 ? $post_id : $attachment_id,
				'post_id'   => $post_id,
				'source_id' => $attachment_id,
				'purpose'   => (string) $input['purpose'],
				'apply'     => ! empty( $input['apply'] ),
				'title'     => $title,
				'prompt'    => $stored,
				'errors'    => 0,
			)
		);
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		return array(
			'id'     => $job_id,
			'status' => 'running',
			'stage'  => 'queued',
			'task'   => $task,
			'model'  => $slug,
		);
	}

	/**
	 * Writes alt text for an image with an LLM that reads images.
	 *
	 * @param string $slug  Gateway model ID.
	 * @param array  $input Request values.
	 * @return array|WP_Error
	 */
	private static function run_alt( $slug, array $input ) {
		$attachment_id = (int) $input['attachment_id'];
		if ( $attachment_id <= 0 ) {
			return new WP_Error( 'wiro_ai_no_image', __( 'Pick an image first.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		$data_url = Wiro_AI_Media::data_url( $attachment_id );
		if ( is_wp_error( $data_url ) ) {
			return $data_url;
		}

		$context = trim( (string) $input['title'] );
		$text    = sprintf(
			'Write alt text for this image. Language: the language of the site (locale %s). %s',
			get_locale(),
			'' !== $context ? 'It appears in a page titled: ' . $context . '.' : ''
		);
		$answer  = self::chat(
			array(
				'model'            => $slug,
				'max_tokens'       => 1000,
				'reasoning_effort' => 'low',
				'messages'         => array(
					array(
						'role'    => 'system',
						'content' => 'You write alt text for images on websites. Describe what the image shows in one plain sentence of at most 125 characters, for someone who cannot see it. Do not start with "Image of" or "Photo of". Answer with the alt text only.',
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => trim( $text ),
							),
							array(
								'type'      => 'image_url',
								'image_url' => array(
									'url'    => $data_url,
									'detail' => 'low',
								),
							),
						),
					),
				),
			)
		);
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$alt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $answer['text'] ) ), " \t\"'" );
		if ( mb_strlen( $alt ) > 250 ) {
			$alt = rtrim( mb_substr( $alt, 0, 247 ) ) . '...';
		}

		/* translators: %s: image title. */
		$label  = sprintf( __( 'Alt text: %s', 'wiro-ai' ), self::title_of( $attachment_id ) );
		$job_id = self::record_instant( 'alt', $slug, $label, $attachment_id, $answer['cost'], '' === $alt ? '' : $alt );
		if ( '' === $alt ) {
			return new WP_Error( 'wiro_ai_empty_answer', __( 'The model returned no alt text. Try again or pick another model.', 'wiro-ai' ), array( 'status' => 502 ) );
		}
		return array(
			'id'     => $job_id,
			'status' => 'done',
			'task'   => 'alt',
			'model'  => $slug,
			'cost'   => self::cost_text( $answer['cost'] ),
			'result' => array( 'alt' => $alt ),
		);
	}

	/**
	 * Suggests titles, an excerpt and an SEO description for a post.
	 *
	 * @param string $slug  Gateway model ID.
	 * @param array  $input Request values.
	 * @return array|WP_Error
	 */
	private static function run_text( $slug, array $input ) {
		$title   = trim( (string) $input['title'] );
		$content = self::cap_text( self::plain_text( (string) $input['text'] ), self::MAX_TEXT );
		if ( '' === $title && '' === $content && (int) $input['post_id'] > 0 ) {
			$post    = get_post( (int) $input['post_id'] );
			$title   = $post ? self::title_of( $post->ID ) : '';
			$content = $post ? self::cap_text( self::plain_text( $post->post_content ), self::MAX_TEXT ) : '';
		}
		if ( '' === $title && '' === $content ) {
			return new WP_Error( 'wiro_ai_no_text', __( 'Write a title or some content first.', 'wiro-ai' ), array( 'status' => 400 ) );
		}

		$answer = self::chat(
			array(
				'model'            => $slug,
				'max_tokens'       => 1500,
				'temperature'      => 0.7,
				'reasoning_effort' => 'low',
				'messages'         => array(
					array(
						'role'    => 'system',
						'content' => 'You help a blogger polish a post. Suggest three alternative titles (at most 70 characters each), one excerpt (one or two sentences, at most 300 characters) and one SEO meta description (at most 155 characters). Write in the same language as the post. Keep facts to what the post says. No clickbait, no emojis. Answer with JSON only, in this form: {"titles": ["...", "...", "..."], "excerpt": "...", "seo_description": "..."}',
					),
					array(
						'role'    => 'user',
						'content' => "Title: {$title}\n\nContent:\n{$content}",
					),
				),
			)
		);
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$suggestions = self::parse_suggestions( $answer['text'] );
		/* translators: %s: post title. */
		$label  = sprintf( __( 'Text suggestions: %s', 'wiro-ai' ), '' !== $title ? $title : wp_trim_words( $content, 8, '...' ) );
		$job_id = self::record_instant( 'text', $slug, $label, (int) $input['post_id'], $answer['cost'], null === $suggestions ? '' : wp_json_encode( $suggestions ) );
		if ( null === $suggestions ) {
			return new WP_Error( 'wiro_ai_empty_answer', __( 'The model did not return suggestions. Try again or pick another model.', 'wiro-ai' ), array( 'status' => 502 ) );
		}
		return array(
			'id'     => $job_id,
			'status' => 'done',
			'task'   => 'text',
			'model'  => $slug,
			'cost'   => self::cost_text( $answer['cost'] ),
			'result' => $suggestions,
		);
	}

	/**
	 * Sends a chat request, leaving out optional fields the model does not take.
	 *
	 * The gateway rejects some fields for models that do not support them,
	 * so reasoning_effort, response_format and temperature are only sent to
	 * models that list them.
	 *
	 * @param array $body Request body.
	 * @return array|WP_Error
	 */
	private static function chat( array $body ) {
		$model    = Wiro_AI_Catalog::gateway_model( (string) $body['model'] );
		$supports = is_array( $model ) && isset( $model['supports'] ) ? $model['supports'] : array();
		foreach ( array( 'reasoning_effort', 'response_format', 'temperature' ) as $field ) {
			if ( isset( $body[ $field ] ) && ! in_array( $field, $supports, true ) ) {
				unset( $body[ $field ] );
			}
		}
		if ( isset( $body['max_tokens'] ) && ! in_array( 'max_tokens', $supports, true ) && in_array( 'max_completion_tokens', $supports, true ) ) {
			$body['max_completion_tokens'] = $body['max_tokens'];
			unset( $body['max_tokens'] );
		}
		return Wiro_AI_Client::from_settings()->chat( $body );
	}

	/**
	 * Reads the JSON answer of a text suggestion request.
	 *
	 * @param string $text Model answer.
	 * @return array|null titles, excerpt and seo_description.
	 */
	public static function parse_suggestions( $text ) {
		$start = strpos( (string) $text, '{' );
		$end   = strrpos( (string) $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$clean  = static function ( $value, $max ) {
			$value = is_string( $value ) ? trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) ) : '';
			return mb_strlen( $value ) > $max ? rtrim( mb_substr( $value, 0, $max - 3 ) ) . '...' : $value;
		};
		$titles = array();
		if ( isset( $data['titles'] ) && is_array( $data['titles'] ) ) {
			foreach ( array_slice( $data['titles'], 0, 5 ) as $title ) {
				$title = $clean( $title, 120 );
				if ( '' !== $title ) {
					$titles[] = $title;
				}
			}
		}
		$result = array(
			'titles'          => $titles,
			'excerpt'         => $clean( isset( $data['excerpt'] ) ? $data['excerpt'] : '', 400 ),
			'seo_description' => $clean( isset( $data['seo_description'] ) ? $data['seo_description'] : '', 200 ),
		);
		return ( $titles || '' !== $result['excerpt'] || '' !== $result['seo_description'] ) ? $result : null;
	}

	/**
	 * Stores a job that finished in the same request (LLM answers).
	 *
	 * @param string $task      Task key.
	 * @param string $slug      Model ID.
	 * @param string $label     Record label.
	 * @param int    $object_id Post or attachment ID.
	 * @param float  $cost      Cost in US dollars.
	 * @param string $result    Result text, or empty when the answer was unusable.
	 * @return int Job ID, or 0.
	 */
	private static function record_instant( $task, $slug, $label, $object_id, $cost, $result ) {
		$job_id = Wiro_AI_Jobs::create(
			$label,
			array(
				'task'      => $task,
				'model'     => $slug,
				'object_id' => (int) $object_id,
			)
		);
		if ( is_wp_error( $job_id ) ) {
			return 0;
		}
		Wiro_AI_Jobs::finish(
			$job_id,
			'' === $result ? 'failed' : 'done',
			array(
				'cost'   => (float) $cost,
				'result' => $result,
				'error'  => '' === $result ? __( 'The model returned no usable answer.', 'wiro-ai' ) : '',
			)
		);
		return $job_id;
	}

	/**
	 * Details for saving a finished task's file.
	 *
	 * @param array  $job  Job record.
	 * @param string $kind "image" or "audio".
	 * @param float  $cost Cost of the task.
	 * @return array
	 */
	private static function import_details( array $job, $kind, $cost ) {
		$source_id = isset( $job['source_id'] ) ? (int) $job['source_id'] : 0;
		$title     = isset( $job['title'] ) ? (string) $job['title'] : '';
		$prompt    = isset( $job['prompt'] ) ? (string) $job['prompt'] : '';
		$details   = array(
			'kind'      => $kind,
			'post_id'   => isset( $job['post_id'] ) ? (int) $job['post_id'] : 0,
			'title'     => $title,
			'alt'       => '',
			'prompt'    => $prompt,
			'model'     => $job['model'],
			'task_id'   => isset( $job['task_id'] ) ? (string) $job['task_id'] : '',
			'cost'      => self::cost_text( $cost ),
			'source_id' => $source_id,
		);

		switch ( $job['task'] ) {
			case 'image':
				$featured         = in_array( $job['purpose'], array( 'featured', 'bulk' ), true );
				$details['title'] = '' !== $title ? $title : wp_trim_words( $prompt, 10, '' );
				$details['alt']   = $featured && '' !== $title ? $title : wp_trim_words( $prompt, 25, '' );
				break;
			case 'edit':
			case 'upscale':
			case 'background':
				$suffixes = array(
					'edit'       => __( 'edited', 'wiro-ai' ),
					'upscale'    => __( 'upscaled', 'wiro-ai' ),
					'background' => __( 'no background', 'wiro-ai' ),
				);
				/* translators: 1: image title, 2: what was done, such as "edited". */
				$details['title']   = sprintf( __( '%1$s (%2$s)', 'wiro-ai' ), $title, $suffixes[ $job['task'] ] );
				$details['alt']     = $source_id > 0 ? (string) get_post_meta( $source_id, '_wp_attachment_image_alt', true ) : '';
				$details['post_id'] = $source_id > 0 ? (int) wp_get_post_parent_id( $source_id ) : 0;
				break;
			case 'narration':
				/* translators: %s: post title. */
				$details['title'] = sprintf( __( 'Narration: %s', 'wiro-ai' ), '' !== $title ? $title : __( 'post', 'wiro-ai' ) );
				break;
		}
		return $details;
	}

	/**
	 * Shortens text to a limit, at the end of a sentence when one is near.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest length in characters.
	 * @return string
	 */
	public static function cap_text( $text, $max ) {
		$text = trim( (string) $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut  = mb_substr( $text, 0, $max );
		$stop = max( mb_strrpos( $cut, '. ' ), mb_strrpos( $cut, '! ' ), mb_strrpos( $cut, '? ' ) );
		if ( false !== $stop && $stop > $max - 400 ) {
			$cut = mb_substr( $cut, 0, $stop + 1 );
		}
		return trim( $cut );
	}

	/**
	 * Cost as text without trailing zeros.
	 *
	 * @param float $cost Cost in US dollars.
	 * @return string
	 */
	private static function cost_text( $cost ) {
		$cost = (float) $cost;
		return $cost > 0 ? rtrim( rtrim( sprintf( '%.6F', $cost ), '0' ), '.' ) : '';
	}

	/**
	 * Marks a job as failed.
	 *
	 * @param array  $job     Job record.
	 * @param string $message Error message.
	 * @param float  $cost    Cost charged anyway, if any.
	 * @return array
	 */
	private static function fail( array $job, $message, $cost = 0.0 ) {
		Wiro_AI_Jobs::finish(
			$job['id'],
			'failed',
			array(
				'error' => $message,
				'cost'  => (float) $cost,
			)
		);
		return array(
			'id'     => $job['id'],
			'status' => 'failed',
			'stage'  => 'failed',
			'error'  => $message,
		);
	}

	/**
	 * Response for a running job.
	 *
	 * @param array  $job   Job record.
	 * @param string $stage Stage name.
	 * @return array
	 */
	private static function running_response( array $job, $stage ) {
		return array(
			'id'      => $job['id'],
			'status'  => 'running',
			'stage'   => $stage,
			'task'    => $job['task'],
			'elapsed' => max( 0, time() - (int) $job['started'] ),
		);
	}

	/**
	 * Response for a finished job.
	 *
	 * @param array $job Job record.
	 * @return array
	 */
	private static function done_response( array $job ) {
		$response = array(
			'id'     => $job['id'],
			'status' => 'done',
			'stage'  => 'done',
			'task'   => $job['task'],
			'model'  => $job['model'],
			'cost'   => self::cost_text( isset( $job['cost'] ) ? $job['cost'] : 0 ),
		);
		if ( isset( $job['result'] ) && is_numeric( $job['result'] ) && (int) $job['result'] > 0 ) {
			$response['attachment'] = Wiro_AI_Media::describe( (int) $job['result'] );
		}
		return $response;
	}

	/**
	 * Response for a failed job.
	 *
	 * @param array $job Job record.
	 * @return array
	 */
	private static function failed_response( array $job ) {
		return array(
			'id'     => $job['id'],
			'status' => 'failed',
			'stage'  => 'failed',
			'error'  => isset( $job['error'] ) && '' !== $job['error'] ? $job['error'] : __( 'The task failed.', 'wiro-ai' ),
		);
	}
}
