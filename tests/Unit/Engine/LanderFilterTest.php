<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\FilterCompiler;
use App\Engine\LanderContext;
use Slim\Psr7\Factory\ServerRequestFactory;

test('lander conditions use the forwarded site and button together', function (array $headers, bool $expected): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/catalog');
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }
    $context = new Context('192.0.2.8', 'Browser', 'catalog', time());
    $context->country = 'fr';
    $context->utm = ['source' => 'newsletter'];
    LanderContext::apply($context, $request);
    $match = (new FilterCompiler())->compile([[
        ['field' => 'lander_host', 'op' => 'eq', 'value' => 'catalog.example'],
        ['field' => 'lander_button', 'op' => 'in', 'value' => ['phone', 'chat']],
        ['field' => 'utm_source', 'op' => 'eq', 'value' => 'newsletter'],
    ]]);
    expect($match($context))->toBe($expected);
})->with([
    'normalized headers' => [['X-Lander-Host' => ' Catalog.Example ', 'X-Lander-Path' => '/play/Phone/?q=1'], true],
    'another site' => [['X-Lander-Host' => 'directory.example', 'X-Lander-Path' => '/play/chat/'], false],
    'another button' => [['X-Lander-Host' => 'catalog.example', 'X-Lander-Path' => '/play/mail/'], false],
    'missing headers' => [['Referer' => 'https://catalog.example/play/phone/'], false],
]);

test('absent lander fields fail exists conditions', function (string $field): void {
    $match = (new FilterCompiler())->compile([[['field' => $field, 'op' => 'exists', 'value' => null]]]);
    expect($match(new Context('198.51.100.4', 'Direct', 'direct', time())))->toBeFalse();
})->with(['lander_host', 'lander_button']);
