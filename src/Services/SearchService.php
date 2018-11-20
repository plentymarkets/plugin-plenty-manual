<?php //strict

namespace PlentyManual\Services;

use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\ConfigRepository;

class SearchService
{
    private $type;
    private $host;
    private $snippetLength;

    public function __construct( FrontendSessionStorageFactoryContract $sessionStorage, ConfigRepository $config )
    {
        $lang = $sessionStorage->getLocaleSettings()->language ?? 'de';
        $this->type = "manual_" . $lang;
        $this->host = $config->get('PlentyManual.search.es_host');
        $this->snippetLength = $config->get('PlentyManual.search.snippet_length');
    }

    /**
     * @param string $query
     * @param int $page
     * @param int $itemsPerPage
     * @param bool $conjunctive
     * @return array
     */
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
                    ],
                    "highlight" => [
                        "pre_tags" => ["<em class=\"search-item-keyword\">"],
                        "post_tags" => ["</em>"],
                        "fields" => [
                            "sections.title" => ["number_of_fragments" => 0],
                            "sections.content" => ["number_of_fragments" => 1, "fragment_size" => $this->snippetLength]
                        ]
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
            ],
            "highlight" => [
                "pre_tags" => ["<em class=\"search-item-keyword\">"],
                "post_tags" => ["</em>"],
                "fields" => [
                    "title" => ["number_of_fragments" => 0],
                    "description" => ["number_of_fragments" => 1, "fragment_size" => $this->snippetLength]
                ]
            ]
        ];


        $from = ($page - 1) * $itemsPerPage ;

        $requestArray = array('host' => $this->host,
                        'type' => $this->type,
                        'itemsPerPage' => $itemsPerPage,
                        'from' => $from,
                        'Query' => $mainQuery);

        $result = $this->serverCall($requestArray);

        return $this->readSearchResults( $result, $page, $itemsPerPage, $query);
    }

    /**
     * @param $requestArray
     * @return mixed
     */
    private function serverCall($requestArray)
    {
        if(isset($requestArray['itemsPerPage']) && isset($requestArray['from']) && trim($requestArray['itemsPerPage']) != '' && trim($requestArray['from']) != '')
            $cHandle = curl_init( "{$requestArray['host']}/{$requestArray['type']}/_search?size={$requestArray['itemsPerPage']}&from={$requestArray['from']}" );
        else
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
     * @param $page
     * @param $itemsPerPage
     * @param $query
     * @return array
     */
    private function readSearchResults( $resultData, $page, $itemsPerPage, $query )
    {

        $suggestionsData = array();
        $totalHits = $resultData["hits"]["total"];
        $maxPages = ($totalHits - $totalHits % $itemsPerPage ) / $itemsPerPage;
        if ($totalHits == 0)
        {
            $suggestionsData = $this->suggestQuerySearch($query);
        }

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

        if (isset($suggestionsData))
        {
            $result["suggestions"] = $this->createSuggestArray($suggestionsData);
        }

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

    /**
     * @param $str
     * @param $cmp
     * @return bool
     */
    function startsWith( $str, $cmp )
    {
        $str = trim( strip_tags( $str ) );
        $cmp = trim( strip_tags( $cmp ) );
        return $str === substr( $cmp, 0, strlen( $str ) );
    }

    /**
     * @param $str
     * @param $cmp
     * @return bool
     */
    function endsWith( $str, $cmp )
    {
        $str = trim( strip_tags( $str ) );
        $cmp = trim( strip_tags( $cmp ) );
        return $str === substr( $cmp, strlen( $cmp ) - strlen( $str ) );
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

    /**
     * @param $query
     * @return mixed
     */
    private function suggestQuerySearch($query)
    {

        $tokens = explode(' ', $query);

        $index = 1;

        $numberOfItems = count($tokens);

        if($numberOfItems < 2)
        {
            $suggestionQuery = $this->createSuggestQuery($query,$index);

            $requestArray = array('host' => $this->host,
                'type' => $this->type,
                'Query' => $suggestionQuery);

            return $this->serverCall($requestArray);

        }
        else
        {
            $result = array();

            foreach ($tokens as $token)
            {
                $tempArray = $this->createSuggestQuery($token,$index);

                $requestArray = array('host' => $this->host,
                    'type' => $this->type,
                    'Query' => $tempArray);

                $queryArray = $this->serverCall($requestArray);

                if($index == 2)
                {
                    $result = array_merge($result["suggest"], $queryArray["suggest"]);
                }
                elseif($index > 2)
                {
                    $result = array_merge($result, $queryArray["suggest"]);
                }
                else
                    $result = $queryArray;


                $index++;
            }

            return $result;
        }

    }

    /**
     * @param $suggestArrayData
     * @return array
     */
    private function createSuggestArray($suggestArrayData)
    {

        $suggestArray = array();

        $itemsNb = count($suggestArrayData) / 2;

        if(isset($suggestArrayData["suggest"]))
        {
            foreach ($suggestArrayData["suggest"]["suggestion_content_1"][0]["options"] as $suggestion)
            {
                array_push($suggestArray, $suggestion["text"]);
            }

            foreach ($suggestArrayData["suggest"]["suggestion_description_1"][0]["options"] as $suggestion)
            {
                array_push($suggestArray, $suggestion["text"]);
            }

            $result = array_unique($suggestArray);

            return $result;
        }

        return  $this->createArrayForSuggestion($suggestArrayData, $itemsNb);


    }

    /**
     * @param $query
     * @param $index
     * @return array
     */
    private function createSuggestQuery($query, $index)
    {

        $queryArray = [
            "suggest" => [
                "text" => $query,
                "suggestion_content_".$index => [
                    "phrase" => [
                        "field" => "sections.content",
                        "real_word_error_likelihood" => 0.5,
                        "max_errors" => 0.95,
                        "confidence" => 1.0,
                        "size" => 3,
                        "highlight" => [
                            "pre_tag" => "<em>",
                            "post_tag" => "</em>"
                        ],
                        "collate" => [
                            "query" => [
                                "inline" => [
                                    "match" => [
                                        "" => ""
                                    ]
                                ]
                            ],
                            "params" => [
                                "field_name" => "sections.content"
                            ],
                            "prune" => true
                        ]
                    ]
                ],
                "suggestion_description_".$index => [
                    "phrase" => [
                        "field" => "description",
                        "real_word_error_likelihood" => 0.5,
                        "max_errors" => 0.95,
                        "confidence" => 1.0,
                        "size" => 4,
                        "highlight" => [
                            "pre_tag" => "<em>",
                            "post_tag" => "</em>"
                        ],
                        "collate" => [
                            "query" => [
                                "inline" => [
                                    "match" => [
                                        "" => ""
                                    ]
                                ]
                            ],
                            "params" => [
                                "field_name" => "description"
                            ],
                            "prune" => true
                        ]
                    ]
                ]

            ]
        ];

        return $queryArray;

    }

    /**
     * @param $suggestionArray
     * @param $itemsNb
     * @return array
     */
    private function createArrayForSuggestion($suggestionArray, $itemsNb)
    {

        $arrayTemp = array();

        $suggestedArrayContentTemp = array();

        $suggestedArrayDescriptionTemp = array();

        for($index = 1; $index <= $itemsNb; $index++)
        {
            if(empty($suggestionArray["suggestion_content_".$index][0]["options"]))
            {
                array_push($arrayTemp, $suggestionArray["suggestion_content_".$index][0]["text"]);
            }

            foreach ($suggestionArray["suggestion_content_".$index][0]["options"] as $suggestion)
            {
                array_push($arrayTemp, $suggestion["text"]);
            }

            $suggestedArrayContentTemp[$index] = $arrayTemp;

            $arrayTemp = array();

        }

        for($index = 1; $index <= $itemsNb; $index++)
        {
            if(empty($suggestionArray["suggestion_description_".$index][0]["options"]))
            {
                array_push($arrayTemp, $suggestionArray["suggestion_description_".$index][0]["text"]);
            }

            foreach ($suggestionArray["suggestion_description_".$index][0]["options"] as $suggestion)
            {
                array_push($arrayTemp, $suggestion["text"]);
            }

            $suggestedArrayDescriptionTemp[$index] = $arrayTemp;

            $arrayTemp = array();

        }

        $suggestedArrayContent = $this->createCombinedArray($suggestedArrayContentTemp[1], $suggestedArrayContentTemp[2]);

        for($i = 3; $i <= count($suggestedArrayContentTemp); $i++)
        {
            $suggestedArrayContent = $this->createCombinedArray($suggestedArrayContentTemp, $suggestedArrayContentTemp[$i]);
        }

        $suggestArray = $suggestedArrayContent;

        $suggestedArrayDescription = $this->createCombinedArray($suggestedArrayDescriptionTemp[1], $suggestedArrayDescriptionTemp[2]);

        for($i = 3; $i <= count($suggestedArrayDescriptionTemp); $i++)
        {
            $suggestedArrayDescription = $this->createCombinedArray($suggestedArrayDescription, $suggestedArrayDescriptionTemp[$i]);
        }

        foreach ($suggestedArrayDescription as $key => $value)
        {
            array_push($suggestArray, $value);
        }

        $result = array_unique($suggestArray);

        return $result;

    }

    /**
     * @param $firstArray
     * @param $secondArray
     * @return array
     */
    private function createCombinedArray($firstArray, $secondArray)
    {
        $resultArray = array();

        $index = 0;

        for($i = 0; $i < count($firstArray); $i++)
        {
            for($j = 0; $j < count($secondArray); $j++)
            {
                $resultArray[$index] = $firstArray[$i]. " " . $secondArray[$j];
                $index++;
            }
        }

        return $resultArray;

    }
}