<?php
/**
 * Admin screens: menu, bulk featured images, usage and Media Library tools.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Wiro AI menu (Settings, Bulk images, Usage) and the Wiro AI tools on
 * Media Library images.
 */
class Wiro_AI_Admin {

	/**
	 * The Wiro AI "W" mark as an SVG data URI, painted by WordPress in the
	 * admin color scheme.
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDgwIDEwODAiPjxwYXRoIGZpbGw9ImJsYWNrIiBkPSJNMTI4IDE3cTUzLTEgOTMgMzVhMTIwIDEyMCAwIDAgMSAzNiA4OHYxMzdsMSAxNzl2MjQzbDEtMiAzMy01N2EzNDQgMzQ0IDAgMCAxIDc1LTk0cTE3LTE0IDM1LTI0bDItMWM1NS0zMyAxMjUtMzggMTg3LTIzcTI4IDkgNDkgMzBsMiAyIDE2IDIwIDkgMTEgMiAyIDMgNSA5IDExIDI4IDM3IDEwIDEyIDE0IDE5IDEwIDEyIDEyIDE2IDE5IDI0IDI3IDM1IDIgMyAxNCAxNyAxIDIgMyA0VjQ4OWwxLTMzNmMwLTM5IDgtNzMgMzYtMTAxIDI0LTI0IDU3LTM1IDkxLTM1YTEyMyAxMjMgMCAwIDEgOTAgMzhxMjAgMTkgMjcgNDNsMSAyYzYgMTYgNSAzMiA1IDQ5bDEgMTM2djQ1NHEwIDMyLTQgNjNsLTEgMmEzMjIgMzIyIDAgMCAxLTM0IDEwMiAyNDIgMjQyIDAgMCAxLTYwIDc0bC02IDRhMzYxIDM2MSAwIDAgMS0yMzUgNzhsLTEwLTEyLTYtOC0yLTMtMTAtMTMtMTQtMTktMTAtMTItNDgtNjMtMTAtMTItMTQtMTktMTAtMTItNDgtNjMtMTAtMTItMjUtMzItMS0yLTItMi0yLTItMi0zLTEgMi0zNCA2MC0yIDMtNCA3LTkgMTYtNCA3LTgzIDE0My0yNSA0Mi0yIDUtMyA0aC0xN2MtODktNS0xODItMzktMjQ0LTEwNnEtMjMtMjQtMzgtNTRsLTEtMnEtMjMtNDctMzItOTh2LTRhNDE2IDQxNiAwIDAgMS00LTg4VjE0NlE1IDkyIDQyIDUycTE3LTE2IDM4LTI1bDMtMXEyMi05IDQ1LTkiLz48cGF0aCBmaWxsPSJibGFjayIgZD0iTTEyOCAxN3E1My0xIDkzIDM1YTEyMCAxMjAgMCAwIDEgMzYgOTZ2NzgwYzAgMzcgNSA3MCAzMiA5OHEyNCAyMiA1NiAzMGwtMyA2Yy04NyAzLTE4Mi0zMi0yNDYtOTFxLTMyLTMwLTUzLTY5bC0xLTJxLTIzLTQ3LTMyLTk4di00YTQxNiA0MTYgMCAwIDEtNC04OFYxNDZRNSA5MiA0MiA1MnExNy0xNiAzOC0yNWwzLTFxMjItOSA0NS05Ii8+PHBhdGggZmlsbD0iYmxhY2siIGQ9Im02MzggNTI2IDIgMiAyIDIgMTUgMTggMSAyIDEwIDEyLTEgMi02IDJjLTMzIDktNTIgMzItNjggNjBsLTUgMTAtMzAgNTItOSAxNi00IDctMTAgMTYtMTIgMjEtMTYgMjktMTIgMjEtNDQgNzYtNjAgMTA0LTQ1IDc4LTE4LTRxLTE4LTgtMzMtMjBsLTMtMmMtMjEtMTgtMzQtNDYtMzYtNzR2LTI0bC0xLTMydi0ybC0xLTE0NmMwLTU0IDAtNTQgMTUtNzRsNy0xMSAyMC0zNCAxLTJjMTYtMjggMTYtMjggMjUtMzlsMi0zIDYtNyAxLTJhMjE4IDIxOCAwIDAgMSA3MS01OGwyLTFjNjUtMzggMTc0LTUwIDIzNCA1Ii8+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik02NjcgNTYzcTEyIDEzIDIyIDI3bDM3IDQ4IDE3IDIxIDIxIDI4IDEwIDEyIDE0IDE5IDEwIDEyIDEyIDE2IDMgMyAxIDEgMyA0YzggOSA3IDE3IDcgMjh2MTQwYzAgNDEtNSA3Ni0zNSAxMDZsLTIgMnEtMjUgMjItNTcgMjhsLTMyLTQxLTI0LTMyLTEwLTEyLTE0LTE5LTEwLTEyLTQ4LTYzLTEwLTEyLTE0LTE5LTEwLTEyLTE5LTI1LTMtMy0xLTItMjEtMjYtMi0zLTItM3YtNGw0LTggMi0zIDItNCA2LTEwIDEwLTE3IDMtNiAyLTQgMjEtMzUgMi00IDI2LTQ1YzE4LTMxIDE4LTMxIDI5LTQzbDItMnEyMC0yMCA0OC0yNiIvPjwvc3ZnPg==';

	/**
	 * Hooks the screens into the admin.
	 *
	 * @param Wiro_AI_Settings $settings Settings screen.
	 */
	public function init( Wiro_AI_Settings $settings ) {
		$this->settings = $settings;
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_media_box' ) );
		add_filter( 'media_row_actions', array( $this, 'media_row_actions' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 10, 2 );
	}

	/**
	 * Settings screen.
	 *
	 * @var Wiro_AI_Settings
	 */
	private $settings;

	/**
	 * Adds the Wiro AI menu.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Wiro AI', 'wiro-ai' ),
			__( 'Wiro AI', 'wiro-ai' ),
			'manage_options',
			Wiro_AI_Settings::PAGE,
			array( $this->settings, 'render_page' ),
			self::MENU_ICON,
			81
		);
		add_submenu_page( Wiro_AI_Settings::PAGE, __( 'Wiro AI settings', 'wiro-ai' ), __( 'Settings', 'wiro-ai' ), 'manage_options', Wiro_AI_Settings::PAGE, array( $this->settings, 'render_page' ) );
		add_submenu_page( Wiro_AI_Settings::PAGE, __( 'Bulk featured images', 'wiro-ai' ), __( 'Bulk images', 'wiro-ai' ), 'manage_options', 'wiro-ai-bulk', array( $this, 'render_bulk_page' ) );
		add_submenu_page( Wiro_AI_Settings::PAGE, __( 'Wiro AI usage', 'wiro-ai' ), __( 'Usage', 'wiro-ai' ), 'manage_options', 'wiro-ai-usage', array( $this, 'render_usage_page' ) );
	}

	/**
	 * Post types the bulk screen can fill: shown in the admin, with featured images.
	 *
	 * @return array<string, string> Post type => label.
	 */
	public static function bulk_post_types() {
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name || ! post_type_supports( $type->name, 'thumbnail' ) || ! current_theme_supports( 'post-thumbnails', $type->name ) ) {
				continue;
			}
			$types[ $type->name ] = $type->labels->name;
		}
		return $types;
	}

	/**
	 * Bulk featured images screen. The list comes from the server; the queue
	 * runs in the browser (assets/js/bulk.js) one request at a time per slot.
	 */
	public function render_bulk_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$types = self::bulk_post_types();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters of an admin screen.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $types[ $type ] ) ) {
			$type = $types ? (string) key( $types ) : '';
		}

		$posts = array();
		$pages = 0;
		$total = 0;
		if ( '' !== $type ) {
			$query = new WP_Query(
				array(
					'post_type'              => $type,
					'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page'         => 50,
					'paged'                  => $page,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only screen, 50 posts a page.
					'meta_query'             => array(
						array(
							'key'     => '_thumbnail_id',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
			$pages = (int) $query->max_num_pages;
			$total = (int) $query->found_posts;
			foreach ( $query->posts as $post ) {
				$posts[] = array(
					'id'     => (int) $post->ID,
					'title'  => '' !== trim( get_the_title( $post ) ) ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : __( '(no title)', 'wiro-ai' ),
					'status' => get_post_status_object( get_post_status( $post ) ) ? get_post_status_object( get_post_status( $post ) )->label : '',
					'edit'   => (string) get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}
		?>
		<div class="wrap wiro-ai-admin">
			<h1><?php esc_html_e( 'Bulk featured images', 'wiro-ai' ); ?></h1>
			<p><?php esc_html_e( 'Posts without a featured image are listed here. Pick the posts, check the price, and Wiro AI makes an image for each one from its title (or title and opening words), saves it to the Media Library and sets it as the featured image. Keep this page open while the queue runs.', 'wiro-ai' ); ?></p>
			<?php if ( ! Wiro_AI_Settings::has_key() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'Add your Wiro AI API key first.', 'wiro-ai' ); ?>
					<a href="<?php echo esc_url( Wiro_AI_Settings::url() ); ?>"><?php esc_html_e( 'Open settings', 'wiro-ai' ); ?></a>
				</p></div>
			<?php endif; ?>
			<?php if ( count( $types ) > 1 ) : ?>
				<form method="get" class="wiro-ai-filter">
					<input type="hidden" name="page" value="wiro-ai-bulk" />
					<label for="wiro-ai-post-type"><?php esc_html_e( 'Post type', 'wiro-ai' ); ?></label>
					<select id="wiro-ai-post-type" name="post_type">
						<?php foreach ( $types as $name => $label ) : ?>
							<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $type, $name ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Show', 'wiro-ai' ), 'secondary', '', false ); ?>
				</form>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: %s: number of posts. */
					esc_html( _n( '%s post without a featured image.', '%s posts without a featured image.', $total, 'wiro-ai' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</p>
			<div id="wiro-ai-bulk-root" data-posts="<?php echo esc_attr( wp_json_encode( $posts ) ); ?>"></div>
			<?php
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Usage screen: totals per month and every job, newest first.
	 */
	public function render_usage_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page number of a read-only list.
		$page       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result     = Wiro_AI_Jobs::page( $page, 50 );
		$totals     = Wiro_AI_Jobs::totals();
		$tasks      = Wiro_AI_Catalog::tasks();
		$states     = array(
			'running' => __( 'Running', 'wiro-ai' ),
			'done'    => __( 'Done', 'wiro-ai' ),
			'failed'  => __( 'Failed', 'wiro-ai' ),
		);
		$this_month = gmdate( 'Y-m' );
		$last_month = gmdate( 'Y-m', strtotime( 'first day of last month' ) );
		?>
		<div class="wrap wiro-ai-admin">
			<h1><?php esc_html_e( 'Wiro AI usage', 'wiro-ai' ); ?></h1>
			<div class="wiro-ai-totals">
				<div><span><?php esc_html_e( 'This month', 'wiro-ai' ); ?></span><strong><?php echo esc_html( Wiro_AI_Pricing::format_usd( isset( $totals[ $this_month ] ) ? $totals[ $this_month ] : 0 ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Last month', 'wiro-ai' ); ?></span><strong><?php echo esc_html( Wiro_AI_Pricing::format_usd( isset( $totals[ $last_month ] ) ? $totals[ $last_month ] : 0 ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'All time', 'wiro-ai' ); ?></span><strong><?php echo esc_html( Wiro_AI_Pricing::format_usd( array_sum( $totals ) ) ); ?></strong></div>
			</div>
			<p class="description"><?php esc_html_e( 'Costs are what Wiro AI charged for each request made from this site. Your full billing history is on wiro.ai.', 'wiro-ai' ); ?> <a href="https://wiro.ai/panel/billing" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open billing', 'wiro-ai' ); ?></a></p>
			<table class="widefat striped wiro-ai-usage">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wiro-ai' ); ?></th>
						<th><?php esc_html_e( 'Request', 'wiro-ai' ); ?></th>
						<th><?php esc_html_e( 'Model', 'wiro-ai' ); ?></th>
						<th><?php esc_html_e( 'User', 'wiro-ai' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wiro-ai' ); ?></th>
						<th class="num"><?php esc_html_e( 'Cost', 'wiro-ai' ); ?></th>
						<th><?php esc_html_e( 'Result', 'wiro-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $result['jobs'] ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Nothing yet. Requests show up here as soon as someone uses Wiro AI.', 'wiro-ai' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $result['jobs'] as $job ) : ?>
					<?php
					$user  = get_userdata( (int) $job['author'] );
					$task  = isset( $tasks[ $job['task'] ] ) ? $tasks[ $job['task'] ]['label'] : $job['task'];
					$state = isset( $states[ $job['state'] ] ) ? $states[ $job['state'] ] : $job['state'];
					?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $job['date'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><strong><?php echo esc_html( $job['label'] ); ?></strong><br /><span class="description"><?php echo esc_html( $task ); ?></span></td>
						<td><code><?php echo esc_html( $job['model'] ); ?></code></td>
						<td><?php echo esc_html( $user ? $user->display_name : '' ); ?></td>
						<td>
							<?php echo esc_html( $state ); ?>
							<?php if ( 'failed' === $job['state'] && ! empty( $job['error'] ) ) : ?>
								<br /><span class="description"><?php echo esc_html( wp_trim_words( $job['error'], 20 ) ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( isset( $job['cost'] ) && (float) $job['cost'] > 0 ? Wiro_AI_Pricing::format_usd( (float) $job['cost'] ) : '' ); ?></td>
						<td><?php self::print_result( $job ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			if ( $result['pages'] > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => $result['pages'],
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Prints a job's result: a Media Library link, or the start of its text.
	 *
	 * @param array $job Job record.
	 */
	private static function print_result( array $job ) {
		$result = isset( $job['result'] ) ? $job['result'] : '';
		if ( is_numeric( $result ) && (int) $result > 0 && get_post( (int) $result ) ) {
			$link = get_edit_post_link( (int) $result );
			if ( wp_attachment_is_image( (int) $result ) ) {
				echo '<a href="' . esc_url( (string) $link ) . '">' . wp_get_attachment_image( (int) $result, array( 48, 48 ) ) . '</a>';
			} else {
				echo '<a href="' . esc_url( (string) $link ) . '">' . esc_html( get_the_title( (int) $result ) ) . '</a>';
			}
			return;
		}
		if ( is_string( $result ) && '' !== $result ) {
			$decoded = json_decode( $result, true );
			$text    = is_array( $decoded ) && ! empty( $decoded['titles'][0] ) ? (string) $decoded['titles'][0] : $result;
			echo esc_html( wp_trim_words( $text, 14 ) );
		}
	}

	/**
	 * Adds the Wiro AI box to the edit screen of images.
	 *
	 * @param WP_Post $post Attachment.
	 */
	public function add_media_box( $post ) {
		if ( ! wp_attachment_is_image( $post ) || ! Wiro_AI_Plugin::current_user_can_generate() ) {
			return;
		}
		add_meta_box( 'wiro-ai-media-tools', __( 'Wiro AI', 'wiro-ai' ), array( $this, 'render_media_box' ), 'attachment', 'side', 'high' );
	}

	/**
	 * The Wiro AI box: the scripts render the tools into it.
	 *
	 * @param WP_Post $post Attachment.
	 */
	public function render_media_box( $post ) {
		printf(
			'<div id="wiro-ai-media-tools-root" data-attachment="%1$d" data-title="%2$s"><p class="description">%3$s</p></div>',
			(int) $post->ID,
			esc_attr( Wiro_AI_Runner::title_of( $post->ID ) ),
			esc_html__( 'Loading Wiro AI tools...', 'wiro-ai' )
		);
	}

	/**
	 * Adds a Wiro AI link to images in the Media Library list.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	public function media_row_actions( $actions, $post ) {
		if ( wp_attachment_is_image( $post ) && Wiro_AI_Plugin::current_user_can_generate() && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['wiro_ai'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) . '#wiro-ai-media-tools' ) . '">' . esc_html__( 'Wiro AI tools', 'wiro-ai' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Adds a Wiro AI link to the image details in the media window.
	 *
	 * @param array   $fields Attachment fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public function attachment_fields( $fields, $post ) {
		if ( ! wp_attachment_is_image( $post ) || ! Wiro_AI_Plugin::current_user_can_generate() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}
		$fields['wiro_ai_tools'] = array(
			'label' => __( 'Wiro AI', 'wiro-ai' ),
			'input' => 'html',
			'html'  => '<a href="' . esc_url( get_edit_post_link( $post->ID ) . '#wiro-ai-media-tools' ) . '" target="_blank">' . esc_html__( 'Edit, upscale, remove the background or write alt text', 'wiro-ai' ) . '</a>',
		);
		return $fields;
	}
}
