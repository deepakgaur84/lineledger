<?php

use App\Actions\Sales\SaveFormStyle;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceFont;
use App\Enums\InvoiceLayout;
use App\Enums\LogoPosition;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\FormStyle;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\User;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Sales\InvoiceDesign;
use App\Support\Sales\SampleInvoice;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function designerInvoice(object $test, ?int $formStyleId = null): Invoice
{
    $customer = Contact::create([
        'display_name' => 'Designer Customer',
        'is_customer' => true,
        'shipping_line1' => '9 Delivery Lane',
        'shipping_city' => 'Dunedin',
    ]);

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-DESIGN-1',
        'invoice_date' => CarbonImmutable::create(2026, 4, 1),
        'due_date' => CarbonImmutable::create(2026, 4, 30),
        'form_style_id' => $formStyleId,
    ]);

    $invoice->lines()->create([
        'account_id' => $test->income->id,
        'description' => 'Designed services',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    return $invoice->fresh();
}

/**
 * Render the invoice the way InvoicePdfRenderer does: the view the style's
 * layout picks, with the renderer's own view data.
 */
function renderDesignedHtml(Company $company, Invoice $invoice): string
{
    $renderer = app(InvoicePdfRenderer::class);
    $method = new ReflectionMethod($renderer, 'data');
    $data = $method->invoke($renderer, $company, $invoice);

    return view($data['design']->layout->view(), $data)->render();
}

it('treats a missing style as the classic layout with no accent', function () {
    $design = InvoiceDesign::for(null);

    expect($design->layout)->toBe(InvoiceLayout::Classic)
        ->and($design->accent)->toBeNull()
        ->and($design->title())->toBe('INVOICE')
        ->and($design->font)->toBe(InvoiceFont::Sans)
        ->and($design->logoPosition)->toBe(LogoPosition::Left)
        ->and($design->showShipTo)->toBeTrue()
        ->and($design->zebraRows)->toBeFalse();
});

it('falls back to the layout accent and ignores a malformed colour', function () {
    $style = FormStyle::factory()->make(['layout' => InvoiceLayout::Banner, 'accent_color' => 'teal']);

    expect(InvoiceDesign::for($style)->accent)->toBe('#0f766e');

    $style->accent_color = '#AA12BC';

    expect(InvoiceDesign::for($style)->accent)->toBe('#aa12bc');
});

it('works out readable text colours for any accent', function () {
    expect(InvoiceDesign::readableOn('#0f766e'))->toBe('#ffffff')
        ->and(InvoiceDesign::readableOn('#facc15'))->toBe('#111827')
        ->and(InvoiceDesign::legibleOnWhite('#2563eb'))->toBe('#2563eb')
        ->and(InvoiceDesign::legibleOnWhite('#facc15'))->not->toBe('#facc15')
        ->and(InvoiceDesign::mixWithWhite('#000000', 0.10))->toBe('#e6e6e6');
});

it('flags long titles so they can be printed smaller', function () {
    $style = FormStyle::factory()->make(['title_text' => 'TAX INVOICE']);

    expect(InvoiceDesign::for($style)->title())->toBe('TAX INVOICE')
        ->and(InvoiceDesign::for($style)->longTitle())->toBeTrue()
        ->and(InvoiceDesign::for(FormStyle::factory()->make(['title_text' => 'RECEIPT']))->longTitle())->toBeFalse();
});

it('saves the designer options and defaults new styles to classic', function () {
    $plain = app(SaveFormStyle::class)->handle(['name' => 'Plain']);

    expect($plain->layout)->toBe(InvoiceLayout::Classic)
        ->and($plain->font_family)->toBe(InvoiceFont::Sans)
        ->and($plain->logo_position)->toBe(LogoPosition::Left)
        ->and($plain->show_ship_to)->toBeTrue()
        ->and($plain->zebra_rows)->toBeFalse();

    $designed = app(SaveFormStyle::class)->handle([
        'name' => 'Designed',
        'layout' => 'elegant',
        'font_family' => 'serif',
        'logo_position' => 'right',
        'logo_max_height' => 80,
        'title_text' => ' TAX INVOICE ',
        'show_ship_to' => false,
        'zebra_rows' => true,
    ]);

    expect($designed->layout)->toBe(InvoiceLayout::Elegant)
        ->and($designed->font_family)->toBe(InvoiceFont::Serif)
        ->and($designed->logo_position)->toBe(LogoPosition::Right)
        ->and($designed->logo_max_height)->toBe(80)
        ->and($designed->title_text)->toBe('TAX INVOICE')
        ->and($designed->show_ship_to)->toBeFalse()
        ->and($designed->zebra_rows)->toBeTrue();
});

it('shows the layout gallery on the designer page', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->assertSeeHtml('data-test="layout-gallery"')
        ->assertSeeHtml('data-test="layout-modern"')
        ->assertSeeHtml('data-test="layout-elegant"')
        ->assertSet('f_layout', 'modern')
        ->assertSet('f_accent_color', '#2563eb');
});

it('creates a designed style from the page', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Banner Teal')
        ->set('f_layout', 'banner')
        ->set('f_font_family', 'serif')
        ->set('f_logo_position', 'center')
        ->set('f_logo_max_height', '90')
        ->set('f_title_text', 'TAX INVOICE')
        ->set('f_show_ship_to', false)
        ->set('f_zebra_rows', true)
        ->call('save')
        ->assertHasNoErrors();

    $style = FormStyle::query()->where('name', 'Banner Teal')->firstOrFail();

    expect($style->layout)->toBe(InvoiceLayout::Banner)
        ->and($style->accent_color)->toBe('#0f766e')
        ->and($style->font_family)->toBe(InvoiceFont::Serif)
        ->and($style->logo_position)->toBe(LogoPosition::Center)
        ->and($style->logo_max_height)->toBe(90)
        ->and($style->title_text)->toBe('TAX INVOICE')
        ->and($style->show_ship_to)->toBeFalse()
        ->and($style->zebra_rows)->toBeTrue();
});

it('loads a saved style back into the form when editing', function () {
    $style = app(SaveFormStyle::class)->handle([
        'name' => 'Round trip',
        'layout' => 'ledger',
        'font_family' => 'mono',
        'logo_position' => 'right',
        'logo_max_height' => 48,
        'title_text' => 'STATEMENT',
        'show_ship_to' => false,
        'zebra_rows' => true,
    ]);

    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openEdit', $style->id)
        ->assertSet('f_layout', 'ledger')
        ->assertSet('f_font_family', 'mono')
        ->assertSet('f_logo_position', 'right')
        ->assertSet('f_logo_max_height', '48')
        ->assertSet('f_title_text', 'STATEMENT')
        ->assertSet('f_show_ship_to', false)
        ->assertSet('f_zebra_rows', true)
        ->assertSeeHtml('data-test="style-preview-saved"');
});

it('switches the accent with the layout unless the user chose their own colour', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_layout', 'banner')
        ->assertSet('f_accent_color', '#0f766e')
        ->set('f_layout', 'classic')
        ->assertSet('f_accent_color', '')
        ->set('f_accent_color', '#aa12bc')
        ->set('f_layout', 'elegant')
        ->assertSet('f_accent_color', '#aa12bc');
});

it('rejects an unknown layout, font, logo position or logo height', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Bad options')
        ->set('f_layout', 'neon')
        ->set('f_font_family', 'comic')
        ->set('f_logo_position', 'diagonal')
        ->set('f_logo_max_height', '5')
        ->call('save')
        ->assertHasErrors(['f_layout', 'f_font_family', 'f_logo_position', 'f_logo_max_height']);
});

it('draws every designed layout through the shared view', function (string $layout) {
    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
        'payment_instructions' => "Bank: Example Bank\nReference: invoice number",
    ]);

    $style = app(SaveFormStyle::class)->handle([
        'name' => 'Layout '.$layout,
        'layout' => $layout,
        'title_text' => 'TAX INVOICE',
        'accent_color' => '#aa12bc',
        'footer_message' => 'Style footer wins',
    ]);

    $html = renderDesignedHtml($this->company, designerInvoice($this, $style->id));

    expect($html)
        ->toContain('tpl-'.$layout)
        ->toContain('TAX INVOICE')
        ->toContain('INV-DESIGN-1')
        ->toContain('Designed services')
        ->toContain('Bill To')
        ->toContain('01-Apr-26')
        ->toContain('Payment details')
        ->toContain('Bank: Example Bank')
        ->toContain('Style footer wins');
})->with(['modern', 'minimal', 'banner', 'ledger', 'elegant']);

it('hides the ship-to block when the style turns it off', function () {
    $shown = app(SaveFormStyle::class)->handle(['name' => 'Ships', 'layout' => 'modern', 'show_ship_to' => true]);
    $hidden = app(SaveFormStyle::class)->handle(['name' => 'No ship', 'layout' => 'modern', 'show_ship_to' => false]);

    expect(renderDesignedHtml($this->company, designerInvoice($this, $shown->id)))->toContain('Ship To')
        ->and(renderDesignedHtml($this->company, designerInvoice($this, $hidden->id)))->not->toContain('Ship To');
});

it('applies the typeface to designed layouts', function () {
    $style = app(SaveFormStyle::class)->handle(['name' => 'Serifed', 'layout' => 'minimal', 'font_family' => 'serif']);

    expect(renderDesignedHtml($this->company, designerInvoice($this, $style->id)))->toContain('DejaVu Serif');
});

it('keeps classic as the original layout but honours title, ship-to and font', function () {
    $style = app(SaveFormStyle::class)->handle([
        'name' => 'Classic plus',
        'layout' => 'classic',
        'title_text' => 'TAX INVOICE',
        'show_ship_to' => false,
        'font_family' => 'serif',
    ]);

    $html = renderDesignedHtml($this->company, designerInvoice($this, $style->id));

    expect($html)
        ->not->toContain('tpl-classic')
        ->toContain('TAX INVOICE')
        ->not->toContain('Ship To')
        ->toContain('DejaVu Serif');
});

it('uses the classic view when a company has no styles at all', function () {
    $html = renderDesignedHtml($this->company, designerInvoice($this));

    expect($html)->toContain('INVOICE')->toContain('Bill To')->not->toContain('tpl-');
});

it('produces a real PDF for every layout', function (string $layout) {
    $style = app(SaveFormStyle::class)->handle(['name' => 'PDF '.$layout, 'layout' => $layout]);

    $pdf = app(InvoicePdfRenderer::class)->raw($this->company, designerInvoice($this, $style->id));

    expect($pdf)->toStartWith('%PDF');
})->with(['classic', 'modern', 'minimal', 'banner', 'ledger', 'elegant']);

it('previews a saved style on the latest invoice as an inline PDF', function () {
    $style = app(SaveFormStyle::class)->handle(['name' => 'Previewable', 'layout' => 'banner']);
    designerInvoice($this);

    $response = $this->get(route('lists.form-styles.preview', ['company' => $this->company, 'style' => $style->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF');
});

it('previews on a built-in sample when the company has no invoices', function () {
    $style = app(SaveFormStyle::class)->handle(['name' => 'Sample preview', 'layout' => 'elegant']);

    $response = $this->get(route('lists.form-styles.preview', ['company' => $this->company, 'style' => $style->id]));

    $response->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
});

it('draws the sample invoice without saving anything', function () {
    $invoice = SampleInvoice::make($this->company);

    expect($invoice->exists)->toBeFalse()
        ->and($invoice->lines)->toHaveCount(3)
        ->and($invoice->total_cents)->toBe(150000 + 25000 + 29700)
        ->and(Invoice::query()->count())->toBe(0);
});

it('does not preview another company\'s style', function () {
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $foreign = app(SaveFormStyle::class)->handle(['name' => 'Foreign']);
    app()->instance('current_company', $this->company);

    $this->get(route('lists.form-styles.preview', ['company' => $this->company, 'style' => $foreign->id]))
        ->assertNotFound();
});
