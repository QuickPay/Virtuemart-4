<?php
/**
* Quickpay helper for accessing the Quickpay api.
*
* (c)2016 - www.stokersoft.com
*/

class QuickpayHelper {

	protected $connTimeout = 10; // The connection timeout to Quickpay gateway
	protected $apiUrl = "https://api.quickpay.net";
	protected $apiVersion = 'v10';
	protected $apiKey = ""; // Loaded from the configuration
	protected $format = "application/json";
	protected $synchronized = "?synchronized";
	
	
	function setApiKey($key) {
		$this->apiKey = $key;
	}
	
	/**
	 * Send a request to Quickpay.
	 */
	function request($resource, $postdata = null, $synchronized="?synchronized") {
		if (!function_exists('curl_init')) {
			throw  Exception('CURL is not installed, please install curl');
		}

		$curl = curl_init();
		$url = $this->apiUrl . "/" . $resource . $synchronized;
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_TIMEOUT, $this->connTimeout);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode(":" . $this->apiKey), 'Accept-Version: ' . $this->apiVersion, 'Accept: ' . $this->format));
		if (!is_null($postdata)) {
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postdata));
		}

		$response = curl_exec($curl);

		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($httpCode != 200 && $httpCode!= 201 && $httpCode != 202) {
			throw new Exception($response, $httpCode);
		}

		return $response;
	}

	function put($resource, $postdata = null) {
		$curl = curl_init();
		$url = $this->apiUrl . "/" . $resource;
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_TIMEOUT, $this->connTimeout);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode(":" . $this->apiKey), 'Accept-Version: ' . $this->apiVersion, 'Accept: ' . $this->format));
		if (!is_null($postdata)) {
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postdata));
		}

		$response = curl_exec($curl);

		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($httpCode != 200 && $httpCode != 201 && $httpCode != 202) {
			throw new Exception($response, $httpCode);
		}

		return $response;
	}
	
	/**
	 * Create a payment at the quickpay gateway
	 */
	function qpCreatePayment($orderid, $currency) {
		$postArray = array();
		$postArray['order_id'] = $orderid;
		$postArray['currency'] = $currency;
		$result = $this->request('payments', $postArray,"");
		$result = json_decode($result);
		return $result;
	}

	/**
	 * Create a payment link at the quickpay gateway
	 */
	function qpCreatePaymentLink($id, $array) {
		$result = $this->put('payments/' . $id . '/link', $array);
		$result = json_decode($result);
		return $result;
	}

	/**
	 * Capture a payment at the quickpay gateway
	 */
	function qpCapture($id, $amount, $extras = null) {
		$postArray = array();
		$postArray['id'] = $id;
		$postArray['amount'] = $amount;
		if (!is_null($extras)) {
			$postArray['extras'] = $extras;
		}
		$result = $this->request('payments/' . $id . '/capture', $postArray);
		$result = json_decode($result);
		return $result;
	}

	/**
	 * Refund a payment at the quickpay gateway
	 */
	function qpRefund($id, $amount, $extras = null) {
		$postArray = array();
		$postArray['id'] = $id;
		$postArray['amount'] = $amount;
		if (!is_null($extras)) {
			$postArray['extras'] = $extras;
		}
		$result = $this->request('payments/' . $id . '/refund', $postArray);
		$result = json_decode($result);
		return $result;
	}

	/**
	 * Cancel a payment at the quickpay gateway
	 */
	function qpCancel($id) {
		$postArray = array();
		$postArray['id'] = $id;
		$result = $this->request('payments/' . $id . '/cancel', $postArray);
		$result = json_decode($result);
		return $result;
	}
	
	
}

?>
