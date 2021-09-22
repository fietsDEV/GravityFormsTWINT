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




	//------ SENDING TO TWINT VIA ADYEN -----------//

	public function redirect_url( $feed, $submission_data, $form, $entry ) {

		// Get Adyen/TWINT Payment URL
		$api_url = 'API_URL';
		$api_key = 'YOUR_X-API-KEY';
		$merch_account = 'YOUR_MERCHANT_ACCOUNT';

		$payment_ref = '123';
		$payment_value_raw = 1;
		$payment_value = number_format($payment_value_raw, 2, '.');
		$buyer_ref = 'UNIQUE_SHOPPER_ID_6728';

		$data = '{
			"reference": "'.$payment_ref.'",
			"amount": {
				"value": '.$payment_value.',
				"currency": "CHF"
			},
			"shopperReference": "'.$buyer_ref.'",
			"description": "Spende",
			"countryCode": "CH",
			"merchantAccount": "'.$merch_account.'",
			"shopperLocale": "ch-CH"
		}';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $api_url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$headers = [
			'X-API-Key: '. $api_key,
			'Content-Type: application/json',
		];

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$output = curl_exec ($ch);

		curl_close ($ch);

		// $output response
		/*
		{
			"amount": {
				"currency": "EUR",
				"value": 4200
			},
			"countryCode": "NL",
			"description": "Blue Bag - ModelM671",
			"expiresAt": "2020-07-25T11:32:20Z",
			"id": "PL50C5F751CED39G71",
			"merchantAccount": "YOUR_MERCHANT_ACCOUNT",
			"reference": "YOUR_PAYMENT_REFERENCE",
			"shopperLocale": "nl-NL",
			"shopperReference": "UNIQUE_SHOPPER_ID_6728",
			"url": "https://test.adyen.link/PL45D0F79183A4CCA2"
		}
		*/

		// Write Order-Details to GF
		add_action( 'gform_after_submission_1', 'post_to_gf', 10, 2 );
		function post_to_gf( $entry, $form ) {
		
			$endpoint_url = 'https://thirdparty.com';
			$body = array(
				'first_name' => rgar( $entry, '1.3' ),
				'last_name' => rgar( $entry, '1.6' ),
				'message' => rgar( $entry, '3' ),
				);
			GFCommon::log_debug( 'gform_after_submission: body => ' . print_r( $body, true ) );
		
			$response = wp_remote_post( $endpoint_url, array( 'body' => $body ) );
			GFCommon::log_debug( 'gform_after_submission: response => ' . print_r( $response, true ) );

		};
		// Write Order-Details to iMatrix
		add_action( 'gform_after_submission_2', 'post_to_imatrix', 10, 2 );
		function post_to_imatrix( $entry, $form ) {
		
			$endpoint_url = 'https://thirdparty.com';
			$body = array(
				'first_name' => rgar( $entry, '1.3' ),
				'last_name' => rgar( $entry, '1.6' ),
				'message' => rgar( $entry, '3' ),
				'status' => rgar( $entry, 'status' ), // status "offen"
				);
			GFCommon::log_debug( 'gform_after_submission: body => ' . print_r( $body, true ) );
		
			$response = wp_remote_post( $endpoint_url, array( 'body' => $body ) );
			GFCommon::log_debug( 'gform_after_submission: response => ' . print_r( $response, true ) );
		}

		$data_string = json_decode($output, true);
		$payment_url = $data_string['url'];

		return $payment_url;
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

