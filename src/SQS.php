<?php
//TODO: Register and use any queues only with environment prefix or sufix
declare(strict_types=1);

namespace Memcrab\Queue;

use Aws\Sqs\SqsClient;

class SQS implements QueueInterface
{
    public  $client = null;
    public  $test = null;
    private array $urls;
    private static SQS $instance;
    private static string $region;
    private static string $version;
    private static string $endpoint;
    private static string $key;
    private static string $secret;
    private static \Memcrab\Log\Log $ErrorHandler;

    private function __construct()
    {
    }
    private function __clone()
    {
    }
    public function __wakeup()
    {
    }

    /**
     * @return self
     */
    public static function obj(): self
    {
        if (!isset(self::$instance) || !(self::$instance instanceof self)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array $properties
     * 
     * @return void
     */
    public static function declareConnection(array $properties, \Memcrab\Log\Log $ErrorHandler): void
    {

        if (!isset($properties['region']) || empty($properties['region']) || !is_string($properties['region'])) {
            throw new \Exception("Aws `region` property need to be string");
        }
        if (!isset($properties['version']) || empty($properties['version']) || !is_string($properties['version'])) {
            throw new \Exception("Aws `version` property need to be string");
        }
        if (!isset($properties['endpoint']) || empty($properties['endpoint']) || !is_string($properties['endpoint'])) {
            throw new \Exception("Aws `endpoint` property need to be string");
        }
        if (!isset($properties['key']) || empty($properties['key']) || !is_string($properties['key'])) {
            throw new \Exception("Aws `key` property need to be string");
        }
        if (!isset($properties['secret']) || empty($properties['secret']) || !is_string($properties['secret'])) {
            throw new \Exception("Aws `secret` property need to be string");
        }

        self::$region = $properties['region'];
        self::$version = $properties['version'];
        self::$endpoint = $properties['endpoint'];
        self::$key = $properties['key'];
        self::$secret = $properties['secret'];
        self::$ErrorHandler = $ErrorHandler;

        \register_shutdown_function("Memcrab\Queue\SQS::shutdown");
    }

    /**
     * @return Queue
     */
    public function connect(): bool
    {
        try {
            $this->client = new SqsClient([
                'region' => self::$region,
                'version' => self::$version,
                'endpoint' => self::$endpoint,
                'credentials' => [
                    'key' => self::$key,
                    'secret' => self::$secret,
                ]
            ]);
            return true;
        } catch (\Exception $e) {
            self::$ErrorHandler->error((string) $e);
            return false;
        }
    }

    /**
     * @return bool
     */
    public function ping(): bool
    {
        $connected = false;

        try {
            $client = $this->client();
            if ($client instanceof SqsClient) {
                $client->listQueues();
                $connected = true;
            } else $connected = false;
        } catch (\Exception $e) {
            self::$ErrorHandler->error((string) $e);
            $connected = false;
        }

        return $connected;
    }

    /**
     * @param  string  $name
     * @param  array   $atributes
     * @return mixed
     */
    public function getListOfQueues()
    {
        try {
            $result = $this->client->listQueues();
        } catch (\Exception $e) {
            self::$ErrorHandler->error((string) $e);
            throw $e;
        }

        return $result;
    }

    /**
     * @param string $name
     * @param array|null $atributes
     * 
     * @return self
     */
    public function registerQueue(string $name, ?array $atributes = []): self
    {
        try {

            $result = $this->client->createQueue([
                'QueueName' => $name,
                'Attributes' => $atributes,
            ]);
            $this->urls[$name] = $result->get('QueueUrl');
        } catch (\Exception $e) {
            self::$ErrorHandler->error((string) $e);
            throw $e;
        }

        return $this;
    }

    /**
     * @param string $name
     * @param array $message
     * @param int $VisibilityTimeout
     * 
     * @return self
     */
    public function changeMessageVisibility(string $name, array $message, int $VisibilityTimeout): self
    {
        try {

            $result = $this->client->changeMessageVisibility([
                'QueueUrl' => $this->urls[$name],
                'ReceiptHandle' => $message['ReceiptHandle'],
                'VisibilityTimeout' => $VisibilityTimeout,
            ]);
        } catch (\Exception $e) {
            self::$ErrorHandler->error((string) $e);
            throw $e;
        }

        return $this;
    }

    /**
     * @param  string  $name
     * @param  array   $messageBody
     * @param  array   $attributes
     * @param  int     $delaySeconds
     * @return mixed
     */
    public function sendMessage(string $name, array $messageBody, ?array $attributes = [], int $delaySeconds = 10)
    {
        try {

            $result = $this->client->sendMessage([
                'DelaySeconds' => $delaySeconds,
                'MessageAttributes' => $attributes,
                'MessageBody' => serialize($messageBody),
                'QueueUrl' => $this->urls[$name],
            ]);
        } catch (\Exception $e) {
            self::$ErrorHandler->error((string) $e);
            throw $e;
        }
        return $result;
    }

    /**
     * @param  string  $name
     * @return mixed
     */
    public function receiveMessage(string $name)
    {
        try {
            $result = $this->client->receiveMessage([
                'AttributeNames' => ['SentTimestamp', 'ApproximateReceiveCount'],
                'MaxNumberOfMessages' => 1,
                'MessageAttributeNames' => ['All'],
                'QueueUrl' => $this->urls[$name], // REQUIRED
                'WaitTimeSeconds' => 20,
            ]);
        } catch (\Exception $e) {
            self::$ErrorHandler->error((string) $e);
            throw $e;
        }
        return $result;
    }

    /**
     * @param  string  $name
     * @param  array   $message
     * @return mixed
     */
    public function deleteMessage(string $name, array $message)
    {
        try {
            $result = $this->client->deleteMessage([
                'QueueUrl' => $this->urls[$name], // REQUIRED
                'ReceiptHandle' => $message['ReceiptHandle'], // REQUIRED
            ]);
        } catch (\Exception $e) {
            self::$ErrorHandler->error((string) $e);
            throw $e;
        }
        return $result;
    }

    public function client(): object
    {
        return $this->client;
    }

    /**
     * @param string $queueName
     * 
     * @return string
     */
    public function getQueueUrl(string $queueName): string
    {
        return $this->urls[$queueName];
    }

    /**
     * @return void
     */
    public static function shutdown(): void
    {
        if (isset(self::$instance->client)) {
            unset(self::$instance->client);
        }
    }

    public function __destruct()
    {
        if (!empty($this->client)) {
            unset($this->client);
        }
    }
}
