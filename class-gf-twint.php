<?php

defined( 'ABSPATH' ) || die();

add_action( 'wp', array( 'GFTWINT', 'maybe_thankyou_page' ), 5 );

GFForms::include_payment_addon_framework();

class GFTWINT extends GFPaymentAddOn {

	protected $_version = GF_TWINT_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	protected $_slug = 'gravityformstwint';
	protected $_path = 'gravityformstwint/twint.php';
	protected $_full_path = __FILE__;
	protected $_url = '#';
	protected $_title = 'Gravity Forms TWINT Add-On';
	protected $_short_title = 'TWINT';
	protected $_supports_callbacks = true;
	private $production_url = 'https://www.paypal.com/cgi-bin/webscr/';
	private $sandbox_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr/';

	// Members plugin integration
	protected $_capabilities = array( 'gravityforms_twint', 'gravityforms_twint_uninstall' );

	// Permissions
	protected $_capabilities_settings_page = 'gravityforms_twint';
	protected $_capabilities_form_settings = 'gravityforms_twint';
	protected $_capabilities_uninstall = 'gravityforms_twint_uninstall';

	// Automatic upgrade enabled
	protected $_enable_rg_autoupgrade = true;

	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFTWINT();
		}

		return self::$_instance;
	}

	private function __clone() {
	} /* do nothing */

	public function init_frontend() {
		parent::init_frontend();

		add_filter( 'gform_disable_post_creation', array( $this, 'delay_post' ), 10, 3 );
		add_filter( 'gform_disable_notification', array( $this, 'delay_notification' ), 10, 4 );
	}

	/**
	 * Returns what should be used to prepare the payment amount; form_total or the ID of a specific product field.
	 *
	 * @since 3.3
	 *
	 * @param array $feed The current feed.
	 *
	 * @return string
	 */
	public function get_payment_field( $feed ) {
		switch ( rgars( $feed, 'meta/transactionType' ) ) {
			case 'subscription':
				$key = 'recurringAmount';
				break;

			case 'product':
			case 'donation':
				$key = 'paymentAmount';
				break;
		}

		return rgars( $feed, 'meta/' . $key, 'form_total' );
	}

	//----- SETTINGS PAGES ----------//

	/**
	 * Return the plugin's icon for the plugin/form settings menu.
	 *
	 * @since 3.2
	 *
	 * @return string
	 * 
	 * empty
	 */
	
	 // ----------------------------- //




	//------ SENDING TO TWINT -----------//

	public function redirect_url( $feed, $submission_data, $form, $entry ) {

		//Don't process redirect url if request is a TWINT return
		if ( ! rgempty( 'gf_twint_return', $_GET ) ) {
			return false;
		}

		//updating lead's payment_status to Processing
		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );

		//Getting Url (Production or Sandbox)
		$url = $feed['meta']['mode'] == 'production' ? $this->production_url : $this->sandbox_url;

		$invoice_id = apply_filters( 'gform_twint_invoice', '', $form, $entry );

		$invoice = empty( $invoice_id ) ? '' : "&invoice={$invoice_id}";

		//Current Currency
		$currency = rgar( $entry, 'currency' );

		//Customer fields
		$customer_fields = $this->customer_query_string( $feed, $entry );

		//Image URL 
		$image_url = ! empty( $feed['meta']['imageURL'] ) ? '&image_url=' . urlencode( $feed['meta']['imageURL'] ) : '';

		//Set return mode to 2 (PayPal will post info back to page). rm=1 seems to create lots of problems with the redirect back to the site. Defaulting it to 2.
		$return_mode = '2';

		$return_url = '&return=' . urlencode( $this->return_url( $form['id'], $entry['id'] ) ) . "&rm={$return_mode}";

		//Cancel URL
		$cancel_url = ! empty( $feed['meta']['cancelUrl'] ) ? '&cancel_return=' . urlencode( $feed['meta']['cancelUrl'] ) : '';

		//Don't display note section
		$disable_note = ! empty( $feed['meta']['disableNote'] ) ? '&no_note=1' : '';

		//Don't display shipping section
		$disable_shipping = ! empty( $feed['meta']['disableShipping'] ) ? '&no_shipping=1' : '';

		//URL that will listen to notifications from PayPal
		$ipn_url = urlencode( $this->get_callback_url() );

		$business_email = urlencode( '1' ); // spacer
		$custom_field   = $entry['id'] . '|' . wp_hash( $entry['id'] );

		$url .= "?notify_url={$ipn_url}&charset=UTF-8&currency_code={$currency}&business={$business_email}&custom={$custom_field}{$invoice}{$customer_fields}{$image_url}{$cancel_url}{$disable_note}{$disable_shipping}{$return_url}";
		$query_string = '';

		switch ( $feed['meta']['transactionType'] ) {
			case 'product' :
				//build query string using $submission_data
				$query_string = $this->get_product_query_string( $submission_data, $entry['id'] );
				break;

			case 'donation' :
				$query_string = $this->get_donation_query_string( $submission_data, $entry['id'] );
				break;

			case 'subscription' :
				$query_string = $this->get_subscription_query_string( $feed, $submission_data, $entry['id'] );
				break;
		}

		$query_string = gf_apply_filters( 'gform_twint_query', $form['id'], $query_string, $form, $entry, $feed, $submission_data );

		if ( ! $query_string ) {
			$this->log_debug( __METHOD__ . '(): NOT sending to TWINT: The price is either zero or the gform_twint_query filter was used to remove the querystring that is sent to TWINT.' );

			return '';
		}

		$url .= $query_string;

		$url = gf_apply_filters( 'gform_twint_request', $form['id'], $url, $form, $entry, $feed, $submission_data );
		
		

		$this->log_debug( __METHOD__ . "(): Sending to TWINT: {$url}" );

		return $url;
	}

	public function get_product_query_string( $submission_data, $entry_id ) {

		if ( empty( $submission_data ) ) {
			return false;
		}

		$query_string   = '';
		$payment_amount = rgar( $submission_data, 'payment_amount' );
		$setup_fee      = rgar( $submission_data, 'setup_fee' );
		$trial_amount   = rgar( $submission_data, 'trial' );
		$line_items     = rgar( $submission_data, 'line_items' );
		$discounts      = rgar( $submission_data, 'discounts' );

		$product_index = 1;
		$shipping      = '';
		$discount_amt  = 0;
		$cmd           = '_cart';
		$extra_qs      = '&upload=1';

		//work on products
		if ( is_array( $line_items ) ) {
			foreach ( $line_items as $item ) {
				$product_name = urlencode( $item['name'] );
				$quantity     = $item['quantity'];
				$unit_price   = $item['unit_price'];
				$options      = rgar( $item, 'options' );
				$product_id   = $item['id'];
				$is_shipping  = rgar( $item, 'is_shipping' );

				if ( $is_shipping ) {
					//populate shipping info
					$shipping .= ! empty( $unit_price ) ? "&shipping_1={$unit_price}" : '';
				} else {
					//add product info to querystring
					$query_string .= "&item_name_{$product_index}={$product_name}&amount_{$product_index}={$unit_price}&quantity_{$product_index}={$quantity}";
				}
				//add options
				if ( ! empty( $options ) ) {
					if ( is_array( $options ) ) {
						$option_index = 1;
						foreach ( $options as $option ) {
							// Trim option label to prevent PayPal displaying an error instead of the cart.
							$option_label = urlencode( substr( $option['field_label'], 0, 64 ) );
							$option_name  = urlencode( $option['option_name'] );
							$query_string .= "&on{$option_index}_{$product_index}={$option_label}&os{$option_index}_{$product_index}={$option_name}";
							$option_index ++;
						}
					}
				}
				$product_index ++;
			}
		}

		//look for discounts
		if ( is_array( $discounts ) ) {
			foreach ( $discounts as $discount ) {
				$discount_full = abs( $discount['unit_price'] ) * $discount['quantity'];
				$discount_amt += $discount_full;
			}
			if ( $discount_amt > 0 ) {
				$query_string .= "&discount_amount_cart={$discount_amt}";
			}
		}

		$query_string .= "{$shipping}&cmd={$cmd}{$extra_qs}";
		
		//save payment amount to lead meta
		gform_update_meta( $entry_id, 'payment_amount', $payment_amount );

		return $payment_amount > 0 ? $query_string : false;

	}

	public function get_donation_query_string( $submission_data, $entry_id ) {
		if ( empty( $submission_data ) ) {
			return false;
		}

		$query_string   = '';
		$payment_amount = rgar( $submission_data, 'payment_amount' );
		$line_items     = rgar( $submission_data, 'line_items' );
		$purpose        = '';
		$cmd            = '_donations';

		//work on products
		if ( is_array( $line_items ) ) {
			foreach ( $line_items as $item ) {
				$product_name    = $item['name'];
				$quantity        = $item['quantity'];
				$quantity_label  = $quantity > 1 ? $quantity . ' ' : '';
				$options         = rgar( $item, 'options' );
				$is_shipping     = rgar( $item, 'is_shipping' );
				$product_options = '';

				if ( ! $is_shipping ) {
					//add options
					if ( ! empty( $options ) ) {
						if ( is_array( $options ) ) {
							$product_options = ' (';
							foreach ( $options as $option ) {
								$product_options .= $option['option_name'] . ', ';
							}
							$product_options = substr( $product_options, 0, strlen( $product_options ) - 2 ) . ')';
						}
					}
					$purpose .= $quantity_label . $product_name . $product_options . ', ';
				}
			}
		}

		if ( ! empty( $purpose ) ) {
			$purpose = substr( $purpose, 0, strlen( $purpose ) - 2 );
		}

		$purpose = urlencode( $purpose );

		//truncating to maximum length allowed by PayPal
		if ( strlen( $purpose ) > 127 ) {
			$purpose = substr( $purpose, 0, 124 ) . '...';
		}

		$query_string = "&amount={$payment_amount}&item_name={$purpose}&cmd={$cmd}";
		
		//save payment amount to lead meta
		gform_update_meta( $entry_id, 'payment_amount', $payment_amount );

		return $payment_amount > 0 ? $query_string : false;

	}

	public function get_subscription_query_string( $feed, $submission_data, $entry_id ) {

		if ( empty( $submission_data ) ) {
			return false;
		}

		$query_string         = '';
		$payment_amount       = rgar( $submission_data, 'payment_amount' );
		$setup_fee            = rgar( $submission_data, 'setup_fee' );
		$trial_enabled        = rgar( $feed['meta'], 'trial_enabled' );
		$line_items           = rgar( $submission_data, 'line_items' );
		$discounts            = rgar( $submission_data, 'discounts' );
		$recurring_field      = rgar( $submission_data, 'payment_amount' ); //will be field id or the text 'form_total'
		$product_index        = 1;
		$shipping             = '';
		$discount_amt         = 0;
		$cmd                  = '_xclick-subscriptions';
		$extra_qs             = '';
		$name_without_options = '';
		$item_name            = '';

		//work on products
		if ( is_array( $line_items ) ) {
			foreach ( $line_items as $item ) {
				$product_id     = $item['id'];
				$product_name   = $item['name'];
				$quantity       = $item['quantity'];
				$quantity_label = $quantity > 1 ? $quantity . ' ' : '';

				$unit_price  = $item['unit_price'];
				$options     = rgar( $item, 'options' );
				$product_id  = $item['id'];
				$is_shipping = rgar( $item, 'is_shipping' );

				$product_options = '';
				if ( ! $is_shipping ) {
					//add options

					if ( ! empty( $options ) && is_array( $options ) ) {
						$product_options = ' (';
						foreach ( $options as $option ) {
							$product_options .= $option['option_name'] . ', ';
						}
						$product_options = substr( $product_options, 0, strlen( $product_options ) - 2 ) . ')';
					}

					$item_name .= $quantity_label . $product_name . $product_options . ', ';
					$name_without_options .= $product_name . ', ';
				}
			}

			//look for discounts to pass in the item_name
			if ( is_array( $discounts ) ) {
				foreach ( $discounts as $discount ) {
					$product_name   = $discount['name'];
					$quantity       = $discount['quantity'];
					$quantity_label = $quantity > 1 ? $quantity . ' ' : '';
					$item_name .= $quantity_label . $product_name . ' (), ';
					$name_without_options .= $product_name . ', ';
				}
			}

			if ( ! empty( $item_name ) ) {
				$item_name = substr( $item_name, 0, strlen( $item_name ) - 2 );
			}

			//if name is larger than max, remove options from it.
			if ( strlen( $item_name ) > 127 ) {
				$item_name = substr( $name_without_options, 0, strlen( $name_without_options ) - 2 );

				//truncating name to maximum allowed size
				if ( strlen( $item_name ) > 127 ) {
					$item_name = substr( $item_name, 0, 124 ) . '...';
				}
			}
			$item_name = urlencode( $item_name );

		}

		

		//check for recurring times
		$recurring_times = rgar( $feed['meta'], 'recurringTimes' ) ? '&srt=' . rgar( $feed['meta'], 'recurringTimes' ) : '';
		$recurring_retry = rgar( $feed['meta'], 'recurringRetry' ) ? '1' : '0';

		$billing_cycle_number = rgar( $feed['meta'], 'billingCycle_length' );
		$billing_cycle_type   = $this->convert_interval( rgar( $feed['meta'], 'billingCycle_unit' ), 'char' );

		$query_string = "&cmd={$cmd}&item_name={$item_name}{$trial}&a3={$payment_amount}&p3={$billing_cycle_number}&t3={$billing_cycle_type}&src=1&sra={$recurring_retry}{$recurring_times}";

		//save payment amount to lead meta
		gform_update_meta( $entry_id, 'payment_amount', $payment_amount );
		
		return $payment_amount > 0 ? $query_string : false;

	}

	public function customer_query_string( $feed, $entry ) {
		$fields = '';
		foreach ( $this->get_customer_fields() as $field ) {
			$field_id = $feed['meta'][ $field['meta_name'] ];
			$value    = rgar( $entry, $field_id );

			if ( $field['name'] == 'country' ) {
				$value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_country_code( $value ) : GFCommon::get_country_code( $value );
			} elseif ( $field['name'] == 'state' ) {
				$value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_us_state_code( $value ) : GFCommon::get_us_state_code( $value );
			}

			if ( ! empty( $value ) ) {
				$fields .= "&{$field['name']}=" . urlencode( $value );
			}
		}

		return $fields;
	}

	public function return_url( $form_id, $lead_id ) {
		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port = apply_filters( 'gform_twint_return_url_port', $_SERVER['SERVER_PORT'] );

		if ( $server_port != '80' ) {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		$ids_query = "ids={$form_id}|{$lead_id}";
		$ids_query .= '&hash=' . wp_hash( $ids_query );

		$url = add_query_arg( 'gf_twint_return', base64_encode( $ids_query ), $pageURL );

		$query = 'gf_twint_return=' . base64_encode( $ids_query );
		/**
		 * Filters TWINT's return URL, which is the URL that users will be sent to after completing the payment on TWINT's site.
		 * Useful when URL isn't created correctly (could happen on some server configurations using PROXY servers).
		 *
		 * @since 2.4.5
		 *
		 * @param string  $url 	The URL to be filtered.
		 * @param int $form_id	The ID of the form being submitted.
		 * @param int $entry_id	The ID of the entry that was just created.
		 * @param string $query	The query string portion of the URL.
		 */
		return apply_filters( 'gform_twint_return_url', $url, $form_id, $lead_id, $query  );

	}

	public static function maybe_thankyou_page() {
		$instance = self::get_instance();

		if ( ! $instance->is_gravityforms_supported() ) {
			return;
		}

		if ( $str = rgget( 'gf_twint_return' ) ) {
			$str = base64_decode( $str );

			parse_str( $str, $query );
			if ( wp_hash( 'ids=' . $query['ids'] ) == $query['hash'] ) {
				list( $form_id, $lead_id ) = explode( '|', $query['ids'] );

				$form = GFAPI::get_form( $form_id );
				$lead = GFAPI::get_entry( $lead_id );

				if ( ! class_exists( 'GFFormDisplay' ) ) {
					require_once( GFCommon::get_base_path() . '/form_display.php' );
				}

				$confirmation = GFFormDisplay::handle_confirmation( $form, $lead, false );

				if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
					header( "Location: {$confirmation['redirect']}" );
					exit;
				}

				GFFormDisplay::$submission[ $form_id ] = array( 'is_confirmation' => true, 'confirmation_message' => $confirmation, 'form' => $form, 'lead' => $lead );
			}
		}
	}

	public function get_customer_fields() {
		return array(
			array( 'name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName' ),
			array( 'name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName' ),
			array( 'name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email' ),
			array( 'name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address' ),
			array( 'name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2' ),
			array( 'name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city' ),
			array( 'name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state' ),
			array( 'name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip' ),
			array( 'name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country' ),
		);
	}

	public function convert_interval( $interval, $to_type ) {
		//convert single character into long text for new feed settings or convert long text into single character for sending to paypal
		//$to_type: text (change character to long text), OR char (change long text to character)
		if ( empty( $interval ) ) {
			return '';
		}

		$new_interval = '';
		if ( $to_type == 'text' ) {
			//convert single char to text
			switch ( strtoupper( $interval ) ) {
				case 'D' :
					$new_interval = 'day';
					break;
				case 'W' :
					$new_interval = 'week';
					break;
				case 'M' :
					$new_interval = 'month';
					break;
				case 'Y' :
					$new_interval = 'year';
					break;
				default :
					$new_interval = $interval;
					break;
			}
		} else {
			//convert text to single char
			switch ( strtolower( $interval ) ) {
				case 'day' :
					$new_interval = 'D';
					break;
				case 'week' :
					$new_interval = 'W';
					break;
				case 'month' :
					$new_interval = 'M';
					break;
				case 'year' :
					$new_interval = 'Y';
					break;
				default :
					$new_interval = $interval;
					break;
			}
		}

		return $new_interval;
	}

	public function delay_post( $is_disabled, $form, $entry ) {
		if ( ! $this->is_payment_gateway ) {
			return $is_disabled;
		}

		$feed = $this->current_feed;

		return ! rgempty( 'delayPost', $feed['meta'] );
	}

	public function delay_notification( $is_disabled, $notification, $form, $entry ) {
		if ( ! $this->is_payment_gateway || rgar( $notification, 'event' ) != 'form_submission' ) {
			return $is_disabled;
		}

		$feed                   = $this->current_feed;
		$selected_notifications = is_array( rgar( $feed['meta'], 'selectedNotifications' ) ) ? rgar( $feed['meta'], 'selectedNotifications' ) : array();

		return isset( $feed['meta']['delayNotification'] ) && in_array( $notification['id'], $selected_notifications ) ? true : $is_disabled;
	}


	//------- PROCESSING TWINT (Callback) -----------//

	public function callback() {

		if ( ! $this->is_gravityforms_supported() ) {
			return false;
		}

		$this->log_debug( __METHOD__ . '(): TWINT request received. Starting to process => ' . print_r( $_POST, true ) );

		// Valid TWINT requests must have a custom field
		$custom_field = rgpost( 'custom' );
		if ( empty( $custom_field ) ) {
			$this->log_error( __METHOD__ . '(): TWINT request does not have a custom field, so it was not created by Gravity Forms. Aborting.' );

			return false;
		}


		//------- Send request to paypal and verify it has not been spoofed ---------------------//
		$is_verified = $this->verify_twint();
		if ( is_wp_error( $is_verified ) ) {
			$this->log_error( __METHOD__ . '(): TWINT verification failed with an error. Aborting with a 500 error so that IPN is resent.' );

			return new WP_Error( 'IPNVerificationError', 'There was an error when verifying the IPN message with TWINT', array( 'status_header' => 500 ) );
		} elseif ( ! $is_verified ) {
			$this->log_error( __METHOD__ . '(): IPN request could not be verified by TWINT. Aborting.' );

			return false;
		}

		$this->log_debug( __METHOD__ . '(): IPN message successfully verified by TWINT' );


		//------ Getting entry related to this IPN ----------------------------------------------//
		$entry = $this->get_entry( $custom_field );

		//Ignore orphan IPN messages (ones without an entry)
		if ( ! $entry ) {
			$this->log_error( __METHOD__ . '(): Entry could not be found. Aborting.' );

			return false;
		}
		$this->log_debug( __METHOD__ . '(): Entry has been found => ' . print_r( $entry, true ) );

		if ( $entry['status'] == 'spam' ) {
			$this->log_error( __METHOD__ . '(): Entry is marked as spam. Aborting.' );

			return false;
		}


		//------ Getting feed related to this IPN ------------------------------------------//
		$feed = $this->get_payment_feed( $entry );

		//Ignore IPN messages from forms that are no longer configured with the PayPal add-on
		if ( ! $feed || ! rgar( $feed, 'is_active' ) ) {
			$this->log_error( __METHOD__ . "(): Form no longer is configured with TWINT Addon. Form ID: {$entry['form_id']}. Aborting." );

			return false;
		}
		$this->log_debug( __METHOD__ . "(): Form {$entry['form_id']} is properly configured." );


		//----- Making sure this IPN can be processed -------------------------------------//
		if ( ! $this->can_process_ipn( $feed, $entry ) ) {
			$this->log_debug( __METHOD__ . '(): TWINT cannot be processed.' );

			return false;
		}


		//----- Processing IPN ------------------------------------------------------------//
		$this->log_debug( __METHOD__ . '(): Processing TWINT...' );
		$action = $this->process_ipn( $feed, $entry, rgpost( 'payment_status' ), rgpost( 'txn_type' ), rgpost( 'txn_id' ), rgpost( 'parent_txn_id' ), rgpost( 'subscr_id' ), rgpost( 'mc_gross' ), rgpost( 'pending_reason' ), rgpost( 'reason_code' ), rgpost( 'mc_amount3' ) );
		$this->log_debug( __METHOD__ . '(): TWINT processing complete.' );

		if ( rgempty( 'entry_id', $action ) ) {
			return false;
		}

		return $action;

	}

	public function get_payment_feed( $entry, $form = false ) {

		$feed = parent::get_payment_feed( $entry, $form );

		if ( empty( $feed ) && ! empty( $entry['id'] ) ) {
			//looking for feed created by legacy versions
			$feed = $this->get_twint_feed_by_entry( $entry['id'] );
		}

		$feed = apply_filters( 'gform_twint_get_payment_feed', $feed, $entry, $form ? $form : GFAPI::get_form( $entry['form_id'] ) );

		return $feed;
	}

	private function get_twint_feed_by_entry( $entry_id ) {

		$feed_id = gform_get_meta( $entry_id, 'twint_feed_id' );
		$feed    = $this->get_feed( $feed_id );

		return ! empty( $feed ) ? $feed : false;
	}

	public function post_callback( $callback_action, $callback_result ) {
		if ( is_wp_error( $callback_action ) || ! $callback_action ) {
			return false;
		}

		//run the necessary hooks
		$entry          = GFAPI::get_entry( $callback_action['entry_id'] );
		$feed           = $this->get_payment_feed( $entry );
		$transaction_id = rgar( $callback_action, 'transaction_id' );
		$amount         = rgar( $callback_action, 'amount' );
		$subscriber_id  = rgar( $callback_action, 'subscriber_id' );
		$pending_reason = rgpost( 'pending_reason' );
		$reason         = rgpost( 'reason_code' );
		$status         = rgpost( 'payment_status' );
		$txn_type       = rgpost( 'txn_type' );
		$parent_txn_id  = rgpost( 'parent_txn_id' );

		//run gform_paypal_fulfillment only in certain conditions
		if ( rgar( $callback_action, 'ready_to_fulfill' ) && ! rgar( $callback_action, 'abort_callback' ) ) {
			$this->fulfill_order( $entry, $transaction_id, $amount, $feed );
		} else {
			if ( rgar( $callback_action, 'abort_callback' ) ) {
				$this->log_debug( __METHOD__ . '(): Callback processing was aborted. Not fulfilling entry.' );
			} else {
				$this->log_debug( __METHOD__ . '(): Entry is already fulfilled or not ready to be fulfilled, not running gform_paypal_fulfillment hook.' );
			}
		}

		do_action( 'gform_post_payment_status', $feed, $entry, $status, $transaction_id, $subscriber_id, $amount, $pending_reason, $reason );
		if ( has_filter( 'gform_post_payment_status' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_post_payment_status.' );
		}

		do_action( 'gform_twint_ipn_' . $txn_type, $entry, $feed, $status, $txn_type, $transaction_id, $parent_txn_id, $subscriber_id, $amount, $pending_reason, $reason );
		if ( has_filter( 'gform_twint_ipn_' . $txn_type ) ) {
			$this->log_debug( __METHOD__ . "(): Executing functions hooked to gform_twint_ipn_{$txn_type}." );
		}

		do_action( 'gform_twint_post_ipn', $_POST, $entry, $feed, false );
		if ( has_filter( 'gform_twint_post_ipn' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_twint_post_ipn.' );
		}
	}

	private function verify_twint() {

		$req = 'cmd=_notify-validate';
		foreach ( $_POST as $key => $value ) {
			$value = urlencode( stripslashes( $value ) );
			$req .= "&$key=$value";
		}

		$url = rgpost( 'test_ipn' ) ? $this->sandbox_url : apply_filters( 'gform_twint_ipn_url', $this->production_url );

		$this->log_debug( __METHOD__ . "(): Sending IPN request to twint for validation. URL: $url - Data: $req" );

		$url_info = parse_url( $url );

		//Post back to PayPal system to validate
		$request  = new WP_Http();
		$headers  = array( 'Host' => $url_info['host'] );
		$sslverify = (bool) get_option( 'gform_twint_sslverify' );

		/**
		 * Allow sslverify be modified before sending requests
		 *
		 * @since 2.5.1
		 *
		 * @param bool $sslverify Whether to verify SSL for the request. Default true for new installations, false for legacy installations.
		 */
		$sslverify = apply_filters( 'gform_twint_sslverify', $sslverify );
		$this->log_debug( __METHOD__ . '(): sslverify: ' . $sslverify );
		$response = $request->post( $url, array( 'httpversion' => '1.1', 'headers' => $headers, 'sslverify' => $sslverify, 'ssl' => true, 'body' => $req, 'timeout' => 20 ) );
		$this->log_debug( __METHOD__ . '(): Response: ' . print_r( $response, true ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = trim( $response['body'] );

		if ( ! in_array( $body, array( 'VERIFIED', 'INVALID' ) ) ) {
			return new WP_Error( 'IPNVerificationError', 'Unexpected content in the response body.' );
		}

		return $body == 'VERIFIED';

	}

	private function process_ipn( $config, $entry, $status, $transaction_type, $transaction_id, $parent_transaction_id, $subscriber_id, $amount, $pending_reason, $reason, $recurring_amount ) {
		$this->log_debug( __METHOD__ . "(): Payment status: {$status} - Transaction Type: {$transaction_type} - Transaction ID: {$transaction_id} - Parent Transaction: {$parent_transaction_id} - Subscriber ID: {$subscriber_id} - Amount: {$amount} - Pending reason: {$pending_reason} - Reason: {$reason}" );

		$action = array();
		switch ( strtolower( $transaction_type ) ) {
			case 'subscr_payment' :
				//transaction created
				$action['id']               = $transaction_id;
				$action['transaction_id']   = $transaction_id;
				$action['type']             = 'add_subscription_payment';
				$action['subscription_id']  = $subscriber_id;
				$action['amount']           = $amount;
				$action['entry_id']         = $entry['id'];
				$action['payment_method']	= 'TWINT';
				return $action;
				break;

			case 'subscr_signup' :
				//no transaction created
				$action['id']               = $subscriber_id . '_' . $transaction_type;
				$action['type']             = 'create_subscription';
				$action['subscription_id']  = $subscriber_id;
				$action['amount']           = $recurring_amount;
				$action['entry_id']         = $entry['id'];
				$action['ready_to_fulfill'] = ! $entry['is_fulfilled'] ? true : false;
				
				if ( ! $this->is_valid_initial_payment_amount( $entry['id'], $recurring_amount ) ){
					//create note and transaction
					$this->log_debug( __METHOD__ . '(): Payment amount does not match subscription amount. Subscription will not be activated.' );
					GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'Payment amount (%s) does not match subscription amount. Subscription will not be activated. Transaction ID: %s', 'gravityformspaypal' ), GFCommon::to_money( $recurring_amount, $entry['currency'] ), $subscriber_id ) );
					GFPaymentAddOn::insert_transaction( $entry['id'], 'payment', $subscriber_id, $recurring_amount );

					$action['abort_callback'] = true;
				}

				return $action;
				break;

			case 'subscr_cancel' :
				//no transaction created

				$action['id'] = $subscriber_id . '_' . $transaction_type;
				$action['type']            = 'cancel_subscription';
				$action['subscription_id'] = $subscriber_id;
				$action['entry_id']        = $entry['id'];

				return $action;
				break;

			case 'subscr_eot' :
				//no transaction created
				if ( empty( $transaction_id ) ) {
					$action['id'] = $subscriber_id . '_' . $transaction_type;
				} else {
					$action['id'] = $transaction_id;
				}
				$action['type']            = 'expire_subscription';
				$action['subscription_id'] = $subscriber_id;
				$action['entry_id']        = $entry['id'];

				return $action;
				break;

			case 'subscr_failed' :
				//no transaction created
				if ( empty( $transaction_id ) ) {
					$action['id'] = $subscriber_id . '_' . $transaction_type;
				} else {
					$action['id'] = $transaction_id;
				}
				$action['type']            = 'fail_subscription_payment';
				$action['subscription_id'] = $subscriber_id;
				$action['entry_id']        = $entry['id'];
				$action['amount']          = $amount;

				return $action;
				break;

			default:
				//handles products and donation
				switch ( strtolower( $status ) ) {
					case 'completed' :
						//creates transaction
						$action['id']               = $transaction_id . '_' . $status;
						$action['type']             = 'complete_payment';
						$action['transaction_id']   = $transaction_id;
						$action['amount']           = $amount;
						$action['entry_id']         = $entry['id'];
						$action['payment_date']     = gmdate( 'y-m-d H:i:s' );
						$action['payment_method']	= 'PayPal';
						$action['ready_to_fulfill'] = ! $entry['is_fulfilled'] ? true : false;
						
						if ( ! $this->is_valid_initial_payment_amount( $entry['id'], $amount ) ){
							//create note and transaction
							$this->log_debug( __METHOD__ . '(): Payment amount does not match product price. Entry will not be marked as Approved.' );
							GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'Payment amount (%s) does not match product price. Entry will not be marked as Approved. Transaction ID: %s', 'gravityformspaypal' ), GFCommon::to_money( $amount, $entry['currency'] ), $transaction_id ) );
							GFPaymentAddOn::insert_transaction( $entry['id'], 'payment', $transaction_id, $amount );

							$action['abort_callback'] = true;
						}

						return $action;
						break;

					case 'reversed' :
						//creates transaction
						$this->log_debug( __METHOD__ . '(): Processing reversal.' );
						GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Refunded' );
						GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'Payment has been reversed. Transaction ID: %s. Reason: %s', 'gravityformspaypal' ), $transaction_id, $this->get_reason( $reason ) ) );
						GFPaymentAddOn::insert_transaction( $entry['id'], 'refund', $action['transaction_id'], $action['amount'] );
						break;

					case 'canceled_reversal' :
						//creates transaction
						$this->log_debug( __METHOD__ . '(): Processing a reversal cancellation' );
						GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Paid' );
						GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'Payment reversal has been canceled and the funds have been transferred to your account. Transaction ID: %s', 'gravityformspaypal' ), $entry['transaction_id'] ) );
						GFPaymentAddOn::insert_transaction( $entry['id'], 'payment', $action['transaction_id'], $action['amount'] );
						break;

					case 'processed' :
					case 'pending' :
						$action['id']             = $transaction_id . '_' . $status;
						$action['type']           = 'add_pending_payment';
						$action['transaction_id'] = $transaction_id;
						$action['entry_id']       = $entry['id'];
						$action['amount']         = $amount;
						$action['entry_id']       = $entry['id'];
						$amount_formatted         = GFCommon::to_money( $action['amount'], $entry['currency'] );
						$action['note']           = sprintf( __( 'Payment is pending. Amount: %s. Transaction ID: %s. Reason: %s', 'gravityformspaypal' ), $amount_formatted, $action['transaction_id'], $this->get_pending_reason( $pending_reason ) );

						return $action;
						break;

					case 'refunded' :
						$action['id']             = $transaction_id . '_' . $status;
						$action['type']           = 'refund_payment';
						$action['transaction_id'] = $transaction_id;
						$action['entry_id']       = $entry['id'];
						$action['amount']         = $amount;

						return $action;
						break;

					case 'voided' :
						$action['id']             = $transaction_id . '_' . $status;
						$action['type']           = 'void_authorization';
						$action['transaction_id'] = $transaction_id;
						$action['entry_id']       = $entry['id'];
						$action['amount']         = $amount;

						return $action;
						break;

					case 'denied' :
					case 'failed' :
						$action['id']             = $transaction_id . '_' . $status;
						$action['type']           = 'fail_payment';
						$action['transaction_id'] = $transaction_id;
						$action['entry_id']       = $entry['id'];
						$action['amount']         = $amount;

						return $action;
						break;
				}

				break;
		}
	}

	public function get_entry( $custom_field ) {

		//Getting entry associated with this IPN message (entry id is sent in the 'custom' field)
		list( $entry_id, $hash ) = explode( '|', $custom_field );
		$hash_matches = wp_hash( $entry_id ) == $hash;

		//allow the user to do some other kind of validation of the hash
		$hash_matches = apply_filters( 'gform_paypal_hash_matches', $hash_matches, $entry_id, $hash, $custom_field );

		//Validates that Entry Id wasn't tampered with
		if ( ! rgpost( 'test_ipn' ) && ! $hash_matches ) {
			$this->log_error( __METHOD__ . "(): Entry ID verification failed. Hash does not match. Custom field: {$custom_field}. Aborting." );

			return false;
		}

		$this->log_debug( __METHOD__ . "(): IPN message has a valid custom field: {$custom_field}" );

		$entry = GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $entry ) ) {
			$this->log_error( __METHOD__ . '(): ' . $entry->get_error_message() );

			return false;
		}

		return $entry;
	}

	public function can_process_ipn( $feed, $entry ) {

		$this->log_debug( __METHOD__ . '(): Checking that IPN can be processed.' );
		//Only process test messages coming fron SandBox and only process production messages coming from production PayPal
		if ( ( $feed['meta']['mode'] == 'test' && ! rgpost( 'test_ipn' ) ) || ( $feed['meta']['mode'] == 'production' && rgpost( 'test_ipn' ) ) ) {
			$this->log_error( __METHOD__ . "(): Invalid test/production mode. IPN message mode (test/production) does not match mode configured in the PayPal feed. Configured Mode: {$feed['meta']['mode']}. IPN test mode: " . rgpost( 'test_ipn' ) );

			return false;
		}

		/**
		 * Filter through your Paypal business email (Checks to make sure it matches)
		 *
		 * @param string $feed['meta']['paypalEmail'] The Paypal Email to filter through (Taken from the feed object under feed meta)
		 * @param array $feed The Feed object to filter through and use for modifications
		 * @param array $entry The Entry Object to filter through and use for modifications
		 */
		$business_email = apply_filters( 'gform_paypal_business_email', $feed['meta']['paypalEmail'], $feed, $entry );

		$recipient_email = rgempty( 'business' ) ? rgpost( 'receiver_email' ) : rgpost( 'business' );
		if ( strtolower( trim( $recipient_email ) ) != strtolower( trim( $business_email ) ) ) {
			$this->log_error( __METHOD__ . '(): PayPal email does not match. Email entered on PayPal feed:' . strtolower( trim( $business_email ) ) . ' - Email from IPN message: ' . $recipient_email );

			return false;
		}

		//Pre IPN processing filter. Allows users to cancel IPN processing
		$cancel = apply_filters( 'gform_paypal_pre_ipn', false, $_POST, $entry, $feed );

		if ( $cancel ) {
			$this->log_debug( __METHOD__ . '(): IPN processing cancelled by the gform_paypal_pre_ipn filter. Aborting.' );
			do_action( 'gform_paypal_post_ipn', $_POST, $entry, $feed, true );
			if ( has_filter( 'gform_paypal_post_ipn' ) ) {
				$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_paypal_post_ipn.' );
			}

			return false;
		}

		return true;
	}

	public function cancel_subscription( $entry, $feed, $note = null ) {

		parent::cancel_subscription( $entry, $feed, $note );

		$this->modify_post( rgar( $entry, 'post_id' ), rgars( $feed, 'meta/update_post_action' ) );

		return true;
	}

	public function modify_post( $post_id, $action ) {

		$result = false;

		if ( ! $post_id ) {
			return $result;
		}

		switch ( $action ) {
			case 'draft':
				$post = get_post( $post_id );
				$post->post_status = 'draft';
				$result = wp_update_post( $post );
				$this->log_debug( __METHOD__ . "(): Set post (#{$post_id}) status to \"draft\"." );
				break;
			case 'delete':
				$result = wp_delete_post( $post_id );
				$this->log_debug( __METHOD__ . "(): Deleted post (#{$post_id})." );
				break;
		}

		return $result;
	}

	private function get_reason( $code ) {

		switch ( strtolower( $code ) ) {
			case 'adjustment_reversal':
				return esc_html__( 'Reversal of an adjustment', 'gravityformspaypal' );
			case 'buyer-complaint':
				return esc_html__( 'A reversal has occurred on this transaction due to a complaint about the transaction from your customer.', 'gravityformspaypal' );

			case 'chargeback':
				return esc_html__( 'A reversal has occurred on this transaction due to a chargeback by your customer.', 'gravityformspaypal' );

			case 'chargeback_reimbursement':
				return esc_html__( 'Reimbursement for a chargeback.', 'gravityformspaypal' );

			case 'chargeback_settlement':
				return esc_html__( 'Settlement of a chargeback.', 'gravityformspaypal' );

			case 'guarantee':
				return esc_html__( 'A reversal has occurred on this transaction due to your customer triggering a money-back guarantee.', 'gravityformspaypal' );

			case 'other':
				return esc_html__( 'Non-specified reason.', 'gravityformspaypal' );

			case 'refund':
				return esc_html__( 'A reversal has occurred on this transaction because you have given the customer a refund.', 'gravityformspaypal' );

			default:
				return empty( $code ) ? esc_html__( 'Reason has not been specified. For more information, contact PayPal Customer Service.', 'gravityformspaypal' ) : $code;
		}
	}

	/**
	 * Determines if the current request is to the IPN URL.
	 *
	 * Support for page=gf_paypal_ipn remains so IPNs will continue to be processed for existing subscriptions.
	 *
	 * @since unknown
	 * @since 3.4 Added support for requests to the frameworks default callback=slug URL.
	 *
	 * @return bool
	 */
	public function is_callback_valid() {
		return parent::is_callback_valid() || rgget( 'page' ) === 'gf_paypal_ipn';
	}

	/**
	 * Returns the URL to be used for IPN processing.
	 *
	 * @since 3.4
	 *
	 * @return string
	 */
	public function get_callback_url() {
		return add_query_arg( 'callback', $this->_slug, home_url( '/', 'https' ) );
	}

	private function get_pending_reason( $code ) {
		switch ( strtolower( $code ) ) {
			case 'address':
				return esc_html__( 'The payment is pending because your customer did not include a confirmed shipping address and your Payment Receiving Preferences are set to allow you to manually accept or deny each of these payments. To change your preference, go to the Preferences section of your Profile.', 'gravityformspaypal' );

			case 'authorization':
				return esc_html__( 'You set the payment action to Authorization and have not yet captured funds.', 'gravityformspaypal' );

			case 'echeck':
				return esc_html__( 'The payment is pending because it was made by an eCheck that has not yet cleared.', 'gravityformspaypal' );

			case 'intl':
				return esc_html__( 'The payment is pending because you hold a non-U.S. account and do not have a withdrawal mechanism. You must manually accept or deny this payment from your Account Overview.', 'gravityformspaypal' );

			case 'multi-currency':
				return esc_html__( 'You do not have a balance in the currency sent, and you do not have your Payment Receiving Preferences set to automatically convert and accept this payment. You must manually accept or deny this payment.', 'gravityformspaypal' );

			case 'order':
				return esc_html__( 'You set the payment action to Order and have not yet captured funds.', 'gravityformspaypal' );

			case 'paymentreview':
				return esc_html__( 'The payment is pending while it is being reviewed by PayPal for risk.', 'gravityformspaypal' );

			case 'unilateral':
				return esc_html__( 'The payment is pending because it was made to an email address that is not yet registered or confirmed.', 'gravityformspaypal' );

			case 'upgrade':
				return esc_html__( 'The payment is pending because it was made via credit card and you must upgrade your account to Business or Premier status in order to receive the funds. Upgrade can also mean that you have reached the monthly limit for transactions on your account.', 'gravityformspaypal' );

			case 'verify':
				return esc_html__( 'The payment is pending because you are not yet verified. You must verify your account before you can accept this payment.', 'gravityformspaypal' );

			case 'other':
				return esc_html__( 'Reason has not been specified. For more information, contact PayPal Customer Service.', 'gravityformspaypal' );

			default:
				return empty( $code ) ? esc_html__( 'Reason has not been specified. For more information, contact PayPal Customer Service.', 'gravityformspaypal' ) : $code;
		}
	}

	//------- ADMIN FUNCTIONS/HOOKS -----------//

	public function init_admin() {

		parent::init_admin();

		//add actions to allow the payment status to be modified
		add_action( 'gform_payment_status', array( $this, 'admin_edit_payment_status' ), 3, 3 );
		add_action( 'gform_payment_date', array( $this, 'admin_edit_payment_date' ), 3, 3 );
		add_action( 'gform_payment_transaction_id', array( $this, 'admin_edit_payment_transaction_id' ), 3, 3 );
		add_action( 'gform_payment_amount', array( $this, 'admin_edit_payment_amount' ), 3, 3 );
		add_action( 'gform_after_update_entry', array( $this, 'admin_update_payment' ), 4, 2 );

		//checking if webserver is compatible with PayPal SSL certificate
		add_action( 'admin_notices', array( $this, 'check_ipn_request' ) );

	}

	/**
	 * Add supported notification events.
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return array
	 */
	public function supported_notification_events( $form ) {
		if ( ! $this->has_feed( $form['id'] ) ) {
			return false;
		}

		return array(
				'complete_payment'          => esc_html__( 'Payment Completed', 'gravityformspaypal' ),
				'refund_payment'            => esc_html__( 'Payment Refunded', 'gravityformspaypal' ),
				'fail_payment'              => esc_html__( 'Payment Failed', 'gravityformspaypal' ),
				'add_pending_payment'       => esc_html__( 'Payment Pending', 'gravityformspaypal' ),
				'void_authorization'        => esc_html__( 'Authorization Voided', 'gravityformspaypal' ),
				'create_subscription'       => esc_html__( 'Subscription Created', 'gravityformspaypal' ),
				'cancel_subscription'       => esc_html__( 'Subscription Canceled', 'gravityformspaypal' ),
				'expire_subscription'       => esc_html__( 'Subscription Expired', 'gravityformspaypal' ),
				'add_subscription_payment'  => esc_html__( 'Subscription Payment Added', 'gravityformspaypal' ),
				'fail_subscription_payment' => esc_html__( 'Subscription Payment Failed', 'gravityformspaypal' ),
		);
	}

	public function admin_edit_payment_status( $payment_status, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $payment_status;
		}

		//create drop down for payment status
		$payment_string = gform_tooltip( 'paypal_edit_payment_status', '', true );
		$payment_string .= '<select id="payment_status" name="payment_status">';
		$payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
		$payment_string .= '<option value="Paid">Paid</option>';
		$payment_string .= '</select>';

		return $payment_string;
	}

	public function admin_edit_payment_date( $payment_date, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $payment_date;
		}

		$payment_date = $entry['payment_date'];
		if ( empty( $payment_date ) ) {
			$payment_date = gmdate( 'y-m-d H:i:s' );
		}

		$input = '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';

		return $input;
	}

	public function admin_edit_payment_transaction_id( $transaction_id, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $transaction_id;
		}

		$input = '<input type="text" id="paypal_transaction_id" name="paypal_transaction_id" value="' . $transaction_id . '">';

		return $input;
	}

	public function admin_edit_payment_amount( $payment_amount, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $payment_amount;
		}

		if ( empty( $payment_amount ) ) {
			$payment_amount = GFCommon::get_order_total( $form, $entry );
		}

		$input = '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';

		return $input;
	}

	public function admin_update_payment( $form, $entry_id ) {
		check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );

		//update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
		$entry = GFFormsModel::get_lead( $entry_id );

		if ( $this->payment_details_editing_disabled( $entry, 'update' ) ) {
			return;
		}
        
		//get payment fields to update
		$payment_status = rgpost( 'payment_status' );
		//when updating, payment status may not be editable, if no value in post, set to lead payment status
		if ( empty( $payment_status ) ) {
			$payment_status = $entry['payment_status'];
		}

		$payment_amount      = GFCommon::to_number( rgpost( 'payment_amount' ) );
		$payment_transaction = rgpost( 'paypal_transaction_id' );
		$payment_date        = rgpost( 'payment_date' );

		$status_unchanged = $entry['payment_status'] == $payment_status;
		$amount_unchanged = $entry['payment_amount'] == $payment_amount;
		$id_unchanged     = $entry['transaction_id'] == $payment_transaction;
		$date_unchanged   = $entry['payment_date'] == $payment_date;

		if ( $status_unchanged && $amount_unchanged && $id_unchanged && $date_unchanged ) {
			return;
		}

		if ( empty( $payment_date ) ) {
			$payment_date = gmdate( 'y-m-d H:i:s' );
		} else {
			//format date entered by user
			$payment_date = date( 'Y-m-d H:i:s', strtotime( $payment_date ) );
		}

		global $current_user;
		$user_id   = 0;
		$user_name = 'System';
		if ( $current_user && $user_data = get_userdata( $current_user->ID ) ) {
			$user_id   = $current_user->ID;
			$user_name = $user_data->display_name;
		}

		$entry['payment_status'] = $payment_status;
		$entry['payment_amount'] = $payment_amount;
		$entry['payment_date']   = $payment_date;
		$entry['transaction_id'] = $payment_transaction;

		// if payment status does not equal approved/paid or the lead has already been fulfilled, do not continue with fulfillment
		if ( ( $payment_status == 'Approved' || $payment_status == 'Paid' ) && ! $entry['is_fulfilled'] ) {
			$action['id']             = $payment_transaction;
			$action['type']           = 'complete_payment';
			$action['transaction_id'] = $payment_transaction;
			$action['amount']         = $payment_amount;
			$action['entry_id']       = $entry['id'];

			$this->complete_payment( $entry, $action );
			$this->fulfill_order( $entry, $payment_transaction, $payment_amount );
		}
		//update lead, add a note
		GFAPI::update_entry( $entry );
		GFFormsModel::add_note( $entry['id'], $user_id, $user_name, sprintf( esc_html__( 'Payment information was manually updated. Status: %s. Amount: %s. Transaction ID: %s. Date: %s', 'gravityformspaypal' ), $entry['payment_status'], GFCommon::to_money( $entry['payment_amount'], $entry['currency'] ), $payment_transaction, $entry['payment_date'] ) );
	}

	public function fulfill_order( &$entry, $transaction_id, $amount, $feed = null ) {

		if ( ! $feed ) {
			$feed = $this->get_payment_feed( $entry );
		}

		$form = GFFormsModel::get_form_meta( $entry['form_id'] );
		if ( rgars( $feed, 'meta/delayPost' ) ) {
			$this->log_debug( __METHOD__ . '(): Creating post.' );
			$entry['post_id'] = GFFormsModel::create_post( $form, $entry );
			$this->log_debug( __METHOD__ . '(): Post created.' );
		}

		if ( rgars( $feed, 'meta/delayNotification' ) ) {
			//sending delayed notifications
			$notifications = $this->get_notifications_to_send( $form, $feed );
			GFCommon::send_notifications( $notifications, $form, $entry, true, 'form_submission' );
		}

		do_action( 'gform_paypal_fulfillment', $entry, $feed, $transaction_id, $amount );
		if ( has_filter( 'gform_paypal_fulfillment' ) ) {
			$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_paypal_fulfillment.' );
		}

	}

	/**
	 * Retrieve the IDs of the notifications to be sent.
	 *
	 * @param array $form The form which created the entry being processed.
	 * @param array $feed The feed which processed the entry.
	 *
	 * @return array
	 */
	public function get_notifications_to_send( $form, $feed ) {
		$notifications_to_send  = array();
		$selected_notifications = rgars( $feed, 'meta/selectedNotifications' );

		if ( is_array( $selected_notifications ) ) {
			// Make sure that the notifications being sent belong to the form submission event, just in case the notification event was changed after the feed was configured.
			foreach ( $form['notifications'] as $notification ) {
				if ( rgar( $notification, 'event' ) != 'form_submission' || ! in_array( $notification['id'], $selected_notifications ) ) {
					continue;
				}

				$notifications_to_send[] = $notification['id'];
			}
		}

		return $notifications_to_send;
	}

	private function is_valid_initial_payment_amount( $entry_id, $amount_paid ) {

		//get amount initially sent to paypal
		$amount_sent = gform_get_meta( $entry_id, 'payment_amount' );
		if ( empty( $amount_sent ) ) {
			return true;
		}

		$epsilon    = 0.00001;
		$is_equal   = abs( floatval( $amount_paid ) - floatval( $amount_sent ) ) < $epsilon;
		$is_greater = floatval( $amount_paid ) > floatval( $amount_sent );

		//initial payment is valid if it is equal to or greater than product/subscription amount
		if ( $is_equal || $is_greater ) {
			return true;
		}

		return false;

	}

	public function paypal_fulfillment( $entry, $paypal_config, $transaction_id, $amount ) {
		//no need to do anything for paypal when it runs this function, ignore
		return false;
	}

	/**
	 * Editing of the payment details should only be possible if the entry was processed by PayPal, if the payment status is Pending or Processing, and the transaction was not a subscription.
	 *
	 * @param array $entry The current entry
	 * @param string $action The entry detail page action, edit or update.
	 *
	 * @return bool
	 */
	public function payment_details_editing_disabled( $entry, $action = 'edit' ) {
		if ( ! $this->is_payment_gateway( $entry['id'] ) ) {
			// Entry was not processed by this add-on, don't allow editing.
			return true;
		}

		$payment_status = rgar( $entry, 'payment_status' );
		if ( $payment_status == 'Approved' || $payment_status == 'Paid' || rgar( $entry, 'transaction_type' ) == 2 ) {
			// Editing not allowed for this entries transaction type or payment status.
			return true;
		}

		if ( $action == 'edit' && rgpost( 'screen_mode' ) == 'edit' ) {
			// Editing is allowed for this entry.
			return false;
		}

		if ( $action == 'update' && rgpost( 'screen_mode' ) == 'view' && rgpost( 'action' ) == 'update' ) {
			// Updating the payment details for this entry is allowed.
			return false;
		}

		// In all other cases editing is not allowed.

		return true;
	}

	/**
	 * Activate sslverify by default for new installations.
	 *
	 * Transform data when upgrading from legacy paypal.
	 *
	 * @param $previous_version
	 */
	public function upgrade( $previous_version ) {

		if ( empty( $previous_version ) ) {
			$previous_version = get_option( 'gf_paypal_version' );
		}

		if ( empty( $previous_version ) ) {
			update_option( 'gform_paypal_sslverify', true );
		}

		$previous_is_pre_addon_framework = ! empty( $previous_version ) && version_compare( $previous_version, '2.0.dev1', '<' );

		if ( $previous_is_pre_addon_framework ) {

			//copy plugin settings
			$this->copy_settings();

			//copy existing feeds to new table
			$this->copy_feeds();

			//copy existing paypal transactions to new table
			$this->copy_transactions();

			//updating payment_gateway entry meta to 'gravityformspaypal' from 'paypal'
			$this->update_payment_gateway();

			//updating entry status from 'Approved' to 'Paid'
			$this->update_lead();			
			
		}

		// Remove TLS 1.2 warning.
		if ( ! empty( $previous_version ) && version_compare( $previous_version, '3.2', '<' ) ) {
			delete_transient( 'gravityformspaypal_tlstest_response' );
		}

	}

	public function uninstall(){
		parent::uninstall();
		delete_option( 'gform_paypal_sslverify' );
	}

	public static function get_entry_table_name() {
		return version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? GFFormsModel::get_lead_table_name() : GFFormsModel::get_entry_table_name();
 	}

	public static function get_entry_meta_table_name() {
		return version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? GFFormsModel::get_lead_meta_table_name() : GFFormsModel::get_entry_meta_table_name();
 	}

	public static function get_gravityforms_db_version() {

		if ( method_exists( 'GFFormsModel', 'get_database_version' ) ) {
			$db_version = GFFormsModel::get_database_version();
		} else {
			$db_version = GFForms::$version;
		}

		return $db_version;
	}

	//------ FOR BACKWARDS COMPATIBILITY ----------------------//

	public function update_feed_id( $old_feed_id, $new_feed_id ) {
		global $wpdb;
		$entry_meta_table = self::get_entry_meta_table_name();
		$sql = $wpdb->prepare( "UPDATE {$entry_meta_table} SET meta_value=%s WHERE meta_key='paypal_feed_id' AND meta_value=%s", $new_feed_id, $old_feed_id );
		$wpdb->query( $sql );
	}

	public function add_legacy_meta( $new_meta, $old_feed ) {

		$known_meta_keys = array(
								'email', 'mode', 'type', 'style', 'continue_text', 'cancel_url', 'disable_note', 'disable_shipping', 'recurring_amount_field', 'recurring_times',
								'recurring_retry', 'billing_cycle_number', 'billing_cycle_type', 'trial_period_enabled', 'trial_amount', 'trial_period_number', 'trial_period_type', 'delay_post',
								'update_post_action', 'delay_notifications', 'selected_notifications', 'paypal_conditional_enabled', 'paypal_conditional_field_id',
								'paypal_conditional_operator', 'paypal_conditional_value', 'customer_fields',
								);

		foreach ( $old_feed['meta'] as $key => $value ) {
			if ( ! in_array( $key, $known_meta_keys ) ) {
				$new_meta[ $key ] = $value;
			}
		}

		return $new_meta;
	}

	public function update_payment_gateway() {
		global $wpdb;
		$entry_meta_table = self::get_entry_meta_table_name();
		$sql = $wpdb->prepare( "UPDATE {$entry_meta_table} SET meta_value=%s WHERE meta_key='payment_gateway' AND meta_value='paypal'", $this->_slug );
		$wpdb->query( $sql );
	}

	public function update_lead() {
		global $wpdb;
		$entry_table = self::get_entry_table_name();
		$entry_meta_table = self::get_entry_meta_table_name();
		$entry_id_column = version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? 'lead_id' : 'entry_id';
		$sql = $wpdb->prepare(
			"UPDATE {$entry_table}
			 SET payment_status='Paid', payment_method='PayPal'
		     WHERE payment_status='Approved'
		     		AND ID IN (
					  	SELECT {$entry_id_column} FROM {$entry_meta_table} WHERE meta_key='payment_gateway' AND meta_value=%s
				   	)",
			$this->_slug);

		$wpdb->query( $sql );
	}

	public function copy_settings() {
		//copy plugin settings
		$old_settings = get_option( 'gf_paypal_configured' );
		$new_settings = array( 'gf_paypal_configured' => $old_settings );
		$this->update_plugin_settings( $new_settings );
	}

	public function copy_feeds() {
		//get feeds
		$old_feeds = $this->get_old_feeds();

		if ( $old_feeds ) {

			$counter = 1;
			foreach ( $old_feeds as $old_feed ) {
				$feed_name       = 'Feed ' . $counter;
				$form_id         = $old_feed['form_id'];
				$is_active       = $old_feed['is_active'];
				$customer_fields = $old_feed['meta']['customer_fields'];

				$new_meta = array(
					'feedName'                     => $feed_name,
					'paypalEmail'                  => rgar( $old_feed['meta'], 'email' ),
					'mode'                         => rgar( $old_feed['meta'], 'mode' ),
					'transactionType'              => rgar( $old_feed['meta'], 'type' ),
					'type'                         => rgar( $old_feed['meta'], 'type' ), //For backwards compatibility of the delayed payment feature
					'cancelUrl'                    => rgar( $old_feed['meta'], 'cancel_url' ),
					'disableNote'                  => rgar( $old_feed['meta'], 'disable_note' ),
					'disableShipping'              => rgar( $old_feed['meta'], 'disable_shipping' ),

					'recurringAmount'              => rgar( $old_feed['meta'], 'recurring_amount_field' ) == 'all' ? 'form_total' : rgar( $old_feed['meta'], 'recurring_amount_field' ),
					'recurring_amount_field'       => rgar( $old_feed['meta'], 'recurring_amount_field' ), //For backwards compatibility of the delayed payment feature
					'recurringTimes'               => rgar( $old_feed['meta'], 'recurring_times' ),
					'recurringRetry'               => rgar( $old_feed['meta'], 'recurring_retry' ),
					'paymentAmount'                => 'form_total',
					'billingCycle_length'          => rgar( $old_feed['meta'], 'billing_cycle_number' ),
					'billingCycle_unit'            => $this->convert_interval( rgar( $old_feed['meta'], 'billing_cycle_type' ), 'text' ),

					'trial_enabled'                => rgar( $old_feed['meta'], 'trial_period_enabled' ),
					'trial_product'                => 'enter_amount',
					'trial_amount'                 => rgar( $old_feed['meta'], 'trial_amount' ),
					'trialPeriod_length'           => rgar( $old_feed['meta'], 'trial_period_number' ),
					'trialPeriod_unit'             => $this->convert_interval( rgar( $old_feed['meta'], 'trial_period_type' ), 'text' ),

					'delayPost'                    => rgar( $old_feed['meta'], 'delay_post' ),
					'change_post_status'           => rgar( $old_feed['meta'], 'update_post_action' ) ? '1' : '0',
					'update_post_action'           => rgar( $old_feed['meta'], 'update_post_action' ),

					'delayNotification'            => rgar( $old_feed['meta'], 'delay_notifications' ),
					'selectedNotifications'        => rgar( $old_feed['meta'], 'selected_notifications' ),

					'billingInformation_firstName' => rgar( $customer_fields, 'first_name' ),
					'billingInformation_lastName'  => rgar( $customer_fields, 'last_name' ),
					'billingInformation_email'     => rgar( $customer_fields, 'email' ),
					'billingInformation_address'   => rgar( $customer_fields, 'address1' ),
					'billingInformation_address2'  => rgar( $customer_fields, 'address2' ),
					'billingInformation_city'      => rgar( $customer_fields, 'city' ),
					'billingInformation_state'     => rgar( $customer_fields, 'state' ),
					'billingInformation_zip'       => rgar( $customer_fields, 'zip' ),
					'billingInformation_country'   => rgar( $customer_fields, 'country' ),

				);

				$new_meta = $this->add_legacy_meta( $new_meta, $old_feed );

				//add conditional logic
				$conditional_enabled = rgar( $old_feed['meta'], 'paypal_conditional_enabled' );
				if ( $conditional_enabled ) {
					$new_meta['feed_condition_conditional_logic']        = 1;
					$new_meta['feed_condition_conditional_logic_object'] = array(
						'conditionalLogic' =>
							array(
								'actionType' => 'show',
								'logicType'  => 'all',
								'rules'      => array(
									array(
										'fieldId'  => rgar( $old_feed['meta'], 'paypal_conditional_field_id' ),
										'operator' => rgar( $old_feed['meta'], 'paypal_conditional_operator' ),
										'value'    => rgar( $old_feed['meta'], 'paypal_conditional_value' )
									),
								)
							)
					);
				} else {
					$new_meta['feed_condition_conditional_logic'] = 0;
				}


				$new_feed_id = $this->insert_feed( $form_id, $is_active, $new_meta );
				$this->update_feed_id( $old_feed['id'], $new_feed_id );

				$counter ++;
			}
		}
	}

	public function copy_transactions() {
		//copy transactions from the paypal transaction table to the add payment transaction table
		global $wpdb;
		$old_table_name = $this->get_old_transaction_table_name();
		if ( ! $this->table_exists( $old_table_name ) ) {
			return false;
		}
		$this->log_debug( __METHOD__ . '(): Copying old PayPal transactions into new table structure.' );

		$new_table_name = $this->get_new_transaction_table_name();

		$sql = "INSERT INTO {$new_table_name} (lead_id, transaction_type, transaction_id, is_recurring, amount, date_created)
					SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created FROM {$old_table_name}";

		$wpdb->query( $sql );

		$this->log_debug( __METHOD__ . "(): transactions: {$wpdb->rows_affected} rows were added." );
	}
	
	public function get_old_transaction_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'rg_paypal_transaction';
	}

	public function get_new_transaction_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'gf_addon_payment_transaction';
	}

	public function get_old_feeds() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'rg_paypal';

		if ( ! $this->table_exists( $table_name ) ) {
			return false;
		}

		$form_table_name = GFFormsModel::get_form_table_name();
		$sql             = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
					FROM {$table_name} s
					INNER JOIN {$form_table_name} f ON s.form_id = f.id";

		$this->log_debug( __METHOD__ . "(): getting old feeds: {$sql}" );

		$results = $wpdb->get_results( $sql, ARRAY_A );

		$this->log_debug( __METHOD__ . "(): error?: {$wpdb->last_error}" );

		$count = sizeof( $results );

		$this->log_debug( __METHOD__ . "(): count: {$count}" );

		for ( $i = 0; $i < $count; $i ++ ) {
			$results[ $i ]['meta'] = maybe_unserialize( $results[ $i ]['meta'] );
		}

		return $results;
	}

	//This function kept static for backwards compatibility
	public static function get_config_by_entry( $entry ) {

		$paypal = GFPayPal::get_instance();

		$feed = $paypal->get_payment_feed( $entry );

		if ( empty( $feed ) ) {
			return false;
		}

		return $feed['addon_slug'] == $paypal->_slug ? $feed : false;
	}

	//This function kept static for backwards compatibility
	//This needs to be here until all add-ons are on the framework, otherwise they look for this function
	public static function get_config( $form_id ) {

		$paypal = GFPayPal::get_instance();
		$feed   = $paypal->get_feeds( $form_id );

		//Ignore IPN messages from forms that are no longer configured with the PayPal add-on
		if ( ! $feed ) {
			return false;
		}

		return $feed[0]; //only one feed per form is supported (left for backwards compatibility)
	}

	//------------------------------------------------------
}