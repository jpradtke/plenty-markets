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
class RefundEventProcedure
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
                    $captureid= $paymentHelper->getPaymentPropertyValue($payment, PaymentProperty::TYPE_CAPTURE_ID);
                    /* Do stuff if already captured else void?*/
                    if (null == $captureid)
                    {
                        throw new \Exception("Can not refund payment, captureNumber does not exists.");
                    } else
                    {
                        $paymentRequest = [
                            'captureNumber'=>$captureid,
                            'orderItems'=>[]
                        ];

                        foreach ($order->orderItems as $orderItem){
                            $paymentRequest['orderItems'][] = [
                                'productId'=>"_".$orderItem['itemVariationId'],
                                'description'=>$orderItem['orderItemName'],
                                'grossUnitPrice'=>$orderItem['amounts'][0]['priceGross'],
                                'netUnitPrice'=>$orderItem['amounts'][0]['priceNet'],
                                'quantity'=>$orderItem['quantity'],
//                                'debug'=>$orderItem
                            ];
                        }

                        // refund the payment
                        $refundResult = $paymentService->refundPayment($mode,$data['country'],$saleId,$paymentRequest);

                        if ($refundResult['error'])
                        {
                            throw new \Exception($refundResult['error_msg']);
                        } else
                        {
                            $paymentData = [];
                            $paymentData['parentId'] = $payment->id;
                            $paymentData['type'] = 'debit';

                            // if the refund is pending, set the payment unaccountable
                            if ($refundResult['state'] == 'pending')
                            {
                                $paymentData['unaccountable'] = 1;  //1 true 0 false
                            }

                            // get the sale details of the refunded payment
                            $saleDetails = $paymentService->getSaleDetails($mode,$data['country'],$saleId);

                            if (!isset($saleDetails['error']))
                            {
                                // create the new debit payment
                                /** @var Payment $debitPayment */
                                $debitPayment = $paymentHelper->createPlentyPayment($saleDetails, $paymentData);

                                // read the payment status of the refunded payment
                                $payment->status = $paymentHelper->mapStatus($saleDetails['state']);

                                // update the refunded payment
                                $paymentContract->updatePayment($payment);
                            }

                            if (isset($debitPayment) && $debitPayment instanceof Payment)
                            {
                                if ($refundResult['refundNumbers'])
                                {
                                    // assign the new debit payment to the order
                                    $paymentHelper->assignPlentyPaymentToPlentyOrder($debitPayment, $order->id);
                                }
                            }
                        }
                    }

                    unset($saleId);
                }
            }
        }
    }
}