<?php

namespace OpenAI\Chat;

use Closure;
use OpenAI\Client;
use Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\AppendStream;
use GuzzleHttp\Psr7\BufferStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use OpenAI\Chat\Attributes\Parameter;
use OpenAI\Chat\Attributes\Tool;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Http\Message\ResponseException;
use React\Stream\DuplexStreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionObject;

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

    private $baseURL;
    private $openai_api_key;

    /** @var ReflectionFunction[] */
    protected $tools = [];

    public function __construct(
        string $openai_api_key,
        string $model = "gpt-3.5-turbo-0125",
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

        $this->baseURL = $baseURL;
        $this->openai_api_key = $openai_api_key;
    }


    public function addTool(Closure $tool)
    {
        $func = new ReflectionFunction($tool);

        if ($func->getAttributes(Tool::class) == null) {
            return;
        }

        $tool_attr = $func->getAttributes(Tool::class);
        $args = $tool_attr[0]->getArguments();


        if (!array_key_exists("name", $args)) {
            $this->tools[$func->getName()] = $func;
        } else {
            $this->tools[$args["name"]] = $func;
        }
    }


    public function toolToBody(ReflectionFunction $method)
    {
        if ($tool_attr = $method->getAttributes(Tool::class)) {

            $function = [];
            $args = $tool_attr[0]->getArguments();

            if (!array_key_exists("name", $args)) {
                $function["name"] = $method->getName();
            } else {
                $function["name"] = $args["name"];
            }

            $function["description"] = $args["description"];

            $function["parameters"] = [
                "type" => "object",
            ];

            $properties = [];
            $required = [];

            foreach ($method->getParameters() as $param) {
                $param_attr = $param->getAttributes(Parameter::class);
                if ($param_attr) {
                    $param_attr = $param_attr[0];

                    $property = [];
                    $property["description"] = ($param_attr->newInstance())->getDescription();
                    $property["type"] = $param->getType()->getName();

                    $properties[$param->getName()] = $property;

                    if (!$param->isOptional()) {
                        $required[] = $param->getName();
                    }
                }
            }

            $function["parameters"]["properties"] = $properties;

            $function["parameters"]["required"] = $required;


            return [
                "type" => "function",
                "function" => $function
            ];
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
            // "tokens" => count((new Gpt3Tokenizer(new Gpt3TokenizerConfig()))->encode($content)) + 4
        ];
    }

    public function addSystemMessage(string $content)
    {
        $t = new Gpt3Tokenizer(new Gpt3TokenizerConfig());
        $this->messages[] = [
            "role" => "system",
            "content" => $content,
            //  "tokens" => count((new Gpt3Tokenizer(new Gpt3TokenizerConfig()))->encode($content)) + 4
        ];
    }

    public function addAssistantMessage(string $content)
    {
        $t = new Gpt3Tokenizer(new Gpt3TokenizerConfig());
        $this->messages[] = [
            "role" => "assistant",
            "content" => $content,
            //  "tokens" => count((new Gpt3Tokenizer(new Gpt3TokenizerConfig()))->encode($content)) + 4
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
            // "tokens" => count($t->encode($content)) + count($t->encode($name)) + 4
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

    /**
     * @deprecated
     */
    public function setFunctionCall($function_call)
    {
        $this->function_call = $function_call;
    }

    public function getBody()
    {

        //  $max_token = $this->token;

        // function token
        //    $token_count = $this->getFunctionsToken();

        // system token
        /*         foreach ($this->messages as $m) {
            if ($m["role"] == "system") {
                $token_count += $m["tokens"];
            }
        } */

        /*         $token_left = $max_token - $token_count;

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
        $final = array_reverse($final); */

        /*      $body = [
            "model" => $this->model,
            "messages" => array_map(function ($m) {
                unset($m["tokens"]);
                return $m;
            }, $final),
        ]; */

        $body = [
            "model" => $this->model,
            "messages" => $this->messages,
        ];

        if (isset($this->temperature)) {
            $body["temperature"] = $this->temperature;
        }

        foreach ($this->tools as $tool) {
            $body["tools"][] = $this->toolToBody($tool);
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

        /* 
        if ($this->function_call) {
            $body["function_call"] = $this->function_call;
        }
 */



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

    public function runAsync()
    {
        $body = $this->getBody();
        $body["stream"] = true;


        $browser = new \React\Http\Browser();

        $promise = $browser->requestStreaming("POST", $this->baseURL . "chat/completions", [
            "Authorization" => "Bearer " . $this->openai_api_key,
            "Content-Type" => "application/json"
        ], json_encode($body, JSON_UNESCAPED_UNICODE));

        $stream = new ThroughStream();
        $promise->then(function (ResponseInterface $response) use (&$stream) {

            $s = $response->getBody();
            assert($s instanceof ReadableStreamInterface);

            $tool_calls = [];

            $s->on('data', function ($chunk) use (&$stream, &$tool_calls) {

                $lines = explode("\n", $chunk);

                foreach ($lines as $line) {
                    if (substr($line, 0, 6) != "data: ") continue;
                    //remove data:
                    $line = substr($line, 6);

                    if ($line == "[DONE]") {
                        if (count($tool_calls)) {
                            $this->messages[] = [
                                "role" => "assistant",
                                "tool_calls" => $tool_calls,
                                "content" => null
                            ];

                            foreach ($tool_calls as $tool_call) {
                                //execute function

                                $this->messages[] = [
                                    "role" => "tool",
                                    "tool_call_id" => $tool_call["id"],
                                    "content" => json_encode($this->executeFunction($tool_call["function"]), JSON_UNESCAPED_UNICODE),
                                ];
                            }

                            $s = $this->runAsync();
                            $s->on('data', function ($data) use ($stream) {
                                $stream->write($data);
                            });
                        } else {
                            $stream->write("data: [DONE]\n\n");
                            $stream->close();
                        }
                        break;
                    }

                    $message = json_decode($line, true);
                    $delta = $message["choices"][0]["delta"];

                    if (isset($delta["content"])) {
                        //$s->write("data: " . $delta["content"] . "\n\n");
                        $stream->write("data: " . $line . "\n\n");
                        continue;
                    }

                    if (isset($delta["tool_calls"])) {
                        $tool_call = $delta["tool_calls"][0];

                        if (isset($tool_call["id"])) {
                            $tool_calls[] = $tool_call;
                        } else {
                            $index = intval($tool_call["index"]);
                            $tool_calls[$index]["function"]["arguments"] .= $tool_call["function"]["arguments"];
                        }
                    }
                }
            });
        }, function (ResponseException $response) {
            echo $response->getMessage();
        });



        return $stream;
    }

    private function executeFunction($function)
    {
        $name = $function["name"];
        $arguments = json_decode($function["arguments"], true);

        if (!array_key_exists($name, $this->tools)) {
            return null;
        }
        $func = $this->tools[$name];
        return $func->invoke(...$arguments);
    }

    public function runAsStream()
    {
        $body = $this->getBody();
        $body["stream"] = true;
        $response = $this->client->createChatCompletion($body, true);

        if ($response instanceof ResponseInterface) {
            return $response->getBody();
        }
        return null;
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

            $this->messages[] = $message;

            if (isset($message["tool_calls"])) {
                $tool_calls = $message["tool_calls"];
                foreach ($tool_calls as $tool_call) {
                    $this->messages[] = [
                        "role" => "tool",
                        "content" =>  json_encode($this->executeFunction($tool_call["function"]), JSON_UNESCAPED_UNICODE),
                        "tool_call_id" =>  $tool_call["id"],
                    ];
                }

                continue;
            } else {
                break;
            }
        } while (true);

        return $response["choices"][0]["message"]["content"];
    }
}
