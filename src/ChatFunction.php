<?php

namespace OpenAI\Chat;

class ChatFunction implements FunctionInterface
{
    private $name;
    private $description;
    private $parameters;
    private $handler;

    public function __construct(string $name, string $description, array $parameters, callable $handler)
    {
        $this->name = $name;
        $this->description = $description;
        $this->parameters = $parameters;
        $this->handler = $handler;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }
}
