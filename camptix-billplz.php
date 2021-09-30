<?php
/**
 * Plugin Name: Billplz for CampTix
 * Plugin URI: https://github.com/Billplz/Billplz-for-CampTix
 * Description: Billplz Payment Gateway
 * Author: Wanzul Hosting Enterprise
 * Author URI: http://www.fb.com/billplzplugin
 * Version: 3.0
 * License: GPLv2 or later
 * Text Doomain: billplz
 */
if (!defined('ABSPATH'))
    exit; // Exit if accessed directly


    
// Add MYR currency
add_filter('camptix_currencies', 'camptix_billplz_add_inr_currency');

function camptix_billplz_add_inr_currency($currencies)
{
    if (!$currencies['MYR']) {
        $currencies['MYR'] = array(
            'label' => __('Ringgit Malaysia', 'billplz'),
            'format' => 'RM %s',
        );
    }
    return $currencies;
}
// Load the Billplz Payment Method
add_action('camptix_load_addons', 'camptix_billplz_load_payment_method');

function camptix_billplz_load_payment_method()
{
    if (!class_exists('CampTix_Payment_Method_Billplz')) {
        require_once plugin_dir_path(__FILE__) . 'includes/billplz.php';
        require_once plugin_dir_path(__FILE__) . 'classes/class-camptix-payment-method-billplz.php';
    }
    camptix_register_addon('CampTix_Payment_Method_Billplz');
}
// Remove Record created by this plugin
register_uninstall_hook(__FILE__, 'camptix_billplz_uninstall');

function camptix_billplz_uninstall()
{
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'billplz_camptix_%'");
}
