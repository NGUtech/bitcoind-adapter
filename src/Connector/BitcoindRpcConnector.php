<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/bitcoind-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Bitcoind\Connector;

use Daikon\Dbal\Connector\ConnectorInterface;
use Daikon\Dbal\Connector\ProvidesConnector;
use Denpa\Bitcoin\Client;

final class BitcoindRpcConnector implements ConnectorInterface
{
    use ProvidesConnector;

    protected function connect(): Client
    {
        return new Client($this->settings);
    }
}
