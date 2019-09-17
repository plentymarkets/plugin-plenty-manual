<?php

namespace PlentyManual\Helpers;

class ResultsStorage
{

    private $storageArray = array();

    public function __construct(array $storageArray = [])
    {
        $this->storageArray = $storageArray;
    }

    public function deleteResults(): void
    {
        foreach ($this->storageArray as $key => $value)
            unset($this->storageArray[$key]);
    }

    public function setResults($arr): void
    {
        if(!empty($this->storageArray))
            $this->deleteResults();
        $this->storageArray = $arr;
    }

    public function getResults()
    {
        return $this->storageArray;
    }
}