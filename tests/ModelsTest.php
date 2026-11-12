<?php
/**
 * Unit tests for Wiro_AI_Models.
 *
 * @package WiroAI
 */

use PHPUnit\Framework\TestCase;

/**
 * Request building, slugs, outputs and URL checks.
 */
final class ModelsTest extends TestCase {

	/**
	 * A parameter field as parameter_items() returns it.
	 *
	 * @param string $id       Parameter ID.
	 * @param string $type     Parameter type.
	 * @param bool   $required Whether the model requires it.
	 * @param string $fallback Default value.
	 * @param array  $options  Menu options.
	 * @return array
	 */
	private static function item( $id, $type, $required = false, $fallback = '', array $options = array() ) {
		return array(
			'id'       => $id,
			'type'     => $type,
			'required' => $required,
			'default'  => $fallback,
			'options'  => $options,
		);
	}

	/**
	 * Slugs and model page URLs become lowercase owner/project slugs.
	 */
	public function test_normalize_slug_accepts_slugs_and_urls() {
		$this->assertSame( 'google/nano-banana-2-1', Wiro_AI_Models::normalize_slug( 'google/nano-banana-2-1' ) );
		$this->assertSame( 'google/nano-banana-2-1', Wiro_AI_Models::normalize_slug( ' Google/Nano-Banana-2-1/ ' ) );
		$this->assertSame( 'bytedance/seedream-v5-lite', Wiro_AI_Models::normalize_slug( 'https://wiro.ai/models/bytedance/seedream-v5-lite' ) );
		$this->assertSame( 'openai/gpt-image-2', Wiro_AI_Models::normalize_slug( 'https://api.wiro.ai/v1/Run/openai/gpt-image-2' ) );
		$this->assertSame( 'pruna/p-image', Wiro_AI_Models::normalize_slug( 'https://wiro.ai/models/pruna/p-image?tab=api#run' ) );
	}

	/**
	 * Anything that is not owner/project is rejected.
	 */
	public function test_normalize_slug_rejects_bad_values() {
		foreach ( array( '', 'nano-banana', 'a/b/c', '../etc', 'owner/mo del', '-owner/model', 'owner/<b>' ) as $value ) {
			$this->assertSame( '', Wiro_AI_Models::normalize_slug( $value ), $value );
		}
	}

	/**
	 * The built-in model list is well formed.
	 */
	public function test_catalog_keys_are_valid_slugs_and_include_the_default() {
		$catalog = Wiro_AI_Models::catalog();
		$this->assertArrayHasKey( Wiro_AI_Models::DEFAULT_MODEL, $catalog );
		foreach ( $catalog as $slug => $model ) {
			$this->assertSame( $slug, Wiro_AI_Models::normalize_slug( $slug ) );
			$this->assertNotEmpty( $model['label'] );
		}
		$this->assertTrue( Wiro_AI_Models::is_ratio( Wiro_AI_Models::DEFAULT_RATIO ) );
	}

	/**
	 * Tool/Detail parameter groups become a flat list.
	 */
	public function test_parameter_items_flattens_groups_and_options() {
		$tool  = array(
			'parameters' => array(
				array(
					'items' => array(
						array(
							'id'           => 'prompt',
							'type'         => 'textarea',
							'required'     => true,
							'defaultvalue' => 'A sample prompt',
						),
						array(
							'id'           => 'aspectRatio',
							'type'         => 'select',
							'required'     => false,
							'defaultvalue' => '16:9',
							'options'      => array(
								array(
									'text'  => '16:9',
									'value' => '16:9',
								),
								array(
									'text'  => '1:1',
									'value' => '1:1',
								),
							),
						),
						'not an item',
					),
				),
				array( 'items' => array( array( 'type' => 'number' ) ) ),
			),
		);
		$items = Wiro_AI_Models::parameter_items( $tool );
		$this->assertCount( 2, $items );
		$this->assertSame( array( '16:9', '1:1' ), $items[1]['options'] );
		$this->assertTrue( $items[0]['required'] );
		$this->assertSame( array(), Wiro_AI_Models::parameter_items( null ) );
	}

	/**
	 * Only required menus, numbers and checkboxes get their defaults.
	 */
	public function test_build_params_fills_required_defaults_only() {
		$items  = array(
			self::item( 'inputImage', 'combinefileinput', false, 'https://cdn.wiro.ai/sample.png' ),
			self::item( 'prompt', 'textarea', true, 'Sample prompt' ),
			self::item( 'resolution', 'select', true, '1K', array( '1K', '2K' ) ),
			self::item( 'samples', 'number', true, '1' ),
			self::item( 'guidance', 'float', true, '3.0' ),
			self::item( 'seed', 'text', true, '42' ),
			self::item( 'steps', 'number', false, '30' ),
		);
		$params = Wiro_AI_Models::build_params( $items, 'A red fox', '16:9' );
		$this->assertSame(
			array(
				'resolution' => '1K',
				'samples'    => 1,
				'guidance'   => 3.0,
				'prompt'     => 'A red fox',
			),
			$params
		);
	}

	/**
	 * A supported ratio goes straight to the ratio menu.
	 */
	public function test_build_params_uses_the_ratio_menu() {
		$items  = array( self::item( 'ratio', 'select', true, '1:1', array( '1:1', '3:2', '16:9' ) ) );
		$params = Wiro_AI_Models::build_params( $items, 'x', '3:2' );
		$this->assertSame( '3:2', $params['ratio'] );
	}

	/**
	 * An unsupported ratio falls back to the closest option.
	 */
	public function test_build_params_picks_the_closest_ratio() {
		$items = array( self::item( 'aspectRatio', 'select', false, 'auto', array( 'auto', '16:9', '9:16', '1:1' ) ) );
		$this->assertSame( '16:9', Wiro_AI_Models::build_params( $items, 'x', '3:2' )['aspectRatio'] );
		$this->assertSame( '9:16', Wiro_AI_Models::build_params( $items, 'x', '2:3' )['aspectRatio'] );
		$this->assertSame( '1:1', Wiro_AI_Models::build_params( $items, 'x', '1:1' )['aspectRatio'] );
	}

	/**
	 * Models without a ratio menu get a width and height.
	 */
	public function test_build_params_sets_width_and_height_without_a_ratio_menu() {
		$items  = array(
			self::item( 'width', 'number', false, '1024' ),
			self::item( 'height', 'number', false, '1024' ),
		);
		$params = Wiro_AI_Models::build_params( $items, 'x', '16:9' );
		$this->assertSame( 1360, $params['width'] );
		$this->assertSame( 768, $params['height'] );
		foreach ( Wiro_AI_Models::RATIOS as $ratio ) {
			list( $width, $height ) = Wiro_AI_Models::dimensions( $ratio );
			$this->assertSame( 0, $width % 16, $ratio );
			$this->assertSame( 0, $height % 16, $ratio );
			$this->assertLessThanOrEqual( 1024 * 1024, $width * $height, $ratio );
		}
	}

	/**
	 * Presets apply only when the model accepts the value.
	 */
	public function test_build_params_applies_presets_the_model_accepts() {
		$items  = array( self::item( 'quality', 'select', true, 'low', array( 'low', 'medium', 'high' ) ) );
		$params = Wiro_AI_Models::build_params(
			$items,
			'x',
			'1:1',
			array(
				'quality' => 'medium',
				'unknown' => 'value',
			)
		);
		$this->assertSame( 'medium', $params['quality'] );
		$this->assertArrayNotHasKey( 'unknown', $params );

		$params = Wiro_AI_Models::build_params( $items, 'x', '1:1', array( 'quality' => 'ultra' ) );
		$this->assertSame( 'low', $params['quality'] );

		$params = Wiro_AI_Models::build_params( array(), 'x', '1:1', array( 'quality' => 'medium' ) );
		$this->assertSame(
			array(
				'quality' => 'medium',
				'prompt'  => 'x',
			),
			$params
		);
	}

	/**
	 * The first image output is picked.
	 */
	public function test_pick_image_output_skips_text_outputs() {
		$outputs = array(
			array(
				'contenttype' => 'raw',
				'content'     => array( 'raw' => 'text' ),
			),
			array(
				'contenttype' => 'image/png',
				'url'         => 'https://cdn1.wiro.ai/a/b/0.png',
			),
		);
		$this->assertSame(
			array(
				'url'  => 'https://cdn1.wiro.ai/a/b/0.png',
				'type' => 'image/png',
			),
			Wiro_AI_Models::pick_image_output( $outputs )
		);
		$this->assertSame( 'https://cdn1.wiro.ai/x.webp', Wiro_AI_Models::pick_image_output( array( array( 'url' => 'https://cdn1.wiro.ai/x.webp' ) ) )['url'] );
		$this->assertNull( Wiro_AI_Models::pick_image_output( array( array( 'url' => 'https://cdn1.wiro.ai/x.mp4' ) ) ) );
		$this->assertNull( Wiro_AI_Models::pick_image_output( 'nope' ) );
	}

	/**
	 * Only HTTPS Wiro AI hosts are trusted for downloads.
	 */
	public function test_is_wiro_file_url() {
		$this->assertTrue( Wiro_AI_Models::is_wiro_file_url( 'https://cdn1.wiro.ai/uploads/0.png' ) );
		$this->assertTrue( Wiro_AI_Models::is_wiro_file_url( 'https://wiro.ai/file.png' ) );
		foreach ( array(
			'http://cdn1.wiro.ai/0.png',
			'https://wiro.ai.example.com/0.png',
			'https://evilwiro.ai/0.png',
			'https://cdn1.wiro.ai:8443/0.png',
			'https://user@cdn1.wiro.ai/0.png',
			'file:///etc/passwd',
			'',
		) as $url ) {
			$this->assertFalse( Wiro_AI_Models::is_wiro_file_url( $url ), $url );
		}
	}

	/**
	 * The file extension comes from the URL or the content type.
	 */
	public function test_image_extension() {
		$this->assertSame( 'jpg', Wiro_AI_Models::image_extension( 'https://cdn1.wiro.ai/a.jpeg', '' ) );
		$this->assertSame( 'webp', Wiro_AI_Models::image_extension( 'https://cdn1.wiro.ai/a.webp?x=1', 'image/png' ) );
		$this->assertSame( 'png', Wiro_AI_Models::image_extension( 'https://cdn1.wiro.ai/a', 'image/png' ) );
		$this->assertSame( 'jpg', Wiro_AI_Models::image_extension( 'https://cdn1.wiro.ai/a.bin', 'image/jpeg' ) );
		$this->assertSame( 'png', Wiro_AI_Models::image_extension( 'https://cdn1.wiro.ai/a', '' ) );
	}

	/**
	 * Task statuses map to the stages the editor shows.
	 */
	public function test_status_stage() {
		$this->assertSame( 'queued', Wiro_AI_Models::status_stage( 'task_queue' ) );
		$this->assertSame( 'preparing', Wiro_AI_Models::status_stage( 'task_assign' ) );
		$this->assertSame( 'generating', Wiro_AI_Models::status_stage( 'task_start' ) );
		$this->assertSame( 'finishing', Wiro_AI_Models::status_stage( 'task_postprocess_start' ) );
		$this->assertSame( 'done', Wiro_AI_Models::status_stage( 'task_postprocess_end' ) );
		$this->assertSame( 'queued', Wiro_AI_Models::status_stage( 'something_new' ) );
	}
}
