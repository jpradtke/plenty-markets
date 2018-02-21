<?php // strict

namespace AfterPay\Providers;

use AfterPay\Methods\AfterPayInstallmentPaymentMethod;
use AfterPay\Services\AfterPayInstallmentService;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\Frontend\Events\FrontendLanguageChanged;
use Plenty\Modules\Frontend\Events\FrontendShippingCountryChanged;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Pdf\Events\OrderPdfGenerationEvent;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Document\Models\Document;

use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\ServiceProvider;

use AfterPay\Services\PaymentService;
use AfterPay\Helper\PaymentHelper;
use AfterPay\Methods\AfterPayPaymentMethod;
use AfterPay\Procedures\RefundEventProcedure;
use AfterPay\Procedures\CaptureEventProcedure;
use AfterPay\Procedures\VoidEventProcedure;

/**
 * Class AfterPayServiceProvider
 * @package AfterPay\Providers
 */
class AfterPayServiceProvider extends ServiceProvider
{
    /**
     * Register the route service provider
     */
    public function register()
    {
        $this->getApplication()->register(AfterPayRouteServiceProvider::class);

        $this->getApplication()->bind(RefundEventProcedure::class);
        $this->getApplication()->bind(CaptureEventProcedure::class);
        $this->getApplication()->bind(VoidEventProcedure::class);
    }

    /**
     * Boot additional AfterPay services
     *
     * @param Dispatcher $eventDispatcher
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param AfterPayInstallmentService $AfterPayInstallmentService
     * @param BasketRepositoryContract $basket
     * @param PaymentMethodContainer $payContainer
     * @param EventProceduresService $eventProceduresService
     */
    public function boot(Dispatcher $eventDispatcher,
                         PaymentHelper $paymentHelper,
                         PaymentService $paymentService,
                         AfterPayInstallmentService $AfterPayInstallmentService,
                         BasketRepositoryContract $basket,
                         PaymentMethodContainer $payContainer,
                         EventProceduresService $eventProceduresService)
    {

        /**
         * @TODO if entfernen nach dme der Tippfehler aus der datenbank getilgt wurde
         */
        if ($paymentHelper->getAfterPayMopIdByPaymentKey('AfterPay'))
        {
            $payContainer->register('plentyAfterPay::AfterPay', AfterPayPaymentMethod::class,
                [AfterBasketChanged::class,
                    AfterBasketItemAdd::class,
                    AfterBasketCreate::class,
                    FrontendLanguageChanged::class,
                    FrontendShippingCountryChanged::class
                ]);

            $payContainer->register('plentyAfterPay::AfterPayINSTALLMENT', AfterPayInstallmentPaymentMethod::class,
                [AfterBasketChanged::class,
                    AfterBasketItemAdd::class,
                    AfterBasketCreate::class,
                    FrontendLanguageChanged::class,
                    FrontendShippingCountryChanged::class
                ]);
        } else
        {
            // Register the AfterPay payment method in the payment method container
            $payContainer->register('plentyAfterPay::' . PaymentHelper::PAYMENTKEY_AFTERPAY, AfterPayPaymentMethod::class,
                [AfterBasketChanged::class,
                    AfterBasketItemAdd::class,
                    AfterBasketCreate::class,
                    FrontendLanguageChanged::class,
                    FrontendShippingCountryChanged::class
                ]);

            // Register the AfterPay installment payment method in the payment method container
            $payContainer->register('plentyAfterPay::' . PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT, AfterPayInstallmentPaymentMethod::class,
                [AfterBasketChanged::class,
                    AfterBasketItemAdd::class,
                    AfterBasketCreate::class,
                    FrontendLanguageChanged::class,
                    FrontendShippingCountryChanged::class
                ]);
        }

        // Register AfterPay Refund Event Procedure
        $eventProceduresService->registerProcedure(
            'plentyAfterPay',
            ProcedureEntry::PROCEDURE_GROUP_ORDER,
            ['de' => 'Rückzahlung der AfterPay-Zahlung',
                'en' => 'Refund the AfterPay-Payment'],
            'AfterPay\Procedures\RefundEventProcedure@run');
        $eventProceduresService->registerProcedure(
            'plentyAfterPay',
            ProcedureEntry::PROCEDURE_GROUP_ORDER,
            ['de' => 'Capture der AfterPay-Zahlung',
                'en' => 'Capture the AfterPay-Payment'],
            'AfterPay\Procedures\CaptureEventProcedure@run');
        $eventProceduresService->registerProcedure(
            'plentyAfterPay',
            ProcedureEntry::PROCEDURE_GROUP_ORDER,
            ['de' => 'Void der AfterPay-Zahlung',
                'en' => 'Void the AfterPay-Payment'],
            'AfterPay\Procedures\VoidEventProcedure@run');

        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(GetPaymentMethodContent::class,
            function (GetPaymentMethodContent $event) use ($paymentHelper, $basket, $paymentService, $AfterPayInstallmentService)
            {
                if ($event->getMop() == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAY))
                {
                    $basket = $basket->load();

                    $event->setValue($paymentService->getPaymentContent($basket));
                    $event->setType($paymentService->getReturnType());
                } elseif ($event->getMop() == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT))
                {
                    $basket = $basket->load();
                    $event->setValue($AfterPayInstallmentService->getInstallmentContent($basket));
                    $event->setType($AfterPayInstallmentService->getReturnType());

                }
            });

        // Listen for the event that executes the payment
        $eventDispatcher->listen(ExecutePayment::class,
            function (ExecutePayment $event) use ($paymentHelper, $paymentService)
            {
                if ($event->getMop() == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAY) ||
                    $event->getMop() == $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT))
                {
                    switch ($event->getMop())
                    {
                        case $paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT):
                            $mode = PaymentHelper::MODE_AFTERPAY_INSTALLMENT;
                            break;
                        default:
                            $mode = PaymentHelper::MODE_AFTERPAY;
                            break;
                    }

                    // Execute the payment
                    $AfterPayPaymentData = $paymentService->getExecutedPayment();

                    // Check whether the AfterPay payment has been executed successfully
                    if ($paymentService->getReturnType() != 'errorCode')
                    {
                        // Create a plentymarkets payment from the AfterPay execution params
                        $plentyPayment = $paymentHelper->createPlentyPayment((array)$AfterPayPaymentData);

                        if ($plentyPayment instanceof Payment)
                        {
                            // Assign the payment to an order in plentymarkets
                            $paymentHelper->assignPlentyPaymentToPlentyOrder($plentyPayment, $event->getOrderId());

                            $event->setType('success');
                            $event->setValue('The Payment has been executed successfully!');
                        }
                    } else
                    {
                        $event->setType('error');
                        $event->setValue('The AfterPay-Payment could not be executed!');
                    }
                }
            });

    }

}
