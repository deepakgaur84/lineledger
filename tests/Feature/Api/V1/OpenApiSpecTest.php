<?php

it('serves a valid dereferenced OpenAPI spec without auth', function () {
    $response = $this->getJson('/api/v1/openapi.json');

    $response->assertStatus(200)
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('info.title', 'LineLedger REST API');

    $spec = $response->json();

    // Reusable operation fragments were inlined, not left as $refs.
    expect($spec['components']['x-ops'] ?? null)->toBeNull();

    $refsLeft = 0;
    foreach ($spec['paths'] as $item) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (isset($item[$method]['$ref'])) {
                $refsLeft++;
            }
        }
    }
    expect($refsLeft)->toBe(0);

    // Spot-check a couple of representative operations resolved correctly.
    expect($spec['paths']['/customers']['get']['summary'])->toBe('List contacts')
        ->and($spec['paths']['/invoices']['post']['summary'])->toBe('Create (and post) an invoice')
        ->and(count($spec['paths']))->toBeGreaterThan(40);
});

it('documents the 423 edit-lock response on lockable writes only', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertStatus(200)->json();

    expect($spec['components']['responses'])->toHaveKey('Locked')
        ->and($spec['components']['responses']['Locked']['headers'])->toHaveKey('Retry-After')
        ->and($spec['paths']['/invoices/{invoice}']['patch']['responses'])->toHaveKey('423')
        ->and($spec['paths']['/invoices/{invoice}']['patch']['responses']['423']['$ref'])->toBe('#/components/responses/Locked')
        // Inlined shared fragments carry it too…
        ->and($spec['paths']['/bills/{bill}/post']['post']['responses'])->toHaveKey('423')
        ->and($spec['paths']['/customers/{contact}']['delete']['responses'])->toHaveKey('423')
        // …but resources with no web editor never answer 423.
        ->and($spec['paths']['/stock-adjustments/{stockAdjustment}/post']['post']['responses'])->not->toHaveKey('423')
        ->and($spec['paths']['/tax-return-payments/{taxReturnPayment}']['delete']['responses'])->not->toHaveKey('423')
        ->and($spec['paths']['/bank-reconciliations/{bankReconciliation}']['patch']['responses'])->not->toHaveKey('423');
});
