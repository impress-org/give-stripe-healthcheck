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
			'id'       => 'give_stripe_healthcheck_fix_duplicate_card_sources',
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

	$give_updates = Give_Updates::get_instance();

	$donors = Give()->donors->get_donors(
		array(
			'number' => 100,
			'paged'  => $give_updates->step,
		)
	);

	require_once GIVE_STRIPE_PLUGIN_DIR . '/vendor/autoload.php';

	\Stripe\Stripe::setApiKey( Give_Stripe_Gateway::get_secret_key() );

	if ( ! empty( $donors ) ) {
		$give_updates->set_percentage( count( $donors ), $give_updates->step * 100 );

		foreach ( $donors as $donor ) {

			$stripe_customer_id = Give()->donor_meta->get_meta( $donor->id, '_give_stripe_customer_id', true );

			if ( ! empty( $stripe_customer_id ) ) {

				$customer    = \Stripe\Customer::retrieve( $stripe_customer_id );
				$all_sources = $customer->sources->all();

				if ( count( $all_sources->data ) > 0 ) {
					foreach ( $all_sources->data as $source_item ) {

						error_log( print_r( $source_item->id, true ) . "\n", 3, WP_CONTENT_DIR . '/debug_new.log' );
					}
				}
			}
		}
	} else {
error_log( print_r( 'success', true ) . "\n", 3, WP_CONTENT_DIR . '/debug_new.log' );
give_die();
	}

}

/**
 * Fix corrupted payment meta if any
 *
 * @since 1.0.0
 */
function give_db_healthcheck_post_200_data_callback() {
	global $wpdb, $post;
	$give_updates         = Give_Updates::get_instance();
	$donation_id_col_name = Give()->payment_meta->get_meta_type() . '_id';
	$donation_table       = Give()->payment_meta->table_name;

	$payments = $wpdb->get_col(
		"
			SELECT ID FROM $wpdb->posts
			WHERE 1=1
			AND $wpdb->posts.post_type = 'give_payment'
			AND {$wpdb->posts}.post_status IN ('" . implode( "','", array_keys( give_get_payment_statuses() ) ) . "')
			ORDER BY $wpdb->posts.post_date ASC 
			LIMIT 100
			OFFSET " . $give_updates->get_offset( 100 )
	);

	if ( ! empty( $payments ) ) {
		$give_updates->set_percentage( give_get_total_post_type_count( 'give_payment' ), $give_updates->get_offset( 100 ) );

		foreach ( $payments as $payment_id ) {
			$post = get_post( $payment_id );
			setup_postdata( $post );

			// Do not add new meta keys if already refactored.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$donation_table} WHERE {$donation_id_col_name}=%d AND meta_key=%s", $post->ID, '_give_payment_donor_id' ) ) ) {
				continue;
			}


			// Split _give_payment_meta meta.
			// @todo Remove _give_payment_meta after releases 2.0
			$payment_meta = give_get_meta( $post->ID, '_give_payment_meta', true );

			if ( ! empty( $payment_meta ) ) {
				_give_20_bc_split_and_save_give_payment_meta( $post->ID, $payment_meta );
			}

			$deprecated_meta_keys = array(
				'_give_payment_customer_id' => '_give_payment_donor_id',
				'_give_payment_user_email'  => '_give_payment_donor_email',
				'_give_payment_user_ip'     => '_give_payment_donor_ip',
			);

			foreach ( $deprecated_meta_keys as $old_meta_key => $new_meta_key ) {
				// Do not add new meta key if already exist.
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$donation_table} WHERE {$donation_id_col_name}=%d AND meta_key=%s", $post->ID, $new_meta_key ) ) ) {
					continue;
				}

				$wpdb->insert(
					$donation_table,
					array(
						"$donation_id_col_name" => $post->ID,
						'meta_key'              => $new_meta_key,
						'meta_value'            => give_get_meta( $post->ID, $old_meta_key, true ),
					)
				);
			}

			// Bailout
			if ( $donor_id = give_get_meta( $post->ID, '_give_payment_donor_id', true ) ) {
				/* @var Give_Donor $donor */
				$donor = new Give_Donor( $donor_id );

				$address['line1']   = give_get_meta( $post->ID, '_give_donor_billing_address1', true, '' );
				$address['line2']   = give_get_meta( $post->ID, '_give_donor_billing_address2', true, '' );
				$address['city']    = give_get_meta( $post->ID, '_give_donor_billing_city', true, '' );
				$address['state']   = give_get_meta( $post->ID, '_give_donor_billing_state', true, '' );
				$address['zip']     = give_get_meta( $post->ID, '_give_donor_billing_zip', true, '' );
				$address['country'] = give_get_meta( $post->ID, '_give_donor_billing_country', true, '' );

				// Save address.
				$donor->add_address( 'billing[]', $address );
			}

		}// End while().

		wp_reset_postdata();
	} else {
		// @todo Delete user id meta after releases 2.0
		// $wpdb->get_var( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key=%s", '_give_payment_user_id' ) );

		// No more forms found, finish up.
		give_set_upgrade_complete( 'give_db_healthcheck_post_200_data' );
	}
}


/**
 * Fix corrupted payment donor if any
 *
 * @since 0.0.2
 */
function give_db_healthcheck_donation_donor_callback() {
	global $wpdb;
	$give_updates = Give_Updates::get_instance();

	$payments = $wpdb->get_col(
		"
			SELECT ID FROM $wpdb->posts
			WHERE 1=1
			AND $wpdb->posts.post_type = 'give_payment'
			AND {$wpdb->posts}.post_status IN ('" . implode( "','", array_keys( give_get_payment_statuses() ) ) . "')
			ORDER BY $wpdb->posts.post_date ASC 
			LIMIT 100
			OFFSET " . $give_updates->get_offset( 100 )
	);

	if ( $payments ) {
		foreach ( $payments as $payment ) {

			if (
				! give_get_meta( $payment, '_give_payment_donor_id', true )
				&& ( $donor_email = give_get_meta( $payment, '_give_payment_donor_email', true ) )
			) {
				if ( $donor_id = Give()->donors->get_column_by( 'id', 'email', $donor_email ) ) {
					give_update_meta( $payment, '_give_payment_donor_id', $donor_id );
				}
			}
		}
	} else {
		// @todo Delete user id meta after releases 2.0
		// $wpdb->get_var( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key=%s", '_give_payment_user_id' ) );

		// No more forms found, finish up.
		give_set_upgrade_complete( 'give_db_healthcheck_donation_donor' );
	}
}

/**
 * Recover old payment data
 *
 * @since 0.0.3
 */
function give_db_healthcheck_003_recover_old_paymentdata_callback() {
	global $wpdb;

	$give_updates         = Give_Updates::get_instance();
	$donation_id_col_name = Give()->payment_meta->get_meta_type() . '_id';
	$donation_table       = Give()->payment_meta->table_name;

	// form query
	$payments = new WP_Query( array(
			'paged'          => $give_updates->step,
			'status'         => 'any',
			'order'          => 'ASC',
			'post_type'      => array( 'give_payment' ),
			'posts_per_page' => 100,
		)
	);

	if ( $payments->have_posts() ) {
		$give_updates->set_percentage( $payments->found_posts, $give_updates->step * 100 );

		while ( $payments->have_posts() ) {
			$payments->the_post();

			$meta_data = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT * FROM $wpdb->postmeta
					WHERE post_id=%d
					",
					get_the_ID()
				),
				ARRAY_A
			);

			if ( ! empty( $meta_data ) ) {
				foreach ( $meta_data as $index => $data ) {
					// ignore _give_payment_meta key.
					if ( '_give_payment_meta' === $data['meta_key'] ) {
						continue;
					}

					$is_duplicate_meta_key = $wpdb->get_results(
						$wpdb->prepare(
							"
							SELECT * FROM {$donation_table}
							WHERE meta_key=%s
							AND {$donation_id_col_name}=%d
							",
							$data['meta_key'],
							$data['post_id']
						),
						ARRAY_A
					);

					if ( $is_duplicate_meta_key ) {

						continue;
					}

					$data[ $donation_id_col_name ] = $data['post_id'];

					unset( $data['post_id'] );
					unset( $data['meta_id'] );

					Give()->payment_meta->insert( $data );
				}
			}

		}// End while().

		wp_reset_postdata();
	} else {
		// No more forms found, finish up.
		give_set_upgrade_complete( 'give_db_healthcheck_003_recover_old_paymentdata' );
	}
}


/**
 * Recover old donation meta data
 *
 * @since 0.0.3
 */
function give_db_healthcheck_220_recover_donationmeta_callback(){
	global $wpdb;
	$give_updates = Give_Updates::get_instance();


	$total_payments = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}give_paymentmeta", ARRAY_N );
	$payments       = $wpdb->get_results(
		"SELECT *
				FROM {$wpdb->prefix}give_paymentmeta
				ORDER BY meta_id ASC
				LIMIT 100
				OFFSET " . $give_updates->get_offset( 100 ),
		ARRAY_A
	);

	if ( ! empty( $payments ) ) {
		$give_updates->set_percentage( count( $total_payments ), $give_updates->step * 100 );

		foreach ( $payments as $payment ) {
			if( empty( $payment['meta_value'] ) ) {
				continue;
			}

			$existing_donation_value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value
					FROM {$wpdb->prefix}give_donationmeta
					WHERE donation_id = %d
					AND meta_key = %s
					",
					$payment['payment_id'],
					$payment['meta_key']
				)
			);

			if( $existing_donation_value !== $payment['meta_value'] ) {
				$wpdb->replace(
					"{$wpdb->prefix}give_donationmeta",
					array(
						'donation_id'    => $payment['payment_id'],
						'meta_key'   => $payment['meta_key'],
						'meta_value' => $payment['meta_value'],
					),
					array(
						'%d',
						'%s',
						'%s'
					)
				);
			}

		}// End while().
	}else{
		if( ! give_has_upgrade_completed( 'v220_rename_donation_meta_type' ) ) {
			give_set_upgrade_complete( 'v220_rename_donation_meta_type' );
		}

		// No more forms found, finish up.
		give_set_upgrade_complete( 'give_db_healthcheck_220_recover_donationmeta' );
	}
}
