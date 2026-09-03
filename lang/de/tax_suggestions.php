<?php

return [
    'domestic' => 'Der Kunde sitzt in Deutschland; die Steuerklasse des Artikels bestimmt den deutschen Satz.',
    'eu_b2b_service' => 'EU-Geschäftskunde mit USt-IdNr.: Für diese Leistung wird Reverse Charge empfohlen.',
    'eu_b2b_goods' => 'EU-Geschäftskunde mit USt-IdNr.: Eine innergemeinschaftliche Lieferung mit 0 % wird empfohlen.',
    'eu_ambiguous' => 'EU-Kunde ohne verwendbare USt-IdNr.: B2C-/OSS- und Leistungsortregeln erfordern eine manuelle Prüfung.',
    'third_country_goods' => 'Warenlieferung ins Drittland: Ausfuhrbehandlung wird vorbehaltlich des Ausfuhrnachweises empfohlen.',
    'third_country_service' => 'Leistung ins Drittland: 0 % wird empfohlen, der Leistungsort muss jedoch manuell geprüft werden.',
    'missing_customer_country' => 'Das Kundenland fehlt; eine verlässliche internationale Steuerentscheidung ist nicht möglich.',
    'unsupported_company_country' => 'Version 0.1 unterstützt nur Unternehmen mit Sitz in Deutschland; die Steuerbehandlung muss manuell bestätigt werden.',
];
