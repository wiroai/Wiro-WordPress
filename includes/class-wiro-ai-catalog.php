<?php
/**
 * Tasks, their model lists and price quotes.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every task the plugin offers, with the Wiro AI catalog categories its models
 * come from. Model lists are read from Tool/List (or, for LLM tasks, from the
 * gateway's model list) and cached for twelve hours.
 */
class Wiro_AI_Catalog {

	/**
	 * Cache lifetime of model lists and model details, in seconds.
	 */
	const CACHE_TTL = 43200;

	/**
	 * Models that are never listed: uncensored models, and NSFW models that
	 * are not moderation tools.
	 */
	const HIDDEN = '/uncensored|decensored|abliterated|obliterated|heretic|refusal[- ]reduced/i';

	/**
	 * Task definitions.
	 *
	 * @return array<string, array>
	 */
	public static function tasks() {
		return array(
			'image'      => array(
				'label'       => __( 'Images from a prompt', 'wiro-ai' ),
				'source'      => 'run',
				'categories'  => array( 'text-to-image' ),
				'output'      => 'image',
				'recommended' => array(
					'google/nano-banana-2-1',
					'openai/gpt-image-2',
					'bytedance/seedream-v5-pro',
					'black-forest-labs/flux-2-pro',
					'google/nano-banana-pro',
					'xai/grok-imagine-image-v2',
					'alibaba/qwen-image-3-0',
					'pruna/p-image',
				),
			),
			'edit'       => array(
				'label'       => __( 'Edit an image with a prompt', 'wiro-ai' ),
				'source'      => 'run',
				'categories'  => array( 'image-editing' ),
				'input'       => 'image',
				'output'      => 'image',
				'skip'        => '/try-on|store-image|shopify|product-ad/i',
				'recommended' => array(
					'google/nano-banana-2-1',
					'openai/gpt-image-2',
					'bytedance/seedream-v5-pro',
					'google/nano-banana-pro',
					'black-forest-labs/flux-2-pro',
					'alibaba/qwen-image-3-0-pro',
					'fireredteam/firered-image-edit',
				),
			),
			'upscale'    => array(
				'label'       => __( 'Upscale an image', 'wiro-ai' ),
				'source'      => 'run',
				'categories'  => array( 'upscale' ),
				'input'       => 'image',
				'output'      => 'image',
				'skip'        => '/video/i',
				'recommended' => array( 'xinntao/real-esrgan', 'wiro/image-restoration', 'wiroai/flux-upscaler' ),
			),
			'background' => array(
				'label'       => __( 'Remove the background', 'wiro-ai' ),
				'source'      => 'run',
				'categories'  => array( 'background-removal' ),
				'input'       => 'image',
				'output'      => 'image',
				'recommended' => array( 'wiro/remove-background', 'briaai/rmbg-2-0', 'ideogram/remove-background', 'zhengpeng7/birefnet' ),
			),
			'narration'  => array(
				'label'       => __( 'Narration (text to speech)', 'wiro-ai' ),
				'source'      => 'run',
				'categories'  => array( 'text-to-speech' ),
				'output'      => 'audio',
				'skip'        => '/sound-effects|dialogue|realtime|faceless|voice-clone/i',
				'skip_cats'   => array( 'voice-clone', 'speech-to-speech', 'realtime-tts', 'text-to-video', 'video-generator', 'talking-head' ),
				'max_chars'   => 10000,
				'recommended' => array( 'elevenlabs/eleven-v4-turbo', 'elevenlabs/eleven-v4', 'google/gemini-3-1-tts', 'wiro/kokoro-tts' ),
			),
			'alt'        => array(
				'label'       => __( 'Alt text for images', 'wiro-ai' ),
				'source'      => 'gateway',
				'vision'      => true,
				'recommended' => array( 'google/gemini-3-5-flash-lite', 'openai/gpt-5-nano', 'google/gemini-3-flash', 'openai/gpt-5-mini' ),
			),
			'text'       => array(
				'label'       => __( 'Titles, excerpts and SEO descriptions', 'wiro-ai' ),
				'source'      => 'gateway',
				'recommended' => array( 'google/gemini-3-5-flash-lite', 'openai/gpt-5-mini', 'google/gemini-3-flash', 'claude/sonnet-5' ),
			),
		);
	}

	/**
	 * One task definition.
	 *
	 * @param string $task Task key.
	 * @return array|null
	 */
	public static function task( $task ) {
		$tasks = self::tasks();
		return isset( $tasks[ $task ] ) ? $tasks[ $task ] : null;
	}

	/**
	 * Default model of a task: the one picked in the settings, or the first
	 * recommended model.
	 *
	 * @param string $task Task key.
	 * @return string Model slug.
	 */
	public static function default_model( $task ) {
		$definition = self::task( $task );
		if ( null === $definition ) {
			return '';
		}
		$settings = Wiro_AI_Settings::get();
		$chosen   = isset( $settings['models'][ $task ] ) ? Wiro_AI_Models::normalize_slug( $settings['models'][ $task ] ) : '';
		return '' !== $chosen ? $chosen : $definition['recommended'][0];
	}

	/**
	 * Models offered for a task.
	 *
	 * @param string $task Task key.
	 * @return array{recommended: array, all: array, complete: bool} Each entry has value, label and title.
	 */
	public static function models( $task ) {
		$definition = self::task( $task );
		if ( null === $definition ) {
			return array(
				'recommended' => array(),
				'all'         => array(),
				'complete'    => false,
			);
		}

		$key    = 'wiro_ai_models_' . $task;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['recommended'], $cached['all'] ) ) {
			return $cached;
		}

		$records  = array();
		$complete = Wiro_AI_Settings::has_key();
		if ( $complete ) {
			$fetched  = 'gateway' === $definition['source'] ? self::fetch_gateway_records( $definition ) : self::fetch_run_records( $definition );
			$complete = ! is_wp_error( $fetched );
			$records  = is_wp_error( $fetched ) ? array() : $fetched;
		}

		$recommended = array();
		foreach ( $definition['recommended'] as $slug ) {
			$recommended[] = isset( $records[ $slug ] ) ? $records[ $slug ] : self::entry( $slug, '', '' );
		}
		$all = array();
		foreach ( $records as $slug => $record ) {
			if ( ! in_array( $slug, $definition['recommended'], true ) ) {
				$all[] = $record;
			}
		}

		$result = array(
			'recommended' => $recommended,
			'all'         => $all,
			'complete'    => $complete,
		);
		if ( $complete ) {
			set_transient( $key, $result, self::CACHE_TTL );
		}
		return $result;
	}

	/**
	 * Whether a model may run a task: one of the recommended models, a model
	 * of the task's categories, or the one picked in the settings.
	 *
	 * @param string $task Task key.
	 * @param string $slug Model slug.
	 * @return bool
	 */
	public static function is_allowed( $task, $slug ) {
		$definition = self::task( $task );
		if ( null === $definition || '' === $slug ) {
			return false;
		}
		if ( in_array( $slug, $definition['recommended'], true ) || self::default_model( $task ) === $slug ) {
			return true;
		}
		$models = self::models( $task );
		foreach ( $models['all'] as $entry ) {
			if ( $entry['value'] === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A catalog model's parameters and prices, cached for twelve hours.
	 *
	 * @param string $slug Model slug.
	 * @return array|WP_Error items, dynamicprice, approximatelycost, discount, title.
	 */
	public static function detail( $slug ) {
		$key    = 'wiro_ai_model_' . md5( $slug );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['items'] ) ) {
			return $cached;
		}
		$tool = Wiro_AI_Client::from_settings()->tool_detail( $slug );
		if ( is_wp_error( $tool ) ) {
			return $tool;
		}
		$detail = array(
			'items'             => Wiro_AI_Models::parameter_items( $tool ),
			'dynamicprice'      => Wiro_AI_Pricing::rows( isset( $tool['dynamicprice'] ) ? $tool['dynamicprice'] : null ),
			'approximatelycost' => isset( $tool['approximatelycost'] ) && is_numeric( $tool['approximatelycost'] ) ? (float) $tool['approximatelycost'] : 0.0,
			'discount'          => isset( $tool['discount'] ) && is_numeric( $tool['discount'] ) ? (float) $tool['discount'] : 0.0,
			'title'             => isset( $tool['title'] ) && is_string( $tool['title'] ) ? $tool['title'] : '',
		);
		set_transient( $key, $detail, self::CACHE_TTL );
		return $detail;
	}

	/**
	 * An LLM gateway model, or null when the gateway does not list it.
	 *
	 * @param string $slug Model ID.
	 * @return array|null id, name, pricing, vision.
	 */
	public static function gateway_model( $slug ) {
		$models = self::gateway_records();
		return is_array( $models ) && isset( $models[ $slug ] ) ? $models[ $slug ] : null;
	}

	/**
	 * Values a task's model gets on every request.
	 *
	 * @param string $task Task key.
	 * @param string $slug Model slug.
	 * @return array
	 */
	public static function presets( $task, $slug ) {
		$presets = array();
		if ( in_array( $task, array( 'image', 'edit' ), true ) && 'openai/gpt-image-2' === $slug ) {
			$presets['quality'] = 'medium';
		}
		/**
		 * Filters the values sent to a model on every request of a task.
		 *
		 * @param array  $presets Parameter values.
		 * @param string $task    Task key.
		 * @param string $slug    Model slug.
		 */
		$presets = apply_filters( 'wiro_ai_presets', $presets, $task, $slug );
		return is_array( $presets ) ? $presets : array();
	}

	/**
	 * Price line for a planned request.
	 *
	 * @param string $task    Task key.
	 * @param string $slug    Model slug.
	 * @param array  $values  Common values: ratio, voice, scale.
	 * @param array  $context chars: characters of the prompt or text; images: number of images sent to an LLM.
	 * @return array|WP_Error Quote with amount, approximate, label and note.
	 */
	public static function quote( $task, $slug, array $values, array $context = array() ) {
		$definition = self::task( $task );
		if ( null === $definition ) {
			return new WP_Error( 'wiro_ai_bad_task', __( 'Unknown task.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		$chars = isset( $context['chars'] ) ? max( 0, (int) $context['chars'] ) : 0;

		if ( 'gateway' === $definition['source'] ) {
			$model = self::gateway_model( $slug );
			if ( null === $model ) {
				return new WP_Error( 'wiro_ai_bad_model', __( 'The LLM gateway does not list this model.', 'wiro-ai' ), array( 'status' => 400 ) );
			}
			$images = isset( $context['images'] ) ? max( 0, (int) $context['images'] ) : 0;
			$input  = 150 + (int) ceil( $chars / 4 ) + 1200 * $images;
			$output = 'alt' === $task ? 120 : 500;
			return Wiro_AI_Pricing::llm_quote( $model['pricing'], $input, $output );
		}

		$detail = self::detail( $slug );
		if ( is_wp_error( $detail ) ) {
			return $detail;
		}
		if ( ! empty( $definition['input'] ) ) {
			$values['image_url'] = 'https://cdn1.wiro.ai/placeholder.png';
		}
		if ( 'narration' === $task && ! isset( $values['prompt'] ) ) {
			$values['prompt'] = str_repeat( 'a', max( 1, $chars ) );
		}
		$params = Wiro_AI_Models::build_run_params( $detail['items'], $values, self::presets( $task, $slug ) );
		$pixels = isset( $params['width'], $params['height'] ) ? (int) $params['width'] * (int) $params['height'] : 0;
		return Wiro_AI_Pricing::quote(
			$detail,
			$params,
			array(
				'chars'  => $chars,
				'pixels' => $pixels,
			)
		);
	}

	/**
	 * Drops the cached model lists, for example after the key changes.
	 */
	public static function flush() {
		foreach ( array_keys( self::tasks() ) as $task ) {
			delete_transient( 'wiro_ai_models_' . $task );
		}
		delete_transient( 'wiro_ai_gateway_models' );
	}

	/**
	 * A list entry.
	 *
	 * @param string $slug  Model slug.
	 * @param string $title Model title.
	 * @param string $price Short price.
	 * @return array{value: string, label: string, title: string, price: string}
	 */
	private static function entry( $slug, $title, $price ) {
		return array(
			'value' => $slug,
			'label' => '' !== $price ? $slug . ' · ' . $price : $slug,
			'title' => $title,
			'price' => $price,
		);
	}

	/**
	 * Catalog models of a Run task, keyed by slug.
	 *
	 * @param array $definition Task definition.
	 * @return array|WP_Error
	 */
	private static function fetch_run_records( array $definition ) {
		$client  = Wiro_AI_Client::from_settings();
		$records = array();
		foreach ( $definition['categories'] as $category ) {
			$list = $client->tool_list( $category, 100 );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $tool ) {
				if ( ! is_array( $tool ) ) {
					continue;
				}
				$owner   = isset( $tool['cleanslugowner'] ) ? (string) $tool['cleanslugowner'] : '';
				$project = isset( $tool['cleanslugproject'] ) ? (string) $tool['cleanslugproject'] : '';
				$slug    = Wiro_AI_Models::normalize_slug( $owner . '/' . $project );
				if ( '' === $slug || isset( $records[ $slug ] ) || self::hidden( $tool, $slug, $definition ) ) {
					continue;
				}
				$title            = isset( $tool['title'] ) && is_string( $tool['title'] ) ? $tool['title'] : '';
				$records[ $slug ] = self::entry( $slug, $title, Wiro_AI_Pricing::short_label( $tool ) );
			}
		}
		return $records;
	}

	/**
	 * Gateway models for an LLM task, keyed by ID.
	 *
	 * @param array $definition Task definition.
	 * @return array|WP_Error
	 */
	private static function fetch_gateway_records( array $definition ) {
		$models = self::gateway_records();
		if ( is_wp_error( $models ) ) {
			return $models;
		}
		$records = array();
		foreach ( $models as $slug => $model ) {
			if ( ! empty( $definition['vision'] ) && ! $model['vision'] ) {
				continue;
			}
			if ( preg_match( self::HIDDEN, $slug . ' ' . $model['name'] ) ) {
				continue;
			}
			$in    = isset( $model['pricing']['prompt'] ) ? (float) $model['pricing']['prompt'] * 1000000 : 0.0;
			$out   = isset( $model['pricing']['completion'] ) ? (float) $model['pricing']['completion'] * 1000000 : 0.0;
			$price = $in > 0 || $out > 0
				/* translators: 1: price per million input tokens, 2: price per million output tokens. */
				? sprintf( __( '%1$s / %2$s per 1M tokens', 'wiro-ai' ), Wiro_AI_Pricing::format_usd( $in ), Wiro_AI_Pricing::format_usd( $out ) )
				: '';
			// Models without a listed token price stay out of the menu; the price line could not say what they cost.
			if ( '' === $price ) {
				continue;
			}
			$records[ $slug ] = self::entry( $slug, $model['name'], $price );
		}
		return $records;
	}

	/**
	 * The gateway's model list, reduced and cached.
	 *
	 * @return array|WP_Error Models keyed by ID.
	 */
	private static function gateway_records() {
		$cached = get_transient( 'wiro_ai_gateway_models' );
		if ( is_array( $cached ) && ( ! $cached || isset( current( $cached )['supports'] ) ) ) {
			return $cached;
		}
		$list = Wiro_AI_Client::from_settings()->gateway_models();
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$models = array();
		foreach ( $list as $model ) {
			if ( ! is_array( $model ) || empty( $model['id'] ) || ! is_string( $model['id'] ) ) {
				continue;
			}
			$modalities = isset( $model['architecture']['input_modalities'] ) && is_array( $model['architecture']['input_modalities'] ) ? $model['architecture']['input_modalities'] : array();
			$name       = isset( $model['name'] ) && is_string( $model['name'] ) ? preg_replace( '/^\(Wiro\)\s*/', '', $model['name'] ) : $model['id'];

			$models[ $model['id'] ] = array(
				'id'       => $model['id'],
				'name'     => $name,
				'pricing'  => isset( $model['pricing'] ) && is_array( $model['pricing'] ) ? $model['pricing'] : array(),
				'vision'   => in_array( 'image', $modalities, true ),
				'supports' => isset( $model['supported_parameters'] ) && is_array( $model['supported_parameters'] ) ? array_values( array_filter( $model['supported_parameters'], 'is_string' ) ) : array(),
			);
		}
		set_transient( 'wiro_ai_gateway_models', $models, self::CACHE_TTL );
		return $models;
	}

	/**
	 * Whether a catalog model stays out of a task's list.
	 *
	 * @param array  $tool       Tool/List record.
	 * @param string $slug       Model slug.
	 * @param array  $definition Task definition.
	 * @return bool
	 */
	private static function hidden( array $tool, $slug, array $definition ) {
		$title       = isset( $tool['title'] ) && is_string( $tool['title'] ) ? $tool['title'] : '';
		$description = isset( $tool['description'] ) && is_string( $tool['description'] ) ? $tool['description'] : '';
		if ( preg_match( self::HIDDEN, $slug . ' ' . $title . ' ' . $description ) ) {
			return true;
		}
		if ( preg_match( '/nsfw/i', $slug . ' ' . $title ) && ! preg_match( '/detect|classif|moderat|filter|checker/i', $slug . ' ' . $title ) ) {
			return true;
		}
		if ( ! empty( $definition['skip'] ) && preg_match( $definition['skip'], $slug ) ) {
			return true;
		}
		if ( ! empty( $definition['skip_cats'] ) && ! empty( $tool['categories'] ) && is_array( $tool['categories'] ) ) {
			if ( array_intersect( $definition['skip_cats'], $tool['categories'] ) ) {
				return true;
			}
		}
		return false;
	}
}
