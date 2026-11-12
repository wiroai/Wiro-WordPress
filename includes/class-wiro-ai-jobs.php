<?php
/**
 * Job records and usage totals.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every Wiro AI request the plugin makes is stored as a private "wiro_ai_job"
 * post: who started it, the task and model, its state, cost and result. The
 * Usage screen lists them, and monthly totals live in one option so the
 * spending limit can be checked cheaply.
 */
class Wiro_AI_Jobs {

	/**
	 * Post type of job records.
	 */
	const POST_TYPE = 'wiro_ai_job';

	/**
	 * Meta key that holds a job's data.
	 */
	const META = '_wiro_ai_job';

	/**
	 * Option with the spend per month, "Y-m" => US dollars.
	 */
	const TOTALS = 'wiro_ai_usage_totals';

	/**
	 * Registers the post type. It has no screens, archives or REST routes.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Wiro AI jobs', 'wiro-ai' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'author' ),
			)
		);
	}

	/**
	 * Creates a job record.
	 *
	 * @param string $label Short description, such as "Featured image: My post".
	 * @param array  $data  Job data.
	 * @return int|WP_Error Job ID.
	 */
	public static function create( $label, array $data ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_strip_all_tags( mb_substr( (string) $label, 0, 180 ) ),
				'post_author' => get_current_user_id(),
				'post_parent' => isset( $data['object_id'] ) ? (int) $data['object_id'] : 0,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$data = array_merge(
			array(
				'task'      => '',
				'model'     => '',
				'state'     => 'running',
				'stage'     => 'queued',
				'cost'      => 0.0,
				'started'   => time(),
				'object_id' => 0,
			),
			$data
		);
		update_post_meta( $id, self::META, $data );
		return (int) $id;
	}

	/**
	 * A job's data, with its ID, author and date.
	 *
	 * @param int $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$data = get_post_meta( $post->ID, self::META, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$data['id']     = (int) $post->ID;
		$data['author'] = (int) $post->post_author;
		$data['label']  = $post->post_title;
		$data['date']   = $post->post_date_gmt;
		return $data;
	}

	/**
	 * Merges changes into a job's data.
	 *
	 * @param int   $id      Job ID.
	 * @param array $changes New values.
	 * @return array|null The updated job.
	 */
	public static function update( $id, array $changes ) {
		$job = self::get( $id );
		if ( null === $job ) {
			return null;
		}
		$data = array_merge( $job, $changes );
		unset( $data['id'], $data['author'], $data['label'], $data['date'] );
		update_post_meta( (int) $id, self::META, $data );
		return self::get( $id );
	}

	/**
	 * Closes a job, records its cost and forgets its task token.
	 *
	 * @param int    $id      Job ID.
	 * @param string $state   "done" or "failed".
	 * @param array  $changes Other values, such as cost, result or error.
	 * @return array|null
	 */
	public static function finish( $id, $state, array $changes = array() ) {
		$changes['state']    = $state;
		$changes['stage']    = $state;
		$changes['finished'] = time();
		$changes['token']    = '';
		$job                 = self::update( $id, $changes );
		$cost                = isset( $changes['cost'] ) ? (float) $changes['cost'] : 0.0;
		if ( null !== $job && $cost > 0 ) {
			self::add_cost( $cost );
		}
		return $job;
	}

	/**
	 * Adds a cost to this month's total.
	 *
	 * @param float $cost Cost in US dollars.
	 */
	public static function add_cost( $cost ) {
		$totals = self::totals();
		$month  = gmdate( 'Y-m' );
		if ( ! isset( $totals[ $month ] ) ) {
			$totals[ $month ] = 0.0;
		}
		$totals[ $month ] = round( $totals[ $month ] + (float) $cost, 6 );
		update_option( self::TOTALS, $totals, false );
	}

	/**
	 * Spend per month.
	 *
	 * @return array<string, float> "Y-m" => US dollars, newest first.
	 */
	public static function totals() {
		$totals = get_option( self::TOTALS, array() );
		if ( ! is_array( $totals ) ) {
			return array();
		}
		$clean = array();
		foreach ( $totals as $month => $amount ) {
			if ( is_string( $month ) && preg_match( '/^\d{4}-\d{2}$/', $month ) && is_numeric( $amount ) ) {
				$clean[ $month ] = (float) $amount;
			}
		}
		krsort( $clean );
		return $clean;
	}

	/**
	 * Spend in the current month (UTC).
	 *
	 * @return float
	 */
	public static function month_total() {
		$totals = self::totals();
		$month  = gmdate( 'Y-m' );
		return isset( $totals[ $month ] ) ? $totals[ $month ] : 0.0;
	}

	/**
	 * Error when the monthly spending limit is reached, else null.
	 *
	 * @return WP_Error|null
	 */
	public static function limit_error() {
		$settings = Wiro_AI_Settings::get();
		$limit    = (float) $settings['monthly_limit'];
		if ( $limit <= 0 || self::month_total() < $limit ) {
			return null;
		}
		return new WP_Error(
			'wiro_ai_limit',
			sprintf(
				/* translators: %s: monthly spending limit such as $20.00. */
				__( 'This site reached its monthly Wiro AI spending limit of %s. An administrator can raise it in Wiro AI > Settings.', 'wiro-ai' ),
				Wiro_AI_Pricing::format_usd( $limit )
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * A page of job records, newest first.
	 *
	 * @param int $page     Page number, from 1.
	 * @param int $per_page Records per page.
	 * @return array{jobs: array[], total: int, pages: int}
	 */
	public static function page( $page, $per_page = 50 ) {
		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, (int) $per_page ),
				'paged'                  => max( 1, (int) $page ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
			)
		);
		$jobs  = array();
		foreach ( $query->posts as $post ) {
			$job = self::get( $post->ID );
			if ( null !== $job ) {
				$jobs[] = $job;
			}
		}
		return array(
			'jobs'  => $jobs,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Deletes every job record, in batches. Used when the plugin is uninstalled.
	 */
	public static function delete_all() {
		do {
			$ids = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		} while ( $ids );
	}
}
