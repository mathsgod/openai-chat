# openai-chat

## Installation

```bash
composer require mathsgod/openai-chat
```


## Usage

To use the OpenAI chat, you need to create a new instance of the `System` class and pass the OpenAI API key as the first argument.

```php
use OpenAI\Chat\System;

$system = new System($_ENV['OPENAI_API_KEY']);

echo $system->ask("Hello");

```

### Add a tool

```php
use OpenAI\Chat\Attributes\Tool;
use OpenAI\Chat\Attributes\Parameter;

#[Tool(description: 'Get the release date of iphone')]
function getIPhoneReleaseDate(#[Parameter("model of the phone")] string $model)
{
    return ["date" => "2022-09-14", "model" => $model];
}

$system->addTool(Closure::fromCallable("getIPhoneReleaseDate"));

echo $system->ask("When will iPhone 14 be released?");
```


#### Add a tool from a class method

```php
class Controller
{
    public $price = "$799";

    #[Tool(description: 'Get the price of iphone')]
    public function getIPhonePrice(#[Parameter("model of the phone")] string $model)
    {
        return ["price" => $this->price, "model" => $model];
    }
}

$system->addTool(Closure::fromCallable([new Controller(), "getIPhonePrice"]));

echo $system->ask("What is the price and release date of iphone14?");

```

### Get usage records
After run the code above, you can get the usage records
```php

print_r($system->getUsages());

```

### Streaming

```php
$stream = $system->askAsStream("What is the price and release date of iphone14?");

$stream->on('data', function ($data) {
    echo $data;
});

```

