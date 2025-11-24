<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Contracts;

/**
 * Contract for invoice tax breakdown details
 */
interface VeriFactuBreakdown
{
    /**
     * Get the regime type (ClaveRegimen)
     * Common values: '01' (General regime), '02' (Export), etc.
     *
     * @return string
     */
    public function getRegimeType(): string;

    /**
     * Get the operation type (CalificacionOperacion)
     * Common values: 'S1' (Subject and not exempt), 'S2' (Subject and exempt), etc.
     *
     * @return string
     */
    public function getOperationType(): string;

    /**
     * Get the tax rate as a percentage (e.g., 21.0 for 21% VAT)
     *
     * @return float
     */
    public function getTaxRate(): float;

    /**
     * Get the base amount in euros (amount before tax)
     *
     * @return float
     */
    public function getBaseAmount(): float;

    /**
     * Get the tax amount in euros
     *
     * @return float
     */
    public function getTaxAmount(): float;
}
