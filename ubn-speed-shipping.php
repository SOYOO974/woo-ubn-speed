<?php

/**
 * Plugin Name: UBN Speed Shipping
 * Plugin URI: https://soyoo.re
 * Description: Custom plugin to Integrate UBN shipping with Conforama.
 * Version: 1.2
 * Author: soyoo.re
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

/*--------------------------------------------------------------
# GET ACTIVE API CONFIG
--------------------------------------------------------------*/
function ubn_get_api_config() {

	$mode = get_option(
		'ubn_ss_api_mode',
		'production'
	);

	if ($mode === 'test') {

		return [

			'api_base' =>
				get_option(
					'ubn_test_api_base',
					''
				),

			'api_key' =>
				get_option(
					'ubn_test_api_key',
					''
				),

			'hmac_secret' =>
				get_option(
					'ubn_test_hmac_secret',
					''
				),

			'partner_id' =>
				get_option(
					'ubn_test_partner_id',
					''
				),

			'source_site' =>
				get_option(
					'ubn_test_source_site',
					''
				),
		];
	}

	return [

		'api_base' =>
			get_option(
				'ubn_prod_api_base',
				''
			),

		'api_key' =>
			get_option(
				'ubn_prod_api_key',
				''
			),

		'hmac_secret' =>
			get_option(
				'ubn_prod_hmac_secret',
				''
			),

		'partner_id' =>
			get_option(
				'ubn_prod_partner_id',
				''
			),

		'source_site' =>
			get_option(
				'ubn_prod_source_site',
				''
			),
	];

}

/*--------------------------------------------------------------
# CREATE UBN LOGS TABLE
--------------------------------------------------------------*/


function ubn_create_logs_table() {

	global $wpdb;

	$table_name = $wpdb->prefix . 'ubn_logs';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id BIGINT(20) NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		type VARCHAR(100) NOT NULL,
		endpoint TEXT NULL,
		request_data LONGTEXT NULL,
		response_data LONGTEXT NULL,
		status_code VARCHAR(20) NULL,
		order_id BIGINT(20) NULL,
		message TEXT NULL,
		PRIMARY KEY (id)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

	dbDelta($sql);
} 
/*--------------------------------------------------------------
# ENSURE LOG TABLE EXISTS
--------------------------------------------------------------*/
add_action('init', 'ubn_ensure_logs_table_exists');

function ubn_ensure_logs_table_exists() {

	global $wpdb;

	$table_name = $wpdb->prefix . 'ubn_logs';

	$table_exists = $wpdb->get_var(
		$wpdb->prepare(
			"SHOW TABLES LIKE %s",
			$table_name
		)
	);

	if ($table_exists !== $table_name) {

		ubn_create_logs_table();
	}
}

/*--------------------------------------------------------------
# SAVE UBN LOG
--------------------------------------------------------------*/
function ubn_add_log($type, $endpoint = '', $request_data = '', $response_data = '', $status_code = '', $order_id = 0, $message = '') {

	global $wpdb;

	$table_name = $wpdb->prefix . 'ubn_logs';

	$wpdb->insert(
		$table_name,
		[
			'created_at'   => current_time('mysql'),
			'type'         => $type,
			'endpoint'     => $endpoint,
			'request_data' => maybe_serialize($request_data),
			'response_data'=> maybe_serialize($response_data),
			'status_code'  => $status_code,
			'order_id'     => $order_id,
			'message'      => $message,
		]
	);
}


/*--------------------------------------------------------------
# CREATE SHIPMENT WHEN ORDER GOES PROCESSING
--------------------------------------------------------------*/
add_action('woocommerce_order_status_changed','ubn_create_processing_shipment',20,4);

function ubn_create_processing_shipment($order_id, $old_status, $new_status, $order) {

	if (!$order_id) {
		return;
	}

	$order = wc_get_order($order_id);

	if (!$order) {
		return;
	}

	// Only when becoming processing
	if ($new_status !== 'processing') {
		return;
	}
	


	ubn_create_shipment($order_id);
}

// Disable frontend execution if disabled from settings 
function ubn_ss_is_enabled() {

    // Plugin globally disabled
    if (!get_option('ubn_ss_enabled')) {
        return false;
    }

    // Admin-only frontend mode enabled
    if (get_option('ubn_ss_admin_only_frontend')) {

        // Allow backend always
        if (is_admin()) {
            return true;
        }

        // Allow logged-in admins on frontend
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return true;
        }

        // Block everyone else
        return false;
    }

    // Normal enabled mode
    return true;
}

/*--------------------------------------------------------------
# GET STORE MANAGEMENT MODE (SINGLE VS MULTI)
--------------------------------------------------------------*/
function ubn_get_store_mode() {
    $default = get_option('ubn_store_105_company') ? 'multi' : 'single';
    return get_option('ubn_ss_store_mode', $default);
}

/*--------------------------------------------------------------
# Email Recipient for Store Notification
--------------------------------------------------------------*/
function ubn_get_store_notification_emails($store_id) {

    if (ubn_get_store_mode() === 'single' || $store_id === 'single') {
        $emails = get_option(
            'ubn_single_store_notification_emails',
            get_option('admin_email', '')
        );
    } else {
        $emails = get_option(
            'ubn_store_' . $store_id . '_notification_emails',
            ''
        );
    }

    return array_filter(
        array_map(
            'trim',
            preg_split('/[\r\n,]+/', $emails)
        )
    );
}

/*--------------------------------------------------------------
# PRODUCT IMAGE URL RESOLVER (SIMPLE & VARIABLE PRODUCTS)
--------------------------------------------------------------*/
function ubn_get_product_image_url($product, $item = null, $size = 'thumbnail') {
	$image_id = 0;

	// 1. Get product image ID
	if ($product && method_exists($product, 'get_image_id')) {
		$image_id = $product->get_image_id();
	}

	// 2. If variation product image is 0/empty, fallback to parent variable product
	if (!$image_id) {
		if ($product && method_exists($product, 'is_type') && $product->is_type('variation')) {
			$parent_id = $product->get_parent_id();
			if ($parent_id) {
				$parent_product = wc_get_product($parent_id);
				if ($parent_product) {
					$image_id = $parent_product->get_image_id();
				}
			}
		} elseif ($item && method_exists($item, 'get_product_id')) {
			$parent_product = wc_get_product($item->get_product_id());
			if ($parent_product) {
				$image_id = $parent_product->get_image_id();
			}
		}
	}

	// 3. Multi-level URL resolution (thumbnail -> medium -> full -> direct attachment file URL)
	$image_url = '';
	if ($image_id) {
		$image_url = wp_get_attachment_image_url($image_id, $size);
		if (!$image_url) {
			$image_url = wp_get_attachment_image_url($image_id, 'medium');
		}
		if (!$image_url) {
			$image_url = wp_get_attachment_image_url($image_id, 'full');
		}
		if (!$image_url) {
			$image_url = wp_get_attachment_url($image_id);
		}
	}

	// 4. Fallback to static WooCommerce placeholder file URL (avoid base64 data URIs)
	if (empty($image_url) || strpos($image_url, 'data:image') === 0) {
		$image_url = function_exists('WC') && WC()->plugin_url()
			? WC()->plugin_url() . '/assets/images/placeholder.png'
			: wc_placeholder_img_src($size);
	}

	return $image_url;
}

/*--------------------------------------------------------------
# Email Tag Parser
--------------------------------------------------------------*/
function ubn_prepare_email_tags($order, $shipment_data = []) {

   	$products_html = '';

	$total_articles = 0;

	foreach ($order->get_items() as $item) {

		$qty = (int) $item->get_quantity();

		$total_articles += $qty;

		$product = $item->get_product();

		if (!$product) {
			continue;
		}

		$image_url = ubn_get_product_image_url($product, $item, 'thumbnail');

		$sku = $product->get_sku();

		$products_html .= '

		<table width="100%" cellpadding="0" cellspacing="0" style="
			border:1px solid #eeeeee;
			border-radius:8px;
			margin:10px 0;
			padding:10px;
			background: #fff;
		">
			<tr>

				<td width="90" valign="top">

					' . ($image_url
						? '<img src="' . esc_url($image_url) . '" width="70" data-no-lazy="1" data-skip-lazy="1" class="no-lazy skip-lazy" style="border-radius:6px;margin-left:3px;">'
						: '') . '

				</td>

				<td valign="top">

					<div style="
						font-size:14px;
						font-weight:600;
						margin-bottom:4px;
						line-height:17px;
					">
						' . esc_html($item->get_name()) . '
					</div>

					<div style="
						color:#666;
						font-size:12px;
						line-height:8px;
					">
						SKU : ' . esc_html($sku ?: 'N/A') . '
					</div>

					<div style="
						color:#666;
						font-size:12px;
						margin-top:3px;
						line-height:8px;
					">
						Quantite : ' . $qty . '
					</div>

				</td>

			</tr>
		</table>';
	}

	$product_list = '

					<div style="
					font-size:14px;
					font-weight:bold;
					margin-bottom:20px;
					line-height:7px;
				">
					' . $total_articles . ' Article' . ($total_articles > 1 ? 's' : '') . '
				</div>

	' . $products_html;
	
	$store_id = ubn_get_order_store_id($order);

	$store_name = '';
	$mode = ubn_get_store_mode();

	if ($mode === 'single') {
		$store_name = get_option('ubn_ss_brand_name', get_bloginfo('name'));
	} else {
		switch ($store_id) {
			case '105':
				$store_name = 'Conforama Nord';
				break;
			case '106':
				$store_name = 'Conforama Sud';
				break;
			default:
				$store_name = get_option('ubn_ss_brand_name', get_bloginfo('name'));
				break;
		}
	}

    return [
		
		'{site_name}' =>
			get_option('ubn_ss_brand_name', get_bloginfo('name')),

		'{store_name}' => 
			$store_name,

        '{order_number}' =>
            $order->get_order_number(),

        '{order_id}' =>
            $order->get_id(),

        '{order_date}' =>
            $order->get_date_created()
            ? $order->get_date_created()->date_i18n('d/m/Y H:i')
            : '',

        '{order_total}' =>
            $order->get_total(),

        '{customer_name}' =>
            $order->get_formatted_billing_full_name(),

        '{customer_email}' =>
            $order->get_billing_email(),

        '{customer_phone}' =>
            $order->get_billing_phone(),

        '{shipping_address}' =>
            $order->get_formatted_shipping_address(),

        '{shipping_city}' =>
            $order->get_shipping_city(),

        '{shipping_postcode}' =>
            $order->get_shipping_postcode(),

        '{shipment_id}' =>
            $shipment_data['shipment_id'] ?? '',

        '{tracking_number}' =>
            $shipment_data['tracking_number'] ?? '',

        '{wallet_ref}' =>
            $shipment_data['wallet_ref'] ?? '',

        '{pdf_url}' =>
            $shipment_data['pdf_url'] ?? '',

        '{product_list}' =>
  			$product_list,

		 '{print_note_url}' =>
            home_url('/?ubn_print_note=' . $order->get_id() . '&token=' . ubn_generate_print_token($order->get_id())),
    ];
}
/*--------------------------------------------------------------
# Email Send for Expo Nord and Sud
--------------------------------------------------------------*/
function ubn_send_preparation_email(
    $order,
    $shipment_data = []
) {

    if (!$order) {
        return;
    }

    $order_id = $order->get_id();

    if (
        get_post_meta(
            $order_id,
            '_ubn_preparation_email_sent',
            true
        )
    ) {
        return;
    }

    $store_id = ubn_get_order_store_id($order);

    $emails =
        ubn_get_store_notification_emails(
            $store_id
        );

    if (empty($emails)) {
        return;
    }

    $subject = get_option(
        'ubn_ss_email_subject'
    );

    $template = get_option(
        'ubn_ss_email_template'
    );

    $tags =
        ubn_prepare_email_tags(
            $order,
            $shipment_data
        );

    $subject =
        str_replace(
            array_keys($tags),
            array_values($tags),
            $subject
        );

	$message = str_replace(
		array_keys($tags),
		array_values($tags),
		$template
	);

	$message = wpautop($message);

    $headers = [
        'Content-Type: text/html; charset=UTF-8'
    ];

    $sent = wp_mail(
        $emails,
        $subject,
        $message,
        $headers
    );

    if ($sent) {

        update_post_meta(
        $order_id,
        '_ubn_preparation_email_sent',
        current_time('mysql')
		);

		update_post_meta(
			$order_id,
			'_ubn_preparation_email_recipients',
			implode(', ', $emails)
		);

		update_post_meta(
			$order_id,
			'_ubn_preparation_email_subject',
			$subject
		);

        ubn_add_log(
            'email_success',
            '',
            '',
            $emails,
            '',
            $order_id,
            'Preparation email sent'
        );

    } else {

        ubn_add_log(
            'email_error',
            '',
            '',
            $emails,
            '',
            $order_id,
            'Preparation email failed'
        );
    }
}

/*--------------------------------------------------------------
# SHIPMENT FAILURE ALERT EMAIL
--------------------------------------------------------------*/
function ubn_send_shipment_failure_email(
    $order_id,
    $payload,
    $error_data
) {

    $raw_emails = get_option('ubn_ss_admin_error_emails', "saifwebservices@gmail.com, julien@soyoo.re");
    $recipients = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw_emails)));
    if (empty($recipients)) {
        $recipients = [get_option('admin_email')];
    }

    $subject =
        '[UBN] Shipment Creation Failed - Order #' . $order_id;

    $message = '';

    $message .= "A shipment creation attempt failed.\n\n";

    $message .= "Order ID: " . $order_id . "\n\n";

    $message .= "Error Details:\n";

    $message .= wp_json_encode(
        $error_data,
        JSON_PRETTY_PRINT
    );

    $message .= "\n\n\n";

    $message .= "Request Payload:\n";

    $message .= wp_json_encode(
        $payload,
        JSON_PRETTY_PRINT
    );

    $headers = [
        'Content-Type: text/plain; charset=UTF-8'
    ];

    wp_mail(
        $recipients,
        $subject,
        $message,
        $headers
    );
}
/*--------------------------------------------------------------
# MARK UBN ORDER AT CHECKOUT
--------------------------------------------------------------*/
add_action('woocommerce_checkout_create_order', 'ubn_mark_order_at_checkout', 20, 2);

function ubn_mark_order_at_checkout($order, $data) {

	if (!$order) {
		return;
	}

	if (!ubn_is_ubn_order($order)) {
		return;
	}

	$order->update_meta_data('_ubn_order','yes');
}
/*--------------------------------------------------------------
# CHECK IF SHIPPING METHOD IS UBN (OR MAPPED EXISTING METHOD)
--------------------------------------------------------------*/
function ubn_is_ubn_method_chosen($chosen_method_strings = []) {
	if (empty($chosen_method_strings)) {
		return false;
	}

	$chosen_method_strings = (array) $chosen_method_strings;

	// Single Store Mode with 'use_existing' mode active
	if (ubn_get_store_mode() === 'single' && get_option('ubn_ss_shipping_method_mode', 'create_new') === 'use_existing') {
		$mapped_methods = (array) get_option('ubn_ss_mapped_methods', []);
		foreach ($chosen_method_strings as $method_str) {
			$parts = explode(':', $method_str);
			$method_id = $parts[0] ?? $method_str;
			if (in_array($method_id, $mapped_methods, true) || in_array($method_str, $mapped_methods, true)) {
				return true;
			}
		}
		return false;
	}

	// Default & Multi-Store Mode: Check ubn_hub_express
	foreach ($chosen_method_strings as $method_str) {
		if (strpos($method_str, 'ubn_hub_express') !== false) {
			return true;
		}
	}

	return false;
}

/*--------------------------------------------------------------
# CHECK IF ORDER IS UBN ORDER
--------------------------------------------------------------*/
function ubn_is_ubn_order($order) {

	if (!$order) {
		return false;
	}

	$shipping_methods = $order->get_shipping_methods();

	if (empty($shipping_methods)) {
		return false;
	}

	$method_strings = [];
	foreach ($shipping_methods as $shipping_item) {
		$method_id   = $shipping_item->get_method_id();
		$instance_id = $shipping_item->get_instance_id();
		$method_strings[] = $method_id;
		if ($instance_id) {
			$method_strings[] = $method_id . ':' . $instance_id;
		}
	}

	return ubn_is_ubn_method_chosen($method_strings);
}

/*--------------------------------------------------------------
# BACKWARD COMPATIBILITY ALIAS
--------------------------------------------------------------*/
function ubn_order_uses_shipping($order) {
	return ubn_is_ubn_order($order);
}


/*--------------------------------------------------------------
# CREATE UBN SHIPMENT
--------------------------------------------------------------*/
function ubn_create_shipment($order_id) {

	$order = wc_get_order($order_id);

	if (!$order) {

		ubn_add_log(
			'shipment_error',
			'',
			'',
			'',
			'',
			$order_id,
			'Order not found'
		);

		return;
	}

	// Verify shipping method
	if (!ubn_is_ubn_order($order)) {
		return;
	}

	// Prevent duplicate shipment
if (get_post_meta($order_id, '_ubn_shipment_created', true)) {

	ubn_add_log(
		'debug',
		'',
		'',
		'',
		'',
		$order_id,
		'Shipment already exists'
	);

	return;
}


	$payload = ubn_build_shipment_payload($order);
	
	$config = ubn_get_api_config();

	if (empty($payload)) {

		ubn_add_log(
			'shipment_error',
			'',
			$payload,
			'',
			'',
			$order_id,
			'Failed to build shipment payload'
		);
		
		ubn_send_shipment_failure_email(
			$order_id,
			[],
			[
				'error' => 'Failed to build shipment payload'
			]
		);

		return;
	}

	$endpoint =
	trailingslashit(
		$config['api_base']
	) . 'shipments';

	$body_raw = wp_json_encode($payload);

	$timestamp = time();

	$message = $timestamp . "." . $body_raw;

	$signature = hash_hmac(
		"sha256",
		$message,
		$config['api_key']
	);

	$response = wp_remote_post($endpoint, [

		'headers' => [

			'X-UBN-API-KEY' =>
				$config['api_key'],

			'X-UBN-Source-Site' =>
				$config['source_site'],

			'X-UBN-Partner' =>
				$config['partner_id'],

			'X-UBN-Customer' =>
				$config['partner_id'],
			'X-UBN-Timestamp'   => $timestamp,
			'X-UBN-Sign'        => $signature,
			'Content-Type'      => 'application/json'
		],

		'body' => $body_raw,

		'timeout' => 30
	]);

	// WP Error
	if (is_wp_error($response)) {

		ubn_add_log(
			'shipment_error',
			$endpoint,
			$payload,
			$response->get_error_message(),
			'wp_error',
			$order_id,
			'Shipment request failed'
		);
		
		ubn_send_shipment_failure_email(
			$order_id,
			$payload,
			[
				'wp_error' =>
					$response->get_error_message()
			]
		);
		
		return;
	}
	

	$status_code = wp_remote_retrieve_response_code($response);
	
	

	$response_body = wp_remote_retrieve_body($response);

	$data = json_decode($response_body, true);

	// API failed
	if (
		$status_code >= 400 ||
		empty($data['success'])
	) {

		update_post_meta(
			$order_id,
			'_ubn_shipment_error',
			wp_json_encode([
				'status_code' => $status_code,
				'response'    => $data,
				'raw'         => $response_body,
			], JSON_PRETTY_PRINT)
		);

		ubn_add_log(
			'shipment_error',
			$endpoint,
			$payload,
			[
				'status_code' => $status_code,
				'raw_response' => $response_body,
				'decoded_response' => $data,
			],
			$status_code,
			$order_id,
			'Shipment API failed'
		);
		
		ubn_send_shipment_failure_email(
			$order_id,
			$payload,
			[
				'status_code' => $status_code,
				'raw_response' => $response_body,
				'decoded_response' => $data,
			]
		);

		return;
	}
	
	delete_post_meta($order_id, '_ubn_shipment_error');

	update_post_meta(
		$order_id,
		'_ubn_shipment_success',
		wp_json_encode($data, JSON_PRETTY_PRINT)
	);
	
	
	// Save shipment meta
	update_post_meta($order_id, '_ubn_shipment_created', 'yes');

	update_post_meta(
		$order_id,
		'_ubn_tracking_number',
		$data['tracking_number'] ?? ''
	);

	update_post_meta(
		$order_id,
		'_ubn_shipment_id',
		$data['shipment_id'] ?? ''
	);

	update_post_meta(
		$order_id,
		'_ubn_wallet_debit',
		$data['wallet_debit'] ?? ''
	);

	update_post_meta(
		$order_id,
		'_ubn_wallet_ref',
		$data['wallet_ref'] ?? ''
	);

	update_post_meta(
		$order_id,
		'_ubn_pdf_url',
		$data['pdf_url'] ?? ''
	);

	update_post_meta(
		$order_id,
		'_ubn_shipment_response',
		$data
	);
	
	ubn_send_preparation_email(
		$order,
		$data
	);

	// Success log
	ubn_add_log(
		'shipment_success',
		$endpoint,
		$payload,
		$data,
		$status_code,
		$order_id,
		'Shipment created successfully'
	);
}
/*--------------------------------------------------------------

# GET ORDER STORE ID

--------------------------------------------------------------*/
function ubn_get_order_store_id($order) {

	if (!$order) {
		return '';
	}

	$line_items = $order->get_items();

	foreach ($line_items as $item_id => $item) {

		$meta_data = $item->get_meta_data();

		foreach ($meta_data as $meta) {

			if (
				$meta->key === '_woocommerce_multi_inventory_inventory'
			) {

				return (string) $meta->value;
			}
		}
	}

	return '';

}

/*--------------------------------------------------------------

# GET STORE SHIPPER DATA

--------------------------------------------------------------*/
function ubn_get_store_shipper_data($store_id = '') {
	$mode = ubn_get_store_mode();

	if ($mode === 'single' || empty($store_id)) {
		$wc_country_raw = get_option('woocommerce_default_country', 'RE');
		$wc_country_parts = explode(':', $wc_country_raw);
		$wc_country = $wc_country_parts[0] ?? 'RE';

		return [
			'company'   => get_option('ubn_single_store_company', get_option('ubn_ss_brand_name', get_bloginfo('name'))),
			'name'      => get_option('ubn_single_store_name', get_option('ubn_ss_brand_name', get_bloginfo('name'))),
			'firstname' => get_option('ubn_single_store_firstname', ''),
			'country'   => get_option('ubn_single_store_country', $wc_country),
			'postcode'  => get_option('ubn_single_store_postcode', get_option('woocommerce_store_postcode', '')),
			'city'      => get_option('ubn_single_store_city', get_option('woocommerce_store_city', '')),
			'address'   => get_option('ubn_single_store_address', get_option('woocommerce_store_address', '')),
			'address2'  => get_option('ubn_single_store_address2', get_option('woocommerce_store_address_2', '')),
			'phone'        => get_option('ubn_single_store_phone', ''),
			'email'        => get_option('ubn_single_store_email', get_option('admin_email', '')),
			'pickup_hours' => get_option('ubn_single_store_pickup_hours', '10:00 - 18:00'),
		];
	}

	return [

		'company' => get_option(
			'ubn_store_' . $store_id . '_company',
			''
		),

		'name' => get_option(
			'ubn_store_' . $store_id . '_name',
			''
		),

		'firstname' => get_option(
			'ubn_store_' . $store_id . '_firstname',
			''
		),

		'country' => get_option(
			'ubn_store_' . $store_id . '_country',
			''
		),

		'postcode' => get_option(
			'ubn_store_' . $store_id . '_postcode',
			''
		),

		'city' => get_option(
			'ubn_store_' . $store_id . '_city',
			''
		),

		'address' => get_option(
			'ubn_store_' . $store_id . '_address',
			''
		),

		'address2' => get_option(
			'ubn_store_' . $store_id . '_address2',
			''
		),

		'phone' => get_option(
			'ubn_store_' . $store_id . '_phone',
			''
		),

		'email' => get_option(
			'ubn_store_' . $store_id . '_email',
			''
		),

		'pickup_hours' => get_option(
			'ubn_store_' . $store_id . '_pickup_hours',
			'10:00 - 18:00'
		),
	];
}
/*--------------------------------------------------------------

# NORMALIZE REUNION CITY FOR UBN

--------------------------------------------------------------*/
function ubn_normalize_reunion_city($postcode, $city = '') {

$map = [

	'97439' => 'Sainte-Rose',
	'97437' => 'Saint-Anne',
	'97431' => 'La Plaine-des-Palmistes',
	'97470' => 'Saint-Benoît',
	'97412' => 'Bras-Panon',
	'97433' => 'Salazie',
	'97440' => 'Saint-André',
	'97441' => 'Sainte-Suzanne',
	'97438' => 'Sainte-Marie',
	'97490' => 'Sainte-Clotilde',
	'97400' => 'Saint-Denis',
	'97417' => 'La Montagne',
	'97419' => 'La Possession',
	'97420' => 'Le Port',
	'97460' => 'Saint-Paul',
	'97435' => 'Saint Gilles Les Hauts',
	'97422' => 'La Saline',
	'97434' => 'La Saline Les Bains',
	'97436' => 'Saint-Leu',
	'97425' => 'Les Avirons',
	'97427' => 'Etang-Salé',
	'97450' => 'Saint-Louis',
	'97414' => 'Entre-Deux',
	'97430' => 'Le Tampon',
	'97432' => 'Ravine des Cabris',
	'97410' => 'Saint-Pierre',
	'97429' => 'Petite-Île',
	'97480' => 'Saint-Joseph',
	'97442' => 'Saint-Philippe',
	
	// --- Taken from arranged data supplied by Julien
	'97411' => 'Bois Nèfles Saint Paul',
	'97423' => 'Guillaume',
	'97426' => 'Les Trois-Bassins',
	'97416' => 'Chaloupe Saint Leu',
	'97424' => 'Piton Saint Leu',
	'97421' => 'La Rivière Saint Louis',
	'97418' => 'La Plaines des Cafres',

	// --- Taken from the raw data supplied from email with attachement.
	'97405' => 'Saint-Denis',
	'97457' => 'Saint-Pierre',
	'97456' => 'Saint-Pierre',
	'97448' => 'Saint-Pierre',
	'97491' => 'Sainte-Clotilde',
];

$postcode = trim((string) $postcode);

// Exact postcode match
if (isset($map[$postcode])) {
	return $map[$postcode];
}

// Safe fallback
return $city;

}

/*--------------------------------------------------------------
# BUILD SHIPMENT PAYLOAD
--------------------------------------------------------------*/
function ubn_build_shipment_payload($order) {

	if (!$order) {
		return [];
	}
	$store_id = ubn_get_order_store_id($order);

	$shipper = ubn_get_store_shipper_data($store_id);

	$disable_weight = get_option('ubn_ss_disable_weight_calc', '0');
	$default_weight = (float) get_option('ubn_ss_default_package_weight', '1.0');
	if ($default_weight <= 0) {
		$default_weight = 1.0;
	}

	$items = [];

	$total_weight = 0;
	$total_value  = 0;
	$max_length   = 10; // Default fallback minimum dimension array
	$max_width    = 10;
	$max_height   = 10;

	if ($disable_weight === '1') {
		// 1. Weight calculation DISABLED: Force default package weight
		$total_weight = $default_weight;
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if ($product) {
				$qty = (int) $item->get_quantity();
				$total_value += ((float) $product->get_price()) * $qty;
			}
		}
	} else {
		// 2. Weight calculation ENABLED: Accumulate actual product weights
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if (!$product) {
				continue;
			}

			$qty = (int) $item->get_quantity();
			
			$p_weight = (float) $product->get_weight();
			$total_weight += ($p_weight > 0 ? $p_weight : $default_weight) * $qty;
			$total_value  += ((float) $product->get_price()) * $qty;

			// Track largest bounding box dimensions required to fit items
			$p_length = (float) $product->get_length() ?: 10.0;
			$p_width  = (float) $product->get_width() ?: 10.0;
			$p_height = (float) $product->get_height() ?: 10.0;

			if ($p_length > $max_length) { $max_length = $p_length; }
			if ($p_width > $max_width)   { $max_width  = $p_width;  }
			if ($p_height > $max_height) { $max_height = $p_height; }
		}

		if ($total_weight <= 0) {
			$total_weight = $default_weight;
		}
	}
	
	/*--------------------------------------------------------------
	# PACKAGE DESCRIPTION
	--------------------------------------------------------------*/

	$product_descriptions = [];
	$total_articles       = 0; // Initialize article counter

	foreach ($order->get_items() as $item) {
		
		$item_qty = (int) $item->get_quantity();
		
		// 1. Add to total article count
		$total_articles += $item_qty;

		// 2. Format individual item strings
		$product_descriptions[] = $item->get_name() . ' x' . $item_qty;
	}

	// 3. Assemble with prefix and a strong visual delimiter
	$package_description = $total_articles . ' ARTICLES : ' . implode('  •  ', $product_descriptions);

	// 4. Dynamic UBN Item Type Resolution (Colis vs Colis XL based on weight/dimensions)
	$sum_dimensions = ($max_length + $max_width + $max_height);
	$item_type      = 'Colis';

	if ((float) $total_weight > 30.0 || (float) $sum_dimensions > 150.0) {
		$item_type = 'Colis XL';
	}

	// 5. Declare EXACTLY one unified package block for the complete order
	$items[] = [
		'qty'            => 1, // Strictly 1 order = 1 package parcel
		'type'           => $item_type,
		'description'	 => $package_description,
		'weight'         => $total_weight,
		'length'         => $max_length,
		'width'          => $max_width,
		'height'         => $max_height,
		'sum_dimensions' => $sum_dimensions,
		'value'          => $total_value
	];
	
	$config = ubn_get_api_config();

	return [

		'id_api_connect' =>
			$config['partner_id'],

		'ubn_sr_source_site' =>
			$config['source_site'],

		'ref_commande' => 'WC-' . $order->get_order_number(),

		'service' => get_option(
			'ubn_ss_service',
			'express'
		),

		'type_of_shipment' => 'Intradepartement',
		'wpcargo_shipper_company_name' =>
		$shipper['company'] ?? '',

		'wpcargo_shipper_name' =>
		$shipper['name'] ?? '',

		'wpcargo_shipper_firstname' =>
		$shipper['firstname'] ?? '',

		'wpcargo_shipper_addressp' =>
		$shipper['country'] ?? '',

		'wpcargo_shipper_addresscp' =>
		$shipper['postcode'] ?? '',

		'wpcargo_shipper_addressv' =>
		$shipper['city'] ?? '',

		'wpcargo_shipper_address' =>
		$shipper['address'] ?? '',

		'wpcargo_shipper_address_additoinal' =>
		$shipper['address2'] ?? '',

		'wpcargo_shipper_phone' =>
		$shipper['phone'] ?? '',

		'wpcargo_shipper_email' =>
		$shipper['email'] ?? '',

		'receiver_name' =>
			$order->get_shipping_last_name()
			?: $order->get_billing_last_name(),

		'receiver_firstname' =>
			$order->get_shipping_first_name()
			?: $order->get_billing_first_name(),

		'receiver_phone' =>
			$order->get_billing_phone(),

		'receiver_email' =>
			$order->get_billing_email(),

		'receiver_address' =>
			$order->get_shipping_address_1()
			?: $order->get_billing_address_1(),

		'wpcargo_receiver_address_additoinal' =>
			$order->get_shipping_address_2()
			?: $order->get_billing_address_2(),

		'receiver_postcode' =>
			$order->get_shipping_postcode()
			?: $order->get_billing_postcode(),

		'receiver_city' =>
			ubn_normalize_reunion_city(
				$order->get_shipping_postcode()
					?: $order->get_billing_postcode(),

				$order->get_shipping_city()
					?: $order->get_billing_city()
			),

		'wpcargo_receiver_addressp' =>
			$order->get_shipping_country() === 'RE'
				? 'La Réunion'
				: WC()->countries->countries[
					$order->get_shipping_country()
				] ?? 'La Réunion',

		'mode_reprog' => 'Manual rescheduling',
		'creneau_horaire_enlevement' => $shipper['pickup_hours'] ?? '10:00 - 18:00',
		'items' => $items
	];
}

/*--------------------------------------------------------------
# POSTCODE VALIDATION
--------------------------------------------------------------*/
function ubn_postcode_allowed() {

    if (!WC()->customer) {
        return true;
    }

    $postcode = trim(
        WC()->customer->get_shipping_postcode()
    );

    if (empty($postcode)) {
        return true;
    }

    $blocked = get_option(
        'ubn_ss_blocked_postcodes',
        ''
    );

    $blocked_codes = array_filter(
		array_map(
			'trim',
			preg_split('/[\r\n,]+/', $blocked)
		)
	);

    return !in_array(
        $postcode,
        $blocked_codes,
        true
    );
}

/*--------------------------------------------------------------
# ADMIN SETTINGS
--------------------------------------------------------------*/
add_action('admin_menu', function () {
    add_options_page('UBN Speed Shipping', 'UBN Speed Shipping', 'manage_options', 'ubn-ss', 'ubn_ss_settings_page');
});

add_action('admin_init', function () {
    register_setting('ubn_ss_general', 'ubn_ss_enabled');
	register_setting('ubn_ss_general', 'ubn_ss_admin_only_frontend');
    register_setting('ubn_ss_general', 'ubn_ss_tax_none'); 
	register_setting('ubn_ss_general','ubn_ss_blocked_postcodes');
	register_setting('ubn_ss_general', 'ubn_ss_service');
	register_setting('ubn_ss_general', 'ubn_ss_label');
	register_setting('ubn_ss_general', 'ubn_ss_description'); 
	register_setting('ubn_ss_general', 'ubn_ss_price_per_qty');
	register_setting('ubn_ss_general', 'ubn_ss_store_mode');
	register_setting('ubn_ss_general', 'ubn_ss_shipping_method_mode');
	register_setting('ubn_ss_general', 'ubn_ss_mapped_methods');
	register_setting('ubn_ss_general', 'ubn_ss_disable_weight_calc');
	register_setting('ubn_ss_general', 'ubn_ss_default_package_weight');
    register_setting('ubn_ss_categories_group', 'ubn_ss_categories');

	/*--------------------------------------------------------------
	# STYLES SETTINGS
	--------------------------------------------------------------*/
	register_setting('ubn_ss_styles', 'ubn_ss_primary_color');
	register_setting('ubn_ss_styles', 'ubn_ss_brand_name');

	/*--------------------------------------------------------------
	# SINGLE STORE SHIPPER SETTINGS
	--------------------------------------------------------------*/
	register_setting('ubn_ss_shipper', 'ubn_single_store_company');
	register_setting('ubn_ss_shipper', 'ubn_single_store_name');
	register_setting('ubn_ss_shipper', 'ubn_single_store_firstname');
	register_setting('ubn_ss_shipper', 'ubn_single_store_country');
	register_setting('ubn_ss_shipper', 'ubn_single_store_postcode');
	register_setting('ubn_ss_shipper', 'ubn_single_store_city');
	register_setting('ubn_ss_shipper', 'ubn_single_store_address');
	register_setting('ubn_ss_shipper', 'ubn_single_store_address2');
	register_setting('ubn_ss_shipper', 'ubn_single_store_phone');
	register_setting('ubn_ss_shipper', 'ubn_single_store_email');
	register_setting('ubn_ss_shipper', 'ubn_single_store_pickup_hours');
	
	/*--------------------------------------------------------------
	# MULTI STORE SHIPPER SETTINGS (CONFORAMA)
	--------------------------------------------------------------*/

	// NORD
	register_setting('ubn_ss_shipper', 'ubn_store_105_company');
	register_setting('ubn_ss_shipper', 'ubn_store_105_name');
	register_setting('ubn_ss_shipper', 'ubn_store_105_firstname');
	register_setting('ubn_ss_shipper', 'ubn_store_105_country');
	register_setting('ubn_ss_shipper', 'ubn_store_105_postcode');
	register_setting('ubn_ss_shipper', 'ubn_store_105_city');
	register_setting('ubn_ss_shipper', 'ubn_store_105_address');
	register_setting('ubn_ss_shipper', 'ubn_store_105_address2');
	register_setting('ubn_ss_shipper', 'ubn_store_105_phone');
	register_setting('ubn_ss_shipper', 'ubn_store_105_email');
	register_setting('ubn_ss_shipper', 'ubn_store_105_pickup_hours');

	// SUD
	register_setting('ubn_ss_shipper', 'ubn_store_106_company');
	register_setting('ubn_ss_shipper', 'ubn_store_106_name');
	register_setting('ubn_ss_shipper', 'ubn_store_106_firstname');
	register_setting('ubn_ss_shipper', 'ubn_store_106_country');
	register_setting('ubn_ss_shipper', 'ubn_store_106_postcode');
	register_setting('ubn_ss_shipper', 'ubn_store_106_city');
	register_setting('ubn_ss_shipper', 'ubn_store_106_address');
	register_setting('ubn_ss_shipper', 'ubn_store_106_address2');
	register_setting('ubn_ss_shipper', 'ubn_store_106_phone');
	register_setting('ubn_ss_shipper', 'ubn_store_106_email');
	register_setting('ubn_ss_shipper', 'ubn_store_106_pickup_hours');
	
	// API configuration settings
	// Mode 
	register_setting('ubn_ss_api_config', 'ubn_ss_api_mode');
	register_setting('ubn_ss_api_config', 'ubn_ss_admin_error_emails');
	// Production
	register_setting('ubn_ss_api_config', 'ubn_prod_api_base');
	register_setting('ubn_ss_api_config', 'ubn_prod_api_key');
	register_setting('ubn_ss_api_config', 'ubn_prod_hmac_secret');
	register_setting('ubn_ss_api_config', 'ubn_prod_partner_id');
	register_setting('ubn_ss_api_config', 'ubn_prod_source_site');

	// Test
	register_setting('ubn_ss_api_config', 'ubn_test_api_base');
	register_setting('ubn_ss_api_config', 'ubn_test_api_key');
	register_setting('ubn_ss_api_config', 'ubn_test_hmac_secret');
	register_setting('ubn_ss_api_config', 'ubn_test_partner_id');
	register_setting('ubn_ss_api_config', 'ubn_test_source_site');
	
	// Email
	register_setting('ubn_ss_email', 'ubn_ss_email_subject');
	register_setting('ubn_ss_email', 'ubn_ss_email_template');
	register_setting('ubn_ss_email', 'ubn_single_store_notification_emails');
	register_setting('ubn_ss_email', 'ubn_store_105_notification_emails');
	register_setting('ubn_ss_email', 'ubn_store_106_notification_emails');

	// Delivery Note
	register_setting('ubn_ss_delivery_note', 'ubn_ss_delivery_note_template');
	});

/*--------------------------------------------------------------
# SETTINGS PAGE
--------------------------------------------------------------*/
function ubn_ss_settings_page() {
    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    $selected_cats = (array) get_option('ubn_ss_categories', []);

    $active_tab = $_GET['tab'] ?? 'general';
?>

	<style>
	.ubn-wrap {
		max-width: 1200px;
	}

	.ubn-card {
		background: #fff;
		border-radius: 12px;
		padding: 20px;
		margin-top: 20px;
		box-shadow: 0 2px 10px rgba(0,0,0,0.05);
	}

	.ubn-title {
		font-size: 20px;
		font-weight: 600;
		margin-bottom: 15px;
	}

	.ubn-switch {
		position: relative;
		display: inline-block;
		width: 46px;
		height: 24px;
	}

	.ubn-switch input {
		opacity: 0;
		width: 0;
		height: 0;
	}

	.ubn-slider {
		position: absolute;
		cursor: pointer;
		background-color: #ccc;
		border-radius: 24px;
		top: 0; left: 0; right: 0; bottom: 0;
		transition: .3s;
	}

	.ubn-slider:before {
		position: absolute;
		content: "";
		height: 18px;
		width: 18px;
		left: 3px;
		bottom: 3px;
		background-color: white;
		border-radius: 50%;
		transition: .3s;
	}

	.ubn-switch input:checked + .ubn-slider {
		background-color: #2271b1;
	}

	.ubn-switch input:checked + .ubn-slider:before {
		transform: translateX(22px);
	}

	.ubn-field {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 15px;
	}

	.ubn-select {
		min-width: 250px;
		padding: 6px 10px;
		border-radius: 6px;
	}

	.ubn-list {
		columns: 2;
	}

	.nav-tab-wrapper {
		margin-top: 20px;
	}
	</style>

	<div class="wrap ubn-wrap">
	<h1>🚀 UBN Speed Shipping</h1>

	<h2 class="nav-tab-wrapper">
	<a href="?page=ubn-ss&tab=general" class="nav-tab <?php echo $active_tab=='general'?'nav-tab-active':''; ?>">General</a>
	<a href="?page=ubn-ss&tab=categories" class="nav-tab <?php echo $active_tab=='categories'?'nav-tab-active':''; ?>">Categories</a>
	<a href="?page=ubn-ss&tab=shipper-details" class="nav-tab <?php echo $active_tab=='shipper-details'?'nav-tab-active':''; ?>">Shipper Details</a>
	<a href="?page=ubn-ss&tab=styles" class="nav-tab <?php echo $active_tab=='styles'?'nav-tab-active':''; ?>">Styles</a>
	<a href="?page=ubn-ss&tab=zone-manager" class="nav-tab <?php echo $active_tab=='zone-manager'?'nav-tab-active':''; ?>">Zone Manager</a>
	<a href="?page=ubn-ss&tab=ubn-speed-configuration" class="nav-tab <?php echo $active_tab=='ubn-speed-configuration'?'nav-tab-active':''; ?>">UBN Speed Configuration</a>
	<a href="?page=ubn-ss&tab=emails" class="nav-tab <?php echo $active_tab=='emails'?'nav-tab-active':''; ?>"> Emails</a>
	<a href="?page=ubn-ss&tab=delivery-note" class="nav-tab <?php echo $active_tab=='delivery-note'?'nav-tab-active':''; ?>">Delivery Note</a>
	<a href="?page=ubn-ss&tab=orders" class="nav-tab <?php echo $active_tab=='orders'?'nav-tab-active':''; ?>">Orders</a>
	<a href="?page=ubn-ss&tab=logs" class="nav-tab <?php echo $active_tab=='logs'?'nav-tab-active':''; ?>">Logs</a>
	<a href="?page=ubn-ss&tab=daily-report" class="nav-tab <?php echo $active_tab=='daily-report'?'nav-tab-active':''; ?>">Daily Report</a>
	</h2>

	<?php if ($active_tab=='general'): ?>
	<form method="post" action="options.php">
	<?php settings_fields('ubn_ss_general'); ?>

	<div class="ubn-card">
	<div class="ubn-title">General Settings</div>

	<div class="ubn-field">
	<div>
		<strong>Store Management Mode</strong><br>
		<small>Select Single Store Mode (Default) or Multi Store Mode (Conforama 2-Store Setup)</small>
	</div>
	<select name="ubn_ss_store_mode" class="ubn-select">
		<option value="single" <?php selected(ubn_get_store_mode(), 'single'); ?>>Single Store Mode (Default)</option>
		<option value="multi" <?php selected(ubn_get_store_mode(), 'multi'); ?>>Multi Store Mode (Conforama Setup)</option>
	</select>
	</div>

	<div class="ubn-field">
	<label>Enable Shipping</label>
	<label class="ubn-switch">
	<input type="checkbox" name="ubn_ss_enabled" value="1" <?php checked(get_option('ubn_ss_enabled'),1); ?>>
	<span class="ubn-slider"></span>
	</label>
	</div>

	<div class="ubn-field">
	<label>Enable only for Admin</label>

	<label class="ubn-switch">

	<input
		type="checkbox"
		name="ubn_ss_admin_only_frontend"
		value="1"
		<?php checked(get_option('ubn_ss_admin_only_frontend'), 1); ?>
	>

	<span class="ubn-slider"></span>

	</label>
	</div>

	<div class="ubn-field">
	<label>Disable Tax (UBN Only)</label>
	<label class="ubn-switch">
	<input type="checkbox" name="ubn_ss_tax_none" value="1" <?php checked(get_option('ubn_ss_tax_none'),1); ?>>
	<span class="ubn-slider"></span>
	</label>
	</div>
		
	<div class="ubn-field" style="align-items:flex-start;">

		<label>
			Blocked ZIP/Postcodes
			<br>
			<small>
				Comma-separated postcodes
			</small>
		</label>

		<input
			type="text"
			name="ubn_ss_blocked_postcodes"
			value="<?php echo esc_attr(
				get_option('ubn_ss_blocked_postcodes', '')
			); ?>"
			class="regular-text"
			style="min-width:350px;"
			placeholder="97411, 97412, 97413"
		/>

	</div>

	<div class="ubn-field">
	<label>Shipping Label</label>

	<input
		type="text"
		name="ubn_ss_label"
		value="<?php echo esc_attr(get_option('ubn_ss_label', 'Livraison rapide par colis')); ?>"
		class="regular-text"
		style="min-width:250px;"
	>
	</div>
	
	<div class="ubn-field">
		<label>Shipping Description</label>

		<input
			type="text"
			name="ubn_ss_description"
			value="<?php echo esc_attr(get_option('ubn_ss_description', '')); ?>"
			class="regular-text"
			style="min-width:250px;"
			placeholder="Displays below label at checkout"
		>
	</div>
	<div class="ubn-field">

	<label>Fixed Shipping Fee Per Order</label>

		<div style="display:flex; align-items:center; gap:10px;">

			<input
				type="number"
				step="0.01"
				min="0"
				name="ubn_ss_price_per_qty"
				value="<?php echo esc_attr(get_option('ubn_ss_price_per_qty', '15')); ?>"
				class="small-text"
				style="width:120px;"
			>

			<span>
				<?php echo get_woocommerce_currency_symbol(); ?>
			</span>

		</div>

	</div>

	<div class="ubn-field">
	<label>Select Service</label>
	<select class="ubn-select" name="ubn_ss_service">
	<?php
	$services = ubn_ss_get_services();
	$selected = get_option('ubn_ss_service');

	foreach ($services as $s) {
		echo '<option value="'.$s['id'].'" '.selected($selected,$s['id'],false).'>'.$s['label'].'</option>';
	}
	?>
	</select>
	</div>

	<div class="ubn-field" style="margin-top:15px; border-top:1px solid #eee; padding-top:15px;">
		<div>
			<strong>Disable Product Weight Calculation</strong>
			<div style="color:#666; font-size:12px; margin-top:2px;">
				Ignore product weights from WooCommerce and force default package weight (keeps standard Colis rate type).
			</div>
		</div>
		<label class="ubn-switch">
			<input type="checkbox" name="ubn_ss_disable_weight_calc" value="1" <?php checked(get_option('ubn_ss_disable_weight_calc', '0'), '1'); ?>>
			<span class="ubn-slider round"></span>
		</label>
	</div>

	<div class="ubn-field">
		<div>
			<strong>Default Package Weight (kg)</strong>
			<div style="color:#666; font-size:12px; margin-top:2px;">
				Fallback weight used when weight calculation is disabled or products have no weight set (Default: 1.0 kg).
			</div>
		</div>
		<input
			type="number"
			step="0.1"
			min="0.1"
			name="ubn_ss_default_package_weight"
			value="<?php echo esc_attr(get_option('ubn_ss_default_package_weight', '1.0')); ?>"
			class="small-text"
			style="width:120px;"
		>
	</div>

	<?php if (ubn_get_store_mode() === 'single'): ?>
	</div> <!-- Close primary .ubn-card -->

	<div class="ubn-card" style="margin-top:20px;">
		<div class="ubn-title" style="margin-bottom:6px;">Shipping Method Integration Mode</div>
		<p style="color:#666; font-size:13px; margin-bottom:20px; line-height:1.5;">
			Choose whether UBN Speed Shipping operates using a dedicated shipping method or links to existing WooCommerce zone shipping methods.
		</p>

		<?php $method_mode = get_option('ubn_ss_shipping_method_mode', 'create_new'); ?>

		<div style="display:flex; flex-direction:column; gap:12px; margin-bottom:20px;">
			<!-- Option 1: Dedicated UBN Method -->
			<label style="display:block; padding:15px; border-radius:8px; border:2px solid <?php echo $method_mode === 'create_new' ? '#52a74f' : '#e2e8f0'; ?>; background:<?php echo $method_mode === 'create_new' ? '#f4faf4' : '#fff'; ?>; cursor:pointer; transition:all 0.2s ease;" id="ubn_mode_card_create_new">
				<div style="display:flex; align-items:flex-start; gap:10px;">
					<input type="radio" name="ubn_ss_shipping_method_mode" value="create_new" <?php checked($method_mode, 'create_new'); ?> style="margin-top:3px;" onchange="ubnToggleIntegrationMode('create_new');">
					<div>
						<strong style="font-size:14px; color:#111;">Create/Use Dedicated UBN Shipping Method (Default)</strong>
						<div style="color:#666; font-size:12px; margin-top:4px; line-height:1.4;">
							Uses the dedicated <code>ubn_hub_express</code> shipping method managed via the Zone Manager tab.
						</div>
					</div>
				</div>
			</label>

			<!-- Option 2: Link to Existing Methods -->
			<label style="display:block; padding:15px; border-radius:8px; border:2px solid <?php echo $method_mode === 'use_existing' ? '#52a74f' : '#e2e8f0'; ?>; background:<?php echo $method_mode === 'use_existing' ? '#f4faf4' : '#fff'; ?>; cursor:pointer; transition:all 0.2s ease;" id="ubn_mode_card_use_existing">
				<div style="display:flex; align-items:flex-start; gap:10px;">
					<input type="radio" name="ubn_ss_shipping_method_mode" value="use_existing" <?php checked($method_mode, 'use_existing'); ?> style="margin-top:3px;" onchange="ubnToggleIntegrationMode('use_existing');">
					<div>
						<strong style="font-size:14px; color:#111;">Link to Existing WooCommerce Shipping Methods</strong>
						<div style="color:#666; font-size:12px; margin-top:4px; line-height:1.4;">
							Select existing WooCommerce shipping methods from your shipping zones below to trigger UBN Speed Shipping processing upon order placement.
						</div>
					</div>
				</div>
			</label>
		</div>

		<!-- Dynamic Mapped Methods Box -->
		<div id="ubn_mapped_methods_wrapper" style="background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #cbd5e1; display:<?php echo $method_mode === 'use_existing' ? 'block' : 'none'; ?>;">
			<div style="font-weight:600; font-size:14px; color:#1e293b; margin-bottom:4px;">
				Select Mapped WooCommerce Zone Shipping Methods
			</div>
			<p style="color:#64748b; font-size:12px; margin-bottom:15px;">
				Check the existing shipping methods from your WooCommerce zones that should be processed by UBN Speed Shipping:
			</p>
			<?php
			$mapped_methods = (array) get_option('ubn_ss_mapped_methods', []);
			$all_methods = [];

			if (class_exists('WC_Shipping_Zones')) {
				$all_zones = WC_Shipping_Zones::get_zones();
				foreach ($all_zones as $zone_data) {
					$zone = new WC_Shipping_Zone($zone_data['id']);
					$zone_name = $zone->get_zone_name();
					foreach ($zone->get_shipping_methods() as $instance_id => $method) {
						if ($method->id === 'ubn_hub_express') continue;
						$key = $method->id . ':' . $instance_id;
						$all_methods[$key] = [
							'id'    => $method->id,
							'key'   => $key,
							'title' => $method->get_title(),
							'zone'  => $zone_name,
						];
					}
				}

				// Zone 0 (Default / Rest of World Zone)
				$default_zone = new WC_Shipping_Zone(0);
				foreach ($default_zone->get_shipping_methods() as $instance_id => $method) {
					if ($method->id === 'ubn_hub_express') continue;
					$key = $method->id . ':' . $instance_id;
					$all_methods[$key] = [
						'id'    => $method->id,
						'key'   => $key,
						'title' => $method->get_title(),
						'zone'  => 'Default Zone',
					];
				}
			}

			if (!empty($all_methods)) {
				echo '<div style="display:flex; flex-direction:column; gap:8px;">';
				foreach ($all_methods as $m_key => $m_data) {
					$is_checked = in_array($m_key, $mapped_methods, true) || in_array($m_data['id'], $mapped_methods, true);
					echo '<label style="display:flex; align-items:center; justify-content:space-between; background:#ffffff; padding:10px 14px; border-radius:6px; border:1px solid #e2e8f0; cursor:pointer;">';
					echo '<span style="display:flex; align-items:center; gap:10px;">';
					echo '<input type="checkbox" name="ubn_ss_mapped_methods[]" value="' . esc_attr($m_key) . '" ' . checked($is_checked, true, false) . '> ';
					echo '<strong style="font-size:13px; color:#334155;">' . esc_html($m_data['title']) . '</strong>';
					echo '</span>';
					echo '<span style="font-size:11px; color:#64748b; background:#f1f5f9; padding:3px 8px; border-radius:4px; font-weight:500;">Zone: ' . esc_html($m_data['zone']) . ' | ID: ' . esc_html($m_key) . '</span>';
					echo '</label>';
				}
				echo '</div>';
			} else {
				echo '<div style="color:#94a3b8; font-size:13px; padding:10px; background:#fff; border-radius:6px; border:1px solid #e2e8f0;">No non-UBN shipping methods found in your WooCommerce Shipping Zones.</div>';
			}
			?>
		</div>

		<script>
		function ubnToggleIntegrationMode(mode) {
			var wrapper = document.getElementById('ubn_mapped_methods_wrapper');
			var cardCreate = document.getElementById('ubn_mode_card_create_new');
			var cardExisting = document.getElementById('ubn_mode_card_use_existing');

			if (wrapper) {
				wrapper.style.display = (mode === 'use_existing') ? 'block' : 'none';
			}
			if (cardCreate && cardExisting) {
				if (mode === 'create_new') {
					cardCreate.style.borderColor = '#52a74f';
					cardCreate.style.background = '#f4faf4';
					cardExisting.style.borderColor = '#e2e8f0';
					cardExisting.style.background = '#ffffff';
				} else {
					cardExisting.style.borderColor = '#52a74f';
					cardExisting.style.background = '#f4faf4';
					cardCreate.style.borderColor = '#e2e8f0';
					cardCreate.style.background = '#ffffff';
				}
			}
		}
		</script>

	<?php else: ?>
	</div> <!-- Close primary .ubn-card -->
	<?php endif; ?>

	<?php submit_button('Save Settings'); ?>
	</form>
	<?php endif; ?>


	<?php if ($active_tab=='categories'): ?>
	<form method="post" action="options.php">
	<?php settings_fields('ubn_ss_categories_group'); ?>

	<div class="ubn-card">
	<div class="ubn-title">Allowed Categories</div>

	<div class="ubn-list">
	<?php foreach ($categories as $cat): ?>
	<label>
	<input type="checkbox" name="ubn_ss_categories[]" value="<?php echo $cat->term_id; ?>"
	<?php checked(in_array($cat->term_id,$selected_cats)); ?>>
	<?php echo $cat->name; ?>
	</label><br>
	<?php endforeach; ?>
	</div>

	</div>

	<?php submit_button('Save Categories'); ?>
	</form>
	<?php endif; ?>

	<?php if ($active_tab=='shipper-details'): ?>

	<form method="post" action="options.php">

	<?php settings_fields('ubn_ss_shipper'); ?>

	<div class="ubn-card">

	<?php if (ubn_get_store_mode() === 'single'): ?>

		<div class="ubn-title" style="display:flex; justify-content:space-between; align-items:center;">
			<span>Single Store Shipper Details</span>
			<button type="button" class="button button-secondary" id="ubn-prefill-wc-btn">
				📍 Pre-fill from WooCommerce Store Address
			</button>
		</div>
		<p style="color:#666; font-size:13px; margin-bottom:20px;">
			Configure the primary shipper/sender address used for UBN Speed shipping payloads.
		</p>

		<?php
		$wc_country_raw = get_option('woocommerce_default_country', 'RE');
		$wc_country_parts = explode(':', $wc_country_raw);
		$wc_country = $wc_country_parts[0] ?? 'RE';

		$single_fields = [
			'company'      => ['Company Name', 'wpcargo_shipper_company_name', get_option('ubn_ss_brand_name', get_bloginfo('name'))],
			'name'         => ['Sender Contact Name', 'wpcargo_shipper_name', get_option('ubn_ss_brand_name', get_bloginfo('name'))],
			'firstname'    => ['Sender First Name', 'wpcargo_shipper_firstname', ''],
			'country'      => ['Country Code', 'wpcargo_shipper_addressp', $wc_country],
			'postcode'     => ['Postcode', 'wpcargo_shipper_addresscp', get_option('woocommerce_store_postcode', '')],
			'city'         => ['City', 'wpcargo_shipper_addressv', get_option('woocommerce_store_city', '')],
			'address'      => ['Street Address', 'wpcargo_shipper_address', get_option('woocommerce_store_address', '')],
			'address2'     => ['Additional Address', 'wpcargo_shipper_address_additoinal', get_option('woocommerce_store_address_2', '')],
			'phone'        => ['Phone Number', 'wpcargo_shipper_phone', ''],
			'email'        => ['Email Address', 'wpcargo_shipper_email', get_option('admin_email', '')],
			'pickup_hours' => ['Pickup Time Slot', 'creneau_horaire_enlevement', '10:00 - 18:00'],
		];

		foreach ($single_fields as $field_key => $field_data) :
			$option_key = 'ubn_single_store_' . $field_key;
			$val = get_option($option_key, $field_data[2]);
			?>
			<div class="ubn-field">
				<div>
					<strong><?php echo esc_html($field_data[0]); ?></strong><br>
					<small style="color:#666;"><?php echo esc_html($field_data[1]); ?></small>
				</div>
				<input
					type="text"
					id="ubn_single_store_<?php echo esc_attr($field_key); ?>"
					name="<?php echo esc_attr($option_key); ?>"
					value="<?php echo esc_attr($val); ?>"
					class="regular-text"
					style="min-width:350px;"
				>
			</div>
			<?php
		endforeach;
		?>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var btn = document.getElementById('ubn-prefill-wc-btn');
			if (btn) {
				btn.addEventListener('click', function() {
					if (confirm('Pre-fill address fields using current WooCommerce Store Settings?')) {
						var fieldMap = {
							'company': <?php echo wp_json_encode(get_bloginfo('name')); ?>,
							'name': <?php echo wp_json_encode(get_bloginfo('name')); ?>,
							'country': <?php echo wp_json_encode($wc_country); ?>,
							'postcode': <?php echo wp_json_encode(get_option('woocommerce_store_postcode', '')); ?>,
							'city': <?php echo wp_json_encode(get_option('woocommerce_store_city', '')); ?>,
							'address': <?php echo wp_json_encode(get_option('woocommerce_store_address', '')); ?>,
							'address2': <?php echo wp_json_encode(get_option('woocommerce_store_address_2', '')); ?>,
							'email': <?php echo wp_json_encode(get_option('admin_email', '')); ?>
						};
						for (var key in fieldMap) {
							var el = document.getElementById('ubn_single_store_' + key);
							if (el && fieldMap[key]) {
								el.value = fieldMap[key];
							}
						}
					}
				});
			}
		});
		</script>

	<?php else: ?>

		<div class="ubn-title">Multi-Store Shipper Details (Conforama Mode)</div>

		<?php
		$stores = [
			'105' => 'Conforama Nord',
			'106' => 'Conforama Sud',
		];

		$fields = [
			'company'      => ['Store Company', 'wpcargo_shipper_company_name'],
			'name'         => ['Store Name', 'wpcargo_shipper_name'],
			'firstname'    => ['Store First Name', 'wpcargo_shipper_firstname'],
			'country'      => ['Country', 'wpcargo_shipper_addressp'],
			'postcode'     => ['Postcode', 'wpcargo_shipper_addresscp'],
			'city'         => ['City', 'wpcargo_shipper_addressv'],
			'address'      => ['Address', 'wpcargo_shipper_address'],
			'address2'     => ['Additional Address', 'wpcargo_shipper_address_additoinal'],
			'phone'        => ['Phone', 'wpcargo_shipper_phone'],
			'email'        => ['Email', 'wpcargo_shipper_email'],
			'pickup_hours' => ['Pickup Time Slot', 'creneau_horaire_enlevement'],
		];

		foreach ($stores as $store_id => $store_label) :
			echo '<hr style="margin:30px 0;">';
			echo '<h2>' . esc_html($store_label) . '</h2>';

			foreach ($fields as $field_key => $field_data) :
				$option_key = 'ubn_store_' . $store_id . '_' . $field_key;
				?>
				<div class="ubn-field">
					<div>
						<strong><?php echo esc_html($field_data[0]); ?></strong><br>
						<small style="color:#666;"><?php echo esc_html($field_data[1]); ?></small>
					</div>
					<input
						type="text"
						name="<?php echo esc_attr($option_key); ?>"
						value="<?php echo esc_attr(get_option($option_key, '')); ?>"
						class="regular-text"
						style="min-width:350px;"
					>
				</div>
				<?php
			endforeach;
		endforeach;
		?>

	<?php endif; ?>

	</div>

	<?php submit_button('Save Settings'); ?>

	</form>

	<?php endif; ?>	

	<?php if ($active_tab=='styles'): ?>
	<form method="post" action="options.php">
	<?php settings_fields('ubn_ss_styles'); ?>
	<div class="ubn-card">
		<div class="ubn-title">Branding & Styles Settings</div>
		<p style="color:#666; margin-bottom:20px;">
			Customize the brand name and primary accent color used across emails, delivery notes, and automatic reports.
		</p>
		<div class="ubn-field">
			<div>
				<strong>Brand / Store Display Name</strong><br>
				<small>Displays on delivery notes, email headers, and automatic report footers.</small>
			</div>
			<input type="text" name="ubn_ss_brand_name" value="<?php echo esc_attr(get_option('ubn_ss_brand_name', get_bloginfo('name'))); ?>" class="regular-text" style="min-width:350px;">
		</div>
		<div class="ubn-field">
			<div>
				<strong>Primary Accent Color</strong><br>
				<small>Theme accent color used for email headers, action buttons, and delivery note accents (e.g. #db3832 for Conforama).</small>
			</div>
			<input type="color" name="ubn_ss_primary_color" value="<?php echo esc_attr(get_option('ubn_ss_primary_color', '#db3832')); ?>" style="width:60px; height:36px; padding:2px; cursor:pointer;">
		</div>
	</div>

	<div class="ubn-card">
		<div class="ubn-title">Live Preview</div>
		<div style="border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden; max-width: 600px; font-family: sans-serif;">
			<div style="background: <?php echo esc_attr(get_option('ubn_ss_primary_color', '#db3832')); ?>; padding: 15px 20px; color: #fff;">
				<h3 style="margin: 0; color: #ffffff; font-size: 16px;"><?php echo esc_html(get_option('ubn_ss_brand_name', get_bloginfo('name'))); ?> - Notification Sample</h3>
			</div>
			<div style="padding: 20px;">
				<p style="margin-top:0; font-size: 13px; color: #333;">This is a visual preview of your brand header and styled elements.</p>
				<a href="#" onclick="return false;" style="display: inline-block; background: <?php echo esc_attr(get_option('ubn_ss_primary_color', '#db3832')); ?>; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; font-size: 13px;">Styled Action Button</a>
			</div>
		</div>
	</div>
	<?php submit_button('Save Styles'); ?>
	</form>
	<?php endif; ?>	

	<?php if ($active_tab=='zone-manager'): ?>

	<div class="ubn-card">

	<?php ubn_render_zone_manager(); ?>

	</div>

	<?php endif; ?>

	<?php if ($active_tab=='orders'): ?>

	<div class="ubn-card">

	<?php ubn_orders_page(); ?>

	</div>

	<?php endif; ?>

	<?php if ($active_tab=='ubn-speed-configuration'): ?>

	<form method="post" action="options.php">

	<?php settings_fields('ubn_ss_api_config'); ?>

	<div class="ubn-card">

	<div class="ubn-title">API Mode</div>

	<div class="ubn-field">


	<div>
		<strong>Active Mode</strong>
		<br>
		<small>Select Production or Test mode</small>
	</div>

	<select name="ubn_ss_api_mode">

		<option value="production"
		<?php selected(
			get_option(
				'ubn_ss_api_mode',
				'production'
			),
			'production'
		); ?>>
			Production
		</option>

		<option value="test"
		<?php selected(
			get_option(
				'ubn_ss_api_mode',
				'production'
			),
			'test'
		); ?>>
			Test
		</option>

	</select>


	</div>

	</div>

	<div class="ubn-card">

	<div class="ubn-title">Production Configuration</div>

	<?php

	$production_fields = [

		'ubn_prod_api_base' =>
			'API Base',

		'ubn_prod_api_key' =>
			'API Key',

		'ubn_prod_hmac_secret' =>
			'HMAC Secret',

		'ubn_prod_partner_id' =>
			'Partner ID',

		'ubn_prod_source_site' =>
			'Source Site',
	];

	foreach ($production_fields as $field => $label) :

	?>

	<div class="ubn-field">


	<div style="max-width:350px;">

		<strong><?php echo esc_html($label); ?></strong>
		<br>
		<small><?php echo esc_html($field); ?></small>

	</div>

	<input
		type="<?php echo (
			strpos($field, 'secret') !== false
			|| strpos($field, 'key') !== false
		)
			? 'password'
			: 'text'; ?>"
		name="<?php echo esc_attr($field); ?>"
		value="<?php echo esc_attr(
			get_option($field, '')
		); ?>"
		class="regular-text"
		style="min-width:420px;"
	>


	</div>

	<?php endforeach; ?>

	</div>

	<div class="ubn-card">

	<div class="ubn-title">Test Configuration</div>

	<?php

	$test_fields = [

		'ubn_test_api_base' =>
			'API Base',

		'ubn_test_api_key' =>
			'API Key',

		'ubn_test_hmac_secret' =>
			'HMAC Secret',

		'ubn_test_partner_id' =>
			'Partner ID',

		'ubn_test_source_site' =>
			'Source Site',
	];

	foreach ($test_fields as $field => $label) :

	?>

	<div class="ubn-field">

	<div style="max-width:350px;">

		<strong><?php echo esc_html($label); ?></strong>
		<br>
		<small><?php echo esc_html($field); ?></small>

	</div>

	<input
		type="<?php echo (
			strpos($field, 'secret') !== false
			|| strpos($field, 'key') !== false
		)
			? 'password'
			: 'text'; ?>"
		name="<?php echo esc_attr($field); ?>"
		value="<?php echo esc_attr(
			get_option($field, '')
		); ?>"
		class="regular-text"
		style="min-width:420px;"
	>


	</div>

	<?php endforeach; ?>

	</div>

	<div class="ubn-card">
		<div class="ubn-title">Admin Failure Alert Settings</div>
		<div class="ubn-field" style="align-items:flex-start;">
			<div>
				<strong>Failure Notification Emails</strong><br>
				<small>Alert emails when shipment creation API requests fail (comma or newline separated)</small>
			</div>
			<textarea
				name="ubn_ss_admin_error_emails"
				rows="3"
				style="width:420px;"
			><?php echo esc_textarea(
				get_option('ubn_ss_admin_error_emails', "saifwebservices@gmail.com\njulien@soyoo.re")
			); ?></textarea>
		</div>
	</div>

	<?php submit_button('Save API Configuration'); ?>

	</form>

	<?php endif; ?>

	<?php if ($active_tab=='emails'): ?>

		<form method="post" action="options.php">

			<?php settings_fields('ubn_ss_email'); ?>

			<div class="ubn-card">

			<div class="ubn-title">
			Preparation Email Settings
			</div>
			<br><br>

			<div class="ubn-field">
			<label>Email Subject</label>

			<input
			type="text"
			name="ubn_ss_email_subject"
			value="<?php echo esc_attr(
			get_option(
			'ubn_ss_email_subject',
			'[UBN SPEED] {store_name} - Order #{order_number} - Package Preparation Required'
			)
			); ?>"
			class="regular-text"
			style="min-width:600px;"
			>
			</div>

			<?php if (ubn_get_store_mode() === 'single'): ?>

				<div class="ubn-field" style="align-items:flex-start;">
				<label>Notification Emails<br><small style="color:#666;">Comma or newline separated</small></label>

				<textarea
				name="ubn_single_store_notification_emails"
				rows="4"
				style="width:600px;"
				><?php echo esc_textarea(
				get_option(
				'ubn_single_store_notification_emails',
				get_option('admin_email', '')
				)
				); ?></textarea>
				</div>

			<?php else: ?>

				<div class="ubn-field" style="align-items:flex-start;">
				<label>Conforama Nord Emails</label>

				<textarea
				name="ubn_store_105_notification_emails"
				rows="4"
				style="width:600px;"
				><?php echo esc_textarea(
				get_option(
				'ubn_store_105_notification_emails',
				"caissenord@ridis-reunion.com\nconfdeconord@gmail.com\nweb@ridis-reunion.com"
				)
				); ?></textarea>
				</div>

				<div class="ubn-field" style="align-items:flex-start;">
				<label>Conforama Sud Emails</label>

				<textarea
				name="ubn_store_106_notification_emails"
				rows="4"
				style="width:600px;"
				><?php echo esc_textarea(
				get_option(
				'ubn_store_106_notification_emails',
				"caissesud@ridis-reunion.com\ndirsud@ridis-reunion.com\nconfdecosud@gmail.com\nweb@ridis-reunion.com"
				)
				); ?></textarea>
				</div>

			<?php endif; ?>

			<div style="margin-top:30px;">

				<h2 style="margin-bottom:10px;">
					Email Template
				</h2>

				<p style="margin-bottom:0;color:#666;line-height:2;">
					You can use the following tags in both the Email Subject and Email Template:
					<br>
					<code>{site_name}</code>
					<code>{store_name}</code>
					<code>{order_number}</code>
					<code>{order_id}</code>
					<code>{order_date}</code>
					<code>{order_total}</code>
					<code>{customer_name}</code>
					<code>{customer_email}</code>
					<code>{customer_phone}</code>
					<code>{shipping_address}</code>
					<code>{shipping_city}</code>
					<code>{shipping_postcode}</code>
					<code>{shipment_id}</code>
					<code>{tracking_number}</code>
					<code>{wallet_ref}</code>
					<code>{pdf_url}</code>
					<code>{product_list}</code>
					<code>{print_note_url}</code>
				</p>
				<?php

				$default_template = '
						<p>
							A UBN Speed shipment has been successfully created for
							<strong>Order #{order_number}</strong>.
						</p>

						<p>
							Please prepare all items for this order before
							<strong>10:00 AM on the next opening day</strong>.
						</p>

						<p>
							<strong>Store:</strong> {store_name}
						</p>

						<p>
							<strong>Shipment ID:</strong> {shipment_id}
						</p>

						<p>
							<strong>Tracking Number:</strong> {tracking_number}
						</p>

						<p>
							<strong>Customer:</strong> {customer_name}
						</p>

						<p>
							<strong>Phone:</strong> {customer_phone}
						</p>

						<p>
							<strong>Products:</strong><br>
							{product_list}
						</p>
					';

				$template = get_option(
					'ubn_ss_email_template',
					$default_template
				);

				wp_editor(
					$template,
					'ubn_ss_email_template_editor',
					[
						'textarea_name' => 'ubn_ss_email_template',
						'textarea_rows' => 18,
						'media_buttons' => false,
						'teeny' => false,
						'quicktags' => true,
					]
				);

				?>

				</div>
			</div>

			<?php submit_button('Save Email Settings'); ?>

		</form>

	<?php endif; ?>

	<?php if ($active_tab=='delivery-note'): ?>
		<form method="post" action="options.php">
			<?php settings_fields('ubn_ss_delivery_note'); ?>
			<div class="ubn-card">
				<div class="ubn-title">Delivery Note Template</div>
				<p style="margin-bottom:20px;color:#666;line-height:2;">
					Design the HTML template for your Delivery Note. It will be printed cleanly on an A4 page. 
					<br>
					<strong>Available tags:</strong> 
					<code>{site_name}</code>
					<code>{store_name}</code>
					<code>{order_number}</code>
					<code>{customer_name}</code>
					<code>{customer_email}</code>
					<code>{customer_phone}</code>
					<code>{shipping_address}</code>
					<code>{shipping_city}</code>
					<code>{shipping_postcode}</code>
					<code>{product_list}</code>
					<code>{qr_code}</code>
				</p>
				<?php
				$template = get_option('ubn_ss_delivery_note_template', '');
				wp_editor(
					$template,
					'ubn_ss_delivery_note_template_editor',
					[
						'textarea_name' => 'ubn_ss_delivery_note_template',
						'textarea_rows' => 25,
						'media_buttons' => true,
						'teeny' => false,
						'quicktags' => true,
					]
				);
				?>
			</div>
			<?php submit_button('Save Delivery Note Settings'); ?>
		</form>
	<?php endif; ?>

	<?php if ($active_tab=='logs'): ?>

	<div class="ubn-card">

	<?php ubn_logs_page(); ?>

	</div>

	<?php endif; ?>	

	<?php if ($active_tab=='daily-report'): ?>
		<?php ubn_render_daily_report_tab(); ?>
	<?php endif; ?>

	</div>
	<?php
}
/*--------------------------------------------------------------
# ZONE MANAGER
--------------------------------------------------------------*/
function ubn_render_zone_manager() {

	if (!current_user_can('manage_woocommerce')) {
		return;
	}

	$notice = '';

	// ADD SHIPPING
	if (isset($_POST['ubn_add_shipping'])) {

		check_admin_referer('ubn_zone_manager');

		$notice = ubn_add_shipping_to_all_zones();
	}

	// REMOVE SHIPPING
	if (isset($_POST['ubn_remove_shipping'])) {

		check_admin_referer('ubn_zone_manager');

		$notice = ubn_remove_shipping_from_all_zones();
	}

	// SUCCESS NOTICE
	if (!empty($notice)) {

		echo '
		<div style="
			background:#f0fff4;
			border:1px solid #46b450;
			padding:14px 16px;
			margin:20px 0;
			border-radius:8px;
			color:#1e4620;
			font-size:14px;
			font-weight:500;
			box-shadow:0 1px 3px rgba(0,0,0,0.05);
		">
			<div style="font-size:15px; margin-bottom:4px;">
				✅ Action completed successfully
			</div>

			<div>
				' . esc_html($notice) . '
			</div>
		</div>';
	}
	
	// 1. RUN THE ZONE AUDIT METRICS COUNTER IN THE BACKGROUND
	$all_wc_zones   = WC_Shipping_Zones::get_zones();
	$total_zones    = count($all_wc_zones);
	$active_count   = 0;
	$skipped_count  = 0;
	$missing_count  = 0;

	foreach ($all_wc_zones as $zone_data) {
		$zone    = new WC_Shipping_Zone($zone_data['id']);
		$methods = $zone->get_shipping_methods();
		
		$has_ubn        = false;
		$is_pickup_only = false;

		if (count($methods) === 1 && reset($methods)->id === 'local_pickup') {
			$is_pickup_only = true;
		}

		foreach ($methods as $method) {
			if ($method->id === 'ubn_hub_express') {
				$has_ubn = true;
				break;
			}
		}

		if ($has_ubn) {
			$active_count++;
		} elseif ($is_pickup_only) {
			$skipped_count++;
		} else {
			$missing_count++;
		}
	}

	// 2. RETRIEVE CURRENT PRICING CONFIGURATIONS
	$current_label = get_option('ubn_ss_label', 'Livraison rapide par colis');
	$current_fee   = (float) get_option('ubn_ss_price_per_qty', 15);
	$currency      = get_woocommerce_currency_symbol();
	?>

	<div style="margin-top: 15px;">
		<h2>🚀 UBN Zone Manager</h2>
		<p style="color:#646970; font-size: 13px; margin-bottom: 20px;">
			Quick summary and automation tools for your express shipping infrastructure.
		</p>

		<div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 30px;">
			
			<div style="border-bottom: 1px solid #f0f0f1; padding-bottom: 15px; margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 30px;">
				<div>
					<span style="color: #646970; font-size: 12px; display: block; font-weight: 500;">Shipping Method Label:</span>
					<strong style="font-size: 15px; color: #1d2327;"><?php echo esc_html($current_label); ?></strong>
				</div>
				<div>
					<span style="color: #646970; font-size: 12px; display: block; font-weight: 500;">Configured Price (Per Order):</span>
					<strong style="font-size: 15px; color: #1d2327;"><?php echo esc_html($current_fee) . ' ' . $currency; ?></strong>
				</div>
			</div>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; text-align: center;">
				<div style="padding: 10px; background: #f6f7f7; border-radius: 6px;">
					<span style="font-size: 20px; font-weight: 700; color: #1d2327; display: block;"><?php echo $total_zones; ?></span>
					<span style="color: #646970; font-size: 11px;">Total Zones</span>
				</div>
				<div style="padding: 10px; background: #eaf7ed; border-radius: 6px; border: 1px solid #a3e2b8;">
					<span style="font-size: 20px; font-weight: 700; color: #115e2e; display: block;"><?php echo $active_count; ?></span>
					<span style="color: #115e2e; font-size: 11px; font-weight: 500;">Active Zones</span>
				</div>
				<div style="padding: 10px; background: #f1f2f4; border-radius: 6px;">
					<span style="font-size: 20px; font-weight: 700; color: #40464d; display: block;"><?php echo $skipped_count; ?></span>
					<span style="color: #40464d; font-size: 11px;">Skipped (Pickup Only)</span>
				</div>
				<div style="padding: 10px; background: <?php echo $missing_count > 0 ? '#fff3e6' : '#eaf7ed'; ?>; border-radius: 6px; border: 1px solid <?php echo $missing_count > 0 ? '#ffd9b3' : '#a3e2b8'; ?>;">
					<span style="font-size: 20px; font-weight: 700; color: <?php echo $missing_count > 0 ? '#b35c00' : '#115e2e'; ?>; display: block;"><?php echo $missing_count; ?></span>
					<span style="color: <?php echo $missing_count > 0 ? '#b35c00' : '#115e2e'; ?>; font-size: 11px; font-weight: 500;">Not Active</span>
				</div>
			</div>

		</div>

		<h3 style="font-size: 15px; font-weight: 600; margin-bottom: 12px; color: #1d2327;">⚙️ Global Management Actions</h3>
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
			
			<div style="background: #fff; border: 1px solid #c3c4c7; padding: 15px; border-radius: 8px;">
				<h4 style="margin: 0 0 5px 0; font-size: 13px; color: #1d2327;">Activate UBN Everywhere</h4>
				<p style="color: #646970; font-size: 12px; margin: 0 0 15px 0; line-height: 1.4;">
					Adds the UBN Express shipping method to all eligible zones. Local pickup-only zones are automatically skipped.
				</p>
				<form method="post">
					<?php wp_nonce_field('ubn_zone_manager'); ?>
					<button type="submit" name="ubn_add_shipping" class="button button-primary" onclick="return confirm('This will add the UBN shipping method to all eligible zones.\n\nExisting shipping methods will not be modified.\n\nContinue?');">
						Add UBN shipping to all zones
					</button>
				</form>
			</div>

			<div style="background: #fff; border: 1px solid #c3c4c7; padding: 15px; border-radius: 8px;">
				<h4 style="margin: 0 0 5px 0; font-size: 13px; color: #1d2327;">Deactivate UBN Everywhere</h4>
				<p style="color: #646970; font-size: 12px; margin: 0 0 15px 0; line-height: 1.4;">
					Removes the UBN Express shipping method from all active shipping zones. Other shipping methods remain untouched.
				</p>
				<form method="post">
					<?php wp_nonce_field('ubn_zone_manager'); ?>
					<button type="submit" name="ubn_remove_shipping" class="button button-secondary" style="color: #b32d2e; border-color: #b32d2e;" onclick="return confirm('This will remove all UBN shipping methods from all zones.\n\nOther shipping methods will remain untouched.\n\nContinue?');">
						Remove UBN from all zones
					</button>
				</form>
			</div>

		</div>
	</div>
	<?php
}
/*--------------------------------------------------------------
# ADD UBN SHIPPING TO ALL ZONES
--------------------------------------------------------------*/
function ubn_add_shipping_to_all_zones() {

	$zones = WC_Shipping_Zones::get_zones();

	$added = 0;
	$exists = 0;
	$skipped = 0;
	$errors = 0;

	foreach ($zones as $zone_data) {

		try {

			$zone = new WC_Shipping_Zone($zone_data['id']);

			$methods = $zone->get_shipping_methods();

			// Skip local pickup only zones
			if (
				count($methods) === 1 &&
				reset($methods)->id === 'local_pickup'
			) {
				$skipped++;
				continue;
			}

			$already_exists = false;

			foreach ($methods as $method) {

				if ($method->id === 'ubn_hub_express') {
					$already_exists = true;
					break;
				}
			}

			if ($already_exists) {
				$exists++;
				continue;
			}

			$zone->add_shipping_method('ubn_hub_express');

			$added++;

		} catch (Exception $e) {

			$errors++;
		}
	}

	WC_Cache_Helper::get_transient_version('shipping', true);

	return
		$added . ' zone(s) updated. '
		. $exists . ' already had UBN shipping. '
		. $skipped . ' zone skipped because it was a local pickup zone. '
		. $errors . ' error(s).';
}
/*--------------------------------------------------------------
# REMOVE UBN SHIPPING FROM ALL ZONES
--------------------------------------------------------------*/
function ubn_remove_shipping_from_all_zones() {

	$zones = WC_Shipping_Zones::get_zones();

	$removed = 0;
	$errors = 0;

	foreach ($zones as $zone_data) {

		try {

			$zone = new WC_Shipping_Zone($zone_data['id']);

			$methods = $zone->get_shipping_methods();

			foreach ($methods as $instance_id => $method) {

				if ($method->id === 'ubn_hub_express') {

					$zone->delete_shipping_method($instance_id);

					$removed++;
				}
			}

		} catch (Exception $e) {

			$errors++;
		}
	}

	WC_Cache_Helper::get_transient_version('shipping', true);

	return
		$removed . ' UBN shipping method(s) removed. '
		. $errors . ' error(s).';
}
/*--------------------------------------------------------------
# MANUAL ORDER ACTION - CREATE SHIPMENT
--------------------------------------------------------------*/

// Add custom order action
add_filter(
	'woocommerce_order_actions',
	'ubn_add_create_shipment_order_action'
);

function ubn_add_create_shipment_order_action($actions) {

	global $theorder;

	if (
		!$theorder ||
		!ubn_is_ubn_order($theorder)
	) {
		return $actions;
	}

	$actions['ubn_create_shipment'] = 'Create UBN Shipment';

	return $actions;
}

// Handle custom order action
add_action('woocommerce_order_action_ubn_create_shipment', 'ubn_handle_manual_create_shipment');

function ubn_handle_manual_create_shipment($order) {
	
	

	if (!$order) {
		return;
	}

	$order_id = $order->get_id();
	
	ubn_add_log(
		'debug',
		'',
		'',
		'',
		'',
		$order_id,
		'Manual order action triggered'
	);

	// Run existing shipment function
	ubn_create_shipment($order_id);
}
/*--------------------------------------------------------------
# ORDER METABOX
--------------------------------------------------------------*/
add_action(
	'add_meta_boxes',
	'ubn_register_order_metabox'
);

function ubn_register_order_metabox() {

	add_meta_box(
		'ubn-shipment-box',
		'UBN Shipment',
		'ubn_render_order_metabox',
		'shop_order',
		'side',
		'default'
	);
}

function ubn_render_order_metabox($post) {

	$order_id = $post->ID;

	$tracking = get_post_meta(
		$order_id,
		'_ubn_tracking_number',
		true
	);

	$shipment_id = get_post_meta(
		$order_id,
		'_ubn_shipment_id',
		true
	);

	$wallet = get_post_meta(
		$order_id,
		'_ubn_wallet_debit',
		true
	);

	$pdf = get_post_meta(
		$order_id,
		'_ubn_pdf_url',
		true
	);

	$created = get_post_meta(
		$order_id,
		'_ubn_shipment_created',
		true
	);

	if (!$created) {

		echo '<p>No shipment created yet.</p>';

		return;
	}

	echo '<p><strong>Shipment Created:</strong> Yes</p>';

	echo '<p><strong>Tracking:</strong><br>'
		. esc_html($tracking)
		. '</p>';

	echo '<p><strong>Shipment ID:</strong><br>'
		. esc_html($shipment_id)
		. '</p>';

	echo '<p><strong>Wallet Debit:</strong><br>'
		. esc_html($wallet)
		. ' '
		. get_woocommerce_currency_symbol()
		. '</p>';

	if ($pdf) {

		echo '<p>';

		echo '<a href="'
			. esc_url($pdf)
			. '" target="_blank" class="button button-primary">';

		echo 'Open PDF Label';

		echo '</a>';

		echo '</p>';
	}
}

/*--------------------------------------------------------------
# SHOW SHIPMENT DEBUG ON ORDER PAGE
--------------------------------------------------------------*/

add_action(
	'woocommerce_admin_order_data_after_order_details',
	'ubn_show_shipment_debug_admin'
);

function ubn_show_shipment_debug_admin($order) {

	if (!$order) {
		return;
	}

	$order_id = $order->get_id();

	$error = get_post_meta(
		$order_id,
		'_ubn_shipment_error',
		true
	);

	$success = get_post_meta(
		$order_id,
		'_ubn_shipment_success',
		true
	);

	echo '<div style="margin-top:240px;">';

// 	echo '<h3>UBN Shipment </h3>';

	// SUCCESS
	if ($success) {

		$data = json_decode($success, true);

		$tracking_number = $data['tracking_number'] ?? '';
		$shipment_id     = $data['shipment_id'] ?? '';
		$wallet_debit    = $data['wallet_debit'] ?? '';
		$wallet_ref      = $data['wallet_ref'] ?? '';
		$pdf_url         = $data['pdf_url'] ?? '';
		$request_id      = $data['request_id'] ?? '';
		$service_id      = $data['pricing']['service_id'] ?? '';

		echo '
		<div style="
			background:#ecfdf3;
			border:1px solid #46b450;
			padding:18px;
			margin-bottom:15px;
			border-radius:8px;
		">

			<h3 style="
				margin-top:0;
				color:#1e7e34;
			">
				 Shipment Created Successfully
			</h3>

			<table style="
				width:100%;
				border-collapse:collapse;
			">

				<tr>
					<td style="padding:6px 0;"><strong>Tracking Number</strong></td>
					<td>' . esc_html($tracking_number) . '</td>
				</tr>

				<tr>
					<td style="padding:6px 0;"><strong>Shipment ID</strong></td>
					<td>' . esc_html($shipment_id) . '</td>
				</tr>

				<tr>
					<td style="padding:6px 0;"><strong>Wallet Debit</strong></td>
					<td>'
						. esc_html($wallet_debit)
						. ' '
						. get_woocommerce_currency_symbol()
					. '</td>
				</tr>

				<tr>
					<td style="padding:6px 0;"><strong>Wallet Reference</strong></td>
					<td>' . esc_html($wallet_ref) . '</td>
				</tr>

				<tr>
					<td style="padding:6px 0;"><strong>Service</strong></td>
					<td>' . esc_html($service_id) . '</td>
				</tr>

				<tr>
					<td style="padding:6px 0;"><strong>Request ID</strong></td>
					<td>' . esc_html($request_id) . '</td>
				</tr>

			</table>';

		

			echo '
			<p style="margin-top:18px;">

				<a
					href="https://ubn-speed.fr/suivi-colis/?ubn_tracking=' . esc_html($tracking_number) . '"
					target="_blank"
					class="button button-primary"
				>
					Voir le suivi du colis
				</a>

			</p>';
		

		echo '</div>';
	}

	// ERROR
	if ($error) {

		echo '
		<div style="
			background:#fff5f5;
			border:1px solid #dc3232;
			padding:15px;
		">
			<strong style="color:#dc3232;">
				Shipment API Error
			</strong>

			<pre style="
				margin-top:10px;
				white-space:pre-wrap;
			">' . esc_html($error) . '</pre>
		</div>';
	}

	echo '</div>';
	
	$email_sent = get_post_meta(
    $order->get_id(),
    '_ubn_preparation_email_sent',
    true
	);

	$email_recipients = get_post_meta(
		$order->get_id(),
		'_ubn_preparation_email_recipients',
		true
	);

	$email_subject = get_post_meta(
		$order->get_id(),
		'_ubn_preparation_email_subject',
		true
	);

	if ($email_sent) :
	?>

	<hr style="margin:15px 0;">

	<h3 style="margin-bottom:10px;">
		Preparation Email
	</h3>

	<table class="widefat striped">

		<tr>
			<td width="180">
				<strong>Status</strong>
			</td>
			<td style="color:green;">
				Sent ✓
			</td>
		</tr>

		<tr>
			<td>
				<strong>Sent At</strong>
			</td>
			<td>
				<?php echo esc_html($email_sent); ?>
			</td>
		</tr>

		<tr>
			<td>
				<strong>Subject</strong>
			</td>
			<td>
				<?php echo esc_html($email_subject); ?>
			</td>
		</tr>

		<tr>
			<td>
				<strong>Recipients</strong>
			</td>
			<td>
				<?php
				echo nl2br(
					esc_html(
						str_replace(
							', ',
							"\n",
							$email_recipients
						)
					)
				);
				?>
			</td>
		</tr>

	</table>

	<?php
	endif;
}
/*--------------------------------------------------------------
# UBN ORDERS PAGE
--------------------------------------------------------------*/
function ubn_orders_page() {

	$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
	$per_page = 20;

	$current_status = isset($_GET['ubn_status']) ? sanitize_text_field($_GET['ubn_status']) : 'default';
	$current_time = isset($_GET['ubn_time']) ? sanitize_text_field($_GET['ubn_time']) : 'this_month';

	$query_args = [
		'limit'      => $per_page,
		'page'       => $paged,
		'paginate'   => true,
		'meta_key'   => '_ubn_order',
		'meta_value' => 'yes',
	];

	// Status logic
	if ($current_status === 'all') {
		$query_args['status'] = array_keys(wc_get_order_statuses());
	} elseif ($current_status === 'default') {
		$all_statuses = array_keys(wc_get_order_statuses());
		$query_args['status'] = array_diff($all_statuses, ['wc-cancelled']);
	} else {
		$query_args['status'] = [$current_status];
	}

	// Time logic
	$timezone = wp_timezone();
	if ($current_time === 'this_month') {
		$start_date = new DateTime('first day of this month 00:00:00', $timezone);
		$end_date = new DateTime('last day of this month 23:59:59', $timezone);
		$query_args['date_created'] = $start_date->format('Y-m-d') . '...' . $end_date->format('Y-m-d');
	} elseif ($current_time === 'last_month') {
		$start_date = new DateTime('first day of last month 00:00:00', $timezone);
		$end_date = new DateTime('last day of last month 23:59:59', $timezone);
		$query_args['date_created'] = $start_date->format('Y-m-d') . '...' . $end_date->format('Y-m-d');
	}

	$orders = wc_get_orders($query_args);

	?>

	<div class="wrap">

		<h1>UBN Orders</h1>
		
		<form method="get" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">
			<input type="hidden" name="page" value="ubn-ss">
			<input type="hidden" name="tab" value="orders">
			
			<select name="ubn_status" style="max-width: 200px;">
				<option value="default" <?php selected($current_status, 'default'); ?>>All (except cancelled)</option>
				<option value="all" <?php selected($current_status, 'all'); ?>>All</option>
				<?php foreach (wc_get_order_statuses() as $slug => $name): ?>
					<option value="<?php echo esc_attr($slug); ?>" <?php selected($current_status, $slug); ?>><?php echo esc_html($name); ?></option>
				<?php endforeach; ?>
			</select>
			
			<select name="ubn_time" style="max-width: 200px;">
				<option value="this_month" <?php selected($current_time, 'this_month'); ?>>This Month</option>
				<option value="last_month" <?php selected($current_time, 'last_month'); ?>>Last Month</option>
				<option value="all" <?php selected($current_time, 'all'); ?>>All Time</option>
			</select>
			
			<button type="submit" class="button button-primary">Filter</button>
		</form>

		<style>

			.ubn-shipment-created-row {
				background: #ecfdf3 !important;
			}

			.ubn-success-badge {
				display:inline-block;
				background:#46b450;
				color:#fff;
				padding:6px 10px;
				border-radius:4px;
				font-weight:600;
				animation: ubnPulse 1s ease-in-out 3;
			}

			@keyframes ubnPulse {

				0% {
					transform: scale(1);
				}

				50% {
					transform: scale(1.08);
				}

				100% {
					transform: scale(1);
				}
			}

		</style>
		<table class="widefat striped">

			<thead>
				<tr>
					<th>Order</th>
					<th>Customer</th>
					<th>Total</th>
					<th>Status</th>
					<th>Shipment</th>
					<th>Date</th>
					<th>Actions</th>
				</tr>
			</thead>

			<tbody>

				<?php
				$highlight_order = isset($_GET['shipment_created'])
									? absint($_GET['shipment_created'])
									: 0;
				if (!empty($orders->orders)) :

					foreach ($orders->orders as $order) :

						

						?>

						<tr <?php if ($highlight_order === $order->get_id()) : ?>
							class="ubn-shipment-created-row"
						<?php endif; ?>>

							<td>
								<a href="<?php echo admin_url('post.php?post=' . $order->get_id() . '&action=edit'); ?>">
									#<?php echo $order->get_id(); ?>
								</a>
							</td>

							<td>
								<?php echo esc_html($order->get_formatted_billing_full_name()); ?>
							</td>

							<td>
								<?php echo wp_kses_post($order->get_formatted_order_total()); ?>
							</td>

							<td>
								<?php echo esc_html(wc_get_order_status_name($order->get_status())); ?>
							</td>

							<td>

								<?php

								$shipment_created = get_post_meta(
									$order->get_id(),
									'_ubn_shipment_created',
									true
								);

								$shipment_error = get_post_meta(
									$order->get_id(),
									'_ubn_shipment_error',
									true
								);

								if ($shipment_created) {

									echo '<span style="color:#1e7e34;font-weight:600;">Success</span>';

								} elseif ($shipment_error) {

									echo '<span style="color:#dc3232;font-weight:600;">Failed</span>';

								} else {

									echo '<span style="color:#996800;font-weight:600;">Pending</span>';
								}

								?>

							</td>

							<td>
								<?php echo esc_html($order->get_date_created()->date('Y-m-d H:i')); ?>
							</td>
							
							<td>

								<?php

								$shipment_created = get_post_meta(
									$order->get_id(),
									'_ubn_shipment_created',
									true
								);

								$shipment_error = get_post_meta(
									$order->get_id(),
									'_ubn_shipment_error',
									true
								);

								if (!$shipment_created) :

									$url = wp_nonce_url(
										admin_url(
											'admin.php?page=ubn-ss&tab=orders&ubn_create_shipment=' . $order->get_id()
										),
										'ubn_create_shipment_' . $order->get_id()
									);
									?>

									<a
										href="<?php echo esc_url($url); ?>"
										class="button button-primary"
										onclick="return confirm('Create shipment for this order?');"
									>
										<?php echo $shipment_error ? 'Retry Shipment' : 'Create Shipment'; ?>
									</a>

								<?php else : ?>

									<?php if ($highlight_order === $order->get_id()) : ?>

									<span class="ubn-success-badge">
										✓ Shipment Created
									</span>

								<?php else : ?>

									<span style="color:#1e7e34;font-weight:600;">
										Created
									</span>

								<?php endif; ?>

								<?php endif; ?>

							</td>

						</tr>

						<?php

					endforeach;

				else :

					?>

					<tr>
						<td colspan="7">No UBN orders found.</td>
					</tr>

					<?php

				endif;

				?>

			</tbody>

		</table>

		<?php

		echo paginate_links([
			'base'      => add_query_arg('paged', '%#%'),
			'format'    => '',
			'current'   => $paged,
			'total'     => $orders->max_num_pages,
			'prev_text' => '«',
			'next_text' => '»'
		]);

		?>
	<script>
		document.addEventListener('DOMContentLoaded', function() {

			const url = new URL(window.location);

			if (url.searchParams.has('shipment_created')) {

				setTimeout(function() {

					url.searchParams.delete('shipment_created');

					window.history.replaceState(
						{},
						'',
						url.toString()
					);

				}, 3000);

			}

		});
	</script>
	</div>

	<?php

	
}

/*--------------------------------------------------------------
# MANUAL SHIPMENT CREATION FROM UBN ORDERS PAGE
--------------------------------------------------------------*/

add_action(
    'admin_init',
    'ubn_handle_orders_page_shipment_creation'
);

function ubn_handle_orders_page_shipment_creation() {

    if (
        !is_admin() ||
        !current_user_can('manage_woocommerce')
    ) {
        return;
    }

    if (
        empty($_GET['ubn_create_shipment'])
    ) {
        return;
    }

    $order_id = absint(
        $_GET['ubn_create_shipment']
    );

    check_admin_referer(
        'ubn_create_shipment_' . $order_id
    );
	
	ubn_add_log(
		'debug',
		'',
		'',
		'',
		'',
		$order_id,
		'Shipment manually created from Orders page'
	);

    ubn_create_shipment($order_id);
	
	

    wp_safe_redirect(
        admin_url(
            'admin.php?page=ubn-ss&tab=orders&shipment_created=' . $order_id
        )
    );

    exit;
}

/*--------------------------------------------------------------
# CLEAR LOGS
--------------------------------------------------------------*/

add_action(
    'admin_init',
    'ubn_handle_log_cleanup'
);

function ubn_handle_log_cleanup() {

    if (
        !is_admin() ||
        !current_user_can('manage_woocommerce')
    ) {
        return;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'ubn_logs';

    // Clear Success Logs
    if (!empty($_GET['ubn_clear_success_logs'])) {

        check_admin_referer(
            'ubn_clear_success_logs'
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE type = %s",
                'shipment_success'
            )
        );

        wp_safe_redirect(
            admin_url(
                'admin.php?page=ubn-ss&tab=logs&logs_cleared=success'
            )
        );

        exit;
    }

    // Clear All Logs
    if (!empty($_GET['ubn_clear_all_logs'])) {

        check_admin_referer(
            'ubn_clear_all_logs'
        );

        $wpdb->query(
            "DELETE FROM {$table_name}"
        );

        wp_safe_redirect(
            admin_url(
                'admin.php?page=ubn-ss&tab=logs&logs_cleared=all'
            )
        );

        exit;
    }
}

/*--------------------------------------------------------------
# UBN LOGS PAGE
--------------------------------------------------------------*/
function ubn_logs_page() {

	global $wpdb;

	$table_name = $wpdb->prefix . 'ubn_logs';

	$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

	$per_page = 20;

	$offset = ($paged - 1) * $per_page;

	$total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

	$logs = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table_name ORDER BY id DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		)
	);

	?>

	<div class="wrap">

		<h1>UBN Logs</h1>
		<?php if (!empty($_GET['logs_cleared'])) : ?>

			<p style="
				color:#1e7e34;
				font-weight:600;
				margin:10px 0 15px;
				font-size:14px;
			">

				<?php

				if ($_GET['logs_cleared'] === 'success') {

					echo '✓ Success logs cleared successfully.';

				} elseif ($_GET['logs_cleared'] === 'all') {

					echo '✓ All logs cleared successfully.';
				}

				?>

			</p>
		
		<script>
			document.addEventListener('DOMContentLoaded', function() {

				const url = new URL(window.location);

				if (url.searchParams.has('logs_cleared')) {

					url.searchParams.delete('logs_cleared');

					window.history.replaceState(
						{},
						'',
						url.toString()
					);
				}

			});
		</script>

		<?php endif; ?>
		<div style="margin:15px 0;">

			<?php

			$clear_success_url = wp_nonce_url(
				admin_url(
					'admin.php?page=ubn-ss&tab=logs&ubn_clear_success_logs=1'
				),
				'ubn_clear_success_logs'
			);

			$clear_all_url = wp_nonce_url(
				admin_url(
					'admin.php?page=ubn-ss&tab=logs&ubn_clear_all_logs=1'
				),
				'ubn_clear_all_logs'
			);

			?>

			<a
				href="<?php echo esc_url($clear_success_url); ?>"
				class="button"
				onclick="return confirm('Delete all successful shipment logs?');"
			>
				Clear Success Logs
			</a>

			<a
				href="<?php echo esc_url($clear_all_url); ?>"
				class="button button-secondary"
				style="margin-left:8px;color:#b32d2e;border-color:#b32d2e;"
				onclick="return confirm('Delete ALL UBN logs? This cannot be undone.');"
			>
				Clear All Logs
			</a>

		</div>
		<table class="widefat striped">

			<thead>
				<tr>
					<th>ID</th>
					<th>Date</th>
					<th>Type</th>
					<th>Status</th>
					<th>Order</th>
					<th>Message</th>
					<th style="width:160px;">Request</th>
					<th style="width:160px;">Response</th>
				</tr>
			</thead>

			<tbody>

				<?php if ($logs) : ?>

					<?php foreach ($logs as $log) : ?>

						<tr>

							<td><?php echo esc_html($log->id); ?></td>

							<td><?php echo esc_html($log->created_at); ?></td>

							<td><?php echo esc_html($log->type); ?></td>

							<td><?php echo esc_html($log->status_code); ?></td>

							<td>

								<?php if ($log->order_id) : ?>

									<a href="<?php echo admin_url('post.php?post=' . $log->order_id . '&action=edit'); ?>">

										#<?php echo esc_html($log->order_id); ?>

									</a>

								<?php endif; ?>

							</td>

							<td>

							<?php echo esc_html($log->message); ?>

						</td>
							
						<td>

								<?php if (
									in_array(
										$log->type,
										['shipment_success', 'shipment_error']
									)
								) : ?>

									<button
										type="button"
										class="button button-secondary ubn-view-request"
										data-request="<?php echo esc_attr(
											wp_json_encode(
												maybe_unserialize($log->request_data),
												JSON_PRETTY_PRINT
											)
										); ?>"
									>
										View Request
									</button>

								<?php else : ?>

									—

								<?php endif; ?>

							</td>

							<td>

							<?php if (
								in_array(
									$log->type,
									['shipment_success', 'shipment_error']
								)
							) : ?>

								<button
									type="button"
									class="button button-secondary ubn-view-response"
									data-response="<?php echo esc_attr(
										wp_json_encode(
											maybe_unserialize($log->response_data),
											JSON_PRETTY_PRINT
										)
									); ?>"
								>

									<?php
									echo $log->type === 'shipment_success'
										? 'View Success'
										: 'View Error';
									?>

								</button>

							<?php else : ?>

								—

							<?php endif; ?>

							</td>

						</tr>

					<?php endforeach; ?>

				<?php else : ?>

					<tr>
						<td colspan="8">No logs found.</td>
					</tr>

				<?php endif; ?>

			</tbody>

		</table>

		<?php

		echo paginate_links([
			'base'      => add_query_arg('paged', '%#%'),
			'format'    => '',
			'current'   => $paged,
			'total'     => ceil($total / $per_page),
			'prev_text' => '«',
			'next_text' => '»'
		]);

		
/*--------------------------------------------------------------
# LOG RESPONSE MODAL
--------------------------------------------------------------*/
?>

<div
	id="ubn-log-modal"
	style="
		display:none;
		position:fixed;
		top:0;
		left:0;
		width:100%;
		height:100%;
		background:rgba(0,0,0,0.6);
		z-index:99999;
	"
>

	<div style="
		background:#fff;
		width:90%;
		max-width:900px;
		margin:50px auto;
		padding:25px;
		border-radius:10px;
		position:relative;
		max-height:85vh;
		overflow:auto;
	">

		<button
			type="button"
			id="ubn-close-modal"
			style="
				position: absolute;
				top: 15px;
				right: 15px;
				border: none;
				background: #dc3232;
				color: #fff;
				width: 32px;
				height: 32px;
				border-radius: 50%;
				cursor: pointer;
				font-size: 23px;
				line-height: 1;
				padding-bottom: 6px;
			"
		>
			×
		</button>

		<h2 id="ubn-modal-title">
			UBN Log Details
		</h2>

		<pre
			id="ubn-modal-content"
			style="
				background:#f6f7f7;
				padding:20px;
				border-radius:8px;
				overflow:auto;
				white-space:pre-wrap;
				word-break:break-word;
				font-size:12px;
				line-height:1.5;
			"
		></pre>

	</div>

</div>

<script>

document.addEventListener('DOMContentLoaded', function() {

	const modal = document.getElementById('ubn-log-modal');

	const content = document.getElementById('ubn-modal-content');
	
	const title = document.getElementById('ubn-modal-title');
	
	document.querySelectorAll('.ubn-view-request')
		.forEach(button => {

		button.addEventListener('click', function() {

			title.textContent = 'UBN API Request';

			content.textContent =
				this.dataset.request;

			modal.style.display = 'block';
		});

	});
	
	document.querySelectorAll('.ubn-view-response')
	.forEach(button => {

		button.addEventListener('click', function() {

			title.textContent = 'UBN API Response';

			content.textContent =
				this.dataset.response;

			modal.style.display = 'block';
		});
	});

	document.getElementById('ubn-close-modal')
	.addEventListener('click', function() {

			modal.style.display = 'none';
	});

	modal.addEventListener('click', function(e) {

		if (e.target === modal) {

			modal.style.display = 'none';
		}
	});
});

</script>
</div>

	

	<?php
}

/*--------------------------------------------------------------
# GET SERVICES API (UPDATED TO V3.2 CATALOG)
--------------------------------------------------------------*/
function ubn_ss_get_services() {
	
	$config = ubn_get_api_config();
  	$response = wp_remote_get(
		$config['api_base'] . '/catalog',
	[
        'headers' => [
            'X-UBN-API-KEY' =>
				$config['api_key'],

			'X-UBN-Partner' =>
				$config['partner_id'],

			'X-UBN-Source-Site' =>
				$config['source_site']
        ]
    ]);

    if (is_wp_error($response)) return [];

    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    $formatted_services = [];
    if (!empty($body['services'])) {
        foreach ($body['services'] as $svc) {
            $formatted_services[] = [
                'id'    => $svc['id'],
                'label' => $svc['label'] . ' (' . $svc['price_ttc'] . '€)'
            ];
        }
    }

    return $formatted_services;
}

/*--------------------------------------------------------------
# AJAX STOCK UPDATE (AFTER ADD TO CART)
--------------------------------------------------------------*/

/*--------------------------------------------------------------
# JS TRIGGER AFTER ADD TO CART & SINGLE PRODUCT PAGE LOAD
--------------------------------------------------------------*/

/*--------------------------------------------------------------
# VALIDATION HELPERS
--------------------------------------------------------------*/
function ubn_cat_ok($cart){
    $allowed = (array)get_option('ubn_ss_categories',[]);
    if(empty($allowed)) return true;
    foreach($cart as $item){
        $terms = wp_get_post_terms($item['product_id'],'product_cat',['fields'=>'ids']);
        if(!array_intersect($terms,$allowed)) return false;
    }
    return true;
}


function ubn_stock_ok($cart){
    $mode = ubn_get_store_mode();

    foreach($cart as $item){
        $pid = $item['product_id'];
        $qty = (int) $item['quantity'];
        $product = wc_get_product($pid);

        $is_valid = true;

        if (!$product) {
            $is_valid = false;
        } elseif ($mode === 'single') {
            if (!$product->is_in_stock()) {
                $is_valid = false;
            }
            if ($product->managing_stock() && !$product->has_enough_stock($qty)) {
                $is_valid = false;
            }
        } else {
            $store = $item['woocommerce_multi_inventory_inventory']['value'] ?? null;
            if (!$store) {
                $is_valid = false;
            } else {
                $stock = ($store == 105)
                ? get_post_meta($pid, 'depot_expo_nord_quantity', true)
                : get_post_meta($pid, 'depot_expo_sud_quantity', true);

                if ((float) $stock < $qty) {
                    $is_valid = false;
                }
            }
        }

        $is_valid = apply_filters('ubn_validate_item_stock', $is_valid, $item, $mode);

        if (!$is_valid) {
            return false;
        }
    }
    return true;
}

/*--------------------------------------------------------------
# AJAX UBN VALIDATION CHECK
--------------------------------------------------------------*/
function ubn_validate_checkout_shipping() {

    if (!WC()->cart) {
        wp_send_json_error([
            'valid' => false
        ]);
    }

    $cart = WC()->cart->get_cart();

    $valid =
    ubn_ss_is_enabled() &&
    ubn_cat_ok($cart) &&
    ubn_stock_ok($cart) &&
    ubn_postcode_allowed();

    wp_send_json_success([
        'valid' => $valid
    ]);
}

add_action('wp_ajax_ubn_validate_checkout_shipping', 'ubn_validate_checkout_shipping');
add_action('wp_ajax_nopriv_ubn_validate_checkout_shipping', 'ubn_validate_checkout_shipping');

/*--------------------------------------------------------------
# SINGLE-STORE CHECKOUT VALIDATION GUARD
--------------------------------------------------------------*/
add_action('woocommerce_after_checkout_validation', function($data, $errors) {
	// Execute ONLY in Single-Store mode (Conforama Multi-Store mode remains 100% untouched)
	if (ubn_get_store_mode() !== 'single') {
		return;
	}

	// Check if UBN shipping method (or mapped existing method) is selected in submitted checkout form
	$chosen_methods  = $data['shipping_method'] ?? [];
	$is_ubn_selected = ubn_is_ubn_method_chosen($chosen_methods);

	if (!$is_ubn_selected) {
		return;
	}

	// Re-validate cart against UBN Single-Store rules
	$cart = WC()->cart ? WC()->cart->get_cart() : [];
	if (empty($cart)) {
		return;
	}

	$is_valid = ubn_ss_is_enabled() &&
	            ubn_cat_ok($cart) &&
	            ubn_stock_ok($cart) &&
	            ubn_postcode_allowed();

	if (!$is_valid) {
		$errors->add(
			'ubn_invalid_shipping',
			__('Le mode de livraison "Livraison rapide par colis" n\'est plus disponible pour les articles de votre panier. Veuillez sélectionner une autre option de livraison.', 'ubn-speed-shipping')
		);
	}
}, 20, 2);

/*--------------------------------------------------------------
# SHIPPING FILTER (FIXED - SYNCHRONOUS VALIDATION)
--------------------------------------------------------------*/

add_filter('woocommerce_package_rates', function($rates){

	if (!ubn_ss_is_enabled()) {

		foreach($rates as $id => $rate){

			if(strpos($rate->method_id, 'ubn_hub_express') !== false){
				unset($rates[$id]);
			}

		}

		return $rates;
	}

	// 1. Safety check
	if (!WC()->cart) return $rates;

	// 2. Perform validation live, right when WooCommerce asks for rates
	$is_enabled = get_option('ubn_ss_enabled');
	$available  = false;

	if ($is_enabled) {
		$cart = WC()->cart->get_cart();

		if (
			ubn_cat_ok($cart) &&
			ubn_stock_ok($cart) &&
			ubn_postcode_allowed()
		) {
			$available = true;
		}
	}

	// 3. Filter UBN methods (both dedicated ubn_hub_express AND mapped existing methods in Single-Store mode)
	$is_single_store = (ubn_get_store_mode() === 'single');
	$method_mode     = get_option('ubn_ss_shipping_method_mode', 'create_new');
	$mapped_methods  = (array) get_option('ubn_ss_mapped_methods', []);

	foreach ($rates as $id => $rate) {
		$rate_id        = $rate->id;        // e.g. 'flat_rate:1'
		$rate_method_id = $rate->method_id; // e.g. 'flat_rate'

		$is_this_ubn = false;

		if (strpos($rate_method_id, 'ubn_hub_express') !== false || strpos($rate_id, 'ubn_hub_express') !== false) {
			$is_this_ubn = true;
		} elseif ($is_single_store && $method_mode === 'use_existing') {
			if (in_array($rate_id, $mapped_methods, true) || in_array($rate_method_id, $mapped_methods, true)) {
				$is_this_ubn = true;
			}
		}

		if ($is_this_ubn && !$available) {
			unset($rates[$id]);
		}
	}

	// 4. Sort all available rates in ascending order by price
	uasort($rates, function($a, $b) {
		$cost_a = (float) $a->get_cost();
		$cost_b = (float) $b->get_cost();
		
		if ($cost_a == $cost_b) {
			return 0;
		}
		return ($cost_a < $cost_b) ? -1 : 1;
	});

	return $rates;
}, 100);


/*--------------------------------------------------------------
# LOCALIZATION HOOK FOR DYNAMIC SHIPPING DESCRIPTION
--------------------------------------------------------------*/
add_action('wp_footer', 'ubn_localize_shipping_description_data');
function ubn_localize_shipping_description_data() {
    if ( ! is_cart() && ! is_checkout() ) {
        return;
    }
    
    $description = get_option('ubn_ss_description', '');
    ?>
    <script type="text/javascript">
        window.ubn_settings = {
            description: <?php echo wp_json_encode(esc_html($description)); ?>
        };
    </script>
    <?php
}

/*--------------------------------------------------------------
# INJECT UBN DESCRIPTION ONLY WHEN SELECTED (LIKE CLICK & COLLECT)
--------------------------------------------------------------*/
add_action('wp_footer', 'ubn_append_selected_shipping_description_js');
function ubn_append_selected_shipping_description_js() {
    if ( ! is_cart() && ! is_checkout() ) {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Target both checkout and cart execution pages
        if (document.body.classList.contains("woocommerce-checkout") || document.body.classList.contains("woocommerce-cart")) {
            
            function handleUbnDescriptionInjection() {
                const ubnDescription = window.ubn_settings ? window.ubn_settings.description : '';
                
                // If the setting description field is empty, do nothing
                if (!ubnDescription) return;

                // Loop through all available shipping methods on the page
                $('input.shipping_method').each(function() {
                    const $radio = $(this);
                    
                    // Check if this method is UBN and if it is currently selected
                    if ($radio.val().indexOf('ubn_hub_express') !== -1 && $radio.prop('checked')) {
                        
                        // Prevent duplicate descriptions from attaching to the exact same item element wrapper
                        if ($radio.closest('li').find('.ubn-dynamic-shipping-description').length === 0) {
                            $radio.closest('li').append(
                                '<div class="ubn-dynamic-shipping-description" style="margin-top: 7px; font-size: 12px; color: #555;">' + ubnDescription + '</div>'
                            );
                        }
                    }
                });
            }

            // Initial verification check when the page finishes loading
            handleUbnDescriptionInjection();

            // Event listener to check whenever a shipping method option radio gets modified manually
            $(document).on('change', 'input.shipping_method', function() {
                // Instantly scrub any old UBN description divs off the page layout
                $('.ubn-dynamic-shipping-description').remove();
                // Re-evaluate injection logic rules
                handleUbnDescriptionInjection();
            });

            // Re-run safely after AJAX updates complete (Covers standard Cart/Checkout table recalculations)
            $(document).on('updated_checkout updated_shipping_method fragments_refreshed', function() {
                $('.ubn-dynamic-shipping-description').remove();
                handleUbnDescriptionInjection();
            });
        }
    });
    </script>
    <?php
}
/*--------------------------------------------------------------
# WOOCOMMERCE SHIPPING METHOD CLASS (v3.2 QUOTE)
--------------------------------------------------------------*/

add_action('woocommerce_shipping_init', 'ubn_hub_v3_shipping_init');

function ubn_hub_v3_shipping_init() {
    if (!class_exists('WC_UBN_Shipping_Method')) {
        class WC_UBN_Shipping_Method extends WC_Shipping_Method {
            
            public function __construct($instance_id = 0) {
                $this->id                 = 'ubn_hub_express'; 
                $this->instance_id        = absint($instance_id);
                $this->method_title = __('Livraison rapide par colis', 'textdomain');
                $this->method_description = __('Dynamic Reunion shipping via UBN HUB v3.2 protocol.', 'textdomain');
                $this->supports           = array('shipping-zones', 'instance-settings');
                $this->init();
            }

            public function init() {
                $this->init_form_fields();
                $this->init_settings();
                $this->title = get_option('ubn_ss_label','Livraison rapide par colis');
                add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
            }

            public function calculate_shipping($package = array()) {
				
				if (!ubn_ss_is_enabled()) {
					return;
				}
				
                $dest_postcode = $package['destination']['postcode'];
                $dest_city     = $package['destination']['city'];

                if (empty($dest_postcode) || empty($dest_city)) return;

                $api_items = [];
                foreach ($package['contents'] as $item) {
                    $_product = $item['data'];
                    
                    $l = (float)$_product->get_length() ?: 10;
                    $w = (float)$_product->get_width() ?: 10;
                    $h = (float)$_product->get_height() ?: 10;
                    
                    $api_items[] = [
                        'qty'            => $item['quantity'],
                        'type'           => 'Colis', 
                        'description'    => $_product->get_name(),
                        'weight'         => (float)$_product->get_weight() ?: 1.0,
                        'length'         => $l,
                        'width'          => $w,
                        'height'         => $h,
                        'sum_dimensions' => ($l + $w + $h),
                        'value'          => (float)$_product->get_price()
                    ];
                }

				// 🚫 Block ONLY add-to-cart AJAX
				if ( defined('DOING_AJAX') && DOING_AJAX ) {

					$action = $_REQUEST['action'] ?? '';

					// Block add-to-cart ONLY
					if ($action === 'woocommerce_add_to_cart') {
						return;
					}

					// ✅ Allow checkout update AJAX
					if ($action === 'woocommerce_update_order_review') {
						// allow
					}
				}

				// 🚫 Prevent on single product page
				if ( is_product() ) {
					return;
				}

				// ✅ Allow cart, checkout, AND checkout AJAX fallback
				if ( !is_cart() && !is_checkout() && !defined('DOING_AJAX') ) {
					return;
				}
				
				// Calculate custom customer-facing shipping price
				// Fetch fixed shipping price directly from options without checking quantity
				$customer_shipping_cost = (float) get_option('ubn_ss_price_per_qty', 15);

				$rate_args = [
					'id'    => $this->id,
					'label' => $this->title,
					'cost'  => $customer_shipping_cost
				];

				// Force boolean false if the setting is enabled to completely bypass WC tax calculation
				if ( get_option('ubn_ss_tax_none') == 1 ) {
					$rate_args['taxes'] = false;
				}

				$this->add_rate($rate_args);
            }
            

            private function get_ubn_v3_quote($postcode, $city, $items) {
                $selected_service = get_option('ubn_ss_service', 'express'); 

                $payload = [
                    'id_api_connect'     => UBN_PARTNER_ID,
                    'ubn_sr_source_site' => UBN_SOURCE_SITE,
                    'ref_commande'       => 'WOO-' . time(),
                    'service'            => $selected_service,
                    'type_of_shipment'   => 'Intradepartement',
                    'postcode'           => $postcode,
                    'city'               => $city,
                    'items'              => $items
                ];

                $body_raw = json_encode($payload);
                $ts       = time();
                $sig      = hash_hmac("sha256", $ts . "." . $body_raw, UBN_API_KEY);

                $response = wp_remote_post(UBN_API_BASE . '/quote', [
                    'headers' => [
                        'X-UBN-API-KEY'      => UBN_API_KEY,
                        'X-UBN-Source-Site'  => UBN_SOURCE_SITE,
                        'X-UBN-Partner'      => UBN_PARTNER_ID,
                        'X-UBN-Customer'     => UBN_PARTNER_ID,
                        'X-UBN-Timestamp'    => $ts,
                        'X-UBN-Sign'         => $sig,
                        'Content-Type'       => 'application/json'
                    ],
                    'body'    => $body_raw,
                    'timeout' => 15
                ]);

                if (is_wp_error($response)) {

					ubn_add_log(
						'QUOTE_ERROR',
						UBN_API_BASE . '/quote',
						$payload,
						$response->get_error_message(),
						'500',
						0,
						'Quote API request failed'
					);

					return false;
				}
                
                $data = json_decode(wp_remote_retrieve_body($response), true);
                
                return isset($data['pricing']['total_ttc']) ? $data['pricing']['total_ttc'] : false;
            }
        }
    }
}


add_filter('woocommerce_shipping_methods', function($methods) {
	$methods['ubn_hub_express'] = 'WC_UBN_Shipping_Method';
	return $methods;
});


/*--------------------------------------------------------------
# CLEAR CACHED SHIPPING RATES
--------------------------------------------------------------*/
add_action('woocommerce_before_checkout_form', function () {

    if (WC()->session) {

        WC()->session->__unset('shipping_for_package_0');

    }

});

// Load the Delivery Note Module
require_once plugin_dir_path(__FILE__) . 'ubn-delivery-note.php';

// Load the Daily Report Module
require_once plugin_dir_path(__FILE__) . 'ubn-daily-report.php';

?>