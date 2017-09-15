<?php //strict

namespace PlentyManual\Services;

use Plenty\Plugin\ConfigRepository;
use IO\Services\SessionStorageService;
use Plenty\Plugin\Log\Loggable;

class SearchService
{
    use Loggable;
    
    private $type;
    private $host;
    private $snippetLength;

    public function __construct( SessionStorageService $sessionStorage, ConfigRepository $config )
    {
        $this->type = "manual_" . $sessionStorage->getLang();
        $this->host = $config->get('PlentyManual.search.es_host');
        $this->snippetLength = (int)$config->get('PlentyManual.search.snippet_length');
    }

    public function search(
        string $query,
        int $page = 1,
        int $itemsPerPage = 3,
        bool $conjunctive = true
    )
    {
        $operator = $conjunctive ? "and" : "or";
        $sectionQuery = [
            "nested" => [
                "path" => "sections",
                "query" => [
                    "dis_max" => [
                        "queries" => [
                            [
                                "match" => [
                                    "sections.title" => [ "query" => $query, "operator" => $operator ]
                                ]
                            ],
                            [
                                "match" => [
                                    "sections.content" => [ "query" => $query, "operator" => $operator ]
                                ]
                            ]
                        ],
                        "tie_breaker" => 0.3
                    ]
                ],
                "inner_hits" => [
                    "_source" => [
                        "includes" => [ "id", "url", "title", "content" ]
                    ]
                ]
            ]
        ];

        $documentQuery = [
            "dis_max" => [
                "queries" => [
                    [
                        "match" => [
                            "title" => [ "query" => $query, "operator" => $operator ]
                        ]
                    ],
                    [
                        "match" => [
                            "description" => [ "query" => $query, "operator" => $operator ]
                        ]
                    ]
                ],
                "tie_breaker" => 0.3
            ],
        ];

        $mainQuery = [
            "_source" => ["url", "title", "description"],
            "query" => [
                "dis_max" => [
                    "queries" => [
                        $sectionQuery,
                        $documentQuery
                    ],
                    "tie_breaker" => 0.3
                ]
            ]
        ];
        
        $from = ($page - 1) * $itemsPerPage ;
        $url = "{$this->host}/{$this->type}/_search?size={$itemsPerPage}&from={$from}";
        
        $cHandle = curl_init( $url );
    
        $this->getLogger("search_result")->error('url', ['value' => $url]);
        
        curl_setopt( $cHandle, CURLOPT_POST, true );
        curl_setopt( $cHandle, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $cHandle, CURLOPT_POSTFIELDS, json_encode( $mainQuery ) );

        $response = curl_exec( $cHandle );

        curl_close( $cHandle );

        $result = json_decode( $response, true );
        
        $this->getLogger("search_result")->error('items_per_page', ['value' => $itemsPerPage]);
        $this->getLogger("search_result")->error('response', ['value' => $response]);
        $this->getLogger("search_result")->error('result', ['value' => $result]);
        
        return $this->readSearchResults( $result, $page, $itemsPerPage );
    }

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
                "title" => $hit["highlight"]["title"][0],
                "description" => $hit["highlight"]["description"][0],
                "url" => $hit["_source"]["url"],
                "sections" => [],
                "cutBefore" => false,
                "cutAfter" => false
            ];

            if ( $doc["title"] === null )
            {
                $doc["title"] = $hit["_source"]["title"];
            }
            if ( $doc["description"] === null )
            {
                $doc["description"] = $this->trim( $hit["_source"]["description"], $this->snippetLength );
            }

//            if ( strip_tags( $doc["description"] ) !== substr( $hit["_source"]["description"], 0, strlen( strip_tags( $doc["description"] ) ) ) )
            if ( !$this->startsWith( $doc["description"], $hit["_source"]["description"] ) )
            {
                $doc["cutBefore"] = true;
            }

//            if ( strip_tags( $doc["description"] ) !== substr( $hit["_source"]["description"], strlen($hit["_source"]["description"]) - strlen( strip_tags( $doc["description"] ) ) ) )
            if ( !$this->endsWith( $doc["description"], $hit["_source"]["description"] ) )
            {
                $doc["cutAfter"] = true;
            }



            $sections = [];
            foreach ( $hit["inner_hits"]["sections"]["hits"]["hits"] as $inner_hit )
            {
                $sec = [
                    "id" => $inner_hit["_source"]["id"],
                    "url" => $inner_hit["_source"]["url"],
                    "title" => $inner_hit["highlight"]["sections.title"][0],
                    "content" => $this->trim( $inner_hit["highlight"]["sections.content"][0], $this->snippetLength ),
                    "cutBefore" => false,
                    "cutAfter" => false
                ];

                if ( $sec["title"] === null )
                {
                    $sec["title"] = $inner_hit["_source"]["title"];
                }
                if ( $sec["content"] === null )
                {
                    $sec["content"] = $this->trim( $inner_hit["_source"]["content"], $this->snippetLength );
                }

//                if ( trim( strip_tags( $sec["content"] ) ) !== substr( trim($inner_hit["_source"]["content"]), 0, strlen( trim( strip_tags( $sec["content"] ) ) ) ) )
                if ( !$this->startsWith( $sec["content"], $inner_hit["_source"]["content"] ) )
                {
                    $sec["cutBefore"] = true;
                }

//                if ( trim( strip_tags( $sec["content"] ) ) !== substr( $inner_hit["_source"]["content"], strlen( trim( $inner_hit["_source"]["content"]) ) - strlen( trim( strip_tags( $sec["content"] ) ) ) ) )
                if ( !$this->endsWith( $sec["content"], $inner_hit["_source"]["content"] ) )
                {
                    $sec["cutAfter"] = true;
                }

                array_push( $sections, $sec );
            }

            $doc["sections"] = $sections;
            array_push( $result["hits"], $doc );
        }

        return $result;
    }

    function startsWith( $str, $cmp )
    {
        $str = trim( strip_tags( $str ) );
        $cmp = trim( strip_tags( $cmp ) );
        return $str === substr( $cmp, 0, strlen( $str ) );
    }

    function endsWith( $str, $cmp )
    {
        $str = trim( strip_tags( $str ) );
        $cmp = trim( strip_tags( $cmp ) );
        return $str === substr( $cmp, strlen( $cmp ) - strlen( $str ) );
    }

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