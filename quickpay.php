<?php

/**
 * Quickpay Virtuemart module, for Quickpays v10 api only.
 *
 *
 * Version 4.04 - 30/03-2016
 * - Changed payment system to use payment links
 * - Minor fix for prefix'
 * - Minor cleanup
 *
 * Version 4.03 - 22/09-2015
 * - Fixed a minor problem with texts
 *
 * Version 4.02 - 18/09-2015
 * - Fixed bug in fee calculation
 * - Callback could be called accidentally in certain situations, fixed
 * - General cleanup
 * - Added language support so the chosen language is used after payment is completed (or cancelled)
 * - API redirect is upgraded to CURL for a better solution
 * - More tags added to the redirect process
 *
 * Version 4.01 - 02/08-2015
 * - Cleanup in callback handling
 * - Added integration to third party apis by the new field "thirdpartyapi" and the tags [ORDER_NUMBER], [TRANSACTION_ID] and [PAYMENT_METHOD_ID]
 *
 * Version 4.00 - 18/05-2015
 * - Same functionality as 3.01 but for Quickpays new v10 API
 *
 * Version 3.01 - 21/01-2015
 * - The forthcoming order id is sent to Quickpay instead of the random generated orderid
 * - Optional order prefix field in the configuration, any value here is prefixed on the orderid that is sent to quickpay
 * - Tested on stable virtuemart 3 and found ok (earlier version was tested on release candidate only)
 *
 * Version 3.00 - 03/11-2014
 * - Version 7 api support
 * - Support for Virtuemart 3 (2.9.9.2 release candidate), please note things might change before final VM3 is released
 *
 * Version 2.22 - 08/02-2013
 * - Payment id got cleared on a failed transaction, and a second succesful payment on same order was not completed succesfully. (= state and other stuff not updated)
 *
 * Version 2.21 - 02/02-2013
 * - Fixed id in database that had small datatype
 *
 * Version 2.2 - 02/10-2012
 * - Upgraded to api v6
 * - Fee is added to the total if selected
 * - Tested and found ok for Virtuemart 2.0.10
 *
 * Version 2.1 - 05/03-2012
 * - Small fix, the cart was not always emptied
 *
 * Version 2.0 - 29/02-2012
 *
 * - Now support for Virtuemart 2.0.2. Not compatible with earlier versions like 2.0 and 2.0.1.
 *
 */


defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

include_once ('quickpay_helper.php');

class plgVMPaymentQuickpay extends vmPSPlugin
{
    // instance of class
    public static $_this = false;

    protected $connTimeout = 10; // The connection timeout to Quickpay gateway
    protected $apiUrl = "https://api.quickpay.net";
    protected $apiVersion = 'v10';
    protected $apiKey = ""; // Loaded from the configuration
    protected $format = "application/json";
    protected $synchronized = "?synchronized";


    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    function getTableSQLFields()
    {
        $SQLfields = array('id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED', 'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED', 'payment_name' =>
                'varchar(5000)', 'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)', 'cost_per_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)', 'tax_id' => 'smallint(1)',
            'quickpay_time' => 'char(13)', 'quickpay_state' => 'char(10)', 'quickpay_qpstat' =>
                'char(10)', 'quickpay_qpstatmsg' => 'char(50)', 'quickpay_chstat' => 'char(5)',
            'quickpay_chstatmsg' => 'char(50)',
            'quickpay_cardtype' => 'char(32)',
            'quickpay_payment_id' => 'char(32)',
            'quickpay_fraudprobability' => 'char(32)', 'quickpay_fraudremarks' => 'char(32)',
            'quickpay_fraudreport' => 'char(32)');
        return $SQLfields;
    }

    function qpSign($params, $api_key)
    {
        ksort($params);
        $base = "";
        foreach ($params as $key => $value) {
            if ($key != 'checksum') { // Remove the checksum field from the hash calculation
                $base .= $value . " ";
            }
        };
        $base = substr($base, 0, -1);
        return hash_hmac("sha256", $base, $api_key);
    }

    /**
     * Prepare the quickpay payment link
     *
     * @param $cart
     * @param $order
     * @return mixed
     */
    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $session = JFactory::getSession();
        $return_context = $session->getId();
        $this->_debug = $method->debug;
        $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->
            order_number, 'message');

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
        }

        $new_status = '';

        $vat_tax = $order['details']['BT']->order_tax;

        $vendorModel = new VirtueMartModelVendor();
        $vendorModel->setId(1);
        $this->getPaymentCurrency($method);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db = &JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();

        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
        $cd = CurrencyDisplay::getInstance($cart->pricesCurrency);

        $prefix = $method->prefix;
        if ($prefix) {
            if (strlen($prefix.$order['details']['BT']->virtuemart_order_id) < 4) {
                $order_number = $prefix . str_pad($order['details']['BT']->virtuemart_order_id, 4, '0', STR_PAD_LEFT);
            } else {
                $order_number = $prefix . $order['details']['BT']->virtuemart_order_id;
            }
        } else {
            $order_number = str_pad($order['details']['BT']->virtuemart_order_id, 4, '0', STR_PAD_LEFT);
        }

        // Now round the amount if requested from the configuration
        if ($method->quickpay_round_order_amount == 1) {
            $roundedAmount = 100 * round($totalInPaymentCurrency);
        } else {
            $roundedAmount = 100 * $totalInPaymentCurrency;
        }

        // Keep the same language after payment/cancel
        $lang = JFactory::getLanguage();
        $lang_code_explode = explode("-", $lang->getTag());
        $lang_code = strtolower($lang_code_explode[1]);

        $post_variables = Array(
            'agreement_id' => $method->quickpay_agreement_id, // Read from configuration
            'amount' => $roundedAmount,
            'continueurl' => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&ordernumber=' . $order['details']['BT']->order_number),
            'cancelurl' => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&lang=' . $lang_code),
            'callbackurl' => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&sessionid=' . $session->getId() . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&lang=' . $lang_code),
            'language' => $this->resolveQuickpayLang(),
            'autocapture' => $method->quickpay_autocapture,
            'autofee' => $method->quickpay_autofee,
            'payment_methods' => $method->quickpay_cardtypelock, // read from configuration
            'branding_id' => $method->quickpay_branding_id, // Read from configuration
            'google_analytics_tracking_id' => $method->quickpay_google_analytics_tracking_id, // read from configuration
            'google_analytics_client_id' => $method->quickpay_google_analytics_client_id, // read from configuration
            'vat_amount' => 100 * $vat_tax, // paii... calculate it from order
            'product_id' => $method->quickpay_paii_product_id, // read from configuration
            'category' => $method->quickpay_paii_category, // read from configuration paii category
            'reference_title' => JURI::root() . '-' . $order_number, // read from configuration, paii related, see drupal module how we set this
        );

        $helper = new QuickpayHelper();
        $helper->setApiKey($method->quickpay_md5_key);
        $id = $helper->qpCreatePayment($order_number, $currency_code_3);
        $result = $helper->qpCreatePaymentLink($id->id, $post_variables);
        $paymentUrl = $result->url;

        // Prepare data that should be stored in the database
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['payment_name'] = $this->renderPluginName($method, $order);
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $method->payment_currency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency;
        $dbValues['tax_id'] = $method->tax_id;
        $dbValues['quickpay_payment_id'] = $id->id;
        $this->storePSPluginInternalData($dbValues);

        $html = ' <script type="text/javascript">';
        $html .= 'function redirect() {';
        $html .= '  window.location.href = "' . $paymentUrl . '";';
        $html .= '}';
        $html .= "setTimeout('redirect()', 500);";
        $html .= '</script>';

        // 	2 = don't delete the cart, don't send email and don't redirect
        return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $new_status);
    }

    /*
    * Sign the parameters with api key using sha256
    */

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    function plgVmOnPaymentResponseReceived(&$html)
    {
        // the payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = JFactory::getApplication()->input->getInt('pm', 0);

        $vendorId = 0;
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $payment_data = JFactory::getApplication()->input->get('get');
        vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
        $order_number = $payment_data['ordernumber'];

        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        $payment_name = $this->renderPluginName($method);
        $html = ""; // Here we could add some Quickpay status info, but we do not, but it can easily be extended, order is ready

        //We delete the old stuff
        if (!class_exists('VirtueMartCart'))
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
        $cart = VirtueMartCart::getCart();
        $cart->emptyCart();

        return true;
    }

    function plgVmOnUserPaymentCancel()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        $order_number = JFactory::getApplication()->input->getVar('on');
        if (!$order_number) {
            return false;
        }
        $db = JFactory::getDBO();

        $virtuemart_paymentmethod_id = JFactory::getApplication()->input->getInt('pm', 0);
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        ////////////////////////////////////remove prefix from ordernumber///////////////////
        $prefix = $method->prefix;
        if (preg_match('/' . $prefix . '/', $order_number)) {
            $order_number = str_replace($prefix, '', $order_number);
        }
        $order_number = intval($order_number);
        ////////////////////////////////////////////////////////////////////////////

        $query = 'SELECT ' . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->
            _tablename . " WHERE  `order_number`= '" . $order_number . "'";

        $db->setQuery($query);
        $virtuemart_order_id = $db->loadResult();

        if (!$virtuemart_order_id) {
            return null;
        }
        $this->handlePaymentUserCancel($virtuemart_order_id);

        //JRequest::setVar('paymentResponse', $returnValue);
        return true;
    }

    /**
     * Triggered by the Quickpay callback
     * Return:
     * Parameters:
     *  None
     */
    function plgVmOnPaymentNotification()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        $virtuemart_paymentmethod_id = JFactory::getApplication()->input->getInt('pm', 0);
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }

        $requestBody = file_get_contents("php://input");
        $callbackDataGet = JFactory::getApplication()->input->get('get');
        $request = json_decode($requestBody);

        // If someone calls us by accident, bail out
        if (empty($request->operations)) {
            return null;
        }

        $operation = end($request->operations);
        $order_number = $request->order_id;

        if ($operation->type != 'authorize') {
            $this->logInfo('Not an authorize callback');
            return null; // Another method was selected or another callback than authorize triggered, do nothing
        }

        // Check checksum
        $key = $method->quickpay_private_key;
        $checksum = hash_hmac("sha256", $requestBody, $key);
        if ($checksum != $_SERVER["HTTP_QUICKPAY_CHECKSUM_SHA256"]) {
            $this->logInfo('Bad callback checksum', 'ERROR');
            return null;
        }

        // Remove prefix from ordernumber
        $prefix = $method->prefix;
        if (isset($prefix)) {
            $order_number = substr($order_number, strlen($prefix));
        }

        $order_number = intval($order_number);
        $virtuemart_order_id = $order_number;

        if (!$virtuemart_order_id) {
            return;
        }

        $vendorId = 0;
        $payment = $this->getDataByOrderId($virtuemart_order_id);

        $method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        if (!$payment) {
            $this->logInfo('getDataByOrderId payment not found: exit ', 'ERROR');
            return null;
        }

        $post_msg = '';

        $this->updateDbValues($virtuemart_order_id, $payment->virtuemart_paymentmethod_id, $request);


        if ($request->accepted && $operation->qp_status_code == "20000") {
            $new_status = $method->status_success;
            $this->logInfo('process OK, status', 'message');

            // Update any payment fee
            $db = JFactory::getDbo();
            $query = $db->getQuery(true);
            $query->select('order_total')->from($db->quoteName('#__virtuemart_orders'))->where($db->quoteName('virtuemart_order_id') . '=' . $virtuemart_order_id);
            $db->setQuery($query);
            $oldOrderTotal = $db->loadResult();
            $oldOrderTotal *= 100.0;
            $fee = $operation->amount - $oldOrderTotal;
            if ($fee > 0) {
                $quickPayFee = $fee / 100.0;
                $db = JFactory::getDBO();
                $query = "update #__virtuemart_orders SET order_payment=" . $quickPayFee . ",order_total = order_total+$quickPayFee WHERE virtuemart_order_id=" . $virtuemart_order_id . " AND order_payment = 0";
                $db->setQuery($query);
                $db->query();
            }
        } else {
            $this->logInfo('process ERROR', 'ERROR');
            $new_status = $method->status_canceled;
        }

        $this->logInfo('plgVmOnPaymentNotification return new_status:' . $new_status, 'message');
        $this->logInfo('plgVmOnPaymentNotification session:', $return_context);

        $modelOrder = VmModel::getModel('orders');
        $order = array();
        $order['order_status'] = $new_status;
        $order['customer_notified'] = 1;
        $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);

        $this->logInfo('Notification, sentOrderConfirmedEmail ' . $order_number . ' ' . $new_status, 'message'); //// remove vmcart
        $this->emptyCart($return_context);

        // Now notify any third party services
        if ($method->quickpay_thirdpartyapi != '' && $request->accepted && $operation->qp_status_code == "20000") {
            $apiurl = $method->quickpay_thirdpartyapi;

            $lang = JFactory::getLanguage();
            $lang_code_explode = explode("-", $lang->getTag());
            $lang_code = strtolower($lang_code_explode[1]);

            $apiurl = str_replace('[LANGUAGE]', $lang_code, $apiurl);
            $apiurl = str_replace('[ORDER_ID]', $virtuemart_order_id, $apiurl);
            $apiurl = str_replace('[TRANSACTION_ID]', $request->id, $apiurl);
            $apiurl = str_replace('[PAYMENT_METHOD_ID]', $payment->virtuemart_paymentmethod_id, $apiurl);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiurl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Important for HTTPS
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $output = curl_exec($ch);
            curl_close($ch);
        }
    }

	public function plgVmOnUpdateOrderPayment(&$order, $old_order_status) 
    {
        if (!$this->selectedThisByMethodId($order->virtuemart_paymentmethod_id)) {
            return null; // Another method was selected, do nothing
        }
        $method = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id);

        if ($order->order_status == 'R') {
            $db = JFactory::getDBO();
            $q = 'SELECT * FROM `' . $this->_tablename . '` ' .
                'WHERE `virtuemart_order_id` = ' . $order->virtuemart_order_id;
            $db->setQuery($q);
            if (!($paymentTable = $db->loadObject())) {
                return '';
            }
            if(!$paymentTable->quickpay_payment_id){
                return '';
            }
                        
            $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
            $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order->order_total, false), 2);
            if ($method->quickpay_round_order_amount == 1) {
                $roundedAmount = 100 * round($totalInPaymentCurrency);
            } else {
                $roundedAmount = 100 * $totalInPaymentCurrency;
            }
            $helper = new QuickpayHelper();
            $helper->setApiKey($method->quickpay_md5_key);
            try {
                $result = $helper->qpRefund($paymentTable->quickpay_payment_id, $roundedAmount);
                $this->updateDbValues($order->virtuemart_order_id, $order->virtuemart_paymentmethod_id, $result);
            } catch (Exception $e){

            }
            return true;
        } else if(($order->order_status == 'S' || $order->order_status == 'F') && !$method->quickpay_autocapture){
            $db = JFactory::getDBO();
            $q = 'SELECT * FROM `' . $this->_tablename . '` ' .
                'WHERE `virtuemart_order_id` = ' . $order->virtuemart_order_id;
            $db->setQuery($q);
            if (!($paymentTable = $db->loadObject())) {
                return '';
            }
            if(!$paymentTable->quickpay_payment_id && $paymentTable->quickpay_state == 'new'){
                return '';
            }
            $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
            $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order->order_total, false), 2);
            if ($method->quickpay_round_order_amount == 1) {
                $roundedAmount = 100 * round($totalInPaymentCurrency);
            } else {
                $roundedAmount = 100 * $totalInPaymentCurrency;
            }
            $helper = new QuickpayHelper();
            $helper->setApiKey($method->quickpay_md5_key);
            try {
                $result = $helper->qpCapture($paymentTable->quickpay_payment_id, $roundedAmount);
                $this->updateDbValues($order->virtuemart_order_id, $order->virtuemart_paymentmethod_id, $result);
            } catch (Exception $e){

            }
            
            return true;
        }
        return true;
    }

    protected function updateDbValues($virtuemart_order_id, $virtuemart_paymentmethod_id, $resultData){
        $response_fields = [];
        $response_fields['virtuemart_order_id'] = $virtuemart_order_id;
        $response_fields['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;

        $operation = end($resultData->operations);

        $response_fields['quickpay_qpstatmsg'] = $operation->qp_status_msg;
        $response_fields['quickpay_qpstat'] = $operation->qp_status_code;
        $response_fields['quickpay_time'] = date('h:i:s', strtotime($operation->created_at));
        $response_fields['quickpay_chstat'] = $operation->aq_status_code;
        $response_fields['quickpay_chstatmsg'] = $operation->aq_status_msg;
        $response_fields['quickpay_chstatmsg'] = $operation->aq_status_msg;
        $response_fields['quickpay_state'] = ucfirst($resultData->state);
        $response_fields['quickpay_cardtype'] = $resultData->metadata->brand;
        $method = $this->getVmPluginMethod($virtuemart_paymentmethod_id);
        $response_fields['payment_name'] = $this->renderPluginName($method);
        $response_fields['quickpay_payment_id'] = $resultData->id;
        $response_fields['quickpay_fraudprobability'] = $resultData->metadata->fraud_suspected;  

        $this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', 1);
    }

    /**
     * Display stored payment data for an order
     * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id)
    {
        if (!$this->selectedThisByMethodId($payment_method_id)) {
            return null; // Another method was selected, do nothing
        }

        $db = JFactory::getDBO();
        $q = 'SELECT * FROM `' . $this->_tablename . '` ' .
            'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            return '';
        }
        $this->getPaymentCurrency($paymentTable);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' .
            $paymentTable->payment_currency . '" ';
        $db = &JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();
        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('PAYMENT_NAME', $paymentTable->payment_name);
        $code = "quickpay_";
        foreach ($paymentTable as $key => $value) {
            if (substr($key, 0, strlen($code)) == $code) {
                $html .= $this->getHtmlRowBE($key, $value);
            }
        }
        $html .= '</table>' . "\n";

        return $html;
    }

    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->cost_percent_total)) {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }
        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total *
                0.01));
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart : the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /**
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * plgVmonSelectedCalculatePricePayment
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk
     * public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
     * return null;
     * }
     */
    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     * @author Max Milbers
     * public function plgVmOnCheckoutCheckDataPayment($psType, VirtueMartCart $cart) {
     * return null;
     * }
     */

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Quickpay Table');
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     * @author: Valerie Isaksen
     *
     * @param $cart_prices : cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount = $cart_prices['salesPrice'];
        $amount_cond = ($amount >= $method->min_amount and $amount <= $method->
            max_amount or ($method->min_amount <= $amount and ($method->max_amount == 0)));

        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id']))
            $address['virtuemart_country_id'] = 0;
        if (in_array($address['virtuemart_country_id'], $countries) || count($countries) ==
            0
        ) {
            if ($amount_cond) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide the language to use in quickpay
     */
    private function resolveQuickpayLang()
    {
        $txtId = "VMPAYMENT_QUICKPAY_PAYWINDOWLANGUAGE";
        if (JText::_($txtId)) {
            return JText::_($txtId);
        } else {
            return 'da';
        }
    }



}

// No closing tag




