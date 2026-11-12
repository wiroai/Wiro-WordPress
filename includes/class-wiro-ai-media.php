<?php
/**
 * Saves generated images to the Media Library.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads a finished image from Wiro AI and adds it to the Media Library.
 */
class Wiro_AI_Media {

	/**
	 * Downloads an image and creates its attachment.
	 *
	 * @param string $url     Output URL on a Wiro AI file host.
	 * @param string $type    Content type reported by Wiro AI.
	 * @param array  $details {
	 *     Details of the image.
	 *
	 *     @type int    $post_id Post the image belongs to, or 0.
	 *     @type string $title   Attachment title and base of the file name.
	 *     @type string $alt     Alternative text.
	 *     @type string $prompt  Prompt the image was made from.
	 *     @type string $model   Model slug.
	 *     @type string $task_id Wiro AI task ID.
	 *     @type string $cost    Cost of the task in US dollars.
	 * }
	 * @return int|WP_Error Attachment ID.
	 */
	public static function import( $url, $type, array $details ) {
		if ( ! Wiro_AI_Models::is_wiro_file_url( $url ) ) {
			return new WP_Error( 'wiro_ai_bad_url', __( 'The image is not on a Wiro AI file host, so it was not downloaded.', 'wiro-ai' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$details = wp_parse_args(
			$details,
			array(
				'post_id' => 0,
				'title'   => '',
				'alt'     => '',
				'prompt'  => '',
				'model'   => '',
				'task_id' => '',
				'cost'    => '',
			)
		);

		$tmp = self::download( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$base = sanitize_title( $details['title'] );
		if ( '' === $base ) {
			$base = 'wiro-ai-image';
		}
		$base = trim( substr( $base, 0, 80 ), '-' );
		$file = array(
			'name'     => sanitize_file_name( $base . '.' . Wiro_AI_Models::image_extension( $url, $type ) ),
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

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'wiro_ai_not_image', __( 'The file from Wiro AI is not an image WordPress can use.', 'wiro-ai' ) );
		}

		if ( '' !== $details['alt'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $details['alt'] );
		}
		update_post_meta(
			$attachment_id,
			'_wiro_ai',
			array(
				'model'   => $details['model'],
				'task_id' => $details['task_id'],
				'cost'    => $details['cost'],
				'created' => time(),
			)
		);

		return $attachment_id;
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
		$tmp = wp_tempnam( 'wiro-ai-image' );
		if ( ! $tmp ) {
			return new WP_Error( 'wiro_ai_download_failed', __( 'Could not create a temporary file for the image.', 'wiro-ai' ) );
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
				sprintf( __( 'Could not download the image from Wiro AI: %s', 'wiro-ai' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'wiro_ai_download_failed',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Could not download the image from Wiro AI (HTTP %d).', 'wiro-ai' ), $code )
			);
		}

		return $tmp;
	}

	/**
	 * Attachment fields the editor scripts need.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function describe( $attachment_id ) {
		$full  = wp_get_attachment_image_src( $attachment_id, 'full' );
		$large = wp_get_attachment_image_src( $attachment_id, 'large' );
		$thumb = wp_get_attachment_image_src( $attachment_id, 'medium' );
		return array(
			'id'        => (int) $attachment_id,
			'url'       => $full ? $full[0] : wp_get_attachment_url( $attachment_id ),
			'width'     => $full ? (int) $full[1] : 0,
			'height'    => $full ? (int) $full[2] : 0,
			'large_url' => $large ? $large[0] : '',
			'thumb_url' => $thumb ? $thumb[0] : '',
			'alt'       => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'edit_url'  => get_edit_post_link( $attachment_id, 'raw' ),
		);
	}
}
