<?php

namespace PlentyManual\Models;

/**
 * Class ChangelogEntry
 * @package PlentyManual\Models
 */
class ChangelogEntry implements \JsonSerializable
{
    /**
     * @var string
     */
    private $title = '';

    /**
     * @var string
     */
    private $dateString = '';

    /**
     * @var string
     */
    private $url = '';

    /**
     * @var array
     */
    private $tags = [];

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title
     * @return $this
     */
    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    /**
     * @return string
     */
    public function getDateString(): string
    {
        return $this->dateString;
    }

    /**
     * @param string $dateString
     * @return $this
     */
    public function setDateString($dateString)
    {
        $this->dateString = $dateString;
        return $this;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @return array
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array $tags
     * @return $this
     */
    public function setTags($tags)
    {
        $this->tags = $tags;
        return $this;
    }

    /**
     * Specify data which should be serialized to JSON
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    function jsonSerialize()
    {
        return [];
    }
}