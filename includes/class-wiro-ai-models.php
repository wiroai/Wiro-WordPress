<?php
/**
 * Model list and request building.
 *
 * Everything in this class is plain PHP with no WordPress calls, so the unit
 * tests can load it on its own.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image models offered in the editor and the helpers that turn a prompt into
 * the parameters of a Wiro AI Run request.
 */
final class Wiro_AI_Models {

	/**
	 * Model used until the site owner picks another one.
	 */
	const DEFAULT_MODEL = 'google/nano-banana-2-1';

	/**
	 * Aspect ratio used until the site owner picks another one.
	 */
	const DEFAULT_RATIO = '16:9';

	/**
	 * Aspect ratios offered in the editor, as width:height.
	 */
	const RATIOS = array( '16:9', '3:2', '4:3', '1:1', '2:3', '9:16' );

	/**
	 * Task statuses that close a task on Wiro AI.
	 */
	const DONE_STATUSES = array( 'task_postprocess_end', 'task_cancel' );

	/**
	 * Image models shown in the model menus.
	 *
	 * The key is the model's owner/project slug, as in its page URL on
	 * https://wiro.ai/models. "params" holds values sent with every request
	 * to that model, on top of the model's own defaults.
	 *
	 * @return array<string, array{label: string, params?: array<string, string>}>
	 */
	public static function catalog() {
		return array(
			'google/nano-banana-2-1'       => array( 'label' => 'Nano Banana 2.1 (Google)' ),
			'openai/gpt-image-2'           => array(
				'label'  => 'GPT Image 2 (OpenAI)',
				'params' => array( 'quality' => 'medium' ),
			),
			'bytedance/seedream-v5-pro'    => array( 'label' => 'Seedream 5 Pro (ByteDance)' ),
			'black-forest-labs/flux-2-pro' => array( 'label' => 'FLUX.2 Pro (Black Forest Labs)' ),
			'google/nano-banana-pro'       => array( 'label' => 'Nano Banana Pro (Google)' ),
			'xai/grok-imagine-image-v2'    => array( 'label' => 'Grok Imagine Image 2 (xAI)' ),
			'alibaba/qwen-image-3-0'       => array( 'label' => 'Qwen Image 3.0 (Alibaba)' ),
			'pruna/p-image'                => array( 'label' => 'P-Image (Pruna)' ),
		);
	}

	/**
	 * Turns "owner/project" or a wiro.ai model URL into a lowercase slug.
	 *
	 * @param string $value Slug or URL typed by the user.
	 * @return string The slug, or an empty string when the value is not valid.
	 */
	public static function normalize_slug( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '#wiro\.ai/(?:models/|v1/run/)?([^/?\#\s]+)/([^/?\#\s]+)#i', $value, $match ) ) {
			$owner   = $match[1];
			$project = $match[2];
		} else {
			$parts = array_values(
				array_filter(
					explode( '/', trim( $value, '/' ) ),
					static function ( $part ) {
						return '' !== $part;
					}
				)
			);
			if ( 2 !== count( $parts ) ) {
				return '';
			}
			list( $owner, $project ) = $parts;
		}
		$owner   = strtolower( $owner );
		$project = strtolower( $project );
		$pattern = '/^[a-z0-9][a-z0-9._-]{0,99}$/';
		if ( ! preg_match( $pattern, $owner ) || ! preg_match( $pattern, $project ) ) {
			return '';
		}
		return $owner . '/' . $project;
	}

	/**
	 * Whether a value is one of the offered aspect ratios.
	 *
	 * @param string $ratio Ratio such as "16:9".
	 * @return bool
	 */
	public static function is_ratio( $ratio ) {
		return in_array( $ratio, self::RATIOS, true );
	}

	/**
	 * Reduces a Tool/Detail record to the parameter fields this plugin reads.
	 *
	 * @param array $tool One entry of the "tool" list returned by Tool/Detail.
	 * @return array<int, array{id: string, type: string, required: bool, default: string, options: array<int, string>}>
	 */
	public static function parameter_items( $tool ) {
		$items = array();
		if ( ! is_array( $tool ) || empty( $tool['parameters'] ) || ! is_array( $tool['parameters'] ) ) {
			return $items;
		}
		foreach ( $tool['parameters'] as $group ) {
			if ( ! is_array( $group ) || empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
				continue;
			}
			foreach ( $group['items'] as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) || ! is_string( $item['id'] ) ) {
					continue;
				}
				$options = array();
				if ( ! empty( $item['options'] ) && is_array( $item['options'] ) ) {
					foreach ( $item['options'] as $option ) {
						if ( is_array( $option ) && isset( $option['value'] ) && is_scalar( $option['value'] ) ) {
							$options[] = (string) $option['value'];
						} elseif ( is_scalar( $option ) ) {
							$options[] = (string) $option;
						}
					}
				}
				$default = isset( $item['defaultvalue'] ) && is_scalar( $item['defaultvalue'] ) ? (string) $item['defaultvalue'] : '';
				$items[] = array(
					'id'       => $item['id'],
					'type'     => isset( $item['type'] ) && is_string( $item['type'] ) ? $item['type'] : '',
					'required' => ! empty( $item['required'] ),
					'default'  => $default,
					'options'  => $options,
				);
			}
		}
		return $items;
	}

	/**
	 * Builds the Run parameters for a text-to-image request.
	 *
	 * Wiro AI rejects a run that leaves out a required setting, so required
	 * menus, numbers and checkboxes get the model's default value. Text, JSON
	 * and file fields are never filled in, because their defaults are samples.
	 * The aspect ratio goes to the model's ratio menu when it has one, or to
	 * its width and height fields.
	 *
	 * @param array  $items  Parameter fields from parameter_items(). Empty when unknown.
	 * @param string $prompt Prompt text.
	 * @param string $ratio  Aspect ratio such as "16:9".
	 * @param array  $preset Values to send to this model on every request.
	 * @return array<string, mixed>
	 */
	public static function build_params( array $items, $prompt, $ratio, array $preset = array() ) {
		$params = array();
		$by_id  = array();
		foreach ( $items as $item ) {
			$by_id[ $item['id'] ] = $item;
			if ( ! $item['required'] || '' === $item['default'] || 'prompt' === $item['id'] ) {
				continue;
			}
			if ( in_array( $item['type'], array( 'number', 'float', 'range' ), true ) ) {
				if ( is_numeric( $item['default'] ) ) {
					$number = $item['default'] + 0;
					if ( 'float' !== $item['type'] && floor( $number ) === (float) $number ) {
						$number = (int) $number;
					}
					$params[ $item['id'] ] = $number;
				}
			} elseif ( in_array( $item['type'], array( 'select', 'checkbox' ), true ) ) {
				$params[ $item['id'] ] = $item['default'];
			}
		}

		foreach ( $preset as $key => $value ) {
			if ( ! $by_id ) {
				$params[ $key ] = $value;
				continue;
			}
			if ( ! isset( $by_id[ $key ] ) ) {
				continue;
			}
			$options = $by_id[ $key ]['options'];
			if ( $options && ! in_array( (string) $value, $options, true ) ) {
				continue;
			}
			$params[ $key ] = $value;
		}

		$ratio_item = self::find_ratio_item( $items );
		if ( null !== $ratio_item ) {
			$choice = self::closest_ratio( $ratio, $ratio_item['options'] );
			if ( '' !== $choice ) {
				$params[ $ratio_item['id'] ] = $choice;
			}
		} elseif ( isset( $by_id['width'], $by_id['height'] ) ) {
			$size             = self::dimensions( $ratio );
			$params['width']  = $size[0];
			$params['height'] = $size[1];
		}

		$params['prompt'] = (string) $prompt;
		return $params;
	}

	/**
	 * Finds the menu that sets the aspect ratio, if the model has one.
	 *
	 * @param array $items Parameter fields from parameter_items().
	 * @return array|null
	 */
	public static function find_ratio_item( array $items ) {
		foreach ( $items as $item ) {
			if ( 'select' !== $item['type'] || ! $item['options'] ) {
				continue;
			}
			$id = strtolower( $item['id'] );
			if ( in_array( $id, array( 'aspectratio', 'aspect_ratio', 'ratio', 'aspect' ), true ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Picks the option closest to the wanted ratio.
	 *
	 * @param string $ratio   Wanted ratio such as "16:9".
	 * @param array  $options Option values of the model's ratio menu.
	 * @return string The chosen option, or an empty string when none fits.
	 */
	public static function closest_ratio( $ratio, array $options ) {
		if ( in_array( $ratio, $options, true ) ) {
			return $ratio;
		}
		$wanted = self::ratio_value( $ratio );
		if ( null === $wanted ) {
			return '';
		}
		$best      = '';
		$best_diff = null;
		foreach ( $options as $option ) {
			$value = self::ratio_value( $option );
			if ( null === $value ) {
				continue;
			}
			$diff = abs( log( $value ) - log( $wanted ) );
			if ( null === $best_diff || $diff < $best_diff ) {
				$best      = $option;
				$best_diff = $diff;
			}
		}
		return $best;
	}

	/**
	 * Width divided by height for a "w:h" string.
	 *
	 * @param string $ratio Ratio such as "16:9".
	 * @return float|null
	 */
	public static function ratio_value( $ratio ) {
		if ( ! is_string( $ratio ) || ! preg_match( '/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $ratio, $match ) ) {
			return null;
		}
		$width  = (float) $match[1];
		$height = (float) $match[2];
		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}
		return $width / $height;
	}

	/**
	 * Pixel size of about one megapixel for a ratio, in multiples of 16.
	 *
	 * @param string $ratio Ratio such as "16:9".
	 * @return int[] Width and height.
	 */
	public static function dimensions( $ratio ) {
		$sizes = array(
			'16:9' => array( 1360, 768 ),
			'3:2'  => array( 1248, 832 ),
			'4:3'  => array( 1168, 880 ),
			'1:1'  => array( 1024, 1024 ),
			'2:3'  => array( 832, 1248 ),
			'9:16' => array( 768, 1360 ),
		);
		return isset( $sizes[ $ratio ] ) ? $sizes[ $ratio ] : $sizes['1:1'];
	}

	/**
	 * Finds the first image among a finished task's outputs.
	 *
	 * @param mixed $outputs The "outputs" list of a Task/Detail record.
	 * @return array{url: string, type: string}|null
	 */
	public static function pick_image_output( $outputs ) {
		if ( ! is_array( $outputs ) ) {
			return null;
		}
		foreach ( $outputs as $output ) {
			if ( ! is_array( $output ) || empty( $output['url'] ) || ! is_string( $output['url'] ) ) {
				continue;
			}
			$type = isset( $output['contenttype'] ) && is_string( $output['contenttype'] ) ? strtolower( $output['contenttype'] ) : '';
			$path = (string) parse_url( $output['url'], PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress functions in this class.
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( 0 === strpos( $type, 'image/' ) || in_array( $ext, array( 'png', 'jpg', 'jpeg', 'webp', 'gif' ), true ) ) {
				return array(
					'url'  => $output['url'],
					'type' => $type,
				);
			}
		}
		return null;
	}

	/**
	 * Whether a URL points to a Wiro AI file host over HTTPS.
	 *
	 * @param string $url Output URL.
	 * @return bool
	 */
	public static function is_wiro_file_url( $url ) {
		$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress functions in this class.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['port'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		return 'wiro.ai' === $host || '.wiro.ai' === substr( $host, -8 );
	}

	/**
	 * File extension for a downloaded image.
	 *
	 * @param string $url  Output URL.
	 * @param string $type Content type reported by Wiro AI.
	 * @return string
	 */
	public static function image_extension( $url, $type ) {
		$path = (string) parse_url( (string) $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress functions in this class.
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'jpeg' === $ext ) {
			$ext = 'jpg';
		}
		if ( in_array( $ext, array( 'png', 'jpg', 'webp', 'gif' ), true ) ) {
			return $ext;
		}
		$by_type = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		return isset( $by_type[ $type ] ) ? $by_type[ $type ] : 'png';
	}

	/**
	 * Short machine name for a task status, used by the editor scripts.
	 *
	 * @param string $status Task status from Task/Detail.
	 * @return string One of queued, preparing, generating, finishing or done.
	 */
	public static function status_stage( $status ) {
		$stages = array(
			'task_queue'             => 'queued',
			'task_accept'            => 'preparing',
			'task_preprocess_start'  => 'preparing',
			'task_preprocess_end'    => 'preparing',
			'task_assign'            => 'preparing',
			'task_error'             => 'preparing',
			'task_start'             => 'generating',
			'task_output'            => 'generating',
			'task_output_full'       => 'generating',
			'task_end'               => 'finishing',
			'task_postprocess_start' => 'finishing',
			'task_postprocess_end'   => 'done',
		);
		return isset( $stages[ $status ] ) ? $stages[ $status ] : 'queued';
	}
}
