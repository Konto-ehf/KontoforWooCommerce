<?php
/*
  Plugin Name: Konto Checkout for WooCommerce
  Plugin URI: http://wcplugin.konto.is/
  Description: Konto e-invoices and bank claims for WooCommerce orders, with stock sync from Konto inventory (lots).
  Text Domain: woo-konto-checkout
  Version: 2.1.0
  Author: Konto
  Author URI: https://konto.is/
  License: GPLv3
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
  Repo: https://github.com/KontoIS/KontoforWooCommerce
  Requires at least: 6.0
  Requires PHP: 7.4
  Requires Plugins: woocommerce
  WC requires at least: 7.0
  WC tested up to: 11.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'KONTO_VERSION' ) ) {
	define( 'KONTO_VERSION', '2.1.0' );
	define( 'KONTO_FILE', __FILE__ );
	define( 'KONTO_DIR', plugin_dir_path( __FILE__ ) );
	define( 'KONTO_URL', plugin_dir_url( __FILE__ ) );
}

/* -------------------------------------------------------------------------
 * WooCommerce feature compatibility: HPOS order storage + block checkout.
 * ---------------------------------------------------------------------- */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KONTO_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', KONTO_FILE, true );
	}
} );

register_deactivation_hook( __FILE__, 'konto_on_deactivate' );
function konto_on_deactivate() {
	wp_clear_scheduled_hook( 'konto_inventory_sync' );
	wp_clear_scheduled_hook( 'konto_inventory_sync_soon' );
}

add_filter( 'cron_schedules', 'konto_cron_schedules' );
function konto_cron_schedules( $schedules ) {
	$schedules['konto_15min'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => 'Every 15 minutes (Konto)',
	);
	return $schedules;
}

/* -------------------------------------------------------------------------
 * Small helpers (no WooCommerce classes needed).
 * ---------------------------------------------------------------------- */

/** Digits only: "010130-2989" -> "0101302989". */
function konto_normalize_kennitala( $value ) {
	return preg_replace( '/\D+/', '', (string) $value );
}

/** Icelandic kennitala: 10 digits, mod-11 check digit, century digit 8/9/0. */
function konto_is_valid_kennitala( $value ) {
	$kt = konto_normalize_kennitala( $value );
	if ( strlen( $kt ) !== 10 ) {
		return false;
	}
	$weights = array( 3, 2, 7, 6, 5, 4, 3, 2 );
	$sum     = 0;
	for ( $i = 0; $i < 8; $i++ ) {
		$sum += (int) $kt[ $i ] * $weights[ $i ];
	}
	$check = 11 - ( $sum % 11 );
	if ( 11 === $check ) {
		$check = 0;
	}
	if ( 10 === $check || (int) $kt[8] !== $check ) {
		return false;
	}
	return in_array( $kt[9], array( '8', '9', '0' ), true );
}

/** Kennitala stored on an order by the classic or the block checkout. */
function konto_get_order_kennitala( $order ) {
	foreach ( array( '_billing_ssn', '_wc_other/konto/kennitala', '_wc_billing/konto/kennitala' ) as $key ) {
		$value = konto_normalize_kennitala( $order->get_meta( $key ) );
		if ( '' !== $value ) {
			return $value;
		}
	}
	return '';
}

function konto_order_wants_xml( $order ) {
	foreach ( array( '_billing_konto_send_xml', '_wc_other/konto/send_xml', '_wc_billing/konto/send_xml' ) as $key ) {
		$value = $order->get_meta( $key );
		if ( $value && 'false' !== $value && '0' !== (string) $value ) {
			return true;
		}
	}
	return false;
}

/** Per-user admin notices without PHP sessions. */
function konto_add_notice( $message, $type = 'error' ) {
	$key     = 'konto_notice_' . get_current_user_id();
	$notices = get_transient( $key );
	$notices = is_array( $notices ) ? $notices : array();
	$notices[] = array( 'type' => $type, 'message' => $message );
	set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
}

add_action( 'admin_notices', 'konto_render_notices' );
function konto_render_notices() {
	$key     = 'konto_notice_' . get_current_user_id();
	$notices = get_transient( $key );
	if ( ! is_array( $notices ) || ! $notices ) {
		return;
	}
	delete_transient( $key );
	foreach ( $notices as $notice ) {
		$class = 'success' === $notice['type'] ? 'notice-success' : ( 'warning' === $notice['type'] ? 'notice-warning' : 'notice-error' );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
}

/* -------------------------------------------------------------------------
 * Bootstrap once WooCommerce is loaded.
 * ---------------------------------------------------------------------- */
add_action( 'plugins_loaded', 'konto_gateway_init', 11 );
function konto_gateway_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	konto_define_classes();

	add_filter( 'woocommerce_payment_gateways', 'konto_add_gateway' );

	// Checkout fields: classic checkout + block checkout.
	add_filter( 'woocommerce_checkout_fields', 'konto_checkout_fields' );
	add_action( 'woocommerce_after_checkout_validation', 'konto_classic_checkout_validation', 10, 2 );
	add_action( 'woocommerce_init', 'konto_register_block_checkout_fields' );
	// WooCommerce fires woocommerce_blocks_loaded during plugins_loaded (priority 10),
	// before this runs, so register straight away when it has already happened.
	if ( did_action( 'woocommerce_blocks_loaded' ) ) {
		konto_blocks_loaded();
	} else {
		add_action( 'woocommerce_blocks_loaded', 'konto_blocks_loaded' );
	}

	// Admin: order list buttons, order screen actions, handlers.
	add_filter( 'woocommerce_admin_order_actions', 'konto_admin_order_actions', 10, 2 );
	add_filter( 'woocommerce_order_actions', 'konto_order_screen_actions', 10, 2 );
	add_action( 'woocommerce_order_action_konto_create_invoice', 'konto_order_screen_create_invoice' );
	add_action( 'woocommerce_order_action_konto_create_draft', 'konto_order_screen_create_draft' );
	add_action( 'woocommerce_order_action_konto_credit_refunds', 'konto_order_screen_credit_refunds' );

	// Refunds -> Konto credit notes.
	add_action( 'woocommerce_create_refund', 'konto_capture_refund_restock', 10, 2 );
	add_action( 'woocommerce_order_refunded', 'konto_on_order_refunded', 20, 2 );
	add_action( 'admin_post_konto_create_invoice', 'konto_admin_post_create_invoice' );
	add_action( 'admin_post_konto_sync_now', 'konto_admin_post_sync_now' );
	add_action( 'admin_head', 'konto_admin_head' );

	// Automatic invoices for orders paid with another method.
	add_action( 'woocommerce_order_status_processing', 'konto_maybe_auto_invoice', 20, 1 );
	add_action( 'woocommerce_order_status_completed', 'konto_maybe_auto_invoice', 20, 1 );

	// Inventory sync.
	add_action( 'konto_inventory_sync', 'konto_inventory_sync_cron' );
	add_action( 'konto_inventory_sync_soon', 'konto_inventory_sync_cron' );
	add_action( 'admin_init', 'konto_ensure_sync_schedule' );
}

function konto_add_gateway( $methods ) {
	$methods[] = 'Konto_Gateway_WC';
	return $methods;
}

/** The gateway instance WooCommerce holds (settings already loaded). */
function konto_gateway() {
	if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways['konto'] ) ) {
			return $gateways['konto'];
		}
	}
	return class_exists( 'Konto_Gateway_WC' ) ? new Konto_Gateway_WC() : null;
}

function konto_define_classes() {
	if ( class_exists( 'Konto_Gateway_WC' ) ) {
		return;
	}

	/**
	 * Thin client for the Konto API (username + API key, form-encoded POST).
	 */
	class Konto_Api {
		protected $base;
		protected $username;
		protected $api_key;

		public function __construct( $base, $username, $api_key ) {
			$this->base     = untrailingslashit( $base );
			$this->username = (string) $username;
			$this->api_key  = (string) $api_key;
		}

		/**
		 * @return array|WP_Error Decoded response with status === true, or an error.
		 */
		public function call( $action, $params = array() ) {
			$body = array_merge(
				array(
					'username' => $this->username,
					'api_key'  => $this->api_key,
				),
				$params
			);
			$path     = '/api/v1/' . $action;
			$response = wp_remote_post(
				$this->base . $path,
				array(
					'timeout'     => 45,
					'redirection' => 0,
					'headers'     => array( 'Accept' => 'application/json' ),
					'body'        => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				Konto_Gateway_WC::log( sprintf( 'POST %s failed: %s', $path, $response->get_error_message() ), 'error' );
				return $response;
			}

			$code   = (int) wp_remote_retrieve_response_code( $response );
			$raw    = wp_remote_retrieve_body( $response );
			$result = json_decode( $raw, true );
			Konto_Gateway_WC::log( sprintf( 'POST %s -> HTTP %d', $path, $code ) );

			if ( ! is_array( $result ) ) {
				Konto_Gateway_WC::log( sprintf( 'POST %s: response is not JSON: %s', $path, substr( (string) $raw, 0, 300 ) ), 'error' );
				/* translators: %d: HTTP status code. */
				return new WP_Error( 'konto_bad_response', sprintf( __( 'Konto returned an unexpected response (HTTP %d).', 'woo-konto-checkout' ), $code ) );
			}
			if ( empty( $result['status'] ) ) {
				$message = ! empty( $result['message'] ) ? $result['message'] : sprintf( 'HTTP %d', $code );
				Konto_Gateway_WC::log( sprintf( 'POST %s rejected (HTTP %d): %s', $path, $code, $message ), 'error' );
				return new WP_Error( 'konto_rejected', $message, $result );
			}
			return $result;
		}
	}

	class Konto_Gateway_WC extends WC_Payment_Gateway {
		public static $log_enabled = false;
		public static $log         = false;

		protected $currencies = array( 'ISK', 'EUR', 'USD', 'DKK', 'NOK', 'SEK', 'JPY', 'GBP', 'AUD', 'PLN', 'CAD', 'CHF', 'CNY', 'NZD', 'MXN', 'SGD', 'HKD', 'KRW', 'TRY', 'RUB', 'INR', 'VND', 'BRL', 'ZAR', 'UAH', 'CZK' );

		public function __construct() {
			$this->id                 = 'konto';
			$this->icon               = KONTO_URL . 'konto_netbanki.png';
			$this->has_fields         = false;
			$this->method_title       = 'Konto';
			$this->method_description = __( 'Konto issues the invoice and creates a claim in the buyer\'s online bank. Also creates invoices for orders paid by other methods and keeps stock in sync with Konto inventory.', 'woo-konto-checkout' );
			$this->supports           = array( 'products' );

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			self::$log_enabled = 'yes' === $this->get_option( 'log', 'no' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public static function log( $message, $level = 'info' ) {
			if ( ! self::$log_enabled && 'error' !== $level ) {
				return;
			}
			if ( empty( self::$log ) ) {
				self::$log = wc_get_logger();
			}
			if ( is_array( $message ) ) {
				$message = wc_print_r( $message, true );
			}
			self::$log->log( $level, $message, array( 'source' => 'konto' ) );
		}

		public function is_available() {
			if ( ! in_array( get_woocommerce_currency(), $this->currencies, true ) ) {
				return false;
			}
			return parent::is_available();
		}

		public function api() {
			if ( 'yes' === $this->get_option( 'testmode', 'no' ) ) {
				$base = $this->get_option( 'test_url', 'https://dev.konto.is' );
			} else {
				$base = 'https://konto.is';
			}
			$base = apply_filters( 'konto_api_base_url', $base, $this );
			return new Konto_Api( $base, $this->get_option( 'username' ), $this->get_option( 'api_key' ) );
		}

		public function inventory_enabled() {
			$account = get_option( 'konto_account', array() );
			return 'yes' === $this->get_option( 'inventory_sync', 'no' ) && ! empty( $account['inventory'] );
		}

		/** Calls hello and caches the account (name, kennitala, inventory flag). */
		public function refresh_account() {
			$result = $this->api()->call( 'hello' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$account = array(
				'name'       => isset( $result['name'] ) ? $result['name'] : '',
				'kennitala'  => isset( $result['registration_no'] ) ? konto_normalize_kennitala( $result['registration_no'] ) : '',
				'inventory'  => ! empty( $result['inventory'] ),
				'checked_at' => time(),
			);
			update_option( 'konto_account', $account, false );
			return $account;
		}

		public function process_admin_options() {
			$saved = parent::process_admin_options();
			$this->init_settings();

			if ( '' === trim( (string) $this->get_option( 'username' ) ) || '' === trim( (string) $this->get_option( 'api_key' ) ) ) {
				konto_schedule_sync( false );
				return $saved;
			}

			$account = $this->refresh_account();
			if ( is_wp_error( $account ) ) {
				/* translators: %s: error message from Konto. */
				WC_Admin_Settings::add_error( sprintf( __( 'Could not connect to Konto: %s', 'woo-konto-checkout' ), $account->get_error_message() ) );
				konto_schedule_sync( false );
				return $saved;
			}
			/* translators: 1: company name, 2: kennitala. */
			WC_Admin_Settings::add_message( sprintf( __( 'Connected to Konto as %1$s (kt. %2$s).', 'woo-konto-checkout' ), $account['name'], $account['kennitala'] ) );

			if ( 'yes' === $this->get_option( 'inventory_sync', 'no' ) && empty( $account['inventory'] ) ) {
				WC_Admin_Settings::add_error( __( 'Stock sync is on, but the Inventory add-on is not active on this Konto account. Stock will not be synced until it is.', 'woo-konto-checkout' ) );
			}
			konto_schedule_sync( $this->inventory_enabled() );
			return $saved;
		}

		public function admin_options() {
			echo '<h2>Konto</h2>';
			echo '<p>' . esc_html__( 'Reikningar sendir frá Konto og krafa stofnuð í netbanka greiðanda.', 'woo-konto-checkout' ) . '</p>';
			if ( ! in_array( get_woocommerce_currency(), $this->currencies, true ) ) {
				/* translators: %s: comma-separated list of currency codes. */
				echo '<div class="inline error"><p><strong>' . esc_html__( 'Gateway disabled', 'woo-konto-checkout' ) . '</strong>: ' . esc_html( sprintf( __( 'The store currency is not supported by Konto. Use one of: %s.', 'woo-konto-checkout' ), implode( ', ', $this->currencies ) ) ) . '</p></div>';
			}
			$account = get_option( 'konto_account', array() );
			if ( ! empty( $account['kennitala'] ) ) {
				/* translators: 1: company name, 2: kennitala, 3: "active" or "not active". */
				echo '<p>' . esc_html( sprintf( __( 'Connected account: %1$s (kt. %2$s). Inventory add-on: %3$s.', 'woo-konto-checkout' ), $account['name'], $account['kennitala'], ! empty( $account['inventory'] ) ? __( 'active', 'woo-konto-checkout' ) : __( 'not active', 'woo-konto-checkout' ) ) ) . '</p>';
			}
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';
			konto_render_sync_status();
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'        => array(
					'title'   => __( 'Enable/Disable', 'woo-konto-checkout' ),
					'label'   => __( 'Offer Konto (invoice + bank claim) at checkout', 'woo-konto-checkout' ),
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				'title'          => array(
					'title'       => __( 'Title', 'woo-konto-checkout' ),
					'type'        => 'text',
					'description' => __( 'The payment method name the customer sees at checkout.', 'woo-konto-checkout' ),
					'default'     => 'Reikning í netbanka',
				),
				'description'    => array(
					'title'       => __( 'Description', 'woo-konto-checkout' ),
					'type'        => 'textarea',
					'description' => __( 'Shown to the customer when they choose Konto.', 'woo-konto-checkout' ),
					'default'     => 'Rafrænn reikningur á PDF berst á netfangið þitt og krafa birtist í netbanka undir „Ógreiddir reikningar“. Kennitala er nauðsynleg.',
				),
				'username'       => array(
					'title'       => __( 'Username', 'woo-konto-checkout' ),
					'type'        => 'text',
					'description' => __( 'Konto: Vefþjónustuaðgangur under Áskriftir og viðbætur.', 'woo-konto-checkout' ),
					'default'     => '',
				),
				'api_key'        => array(
					'title'       => __( 'API key', 'woo-konto-checkout' ),
					'type'        => 'password',
					'description' => __( 'Konto: Vefþjónustuaðgangur under Áskriftir og viðbætur.', 'woo-konto-checkout' ),
					'default'     => '',
				),
				'invoicing'      => array(
					'title' => __( 'Invoices', 'woo-konto-checkout' ),
					'type'  => 'title',
				),
				'due_days'       => array(
					'title'             => __( 'Due date (days)', 'woo-konto-checkout' ),
					'type'              => 'number',
					'default'           => '5',
					'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				),
				'final_days'     => array(
					'title'             => __( 'Final due date (days)', 'woo-konto-checkout' ),
					'type'              => 'number',
					'default'           => '7',
					'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				),
				'lang'           => array(
					'title'   => __( 'Invoice language', 'woo-konto-checkout' ),
					'type'    => 'select',
					'default' => 'auto',
					'options' => array(
						'auto' => __( 'Icelandic for Icelandic addresses, otherwise English', 'woo-konto-checkout' ),
						'is'   => __( 'Icelandic', 'woo-konto-checkout' ),
						'en'   => __( 'English', 'woo-konto-checkout' ),
					),
				),
				'mark'           => array(
					'title'       => __( 'Order status after a Konto checkout', 'woo-konto-checkout' ),
					'label'       => __( 'Mark as Processing', 'woo-konto-checkout' ),
					'type'        => 'checkbox',
					'description' => __( 'On: the order goes to Processing once the claim is created. Off: it waits as On hold until you confirm payment. Stock is reserved either way.', 'woo-konto-checkout' ),
					'default'     => 'yes',
				),
				'auto_invoice'   => array(
					'title'       => __( 'Other payment methods', 'woo-konto-checkout' ),
					'label'       => __( 'Create a paid Konto invoice automatically', 'woo-konto-checkout' ),
					'type'        => 'checkbox',
					'description' => __( 'When an order paid by another method (e.g. card) reaches Processing or Completed. Without a kennitala the invoice is a cash sale (Staðgreitt) on your own kennitala.', 'woo-konto-checkout' ),
					'default'     => 'no',
				),
				'credit_notes'   => array(
					'title'       => __( 'Refunds', 'woo-konto-checkout' ),
					'label'       => __( 'Create a Konto credit note when an order is refunded', 'woo-konto-checkout' ),
					'type'        => 'checkbox',
					'description' => __( 'Full refunds credit the whole invoice and cancel an unpaid bank claim. Partial refunds credit the refunded lines; items you restock in WooCommerce are also put back into their Konto lots.', 'woo-konto-checkout' ),
					'default'     => 'yes',
				),
				'inventory'      => array(
					'title' => __( 'Inventory', 'woo-konto-checkout' ),
					'type'  => 'title',
				),
				'inventory_sync' => array(
					'title'       => __( 'Stock sync', 'woo-konto-checkout' ),
					'label'       => __( 'Sync stock from Konto inventory (lots) every 15 minutes', 'woo-konto-checkout' ),
					'type'        => 'checkbox',
					'description' => __( 'Products are matched on SKU = Konto item number. Konto is the master: WooCommerce stock = sellable Konto stock minus units in orders not yet invoiced in Konto. Invoices draw down Konto lots, earliest expiry first. Requires the Konto Inventory add-on.', 'woo-konto-checkout' ),
					'default'     => 'no',
				),
				'advanced'       => array(
					'title' => __( 'Advanced', 'woo-konto-checkout' ),
					'type'  => 'title',
				),
				'testmode'       => array(
					'title'       => __( 'Test mode', 'woo-konto-checkout' ),
					'label'       => __( 'Send requests to the test server below instead of konto.is', 'woo-konto-checkout' ),
					'type'        => 'checkbox',
					'default'     => 'no',
				),
				'test_url'       => array(
					'title'   => __( 'Test server URL', 'woo-konto-checkout' ),
					'type'    => 'text',
					'default' => 'https://dev.konto.is',
				),
				'log'            => array(
					'title'       => __( 'Debug log', 'woo-konto-checkout' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable logging', 'woo-konto-checkout' ),
					'default'     => 'no',
					'description' => __( 'Logged under WooCommerce > Status > Logs, source "konto". The API key is never logged. Errors are always logged.', 'woo-konto-checkout' ),
				),
			);
		}

		/**
		 * Checkout with Konto: invoice + bank claim to the buyer's kennitala.
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new Exception( esc_html__( 'Order not found.', 'woo-konto-checkout' ) );
			}

			if ( ! $order->get_meta( 'konto_invoice' ) ) {
				$kennitala = konto_get_order_kennitala( $order );
				if ( ! konto_is_valid_kennitala( $kennitala ) ) {
					throw new Exception( esc_html__( 'A valid kennitala is required to pay with Konto.', 'woo-konto-checkout' ) );
				}
				$result = konto_create_invoice_for_order( $order, array( 'claim' => true ) );
				if ( is_wp_error( $result ) ) {
					/* translators: %s: error message from Konto. */
					throw new Exception( esc_html( sprintf( __( 'Konto could not create the invoice: %s', 'woo-konto-checkout' ), $result->get_error_message() ) ) );
				}
			}

			// Both statuses hold stock; Processing = trusted, On hold = wait for payment.
			if ( 'yes' === $this->get_option( 'mark', 'yes' ) ) {
				$order->update_status( 'processing', __( 'Konto claim created.', 'woo-konto-checkout' ) );
			} else {
				$order->update_status( 'on-hold', __( 'Konto claim created, awaiting payment.', 'woo-konto-checkout' ) );
			}
			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}
}

/* -------------------------------------------------------------------------
 * Invoice payload.
 * ---------------------------------------------------------------------- */

/** Konto VAT code for a percentage. Throws on a rate Konto does not have. */
function konto_vat_code_for_percent( $percent, $name ) {
	if ( abs( $percent - 24 ) < 0.6 ) {
		return 'S';
	}
	if ( abs( $percent - 11 ) < 0.6 ) {
		return 'AA';
	}
	if ( abs( $percent ) < 0.5 ) {
		return 'Z';
	}
	throw new Exception( esc_html( sprintf( 'Unsupported VAT rate %s%% on %s. Konto supports 24%%, 11%% and 0%%.', wc_format_decimal( $percent, 2 ), $name ) ) );
}

/**
 * Splits a line's net amount by VAT rate, using the rates stored on the order
 * itself (not today's tax table). Usually one part; a fee or line taxed at
 * several rates gives one part per rate, plus a 0% part for any untaxed rest.
 *
 * @return array list of array( code, net )
 * @throws Exception
 */
function konto_vat_parts( $order, $item ) {
	static $rates = array();
	$oid = $order->get_id();
	if ( ! isset( $rates[ $oid ] ) ) {
		$rates[ $oid ] = array();
		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			$percent = method_exists( $tax_item, 'get_rate_percent' ) ? $tax_item->get_rate_percent() : null;
			if ( null === $percent || '' === $percent ) {
				$percent = WC_Tax::get_rate_percent_value( $tax_item->get_rate_id() );
			}
			$rates[ $oid ][ (int) $tax_item->get_rate_id() ] = (float) $percent;
		}
	}

	$total = (float) $item->get_total();
	$taxes = $item->get_taxes();
	$used  = array();
	if ( ! empty( $taxes['total'] ) ) {
		foreach ( $taxes['total'] as $rate_id => $amount ) {
			if ( '' !== $amount && abs( (float) $amount ) > 0.000001 ) {
				$used[ (int) $rate_id ] = (float) $amount;
			}
		}
	}

	if ( ! $used ) {
		return array( array( 'Z', $total ) );
	}
	if ( 1 === count( $used ) ) {
		$rate_id = key( $used );
		$percent = isset( $rates[ $oid ][ $rate_id ] ) ? $rates[ $oid ][ $rate_id ] : ( abs( $total ) > 0.0001 ? $used[ $rate_id ] / $total * 100 : 0.0 );
		return array( array( konto_vat_code_for_percent( $percent, $item->get_name() ), $total ) );
	}

	$parts = array();
	$rest  = $total;
	foreach ( $used as $rate_id => $amount ) {
		$percent = isset( $rates[ $oid ][ $rate_id ] ) ? $rates[ $oid ][ $rate_id ] : 0.0;
		$code    = konto_vat_code_for_percent( $percent, $item->get_name() );
		if ( 'Z' === $code ) {
			continue;
		}
		$net     = $amount / ( $percent / 100 );
		$parts[] = array( $code, $net );
		$rest   -= $net;
	}
	if ( abs( $rest ) > 0.01 ) {
		$parts[] = array( 'Z', $rest );
	}
	return $parts;
}

/** Konto catalogue guid for a product (SKU = Konto item number), from the last sync. */
function konto_inventory_guid_for_product( $product ) {
	if ( ! $product ) {
		return '';
	}
	$guid = (string) $product->get_meta( '_konto_item_guid' );
	if ( '' === $guid && $product->get_parent_id() ) {
		// A variation without its own SKU is stocked through its parent.
		$parent = wc_get_product( $product->get_parent_id() );
		$guid   = $parent ? (string) $parent->get_meta( '_konto_item_guid' ) : '';
	}
	return $guid;
}

/**
 * Bill-to block for an order: the buyer's kennitala when given, otherwise a cash
 * sale (Staðgreitt) on the seller's own kennitala.
 *
 * @return array( customer, customer_default, due_days, final_days )
 */
function konto_invoice_customer( $order ) {
	$gateway = konto_gateway();
	$kennitala = konto_get_order_kennitala( $order );
	$account   = get_option( 'konto_account', array() );
	$lang      = $gateway ? $gateway->get_option( 'lang', 'auto' ) : 'auto';
	if ( 'auto' === $lang ) {
		$country = $order->get_billing_country();
		$lang    = ( '' === $country || 'IS' === $country ) ? 'is' : 'en';
	}
	$due_days   = $gateway ? max( 0, (int) $gateway->get_option( 'due_days', 5 ) ) : 5;
	$final_days = $gateway ? max( 0, (int) $gateway->get_option( 'final_days', 7 ) ) : 7;

	$name = trim( $order->get_billing_company() );
	if ( '' === $name ) {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	$customer = array(
		'email'        => $order->get_billing_email(),
		'name'         => $name,
		'address'      => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
		'zip'          => $order->get_billing_postcode(),
		'city'         => $order->get_billing_city(),
		'phone_number' => $order->get_billing_phone(),
		'currency'     => $order->get_currency(),
		'lang'         => $lang,
		'due_date'     => $due_days,
		'final_date'   => $final_days,
		'output_select' => 3,
	);
	$customer_default = false;

	if ( '' !== $kennitala ) {
		$customer['registration_no'] = $kennitala;
		if ( konto_order_wants_xml( $order ) ) {
			$customer['output_select']      = 2;
			$customer['trading_partner_id'] = $kennitala;
		}
	} elseif ( ! empty( $account['kennitala'] ) ) {
		// Cash sale: no buyer kennitala, so the bill-to kennitala is the seller's own.
		$customer['name']            = 'Staðgreitt';
		$customer['registration_no'] = $account['kennitala'];
	} else {
		// Seller kennitala unknown (never connected): the account's default contact.
		$customer_default = true;
	}

	return array( $customer, $customer_default, $due_days, $final_days );
}

/**
 * Appends a 0% rounding line when WooCommerce's rounding and Konto's recomputation
 * (sum of unit_price x qty plus VAT, to 2 decimals) differ. Throws when the
 * difference is too large to be rounding.
 */
function konto_add_rounding_line( &$lines, $target ) {
	$rates = array( 'S' => 24, 'AA' => 11, 'Z' => 0 );
	$net   = 0;
	$vat   = 0;
	foreach ( $lines as $line ) {
		$amount = $line['unit_price'] * $line['qty'];
		$net   += $amount;
		$vat   += $amount * ( $rates[ $line['tax'] ] / 100 );
	}
	$konto_total = round( $net + $vat, 2 );
	$target      = round( (float) $target, 2 );
	$diff        = round( $target - $konto_total, 2 );
	if ( 0.0 === $diff ) {
		return;
	}
	// WooCommerce may round each line's VAT to the currency's decimals (whole
	// krónur for ISK): up to half a unit per line.
	$tolerance = max( 1, 0.5 * count( $lines ) );
	if ( abs( $diff ) > $tolerance ) {
		throw new Exception( esc_html( sprintf( 'Invoice lines add up to %s but the total is %s.', wc_format_decimal( $konto_total, 2 ), wc_format_decimal( $target, 2 ) ) ) );
	}
	$lines[] = array(
		'item_number' => 'rounding',
		'description' => __( 'Rounding', 'woo-konto-checkout' ),
		'qty'         => 1,
		'uom'         => 'C62',
		'tax'         => 'Z',
		'unit_price'  => $diff,
	);
}

/**
 * Builds the create-invoice payload. Lines are net of VAT and of discounts, VAT
 * codes come from the order's own tax lines, and a rounding line absorbs the
 * per-line rounding difference between WooCommerce and Konto.
 *
 * @return array
 * @throws Exception
 */
function konto_build_invoice_data( $order, $args ) {
	$gateway   = konto_gateway();
	$inventory = $gateway && $gateway->inventory_enabled();
	$lines     = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$qty = (float) $item->get_quantity();
		if ( $qty <= 0 ) {
			continue;
		}
		$product = $item->get_product();
		$sku     = ( $product && $product->get_sku() ) ? $product->get_sku() : (string) $item->get_id();
		foreach ( konto_vat_parts( $order, $item ) as $i => $part ) {
			$line = array(
				'item_number' => $sku,
				'description' => $item->get_name(),
				'qty'         => $i ? 1 : $qty,
				'uom'         => 'C62',
				'tax'         => $part[0],
				'unit_price'  => round( $i ? $part[1] : $part[1] / $qty, 4 ),
			);
			if ( 0 === $i && $inventory ) {
				$guid = konto_inventory_guid_for_product( $product );
				if ( $guid ) {
					$line['inventory_guid'] = $guid;
				}
			}
			$lines[] = $line;
		}
	}

	foreach ( array( 'shipping', 'fee' ) as $type ) {
		foreach ( $order->get_items( $type ) as $item ) {
			if ( abs( (float) $item->get_total() ) < 0.0001 && abs( (float) $item->get_total_tax() ) < 0.0001 ) {
				continue;
			}
			$name = $item->get_name();
			if ( '' === $name ) {
				$name = 'shipping' === $type ? __( 'Shipping', 'woo-konto-checkout' ) : __( 'Fee', 'woo-konto-checkout' );
			}
			foreach ( konto_vat_parts( $order, $item ) as $part ) {
				$lines[] = array(
					'item_number' => $type,
					'description' => $name,
					'qty'         => 1,
					'uom'         => 'C62',
					'tax'         => $part[0],
					'unit_price'  => round( $part[1], 4 ),
				);
			}
		}
	}

	if ( ! $lines ) {
		throw new Exception( esc_html__( 'The order has no lines to invoice.', 'woo-konto-checkout' ) );
	}

	$order_total = round( (float) $order->get_total(), 2 );
	konto_add_rounding_line( $lines, $order_total );

	list( $customer, $customer_default, $due_days, $final_days ) = konto_invoice_customer( $order );

	$data = array(
		'amount'              => $order_total,
		'currency'            => $order->get_currency(),
		'customer'            => $customer,
		'settlement_date'     => gmdate( 'Y-m-d', time() + $final_days * DAY_IN_SECONDS ),
		'due_date'            => gmdate( 'Y-m-d', time() + $due_days * DAY_IN_SECONDS ),
		'issue_date'          => gmdate( 'Y-m-d' ),
		'type'                => 'invoice',
		'is_claim'            => ! empty( $args['claim'] ),
		'default_payment_fee' => true,
		'items'               => $lines,
		'mark_paid'           => empty( $args['claim'] ),
		'customer_default'    => $customer_default,
		'cost_provide'        => $order->get_order_number(),
		'save'                => ! empty( $args['draft'] ),
	);
	return apply_filters( 'konto_invoice_data', $data, $order, $args );
}

/**
 * Creates the Konto invoice for an order once (guarded by the konto_invoice meta).
 *
 * $args: claim (bool) bank claim instead of paid invoice, draft (bool) save only.
 *
 * @return string|WP_Error Invoice guid.
 */
function konto_create_invoice_for_order( $order, $args = array() ) {
	$existing = $order->get_meta( 'konto_invoice' );
	if ( $existing ) {
		return new WP_Error( 'konto_exists', __( 'This order already has a Konto invoice.', 'woo-konto-checkout' ) );
	}
	$gateway = konto_gateway();
	if ( ! $gateway || '' === trim( (string) $gateway->get_option( 'api_key' ) ) ) {
		return new WP_Error( 'konto_not_configured', __( 'Konto is not configured (username and API key).', 'woo-konto-checkout' ) );
	}

	// Make sure the seller kennitala is known for a cash sale.
	$account = get_option( 'konto_account', array() );
	if ( empty( $account['kennitala'] ) && '' === konto_get_order_kennitala( $order ) ) {
		$gateway->refresh_account();
	}

	try {
		$data = konto_build_invoice_data( $order, $args );
	} catch ( Exception $e ) {
		$message = wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ); // Thrown escaped; shown escaped again on output.
		Konto_Gateway_WC::log( 'Order ' . $order->get_order_number() . ': ' . $message, 'error' );
		return new WP_Error( 'konto_payload', $message );
	}

	Konto_Gateway_WC::log( array( 'order' => $order->get_order_number(), 'invoice' => $data ) );
	$result = $gateway->api()->call( 'create-invoice', array( 'data' => wp_json_encode( $data ) ) );
	if ( is_wp_error( $result ) ) {
		/* translators: %s: error message from Konto. */
		$order->add_order_note( sprintf( __( 'Konto invoice failed: %s', 'woo-konto-checkout' ), $result->get_error_message() ) );
		return $result;
	}

	$guid = isset( $result['result'] ) ? (string) $result['result'] : '';
	$order->update_meta_data( 'konto_invoice', $guid ? $guid : 'created' );
	if ( ! empty( $args['draft'] ) ) {
		$order->update_meta_data( 'konto_invoice_draft', 'yes' );
	}

	$kind = ! empty( $args['draft'] ) ? __( 'saved as draft', 'woo-konto-checkout' ) : ( ! empty( $args['claim'] ) ? __( 'issued with a bank claim', 'woo-konto-checkout' ) : __( 'issued as paid', 'woo-konto-checkout' ) );
	/* translators: 1: how the invoice was created (e.g. "issued as paid"), 2: Konto invoice id. */
	$note = sprintf( __( 'Konto invoice %1$s (%2$s).', 'woo-konto-checkout' ), $kind, $guid );
	if ( 'Staðgreitt' === $data['customer']['name'] ) {
		$note .= ' ' . __( 'No kennitala: cash sale (Staðgreitt) on the seller\'s kennitala.', 'woo-konto-checkout' );
	}
	if ( ! empty( $result['inventory'] ) && is_array( $result['inventory'] ) ) {
		$parts = array();
		foreach ( $result['inventory'] as $row ) {
			$label = isset( $row['item_number'] ) ? $row['item_number'] : $row['inventory_guid'];
			$lots  = array();
			if ( ! empty( $row['lots'] ) ) {
				foreach ( $row['lots'] as $lot ) {
					$lots[] = $lot['number'] . ' × ' . $lot['qty'];
				}
			}
			$text = $label . ': ' . ( $lots ? implode( ', ', $lots ) : '-' );
			if ( ! empty( $row['short'] ) ) {
				/* translators: %d: number of units missing in Konto. */
				$text .= ' ' . sprintf( __( '(%d not in stock in Konto)', 'woo-konto-checkout' ), $row['short'] );
			} elseif ( isset( $row['status'] ) && 'ok' !== $row['status'] ) {
				$text .= ' (' . $row['status'] . ')';
			}
			$parts[] = $text;
		}
		$note .= ' ' . __( 'Konto lots:', 'woo-konto-checkout' ) . ' ' . implode( '; ', $parts );
	}
	$order->add_order_note( $note );
	$order->save();

	// Refresh stock shortly after Konto drew the lots down.
	if ( $gateway->inventory_enabled() && ! wp_next_scheduled( 'konto_inventory_sync_soon' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'konto_inventory_sync_soon' );
	}
	return $guid;
}

/* -------------------------------------------------------------------------
 * Refunds -> Konto credit notes.
 * ---------------------------------------------------------------------- */

/** Remembers whether the refund restocked items (WooCommerce does not store it). */
function konto_capture_refund_restock( $refund, $args ) {
	$refund->update_meta_data( '_konto_restock', ! empty( $args['restock_items'] ) ? 'yes' : 'no' );
}

function konto_on_order_refunded( $order_id, $refund_id ) {
	$gateway = konto_gateway();
	if ( ! $gateway || 'yes' !== $gateway->get_option( 'credit_notes', 'yes' ) ) {
		return;
	}
	$order  = wc_get_order( $order_id );
	$refund = wc_get_order( $refund_id );
	if ( ! $order || ! $refund || ! $order->get_meta( 'konto_invoice' ) ) {
		return;
	}
	$result = konto_create_credit_note( $order, $refund );
	if ( is_wp_error( $result ) && is_admin() ) {
		konto_add_notice( sprintf( /* translators: 1: order number, 2: error message. */ __( 'Order %1$s: no Konto credit note. %2$s', 'woo-konto-checkout' ), $order->get_order_number(), $result->get_error_message() ) );
	}
}

/** Refunds of this order that have no Konto credit note yet. */
function konto_refunds_without_credit_note( $order ) {
	$open = array();
	foreach ( $order->get_refunds() as $refund ) {
		if ( ! $refund->get_meta( '_konto_credit_note' ) && abs( (float) $refund->get_amount() ) > 0.0001 ) {
			$open[] = $refund;
		}
	}
	return $open;
}

function konto_order_screen_credit_refunds( $order ) {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}
	foreach ( array_reverse( konto_refunds_without_credit_note( $order ) ) as $refund ) {
		$result = konto_create_credit_note( $order, $refund );
		if ( is_wp_error( $result ) ) {
			konto_add_notice( $result->get_error_message() );
			return;
		}
	}
}

/**
 * Credit-note lines for a refund: the refunded items, shipping and fees with their
 * own VAT, or -- for a refund of an amount only -- that amount spread over the
 * order's VAT rates in proportion. Restocked item lines carry inventory_guid so
 * Konto puts them back into the lots the invoice drew.
 *
 * @throws Exception
 */
function konto_build_credit_lines( $order, $refund, $restock ) {
	$gateway   = konto_gateway();
	$inventory = $restock && $gateway && $gateway->inventory_enabled();
	$lines     = array();

	foreach ( $refund->get_items( 'line_item' ) as $item ) {
		if ( abs( (float) $item->get_total() ) < 0.0001 && abs( (float) $item->get_total_tax() ) < 0.0001 ) {
			continue;
		}
		$qty     = abs( (float) $item->get_quantity() );
		$product = $item->get_product();
		$sku     = ( $product && $product->get_sku() ) ? $product->get_sku() : (string) $item->get_id();
		foreach ( konto_vat_parts( $order, $item ) as $i => $part ) {
			$net  = -$part[1];
			$q    = ( 0 === $i && $qty > 0 ) ? $qty : 1;
			$line = array(
				'item_number' => $sku,
				'description' => $item->get_name(),
				'qty'         => $q,
				'uom'         => 'C62',
				'tax'         => $part[0],
				'unit_price'  => round( $net / $q, 4 ),
			);
			if ( 0 === $i && $inventory && $qty > 0 ) {
				$guid = konto_inventory_guid_for_product( $product );
				if ( $guid ) {
					$line['inventory_guid'] = $guid;
				}
			}
			$lines[] = $line;
		}
	}

	foreach ( array( 'shipping', 'fee' ) as $type ) {
		foreach ( $refund->get_items( $type ) as $item ) {
			if ( abs( (float) $item->get_total() ) < 0.0001 && abs( (float) $item->get_total_tax() ) < 0.0001 ) {
				continue;
			}
			$name = $item->get_name();
			if ( '' === $name ) {
				$name = 'shipping' === $type ? __( 'Shipping', 'woo-konto-checkout' ) : __( 'Fee', 'woo-konto-checkout' );
			}
			foreach ( konto_vat_parts( $order, $item ) as $part ) {
				$lines[] = array(
					'item_number' => $type,
					'description' => $name,
					'qty'         => 1,
					'uom'         => 'C62',
					'tax'         => $part[0],
					'unit_price'  => round( -$part[1], 4 ),
				);
			}
		}
	}

	$amount = abs( (float) $refund->get_amount() );

	if ( ! $lines ) {
		// Amount-only refund: split by the order's own VAT mix (gross per rate).
		$rates = array( 'S' => 24, 'AA' => 11, 'Z' => 0 );
		$gross = array();
		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			foreach ( konto_vat_parts( $order, $item ) as $part ) {
				$gross[ $part[0] ] = ( isset( $gross[ $part[0] ] ) ? $gross[ $part[0] ] : 0 ) + $part[1] * ( 1 + $rates[ $part[0] ] / 100 );
			}
		}
		$sum = array_sum( $gross );
		if ( abs( $sum ) < 0.0001 ) {
			$gross = array( 'Z' => 1 );
			$sum   = 1;
		}
		$reason = $refund->get_reason();
		foreach ( $gross as $code => $g ) {
			$share = $amount * $g / $sum;
			if ( abs( $share ) < 0.0001 ) {
				continue;
			}
			$lines[] = array(
				'item_number' => 'refund',
				'description' => $reason ? $reason : __( 'Refund', 'woo-konto-checkout' ),
				'qty'         => 1,
				'uom'         => 'C62',
				'tax'         => $code,
				'unit_price'  => round( $share / ( 1 + $rates[ $code ] / 100 ), 4 ),
			);
		}
	}

	konto_add_rounding_line( $lines, $amount );
	return $lines;
}

/**
 * Creates the Konto credit note for one WooCommerce refund (once per refund).
 *
 * @return string|WP_Error Credit note guid.
 */
function konto_create_credit_note( $order, $refund ) {
	$done = $refund->get_meta( '_konto_credit_note' );
	if ( $done ) {
		return $done;
	}
	$invoice = $order->get_meta( 'konto_invoice' );
	$gateway = konto_gateway();
	if ( ! $invoice || 'created' === $invoice || ! $gateway ) {
		return new WP_Error( 'konto_no_invoice', __( 'The order has no Konto invoice to credit.', 'woo-konto-checkout' ) );
	}
	$amount = abs( (float) $refund->get_amount() );
	if ( $amount < 0.0001 ) {
		return new WP_Error( 'konto_zero', __( 'Nothing to credit.', 'woo-konto-checkout' ) );
	}
	if ( konto_draft_still_open( $order ) ) {
		$error = new WP_Error( 'konto_draft', __( 'The Konto invoice is still a draft. Issue it or delete it in Konto, then create the credit note.', 'woo-konto-checkout' ) );
		$order->add_order_note( sprintf( /* translators: 1: refund id, 2: reason. */ __( 'Refund #%1$d: no Konto credit note. %2$s', 'woo-konto-checkout' ), $refund->get_id(), $error->get_error_message() ) );
		return $error;
	}

	$full    = round( (float) $order->get_total_refunded(), 2 ) >= round( (float) $order->get_total(), 2 );
	$restock = 'yes' === $refund->get_meta( '_konto_restock' );

	// A partial credit cannot shrink a bank claim that is still open.
	if ( ! $full && 'konto' === $order->get_payment_method() ) {
		$status = $gateway->api()->call( 'get-invoice', array( 'guid' => $invoice ) );
		if ( ! is_wp_error( $status ) && isset( $status['result']['status'] ) && 'Paid' !== $status['result']['status'] ) {
			$error = new WP_Error( 'konto_open_claim', __( 'Partial refund of a bank claim that is not paid yet. Adjust or cancel the claim in Konto; no credit note was created.', 'woo-konto-checkout' ) );
			$order->add_order_note( sprintf( /* translators: 1: refund id, 2: reason. */ __( 'Refund #%1$d: no Konto credit note. %2$s', 'woo-konto-checkout' ), $refund->get_id(), $error->get_error_message() ) );
			return $error;
		}
	}

	try {
		$lines = konto_build_credit_lines( $order, $refund, $restock );
	} catch ( Exception $e ) {
		$message = wp_specialchars_decode( $e->getMessage(), ENT_QUOTES );
		$order->add_order_note( sprintf( /* translators: 1: refund id, 2: error message. */ __( 'Refund #%1$d: Konto credit note failed. %2$s', 'woo-konto-checkout' ), $refund->get_id(), $message ) );
		return new WP_Error( 'konto_payload', $message );
	}

	list( $customer, $customer_default, $due_days, $final_days ) = konto_invoice_customer( $order );
	$data = apply_filters(
		'konto_credit_note_data',
		array(
			'amount'           => round( $amount, 2 ),
			'currency'         => $order->get_currency(),
			'type'             => 'credit',
			'ref_invoice_guid' => $invoice,
			'credit_stock'     => 'lines',
			'credit_partial'   => ! $full,
			'customer'         => $customer,
			'customer_default' => $customer_default,
			'settlement_date'  => gmdate( 'Y-m-d', time() + $final_days * DAY_IN_SECONDS ),
			'due_date'         => gmdate( 'Y-m-d', time() + $due_days * DAY_IN_SECONDS ),
			'issue_date'       => gmdate( 'Y-m-d' ),
			'is_claim'         => false,
			'mark_paid'        => true,
			'items'            => $lines,
			'cost_provide'     => $order->get_order_number() . '-R' . $refund->get_id(),
		),
		$order,
		$refund
	);

	Konto_Gateway_WC::log( array( 'order' => $order->get_order_number(), 'refund' => $refund->get_id(), 'credit_note' => $data ) );
	$result = $gateway->api()->call( 'create-invoice', array( 'data' => wp_json_encode( $data ) ) );
	if ( is_wp_error( $result ) ) {
		$order->add_order_note( sprintf( /* translators: 1: refund id, 2: error message. */ __( 'Refund #%1$d: Konto credit note failed. %2$s', 'woo-konto-checkout' ), $refund->get_id(), $result->get_error_message() ) );
		return $result;
	}

	$guid = isset( $result['result'] ) ? (string) $result['result'] : 'created';
	$refund->update_meta_data( '_konto_credit_note', $guid );
	$refund->save_meta_data();

	$note = sprintf(
		/* translators: 1: refund id, 2: "full" or "partial", 3: credit note id. */
		__( 'Refund #%1$d: Konto credit note created (%2$s, %3$s).', 'woo-konto-checkout' ),
		$refund->get_id(),
		$full ? __( 'full credit, invoice closed', 'woo-konto-checkout' ) : __( 'partial', 'woo-konto-checkout' ),
		$guid
	);
	if ( ! empty( $result['inventory'] ) && is_array( $result['inventory'] ) ) {
		$parts = array();
		foreach ( $result['inventory'] as $row ) {
			$label = isset( $row['item_number'] ) ? $row['item_number'] : $row['inventory_guid'];
			$lots  = array();
			if ( ! empty( $row['lots'] ) ) {
				foreach ( $row['lots'] as $lot ) {
					$lots[] = $lot['number'] . ' × ' . $lot['qty'];
				}
			}
			$text = $label . ': ' . ( $lots ? implode( ', ', $lots ) : '-' );
			if ( ! empty( $row['short'] ) ) {
				/* translators: %d: number of units. */
				$text .= ' ' . sprintf( __( '(%d not returned to a lot)', 'woo-konto-checkout' ), $row['short'] );
			}
			$parts[] = $text;
		}
		$note .= ' ' . __( 'Back into Konto lots:', 'woo-konto-checkout' ) . ' ' . implode( '; ', $parts );
	} elseif ( ! $restock ) {
		$note .= ' ' . __( 'Not restocked, so Konto stock is unchanged.', 'woo-konto-checkout' );
	}
	$order->add_order_note( $note );

	if ( $gateway->inventory_enabled() && ! wp_next_scheduled( 'konto_inventory_sync_soon' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'konto_inventory_sync_soon' );
	}
	return $guid;
}

/* -------------------------------------------------------------------------
 * Checkout fields.
 * ---------------------------------------------------------------------- */

function konto_checkout_fields( $fields ) {
	$fields['billing']['billing_ssn']            = array(
		'type'        => 'text',
		'label'       => __( 'Kennitala', 'woo-konto-checkout' ),
		'placeholder' => _x( 'Kennitala', 'placeholder', 'woo-konto-checkout' ),
		'description' => __( 'Required when paying with Konto (invoice in online bank).', 'woo-konto-checkout' ),
		'required'    => false,
		'clear'       => true,
		'priority'    => 25,
		'class'       => array( 'form-row-wide' ),
		'custom_attributes' => array( 'inputmode' => 'numeric', 'maxlength' => '11', 'autocomplete' => 'off' ),
	);
	$fields['billing']['billing_konto_send_xml'] = array(
		'type'     => 'checkbox',
		'label'    => __( 'Send the invoice as an XML e-invoice (companies)', 'woo-konto-checkout' ),
		'required' => false,
		'clear'    => true,
		'priority' => 26,
	);
	return $fields;
}

function konto_classic_checkout_validation( $data, $errors ) {
	$kennitala = isset( $data['billing_ssn'] ) ? konto_normalize_kennitala( $data['billing_ssn'] ) : '';
	$method    = isset( $data['payment_method'] ) ? $data['payment_method'] : '';
	if ( 'konto' === $method && '' === $kennitala ) {
		$errors->add( 'validation', __( 'Please enter your kennitala to pay with Konto.', 'woo-konto-checkout' ) );
	} elseif ( '' !== $kennitala && ! konto_is_valid_kennitala( $kennitala ) ) {
		$errors->add( 'validation', __( 'The kennitala is not valid.', 'woo-konto-checkout' ) );
	}
}

/** Block checkout (WooCommerce 8.9+): the same two fields as additional contact fields. */
function konto_register_block_checkout_fields() {
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
		return;
	}
	woocommerce_register_additional_checkout_field(
		array(
			'id'                => 'konto/kennitala',
			'label'             => __( 'Kennitala', 'woo-konto-checkout' ),
			'optionalLabel'     => __( 'Kennitala (required for Konto bank invoice)', 'woo-konto-checkout' ),
			'location'          => 'contact',
			'type'              => 'text',
			'required'          => false,
			'attributes'        => array(
				'autocomplete' => 'off',
				'maxLength'    => 11,
			),
			'sanitize_callback' => 'konto_normalize_kennitala',
			'validate_callback' => 'konto_block_validate_kennitala',
		)
	);
	woocommerce_register_additional_checkout_field(
		array(
			'id'       => 'konto/send_xml',
			'label'    => __( 'Send the invoice as an XML e-invoice (companies)', 'woo-konto-checkout' ),
			'location' => 'contact',
			'type'     => 'checkbox',
		)
	);
}

function konto_block_validate_kennitala( $value ) {
	$value = konto_normalize_kennitala( $value );
	if ( '' !== $value && ! konto_is_valid_kennitala( $value ) ) {
		return new WP_Error( 'konto_invalid_kennitala', __( 'The kennitala is not valid.', 'woo-konto-checkout' ) );
	}
	return true;
}

/** Block checkout payment method (registered with an inline script, no build step). */
function konto_blocks_loaded() {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	if ( ! class_exists( 'Konto_Blocks_Payment' ) ) {
		class Konto_Blocks_Payment extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
			protected $name = 'konto';

			public function initialize() {
				$this->settings = get_option( 'woocommerce_konto_settings', array() );
			}

			public function is_active() {
				$gateway = konto_gateway();
				return $gateway && $gateway->is_available();
			}

			public function get_payment_method_script_handles() {
				wp_register_script( 'konto-blocks', false, array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), KONTO_VERSION, true );
				wp_add_inline_script( 'konto-blocks', konto_blocks_script() );
				return array( 'konto-blocks' );
			}

			public function get_payment_method_data() {
				return array(
					'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : 'Konto',
					'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
					'icon'        => KONTO_URL . 'konto_netbanki.png',
					'supports'    => array( 'products' ),
				);
			}
		}
	}
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new Konto_Blocks_Payment() );
		}
	);
}

function konto_blocks_script() {
	return <<<'JS'
( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	if ( ! registry || ! settingsApi ) { return; }
	// WooCommerce 9+ serves gateway data under paymentMethodData; older versions as konto_data.
	var settings = ( settingsApi.getSetting( 'paymentMethodData', {} ) || {} ).konto || settingsApi.getSetting( 'konto_data', {} );
	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var title = decode( settings.title || 'Konto' );
	var Content = function () { return el( 'div', null, decode( settings.description || '' ) ); };
	var Label = function ( props ) {
		var L = props.components && props.components.PaymentMethodLabel;
		return L ? el( L, { text: title } ) : el( 'span', null, title );
	};
	registry.registerPaymentMethod( {
		name: 'konto',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: { features: settings.supports || [ 'products' ] }
	} );
} )();
JS;
}

/* -------------------------------------------------------------------------
 * Admin: create invoices for orders paid by other methods.
 * ---------------------------------------------------------------------- */

function konto_order_can_be_invoiced( $order ) {
	return $order
		&& 'konto' !== $order->get_payment_method()
		&& $order->has_status( array( 'processing', 'completed', 'on-hold' ) )
		&& ! $order->get_meta( 'konto_invoice' );
}

function konto_admin_order_actions( $actions, $order ) {
	if ( ! konto_order_can_be_invoiced( $order ) || ! current_user_can( 'edit_shop_orders' ) ) {
		return $actions;
	}
	foreach ( array( 0, 1 ) as $draft ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'konto_create_invoice',
					'order_id' => $order->get_id(),
					'draft'    => $draft,
				),
				admin_url( 'admin-post.php' )
			),
			'konto_create_invoice_' . $order->get_id()
		);
		$key             = $draft ? 'konto_draft' : 'konto';
		$actions[ $key ] = array(
			'url'    => $url,
			'name'   => $draft ? __( 'Import order to Konto as a saved (draft) invoice', 'woo-konto-checkout' ) : __( 'Import order to Konto, issue and send invoice', 'woo-konto-checkout' ),
			'action' => $key,
		);
	}
	return $actions;
}

function konto_admin_post_create_invoice() {
	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
	check_admin_referer( 'konto_create_invoice_' . $order_id );
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'woo-konto-checkout' ), 403 );
	}
	$order = wc_get_order( $order_id );
	if ( ! konto_order_can_be_invoiced( $order ) ) {
		konto_add_notice( __( 'This order cannot be invoiced in Konto (already invoiced, paid with Konto, or not Processing/Completed/On hold).', 'woo-konto-checkout' ) );
	} else {
		$result = konto_create_invoice_for_order( $order, array( 'draft' => ! empty( $_GET['draft'] ) ) );
		if ( is_wp_error( $result ) ) {
			/* translators: 1: order number, 2: error message. */
			konto_add_notice( sprintf( __( 'Order %1$s: %2$s', 'woo-konto-checkout' ), $order->get_order_number(), $result->get_error_message() ) );
		} else {
			/* translators: %s: order number. */
			konto_add_notice( sprintf( __( 'Order %s was sent to Konto.', 'woo-konto-checkout' ), $order->get_order_number() ), 'success' );
		}
	}
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wc-orders' ) );
	exit;
}

function konto_order_screen_actions( $actions, $order = null ) {
	if ( $order && konto_order_can_be_invoiced( $order ) ) {
		$actions['konto_create_invoice'] = __( 'Konto: issue and send invoice', 'woo-konto-checkout' );
		$actions['konto_create_draft']   = __( 'Konto: save as draft invoice', 'woo-konto-checkout' );
	}
	if ( $order && $order->get_meta( 'konto_invoice' ) && konto_refunds_without_credit_note( $order ) ) {
		$actions['konto_credit_refunds'] = __( 'Konto: create credit notes for refunds', 'woo-konto-checkout' );
	}
	return $actions;
}

function konto_order_screen_create_invoice( $order ) {
	konto_order_screen_run( $order, false );
}

function konto_order_screen_create_draft( $order ) {
	konto_order_screen_run( $order, true );
}

function konto_order_screen_run( $order, $draft ) {
	if ( ! current_user_can( 'edit_shop_orders' ) || ! konto_order_can_be_invoiced( $order ) ) {
		return;
	}
	$result = konto_create_invoice_for_order( $order, array( 'draft' => $draft ) );
	if ( is_wp_error( $result ) ) {
		konto_add_notice( $result->get_error_message() );
	}
}

function konto_maybe_auto_invoice( $order_id ) {
	$gateway = konto_gateway();
	if ( ! $gateway || 'yes' !== $gateway->get_option( 'auto_invoice', 'no' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( konto_order_can_be_invoiced( $order ) ) {
		konto_create_invoice_for_order( $order, array() );
	}
}

function konto_admin_head() {
	echo '<style>.wc-action-button-konto::after{font-family:dashicons!important;content:"\f170"!important}.wc-action-button-konto_draft::after{font-family:dashicons!important;content:"\f137"!important}</style>';
}

/* -------------------------------------------------------------------------
 * Inventory sync (Konto -> WooCommerce).
 * ---------------------------------------------------------------------- */

function konto_schedule_sync( $enabled ) {
	$next = wp_next_scheduled( 'konto_inventory_sync' );
	if ( $enabled && ! $next ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'konto_15min', 'konto_inventory_sync' );
	} elseif ( ! $enabled && $next ) {
		wp_clear_scheduled_hook( 'konto_inventory_sync' );
	}
}

function konto_ensure_sync_schedule() {
	$gateway = konto_gateway();
	if ( $gateway ) {
		konto_schedule_sync( $gateway->inventory_enabled() );
	}
}

function konto_inventory_sync_cron() {
	konto_inventory_sync();
}

/**
 * Units WooCommerce has already taken out of stock for orders Konto has not been
 * invoiced for yet -- Konto still counts them, so they are subtracted from its figure.
 *
 * @return array product or variation id => qty
 */
function konto_reserved_quantities() {
	$days     = (int) apply_filters( 'konto_reservation_days', 60 );
	$reserved = array();
	$page     = 1;
	do {
		$ids = wc_get_orders(
			array(
				'type'         => 'shop_order', // Refunds carry status "completed" too.
				'status'       => array( 'wc-processing', 'wc-on-hold', 'wc-completed' ),
				'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
				'limit'        => 200,
				'page'         => $page,
				'return'       => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || $order instanceof WC_Order_Refund ) {
				continue;
			}
			// Invoiced orders are already counted out in Konto -- except drafts, which
			// only draw the lots down once they are issued in Konto.
			if ( $order->get_meta( 'konto_invoice' ) && ! konto_draft_still_open( $order ) ) {
				continue;
			}
			if ( ! $order->get_data_store()->get_stock_reduced( $order->get_id() ) ) {
				continue;
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product = $item->get_product();
				// Count against the product that holds the stock (a variation may use its parent's).
				$pid = $product ? $product->get_stock_managed_by_id() : ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
				$qty = $item->get_meta( '_reduced_stock', true );
				$qty = ( '' === $qty ) ? $item->get_quantity() : $qty;
				if ( $pid && $qty > 0 ) {
					$reserved[ $pid ] = ( isset( $reserved[ $pid ] ) ? $reserved[ $pid ] : 0 ) + (int) $qty;
				}
			}
		}
		$page++;
	} while ( count( $ids ) === 200 );
	return $reserved;
}

/**
 * True while the order's Konto invoice is still a draft. Asks Konto once per sync for
 * draft orders only; when the draft has been issued the flag is cleared for good.
 * On a failed lookup the draft is assumed open (reserving errs on the safe side).
 */
function konto_draft_still_open( $order ) {
	if ( 'yes' !== $order->get_meta( 'konto_invoice_draft' ) ) {
		return false;
	}
	$gateway = konto_gateway();
	if ( ! $gateway ) {
		return true;
	}
	$result = $gateway->api()->call( 'get-invoice', array( 'guid' => $order->get_meta( 'konto_invoice' ) ) );
	if ( is_wp_error( $result ) || empty( $result['result']['status'] ) ) {
		return true;
	}
	if ( 'Draft' === $result['result']['status'] ) {
		return true;
	}
	$order->delete_meta_data( 'konto_invoice_draft' );
	/* translators: %s: Konto invoice status. */
	$order->add_order_note( sprintf( __( 'The Konto draft invoice has been issued (%s).', 'woo-konto-checkout' ), $result['result']['status'] ) );
	$order->save();
	return false;
}

/**
 * Pulls Konto items and sets WooCommerce stock for every SKU that matches a Konto
 * item number with inventory. Konto is the master; unmatched products are untouched.
 *
 * @return array report
 */
function konto_inventory_sync() {
	$report  = array(
		'time'        => time(),
		'updated'     => 0,
		'unchanged'   => 0,
		'not_tracked' => 0,
		'unmatched'   => array(),
		'changes'     => array(),
		'error'       => '',
	);
	$gateway = konto_gateway();
	if ( ! $gateway || ! $gateway->inventory_enabled() ) {
		$report['error'] = __( 'Stock sync is off, or the Konto Inventory add-on is not active.', 'woo-konto-checkout' );
		update_option( 'konto_inventory_last_sync', $report, false );
		return $report;
	}
	if ( get_transient( 'konto_sync_lock' ) ) {
		$report['error'] = __( 'A sync is already running.', 'woo-konto-checkout' );
		return $report;
	}
	set_transient( 'konto_sync_lock', 1, 5 * MINUTE_IN_SECONDS );
	try {
		$report = konto_inventory_sync_locked( $gateway, $report );
	} catch ( Throwable $e ) {
		// Never leave the lock behind or a half-written report after an unexpected error.
		$report['error'] = $e->getMessage();
		Konto_Gateway_WC::log( 'Stock sync failed: ' . $e->getMessage(), 'error' );
		update_option( 'konto_inventory_last_sync', $report, false );
	}
	delete_transient( 'konto_sync_lock' );
	return $report;
}

/** The sync itself; konto_inventory_sync() holds the lock around it. */
function konto_inventory_sync_locked( $gateway, $report ) {
	global $wpdb;


	$api   = $gateway->api();
	$count = $api->call( 'get-count-items' );
	if ( is_wp_error( $count ) ) {
		$report['error'] = $count->get_error_message();
		update_option( 'konto_inventory_last_sync', $report, false );
		delete_transient( 'konto_sync_lock' );
		return $report;
	}
	$limit = 100;
	$pages = (int) ceil( (int) $count['result'] / $limit );
	$items = array();
	for ( $page = 1; $page <= $pages; $page++ ) {
		$batch = $api->call( 'get-items', array( 'limit' => $limit, 'page' => $page ) );
		if ( is_wp_error( $batch ) ) {
			$report['error'] = $batch->get_error_message();
			update_option( 'konto_inventory_last_sync', $report, false );
			delete_transient( 'konto_sync_lock' );
			return $report;
		}
		foreach ( (array) $batch['result'] as $row ) {
			$items[] = $row;
		}
	}

	// SKU -> product id, one query (product lookup table, WooCommerce 3.6+).
	$skus = array();
	// One indexed read of the product lookup table instead of a query per Konto item.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	foreach ( $wpdb->get_results( "SELECT product_id, sku FROM {$wpdb->prefix}wc_product_meta_lookup WHERE sku <> ''" ) as $row ) {
		$skus[ (string) $row->sku ] = (int) $row->product_id;
	}

	$reserved = konto_reserved_quantities();
	$matched  = array();

	foreach ( $items as $row ) {
		$number = isset( $row['item_number'] ) ? trim( (string) $row['item_number'] ) : '';
		if ( '' === $number || ! isset( $skus[ $number ] ) ) {
			continue;
		}
		$pid             = $skus[ $number ];
		$matched[ $pid ] = true;
		$product         = wc_get_product( $pid );
		if ( ! $product ) {
			continue;
		}
		if ( empty( $row['inventory_tracked'] ) || ! array_key_exists( 'qty_available', $row ) ) {
			$report['not_tracked']++;
			continue;
		}

		if ( $product->get_meta( '_konto_item_guid' ) !== $row['guid'] ) {
			$product->update_meta_data( '_konto_item_guid', $row['guid'] );
			$product->save_meta_data();
		}

		$held   = isset( $reserved[ $pid ] ) ? $reserved[ $pid ] : 0;
		$target = max( 0, (int) $row['qty_available'] - $held );

		if ( ! $product->managing_stock() ) {
			$product->set_manage_stock( true );
			$product->save();
		}
		$current = $product->get_stock_quantity();
		if ( null === $current || (int) $current !== $target ) {
			wc_update_product_stock( $product, $target, 'set' );
			$report['updated']++;
			if ( count( $report['changes'] ) < 50 ) {
				$report['changes'][] = sprintf( '%s: %s → %d', $number, null === $current ? '-' : (int) $current, $target );
			}
			Konto_Gateway_WC::log( sprintf( 'Stock %s (product %d): %s -> %d (Konto %d, reserved %d)', $number, $pid, null === $current ? '-' : (int) $current, $target, (int) $row['qty_available'], $held ) );
		} else {
			$report['unchanged']++;
		}
	}

	foreach ( $skus as $sku => $pid ) {
		if ( ! isset( $matched[ $pid ] ) && count( $report['unmatched'] ) < 50 ) {
			$report['unmatched'][] = $sku;
		}
	}
	$report['unmatched_total'] = count( $skus ) - count( $matched );

	update_option( 'konto_inventory_last_sync', $report, false );
	delete_transient( 'konto_sync_lock' );
	return $report;
}

function konto_admin_post_sync_now() {
	check_admin_referer( 'konto_sync_now' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'woo-konto-checkout' ), 403 );
	}
	$report = konto_inventory_sync();
	if ( $report['error'] ) {
		/* translators: %s: error message. */
		konto_add_notice( sprintf( __( 'Konto stock sync: %s', 'woo-konto-checkout' ), $report['error'] ) );
	} else {
		/* translators: 1: number of products updated, 2: number unchanged. */
		konto_add_notice( sprintf( __( 'Konto stock sync: %1$d updated, %2$d unchanged.', 'woo-konto-checkout' ), $report['updated'], $report['unchanged'] ), 'success' );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=konto' ) );
	exit;
}

function konto_render_sync_status() {
	$gateway = konto_gateway();
	if ( ! $gateway || 'yes' !== $gateway->get_option( 'inventory_sync', 'no' ) ) {
		return;
	}
	$report = get_option( 'konto_inventory_last_sync', array() );
	$url    = wp_nonce_url( admin_url( 'admin-post.php?action=konto_sync_now' ), 'konto_sync_now' );
	echo '<h3>' . esc_html__( 'Stock sync status', 'woo-konto-checkout' ) . '</h3>';
	if ( ! $report ) {
		echo '<p>' . esc_html__( 'No sync has run yet.', 'woo-konto-checkout' ) . '</p>';
	} else {
		/* translators: 1: date and time, 2: updated, 3: unchanged, 4: Konto items without lots, 5: store SKUs not found in Konto. */
		echo '<p>' . esc_html( sprintf( __( 'Last sync: %1$s. Updated %2$d, unchanged %3$d, Konto items without lots %4$d, store SKUs not found in Konto %5$d.', 'woo-konto-checkout' ), wp_date( 'Y-m-d H:i', $report['time'] ), $report['updated'], $report['unchanged'], $report['not_tracked'], isset( $report['unmatched_total'] ) ? $report['unmatched_total'] : count( $report['unmatched'] ) ) ) . '</p>';
		if ( ! empty( $report['error'] ) ) {
			echo '<p style="color:#b32d2e">' . esc_html( $report['error'] ) . '</p>';
		}
		if ( ! empty( $report['changes'] ) ) {
			echo '<p>' . esc_html__( 'Changes:', 'woo-konto-checkout' ) . ' ' . esc_html( implode( ', ', $report['changes'] ) ) . '</p>';
		}
		if ( ! empty( $report['unmatched'] ) ) {
			echo '<p>' . esc_html__( 'SKUs not in Konto:', 'woo-konto-checkout' ) . ' ' . esc_html( implode( ', ', $report['unmatched'] ) ) . '</p>';
		}
	}
	$next = wp_next_scheduled( 'konto_inventory_sync' );
	if ( $next ) {
		/* translators: %s: date and time. */
		echo '<p>' . esc_html( sprintf( __( 'Next automatic sync: %s.', 'woo-konto-checkout' ), wp_date( 'Y-m-d H:i', $next ) ) ) . '</p>';
	}
	echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Sync stock now', 'woo-konto-checkout' ) . '</a></p>';
}
