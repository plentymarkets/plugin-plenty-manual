<?php

namespace PlentyManual\Helpers;

class ResultsStorage
{

    private $storageArray;

    public function __construct()
    {
    }

    public function deleteResults()
    {
        foreach ($this->storageArray as $key => $value)
            unset($this->storageArray[$key]);
        return true;
    }

    public function setResults($arr)
    {
        $deleteFlag = false;
        $deleteFlag = $this->deleteResults();
        $this->storageArray = $arr;

        return $arr;
    }

    public function getResults()
    {
        return $this->storageArray;
    }
}