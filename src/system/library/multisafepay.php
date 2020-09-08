<?php

/**
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the MultiSafepay plugin
 * to newer versions in the future. If you wish to customize the plugin for your
 * needs please document your changes and make backups before you update.
 *
 * @category    MultiSafepay
 * @package     Connect
 * @author      TechSupport <integration@multisafepay.com>
 * @copyright   Copyright (c) MultiSafepay, Inc. (https://www.multisafepay.com)
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR
 * PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN
 * ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

class Multisafepay {

    const FIXED_TYPE = 'F';
    const PERCENTAGE_TYPE = 'P';
    const ROUTE = 'extension/payment/multisafepay';
    const OC_VERSION = VERSION;

    public function __construct($registry) {
        $this->registry = $registry;
    }

    /**
     * Magic method that returns any object used in OpenCart from registry object
     * when has not been found inside this class
     *
     * @param string $name
     * @return object
     *
     */
    public function __get($name) {
        return $this->registry->get($name);
    }

    /**
     * Returns the plugin version .
     *
     * @return strong $plugin_version
     *
     */
    public function getPluginVersion() {
        $plugin_version = '3.0.0';
        return $plugin_version;
    }

    /**
     * Returns a ShoppingCart object to be used in .
     *
     * @param int $order_id
     * @return ShoppingCart  object
     *
     */
    public function getShoppingCartItems($order_id) {
        $order_info = $this->getOrderInfo($order_id);
        $order_products = $this->getOrderProducts($order_id);
        $coupon_info = $this->getCouponInfo($order_id);
        $shopping_cart_items = array();

        // Order Products
        foreach ($order_products as $product) {
            $shopping_cart_item = $this->getCartItem($product, $order_id);
            $shopping_cart_items[$this->config->get('total_sub_total_sort_order')][] = $shopping_cart_item;
        }

        // Shipping Cost
        $shipping_info = $this->getShippingInfo($order_id);
        if ($shipping_info) {
            $shipping_cart_item = $this->getShippingItem($order_id);
            $shopping_cart_items[$this->config->get('total_shipping_sort_order')][] = $shipping_cart_item;
        }

        // Coupons
        if ($coupon_info) {
            $coupon_cart_item = $this->getCouponItem($order_id);
            if ($coupon_cart_item) {
                $shopping_cart_items[$this->config->get('total_coupon_sort_order')][] = $coupon_cart_item;
            }
        }

        // Handling Fee
        $handling_fee_info = $this->getHandlingFeeInfo($order_id);
        if ($handling_fee_info) {
            $handling_fee_cart_item = $this->getHandlingFeeItem($order_id);
            $shopping_cart_items[$this->config->get('total_handling_sort_order')][] = $handling_fee_cart_item;
        }

        // Low Order Fee
        $low_order_fee_info = $this->getLowOrderFeeInfo($order_id);
        if ($low_order_fee_info) {
            $low_order_fee_info_cart_item = $this->getLowOrderFeeItem($order_id);
            $shopping_cart_items[$this->config->get('total_low_order_fee_sort_order')][] = $low_order_fee_info_cart_item;
        }

        // Fixed Taxes
        $fixed_taxes_items = $this->getFixedTaxesItems($order_id);
        if (!empty($fixed_taxes_items)) {
            $fixed_taxes_items = $this->getFixedTaxesItems($order_id);
            $shopping_cart_items[$this->config->get('total_tax_sort_order')] = $fixed_taxes_items;
        }

        // Customer Balance - Credit
        $customer_additional_data = $this->getAdditionalCustomerData();
        if ($customer_additional_data['customer_balance'] > 0) {
            $customer_balance_item = $this->getCustomerBalanceItem($order_id);
            $shopping_cart_items[$this->config->get('total_credit_sort_order')][] = $customer_balance_item;
        }

        // Vouchers Gift Cards
        $voucher_info = $this->getVoucherInfo($order_id);
        if ($voucher_info) {
            $voucher_info_cart_item = $this->getVoucherItem($order_id);
            $shopping_cart_items[$this->config->get('total_voucher_sort_order')][] = $voucher_info_cart_item;
        }

        // Sort Order Shopping Cart Items
        $cart_items = $this->reOrderShoppingCartItems($shopping_cart_items);

        $shopping_cart = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart($cart_items);

        return $shopping_cart;
    }

    /**
     * Returns the tax rate value applied for a item in the cart.
     *
     * @param float $total
     * @param int $tax_class_id
     * @return float
     *
     */
    private function getItemTaxRate($total, $tax_class_id) {
        $tax_rate = 0;
        $rates = $this->tax->getRates($total, $tax_class_id);
        foreach ($rates as $oc_tax_rate) {
            if ($oc_tax_rate['type'] == self::PERCENTAGE_TYPE) {
                $tax_rate = $tax_rate + $oc_tax_rate['rate'];
            }
        }
        return $tax_rate;
    }

    /**
     * Returns boolean if sort order module provided is lower than the one setup for taxes,
     * used to determined if necessary calculated taxes for those modules.
     *
     * @return bool
     *
     */
    private function isSortOrderLowerThanTaxes($module_sort_order) {
        $tax_sort_order = $this->config->get('total_tax_sort_order');
        if ((int)$tax_sort_order > (int)$module_sort_order) {
            return true;
        }
        return false;
    }

    /**
     * Returns a Sdk object
     *
     * @return Sdk object
     * @throws InvalidApiKeyException
     *
     */
    public function getSdkObject() {

        $this->language->load(self::ROUTE);

        require_once(DIR_SYSTEM . 'library/multisafepay/vendor/autoload.php');

        $enviroment = (empty($this->config->get('payment_multisafepay_environment')) ? true : false);
        $api_key = (($enviroment) ? $this->config->get('payment_multisafepay_api_key') : $this->config->get('payment_multisafepay_sandbox_api_key'));

        try {
            $msp = new \MultiSafepay\Sdk($api_key, $enviroment);
        }
        catch (\MultiSafepay\Exception\InvalidApiKeyException $invalidApiKeyException ) {
            if ($this->config->get('payment_multisafepay_debug_mode')) {
                $this->log->write($invalidApiKeyException->getMessage());
            }
            $this->session->data['error'] = $this->language->get('text_error');
            $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
        }

        return $msp;

    }

    /**
     * Return Order Request Object
     *
     * @param int $order_id
     * @return OrderRequest object
     * @throws ApiException
     *
     */
    public function getOrderRequestObject($data) {

        $this->language->load(self::ROUTE);

        $order_info = $this->getOrderInfo($data['order_id']);

        // Order Request
        $sdk = $this->getSdkObject();

        $msp_order = new \MultiSafepay\Api\Transactions\OrderRequest();
        $msp_order->addOrderId($data['order_id']);
        $msp_order->addType($data['type']);

        // Order Request: Gateway
        if (!empty($data['gateway'])) {
            $msp_order->addGatewayCode($data['gateway']);
        }

        if (isset($data['gateway_info']) && $data['gateway_info'] != '') {
            $gateway_info = $this->getGatewayInfoInterfaceObject($data);
            $msp_order->addGatewayInfo($gateway_info);
        }

        // Order Request: Plugin details
        $plugin_details = $this->getPluginDetailsObject();
        $msp_order->addPluginDetails($plugin_details);

        // Order Request: Money
        $order_total = $this->getMoneyObjectOrderAmount($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $msp_order->addMoney($order_total);

        // Order Request: Description
        $description = $this->getOrderDescriptionObject($data['order_id']);
        $msp_order->addDescription($description);

        // Order Request: Payment Options
        $payment_options = $this->getPaymentOptionsObject();
        $msp_order->addPaymentOptions($payment_options);

        // Order Request: Second Chance
        $payment_multisafepay_second_chance = ($this->config->get('payment_multisafepay_second_chance')) ? true : false;
        $second_chance = $this->getSecondChanceObject($payment_multisafepay_second_chance);
        $msp_order->addSecondChance($second_chance);

        // Order Request: Google Analytics ID
        $google_analytics_account_id = $this->getAnalyticsAccountIdObject($this->config->get('payment_multisafepay_google_analytics_account_id'));
        if ($google_analytics_account_id) {
            $msp_order->addGoogleAnalytics($google_analytics_account_id);
        }

        // Order Request: Shopping Cart Items - Products
        $shopping_cart = $this->getShoppingCartItems($data['order_id']);
        $msp_order->addShoppingCart($shopping_cart);

        // Order Request: Customer
        $customer_payment = $this->getCustomerObject($data['order_id'], 'payment');
        $msp_order->addCustomer($customer_payment);

        // Order Request: Customer Delivery. Only if the order requires delivery.
        if ($order_info['shipping_method'] != '') {
            $customer_shipping = $this->getCustomerObject($data['order_id'], 'shipping');
            $msp_order->addDelivery($customer_shipping);
        }

        // Order Request: Days Active
        if ($this->config->get('payment_multisafepay_days_active')) {
            $msp_order->addDaysActive((int)$this->config->get('payment_multisafepay_days_active'));
        }

        return $msp_order;

    }

    /**
     * Process an Order Request
     *
     * @param OrderRequest $msp_order
     * @return OrderRequest object
     * @throws ApiException
     *
     */
    public function processOrderRequestObject($msp_order) {
        if (!$msp_order) {
            return false;
        }

        $this->language->load(self::ROUTE);
        $sdk = $this->getSdkObject();
        $transaction_manager = $sdk->getTransactionManager();
        try {
            $order_request = $transaction_manager->create($msp_order);
            return $order_request;
        }
        catch (\MultiSafepay\Exception\ApiException $apiException ) {
            if ($this->config->get('payment_multisafepay_debug_mode')) {
                $this->log->write($apiException->getMessage());
            }
            $this->session->data['error'] = $this->language->get('text_error');
            $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
        }

    }

    /**
     * Process a Refund Request
     *
     * @param RefundRequest $msp_order
     * @return RefundRequest object
     * @throws ApiException
     *
     */
    public function processRefundRequestObject($msp_order, $refund_request) {
        if (!$msp_order || !$refund_request) {
            return false;
        }
        $sdk = $this->getSdkObject();
        $transaction_manager = $sdk->getTransactionManager();
        try {
            $process_refund = $transaction_manager->refund($msp_order, $refund_request);
            return $process_refund;
        }
        catch (\MultiSafepay\Exception\ApiException $apiException ) {
            if ($this->config->get('payment_multisafepay_debug_mode')) {
                $this->log->write($apiException->getMessage());
            }
            return false;
        }
    }

    /**
     * Create an Refund Request
     *
     * @param RefundRequest $msp_order
     * @return RefundRequest object
     * @throws ApiException
     *
     */
    public function createRefundRequestObject($msp_order) {
        if (!$msp_order) {
            return false;
        }
        $sdk = $this->getSdkObject();
        $transaction_manager = $sdk->getTransactionManager();

        try {
            $refund_request = $transaction_manager->createRefundRequest($msp_order);
            return $refund_request;
        }
        catch (\MultiSafepay\Exception\ApiException $apiException ) {
            if ($this->config->get('payment_multisafepay_debug_mode')) {
                $this->log->write($apiException->getMessage());
            }
            return false;
        }


    }

    /**
     * Return Issuers by gateway code
     *
     * @param string $gateway_code
     * @return array Issuers
     *
     */
    public function getIssuersByGatewayCode($gateway_code) {
        $sdk = $this->getSdkObject();
        try {
            $issuer_manager = $sdk->getIssuerManager();
            $issuers = $issuer_manager->getIssuersByGatewayCode($gateway_code);
        }
        catch (InvalidArgumentException $invalidArgumentException ) {
            if ($this->config->get('payment_multisafepay_debug_mode')) {
                $this->log->write($invalidArgumentException->getMessage());
            }
            return false;
        }

        $data_issuers = array();
        foreach ($issuers as $issuer) {
            $data_issuers[] = array(
                'code' => $issuer->getCode(),
                'description' => $issuer->getDescription()
            );
        }
        return $data_issuers;
    }

    /**
     * Return Gateway object by code
     *
     * @param string $gateway_code
     * @return Gateway object
     *
     */
    public function getGatewayObjectByCode($gateway_code) {
        $this->language->load(self::ROUTE);
        $sdk = $this->getSdkObject();
        try {
            $gateway_manager = $sdk->getGatewayManager();
            $gateway = $gateway_manager->getByCode($gateway_code);
        }
        catch (\MultiSafepay\Exception\ApiException $apiException ) {
            if ($this->config->get('payment_multisafepay_debug_mode')) {
                $this->log->write($apiException->getMessage());
            }
            $this->session->data['error'] = $this->language->get('text_error');
            $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
        }

        return $gateway;
    }

    /**
     * Returns a CustomerDetails object used to build the order request object,
     * in addCustomer and addDelivery methods.
     *
     * @param array $order_info Order information.
     * @param string $type Used to build the object with the order`s shipping or payment information.
     * @return CustomerDetails object
     *
     */
    public function getCustomerObject($order_id, $type = 'payment') {
        $order_info = $this->getOrderInfo($order_id);
        $customer_ip = new \MultiSafepay\ValueObject\IpAddress($order_info['ip']);
        $telephone = $this->getTelephoneObject($order_info['telephone']);
        $customer_obj =  new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails();
        $customer_obj->addIpAddress($customer_ip);
        if ($order_info['forwarded_ip']) {
            $forwarded_ip = new \MultiSafepay\ValueObject\IpAddress($order_info['forwarded_ip']);
            $customer_obj->addForwardedIp($forwarded_ip);
        }
        $customer_obj->addUserAgent($order_info['user_agent']);
        $customer_obj->addPhoneNumber($telephone);
        $customer_obj->addLocale($this->getLocale());
        $customer_email_address_details =  $this->getEmailAddressObject($order_info['email']);
        $customer_obj->addEmailAddress($customer_email_address_details);
        $customer_obj->addFirstName($order_info[$type . '_firstname']);
        $customer_obj->addLastName($order_info[$type . '_lastname']);

        $customer_address_parser_obj = new \MultiSafepay\ValueObject\Customer\AddressParser();
        $parsed_address = $customer_address_parser_obj->parse($order_info[$type . '_address_1'], $order_info[$type . '_address_2']);

        $customer_address_obj = new \MultiSafepay\ValueObject\Customer\Address();
        $customer_address_obj->addStreetName($parsed_address[0]);
        $customer_address_obj->addHouseNumber($parsed_address[1]);

        $customer_address_obj->addZipCode($order_info[$type . '_postcode']);
        $customer_address_obj->addCity($order_info[$type . '_city']);
        $customer_address_obj->addState($order_info[$type . '_zone']);
        $customer_address_country_obj = $this->getCountryObject($order_info[$type . '_iso_code_2']);
        $customer_address_obj->addCountry($customer_address_country_obj);
        $customer_obj->addAddress($customer_address_obj);
        return $customer_obj;
    }

    /**
     * Returns BankAccount object to be used in OrderRequest transaction
     *
     * @return BankAccount object
     *
     */
    private function getBankAccountObject($bank_account) {
        $bank_account = new \MultiSafepay\ValueObject\BankAccount($bank_account);
        return $bank_account;
    }

    /**
     * Returns EmailAddress object to be used in OrderRequest transaction
     *
     * @return EmailAddress object
     *
     */
    private function getEmailAddressObject($email) {
        $email_address = new \MultiSafepay\ValueObject\Customer\EmailAddress($email);
        return $email_address;
    }

    /**
     * Returns Iban object to be used in OrderRequest transaction
     *
     * @return Iban object
     *
     */
    private function getIbanObject($iban) {
        $iban = new \MultiSafepay\ValueObject\IbanNumber($iban);
        return $iban;
    }

    /**
     * Returns PhoneNumber object to be used in OrderRequest transaction
     *
     * @return PhoneNumber object
     *
     */
    private function getTelephoneObject($telephone) {
        $phone_number = new \MultiSafepay\ValueObject\Customer\PhoneNumber($telephone);
        return $phone_number;
    }

    /**
     * Returns Gender object to be used in OrderRequest transaction
     *
     * @param string $gender
     * @return Gender object
     *
     */
    private function getGenderObject($gender) {
        $gender = new \MultiSafepay\ValueObject\Gender($gender);
        return $gender;
    }

    /**
     * Returns Date object to be used in OrderRequest transaction
     *
     * @param string $date
     * @return Date object
     *
     */
    private function getDateObject($date) {
        $date = new \MultiSafepay\ValueObject\Date($date);
        return $date;
    }

    /**
     * Returns GoogleAnalytics object to be used in OrderRequest transaction
     *
     * @return mixed boolean|GoogleAnalytics object
     *
     */
    public function getAnalyticsAccountIdObject($payment_multisafepay_google_analytics_account_id) {
        if (empty($payment_multisafepay_google_analytics_account_id)) {
            return false;
        }
        $google_analytics_account_id_details = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\GoogleAnalytics();
        $google_analytics_account_id_details->addAccountId($payment_multisafepay_google_analytics_account_id);
        return $google_analytics_account_id_details;
    }

    /**
     * Returns SecondChance object to be used in OrderRequest transaction
     *
     * @param bool $second_chance_status
     * @return SecondChance object
     *
     */
    public function getSecondChanceObject($second_chance_status) {
        $second_chance_details = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\SecondChance();
        $second_chance_details->addSendEmail($second_chance_status);
        return $second_chance_details;
    }

    /**
     * Returns PluginDetails object to be used in OrderRequest transaction
     *
     * @return PluginDetails object
     *
     */
    public function getPluginDetailsObject() {
        $plugin_details = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\PluginDetails();
        $plugin_details->addApplicationName('OpenCart');
        $plugin_details->addApplicationVersion(self::OC_VERSION);
        $plugin_details->addPluginVersion($this->getPluginVersion());
        $plugin_details->addShopRootUrl($this->getShopUrl());
        return $plugin_details;
    }

    /**
     * Returns a PaymentOptions object used to build the order request object
     *
     * @return PaymentOptions object
     *
     */
    public function getPaymentOptionsObject() {
        $payment_options_details = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\PaymentOptions();
        $payment_options_details->addNotificationMethod('GET');
        $payment_options_details->addNotificationUrl($this->url->link(self::ROUTE . '/callback', '', 'SSL'));
        $payment_options_details->addRedirectUrl($this->url->link('checkout/success', '', 'SSL'));
        $payment_options_details->addCancelUrl($this->url->link('checkout/checkout', '', 'SSL'));
        return $payment_options_details;
    }

    /**
     * Returns a Description object used to build the order request object
     *
     * @param int $order_id
     * @return Description object
     *
     */
    public function getOrderDescriptionObject($order_id) {
        $this->load->language(self::ROUTE);
        $description = sprintf($this->language->get('text_order_description'), $order_id, $this->config->get('config_name'), date($this->language->get('datetime_format')) );
        $description_details = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\Description();
        $description_details->addDescription($description);
        return $description_details;
    }

    /**
     * Returns GatewayInfoInterface object to be used in OrderRequest transaction
     *
     * @return mixed boolean|GatewayInfoInterface object
     *
     */
    public function getGatewayInfoInterfaceObject($data) {

        if (!isset($data['gateway_info']) && empty($data['gateway_info'])) {
            return false;
        }

        switch ($data['gateway_info']) {
            case "Ideal":
                if (!isset($data['issuer_id']) && !empty($data['issuer_id'])) {
                    return false;
                }
                $gateway_info = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Ideal();
                $gateway_info->addIssuerId($data['issuer_id']);
                break;
            case "QrCode":
                $gateway_info = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\QrCode();
                $gateway_info->addQrSize(250);
                $gateway_info->addAllowChangeAmount(false);
                $gateway_info->addAllowMultiple(false);
                break;
            case "Account":
                $gateway_info = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Account();
                $iban =  $this->getIbanObject($data['account_holder_iban']);
                $gateway_info->addAccountHolderName($data['account_holder_name']);
                $gateway_info->addAccountId($iban);
                $gateway_info->addAccountHolderIban($iban);
                $gateway_info->addEmanDate($data['emandate']);
                break;
            case "Meta":
                $order_info = $this->getOrderInfo($data['order_id']);
                $telephone = $this->getTelephoneObject($order_info['telephone']);
                $email_address =  $this->getEmailAddressObject($order_info['email']);
                $gateway_info = new \MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Meta();
                $gateway_info->addPhone($telephone);
                $gateway_info->addEmailAddress($email_address);
                if(isset($data['gender']) && !empty($data['gender'])) {
                    $gender =  $this->getGenderObject($data['gender']);
                    $gateway_info->addGender($gender);
                }
                if(isset($data['birthday']) && !empty($data['birthday'])) {
                    $birthday =  $this->getDateObject($data['birthday']);
                    $gateway_info->addBirthday($birthday);
                }
                if(isset($data['bankaccount']) && !empty($data['bankaccount'])) {
                    $bank_account =  $this->getBankAccountObject($data['bankaccount']);
                    $gateway_info->addBankAccount($bank_account);
                }
                break;
        }

        return $gateway_info;

    }

    /**
     * Returns a CartItem object used to build the order request object
     *
     * @param float $amount
     * @param string $currency_code
     * @param float $currency_value
     * @param bool $is_negative
     * @param string $name
     * @param int $quantity
     * @param string $merchant_item_id
     * @param string $tax_table_selector
     * @param string $description
     * @param string $weight_unit
     * @param float $weight_value
     * @return CartItem object
     *
     */
    private function getCartItemObject($price, $order_info, $name, $quantity, $merchant_item_id,
        $tax_rate, $description = '', $weight_unit = false, $weight_value = false) {
        $unit_price = $this->getMoneyObject($price, $order_info['currency_code'], $order_info['currency_value']);
        $cart_item = new \MultiSafepay\ValueObject\CartItem();
        $cart_item->addName($name);
        $cart_item->addUnitPrice($unit_price);
        $cart_item->addQuantity((int)$quantity);
        $cart_item->addMerchantItemId($merchant_item_id);
        $cart_item->addTaxRate((float)$tax_rate);
        $cart_item->addDescription($description);
        if ($weight_unit && $weight_value) {
            $cart_item_weight = $this->getWeightObject($weight_unit, (float)$weight_value);
            $cart_item->addWeight($cart_item_weight);
        }
        return $cart_item;
    }

    /**
     * Returns a negative CartItem object used to build the order request object
     *
     * @param float $amount
     * @param string $currency_code
     * @param float $currency_value
     * @param bool $is_negative
     * @param string $name
     * @param int $quantity
     * @param string $merchant_item_id
     * @param string $tax_table_selector
     * @param string $description
     * @param string $weight_unit
     * @param float $weight_value
     * @return CartItem object
     *
     */
    private function getNegativeCartItemObject($price, $order_info, $name, $quantity, $merchant_item_id,
        $tax_rate, $description = '', $weight_unit = false, $weight_value = false) {

        $unit_price = $this->getMoneyObject($price, $order_info['currency_code'], $order_info['currency_value']);
        $unit_price = $unit_price->negative();

        $cart_item = new \MultiSafepay\ValueObject\CartItem();
        $cart_item->addName($name);
        $cart_item->addUnitPrice($unit_price);
        $cart_item->addQuantity($quantity);
        $cart_item->addMerchantItemId($merchant_item_id);
        $cart_item->addTaxRate((float)$tax_rate);
        $cart_item->addDescription($description);
        if ($weight_unit && $weight_value) {
            $cart_item_weight = $this->getWeightObject($weight_unit, $weight_value);
            $cart_item->addWeight($cart_item_weight);
        }
        return $cart_item;
    }

    /**
     * Returns a Weight object used to build the order request object
     *
     * @param string $weight_unit
     * @param float $weight_value
     * @return Weight object
     *
     */
    private function getWeightObject($weight_unit, $weight_value) {
        $cart_item_weight = new \MultiSafepay\ValueObject\Weight(strtoupper($weight_unit), $weight_value);
        return $cart_item_weight;
    }

    /**
     * Returns an amount convert into another currency
     *
     * @param float $number
     * @param string $currency
     * @param float $value
     * @return Money object
     *
     */
    public function formatByCurrency($number, $currency, $value = '') {

        $this->load->model('localisation/currency');

        $currencies = $this->model_localisation_currency->getCurrencies();

        $decimal_place = 10;

        if (!$value) {
            $value = $currencies[$currency]['value'];
        }

        $amount = ($value) ? (float)$number * $value : (float)$number;

        $amount = round($amount, (int)$decimal_place);

        return $amount;

    }

    /**
     * Returns a Money object used to build the order request object addMoney method
     *
     * @param float $amount
     * @param string $currency_code
     * @param float $currency_value
     * @return Money object
     *
     */
    public function getMoneyObjectOrderAmount($amount, $currency_code, $currency_value) {
        $amount = $this->formatByCurrency($amount, $currency_code, $currency_value);
        $amount =  round(($amount * 100));
        $amount = new  \MultiSafepay\ValueObject\Money($amount, $currency_code);
        return $amount;
    }

    /**
     * Returns a Money object used to build the order request object in shopping cart unit prices
     *
     * @param float $amount
     * @param string $currency_code
     * @param float $currency_value
     * @return Money object
     *
     */
    public function getMoneyObject($amount, $currency_code, $currency_value) {
        $amount =  round(($amount * 100), 10);
        $amount = $this->formatByCurrency($amount, $currency_code, $currency_value);
        $amount = new  \MultiSafepay\ValueObject\Money($amount, $currency_code);
        return $amount;
    }

    /**
     * Returns a Country object
     *
     * @param string $country_code
     * @return Country object
     *
     */
    private function getCountryObject($country_code) {
        $country = new \MultiSafepay\ValueObject\Customer\Country($country_code);
        return $country;
    }

    /**
     * Returns an Order object
     *
     * @param int $order_id
     * @return Order object
     *
     */
    public function getOrderObject($order_id) {
        $sdk = $this->multisafepay->getSdkObject();
        $transaction_manager = $sdk->getTransactionManager();
        try {
            $order = $transaction_manager->get($order_id);
        }
        catch (\MultiSafepay\Exception\ApiException $apiException ) {
            return false;
        }
        return $order;
    }

    /**
     * Returns bool after validates IBAN format
     *
     * @return bool
     *
     */
    public function validateIban($iban) {
        require_once(DIR_SYSTEM . 'library/multisafepay/vendor/autoload.php');
        try {
            $iban = new \MultiSafepay\ValueObject\IbanNumber($iban);
            return true;
        }
        catch (\MultiSafepay\Exception\InvalidArgumentException $invalidArgumentException ) {
            return false;
        }
    }

    /**
     * Set the MultiSafepay order status as shipped or cancelled.
     *
     * @param int $order_id
     * @param string $status allowed values are shipped and cancelled
     * @return Order object
     *
     */
    public function changeMultiSafepayOrderStatusTo($order_id, $status) {
        $sdk = $this->getSdkObject();
        $transaction_manager = $sdk->getTransactionManager();
        $update_order = new MultiSafepay\Api\Transactions\UpdateRequest();
        $update_order->addId($order_id);
        $update_order->addStatus($status);

        try {
            $transaction_manager->update($order_id, $update_order);
        }
        catch (\MultiSafepay\Exception\ApiException $apiException ) {
            die($apiException->getMessage());
        }

    }

    /**
     * Returns an array with additional information of the customer, to be used as additional
     * customer information in the order transaction
     *
     * @return array
     *
     */
    private function getAdditionalCustomerData() {
        if (!$this->customer->isLogged()) {
            $customer_additional_data = array(
                'customer_id' => 0,
                'customer_group_id' => $this->config->get('config_customer_group_id'),
                'customer_balance' => 0,
                'customer_reward_points' => 0,
            );
            return $customer_additional_data;
        }

        $customer_additional_data = array(
            'customer_id' => $this->customer->getId(),
            'customer_group_id' => $this->customer->getGroupId(),
            'customer_balance' => $this->customer->getBalance(),
            'customer_reward_points' => $this->customer->getRewardPoints(),
        );
        return $customer_additional_data;

    }

    /**
     * Returns the language code required by MultiSafepay.
     * Language code concatenated with the country code. Format: ab_CD.
     *
     * @return string
     *
     */
    private function getLocale() {
        $this->load->model('localisation/language');
        $language = $this->model_localisation_language->getLanguage($this->config->get('config_language_id'));

        if ((strlen($language['code']) !== 5 && strlen($language['code']) !== 2)) {
            return 'en_US';
        }

        if (strlen($language['code']) == 5) {
            $locale_strings = explode('-', $language['code']);
            $locale = $locale_strings[0] . '_' . strtoupper($locale_strings[1]);
        }

        if (strlen($language['code']) == 2) {
            $locale = $language['code'] . '_' . strtoupper($language['code']);
        }

        return $locale;
    }

    /**
     * Returns the shop url according with the selected protocol
     *
     * @return string
     *
     */
    public function getShopUrl() {
        if (isset($this->request->server['HTTPS']) && (($this->request->server['HTTPS'] == 'on') || ($this->request->server['HTTPS'] == '1'))) {
            return $this->config->get('config_ssl');
        }
        return  $this->config->get('config_url');
    }

    /**
     * Returns a unique product ID, formed with the product id concatenated with the id
     * of the products options, selected in the order.
     *
     * @param int $order_id The order id.
     * @param array $product The product from order information.
     * @return string
     *
     */
    private function getUniqueProductId($order_id, $product) {
        $unique_product_id = $product['product_id'];

        $option_data = $this->getProductOptionsData($order_id, $product);

        if (!empty($option_data)) {
            foreach($option_data as $option) {
                $unique_product_id .= '-' .  $option['product_option_id'];
            }
        }

        return (string)$unique_product_id;
    }

    /**
     * Returns product's name, according with order information,
     * including quantity and options selected.
     *
     * @param int $order_id The order id.
     * @param array $product The product from order information.
     * @return string
     *
     */
    private function getProductName($order_id, $product) {
        $option_data = $this->getProductOptionsData($order_id, $product);

        if (empty($option_data)) {
            return $product['quantity'] . ' x ' . $product['name'];
        }

        $option_output = '';

        foreach($option_data as $option) {
            $option_output .= $option['name'] . ': ' . $option['value'] . ', ';
        }
        $option_output = ' (' . substr($option_output, 0, -2) . ')';
        $product_name = $product['quantity'] . ' x ' . $product['name'] . $option_output;

        return $product_name;
    }

    /**
     * Returns product's options selected in the order
     *
     * @param int $order_id The order id.
     * @param array $product The product from order information.
     * @return array
     *
     */
    private function getProductOptionsData($order_id, $product) {
        $this->load->model('checkout/order');

        $option_data = array();

        $options = $this->model_checkout_order->getOrderOptions($order_id, $product['product_id']);

        foreach ($options as $option) {
            if ($option['type'] !== 'file') {
                $option_data[] = $this->extractOptionsData($option);
            }
            if ($option['type'] === 'file') {
                $option_data[] = $this->extractOptionsFileData($option);
            }
        }
        return $option_data;
    }

    /**
     * Extract product's options data from options array
     *
     * @param array $option
     * @return array
     *
     */
    private function extractOptionsData($option) {
        $option_data = array(
            'name'               => $option['name'],
            'value'              => $option['value'],
            'product_option_id'  => $option['product_option_id'],
            'order_option_id'    => $option['order_option_id']
        );
        return $option_data;
    }

    /**
     * Extract product's options data file from options array
     *
     * @param array $option
     * @return array
     *
     */
    private function extractOptionsFileData($option) {
        $this->load->model('tool/upload');
        $upload_info = $this->model_tool_upload->getUploadByCode($option['value']);
        if ($upload_info) {
            $option_data = array(
                'name'               => $option['name'],
                'value'              => $upload_info['name'],
                'product_option_id'  => $option['product_option_id'],
                'order_option_id'    => $option['order_option_id']
            );
        }
        return $option_data;
    }

    /**
     * Extract fixed rates from taxes that might be related to handling and low order fee total modules,
     * used as helper in the function getFixedTaxesItems
     *
     * @param array $product
     * @param int $quantity
     * @param array $fixed_taxes_items
     * @return array $fixed_taxes_items
     *
     */
    private function extractFixedTaxesRatesFromProducts($oc_tax_rate, $quantity, $fixed_taxes_items) {
        if ($oc_tax_rate['type'] == self::FIXED_TYPE) {
            for ($i = 1; $i <= $quantity; $i++) {
                $fixed_taxes_items[] = $oc_tax_rate;
            }
        }
        return $fixed_taxes_items;
    }

    /**
     * Extract fixed rates from taxes that might be related to handling and low order fee total modules,
     * used as helper in the function getFixedTaxesItems
     *
     * @param array $order_totals
     * @param array $fixed_taxes_items
     * @param string $key
     * @param string $type
     * @return array $fixed_taxes_items
     *
     */
    private function extractFixedTaxesFromHandlingLowOrderFee($order_totals, $fixed_taxes_items, $key, $type) {
        $tax_class_id  = $this->config->get('total_' . $type . '_tax_class_id');
        $is_order_lower_than_taxes = $this->isSortOrderLowerThanTaxes($this->config->get('total_' . $type . '_sort_order'));
        if ($tax_class_id && $is_order_lower_than_taxes) {
            $fixed_taxes_items = $this->addToArrayOfFixedTaxes($order_totals[$key]['value'], $tax_class_id, $fixed_taxes_items);
        }
        return $fixed_taxes_items;

    }

    /**
     * Returns an array with fixed taxes; which will becomes in cart items.
     *
     * @param int $order_id
     * @return array $fixed_taxes_items
     *
     */
    private function getFixedTaxesItems($order_id) {
        $order_totals = $this->getOrderTotals($order_id);
        $order_info = $this->getOrderInfo($order_id);
        $order_products = $this->getOrderProducts($order_id);
        $coupon_info = $this->getCouponInfo($order_id);

        $has_handling = array_search('handling', array_column($order_totals, 'code'));
        $has_low_order_fee = array_search('low_order_fee', array_column($order_totals, 'code'));
        $has_shipping = array_search('shipping', array_column($order_totals, 'code'));
        $has_coupons = array_search('coupon', array_column($order_totals, 'code'));

        $fixed_taxes_items = array();

        foreach ($order_products as $product) {
            $product_info = $this->getProductInfo($product['product_id']);
            $oc_tax_rates = $this->tax->getRates($product['price'], $product_info['tax_class_id']);
            foreach ($oc_tax_rates as $oc_tax_rate) {
                $fixed_taxes_items = $this->extractFixedTaxesRatesFromProducts($oc_tax_rate, $product['quantity'], $fixed_taxes_items);
            }
        }

        if ($has_shipping) {
            $shipping_tax_class_id = $this->getShippingTaxClassId($order_info['shipping_code']);
            if ($shipping_tax_class_id) {
                    $fixed_taxes_items = $this->addToArrayOfFixedTaxes($order_totals[$has_shipping]['value'], $shipping_tax_class_id, $fixed_taxes_items);
            }
        }

        if ($has_handling) {
            $fixed_taxes_items = $this->extractFixedTaxesFromHandlingLowOrderFee($order_totals, $fixed_taxes_items, $has_handling, 'handling');
        }

        if ($has_low_order_fee) {
            $fixed_taxes_items = $this->extractFixedTaxesFromHandlingLowOrderFee($product, $fixed_taxes_items, $has_low_order_fee, 'low_order_fee');
        }

        if (empty($fixed_taxes_items)) {
            return false;
        }

        if (!empty($fixed_taxes_items)) {
            $shopping_cart_items = array();
            // If there are more than once with the same id; must be grouped, then counted
            foreach ($fixed_taxes_items as $fixed_taxes_item) {
                $fixed_taxes_items_ungrouped[$fixed_taxes_item['tax_rate_id']][] = $fixed_taxes_item;
            }
            foreach ($fixed_taxes_items_ungrouped as $fixed_taxes_item) {
                $fixed_taxes_item_quantity = count($fixed_taxes_item);
                $shopping_cart_item = $this->getCartItemObject(
                    $fixed_taxes_item[0]['amount'],
                    $order_info,
                    sprintf($this->language->get('text_fixed_product_name'), $fixed_taxes_item[0]['name']),
                    $fixed_taxes_item_quantity,
                    'TAX-' . $fixed_taxes_item[0]['tax_rate_id'],
                    0
                );
                $shopping_cart_items[] = $shopping_cart_item;
            }
            return $shopping_cart_items;
        }
    }

    /**
     * Search into the array of tax rates that belongs to shipping, handling or low order fees
     * an add the rates found to an array, to be return an added to the transaction as items.
     *
     * @param float $total
     * @param int $tax_class_id
     * @param array $array_taxes
     * @return array
     *
     */
    private function addToArrayOfFixedTaxes($total, $tax_class_id, $array_taxes) {
        $rate = $this->tax->getRates($total, $tax_class_id);
        foreach ($rate as $oc_tax_rate) {
            if ($oc_tax_rate['type'] == self::FIXED_TYPE) {
                $array_taxes[] = $oc_tax_rate;
            }
        }
        return $array_taxes;
    }

    /**
     * Returns the shipping tax class id if exist from the order shipping code.
     *
     * @param string $shipping_code
     * @return mixed boolean|int
     *
     */
    private function getShippingTaxClassId($shipping_code) {
        $shipping_code = explode('.', $shipping_code);
        $shipping_tax_class_id_key = 'shipping_' . $shipping_code['0'] . '_tax_class_id';
        $shipping_tax_class_id = $this->config->get($shipping_tax_class_id_key);
        return $shipping_tax_class_id;
    }

    /**
     * Returns order totals information.
     *
     * @param int $order_id
     * @return array $order_totals
     *
     */
    public function getOrderTotals($order_id) {
        $this->load->model('checkout/order');
        $order_totals = $this->model_checkout_order->getOrderTotals($order_id);
        return $order_totals;
    }

    /**
     * Returns order information.
     *
     * @param int $order_id
     * @return array $order_info
     *
     */
    public function getOrderInfo($order_id) {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        return $order_info;
    }

    /**
     * Returns product information.
     *
     * @param int $product_id
     * @return array $product_info
     *
     */
    private function getProductInfo($product_id) {
        $this->load->model('catalog/product');
        $product_info = $this->model_catalog_product->getProduct($product_id);
        return $product_info;
    }

    /**
     * Returns order`s products.
     *
     * @param int $order_id
     * @return array $order_products
     *
     */
    public function getOrderProducts($order_id) {
        $this->load->model('checkout/order');
        $order_products = $this->model_checkout_order->getOrderProducts($order_id);
        return $order_products;
    }

    /**
     * Returns coupon info if exist, or false.
     *
     * @param int $order_id
     * @return mixed false|$coupon_info
     *
     */
    private function getCouponInfo($order_id) {
        $order_totals = $this->getOrderTotals($order_id);
        $has_coupons = array_search('coupon', array_column($order_totals, 'code'));
        if (!$has_coupons) {
            return false;
        }
        $this->load->model('extension/total/coupon');
        $coupon_info = $this->model_extension_total_coupon->getCoupon($this->session->data['coupon']);
        $coupon_info['is_order_lower_than_taxes'] = $this->isSortOrderLowerThanTaxes($this->config->get('total_coupon_sort_order'));
        return $coupon_info;
    }

    /**
     * Returns shipping method info if exist, or false.
     *
     * @param int $order_id
     * @return mixed false|$coupon_info
     *
     */
    private function getShippingInfo($order_id) {
        $order_totals = $this->getOrderTotals($order_id);
        $has_shipping = array_search('shipping', array_column($order_totals, 'code'));
        if (!$has_shipping) {
            return false;
        }
        $order_info = $this->getOrderInfo($order_id);
        $shipping_tax_class_id = $this->getShippingTaxClassId($order_info['shipping_code']);
        $tax_rate = $this->getItemTaxRate($order_totals[$has_shipping]['value'], $shipping_tax_class_id);
        $shipping_info = array(
            'value' => $order_totals[$has_shipping]['value'],
            'title' => $order_totals[$has_shipping]['title'],
            'tax_rate' => $tax_rate
        );
        return $shipping_info;
    }

    /**
     * Returns CartItem object with shipping information.
     *
     * @param int $order_id
     * @return CartItem object
     *
     */
    private function getShippingItem($order_id) {
        $this->load->language(self::ROUTE);
        $order_info = $this->getOrderInfo($order_id);
        $coupon_info = $this->getCouponInfo($order_id);
        $shipping_info = $this->getShippingInfo($order_id);

        if (($coupon_info && $coupon_info['shipping'])) {
            return $this->getCartItemObject(
                0,
                $order_info,
                sprintf($this->language->get('text_coupon_applied_to_shipping'), $shipping_info['title'], $coupon_info['name']),
                1,
                'msp-shipping',
                0
            );
        }
        
        if ((!$coupon_info) || ($coupon_info && !$coupon_info['shipping'])) {
            return $this->getCartItemObject(
                $shipping_info['value'],
                $order_info,
                $shipping_info['title'],
                1,
                'msp-shipping',
                $shipping_info['tax_rate']
            );
        }
    }

    /**
     * Returns CartItem object with product information.
     *
     * @param array $product
     * @param int $order_id
     * @return CartItem object
     *
     */
    private function getCartItem($product, $order_id) {
        $this->load->language(self::ROUTE);
        $order_info = $this->getOrderInfo($order_id);
        $product_info =  $this->getProductInfo($product['product_id']);
        $product_name = $this->getProductName($order_id, $product);
        $product_price = $product['price'];
        $product_description = '';
        $merchant_item_id = $this->getUniqueProductId($order_id, $product);
        $tax_rate = $this->getItemTaxRate($product['price'], $product_info['tax_class_id']);

        $reward_info = $this->getRewardInfo($order_id);
        $coupon_info = $this->getCouponInfo($order_id);

        if($reward_info) {
            $discount_by_product = $this->getRewardPointsDiscountByProduct($order_id);
            if(isset($discount_by_product[$product['product_id']]['discount_per_product'])) {
                $product_price -= $discount_by_product[$product['product_id']]['discount_per_product'];
                $discount =  $this->currency->format($discount_by_product[$product['product_id']]['discount_per_products'], $order_info['currency_code'], $order_info['currency_value'], true);
                $product_name .= sprintf($this->language->get('text_reward_applied'), $discount, strtolower($reward_info['title']));
                $product_description .= sprintf($this->language->get('text_reward_applied'), $discount, strtolower($reward_info['title']));
            }
        }

        // Coupons apply just to a few items in the order.
        if ($coupon_info
            && $coupon_info['type'] == self::PERCENTAGE_TYPE
            && $coupon_info['is_order_lower_than_taxes']
            && !empty($coupon_info['product'])
            && in_array($product['product_id'], $coupon_info['product'])) {
            $product_price -= ($product['price'] * ($coupon_info['discount'] / 100));
            // If coupon is just for free shipping, the name and description is not modified
            if ($coupon_info['discount'] > 0) {
                $product_name .= ' - '.sprintf($this->language->get('text_coupon_applied'), $coupon_info['name']);
                $product_description .= sprintf(
                    $this->language->get('text_price_before_coupon'),
                    $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value'], true),
                    $coupon_info['name']
                );
            }
        }

        // Coupons apply for all items in the order.
        if ($coupon_info && $coupon_info['type'] == self::PERCENTAGE_TYPE && $coupon_info['is_order_lower_than_taxes'] && empty($coupon_info['product'])) {
            $product_price -= ($product['price'] * round(($coupon_info['discount']/100), 2));
            // If coupon is just for free shipping, the name and description is not modified
            if ($coupon_info['discount'] > 0) {
                $product_name .= ' - ' . sprintf($this->language->get('text_coupon_applied'),
                        $coupon_info['name']);
                $product_description .= sprintf($this->language->get('text_price_before_coupon'),
                    $this->currency->format($product['price'], $order_info['currency_code'],
                        $order_info['currency_value'], true), $coupon_info['name']);
            }
        }

        $shopping_cart_item = $this->getCartItemObject(
            $product_price,
            $order_info,
            $product_name,
            $product['quantity'],
            $merchant_item_id,
            $tax_rate,
            $product_description,
            $this->weight->getUnit($product_info['weight_class_id']),
            $product_info['weight']
        );

        return $shopping_cart_item;
    }

    /**
     * Returns CartItem object with product information.
     *
     * @param int $order_id
     * @return CartItem object
     *
     */
    private function getCouponItem($order_id) {
        $coupon_info = $this->getCouponInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);

        if ((!$coupon_info) || ($coupon_info['type'] !== self::FIXED_TYPE) || ($coupon_info['type'] == self::FIXED_TYPE && $coupon_info['discount'] > 0) || ($coupon_info['is_order_lower_than_taxes']) ) {
            return false;
        }

        return $this->getCartItemObject(
            $coupon_info['value'],
            $order_info,
            $coupon_info['name'],
            1,
            'COUPON',
            0
        );
    }

    /**
     * Returns handling fee information if exist, or false.
     *
     * @param int $order_id
     * @return mixed false|array
     *
     */
    private function getHandlingFeeInfo($order_id) {
        $order_totals = $this->getOrderTotals($order_id);
        $has_handling_fee = array_search('handling', array_column($order_totals, 'code'));
        if (!$has_handling_fee) {
            return false;
        }

        $handling_tax_class_id  = $this->config->get('total_handling_tax_class_id');
        $tax_rate = $this->getItemTaxRate($order_totals[$has_handling_fee]['value'], $handling_tax_class_id);
        $handling_fee_info = array(
            'value' => $order_totals[$has_handling_fee]['value'],
            'title' => $order_totals[$has_handling_fee]['title'],
            'is_order_lower_than_taxes' => $this->isSortOrderLowerThanTaxes($this->config->get('total_handling_sort_order')),
            'tax_rate' => $tax_rate
        );
        return $handling_fee_info;
    }

    /**
     * Returns CartItem object with handling fee information.
     *
     * @param int $order_id
     * @return CartItem object
     *
     */
    private function getHandlingFeeItem($order_id) {
        $handling_fee_info = $this->getHandlingFeeInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);
        if (!$handling_fee_info) {
            return false;
        }

        return $this->getCartItemObject(
            $handling_fee_info['value'],
            $order_info,
            $handling_fee_info['title'],
            1,
            'HANDLING',
            $handling_fee_info['tax_rate']
        );
    }

    /**
     * Returns low order fee information.
     *
     * @param int $order_id
     * @return array $low_order_fee_info
     *
     */
    private function getLowOrderFeeInfo($order_id) {
        $order_totals = $this->getOrderTotals($order_id);
        $has_low_order_fee = array_search('low_order_fee', array_column($order_totals, 'code'));
        if (!$has_low_order_fee) {
            return false;
        }

        $low_order_fee_tax_class_id  = $this->config->get('total_low_order_fee_tax_class_id');
        $tax_rate = $this->getItemTaxRate($order_totals[$has_low_order_fee]['value'], $low_order_fee_tax_class_id);
        $low_order_fee_info = array(
            'value' => $order_totals[$has_low_order_fee]['value'],
            'title' => $order_totals[$has_low_order_fee]['title'],
            'is_order_lower_than_taxes' => $this->isSortOrderLowerThanTaxes($this->config->get('total_low_order_fee_sort_order')),
            'tax_rate' => $tax_rate
        );
        return $low_order_fee_info;
    }

    /**
     * Returns CartItem object with low order fee.
     *
     * @param int $order_id
     * @return CartItem object
     *
     */
    private function getLowOrderFeeItem($order_id) {
        $low_order_fee_info = $this->getLowOrderFeeInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);
        if ($low_order_fee_info) {
            return $this->getCartItemObject(
                $low_order_fee_info['value'],
                $order_info,
                $low_order_fee_info['title'],
                1,
                'LOWORDERFEE',
                $low_order_fee_info['tax_rate']
            );
        }
    }

    /**
     * Returns reward info if exist, or false.
     *
     * @param int $order_id
     * @return array $reward_info
     */
    private function getRewardInfo($order_id) {
        $order_totals = $this->getOrderTotals($order_id);
        $has_reward = array_search('reward', array_column($order_totals, 'code'));
        if (!$has_reward) {
            return false;
        }

        $reward_info = array(
            'value' => $order_totals[$has_reward]['value'],
            'title' => $order_totals[$has_reward]['title']
        );
        return $reward_info;
    }

    /**
     * Returns reward discount by product id.
     *
     * @param int $order_id
     * @return array $discounts
     *
     */
    private function getRewardPointsDiscountByProduct($order_id) {
        $order_products = $this->getOrderProducts($order_id);
        $points_total = 0;

        foreach ($order_products as $product) {
            $product_info = $this->getProductInfo($product['product_id']);
            if ($product_info['points']) {
                $points_total += ($product_info['points'] * $product['quantity']);
            }
        }

        $discounts = array();
        foreach ($order_products as $product) {
            $product_info = $this->getProductInfo($product['product_id']);
            if ($product_info['points']) {
                $discount_per_products = $product['total'] * ($this->session->data['reward'] / $points_total);
                $discount_per_product = $discount_per_products / $product['quantity'];
                $discounts[$product['product_id']]['discount_per_product'] = $discount_per_product;
                $discounts[$product['product_id']]['discount_per_products'] = $discount_per_products;
            }
        }
        return $discounts;
    }

    /**
     * Returns CartItem object with customer balance.
     *
     * @param int $order_id
     * @return CartItem object
     *
     */
    private function getCustomerBalanceItem($order_id) {
        $this->load->language(self::ROUTE);
        $customer_additional_data = $this->getAdditionalCustomerData();
        $order_info = $this->getOrderInfo($order_id);
        return $this->getNegativeCartItemObject(
            $customer_additional_data['customer_balance'],
            $order_info,
            $this->language->get('text_customer_balance'),
            1,
            'CREDIT',
            0
        );
    }

    /**
     * Returns voucher information if exist, or false.
     *
     * @param int $order_id
     * @return mixed false|array
     *
     */
    private function getVoucherInfo($order_id) {
        $order_totals = $this->getOrderTotals($order_id);
        $has_voucher = array_search('voucher', array_column($order_totals, 'code'));
        if (!$has_voucher) {
            return false;
        }

        $voucher_info = array(
            'value' => $order_totals[$has_voucher]['value'],
            'title' => $order_totals[$has_voucher]['title']
        );
        return $voucher_info;
    }

    /**
     * Returns CartItem object with voucher.
     *
     * @param int $order_id
     * @return CartItem object
     *
     */
    private function getVoucherItem($order_id) {
        $voucher_info = $this->getVoucherInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);
        if ($voucher_info) {
            return $this->getNegativeCartItemObject(
                $voucher_info['value'],
                $order_info,
                $voucher_info['title'],
                1,
                'VOUCHER',
                0
            );
        }
    }

    /**
     * Returns cart items reordered.
     *
     * @param array $shopping_cart_items
     * @return array $cart_items
     *
     */
    private function reOrderShoppingCartItems($shopping_cart_items) {
        ksort($shopping_cart_items);
        $cart_items = array();
        foreach ($shopping_cart_items as $key => $value) {
            foreach ($value as $item) {
                $cart_items[] = $item;
            }
        }
        return $cart_items;
    }

    /**
     * Return all gateways
     *
     * @return array $gateways
     *
     */
    public function getGateways() {
        $this->language->load(self::ROUTE);
        $this->load->model('setting/setting');
        $gateways = array(
            array(
                'id' => 'MULTISAFEPAY',
                'code' => 'multisafepay',
                'route' => 'multisafepay',
                'description' => $this->language->get('text_title_multisafepay'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_multisafepay'), 'https://docs.multisafepay.com/'),
                'image' => 'multisafepay'
            ),
            array(
                'id' => 'AFTERPAY',
                'code' => 'afterpay',
                'route' => 'multisafepay/afterPay',
                'description' => $this->language->get('text_title_afterpay'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_afterpay'), 'https://docs.multisafepay.com/payment-methods/billing-suite/afterpay/'),
                'image' => 'afterpay'
            ),
            array(
                'id' => 'ALIPAY',
                'code' => 'alipay',
                'route' => 'multisafepay/alipay',
                'description' => $this->language->get('text_title_alipay'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_alipay'), 'https://docs.multisafepay.com/payment-methods/wallet/alipay/'),
                'image' => 'alipay'
            ),
            array(
                'id' => 'AMEX',
                'code' => 'amex',
                'route' => 'multisafepay/amex',
                'description' => $this->language->get('text_title_american_express'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_american_express'), 'https://docs.multisafepay.com/payment-methods/credit-and-debit-cards/american-express/'),
                'image' => 'amex'
            ),
            array(
                'id' => 'APPLEPAY',
                'code' => 'applepay',
                'route' => 'multisafepay/applePay',
                'description' => $this->language->get('text_title_apple_pay'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_apple_pay'), 'https://docs.multisafepay.com/payment-methods/wallet/applepay/'),
                'image' => 'applepay'
            ),
            array(
                'id' => 'MISTERCASH',
                'code' => 'mistercash',
                'route' => 'multisafepay/bancontact',
                'description' => $this->language->get('text_title_bancontact'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_bancontact'), 'https://docs.multisafepay.com/payment-methods/banks/bancontact/'),
                'image' => 'bancontact'
            ),
            array(
                'id' => 'BABYCAD',
                'code' => 'babycad',
                'route' => 'multisafepay/babyCad',
                'description' => $this->language->get('text_title_baby_cad'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_baby_cad'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'babycad'
            ),
            array(
                'id' => 'BANKTRANS',
                'code' => 'banktrans',
                'route' => 'multisafepay/bankTransfer',
                'description' => $this->language->get('text_title_bank_transfer'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_bank_transfer'), 'https://docs.multisafepay.com/payment-methods/banks/bank-transfer/'),
                'image' => 'banktrans'
            ),
            array(
                'id' => 'BEAUTYWELL',
                'code' => 'beautywellness',
                'route' => 'multisafepay/beautyWellness',
                'description' => $this->language->get('text_title_beauty_wellness'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_beauty_wellness'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'beautywellness'
            ),
            array(
                'id' => 'BELFIUS',
                'code' => 'belfius',
                'route' => 'multisafepay/belfius',
                'description' => $this->language->get('text_title_belfius'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_belfius'), 'https://docs.multisafepay.com/payment-methods/banks/belfius/'),
                'image' => 'belfius'
            ),
            array(
                'id' => 'BOEKENBON',
                'code' => 'boekenbon',
                'route' => 'multisafepay/boekenbon',
                'description' => $this->language->get('text_title_boekenbon'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_boekenbon'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'boekenbon'
            ),
            array(
                'id' => 'CBC',
                'code' => 'cbc',
                'route' => 'multisafepay/cbc',
                'description' => $this->language->get('text_title_cbc'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_cbc'), 'https://docs.multisafepay.com/payment-methods/banks/cbc/'),
                'image' => 'cbc'
            ),
            array(
                'id' => 'CREDITCARD',
                'code' => 'creditcard',
                'route' => 'multisafepay/creditCard',
                'description' => $this->language->get('text_title_credit_card'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_credit_card'), 'https://docs.multisafepay.com/payment-methods/credit-and-debit-cards/'),
                'image' => 'creditcard'
            ),
            array(
                'id' => 'DBRTP',
                'code' => 'dbrtp',
                'route' => 'multisafepay/dbrtp',
                'description' => $this->language->get('text_title_dbrtp'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_dbrtp'), 'https://docs.multisafepay.com/payment-methods/banks/direct-bank-transfer/'),
                'image' => 'dbrtp'
            ),
            array(
                'id' => 'DIRECTBANK',
                'code' => 'directbank',
                'route' => 'multisafepay/directBank',
                'description' => $this->language->get('text_title_direct_bank'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_direct_bank'), 'https://docs.multisafepay.com/payment-methods/banks/sofort-banking/'),
                'image' => 'directbank'
            ),
            array(
                'id' => 'DOTPAY',
                'code' => 'dotpay',
                'route' => 'multisafepay/dotpay',
                'description' => $this->language->get('text_title_dotpay'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_dotpay'), 'https://docs.multisafepay.com/payment-methods/banks/dotpay/'),
                'image' => 'Dotpay'
            ),
            array(
                'id' => 'EPS',
                'code' => 'eps',
                'route' => 'multisafepay/eps',
                'description' => $this->language->get('text_title_eps'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_eps'), 'https://docs.multisafepay.com/payment-methods/banks/eps/'),
                'image' => 'eps'
            ),
            array(
                'id' => 'EINVOICE',
                'code' => 'einvoice',
                'route' => 'multisafepay/eInvoice',
                'description' => $this->language->get('text_title_e_invoicing'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_e_invoicing'), 'https://docs.multisafepay.com/payment-methods/billing-suite/e-invoicing/'),
                'image' => 'einvoice'
            ),
            array(
                'id' => 'FASHIONCHQ',
                'code' => 'fashioncheque',
                'route' => 'multisafepay/fashionCheque',
                'description' => $this->language->get('text_title_fashion_cheque'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_fashion_cheque'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'fashioncheque'
            ),
            array(
                'id' => 'FASHIONGFT',
                'code' => 'fashiongiftcard',
                'route' => 'multisafepay/fashionGiftCard',
                'description' => $this->language->get('text_title_fashion_gift_card'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_fashion_gift_card'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'fashiongiftcard'
            ),
            array(
                'id' => 'FIETSENBON',
                'code' => 'fietsenbon',
                'route' => 'multisafepay/fietsenbon',
                'description' => $this->language->get('text_title_fietsenbon'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_fietsenbon'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'fietsenbon'
            ),
            array(
                'id' => 'GEZONDHEID',
                'code' => 'gezondheidsbon',
                'route' => 'multisafepay/gezondheidsbon',
                'description' => $this->language->get('text_title_gezondheidsbon'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_gezondheidsbon'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'gezondheidsbon'
            ),
            array(
                'id' => 'GIVACARD',
                'code' => 'givacard',
                'route' => 'multisafepay/givaCard',
                'description' => $this->language->get('text_title_giva_card'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_giva_card'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'givacard'
            ),
            array(
                'id' => 'GIROPAY',
                'code' => 'giropay',
                'route' => 'multisafepay/giroPay',
                'description' => $this->language->get('text_title_giropay'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_giropay'), 'https://docs.multisafepay.com/payment-methods/banks/giropay/'),
                'image' => 'giropay'
            ),
            array(
                'id' => 'GOODCARD',
                'code' => 'goodcard',
                'route' => 'multisafepay/goodCard',
                'description' => $this->language->get('text_title_good_card'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_good_card'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'goodcard'
            ),
            array(
                'id' => 'IN3',
                'code' => 'in3',
                'route' => 'multisafepay/in3',
                'description' => $this->language->get('text_title_in3'),
                'type' => 'gateway',
                'docs' => '',
                'image' => 'in3'
            ),
            array(
                'id' => 'IDEAL',
                'code' => 'ideal',
                'route' => 'multisafepay/ideal',
                'description' => $this->language->get('text_title_ideal'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_ideal'), 'https://docs.multisafepay.com/payment-methods/banks/ideal/'),
                'image' => 'ideal'
            ),
            array(
                'id' => 'IDEALQR',
                'code' => 'idealqr',
                'route' => 'multisafepay/idealQr',
                'description' => $this->language->get('text_title_ideal_qr'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_ideal_qr'), 'https://docs.multisafepay.com/payment-methods/banks/idealqr/'),
                'image' => 'ideal-qr'
            ),
            array(
                'id' => 'INGHOME',
                'code' => 'ing',
                'route' => 'multisafepay/ing',
                'description' => $this->language->get('text_title_ing_home_pay'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_ing_home_pay'), 'https://docs.multisafepay.com/payment-methods/banks/ing-home-pay/'),
                'image' => 'ing'
            ),
            array(
                'id' => 'KBC',
                'code' => 'kbc',
                'route' => 'multisafepay/kbc',
                'description' => $this->language->get('text_title_kbc'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_kbc'), 'https://docs.multisafepay.com/payment-methods/banks/kbc/'),
                'image' => 'kbc'
            ),
            array(
                'id' => 'KLARNA',
                'code' => 'klarna',
                'route' => 'multisafepay/klarna',
                'description' => $this->language->get('text_title_klarna'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_klarna'), 'https://docs.multisafepay.com/payment-methods/billing-suite/klarna/'),
                'image' => 'klarna'
            ),
            array(
                'id' => 'MAESTRO',
                'code' => 'maestro',
                'route' => 'multisafepay/maestro',
                'description' => $this->language->get('text_title_maestro'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_maestro'), 'https://docs.multisafepay.com/payment-methods/credit-and-debit-cards/maestro/'),
                'image' => 'maestro'
            ),
            array(
                'id' => 'MASTERCARD',
                'code' => 'mastercard',
                'route' => 'multisafepay/mastercard',
                'description' => $this->language->get('text_title_mastercard'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_mastercard'), 'https://docs.multisafepay.com/payment-methods/credit-and-debit-cards/mastercard/'),
                'image' => 'mastercard'
            ),
            array(
                'id' => 'NATNLETUIN',
                'code' => 'nationaletuinbon',
                'route' => 'multisafepay/nationaleTuinbon',
                'description' => $this->language->get('text_title_nationale_tuinbon'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_nationale_tuinbon'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'nationaletuinbon'
            ),
            array(
                'id' => 'PARFUMCADE',
                'code' => 'parfumcadeaukaart',
                'route' => 'multisafepay/parfumCadeaukaart',
                'description' => $this->language->get('text_title_parfum_cadeaukaart'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_parfum_cadeaukaart'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'parfumcadeaukaart'
            ),
            array(
                'id' => 'PAYAFTER',
                'code' => 'payafter',
                'route' => 'multisafepay/payAfterDelivery',
                'description' => $this->language->get('text_title_pay_after_delivery'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_pay_after_delivery'), 'https://docs.multisafepay.com/payment-methods/billing-suite/pay-after-delivery/'),
                'image' => 'payafter'
            ),
            array(
                'id' => 'PAYPAL',
                'code' => 'paypal',
                'route' => 'multisafepay/payPal',
                'description' => $this->language->get('text_title_paypal'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_paypal'), 'https://docs.multisafepay.com/payment-methods/wallet/paypal/'),
                'image' => 'paypal'
            ),
            array(
                'id' => 'PODIUM',
                'code' => 'podium',
                'route' => 'multisafepay/podium',
                'description' => $this->language->get('text_title_podium'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_podium'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'podium'
            ),
            array(
                'id' => 'PSAFECARD',
                'code' => 'paysafecard',
                'route' => 'multisafepay/paysafecard',
                'description' => $this->language->get('text_title_paysafecard'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_paysafecard'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/paysafecard/'),
                'image' => 'paysafecard'
            ),
            array(
                'id' => 'SANTANDER',
                'code' => 'santander',
                'route' => 'multisafepay/betaalplan',
                'description' => $this->language->get('text_title_santander_betaalplan'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_santander_betaalplan'), 'https://docs.multisafepay.com/payment-methods/billing-suite/betaalplan/'),
                'image' => 'betaalplan'
            ),
            array(
                'id' => 'DIRDEB',
                'code' => 'dirdeb',
                'route' => 'multisafepay/dirDeb',
                'description' => $this->language->get('text_title_sepa_direct_debit'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_sepa_direct_debit'), 'https://docs.multisafepay.com/payment-methods/banks/sepa-direct-debit/'),
                'image' => 'dirdeb'
            ),
            array(
                'id' => 'SPORTENFIT',
                'code' => 'sportfit',
                'route' => 'multisafepay/sportFit',
                'description' => $this->language->get('text_title_sport_fit'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_sport_fit'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'sportenfit'
            ),
            array(
                'id' => 'TRUSTLY',
                'code' => 'trustly',
                'route' => 'multisafepay/trustly',
                'description' => $this->language->get('text_title_trustly'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_trustly'), 'https://docs.multisafepay.com/payment-methods/banks/trustly/'),
                'image' => 'trustly'
            ),
            array(
                'id' => 'VISA',
                'code' => 'visa',
                'route' => 'multisafepay/visa',
                'description' => $this->language->get('text_title_visa'),
                'type' => 'gateway',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_visa'), 'https://docs.multisafepay.com/payment-methods/credit-and-debit-cards/visa/'),
                'image' => 'visa'
            ),
            array(
                'id' => 'VVVGIFTCRD',
                'code' => 'vvv',
                'route' => 'multisafepay/vvvGiftCard',
                'description' => $this->language->get('text_title_vvv_cadeaukaart'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_vvv_cadeaukaart'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'vvv'
            ),
            array(
                'id' => 'WEBSHOPGIFTCARD',
                'code' => 'webshopgiftcard',
                'route' => 'multisafepay/webshopGiftCard',
                'description' => $this->language->get('text_title_webshop_giftcard'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_webshop_giftcard'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'webshopgiftcard'
            ),
            array(
                'id' => 'WELLNESSGIFTCARD',
                'code' => 'wellnessgiftcard',
                'route' => 'multisafepay/wellnessGiftCard',
                'description' => $this->language->get('text_title_wellness_giftcard'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_wellness_giftcard'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'wellnessgiftcard'
            ),
            array(
                'id' => 'WIJNCADEAU',
                'code' => 'wijncadeau',
                'route' => 'multisafepay/wijnCadeau',
                'description' => $this->language->get('text_title_wijncadeau'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_wijncadeau'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'wijncadeau'
            ),
            array(
                'id' => 'WINKELCHEQUE',
                'code' => 'winkelcheque',
                'route' => 'multisafepay/winkelCheque',
                'description' => $this->language->get('text_title_winkel_cheque'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_winkel_cheque'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'winkelcheque'
            ),
            array(
                'id' => 'YOURGIFT',
                'code' => 'yourgift',
                'route' => 'multisafepay/yourGift',
                'description' => $this->language->get('text_title_yourgift'),
                'type' => 'giftcard',
                'docs' => sprintf($this->language->get('text_gateway_docs_info'), $this->language->get('text_title_yourgift'), 'https://docs.multisafepay.com/payment-methods/prepaid-cards/gift-cards/'),
                'image' => 'yourgift'
            )
        );

        return $gateways;
    }

    /**
     * Return gateway by gateway id
     *
     * @param string $gateway_id
     * @return mixed bool|array
     *
     */
    public function getGatewayById($gateway_id) {
        $gateways = $this->getGateways();
        $gateway_key = array_search($gateway_id, array_column($gateways, 'id'));

        if(!$gateway_key) {
            return false;
        }

        return $gateways[$gateway_key];

    }

    /**
     * Return ordered gateways
     *
     * @param int $store_id
     * @return array $gateways
     *
     */
    public function getOrderedGateways($store_id = 0) {
        $gateways = $this->getGateways();
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('payment_multisafepay', $store_id);
        $sort_order = array();
        foreach($gateways as $key => $gateway) {
            if(!isset($settings['payment_multisafepay_' . $gateway['code'] . '_sort_order'])) {
                $sort_order[$key] = 0;
            }
            if(isset($settings['payment_multisafepay_' . $gateway['code'] . '_sort_order'])) {
                $sort_order[$key] = $settings['payment_multisafepay_'. $gateway['code']. '_sort_order'];
            }
        }
        array_multisort($sort_order, SORT_ASC, $gateways);
        return $gateways;
    }

    /**
     * Return gateways from the API available for the merchant.
     *
     * @return array $gateways
     *
     */
    public function getAvailableGateways($enviroment = false, $api_key = false) {

        if(!$api_key) {
            return false;
        }

        require_once(DIR_SYSTEM . 'library/multisafepay/vendor/autoload.php');

        try {
            $sdk = new \MultiSafepay\Sdk($api_key, $enviroment);
        }
        catch (\MultiSafepay\Exception\InvalidApiKeyException $invalidApiKeyException ) {
            return false;
        }

        try {
            $gateway_manager = $sdk->getGatewayManager();
        }
        catch (\MultiSafepay\Exception\ApiException $apiException ) {
            return false;
        }

        try {
            $gateways = $gateway_manager->getGateways();
        }
        catch (\MultiSafepay\Exception\ApiException $apiException ) {
            return false;
        }

        if(!$gateways) {
            return false;
        }

        $available_gateways = array();

        // This methods has been hardcoded, since are availables but it doesn`t comes in the request.
        $available_gateways[] = 'MULTISAFEPAY';
        $available_gateways[] = 'CREDITCARD';

        foreach ($gateways as $gateway) {
            $available_gateways[] = $gateway->getId();
        }

        return $available_gateways;
    }

}