<?php

namespace PlentyManual\Services;

use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\ConfigRepository;
use PlentyManual\Helpers\ResultsStorage;

class SwitchLanguageService
{
    private $type;
    private $host;
    private $snippetLength;
    private $resultsStorage;
    private $lang;

    public function __construct( FrontendSessionStorageFactoryContract $sessionStorage, ConfigRepository $config, ResultsStorage $resultsStorage )
    {
        $this->lang = $sessionStorage->getLocaleSettings()->language ?? 'de';
        $this->type = "manual_" . $this->lang;
        $this->host = $config->get('PlentyManual.search.es_host');
        $this->snippetLength = $config->get('PlentyManual.search.snippet_length');
        $this->resultsStorage = $resultsStorage;
    }

    public function sLSearch(
        int $page = 1,
        int $itemPerPage = 25)
    {
        $idValues = $this->resultsStorage->getResults();
        $storageValues = array_values($idValues);

        $queryArray = [
            'bool' => [
                'should' => [],
            ]
        ];
        foreach($storageValues as $value)
        {
            $queryArray['bool']['should'][] = [
                'bool' => [
                    'must'=> [
                        'match' => [
                            'languageID' => $value
                        ],
                    ],
                ],
            ];
        }

        $query = [
            'query' => $queryArray
        ];

        $requestArray = array('host' => $this->host,
            'type' => $this->type,
            'query' => $query);
        $result = $this->serverCall($requestArray);
        return $this->readSearchResults($result, $page, $itemPerPage);
    }

    public function sLPage($languageID)
    {
        $url = "";

        $query = [
            "query" => [
                "query_string" => [ "query" => $languageID, "default_operator" => "AND"]
            ]
        ];

        $requestArray = array('host' => $this->host,
            'type' => $this->type,
            'query' => $query);

        $result = $this->serverCall($requestArray);

        $totalHits = $result["hits"]["total"];

        if($totalHits === 0)
        {
            return $url;
        }
        else
        {
            $url = $result["hits"]["hits"][0]["_source"]["url"];
        }

        return $url;

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
     * @param $page
     * @param $itemsPerPage
     * @return array
     */
    private function readSearchResults( $resultData, $page, $itemsPerPage )
    {
        $totalHits = $resultData["hits"]["total"];
        $maxPages = ($totalHits - $totalHits % $itemsPerPage ) / $itemsPerPage;

        if ( $totalHits % $itemsPerPage > 0 )
        {
            $maxPages++;
        }

        $hitsEnd = ($page * $itemsPerPage);

        if ( $hitsEnd > $totalHits )
        {
            $hitsEnd = $totalHits;
        }

        $result = [
            "currentPage" => $page,
            "itemsPerPage" => $itemsPerPage,
            "maxPages" => $maxPages,
            "hitsStart" => ($page - 1) * $itemsPerPage + 1,
            "hitsEnd" => $hitsEnd,
            "hitsTotal" => $totalHits,
            "hits" => []
        ];
        foreach( $resultData["hits"]["hits"] as $hit )
        {
            $doc = [
                "title" => $hit["_source"]["title"],
                "description" => $hit["_source"]["description"],
                "url" => $hit["_source"]["url"],
                "sections" => [],
                "cutBefore" => false,
                "cutAfter" => false
            ];

            $sections = [];

            foreach ( $hit["_source"]["sections"] as $inner_hit )
            {
                $sec = [
                    "id" => $inner_hit["id"],
                    "url" => $inner_hit["url"],
                    "title" => $inner_hit["title"],
                    "content" => $this->trim( $inner_hit["content"], $this->snippetLength ),
                    "cutBefore" => false,
                    "cutAfter" => false
                ];

                array_push( $sections, $sec );

            }

            $doc["sections"] = $sections;

            array_push( $result["hits"], $doc );

        }

        return $result;
    }

    /**
     * @param string|null $text
     * @param int $length
     * @return bool|null|string
     */
    function trim( string $text = null, int $length = 250 )
    {
        if ( $text === null )
        {
            return null;
        }
        while ( preg_match( '/^[^\wäöüÄÖÜß<>\/]/', $text ) )
        {
            $text = substr( $text, 1 );
        }
        while( preg_match( '/^[^\s]/', substr( $text, $length ) )  )
        {
            $length++;
        }
        return substr( $text, 0, $length );
    }
}