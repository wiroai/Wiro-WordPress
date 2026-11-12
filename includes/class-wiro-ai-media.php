<?php
/**
 * Media Library helpers: saving results and reading source images.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads finished images and audio from Wiro AI into the Media Library,
 * and prepares Media Library images for the models that take one.
 */
class Wiro_AI_Media {

	/**
	 * Image types the source image of an edit can have.
	 */
	const SOURCE_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/**
	 * Downloads a file and creates its attachment.
	 *
	 * @param string $url     Output URL on a Wiro AI file host.
	 * @param string $type    Content type reported by Wiro AI.
	 * @param array  $details {
	 *     Details of the file.
	 *
	 *     @type string $kind      "image" or "audio".
	 *     @type int    $post_id   Post the file belongs to, or 0.
	 *     @type string $title     Attachment title and base of the file name.
	 *     @type string $alt       Alternative text (images).
	 *     @type string $prompt    Prompt or source text, saved as the description.
	 *     @type string $model     Model slug.
	 *     @type string $task_id   Wiro AI task ID.
	 *     @type string $cost      Cost of the task in US dollars.
	 *     @type int    $source_id Attachment the file was made from, or 0.
	 * }
	 * @return int|WP_Error Attachment ID.
	 */
	public static function import( $url, $type, array $details ) {
		if ( ! Wiro_AI_Models::is_wiro_file_url( $url ) ) {
			return new WP_Error( 'wiro_ai_bad_url', __( 'The file is not on a Wiro AI file host, so it was not downloaded.', 'wiro-ai' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$details  = wp_parse_args(
			$details,
			array(
				'kind'      => 'image',
				'post_id'   => 0,
				'title'     => '',
				'alt'       => '',
				'prompt'    => '',
				'model'     => '',
				'task_id'   => '',
				'cost'      => '',
				'source_id' => 0,
			)
		);
		$is_audio = 'audio' === $details['kind'];

		$tmp = self::download( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$base = sanitize_title( $details['title'] );
		if ( '' === $base ) {
			$base = $is_audio ? 'wiro-ai-audio' : 'wiro-ai-image';
		}
		$base      = trim( substr( $base, 0, 80 ), '-' );
		$extension = $is_audio ? Wiro_AI_Models::audio_extension( $url, $type ) : Wiro_AI_Models::image_extension( $url, $type );
		$file      = array(
			'name'     => sanitize_file_name( $base . '.' . $extension ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload(
			$file,
			(int) $details['post_id'],
			'' !== $details['title'] ? $details['title'] : null,
			array( 'post_content' => $details['prompt'] )
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $attachment_id;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		$ok   = $is_audio ? 0 === strpos( $mime, 'audio/' ) : wp_attachment_is_image( $attachment_id );
		if ( ! $ok ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error(
				'wiro_ai_bad_file',
				$is_audio
					? __( 'The file from Wiro AI is not audio WordPress can use.', 'wiro-ai' )
					: __( 'The file from Wiro AI is not an image WordPress can use.', 'wiro-ai' )
			);
		}

		if ( ! $is_audio && '' !== $details['alt'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $details['alt'] );
		}
		update_post_meta(
			$attachment_id,
			'_wiro_ai',
			array(
				'model'     => $details['model'],
				'task_id'   => $details['task_id'],
				'cost'      => $details['cost'],
				'source_id' => (int) $details['source_id'],
				'created'   => time(),
			)
		);

		return $attachment_id;
	}

	/**
	 * Attachment fields the scripts need.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function describe( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$base          = array(
			'id'       => $attachment_id,
			'url'      => (string) wp_get_attachment_url( $attachment_id ),
			'title'    => Wiro_AI_Runner::title_of( $attachment_id ),
			'edit_url' => (string) get_edit_post_link( $attachment_id, 'raw' ),
			'mime'     => (string) get_post_mime_type( $attachment_id ),
		);
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $base;
		}
		$full  = wp_get_attachment_image_src( $attachment_id, 'full' );
		$large = wp_get_attachment_image_src( $attachment_id, 'large' );
		$thumb = wp_get_attachment_image_src( $attachment_id, 'medium' );
		return array_merge(
			$base,
			array(
				'url'       => $full ? $full[0] : $base['url'],
				'width'     => $full ? (int) $full[1] : 0,
				'height'    => $full ? (int) $full[2] : 0,
				'large_url' => $large ? $large[0] : '',
				'thumb_url' => $thumb ? $thumb[0] : '',
				'alt'       => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			)
		);
	}

	/**
	 * The file of an image attachment, for uploading it to Wiro AI.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{path: string, name: string, mime: string}|WP_Error
	 */
	public static function source_file( $attachment_id ) {
		$mime = (string) get_post_mime_type( (int) $attachment_id );
		if ( ! in_array( $mime, self::SOURCE_TYPES, true ) ) {
			return new WP_Error( 'wiro_ai_bad_source', __( 'Pick a JPEG, PNG, WebP or GIF image.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		$path = get_attached_file( (int) $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'wiro_ai_bad_source', __( 'The image file is missing from the server.', 'wiro-ai' ), array( 'status' => 400 ) );
		}
		return array(
			'path' => $path,
			'name' => wp_basename( $path ),
			'mime' => $mime,
		);
	}

	/**
	 * An image attachment as a JPEG (or, when it may be transparent, PNG) data
	 * URL of at most $max pixels a side, for LLMs that read images.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $max           Longest side in pixels.
	 * @return string|WP_Error
	 */
	public static function data_url( $attachment_id, $max = 768 ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$source = self::source_file( $attachment_id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$editor = wp_get_image_editor( $source['path'] );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$editor->resize( $max, $max, false );
		$editor->set_quality( 82 );
		$tmp = wp_tempnam( 'wiro-ai-vision' );
		if ( ! $tmp ) {
			return new WP_Error( 'wiro_ai_vision_failed', __( 'Could not prepare the image.', 'wiro-ai' ) );
		}
		// JPEG turns transparent areas black, so images that may have them are sent as PNG.
		$format = 'image/jpeg' === $source['mime'] ? 'image/jpeg' : 'image/png';
		$saved  = $editor->save( $tmp . ( 'image/png' === $format ? '.png' : '.jpg' ), $format );
		wp_delete_file( $tmp );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		global $wp_filesystem;
		$bytes = WP_Filesystem() && $wp_filesystem ? $wp_filesystem->get_contents( $saved['path'] ) : false;
		wp_delete_file( $saved['path'] );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'wiro_ai_vision_failed', __( 'Could not prepare the image.', 'wiro-ai' ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes image bytes for the LLM request, not code.
		return 'data:' . $format . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Downloads a file to a temporary path.
	 *
	 * Works like download_url(), but sends the plugin's own user agent (not
	 * the site address) and stops at 50 MB.
	 *
	 * @param string $url File URL.
	 * @return string|WP_Error Temporary file path.
	 */
	private static function download( $url ) {
		$tmp = wp_tempnam( 'wiro-ai-file' );
		if ( ! $tmp ) {
			return new WP_Error( 'wiro_ai_download_failed', __( 'Could not create a temporary file for the download.', 'wiro-ai' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 120,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => 50 * MB_IN_BYTES,
				'user-agent'          => 'Wiro-WordPress/' . WIRO_AI_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'wiro_ai_download_failed',
				/* translators: %s: error message. */
				sprintf( __( 'Could not download the file from Wiro AI: %s', 'wiro-ai' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'wiro_ai_download_failed',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Could not download the file from Wiro AI (HTTP %d).', 'wiro-ai' ), $code )
			);
		}

		return $tmp;
	}
}
