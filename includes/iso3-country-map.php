<?php

/**
 * WooCommerce stores countries as ISO-2 codes, while Razorpay order creation
 * expects ISO-3 codes in `customer_details.*_address.country`.
 *
 * WooCommerce (and WordPress core) ship no ISO-2 to ISO-3 source, so the map is
 * kept static. It covers the UN member states plus the territories WooCommerce
 * allows as billing/shipping countries.
 */
function rzpGetIso3CountryCode($iso2)
{
    $iso3CountryMap = [
        'AF' => 'AFG', 'AL' => 'ALB', 'DZ' => 'DZA', 'AD' => 'AND', 'AO' => 'AGO',
        'AG' => 'ATG', 'AR' => 'ARG', 'AM' => 'ARM', 'AU' => 'AUS', 'AT' => 'AUT',
        'AZ' => 'AZE', 'BS' => 'BHS', 'BH' => 'BHR', 'BD' => 'BGD', 'BB' => 'BRB',
        'BY' => 'BLR', 'BE' => 'BEL', 'BZ' => 'BLZ', 'BJ' => 'BEN', 'BT' => 'BTN',
        'BO' => 'BOL', 'BA' => 'BIH', 'BW' => 'BWA', 'BR' => 'BRA', 'BN' => 'BRN',
        'BG' => 'BGR', 'BF' => 'BFA', 'BI' => 'BDI', 'CV' => 'CPV', 'KH' => 'KHM',
        'CM' => 'CMR', 'CA' => 'CAN', 'CF' => 'CAF', 'TD' => 'TCD', 'CL' => 'CHL',
        'CN' => 'CHN', 'CO' => 'COL', 'KM' => 'COM', 'CG' => 'COG', 'CD' => 'COD',
        'CR' => 'CRI', 'CI' => 'CIV', 'HR' => 'HRV', 'CU' => 'CUB', 'CY' => 'CYP',
        'CZ' => 'CZE', 'DK' => 'DNK', 'DJ' => 'DJI', 'DM' => 'DMA', 'DO' => 'DOM',
        'EC' => 'ECU', 'EG' => 'EGY', 'SV' => 'SLV', 'GQ' => 'GNQ', 'ER' => 'ERI',
        'EE' => 'EST', 'SZ' => 'SWZ', 'ET' => 'ETH', 'FJ' => 'FJI', 'FI' => 'FIN',
        'FR' => 'FRA', 'GA' => 'GAB', 'GM' => 'GMB', 'GE' => 'GEO', 'DE' => 'DEU',
        'GH' => 'GHA', 'GR' => 'GRC', 'GD' => 'GRD', 'GT' => 'GTM', 'GN' => 'GIN',
        'GW' => 'GNB', 'GY' => 'GUY', 'HT' => 'HTI', 'HN' => 'HND', 'HU' => 'HUN',
        'IS' => 'ISL', 'IN' => 'IND', 'ID' => 'IDN', 'IR' => 'IRN', 'IQ' => 'IRQ',
        'IE' => 'IRL', 'IL' => 'ISR', 'IT' => 'ITA', 'JM' => 'JAM', 'JP' => 'JPN',
        'JO' => 'JOR', 'KZ' => 'KAZ', 'KE' => 'KEN', 'KI' => 'KIR', 'KP' => 'PRK',
        'KR' => 'KOR', 'KW' => 'KWT', 'KG' => 'KGZ', 'LA' => 'LAO', 'LV' => 'LVA',
        'LB' => 'LBN', 'LS' => 'LSO', 'LR' => 'LBR', 'LY' => 'LBY', 'LI' => 'LIE',
        'LT' => 'LTU', 'LU' => 'LUX', 'MG' => 'MDG', 'MW' => 'MWI', 'MY' => 'MYS',
        'MV' => 'MDV', 'ML' => 'MLI', 'MT' => 'MLT', 'MH' => 'MHL', 'MR' => 'MRT',
        'MU' => 'MUS', 'MX' => 'MEX', 'FM' => 'FSM', 'MD' => 'MDA', 'MC' => 'MCO',
        'MN' => 'MNG', 'ME' => 'MNE', 'MA' => 'MAR', 'MZ' => 'MOZ', 'MM' => 'MMR',
        'NA' => 'NAM', 'NR' => 'NRU', 'NP' => 'NPL', 'NL' => 'NLD', 'NZ' => 'NZL',
        'NI' => 'NIC', 'NE' => 'NER', 'NG' => 'NGA', 'MK' => 'MKD', 'NO' => 'NOR',
        'OM' => 'OMN', 'PK' => 'PAK', 'PW' => 'PLW', 'PA' => 'PAN', 'PG' => 'PNG',
        'PY' => 'PRY', 'PE' => 'PER', 'PH' => 'PHL', 'PL' => 'POL', 'PT' => 'PRT',
        'QA' => 'QAT', 'RO' => 'ROU', 'RU' => 'RUS', 'RW' => 'RWA', 'KN' => 'KNA',
        'LC' => 'LCA', 'VC' => 'VCT', 'WS' => 'WSM', 'SM' => 'SMR', 'ST' => 'STP',
        'SA' => 'SAU', 'SN' => 'SEN', 'RS' => 'SRB', 'SC' => 'SYC', 'SL' => 'SLE',
        'SG' => 'SGP', 'SK' => 'SVK', 'SI' => 'SVN', 'SB' => 'SLB', 'SO' => 'SOM',
        'ZA' => 'ZAF', 'SS' => 'SSD', 'ES' => 'ESP', 'LK' => 'LKA', 'SD' => 'SDN',
        'SR' => 'SUR', 'SE' => 'SWE', 'CH' => 'CHE', 'SY' => 'SYR', 'TW' => 'TWN',
        'TJ' => 'TJK', 'TZ' => 'TZA', 'TH' => 'THA', 'TL' => 'TLS', 'TG' => 'TGO',
        'TO' => 'TON', 'TT' => 'TTO', 'TN' => 'TUN', 'TR' => 'TUR', 'TM' => 'TKM',
        'TV' => 'TUV', 'UG' => 'UGA', 'UA' => 'UKR', 'AE' => 'ARE', 'GB' => 'GBR',
        'US' => 'USA', 'UY' => 'URY', 'UZ' => 'UZB', 'VU' => 'VUT', 'VE' => 'VEN',
        'VN' => 'VNM', 'YE' => 'YEM', 'ZM' => 'ZMB', 'ZW' => 'ZWE',
        // WooCommerce supported territories that are not UN member states
        'HK' => 'HKG', 'MO' => 'MAC', 'PS' => 'PSE', 'XK' => 'XKX', 'PR' => 'PRI',
        'GU' => 'GUM', 'VI' => 'VIR', 'AS' => 'ASM', 'MP' => 'MNP', 'UM' => 'UMI',
        // UK Crown dependencies and overseas territories
        'JE' => 'JEY', 'GG' => 'GGY', 'IM' => 'IMN', 'GI' => 'GIB', 'FK' => 'FLK',
        'SH' => 'SHN', 'TC' => 'TCA', 'VG' => 'VGB', 'MS' => 'MSR', 'AI' => 'AIA',
        'KY' => 'CYM', 'BM' => 'BMU', 'PN' => 'PCN', 'GS' => 'SGS', 'IO' => 'IOT',
        // French overseas territories
        'GP' => 'GLP', 'MQ' => 'MTQ', 'GF' => 'GUF', 'RE' => 'REU', 'YT' => 'MYT',
        'PM' => 'SPM', 'BL' => 'BLM', 'MF' => 'MAF', 'TF' => 'ATF', 'NC' => 'NCL',
        'PF' => 'PYF', 'WF' => 'WLF',
        // Dutch Caribbean
        'AW' => 'ABW', 'CW' => 'CUW', 'BQ' => 'BES', 'SX' => 'SXM',
        // Other frequently used territories
        'GL' => 'GRL', 'FO' => 'FRO', 'AX' => 'ALA', 'EH' => 'ESH',
    ];

    $iso2 = strtoupper(trim((string) $iso2));

    if (isset($iso3CountryMap[$iso2]))
    {
        return $iso3CountryMap[$iso2];
    }

    // Pass the value through for codes that are already ISO-3 or unknown, so an
    // address is never dropped entirely because of a missing mapping.
    return $iso2;
}
