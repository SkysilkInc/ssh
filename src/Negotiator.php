<?php

namespace Amp\Ssh;

use function Amp\call;
use Amp\Promise;
use Amp\Ssh\Encryption\Aes;
use Amp\Ssh\Encryption\Decryption;
use Amp\Ssh\Encryption\Encryption;
use Amp\Ssh\KeyExchange\Curve25519Sha256;
use Amp\Ssh\KeyExchange\DiffieHellmanGroup;
use Amp\Ssh\KeyExchange\KeyExchange;
use Amp\Ssh\Mac\Hash;
use Amp\Ssh\Mac\Mac;
use Amp\Ssh\Message\KeyExchangeInit;
use Amp\Ssh\Message\Message;
use Amp\Ssh\Message\NewKeys;
use Amp\Ssh\Transport\BinaryPacketHandler;

/**
 * Negotiate algorithms to use for the ssh connection.
 *
 * @internal
 */
final class Negotiator {
    /** @var Decryption[] */
    private $decryptions = [];

    /** @var Encryption[] */
    private $encryptions = [];

    /** @var KeyExchange[] */
    private $keyExchanges = [];

    /** @var Mac[] */
    private $macs = [];

    private $sessionId;

    private function addDecryption(Decryption $decryption) {
        $this->decryptions[$decryption->getName()] = $decryption;
    }

    private function addEncryption(Encryption $encryption) {
        $this->encryptions[$encryption->getName()] = $encryption;
    }

    private function addKeyExchange(KeyExchange $keyExchange) {
        $this->keyExchanges[$keyExchange->getName()] = $keyExchange;
    }

    private function addMac(Mac $mac) {
        $this->macs[$mac->getName()] = $mac;
    }

    public function getSessionId(): string {
        return $this->sessionId;
    }

    public function negotiate(BinaryPacketHandler $binaryPacketHandler, string $serverIdentification, string $clientIdentification): Promise {
        return call(function () use ($binaryPacketHandler, $serverIdentification, $clientIdentification) {
            /*
            Key exchange will begin immediately after sending this identifier.
            All packets following the identification string SHALL use the binary
            packet protocol,
            */

            $serverKex = yield $binaryPacketHandler->read();

            if (!$serverKex instanceof KeyExchangeInit) {
                throw new \RuntimeException('Invalid packet');
            }

            $clientKex = $this->createKeyExchange();
            yield $binaryPacketHandler->write($clientKex);

            // Negotiate
            $kex = $this->getKeyExchange($clientKex, $serverKex);
            $encrypt = $this->getEncrypt($clientKex, $serverKex);
            $decrypt = $this->getDecrypt($clientKex, $serverKex);
            $encryptMac = $this->getEncryptMac($clientKex, $serverKex);
            $decryptMac = $this->getDecryptMac($clientKex, $serverKex);

            /** @var Message $exchangeSend */
            /** @var Message $exchangeReceive */
            list($key, $exchangeSend, $exchangeReceive) = yield $kex->exchange($binaryPacketHandler);

            /*
            The hash H is computed as the HASH hash of the concatenation of the
            following:

                string    V_C, the client's identification string (CR and LF
                        excluded)
                string    V_S, the server's identification string (CR and LF
                        excluded)
                string    I_C, the payload of the client's SSH_MSG_KEXINIT
                string    I_S, the payload of the server's SSH_MSG_KEXINIT
                string    K_S, the host key
                mpint     e, exchange value sent by the client
                mpint     f, exchange value sent by the server
                mpint     K, the shared secret
             */

            $clientKexPayload = $clientKex->encode();
            $serverKexPayload = $serverKex->encode();

            $exchangeHash = \pack(
                'Na*Na*Na*Na*Na*Na*Na*Na*',
                \strlen($clientIdentification),
                $clientIdentification,
                \strlen($serverIdentification),
                $serverIdentification,
                \strlen($clientKexPayload),
                $clientKexPayload,
                \strlen($serverKexPayload),
                $serverKexPayload,
                \strlen($kex->getHostKey($exchangeReceive)),
                $kex->getHostKey($exchangeReceive),
                \strlen($kex->getEBytes($exchangeSend)),
                $kex->getEBytes($exchangeSend),
                \strlen($kex->getFBytes($exchangeReceive)),
                $kex->getFBytes($exchangeReceive),
                \strlen($key),
                $key
            );

            $exchangeHash = $kex->hash($exchangeHash);

            if ($this->sessionId === null) {
                $this->sessionId = $exchangeHash;
            }

            $serverHostKeyFormat = $this->getServerHostKey($clientKex, $serverKex);

            if ($serverHostKeyFormat !== $exchangeReceive->signatureFormat || $serverHostKeyFormat !== $exchangeReceive->hostKeyFormat) {
                throw new \RuntimeException('Bad protocol negotiated');
            }

            yield $binaryPacketHandler->write(new NewKeys());
            yield $binaryPacketHandler->read();

            $key = \pack('Na*', \strlen($key), $key);

            $createDerivationKey = function ($type, $length) use ($kex, $key, $exchangeHash) {
                $derivation = $kex->hash($key . $exchangeHash . $type . $this->sessionId);

                while ($length > \strlen($derivation)) {
                    $derivation .= $kex->hash($key . $exchangeHash . $derivation);
                }

                return \substr($derivation, 0, $length);
            };

            $encrypt->resetEncrypt(
                $createDerivationKey('C', $encrypt->getKeySize()),
                $createDerivationKey('A', $encrypt->getBlockSize())
            );

            $decrypt->resetDecrypt(
                $createDerivationKey('D', $decrypt->getKeySize()),
                $createDerivationKey('B', $decrypt->getBlockSize())
            );

            $encryptMac->setKey($createDerivationKey('E', $encryptMac->getLength()));
            $decryptMac->setKey($createDerivationKey('F', $decryptMac->getLength()));

            $binaryPacketHandler->updateEncryption($encrypt, $encryptMac);
            $binaryPacketHandler->updateDecryption($decrypt, $decryptMac);

            return $binaryPacketHandler;
        });
    }

    private function getDecrypt(KeyExchangeInit $clientKex, KeyExchangeInit $serverKex): Decryption {
        $decrypt = \current(\array_intersect(
            $clientKex->encryptionAlgorithmsServerToClient,
            $serverKex->encryptionAlgorithmsServerToClient
        ));

        return $this->decryptions[$decrypt];
    }

    private function getEncrypt(KeyExchangeInit $clientKex, KeyExchangeInit $serverKex): Encryption {
        $encrypt = \current(\array_intersect(
            $clientKex->encryptionAlgorithmsClientToServer,
            $serverKex->encryptionAlgorithmsClientToServer
        ));

        return $this->encryptions[$encrypt];
    }

    private function getKeyExchange(KeyExchangeInit $clientKex, KeyExchangeInit $serverKex): KeyExchange {
        $keyExchangeName = \current(\array_intersect(
            $clientKex->kexAlgorithms,
            $serverKex->kexAlgorithms
        ));

        return $this->keyExchanges[$keyExchangeName];
    }

    private function getServerHostKey(KeyExchangeInit $clientKex, KeyExchangeInit $serverKex) {
        $serverHostKey = \current(\array_intersect(
            $clientKex->serverHostKeyAlgorithms,
            $serverKex->serverHostKeyAlgorithms
        ));

        return $serverHostKey;
    }

    private function getDecryptMac(KeyExchangeInit $clientKex, KeyExchangeInit $serverKex): Mac {
        $mac = \current(\array_intersect(
            $clientKex->macAlgorithmsServerToClient,
            $serverKex->macAlgorithmsServerToClient
        ));

        return clone $this->macs[$mac];
    }

    private function getEncryptMac(KeyExchangeInit $clientKex, KeyExchangeInit $serverKex): Mac {
        $mac = \current(\array_intersect(
            $clientKex->macAlgorithmsClientToServer,
            $serverKex->macAlgorithmsClientToServer
        ));

        return clone $this->macs[$mac];
    }

    private function createKeyExchange(): KeyExchangeInit {
        $clientKex = new KeyExchangeInit();
        $clientKex->cookie = \random_bytes(16);
        $clientKex->kexAlgorithms = \array_keys($this->keyExchanges);
        $clientKex->serverHostKeyAlgorithms = [
            'ssh-rsa', // RECOMMENDED  sign   Raw RSA Key
            'ssh-dss',  // REQUIRED     sign   Raw DSS Key
            'ssh-ed25519', // RECOMMENDED   sign   Raw ED25519 Key
        ];
        $clientKex->encryptionAlgorithmsClientToServer = \array_keys($this->encryptions);
        $clientKex->encryptionAlgorithmsServerToClient = \array_keys($this->decryptions);
        $clientKex->macAlgorithmsServerToClient = \array_keys($this->macs);
        $clientKex->macAlgorithmsClientToServer = \array_keys($this->macs);
        $clientKex->compressionAlgorithmsServerToClient = $clientKex->compressionAlgorithmsClientToServer = [
            'none',
        ];

        return $clientKex;
    }

    public static function create() {
        $negotiator = new static();
        foreach (self::supportedKeyExchanges() as $keyExchange) {
            $negotiator->addKeyExchange($keyExchange);
        }

        foreach (self::supportedEncryptions() as $algorithm) {
            $negotiator->addEncryption($algorithm);
        }

        foreach (self::supportedDecryptions() as $algorithm) {
            $negotiator->addDecryption($algorithm);
        }

        foreach (self::supportedMacs() as $algorithm) {
            $negotiator->addMac($algorithm);
        }

        return $negotiator;
    }

    public static function supportedKeyExchanges() {
        return \array_merge([new Curve25519Sha256()], DiffieHellmanGroup::create());
    }

    public static function supportedEncryptions() {
        return Aes::create();
    }

    public static function supportedDecryptions() {
        return Aes::create();
    }

    public static function supportedMacs() {
        return [
            new Hash('sha256', 'hmac-sha2-256', 32),
            new Hash('sha1', 'hmac-sha1', 20),
        ];
    }
}
