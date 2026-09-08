<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

/**
 * An artifact names a seal key id this deployment cannot resolve to a certificate.
 *
 * This is an **evidence gap**, not a nuisance: the deployment is holding a document it
 * cannot say who sealed. It happens when a rotation dropped the outgoing key id out of
 * `ESIGN_SEAL_RETIRED_KEYS`, when a retired certificate file was deleted, or when a restore
 * brought the database back without the key material beside it.
 *
 * The fix is always to put the retired *certificate* back — never the retired private key,
 * which verification does not need and which should already have been destroyed on the
 * schedule the key policy sets. See docs/operations/seal-key-management.md.
 */
final class SealKeyUnknownException extends SealingException {}
