<?php


namespace PlentyManual\Services;

use Plenty\Plugin\ConfigRepository;
use PlentyManual\Helpers\Metadata;
use Plenty\Plugin\Mail\Contracts\MailerContract;


class BrokenLinks
{

    /**
     * @var host
     */
    private $host;

    /**
     * @var MailerContract
     */
    private $mailerContract;

    private $recipients;

    private $recipients_dev;

    public function __construct(ConfigRepository $config,
                                MailerContract $mailerContract
    )
    {
        $this->host = $config->get('PlentyManual.search.es_host');
        $this->mailerContract = $mailerContract;
        $this->recipients = $config->get('PlentyManual.broken_links.email_productive');
        $this->recipients_dev = $config->get('PlentyManual.broken_links.email_development');
    }

    /**
     * @return array
     */
    public function run()
    {
        $result = [
            "German" => [],
            "English" => []
        ];

        $break = "</br></br>";

        $result["German"] = $this->brokenLinksArray("de");

        $result["English"] = $this->brokenLinksArray("en");

        if(empty($result["English"]))
        {
            $mailContent = "No broken links on English index!".$break;
            $mailContentDev = "No broken links on English index!".$break;
        }
        else
        {
            $nbOfLinks = count($result["English"]);
            $mailContent = "English broken links: ". $nbOfLinks .$break;
            $mailContentDev = "English broken links: ". $nbOfLinks .$break;

            foreach ($result["English"] as $link)
            {
                $mailContent .= $link["url"].$break;
                $mailContentDev .= $link["url"].$break;
                $mailContentDev .= $link["ID"].$break;
            }
        }

        if(empty($result["German"]))
        {
            $mailContent .= "No broken links on German index!".$break;
            $mailContentDev .= "No broken links on German index!".$break;
        }
        else
        {
            $nbOfLinks = count($result["German"]);
            $mailContent .= "German broken links: ". $nbOfLinks .$break;
            $mailContentDev .= "German broken links: ". $nbOfLinks .$break;

            foreach ($result["German"] as $link)
            {
                $mailContent .= $link["url"].$break;
                $mailContentDev .= $link["url"].$break;
                $mailContentDev .= $link["ID"].$break;
            }
        }

        if (\strpos($mailContent, 'http') !== false)
        {
            $subject = "Broken Links";
            $this->mailerContract->sendHtml($mailContentDev, $this->recipients_dev, $subject);
            $this->mailerContract->sendHtml($mailContent, $this->recipients, $subject);
        }

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
            'query' => $requestQuery);



        $result = $this->links($this->serverCall($requestArray), $lang);

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
        curl_setopt( $cHandle, CURLOPT_POSTFIELDS, json_encode( $requestArray['query'] ) );

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
    private function links($resultData, $lang)
    {
        $totalHits = $resultData["hits"]["total"];

        if ($totalHits == 0)
        {
            print "Error";
        }

        $result = [
            "urls" => []
        ];

        foreach($resultData["hits"]["hits"] as $hit)
        {
            $doc = [
                "ID" => $hit["_id"],
                "url" => "https://knowledge.plentymarkets.com".$hit["_source"]["url"],
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
                array_push($brokenLinksList, $url);
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