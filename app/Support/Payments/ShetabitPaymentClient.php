<?php

namespace App\Support\Payments;

use Shetabit\Multipay\Invoice as MultipayInvoice;
use Shetabit\Multipay\Payment as MultipayPayment;
use Shetabit\Multipay\RedirectionForm;

class ShetabitPaymentClient
{
    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $details
     * @return array{authority:string,redirect_url:string}
     */
    public function purchase(
        array $config,
        string $driver,
        int|float $amount,
        string $callbackUrl,
        array $details = []
    ): array {
        $invoice = (new MultipayInvoice)->amount($amount);
        foreach ($details as $key => $value) {
            if ($value !== null && $value !== '') {
                $invoice->detail($key, $value);
            }
        }

        $authority = null;
        $payment = new MultipayPayment($config);
        $redirection = $payment
            ->via($driver)
            ->callbackUrl($callbackUrl)
            ->purchase($invoice, function ($gatewayDriver, $transactionId) use (&$authority): void {
                $authority = $transactionId;
            })
            ->pay();

        return [
            'authority' => trim((string) $authority),
            'redirect_url' => $this->redirectionUrl($redirection),
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{reference:string,details:array<string,mixed>}
     */
    public function verify(array $config, string $driver, int|float $amount, string $authority): array
    {
        $receipt = (new MultipayPayment($config))
            ->via($driver)
            ->amount($amount)
            ->transactionId($authority)
            ->verify();

        $details = method_exists($receipt, 'getDetails') ? (array) $receipt->getDetails() : [];

        return [
            'reference' => $receipt->getReferenceId(),
            'details' => $details,
        ];
    }

    protected function redirectionUrl(mixed $redirection): string
    {
        if ($redirection instanceof RedirectionForm) {
            return trim($redirection->getAction());
        }

        if (is_string($redirection)) {
            return trim($redirection);
        }

        return '';
    }
}
