<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WPCleverWoonp' ) ) {
	return;
}

class WoonpCore {
	protected static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_to_cart_item_data' ], PHP_INT_MAX );
		add_filter( 'woocommerce_get_cart_contents', [ $this, 'get_cart_contents' ], PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_loop_add_to_cart_link', [ $this, 'loop_add_to_cart_link' ], PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_get_price_html', [ $this, 'hide_original_price' ], PHP_INT_MAX, 2 );
		add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'add_input_field' ], PHP_INT_MAX );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'add_to_cart_validation' ], PHP_INT_MAX, 2 );
	}

	public function add_to_cart_item_data( $cart_item_data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST['woonp'] ) && '' !== $_POST['woonp'] ) {
			$cart_item_data['woonp'] = self::sanitize_price( wp_unslash( $_POST['woonp'] ) );
			unset( $_REQUEST['woonp'] );
		}

		return $cart_item_data;
	}

	public function get_cart_contents( $cart_contents ) {
		foreach ( $cart_contents as $cart_item ) {
			if ( ! isset( $cart_item['woonp'] ) || ! self::is_valid_product( $cart_item['data'] ) ) {
				continue;
			}

			$cart_item['data']->set_price( apply_filters( 'woonp_cart_item_price', $cart_item['woonp'], $cart_item ) );
		}

		return $cart_contents;
	}

	public function hide_original_price( $price, $product ) {
		if ( is_admin() || ! self::is_valid_product( $product ) ) {
			return $price;
		}

		$suggested_price = apply_filters( 'woonp_suggested_price', WoonpHelper::get_setting( 'suggested_price', /* translators: price */ esc_html__( 'Suggested Price: %s', 'wpc-name-your-price' ) ), $product );

		return sprintf( $suggested_price, $price );
	}

	function loop_add_to_cart_link( $link, $product ) {
		if ( WoonpHelper::get_setting( 'atc_button', 'show' ) === 'hide' && self::is_valid_product( $product ) ) {
			return '';
		}

		return $link;
	}

	public static function is_valid_product( $product = null ) {
		if ( ! $product ) {
			global $product;
		}

		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}

		$product_id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$global_status = WoonpHelper::get_setting( 'global_status', 'enable' );
		$status        = get_post_meta( $product_id, '_woonp_status', true ) ?: 'default';

		if ( ( $status === 'overwrite' ) || ( $status === 'default' && $global_status !== 'disable' ) ) {
			return true;
		}

		return false;
	}

	public static function add_input_field() {
		global $product;

		if ( ! self::is_valid_product( $product ) ) {
			return;
		}

		$product_id = $product->get_id();
		$status     = get_post_meta( $product_id, '_woonp_status', true ) ?: 'default';
		$type       = $min = $max = $step = $values = '';

		if ( $status === 'overwrite' ) {
			$type   = get_post_meta( $product_id, '_woonp_type', true );
			$min    = str_replace( ',', '.', get_post_meta( $product_id, '_woonp_min', true ) );
			$max    = str_replace( ',', '.', get_post_meta( $product_id, '_woonp_max', true ) );
			$step   = str_replace( ',', '.', get_post_meta( $product_id, '_woonp_step', true ) );
			$values = get_post_meta( $product_id, '_woonp_values', true );
		}

		if ( $status === 'default' ) {
			$type   = WoonpHelper::get_setting( 'type', 'default' );
			$min    = str_replace( ',', '.', WoonpHelper::get_setting( 'min' ) );
			$max    = str_replace( ',', '.', WoonpHelper::get_setting( 'max' ) );
			$step   = str_replace( ',', '.', WoonpHelper::get_setting( 'step' ) );
			$values = WoonpHelper::get_setting( 'values' );
		}

		switch ( WoonpHelper::get_setting( 'value', 'price' ) ) {
			case 'price':
				$value = self::sanitize_price( $product->get_price() );
				break;
			case 'min':
				$value = self::sanitize_price( $min );
				break;
			case 'max':
				$value = self::sanitize_price( $max );
				break;
			default:
				$value = '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_product() && isset( $_GET['woonp'] ) && '' !== $_GET['woonp'] ) {
			$value = self::sanitize_price( wp_unslash( $_GET['woonp'] ) );
		}

		$input_id    = 'woonp_' . $product_id;
		$input_label = apply_filters( 'woonp_input_label', WoonpHelper::get_setting( 'label', /* translators: currency */ esc_html__( 'Name Your Price (%s) ', 'wpc-name-your-price' ) ), $product_id );
		$label       = sprintf( $input_label, get_woocommerce_currency_symbol() );
		$price       = '<div class="' . esc_attr( apply_filters( 'woonp_input_class', 'woonp woonp-' . $status . ' woonp-type-' . $type, $product ) ) . '" data-min="' . esc_attr( $min ) . '" data-max="' . esc_attr( $max ) . '" data-step="' . esc_attr( $step ) . '">';
		$price       .= '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $label ) . '</label>';

		if ( ( $type === 'select' ) && ( $values = WPCleverWoonp::get_values( $values ) ) && ! empty( $values ) ) {
			// select
			$select = '<select id="' . esc_attr( $input_id ) . '" class="woonp-select" name="woonp">';

			foreach ( $values as $v ) {
				$select .= '<option value="' . esc_attr( $v['value'] ) . '" ' . ( $value == $v['value'] ? 'selected' : '' ) . '>' . esc_html( $v['name'] ) . '</option>';
			}

			$select .= '</select>';

			// All dynamic values inside $select are escaped with esc_attr()/esc_html() at point of insertion.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$price .= apply_filters( 'woonp_input_select', $select, $product );
		} else {
			// default
			$input = '<input type="number" id="' . esc_attr( $input_id ) . '" class="woonp-input" step="' . esc_attr( $step ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( 0 < $max ? $max : '' ) . '" name="woonp" value="' . esc_attr( $value ) . '" size="4"/>';

			// All dynamic values inside $input are escaped with esc_attr() at point of insertion.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$price .= apply_filters( 'woonp_input_number', $input, $product );
		}

		$price .= '</div>';

		// All dynamic values inside $price are escaped with esc_attr()/esc_html() at point of insertion.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo apply_filters( 'woonp_input', $price, $product );
	}

	public static function add_to_cart_validation( $passed, $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST['woonp'] ) && '' !== $_POST['woonp'] ) {
			$price = (float) self::sanitize_price( wp_unslash( $_POST['woonp'] ) );

			if ( ! self::is_valid_product( $product_id ) ) {
				wc_add_notice( esc_html__( 'This product does not allow you to name your price.', 'wpc-name-your-price' ), 'error' );

				return false;
			}

			if ( $price < 0 ) {
				wc_add_notice( esc_html__( 'You can\'t fill the negative price.', 'wpc-name-your-price' ), 'error' );

				return false;
			} else {
				$status = get_post_meta( $product_id, '_woonp_status', true ) ?: 'default';
				$type   = $values = '';
				$min    = $max = 0;
				$step   = 1;

				if ( $status === 'overwrite' ) {
					$type   = get_post_meta( $product_id, '_woonp_type', true );
					$min    = (float) str_replace( ',', '.', get_post_meta( $product_id, '_woonp_min', true ) );
					$max    = (float) str_replace( ',', '.', get_post_meta( $product_id, '_woonp_max', true ) );
					$step   = (float) str_replace( ',', '.', get_post_meta( $product_id, '_woonp_step', true ) ?: 1 );
					$values = get_post_meta( $product_id, '_woonp_values', true );
				} elseif ( $status === 'default' ) {
					$status = WoonpHelper::get_setting( 'global_status', 'enable' );
					$type   = WoonpHelper::get_setting( 'type', 'default' );
					$min    = (float) str_replace( ',', '.', WoonpHelper::get_setting( 'min' ) );
					$max    = (float) str_replace( ',', '.', WoonpHelper::get_setting( 'max' ) );
					$step   = (float) str_replace( ',', '.', WoonpHelper::get_setting( 'step' ) ?: 1 );
					$values = WoonpHelper::get_setting( 'values' );
				}

				if ( $step <= 0 ) {
					$step = 1;
				}

				if ( $status !== 'disable' ) {
					if ( $type === 'select' ) {
						// Validate for select type
						if ( class_exists( 'WPCleverWoonp' ) ) {
							$values_list = WPCleverWoonp::get_values( $values );

							if ( ! empty( $values_list ) ) {
								$valid_prices = [];

								foreach ( $values_list as $v ) {
									$valid_prices[] = (float) $v['value'];
								}

								if ( ! in_array( (float) $price, $valid_prices, true ) ) {
									wc_add_notice( esc_html__( 'Invalid price. Please try again!', 'wpc-name-your-price' ), 'error' );

									return false;
								}
							}
						}
					} else {
						// Validate for default/number input type
						$step_valid = true;

						if ( $step > 0 ) {
							$ratio      = ( $price - $min ) / $step;
							$step_valid = abs( $ratio - round( $ratio ) ) < 0.0001;
						}

						if ( ( $min && ( $price < $min ) ) || ( $max && ( $price > $max ) ) || ! $step_valid ) {
							wc_add_notice( esc_html__( 'Invalid price. Please try again!', 'wpc-name-your-price' ), 'error' );

							return false;
						}
					}
				}
			}
		}

		return $passed;
	}

	public static function sanitize_price( $price ) {
		$price = str_replace( ',', '.', sanitize_text_field( (string) $price ) );

		return filter_var( $price, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
	}
}

return WoonpCore::instance();
