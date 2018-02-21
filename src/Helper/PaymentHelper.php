<?php //strict

namespace AfterPay\Helper;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Order\Models\Order;

use AfterPay\Services\SessionStorageService;
use AfterPay\Services\PaymentService;

/**
 * Class PaymentHelper
 * @package AfterPay\Helper
 */
class PaymentHelper
{
    const PAYMENTKEY_AFTERPAY = 'AFTERPAY';
    const PAYMENTKEY_AFTERPAYINSTALLMENT = 'AFTERPAYINSTALLMENT';

    const MODE_AFTERPAY = 'invoice';
    const MODE_AFTERPAY_INSTALLMENT = 'installment';
    const MODE_AFTERPAY_NOTIFICATION = 'notification';
    const SHIPPINGPRODUCTID = 0;

    /**
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodRepository;

    /**
     * @var ConfigRepository
     */
    private $config;

    /**
     * @var SessionStorageService
     */
    private $sessionService;

    /**
     * @var PaymentOrderRelationRepositoryContract
     */
    private $paymentOrderRelationRepo;

    /**
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     * @var OrderRepositoryContract
     */
    private $orderRepo;

    /**
     * @var PaymentService
     */
    private $paymentService;

    /**
     * @var array
     */
    private $statusMap = array();

    /**
     * PaymentHelper constructor.
     *
     * @param PaymentMethodRepositoryContract $paymentMethodRepository
     * @param PaymentRepositoryContract $paymentRepo
     * @param PaymentOrderRelationRepositoryContract $paymentOrderRelationRepo
     * @param ConfigRepository $config
     * @param SessionStorageService $sessionService
     * @param OrderRepositoryContract $orderRepo
     * @param PaymentService $paymentService
     */
    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository,
                                PaymentRepositoryContract $paymentRepo,
                                PaymentOrderRelationRepositoryContract $paymentOrderRelationRepo,
                                ConfigRepository $config,
                                SessionStorageService $sessionService,
                                OrderRepositoryContract $orderRepo)
    {
        $this->config = $config;
        $this->sessionService = $sessionService;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->paymentOrderRelationRepo = $paymentOrderRelationRepo;
        $this->paymentRepository = $paymentRepo;
        $this->orderRepo = $orderRepo;
        $this->statusMap = array();
    }

    public function getAfterPayMopIdByPaymentKey($paymentKey)
    {
        if (strlen($paymentKey))
        {
            // List all payment methods for the given plugin
            $paymentMethods = $this->paymentMethodRepository->allForPlugin('plentyAfterPay');
//            return json_encode($this->paymentMethodRepository->all());
            if (!is_null($paymentMethods))
            {
                foreach ($paymentMethods as $paymentMethod)
                {
                    if (strtolower($paymentMethod->paymentKey) == strtolower($paymentKey))
                    {
                        return $paymentMethod->id;
                    }
                }
            }
        }

        return 'no_paymentmethod_found';
    }

    /**
     * Get the REST return URLs for the given mode
     *
     * @param string $mode
     * @return array(success => $url, cancel => $url)
     */
    public function getRestReturnUrls($mode)
    {
        $domain = $this->getDomain();

        $urls = [];

        switch ($mode)
        {
//            case self::MODE_AFTERPAY_PLUS:
            case self::MODE_AFTERPAY:
                $urls['success'] = $domain . '/payment/AfterPay/checkoutSuccess/' . $mode;
                $urls['cancel'] = $domain . '/payment/AfterPay/checkoutCancel/' . $mode;
                break;
            case self::MODE_AFTERPAY_INSTALLMENT:
                $urls['success'] = $domain . '/payment/AfterPayInstallment/prepareInstallment';
                $urls['cancel'] = $domain . '/payment/AfterPay/checkoutCancel/' . $mode;
                break;
//            case self::MODE_AFTERPAYEXPRESS:
//                $urls['success'] = $domain.'/payment/AfterPay/expressCheckoutSuccess';
//                $urls['cancel'] = $domain.'/payment/AfterPay/expressCheckoutCancel';
//                break;
            case self::MODE_AFTERPAY_NOTIFICATION:
                $urls[self::MODE_AFTERPAY_NOTIFICATION] = $domain . '/payment/AfterPay/notification';
                break;
        }

        return $urls;
    }

    /**
     * Get the REST return URLs for the given mode
     *
     * @param int $orderId
     * @return array(success => $url, cancel => $url)
     */
    public function getRestOrderReturnUrls($orderId)
    {
        $domain = $this->getDomain();

        $urls = [];
        $urls['success'] = $domain . '/payment/AfterPay/payOrderSuccess/' . $orderId;
        $urls['cancel'] = $domain . '/payment/AfterPay/payOrderCancel/' . $orderId;

        return $urls;
    }

    /**
     * @return string
     */
    public function getDomain()
    {
        /** @var WebstoreHelper $webstoreHelper */
        $webstoreHelper = pluginApp(WebstoreHelper::class);

        /** @var \Plenty\Modules\System\Models\WebstoreConfiguration $webstoreConfig */
        $webstoreConfig = $webstoreHelper->getCurrentWebstoreConfiguration();

        $domain = $webstoreConfig->domainSsl;

        return $domain;
    }

    /**
     * Create a payment in plentymarkets from the AfterPay execution response data
     *
     * @param array $AfterPayPaymentData
     * @param array $paymentData
     * @return Payment
     */
    public function createPlentyPayment(array $AfterPayPaymentData, $paymentData = [])
    {
        /** @var Payment $payment */
        $payment = pluginApp(Payment::class);

        $payment->mopId = (int)$this->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAY);
        $payment->transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING;
        $payment->status = $this->mapStatus((STRING)$AfterPayPaymentData['outcome']);
        $payment->currency = $AfterPayPaymentData['order']['currency'] ? $AfterPayPaymentData['order']['currency'] : 'EUR';
        $payment->amount = $AfterPayPaymentData['order']['totalGrossAmount'];
        $payment->receivedAt = date(DATE_ATOM);

        if (!empty($paymentData['type']))
        {
            $payment->type = PaymentHelper::PAYMENTKEY_AFTERPAY;
        }

        if (!empty($paymentData['parentId']))
        {
            $payment->parentId = $paymentData['parentId'];
        }

        if (!empty($paymentData['unaccountable']))
        {
            $payment->unaccountable = $paymentData['unaccountable'];
        }

        $paymentProperty = [];

        /**
         * Add payment property with type booking text
         */
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, 'ReservationId: ' . $AfterPayPaymentData['reservationId'] . '
CheckoutId: ' . (string)$AfterPayPaymentData['checkoutId']);

        /**
         * Add payment property with type transactionId
         */
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_TRANSACTION_ID, $AfterPayPaymentData['order']['number']);

        /**
         * Add payment property with type origin
         */
        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ORIGIN, Payment::ORIGIN_PLUGIN);

//        /**
//         * Add payment property with type account of the receiver
//         */
//        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_ACCOUNT_OF_RECEIVER, $AfterPayPaymentData['id']);

//        if(!empty($AfterPayPaymentData[SessionStorageService::AfterPay_INSTALLMENT_COSTS])
//        && is_array($AfterPayPaymentData[SessionStorageService::AfterPay_INSTALLMENT_COSTS]))
//        {
//            $creditFinancing = $AfterPayPaymentData[SessionStorageService::AfterPay_INSTALLMENT_COSTS];
//
//            $paymentText = [];
//            $paymentText['financingCosts'] = $creditFinancing['total_interest']['value'];
//            $paymentText['totalCostsIncludeFinancing'] = $creditFinancing['total_cost']['value'];
//            $paymentText['currency'] = $creditFinancing['total_cost']['currency'];
//
//            /**
//             * Add payment property with type payment text
//             */
//            $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_PAYMENT_TEXT, json_encode($paymentText));
//        }
//
//        if(!empty($AfterPayPaymentData['payment_instruction']) && is_array($AfterPayPaymentData['payment_instruction']))
//        {
//            if(is_array($AfterPayPaymentData['payment_instruction']['recipient_banking_instruction']))
//            {
//                $paymentText = [];
//                $paymentText['bankName'] = $AfterPayPaymentData['payment_instruction']['recipient_banking_instruction']['bank_name'];
//                $paymentText['accountHolder'] = $AfterPayPaymentData['payment_instruction']['recipient_banking_instruction']['account_holder_name'];
//                $paymentText['iban'] = $AfterPayPaymentData['payment_instruction']['recipient_banking_instruction']['international_bank_account_number'];
//                $paymentText['bic'] = $AfterPayPaymentData['payment_instruction']['recipient_banking_instruction']['bank_identifier_code'];
//                $paymentText['referenceNumber'] = $AfterPayPaymentData['payment_instruction']['reference_number'];
//                $paymentText['paymentDue'] = $AfterPayPaymentData['payment_instruction']['payment_due_date'];
//
//                /**
//                 * Add payment property with type payment text
//                 */
//                $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_PAYMENT_TEXT, json_encode($paymentText));
//            }
//        }

        $paymentProperty[] = $this->getPaymentProperty(PaymentProperty::TYPE_PAYMENT_TEXT, json_encode(['country'=>$AfterPayPaymentData['country']]));

        $payment->properties = $paymentProperty;
        $payment->regenerateHash = true;

        $payment = $this->paymentRepository->createPayment($payment);

        return $payment;
    }

    /**
     * Returns a PaymentProperty with the given params
     *
     * @param Payment $payment
     * @param array $data
     * @return PaymentProperty
     */
    private function getPaymentProperty($typeId, $value)
    {
        /** @var PaymentProperty $paymentProperty */
        $paymentProperty = pluginApp(\Plenty\Modules\Payment\Models\PaymentProperty::class);

        $paymentProperty->typeId = $typeId;
        $paymentProperty->value = $value;

        return $paymentProperty;
    }

    /**
     * Assign the payment to an order in plentymarkets
     *
     * @param Payment $payment
     * @param int $orderId
     */
    public function assignPlentyPaymentToPlentyOrder(Payment $payment, int $orderId)
    {
        // Get the order by the given order ID
        $order = $this->orderRepo->findOrderById($orderId);

        // Check whether the order truly exists in plentymarkets
        if (!is_null($order) && $order instanceof Order)
        {
            // Assign the given payment to the given order
            $this->paymentOrderRelationRepo->createOrderRelation($payment, $order);
        }
    }

    /**
     * Map the AfterPay payment status to the plentymarkets payment status
     *
     * @param string $status
     * @return int
     *
     */
    public function mapStatus(string $status)
    {
        if (!is_array($this->statusMap) || count($this->statusMap) <= 0)
        {
            $statusConstants = $this->paymentRepository->getStatusConstants();

            if (!is_null($statusConstants) && is_array($statusConstants))
            {
                $this->statusMap['Accepted'] = $statusConstants['approved'];
                $this->statusMap['Pending'] = $statusConstants['awaiting_approval'];
                $this->statusMap['Rejected'] = $statusConstants['refused'];
            }
        }

        return strlen($status) ? (int)$this->statusMap[$status] : 2;
    }

    /**
     * @param Payment $payment
     * @param int $propertyType
     * @return null|string
     */
    public function getPaymentPropertyValue($payment, $propertyType)
    {
        $properties = $payment->properties;

        if (($properties->count() > 0) || (is_array($properties) && count($properties) > 0))
        {
            /** @var PaymentProperty $property */
            foreach ($properties as $property)
            {
                if ($property instanceof PaymentProperty)
                {
                    if ($property->typeId == $propertyType)
                    {
                        return $property->value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param EventProceduresTriggered $eventTriggered
     */
    public function getOderIdForEvents(EventProceduresTriggered $eventTriggered){
        /** @var Order $order */
        $order = $eventTriggered->getOrder();

        // only sales orders and credit notes are allowed order types to refund
        switch($order->typeId)
        {
            case 1: //sales order
                $orderId = $order->id;
                break;
            case 4: //credit note
                $originOrders = $order->originOrders;
                if(!$originOrders->isEmpty() && $originOrders->count() > 0)
                {
                    $originOrder = $originOrders->first();

                    if($originOrder instanceof Order)
                    {
                        if($originOrder->typeId == 1)
                        {
                            $orderId = $originOrder->id;
                        }
                        else
                        {
                            $originOriginOrders = $originOrder->originOrders;
                            if(is_array($originOriginOrders) && count($originOriginOrders) > 0)
                            {
                                $originOriginOrder = $originOriginOrders->first();
                                if($originOriginOrder instanceof Order)
                                {
                                    $orderId = $originOriginOrder->id;
                                }
                            }
                        }
                    }
                }
                break;
        }

        return $orderId;
    }
}
