<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/bitcoind-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Bitcoind\Service;

use Daikon\Interop\Assertion;
use Daikon\Money\Exception\PaymentServiceFailed;
use Daikon\Money\Exception\PaymentServiceUnavailable;
use Daikon\Money\Service\MoneyServiceInterface;
use Daikon\Money\ValueObject\MoneyInterface;
use Daikon\ValueObject\Natural;
use Denpa\Bitcoin\Client;
use Denpa\Bitcoin\Exceptions\BadRemoteCallException;
use Denpa\Bitcoin\Responses\BitcoindResponse;
use NGUtech\Bitcoin\Entity\BitcoinBlock;
use NGUtech\Bitcoin\Entity\BitcoinTransaction;
use NGUtech\Bitcoin\Service\BitcoinCurrencies;
use NGUtech\Bitcoin\Service\BitcoinServiceInterface;
use NGUtech\Bitcoin\Service\SatoshiCurrencies;
use NGUtech\Bitcoin\ValueObject\Address;
use NGUtech\Bitcoin\ValueObject\Bitcoin;
use NGUtech\Bitcoin\ValueObject\Hash;
use NGUtech\Bitcoin\ValueObject\Output;
use NGUtech\Bitcoin\ValueObject\OutputList;
use NGUtech\Bitcoind\Connector\BitcoindRpcConnector;
use Psr\Log\LoggerInterface;

class BitcoindService implements BitcoinServiceInterface
{
    public const FAILURE_REASON_INSUFFICIENT_FUNDS = -4;
    public const FAILURE_REASON_UNRECOGNISED_TX_ID = -5;

    protected LoggerInterface $logger;

    protected BitcoindRpcConnector $connector;

    protected MoneyServiceInterface $moneyService;

    protected array $settings;

    public function __construct(
        LoggerInterface $logger,
        BitcoindRpcConnector $connector,
        MoneyServiceInterface $moneyService,
        array $settings = []
    ) {
        $this->logger = $logger;
        $this->connector = $connector;
        $this->moneyService = $moneyService;
        $this->settings = $settings;
    }

    public function request(BitcoinTransaction $transaction): BitcoinTransaction
    {
        Assertion::true($this->canRequest($transaction->getAmount()), 'Bitcoind service cannot request given amout.');

        $result = $this->call('getnewaddress', [
            (string)$transaction->getLabel(),
            $this->settings['request']['address_type'] ?? 'legacy'
        ]);

        return $transaction->withValues([
            'outputs' => [['address' => $result, 'value' => (string)$transaction->getAmount()]],
            'confTarget' => $this->settings['request']['conf_target'] ?? 3
        ]);
    }

    public function send(BitcoinTransaction $transaction): BitcoinTransaction
    {
        Assertion::true($this->canSend($transaction->getAmount()), 'Bitcoind service cannot send given amount.');

        $fundedTransaction = $this->createFundedTransaction($transaction);
        $signedTransaction = $this->call('signrawtransactionwithwallet', [$fundedTransaction['hex']]);
        if ($signedTransaction['complete'] !== true) {
            throw new PaymentServiceFailed('Incomplete transaction.');
        }
        //@todo improve high fee rate handling
        $hex = $this->call('sendrawtransaction', [$signedTransaction['hex'], 0]);

        return $transaction
            ->withValue('id', $hex)
            ->withValue('feeSettled', $this->convert($fundedTransaction['fee'].BitcoinCurrencies::BTC));
    }

    public function validateAddress(Address $address): bool
    {
        $result = $this->call('validateaddress', [(string)$address]);
        return $result['isvalid'] === true;
    }

    public function estimateFee(BitcoinTransaction $transaction): Bitcoin
    {
        $result = $this->createFundedTransaction($transaction);
        return $this->convert($result['fee'].BitcoinCurrencies::BTC);
    }

    public function getBlock(Hash $id): BitcoinBlock
    {
        $result = $this->call('getblock', [(string)$id]);
        return BitcoinBlock::fromNative([
            'hash' => $result['hash'],
            'merkleRoot' => $result['merkleroot'],
            'confirmations' => $result['confirmations'],
            'transactions' => $result['tx'],
            'height' => $result['height'],
            'timestamp' => (string)$result['time']
        ]);
    }

    public function getTransaction(Hash $id): ?BitcoinTransaction
    {
        try {
            $result = $this->call('gettransaction', [(string)$id]);
        } catch (PaymentServiceFailed $error) {
            if ($error->getCode() === self::FAILURE_REASON_UNRECOGNISED_TX_ID) {
                return null;
            }
            throw $error;
        }

        $outputs = $this->makeOutputList($result['details']);

        return BitcoinTransaction::fromNative([
            'id' => $result['txid'],
            'amount' => (string)$outputs->getTotal(),
            'outputs' => $outputs->toNative(),
            'confirmations' => $result['confirmations'],
            'feeSettled' => $this->convert(ltrim($result['fee'], '-').BitcoinCurrencies::BTC),
            'rbf' => $result['bip125-replaceable'] === 'yes'
        ]);
    }

    public function getConfirmedBalance(Address $address, Natural $confirmations): Bitcoin
    {
        $result = $this->call('listreceivedbyaddress', [$confirmations->toNative(), false, false, (string)$address]);
        return $this->convert(($result[0]['amount'] ?? '0').BitcoinCurrencies::BTC);
    }

    public function canRequest(MoneyInterface $amount): bool
    {
        return ($this->settings['request']['enabled'] ?? true)
            && $amount->isGreaterThanOrEqual(
                $this->convert(($this->settings['request']['minimum'] ?? '1'.SatoshiCurrencies::SAT))
            );
    }

    public function canSend(MoneyInterface $amount): bool
    {
        return ($this->settings['send']['enabled'] ?? true)
            && $amount->isGreaterThanOrEqual(
                $this->convert(($this->settings['send']['minimum'] ?? '1'.SatoshiCurrencies::SAT))
            );
    }

    /** @return mixed */
    protected function call(string $command, array $params = [])
    {
        /** @var Client $client */
        $client = $this->connector->getConnection();

        try {
            /** @var BitcoindResponse $response */
            $response = $client->request($command, ...$params);
        } catch (BadRemoteCallException $error) {
            if ($error->getCode() === self::FAILURE_REASON_INSUFFICIENT_FUNDS) {
                throw new PaymentServiceUnavailable($error->getMessage(), $error->getCode());
            }
            $this->logger->error($error->getMessage());
            throw new PaymentServiceFailed("Bitcoind '$command' error.", $error->getCode());
        }

        //convert numbers to strings
        $json = preg_replace('/"([\w-]+?)":([\d\.e-]+)([,}\]])/', '"$1":"$2"$3', (string)$response->getBody());
        $response = json_decode($json, true);
        if (!empty($response['error']) || !empty($response['result']['errors'])) {
            $this->logger->error($response['error'] ?? $response['result']['errors']);
            throw new PaymentServiceFailed("Bitcoind '$command' request failed.", $response['error']['code']);
        }

        return $response['result'];
    }

    protected function convert(string $amount, string $currency = SatoshiCurrencies::MSAT): Bitcoin
    {
        return $this->moneyService->convert($this->moneyService->parse($amount), $currency);
    }

    protected function createFundedTransaction(BitcoinTransaction $transaction): array
    {
        //@todo coin control in an async context
        $rawTransaction = $this->call('createrawtransaction', [
            [],
            array_map(
                fn(Output $output): array => [
                    (string)$output->getAddress() => $this->formatForRpc($output->getValue())
                ],
                $transaction->getOutputs()->unwrap()
            ),
            0,
            $this->settings['send']['rbf'] ?? true
        ]);

        return $this->call('fundrawtransaction', [$rawTransaction, [
            'feeRate' => $transaction->getFeeRate()->format(8),
            'change_type' => $this->settings['send']['change_type'] ?? 'bech32'
        ]]);
    }

    protected function formatForRpc(Bitcoin $bitcoin): string
    {
        return preg_replace('/[^\d\.]/', '', $this->moneyService->format(
            $this->convert((string)$bitcoin, BitcoinCurrencies::BTC)
        ));
    }

    protected function makeOutputList(array $details): OutputList
    {
        return OutputList::fromNative(
            array_reduce($details, function (array $carry, array $entry): array {
                if ($entry['category'] === 'send') {
                    $carry[] = [
                        'address' => $entry['address'],
                        'value' => (string)$this->convert(ltrim($entry['amount'], '-').BitcoinCurrencies::BTC)
                    ];
                }
                return $carry;
            }, [])
        );
    }
}
