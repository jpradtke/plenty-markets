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
class CaptureEventProcedure
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

        if(empty($orderId))
        {
            throw new \Exception('Capture AfterPay payment failed! The given order is invalid!');
        }

        /** @var Payment[] $payment */
        $payments = $paymentContract->getPaymentsByOrderId($orderId);

        /** @var Payment $payment */
        foreach($payments as $payment)
        {
            if($payment->mopId == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAY)
            OR $payment->mopId == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT))
            {
                $saleId = (string)$paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_TRANSACTION_ID);

                if($saleId)
                {
                    $data=json_decode($paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_PAYMENT_TEXT),true);
                    if($payment->mopId == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT)){
                        $mode = PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT;
                    }else{
                        $mode = PaymentHelper::PAYMENTKEY_AFTERPAY;
                    }

                    $paymentdata = [
                        'invoiceNumber'=> $orderId,
                        'orderDetails' => [
                            'totalGrossAmount' => $payment->amount,
                            'currency' => $payment->currency,
                            'items'=>[]
                        ]
                    ];

                    // refund the payment
                    $captureResult = $paymentService->capturePayment($mode,$data['country'],$saleId,$paymentdata);

                    if($captureResult['message'])
                    {
                        throw new \Exception($captureResult['message']);
                    }
                    else
                    {
                        /** @TODO captureNumber speichern */
                        if( null == $paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_CAPTURE_ID))
                        {
                            /** @var PaymentProperty $paymentProperty */
                            $paymentProperty = pluginApp(\Plenty\Modules\Payment\Models\PaymentProperty::class);

                            $paymentProperty->typeId = PaymentProperty::TYPE_CAPTURE_ID;
                            $paymentProperty->value = $captureResult['captureNumber'];
                            $payment->properties[] = $paymentProperty;
                            $paymentContract->updatePayment($payment);
                        }else{
                            throw new \Exception("captureNumber ".$captureResult['captureNumber']." already exists.");
                        }
                    }

                    unset($saleId);
                }
            }
        }
    }
}