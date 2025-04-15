<?php
/**
 * PayPal Webhook Handler for My VIN Verifier Plugin.
 * Listens for notifications from PayPal (via WP REST API endpoint) and triggers order fulfillment.
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles incoming POST requests to the PayPal webhook endpoint.
 * Callback for the REST route registered in my-vin-verifier.php.
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response Response object (typically HTTP 200 OK).
 */
function my_vin_handle_paypal_webhook_request( WP_REST_Request $request ) {

    // --- 1. Get Raw Body and Headers ---
    $raw_body = $request->get_body();
    $headers = $request->get_headers(); // Headers are lowercase keys

    // Log incoming request for debugging (consider conditional logging)
    // error_log("PayPal Webhook Received: Headers=" . print_r($headers, true) . " Body=" . $raw_body);

    // --- 2. Verify Webhook Signature (CRITICAL!) ---
    $verification_result = my_vin_paypal_verify_webhook_signature( $headers, $raw_body ); // From paypal-api.php

    if ( is_wp_error( $verification_result ) || $verification_result !== true ) {
        $error_message = is_wp_error($verification_result) ? $verification_result->get_error_message() : 'Verification check returned false.';
        error_log("[VIN Plugin] PayPal Webhook Verification Failed: " . $error_message);
        // Return HTTP 400 Bad Request - PayPal might retry if verification fails temporarily
        return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Webhook verification failed.' ), 400 );
    }

    // --- 3. Parse the Event Payload ---
    $event = json_decode( $raw_body, true );
    if ( json_last_error() !== JSON_ERROR_NONE || empty( $event['event_type'] ) || empty( $event['resource'] ) ) {
        error_log("[VIN Plugin] PayPal Webhook Error: Invalid JSON payload received. Body: " . $raw_body);
        // Respond 200 OK even if payload is bad, as verification passed. Prevents PayPal retries for bad data.
        return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid webhook payload.' ), 200 );
    }

    $event_type = $event['event_type'];
    $resource = $event['resource'];
    $internal_order_id = null;
    $paypal_capture_id = null;
    $payment_status_to_update = null;

    error_log("[VIN Plugin] Processing Webhook Event: " . $event_type); // Log event type

    // --- 4. Process Relevant Events ---

    // --- PAYMENT.CAPTURE.COMPLETED ---
    if ( $event_type === 'PAYMENT.CAPTURE.COMPLETED' ) {
        // Extract necessary IDs
        // The 'custom_id' from the purchase_unit links back to our internal report_id
        $internal_order_id = isset( $resource['custom_id'] ) ? absint( $resource['custom_id'] ) : null;
        $paypal_capture_id = isset( $resource['id'] ) ? sanitize_text_field( $resource['id'] ) : null; // This is the Capture ID

        if ( ! $internal_order_id ) {
             error_log("[VIN Plugin] Webhook Error (PAYMENT.CAPTURE.COMPLETED): Could not find internal order ID (custom_id) in resource. Payload: " . print_r($resource, true));
        } else {
            // Update payment status and store Capture ID
             my_vin_update_report_meta($internal_order_id, [
                 'payment_status' => 'completed',
                 'payment_transaction_id' => $paypal_capture_id // Store Capture ID for potential refunds
             ]);

            // --- Trigger Fulfillment ---
            // This function handles idempotency checks internally.
            $fulfillment_result = my_vin_fulfill_order( $internal_order_id ); // Defined in ajax-handlers.php

            if ( is_wp_error( $fulfillment_result ) ) {
                // Fulfillment failed AFTER successful payment capture via webhook!
                error_log("[VIN Plugin] CRITICAL (Webhook Triggered): Fulfillment failed after payment capture for Order ID: {$internal_order_id}, PayPal Capture ID: {$paypal_capture_id}. Error: " . $fulfillment_result->get_error_message());
                // --- TODO: Initiate REFUND via PayPal API ---
                // $refund_result = my_vin_paypal_issue_refund( $paypal_capture_id );
                // if (is_wp_error($refund_result)) { error_log("[VIN Plugin] CRITICAL (Webhook): Refund attempt failed. Capture ID: {$paypal_capture_id}. Error: " . $refund_result->get_error_message()); }
                // else { my_vin_update_report_meta($internal_order_id, ['payment_status' => 'refunded', 'report_status' => 'failed']); }
                // --- TODO: Notify site admin ---
            } else {
                // Fulfillment successful via webhook.
                error_log("[VIN Plugin] Fulfillment successful via webhook for Order ID: {$internal_order_id}.");
                // --- TODO: Optionally send email notification to customer ---
            }
        }
    }
    // --- PAYMENT.CAPTURE.DENIED / FAILED / PENDING / REVERSED ---
    elseif ( in_array($event_type, ['PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.REVERSED', 'PAYMENT.CAPTURE.FAILED']) ) {
        // Find internal order ID (e.g., via custom_id or invoice_id in resource)
        // Note: The structure might differ slightly for these events. Inspect PayPal docs/logs.
         $internal_order_id = isset( $resource['custom_id'] ) ? absint( $resource['custom_id'] ) : null;
         // Or maybe from supplementary_data -> related_ids -> order_id? Needs checking.

         if ($internal_order_id) {
             // Determine status based on event type
             if ($event_type === 'PAYMENT.CAPTURE.REVERSED') {
                 $payment_status_to_update = 'refunded'; // Or 'reversed'
             } elseif ($event_type === 'PAYMENT.CAPTURE.DENIED' || $event_type === 'PAYMENT.CAPTURE.FAILED') {
                  $payment_status_to_update = 'failed';
             } elseif ($event_type === 'PAYMENT.CAPTURE.PENDING') {
                  $payment_status_to_update = 'pending_capture'; // Or keep as 'pending_paypal'
             }

             if ($payment_status_to_update) {
                 error_log("[VIN Plugin] Webhook: Updating payment status for Order ID {$internal_order_id} to {$payment_status_to_update} based on event {$event_type}.");
                 my_vin_update_report_meta($internal_order_id, ['payment_status' => $payment_status_to_update]);
             }
         } else {
              error_log("[VIN Plugin] Webhook Warning ({$event_type}): Could not determine internal order ID from resource.");
         }
    }
    // --- Handle other event types if needed (e.g., disputes, refunds initiated elsewhere) ---
    else {
        // Log unhandled event types for monitoring
        error_log("[VIN Plugin] Info: Received unhandled PayPal webhook event type: " . $event_type);
    }


    // --- 5. Respond to PayPal ---
    // Always respond with HTTP 200 OK quickly to acknowledge receipt.
    // Do not include complex data in the response body.
    return new WP_REST_Response( array( 'status' => 'received' ), 200 );
}

?>
