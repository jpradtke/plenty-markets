<?php //strict

namespace AfterPay\Services;

use IO\Constants\SessionStorageKeys;
use IO\Services\ItemLoader\Loaders\Facets;
use IO\Services\ItemLoader\Loaders\SingleItem;
use Plenty\Modules\Account\Address\Models\Address;
use IO\Services\ItemLoader\Services\ItemLoaderService;
use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Order\Models\OrderItem;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Basket\Models\BasketItem;
use Plenty\Modules\Frontend\Services\SystemService;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;

use AfterPay\Helper\PaymentHelper;
use AfterPay\Services\Database\SettingsService;

/**
 * @package AfterPay\Services
 */
class PaymentService
{
    use Loggable;

    /**
     * @var string
     */
    private $returnType = '';

    /**
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodRepository;

    /**
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var AddressRepositoryContract
     */
    private $addressRepo;

    /**
     * @var ConfigRepository
     */
    private $config;

    /**
     * @var SessionStorageService
     */
    private $sessionStorage;

    /**
     * @var ContactService
     */
    private $contactService;

    /**
     * @var SystemService
     */
    private $systemService;

    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * @var array
     */
    public $settings = [];

    /** @var BasketRepositoryContract */
    private $basketContract;
    private $customer;

    /**
     * PaymentService constructor.
     *
     * @param PaymentMethodRepositoryContract $paymentMethodRepository
     * @param PaymentRepositoryContract $paymentRepository
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param AddressRepositoryContract $addressRepo
     * @param SessionStorageService $sessionStorage
     * @param \AfterPay\Services\ContactService $contactService
     * @param SystemService $systemService
     * @param SettingsService $settingsService
     * @param BasketRepositoryContract $basketContract
     */
    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository,
                                PaymentRepositoryContract $paymentRepository,
                                ConfigRepository $config,
                                PaymentHelper $paymentHelper,
                                AddressRepositoryContract $addressRepo,
                                SessionStorageService $sessionStorage,
                                ContactService $contactService,
                                SystemService $systemService,
                                SettingsService $settingsService,
                                BasketRepositoryContract $basketContract
    )
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentRepository = $paymentRepository;
        $this->paymentHelper = $paymentHelper;
        $this->addressRepo = $addressRepo;
        $this->config = $config;
        $this->sessionStorage = $sessionStorage;
        $this->contactService = $contactService;
        $this->systemService = $systemService;
        $this->settingsService = $settingsService;
        $this->basketContract = $basketContract;
    }

    /**
     * Get the type of payment from the content of the AfterPay container
     *
     * @return string
     */
    public function getReturnType()
    {
        return $this->returnType;
    }

    /**
     * @param string $returnType
     */
    public function setReturnType(string $returnType)
    {
        $this->returnType = $returnType;
    }

    /**
     * Get the AfterPay payment content
     *
     * @param Basket $basket
     * @param string $mode
     * @return string|array|null
     */
    public function getPaymentContent(Basket $basket, $mode = PaymentHelper::MODE_AFTERPAY, $additionalRequestParams = []): string
    {

        $preparePaymentResult = [];
        if (!strlen($mode))
        {
            $mode = PaymentHelper::MODE_AFTERPAY;
        }

        $AfterPayRequestParams = $this->getAfterPayParams($basket, $mode);
//
//        // Add Additional request params
        $AfterPayRequestParams = array_merge($AfterPayRequestParams, $additionalRequestParams);

        if ($mode == PaymentHelper::MODE_AFTERPAY)
        {
            $AfterPayRequestParams['request']['payment'] = [
                'type' => 'Invoice'
            ];
            $preparePaymentResult['links'] = [
                [
                    'method' => 'REDIRECT',
                    'href' => 'payment/afterPay/checkout'
                ]
            ];
        } else
        {
            $preparePaymentResult['links'] = [
                [
                    'method' => 'REDIRECT',
                    'href' => 'payment/afterPayInstallment/financingOptions'
                ]
            ];
        }

        /** @var FrontendSessionStorageFactoryContract $session */
        $session = pluginApp(FrontendSessionStorageFactoryContract::class);
        $lang = strtoupper($session->getLocaleSettings()->language);

        $errors = $this->checkAuthorizationParamRequirements($AfterPayRequestParams['request'], $lang);

        if (is_array($errors) && $errors['error_msg'])
        {
            $this->returnType = 'errorCode';
            return $errors['error_msg'];
        }
        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_COUNTRY, $basket->shippingCountryId);
        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_REQUEST, $AfterPayRequestParams);

        $preparePaymentResult['request'] = $AfterPayRequestParams['request'];

        // Get the content of the AfterPay container
        $links = $preparePaymentResult['links'];
        $paymentContent = $preparePaymentResult['request'];
//
        if (is_array($links))
        {
            foreach ($links as $link)
            {
                // Get the redirect URLs for the content
                if ($link['method'] == 'REDIRECT')
                {
                    $paymentContent = $link['href'];
                    $this->returnType = 'redirectUrl';
                }
            }
        }
//
//        // Check whether the content is set. Else, return an error code.
        if (is_null($paymentContent) OR !strlen($paymentContent))
        {
            $this->returnType = 'errorCode';
            return json_encode($preparePaymentResult);
        }
//
        return $paymentContent;
    }


    /**
     * Get the AfterPay payment content
     *
     * @param Order $order
     * @param string $mode
     * @return string|array|null
     */
    public function getPaymentContentByOrder(Order $order, $mode = PaymentHelper::MODE_AFTERPAY, $additionalRequestParams = []): string
    {
        if (!strlen($mode))
        {
            $mode = PaymentHelper::MODE_AFTERPAY;
        }

        $AfterPayRequestParams = $this->getAfterPayParamsByOrder($order, $mode);

        // Add Additional request params
        $AfterPayRequestParams = array_merge($AfterPayRequestParams, $additionalRequestParams);

        $AfterPayRequestParams['mode'] = $mode;

        // Prepare the AfterPay payment
//        $preparePaymentResult = $this->libService->libPreparePayment($AfterPayRequestParams);
        $preparePaymentResult = [];
        $this->getLogger('AfterPay_PaymentService')->debug('preparePayment', $preparePaymentResult);

        // Check for errors
        if (is_array($preparePaymentResult) && $preparePaymentResult['error'])
        {
            $this->returnType = 'errorCode';
            return $preparePaymentResult['error_msg'] ? $preparePaymentResult['error_msg'] : $preparePaymentResult['error_description'];
        }

        // Store the AfterPay Pay ID in the session
        if (isset($preparePaymentResult['id']) && strlen($preparePaymentResult['id']))
        {
            $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_PAY_ID, $preparePaymentResult['id']);
        }

        // Get the content of the AfterPay container
        $links = $preparePaymentResult['links'];
        $paymentContent = null;

        if (is_array($links))
        {
            foreach ($links as $link)
            {
                // Get the redirect URLs for the content
                if ($link['method'] == 'REDIRECT')
                {
                    $paymentContent = $link['href'];
                    $this->returnType = 'redirectUrl';
                }
            }
        }

        // Check whether the content is set. Else, return an error code.
        if (is_null($paymentContent) OR !strlen($paymentContent))
        {
            $this->returnType = 'errorCode';
            return json_encode($preparePaymentResult);
        }

        return $paymentContent;

    }

    /**
     * @param Order $order
     * @return string
     */
    public function prepareAfterPayPaymentByOrder(Order $order)
    {
        $paymentContent = $this->getPaymentContentByOrder($order);

        return $paymentContent;
    }


    /**
     * Execute the AfterPay payment
     *
     * @param string $mode
     * @param array $additionalParams
     * @return string
     */
    public function executePayment($mode = PaymentHelper::MODE_AFTERPAY, $additionalParams = [])
    {
        // Load the mandatory AfterPay data from session
//        $ppPayId    = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_PAY_ID);
        $countryId = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_COUNTRY);
        $executeParams = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_REQUEST);
        if (empty($executeParams))
        {
            return "payment/afterPay/checkoutCancel/$mode";
        }
        $preparePaymentResult['request'] = array_merge($executeParams['request'], $additionalParams);

        if (is_array($preparePaymentResult) && $preparePaymentResult['error'])
        {
            $this->returnType = 'errorCode';
            return $preparePaymentResult['error_msg'] ? $preparePaymentResult['error_msg'] : $preparePaymentResult['error_description'];
        }


//        // Prepare the AfterPay payment
        $executeResponse = $this->post($mode, $countryId, '/api/v3/checkout/authorize', $preparePaymentResult['request']);

        $this->getLogger('AfterPay_PaymentService')->debug('executePayment', $preparePaymentResult);

        if (isset($executeResponse['outcome']) && $executeResponse['outcome'] !== "Rejected")
        {
            $executeResponse['order'] = $executeParams['request']['order'];
            $executeResponse['country'] = $countryId;
            // Store the AfterPay Pay ID in the session
            if (isset($executeResponse['checkoutId']) && strlen($executeResponse['checkoutId']))
            {
                $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_PAY_ID, $executeResponse['checkoutId']);
                $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_ID, $executeParams['request']['order']['number']);
            } else
            {
                $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_PAY_ID, null);
                $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_ID, null);
            }
            $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_RESPONSE, $executeResponse);
        }

        $errors = $this->checkForErrors($executeResponse);
        if ($errors !== false)
        {
            return $errors;
        }
//
//        $this->sessionStorage->setSessionValue(PaymentHelper::MODE_AFTERPAY_NOTIFICATION,['message'=>"debugging",'debug'=>[
//            $executeParams,
//            $additionalParams,
//            $executeResponse
//        ]]);
//        return 'payment/afterPay/error/?type=debug&mode='. $mode . '&paymentId='.$preparePaymentResult['checkoutId'];

        // Clear the session parameters
//        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_PAY_ID, null);


        return 'payment/afterPay/contact/?type=' . $mode . '&paymentId=' . $executeResponse['checkoutId'];
    }

    /**
     * Execute the AfterPay payment
     *
     * @return array|string
     */
    public function getExecutedPayment()
    {
        // Load the mandatory AfterPay data from session
        $executeResponse = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_RESPONSE);

        $errors = $this->checkForErrors($executeResponse);
        if ($errors !== false)
        {
            $this->returnType = 'errorCode';
            return $this->sessionStorage->getSessionValue(PaymentHelper::MODE_AFTERPAY_NOTIFICATION);
        }
//
//        // Clear the session parameters
//        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_PAY_ID, null);
//        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_REQUEST, null);
//        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_RESPONSE, null);
//        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_COUNTRY, null);

        return $executeResponse;
    }

    private function checkForErrors($afterpayResponse)
    {
        // Check for errors
        $errorResponse = [];
        if (is_array($afterpayResponse) && $afterpayResponse['code'])
        {
            $errorResponse = $afterpayResponse;
        } elseif (is_array($afterpayResponse[0]) && $afterpayResponse[0]['code'])
        {
            $errorResponse = $afterpayResponse[0];
        } elseif (is_array($afterpayResponse['riskCheckMessages']) && $afterpayResponse['riskCheckMessages']['code'])
        {
            $errorResponse = $afterpayResponse['riskCheckMessages'];
        } elseif (is_array($afterpayResponse['riskCheckMessages'][0]) && $afterpayResponse['riskCheckMessages'][0]['code'])
        {
            $errorResponse = $afterpayResponse['riskCheckMessages'][0];
        }
        if (!empty($errorResponse) && $errorResponse['code'])
        {
            $this->returnType = 'errorCode';
            $message = $errorResponse['customerFacingMessage'] ? $errorResponse['customerFacingMessage'] : $errorResponse['code'] . ': ' . $errorResponse['message'];

            $this->sessionStorage->setSessionValue(PaymentHelper::MODE_AFTERPAY_NOTIFICATION, ['message' => $message, 'debug' => $errorResponse]);
            return 'payment/afterPay/error/';
        }
        return false;
    }

    /**
     * @param $paymentId
     */
    public function handleAfterPayCustomer($paymentId, $mode = PaymentHelper::MODE_AFTERPAY)
    {
        $response = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_RESPONSE);

        // update or create a contact
        $this->contactService->handleAfterPayContact($response['customer']);
    }

    /**
     * @param $paymentId
     * @param string $mode
     * @return array
     */
    public function getPaymentDetails($paymentId, $mode = PaymentHelper::MODE_AFTERPAY)
    {
        $requestParams = $this->getApiContextParams($mode);
        $requestParams['paymentId'] = $paymentId;
        $requestParams['mode'] = $mode;

        // @TODO get "/api/v3/orders/{{$paymentId}}"
        $response = [];
        $this->getLogger('AfterPay_PaymentService')->debug('getPaymentDetails', $response);

        return $response;
    }

    /**
     * request the AfterPay sale for the given saleId
     *
     * @param $saleId
     * @return array
     */
    public function getSaleDetails($mode, $country, $saleId)
    {

        $saleDetailsResult = $this->get($mode, $country, '/api/v3/orders/' . $saleId);
        $this->getLogger('AfterPay_PaymentService')->debug('getSaleDetails', $saleDetailsResult);

        return $saleDetailsResult;
    }

    /**
     * Refund the given payment
     *
     * @param int $saleId
     * @param array $paymentData
     * @return array
     */
    public function capturePayment($mode, $country, $saleId, $paymentData = [])
    {
        $requestParams['orderDetails'] = [];

        if (!empty($paymentData))
        {
            $requestParams = array_merge($requestParams, $paymentData);
        }

        $response = $this->post($mode, $country, '/api/v3/orders/' . $saleId . '/captures', $requestParams);

        return $response;
    }

    /**
     * Refund the given payment
     *
     * @param int $saleId
     * @param array $paymentData
     * @return array
     */
    public function voidPayment($mode, $country, $saleId, $paymentData = [])
    {
        $requestParams = [];
        if (!empty($paymentData))
        {
            $requestParams = array_merge($requestParams, $paymentData);
        }
        /*
{
  "cancellationDetails": {
    "totalGrossAmount": 100.00,
    "currency": "EUR",
    "risk": {
      "channelType": "CallCenter",
      "deliveryType": "Express"
    },
    "items": [
      {
        "productId": "1",
        "description": "Tablet Black",
        "grossUnitPrice": 40.00,
        "quantity": 2.0
      },
      {
        "productId": "2",
        "description": "MusicPlayer Black",
        "grossUnitPrice": 20.00,
        "quantity": 1.0
      }
    ]
  }
}
        */

        $response = $this->post($mode, $country, '/api/v3/orders/' . $saleId . '/voids', $requestParams);

        return $response;
    }

    /**
     * Refund the given payment
     *
     * @param int $saleId
     * @param array $paymentData
     * @return array
     */
    public function refundPayment($mode, $country, $saleId, $paymentData = [])
    {
        $requestParams = [];
        if (!empty($paymentData))
        {
            $requestParams = array_merge($requestParams, $paymentData);
        }
        /*
        {
          "captureNumber": "000000001",
          "orderItems": [
            {
              "productId": "1",
              "groupId": "GROUP111",
              "description": "Tablet Black",
              "grossUnitPrice": 40.00,
              "quantity": 1.0
            }
          ]
        }*/
        $response = $this->post($mode, $country, '/api/v3/orders/' . $saleId . '/refunds', $requestParams);

        return $response;
    }

    /**
     * Fill and return the AfterPay parameters
     *
     * @param Basket $basket
     * @param String $mode
     * @return array
     */
    public function getAfterPayParams(Basket $basket = null, $mode = PaymentHelper::MODE_AFTERPAY)
    {
        $AfterPayRequestParams = $this->getApiContextParams($mode, $basket->shippingCountryId);
        $AfterPayRequestParams['request'] = $this->getCustomerAndOrder($basket);

        // Get the URLs for AfterPay parameters

        return $AfterPayRequestParams;
    }

    /**
     * Fill and return the AfterPay parameters
     *
     * @param Order $order
     * @param String $mode
     * @return array
     */
    public function getAfterPayParamsByOrder(Order $order = null, $mode = PaymentHelper::MODE_AFTERPAY)
    {

//        $payments = $paymentContract->getPaymentsByOrderId($order->îd);

        $AfterPayRequestParams['basket'] = [];
        $basket['currency'] = $order->amounts[0]->currency;
        $basket['basketAmount'] = $order->amounts[0]->invoiceTotal;
        $basket['couponDiscount'] = 0;
        $basket['shippingAmount'] = 0;

        /** declare the variable as array */
        $AfterPayRequestParams['basketItems'] = [];

        /** @var \Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract $itemContract */
        $itemContract = pluginApp(\Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract::class);

        $itemSum = 0.0;

        /** @var OrderItem $basketItem */
        foreach ($order->orderItems as $orderItem)
        {
            if ($orderItem->typeId == 4) //coupon
            {
                $basket['couponDiscount'] = $orderItem->amounts[0]->priceGross; //amount
                continue;
            }
            if ($orderItem->typeId == 6) //shipping costs
            {
                $basket['shippingAmount'] = $orderItem->amounts[0]->priceGross; //amount
                continue;
            }


            $AfterPayBasketItem['itemId'] = $orderItem->variation->itemId;
            $AfterPayBasketItem['quantity'] = $orderItem->quantity;
            $AfterPayBasketItem['price'] = $orderItem->amounts[0]->priceGross;

            $itemSum += $orderItem->quantity * $orderItem->amounts[0]->priceGross;

            /** @var \Plenty\Modules\Item\Item\Models\Item $item */
            $item = $itemContract->show($orderItem->variation->itemId);

            /** @var \Plenty\Modules\Item\Item\Models\ItemText $itemText */
            $itemText = $item->texts;

            $AfterPayBasketItem['name'] = $itemText->first()->name1;

            $AfterPayRequestParams['basketItems'][] = $AfterPayBasketItem;
        }

        $basket['itemSum'] = $itemSum;

        /** @var Basket $basket */
        $AfterPayRequestParams['basket'] = $basket;

        // Read the shipping address ID from the session
        $shippingAddress = $order->deliveryAddress;

        if (empty($shippingAddress))
        {
            $shippingAddress = $order->billingAddress;
        }

        if (!is_null($shippingAddress))
        {
            /** declarce the variable as array */
            $AfterPayRequestParams['shippingAddress'] = [];
            $AfterPayRequestParams['shippingAddress']['town'] = $shippingAddress->town;
            $AfterPayRequestParams['shippingAddress']['postalCode'] = $shippingAddress->postalCode;
            $AfterPayRequestParams['shippingAddress']['firstname'] = $shippingAddress->firstName;
            $AfterPayRequestParams['shippingAddress']['lastname'] = $shippingAddress->lastName;
            $AfterPayRequestParams['shippingAddress']['street'] = $shippingAddress->street;
            $AfterPayRequestParams['shippingAddress']['houseNumber'] = $shippingAddress->houseNumber;

            /** @var \Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract $countryRepo */
            $countryRepo = pluginApp(\Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract::class);

            // Fill the country for AfterPay parameters
            $country = [];
            $country['isoCode2'] = $countryRepo->findIsoCode($shippingAddress->countryId, 'iso_code_2');
            $AfterPayRequestParams['country'] = $country;

            // Get the URLs for AfterPay parameters
            $AfterPayRequestParams['urls'] = $this->paymentHelper->getRestOrderReturnUrls($order->id);

            return $AfterPayRequestParams;
        }
//        }
        // Get the URLs for AfterPay parameters
        $AfterPayRequestParams['urls'] = $this->paymentHelper->getRestOrderReturnUrls($order->id);

        return $AfterPayRequestParams;
    }

    /**
     * Return the api params for the authentication
     *
     * @param string $mode
     * @return array
     */
    public function getApiContextParams($mode = PaymentHelper::MODE_AFTERPAY, $country = 0)
    {
        $settingType = 'AfterPay';
        if ($mode == PaymentHelper::MODE_AFTERPAY_INSTALLMENT)
        {
            $settingType = 'afterPay_installment';
        }
        $this->loadCurrentSettings($settingType, $country);

        $apiContextParams = [];

        $apiContextParams['apiurl'] = ($this->settings['productionMode'] == 1) ? $this->config->get('AfterPay.api.production') : $this->config->get('AfterPay.api.sandbox');
        /* trim excess slash after url */
        $apiContextParams['apiurl'] = rtrim($apiContextParams['apiurl'], '/');

        $apiContextParams['header']['X-Auth-Key'] = $this->settings['xauthKey'];
        $apiContextParams['header']['Content-Type'] = 'application/json';
        return $apiContextParams;
    }

    /**
     * Load the settings from the datebase for the given settings type
     *
     * @param string $settingsType
     * @param int $country
     * @return void
     */
    public function loadCurrentSettings($settingsType = 'AfterPay', $country = 0)
    {
        $setting = $this->settingsService->loadSetting($this->systemService->getPlentyId(), $settingsType, $country);
        if (is_array($setting) && count($setting) > 0)
        {
            $this->settings = $setting;
        }
    }

    /**
     * @return PaymentHelper
     */
    public function getPaymentHelper(): PaymentHelper
    {
        return $this->paymentHelper;
    }

    /**
     * @return \AfterPay\Services\SessionStorageService
     */
    public function getSessionStorage(): \AfterPay\Services\SessionStorageService
    {
        return $this->sessionStorage;
    }

    /**
     * @return AddressRepositoryContract
     */
    public function getAddressRepository(): AddressRepositoryContract
    {
        return $this->addressRepo;
    }

    public function getCustomerAndOrder($basket)
    {
        $AfterPayRequestParams = [];
        /** @var \Plenty\Modules\Frontend\Services\AccountService $plentyAccountService */
        $plentyAccountService = pluginApp(\Plenty\Modules\Frontend\Services\AccountService::class);
        $customer = $plentyAccountService->getAccountContactId();


        /** @var \Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract $itemContract */
        $itemContract = pluginApp(\Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract::class);
        /** @var \Plenty\Modules\Item\Variation\Contracts\VariationRepositoryContract $variationContract */
        $variationContract = pluginApp(\Plenty\Modules\Item\Variation\Contracts\VariationRepositoryContract::class);


        $AfterPayRequestParams['customer'] = $this->setCustomerData($customer, $basket);
        $this->setOrderData($AfterPayRequestParams, $basket);
        $this->setOrderItemsData($AfterPayRequestParams, $basket, $itemContract, $variationContract);
        $this->setCustomerAddressData($AfterPayRequestParams, $basket);
        $this->setCustomerRiskdata($AfterPayRequestParams, $customer);

        return $AfterPayRequestParams;

    }

    /**
     * @param $customer
     * @param Basket $basket
     * @return array
     */
    private function setCustomerData($customer, $basket)
    {
        $return = [];
        /** @var FrontendSessionStorageFactoryContract $session */
        $session = pluginApp(FrontendSessionStorageFactoryContract::class);
        $lang = strtoupper($session->getLocaleSettings()->language);

        /** @var ContactRepositoryContract $ContactContract */
        $ContactContract = pluginApp(ContactRepositoryContract::class);

        /**
         * @var SessionStorageService $sessionStorage
         */
        $sessionStorage = pluginApp(SessionStorageService::class);
        $email = $sessionStorage->getSessionValue(SessionStorageKeys::GUEST_EMAIL);
        if ($customer == 0)
        {
            $address = $this->getAddressById($basket->customerInvoiceAddressId);
            $return = [
                "firstName" => $address['firstName'],
                "lastName" => $address['lastName'],
                "salutation" => '',
                "email" => $email,
                "customerCategory" => "Person",
                "conversationLanguage" => $lang,
//                "debugCustomer" => $customerContact->toArray(),
            ];
        } else
        {
            /** @var \Plenty\Modules\Account\Contact\Models\Contact $customerContact */
            $customerContact = $ContactContract->findContactById($customer);

            $return = [
                "salutation" => ($customerContact->gender == 'male') ? 'Mr' : ($customerContact->gender == 'female') ? 'Mrs' : '',
                "firstName" => $customerContact->firstName,
                "lastName" => $customerContact->lastName,
                "email" => $customerContact->email,
                "customerCategory" => "Person",
                "conversationLanguage" => $lang,
                "birthDate" => empty($customerContact->birthdayAt) ? '' : $customerContact->birthdayAt->format(DATE_ATOM),
                "identificationNumber" => ''
//                "debugCustomer" => $customerContact,
            ];
        }
        return $return;
    }

    /**
     * @param $AfterPayRequestParams
     * @param Basket $basket
     */
    private function setOrderData(&$AfterPayRequestParams, $basket)
    {
        $AfterPayRequestParams['order'] = [
            "number" => uniqid(),
            "totalGrossAmount" => $basket->basketAmount,
            "totalNetAmount" => $basket->basketAmountNet,
            "currency" => $basket->currency,
            "items" => [

            ]
        ];

        if ($basket->shippingAmount > 0)
        {
            /** @TODO Name der Versandart nutzen */
//            /** @var ShippingServiceProviderRepositoryContract $shippingProviderRepositoryContract */
//            $shippingProviderRepositoryContract = pluginApp(ShippingServiceProviderRepositoryContract::class);
//            $shippingProvider = $shippingProviderRepositoryContract->find($basket->shippingProviderId);

            $AfterPayRequestParams['order']["items"][] = [
                "productId" => PaymentHelper::SHIPPINGPRODUCTID,
                "description" => "Shipping Costs",
                "grossUnitPrice" => $basket->shippingAmount,
                "netUnitPrice" => $basket->shippingAmountNet,
                "vatPercent" => round((($basket->basketAmount / $basket->basketAmountNet) - 1) * 100),
                "vatAmount" => $basket->shippingAmount - $basket->shippingAmountNet,
                "quantity" => 1.0
            ];
        }
        /** @TODO Google Analytics Integration testen */
        if ($this->settings['analytics'] == 1)
        {
            $AfterPayRequestParams['order']['googleAnalyticsUserId'] = $this->settings['analyticsUserId'];
            $AfterPayRequestParams['order']['googleAnalyticsUserId'] = $this->settings['analyticsClientId'];
        }
    }

    /**
     * @param $AfterPayRequestParams
     * @param Basket $basket
     * @param \Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract $itemContract
     * @param \Plenty\Modules\Item\Variation\Contracts\VariationRepositoryContract $variationContract
     */
    private function setOrderItemsData(&$AfterPayRequestParams, $basket, $itemContract, $variationContract)
    {
        $totalNetPrice = 0;
        /** @var BasketItem $item */
        foreach ($basket->basketItems as $basketItem)
        {
            /** @var \Plenty\Modules\Item\Item\Models\Item $item */
            $item = $itemContract->show($basketItem->itemId);

            /** @var \Plenty\Modules\Item\Item\Models\ItemText $itemText */
            $itemText = $item->texts;

            /** @var \Plenty\Modules\Item\Variation\Models\Variation $itemVariation */
            $itemVariation = $variationContract->findById($basketItem->variationId);

            if (!empty($itemVariation->images))
            {
                $image = $itemVariation->images[0]['urlPreview'];
            } else
            {
                /* use itemloader to load general data for a product */

                /** @var  ItemLoaderService $ItemLoader */
                $ItemLoader = pluginApp(ItemLoaderService::class);
                $response = $ItemLoader->loadForTemplate("Ceres::Item.SingleItemWrapper", [SingleItem::class, Facets::class], [
                    "variationId" => $basketItem->variationId
                ]);
                //End ItemLoader

                $image = $response['documents'][0]['data']['images']['all'][0]['urlPreview'];
            }
            $netprice = round($basketItem->price / (($basketItem->vat / 100) + 1), 2);
            $AfterPayRequestParams['order']['items'][] =
                [
                    "productId" => $basketItem->variationId,
                    "description" => $itemText->first()->name1,
                    "netUnitPrice" => $netprice,
                    "grossUnitPrice" => $basketItem->price,
                    "vatPercent" => $basketItem->vat,
                    "vatAmount" => $basketItem->price - $netprice,
                    "quantity" => $basketItem->quantity,
                    "imageUrl" => $image
                ];
            $totalNetPrice += $netprice;
            unset($itemVariation);
        }
    }

    private function setCustomerAddressData(&$AfterPayRequestParams, $basket)
    {
        // Read the shipping address ID from the session
        $shippingAddressId = $basket->customerShippingAddressId ?? $basket->customerInvoiceAddressId;

        if (!is_null($shippingAddressId))
        {
            if ($shippingAddressId == -99)
            {
                $shippingAddressId = $basket->customerInvoiceAddressId;
            }

            if (!is_null($shippingAddressId))
            {
                $AfterPayRequestParams['customer']['address'] = $this->getAddressById($basket->customerInvoiceAddressId);
            }
            if (!is_null($shippingAddressId) && $shippingAddressId != $basket->customerInvoiceAddressId)
            {

                /** @var \Plenty\Modules\Frontend\Services\AccountService $plentyAccountService */
                $plentyAccountService = pluginApp(\Plenty\Modules\Frontend\Services\AccountService::class);
                $customer = $plentyAccountService->getAccountContactId();
                $AfterPayRequestParams['deliveryCustomer'] = $this->setCustomerData($customer, $basket);
                $AfterPayRequestParams['deliveryCustomer']['address'] = $this->getAddressById($shippingAddressId);

            }
        }
    }

    private function getAddressById($id)
    {
        /** @var Address $shippingAddress */
        $shippingAddress = $this->addressRepo->findAddressById($id);

        $address = [];
        $address['postalPlace'] = $shippingAddress->town;
        $address['postalCode'] = $shippingAddress->postalCode;
        $address['firstName'] = $shippingAddress->firstName;
        $address['lastName'] = $shippingAddress->lastName;
        $address['street'] = $shippingAddress->street;
        $address['streetNumber'] = $shippingAddress->houseNumber;
        $address['countryCode'] = $shippingAddress->country['isoCode2'];
        $address['streetNumberAdditional'] = $shippingAddress->additional;
        return $address;
    }


    private function setCustomerRiskdata(&$AfterPayRequestParams, $customer)
    {
        if ($AfterPayRequestParams['customer']['address']['countryCode'] === "DE")
        {
            $AfterPayRequestParams['customer']['riskData'] = [];
            if ($customer == 0)
            {
                $AfterPayRequestParams['customer']['riskData']['existingCustomer'] = false;
            } else
            {

                /** @var ContactRepositoryContract $ContactContract */
                $ContactContract = pluginApp(ContactRepositoryContract::class);
                $customerContact = $ContactContract->findContactById($customer);

                $AfterPayRequestParams['customer']['riskData']['existingCustomer'] = $customerContact->id > 0 ? true : false;
                $AfterPayRequestParams['customer']['riskData']['marketingOptIn'] = $customerContact->newsletterAllowanceAt != null ? true : false;
                $AfterPayRequestParams['customer']['riskData']['customerSince'] = $customerContact->createdAt->format(DATE_ATOM);
                $AfterPayRequestParams['customer']['riskData']['customerClassification'] = $this->getCustomerTypeNameById($customerContact->typeId);
                $AfterPayRequestParams['customer']['riskData']['ipAddress'] = $_SERVER['REMOTE_ADDR'];
            }
        }
    }

    private function getCustomerTypeNameById($typeId): string
    {
        $typeName = "";
        switch ($typeId)
        {
            case 1:
                $typeName = "CUSTOMER";
                break;
            case 2:
                $typeName = "SALES_LEAD";
                break;
            case 3:
                $typeName = "SALES_REPRESENTATIVE";
                break;
            case 4:
                $typeName = "SUPPLIER";
                break;
            case 5:
                $typeName = "PRODUCER";
                break;
            case 6:
                $typeName = "PARTNER";
                break;
        }

        return $typeName;
    }

    private function checkAuthorizationParamRequirements($AfterPayRequestParams, $currentLang)
    {
        $preparePaymentResult = [];
        $customer = $AfterPayRequestParams['customer'];
        if ($customer['salutation'] === '' && in_array($currentLang, ['DE', 'DK', 'NL', 'BE', 'AT', 'CH']))
        {
            $preparePaymentResult['errorMsg'][] = "Salutation is required";
        }
        if ($customer['email'] === '' && in_array($currentLang, ['DE', 'DK', 'NL', 'BE', 'AT', 'CH', 'SE']))
        {
            $preparePaymentResult['error_msg'][] = "Email address is required";
        }
        if (empty($customer['birthDate']) && in_array($currentLang, ['DE', 'DK', 'NL', 'BE', 'AT']))
        {
            $preparePaymentResult['error_msg'][] = "Birthday is required";
        }
        if ($customer['streetNumberAdditional'] === '' && in_array($currentLang, ['NL']))
        {
            $preparePaymentResult['error_msg'][] = "Additional streetnumber is required";
        }

        return $preparePaymentResult;
    }


    protected function get($mode, $country, $url, $form_data = [])
    {
        $API = $this->getApiContextParams($mode, $country);

//        /** @TODO debugging entfernen */
//        $API['header']['X-Orig-Url'] = $API['apiurl'];
//        $API['apiurl'] = "http://beta.w-plus.de/plentyproxy";

        $req = curl_init($API['apiurl'] . $url);
        curl_setopt($req, CURLOPT_CUSTOMREQUEST, "GET");
        if (!empty($API['header']))
        {
            $processedHeader = [];
            foreach ($API['header'] as $key => $value)
            {
                $processedHeader[] = "$key: $value";
            }
            curl_setopt($req, CURLOPT_HTTPHEADER, $processedHeader);
        }
        curl_setopt($req, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($req, CURLOPT_FOLLOWLOCATION, 1);
        $result = curl_exec($req);
        curl_close($req);

        return json_decode($result, true);
    }

    protected function post($mode, $country, $url, $form_data = [])
    {
        $API = $this->getApiContextParams($mode, $country);

//        /** @TODO debugging entfernen */
//        $API['header']['X-Orig-Url'] = $API['apiurl'];
//        $API['apiurl'] = "http://beta.w-plus.de/plentyproxy";

        $req = curl_init($API['apiurl'] . $url);
        curl_setopt($req, CURLOPT_CUSTOMREQUEST, "POST");
        if (!empty($API['header']))
        {
            $processedHeader = [];
            foreach ($API['header'] as $key => $value)
            {
                $processedHeader[] = "$key: $value";
            }
            curl_setopt($req, CURLOPT_HTTPHEADER, $processedHeader);
        }
        if (!empty($form_data))
        {
            curl_setopt($req, CURLOPT_POSTFIELDS, json_encode($form_data));
        }
        curl_setopt($req, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($req, CURLOPT_FOLLOWLOCATION, 1);
        $result = curl_exec($req);
        curl_close($req);

        return json_decode($result, true);
    }
}
