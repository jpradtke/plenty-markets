<?php //strict

namespace AfterPay\Controllers;

use AfterPay\Services\AfterPayInstallmentService;
use IO\Services\ItemService;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;

use AfterPay\Services\SessionStorageService;
use AfterPay\Services\PaymentService;
use AfterPay\Helper\PaymentHelper;
use Plenty\Plugin\Templates\Twig;

/**
 * Class PaymentController
 * @package AfterPay\Controllers
 */
class PaymentController extends Controller
{
    protected $itemService;
    protected $addressRepo;
    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var ConfigRepository
     */
    private $config;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var PaymentService
     */
    private $paymentService;

    /**
     * @var BasketRepositoryContract
     */
    private $basketContract;

    /**
     * @var OrderRepositoryContract
     */
    private $orderContract;

    /**
     * @var SessionStorageService
     */
    private $sessionStorage;
    /**
     * @var AccountService
     */
    private $accountService;

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param BasketRepositoryContract $basketContract
     * @param OrderRepositoryContract $orderContract
     * @param SessionStorageService $sessionStorage
     * @param ItemService $itemService
     * @param AddressRepositoryContract $addressRepositoryContract
     * @param AccountService $accountService
     */
    public function __construct(Request $request,
                                Response $response,
                                ConfigRepository $config,
                                PaymentHelper $paymentHelper,
                                PaymentService $paymentService,
                                BasketRepositoryContract $basketContract,
                                OrderRepositoryContract $orderContract,
                                SessionStorageService $sessionStorage,
                                ItemService $itemService,
                                AddressRepositoryContract $addressRepositoryContract,
                                AccountService $accountService)
    {
        $this->request = $request;
        $this->response = $response;
        $this->config = $config;
        $this->paymentHelper = $paymentHelper;
        $this->paymentService = $paymentService;
        $this->basketContract = $basketContract;
        $this->orderContract = $orderContract;
        $this->sessionStorage = $sessionStorage;
        $this->itemService = $itemService;
        $this->addressRepo = $addressRepositoryContract;
        $this->accountService = $accountService;
    }

    /**
     * AfterPay redirects to this page if the payment could not be executed or other problems occurred
     * @param string $mode
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function checkoutCancel($mode = PaymentHelper::MODE_AFTERPAY)
    {
        /** @TODO void paymentAuthorization if needed */
        $apPayId = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_ID);
        $country = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_COUNTRY);

        $this->paymentService->voidPayment($mode,$country,$apPayId);
        // clear the AfterPay session values
        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_PAY_ID, null);
        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_ID, null);
        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_REQUEST, null);
        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_RESPONSE, null);
        $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_COUNTRY, null);
        // Redirects to the cancellation page. The URL can be entered in the config.json.
        return $this->response->redirectTo('checkout');
    }

    /**
     * AfterPay redirects to this page if the payment was executed correctly
     * @param string $mode
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function checkoutSuccess($mode = PaymentHelper::MODE_AFTERPAY)
    {
        // Get the AfterPay payment data from the request
        $paymentId = $this->request->get('paymentId');

        // Get the AfterPay Pay ID from the session
        $apPayId = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_PAY_ID);

        // Check whether the Pay ID from the session is equal to the given Pay ID by AfterPay
        if ($paymentId != $apPayId)
        {
            return $this->checkoutCancel();
        }

        // update or create a contact
        $this->paymentService->handleAfterPayCustomer($paymentId, $mode);

        // Redirect to the success page. The URL can be entered in the config.json.
        return $this->response->redirectTo('place-order');
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function prepareInstallment()
    {

        if (empty($this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_REQUEST)))
        {
            return $this->response->redirectTo("payment/afterPay/checkoutCancel/AFTERPAYINSTALLMENT");
        }
        /** @var Checkout $checkout */
        $checkout = pluginApp(\Plenty\Modules\Frontend\Contracts\Checkout::class);

        if ($checkout instanceof Checkout)
        {
            $paymentMethodId = $this->paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT);
            if ($paymentMethodId > 0)
            {
                $checkout->setPaymentMethodId((int)$paymentMethodId);
            }
        }
        /** @var AfterPayInstallmentService $AfterPayInstallmentService */
        $financingCosts = $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_INSTALLMENT_COSTS);
        if ($financingCosts == null)
        {
            $message = "no financing plan selected";
            $this->sessionStorage->setSessionValue(PaymentHelper::MODE_AFTERPAY_NOTIFICATION, ['message' => $message, 'debug' => ""]);
            return $this->response->redirectTo('payment/afterPay/error/');
        } else
        {
            if (is_array($financingCosts) && !empty($financingCosts))
            {
                $additionalParams['payment'] = [
                    'type' => 'Installment',
                    'installment' => [
                        'profileNo' => $financingCosts['installmentProfileNumber'],
                        'numberOfInstallments' => $financingCosts['numberOfInstallments'],
                        'customerInterestRate' => $financingCosts['interestRate']
                    ]
                ];
                $this->sessionStorage->setSessionValue(SessionStorageService::AfterPay_INSTALLMENT_COSTS, null);
            } else
            {
                $message = "financing plan not valid";
                $this->sessionStorage->setSessionValue(PaymentHelper::MODE_AFTERPAY_NOTIFICATION, ['message' => $message, 'debug' => $financingCosts]);
                return $this->response->redirectTo('payment/afterPay/error/');
            }
        }
        // get the AfterPay-express redirect URL
        /** @var PaymentService $PaymentService */
        $PaymentService = pluginApp(\AfterPay\Services\PaymentService::class);
        $redirectURL = $PaymentService->executePayment(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT, $additionalParams);

        return $this->response->redirectTo($redirectURL);
    }

    /**
     * Redirect to AfterPay Checkout
     * @param Twig $twig
     * @return string
     */
    public function checkoutConfirmContact(Twig $twig): string
    {
        $mode = $this->request->get('type');
        $paymentId = $this->request->get('paymentId');
        return $twig->render('AfterPay::Contact', [
            'data' => $this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_RESPONSE),
            'mode' => $mode,
            'paymentId' => $paymentId
        ]);

    }

    /**
     * Redirect to AfterPay Checkout
     * @param Twig $twig
     * @return string
     */
    public function checkoutError(Twig $twig): string
    {
        return $twig->render('AfterPay::Error', ['error' => $this->sessionStorage->getSessionValue(PaymentHelper::MODE_AFTERPAY_NOTIFICATION)]);

    }

    /**
     * Redirect to AfterPay Checkout
     * @param Twig $twig
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function checkout(Twig $twig)
    {

        if (empty($this->sessionStorage->getSessionValue(SessionStorageService::AfterPay_REQUEST)))
        {
            return $this->response->redirectTo("payment/afterPay/checkoutCancel/AFTERPAY");
        }
        /** @var Checkout $checkout */
        $checkout = pluginApp(\Plenty\Modules\Frontend\Contracts\Checkout::class);

        if ($checkout instanceof Checkout)
        {
            $paymentMethodId = $this->paymentHelper->getAfterPayMopIdByPaymentKey(PaymentHelper::PAYMENTKEY_AFTERPAY);
            if ($paymentMethodId > 0)
            {
                $checkout->setPaymentMethodId((int)$paymentMethodId);
            }
        }

        // get the AfterPay-express redirect URL
        /** @var PaymentService $PaymentService */
        $PaymentService = pluginApp(\AfterPay\Services\PaymentService::class);
        $redirectURL = $PaymentService->executePayment(PaymentHelper::PAYMENTKEY_AFTERPAY);

        return $this->response->redirectTo($redirectURL);
    }

    /**
     * Change the payment method in the basket when user select a none AfterPay plus method
     *
     * @param Checkout $checkout
     * @param Request $request
     */
    public function changePaymentMethod(Checkout $checkout, Request $request)
    {
        $paymentMethod = $request->get('paymentMethod');
        if (isset($paymentMethod) && $paymentMethod > 0)
        {
            $checkout->setPaymentMethodId($paymentMethod);
        }
    }

    /**
     * @param AfterPayInstallmentService $AfterPayInstallmentService
     * @param Twig $twig
     * @return string
     */
    public function calculateFinancingOptions(AfterPayInstallmentService $AfterPayInstallmentService, Twig $twig)
    {
        return $AfterPayInstallmentService->calculateFinancingCosts($twig);
    }

    /**
     * @param AfterPayInstallmentService $AfterPayInstallmentService
     * @param Twig $twig
     * @param Request $request
     * @return string
     */
    public function setFinancingOptions(AfterPayInstallmentService $AfterPayInstallmentService, Twig $twig, Request $request)
    {
        return $AfterPayInstallmentService->setFinancingOptions($twig, $request);
    }

    public function migratePaymentMethod()
    {
        $app = pluginApp(\AfterPay\Migrations\CreatePaymentMethod::class);
        return $app->run();
    }

    public function migrateAfterPayTable(Migrate $migrate)
    {
        $app = pluginApp(\AfterPay\Migrations\CreateAfterPayTables::class);
        return $app->run($migrate);
    }
}
