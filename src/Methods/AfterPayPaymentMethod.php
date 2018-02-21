<?php // strict

namespace AfterPay\Methods;

use AfterPay\Services\PaymentService;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Category\Contracts\CategoryRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodService;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Plugin\Application;

/**
 * Class AfterPayPaymentMethod
 * @package AfterPay\Methods
 */
class AfterPayPaymentMethod extends PaymentMethodService
{
    /**
     * @var BasketRepositoryContract
     */
    private $basketRepo;

    /**
     * @var Checkout
     */
    private $checkout;

    /**
     * @var ConfigRepository
     */
    private $configRepo;

    /**
     * @var PaymentService
     */
    private $paymentService;

    /**
     * AfterPayExpressPaymentMethod constructor.
     *
     * @param BasketRepositoryContract $basketRepo
     * @param ConfigRepository $configRepo
     * @param Checkout $checkout
     * @param PaymentService $paymentService
     */
    public function __construct(BasketRepositoryContract $basketRepo,
                                ConfigRepository $configRepo,
                                Checkout $checkout,
                                PaymentService $paymentService)
    {
        $this->basketRepo = $basketRepo;
        $this->configRepo = $configRepo;
        $this->checkout = $checkout;
        $this->paymentService = $paymentService;
        /* load settings by country */
        $this->paymentService->loadCurrentSettings('AfterPay', $this->checkout->getShippingCountryId());
    }

    /**
     * Check whether the plugin is active
     *
     * @return bool
     */
    public function isActive(BasketRepositoryContract $basketRepositoryContract)
    {

        /**
         * Check if country has settings
         */
        if (empty($this->paymentService->settings))
        {
            return false;
        }

        /**
         * Check if an xauthkey is set
         */
        if (empty($this->paymentService->settings['xauthKey']))
        {
            return false;
        }

        if ($this->paymentService->settings['available'] != 1)
        {
            return false;
        }


        /** @var Basket $basket */
        $basket = $basketRepositoryContract->load();

        /**
         * Check the minimum amount
         */
        if ($this->paymentService->settings['minOrderAmount'] > 0.00 &&
            $basket->basketAmount < $this->paymentService->settings['minOrderAmount'])
        {
            return false;
        }

        /**
         * Check the maximum amount
         */
        if ($this->paymentService->settings['maxOrderAmount'] > 0.00 &&
            $this->paymentService->settings['maxOrderAmount'] < $basket->basketAmount)
        {
            return false;
        }

        return true;
    }

    /**
     * Get the name of the plugin
     *
     * @return string
     */
    public function getName()
    {
//        return json_encode($this->paymentService->settings);
        $name = '';

        /** @var FrontendSessionStorageFactoryContract $session */
        $session = pluginApp(FrontendSessionStorageFactoryContract::class);
        $lang = $session->getLocaleSettings()->language;
        if (array_key_exists('language', $this->paymentService->settings))
        {
            if (array_key_exists($lang, $this->paymentService->settings['language']))
            {
                if (array_key_exists('title', $this->paymentService->settings['language'][$lang]))
                {
                    $name = $this->paymentService->settings['language'][$lang]['title'];
                }
            }
        }

        if (!strlen($name))
        {
            $name = 'AfterPay';
        }
        return $name;
    }

    /**
     * Get additional costs for AfterPay.
     * AfterPay did not allow additional costs
     *
     * @return float
     */
    public function getFee(BasketRepositoryContract $basketRepositoryContract): float
    {
        return 0.00;
    }

    /**
     * Get the path of the icon
     *
     * @return string
     */
    public function getIcon(): string
    {
        /** @var Application $app */
        $app = pluginApp(Application::class);
        /** @var FrontendSessionStorageFactoryContract $session */
        $path = $app->getUrlPath('AfterPay') . '/images/logos/';
        $icon = $path . 'AfterPay_logo.svg';

        if (array_key_exists('logo', $this->paymentService->settings))
        {
            /*
            Please note that the original logo is the default logo.
            The other logotypes should only be considered if the readability is not ensured or if the colours are incompatible.
            */
            switch ($this->paymentService->settings['logo'])
            {
                case 1:
                    $icon = $path . 'AfterPay_logo_black.svg';
                    break;
                case 2:
                    $icon = $path . 'AfterPay_logo_grey.svg';
                    break;
                case 3:
                    $icon = $path . 'AfterPay_logo_white.svg';
                    break;
            }
        }

        return $icon;
    }

    /**
     * Get the description of the payment method. The description can be entered in the config.json.
     *
     * @return string
     */
    public function getDescription(): string
    {
        /** @var FrontendSessionStorageFactoryContract $session */
        $session = pluginApp(FrontendSessionStorageFactoryContract::class);
        $lang = $session->getLocaleSettings()->language;

        if (array_key_exists('language', $this->paymentService->settings) &&
            array_key_exists($lang, $this->paymentService->settings['language']) &&
            array_key_exists('description', $this->paymentService->settings['language'][$lang]))
        {
            return $this->paymentService->settings['language'][$lang]['description'];
        }
        return '';
    }

    /**
     * Get SourceUrl
     *
     * @return string
     */
    public function getSourceUrl(): string
    {
        /** @var FrontendSessionStorageFactoryContract $session */
        $session = pluginApp(FrontendSessionStorageFactoryContract::class);
        $lang = $session->getLocaleSettings()->language;

        if (array_key_exists('language', $this->paymentService->settings) &&
            array_key_exists($lang, $this->paymentService->settings['language']))
        {
            if (array_key_exists('infoPage', $this->paymentService->settings['language'][$lang]))
            {
                switch ($this->paymentService->settings['language'][$lang]['infoPage'])
                {
                    case 1:
                        if (array_key_exists('infoPageId', $this->paymentService->settings['language'][$lang]))
                        {
                            // internal
                            $categoryId = (int)$this->paymentService->settings['language'][$lang]['infoPageId'];
                            if ($categoryId > 0)
                            {
                                /** @var CategoryRepositoryContract $categoryContract */
                                $categoryContract = pluginApp(CategoryRepositoryContract::class);
                                return $categoryContract->getUrl($categoryId, $lang);
                            }
                        }
                        return '';
                    case 2:
                        if (array_key_exists('infoPageUrl', $this->paymentService->settings['language'][$lang]))
                        {
                            $url = $this->paymentService->settings['language'][$lang]['infoPageUrl'];
                            if(strpos($url,'http')!==0){
                                $url = 'http://'.ltrim($url,'/: ');
                            }
                            return $url;
                        }
                        return '';
                    default:
                        return 'no valid infoPage set for '.$lang;
                }
            }
        }
        return '';
    }


    /**
     * Check if it is allowed to switch to this payment method
     *
     * @return bool
     */
    public function isSwitchableTo(): bool
    {
        return true;
    }

    /**
     * Check if it is allowed to switch from this payment method
     *
     * @return bool
     */
    public function isSwitchableFrom(): bool
    {
        return true;
    }
}
