<?php

namespace AfterPay\Migrations;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use AfterPay\Helper\PaymentHelper;

/**
 * Migration to create payment mehtods
 *
 * Class CreatePaymentMethod
 * @package AfterPay\Migrations
 */
class CreatePaymentMethod
{
    /**
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodRepositoryContract;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * CreatePaymentMethod constructor.
     *
     * @param PaymentMethodRepositoryContract $paymentMethodRepositoryContract
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(    PaymentMethodRepositoryContract $paymentMethodRepositoryContract,
                                    PaymentHelper $paymentHelper)
    {
        $this->paymentMethodRepositoryContract = $paymentMethodRepositoryContract;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * Run on plugin build
     *
     * Create Method of Payment ID for AfterPay and AfterPay Express if they don't exist
     */
    public function run()
    {
        // Check whether the ID of the AfterPay payment method has been created
        if($this->paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAY) == 'no_paymentmethod_found')
        {
            $paymentMethodData = array( 'pluginKey' => 'plentyAfterPay',
                                        'paymentKey' => 'AFTERPAY',
                                        'name' => 'AfterPay');

            $this->paymentMethodRepositoryContract->createPaymentMethod($paymentMethodData);
        }

        // Check whether the ID of the AfterPay Express payment method has been created
        if($this->paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT) == 'no_paymentmethod_found')
        {
            $paymentMethodData = array( 'pluginKey'   => 'plentyAfterPay',
                                        'paymentKey'  => 'AFTERPAYINSTALLMENT',
                                        'name'        => 'AfterPayInstallment');

            $this->paymentMethodRepositoryContract->createPaymentMethod($paymentMethodData);
        }
//
//        // Check whether the ID of the AfterPay Express payment method has been created
//        if($this->paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYEXPRESS) == 'no_paymentmethod_found')
//        {
//            $paymentMethodData = array( 'pluginKey'   => 'plentyAfterPay',
//                                        'paymentKey'  => 'AfterPayEXPRESS',
//                                        'name'        => 'AfterPayExpress');
//
//            $this->paymentMethodRepositoryContract->createPaymentMethod($paymentMethodData);
//        }
//
//        // Check whether the ID of the AfterPay Express payment method has been created
//        if($this->paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYPLUS) == 'no_paymentmethod_found')
//        {
//            $paymentMethodData = array( 'pluginKey'   => 'plentyAfterPay',
//                                        'paymentKey'  => 'AfterPayPLUS',
//                                        'name'        => 'AfterPayPlus');
//
//            $this->paymentMethodRepositoryContract->createPaymentMethod($paymentMethodData);
//        }
        return $this->paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAY);
    }
}