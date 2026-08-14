<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Spec decision "Verschlüsselungs-Algorithmus": XChaCha20-Poly1305 via the
 * `sodium` PHP extension (bundled with PHP since 7.2, guaranteed present on
 * the core's required PHP >=8.3 — no extra Composer dependency).
 *
 * Uses libsodium's secretstream API (not a single secretbox call) so large
 * database dumps are encrypted/decrypted in chunks rather than loaded into
 * memory whole, while still being authenticated (tamper/corruption of the
 * encrypted file is detected on decrypt, chunk by chunk).
 *
 * Spec decision "At-Rest-Verschlüsselung": opt-in, off by default — callers
 * only invoke this when the admin explicitly enabled it (encryption_enabled
 * setting) and supplied a passphrase; losing that passphrase makes the
 * backup permanently unreadable, which must be communicated in the UI.
 */
final class EncryptionService
{
    private const CHUNK_SIZE = 1024 * 1024; // 1 MiB plaintext per chunk
    private const SALT_BYTES = \SODIUM_CRYPTO_PWHASH_SALTBYTES;

    public function encryptFile(string $sourcePath, string $destPath, string $passphrase): void
    {
        $salt = \random_bytes(self::SALT_BYTES);
        $key = $this->deriveKey($passphrase, $salt);

        [$state, $header] = \sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        $in = \fopen($sourcePath, 'rb');
        $out = \fopen($destPath, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'Konnte Datei zum Verschlüsseln nicht öffnen.'),
            );
        }

        try {
            \fwrite($out, $salt);
            \fwrite($out, $header);

            while (!\feof($in)) {
                $chunk = \fread($in, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new \RuntimeException(\d__('jtl_dbbackup_tool', 'Lesefehler beim Verschlüsseln.'));
                }
                $isLast = \feof($in);
                $tag = $isLast
                    ? \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $encryptedChunk = \sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                \fwrite($out, \pack('N', \strlen($encryptedChunk)) . $encryptedChunk);
            }
        } finally {
            \fclose($in);
            \fclose($out);
            \sodium_memzero($key);
        }
    }

    public function decryptFile(string $sourcePath, string $destPath, string $passphrase): void
    {
        $in = \fopen($sourcePath, 'rb');
        $out = \fopen($destPath, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException(
                \d__('jtl_dbbackup_tool', 'Konnte Datei zum Entschlüsseln nicht öffnen.'),
            );
        }

        try {
            $salt = \fread($in, self::SALT_BYTES);
            $header = \fread($in, \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($salt === false || $header === false || \strlen($salt) !== self::SALT_BYTES) {
                throw new \RuntimeException(
                    \d__('jtl_dbbackup_tool', 'Backup-Datei ist kein gültiges verschlüsseltes Format.'),
                );
            }

            $key = $this->deriveKey($passphrase, $salt);
            $state = \sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

            while (!\feof($in)) {
                $lenBytes = \fread($in, 4);
                if ($lenBytes === false || $lenBytes === '') {
                    break;
                }
                if (\strlen($lenBytes) < 4) {
                    throw new \RuntimeException(
                        \d__('jtl_dbbackup_tool', 'Backup-Datei ist beschädigt (unvollständiger Chunk-Header).'),
                    );
                }
                ['len' => $len] = \unpack('Nlen', $lenBytes);
                $encryptedChunk = \fread($in, $len);
                if ($encryptedChunk === false || \strlen($encryptedChunk) !== $len) {
                    throw new \RuntimeException(
                        \d__('jtl_dbbackup_tool', 'Backup-Datei ist beschädigt (unvollständiger Chunk).'),
                    );
                }

                $result = \sodium_crypto_secretstream_xchacha20poly1305_pull($state, $encryptedChunk);
                if ($result === false) {
                    throw new \RuntimeException(\d__(
                        'jtl_dbbackup_tool',
                        'Entschlüsselung fehlgeschlagen — falsches Passwort oder Datei wurde manipuliert/beschädigt.',
                    ));
                }
                [$chunk] = $result;
                \fwrite($out, $chunk);
            }

            \sodium_memzero($key);
        } finally {
            \fclose($in);
            \fclose($out);
        }
    }

    private function deriveKey(string $passphrase, string $salt): string
    {
        return \sodium_crypto_pwhash(
            \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
    }
}
