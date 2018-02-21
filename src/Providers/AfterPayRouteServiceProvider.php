<?php // strict

namespace AfterPay\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;
use Plenty\Plugin\Routing\ApiRouter;

/**
 * Class AfterPayRouteServiceProvider
 * @package AfterPay\Providers
 */
class AfterPayRouteServiceProvider extends RouteServiceProvider
{
    /**
     * @param Router $router
     * @param ApiRouter $apiRouter
     */
    public function map(Router $router, ApiRouter $apiRouter)
    {
        // AfterPay-Settings routes
        $apiRouter->version(['v1'], ['namespace' => 'AfterPay\Controllers', 'middleware' => 'oauth'],
            function ($apiRouter)
            {
                /** @var ApiRouter $apiRouter */
                $apiRouter->post('payment/afterPay/settings/', 'SettingsController@saveSettings');
                $apiRouter->get('payment/afterPay/settings/{settingType}', 'SettingsController@loadSettings');
                $apiRouter->get('payment/afterPay/setting/{webstore}/{countryid}', 'SettingsController@loadSetting');
                $apiRouter->get('payment/afterPay/languages/{webstore}', 'SettingsController@getActiveLanguageList');

            });

        // Debugging routes
        $router->get('payment/afterPay/migrate/PaymentMethod', 'AfterPay\Controllers\PaymentController@migratePaymentMethod');
        $router->get('payment/afterPay/checkout', 'AfterPay\Controllers\PaymentController@checkout');

        // Get the AfterPay success and cancellation URLs
        $router->get('payment/afterPay/checkoutSuccess/{mode}', 'AfterPay\Controllers\PaymentController@checkoutSuccess');
        $router->get('payment/afterPay/checkoutCancel/{mode}' , 'AfterPay\Controllers\PaymentController@checkoutCancel');

        // Get the AfterPay order checkout
        $router->get('payment/afterPay/contact/', 'AfterPay\Controllers\PaymentController@checkoutConfirmContact');
        $router->get('payment/afterPay/error/', 'AfterPay\Controllers\PaymentController@checkoutError');

        // Routes for the AfterPay Installment
        $router->get('payment/afterPayInstallment/financingOptions/', 'AfterPay\Controllers\PaymentController@calculateFinancingOptions');
        $router->post('payment/afterPayInstallment/financingOptions/', 'AfterPay\Controllers\PaymentController@setFinancingOptions');
        $router->get('payment/afterPay/prepareInstallment', 'AfterPay\Controllers\PaymentController@prepareInstallment');
    }
}
