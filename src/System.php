<?php

namespace OpenAI\Chat;

use OpenAI\Client;
use Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
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

    protected $usages = [];

    private $function_call = null;

    private $token = 16384;

    public function __construct(
        string $openai_api_key,
        string $model = "gpt-3.5-turbo-1106",
        ?float $temperature = null,
        string $baseURL = "https://api.openai.com/v1/"
    ) {
        $this->model = $model;
        $this->client = new Client($openai_api_key, 10, $baseURL);
        $this->logger = new NullLogger();
        $this->temperature = $temperature;

        if ($model == "gpt-3.5-turbo-0613") {
            $this->token = 4096;
        }
    }


    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->client->setLogger($logger);
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

    public function addFunctionMessage(string $content, string $name, string $tool_call_id)
    {
        $t = new Gpt3Tokenizer(new Gpt3TokenizerConfig());
        $this->messages[] = [
            "tool_call_id" => $tool_call_id,
            "role" => "tool",
            "name" => $name,
            "content" => $content,
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

    public function setFunctionCall($function_call)
    {
        $this->function_call = $function_call;
    }

    private function getBody()
    {

        $max_token = $this->token;

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
            $body["tools"] = array_values(array_map(function ($f) {
                return [
                    "type" => "function",
                    "function" => [
                        "name" => $f->getName(),
                        "description" => $f->getDescription(),
                        "parameters" => $f->getParameters()
                    ]
                ];
            }, $this->functions));
        }

        if ($this->function_call) {
            $body["function_call"] = $this->function_call;
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

    public function getUsages()
    {
        return $this->usages;
    }

    public function getMessages()
    {
        return $this->messages;
    }

    public function run()
    {

        do {
            $body = $this->getBody();

            $this->logger->info("Messages", $body["messages"]);

            $response = $this->client->createChatCompletion($body);
            $usage = $response["usage"];

            $this->usages[] = $usage;


            $message = $response["choices"][0]["message"];
            $message["tokens"] = $usage["completion_tokens"] + 3;

            $this->messages[] = $message;

            if (isset($message["tool_calls"])) {
                $tool_calls = $message["tool_calls"];
                foreach ($tool_calls as $tool_call) {
                    $tool_call_id = $tool_call["id"];
                    $arguments = $tool_call["function"]["arguments"];

                    $function = $this->functions[$tool_call["function"]["name"]];
                    $arguments = json_decode($tool_call["function"]["arguments"], true);

                    $this->logger->info("Function call [" . $function->getName() . "]", $arguments);

                    try {
                        $function_response = call_user_func_array($function->getHandler(), $arguments);
                        $this->logger->info("Function response", [$function_response]);
                    } catch (\Exception $e) {
                        $this->logger->error("Function error", [$e->getMessage()]);
                        return $e->getMessage();
                    } catch (\Error $e) {
                        $this->logger->error("Function error", [$e->getMessage()]);
                        return $e->getMessage();
                    }

                    $this->addFunctionMessage(json_encode($function_response, JSON_UNESCAPED_UNICODE), $function->getName(), $tool_call_id);
                }

                continue;
            } else {
                break;
            }
        } while (true);

        return $response["choices"][0]["message"]["content"];
    }
}
