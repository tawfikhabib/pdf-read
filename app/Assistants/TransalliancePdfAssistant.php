<?php
namespace App\Assistants;


use Carbon\Carbon;
use App\Helpers\Helper;
use App\GeonamesCountry;
use Illuminate\Support\Str;

class TransalliancePdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines) {
        return Str::startsWith($lines[4], "TRANSALLIANCE TS LTD");      
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $company_name = $lines[4];
        // Extract order_reference
        $order_reference = null;
        foreach ($lines as $i => $line) {
            if (strpos($line, 'REF.:') !== false) {
                if (isset($lines[$i + 1]) && strpos($lines[$i + 1], '(to note on your invoice)') === false) {
                    $order_reference = trim(str_replace('REF.:', '', $line));
                } else {
                    if (preg_match('/REF.:\s*(\S+)/', $line, $m)) {
                        $order_reference = $m[1];
                    }
                }
                break;
            }
        }

        // Extract company_address (parse into structured object)
        $company_address_raw = null;
        foreach ($lines as $i => $line) {
            if (strpos($line, 'TRANSALLIANCE TS LTD') !== false && isset($lines[$i + 1])) {
            $company_address_raw = $lines[$i + 1];
            break;
            }
        }

        // Attempt to extract country code from address line
        $country_code = '';
        if ($company_address_raw && preg_match('/([A-Z]{2})-/', $company_address_raw, $cm)) {
            $country_code = \App\GeonamesCountry::getIso($cm[1]);
        }

        $company_address = [
            'company' => $company_name,
            'street_address' => '',
            'city' => 'Unknown',
            'country' => $country_code,
            'postal_code' => '',
            'vat_code' => '',
            'contact_person' => '',
            'email' => '',
            'title' => $company_name,
        ];
        if ($company_address_raw) {
            // Street address
            if (preg_match('/^(.*?)(GB-[A-Z0-9]+ [A-Z0-9]+ [A-Z ]+)/', $company_address_raw, $m)) {
                $company_address['street_address'] = trim($m[1]);
            }
            // Postal code and city
            if (preg_match('/GB-([A-Z0-9]+ [A-Z0-9]+) ([A-Z ]+?)(?:\\s*Tel\\s*:|$)/i', $company_address_raw, $m)) {
                $company_address['postal_code'] = $m[1] ?? '';
                $city = isset($m[2]) ? trim($m[2]) : '';
                $company_address['city'] = (strlen($city) >= 2) ? $city : 'Unknown';
            }

            // VAT code
            if (preg_match('/VAT NUM:\s*([A-Z0-9]+)/i', $company_address_raw, $m)) {
                $company_address['vat_code'] = $m[1];
            }
            // Contact person (capture up to 'Tel :')
            if (preg_match('/contact:\s*(.*?)\s*Tel\s*:/i', $company_address_raw, $m)) {
                $company_address['contact_person'] = Str::title(strtolower(trim($m[1])));
            }
            // Email
            if (preg_match('/E-mail\s*:\s*([^\s]+)/i', $company_address_raw, $m)) {
                $company_address['email'] = $m[1];
            }
        }

        // Extract price
        $price = null;
        $currency = 'EUR';
        foreach ($lines as $line) {
            if (preg_match('/SHIPPING PRICE\s*([\d,.]+)\s*(EUR|USD|GBP|PLN|ZAR)/', $line, $m)) {
                $price = uncomma($m[1]);
                $currency = $m[2];
                break;
            }
        }

        // Extract loading info
        $loading_location = null;
        $loading_datetime_from = null;
        $loading_datetime_to = null;
        foreach ($lines as $i => $line) {
            if (trim($line) === 'Loading') {
                for ($j = $i; $j < $i + 20 && isset($lines[$j]); $j++) {
                    if (preg_match('/ON: ([\d\/]+)/', $lines[$j], $m)) {
                        try {
                            if (preg_match('/([\d\/]+)\s*(\d{1,2})h(\d{2})?\s*-\s*(\d{1,2})h(\d{2})?/', $lines[$j], $parts)) {
                                $date = $parts[1];
                                $from_hour = str_pad($parts[2], 2, '0', STR_PAD_LEFT);
                                $from_min = isset($parts[3]) ? str_pad($parts[3], 2, '0', STR_PAD_LEFT) : '00';
                                $to_hour = str_pad($parts[4], 2, '0', STR_PAD_LEFT);
                                $to_min = isset($parts[5]) ? str_pad($parts[5], 2, '0', STR_PAD_LEFT) : '00';

                                $dt_from = \Carbon\Carbon::createFromFormat('d/m/y H:i', "$date $from_hour:$from_min");
                                $dt_to = \Carbon\Carbon::createFromFormat('d/m/y H:i', "$date $to_hour:$to_min");
                                $loading_datetime_from = $dt_from->toIso8601String();
                                $loading_datetime_to = $dt_to->toIso8601String();
                            }
                        } catch (\Exception $e) {
                            $loading_datetime_from = null;
                            $loading_datetime_to = null;
                        }
                    }
                    // After REFERENCE, collect company, street, and city/postal
                    if (preg_match('/REFERENCE/', $lines[$j])) {
                        $loading_company = '';
                        $loading_street = '';
                        $loading_city_postal = '';
                        for ($k = $j + 1; $k < $j + 10 && isset($lines[$k]); $k++) {
                            if (
                                !$loading_company &&
                                trim($lines[$k]) &&
                                !preg_match('/LM|Parc|Pal|Weight|Contact|Tel|Kgs/', $lines[$k]) &&
                                !preg_match('/^[\d,.]+$/', trim($lines[$k]))
                            ) {
                                $loading_company = trim($lines[$k]);
                            } elseif (
                                !$loading_street &&
                                trim($lines[$k]) &&
                                !preg_match('/GB-/', $lines[$k]) &&
                                !preg_match('/LM|Parc|Pal|Weight|Contact|Tel|Kgs/', $lines[$k]) &&
                                !preg_match('/^[\d,.]+$/', trim($lines[$k]))
                            ) {
                                $loading_street = trim($lines[$k]);
                            } elseif ($loading_street) {
                                for ($gb_idx = $k; $gb_idx < $k + 4 && isset($lines[$gb_idx]); $gb_idx++) {
                                    $line = trim($lines[$gb_idx]);
                                    if (Str::contains($line, 'GB-')) {
                                        $afterGb = Str::after($line, 'GB-');
                                        $parts = preg_split('/\s+/', $afterGb, 3);
                                        $loading_postal_code = implode(' ', array_filter(array_slice($parts, 0, 2)));
                                        $loading_city = $parts[2] ?? '';
                                        break;
                                    }
                                }
                                break;
                            }
                        }
                        $loading_location = $loading_company . ', ' . $loading_street . ', ' . $loading_city_postal;
                        break;
                    }
                }
                break;
            }
        }
        // Extract delivery info
        $delivery_location = null;
        $delivery_datetime_from = null;
        $delivery_datetime_to = null;
        foreach ($lines as $i => $line) {
            if (trim($line) === 'Delivery') {
            for ($j = $i; $j < $i + 20 && isset($lines[$j]); $j++) {
                if (preg_match('/ON: ([\d\/]+)/', $lines[$j], $m)) {
                try {
                    if (preg_match('/([\d\/]+)\s*(\d{1,2})h(\d{2})?\s*-\s*(\d{1,2})h(\d{2})?/', $lines[$j], $parts)) {
                    $date = $parts[1];
                    $from_hour = str_pad($parts[2], 2, '0', STR_PAD_LEFT);
                    $from_min = isset($parts[3]) ? str_pad($parts[3], 2, '0', STR_PAD_LEFT) : '00';
                    $to_hour = str_pad($parts[4], 2, '0', STR_PAD_LEFT);
                    $to_min = isset($parts[5]) ? str_pad($parts[5], 2, '0', STR_PAD_LEFT) : '00';

                    $dt_from = \Carbon\Carbon::createFromFormat('d/m/y H:i', "$date $from_hour:$from_min");
                    $dt_to = \Carbon\Carbon::createFromFormat('d/m/y H:i', "$date $to_hour:$to_min");
                    $delivery_datetime_from = $dt_from->toIso8601String();
                    $delivery_datetime_to = $dt_to->toIso8601String();
                    } else {
                    $dt = \Carbon\Carbon::createFromFormat('d/m/y', $m[1]);
                    $delivery_datetime_from = $dt->toIso8601String();
                    $delivery_datetime_to = $dt->toIso8601String();
                    }
                } catch (\Exception $e) {
                    $delivery_datetime_from = null;
                    $delivery_datetime_to = null;
                }
                }
                if (preg_match('/([A-Z]{2}-[A-Z0-9]+ [A-Z ]+)/', $lines[$j], $m)) {
                $delivery_location = $m[1];
                }
                if (empty($delivery_location) && preg_match('/ICONEX FRANCE (.*)/', $lines[$j], $m)) {
                $delivery_location = $m[1];
                }
            }
            break;
            }
        }

        // Extract cargos
        $cargos = [];
        foreach ($lines as $line) {
            if (preg_match('/M. nature: (.*)/', $line, $m)) {
                $nature = $m[1];
                $weight = null;
                foreach ($lines as $wline) {
                    if (preg_match('/Weight . : ([\d,.]+)/', $wline, $wm)) {
                        $weight = uncomma($wm[1]);
                        break;
                    }
                }
                    $cargos[] = [
                        'title' => $nature,
                        'weight' => $weight,
                        'package_count' => 1,
                        'package_type' => 'pallet',
                        'number' => $order_reference,
                        'type' => 'full',
                        'value' => $price,
                        'currency' => $currency,
                    ];
            }
        }
        if (empty($cargos)) {
            $cargos = [
                [
                    'title' => null,
                    'weight' => null,
                    'package_count' => 1,
                    'package_type' => 'pallet',
                    'number' => '',
                    'type' => 'full',
                    'value' => null,
                    'currency' => '',
                ]
            ];
        }

        // Extract instructions
        $instructions = null;
        foreach ($lines as $i => $line) {
            if (trim($line) === 'Instructions') {
                $instructions = '';
                for ($j = $i + 1; $j < $i + 6 && isset($lines[$j]); $j++) {
                    $instructions .= ' ' . $lines[$j];
                }
                $instructions = trim($instructions);
                break;
            }
        }

        $customer = [
            'side' => 'none',
            'details' => $company_address,
        ];

        // Build loading_company_address from loading_location string
        $loading_company_address = $company_address;
        if ($loading_location) {
            $loading_company_address['country'] = $country_code;
            $loading_company_address['postal_code'] = isset($loading_postal_code) ? $loading_postal_code : '';
            $loading_company_address['city'] = isset($loading_city) ? $loading_city : '';
            $loading_company_address['street_address'] = $loading_street;
            $loading_company_address['company'] = $loading_company;
        }
        // Robustly extract destination address fields
        $destination_company_address = [
            'company' => $delivery_location ?? '',
            'street_address' => '',
            'city' => '',
            'country' => '',
            'postal_code' => '',
            'vat_code' => '',
            'contact_person' => '',
            'email' => '',
            'title' => $delivery_location ?? '',
        ];
        // Find the address line after 'Delivery' and 'REFERENCE'
        $delivery_address_line = '';
        foreach ($lines as $i => $line) {
            if (trim($line) === 'Delivery') {
                for ($j = $i; $j < $i + 10 && isset($lines[$j]); $j++) {
                    if (preg_match('/REFERENCE/', $lines[$j])) {
                        // Next non-empty line is the address
                        for ($k = $j + 1; $k < $j + 5 && isset($lines[$k]); $k++) {
                            if (trim($lines[$k])) {
                                $delivery_address_line = trim($lines[$k]);
                                break;
                            }
                        }
                        break;
                    }
                }
                break;
            }
        }
        // Parse city and postal code from address line
        if ($delivery_address_line) {
            foreach (\App\GeonamesCountry::NAME_TO_ISO as $name => $iso) {
                if (stripos($delivery_address_line, $name) !== false) {
                    $destination_company_address['country'] = $iso;
                    break;
                }
            }

            if (preg_match('/-([A-Z0-9]+) ([A-Za-z .\'-]+)/', $delivery_address_line, $match)) {
                $destination_company_address['postal_code'] = trim($match[1]);
                $destination_company_address['city'] = trim($match[2]);
            } else {
                $parts = preg_split('/\s+/', $delivery_address_line);
                if (count($parts) >= 2) {
                    $destination_company_address['city'] = array_pop($parts);
                    $destination_company_address['postal_code'] = implode(' ', $parts);
                }
            }
            $destination_company_address['street_address'] = $delivery_address_line;
        }

        $order = [
            'customer' => $customer,
            'order_reference' => $order_reference,
            'loading_locations' => [
                [
                    'company_address' => $loading_company_address,
                    'time' => [
                        'datetime_from' => $loading_datetime_from ?? '',
                        'datetime_to' => $loading_datetime_to ?? '',
                    ],
                ]
            ],
            'destination_locations' => [
                [
                    'company_address' => $destination_company_address,
                    'time' => [
                        'datetime_from' => $delivery_datetime_from ?? '',
                        'datetime_to' => $delivery_datetime_to ?? '',
                    ],
                ]
            ],
            'cargos' => $cargos,
            'attachment_filenames' => [mb_strtolower($attachment_filename ?? '')],
            'freight_price' => $price,
            'freight_currency' => 'EUR',
            'transport_numbers' => '',
            'comment' => $instructions,
        ];

        $this->createOrder($order);
    }
}
