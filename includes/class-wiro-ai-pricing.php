<?php
/**
 * Price shown before a run.
 *
 * Plain PHP with no WordPress calls, so the unit tests can load it on its own.
 * It follows the way Wiro AI bills a run: the first price row whose values all
 * match the run's settings (or, when rows count files, the valid row with the
 * highest count), then the row's method: cpr per run, cpo per output,
 * cp-promptlength per character, cp-pixel per 1024 x 1024 block, and so on.
 * A model without price rows is billed for its run time.
 *
 * @package WiroAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a model's price rows and a run's settings into a short price line.
 */
final class Wiro_AI_Pricing {

	/**
	 * Pixels in one billing block of cp-pixel models.
	 */
	const PIXEL_BLOCK = 1048576;

	/**
	 * Price rows from a Tool/List or Tool/Detail record.
	 *
	 * @param mixed $dynamicprice The "dynamicprice" field: a JSON string or a list.
	 * @return array[]
	 */
	public static function rows( $dynamicprice ) {
		if ( is_string( $dynamicprice ) ) {
			$dynamicprice = json_decode( $dynamicprice, true );
		}
		if ( ! is_array( $dynamicprice ) ) {
			return array();
		}
		$rows = array();
		foreach ( $dynamicprice as $row ) {
			if ( is_array( $row ) && isset( $row['priceMethod'] ) ) {
				$row['inputs'] = isset( $row['inputs'] ) && is_array( $row['inputs'] ) ? $row['inputs'] : array();
				$rows[]        = $row;
			}
		}
		return $rows;
	}

	/**
	 * The row Wiro AI bills these settings with.
	 *
	 * @param array[] $rows   Price rows.
	 * @param array   $params Run parameters.
	 * @return array|null
	 */
	public static function matching_row( array $rows, array $params ) {
		$counts = false;
		foreach ( $rows as $row ) {
			foreach ( $row['inputs'] as $expected ) {
				if ( null !== self::quantity( $expected ) ) {
					$counts = true;
				}
			}
		}

		if ( ! $counts ) {
			foreach ( $rows as $row ) {
				$all = true;
				foreach ( $row['inputs'] as $key => $expected ) {
					if ( ! self::same_value( $expected, isset( $params[ $key ] ) ? $params[ $key ] : null ) ) {
						$all = false;
						break;
					}
				}
				if ( $all ) {
					return $row;
				}
			}
			return null;
		}

		$best       = null;
		$best_score = -1;
		foreach ( $rows as $row ) {
			$score = 0;
			$valid = true;
			foreach ( $row['inputs'] as $key => $expected ) {
				$quantity = self::quantity( $expected );
				$actual   = isset( $params[ $key ] ) ? $params[ $key ] : null;
				if ( null === $quantity ? ! self::same_value( $expected, $actual ) : $quantity > self::count_of( $actual ) ) {
					$valid = false;
					break;
				}
				$score += (int) $quantity;
			}
			if ( $valid && $score > $best_score ) {
				$best       = $row;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * Price quote for one run.
	 *
	 * @param array $model   Model record with "dynamicprice", and optionally
	 *                       "approximatelycost" and "discount" (percent).
	 * @param array $params  Run parameters.
	 * @param array $context Optional "chars" (prompt characters) and "pixels"
	 *                       (output width times height).
	 * @return array{amount: float|null, approximate: bool, label: string, note: string}
	 */
	public static function quote( array $model, array $params, array $context = array() ) {
		$rows     = self::rows( isset( $model['dynamicprice'] ) ? $model['dynamicprice'] : null );
		$discount = isset( $model['discount'] ) && is_numeric( $model['discount'] ) ? (float) $model['discount'] : 0.0;
		$scale    = $discount > 0 && $discount < 100 ? 1 - $discount / 100 : 1.0;

		if ( ! $rows ) {
			$quote = self::run_time_quote( $model, $scale );
		} else {
			$row   = self::matching_row( $rows, $params );
			$quote = null === $row ? self::run_time_quote( $model, $scale ) : self::row_quote( $row, $params, $context, $scale );
		}

		if ( $scale < 1 && null !== $quote['amount'] && $quote['amount'] > 0 ) {
			$quote['note'] = trim( sprintf( self::t( 'discount' ), self::number( $discount ) ) . ' ' . $quote['note'] );
		}
		return $quote;
	}

	/**
	 * Price quote for a request to the LLM gateway.
	 *
	 * @param array $pricing       The model's "pricing" (USD per token for "prompt" and "completion").
	 * @param int   $input_tokens  Estimated input tokens.
	 * @param int   $output_tokens Estimated output tokens.
	 * @return array{amount: float|null, approximate: bool, label: string, note: string}
	 */
	public static function llm_quote( array $pricing, $input_tokens, $output_tokens ) {
		$in  = isset( $pricing['prompt'] ) && is_numeric( $pricing['prompt'] ) ? (float) $pricing['prompt'] : 0.0;
		$out = isset( $pricing['completion'] ) && is_numeric( $pricing['completion'] ) ? (float) $pricing['completion'] : 0.0;
		if ( $in <= 0 && $out <= 0 ) {
			return array(
				'amount'      => null,
				'approximate' => true,
				'label'       => self::t( 'by_tokens' ),
				'note'        => self::t( 'answer_note' ),
			);
		}
		$amount = $in * max( 0, (int) $input_tokens ) + $out * max( 0, (int) $output_tokens );
		return array(
			'amount'      => $amount,
			'approximate' => true,
			'label'       => sprintf( self::t( 'about' ), self::format_usd( $amount ) ),
			'note'        => sprintf( self::t( 'token_rates' ), self::format_usd( $in * 1000000 ), self::format_usd( $out * 1000000 ) ),
		);
	}

	/**
	 * Short price for a model list, such as "$0.034" or "about $0.022".
	 *
	 * @param array $model Tool/List record.
	 * @return string Empty when nothing useful is known.
	 */
	public static function short_label( array $model ) {
		$rows = self::rows( isset( $model['dynamicprice'] ) ? $model['dynamicprice'] : null );
		if ( ! $rows ) {
			$estimate = isset( $model['approximatelycost'] ) && is_numeric( $model['approximatelycost'] ) ? (float) $model['approximatelycost'] : 0.0;
			return $estimate > 0 ? sprintf( self::t( 'about' ), self::format_usd( $estimate ) ) : '';
		}
		$prices = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['price'] ) && is_numeric( $row['price'] ) ) {
				$prices[] = (float) $row['price'];
			}
		}
		$method = (string) $rows[0]['priceMethod'];
		$low    = $prices ? min( $prices ) : 0.0;
		$high   = $prices ? max( $prices ) : 0.0;
		switch ( $method ) {
			case 'cpr':
			case 'cpo':
			case 'cp-pixel':
				if ( $low <= 0 && $high <= 0 ) {
					return self::t( 'free' );
				}
				return $low < $high ? sprintf( self::t( 'from' ), self::format_usd( $low ) ) : self::format_usd( $low );
			case 'cp-promptlength':
				return sprintf( self::t( 'per_1k_chars' ), self::format_usd( $low * 1000 ) );
			case 'cps':
				return sprintf( self::t( 'per_second_short' ), self::format_usd( $low ) );
			case 'cpt':
				return sprintf( self::t( 'per_token_short' ), self::format_usd( $low ) );
			default:
				return $low > 0 ? sprintf( self::t( 'about' ), self::format_usd( $low ) ) : self::t( 'set_when_ends' );
		}
	}

	/**
	 * Dollar amount as wiro.ai writes it: $0.20, $0.034, $0.00012.
	 *
	 * @param float $amount Amount in US dollars.
	 * @return string
	 */
	public static function format_usd( $amount ) {
		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return '$0';
		}
		if ( $amount >= 1 ) {
			return '$' . number_format( $amount, 2, '.', ',' );
		}
		if ( $amount >= 0.01 ) {
			return '$' . self::trim_zeros( number_format( $amount, 3, '.', '' ) );
		}
		return '$' . self::trim_zeros( rtrim( rtrim( sprintf( '%.8F', (float) sprintf( '%.3G', $amount ) ), '0' ), '.' ) );
	}

	/**
	 * Text for a price phrase: translated inside WordPress, English in the unit tests.
	 *
	 * @param string $key Phrase key.
	 * @return string
	 */
	private static function t( $key ) {
		$english = array(
			'free'             => 'free',
			'about'            => 'about %s',
			'from'             => 'from %s',
			'per_character'    => '%s per character',
			'chars_at'         => '%1$s characters at %2$s each.',
			'per_output'       => '%s per output.',
			'per_audio_second' => '%s per second of audio',
			'per_video_second' => '%s per second of video',
			'per_run_second'   => '%s per second of run time',
			'by_tokens'        => 'billed by tokens',
			'by_run_time'      => 'billed by run time',
			'set_when_ends'    => 'set when the task ends',
			'task_note'        => 'The exact charge shows when the task ends.',
			'answer_note'      => 'The exact charge shows when the answer arrives.',
			'run_time_note'    => 'Billed by run time; past runs averaged this.',
			'token_rates'      => '%1$s per 1M input tokens, %2$s per 1M output tokens.',
			'discount'         => '%s%% off.',
			'per_1k_chars'     => '%s / 1K characters',
			'per_second_short' => '%s / second',
			'per_token_short'  => '%s / token',
		);
		if ( ! function_exists( '__' ) ) {
			return isset( $english[ $key ] ) ? $english[ $key ] : $key;
		}
		switch ( $key ) {
			case 'free':
				return __( 'free', 'wiro-ai' );
			case 'about':
				/* translators: %s: price such as $0.03. */
				return __( 'about %s', 'wiro-ai' );
			case 'from':
				/* translators: %s: price such as $0.03. */
				return __( 'from %s', 'wiro-ai' );
			case 'per_character':
				/* translators: %s: price such as $0.00004. */
				return __( '%s per character', 'wiro-ai' );
			case 'chars_at':
				/* translators: 1: number of characters, 2: price per character. */
				return __( '%1$s characters at %2$s each.', 'wiro-ai' );
			case 'per_output':
				/* translators: %s: price such as $0.03. */
				return __( '%s per output.', 'wiro-ai' );
			case 'per_audio_second':
				/* translators: %s: price such as $0.01. */
				return __( '%s per second of audio', 'wiro-ai' );
			case 'per_video_second':
				/* translators: %s: price such as $0.01. */
				return __( '%s per second of video', 'wiro-ai' );
			case 'per_run_second':
				/* translators: %s: price such as $0.001. */
				return __( '%s per second of run time', 'wiro-ai' );
			case 'by_tokens':
				return __( 'billed by tokens', 'wiro-ai' );
			case 'by_run_time':
				return __( 'billed by run time', 'wiro-ai' );
			case 'set_when_ends':
				return __( 'set when the task ends', 'wiro-ai' );
			case 'task_note':
				return __( 'The exact charge shows when the task ends.', 'wiro-ai' );
			case 'answer_note':
				return __( 'The exact charge shows when the answer arrives.', 'wiro-ai' );
			case 'run_time_note':
				return __( 'Billed by run time; past runs averaged this.', 'wiro-ai' );
			case 'token_rates':
				/* translators: 1: price per million input tokens, 2: price per million output tokens. */
				return __( '%1$s per 1M input tokens, %2$s per 1M output tokens.', 'wiro-ai' );
			case 'discount':
				/* translators: %s: discount percent, such as 50. */
				return __( '%s%% off.', 'wiro-ai' );
			case 'per_1k_chars':
				/* translators: %s: price such as $0.04. */
				return __( '%s / 1K characters', 'wiro-ai' );
			case 'per_second_short':
				/* translators: %s: price such as $0.001. */
				return __( '%s / second', 'wiro-ai' );
			case 'per_token_short':
				/* translators: %s: price such as $0.00001. */
				return __( '%s / token', 'wiro-ai' );
		}
		return isset( $english[ $key ] ) ? $english[ $key ] : $key;
	}

	/**
	 * Quote for one matched row.
	 *
	 * @param array $row     Price row.
	 * @param array $params  Run parameters.
	 * @param array $context Characters and pixels.
	 * @param float $scale   Discount factor.
	 * @return array
	 */
	private static function row_quote( array $row, array $params, array $context, $scale ) {
		$price = ( isset( $row['price'] ) && is_numeric( $row['price'] ) ? (float) $row['price'] : 0.0 ) * $scale;
		$note  = isset( $row['note'] ) && is_string( $row['note'] ) ? trim( preg_replace( '/\s+/', ' ', $row['note'] ) ) : '';

		switch ( (string) $row['priceMethod'] ) {
			case 'cpr':
				return self::result( $price, false, $price > 0 ? self::format_usd( $price ) : self::t( 'free' ), $note );
			case 'cpo':
				$count  = self::output_count( $params );
				$amount = $price * $count;
				$sub    = $count > 1 ? sprintf( self::t( 'per_output' ), self::format_usd( $price ) ) : '';
				return self::result( $amount, false, $amount > 0 ? self::format_usd( $amount ) : self::t( 'free' ), trim( $note . ' ' . $sub ) );
			case 'cp-promptlength':
				$chars = isset( $context['chars'] ) ? max( 0, (int) $context['chars'] ) : 0;
				if ( 0 === $chars ) {
					return self::result( null, true, sprintf( self::t( 'per_character' ), self::format_usd( $price ) ), $note );
				}
				return self::result( $price * $chars, false, self::format_usd( $price * $chars ), trim( sprintf( self::t( 'chars_at' ), number_format( $chars ), self::format_usd( $price ) ) . ' ' . $note ) );
			case 'cp-pixel':
				$pixels = isset( $context['pixels'] ) ? max( 0, (int) $context['pixels'] ) : 0;
				$extra  = ( isset( $row['priceExtra'] ) && is_numeric( $row['priceExtra'] ) ? (float) $row['priceExtra'] : 0.0 ) * $scale;
				if ( 0 === $pixels ) {
					return self::result( $price, true, sprintf( self::t( 'from' ), self::format_usd( $price ) ), $note );
				}
				$blocks = max( 1, (int) ceil( $pixels / self::PIXEL_BLOCK ) );
				$amount = $price + ( $blocks - 1 ) * $extra;
				return self::result( $amount, false, self::format_usd( $amount ), $note );
			case 'cp-audiosecondslength':
				return self::result( null, true, sprintf( self::t( 'per_audio_second' ), self::format_usd( $price ) ), $note );
			case 'cp-outputVideoLength':
				return self::result( null, true, sprintf( self::t( 'per_video_second' ), self::format_usd( $price ) ), $note );
			case 'cps':
				return self::result( null, true, sprintf( self::t( 'per_run_second' ), self::format_usd( $price ) ), $note );
			case 'cpt':
				return self::result( null, true, self::t( 'by_tokens' ), '' !== $note ? $note : self::t( 'task_note' ) );
			case 'cp-readoutput':
				return $price > 0
					? self::result( $price, true, sprintf( self::t( 'about' ), self::format_usd( $price ) ), '' !== $note ? $note : self::t( 'task_note' ) )
					: self::result( null, true, self::t( 'set_when_ends' ), '' !== $note ? $note : self::t( 'task_note' ) );
			default:
				return self::result( null, true, self::t( 'by_run_time' ), $note );
		}
	}

	/**
	 * Quote for a model billed by its run time.
	 *
	 * @param array $model Model record.
	 * @param float $scale Discount factor.
	 * @return array
	 */
	private static function run_time_quote( array $model, $scale ) {
		$estimate = isset( $model['approximatelycost'] ) && is_numeric( $model['approximatelycost'] ) ? (float) $model['approximatelycost'] * $scale : 0.0;
		if ( $estimate > 0 ) {
			return self::result( $estimate, true, sprintf( self::t( 'about' ), self::format_usd( $estimate ) ), self::t( 'run_time_note' ) );
		}
		return self::result( null, true, self::t( 'by_run_time' ), self::t( 'task_note' ) );
	}

	/**
	 * Quote array.
	 *
	 * @param float|null $amount      Amount, when known.
	 * @param bool       $approximate Whether the amount is an estimate.
	 * @param string     $label       Short label.
	 * @param string     $note        Longer note.
	 * @return array
	 */
	private static function result( $amount, $approximate, $label, $note ) {
		return array(
			'amount'      => null === $amount ? null : (float) $amount,
			'approximate' => (bool) $approximate,
			'label'       => (string) $label,
			'note'        => trim( (string) $note ),
		);
	}

	/**
	 * Number of outputs a run asks for.
	 *
	 * @param array $params Run parameters.
	 * @return int
	 */
	private static function output_count( array $params ) {
		foreach ( array( 'n', 'samples', 'numberOfImages', 'numImages', 'numOutputs', 'numberOfOutputs', 'count', 'batchSize' ) as $key ) {
			if ( isset( $params[ $key ] ) && is_numeric( $params[ $key ] ) && (int) $params[ $key ] > 0 ) {
				return (int) $params[ $key ];
			}
		}
		return 1;
	}

	/**
	 * The n of a "QUANTITY:n" price input, or null.
	 *
	 * @param mixed $value Price input value.
	 * @return int|null
	 */
	private static function quantity( $value ) {
		if ( is_string( $value ) && preg_match( '/^QUANTITY:(\d+)$/', $value, $match ) ) {
			return (int) $match[1];
		}
		return null;
	}

	/**
	 * How many items a parameter value sends.
	 *
	 * @param mixed $value Parameter value.
	 * @return int
	 */
	private static function count_of( $value ) {
		if ( null === $value || '' === $value ) {
			return 0;
		}
		if ( is_array( $value ) ) {
			return count(
				array_filter(
					$value,
					static function ( $item ) {
						return null !== $item && '' !== $item;
					}
				)
			);
		}
		return 1;
	}

	/**
	 * Loose comparison, as Wiro AI matches rows: 5 matches "5".
	 *
	 * @param mixed $expected Row value.
	 * @param mixed $actual   Run value.
	 * @return bool
	 */
	private static function same_value( $expected, $actual ) {
		if ( null === $actual ) {
			return false;
		}
		if ( is_int( $expected ) || is_float( $expected ) ) {
			return is_numeric( $actual ) && (float) $actual === (float) $expected;
		}
		return (string) $actual === (string) $expected;
	}

	/**
	 * "2.50" becomes "2.50", "0.200" becomes "0.20", "0.0340" becomes "0.034".
	 *
	 * @param string $value Number as text.
	 * @return string
	 */
	private static function trim_zeros( $value ) {
		if ( false === strpos( $value, '.' ) ) {
			return $value;
		}
		$trimmed = rtrim( rtrim( $value, '0' ), '.' );
		return preg_match( '/\.\d$/', $trimmed ) ? $trimmed . '0' : $trimmed;
	}

	/**
	 * A number without trailing zeros.
	 *
	 * @param float $value Number.
	 * @return string
	 */
	private static function number( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}
}
