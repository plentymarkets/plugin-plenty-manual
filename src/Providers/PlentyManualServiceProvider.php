<?php //strict

namespace PlentyManual\Providers;

use Plenty\Modules\System\Contracts\WebstoreConfigurationRepositoryContract;
use Plenty\Modules\Webshop\Consent\Contracts\ConsentRepositoryContract;
use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Templates\Twig;
use PlentyManual\Extensions\TwigManualExtension;
use PlentyManual\Helpers\ResultsStorage;

/**
 * Class PlentyManualServiceProvider
 * @package PlentyManual\Providers
 */
class PlentyManualServiceProvider extends ServiceProvider
{
    /**
     * Register the core functions
     */
    public function register()
    {
        $this->getApplication()->register(PlentyManualRouteServiceProvider::class);
        $this->getApplication()->singleton(ResultsStorage::class);
    }

    /**
     * boot twig extensions and services
     * @param Twig $twig
     */
    public function boot(Twig $twig)
    {
        $this->registerConsents();

        $twig->addExtension('Twig_Extensions_Extension_Intl');
        $twig->addExtension( TwigManualExtension::class );
    }

    /**
     * Register cookie consents
     */
    private function registerConsents()
    {
        /** @var ConsentRepositoryContract $consentRepository */
        $consentRepository = pluginApp(ConsentRepositoryContract::class);
        $consentRepository->registerConsentGroup(
            'necessary',
            'PlentyManual::dataProcessingConsent.consentGroupNecessaryLabel',
            [
                'position' => 0,
                'necessary' => true,
                'description' => 'PlentyManual::dataProcessingConsent.consentGroupNecessaryDescription'
            ]
        );

        $consentRepository->registerConsentGroup(
            'tracking',
            'PlentyManual::dataProcessingConsent.consentGroupTrackingLabel',
            [
                'position' => 100,
                'description' => 'PlentyManual::dataProcessingConsent.consentGroupTrackingDescription'
            ]
        );

        /** @var WebstoreConfigurationRepositoryContract $webstoreRepository */
        $webstoreRepository = pluginApp(WebstoreConfigurationRepositoryContract::class);
        $webstoreConfig     = $webstoreRepository->findByPlentyId($this->getApplication()->getPlentyId());

        $consentRepository->registerConsent(
            'consent',
            'PlentyManual::dataProcessingConsent.consentConsentLabel',
            [
                'necessary' => true,
                'position' => 100,
                'description' => 'PlentyManual::dataProcessingConsent.consentConsentDescription',
                'provider' => 'PlentyManual::dataProcessingConsent.consentProvider',
                'lifespan' => $webstoreConfig->sessionLifetime > 0 ? '100 days' : 'Session',
                'group' => 'necessary',
                'cookieNames' => ['XDEBUG_SESSION']
            ]
        );

        $consentRepository->registerConsent(
            'session',
            'PlentyManual::dataProcessingConsent.consentSessionLabel',
            [
                'necessary' => true,
                'position' => 200,
                'description' => 'PlentyManual::dataProcessingConsent.consentSessionDescription',
                'provider' => 'PlentyManual::dataProcessingConsent.consentProvider',
                'lifespan' => $webstoreConfig->sessionLifetime > 0 ? '100 days' : 'Session',
                'group' => 'necessary'
            ]
        );

        $consentRepository->registerConsent(
            'googleAnalytics',
            'PlentyManual::dataProcessingConsent.consentGoogleAnalyticsLabel',
            [
                'description' => 'PlentyManual::dataProcessingConsent.consentGoogleAnalyticsDescription',
                'provider' => 'PlentyManual::dataProcessingConsent.consentGoogleAnalyticsProvider',
                'lifespan' => 'PlentyManual::dataProcessingConsent.consentGoogleAnalyticsLifespan',
                'policyUrl' => 'PlentyManual::dataProcessingConsent.consentGoogleAnalyticsPolicyUrl',
                'group' => 'tracking',
                'cookieNames' => ['_ga', '_gid', '_gat', '_gat_gtag_UA_925311_6']
            ]
        );
    }
}
