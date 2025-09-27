<?php
namespace App\Assistants;


use Carbon\Carbon;
use App\Helpers\Helper;
use App\GeonamesCountry;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

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
            if (Str::contains($line, 'REF.:')) {
                if (Arr::exists($lines, $i + 1) && !Str::contains($lines[$i + 1], '(to note on your invoice)')) {
                    $order_reference = Str::of($line)->replace('REF.:', '')->trim();
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
            if (Str::contains($line, 'TRANSALLIANCE TS LTD') && Arr::exists($lines, $i + 1)) {
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
                $company_address['street_address'] = (string) Str::of($m[1])->trim();
            }
            if (!isset($company_address['street_address']) || !is_string($company_address['street_address'])) {
                $company_address['street_address'] = '';
            }

            // Defensive: ensure company is always a string
            if (!isset($company_address['company']) || !is_string($company_address['company'])) {
                $company_address['company'] = '';
            }
            // Company code
            if (!isset($company_address['company_code']) || !is_string($company_address['company_code'])) {
                $company_address['company_code'] = '';
            }
            // VAT code
            if (!isset($company_address['vat_code']) || !is_string($company_address['vat_code'])) {
                $company_address['vat_code'] = '';
            }
            // Email
            if (!isset($company_address['email']) || !is_string($company_address['email'])) {
                $company_address['email'] = '';
            }
            // Contact person
            if (!isset($company_address['contact_person']) || !is_string($company_address['contact_person'])) {
                $company_address['contact_person'] = '';
            }
            // Title
            if (!isset($company_address['title']) || !is_string($company_address['title'])) {
                $company_address['title'] = '';
            }
            // City
            if (!isset($company_address['city']) || !is_string($company_address['city'])) {
                $company_address['city'] = '';
            }
            // Country
            if (!isset($company_address['country']) || !is_string($company_address['country'])) {
                $company_address['country'] = '';
            }
            // Postal code
            if (!isset($company_address['postal_code']) || !is_string($company_address['postal_code'])) {
                $company_address['postal_code'] = '';
            }
            // Comment
            if (!isset($company_address['comment']) || !is_string($company_address['comment'])) {
                $company_address['comment'] = '';
            }
            // Postal code and city
            if (preg_match('/GB-([A-Z0-9]+ [A-Z0-9]+) ([A-Z ]+?)(?:\s*Tel\s*:|$)/i', $company_address_raw, $m)) {
                $company_address['postal_code'] = $m[1] ?? '';
                $city = isset($m[2]) ? (string) Str::of($m[2])->trim() : '';
                $company_address['city'] = (Str::length($city) >= 2) ? $city : 'Unknown';
            }
            // Ensure city is always a string
            if (!isset($company_address['city']) || !is_string($company_address['city'])) {
                $company_address['city'] = '';
            }

            // VAT code
            if (preg_match('/VAT NUM:\s*([A-Z0-9]+)/i', $company_address_raw, $m)) {
                $company_address['vat_code'] = $m[1];
            }
            // Contact person (capture up to 'Tel :')
            if (preg_match('/contact:\s*(.*?)\s*Tel\s*:/i', $company_address_raw, $m)) {
                $company_address['contact_person'] = Str::of($m[1])->lower()->trim()->title();
            }
            // Ensure contact_person is always a string
            if (!isset($company_address['contact_person']) || !is_string($company_address['contact_person'])) {
                $company_address['contact_person'] = '';
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
            if (Str::of($line)->trim()->is('Loading')) {
                for ($j = $i; $j < $i + 20 && Arr::exists($lines, $j); $j++) {
                    if (preg_match('/ON: ([\d\/]+)/', $lines[$j], $m)) {
                        try {
                            if (preg_match('/([\d\/]+)\s*(\d{1,2})h(\d{2})?\s*-\s*(\d{1,2})h(\d{2})?/', $lines[$j], $parts)) {
                                $date = $parts[1];
                                $from_hour = Str::padLeft($parts[2], 2, '0');
                                $from_min = isset($parts[3]) ? Str::padLeft($parts[3], 2, '0') : '00';
                                $to_hour = Str::padLeft($parts[4], 2, '0');
                                $to_min = isset($parts[5]) ? Str::padLeft($parts[5], 2, '0') : '00';

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
                    if (Str::contains($lines[$j], 'REFERENCE')) {
                        $loading_company = '';
                        $loading_street = '';
                        $loading_city_postal = '';
                        for ($k = $j + 1; $k < $j + 10 && Arr::exists($lines, $k); $k++) {
                            if (
                                !$loading_company &&
                                Str::of($lines[$k])->trim()->isNotEmpty() &&
                                !Str::contains($lines[$k], ['LM', 'Parc', 'Pal', 'Weight', 'Contact', 'Tel', 'Kgs']) &&
                                !preg_match('/^[\d,.]+$/', Str::of($lines[$k])->trim())
                            ) {
                                $loading_company = Str::of($lines[$k])->trim();
                            } elseif (
                                !$loading_street &&
                                Str::of($lines[$k])->trim()->isNotEmpty() &&
                                !Str::contains($lines[$k], 'GB-') &&
                                !Str::contains($lines[$k], ['LM', 'Parc', 'Pal', 'Weight', 'Contact', 'Tel', 'Kgs']) &&
                                !preg_match('/^[\d,.]+$/', Str::of($lines[$k])->trim())
                            ) {
                                $loading_street = Str::of($lines[$k])->trim();
                            } elseif ($loading_street) {
                                for ($gb_idx = $k; $gb_idx < $k + 4 && Arr::exists($lines, $gb_idx); $gb_idx++) {
                                    $line = Str::of($lines[$gb_idx])->trim();
                                    if (Str::contains($line, 'GB-')) {
                                        $afterGb = Str::after($line, 'GB-');
                                        $parts = preg_split('/\s+/', $afterGb, 3);
                                        $loading_postal_code = implode(' ', array_filter(array_slice($parts, 0, 2)));
                                        $loading_city = Arr::get($parts, 2, '');
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
            if (Str::of($line)->trim()->is('Delivery')) {
                for ($j = $i; $j < $i + 20 && Arr::exists($lines, $j); $j++) {
                    if (preg_match('/ON: ([\d\/]+)/', $lines[$j], $m)) {
                        try {
                            if (preg_match('/([\d\/]+)\s*(\d{1,2})h(\d{2})?\s*-\s*(\d{1,2})h(\d{2})?/', $lines[$j], $parts)) {
                                $date = $parts[1];
                                $from_hour = Str::padLeft($parts[2], 2, '0');
                                $from_min = isset($parts[3]) ? Str::padLeft($parts[3], 2, '0') : '00';
                                $to_hour = Str::padLeft($parts[4], 2, '0');
                                $to_min = isset($parts[5]) ? Str::padLeft($parts[5], 2, '0') : '00';

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
        $instructions = '';

        foreach ($lines as $i => $line) {
            if (Str::of($line)->trim()->is('Instructions')) {

                for ($j = $i + 1; Arr::exists($lines, $j); $j++) {
                    $nextLine = Str::of($lines[$j])->trim();

                    // stop if line is empty or a section marker
                    if ($nextLine->is('') || $nextLine->is('Delivery') || $nextLine->is('Loading')) {
                        break;
                    }

                    $instructions .= ' ' . $nextLine;
                }

                $instructions = Str::of($instructions)->trim()->toString();
                break;
            }
        }

        // always a plain string (never null)
        $instructions = (string) $instructions;

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
            // Defensive: ensure street_address is always a string
            if (!isset($loading_company_address['street_address']) || !is_string($loading_company_address['street_address'])) {
                $loading_company_address['street_address'] = '';
            }
            $loading_company_address['company'] = $loading_company;
            // Defensive: ensure company is always a string
            if (!isset($loading_company_address['company']) || !is_string($loading_company_address['company'])) {
                $loading_company_address['company'] = '';
            }
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
            if (Str::of($line)->trim()->is('Delivery')) {
                for ($j = $i; $j < $i + 10 && Arr::exists($lines, $j); $j++) {
                    if (Str::contains($lines[$j], 'REFERENCE')) {
                        // Next non-empty line is the address
                        for ($k = $j + 1; $k < $j + 5 && Arr::exists($lines, $k); $k++) {
                            if (Str::of($lines[$k])->trim()->isNotEmpty()) {
                                $delivery_address_line = Str::of($lines[$k])->trim();
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
                if (Str::contains(Str::lower($delivery_address_line), Str::lower($name))) {
                    $destination_company_address['country'] = $iso;
                    break;
                }
            }

            if (preg_match('/-([A-Z0-9]+) ([A-Za-z .\'-]+)/', $delivery_address_line, $match)) {
                $destination_company_address['postal_code'] = Str::of($match[1])->trim();
                $destination_company_address['city'] = Str::of($match[2])->trim();
                // Defensive: ensure city is always a string
                if (!isset($destination_company_address['city']) || !is_string($destination_company_address['city'])) {
                    $destination_company_address['city'] = '';
                }
                // Ensure city has minimum length 2
                if (Str::length($destination_company_address['city']) < 2) {
                    $destination_company_address['city'] = 'Unknown';
                }
            } else {
                $parts = preg_split('/\s+/', $delivery_address_line);
                if (count($parts) >= 2) {
                    $destination_company_address['city'] = Arr::pop($parts);
                    // Defensive: ensure city is always a string
                    if (!isset($destination_company_address['city']) || !is_string($destination_company_address['city'])) {
                        $destination_company_address['city'] = '';
                    }
                    // Ensure city has minimum length 2
                    if (Str::length($destination_company_address['city']) < 2) {
                        $destination_company_address['city'] = 'Unknown';
                    }
                    $destination_company_address['postal_code'] = implode(' ', $parts);
                }
            }
            // Defensive: ensure postal_code is always a string
            if (!isset($destination_company_address['postal_code']) || !is_string($destination_company_address['postal_code'])) {
                $destination_company_address['postal_code'] = '';
            }
            $destination_company_address['street_address'] = $delivery_address_line;
            // Defensive: ensure street_address is always a string
            if (!isset($destination_company_address['street_address']) || !is_string($destination_company_address['street_address'])) {
                $destination_company_address['street_address'] = '';
            }
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
            'attachment_filenames' => [Str::lower($attachment_filename ?? '')],
            'freight_price' => $price,
            'freight_currency' => 'EUR',
            'transport_numbers' => '',
            'comment' => $instructions,
        ];

        $this->createOrder($order);
    }
}
