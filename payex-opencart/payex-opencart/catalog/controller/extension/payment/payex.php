<?php
class ControllerExtensionPaymentPayex extends Controller {
	public function index() {
		$data['button_confirm'] = $this->language->get('button_confirm');

		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

		$data['action'] = 'https://api.payex.io/Payment/Form';

		// get token
		$auth_code = base64_encode($this->config->get('payment_payex_username') . ":" . $this->config->get('payment_payex_security'));
		$url = 'https://api.payex.io/api/Auth/token';
		$options = array (
			'http' => array (
				'header' => "Authorization: Basic " . $auth_code . "\r\n"
					. "Content-Length: 0\r\n",
				'method' => 'POST'
			)
		);
		$context = stream_context_create($options);
		$result = file_get_contents($url, false, $context);

		$data['token'] = json_decode($result)->token;
		$data['ap_merchant'] = $this->config->get('payment_payex_merchant');
		$data['ap_amount'] = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
		$data['ap_currency'] = $order_info['currency_code'];
		$data['ap_purchasetype'] = 'Item';
		$data['ap_itemname'] = $this->config->get('config_name') . ' - #' . $this->session->data['order_id'];
		$data['ap_itemcode'] = $this->session->data['order_id'];
		$data['ap_returnurl'] = $this->url->link('checkout/success');
		$data['ap_cancelurl'] = $this->url->link('checkout/checkout', '', true);
		$data['email'] = $order_info['email'];
		$data['contact_number'] = $order_info['telephone'];
		$data['customer_name'] = $order_info['shipping_firstname'] . " " . $order_info['shipping_lastname'];
		$data['address'] = $order_info['shipping_address_1'];
		$data['postcode'] = $order_info['shipping_postcode'];
		$data['state'] = $order_info['shipping_zone'];
		$data['country'] = $order_info['shipping_country'];
		$data['payment_type'] = 'payex';

		return $this->load->view('extension/payment/payex', $data);
	}

	public function oc_callback() {
		if (isset($this->request->post['auth_code'])) {
			$this->load->model('checkout/order');

			$this->model_checkout_order->addOrderHistory($this->request->post['reference_number'], $this->config->get('payment_payex_order_status_id'), "auth code " . $this->request->post['auth_code'], true);
		}
	}

	public function oc_return() {
		if (isset($this->request->post['auth_code']) && ($this->request->post['auth_code'] == '00' || $this->request->post['auth_code'] == '09' || $this->request->post['auth_code'] == '99')) {
			$this->response->redirect($this->url->link('checkout/success', '', true));
		} else {
			$this->response->redirect($this->url->link('checkout/failure', '', true));
		}

	}
}
