<?php

/*
 * This file is part of vaibhavpandeyvpz/qatar package.
 *
 * (c) Vaibhav Pandey <contact@vaibhavpandey.com>
 *
 * This source file is subject to the MIT license that is bundled with this source code in the LICENSE file.
 */

namespace Qatar;

use Aws\Sqs\SqsClient;

/**
 * ElasticMQ/SQS-backed queue implementation.
 *
 * Uses AWS SQS (or ElasticMQ for local development) for distributed
 * job queuing with built-in reliability and scalability.
 *
 * @author Vaibhav Pandey <contact@vaibhavpandey.com>
 */
final class ElasticMQQueue implements Queue
{
    /**
     * SQS client instance.
     */
    private readonly SqsClient $sqs;

    /**
     * Queue URL for SQS operations.
     */
    private readonly string $queueUrl;

    /**
     * Map of receipt handles for acknowledgment.
     *
     * @var array<string, string>
     */
    private array $receiptHandles = [];

    /**
     * Create a new ElasticMQ/SQS queue instance.
     *
     * @param  array<string, mixed>  $config  AWS SDK configuration.
     *                                        Should include: region, version, endpoint (for ElasticMQ).
     * @param  string  $queueName  Queue name (default: 'default').
     */
    public function __construct(
        array $config,
        string $queueName = 'default',
    ) {
        if (! class_exists(SqsClient::class)) {
            throw new \RuntimeException('AWS SDK not found. Please install aws/aws-sdk-php.');
        }
        $this->sqs = new SqsClient($config);

        // Get or create queue URL
        try {
            $result = $this->sqs->getQueueUrl(['QueueName' => $queueName]);
            $this->queueUrl = $result['QueueUrl'];
        } catch (\Aws\Exception\AwsException $e) {
            // Queue doesn't exist, create it
            $result = $this->sqs->createQueue(['QueueName' => $queueName]);
            $this->queueUrl = $result['QueueUrl'];
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function push(string $job, array $payload, ?int $delay = null): string
    {
        $id = $this->generateId();

        $message = [
            'id' => $id,
            'job' => $job,
            'payload' => $payload,
            'attempts' => 0,
        ];

        $params = [
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => json_encode($message),
            'MessageAttributes' => [
                'JobId' => [
                    'DataType' => 'String',
                    'StringValue' => $id,
                ],
                'JobClass' => [
                    'DataType' => 'String',
                    'StringValue' => $job,
                ],
            ],
        ];

        if ($delay && $delay > 0) {
            $params['DelaySeconds'] = min($delay, 900); // SQS max delay is 15 minutes
        }

        $this->sqs->sendMessage($params);

        return $id;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function pop(?int $timeout = null): ?JobPayload
    {
        $waitTime = $timeout !== null ? min($timeout, 20) : 0; // SQS max wait time is 20 seconds

        $result = $this->sqs->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => $waitTime,
            'MessageAttributeNames' => ['All'],
        ]);

        $messages = $result['Messages'] ?? [];

        if (empty($messages)) {
            return null;
        }

        $message = $messages[0];
        $body = json_decode($message['Body'], true);

        // Store receipt handle for acknowledgment
        $id = $body['id'];
        $this->receiptHandles[$id] = $message['ReceiptHandle'];

        // Increment attempts
        $attempts = ($body['attempts'] ?? 0) + 1;

        return new JobPayload(
            id: $id,
            job: $body['job'],
            payload: $body['payload'],
            attempts: $attempts,
            availableAt: time(),
        );
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function ack(string $id): bool
    {
        if (! isset($this->receiptHandles[$id])) {
            return false;
        }

        try {
            $this->sqs->deleteMessage([
                'QueueUrl' => $this->queueUrl,
                'ReceiptHandle' => $this->receiptHandles[$id],
            ]);

            unset($this->receiptHandles[$id]);

            return true;
        } catch (\Aws\Exception\AwsException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function nack(string $id, ?int $delay = null): bool
    {
        if (! isset($this->receiptHandles[$id])) {
            return false;
        }

        try {
            // Change message visibility to make it available again
            $visibilityTimeout = $delay ?? 0;

            $this->sqs->changeMessageVisibility([
                'QueueUrl' => $this->queueUrl,
                'ReceiptHandle' => $this->receiptHandles[$id],
                'VisibilityTimeout' => $visibilityTimeout,
            ]);

            unset($this->receiptHandles[$id]);

            return true;
        } catch (\Aws\Exception\AwsException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function size(): int
    {
        try {
            $result = $this->sqs->getQueueAttributes([
                'QueueUrl' => $this->queueUrl,
                'AttributeNames' => ['ApproximateNumberOfMessages'],
            ]);

            return (int) ($result['Attributes']['ApproximateNumberOfMessages'] ?? 0);
        } catch (\Aws\Exception\AwsException $e) {
            return 0;
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function purge(): void
    {
        try {
            $this->sqs->purgeQueue(['QueueUrl' => $this->queueUrl]);
        } catch (\Aws\Exception\AwsException $e) {
            // Purge failed, ignore
        }
    }

    /**
     * Generate a unique job ID.
     *
     * @return string Unique identifier.
     */
    private function generateId(): string
    {
        return uniqid('job_', true);
    }
}
