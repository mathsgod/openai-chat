<?php

namespace OpenAI\Chat\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Parameter
{
    private $description;

    public function __construct(?string $description)
    {
        $this->description = $description;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
