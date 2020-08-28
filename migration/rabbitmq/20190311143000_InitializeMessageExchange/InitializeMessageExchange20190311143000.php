<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/bitcoind-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Bitcoind\Migration\RabbitMq;

use Daikon\RabbitMq3\Migration\RabbitMq3Migration;
use PhpAmqpLib\Exchange\AMQPExchangeType;

final class InitializeMessageExchange20190311143000 extends RabbitMq3Migration
{
    public function getDescription(string $direction = self::MIGRATE_UP): string
    {
        return $direction === self::MIGRATE_UP
            ? 'Create a RabbitMQ message exchange for the Bitcoind-Adapter context.'
            : 'Delete the RabbitMQ message message exchange for the Bitcoind-Adapter context.';
    }

    public function isReversible(): bool
    {
        return true;
    }

    public function up(): void
    {
        $this->createMigrationList('bitcoind.adapter.migration_list');
        $this->declareExchange('bitcoind.adapter.exchange', AMQPExchangeType::TOPIC, false, true, false);
    }

    public function down(): void
    {
        $this->deleteExchange('bitcoind.adapter.exchange');
        $this->deleteExchange('bitcoind.adapter.migration_list');
    }
}
