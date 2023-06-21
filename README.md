# openai-chat

## Installation

```bash
composer require mathsgod/openai-chat
```


## Usage

```php
use OpenAI\Chat\System;

$system = new System($_ENV['OPENAI_API_KEY']);

$system->addUserMessage("What is the price and release date of iphone14?");

$system->addFunction("getIPhonePrice", "Get the price of iphone", [
    "type" => "object",
    "properties" => [
        "model" => [
            "type" => "string",
            "description" => "Model of the iPhone"
        ]
    ]
], function (string $model) {
    return ["price" => "$799", "model" => $model];
});

$system->addFunction("getIPhoneReleaseDate", "Get the release date of iphone", [
    "type" => "object",
    "properties" => [
        "model" => [
            "type" => "string",
            "description" => "Model of the iPhone"
        ]
    ]
], function (string $model) {
    return ["date" => "2022-09-14", "model" => $model];
});

var_dump($system->run());
```



### Get usage records
After run the code above, you will can get the usage records
```php

print_r($system->getUsages());

```
