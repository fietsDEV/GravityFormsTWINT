<?php

defined( 'ABSPATH' ) || die();

add_action( 'wp', array( 'GFTWINT', 'maybe_thankyou_page' ), 5 );

GFForms::include_payment_addon_framework();

class GFTWINT extends GFPaymentAddOn {

	protected $_version = GF_TWINT_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	protected $_slug = 'gravityformstwint';
	protected $_path = 'GravityFormsTWINT/twint.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Gravity Forms TWINT Add-On';
	protected $_short_title = 'TWINT';
	protected $_supports_callbacks = true;

	private $production_url = 'https://www.twint.ch/pr';
	private $sandbox_url = 'https://www.twint.ch/sb';

	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}




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

	protected function authorize( $feed, $submission_data, $form, $entry ) {
	}

	protected function capture( $authorization, $feed, $submission_data, $form, $entry ) {
	}

	protected function callback() {
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

