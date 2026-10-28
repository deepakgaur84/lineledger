<?php

namespace App\Services\Banking\Feeds\DTO;

/**
 * One bank account a provider can see, in the provider-neutral shape the Bank
 * feeds page stores as a BankFeedAccount.
 */
final readonly class FeedAccountInfo
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $institution = null,
        public ?string $accountNumber = null,
        public ?string $currency = null,
    ) {}
}
