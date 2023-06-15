<?php

namespace OpenAI\Chat;

use OpenAI\Client;
use Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;

class System
{
    private $model;
    public $messages = [];
    private $client;
    public $functions = [];

    public function __construct(string $openai_api_key, string $model = "gpt-3.5-turbo-0613")
    {
        $this->model = $model;
        $this->client = new Client($openai_api_key);
    }

    public function addUserMessage(string $content)
    {
        $this->messages[] = [
            "role" => "user",
            "content" => $content,
            "tokens" => count((new Gpt3Tokenizer(new Gpt3TokenizerConfig()))->encode($content)) + 4
        ];
    }

    public function addSystemMessage(string $content)
    {
        $t = new Gpt3Tokenizer(new Gpt3TokenizerConfig());
        $this->messages[] = [
            "role" => "system",
            "content" => $content,
            "tokens" => count((new Gpt3Tokenizer(new Gpt3TokenizerConfig()))->encode($content)) + 4
        ];
    }

    public function addAssistantMessage(string $content)
    {
        $t = new Gpt3Tokenizer(new Gpt3TokenizerConfig());
        $this->messages[] = [
            "role" => "assistant",
            "content" => $content,
            "tokens" => count((new Gpt3Tokenizer(new Gpt3TokenizerConfig()))->encode($content)) + 4
        ];
    }

    public function addFunctionMessage(string $content, string $name)
    {
        $t = new Gpt3Tokenizer(new Gpt3TokenizerConfig());
        $this->messages[] = [
            "role" => "function",
            "content" => $content,
            "name" => $name,
            "tokens" => count($t->encode($content)) + count($t->encode($name)) + 4
        ];
    }

    public function addFunction(string $name, string $description, array $parameters, callable $handler)
    {
        $this->functions[$name] = [
            "name" => $name,
            "description" => $description,
            "parameters" => $parameters,
            "handler" => $handler,
        ];
    }

    private function getBody()
    {

        $max_token = 4096;

        // function token
        $token_count = $this->getFunctionsToken();

        // system token
        foreach ($this->messages as $m) {
            if ($m["role"] == "system") {
                $token_count += $m["tokens"];
            }
        }

        $token_left = $max_token - $token_count;


        $final = [];
        $messages = array_reverse($this->messages);
        foreach ($messages as $m) {
            if ($m["role"] == "system") {
                $final[] = $m;
                continue;
            }

            if ($token_left >= $m["tokens"]) {
                $token_left -= $m["tokens"];
                $final[] = $m;
            }
        }
        $final = array_reverse($final);

        $body = [
            "model" => $this->model,
            "messages" => array_map(function ($m) {
                unset($m["tokens"]);
                return $m;
            }, $final),
        ];

        if ($this->functions) {
            $body["functions"] = array_values(array_map(function ($f) {
                return [
                    "name" => $f["name"],
                    "description" => $f["description"],
                    "parameters" => $f["parameters"]
                ];
            }, $this->functions));
        }

        return $body;
    }

    public function getFunctionsToken()
    {
        $t = new Gpt3Tokenizer(new Gpt3TokenizerConfig());

        $ft = 0;
        foreach ($this->functions as $f) {
            $ft += count($t->encode($f["name"]))
                + count($t->encode($f["description"]))
                + count($t->encode(json_encode($f["parameters"], JSON_UNESCAPED_UNICODE)));
            $ft += 4;
        }
        return $ft;
    }

    public function getMessagesToken()
    {
        $mt = 0;
        foreach ($this->messages as $m) {
            $mt += $m["tokens"];
        }
        return $mt;
    }

    public function run()
    {
        do {
            $body = $this->getBody();
            $response = $this->client->createChatCompletion($body);

            $usage = $response["usage"];


            $message = $response["choices"][0]["message"];
            $message["tokens"] = $usage["completion_tokens"] + 3;

            $this->messages[] = $message;

            if ($function_call = $response["choices"][0]["message"]["function_call"] ?? false) {

                $function = $this->functions[$function_call["name"]];
                $arguments = json_decode($function_call["arguments"], true);
                $function_response = json_encode(call_user_func_array($function["handler"], $arguments), JSON_UNESCAPED_UNICODE);
                $this->addFunctionMessage($function_response, $function_call["name"]);

                continue;
            } else {
                break;
            }
        } while (true);

        print_R($response);
        return $response["choices"][0]["message"]["content"];
    }

    public function getTokenCount()
    {
    }
}
