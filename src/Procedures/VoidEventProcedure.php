<?php

namespace AfterPay\Procedures;

use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;

use AfterPay\Services\PaymentService;
use AfterPay\Helper\PaymentHelper;

/**
 * Class RefundEventProcedure
 * @package AfterPay\Procedures
 */
class VoidEventProcedure
{
    /**
     * @param EventProceduresTriggered $eventTriggered
     * @param PaymentService $paymentService
     * @param PaymentRepositoryContract $paymentContract
     * @param PaymentHelper $paymentHelper
     * @throws \Exception
     */
    public function run(EventProceduresTriggered $eventTriggered,
                        PaymentService $paymentService,
                        PaymentRepositoryContract $paymentContract,
                        PaymentHelper $paymentHelper)
    {
        $orderId = $paymentHelper->getOderIdForEvents($eventTriggered);

        if (empty($orderId))
        {
            throw new \Exception('Refund AfterPay payment failed! The given order is invalid!');
        }

        /** @var Payment[] $payment */
        $payments = $paymentContract->getPaymentsByOrderId($orderId);

        /** @var Payment $payment */
        foreach ($payments as $payment)
        {
            if ($payment->mopId == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAY)
                OR $payment->mopId == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT))
            {
                $saleId = (string)$paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_TRANSACTION_ID);

                if ($saleId)
                {
                    $data=json_decode($paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_PAYMENT_TEXT),true);
                    if($payment->mopId == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT)){
                        $mode = PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT;
                    }else{
                        $mode = PaymentHelper::PAYMENTKEY_AFTERPAY;
                    }

                    if (null !== $paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_CAPTURE_ID))
                    {
                        throw new \Exception("Can not void payment, captureNumber ".$paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_CAPTURE_ID)." already exists.");
                    } else
                    {
                        // refund the payment
                        $refundResult = $paymentService->voidPayment($mode,$data['country'],$saleId);

                        if ($refundResult['error'])
                        {
                            throw new \Exception($refundResult['error_msg']);
                        }

                        // if the refund is pending, set the payment unaccountable
                        if ($refundResult['totalAuthorizedAmount'])
                        {
                            $payment->unaccountable = 1;  //1 true 0 false
                            // read the payment status of the refunded payment
                            $payment->status = $paymentHelper->mapStatus('completed');

                            // update the refunded payment
                            $paymentContract->updatePayment($payment);
                        }
                    }

                    unset($saleId);
                }
            }
        }
    }
}