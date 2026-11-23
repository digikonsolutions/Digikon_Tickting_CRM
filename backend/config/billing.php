<?php

return [
    // ISO 4217 code stored with every quote and charge. Amounts are integer minor units (cents) in this currency.
    'currency' => env('BILLING_CURRENCY', 'USD'),
    // How long a quote stays acceptable when the admin does not pick an expiry.
    'default_quote_validity_hours' => (int) env('BILLING_QUOTE_VALIDITY_HOURS', 48),
    // Sanity ceiling for one quote, in minor units.
    'max_quote_minor' => 100_000_000,
];
