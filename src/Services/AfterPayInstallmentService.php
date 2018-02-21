<?php

namespace AfterPay\Services;

use AfterPay\Helper\PaymentHelper;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Templates\Twig;

class AfterPayInstallmentService extends PaymentService
{
    /**
     * @param Basket $basket
     * @return string
     */
    public function getInstallmentContent(Basket $basket): string
    {
        return $this->getPaymentContent($basket, PaymentHelper::MODE_AFTERPAY_INSTALLMENT, ['fundingInstrumentType'=>'CREDIT']);
    }

    public function calculateFinancingCosts(Twig $twig)
    {
        /** @var BasketRepositoryContract $basketContract */
        $basketContract= pluginApp(BasketRepositoryContract::class);
        /** @var Basket $basket */
        $basket = $basketContract->load();
        $qualifyingFinancingOptions = [];
        $financingOptions = $this->getFinancingOptions($basket);

        $qualifyingFinancingOptions = $financingOptions;
        if(is_array($financingOptions) && array_key_exists('availableInstallmentPlans', $financingOptions))
        {
            $qualifyingFinancingOptions = $financingOptions['availableInstallmentPlans'];
//                if(is_array($financingOptions['financing_options'][0]) && is_array(($financingOptions['financing_options'][0]['qualifying_financing_options'])))
//                {
//                    $starExample = [];
//                    /**
//                     * Sort the financing options
//                     * lowest APR and than lowest rate
//                     */
//                    foreach ($financingOptions['financing_options'][0]['qualifying_financing_options'] as $financingOption)
//                    {
//                        $starExample[$financingOption['monthly_payment']['value']] = str_pad($financingOption['credit_financing']['term'],2,'0', STR_PAD_LEFT).'-'.$financingOption['credit_financing']['apr'];
//                        $qualifyingFinancingOptions[str_pad($financingOption['credit_financing']['term'],2,'0', STR_PAD_LEFT).'-'.$financingOption['credit_financing']['apr'].'-'.$financingOption['monthly_payment']['value']] = $financingOption;
//                    }
//
//                    ksort($starExample);
//                    $highestApr = 0;
//                    $lowestRate = 99999999;
//                    $usedTerm = 0;
//                    foreach ($starExample as $montlyRate => $termApr)
//                    {
//                        $termApr = explode('-', $termApr);
//                        $term = $termApr[0];
//                        $apr = $termApr[1];
//                        if($apr >= $highestApr && $montlyRate < $lowestRate)
//                        {
//                            $highestApr = $apr;
//                            $lowestRate = $montlyRate;
//                            $usedTerm = $term;
//                        }
//                    }
//                    $qualifyingFinancingOptions[$usedTerm.'-'.$highestApr.'-'.$lowestRate]['star'] = true;
//
//                    ksort($qualifyingFinancingOptions);
//                }
        }

        return $twig->render('AfterPay::AfterPayInstallment.InstallmentOverlay', ['basketAmount'=>$basket->basketAmountNet, 'financingOptions'=>$qualifyingFinancingOptions, 'merchantName'=>'Testfirma', 'merchantAddress'=>'Teststraße 1, 34117 Kassel']);

    }

    public function setFinancingOptions(Twig $twig, Request $request){
        $sessionStorageService = $this->getSessionStorage();
        /** @var BasketRepositoryContract $basketContract */
        $basketContract= pluginApp(BasketRepositoryContract::class);
        /** @var Basket $basket */
        $basket = $basketContract->load();
        $financingOption = json_decode($request->get('installment'),true);
        $sessionStorageService->setSessionValue(SessionStorageService::AfterPay_INSTALLMENT_COSTS,$financingOption);
        $params = [];
        $params['basketItemAmount'] = $basket->itemSum;
        $params['basketShippingAmount'] = $basket->shippingAmount;
        $params['basketAmountNet'] = $basket->basketAmountNet;
        $params['basketAmountGro'] = $basket->basketAmount;
        $params['currency'] = $basket->currency;
        $params['financingOption'] = $sessionStorageService->getSessionValue(SessionStorageService::AfterPay_INSTALLMENT_COSTS);
//        $params['paymentId'] = $sessionStorageService->getSessionValue(SessionStorageService::AfterPay_PAY_ID);
//        $params['payerId'] = $sessionStorageService->getSessionValue(SessionStorageService::AfterPay_PAYER_ID);
        return $twig->render('AfterPay::AfterPayInstallment.InstallmentOverview',$params);
    }

    /**
     * Get the financing options for the given amount
     *
     * @param Basket $basket
     * @return array
     */
    public function getFinancingOptions(Basket $basket)
    {
        return $this->post(PaymentHelper::PAYMENTKEY_AFTERPAYINSTALLMENT,$basket->shippingCountryId,'/api/v3/lookup/installment-plans',['amount'=>$basket->basketAmount]);
    }

    /**
     * Load the financing costs from the AfterPay payment details
     *
     * @param $paymentId
     * @param string $mode
     * @return mixed|null
     */
    public function getFinancingCosts($paymentId, $mode=PaymentHelper::MODE_AFTERPAY_INSTALLMENT)
    {
        $response = $this->getPaymentDetails($paymentId, $mode);

        if(is_array($response) && array_key_exists('credit_financing_offered', $response))
        {
            return $response['credit_financing_offered'];
        }
        return null;
    }
}