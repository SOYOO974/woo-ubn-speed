<?php

if (!defined('ABSPATH')) exit;

/*--------------------------------------------------------------
# GENERATE SECURE PRINT TOKEN
--------------------------------------------------------------*/
function ubn_generate_print_token($order_id) {
    return wp_hash('ubn_print_' . $order_id);
}

/*--------------------------------------------------------------
# INTERCEPT PRINT REQUEST
--------------------------------------------------------------*/
add_action('template_redirect', 'ubn_handle_delivery_note_print');

function ubn_handle_delivery_note_print() {

    if (empty($_GET['ubn_print_note']) || empty($_GET['token'])) {
        return;
    }

    // Disable EWWW Image Optimizer & WP Rocket Lazy Load on Delivery Note Print Page
    add_filter('eio_lazy_load_enabled', '__return_false', 9999);
    add_filter('ewww_image_optimizer_lazy_load_enabled', '__return_false', 9999);
    add_filter('do_rocket_lazyload', '__return_false', 9999);
    add_filter('rocket_lazyload_images', '__return_false', 9999);

    $order_id = absint($_GET['ubn_print_note']);
    $token = sanitize_text_field($_GET['token']);

    if (!hash_equals(ubn_generate_print_token($order_id), $token)) {
        wp_die('Invalid or expired print token.', 'Unauthorized', ['response' => 403]);
    }

    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_die('Order not found.', 'Not Found', ['response' => 404]);
    }

    // Load Shipment data
    $shipment_data = get_post_meta($order_id, '_ubn_shipment_response', true);
    if (!is_array($shipment_data)) {
        $shipment_data = [];
    }

    // Reuse the exact same tag logic from the email
    $tags = ubn_prepare_email_tags($order, $shipment_data);

    // Get the template
    $template = get_option('ubn_ss_delivery_note_template', '');
    
    if (empty($template)) {
        $template = '<div style="text-align:center; padding: 50px; font-family: sans-serif;">
                        <h2>Delivery Note Not Configured</h2>
                        <p>Please configure the Delivery Note template in the UBN Speed Shipping settings.</p>
                     </div>';
    }

    // QR Code Data
    $tracking_number = $shipment_data['tracking_number'] ?? '';

    // Create the QR Code HTML placeholder
    $qr_placeholder = '';
    if (!empty($tracking_number)) {
        $qr_placeholder = '<div id="ubn-qr-code" style="display:inline-block;"></div>';
        $qr_placeholder .= '<div style="font-size:10px; font-weight:bold; letter-spacing:1px; margin-top:5px; color:#333;">ID ' . esc_html($tracking_number) . '</div>';
    } else {
        $qr_placeholder = '<div style="color:#dc3232; font-weight:600; font-size: 12px;">No tracking number available yet</div>';
    }

    // Replace all standard tags
    $content = str_replace(array_keys($tags), array_values($tags), $template);

    // Replace {qr_code} tag
    $content = str_replace('{qr_code}', $qr_placeholder, $content);
    
    // Apply wpautop to match the email template behavior
    $content = wpautop($content);

    // HTML Output
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title>Bon de Livraison - Commande #<?php echo esc_html($order->get_order_number()); ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 0;
                background: #fff;
                color: #000;
            }
            @media print {
                @page {
                    size: A4;
                    margin: 5mm;
                }
                body {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                    font-size: 13px; /* Slightly compact text for print */
                }
                .no-print { display: none !important; }
                
                /* Smart Page Breaks */
                table, .ubn-print-container table {
                    page-break-inside: auto;
                }
                tr, td, th {
                    page-break-inside: avoid;
                    page-break-after: auto;
                }
                .ubn-print-container h3, .ubn-print-container h2, .ubn-print-container h1 {
                    page-break-after: avoid;
                }
                /* Prevent QR Code from splitting */
                #ubn-qr-code {
                    page-break-inside: avoid;
                }
            }
            .ubn-print-container {
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
                box-sizing: border-box;
            }
            /* Clean up table borders for print */
            .ubn-print-container table {
                border-collapse: collapse;
            }

            /* 2-Column Product Grid Logic (Equal Height) */
            .ubn-products-grid {
                display: grid;
                grid-template-columns: 48.5% 48.5%;
                gap: 15px 3%;
            }
            /* The X Articles text needs to span both columns */
            .ubn-products-grid > div:first-child {
                grid-column: 1 / -1;
            }
            /* Tables inside the grid */
            .ubn-products-grid table {
                width: 100% !important;
                margin: 0 !important;
                box-sizing: border-box;
                height: 100%;
            }
            /* Hide empty <p> tags injected by WordPress wpautop between tables */
            .ubn-products-grid > p {
                display: none !important;
            }
			.ubn-products-grid tr td:nth-child(2) {
				padding-right: 8px;
				padding-bottom:15px;
			}
			.ubn-products-grid>div {
				margin-bottom: 5px !important;
			}
        </style>
        <!-- QRCode.js -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    </head>
    <body>
        <div class="ubn-print-container">
            <?php echo $content; ?>
        </div>

        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var qrDataString = <?php echo wp_json_encode($tracking_number); ?>;
                var qrContainer = document.getElementById("ubn-qr-code");
                
                if (qrDataString && qrContainer) {
                    new QRCode(qrContainer, {
                        text: qrDataString,
                        width: 150,
                        height: 150,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.M
                    });

                    // Wait a tiny bit for the QR code to render before triggering print
                    setTimeout(function() {
                        window.print();
                    }, 500);
                } else {
                    window.print();
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

?>