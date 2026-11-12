<?php
/**
 * Unit tests for Wiro_AI_Pricing.
 *
 * @package WiroAI
 */

use PHPUnit\Framework\TestCase;

/**
 * Price quotes, the way Wiro AI bills a run.
 */
final class PricingTest extends TestCase {

	/**
	 * Per-run prices pick the row whose values match the settings.
	 */
	public function test_cpr_matches_the_settings_row() {
		$model = array(
			'dynamicprice' => '[{"inputs":{"resolution":"1K"},"price":0.0336,"priceMethod":"cpr"},{"inputs":{"resolution":"2K"},"price":0.0504,"priceMethod":"cpr"}]',
		);
		$quote = Wiro_AI_Pricing::quote( $model, array( 'resolution' => '2K' ) );
		$this->assertSame( 0.0504, $quote['amount'] );
		$this->assertFalse( $quote['approximate'] );
		$this->assertSame( '$0.05', $quote['label'] );

		$quote = Wiro_AI_Pricing::quote( $model, array( 'resolution' => '1K' ) );
		$this->assertSame( '$0.034', $quote['label'] );
	}

	/**
	 * Per-output prices multiply by the number of outputs.
	 */
	public function test_cpo_counts_outputs() {
		$model = array(
			'dynamicprice' => array(
				array(
					'inputs'      => array(),
					'price'       => 0.04,
					'priceMethod' => 'cpo',
				),
			),
		);
		$quote = Wiro_AI_Pricing::quote( $model, array( 'samples' => 3 ) );
		$this->assertEqualsWithDelta( 0.12, $quote['amount'], 0.000001 );
	}

	/**
	 * Per-character prices use the length of the text.
	 */
	public function test_promptlength_uses_characters() {
		$model = array(
			'dynamicprice' => array(
				array(
					'inputs'      => array(),
					'price'       => 0.00004,
					'priceMethod' => 'cp-promptlength',
				),
			),
		);
		$quote = Wiro_AI_Pricing::quote( $model, array(), array( 'chars' => 6000 ) );
		$this->assertEqualsWithDelta( 0.24, $quote['amount'], 0.000001 );
		$this->assertSame( '$0.24', $quote['label'] );
		$this->assertStringContainsString( '6,000 characters', $quote['note'] );

		$quote = Wiro_AI_Pricing::quote( $model, array() );
		$this->assertNull( $quote['amount'] );
		$this->assertSame( '$0.00004 per character', $quote['label'] );
	}

	/**
	 * Pixel prices count 1024 x 1024 blocks.
	 */
	public function test_pixel_counts_blocks() {
		$model = array(
			'dynamicprice' => array(
				array(
					'inputs'      => array( 'inputImage' => 'QUANTITY:0' ),
					'price'       => 0.03,
					'priceMethod' => 'cp-pixel',
					'priceExtra'  => 0.015,
				),
			),
		);
		$quote = Wiro_AI_Pricing::quote( $model, array(), array( 'pixels' => 1360 * 768 ) );
		$this->assertEqualsWithDelta( 0.03, $quote['amount'], 0.000001 );
		$quote = Wiro_AI_Pricing::quote( $model, array(), array( 'pixels' => 2048 * 2048 ) );
		$this->assertEqualsWithDelta( 0.075, $quote['amount'], 0.000001 );
	}

	/**
	 * Rows that count files pick the valid row with the highest count.
	 */
	public function test_quantity_rows() {
		$rows = Wiro_AI_Pricing::rows(
			array(
				array(
					'inputs'      => array( 'inputImage' => 'QUANTITY:0' ),
					'price'       => 0.03,
					'priceMethod' => 'cpr',
				),
				array(
					'inputs'      => array( 'inputImage' => 'QUANTITY:1' ),
					'price'       => 0.05,
					'priceMethod' => 'cpr',
				),
			)
		);
		$this->assertSame( 0.05, Wiro_AI_Pricing::matching_row( $rows, array( 'inputImage' => array( 'https://cdn1.wiro.ai/a.png' ) ) )['price'] );
		$this->assertSame( 0.03, Wiro_AI_Pricing::matching_row( $rows, array() )['price'] );
	}

	/**
	 * Models without price rows are billed by run time.
	 */
	public function test_run_time_estimate() {
		$quote = Wiro_AI_Pricing::quote( array( 'approximatelycost' => 0.0218 ), array() );
		$this->assertTrue( $quote['approximate'] );
		$this->assertSame( 'about $0.022', $quote['label'] );
		$quote = Wiro_AI_Pricing::quote( array(), array() );
		$this->assertSame( 'billed by run time', $quote['label'] );
	}

	/**
	 * Prices set when the task ends show the row's note.
	 */
	public function test_readoutput_note() {
		$model = array(
			'dynamicprice' => array(
				array(
					'inputs'      => array(),
					'price'       => 0,
					'priceMethod' => 'cp-readoutput',
					'note'        => 'Input: $1.00 per 1M tokens.',
				),
			),
		);
		$quote = Wiro_AI_Pricing::quote( $model, array() );
		$this->assertSame( 'set when the task ends', $quote['label'] );
		$this->assertSame( 'Input: $1.00 per 1M tokens.', $quote['note'] );
	}

	/**
	 * A discount scales the price and says so.
	 */
	public function test_discount() {
		$model = array(
			'dynamicprice' => array(
				array(
					'inputs'      => array(),
					'price'       => 0.1,
					'priceMethod' => 'cpr',
				),
			),
			'discount'     => 50,
		);
		$quote = Wiro_AI_Pricing::quote( $model, array() );
		$this->assertEqualsWithDelta( 0.05, $quote['amount'], 0.000001 );
		$this->assertStringStartsWith( '50% off.', $quote['note'] );
	}

	/**
	 * LLM quotes estimate from token prices.
	 */
	public function test_llm_quote() {
		$quote = Wiro_AI_Pricing::llm_quote(
			array(
				'prompt'     => '0.0000003',
				'completion' => '0.0000025',
			),
			1000,
			200
		);
		$this->assertEqualsWithDelta( 0.0008, $quote['amount'], 0.0000001 );
		$this->assertSame( 'about $0.0008', $quote['label'] );
		$this->assertTrue( $quote['approximate'] );
		$this->assertSame( 'billed by tokens', Wiro_AI_Pricing::llm_quote( array(), 10, 10 )['label'] );
	}

	/**
	 * Dollar amounts read the way wiro.ai writes them.
	 */
	public function test_format_usd() {
		$this->assertSame( '$0', Wiro_AI_Pricing::format_usd( 0 ) );
		$this->assertSame( '$1.50', Wiro_AI_Pricing::format_usd( 1.5 ) );
		$this->assertSame( '$0.20', Wiro_AI_Pricing::format_usd( 0.2 ) );
		$this->assertSame( '$0.034', Wiro_AI_Pricing::format_usd( 0.0336 ) );
		$this->assertSame( '$0.005', Wiro_AI_Pricing::format_usd( 0.005 ) );
		$this->assertSame( '$0.000408', Wiro_AI_Pricing::format_usd( 0.0004082 ) );
		$this->assertSame( '$0.0000997', Wiro_AI_Pricing::format_usd( 9.97e-5 ) );
	}

	/**
	 * Short list labels.
	 */
	public function test_short_label() {
		$this->assertSame( '$0.034', Wiro_AI_Pricing::short_label( array( 'dynamicprice' => '[{"inputs":{},"price":0.0336,"priceMethod":"cpr"}]' ) ) );
		$this->assertSame( 'from $0.003', Wiro_AI_Pricing::short_label( array( 'dynamicprice' => '[{"inputs":{"q":"low"},"price":0.003,"priceMethod":"cpr"},{"inputs":{"q":"high"},"price":0.2,"priceMethod":"cpr"}]' ) ) );
		$this->assertSame( '$0.04 / 1K characters', Wiro_AI_Pricing::short_label( array( 'dynamicprice' => '[{"inputs":{},"price":0.00004,"priceMethod":"cp-promptlength"}]' ) ) );
		$this->assertSame( 'about $0.00121', Wiro_AI_Pricing::short_label( array( 'approximatelycost' => 0.0012083 ) ) );
		$this->assertSame( '$0.00001 / token', Wiro_AI_Pricing::short_label( array( 'dynamicprice' => '[{"inputs":{},"price":0.00001,"priceMethod":"cpt"}]' ) ) );
		$this->assertSame( '', Wiro_AI_Pricing::short_label( array() ) );
	}
}
