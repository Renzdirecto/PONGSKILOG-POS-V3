<?php

function landingDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

test('initial landing HTML contains canonical social metadata and valid verified Restaurant JSON-LD', function () {
    $response = $this->get(route('home'))->assertOk();
    $xpath = landingDocument($response->getContent());
    expect($xpath->evaluate('string(//link[@rel="canonical"]/@href)'))->toBe('https://pongskilog.com/')
        ->and($xpath->evaluate('string(//meta[@name="description"]/@content)'))->toContain('Quezon City')
        ->and($xpath->evaluate('string(//meta[@property="og:url"]/@content)'))->toBe('https://pongskilog.com/')
        ->and($xpath->evaluate('string(//meta[@name="twitter:card"]/@content)'))->toBe('summary_large_image')
        ->and($xpath->evaluate('string(//meta[@name="robots"]/@content)'))->toContain('index, follow');
    expect($xpath->evaluate('count(//meta[@name="description"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(//link[@rel="canonical"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(//script[@type="application/ld+json"])'))->toBe(1.0);
    $schema = json_decode($xpath->evaluate('string(//script[@type="application/ld+json"])'), true, flags: JSON_THROW_ON_ERROR);
    expect($schema['@type'])->toBe('Restaurant')->and($schema['name'])->toBe('PONGSKILOG')
        ->and($schema['address']['addressLocality'])->toBe('Quezon City')
        ->and($schema['openingHoursSpecification'][0]['opens'])->toBe('18:00')
        ->and($schema['openingHoursSpecification'][0]['closes'])->toBe('00:00')
        ->and($schema)->not->toHaveKeys(['aggregateRating', 'review', 'telephone', 'priceRange']);
    expect($response->headers->get('X-Robots-Tag'))->toBeNull();
});

test('Search Console verification is absent until a real configured token is provided and is escaped', function () {
    config(['seo.google_site_verification' => null]);
    $this->get(route('home'))->assertDontSee('name="google-site-verification"', false);
    config(['seo.google_site_verification' => 'real-token-<test>']);
    $xpath = landingDocument($this->get(route('home'))->getContent());
    expect($xpath->evaluate('string(//meta[@name="google-site-verification"]/@content)'))->toBe('real-token-<test>');
});

test('sitemap contains only the official public homepage and robots permits discovery of noindex headers', function () {
    $response = $this->get(route('sitemap'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = simplexml_load_string($response->getContent());
    expect((string) $xml->url->loc)->toBe('https://pongskilog.com/')->and($xml->url)->toHaveCount(1);
    $robots = file_get_contents(public_path('robots.txt'));
    expect($robots)->toContain('Sitemap: https://pongskilog.com/sitemap.xml')->not->toContain('Disallow: /');
});

test('internal and authentication pages return noindex while retaining their access controls', function (string $name) {
    $response = $this->get(route($name))->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    if ($name !== 'login') {
        $response->assertRedirect();
    } else {
        $response->assertSee('name="robots" content="noindex, nofollow, noarchive"', false)->assertDontSee('rel="canonical"', false);
    }
})->with(['login', 'dashboard', 'branches.index']);
