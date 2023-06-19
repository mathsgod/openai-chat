<?php

namespace OpenAI\Chat;

interface FunctionInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getParameters(): array;
    public function getHandler(): callable;
}
