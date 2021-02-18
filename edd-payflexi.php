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
        add_action( 'init', array( $this, 'edd_payflexi_redirect'), 20);
        add_action( 'init', array( $this, 'payflexi_register_post_type_statuses' ), 10);
		add_action( 'edd_after_cc_fields', array( $this, 'edd_payflexi_add_errors' ), 999 );
        add_action( 'edd_pre_process_purchase', array($this, 'edd_payflexi_check_config'), 1);
        add_action( 'payflexi_redirect_verify', array( $this, 'payflexi_redirect_verify' ));
        add_action( 'payflexi_process_webhook', array( $this, 'payflexi_process_webhook' ) );
        add_action( 'admin_notices', array( $this, 'edd_payflexi_testmode_notice' ));
        add_action( 'edd_payments_table_do_bulk_action', array($this, 'payflexi_edd_bulk_status_action'), 10, 2 );
    

		add_filter( 'edd_payment_gateways', array( $this, 'register_payflexi_gateway' ) );
		add_filter( 'edd_accepted_payment_icons', array( $this, 'payment_icon' ) );
		add_filter( 'edd_settings_sections_gateways', array( $this, 'edd_payflexi_settings_section' ), 10, 1 );
		add_filter( 'edd_settings_gateways', array( $this, 'edd_payflexi_settings' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array ( $this, 'edd_payflexi_plugin_action_links' ));
        add_filter( 'edd_payment_statuses', array( $this, 'add_new_edd_payment_status' ));
        add_filter( 'edd_payments_table_bulk_actions', array( $this, 'payflexi_edd_bulk_status_dropdown' ) );
        add_filter( 'edd_payments_table_views', array( $this, 'payflexi_edd_payments_new_views' ));
        add_filter( 'edd_get_total_earnings_args', array( $this, 'earnings_query' ) );
		add_filter( 'edd_get_earnings_by_date_args', array( $this, 'earnings_query' ) );
		add_filter( 'edd_get_sales_by_date_args', array( $this, 'earnings_query' ) );
		add_filter( 'edd_stats_earnings_args', array( $this, 'earnings_query' ) );
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

        $callback_url = home_url() . '?' . http_build_query(
            [
                'edd-listener' => 'payflexi',
                'reference' => $payflexi_data['reference'],
            ]
        );

        $body = array(
            'name'         => $payflexi_data['name'],
            'amount'       => $payflexi_data['amount'],
            'email'        => $payflexi_data['email'],
            'reference'    => $payflexi_data['reference'],
            'currency'     => edd_get_currency(),
            'callback_url' => $callback_url,
            'domain'       => 'global',
            'meta' => [
                'title' => $payflexi_data['products'],
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

        $payment = edd_insert_payment( $payment_data );

        if ( ! $payment ) {
            edd_record_gateway_error( 'Payment Error', sprintf( 'Payment creation failed before sending buyer to PayFlexi. Payment data: %s', json_encode( $payment_data ) ), $payment );
            edd_send_back_to_checkout( '?payment-mode=payflexi' );

        } else {
            
            $products = '';
            
            foreach( $purchase_data['cart_details'] as $item_id => $item ) {
                $name  = $item['name'];
                $quantity = $item['quantity'];
                $products .= $name . ' (Qty: ' . $quantity . ')';
                $products .= ' | ';
            }

            $products = rtrim( $products, ' | ' );

            $payflexi_data = array();

            $payflexi_data['name']      = $purchase_data['user_info']['first_name'] ? $purchase_data['user_info']['first_name'] : '';
            $payflexi_data['amount']    = $purchase_data['price'];
            $payflexi_data['email']     = $purchase_data['user_email'];
            $payflexi_data['reference'] = 'EDD-' . $payment . '-' . uniqid();
            $payflexi_data['products']   = $products;

            edd_set_payment_transaction_id( $payment, $payflexi_data['reference'] );

            $get_payment_response = $this->payflexi_edd_get_payment_link( $payflexi_data );

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
        if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'payflexi' && isset($_GET['pf_cancelled']) ) {
            edd_set_error( 'payflexi_payment_cancelled', __( 'The Transaction was cancelled by the customer', 'payflexi' ) );
            edd_send_back_to_checkout( '?payment-mode=payflexi' );
        }

        if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'payflexi' && isset($_GET['pf_declined']) ) {
            edd_set_error( 'payflexi_payment_declined', __( 'The Transaction was declined by the payment gateway', 'payflexi' ) );
            edd_send_back_to_checkout( '?payment-mode=payflexi' );
        }

        if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'payflexi' && isset($_GET['pf_approved']) ) {
            do_action( 'payflexi_redirect_verify' );
        }

        if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'eddpayflexi' ) {
            do_action( 'payflexi_process_webhook' );
        }
    }

    /**
	 * Process approved transaction redirect from PayFlexi
	 *
	 * @since 1.0.0
	 * @return void
	 */
    public function payflexi_redirect_verify() {

        if ( isset( $_REQUEST['reference'] ) ) {

            $payment_reference = $_GET['reference'];

            $the_payment_id = edd_get_purchase_id_by_transaction_id( $payment_reference );

            if ( $the_payment_id && get_post_status( $the_payment_id ) == 'publish' ) {
                edd_empty_cart();
                edd_send_to_success_page();
            }

            $payflexi_transaction = $this->payflexi_verify_transaction($payment_reference);

            $order_info = explode( '-', $payment_reference );

            $payment_id = $order_info[1];

            if ( $payment_id && !$payflexi_transaction->errors && ! empty( $payflexi_transaction->data )) {

                $payment = new EDD_Payment( $payment_id );

                $order_total = edd_get_payment_amount( $payment_id );

                $currency_symbol = edd_currency_symbol( $payment->currency );

                $order_amount = $payflexi_transaction->data->amount ? $payflexi_transaction->data->amount : 0;

                $amount_paid = $payflexi_transaction->data->txn_amount ? $payflexi_transaction->data->txn_amount : 0;

                $payflexi_txn_ref = $payflexi_transaction->data->reference;

                if ( $amount_paid < $order_amount ) {
                    add_post_meta( $payment_id, '_edd_payflexi_transaction_id', $payflexi_txn_ref, true );
                    update_post_meta( $payment_id, '_edd_payflexi_order_amount', $order_amount);
                    update_post_meta( $payment_id, '_edd_payflexi_installment_amount_paid', $amount_paid);
                    $note = 'This order is currently was partially paid with ' . $currency_symbol . $amount_paid . ' PayFlexi Transaction Reference: ' . $payflexi_txn_ref;
                    $payment->status = 'partial_payment';
                    $payment->total = $amount_paid;
                    $payment->add_note( $note );
                    $payment->transaction_id = $payflexi_txn_ref;
                } else {
                    $note = 'Payment transaction was successful. PayFlexi Transaction Reference: ' . $payflexi_txn_ref;
                    $payment->status = 'publish';
                    $payment->add_note( $note );
                    $payment->transaction_id = $payflexi_txn_ref;
                }
                $payment->save();
                edd_empty_cart();
                edd_send_to_success_page();
            } else {
                edd_set_error( 'failed_payment', 'Payment failed. Please try again.' );
                edd_send_back_to_checkout( '?payment-mode=payflexi' );
            }
        }
    }
    
    /**
	 * Verify approved transaction from PayFlexi API
	 *
	 * @since 1.0.0
	 * @return void
	 */
    public function payflexi_verify_transaction($payment_reference) {

        $payflexi_url = 'https://api.payflexi.test/merchants/transactions/' . $payment_reference;

        if ( edd_get_option( 'edd_payflexi_test_mode' ) ) {
            $secret_key = trim( edd_get_option( 'edd_payflexi_test_secret_key' ) );
        } else {
            $secret_key = trim( edd_get_option( 'edd_payflexi_live_secret_key' ) );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $secret_key,
        );

        $args = array(
            'sslverify' => false, //Set to true on production
            'headers' => $headers,
            'timeout' => 60,
        );

        $request = wp_remote_get( $payflexi_url, $args );

        if ( ! is_wp_error( $request ) && 200 === wp_remote_retrieve_response_code( $request ) ) {
            $payflexi_response = json_decode( wp_remote_retrieve_body( $request ) );
        } else {
            $payflexi_response = json_decode( wp_remote_retrieve_body( $request ) );

        }

        return $payflexi_response;
    }

    /**
	 * Process webhook event
	 *
	 * @since 1.0.0
	 * @return array
	 */
    public function payflexi_process_webhook() {

        if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') || ! array_key_exists('HTTP_X_PAYFLEXI_SIGNATURE', $_SERVER)) {
            exit;
        }

        //Retrieve the request's body and parse it as JSON.
		$body  = @file_get_contents( 'php://input' );

        if ( edd_get_option( 'edd_payflexi_test_mode' ) ) {
            $secret_key = trim( edd_get_option( 'edd_payflexi_test_secret_key' ) );
        } else {
            $secret_key = trim( edd_get_option( 'edd_payflexi_live_secret_key' ) );
        }

        if ($_SERVER['HTTP_X_PAYFLEXI_SIGNATURE'] !== hash_hmac('sha512', $body, $secret_key)) {
            exit;
        }

        $event = json_decode( $body );

        if ('transaction.approved' == $event->event && 'approved' == $event->data->status) {

            http_response_code( 200 );

            $reference = $event->data->reference;
			$initial_reference = $event->data->initial_reference;

            $the_payment_id = edd_get_purchase_id_by_transaction_id( $initial_reference);

            if ( $the_payment_id && get_post_status( $the_payment_id ) == 'publish' ) {
                exit;
            }

            $order_info = explode( '-', $initial_reference );

            $payment_id = $order_info[1];

            $saved_txn_ref = edd_get_payment_transaction_id( $payment_id );

            $payment = new EDD_Payment( $payment_id );


            $order_total = edd_get_payment_amount( $payment_id );

            $currency_symbol = edd_currency_symbol( $payment->currency );

            $order_amount = get_post_meta($payment_id, '_edd_payflexi_order_amount', true);

            $order_amount  = $order_amount ? $order_amount : $event->data->amount;

            $amount_paid  = $event->data->txn_amount ? $event->data->txn_amount : 0;

            $payflexi_txn_ref = $event->data->reference;

            if ( $amount_paid < $order_amount ) {
                if($reference === $initial_reference && empty($saved_txn_ref)){
                    update_post_meta( $payment_id, '_edd_payflexi_transaction_id', $initial_reference, true );
                    update_post_meta( $payment_id, '_edd_payflexi_order_amount', $order_amount);
                    update_post_meta( $payment_id, '_edd_payflexi_installment_amount_paid', $amount_paid);
                    $note = 'This order is currently was partially paid with ' . $currency_symbol . $amount_paid . ' PayFlexi Transaction Reference: ' . $payflexi_txn_ref;
                    $payment->status = 'partial_payment';
                    $payment->total = $amount_paid;
                    $payment->add_note( $note );
                    $payment->transaction_id = $payflexi_txn_ref;
                }
                if($reference !== $initial_reference){
                    $installment_amount_paid = get_post_meta($payment_id, '_edd_payflexi_installment_amount_paid', true);
                    $total_installment_amount_paid = $installment_amount_paid + $amount_paid;
                    if($total_installment_amount_paid >= $order_amount){
                        update_post_meta($payment_id, '_edd_payflexi_installment_amount_paid', $total_installment_amount_paid);
                        $note = 'The last partial payment of ' . $currency_symbol . $amount_paid . ' PayFlexi Transaction Reference: ' . $payflexi_txn_ref;
                        $payment->status = 'publish';
                        $payment->total = $order_amount;
                        $payment->add_note( $note );
                        $payment->transaction_id = $payflexi_txn_ref;
                    }else{
                        update_post_meta( $payment_id, '_edd_payflexi_transaction_id', $payflexi_txn_ref);
                        update_post_meta( $payment_id, '_edd_payflexi_installment_amount_paid', $total_installment_amount_paid);
                        $note = 'This order is currently was partially paid with ' . $currency_symbol . $amount_paid . ' PayFlexi Transaction Reference: ' . $payflexi_txn_ref;
                        $payment->status = 'partial_payment';
                        $payment->total = $total_installment_amount_paid;
                        $payment->add_note( $note );
                        $payment->transaction_id = $payflexi_txn_ref;
                    }
                }
            } else {

                $note = 'Payment transaction was successful. PayFlexi Transaction Reference: ' . $payflexi_txn_ref;

                $payment->status = 'publish';

                $payment->add_note( $note );

                $payment->transaction_id = $payflexi_txn_ref;

            }

            $payment->save();

            exit;
        }

        exit;
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

    /**
	 * Register a new payment status
	 *
	 * @since 1.0.0
	 * @return array
	 */
    public function add_new_edd_payment_status( $payment_statuses ) {

        $payment_statuses['partial_payment']   = 'Partially Paid';

        return $payment_statuses;   
    }

    /**
    * Adds bulk actions to the bulk action dropdown on "Payment History" screen
    */
    public function payflexi_edd_bulk_status_dropdown( $actions ) {

        $new_bulk_status_actions = array();
        
        // Loop through existing bulk actions
        foreach ( $actions as $key => $action ) {
        
        $new_bulk_status_actions[ $key ] = $action;
            // Add our actions after the "Set To Cancelled" action
            if ('set-status-cancelled' === $key ) {
                $new_bulk_status_actions['set-status-partial-payment'] = 'Set To Partially Paid';
            }
        }
        return $new_bulk_status_actions;
    }

    /**
    * Adds bulk actions to update orders when performed
    */
    public function payflexi_edd_bulk_status_action( $id, $action ) {
 
        if ('set-status-partial-payment' === $action ) {
            edd_update_payment_status( $id, 'partial_payment' );
        }
        
    }

    /**
    * Adds our custom statuses to earnings and sales reports
    */
    public function earnings_query( $args ) {

        $statuses_to_include = array( 'publish', 'revoked', 'partial_payment');

		// Include post_status in case we are filtering to direct database queries like in the edd_stats_earnings_args filter
		$args['post_status'] = $statuses_to_include;

		// Include status in case we are filtering to queries done through edd_get_payments like in the edd_get_total_earnings_args filter
		$args['status'] = $statuses_to_include;

		return $args;
    }

    /**
    * Registers our new statuses as post statuses so we can use them in Payment History navigation
    */
    public function payflexi_register_post_type_statuses() {

        //Payment Statuses
        register_post_status('partial_payment', array(
            'label'                     => _x( 'Partially Paid', 'Partially paid payment status' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Partially Paid <span class="count">(%s)</span>', 'Partially Paid <span class="count">(%s)</span>')
        ));
 
    }

    /**
    * Adds our new payment statuses to the Payment History navigation
    */
    public function payflexi_edd_payments_new_views( $views ) {
        $payment_count        = wp_count_posts('edd_payment');
	    $partial_payment_count    = '&nbsp;<span class="count">(' . $payment_count->partial_payment . ')</span>';
        $current              = isset( $_GET['status'] ) ? $_GET['status'] : '';
        $views['partial_payment'] = sprintf( '<a href="%s"%s>%s</a>', esc_url( add_query_arg( 'status', 'partial_payment', admin_url( 'edit.php?post_type=download&page=edd-payment-history' ) ) ), $current === 'partial_payment' ? ' class="current"' : '', __( 'Parially Paid', 'edd-payflexi' ) . $partial_payment_count );
    
        return $views;
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