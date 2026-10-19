<?php

use App\Actions\Sales\SendInvoiceToCustomer;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Sales\InvoiceSharedNotification;
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Storage\StorageDisks;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->disk = StorageDisks::attachments();
    Storage::fake($this->disk);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $customer = Contact::create(['display_name' => 'Acme', 'email' => 'buyer@acme.test', 'is_customer' => true]);

    $this->invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-ATT-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $this->invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Services',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($this->invoice);
    $this->invoice->refresh();

    $this->attach = function (string $name, string $contents = 'file body', ?int $size = null, ?Invoice $to = null): Attachment {
        $path = 'test/'.$name;
        Storage::disk($this->disk)->put($path, $contents);

        return Attachment::create([
            'company_id' => $this->company->id,
            'attachable_type' => ($to ?? $this->invoice)->getMorphClass(),
            'attachable_id' => ($to ?? $this->invoice)->id,
            'disk' => $this->disk,
            'path' => $path,
            'original_filename' => $name,
            'mime_type' => 'text/plain',
            'size_bytes' => $size ?? strlen($contents),
            'uploaded_by_id' => $this->user->id,
        ]);
    };

    $this->mockPdf = function () {
        $this->mock(InvoicePdfRenderer::class, function ($mock) {
            $mock->shouldReceive('raw')->andReturn('%PDF-1.4 fake');
            $mock->shouldReceive('filename')->andReturn('invoice.pdf');
        });
    };
});

afterEach(fn () => app()->forgetInstance('current_company'));

function invoiceMail(object $test, array $attachmentIds): array
{
    $mail = (new InvoiceSharedNotification(
        invoice: $test->invoice,
        company: $test->company,
        payUrl: 'https://books.test/pay',
        message: 'Hello',
        attachmentIds: $attachmentIds,
    ))->toMail(new AnonymousNotifiable);

    return array_column($mail->rawAttachments, 'name');
}

it('sends only the invoice PDF by default', function () {
    ($this->mockPdf)();
    ($this->attach)('terms.txt');

    expect(invoiceMail($this, []))->toBe(['invoice.pdf']);
});

it('adds the ticked files to the email after the PDF', function () {
    ($this->mockPdf)();
    $a = ($this->attach)('terms.txt', 'AAA');
    ($this->attach)('skipped.txt', 'BBB');
    $c = ($this->attach)('photo.txt', 'CCC');

    expect(invoiceMail($this, [$c->id, $a->id]))->toBe(['invoice.pdf', 'terms.txt', 'photo.txt']);
});

it('never attaches a file that belongs to another invoice', function () {
    ($this->mockPdf)();
    $other = Invoice::create([
        'contact_id' => $this->invoice->contact_id,
        'invoice_no' => 'INV-OTHER',
        'invoice_date' => CarbonImmutable::create(2026, 5, 2),
        'due_date' => CarbonImmutable::create(2026, 6, 1),
    ]);
    $foreign = ($this->attach)('secret.txt', 'nope', null, $other);

    expect(invoiceMail($this, [$foreign->id]))->toBe(['invoice.pdf']);
});

it('skips a file whose stored copy has gone missing', function () {
    ($this->mockPdf)();
    $file = ($this->attach)('gone.txt');
    Storage::disk($this->disk)->delete($file->path);

    expect(invoiceMail($this, [$file->id]))->toBe(['invoice.pdf']);
});

it('passes the ticked ids from the action to the notification', function () {
    Notification::fake();
    $file = ($this->attach)('terms.txt');

    app(SendInvoiceToCustomer::class)->handle($this->company, $this->invoice, ['x@client.test'], 'Hi', bypassOptIn: true, attachmentIds: [$file->id, $file->id]);

    Notification::assertSentOnDemand(InvoiceSharedNotification::class, fn (InvoiceSharedNotification $n) => $n->attachmentIds === [$file->id]);
});

it('sends no attachments from automated callers', function () {
    Notification::fake();
    ($this->attach)('terms.txt');
    $this->invoice->contact->update(['invoice_emails_enabled' => true]);

    app(SendInvoiceToCustomer::class)->handle($this->company, $this->invoice->fresh(), ['x@client.test'], 'Hi');

    Notification::assertSentOnDemand(InvoiceSharedNotification::class, fn (InvoiceSharedNotification $n) => $n->attachmentIds === []);
});

it('hides the attachments option when the invoice has no files', function () {
    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertDontSeeHtml('data-test="send-invoice-attachments"')
        ->assertSee('The email includes the invoice PDF.');
});

it('offers the files unticked and sends none unless the box is ticked', function () {
    Notification::fake();
    ($this->attach)('terms.txt');

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSeeHtml('data-test="send-invoice-attach"')
        ->assertSet('sendAttachments', false)
        ->assertSet('sendAttachmentIds', [])
        ->assertDontSeeHtml('data-test="send-invoice-attachment-list"')
        ->call('sendToClient')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(InvoiceSharedNotification::class, fn (InvoiceSharedNotification $n) => $n->attachmentIds === []);
});

it('ticks every file when "Send attachments" is switched on, and lets you untick some', function () {
    Notification::fake();
    $a = ($this->attach)('terms.txt');
    $b = ($this->attach)('photo.txt');

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('sendAttachments', true)
        ->assertSet('sendAttachmentIds', fn (array $ids) => collect($ids)->sort()->values()->all() === [(string) $a->id, (string) $b->id])
        ->assertSeeHtml('data-test="send-invoice-attachment-list"')
        ->set('sendAttachmentIds', [(string) $a->id])
        ->assertSee('The email includes the invoice PDF and 1 attached file.')
        ->call('sendToClient')
        ->assertHasNoErrors()
        ->assertSet('sendAttachments', false)
        ->assertSet('sendAttachmentIds', []);

    Notification::assertSentOnDemand(InvoiceSharedNotification::class, fn (InvoiceSharedNotification $n) => $n->attachmentIds === [$a->id]);
});

it('ignores ids that are not attached to this invoice', function () {
    Notification::fake();
    $real = ($this->attach)('terms.txt');

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('sendAttachments', true)
        ->set('sendAttachmentIds', [(string) $real->id, '999999'])
        ->call('sendToClient')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(InvoiceSharedNotification::class, fn (InvoiceSharedNotification $n) => $n->attachmentIds === [$real->id]);
});

it('refuses to send when the ticked files are too large for email', function () {
    Notification::fake();
    $big = ($this->attach)('huge.bin', 'x', InvoiceSharedNotification::MAX_ATTACHMENT_BYTES + 1);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('sendAttachments', true)
        ->set('sendAttachmentIds', [(string) $big->id])
        ->call('sendToClient')
        ->assertHasErrors(['sendAttachmentIds']);

    Notification::assertNothingSent();
});
