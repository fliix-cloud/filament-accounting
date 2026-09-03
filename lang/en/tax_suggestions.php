<?php

return [
    'domestic' => 'The customer is in Germany; the item tax class determines the German rate.',
    'eu_b2b_service' => 'EU business customer with a VAT ID: recommend reverse charge for this service.',
    'eu_b2b_goods' => 'EU business customer with a VAT ID: recommend an intra-Community supply at 0%.',
    'eu_ambiguous' => 'EU customer without a usable VAT ID: B2C/OSS and place-of-supply rules require a manual check.',
    'third_country_goods' => 'Third-country goods delivery: recommend export treatment, subject to proof of export.',
    'third_country_service' => 'Third-country service: recommend 0% treatment, but the place of supply requires a manual check.',
    'missing_customer_country' => 'The customer country is missing; no reliable international tax decision is possible.',
    'unsupported_company_country' => 'Version 0.1 supports only companies established in Germany; confirm the tax treatment manually.',
];
