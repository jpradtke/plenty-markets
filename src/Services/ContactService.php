<?php //strict

namespace AfterPay\Services;

use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Account\Address\Models\AddressOption;
use Plenty\Modules\Account\Address\Models\AddressRelationType;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Contact\Contracts\ContactAddressRepositoryContract;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Models\Country;

/**
 * Class ContactService
 * @package AfterPay\Services
 */
class ContactService
{
    /**
     * @var AddressRepositoryContract
     */
    private $addressContract;

    /**
     * @var Checkout
     */
    private $checkout;

    /**
     * ContactService constructor.
     */
    public function __construct(AddressRepositoryContract $addressRepositoryContract,
        Checkout $checkout)
    {
        $this->addressContract = $addressRepositoryContract;
        $this->checkout = $checkout;
    }

    /**
     * @param array $payer
     */
    public function handleAfterPayContact($payer)
    {
        if(isset($payer['addressList']) && !empty($payer['addressList']))
        {
            $rawAddress = $payer['addressList'][0];
            $rawAddress['customerNumber'] = $payer['customerNumber'];
            $rawAddress['firstName'] = $payer['firstName'];
            $rawAddress['lastName'] = $payer['lastName'];
            /**
             * Map the AfterPay address to a plenty address
             * @var Address $address
             */
            $address = $this->mapAPAddressToAddress($rawAddress);

            /** @var AccountService $accountService */
            $accountService = pluginApp(\Plenty\Modules\Frontend\Services\AccountService::class);

            $contactId = $accountService->getAccountContactId();

            // if the user is logged in, update the contact address
            if(!empty($contactId) && $contactId > 0)
            {
                /** @var ContactAddressRepositoryContract $contactAddress */
                $contactAddress = pluginApp(\Plenty\Modules\Account\Contact\Contracts\ContactAddressRepositoryContract::class);

                $createdAddress = $contactAddress->createAddress($address->toArray(), $contactId, AddressRelationType::DELIVERY_ADDRESS);
            }
            // if the user is a guest, create a address and set the invoice address ID
            else
            {
                $createdAddress = $this->addressContract->createAddress($address->toArray());

                if(empty($this->checkout->getCustomerInvoiceAddressId()))
                {
                    // set the customer invoice address ID
                    $this->checkout->setCustomerInvoiceAddressId($createdAddress->id);
                }
            }

            // update/set the customer shipping address ID
            $this->checkout->setCustomerShippingAddressId($createdAddress->id);
        }
    }

    /**
     * @param $shippingAddress
     * @param $email
     * @return Address
     */
    private function mapAPAddressToAddress($shippingAddress)
    {
        /** @var Address $address */
        $address = pluginApp(\Plenty\Modules\Account\Address\Models\Address::class);

//        $name = explode(' ', $shippingAddress['recipient_name']);
//        $street = explode(' ', $shippingAddress['line1']);

        /** @var CountryRepositoryContract $countryContract */
        $countryContract = pluginApp(\Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract::class);

        /** @var Country $country */
        $country = $countryContract->getCountryByIso($shippingAddress['countryCode'], 'isoCode2');

        $address->name2 = $shippingAddress['firstName'];
        $address->name3 = $shippingAddress['lastName'];

        $address->address1 = $shippingAddress['street'];
        $address->houseNumber = $shippingAddress['streetNumber'];
        $address->town = $shippingAddress['postalPlace'];
        $address->postalCode = $shippingAddress['postalCode'];
        $address->countryId = $country->id;

        $addressOptions = [];

        /** @var AddressOption $addressOption */
        $addressOption = pluginApp(\Plenty\Modules\Account\Address\Models\AddressOption::class);

        $addressOption->typeId = AddressOption::TYPE_EXTERNAL_ID;
        $addressOption->value = $shippingAddress['customerNumber'];

        $addressOptions[] = $addressOption->toArray();

        $address->options = $addressOptions;

        return $address;
    }
}