<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/bitcoind-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Bitcoind\Migration\RabbitMq;

use Daikon\RabbitMq3\Migration\RabbitMq3Migration;

final class SetupBitcoindQueue20190311144000 extends RabbitMq3Migration
{
    public function getDescription(string $direction = self::MIGRATE_UP): string
    {
        return $direction === self::MIGRATE_UP
            ? 'Create RabbitMQ queue for Bitcoin messages.'
            : 'Delete RabbitMQ queue for Bitcoin messages.';
    }

    public function isReversible(): bool
    {
        return true;
    }

    protected function up(): void
    {
        $this->declareQueue('bitcoind.adapter.messages', false, true, false, false);
        $this->bindQueue('bitcoind.adapter.messages', 'bitcoind.adapter.exchange', 'bitcoind.message.#');
    }

    protected function down(): void
    {
        $this->deleteQueue('bitcoind.adapter.messages');
    }
}
