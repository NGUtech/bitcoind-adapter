<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/bitcoind-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Bitcoind\Message;

use BitWasp\Buffertools\Buffer;
use Daikon\AsyncJob\Worker\WorkerInterface;
use Daikon\Boot\Service\Provisioner\MessageBusProvisioner;
use Daikon\Interop\Assertion;
use Daikon\Interop\RuntimeException;
use Daikon\MessageBus\MessageBusInterface;
use Daikon\RabbitMq3\Connector\RabbitMq3Connector;
use Daikon\ValueObject\Timestamp;
use NGUtech\Bitcoin\Message\BitcoinBlockHashReceived;
use NGUtech\Bitcoin\Message\BitcoinMessageInterface;
use NGUtech\Bitcoin\Message\BitcoinTransactionHashReceived;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

final class BitcoindMessageWorker implements WorkerInterface
{
    private const MESSAGE_BLOCK_HASH = 'bitcoind.message.hashblock';
    private const MESSAGE_TRANSACTION_HASH = 'bitcoind.message.hashtx';

    private RabbitMq3Connector $connector;

    private MessageBusInterface $messageBus;

    private LoggerInterface $logger;

    private array $settings;

    public function __construct(
        RabbitMq3Connector $connector,
        MessageBusInterface $messageBus,
        LoggerInterface $logger,
        array $settings = []
    ) {
        $this->connector = $connector;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function run(array $parameters = []): void
    {
        $queue = $parameters['queue'];
        Assertion::notBlank($queue);

        $messageHandler = function (AMQPMessage $amqpMessage): void {
            $this->execute($amqpMessage);
        };

        /** @var AMQPChannel $channel */
        $channel = $this->connector->getConnection()->channel();
        $channel->basic_qos(0, 1, false);
        $channel->basic_consume($queue, '', true, false, false, false, $messageHandler);

        while (count($channel->callbacks)) {
            $channel->wait();
        }
    }

    private function execute(AMQPMessage $amqpMessage): void
    {
        try {
            $message = $this->createMessage($amqpMessage);
            if ($message instanceof BitcoinMessageInterface) {
                $this->messageBus->publish($message, MessageBusProvisioner::EVENTS_CHANNEL);
            }
            $amqpMessage->ack();
        } catch (RuntimeException $error) {
            $this->logger->error(
                "Error handling bitcoind message '{$amqpMessage->getRoutingKey()}'.",
                ['exception' => $error->getTrace()]
            );
            $amqpMessage->nack();
        }
    }

    private function createMessage(AMQPMessage $amqpMessage): ?BitcoinMessageInterface
    {
        $payload = [
            'hash' => (new Buffer($amqpMessage->body))->getHex(),
            'receivedAt' => (string)Timestamp::fromTime($amqpMessage->get('timestamp'))
        ];

        switch ($amqpMessage->getRoutingKey()) {
            case self::MESSAGE_TRANSACTION_HASH:
                $message = BitcoinTransactionHashReceived::fromNative($payload);
                break;
            case self::MESSAGE_BLOCK_HASH:
                $message = BitcoinBlockHashReceived::fromNative($payload);
                break;
            default:
                // ignore unknown routing keys
        }

        return $message ?? null;
    }
}
