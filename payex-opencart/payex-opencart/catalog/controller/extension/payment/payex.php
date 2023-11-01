<?php
class ControllerExtensionPaymentPayex extends Controller {
    public function index() {
        $data['button_confirm'] = $this->language->get('button_confirm');

        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        // get token
        $token = base64_encode($this->config->get('payment_payex_username') . ":" . $this->config->get('payment_payex_security'));
        $url = $this->config->get('payment_payex_environment') . 'api/Auth/Token';
        $options = array (
            'http' => array (
                'header' => 'Authorization: Basic ' . $token . "\r\nContent-Length: 0\r\n",
                'method' => 'POST'
            )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->config->get('payment_payex_environment') . 'api/v1/PaymentIntents',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(array(
                array(
                    "amount" => round($this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false) * 100, 0) ,
                    "currency" => $order_info['currency_code'],
                    "description" => $this->config->get('config_name') . ' - #' . $this->session->data['order_id'],
                    "reference_number" => $this->session->data['order_id'],
                    "customer_id" => $order_info['customer_id'],
                    "customer_name" => $order_info['payment_firstname'] . ' ' . $order_info['payment_lastname'],
                    "contact_number" => $order_info['telephone'],
                    "email" => $order_info['email'],
                    "address" => $order_info['payment_company'] . ' ' . $order_info['payment_address_1'] . ',' . $order_info['payment_address_2'],
                    "postcode" => $order_info['payment_postcode'],
                    "city" => $order_info['payment_city'],
                    "state" => $order_info['payment_zone'],
                    "country" => $order_info['payment_country'],
                    "shipping_name" => $order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname'],
                    "shipping_address" => $order_info['shipping_company'] . ' ' . $order_info['shipping_address_1'] . ',' . $order_info['shipping_address_2'],
                    "shipping_postcode" => $order_info['shipping_postcode'],
                    "shipping_city" => $order_info['shipping_city'],
                    "shipping_state" => $order_info['shipping_zone'],
                    "shipping_country" => $order_info['shipping_country'],
                    "return_url" => $this->url->link('extension/payment/payex/oc_callback', '', true),
                    "accept_url" => $this->url->link('extension/payment/payex/oc_callback', '', true),
                    "reject_url" => $this->url->link('extension/payment/payex/oc_callback', '', true),
                    "callback_url" => $this->url->link('extension/payment/payex/oc_callback', '', true),
                    "source" => "opencart"
                )
            )),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . json_decode($result)->token,
                'Content-Type: application/json',
            ),
        ));
        
        $response = curl_exec($curl);
        
        curl_close($curl);

        $decoded = json_decode($response);
        if ($decoded->status == '00' && count($decoded->result) != 0) $data['action'] = $decoded->result[0]->url;

        return $this->load->view('extension/payment/payex', $data);
    }

    public function oc_callback() {
        $this->log->write('[PAYEX] Callback Received - '.json_encode($this->request->post));

        if (isset($this->request->post['auth_code'])) {
            $this->load->model('checkout/order');
            $txn_id = $this->request->post['txn_id'];
            $reference_number = $this->request->post['reference_number'];
            $response_description = $this->request->post['response'];
            $response_code = $this->request->post['auth_code'];

            if ($response_code == '00') {
                $this->log->write('[PAYEX] Successful transaction, changing DB status of # '.$reference_number.' to ' . $this->config->get('payment_payex_completed_status_id'));
                try {
                    $this->cart->clear();
                    $this->model_checkout_order->addOrderHistory(
                        $reference_number,
                        $this->config->get('payment_payex_completed_status_id'),
                        'Payment completed via Payex (Txn ID: '.$txn_id.')',
                        false, true
                    );
                    $this->response->redirect($this->url->link('checkout/success', '', true));
                } catch (\Exception $ex) {
                    $this->log->write('[PAYEX] Failed to update status of order #' . $reference_number);
                    $this->log->write($ex->getMessage());
                }
            } else if ($response_code == '09' || $response_code == '99') {
                $this->log->write('[PAYEX] Pending Transaction #'.$reference_number);
                $this->model_checkout_order->addOrderHistory(
                    $reference_number,
                    $this->config->get('payment_payex_pending_status_id'),
                    'Payment pending via Payex (Txn ID: '.$txn_id.')',
                    false, true
                );
                $this->response->redirect($this->url->link('checkout/checkout', '', true));
            } else {
                $this->log->write('[PAYEX] Failed Transaction #'.$reference_number);
                $this->model_checkout_order->addOrderHistory(
                    $reference_number,
                    $this->config->get('payment_payex_failed_status_id'),
                    'Payment failed via Payex (Txn ID: '.$txn_id.') with Response Code: ' . $response_code . ', Response Message: ' . $response_description,
                    false, true
                );
                $this->response->redirect($this->url->link('checkout/failure', '', true));
            }
        }
    }
}
