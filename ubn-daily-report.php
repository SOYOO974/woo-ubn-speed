<?php

if (!defined('ABSPATH')) exit;

/*--------------------------------------------------------------
# REGISTER SETTINGS
--------------------------------------------------------------*/
add_action('admin_init', 'ubn_ss_daily_report_settings');

function ubn_ss_daily_report_settings() {
    register_setting('ubn_ss_daily_report', 'ubn_ss_report_time');
    register_setting('ubn_ss_daily_report', 'ubn_ss_report_empty');
    register_setting('ubn_ss_daily_report', 'ubn_ss_daily_report_subject');
    register_setting('ubn_ss_daily_report', 'ubn_ss_daily_report_template');
    register_setting('ubn_ss_daily_report', 'ubn_ss_daily_report_empty_subject');
    register_setting('ubn_ss_daily_report', 'ubn_ss_daily_report_empty_template');
}

/*--------------------------------------------------------------
# RENDER DAILY REPORT TAB
--------------------------------------------------------------*/
function ubn_render_daily_report_tab() {
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('ubn_ss_daily_report'); ?>
        <div class="ubn-card">
            <div class="ubn-title">Daily Report Settings</div>
            
            <p style="color:#666;line-height:1.6;margin-bottom:20px;">
                Configure the daily cron job that sends a summary of yesterday's UBN Speed orders to each store.
            </p>

            <div class="ubn-field">
                <div>
                    <strong>Execution Time</strong><br>
                    <small>Time to send the daily report (Local Time)</small>
                </div>
                <input type="time" name="ubn_ss_report_time" value="<?php echo esc_attr(get_option('ubn_ss_report_time', '09:00')); ?>" class="regular-text" style="max-width: 150px;">
            </div>

            <div class="ubn-field">
                <div>
                    <strong>Send Empty Report Alert</strong><br>
                    <small>If checked, send an email even if there are no orders.</small>
                </div>
                <label class="ubn-switch">
                    <input type="checkbox" name="ubn_ss_report_empty" value="1" <?php checked(get_option('ubn_ss_report_empty'), 1); ?>>
                    <span class="ubn-slider"></span>
                </label>
            </div>
        </div>

        <div class="ubn-card">
            <div class="ubn-title">Email Templates</div>
            <p style="margin-bottom:20px;color:#666;line-height:2;">
                <strong>Available tags:</strong> 
                <code>{site_name}</code>
                <code>{store_name}</code>
                <code>{date_phrase}</code>
                <code>{orders_count}</code>
                <code>{orders_table}</code>
            </p>
            
            <div style="margin-bottom: 30px;">
                <h3 style="margin-bottom: 10px;">Standard Report Email (Contains Orders)</h3>
                <div class="ubn-field" style="align-items:flex-start;">
                    <label style="min-width:120px;margin-top:5px;"><strong>Subject</strong></label>
                    <input type="text" name="ubn_ss_daily_report_subject" value="<?php echo esc_attr(get_option('ubn_ss_daily_report_subject', '[UBN SPEED] {store_name} - Rapport Journalier')); ?>" class="regular-text" style="min-width:600px;">
                </div>
                <div style="margin-top:10px;">
                    <?php
                    $primary_color = esc_attr(get_option('ubn_ss_primary_color', '#db3832'));
                    $default_template = '<div style="font-family: Arial,Helvetica,sans-serif; padding: 0;">
<div style="max-width: 800px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e5e5e5;">
<div style="background: ' . $primary_color . '; padding: 20px 20px;">
<h2 style="margin: 0; color: #ffffff;">Rapport journalier UBN Speed - {store_name}</h2>
</div>
<div style="padding: 20px;">
<p style="margin-top: 0;">Bonjour,</p>
<p>Voici le récapitulatif des commandes UBN Speed créées <strong>{date_phrase}</strong> ({orders_count} commandes).</p>
<div style="background: #fafafa; border-left: 4px solid ' . $primary_color . '; padding: 15px 20px; border-radius: 6px; margin: 25px 0;">Merci de préparer ces articles afin qu\'ils soient prêts avant <strong>10h00 le prochain jour d’ouverture</strong>.</div>

{orders_table}

<br>
Merci pour votre collaboration.
</div>
<div style="background: #fafafa; padding: 20px 30px; border-top: 1px solid #eeeeee;">
<div style="font-size: 18px; font-weight: bold; color: ' . $primary_color . ';">{site_name}</div>
<div style="color: #777777; font-size: 13px; margin-top: 5px;">Notification automatique UBN Speed</div>
</div>
</div>
</div>';
                    $template = get_option('ubn_ss_daily_report_template', $default_template);
                    wp_editor($template, 'ubn_ss_daily_report_template_editor', [
                        'textarea_name' => 'ubn_ss_daily_report_template',
                        'textarea_rows' => 18,
                        'media_buttons' => false,
                        'teeny' => false,
                        'quicktags' => true,
                    ]);
                    ?>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <h3 style="margin-bottom: 10px;">Empty Report Email (No Orders)</h3>
                <div class="ubn-field" style="align-items:flex-start;">
                    <label style="min-width:120px;margin-top:5px;"><strong>Subject</strong></label>
                    <input type="text" name="ubn_ss_daily_report_empty_subject" value="<?php echo esc_attr(get_option('ubn_ss_daily_report_empty_subject', '[UBN SPEED] {store_name} - Aucune commande à préparer')); ?>" class="regular-text" style="min-width:600px;">
                </div>
                <div style="margin-top:10px;">
                    <?php
                    $default_empty_template = '<div style="font-family: Arial,Helvetica,sans-serif; padding: 0;">
<div style="max-width: 800px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e5e5e5;">
<div style="background: ' . $primary_color . '; padding: 20px 20px;">
<h2 style="margin: 0; color: #ffffff;">Rapport journalier UBN Speed - {store_name}</h2>
</div>
<div style="padding: 20px;">
<p style="margin-top: 0;">Bonjour,</p>
<div style="background: #fafafa; border: 1px solid #eeeeee; padding: 15px 20px; border-radius: 6px; margin: 25px 0;">Aucune commande Livraison Plume n\'est à préparer aujourd\'hui pour <strong>{store_name}</strong>.</div>
<br>
Merci pour votre collaboration.
</div>
<div style="background: #fafafa; padding: 20px 30px; border-top: 1px solid #eeeeee;">
<div style="font-size: 18px; font-weight: bold; color: ' . $primary_color . ';">{site_name}</div>
<div style="color: #777777; font-size: 13px; margin-top: 5px;">Notification automatique UBN Speed</div>
</div>
</div>
</div>';
                    $empty_template = get_option('ubn_ss_daily_report_empty_template', $default_empty_template);
                    wp_editor($empty_template, 'ubn_ss_daily_report_empty_template_editor', [
                        'textarea_name' => 'ubn_ss_daily_report_empty_template',
                        'textarea_rows' => 15,
                        'media_buttons' => false,
                        'teeny' => false,
                        'quicktags' => true,
                    ]);
                    ?>
                </div>
            </div>
        </div>

        <?php submit_button('Save Report Settings'); ?>
    </form>
    <?php
}

/*--------------------------------------------------------------
# CRON SCHEDULING MANAGEMENT
--------------------------------------------------------------*/
// Reschedule when the setting is updated
add_action('update_option_ubn_ss_report_time', 'ubn_reschedule_daily_report_on_update', 10, 3);
function ubn_reschedule_daily_report_on_update($old_value, $new_value, $option_name) {
    $timestamp = wp_next_scheduled('ubn_daily_report_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ubn_daily_report_event');
    }
    
    // Calculate the next execution time based on the timezone
    $timezone = wp_timezone();
    $time_string = $new_value ?: '09:00';
    
    // Get current time in WP timezone
    $now = new DateTime('now', $timezone);
    $schedule_time = new DateTime($now->format('Y-m-d') . ' ' . $time_string, $timezone);
    
    // If the time has already passed today, schedule for tomorrow
    if ($schedule_time <= $now) {
        $schedule_time->modify('+1 day');
    }
    
    wp_schedule_event($schedule_time->getTimestamp(), 'daily', 'ubn_daily_report_event');
}

// Ensure cron is scheduled on init if missing
add_action('init', 'ubn_ensure_daily_report_scheduled');
function ubn_ensure_daily_report_scheduled() {
    if (!wp_next_scheduled('ubn_daily_report_event')) {
        $time_string = get_option('ubn_ss_report_time', '09:00');
        $timezone = wp_timezone();
        
        $now = new DateTime('now', $timezone);
        $schedule_time = new DateTime($now->format('Y-m-d') . ' ' . $time_string, $timezone);
        
        if ($schedule_time <= $now) {
            $schedule_time->modify('+1 day');
        }
        
        wp_schedule_event($schedule_time->getTimestamp(), 'daily', 'ubn_daily_report_event');
    }
}

/*--------------------------------------------------------------
# REPORT GENERATION & EXECUTION
--------------------------------------------------------------*/
add_action('ubn_daily_report_event', 'ubn_execute_daily_report');

function ubn_execute_daily_report() {
    $timezone = wp_timezone();
    $now = new DateTime('now', $timezone);
    $day_of_week = (int) $now->format('N'); // 1 (Mon) to 7 (Sun)
    
    // Skip weekends
    if ($day_of_week === 6 || $day_of_week === 7) {
        return;
    }

    // 1. Determine timeframe based on day of week
    if ($day_of_week === 1) {
        // Monday: include Friday, Saturday, Sunday
        $start_date = new DateTime('last friday 00:00:00', $timezone);
        $end_date   = new DateTime('yesterday 23:59:59', $timezone);
        $date_query_str = $start_date->format('Y-m-d') . '...' . $end_date->format('Y-m-d');
        $date_phrase = 'du ' . $start_date->format('d/m/Y') . ' au ' . $end_date->format('d/m/Y');
    } else {
        // Tuesday-Friday: just yesterday
        $start_date = new DateTime('yesterday 00:00:00', $timezone);
        $date_query_str = $start_date->format('Y-m-d');
        $date_phrase = 'le ' . $start_date->format('d/m/Y');
    }

    // 2. Query orders (using HPOS-compatible _ubn_order)
    $orders = wc_get_orders([
        'limit'        => -1,
        'date_created' => $date_query_str,
        'status'       => array_keys(wc_get_order_statuses()),
        'meta_key'     => '_ubn_order',
        'meta_value'   => 'yes',
    ]);

    $mode = ubn_get_store_mode();

    if ($mode === 'single') {
        $single_orders = [];
        foreach ($orders as $order) {
            $shipment_created = get_post_meta($order->get_id(), '_ubn_shipment_created', true);
            if ($shipment_created !== 'yes') {
                continue;
            }
            $single_orders[] = $order;
        }
        $brand_name = get_option('ubn_ss_brand_name', get_bloginfo('name'));
        ubn_process_store_report('single', $brand_name, $single_orders, $date_phrase);
    } else {
        // 3. Separate by store & filter legacy meta
        $nord_orders = [];
        $sud_orders = [];

        foreach ($orders as $order) {
            $shipment_created = get_post_meta($order->get_id(), '_ubn_shipment_created', true);
            if ($shipment_created !== 'yes') {
                continue;
            }

            $store_id = ubn_get_order_store_id($order);
            if ($store_id === '105') {
                $nord_orders[] = $order;
            } elseif ($store_id === '106') {
                $sud_orders[] = $order;
            }
        }

        // 4. Process and send emails
        ubn_process_store_report('105', 'Conforama Nord', $nord_orders, $date_phrase);
        ubn_process_store_report('106', 'Conforama Sud', $sud_orders, $date_phrase);
    }
}

function ubn_process_store_report($store_id, $store_name, $orders, $date_phrase) {
    $emails = ubn_get_store_notification_emails($store_id);
    if (empty($emails)) {
        return; // Nowhere to send
    }

    $orders_count = count($orders);
    $site_name = get_option('ubn_ss_brand_name', get_bloginfo('name'));
    $primary_color = esc_attr(get_option('ubn_ss_primary_color', '#db3832'));
    
    // Check if empty and if we should send empty report
    if ($orders_count === 0) {
        $send_empty = get_option('ubn_ss_report_empty');
        if (!$send_empty) {
            return;
        }
        
        $subject = get_option('ubn_ss_daily_report_empty_subject', '[UBN SPEED] {store_name} - Aucune commande à préparer');
        $template = get_option('ubn_ss_daily_report_empty_template', '');
        
        $tags = [
            '{site_name}'    => $site_name,
            '{store_name}'   => $store_name,
            '{date_phrase}'  => $date_phrase,
            '{orders_count}' => $orders_count,
        ];
        
        $subject = str_replace(array_keys($tags), array_values($tags), $subject);
        $message = str_replace(array_keys($tags), array_values($tags), $template);
        $message = wpautop($message);
    } else {
        // Build the HTML for the orders
        $orders_html = '';
        
        foreach ($orders as $order) {
            $order_id = $order->get_id();
            
            // Build products HTML inside the order
            $products_html = '';
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) continue;
                $sku = $product->get_sku();
                
                $image_url = ubn_get_product_image_url($product, $item, 'thumbnail');
                
                $products_html .= '
                <div style="padding: 8px 0; border-bottom: 1px solid #eeeeee;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 50px; vertical-align: middle; padding-right: 15px;">
                                <img src="' . esc_url($image_url) . '" alt="" data-no-lazy="1" data-skip-lazy="1" class="no-lazy skip-lazy" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #e5e5e5;" />
                            </td>
                            <td style="vertical-align: middle;">
                                <div style="font-weight: 600; font-size: 14px;">' . esc_html($item->get_name()) . '</div>
                                <div style="color: #666; font-size: 12px; margin-top: 4px;">SKU: ' . esc_html($sku ?: 'N/A') . ' | Quantité: ' . (int) $item->get_quantity() . '</div>
                            </td>
                        </tr>
                    </table>
                </div>';
            }
            
            $print_note_url = home_url('/?ubn_print_note=' . $order_id . '&token=' . ubn_generate_print_token($order_id));
            $tracking_number = get_post_meta($order_id, '_ubn_tracking_number', true);
            $shipment_id = get_post_meta($order_id, '_ubn_shipment_id', true);
            
            $orders_html .= '
            <div style="border: 2px solid #e5e5e5; border-radius: 8px; margin-bottom: 20px; background: #ffffff;">
                <div style="background: #f9f9f9; padding: 15px 20px; border-bottom: 1px solid #e5e5e5; border-top-left-radius: 6px; border-top-right-radius: 6px;">
                    <h3 style="margin: 0; font-size: 16px;">Commande n°' . esc_html($order->get_order_number()) . '</h3>
                </div>
                <div style="padding: 20px;">
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
                        <tbody>
                            <tr>
                                <td style="padding: 5px 0; width: 140px; color: #666;"><strong>ID Expédition :</strong></td>
                                <td style="padding: 5px 0;">' . esc_html($shipment_id) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 5px 0; color: #666;"><strong>Numéro de suivi :</strong></td>
                                <td style="padding: 5px 0;">' . esc_html($tracking_number) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 5px 0; color: #666;"><strong>Client :</strong></td>
                                <td style="padding: 5px 0;">' . esc_html($order->get_formatted_billing_full_name()) . '</td>
                            </tr>
                            <tr>
                                <td style="padding: 5px 0; color: #666;"><strong>Téléphone :</strong></td>
                                <td style="padding: 5px 0;">' . esc_html($order->get_billing_phone()) . '</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div style="background: #fafafa; border: 1px solid #eeeeee; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <h4 style="margin-top: 0; margin-bottom: 10px; color: #333;">Produits</h4>
                        ' . $products_html . '
                    </div>
                    <div style="margin-top: 15px;">
                        <a href="' . esc_url($print_note_url) . '" target="_blank" rel="noopener" style="display: inline-block; background: #222222; color: #ffffff; text-decoration: none; padding: 12px 20px; border-radius: 6px; font-weight: bold; font-size: 14px; margin-right: 10px; margin-bottom: 10px;">Imprimer le bon de livraison</a><a href="https://ubn-speed.fr/suivi-colis/?ubn_tracking=' . esc_attr($tracking_number) . '" target="_blank" rel="noopener" style="display: inline-block; background: ' . $primary_color . '; color: #ffffff; text-decoration: none; padding: 12px 20px; border-radius: 6px; font-weight: bold; font-size: 14px; margin-bottom: 10px;">Voir le suivi</a>
                    </div>
                </div>
            </div>';
        }
        
        $subject = get_option('ubn_ss_daily_report_subject', '[UBN SPEED] {store_name} - Rapport Journalier');
        $template = get_option('ubn_ss_daily_report_template', '');
        
        $tags = [
            '{site_name}'    => $site_name,
            '{store_name}'   => $store_name,
            '{date_phrase}'  => $date_phrase,
            '{orders_count}' => $orders_count,
            '{orders_table}' => $orders_html,
        ];
        
        $subject = str_replace(array_keys($tags), array_values($tags), $subject);
        $message = str_replace(array_keys($tags), array_values($tags), $template);
        $message = wpautop($message);
    }
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8'
    ];
    
    $sent = wp_mail($emails, $subject, $message, $headers);
    
    if ($sent) {
        ubn_add_log(
            'daily_report_success',
            '',
            '',
            $emails,
            '',
            0,
            'Daily report sent for ' . $store_name . ' (' . $orders_count . ' orders)'
        );
    } else {
        ubn_add_log(
            'daily_report_error',
            '',
            '',
            $emails,
            '',
            0,
            'Failed to send daily report for ' . $store_name
        );
    }
}
