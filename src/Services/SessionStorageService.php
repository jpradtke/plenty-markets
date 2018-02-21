<?php //strict

namespace AfterPay\Services;

use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;

/**
 * Class SessionStorageService
 * @package AfterPay\Services
 */
class SessionStorageService
{
    const DELIVERY_ADDRESS_ID   = "deliveryAddressId";
    const BILLING_ADDRESS_ID    = "billingAddressId";
    const AfterPay_PAY_ID         = "AfterPayPayId";
    const AfterPay_PAYER_ID       = "AfterPayPayerId";
    const AfterPay_INSTALLMENT_CHECK = "checkAfterPayInstallmentCosts";
    const AfterPay_INSTALLMENT_COSTS = "offeredFinancingCosts";
    const AfterPay_REQUEST = "requestbodytopost";
    const AfterPay_COUNTRY = 'country';
    const AfterPay_RESPONSE = 'responseToRequest';
    const AfterPay_ID = "AfterPayId";

    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $sessionStorage;

    /**
     * SessionStorageService constructor.
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     */
    public function __construct(FrontendSessionStorageFactoryContract $sessionStorage)
    {
        $this->sessionStorage = $sessionStorage;
    }

    /**
     * Set the session value
     *
     * @param string $name
     * @param $value
     */
    public function setSessionValue(string $name, $value)
    {
        $this->sessionStorage->getPlugin()->setValue($name, $value);
    }

    /**
     * Get the session value
     *
     * @param string $name
     * @return mixed
     */
    public function getSessionValue(string $name)
    {
        return $this->sessionStorage->getPlugin()->getValue($name);
    }
}
