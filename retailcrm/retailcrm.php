<?php

if (
    function_exists('date_default_timezone_set') &&
    function_exists('date_default_timezone_get')
) {
    date_default_timezone_set(@date_default_timezone_get());
}

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once(dirname(__FILE__) . '/bootstrap.php');

class RetailCRM extends Module
{

    function __construct()
    {
        $this->name = 'retailcrm';
        $this->tab = 'export';
        $this->version = '2.0';
        $this->author = 'Retail Driver LCC';
        $this->displayName = $this->l('RetailCRM');
        $this->description = $this->l('Integration module for RetailCRM');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
        $this->default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $this->default_currency = (int)Configuration::get('PS_CURRENCY_DEFAULT');
        $this->default_country = (int)Configuration::get('PS_COUNTRY_DEFAULT');
        $this->apiUrl = Configuration::get('RETAILCRM_ADDRESS');
        $this->apiKey = Configuration::get('RETAILCRM_API_TOKEN');
        $this->ps_versions_compliancy = array('min' => '1.5', 'max' => _PS_VERSION_);
        $this->version = substr(_PS_VERSION_, 0, 3);

        if ($this->version == '1.6') {
            $this->bootstrap = true;
        }

        if (!empty($this->apiUrl) && !empty($this->apiKey)) {
            $this->api = new RetailcrmProxy($this->apiUrl, $this->apiKey, _PS_ROOT_DIR_ . '/retailcrm.log');
            $this->reference = new RetailcrmReferences($this->api);
        }

        parent::__construct();
    }

    function install()
    {
        return (
            parent::install() &&
            $this->registerHook('newOrder') &&
            $this->registerHook('actionOrderStatusPostUpdate') &&
            $this->registerHook('actionPaymentConfirmation') &&
            $this->registerHook('actionCustomerAccountAdd')
        );
    }

    function uninstall()
    {
        return parent::uninstall() &&
        Configuration::deleteByName('RETAILCRM_ADDRESS') &&
        Configuration::deleteByName('RETAILCRM_API_TOKEN') &&
        Configuration::deleteByName('RETAILCRM_API_STATUS') &&
        Configuration::deleteByName('RETAILCRM_API_DELIVERY') &&
        Configuration::deleteByName('RETAILCRM_LAST_SYNC');
    }

    public function getContent()
    {
        $output = null;

        $address = Configuration::get('RETAILCRM_ADDRESS');
        $token = Configuration::get('RETAILCRM_API_TOKEN');

        if (!$address || $address == '') {
            $output .= $this->displayError($this->l('Invalid or empty crm address'));
        } elseif (!$token || $token == '') {
            $output .= $this->displayError($this->l('Invalid or empty crm api token'));
        } else {
            $output .= $this->displayConfirmation(
                $this->l('Timezone settings must be identical to both of your crm and shop') .
                " <a target=\"_blank\" href=\"$address/admin/settings#t-main\">$address/admin/settings#t-main</a>"
            );
        }

        if (Tools::isSubmit('submit' . $this->name)) {
            $address = strval(Tools::getValue('RETAILCRM_ADDRESS'));
            $token = strval(Tools::getValue('RETAILCRM_API_TOKEN'));
            $delivery = json_encode(Tools::getValue('RETAILCRM_API_DELIVERY'));
            $status = json_encode(Tools::getValue('RETAILCRM_API_STATUS'));
            $payment = json_encode(Tools::getValue('RETAILCRM_API_PAYMENT'));

            if (!$address || empty($address) || !Validate::isGenericName($address)) {
                $output .= $this->displayError($this->l('Invalid crm address'));
            } elseif (!$token || empty($token) || !Validate::isGenericName($token)) {
                $output .= $this->displayError($this->l('Invalid crm api token'));
            } else {
                Configuration::updateValue('RETAILCRM_ADDRESS', $address);
                Configuration::updateValue('RETAILCRM_API_TOKEN', $token);
                Configuration::updateValue('RETAILCRM_API_DELIVERY', $delivery);
                Configuration::updateValue('RETAILCRM_API_STATUS', $status);
                Configuration::updateValue('RETAILCRM_API_PAYMENT', $payment);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        $this->display(__FILE__, 'retailcrm.tpl');

        return $output . $this->displayForm();
    }

    public function displayForm()
    {

        $this->displayConfirmation($this->l('Settings updated'));

        $default_lang = $this->default_lang;

        /*
         * Network connection form
         */
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Network connection'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('CRM address'),
                    'name' => 'RETAILCRM_ADDRESS',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('CRM token'),
                    'name' => 'RETAILCRM_API_TOKEN',
                    'size' => 20,
                    'required' => true
                )
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'button'
            )
        );


        if (!empty($this->apiUrl) && !empty($this->apiKey)) {
            /*
             * Delivery
             */
            $fields_form[1]['form'] = array(
                'legend' => array('title' => $this->l('Delivery')),
                'input' => $this->reference->getDeliveryTypes(),
            );

            /*
             * Order status
             */
            $fields_form[2]['form'] = array(
                'legend' => array('title' => $this->l('Order statuses')),
                'input' => $this->reference->getStatuses(),
            );

            /*
             * Payment
             */
            $fields_form[3]['form'] = array(
                'legend' => array('title' => $this->l('Payment types')),
                'input' => $this->reference->getPaymentTypes(),
            );
        }

        /*
         * Diplay forms
         */

        $helper = new HelperForm();

        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' =>
                array(
                    'desc' => $this->l('Save'),
                    'href' => sprintf(
                        "%s&configure=%s&save%s&token=%s",
                        AdminController::$currentIndex,
                        $this->name,
                        $this->name,
                        Tools::getAdminTokenLite('AdminModules')
                    )
                ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        $helper->fields_value['RETAILCRM_ADDRESS'] = Configuration::get('RETAILCRM_ADDRESS');
        $helper->fields_value['RETAILCRM_API_TOKEN'] = Configuration::get('RETAILCRM_API_TOKEN');

        $deliverySettings = Configuration::get('RETAILCRM_API_DELIVERY');
        if (isset($deliverySettings) && $deliverySettings != '') {
            $deliveryTypes = json_decode($deliverySettings);
            if ($deliveryTypes) {
                foreach ($deliveryTypes as $idx => $delivery) {
                    $name = 'RETAILCRM_API_DELIVERY[' . $idx . ']';
                    $helper->fields_value[$name] = $delivery;
                }
            }
        }

        $statusSettings = Configuration::get('RETAILCRM_API_STATUS');
        if (isset($statusSettings) && $statusSettings != '') {
            $statusTypes = json_decode($statusSettings);
            if ($statusTypes) {
                foreach ($statusTypes as $idx => $status) {
                    $name = 'RETAILCRM_API_STATUS[' . $idx . ']';
                    $helper->fields_value[$name] = $status;
                }
            }
        }

        $paymentSettings = Configuration::get('RETAILCRM_API_PAYMENT');
        if (isset($paymentSettings) && $paymentSettings != '') {
            $paymentTypes = json_decode($paymentSettings);
            if ($paymentTypes) {
                foreach ($paymentTypes as $idx => $payment) {
                    $name = 'RETAILCRM_API_PAYMENT[' . $idx . ']';
                    $helper->fields_value[$name] = $payment;
                }
            }
        }

        return $helper->generateForm($fields_form);
    }

    public function hookActionCustomerAccountAdd($params)
    {
        $this->api->customersCreate(
            array(
                'externalId' => $params['newCustomer']->id,
                'firstName' => $params['newCustomer']->firstname,
                'lastName' => $params['newCustomer']->lastname,
                'email' => $params['newCustomer']->email,
                'createdAt' => $params['newCustomer']->date_add
            )
        );
    }

    public function hookNewOrder($params)
    {
        return $this->hookActionOrderStatusPostUpdate($params);
    }

    public function hookActionPaymentConfirmation($params)
    {
        $this->api->ordersEdit(
            array(
                'externalId' => $params['id_order'],
                'paymentStatus' => 'paid',
                'createdAt' => $params['cart']->date_upd
            )
        );

        return $this->hookActionOrderStatusPostUpdate($params);
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $address_id = Address::getFirstCustomerAddressId($params['cart']->id_customer);
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'address WHERE id_address=' . (int)$address_id;
        $dbaddress = Db::getInstance()->ExecuteS($sql);
        $address = $dbaddress[0];
        $delivery = json_decode(Configuration::get('RETAILCRM_API_DELIVERY'));
        $payment = json_decode(Configuration::get('RETAILCRM_API_PAYMENT'));
        $inCart = $params['cart']->getProducts();

        if (isset($params['orderStatus'])) {
            $this->api->customersEdit(
                array(
                    'externalId' => $params['cart']->id_customer,
                    'lastName' => $params['customer']->lastname,
                    'firstName' => $params['customer']->firstname,
                    'email' => $params['customer']->email,
                    'phones' => array(array('number' => $address['phone'])),
                    'createdAt' => $params['customer']->date_add
                )
            );

            $items = array();
            foreach ($inCart as $item) {
                $items[] = array(
                    'initialPrice' => (!empty($item['rate'])) ? $item['price'] + ($item['price'] * $item['rate'] / 100) : $item['price'],
                    'quantity' => $item['quantity'],
                    'productId' => $item['id_product'],
                    'productName' => $item['name'],
                    'createdAt' => $item['date_add']
                );
            }

            $dTypeKey = $params['cart']->id_carrier;

            if (Module::getInstanceByName('advancedcheckout') === false) {
                $pTypeKey = $params['order']->module;
            } else {
                $pTypeKey = $params['order']->payment;
            }

            $this->api->ordersCreate(
                array(
                    'externalId' => $params['order']->id,
                    'orderType' => 'eshop-individual',
                    'orderMethod' => 'shopping-cart',
                    'status' => 'new',
                    'customerId' => $params['cart']->id_customer,
                    'firstName' => $params['customer']->firstname,
                    'lastName' => $params['customer']->lastname,
                    'phone' => $address['phone'],
                    'email' => $params['customer']->email,
                    'paymentType' => $payment->$pTypeKey,
                    'delivery' => array(
                        'code' => $delivery->$dTypeKey,
                        'cost' => $params['order']->total_shipping,
                        'address' => array(
                            'city' => $address['city'],
                            'index' => $address['postcode'],
                            'text' => $address['address1'],
                        )
                    ),
                    'discount' => $params['order']->total_discounts,
                    'items' => $items,
                    'createdAt' => $params['order']->date_add
                )
            );
        }

        if (!empty($params['newOrderStatus'])) {
            $statuses = OrderState::getOrderStates($this->default_lang);
            $aStatuses = json_decode(Configuration::get('RETAILCRM_API_STATUS'));
            foreach ($statuses as $status) {
                if ($status['name'] == $params['newOrderStatus']->name) {
                    $currStatus = $status['id_order_state'];
                    $this->api->ordersEdit(
                        array(
                            'externalId' => $params['id_order'],
                            'status' => $aStatuses->$currStatus
                        )
                    );
                }
            }
        }
    }
}
