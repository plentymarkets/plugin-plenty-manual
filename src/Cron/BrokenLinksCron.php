<?php

namespace PlentyManual\Cron;


use PlentyManual\Helpers\ManualHelper;
use PlentyManual\Services\BrokenLinks;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Cron\Contracts\CronHandler;


class BrokenLinksCron extends CronHandler
{
    const CLASS_NAME = 'BrokenLinksCron';

    use Loggable;

    /**
     * @var ManualHelper
     */
    private $manualHelper;

    /**
     * BrokenLinksCron constructor.
     *
     * @param ManualHelper $manualHelper
     */
    public function __construct(ManualHelper $manualHelper)
    {
        $this->manualHelper = $manualHelper;
    }

    /**
     * @param BrokenLinks $brokenLinksService
     */
    public function handle(BrokenLinks $brokenLinksService)
    {

        $this->getLogger(self::CLASS_NAME . '\\' . __FUNCTION__)->info('PlentyManual::migration.startCron');

        try {
                $brokenLinksService->run();

        } catch (\Exception $ex) {

        }

        $this->getLogger(self::CLASS_NAME . '\\' . __FUNCTION__)->info('PlentyManual::migration.endCron');

    }

}