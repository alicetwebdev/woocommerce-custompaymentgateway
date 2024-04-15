<?php
/**
 * Plugin Name: WooCommerce Custom Payment Gateway
 * Description: Custom Payment Gateway for WooCommerce
 * Version: 1.0.0
 * Author: Alice T
 */
 
 /**
  * If WooCommerce plugin is not available
  * 
  */
function wccustompaymentgateway_woocommerce_fallback_notice() {
	$msg = '<div class="error">';
	$msg .= '<p>' . __('WooCommerce Custom Payment Gateway depends on the last version of <a href="http://wordpress.org.extend/plugins/woocommerce/">WooCommerce</a> to work!', 'wccustompaymentgateway') . '</p>';
	$msg .= '</div>';
	echo $msg;
}

// Load the function
add_action('plugins_loaded', 'wccustompaymentgateway_gateway_load', 0);

/**
 * Load Custom Payment Gateway plugin function
 * 
 * @return mixed
 */
function wccustompaymentgateway_gateway_load() {
	if (!class_exists('WC_Payment_Gateway')) {
		add_action('admin_notices', 'wccustompaymentgateway_woocommerce_fallback_notice');
		return;
	}
	
	// Load language
	load_plugin_textdomain('wccustompaymentgateway', false, dirname(plugin_basename(__FILE__)). '/languages/');
	
	add_filter('woocommerce_payment_gateways', 'wccustompaymentgateway_add_gateway');
	
	/**
	 * Add Custom Payment Gateway to ensure WooCommerce can load it
	 * 
	 * @param array @methods
	 * @return array
	 */
	function wccustompaymentgateway_add_gateway($methods) {
		$methods[] = 'WC_Custom_Gateway';
		return $methods;
	}
	
	/**
	 * Define the Custom Payment Gateway
	 * 
	 */
	class WC_Custom_Gateway extends WC_Payment_Gateway {
		
		/**
		 * Construct the Custom Payment Gateway class
		 * 
		 * @global mixed $woocommerce
		 */
		public function __construct() {
			global $woocommerce;
			
			$this->id = 'custompaymentgateway';
			$this->icon = plugins_url('images/logo-custompaymentgateway.png', __FILE__);
			$this->has_fields = false;
			$this->method_title = __('Custom Payment Gateway', 'wccustompaymentgateway');
			$this->method_description = __('Proceed payment via Custom Payment Gateway', 'woocommerce');
			
			// Load the form fields
			$this->init_form_fields();
			
			// Load the settings
			$this->init_settings();
			
			// Define settings variables
			$this->title = __('Custom Payment Gateway', 'wccustompaymentgateway');
			$this->payment_title = __('ForYou Payment Gateway', 'wccustompaymentgateway');
			$this->description = __('Pay with Custom Payment Gateway<br/>- Credit Card / Debit Card<br/>- FPX', 'wccustompaymentgateway');
			
			$this->verify_key = 'xxxxxxxxxxxxxxxxxxxxxxxx'; // TO CHANGE ACCORDING TO ENVIRONMENT
			$this->secret_key = 'xxxxxxxxxxxxxxxxxxxxxxxx'; // TO CHANGE ACCORDING TO ENVIRONMENT
			$this->url = "https://YourWebsiteUrl/custom-payment-gateway/payment"; // TO CHANGE ACCORDING TO ENVIRONMENT
			
			// Actions
			add_action('valid_custompaymentgateway_request_returnurl', array(&$this, 'check_custompaymentgateway_response_returnurl'));
			add_action('woocommerce_receipt_custompaymentgateway', array(&$this, 'receipt_page'));
			
			// Save settings configuration
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			
			// Payment listener / API hook
			add_action('woocommerce_api_wc_custom_gateway', array($this, 'check_ipn_response'));
		}
		
		/**
		 * Checking if this gateway is enabled and available in the user's country
		 * 
		 * @return bool
		 */
		public function is_valid_for_use() {
			$currencies = array(
				'MYR',
			);
			
			if (!in_array(get_woocommerce_currency(), $currencies)) {
				return false;
			}
		}
		
		/*
		 * Admin panel options
		 * 
		 */
		public function admin_options() {
			?>
			<h3><?php _e('Custom Payment Gateway', 'wccustompaymentgateway'); ?></h3>
			<p><?php _e('Custom Payment Gateway works by sending the user to Custom Payment Gateway to enter their payment information.', 'wccustompaymentgateway'); ?></p>
			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table><!--/.form-table-->
			<?php
		}
		
		/**
		 * Gateway settings form fields
		 * 
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'wccustompaymentgateway'),
					'type' => 'checkbox',
					'label' => __('Enable Custom Payment Gateway', 'wccustompaymentgateway'),
					'default' => 'yes'
				),
			);
		}
		
		/**
		 * Generate the form
		 * 
		 * @param mixed @order_id
		 * @return string
		 */
		public function generate_form($order_id) {
			$order = new WC_Order($order_id);
			$pay_url = $this->url;
			$amount = $order->get_total() * 100; // Amount send to payment gateway without decimal
			$order_number = $order->get_order_number();
			$secretKey = $this->secret_key;
			$clientId = $this->verify_key;
			$user_id = $order->get_user_id();
			$customerName = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
			$email = $order->get_billing_email();
			$currency = strtolower(get_woocommerce_currency()); // 'myr'
			$transactionDateTime = date_format($order->get_date_created(), "Y-m-d H:i:s");
			$orderDescription = '';
			
			// Checksum
			$dataString = implode(',', array(
				$clientId,
				$user_id,
				$customerName,
				$email,
				$order_number,
				$amount,
				$currency,
				$transactionDateTime
			));
			$checksum = hash_hmac('sha256', $dataString, $secretKey);
			
			// Order description
			$item_names = array();
			if (sizeof($order->get_items()) > 0) {
				foreach ($order->get_items() as $item) {
					if ($item['qty']) {
						$item_names[] = $item['name'] . ' x ' . $item['qty'];
					}
				}
			}
			
			$orderDescription = sprintf(__('Order %s', 'woocommerce'), $order_number) . ' - ' . implode(', ', $item_names);
			
			// Params required to pass to payment gateway
			$custompaymentgateway_args = array(
				'clientId' => $clientId,
				'customerId' => $user_id,
				'customerName' => $customerName,
				'email' => $email,
				'orderNo' => $order_number,
				'amount' => $amount,
				'currency' => $currency,
				'transactionDateTime' => $transactionDateTime,
				'checksum' => $checksum,
				'description' => $orderDescription
			);
			
			$custompaymentgateway_args_array = array();
			
			foreach ($custompaymentgateway_args as $key => $value) {
				$custompaymentgateway_args_array[] = "<input type='hidden' name='" . $key . "' value='" . $value . "' />";
			}
			
			return "<form action='" . $pay_url . "' method='get' id='custompaymentgateway_payment_form' name='custompaymentgateway_payment_form'>" 
					. implode('', $custompaymentgateway_args_array)
					. "<input type='submit' class='button-alt' id='submit_custompaymentgateway_payment_form' value='" . __('Pay via Custom Payment Gateway', 'woothemes') . "' /> "
					. "<a class='button cancel' href='" . $order->get_cancel_order_url() . "'>" . __('Cancel order &amp; restore cart', 'woothemes') . "</a>"
					. "</form>";
		}
		
		/**
		 * Order error button
		 * 
		 * @param object $object order data
		 * @return string error message and cancel button
		 */
		protected function custompaymentgateway_order_error($order) {
			$html = '<p>' . __('An error has occurred while processing your payment, please try again. Or contact us for assistance.', 'wccustompaymentgateway') . '</p>';
			$html .= '<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Click to try again', 'wccustompaymentgateway') . '</a>';
			return $html;
		}
		
		/**
		 * Process the payment and return the result
		 * 
		 * @param int @order_id
		 * @return array
		 */
		public function process_payment($order_id) {
			$order = new WC_Order($order_id);
			return array(
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url(true)
			);
		}
		
		/**
		 * Output for the order received page
		 * 
		 * @param object $order order data
		 */
		public function receipt_page($order) {
			echo $this-> generate_form($order);
		}
		
		/**
		 * Check for Custom Payment Gateway response
		 * 
		 * @access public
		 * @return void
		 */
		function check_ipn_response() {
			@ob_clean();
			
			if (isset($_GET['status']) && isset($_GET['transactionId']) && isset($_GET['paymentId']) && isset($_GET['orderNo']) && isset($_GET['amount']) && isset($_GET['currency']) && isset($_GET['paymentType'])) {
				do_action("valid_custompaymentgateway_request_returnurl", $_GET);
			}
			else {
				wp_die("Custom Payment Gateway Request Failure");
			}
		}
		
		/**
		 * Handle return response
		 * 
		 * @global mixed @woocommerce
		 */
		function check_custompaymentgateway_response_returnurl() {
			global $woocommerce;
			
			// Verify return data with private key
			$verifyresult = $this->verify_trans_result($_GET);
			$status = $_GET['status'];
			if (!$verifyresult) {
				$status = "-1";
			}
			
			$WCOrderId = $_GET['orderNo'];
			$order = new WC_Order($WCOrderId);
			$paymentType = "<br>Payment Type: " . $_GET['paymentType'];
			$getStatus = $order->get_status;
			$transactionId = $_GET['transactionId'];
			
			// Update order status only if order is not 'processing' or 'completed' yet to avoid duplicated update
			if (!in_array($getStatus, array('processing', 'completed'))) {
				
				// Update order status
				$this->update_order_status($WCOrderId, $status, $transactionId, $paymentType);
				
				// If payment success, redirect to order received page
				if (in_array($status, array("success"))) {
					wp_redirect($order->get_checkout_order_received_url());
				}
				// If payment cancelled, redirect to order payment page
				else if (in_array($status, array("pending"))) {
					wp_redirect($order->get_checkout_payment_url( true ));
				}
				// If payment failed / invalid, redirect back to cancel order
				else {
					wp_redirect($order->get_cancel_order_url());
				}
			}
			else {
				wp_redirect($order->get_checkout_order_received_url());
			}
			
			exit;
		}
		
		/**
		 * Update order status
		 * 
		 * @global mixed $woocommerce
		 * @param int $order_id
		 * @param string $custompaymentgateway_status
		 * @param into $transactionId
		 * @param string $paymentType
		 */
		public function update_order_status($order_id, $custompaymentgateway_status, $transactionId, $paymentType) {
			global $woocommerce;
			
			$order = new WC_Order($order_id);
			switch ($custompaymentgateway_status) {
				case 'success':
					$custompaymentgateway_status_txt = 'SUCCESSFUL';
					break;
				case 'pending':
					$custompaymentgateway_status_txt = 'PENDING';
					$wc_status = 'pending';
					break;
				case 'failed':
					$custompaymentgateway_status_txt = 'FAILED';
					$wc_status = 'failed';
					break;
				case 'fail':
					$custompaymentgateway_status_txt = 'FAIL';
					$wc_status = 'failed';
					break;
				default:
					$custompaymentgateway_status_txt = 'Invalid Transaction';
					$wc_status = 'on-hold';
					break;
			}
			
			$order_status = $order->get_status();
			
			// Update order status only if order is not 'processing' or 'completed' to avoid duplicated update
			if (!in_array($order_status, array('processing', 'completed'))) {
				$order->add_order_note('Custom Payment Status: ' . $custompaymentgateway_status_txt . '<br>Transaction ID: ' . $transactionId . $paymentType);
				
				// Payment success
				if ($custompaymentgateway_status == "success") {
					$order->payment_complete();
					$order->set_transaction_id($transactionId);
					$order->save();
				}
				// Payment cancelled / failed / invalid
				else {
					$order->update_status($wc_status, sprintf(__('Payment %s via Custom Payment Gateway.', 'woocommerce'), $transactionId));
				}
			}
		}
		
		/**
		 * To verify transaction result using secret key
		 *
		 * @global mixed $woocommerce
		 * @param array $response
		 * @return boolean verifyresult
		 */
		public function verify_trans_result($queries) {
			
			// Check for query string
			$queryString = $_SERVER['QUERY_STRING'];
			if (empty($queryString)){
				return false;
			}

			parse_str($queryString, $queries);
			$dataString = implode(',', array(
				$queries['transactionId'],
				$queries['paymentId'],
				$queries['status'],
				$queries['orderNo'],
				$queries['amount'],
				$queries['currency'],
				$queries['paymentType']
			));
			$isVerified = $queries['checksum'] === hash_hmac('sha256', $dataString, $this->secret_key);
			
			// Verification success
			if ($isVerified) {
				return true;
			}
			// Verification failed
			else {
				return false;
			}
		}
	}
}
?>