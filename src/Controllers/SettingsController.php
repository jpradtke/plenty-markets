<?php
/**
 * Created by IntelliJ IDEA.
 * User: jkonopka
 * Date: 24.11.16
 * Time: 15:01
 */

namespace AfterPay\Controllers;

use AfterPay\Services\Database\SettingsService;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use Plenty\Modules\System\Contracts\WebstoreConfigurationRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;

class SettingsController extends Controller
{
    /**
     * @var SettingsService
     */
    private $settingsService;


    /**
     * SettingsController constructor.
     * @param SettingsService $settingsService
     * @param Migrate $migrate
     */
    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * @param Request $request
     * @return int
     */
    public function saveSettings(Request $request)
    {
        /** @var \Plenty\Modules\Plugin\DataBase\Contracts\Migrate $migrate */
//        return json_encode($request->get('settings'));
        if ($request->get('AfterPayMode') == 'afterPay' || $request->get('AfterPayMode') == 'afterPay_installment') {
            return $this->settingsService->saveSettings($request->get('AfterPayMode'), $request->get('settings'));
        }
    }

    /**
     * @return bool|mixed
     */
    public function loadSettings($settingType)
    {
        return $this->settingsService->loadSettings($settingType);
    }

    /**
     * Load the settings for one webshop
     *
     * @param $webstore
     * @return null
     */
    public function loadSetting($webstore, $mode, $country)
    {
        return $this->settingsService->loadSetting($webstore, $mode, $country);
    }

    public function getActiveLanguageList($plentyID)
    {
        /** @var WebstoreConfigurationRepositoryContract $webstoreConfig */
        $webstoreConfig = pluginApp(WebstoreConfigurationRepositoryContract::class);
        $webstoreConfig = $webstoreConfig->findByPlentyId($plentyID);
        return json_encode($webstoreConfig->languageList);
    }
}