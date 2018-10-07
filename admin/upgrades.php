<?php
/**
 * This file will be used to run upgrade routines based on health check.
 *
 * @since 1.0.0
 */

// Exit if access directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Register upgrades
 *
 * @since 1.0.0
 */
function give_stripe_healthcheck_notices() {

	Give_Updates::get_instance()->register(
		array(
			'id'       => 'stripe_healthcheck_fix_duplicate_card_sources',
			'version'  => '1.0.0',
			'callback' => 'give_stripe_healthcheck_fix_duplicate_card_sources_callback',
		)
	);

}

add_action( 'give_register_updates', 'give_stripe_healthcheck_notices' );


/**
 * Fix duplicate card sources
 *
 * @since 1.0.0
 */
function give_stripe_healthcheck_fix_duplicate_card_sources_callback() {

	global $wpdb;

	require_once GIVE_STRIPE_PLUGIN_DIR . '/vendor/autoload.php';

	$give_updates = Give_Updates::get_instance();
	$donor_count = Give()->donors->count(
		array(
			'number' => -1,
		)
	);

	$donors = Give()->donors->get_donors(
		array(
			'order'  => 'ASC',
			'paged'  => $give_updates->step,
			'number' => 10,
		)
	);

	$unique_sources      = array();
	$duplicate_sources   = array();
	$unique_fingerprints = array();

	if ( $donors ) {

		$give_updates->set_percentage( $donor_count, $give_updates->step * 10 );

		foreach ( $donors as $donor ) {

			$unique_sources[ $donor->id ]      = array();
			$duplicate_sources[ $donor->id ]   = array();
			$unique_fingerprints[ $donor->id ] = array();
			$stripe_customer_id = Give()->donor_meta->get_meta( $donor->id, '_give_stripe_customer_id', true );

			if ( empty( $stripe_customer_id ) ) {
				$donation_ids    = explode( ',', $donor->payment_ids );
				$total_donations = count( $donation_ids );
				$recent_donation = $donation_ids[ $total_donations - 1 ];
				$stripe_customer_id = give_get_meta( $recent_donation, '_give_stripe_customer_id', true );
			}

			if ( ! empty( $stripe_customer_id ) ) {

				try {

					\Stripe\Stripe::setApiKey( Give_Stripe_Gateway::get_secret_key() );

					$customer    = \Stripe\Customer::retrieve( $stripe_customer_id );
					$all_sources = $customer->sources->all( array(
						'limit' => 100,
						'object' => 'source',
					) );

					if ( count( $all_sources->data ) > 0 ) {
						foreach ( $all_sources->data as $source_item ) {

							$fingerprint = '';
							if ( give_stripe_healthcheck_is_source( $source_item->id ) ) {
								$fingerprint = $source_item->card->fingerprint;
							}

							if ( ! in_array( $fingerprint, $unique_fingerprints[ $donor->id ],true ) ) {
								$unique_fingerprints[ $donor->id ][] = $fingerprint;
								$unique_sources[ $donor->id ][]      = $source_item->id;
							} else {
								$duplicate_sources[ $donor->id ][] = $source_item->id;
							}
						}
					}

					if (
						isset( $unique_sources[ $donor->id ][0] ) &&
						! empty( $unique_sources[ $donor->id ][0] ) &&
						$unique_sources[ $donor->id ][0] !== $customer->default_source
					) {
						try {
							$customer->default_source = $unique_sources[ $donor->id ][0];
							$customer->save();
						} catch ( Exception $e ) {
							give_stripe_record_log(
								__( 'Stripe Healthcheck - Error', 'give-stripe-healthcheck' ),
								sprintf(
									/* translators: 1. Customer ID, 2. Error Message. */
									__( 'Unable to set default source for customer %1$s. Error: %2$s', 'give-stripe-healthcheck' ),
									$customer->id,
									$e->getMessage()
								)
							);
						}
					}

					// Check whether the duplicate sources count is greater than 0.
					if ( count( $duplicate_sources[ $donor->id ] ) > 0 ) {

						// Loop through the list of duplicate sources and detach it from customer.
						foreach ( $duplicate_sources[ $donor->id ] as $duplicate_source ) {
							try {
								$customer->sources->retrieve( $duplicate_source )->detach();

								// Log success.
								give_stripe_record_log(
									__( 'Stripe Healthcheck - Success', 'give-stripe-healthcheck' ),
									sprintf(
										/* translators: 1. Source ID, 2. Customer ID. */
										__( 'Successfully detached source %1$s from customer %2$s.', 'give-stripe-healthcheck' ),
										$duplicate_source,
										$customer->id
									)
								);

							} catch ( Exception $e ) {
								give_stripe_record_log(
									__( 'Stripe Healthcheck - Error', 'give-stripe-healthcheck' ),
									sprintf(
										/* translators: 1. Source ID, 2. Customer ID, 3. Error Message. */
										__( 'Unable to detach source %1$s from customer %2$s. Error: %3$s', 'give-stripe-healthcheck' ),
										$duplicate_source,
										$customer->id,
										$e->getMessage()
									)
								);
							}
						}
					}
				} catch ( Exception $e ) {
					give_stripe_record_log(
						__( 'Stripe Healthcheck - Error', 'give-stripe-healthcheck' ),
						sprintf(
							/* translators: 1. Customer ID, 2. Error Message. */
							__( 'Unable to retrieve customer %1$s. Error: %2$s', 'give-stripe-healthcheck' ),
							$stripe_customer_id,
							$e->getMessage()
						)
					);
				} // End try().
			} // End if().
		} // End foreach().
	} else {
		give_set_upgrade_complete( 'stripe_healthcheck_fix_duplicate_card_sources' );
	} // End if().
}

/**
 * This function will check whether the ID provided is Source ID?
 *
 * @param string $id Source ID.
 *
 * @since  1.0.0
 * @access public
 *
 * @return bool
 */
function give_stripe_healthcheck_is_source( $id ) {
	return (
		$id &&
		preg_match( '/src_/i', $id )
	);
}
