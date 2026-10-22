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
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'add_to_cart_validation' ], PHP_INT_MAX, 4 );
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

	/**
	 * Get the product object from a product or an ID.
	 *
	 * @param WC_Product|int|null $product Product object or ID.
	 *
	 * @return WC_Product|false
	 */
	public static function resolve_product( $product = null ) {
		if ( ! $product ) {
			global $product;
		}

		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}

		return $product;
	}

	/**
	 * Get the effective configuration of a product or a variation.
	 *
	 * @param WC_Product|int|null $product Product object or ID.
	 *
	 * @return array
	 */
	public static function get_config( $product = null ) {
		$config = [
			'status'  => 'disable',
			'enabled' => false,
			'type'    => '',
			'min'     => '',
			'max'     => '',
			'step'    => '',
			'values'  => '',
		];

		$product = self::resolve_product( $product );

		if ( ! $product ) {
			return $config;
		}

		// variations always follow the settings of the variable product
		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$status     = get_post_meta( $product_id, '_woonp_status', true ) ?: 'default';

		$config['status'] = $status;

		if ( $status === 'overwrite' ) {
			$config['enabled'] = true;
			$config['type']    = get_post_meta( $product_id, '_woonp_type', true );
			$config['min']     = str_replace( ',', '.', (string) get_post_meta( $product_id, '_woonp_min', true ) );
			$config['max']     = str_replace( ',', '.', (string) get_post_meta( $product_id, '_woonp_max', true ) );
			$config['step']    = str_replace( ',', '.', (string) get_post_meta( $product_id, '_woonp_step', true ) );
			$config['values']  = get_post_meta( $product_id, '_woonp_values', true );
		} elseif ( $status === 'default' ) {
			$config['enabled'] = WoonpHelper::get_setting( 'global_status', 'enable' ) !== 'disable';
			$config['type']    = WoonpHelper::get_setting( 'type', 'default' );
			$config['min']     = str_replace( ',', '.', (string) WoonpHelper::get_setting( 'min' ) );
			$config['max']     = str_replace( ',', '.', (string) WoonpHelper::get_setting( 'max' ) );
			$config['step']    = str_replace( ',', '.', (string) WoonpHelper::get_setting( 'step' ) );
			$config['values']  = WoonpHelper::get_setting( 'values' );
		}

		return $config;
	}

	public static function is_valid_product( $product = null ) {
		$config = self::get_config( $product );

		return $config['enabled'];
	}

	public static function add_input_field() {
		global $product;

		$current = self::resolve_product( $product );

		if ( ! $current ) {
			return;
		}

		// All dynamic values inside the markup are escaped at point of insertion.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::get_input_html( $current );
	}

	/**
	 * Build the Name Your Price input for a product or a variation.
	 *
	 * @param WC_Product|int|null $product Product object or ID.
	 *
	 * @return string
	 */
	public static function get_input_html( $product = null ) {
		$product = self::resolve_product( $product );

		if ( ! $product ) {
			return '';
		}

		$config = self::get_config( $product );

		if ( ! $config['enabled'] ) {
			return '';
		}

		$product_id = $product->get_id();
		$status     = $config['status'];
		$type       = $config['type'];
		$min        = $config['min'];
		$max        = $config['max'];
		$step       = $config['step'];
		$values     = $config['values'];

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
		if ( function_exists( 'is_product' ) && is_product() && isset( $_GET['woonp'] ) && '' !== $_GET['woonp'] ) {
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

			$price .= apply_filters( 'woonp_input_select', $select, $product );
		} else {
			// default
			$input = '<input type="number" id="' . esc_attr( $input_id ) . '" class="woonp-input" step="' . esc_attr( $step ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( 0 < (float) $max ? $max : '' ) . '" name="woonp" value="' . esc_attr( $value ) . '" size="4"/>';

			$price .= apply_filters( 'woonp_input_number', $input, $product );
		}

		$price .= '</div>';

		return apply_filters( 'woonp_input', $price, $product );
	}

	public static function add_to_cart_validation( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST['woonp'] ) && '' !== $_POST['woonp'] ) {
			$price  = (float) self::sanitize_price( wp_unslash( $_POST['woonp'] ) );
			$target = absint( $variation_id ) ?: $product_id;
			$config = self::get_config( $target );

			if ( ! $config['enabled'] ) {
				wc_add_notice( esc_html__( 'This product does not allow you to name your price.', 'wpc-name-your-price' ), 'error' );

				return false;
			}

			if ( $price < 0 ) {
				wc_add_notice( esc_html__( 'You can\'t fill the negative price.', 'wpc-name-your-price' ), 'error' );

				return false;
			}

			$type   = $config['type'];
			$min    = (float) $config['min'];
			$max    = (float) $config['max'];
			$step   = (float) ( $config['step'] ?: 1 );
			$values = $config['values'];

			if ( $step <= 0 ) {
				$step = 1;
			}

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
				$ratio      = ( $price - $min ) / $step;
				$step_valid = abs( $ratio - round( $ratio ) ) < 0.0001;

				if ( ( $min && ( $price < $min ) ) || ( $max && ( $price > $max ) ) || ! $step_valid ) {
					wc_add_notice( esc_html__( 'Invalid price. Please try again!', 'wpc-name-your-price' ), 'error' );

					return false;
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
