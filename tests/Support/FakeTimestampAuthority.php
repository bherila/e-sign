<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Evidence\Contracts\TimestampAuthority;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A local RFC 3161 timestamp authority that answers well, or badly on purpose (issue #1).
 *
 * The sealer's B-T path was only ever exercised against a public authority, which the suite
 * skips when offline, and its refusal of a *bad response* was covered only by the signing
 * library's own tests. This stands in for the {@see TimestampAuthority} port — the same seam
 * production uses — and mints real tokens for the request it is handed with `openssl ts`,
 * signed by the synthetic `tsa.test.crt`. Each other mode answers with exactly one thing
 * wrong, so a test can show that one fault, on its own, stops the seal.
 *
 * Needs the `openssl` command, which every CI runner and every workstation that can run
 * tests/Fixtures/crypto/generate.sh already has. Without it this throws rather than letting a
 * test skip: a quietly skipped fail-closed test is the gap it exists to close.
 */
final class FakeTimestampAuthority implements TimestampAuthority
{
    /** A token for this exact request: the one that must be accepted. */
    public const GOOD = 'good';

    /** An empty body. */
    public const EMPTY = 'empty';

    /** Bytes that are not a TimeStampResp at all. */
    public const GARBAGE = 'garbage';

    /** A well-formed TimeStampResp whose status is `rejection`, with no token. */
    public const REJECTED = 'rejected';

    /** A genuine token over different bytes (and so a different nonce). */
    public const OTHER_IMPRINT = 'other_imprint';

    /** A genuine token over the right bytes, answering another request's nonce. */
    public const OTHER_NONCE = 'other_nonce';

    /** The right token with one byte of its signature changed. */
    public const TAMPERED_SIGNATURE = 'tampered_signature';

    /** A token signed by a timestamping certificate that expired in 2020. */
    public const EXPIRED_TSA = 'expired_tsa';

    /** The right response with bytes after it. */
    public const TRAILING_BYTES = 'trailing_bytes';

    public const ENDPOINT = 'https://tsa.fixture.test/tsr';

    /** @var list<string> Every TimeStampReq this authority was sent. */
    public array $requests = [];

    public function __construct(private readonly string $mode = self::GOOD) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function endpoint(): string
    {
        return self::ENDPOINT;
    }

    public function assertUsable(): void {}

    public function post(string $derRequest): string
    {
        $this->requests[] = $derRequest;

        return match ($this->mode) {
            self::GOOD => $this->reply($derRequest),
            self::EMPTY => '',
            self::GARBAGE => "\x30\x82not a timestamp response",
            // TimeStampResp ::= SEQUENCE { status PKIStatusInfo ::= SEQUENCE { status INTEGER 2 } }
            self::REJECTED => "\x30\x05\x30\x03\x02\x01\x02",
            self::OTHER_IMPRINT => $this->reply($this->queryFor(['-data', $this->write(random_bytes(32))])),
            self::OTHER_NONCE => $this->reply($this->queryFor(['-digest', $this->imprintOf($derRequest)])),
            self::TAMPERED_SIGNATURE => self::flipByteNearEnd($this->reply($derRequest)),
            self::EXPIRED_TSA => $this->reply($derRequest, 'tsa-expired.test.crt', 'tsa-expired.test.pkey'),
            self::TRAILING_BYTES => $this->reply($derRequest)."\x00\x00",
            default => throw new RuntimeException('Unknown fake TSA mode "'.$this->mode.'".'),
        };
    }

    /* ------------------------------------------------------------------ openssl */

    private function reply(string $query, string $certificate = 'tsa.test.crt', string $key = 'tsa.test.pkey'): string
    {
        $dir = $this->workspace();
        file_put_contents($dir.'/serial', "01\n");
        file_put_contents($dir.'/tsa.cnf', <<<CNF
            [ tsa_config ]
            serial = {$dir}/serial
            signer_digest = sha256
            default_policy = 1.3.6.1.4.1.99999.1
            digests = sha256
            accuracy = secs:1
            ordering = no
            tsa_name = no
            ess_cert_id_chain = no
            ess_cert_id_alg = sha256
            CNF);

        return $this->openssl([
            'ts', '-reply',
            '-config', $dir.'/tsa.cnf', '-section', 'tsa_config',
            '-queryfile', $this->write($query),
            '-signer', SealingFixtures::cryptoPath($certificate),
            '-inkey', SealingFixtures::cryptoPath($key),
            '-out', $dir.'/response.tsr',
        ], $dir.'/response.tsr');
    }

    /**
     * @param  list<string>  $source  `-data <file>` or `-digest <hex>`.
     */
    private function queryFor(array $source): string
    {
        $out = $this->workspace().'/query.tsq';

        return $this->openssl(['ts', '-query', ...$source, '-sha256', '-cert', '-out', $out], $out);
    }

    /** The message imprint of a TimeStampReq, as hex. */
    private function imprintOf(string $query): string
    {
        $process = new Process(['openssl', 'ts', '-query', '-in', $this->write($query), '-text']);
        $process->mustRun();

        if (preg_match('/Message data:\n((?:\s+[0-9a-f]{4} - .*\n)+)/', $process->getOutput(), $block) !== 1) {
            throw new RuntimeException('Could not read the message imprint of the timestamp request.');
        }

        $hex = '';

        foreach (explode("\n", trim($block[1])) as $line) {
            if (preg_match('/[0-9a-f]{4} - ((?:[0-9a-f]{2}[ -]){0,15}[0-9a-f]{2})/', $line, $bytes) === 1) {
                $hex .= str_replace([' ', '-'], '', $bytes[1]);
            }
        }

        return $hex;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function openssl(array $arguments, string $output): string
    {
        $process = new Process(['openssl', ...$arguments]);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($output)) {
            throw new RuntimeException(
                'The fake TSA needs the `openssl` command and could not run it: '.trim($process->getErrorOutput()),
            );
        }

        return (string) file_get_contents($output);
    }

    private function write(string $bytes): string
    {
        $path = $this->workspace().'/'.bin2hex(random_bytes(6)).'.bin';
        file_put_contents($path, $bytes);

        return $path;
    }

    private function workspace(): string
    {
        static $dir = null;

        if ($dir === null) {
            $dir = sys_get_temp_dir().'/esign-fake-tsa-'.bin2hex(random_bytes(6));
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /**
     * The SignerInfo's signature value is the last element of the token, so a byte a little
     * before the end is inside it: the structure still parses, and only the signature is wrong.
     */
    private static function flipByteNearEnd(string $der): string
    {
        $at = strlen($der) - 16;
        $der[$at] = chr(ord($der[$at]) ^ 0x01);

        return $der;
    }
}
