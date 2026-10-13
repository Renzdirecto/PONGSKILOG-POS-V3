<?php

namespace App\Support;

class LandingSeo
{
    /** @return array<string, mixed> */
    public static function metadata(): array
    {
        return [
            'title' => 'PONGSKILOG Quezon City | Silog, Pares & Menu',
            'description' => 'Visit PONGSKILOG on Adarna Street, Quezon City for Filipino silog, pares and lemonade. Explore the menu, store hours and directions. Serving since 2022.',
            'canonical' => 'https://pongskilog.com/',
            'image' => 'https://pongskilog.com/images/branding/og-image.jpg',
            'verification' => config('seo.google_site_verification'),
            'schema' => [
                '@context' => 'https://schema.org', '@type' => 'Restaurant',
                '@id' => 'https://pongskilog.com/#restaurant', 'name' => 'PONGSKILOG',
                'url' => 'https://pongskilog.com/', 'image' => 'https://pongskilog.com/images/branding/og-image.jpg',
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => '1511, Unit V, Purok 8 Adarna St.', 'addressLocality' => 'Quezon City', 'addressRegion' => 'Metro Manila', 'addressCountry' => 'PH'],
                'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 14.6936607, 'longitude' => 121.079139],
                'sameAs' => ['https://www.facebook.com/profile.php?id=100094575655003'],
                'hasMap' => 'https://maps.app.goo.gl/nivpx3cxuP56wAXo8',
                'servesCuisine' => 'Filipino', 'hasMenu' => 'https://pongskilog.com/#board',
                'openingHoursSpecification' => [
                    ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Thursday', 'Friday', 'Saturday'], 'opens' => '18:00', 'closes' => '00:00'],
                    ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Sunday', 'opens' => '00:00', 'closes' => '00:00'],
                ],
                'description' => 'Filipino silog, pares and lemonade in Quezon City. Wednesday is usually closed; contact PONGSKILOG to confirm occasional opening.',
            ],
        ];
    }
}
