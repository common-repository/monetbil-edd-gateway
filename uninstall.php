<?php

/**
 * Uninstall Monetbil
 *
 * Deletes all settings.
 *
 * @package     Monetbil
 * @subpackage  Uninstall
 * @copyright   Copyright (c) 2017, Serge NTONG
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.4.3
 */
// Exit if accessed directly.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load Monetbil file.
include_once( 'monetbil-edd-gateway.php' );

if (class_exists('Easy_Digital_Downloads') and class_exists('Monetbil_Edd_Gateway')) {
    edd_delete_option(Monetbil_Edd_Gateway::MONETBIL_SERVICE_KEY);
    edd_delete_option(Monetbil_Edd_Gateway::MONETBIL_SERVICE_SECRET);
    edd_delete_option(Monetbil_Edd_Gateway::WIDGET_VERSION);
    edd_delete_option(Monetbil_Edd_Gateway::MONETBIL_PAYMENT_REDIRECTION);
}

