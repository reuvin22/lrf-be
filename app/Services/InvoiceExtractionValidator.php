<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Post-LLM validation layer (spec §4-1, closing note). Never blocks — every
 * result still proceeds to NEEDS_REVIEW; this only surfaces warnings for the
 * review screen so nothing is silently dropped.
 */
class InvoiceExtractionValidator
{
    private const MIN_TOLERANCE = 10;

    private const TOLERANCE_RATE = 0.005;

    /**
     * @param  array<string, mixed>  $extracted
     * @return array<int, string>
     */
    public function validate(array $extracted): array
    {
        $warnings = array_values(array_filter(
            is_array($extracted['warnings'] ?? null) ? $extracted['warnings'] : []
        ));

        if (empty($extracted['document_type'])) {
            $warnings[] = 'document_type could not be determined.';
        }

        if (! empty($extracted['issue_date']) && ! $this->isParsableDate($extracted['issue_date'], 'Y-m-d')) {
            $warnings[] = "issue_date (\"{$extracted['issue_date']}\") could not be parsed.";
        }

        if (! empty($extracted['billing_month']) && ! $this->isParsableDate($extracted['billing_month'], 'Y-m')) {
            $warnings[] = "billing_month (\"{$extracted['billing_month']}\") could not be parsed.";
        }

        $subtotal = $extracted['subtotal'] ?? $extracted['total_with_tax'] ?? null;
        $lines = is_array($extracted['lines'] ?? null) ? $extracted['lines'] : [];

        if ($subtotal !== null && ! empty($lines)) {
            $sum = array_sum(array_map(
                fn ($line) => (float) ($line['amount'] ?? $line['amount_with_tax'] ?? 0),
                $lines
            ));

            $tolerance = max(self::MIN_TOLERANCE, abs((float) $subtotal) * self::TOLERANCE_RATE);

            if (abs($sum - (float) $subtotal) > $tolerance) {
                $warnings[] = sprintf(
                    'Line items sum to %s but the document subtotal is %s.',
                    $sum,
                    $subtotal
                );
            }
        }

        return array_values(array_unique($warnings));
    }

    private function isParsableDate(mixed $value, string $format): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            Carbon::createFromFormat($format, $value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
