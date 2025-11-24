<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Contracts;

/**
 * Contract for additional invoice recipients
 * Used when an invoice has multiple recipients
 */
interface VeriFactuRecipient
{
    /**
     * Get the recipient name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the recipient tax ID (NIF/CIF)
     * Can be null for foreign recipients
     *
     * @return string|null
     */
    public function getTaxId(): ?string;
}
