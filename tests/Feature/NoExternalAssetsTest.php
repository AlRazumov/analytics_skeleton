<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves Chart.js locally and pins its version and license', function () {
    $file = public_path('vendor/chartjs/chart.umd.min.js');

    expect(file_exists($file))->toBeTrue()
        ->and(file_get_contents($file, false, null, 0, 200))->toContain('Chart.js v4.5.1')
        ->and(hash_file('sha256', $file))->toBe('48444a82d4edcb5bec0f1965faacdde18d9c17db3063d042abada2f705c9f54a')
        ->and(file_get_contents(public_path('vendor/chartjs/LICENSE.md')))->toContain('MIT License');
});

it('has no external script, stylesheet, image or font links on the rendered pages', function () {
    config(['analytics.mock.profile' => 'small', 'analytics.mock.seed' => 1]);
    $this->artisan('demo:install')->assertExitCode(0);
    $this->actingAs(User::factory()->create());

    foreach (['/dashboards/overview', '/dashboards/stock', '/dashboards/top-products', '/dashboards/turnover'] as $path) {
        $html = $this->get($path)->assertOk()->assertSee('<canvas', false)->getContent();

        // Ссылки на ресурсы, а не навигационные <a href>: script/img src, link href, url(...), @import.
        preg_match_all('~<(?:script|img|iframe|source|link)\b[^>]*\b(?:src|href)\s*=\s*["\']([^"\']+)["\']~i', $html, $tags);
        preg_match_all('~url\(\s*["\']?([^"\')]+)~i', $html, $urls);
        preg_match_all('~@import\s+(?:url\()?["\']?([^"\');]+)~i', $html, $imports);
        $resources = [...$tags[1], ...$urls[1], ...$imports[1]];

        expect($resources)->toContain(asset('vendor/chartjs/chart.umd.min.js'));
        foreach ($resources as $resource) {
            $host = parse_url($resource, PHP_URL_HOST);
            expect($host === null || $host === parse_url(url('/'), PHP_URL_HOST))->toBeTrue("{$path}: внешний ресурс {$resource}");
        }
        expect($html)->not->toContain('cdn.jsdelivr.net');
    }
});
