<?php

// Fork: the Transactions drill-down page is not in the report hub catalog, but its
// Print button must still open the inline PDF.

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Reporting\Render\RenderedArtifact;
use App\Services\Reporting\Render\ReportRenderer;

it('prints the Transactions drill-down page as an inline PDF', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->mock(ReportRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->withArgs(fn ($c, $reportKey, $settings, $format) => $reportKey === 'reports.transactions'
            && ($settings['startDate'] ?? null) === '1900-01-01'
            && $format === 'pdf')
        ->andReturn(new RenderedArtifact('%PDF-stub', 'transactions.pdf', 'application/pdf'));

    $this->actingAs($user)->get(route('reports.print', [
        'company' => $company->slug,
        'reportKey' => 'reports.transactions',
        'account' => 1,
        'start' => '1900-01-01',
        'end' => '2026-10-30',
    ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('still blocks a stranger from printing the Transactions page', function () {
    $company = Company::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('reports.print', [
        'company' => $company->slug,
        'reportKey' => 'reports.transactions',
    ]))->assertForbidden();
});
