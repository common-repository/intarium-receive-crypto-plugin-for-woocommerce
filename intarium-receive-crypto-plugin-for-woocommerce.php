<?php
/**
 * Plugin Name: Intarium Receive Crypto Plugin for WooCommerce
 * Plugin URI: https://intarium.ch
 * Description: Accept CHF, EUR or USD payments from customers and receive Bitcoin or DASH for WooCommerce.
 * Author: Intarium
 * Author URI: https://intarium.ch/
 * Version: 0.9.1
 * Text Domain: intarium-receive-crypto-plugin-for-woocommerce
 *
 * Copyright: (c) 2019 Intarium (support@intarium.ch) and WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   intarium-receive-crypto-plugin-for-woocommerce
 * @author    Intarium
 * @category  Admin
 * @copyright Copyright (c) 2019, Intarium and WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * This offline gateway forks the WooCommerce core "Cheque" payment gateway to create a fiat to bitcoin conversion payment plugin.
 */

// disallow direct access
defined('ABSPATH') or exit;

define('INTARIUM_API_URL', 'https://intarium.ch/api/v1');

// Make sure WooCommerce is active
if (! in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	return;
}

//updates order status of pending orders by connecting to the intarium api
add_action('intarium_hourly_event', 'intarium_do_this_hourly');
function intarium_do_this_hourly() {
    $intariumPaymentObj = new WC_Gateway_Intarium;
    $intariumPaymentObj->do_this_hourly();
}

register_activation_hook(__FILE__, 'intariumActivation');
add_action('wp', 'intariumActivation');
function intariumActivation() {
    if (!wp_next_scheduled('intarium_hourly_event')) {
		wp_schedule_event(time(), 'hourly', 'intarium_hourly_event');
    }
}

register_deactivation_hook(__FILE__, 'intariumDeactivation');
function intariumDeactivation() {
	wp_clear_scheduled_hook('intarium_hourly_event');
}

/**
 * Add the gateway to WC Available Gateways
 * 
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + fiat to bitcoin intarium api gateway
 */
function wc_intarium_add_to_gateways($gateways) {
	$gateways[] = 'WC_Gateway_Intarium';
	return $gateways;
}
add_filter('woocommerce_payment_gateways', 'wc_intarium_add_to_gateways');

/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */ 
function wc_intarium_gateway_plugin_links($links) {
	$plugin_links = array(
		'<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=intarium_gateway') . '">' . __('Configure', 'wc-gateway-intarium') . '</a>'
	);

	return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_intarium_gateway_plugin_links');

/**
 * Intarium
 *
 * Intarium Bitcoin payment gateway that allows you to accept fiat and converts directly to bitcoins.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_Intarium
 * @extends		WC_Payment_Gateway
 * @version		1.0.0
 * @package		WooCommerce/Classes/Payment
 * @author 		Intarium
 */
add_action('plugins_loaded', 'wc_intarium_gateway_init', 11);

function wc_intarium_gateway_init() {
	class WC_Gateway_Intarium extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			global $wp_session;
	  
			$this->id                 = 'intarium_gateway';
			$this->icon               = apply_filters('woocommerce_intarium_icon', '');
			$this->has_fields         = false;
			$this->method_title       = __('Intarium Receive Crypto', 'wc-gateway-intarium');
			$this->method_description = __('Accept EUR, USD or CHF payments from customers and receive Bitcoin or DASH. Orders are marked as "payment-pending" when received and "processing" when payment confirmed.', 'wc-gateway-intarium');
		  
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
		  
			// Define user set variables
            $this->api_token    = $this->get_option('api_token');
			$this->title        = $this->get_option('title');
			$this->description  = $this->get_option('description');
			$this->instructions = $this->get_option('instructions', $this->description);
		 
			// Actions
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
		  
		    // Customer Emails
			add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
			$this->form_fields = apply_filters('wc_intarium_form_fields', array(
				'enabled' => array(
					'title'   => __('Enable/Disable', 'wc-gateway-intarium'),
					'type'    => 'checkbox',
					'label'   => __('Enable payments with Intarium', 'wc-gateway-intarium'),
					'default' => 'no'
				),
                'api_token' => array(
                    'title'       => __('API token', 'wc-gateway-intarium'),
                    'type'        => 'text',
                    'custom_attributes' => array('required' => true),
                    'description' => __('Get your free API Token on intarium.ch after registration.', 'wc-gateway-intarium'),
                    'default'     => __('', 'wc-gateway-intarium'),
                    'desc_tip'    => true,
                ),
				'title' => array(
					'title'       => __('Title', 'wc-gateway-intarium'),
					'type'        => 'text',
					'description' => __('This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-intarium'),
					'default'     => __('Bank Payment', 'wc-gateway-intarium'),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __('Description', 'wc-gateway-intarium'),
					'type'        => 'textarea',
					'description' => __('Payment method description that the customer will see on your checkout.', 'wc-gateway-intarium'),
					'default'     => __('Please remit payment upon pickup or delivery.', 'wc-gateway-intarium'),
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __('Instructions', 'wc-gateway-intarium'),
					'type'        => 'textarea',
					'description' => __('Instructions that will be added to the thank you page and emails.', 'wc-gateway-intarium'),
					'default'     => '',
					'desc_tip'    => true,
				),
			));
		}

		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ($this->instructions) {
				print_r(WC()->session->get('intariumData'));
			}
		}
		
		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions($order, $sent_to_admin, $plain_text = false) {
			$orderData = $order->get_data();
			if ($this->instructions && ! $sent_to_admin && $this->id === $orderData['payment_method'] && $order->has_status('payment-pending')) {
				echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
			}
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $orderData = $order->get_data();
            $data = [
                'client' => [
                    'first_name' => $orderData['billing']['first_name'],
                    'last_name' => $orderData['billing']['last_name'],
                    'company_name' => $orderData['billing']['company'],
                    'street' => $orderData['billing']['address_1'],
                    'strNo' => $orderData['billing']['address_2'],
                    'strCo' => null,
                    'zip' => $orderData['billing']['postcode'],
                    'city' => $orderData['billing']['city'],
                    'country' => $orderData['billing']['country'],
                    'email' => $orderData['billing']['email'],
                ],
                'invoice' => [
                    'invoice_number' => $orderData['id'],
                    'po_number' => null,
                    'invoice_date' => date("Y-m-d H:i:s"),
                    'currency' => $orderData['currency'],
                    'subtotal' => $orderData['total'] - $orderData['total_tax'],
                    'taxtotal' => $orderData['total_tax'],
                    'discount' => 0.0,
                    'items' => [
                        [
                            'key' => '1',
                            'description' => get_home_url().'- Woocommerce Order ID -'.$orderData['id'],
                            'cost' => $orderData['total'] - $orderData['total_tax'],
                            'qty' => 1,
                            'vat' => 0.0,
                        ],
                    ],
                ],
                'status' => 'marked_sent',
            ];
            $intariumApiData = json_encode($data);
            $url = INTARIUM_API_URL.'/invoices';

            $intariumApiResponse = null;
            $intariumApiResponse = $this->_wpRemoteRequest($url, $intariumApiData, $this->api_token);
            $intariumApiResponse = json_decode($intariumApiResponse['body']);
            if(empty($intariumApiResponse->data) || !$intariumApiResponse->data->id){$this->_fail($intariumApiResponse, $orderData);}

            // Mark as on-hold (as normal bank transfer payments)
            $order->update_status('on-hold', __('Awaiting intarium payment', 'wc-gateway-intarium'));
            update_post_meta($order_id , '_intarium_invoice_id', $intariumApiResponse->data->id);
            update_post_meta($order_id , '_intarium_payment_reference', $intariumApiResponse->data->pReference);
            // Reduce stock levels
            $order->reduce_order_stock();
            // Remove cart
            WC()->cart->empty_cart();
            //send new order and payment details email to customer
            $mailer = WC()->mailer();
            $recipient = $orderData['billing']['email'];
            $subject = get_bloginfo()." payment details for order #".$order_id;
            $content = '<div>Dear '.$orderData['billing']['first_name'].' '.$orderData['billing']['last_name'].',<br/>
                Thank you for your order at '.get_bloginfo().'.</div>
                <div>In order to complete the order please send the following bank transaction using the exact reference code so that we can track your payment:</div>
                <table class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
                <tr class="woocommerce-order-overview__order order"><td>Please send <strong>'.$orderData['total'].' '.$orderData['currency'].
                '</strong> to the following account:<td></tr>
                <tr><td>IBAN : <strong>'.$intariumApiResponse->data->pIban.'</strong><td><tr>
                <tr><td>BIC :  <strong>'.$intariumApiResponse->data->p_swift.'</strong><td></tr>
                <tr><td>Message/Reference : <strong>'.$intariumApiResponse->data->pReference.'</strong></td></tr></table>';
            $content .= $this->_get_custom_email_html($order, $subject, $mailer);
            $headers = "Content-Type: text/html\r\n";
            //send the email through wordpress
            $mailer->send($recipient, $subject, $content, $headers);
            $paymentDetailsBlock ='<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
                <li class="woocommerce-order-overview__order order"><p>Please send <strong>'.$orderData['total'].' '.$orderData['currency'].
                '</strong> to the following account:</p>
                <p>IBAN : <strong>'.$intariumApiResponse->data->iban.'</strong></p>
                <p>BIC :  <strong>'.$intariumApiResponse->data->p_swift.'</strong></p>
                <p>Message/Reference : <strong>'.$intariumApiResponse->data->pReference.'</strong></p>';
            // Return thankyou redirect
            WC()->session->set('intariumData', '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
                <li class="woocommerce-order-overview__order order"><p>Please send <strong>'.$orderData['total'].' '.$orderData['currency'].
                '</strong> to the following account:</p>
                <p>IBAN : <strong>'.$intariumApiResponse->data->pIban.'</strong></p>
                <p>BIC :  <strong>'.$intariumApiResponse->data->p_swift.'</strong></p>
                <p>Message/Reference : <strong>'.$intariumApiResponse->data->pReference.'</strong></p>');

            return array(
                'result' 	=> 'success',
                'redirect'	=> $this->get_return_url($order)
            );
	    }

        public function do_this_hourly() {

//            error_log(print_r($var, true), 3, "/home/vagrant/Code/dev.log");
            $customer_orders = wc_get_orders(array(
                'limit' => 10,
                'orderby' => 'date',
                'order' => 'ASC',
                'status' => 'wc-on-hold'
            ));

            if(empty($customer_orders)){return true;}

            foreach ($customer_orders as $customer_order) {
                $metaData = get_post_meta($customer_order->ID);
                if(!isset($metaData["_intarium_invoice_id"])){continue;}
                $invoiceId = $metaData["_intarium_invoice_id"][0];
                $url = INTARIUM_API_URL.'/invoices/'.$invoiceId;
                $intariumApiResponse = $this->_wpRemoteRequest($url, $intariumApiData = [], $this->api_token, 'GET');
                $intariumApiResponse = json_decode($intariumApiResponse['body']);
                if(empty($intariumApiResponse->data)){return true;}
                if($intariumApiResponse->data->originalPaymentStatus == 'paidIn') {
                    $customer_order->update_status('processing', __('Intarium paid in by customer', 'wc-gateway-intarium'));
                }
            }
        }

        protected function _get_custom_email_html($order, $heading = false, $mailer) {
            $template = 'emails/customer-invoice.php';
            return wc_get_template_html($template, array(
                'order'         => $order,
                'email_heading' => $heading,
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $mailer));
        }

        protected function _wpRemoteRequest($url, $bodyData, $token = null, $method = 'POST') {
            return wp_remote_request($url, array(
                'method' => $method,
                'timeout' => 45,
                'redirection' => 5,
                'headers' => array(
                        'Content-type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer '.$token
                    ),
                    'body' => $bodyData)
            );
        }

        protected function _fail($cornCallObj, $orderData, $automatedCall = false) {
            $to = 'support@intarium.ch';
            $subject = 'WC_Gateway_Intarium payment failed';
            $message = get_home_url().'---'.$cornCallObj->message.'--------'.$cornCallObj->url.'-------'.$orderData['currency'].'--'.$orderData['total'].
            '--'.$orderData['billing']['first_name'].$orderData['billing']['last_name'].'--'.$orderData['billing']['phone'].'--'.
            $orderData['billing']['address_1'].'--'.$orderData['billing']['address_2'].'--'.$orderData['billing']['city'].'--'.
            $orderData['billing']['state'].'--'.$orderData['billing']['postcode'].'--'.$orderData['billing']['country'].'--'.$orderData['total_tax'];
            wp_mail($to, $subject, $message);
            if(!$automatedCall) {
                throw new Exception(__('order processing failed, please try again later', 'woo'));
            }
        }

    } // end \WC_Gateway_Intarium class
}