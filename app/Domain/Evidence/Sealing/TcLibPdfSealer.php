<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Sealing\Exceptions\SealFailedException;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampTokenRejectedException;
use Closure;
use Com\Tecnick\Pdf\Exception as TcPdfException;
use Throwable;

/**
 * PAdES sealing on tc-lib-pdf and its companion tc-lib-pdf-sign.
 *
 * Every cryptographic step is the library's: the CMS/CAdES assembly, the ASN.1
 * encoding, the `/ByteRange` computation, the RFC 3161 request and token
 * verification. This class supplies checked key material, a policy-bound TSA
 * transport, and the fail-closed decisions around them.
 *
 * The seal is applied to a document that re-declares the input's pages as Form
 * XObjects, because tc-lib-pdf signs a document it lays out and offers no
 * incremental-update signing of foreign bytes. The consequence is stated in
 * docs/stage0/sealing.md: the sealed artifact is a new object, and the retained
 * original is never rewritten in place.
 */
final class TcLibPdfSealer implements PdfSealer
{
    /** @var Closure(): SealMaterial */
    private readonly Closure $material;

    /**
     * @param  Closure(): SealMaterial  $material  Resolved on use, so an unconfigured
     *                                             deployment fails at the seal or the
     *                                             preflight rather than at boot.
     */
    public function __construct(
        Closure $material,
        private readonly TimestampAuthority $timestampAuthority,
        private readonly ArtifactValidator $validator,
        private readonly bool $allowSha1TimestampToken = false,
    ) {
        $this->material = $material;
    }

    public function preflight(AssuranceLevel $level): void
    {
        ($this->material)();

        if ($level->requiresTimestamp()) {
            $this->timestampAuthority->assertUsable();
        }
    }

    public function seal(SealRequest $request): SealedArtifact
    {
        if ($request->pdf === '') {
            throw new SealFailedException('There is nothing to seal: the input PDF is empty.');
        }

        $material = ($this->material)();

        // Checked before any work: a requested level this deployment cannot
        // reach is an error, and the error must not depend on how far the
        // pipeline got before the TSA was contacted.
        if ($request->level->requiresTimestamp()) {
            if (! $this->timestampAuthority->isConfigured()) {
                throw new TimestampAuthorityNotConfiguredException(
                    'PAdES '.$request->level->value.' was requested but no RFC 3161 timestamp authority is '.
                    'configured. The seal is refused; it is not downgraded to '.AssuranceLevel::PadesBB->value.'.'
                );
            }

            $this->timestampAuthority->assertUsable();
        }

        $sealed = $this->buildSealedPdf($request, $material);

        // The request asked for a level; only the artifact can establish one.
        $report = $this->validator->validate($sealed);
        if ($report->reachedLevel() !== $request->level) {
            throw new SealFailedException(
                'The sealed artifact does not reach PAdES '.$request->level->value.
                ' (read back as '.($report->reachedLevel()?->value ?? 'unsigned').
                ($report->failures === [] ? '' : '; '.implode('; ', $report->failures)).
                '). No artifact is published.'
            );
        }

        return new SealedArtifact(
            pdf: $sealed,
            level: $request->level,
            keyId: $material->keyId,
            digestAlgorithm: $material->digestAlgorithm,
            sha256: hash('sha256', $sealed),
            timestampAuthority: $request->level->requiresTimestamp()
                ? $this->timestampAuthority->endpoint()
                : null,
        );
    }

    /**
     * @throws SealingException
     */
    private function buildSealedPdf(SealRequest $request, SealMaterial $material): string
    {
        $document = new SealingDocument;
        $document->useTimestampAuthority($this->timestampAuthority);

        try {
            $source = $document->setImportSourceData($request->pdf);
            if ($document->getSourcePageCount($source) < 1) {
                throw new SealFailedException('The input PDF has no reachable pages.');
            }

            $document->appendDocument($source);

            $document->signature()->configure([
                'profile' => $request->level->engineProfile(),
                'digest_algorithm' => $material->digestAlgorithm,
                'signcert' => $material->certificatePem(),
                'privkey' => $material->privateKeyPem(),
                'password' => '',
                'extracerts' => $material->chainPem(),
                // Form fill-in and signing stay permitted, which is what an
                // approval signature over an executed agreement needs.
                'cert_type' => 2,
                'info' => [
                    'Name' => $material->subject,
                    'Location' => $request->location,
                    'Reason' => $request->reason,
                    'ContactInfo' => $request->contactInfo,
                ],
            ]);

            if ($request->level->requiresTimestamp()) {
                $document->signature()->timestamp([
                    'enabled' => true,
                    'host' => $this->timestampAuthority->endpoint(),
                    'hash_algorithm' => $material->digestAlgorithm,
                    'policy_oid' => '',
                    'nonce_enabled' => true,
                    'timeout' => 30,
                    'verify_peer' => true,
                    'allow_sha1' => $this->allowSha1TimestampToken,
                ]);
            }

            $sealed = $document->getOutPDFString();
        } catch (SealingException $e) {
            // Already typed: a transport or destination failure raised by the
            // injected timestamp authority passes straight through.
            throw $e;
        } catch (TcPdfException $e) {
            throw $this->translate($e, $request);
        } catch (Throwable $e) {
            throw new SealFailedException('Sealing failed: '.$e->getMessage(), 0, $e);
        }

        if ($sealed === '') {
            throw new SealFailedException('The PDF engine produced no bytes.');
        }

        return $sealed;
    }

    /**
     * Map a tc-lib-pdf failure onto this domain's typed exceptions.
     *
     * The library reports a rejected timestamp token through the same
     * exception type as a layout problem, distinguished only by the message it
     * prefixes in Output::requestSignatureTimestampToken(). Matching on that
     * prefix keeps "the TSA answered with something unusable" separate from
     * "the document could not be built", which are different operational
     * problems; anything unrecognized stays a plain sealing failure rather
     * than being guessed at.
     */
    private function translate(TcPdfException $exception, SealRequest $request): SealingException
    {
        if (str_contains($exception->getMessage(), 'Unable to obtain the TSA timestamp')) {
            return new TimestampTokenRejectedException(
                'The timestamp authority response was refused, so PAdES '.$request->level->value.
                ' was not reached and no artifact is published: '.$exception->getMessage(),
                0,
                $exception
            );
        }

        return new SealFailedException('Sealing failed: '.$exception->getMessage(), 0, $exception);
    }
}
