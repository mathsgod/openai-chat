<?php

namespace OpenAI\Chat;

use OpenAI\Client;
use Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class System implements LoggerAwareInterface
{

    use LoggerAwareTrait;

    private $model;
    public $messages = [];
    private $client;
    /** @var FunctionInterface[] */
    public $functions = [];

    private $temperature;

    public function __construct(string $openai_api_key, string $model = "gpt-3.5-turbo-0613", ?float $temperature = null)
    {
        $this->model = $model;
        $this->client = new Client($openai_api_key);
        $this->logger = new NullLogger();
        $this->temperature = $temperature;
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
        $this->functions[$name] = new ChatFunction($name, $description, $parameters, $handler);
    }

    /**
     * @param FunctionInterface[] $functions
     */
    public function addFunctions(array $functions)
    {
        foreach ($functions as $f) {
            $this->functions[$f->getName()] = $f;
        }
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

        if (isset($this->temperature)) {
            $body["temperature"] = $this->temperature;
        }

        if ($this->functions) {
            $body["functions"] = array_values(array_map(function ($f) {
                return [
                    "name" => $f->getName(),
                    "description" => $f->getDescription(),
                    "parameters" => $f->getParameters()
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
            $ft += count($t->encode($f->getName()))
                + count($t->encode($f->getDescription()))
                + count($t->encode(json_encode($f->getParameters(), JSON_UNESCAPED_UNICODE)));
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

            $this->logger->info("Messages", $body["messages"]);

            $response = $this->client->createChatCompletion($body);
            $usage = $response["usage"];

            $message = $response["choices"][0]["message"];
            $message["tokens"] = $usage["completion_tokens"] + 3;


            $this->messages[] = $message;

            if ($function_call = $response["choices"][0]["message"]["function_call"] ?? false) {


                $function = $this->functions[$function_call["name"]];
                $arguments = json_decode($function_call["arguments"], true);

                $this->logger->info("Function call [" . $function_call["name"] . "]", $arguments);

                $function_response = call_user_func_array($function->getHandler(), $arguments);
                $this->logger->info("Function response", [$function_response]);

                $this->addFunctionMessage(json_encode($function_response, JSON_UNESCAPED_UNICODE), $function_call["name"]);

                continue;
            } else {
                break;
            }
        } while (true);

        return $response["choices"][0]["message"]["content"];
    }
}
