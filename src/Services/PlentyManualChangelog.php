<?php

namespace PlentyManual\Services;

use Plenty\Plugin\CachingRepository;
use Plenty\Plugin\ConfigRepository;
use PlentyManual\Models\ChangelogEntry;

/**
 * Class PlentyManualChangelog
 * @package PlentyManual\Services
 */
class PlentyManualChangelog
{
    const FORUM_HOST = 'https://forum.plentymarkets.com';

    const MAX_CHANGELOG_ENTRIES = 5;

    /**
     * @var array
     */
    private static $tags = ['doku'];

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var CachingRepository
     */
    private $cachingRepository;

    /**
     * PluginChangelog constructor.
     * @param ConfigRepository $configRepository
     * @param CachingRepository $cachingRepository
     */
    public function __construct(ConfigRepository $configRepository, CachingRepository $cachingRepository)
    {
        $this->configRepository  = $configRepository;
        $this->cachingRepository = $cachingRepository;
    }

    /**
     * @return
     */
    public function getChangelog()
    {
        return $this->cachingRepository->remember(
            'manual.changelog',
            5,
            function () {
                $apiKey      = $this->configRepository->get('PlentyManual.discourse_api_key');
                $apiUsername = $this->configRepository->get('PlentyManual.discourse_api_username');

                $url = self::FORUM_HOST . '/search.json?q=%23changelog%20tags%3Adoku' .
                    '&api_key=' . $apiKey .
                    '&api_username=' . $apiUsername;

                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                ]);

                $output = curl_exec($curl);
                curl_close($curl);

                $output = json_decode($output, true);

                $result = $output['topics'];

                if (is_array($result)) {
                    $counter = 0;
                    foreach ($result as $topic) {
                        /** @var ChangelogEntry $changelogEntry */
                        $changelogEntry = pluginApp(ChangelogEntry::class);
                        $changelogEntry->setTitle($topic['title']);
                        $changelogEntry->setUrl(self::FORUM_HOST . '/t/' . $topic['slug'] . '/' . $topic['id']);
                        $changelogEntry->setDateString($topic['created_at']);

                        //TODO change to array_intersect after added to plugin api
                        $changelogEntry->setTags(
                            array_filter($topic['tags'], function ($tag) {
                                return in_array($tag, self::$tags);
                            })
                        );

                        $changelogEntries[] = $changelogEntry;
                        $counter++;

                        if ($counter > self::MAX_CHANGELOG_ENTRIES) {
                            break;
                        }
                    }
                }

                return $changelogEntries;
            }
        );
    }
}