<?php

namespace OpenAI\Chat\Attributes;

#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class Tool
{

    private $description;
    private $name;

    public function __construct(array $attributes = [])
    {
        $this->description = $attributes['description'];
        $this->name = $attributes['name'];
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
