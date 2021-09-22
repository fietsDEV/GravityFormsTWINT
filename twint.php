<?php
/*
Plugin Name: Gravity Forms TWINT Add-On
Plugin URI: https://gravityforms.com
Description: Integrates Gravity Forms with TWINT, enabling end users to purchase goods and services through Gravity Forms.
Version: 1.0
License: GPL-2.0+
Text Domain: gravityformstwint
Domain Path: /languages

------------------------------------------------------------------------

*/

defined( 'ABSPATH' ) || die();

define( 'GF_TWINT_VERSION', '1.0' );

add_action( 'gform_loaded', array( 'GF_TWINT_Bootstrap', 'load' ), 5 );

class GF_TWINT_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		require_once( 'class-gf-twint.php' );

		GFAddOn::register( 'GFTWINT' );
	}
}

function gf_twint() {
	return GFTWINT::get_instance();
}
