<?php

declare(strict_types=1);

namespace Tests\Fixtures\Pdf;

use Com\Tecnick\Pdf\Encrypt\Encrypt;

/**
 * Minimal, deterministic PDF writer used only to build Stage 0 test fixtures.
 *
 * This is deliberately hand-rolled rather than produced by the engine under test:
 * the fixture matrix has to exercise structures the candidate engine cannot emit
 * (cross-reference streams, object streams, encryption, signature dictionaries,
 * XFA, JavaScript, embedded files) and has to pin exact byte-level geometry.
 *
 * Output is byte-reproducible: no timestamps, no random file IDs, no compression.
 */
final class PdfFixtureWriter
{
    /** @var array<int, string> 1-based object number => serialized object body (without "n 0 obj"). */
    private array $objects = [];

    /** @var array<int, bool> Object numbers whose body is a stream that must not be encrypted. */
    private array $skipEncryption = [];

    private ?Encrypt $encrypt = null;

    private string $fileId = '00112233445566778899AABBCCDDEEFF';

    private string $version = '1.4';

    public function __construct(private readonly string $mode = 'xreftable')
    {
        if (! in_array($mode, ['xreftable', 'xrefstream', 'objstream'], true)) {
            throw new \InvalidArgumentException('Unknown fixture writer mode: '.$mode);
        }

        if ($mode !== 'xreftable') {
            $this->version = '1.5';
        }
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function enableEncryption(): void
    {
        // AES-128 (mode 2) with an empty user password: a real standard-security-handler
        // document that any viewer opens without prompting, and that preflight must reject.
        $this->encrypt = new Encrypt(true, $this->fileId, 2, ['print'], '', 'fixture-owner-password');
        $this->version = '1.6';
    }

    /** Reserve an object number so it can be referenced before it is written. */
    public function reserve(): int
    {
        $this->objects[] = '';

        return count($this->objects);
    }

    public function put(int $num, string $body): int
    {
        $this->objects[$num - 1] = $body;

        return $num;
    }

    public function add(string $body): int
    {
        $num = $this->reserve();

        return $this->put($num, $body);
    }

    /**
     * Add a stream object. The dictionary must not contain /Length; it is appended.
     */
    public function addStream(string $dict, string $data, bool $encryptable = true): int
    {
        $num = $this->reserve();

        return $this->putStream($num, $dict, $data, $encryptable);
    }

    public function putStream(int $num, string $dict, string $data, bool $encryptable = true): int
    {
        if (! $encryptable) {
            $this->skipEncryption[$num] = true;
        }

        $payload = $this->maybeEncrypt($num, $data, $encryptable);
        $dict = rtrim($dict);
        if (! str_ends_with($dict, '>>')) {
            throw new \InvalidArgumentException('Stream dictionary must end with >>');
        }
        $dict = substr($dict, 0, -2).' /Length '.strlen($payload).' >>';

        return $this->put($num, $dict."\nstream\n".$payload."\nendstream");
    }

    /** Encode a literal PDF text string, applying document encryption when enabled. */
    public function textString(int $objNum, string $value): string
    {
        $value = $this->maybeEncrypt($objNum, $value, true);
        $escaped = strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']);

        return '('.$escaped.')';
    }

    private function maybeEncrypt(int $objNum, string $data, bool $encryptable): string
    {
        if ($this->encrypt instanceof Encrypt && $encryptable && ! isset($this->skipEncryption[$objNum])) {
            return $this->encrypt->encryptString($data, $objNum);
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $extraTrailer  Additional trailer/catalog-level entries (e.g. Info).
     */
    public function build(int $rootObj, array $extraTrailer = []): string
    {
        return match ($this->mode) {
            'xrefstream' => $this->buildXrefStream($rootObj, $extraTrailer, false),
            'objstream' => $this->buildXrefStream($rootObj, $extraTrailer, true),
            default => $this->buildXrefTable($rootObj, $extraTrailer),
        };
    }

    /** @param array<string, string> $extraTrailer */
    private function trailerEntries(int $rootObj, array $extraTrailer, int $size): string
    {
        $out = '/Size '.$size.' /Root '.$rootObj.' 0 R';
        foreach ($extraTrailer as $key => $value) {
            $out .= ' /'.$key.' '.$value;
        }
        $out .= ' /ID [<'.$this->fileId.'> <'.$this->fileId.'>]';

        return $out;
    }

    /** @param array<string, string> $extraTrailer */
    private function buildXrefTable(int $rootObj, array $extraTrailer): string
    {
        $pdf = '%PDF-'.$this->version."\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($this->objects as $index => $body) {
            $num = $index + 1;
            $offsets[$num] = strlen($pdf);
            $pdf .= $num." 0 obj\n".$body."\nendobj\n";
        }

        $count = count($this->objects) + 1;
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 ".$count."\n0000000000 65535 f \n";
        for ($num = 1; $num < $count; $num++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
        }

        $trailer = $this->trailerEntries($rootObj, $extraTrailer, $count);
        if ($this->encrypt instanceof Encrypt) {
            $trailer .= ' /Encrypt '.$this->encryptDictObject().' 0 R';
        }

        return $pdf.'trailer <<'.$trailer.">>\nstartxref\n".$xrefPos."\n%%EOF\n";
    }

    private ?int $encryptObj = null;

    private function encryptDictObject(): int
    {
        return $this->encryptObj ?? throw new \LogicException('Encryption dictionary was not written.');
    }

    /**
     * Write the /Encrypt dictionary. Must be called after enableEncryption() and
     * before build(). The dictionary object itself is never encrypted.
     */
    public function writeEncryptDictionary(): int
    {
        if (! $this->encrypt instanceof Encrypt) {
            throw new \LogicException('Encryption is not enabled.');
        }

        $data = $this->encrypt->getEncryptionData();
        $num = $this->reserve();
        $this->skipEncryption[$num] = true;
        $cryptFilters = '';
        if ((int) $data['V'] >= 4) {
            $cryptFilters = sprintf(
                ' /CF << /StdCF << /Type /CryptFilter /CFM /%s /AuthEvent /DocOpen /Length %d >> >>'
                .' /StmF /StdCF /StrF /StdCF',
                (string) $data['CF']['CFM'],
                (int) (((int) $data['Length']) / 8),
            );
        }
        $this->put($num, sprintf(
            '<< /Filter /Standard /V %d /R %d /Length %d /P %d /O <%s> /U <%s>%s >>',
            (int) $data['V'],
            (int) $data['R'],
            (int) $data['Length'],
            (int) $data['protection'],
            bin2hex((string) $data['O']),
            bin2hex((string) $data['U']),
            $cryptFilters,
        ));
        $this->encryptObj = $num;

        return $num;
    }

    /** @param array<string, string> $extraTrailer */
    private function buildXrefStream(int $rootObj, array $extraTrailer, bool $useObjectStreams): string
    {
        // Objects that may live inside an object stream: non-stream objects only.
        $compressible = [];
        if ($useObjectStreams) {
            foreach ($this->objects as $index => $body) {
                $num = $index + 1;
                if (! str_contains($body, "\nstream\n")) {
                    $compressible[] = $num;
                }
            }
        }

        $objStmNum = 0;
        $inObjStm = [];
        if ($compressible !== []) {
            $objStmNum = $this->reserve();
            $pairs = '';
            $payload = '';
            foreach ($compressible as $slot => $num) {
                $pairs .= $num.' '.strlen($payload).' ';
                $payload .= $this->objects[$num - 1]."\n";
                $inObjStm[$num] = $slot;
            }
            $first = strlen($pairs);
            $this->putStream(
                $objStmNum,
                '<< /Type /ObjStm /N '.count($compressible).' /First '.$first.' >>',
                $pairs.$payload,
            );
        }

        $xrefNum = $this->reserve();

        $pdf = '%PDF-'.$this->version."\n%\xE2\xE3\xCF\xD3\n";
        /** @var array<int, array{int, int, int}> $entries */
        $entries = [0 => [0, 0, 65535]];
        foreach ($this->objects as $index => $body) {
            $num = $index + 1;
            if ($num === $xrefNum) {
                continue;
            }
            if (isset($inObjStm[$num])) {
                $entries[$num] = [2, $objStmNum, $inObjStm[$num]];

                continue;
            }
            $entries[$num] = [1, strlen($pdf), 0];
            $pdf .= $num." 0 obj\n".$body."\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $entries[$xrefNum] = [1, $xrefPos, 0];
        $size = count($this->objects) + 1;

        $data = '';
        for ($num = 0; $num < $size; $num++) {
            [$type, $f2, $f3] = $entries[$num] ?? [0, 0, 0];
            $data .= chr($type).pack('N', $f2).pack('n', $f3);
        }

        $dict = '<< /Type /XRef /W [1 4 2] '.$this->trailerEntries($rootObj, $extraTrailer, $size).' >>';
        // The cross-reference stream is never encrypted.
        $this->skipEncryption[$xrefNum] = true;
        $this->putStream($xrefNum, $dict, $data, false);

        $pdf .= $xrefNum." 0 obj\n".$this->objects[$xrefNum - 1]."\nendobj\n";

        return $pdf."startxref\n".$xrefPos."\n%%EOF\n";
    }
}
