<?php


namespace PlentyManual\Services;

use Plenty\Plugin\ConfigRepository;
use PlentyManual\Helpers\Metadata;
use PlentyManual\Helpers\ManualHelper;

class BrokenLinks
{

    private $host;

    private $manualHelper;

    const RECIPIENTS = [
      'daniel.borza@plentydevelopment.com',
      'doku@plentymarkets.com'
    ];

    public function __construct(ConfigRepository $config,
                                ManualHelper $manualHelper
    )
    {
        $this->host = $config->get('PlentyManual.search.es_host');
        $this->manualHelper = $manualHelper;
    }

    /**
     * @return array
     */
    public function run()
    {
        $subject = "No Broken Links";

        $result = [
            "German" => [],
            "English" => []
        ];

        $break = "</br></br>";

        $result["German"] = $this->brokenLinksArray("de");

        $result["English"] = $this->brokenLinksArray("en");

        $mailContent = "English broken links:".$break;

        if(empty($result["English"]))
        {
            $mailContent .= "No broken links on English index!".$break;
        }
        else
        {
            foreach ($result["English"] as $link)
            {
                $mailContent .= $link.$break;
            }
        }

        $mailContent .= "German broken links:".$break;
        if(empty($result["German"]))
        {
            $mailContent .= "No broken links on German index!".$break;
        }
        else
        {
            foreach ($result["German"] as $link)
            {
                $mailContent .= $link.$break;
            }
        }

        if (\strpos($mailContent, 'http') !== false)
            $subject = "Broken Links";

        $this->manualHelper->sendMail($mailContent, self::RECIPIENTS, $subject);

        return $result;
    }

    /**
     * @param $lang
     * @return array
     */
    private function brokenLinksArray($lang)
    {
        $requestQuery = [
            "aggs" => [
                "whatever" => [
                    "terms" => [ "field" => "url", "size" => 1 ]
                ]
            ],
            "size" => 1000
        ];

        $type = "manual_".$lang;

        $requestArray = array('host' => $this->host,
            'type' => $type,
            'Query' => $requestQuery);



        $result = $this->Links($this->serverCall($requestArray), $lang);

        return $result;

    }

    /**
     * @param $requestArray
     * @return mixed
     */
    private function serverCall($requestArray)
    {
        $cHandle = curl_init( "{$requestArray['host']}/{$requestArray['type']}/_search?" );

        curl_setopt( $cHandle, CURLOPT_POST, true );
        curl_setopt( $cHandle, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $cHandle, CURLOPT_POSTFIELDS, json_encode( $requestArray['Query'] ) );

        $response = curl_exec( $cHandle );

        curl_close( $cHandle );

        $result = json_decode( $response, true );

        return $result;
    }

    /**
     * @param $resultData
     * @param $lang
     * @return array
     */
    private function Links($resultData, $lang)
    {
        $totalHits = $resultData["hits"]["total"];

        if ($totalHits == 0)
        {
            print "Error";
        }

        $result = [
            "urls" => []
        ];

        if($lang === "de")
            $langAdd = '';
        else
            $langAdd = '/'.$lang;

        foreach($resultData["hits"]["hits"] as $hit)
        {
            $doc = [
                "ID" => $hit["_id"],
                "url" => "https://knowledge.plentymarkets.com".$langAdd.$hit["_source"]["url"],
            ];

            array_push($result["urls"], $doc);
        }

        return $this->testLinks($result, $lang);

    }

    /**
     * @param $linksArray
     * @param $lang
     * @return array
     */
    private function testLinks($linksArray, $lang)
    {
        $brokenLinksList = array();

        $pages = $this->getPages($lang);

        foreach ($linksArray["urls"] as $url)
        {
            $checkUrl = $this->getPageByPath( $url["url"], $pages);

            if ($checkUrl === null)
                array_push($brokenLinksList, $url["url"]);
        }

        return $brokenLinksList;

    }

    /**
     * @param $lang
     * @return array
     */
    private function getPages($lang)
    {
        if ( $lang === "de" )
        {
            $pages = Metadata::DE;
        }
        else
        {
            $pages = Metadata::EN;
        }

        return $pages;
    }

    /**
     * @param string $path
     * @param $pages
     * @return null
     */
    private function getPageByPath( string $path, $pages )
    {
        $levels = explode( "/", $path );
        $page = null;
        foreach( $levels as $lvl )
        {
            foreach( $pages as $p )
            {
                $page = null;
                if( $p["urlName"] === $lvl )
                {
                    $page = $p;
                    $pages = $p["children"];
                    break;
                }
            }
        }

        return $page;
    }

}