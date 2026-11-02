<?php

declare(strict_types=1);

use App\Admin\Controller\FlowController;
use App\Admin\Form\FlowForm;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\Asset\Manifest;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $_SESSION = [];
    pdo()->exec('DELETE FROM core.campaigns');
    $db = new Connection(pdo());
    $campaigns = new CampaignRepository($db, new CampaignIdGenerator());
    $this->campaign = $campaigns->create(['name' => 'catalog-str-eur', 'slug' => 'catalog']);
    $this->flows = new FlowRepository($db);
    $this->controller = new FlowController($this->flows, $campaigns, new OfferRepository($db), new FlowForm());
    $root = dirname(__DIR__, 3);
    $this->view = new View($root . '/resources/views', new Manifest($root . '/public/assets/manifest.json'),
        new I18n((new TranslatorFactory($root . '/resources/translations'))->create()));
    $this->data = [
        'name' => 'Phone route', 'schema_id' => 13, 'target_type' => 'none',
        'filters' => json_encode([[
            ['field' => 'lander_host', 'op' => 'eq', 'value' => 'catalog.example'],
            ['field' => 'lander_button', 'op' => 'eq', 'value' => 'phone'],
        ]], JSON_THROW_ON_ERROR),
    ];
});

test('admin renders lander filter choices in both languages', function (string $locale, string $hostLabel, string $buttonLabel): void {
    $this->view->i18n->setLocale($locale);
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/campaigns/new');
    $response = $this->controller->new_($request, new Response(), $this->view, $this->campaign->id);
    expect((string)$response->getBody())->toContain(
        '<option value="lander_host">' . $hostLabel . '</option>',
        '<option value="lander_button">' . $buttonLabel . '</option>',
    );
})->with([
    ['en', 'Lander host', 'Lander button'],
    ['ru', 'Домен сайта', 'Кнопка'],
]);

test('admin creates and edits lander filters through the stock form validation', function (): void {
    $factory = new ServerRequestFactory();
    $cid = $this->campaign->id;
    $this->controller->create($factory->createServerRequest('POST', '/flows')->withParsedBody($this->data), new Response(), $cid);
    $created = $this->flows->forCampaign($cid);
    expect($created)->toHaveCount(1);
    $flow = $created[0];
    expect($flow->filters)->toEqual(json_decode($this->data['filters'], true));
    $this->data['filters'] = json_encode([[
        ['field' => 'lander_host', 'op' => 'in', 'value' => 'directory.example,catalog.example'],
        ['field' => 'lander_button', 'op' => 'eq', 'value' => 'chat'],
    ]], JSON_THROW_ON_ERROR);
    $this->controller->update($factory->createServerRequest('POST', '/flows/edit')->withParsedBody($this->data), new Response(), $cid, $flow->id);
    expect($this->flows->findById($flow->id)?->filters)->toEqual(json_decode($this->data['filters'], true));
    $response = $this->controller->edit($factory->createServerRequest('GET', '/flows/edit'), new Response(), $this->view, $cid, $flow->id);
    expect(html_entity_decode((string)$response->getBody(), ENT_QUOTES))->toContain('directory.example,catalog.example', 'chat');
});
