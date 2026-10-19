<?php

namespace App\Notifications\Sales;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Locales;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Emails an invoice to a customer with a one-click "View & pay" magic link and
 * the invoice PDF attached. Sent from the platform's verified address (so DKIM/SPF
 * align) but shown under the company's name, with Reply-To pointed at the company
 * so customer replies reach them. The message body is editable per send. The PDF
 * is rendered at delivery time so the queued job stays small.
 *
 * Files attached to the invoice are only included when their ids are passed in
 * $attachmentIds (the sender ticks them on the send confirmation); they are read
 * from storage at delivery time too.
 */
class InvoiceSharedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Largest combined size of invoice attachments in one email (bytes). */
    public const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;

    /**
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<int>  $attachmentIds
     */
    public function __construct(
        public Invoice $invoice,
        public Company $company,
        public string $payUrl,
        public string $message,
        public ?string $replyToAddress = null,
        public ?string $senderName = null,
        public array $cc = [],
        public array $bcc = [],
        public array $attachmentIds = [],
    ) {
        // Route-notified (a bare address), so there's no notifiable preference:
        // pin the document language here so Laravel renders the whole message in it.
        $this->locale = Locales::forDocument($invoice->contact, $company);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildMail();
    }

    private function buildMail(): MailMessage
    {
        $companyName = $this->company->brand_name ?: $this->company->name;
        $senderName = $this->senderName ?: $companyName;
        $amount = number_format($this->invoice->total_cents / 100, 2).' '.$this->company->currency_code;
        $renderer = app(InvoicePdfRenderer::class);

        // Send from a no-reply mailbox on the platform's verified domain so the
        // envelope still aligns with DKIM/SPF — Resend verifies the whole domain,
        // not individual mailboxes. We can't send *as* the company's own address
        // (that domain isn't verified with our sender), so the company is surfaced
        // through the display name and Reply-To instead, and the From is a no-reply
        // nobody is expected to write back to.
        $fromAddress = 'no-reply@'.Str::after(config('mail.from.address'), '@');

        $mail = (new MailMessage)
            ->subject(__('Invoice :no from :company', ['no' => $this->invoice->invoice_no, 'company' => $companyName]))
            ->from($fromAddress, $senderName);

        if ($this->replyToAddress !== null) {
            $mail->replyTo($this->replyToAddress, $senderName);
        }

        if ($this->cc !== []) {
            $mail->cc($this->cc);
        }

        if ($this->bcc !== []) {
            $mail->bcc($this->bcc);
        }

        // Custom markdown view so the email reads as the company's, not the
        // platform's: no "LineLedger" header, no "Regards, LineLedger" sign-off.
        $mail->markdown('emails.invoice-shared', [
            'companyName' => $companyName,
            'introMessage' => $this->message,
            'detailLine' => __('Invoice :no — :amount, due :due.', [
                'no' => $this->invoice->invoice_no,
                'amount' => $amount,
                'due' => $this->invoice->due_date?->toDateString() ?? __('on receipt'),
            ]),
            'actionUrl' => $this->payUrl,
        ]);

        $mail->attachData($renderer->raw($this->company, $this->invoice), $renderer->filename($this->invoice), [
            'mime' => 'application/pdf',
        ]);

        foreach ($this->filesToAttach() as $file) {
            $mail->attachData((string) Storage::disk($file->disk)->get($file->path), $file->original_filename, [
                'mime' => $file->mime_type ?: 'application/octet-stream',
            ]);
        }

        return $mail;
    }

    /**
     * The ticked files, limited to ones that really belong to this invoice (and
     * company) and still exist in storage. The explicit scoping matters because
     * this runs in a queue worker with no current company bound.
     *
     * @return Collection<int, Attachment>
     */
    private function filesToAttach(): Collection
    {
        if ($this->attachmentIds === []) {
            return new Collection;
        }

        return Attachment::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('attachable_type', $this->invoice->getMorphClass())
            ->where('attachable_id', $this->invoice->id)
            ->whereKey($this->attachmentIds)
            ->orderBy('id')
            ->get()
            ->filter(fn (Attachment $file) => Storage::disk($file->disk)->exists($file->path))
            ->values();
    }
}
