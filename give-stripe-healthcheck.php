<?php
/**
 * Plugin Name: Give - Stripe HealthCheck
 * Plugin URI: https://github.com/impress-org/give-stripe-healthcheck
 * Description: The most robust, flexible, and intuitive way to accept donations on WordPress.
 * Author: GiveWP
 * Author URI: https://givewp.com
 * Version: 1.0.0
 * Text Domain: give-database-healthcheck
 * Domain Path: /languages
 * GitHub Plugin URI: https://github.com/impress-org/give-stripe-healthcheck
 */

final class Give_Stripe_HealthCheck {
	/**
	 * Instance.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @var $instance
	 */
	static private $instance;

	/**
	 * Singleton pattern.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function __construct() {
	}


	/**
	 * Get instance.
	 *
	 * @since 1.0.0
	 * @access static
	 *
	 * @return Give_Stripe_HealthCheck
	 */
	public static function get_instance() {
		if ( null === static::$instance ) {
			self::$instance = new static();

			self::$instance->constants();
			self::$instance->files();
		}

		return self::$instance;
	}

	/**
	 * Constant
	 *
	 * @since 1.0.0
	 */
	private function constants() {
		define( 'GIVE_STRIPE_HEALTHCHECK_DIR', plugin_dir_path( __FILE__ ) );
		define( 'GIVE_STRIPE_HEALTHCHECK_VERSION', '1.0.0' );
	}

	/**
	 * Files
	 *
	 * @since 1.0.0
	 */
	private function files() {
		require_once GIVE_STRIPE_HEALTHCHECK_DIR . 'admin/upgrades.php';
	}
}

Give_Stripe_HealthCheck::get_instance();
