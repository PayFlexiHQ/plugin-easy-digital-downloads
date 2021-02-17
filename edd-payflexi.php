<?php
/*
Plugin Name: PayFlexi Installment Payment Gateway for Easy Digital Downloads
Plugin URL: https://developer.payflexi.co
Description: The PayFlexi Instalment Payment for Easy Digital Downloads allows site to accept installment payments for products from their customers. Accept payments via Stripe, PayStack, Flutterwave, and more. 
Version: 1.0.0
Author: PayFlexi
Author URI: https://payflexi.co
License: GPL-2.0+
License URI:http://www.gnu.org/licenses/gpl-2.0.txt
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Easy Digital Downloads is active
if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
	return;
}

define('EDD_PAYFLEXI_URL', plugin_dir_url( __FILE__ ) );

define('EDD_PAYFLEXI_VERSION', '1.0.0' );

class EDD_Payflexi_Gateway {

    /**
	 * Get things started
	 *
	 * @since 1.2
	 */
	public function __construct() {

		if( ! function_exists( 'edd_is_gateway_active') ) {
			return;
		}

		add_action( 'edd_payflexi_cc_form', '__return_false' );
		add_action( 'edd_gateway_payflexi', array( $this, 'process_payment' ) );
        add_action( 'init', array( $this, 'edd_payflexi_redirect') );
		add_action( 'edd_after_cc_fields', array( $this, 'edd_payflexi_add_errors' ), 999 );
        add_action( 'edd_pre_process_purchase', array($this, 'edd_payflexi_check_config'), 1);
        add_action( 'payflexi_redirect_verify', array( $this, 'payflexi_redirect_verify' ));
        add_action( 'payflexi_process_webhook', array( $this, 'payflexi_process_webhook' ) );
        add_action( 'admin_notices', array( $this, 'edd_payflexi_testmode_notice' ));

		add_filter( 'edd_payment_gateways', array( $this, 'register_payflexi_gateway' ) );
		add_filter( 'edd_accepted_payment_icons', array( $this, 'payment_icon' ) );
		add_filter( 'edd_settings_sections_gateways', array( $this, 'edd_payflexi_settings_section' ), 10, 1 );
		add_filter( 'edd_settings_gateways', array( $this, 'edd_payflexi_settings' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array ( $this, 'edd_payflexi_plugin_action_links' ));
	}

    /**
	 * Adds an error message container
	 *
	 * @since 1.0.0
	 * @return void
	 */
    public function edd_payflexi_add_errors() {
        echo '<div id="edd-payflexi-payment-errors"></div>';
    }

    /**
	 * Add Gateway subsection
	 *
	 * @since 1.3.6
	 * @param array  $sections Gateway subsections
	 *
	 * @return array
	 */
	public function edd_payflexi_settings_section( $sections ) {
		$sections['payflexi-settings'] = __( 'PayFlexi Flexible Checkout', 'edd_payflexi' );

		return $sections;
	}


	/**
	 * Retrieve the API credentials
	 *
	 * @since 1.0.0
	 * @return array
	 */
    public function payflexi_edd_is_setup() {
        if ( edd_get_option( 'edd_payflexi_test_mode' ) ) {
            $secret_key = trim( edd_get_option( 'edd_payflexi_test_secret_key' ) );
            $public_key = trim( edd_get_option( 'edd_payflexi_test_public_key' ) );
        } else {
            $secret_key = trim( edd_get_option( 'edd_payflexi_live_secret_key' ) );
            $public_key = trim( edd_get_option( 'edd_payflexi_live_public_key' ) );
        }
        if ( empty( $public_key ) || empty( $secret_key ) ) {
            return false;
        }
        return true;
    }

    /**
	 * Register the gateway
	 *
	 * @since 1.0.0
	 * @return array
	 */
    public function register_payflexi_gateway($gateways) {
        if ($this->payflexi_edd_is_setup() ) {
            $gateways['payflexi'] = array(
                'admin_label'    => 'PayFlexi',
                'checkout_label' => 'PayFlexi Installment Payment',
            );
        }
        return $gateways;
    }

	/**
	 * Register the gateway settings
	 *
	 * @since 1.0.0
	 * @return array
	 */
    public function edd_payflexi_settings( $settings ) {

        $payflexi_settings = array(
            array(
                'id'   => 'edd_payflexi_settings',
                'name' => '<strong>PayFlexi Settings</strong>',
                'desc' => 'Configure the gateway settings',
                'type' => 'header',
            ),
            array(
                'id'   => 'edd_payflexi_test_mode',
                'name' => 'Enable Test Mode',
                'desc' => 'Test mode enables you to test payments before going live. Once the LIVE MODE is enabled on your PayFlexi Merchant Account uncheck this',
                'type' => 'checkbox',
                'std'  => 0,
            ),
            array(
                'id'   => 'edd_payflexi_test_secret_key',
                'name' => 'Test Secret Key',
                'desc' => 'Enter your Test Secret Key here',
                'type' => 'text',
                'size' => 'regular',
            ),
            array(
                'id'   => 'edd_payflexi_test_public_key',
                'name' => 'Test Public Key',
                'desc' => 'Enter your Test Public Key here',
                'type' => 'text',
                'size' => 'regular',
            ),
            array(
                'id'   => 'edd_payflexi_live_secret_key',
                'name' => 'Live Secret Key',
                'desc' => 'Enter your Live Secret Key here',
                'type' => 'text',
                'size' => 'regular',
            ),
            array(
                'id'   => 'edd_payflexi_live_public_key',
                'name' => 'Live Public Key',
                'desc' => 'Enter your Live Public Key here',
                'type' => 'text',
                'size' => 'regular',
            ),
            array(
                'id'   => 'edd_payflexi_webhook',
                'type' => 'descriptive_text',
                'name' => 'Webhook URL',
                'desc' => '<p><strong>Important:</strong> To avoid situations where bad network makes it impossible to verify transactions, set your webhook URL <a href="https://merchant.payflexi.co/settings/#api-keys-integrations" target="_blank">here</a> in your PayFlexi account to the URL below.</p>' . '<p><strong><pre>' . home_url( 'index.php?edd-listener=eddpayflexi' ) . '</pre></strong></p>',
            ),
        );
        if ( version_compare( EDD_VERSION, 2.5, '>=' ) ) {
            $payflexi_settings = array( 'payflexi-settings' => $payflexi_settings );
        }
        return array_merge( $settings, $payflexi_settings );
    }


	/**
	 * Pre purchase check for using PayFlexi
	 *
	 * @since 1.0.0
	 * @return array
	 */
    public function edd_payflexi_check_config() {
        $is_enabled = edd_is_gateway_active('payflexi');

        if ( ( ! $is_enabled || false === $this->payflexi_edd_is_setup() ) && 'payflexi' == edd_get_chosen_gateway() ) {
            edd_set_error( 'payflexi_gateway_not_configured', 'There is an error with the PayFlexi configuration.' );
        }

    }


    public function payflexi_edd_get_payment_link($payflexi_data) {

        $payflexi_url = 'https://api.payflexi.test/merchants/transactions';

        if ( edd_get_option( 'edd_payflexi_test_mode' ) ) {
            $secret_key = trim( edd_get_option( 'edd_payflexi_test_secret_key' ) );
        } else {
            $secret_key = trim( edd_get_option( 'edd_payflexi_live_secret_key' ) );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type' =>  'application/json',
            'Accept' =>  'application/json'
        );

        $callback_url = add_query_arg( 'edd-listener', 'payflexi', home_url( 'index.php' ) );

        $body = array(
            'amount'       => $payflexi_data['amount'],
            'email'        => $payflexi_data['email'],
            'reference'    => $payflexi_data['reference'],
            'currency'     => edd_get_currency(),
            'callback_url' => $callback_url,
            'domain'       => 'global',
            'meta' => [
                'title' => 'Test Payment',
            ]
        );

        $args = array(
            'body'    => json_encode($body),
            'headers' => $headers,
            'sslverify' => false,
            'timeout' => 60,
        );

        $request = wp_remote_post( $payflexi_url, $args );

        if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
            $payflexi_response = json_decode( wp_remote_retrieve_body( $request ) );
        } else {
            $payflexi_response = json_decode( wp_remote_retrieve_body( $request ) );
        }
        return $payflexi_response;
    }

    /**
	 * Process the purchase data and send to PayFlexi
	 *
	 * @since 1.0.0
	 * @return void
	 */
    public function process_payment( $purchase_data ) {

        $payment_data = array(
            'price'        => $purchase_data['price'],
            'date'         => $purchase_data['date'],
            'user_email'   => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency'     => edd_get_currency(),
            'downloads'    => $purchase_data['downloads'],
            'cart_details' => $purchase_data['cart_details'],
            'user_info'    => $purchase_data['user_info'],
            'status'       => 'pending',
            'gateway'      => 'payflexi',
        );

        ray(['EDD Payment Data' => $payment_data]);

        $payment = edd_insert_payment( $payment_data );

        if ( ! $payment ) {
            edd_record_gateway_error( 'Payment Error', sprintf( 'Payment creation failed before sending buyer to PayFlexi. Payment data: %s', json_encode( $payment_data ) ), $payment );
            edd_send_back_to_checkout( '?payment-mode=payflexi' );

        } else {

            $payflexi_data = array();

            $payflexi_data['amount']    = $purchase_data['price'];
            $payflexi_data['email']     = $purchase_data['user_email'];
            $payflexi_data['reference'] = 'EDD-' . $payment . '-' . uniqid();

            edd_set_payment_transaction_id( $payment, $payflexi_data['reference'] );

            $get_payment_response = $this->payflexi_edd_get_payment_link( $payflexi_data );
            
            ray($get_payment_response);

            if (!$get_payment_response->errors) {
                wp_redirect($get_payment_response->checkout_url);
                exit;
            } else {
                edd_record_gateway_error( 'Payment Error', $get_payment_response->message );
                edd_set_error( 'payflexi_error', 'Can\'t connect to the gateway, Please try again.' );
                edd_send_back_to_checkout( '?payment-mode=payflexi' );
            }
        }
    }

    /**
	 * Process webhooks & redirect sent from PayFlexi
	 *
	 * @since 1.0.0
	 * @return void
	 */
    public function edd_payflexi_redirect() {

        if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'payflexi' ) {
            do_action( 'payflexi_redirect_verify' );
        }

        if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'eddpayflexi' ) {
            do_action( 'payflexi_process_webhook' );
        }
    }



    public function payflexi_redirect_verify() {

        if ( isset( $_REQUEST['trxref'] ) ) {

            $transaction_id = $_REQUEST['trxref'];

            $the_payment_id = edd_get_purchase_id_by_transaction_id( $transaction_id );

            if ( $the_payment_id && get_post_status( $the_payment_id ) == 'publish' ) {

                edd_empty_cart();

                edd_send_to_success_page();
            }

            $paystack_txn = $this->payflexi_verify_transaction( $transaction_id );

            $order_info = explode( '-', $transaction_id );

            $payment_id = $order_info[1];

            if ( $payment_id && ! empty( $paystack_txn->data ) && ( $paystack_txn->data->status === 'success' ) ) {

                $payment = new EDD_Payment( $payment_id );

                $order_total = edd_get_payment_amount( $payment_id );

                $currency_symbol = edd_currency_symbol( $payment->currency );

                $amount_paid = $paystack_txn->data->amount / 100;

                $paystack_txn_ref = $paystack_txn->data->reference;

                if ( $amount_paid < $order_total ) {

                    $note = 'Look into this purchase. This order is currently revoked. Reason: Amount paid is less than the total order amount. Amount Paid was ' . $currency_symbol . $amount_paid . ' while the total order amount is ' . $currency_symbol . $order_total . '. Paystack Transaction Reference: ' . $paystack_txn_ref;

                    $payment->status = 'revoked';

                    $payment->add_note( $note );

                    $payment->transaction_id = $paystack_txn_ref;

                } else {

                    $note = 'Payment transaction was successful. Paystack Transaction Reference: ' . $paystack_txn_ref;

                    $payment->status = 'publish';

                    $payment->add_note( $note );

                    $payment->transaction_id = $paystack_txn_ref;

                }

                $payment->save();

                edd_empty_cart();

                edd_send_to_success_page();

            } else {

                edd_set_error( 'failed_payment', 'Payment failed. Please try again.' );

                edd_send_back_to_checkout( '?payment-mode=paystack' );

            }
        }

    }

    public function payflexi_process_webhook() {

        if ( ( strtoupper( $_SERVER['REQUEST_METHOD'] ) != 'POST' ) || ! array_key_exists( 'HTTP_X_PAYSTACK_SIGNATURE', $_SERVER ) ) {
            exit;
        }

        $json = file_get_contents( 'php://input' );

        if ( edd_get_option( 'edd_paystack_test_mode' ) ) {

            $secret_key = trim( edd_get_option( 'edd_paystack_test_secret_key' ) );

        } else {

            $secret_key = trim( edd_get_option( 'edd_paystack_live_secret_key' ) );

        }

        // validate event do all at once to avoid timing attack
        if ( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac( 'sha512', $json, $secret_key ) ) {
            exit;
        }

        $event = json_decode( $json );

        if ( 'charge.success' == $event->event ) {

            http_response_code( 200 );

            $transaction_id = $event->data->reference;

            $the_payment_id = edd_get_purchase_id_by_transaction_id( $transaction_id );

            if ( $the_payment_id && get_post_status( $the_payment_id ) == 'publish' ) {
                exit;
            }

            $order_info = explode( '-', $transaction_id );

            $payment_id = $order_info[1];

            $saved_txn_ref = edd_get_payment_transaction_id( $payment_id );

            if ( $event->data->reference != $saved_txn_ref ) {
                exit;
            }

            $payment = new EDD_Payment( $payment_id );

            $order_total = edd_get_payment_amount( $payment_id );

            $currency_symbol = edd_currency_symbol( $payment->currency );

            $amount_paid = $event->data->amount / 100;

            $paystack_txn_ref = $event->data->reference;

            if ( $amount_paid < $order_total ) {

                $note = 'Look into this purchase. This order is currently revoked. Reason: Amount paid is less than the total order amount. Amount Paid was ' . $currency_symbol . $amount_paid . ' while the total order amount is ' . $currency_symbol . $order_total . '. Paystack Transaction Reference: ' . $paystack_txn_ref;

                $payment->status = 'revoked';

                $payment->add_note( $note );

                $payment->transaction_id = $paystack_txn_ref;

            } else {

                $note = 'Payment transaction was successful. Paystack Transaction Reference: ' . $paystack_txn_ref;

                $payment->status = 'publish';

                $payment->add_note( $note );

                $payment->transaction_id = $paystack_txn_ref;

            }

            $payment->save();

            exit;
        }

        exit;
    }


    public function payflexi_verify_transaction( $payment_token ) {

        $paystack_url = 'https://api.paystack.co/transaction/verify/' . $payment_token;

        if ( edd_get_option( 'edd_paystack_test_mode' ) ) {

            $secret_key = trim( edd_get_option( 'edd_paystack_test_secret_key' ) );

        } else {

            $secret_key = trim( edd_get_option( 'edd_paystack_live_secret_key' ) );

        }

        $headers = array(
            'Authorization' => 'Bearer ' . $secret_key,
        );

        $args = array(
            'headers' => $headers,
            'timeout' => 60,
        );

        $request = wp_remote_get( $paystack_url, $args );

        if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {

            $paystack_response = json_decode( wp_remote_retrieve_body( $request ) );

        } else {

            $paystack_response = json_decode( wp_remote_retrieve_body( $request ) );

        }

        return $paystack_response;

    }


    public function edd_payflexi_testmode_notice() {
        if ( edd_get_option( 'edd_payflexi_test_mode' ) ) {
            ?>
            <div class="error">
                <p>PayFlexi testmode is still enabled for EDD, click <a href="<?php echo get_bloginfo( 'wpurl' ); ?>/wp-admin/edit.php?post_type=download&page=edd-settings&tab=gateways&section=payflexi-settings">here</a> to disable it when you want to start accepting live payment on your site.</p>
            </div>
            <?php
        }
    }
    
	/**
	 * Register the gateway icon
	 *
	 * @since 1.0.0
	 * @return array
	 */
    public function payment_icon( $icons ) {
        $icons[ EDD_PAYFLEXI_URL . 'assets/images/payflexi-wc.png' ] = 'PayFlexi';
        return $icons;
    }


    public function edd_payflexi_plugin_action_links( $links ) {
        $settings_link = array(
            'settings' => '<a href="' . admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=payflexi-settings' ) . '" title="Settings">Settings</a>',
        );
        return array_merge( $settings_link, $links );
    }

}

/**
 * Load our plugin
 *
 * @since 1.0.0
 * @return void
 */
function edd_payflexi_load() {
	$gateway = new EDD_Payflexi_Gateway;
	unset( $gateway );
}
add_action('plugins_loaded', 'edd_payflexi_load');