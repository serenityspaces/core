<?php
/**
 * Country and language helpers for SerenitySpaces.
 * Countries stored as ISO 3166-1 alpha-2 codes.
 * Flags rendered via Unicode Regional Indicator letters (emoji).
 */

function countryFlag(string $code): string {
    if (strlen($code) !== 2) return '';
    $code = strtoupper($code);
    return mb_convert_encoding(
        '&#' . (0x1F1E6 + ord($code[0]) - 65) . ';&#' . (0x1F1E6 + ord($code[1]) - 65) . ';',
        'UTF-8', 'HTML-ENTITIES'
    );
}

function getCountryList(): array {
    return [
        'AF' => 'Afghanistan',       'AL' => 'Albania',           'DZ' => 'Algeria',
        'AR' => 'Argentina',         'AU' => 'Australia',         'AT' => 'Austria',
        'BD' => 'Bangladesh',        'BE' => 'Belgium',           'BR' => 'Brazil',
        'BG' => 'Bulgaria',          'CA' => 'Canada',            'CL' => 'Chile',
        'CN' => 'China',             'CO' => 'Colombia',          'HR' => 'Croatia',
        'CZ' => 'Czech Republic',    'DK' => 'Denmark',           'EG' => 'Egypt',
        'FI' => 'Finland',           'FR' => 'France',            'DE' => 'Germany',
        'GH' => 'Ghana',             'GR' => 'Greece',            'HK' => 'Hong Kong',
        'HU' => 'Hungary',           'IN' => 'India',             'ID' => 'Indonesia',
        'IE' => 'Ireland',           'IL' => 'Israel',            'IT' => 'Italy',
        'JP' => 'Japan',             'JO' => 'Jordan',            'KE' => 'Kenya',
        'KR' => 'South Korea',       'LB' => 'Lebanon',           'MY' => 'Malaysia',
        'MX' => 'Mexico',            'MA' => 'Morocco',           'NL' => 'Netherlands',
        'NZ' => 'New Zealand',       'NG' => 'Nigeria',           'NO' => 'Norway',
        'PK' => 'Pakistan',          'PE' => 'Peru',              'PH' => 'Philippines',
        'PL' => 'Poland',            'PT' => 'Portugal',          'RO' => 'Romania',
        'RU' => 'Russia',            'SA' => 'Saudi Arabia',      'RS' => 'Serbia',
        'SG' => 'Singapore',         'ZA' => 'South Africa',      'ES' => 'Spain',
        'SE' => 'Sweden',            'CH' => 'Switzerland',       'TW' => 'Taiwan',
        'TH' => 'Thailand',          'TN' => 'Tunisia',           'TR' => 'Turkey',
        'UA' => 'Ukraine',           'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',    'US' => 'United States',     'VN' => 'Vietnam',
        'ZW' => 'Zimbabwe',
    ];
}

function getLanguageList(): array {
    return [
        'en' => 'English',        'es' => 'Spanish',       'fr' => 'French',
        'de' => 'German',         'pt' => 'Portuguese',    'ar' => 'Arabic',
        'zh' => 'Chinese (Mandarin)', 'yue' => 'Chinese (Cantonese)',
        'hi' => 'Hindi',          'bn' => 'Bengali',       'ru' => 'Russian',
        'ja' => 'Japanese',       'ko' => 'Korean',        'it' => 'Italian',
        'nl' => 'Dutch',          'pl' => 'Polish',        'sv' => 'Swedish',
        'no' => 'Norwegian',      'da' => 'Danish',        'fi' => 'Finnish',
        'tr' => 'Turkish',        'el' => 'Greek',         'he' => 'Hebrew',
        'fa' => 'Farsi / Persian', 'ur' => 'Urdu',         'vi' => 'Vietnamese',
        'th' => 'Thai',           'id' => 'Indonesian',    'ms' => 'Malay',
        'ro' => 'Romanian',       'cs' => 'Czech',         'sk' => 'Slovak',
        'hu' => 'Hungarian',      'uk' => 'Ukrainian',     'bg' => 'Bulgarian',
        'hr' => 'Croatian',       'sr' => 'Serbian',       'af' => 'Afrikaans',
        'sw' => 'Swahili',        'am' => 'Amharic',       'ta' => 'Tamil',
        'te' => 'Telugu',         'ml' => 'Malayalam',     'pa' => 'Punjabi',
        'gu' => 'Gujarati',       'mr' => 'Marathi',       'si' => 'Sinhala',
        'my' => 'Burmese',        'km' => 'Khmer',         'lo' => 'Lao',
        'mn' => 'Mongolian',      'ne' => 'Nepali',        'cy' => 'Welsh',
        'ga' => 'Irish',          'eu' => 'Basque',        'ca' => 'Catalan',
        'tl' => 'Tagalog (Filipino)', 'ceb' => 'Cebuano',    'ilo' => 'Ilocano',
        'war' => 'Waray',         'hil' => 'Hiligaynon',
    ];
}
