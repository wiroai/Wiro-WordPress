<?php
/**
 * Wiro AI API client.
 *
 * API reference: https://wiro.ai/docs/run-a-model and https://wiro.ai/docs/tasks
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends requests to the Wiro AI API with the site's API key.
 */
class Wiro_AI_Client {

	/**
	 * Base URL of the Wiro AI API.
	 */
	const API_BASE = 'https://api.wiro.ai/v1/';

	/**
	 * Base URL of the Wiro AI LLM gateway (OpenAI compatible).
	 */
	const GATEWAY_BASE = 'https://llm.wiro.ai/v1/';

	/**
	 * API key of the Wiro AI project.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API secret, set only for projects that use signature authentication.
	 *
	 * @var string
	 */
	private $api_secret;

	/**
	 * Constructor.
	 *
	 * @param string $api_key    API key.
	 * @param string $api_secret API secret, or an empty string.
	 */
	public function __construct( $api_key, $api_secret = '' ) {
		$this->api_key    = trim( (string) $api_key );
		$this->api_secret = trim( (string) $api_secret );
	}

	/**
	 * Client built from the saved settings.
	 *
	 * @return Wiro_AI_Client
	 */
	public static function from_settings() {
		return new self( Wiro_AI_Settings::api_key(), Wiro_AI_Settings::api_secret() );
	}

	/**
	 * Starts a task.
	 *
	 * @param string $slug   Model slug, owner/project.
	 * @param array  $params Run parameters.
	 * @return array|WP_Error Response with taskid and socketaccesstoken.
	 */
	public function run( $slug, array $params ) {
		$parts = explode( '/', $slug );
		$path  = 'Run/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( isset( $parts[1] ) ? $parts[1] : '' );
		$body  = $this->post( $path, $params, 60 );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['socketaccesstoken'] ) || ! is_string( $body['socketaccesstoken'] ) ) {
			return new WP_Error( 'wiro_ai_no_task', __( 'Wiro AI accepted the request but returned no task.', 'wiro-ai' ) );
		}
		return $body;
	}

	/**
	 * Reads the current state of a task.
	 *
	 * @param string $token Task token returned by run().
	 * @return array|WP_Error The task record.
	 */
	public function task_detail( $token ) {
		$body = $this->post( 'Task/Detail', array( 'tasktoken' => (string) $token ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['tasklist'][0] ) || ! is_array( $body['tasklist'][0] ) ) {
			return new WP_Error( 'wiro_ai_no_task', __( 'Wiro AI returned no task details.', 'wiro-ai' ) );
		}
		return $body['tasklist'][0];
	}

	/**
	 * Reads a model's public details, including its parameters.
	 *
	 * @param string $slug Model slug, owner/project.
	 * @return array|WP_Error The model record.
	 */
	public function tool_detail( $slug ) {
		$parts = explode( '/', $slug );
		$body  = $this->post(
			'Tool/Detail',
			array(
				'slugowner'   => $parts[0],
				'slugproject' => isset( $parts[1] ) ? $parts[1] : '',
			)
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['tool'][0] ) || ! is_array( $body['tool'][0] ) ) {
			/* translators: %s: model slug such as google/nano-banana-2-1. */
			return new WP_Error( 'wiro_ai_no_model', sprintf( __( 'Wiro AI has no model called %s.', 'wiro-ai' ), $slug ) );
		}
		return $body['tool'][0];
	}

	/**
	 * Lists catalog models of one category, most used first.
	 *
	 * @param string $category Category such as "text-to-image".
	 * @param int    $limit    Number of models.
	 * @return array|WP_Error Tool/List records.
	 */
	public function tool_list( $category, $limit = 100 ) {
		$body = $this->post(
			'Tool/List',
			array(
				'start'         => '0',
				'limit'         => (string) (int) $limit,
				'categories'    => array( (string) $category ),
				'sort'          => 'ratedusercount',
				'order'         => 'DESC',
				'hideworkflows' => true,
				'summary'       => true,
			),
			30
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return isset( $body['tool'] ) && is_array( $body['tool'] ) ? $body['tool'] : array();
	}

	/**
	 * Uploads a local file to Wiro AI, for models that take a file URL.
	 *
	 * @param string $path     Local file path.
	 * @param string $filename File name to send.
	 * @param string $mime     Content type.
	 * @return string|WP_Error URL of the uploaded file.
	 */
	public function upload_file( $path, $filename, $mime ) {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new WP_Error( 'wiro_ai_upload_failed', __( 'Could not read the image file.', 'wiro-ai' ) );
		}
		$bytes = $wp_filesystem->get_contents( $path );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'wiro_ai_upload_failed', __( 'Could not read the image file.', 'wiro-ai' ) );
		}

		$boundary = 'wiro' . wp_generate_password( 24, false, false );
		$filename = str_replace( array( '"', "\r", "\n" ), '', $filename );
		$body     = '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n"
			. 'Content-Type: ' . $mime . "\r\n\r\n"
			. $bytes . "\r\n"
			. '--' . $boundary . "--\r\n";

		$data = $this->request( 'File/Upload', $body, 'multipart/form-data; boundary=' . $boundary, 120 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['list'][0]['url'] ) || ! is_string( $data['list'][0]['url'] ) ) {
			return new WP_Error( 'wiro_ai_upload_failed', __( 'Wiro AI accepted the upload but returned no file address.', 'wiro-ai' ) );
		}
		return $data['list'][0]['url'];
	}

	/**
	 * Sends a Chat Completions request to the LLM gateway.
	 *
	 * @param array $body Request body: model, messages and options.
	 * @return array|WP_Error Text, cost and usage of the answer.
	 */
	public function chat( array $body ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'wiro_ai_no_key', __( 'Add your Wiro AI API key in Wiro AI > Settings.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		$token    = '' !== $this->api_secret ? $this->api_key . ':' . $this->api_secret : $this->api_key;
		$response = wp_remote_post(
			self::GATEWAY_BASE . 'chat/completions',
			array(
				'timeout'     => 150,
				'redirection' => 0,
				'user-agent'  => 'Wiro-WordPress/' . WIRO_AI_VERSION,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wiro_ai_unreachable',
				/* translators: %s: error message from the HTTP library. */
				sprintf( __( 'Could not reach the Wiro AI LLM gateway: %s', 'wiro-ai' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( trim( wp_remote_retrieve_body( $response ) ), true );
		if ( ! is_array( $data ) || isset( $data['error'] ) || $code >= 400 ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ? $data['error']['message'] : '';
			if ( 401 === $code ) {
				$message = __( 'Wiro AI did not accept the API key. Check the key, and the secret if your project uses signature authentication, in Wiro AI > Settings.', 'wiro-ai' );
			} elseif ( '' === $message ) {
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'The Wiro AI LLM gateway returned an error (HTTP %d).', 'wiro-ai' ), $code );
			}
			return new WP_Error( 'wiro_ai_gateway_error', wp_strip_all_tags( $message ), array( 'status' => 400 ) );
		}

		$message = isset( $data['choices'][0]['message'] ) && is_array( $data['choices'][0]['message'] ) ? $data['choices'][0]['message'] : array();
		$text    = '';
		if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
			$text = $message['content'];
		} elseif ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
			foreach ( $message['content'] as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		return array(
			'text'   => trim( $text ),
			'cost'   => isset( $data['usage']['cost'] ) && is_numeric( $data['usage']['cost'] ) ? (float) $data['usage']['cost'] : 0.0,
			'finish' => isset( $data['choices'][0]['finish_reason'] ) && is_string( $data['choices'][0]['finish_reason'] ) ? $data['choices'][0]['finish_reason'] : '',
		);
	}

	/**
	 * Models served by the LLM gateway.
	 *
	 * @return array|WP_Error Model records with id, name, pricing and input modalities.
	 */
	public function gateway_models() {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'wiro_ai_no_key', __( 'Add your Wiro AI API key in Wiro AI > Settings.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		$token    = '' !== $this->api_secret ? $this->api_key . ':' . $this->api_secret : $this->api_key;
		$response = wp_remote_get(
			self::GATEWAY_BASE . 'models',
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'user-agent'  => 'Wiro-WordPress/' . WIRO_AI_VERSION,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error( 'wiro_ai_gateway_error', __( 'The Wiro AI LLM gateway returned no models.', 'wiro-ai' ) );
		}
		return $data['data'];
	}

	/**
	 * Checks that Wiro AI accepts the API key (and secret). Free of charge.
	 *
	 * @return true|WP_Error
	 */
	public function check_key() {
		$body = $this->post( 'Task/List', array( 'limit' => '1' ) );
		return is_wp_error( $body ) ? $body : true;
	}

	/**
	 * Sends a JSON POST request.
	 *
	 * @param string $path    Path under the API base, such as "Task/Detail".
	 * @param array  $body    Request body.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|WP_Error Decoded response body.
	 */
	private function post( $path, array $body, $timeout = 20 ) {
		return $this->request( $path, wp_json_encode( $body ? $body : new stdClass() ), 'application/json', $timeout );
	}

	/**
	 * Sends a POST request with a prepared body.
	 *
	 * @param string $path         Path under the API base.
	 * @param string $body         Request body.
	 * @param string $content_type Content type of the body.
	 * @param int    $timeout      Timeout in seconds.
	 * @return array|WP_Error Decoded response body.
	 */
	private function request( $path, $body, $content_type, $timeout ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'wiro_ai_no_key', __( 'Add your Wiro AI API key in Wiro AI > Settings.', 'wiro-ai' ), array( 'status' => 400 ) );
		}

		$headers                 = $this->headers();
		$headers['Content-Type'] = $content_type;

		$response = wp_remote_post(
			self::API_BASE . $path,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'user-agent'  => 'Wiro-WordPress/' . WIRO_AI_VERSION,
				'headers'     => $headers,
				'body'        => $body,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wiro_ai_unreachable',
				/* translators: %s: error message from the HTTP library. */
				sprintf( __( 'Could not reach the Wiro AI API: %s', 'wiro-ai' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wiro_ai_bad_response',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'The Wiro AI API answered with HTTP %d and no readable body.', 'wiro-ai' ), $code ),
				array( 'status' => 502 )
			);
		}
		if ( ! isset( $data['result'] ) || true !== $data['result'] ) {
			return self::error_from_body( $data, $code );
		}
		return $data;
	}

	/**
	 * Request headers, signed when the project uses signature authentication.
	 *
	 * @return array<string, string>
	 */
	private function headers() {
		$headers = array(
			'Accept'    => 'application/json',
			'x-api-key' => $this->api_key,
		);
		if ( '' !== $this->api_secret ) {
			$nonce                  = sprintf( '%.0f', floor( microtime( true ) * 1000 ) );
			$headers['x-nonce']     = $nonce;
			$headers['x-signature'] = hash_hmac( 'sha256', $this->api_secret . $nonce, $this->api_key );
		}
		return $headers;
	}

	/**
	 * Turns a failed API response into a readable error.
	 *
	 * @param array $data Decoded response body.
	 * @param int   $code HTTP status code.
	 * @return WP_Error
	 */
	private static function error_from_body( array $data, $code ) {
		$messages = array();
		$codes    = array();
		if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			foreach ( $data['errors'] as $error ) {
				if ( is_array( $error ) ) {
					if ( isset( $error['message'] ) && is_scalar( $error['message'] ) ) {
						$messages[] = trim( (string) $error['message'] );
					}
					if ( isset( $error['code'] ) && is_numeric( $error['code'] ) ) {
						$codes[] = (int) $error['code'];
					}
				} elseif ( is_scalar( $error ) ) {
					$messages[] = trim( (string) $error );
				}
			}
		}
		$text = trim( implode( ' ', array_filter( $messages ) ) );

		if ( in_array( 97, $codes, true ) ) {
			$text = __( 'Your Wiro AI balance is too low for this model. Add credits at wiro.ai/panel/billing and try again.', 'wiro-ai' );
		} elseif ( in_array( 96, $codes, true ) ) {
			$text = __( 'Your Wiro AI project is already running as many tasks as it can. Wait for one to finish and try again.', 'wiro-ai' );
		} elseif ( 401 === $code || 403 === $code || false !== stripos( $text, 'authorization' ) ) {
			$text = __( 'Wiro AI did not accept the API key. Check the key, and the secret if your project uses signature authentication, in Wiro AI > Settings.', 'wiro-ai' );
		} elseif ( '' === $text ) {
			/* translators: %d: HTTP status code. */
			$text = sprintf( __( 'The Wiro AI API returned an error (HTTP %d).', 'wiro-ai' ), $code );
		}

		return new WP_Error( 'wiro_ai_api_error', wp_strip_all_tags( $text ), array( 'status' => 400 ) );
	}
}
