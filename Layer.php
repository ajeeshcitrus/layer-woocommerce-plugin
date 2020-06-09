<?php

/*
 * Plugin Name: Layer Payment Gateway
 * Plugin URI: https://open.money/
 * Description: Open's Layer Payment Gateway integration for WooCommerce
 * Version: 1.0.3
 * Author: Openers
 * Author URI: https://open.money/
*/


require_once 'layer_api.php';
require_once 'PaymentEntity.php';

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

//hook as WooComerce payment gateway
add_filter( 'woocommerce_payment_gateways', 'layer_add_gateway_class' );
function layer_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_Layer_Gateway';
    return $gateways;
}

//init layer's gateway class
add_action( 'plugins_loaded', 'layer_init_gateway_class' );


register_activation_hook( __FILE__, 'layer_activation_check' );


function layer_activation_check(){

    try{

        $php_version = phpversion();
        global $wp_version;
        $wc_plugin_details = [];

        if($php_version < (int)7){

            throw new Exception("Layer Payments only supports PHP 7+");
        }

        if ( !class_exists( 'woocommerce' ) ){

            throw new Exception("Layer Payments requires WooCommerce to be installed and activated");

        } else {

            if(defined('WC_PLUGIN_FILE')){

                $wc_plugin_details = get_plugin_data(WC_PLUGIN_FILE);

            } else {

                $wc_plugin_details = get_plugin_data(WP_PLUGIN_DIR.'/woocommerce/woocommerc.php');

            }

            if(isset($wc_plugin_details['Version']) && !empty($wc_plugin_details['Version'])){

                if($wc_plugin_details['Version'] < (int)4.0){

                    throw new Exception("Layer Payments only supports Woocommerce 4.2+");

                }
            } else {

                throw new Exception("Unable to determine Woocommerce version");

            }
        }

        if($wp_version < (int)5){

            throw new Exception("Layer Payments only supports Wordpress 5+");
        }

    }catch (Exception $exception){

        die($exception->getMessage());
    }

    return false;
}


function layer_init_gateway_class() {


    class WC_Layer_Gateway extends WC_Payment_Gateway {


        public function __construct() {


            $this->payment = new PaymentEntity();

            $this->id = 'layer';
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = 'Layer Payment Gateway';
            $this->method_description = 'Layer Payment Gateway from Open';

            $this->supports = array(
                'products'
            );

            $this->init_form_fields();

            $this->init_settings();
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description');
            $this->enabled = $this->get_option( 'enabled' );
            $this->sandbox = 'yes' === $this->get_option( 'sandbox' );

            if($this->sandbox == "yes"){

                $this->access_key = $this->get_option( 'sandbox_api_key');
                $this->secret_key = $this->get_option( 'sandbox_api_secret' );

            } else {

                $this->access_key = $this->get_option( 'api_key');
                $this->secret_key = $this->get_option( 'secret_key' );
            }

            add_action('woocommerce_api_layer_callback', array($this, 'process_layer_response'));
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action('woocommerce_receipt_' . $this->id, array($this, 'process_payment_view'));
            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        }


        public function init_form_fields(){


            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Layer',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'Layer payment gateway',
                    'default'     => 'Layer (CC/DC/NB/UPI)',
                ),
                /*'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'Description in checkout',
                    'default'     => 'Pay with our super cool gateway',
                ),*/
                'sandbox' => array(
                    'title'       => 'Sandbox',
                    'label'       => 'Enable Sandbox Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'sandbox_api_key' => array(
                    'title'       => 'Sandbox API Key',
                    'type'        => 'text'
                ),
                'sandbox_api_secret' => array(
                    'title'       => 'Sandbox Secret Key',
                    'type'        => 'password',
                ),
                'api_key' => array(
                    'title'       => 'Live API Key',
                    'type'        => 'text'
                ),
                'secret_key' => array(
                    'title'       => 'Live Secret Key',
                    'type'        => 'password'
                )
            );
        }

        public function payment_fields() {


        }

        public function payment_scripts() {


            if( $this->settings['sandbox'] != "yes"){

                wp_enqueue_script( 'layer_js', 'https://payments.open.money/layer',"","",false);

            } else {

                wp_enqueue_script( 'layer_js', 'https://sandbox-payments.open.money/layer',"","",false);

            }

            wp_register_script( 'layer_checkout_js', plugin_dir_url(__FILE__)."layer_checkout.js" );
            wp_enqueue_script( 'layer_checkout_js',"","","",true);

        }


        public function validate_fields(){

           return true;

        }


        public function process_payment( $order_id ){

            global $woocommerce;

            $order = wc_get_order( $order_id );

            $env = $this->settings['sandbox'];
            if($env != "yes"){  $env = "live"; }

            if(empty($this->access_key)){
                wc_add_notice(  'Plugin error. Access Key is empty.',"error");
                return;
            }

            if(empty($this->secret_key)){
                wc_add_notice(  'Plugin error. Secret Key is empty',"error");
                return;
            }

            $layer_api = new LayerApi($env,$this->access_key,$this->secret_key);

            $contact_number = (int)$order->get_billing_phone();
            $email_id = $order->get_billing_email();

            if(strlen($contact_number) > 10){

                $contact_number = NULL;
                $email_id = NULL;
            }

            $layer_payment_token_data = $layer_api->create_payment_token([
                'amount' => $order->get_total(),
                'currency' => 'INR',
                'name'  => $order->get_formatted_billing_full_name(),
                'email_id' => $email_id,
                'contact_number' => (string)$contact_number,
                'udf'   => [
                    'woocommerce_order_id'  => $order->get_id(),
                    'woocommerce_order_key'  => $order->get_order_key(),
                    'woocommerce_order_created_at'  => $order->get_date_created()->date("Y-m-d H:i:s"),
                ]
            ]);

            if(isset($layer_payment_token_data['error'])){
                wc_add_notice(  'E55 Payment error. ' . $layer_payment_token_data['error'],'error' );
                return NULL;
            }

            if(!isset($layer_payment_token_data["id"]) || empty($layer_payment_token_data["id"])){

                wc_add_notice(  'Payment error. ' . 'Layer token ID cannot be empty','error' );
                return NULL;
            }

            $woocommerce->session->set("layer_payment_token_id",$layer_payment_token_data["id"]);

            $args = [
                'key' => $order->get_order_key(),
                'layer_payment_token_id' => $layer_payment_token_data["id"]
            ];

            return array(
                'result' => 'success',
                'redirect' => add_query_arg($args, $order->get_checkout_payment_url(true))
            );

        }

        public function process_payment_view($order_id){

            $is_retry = 0;

            if(isset($_GET['retry']) && $_GET['retry'] == "1"){

                wc_print_notice("Payment Failed! Retry your payment by clicking on Pay Now","error");
                $is_retry = 1;
            }

            global $woocommerce;
            $order = wc_get_order( $order_id );
            $layer_payment_token_id = NULL;


            $env = $this->settings['sandbox'];
            if($env != "yes"){  $env = "live"; }
            $layer_api = new LayerApi($env,$this->access_key,$this->secret_key);

            if(empty($woocommerce->session->get("layer_payment_token_id"))){

                $layer_payment_token_id = $woocommerce->session->get("layer_payment_token_id");

            } else {

                $layer_payment_token_id = $_GET['layer_payment_token_id'];
            }

            $layer_payment_token_id = $woocommerce->session->get("layer_payment_token_id");

            if(empty($layer_payment_token_id)){

                wc_print_notice("Invalid session. Unable to proceed. E66", "error");
                return NULL;
            }

            try{

                $payment_token_data = $layer_api->get_payment_token($woocommerce->session->get("layer_payment_token_id"));


                if(!empty($payment_token_data)){

                    if(isset($layer_payment_token_data['error'])){
                        wc_add_notice(  'E56 Payment error. ' . $payment_token_data['error'],'error' );
                        return;
                    }

                    if($payment_token_data['status'] == "paid"){

                        throw new Exception("Layer: this order has already been paid");

                    }

                    if($payment_token_data['amount'] != $order->calculate_totals()){

                        throw new Exception("Layer: an amount mismatch occurred");

                    }

                    $jsdata = array(
                        'payment_token_id' => $payment_token_data['id'],
                        'accesskey' => $this->access_key,
                        'retry' => $is_retry,
                    );


                    wp_localize_script('layer_checkout_js','layer_params',$jsdata);


                    $hash = $this->create_hash(array(
                        'layer_pay_token_id'    => $payment_token_data['id'],
                        'layer_order_amount'    => $payment_token_data['amount'],
                        'woo_order_id'    => $order_id,
                    ));

                    $args = [
                        'key' => $order->get_order_key(),
                        'layer_payment_token_id' => $layer_payment_token_id,
                        'retry' => true
                    ];

                    echo " <form action='".get_site_url() . '/?wc-api=layer_callback' ."' method='post' style='display: none' name='layer_payment_int_form'>
                                <input type='hidden' name='layer_pay_token_id' value='".$payment_token_data['id']."'>
                                <input type='hidden' name='woo_order_id' value='".$order_id."'>
                                <input type='hidden' name='layer_order_amount' value='".$payment_token_data['amount']."'>
                                <input type='hidden' id='layer_payment_id' name='layer_payment_id' value=''>
                                <input type='hidden' id='fallback_url' name='fallback_url' value='".add_query_arg($args, $order->get_checkout_payment_url(true))."'>
                                <input type='hidden' name='hash' value='".$hash."'>
                            </form>";

                    echo "<button class='float-right' id='LayerPayNow'>Pay Now</button>";


                }

            } catch (Throwable $exception){

                wc_print_notice($exception->getMessage() ." E12","error");

            }
        }


        public function process_layer_response(){

            global $woocommerce;

            $fallback_url = $_POST['fallback_url'];


            if(!isset($_POST['layer_payment_id']) || empty($_POST['layer_payment_id'])){

                wp_redirect($fallback_url);
                return NULL ;
            }

            try {

                $data = array(
                    'layer_pay_token_id'    => $_POST['layer_pay_token_id'],
                    'layer_order_amount'    => $_POST['layer_order_amount'],
                    'woo_order_id'    => $_POST['woo_order_id'],
                );

                if($this->verify_hash($data,$_POST['hash'])){


                    $order = wc_get_order( $data['woo_order_id'] );

                    if(!empty($order)){

                        $env = $this->settings['sandbox'];
                        if($env != "yes"){  $env = "live"; }
                        $layer_api = new LayerApi($env,$this->access_key,$this->secret_key);
                        $payment_data = $layer_api->get_payment_details($_POST['layer_payment_id']);


                        if(isset($payment_data['error'])){

                            $order->add_order_note( json_encode([
                                'payment_data' => $payment_data,
                                'data' => $data
                            ]));

                            throw new Exception("Layer: an error occurred E14");

                        }

                        if(isset($payment_data['id']) && !empty($payment_data)){


                            if($payment_data['payment_token']['id'] != $data['layer_pay_token_id']){

                                $order->add_order_note( json_encode([
                                    'payment_data' => $payment_data,
                                    'data' => $data,
                                ]));

                                throw new Exception("Layer: received layer_pay_token_id and collected layer_pay_token_id doesnt match");

                            }


                            if($data['layer_order_amount'] != $payment_data['amount'] || $order->get_total() !=$payment_data['amount'] ){


                                $order->add_order_note( json_encode([
                                    'payment_data' => $payment_data,
                                    'data' => $data,
                                    'order_total' => $order->get_total()
                                ]));

                                throw new Exception("Layer: received amount and collected amount doesnt match");

                            }

                            $order->add_order_note( "Payment Data ". json_encode($payment_data));


                            switch ($payment_data['status']){


                                case $this->payment::status_captured:
                                case $this->payment::status_authorized:

                                    $order->payment_complete();
                                    $order->add_order_note( "Payment ID ". $payment_data['id']);
                                    $woocommerce->cart->empty_cart();
                                    break;

                                case $this->payment::status_failed:
                                case $this->payment::status_cancelled:

                                    $order->add_order_note( "Payment ID ". $payment_data['id'] ." Failed");
                                    wp_redirect($fallback_url);
                                    exit;

                                break;

                                case $this->payment::status_late_authorized:
                                case $this->payment::status_pending:
                                default:

                                    $order->add_order_note( "Payment ID ". $payment_data['id'] ." is Pending");
                                    wp_redirect($fallback_url);
                                    exit;

                                break;

                            }



                        } else {

                            $order->add_order_note( json_encode([
                                'payment_data' => $payment_data,
                                'data' => $data
                            ]));

                            throw new Exception("invalid payment data received E98");
                        }

                    } else {

                        throw new Exception("unable to create order object");

                    }

                } else {

                    throw new Exception("hash validation failed");
                }

            } catch (Throwable $exception){

                $order->add_order_note( "Layer: an error occurred " . $exception->getMessage());
                wc_add_notice(  'Please try again. E26', 'error' );
                wp_redirect($fallback_url);


            }

            wp_redirect($order->get_checkout_order_received_url());

        }


        function create_hash($data){

            ksort($data);

            $hash_string = $this->access_key;

            foreach ($data as $key=>$value){

                $hash_string .= '|'.$value;
            }

            return hash_hmac("sha256",$hash_string,$this->secret_key);
        }

        function verify_hash($data,$rec_hash){

            $gen_hash = $this->create_hash($data);

            if($gen_hash === $rec_hash){

                return true;
            }

            return false;

        }

    }



}
