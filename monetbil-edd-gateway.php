<?php

/**
  Plugin Name: Monetbil - Mobile Money Gateway for Easy Digital Downloads
  Plugin URI: https://github.com/Monetbil/monetbil-wordpress-easy-digital-downloads
  Description: A Payment Gateway for Mobile Money Payments - Easy Digital Downloads
  Version: 1.15
  Author: Serge NTONG
  Author URI: https://www.monetbil.com/
  Text Domain: monetbil
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'init_monetbil_edd_gateway', 0);

function init_monetbil_edd_gateway()
{
    if (!class_exists('Easy_Digital_Downloads')) {
        return;
    }

    // Save settings
    if (is_admin()) {
        // For old version
        $version = edd_get_option(Monetbil_Edd_Gateway::WIDGET_VERSION);

        if (0 === strpos($version, 'v2')) {
            edd_update_option(Monetbil_Edd_Gateway::WIDGET_VERSION, Monetbil_Edd_Gateway::WIDGET_VERSION_V2);
        }
    }

    /**
     * Registering the Gateway
     *
     * @param  array $gateways
     * @return array
     */
    function monetbil_edd_register_gateway($gateways)
    {
        $gateways[Monetbil_Edd_Gateway::GATEWAY] = array(
            'admin_label' => 'Monetbil',
            'checkout_label' => __('Monetbil Mobile Money', Monetbil_Edd_Gateway::GATEWAY)
        );
        return $gateways;
    }

    /**
     * Setup a custom Credit Card form for Monetbil
     */
    function pw_edd_monetbil_cc_form()
    {
        ob_start();
        echo ob_get_clean();
    }

    /**
     * Register scripts
     */
    function monetbil_edd_register_scripts()
    {

        if (!Monetbil_Edd_Gateway::isMonetbilEDDPaymentPage()) {
            return;
        }

        wp_register_style('mnbeddbootstrap', plugins_url('assets/css/bootstrap.min.css', __FILE__), '', '', false);
        wp_register_style('mnbeddstyle', plugins_url('assets/css/style.css', __FILE__), '', '', false);

        wp_register_script('monetbil-widget-v1', plugins_url('assets/js/monetbil-mobile-payments.js', __FILE__), '', time(), true);
        wp_register_script('monetbil-widget-v2', plugins_url('assets/js/monetbil.min.js', __FILE__), '', time(), true);

        wp_enqueue_style('mnbeddbootstrap');
        wp_enqueue_style('mnbeddstyle');

        if (Monetbil_Edd_Gateway::WIDGET_VERSION_V2 == Monetbil_Edd_Gateway::getWidgetVersion()) {
            wp_enqueue_script('monetbil-widget-v2');
        } else {
            wp_enqueue_script('monetbil-widget-v1');
        }
    }

    /**
     * Change the purchase button
     *
     * @param string $button_purchase
     * @return string
     */
    function edd_custom_edd_checkout_button_purchase($button_purchase)
    {
        $payment_mode = Monetbil_Edd_Gateway::getQuery('payment-mode');

        if (Monetbil_Edd_Gateway::GATEWAY != $payment_mode) {
            return $button_purchase;
        }

        ob_start();
        return $button_purchase;
    }

    /**
     * Updates a payment amount.
     *
     * @since  1.0
     * @param  int    $payment_id Payment ID
     * @param  int $new_amount New Payment Amount
     * @return bool               If the payment was successfully updated
     */
    function edd_update_payment_amount($payment_id, $new_amount)
    {
        $updated = false;
        $payment = new EDD_Payment($payment_id);

        if ($payment && $payment->ID > 0) {

            $payment->total = $new_amount;
            $updated = $payment->save();
        }

        return $updated;
    }

    /**
     * If the current user is the author of an item in someone's purchase, let
     * them view the purchase receipt.
     *
     * @param boolean $user_can_view
     * @param array $edd_receipt_args
     * @return boolean $user_can_view
     */
    function monetbil_user_can_view_receipt($user_can_view, $edd_receipt_args)
    {
        $cart = edd_get_payment_meta_cart_details($edd_receipt_args['id']);

        foreach ($cart as $item) {
            $item = get_post($item['id']);

            if ($item->post_author == get_current_user_id()) {
                $user_can_view = true;
                break;
            }
        }

        return $user_can_view;
    }

    /**
     * Hook on /monetbil/edd/notify
     */
    function monetbil_edd_notify()
    {

        if (!Monetbil_Edd_Gateway::isMonetbilEDDNotify()) {
            return;
        }

//        if (!Monetbil_Edd_Gateway::checkServer()) {
//            header('HTTP/1.0 404 Not Found');
//            exit('Error: 404 Not Found');
//        }

        $service_secret = Monetbil_Edd_Gateway::getServiceSecret();
        $params = Monetbil_Edd_Gateway::getPost();

        if (!Monetbil_Edd_Gateway::checkSign($service_secret, $params)) {
            header('HTTP/1.0 403 Forbidden');
            exit('Error: Invalid signature');
        }

        $amount = absint(Monetbil_Edd_Gateway::getPost('amount', 0));
        $payment_id = Monetbil_Edd_Gateway::getPost('item_ref');
        $transaction_id = Monetbil_Edd_Gateway::getPost('transaction_id');

        list($payment_status, $testmode) = Monetbil_Edd_Gateway::checkPayment($transaction_id);

        edd_update_payment_amount($payment_id, $amount);

        if (Monetbil_Edd_Gateway::STATUS_SUCCESS == $payment_status
                or Monetbil_Edd_Gateway::STATUS_SUCCESS_TESTMODE == $payment_status
        ) {

            // Payment has been successful
            $order_state = 'publish';
            $note = __('[Monetbil] Successful payment! #' . $transaction_id, Monetbil_Edd_Gateway::GATEWAY);

            if ($testmode) {
                $order_state = 'pending';
                $note .= ' - TEST MODE';
            }

            edd_update_payment_status($payment_id, $order_state);
            edd_insert_payment_note($payment_id, $note);
        } elseif (Monetbil_Edd_Gateway::STATUS_CANCELLED == $payment_status
                or Monetbil_Edd_Gateway::STATUS_CANCELLED_TESTMODE == $payment_status) {

            // Transaction cancelled
            $order_state = 'abandoned';
            $note = __('[Monetbil] Transaction cancelled! #' . $transaction_id, Monetbil_Edd_Gateway::GATEWAY);

            if ($testmode) {
                $order_state = 'abandoned';
                $note .= ' - TEST MODE';
            }

            edd_update_payment_status($payment_id, $order_state);
            edd_insert_payment_note($payment_id, $note);
        } elseif (Monetbil_Edd_Gateway::STATUS_FAILED == $payment_status
                or Monetbil_Edd_Gateway::STATUS_FAILED_TESTMODE == $payment_status) {

            // Payment failed
            $order_state = 'failed';
            $note = __('[Monetbil] Payment failed! #' . $transaction_id, Monetbil_Edd_Gateway::GATEWAY);

            if ($testmode) {
                $order_state = 'failed';
                $note .= ' - TEST MODE';
            }

            edd_update_payment_status($payment_id, $order_state);
            edd_insert_payment_note($payment_id, $note);
        }

        // Received
        exit('received');
    }

    /**
     * Hook on /monetbil/edd/return
     */
    function monetbil_edd_return()
    {

        if (!Monetbil_Edd_Gateway::isMonetbilEDDReturnPage()) {
            return;
        }

        $params = Monetbil_Edd_Gateway::getQueryParams();
        $service_secret = Monetbil_Edd_Gateway::getServiceSecret();

        if (!Monetbil_Edd_Gateway::checkSign($service_secret, $params)) {
            edd_send_back_to_checkout('?payment-mode=monetbil');
        }

        $payment_status = Monetbil_Edd_Gateway::getQuery('status');
        $payment_id = Monetbil_Edd_Gateway::getQuery('item_ref');

        if ('success' == $payment_status) {

            // Payment has been successful
            edd_empty_cart();
            edd_send_to_success_page();
        } elseif ('cancelled' == $payment_status) {

            // Transaction cancelled
            edd_send_back_to_checkout('?payment-mode=monetbil');
        } else {

            // Payment failed
            $redirect_url = add_query_arg(array(
                'payment-confirmation' => Monetbil_Edd_Gateway::GATEWAY,
                'payment-id' => $payment_id
                    ), get_permalink(edd_get_option('failure_page', false
            )));

            // Redirect
            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Hook on /monetbil/edd/payment
     */
    function monetbil_edd_payment()
    {

        if (!Monetbil_Edd_Gateway::isMonetbilEDDPaymentPage()) {
            return;
        }

        $payment_id = absint(Monetbil_Edd_Gateway::getQuery('payment_id', 0));

        if (!$payment_id) {
            edd_send_back_to_checkout('?payment-mode=monetbil');
        }

        $payment = edd_get_payment($payment_id);

        if (!$payment) {
            edd_send_back_to_checkout('?payment-mode=monetbil');
        }

        $purchase_data = $payment->array_convert();

        $payment_url = Monetbil_Edd_Gateway::getMonetbilPaymentUrl($payment_id);

        ($payment_url);
        ($purchase_data);

        include 'templates/receipt.php';
    }

    /**
     * Add Actions Links
     *
     * @param  array $actions
     * @return array
     */
    function monetbil_edd_actions_links($actions)
    {
        $custom_actions = array(
            'Create An Account' => '<a href="https://www.monetbil.com/try-monetbil" target="_blank">' . __('Create An Account', Monetbil_Edd_Gateway::GATEWAY) . '</a>',
            'Create new service' => '<a href="https://www.monetbil.com/services/create" target="_blank">' . __('Create new service', Monetbil_Edd_Gateway::GATEWAY) . '</a>'
        );

        return array_merge($custom_actions, $actions);
    }

    /**
     * Register Monetbil subsection
     *
     * @param  array $gateway_sections
     * @return array
     */
    function edd_register_monetbil_section($gateway_sections)
    {
        $gateway_sections[Monetbil_Edd_Gateway::GATEWAY] = __('Monetbil', Monetbil_Edd_Gateway::GATEWAY);
        return $gateway_sections;
    }

    /**
     * Adds the settings to the Payment Gateways section
     *
     * @param  array $gateway_settings
     * @return array
     */
    function edd_register_monetbil_gateway_settings($gateway_settings)
    {
        $service_key = Monetbil_Edd_Gateway::getServiceKey();
        $service_secret = Monetbil_Edd_Gateway::getServiceSecret();

        $configured = false;
        if ($service_key and $service_secret) {
            $configured = true;
        }

        $monetbil_settings = array();

        $monetbil_settings[] = array(
            'id' => 'config',
            'name' => Monetbil_Edd_Gateway::generateNotice($configured),
            'type' => 'header'
        );

        $monetbil_settings[] = array(
            'id' => 'monetbil_settings',
            'name' => '<strong>' . __('Monetbil Settings', Monetbil_Edd_Gateway::GATEWAY) . '</strong> | <a href="https://www.monetbil.com/services" target="_blank">' . __('Dashboard', Monetbil_Edd_Gateway::GATEWAY) . '</a>',
            'desc' => __('Configure the gateway settings', Monetbil_Edd_Gateway::GATEWAY),
            'type' => 'header'
        );

        $monetbil_settings[] = array(
            'id' => Monetbil_Edd_Gateway::MONETBIL_SERVICE_KEY,
            'name' => __('Service key', Monetbil_Edd_Gateway::GATEWAY),
            'desc' => __('This is the service key provided by Monetbil when you created a service.', Monetbil_Edd_Gateway::GATEWAY),
            'tooltip_title' => __('Service key', Monetbil_Edd_Gateway::GATEWAY),
            'tooltip_desc' => __('This is the service key provided by Monetbil when you created a service.', Monetbil_Edd_Gateway::GATEWAY),
            'type' => 'text',
            'size' => 'regular'
        );

        $monetbil_settings[] = array(
            'id' => Monetbil_Edd_Gateway::MONETBIL_SERVICE_SECRET,
            'name' => __('Service secret', Monetbil_Edd_Gateway::GATEWAY),
            'tooltip_title' => __('Service secret', Monetbil_Edd_Gateway::GATEWAY),
            'tooltip_desc' => __('This is the service secret Monetbil generated when creating a service.', Monetbil_Edd_Gateway::GATEWAY),
            'desc' => __('This is the service secret Monetbil generated when creating a service.', Monetbil_Edd_Gateway::GATEWAY),
            'type' => 'text',
            'size' => 'regular'
        );

        $monetbil_settings[] = array(
            'id' => Monetbil_Edd_Gateway::WIDGET_VERSION,
            'name' => __('Widget version', Monetbil_Edd_Gateway::GATEWAY),
            'desc' => __('Widget version', Monetbil_Edd_Gateway::GATEWAY),
            'type' => 'select',
            'options' => array(
                Monetbil_Edd_Gateway::WIDGET_VERSION_V2 => __('Version 2 (Responsive)', Monetbil_Edd_Gateway::GATEWAY),
                Monetbil_Edd_Gateway::WIDGET_VERSION_V1 => __('Version 1 (Not responsive)', Monetbil_Edd_Gateway::GATEWAY)
            ),
            'tooltip_title' => __('Widget version', Monetbil_Edd_Gateway::GATEWAY),
            'tooltip_desc' => __('', Monetbil_Edd_Gateway::GATEWAY),
            'chosen' => true
        );

        $monetbil_settings[] = array(
            'id' => Monetbil_Edd_Gateway::MONETBIL_PAYMENT_REDIRECTION,
            'name' => __('Payment redirection', Monetbil_Edd_Gateway::GATEWAY),
            'desc' => __('Payment redirection', Monetbil_Edd_Gateway::GATEWAY),
            'type' => 'select',
            'options' => array(
                Monetbil_Edd_Gateway::MONETBIL_PAYMENT_REDIRECTION_NO => __('NO', Monetbil_Edd_Gateway::GATEWAY),
                Monetbil_Edd_Gateway:: MONETBIL_PAYMENT_REDIRECTION_YES => __('YES', Monetbil_Edd_Gateway::GATEWAY)
            ),
            'tooltip_title' => __('Payment redirection', Monetbil_Edd_Gateway::GATEWAY),
            'tooltip_desc' => __('', Monetbil_Edd_Gateway::GATEWAY),
            'chosen' => true
        );

        $gateway_settings[Monetbil_Edd_Gateway::GATEWAY] = apply_filters('edd_monetbil_settings', $monetbil_settings);

        return $gateway_settings;
    }

    /**
     * Processing the Payment
     */
    function monetbil_process_payment($purchase_data)
    {
        // Collect payment data
        $payment_data = array(
            'price' => $purchase_data['price'],
            'date' => $purchase_data['date'],
            'user_email' => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency' => edd_get_currency(),
            'downloads' => $purchase_data['downloads'],
            'cart_details' => $purchase_data['cart_details'],
            'user_info' => $purchase_data['user_info'],
            'gateway' => Monetbil_Edd_Gateway::GATEWAY,
            'status' => 'pending'
        );

        // Record the pending payment
        $payment_id = edd_insert_payment($payment_data);

        // Check payment
        if (!$payment_id) {
            // Record the error
            edd_record_gateway_error(__('Payment Error', Monetbil_Edd_Gateway::GATEWAY), sprintf(__('Payment creation failed before sending buyer to Monetbil. Payment data: %s', Monetbil_Edd_Gateway::GATEWAY), json_encode($payment_data)), $payment_id);
            // Problems? send back
            edd_send_back_to_checkout('?payment-mode=monetbil');
        }

        // Get the payment url
        if (Monetbil_Edd_Gateway::MONETBIL_PAYMENT_REDIRECTION_NO == Monetbil_Edd_Gateway::getWidgetRedirection()) {

            $payment_url = add_query_arg(array(
                'payment_id' => $payment_id,
                'payment_key' => $purchase_data['purchase_key']
                    ), Monetbil_Edd_Gateway::getServerUrl() . Monetbil_Edd_Gateway::EDD_PAYMENT_URI);
        } else {
            $payment_url = Monetbil_Edd_Gateway::getMonetbilPaymentUrl($payment_id);
        }

        // Redirect
        wp_redirect($payment_url);
        exit;
    }

    /**
     * Adding Currency Support
     * 
     * @param array $currencies
     * @return array
     */
    function monetbil_edd_currencies($currencies)
    {
        $currencies['XAF'] = __('FCFA', Monetbil_Edd_Gateway::GATEWAY);
        return $currencies;
    }

    add_action('edd_gateway_monetbil', 'monetbil_process_payment');
    add_action('edd_monetbil_cc_form', 'pw_edd_monetbil_cc_form');
    add_action('parse_request', 'monetbil_edd_payment');
    add_action('parse_request', 'monetbil_edd_return');
    add_action('parse_request', 'monetbil_edd_notify');
    add_action('wp_enqueue_scripts', 'monetbil_edd_register_scripts');

    add_filter('edd_currencies', 'monetbil_edd_currencies');
    add_filter('edd_payment_gateways', 'monetbil_edd_register_gateway');
    add_filter('edd_settings_sections_gateways', 'edd_register_monetbil_section', 1, 1);
    add_filter('edd_settings_gateways', 'edd_register_monetbil_gateway_settings', 1, 1);
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'monetbil_edd_actions_links', 10, 1);
    add_filter('edd_checkout_button_purchase', 'edd_custom_edd_checkout_button_purchase');
    add_filter('edd_user_can_view_receipt', 'monetbil_user_can_view_receipt', 1, 2);
}

/**
 * Monetbil Edd Gateway class
 */
class Monetbil_Edd_Gateway
{

    const GATEWAY = 'monetbil';
    const WIDGET_URL = 'https://www.monetbil.com/widget/';
    const CHECK_PAYMENT_URL = 'https://api.monetbil.com/payment/v1/checkPayment';
    // EDD
    const EDD_PAYMENT_URI = '/monetbil/edd/payment';
    const EDD_RETURN_URI = '/monetbil/edd/return';
    const EDD_NOTIFY_URI = '/monetbil/edd/notify';
    // Monetbil Service
    const MONETBIL_SERVICE_KEY = 'MONETBIL_SERVICE_KEY';
    const MONETBIL_SERVICE_SECRET = 'MONETBIL_SERVICE_SECRET';
    // Monetbil Payment redirection
    const MONETBIL_PAYMENT_REDIRECTION_DEFAULT = 'no';
    const MONETBIL_PAYMENT_REDIRECTION_YES = 'yes';
    const MONETBIL_PAYMENT_REDIRECTION_NO = 'no';
    const MONETBIL_PAYMENT_REDIRECTION = 'MONETBIL_PAYMENT_REDIRECTION';
    // Monetbil Widget version
    const WIDGET_DEFAULT_VERSION = 'v2';
    const WIDGET_VERSION_V1 = 'v1';
    const WIDGET_VERSION_V2 = 'v2.1';
    const WIDGET_VERSION = 'WIDGET_VERSION';
    // Live mode
    const STATUS_SUCCESS = 1;
    const STATUS_FAILED = 0;
    const STATUS_CANCELLED = -1;
    // Test mode
    const STATUS_SUCCESS_TESTMODE = 7;
    const STATUS_FAILED_TESTMODE = 8;
    const STATUS_CANCELLED_TESTMODE = 9;

    public static function getMonetbilPaymentUrl($payment_id)
    {
        $payment_url = null;
        $payment_id = absint($payment_id);

        if (!$payment_id) {
            return $payment_url;
        }

        $payment = edd_get_payment($payment_id);

        if (!$payment) {
            return $payment_url;
        }

        $purchase_data = $payment->array_convert();

        // Get the return url
        $return_url = Monetbil_Edd_Gateway::getServerUrl() . Monetbil_Edd_Gateway::EDD_RETURN_URI;

        // Get the notify url
        $notify_url = Monetbil_Edd_Gateway::getServerUrl() . Monetbil_Edd_Gateway:: EDD_NOTIFY_URI;

        // Setup Monetbil arguments
        $monetbil_args = array(
            'amount' => $purchase_data['total'],
            'phone' => '',
            'locale' => get_locale(), // Display language fr or en
            'country' => 'CM',
            'currency' => edd_get_currency(),
            'item_ref' => $payment_id,
            'payment_ref' => $purchase_data['key'],
            'user' => $purchase_data['user_id'],
            'first_name' => $purchase_data['user_info']['first_name'],
            'last_name' => $purchase_data['user_info']['last_name'],
            'email' => $purchase_data['email'],
            'return_url' => $return_url,
            'notify_url' => $notify_url
        );

        $service_secret = Monetbil_Edd_Gateway::getServiceSecret();
        $version = Monetbil_Edd_Gateway::getWidgetVersion();

//        $monetbil_args['sign'] = Monetbil_Edd_Gateway::sign($service_secret, $monetbil_args);

        if (Monetbil_Edd_Gateway::WIDGET_VERSION_V2 == $version) {

            $response = wp_remote_post(Monetbil_Edd_Gateway::getWidgetUrl(), array(
                'body' => $monetbil_args
            ));

            $body = wp_remote_retrieve_body($response);

            $result = json_decode($body, true);

            if (is_array($result) and array_key_exists('payment_url', $result)) {
                $payment_url = $result['payment_url'];
            }
        }

        if (!$payment_url) {
            $version = Monetbil_Edd_Gateway::WIDGET_VERSION_V1;
            $payment_url = Monetbil_Edd_Gateway::getWidgetV1Url($monetbil_args);
        }

        return $payment_url;
    }

    /**
     * @return string
     */
    public static function sign($service_secret, $params)
    {
        ksort($params);
        $signature = md5($service_secret . implode('', $params));
        return $signature;
    }

    /**
     * @return boolean
     */
    public static function checkSign($service_secret, $params)
    {
        if (!array_key_exists('sign', $params)) {
            return false;
        }

        $sign = $params['sign'];
        unset($params['sign']);

        $signature = Monetbil_Edd_Gateway::sign($service_secret, $params);

        return ($sign == $signature);
    }

    /**
     * checkServer
     *
     * @return boolean
     */
    public static function checkServer()
    {
        return in_array($_SERVER['REMOTE_ADDR'], array(
            '184.154.224.14',
            '184.154.229.42'
        ));
    }

    /**
     * @return array ($payment_status, $testmode)
     */
    public static function checkPayment($paymentId)
    {
        $postData = array(
            'paymentId' => $paymentId
        );

        $response = wp_remote_post(Monetbil_Edd_Gateway::CHECK_PAYMENT_URL, array(
            'body' => $postData
        ));

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        $payment_status = 0;
        $testmode = 0;
        if (is_array($result) and array_key_exists('transaction', $result)) {
            $transaction = $result['transaction'];

            $payment_status = $transaction['status'];
            $testmode = $transaction['testmode'];
        }

        return array($payment_status, $testmode);
    }

    /**
     * @return string | null
     */
    public static function getPost($key = null, $default = null)
    {
        return $key == null ? $_POST : (isset($_POST[$key]) ? $_POST[$key] : $default);
    }

    /**
     * @return string | null
     */
    public static function getQuery($key = null, $default = null)
    {
        return $key == null ? $_GET : (isset($_GET[$key]) ? $_GET[$key] : $default);
    }

    /**
     * @return array
     */
    public static function getQueryParams()
    {
        $queryParams = array();
        $parts = explode('?', Monetbil_Edd_Gateway::getUrl());

        if (isset($parts[1])) {
            parse_str($parts[1], $queryParams);
        }

        return $queryParams;
    }

    /**
     * @return string | null
     */
    public static function getServerUrl()
    {
        return get_site_url();
    }

    /**
     * @return string | null
     */
    public static function getUrl()
    {
        $url = Monetbil_Edd_Gateway::getServerUrl() . Monetbil_Edd_Gateway::getUri();
        return $url;
    }

    /**
     * @return string | null
     */
    public static function getUri()
    {
        $requestUri = $_SERVER['REQUEST_URI'];
        $uri = '/' . ltrim($requestUri, '/');

        return $uri;
    }

    /**
     * @return string
     */
    public static function getServiceKey()
    {
        $service_key = edd_get_option(Monetbil_Edd_Gateway::MONETBIL_SERVICE_KEY, '');
        return $service_key;
    }

    /**
     * @return string
     */
    public static function getServiceSecret()
    {
        $service_secret = edd_get_option(Monetbil_Edd_Gateway::MONETBIL_SERVICE_SECRET, '');
        return $service_secret;
    }

    /**
     * @return string
     */
    public static function getWidgetVersion()
    {
        $version = edd_get_option(Monetbil_Edd_Gateway::WIDGET_VERSION, Monetbil_Edd_Gateway::WIDGET_DEFAULT_VERSION);
        return $version;
    }

    /**
     * @return string
     */
    public static function getWidgetRedirection()
    {
        $redirection = edd_get_option(Monetbil_Edd_Gateway::MONETBIL_PAYMENT_REDIRECTION, Monetbil_Edd_Gateway::WIDGET_DEFAULT_VERSION);
        return $redirection;
    }

    /**
     * @return string
     */
    public static function getWidgetUrl()
    {
        $version = Monetbil_Edd_Gateway::getWidgetVersion();
        $service_key = Monetbil_Edd_Gateway::getServiceKey();
        $widget_url = Monetbil_Edd_Gateway::WIDGET_URL . $version . '/' . $service_key;
        return $widget_url;
    }

    /**
     * @return string
     */
    public static function getWidgetV1Url($monetbil_args)
    {
        $monetbil_v1_redirect = Monetbil_Edd_Gateway::getWidgetUrl() . '?' . http_build_query($monetbil_args, '', '&');
        return $monetbil_v1_redirect;
    }

    /**
     * @return boolean
     */
    public static function isMonetbilEDDPaymentPage()
    {
        $uri = Monetbil_Edd_Gateway::getUri();

        if (false === stripos($uri, Monetbil_Edd_Gateway::EDD_PAYMENT_URI)) {
            return false;
        }

        return true;
    }

    /**
     * @return boolean
     */
    public static function isMonetbilEDDReturnPage()
    {
        $uri = Monetbil_Edd_Gateway::getUri();

        if (false === stripos($uri, Monetbil_Edd_Gateway::EDD_RETURN_URI)) {
            return false;
        }

        return true;
    }

    /**
     * @return boolean
     */
    public static function isMonetbilEDDNotify()
    {
        $uri = Monetbil_Edd_Gateway::getUri();

        if (false === stripos($uri, Monetbil_Edd_Gateway::EDD_NOTIFY_URI)) {
            return false;
        }

        return true;
    }

    /**
     * @return string
     */
    public static function generateNotice($configured)
    {
        if ($configured) {
            return '<p class="alert alert-info">
                    <span class="dashicons dashicons-yes"></span>' . __("Service perfectly configured", Monetbil_Edd_Gateway::GATEWAY) .
                    '</p>';
        } else {
            return '<p class="alert alert-error">
                    <span class="dashicons dashicons-no"></span>' . __("Service not configured", Monetbil_Edd_Gateway::GATEWAY) .
                    '</p>';
        }
    }

}
