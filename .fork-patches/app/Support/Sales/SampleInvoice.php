<?php

namespace App\Support\Sales;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * An in-memory (never saved) invoice for the designer's preview, used when the
 * company has no real invoice to draw. Every relation the PDF views touch is
 * set explicitly so rendering it issues no queries.
 */
final class SampleInvoice
{
    public static function make(Company $company): Invoice
    {
        $customer = (new Contact)->forceFill([
            'display_name' => __('Sample Customer Ltd'),
            'billing_line1' => '1 Example Street',
            'billing_city' => 'Sampleton',
            'billing_postal_code' => '1000',
            'shipping_line1' => '25 Delivery Road',
            'shipping_city' => 'Sampleton',
            'shipping_postal_code' => '1001',
        ]);

        $lines = new Collection;

        foreach ([
            [__('Consulting services'), '10.0000', 15000],
            [__('Project setup'), '1.0000', 25000],
            [__('Support plan (monthly)'), '3.0000', 9900],
        ] as $order => [$description, $quantity, $unitCents]) {
            $subtotal = (int) round((float) $quantity * $unitCents);

            $line = (new InvoiceLine)->forceFill([
                'description' => $description,
                'quantity' => $quantity,
                'unit_price_cents' => $unitCents,
                'line_discount_cents' => 0,
                'line_subtotal_cents' => $subtotal,
                'line_tax_cents' => 0,
                'secondary_tax_cents' => 0,
                'line_total_cents' => $subtotal,
                'line_order' => $order,
            ]);
            $line->setRelation('item', null);
            $line->setRelation('taxCode', null);
            $line->setRelation('secondaryTaxCode', null);

            $lines->push($line);
        }

        $subtotal = (int) $lines->sum('line_subtotal_cents');

        $invoice = (new Invoice)->forceFill([
            'company_id' => $company->id,
            'invoice_no' => 'INV-0001',
            'invoice_date' => Carbon::today()->toDateString(),
            'due_date' => Carbon::today()->addDays(30)->toDateString(),
            'customer_po' => 'PO-1234',
            'customer_message' => __('Thank you for your business.'),
            'subtotal_cents' => $subtotal,
            'total_cents' => $subtotal,
            'amount_paid_cents' => 0,
        ]);
        $invoice->setRelation('contact', $customer);
        $invoice->setRelation('lines', $lines);
        $invoice->setRelation('terms', null);
        $invoice->setRelation('salesRep', null);
        $invoice->setRelation('formStyle', null);
        $invoice->setRelation('paymentRequests', new Collection);

        return $invoice;
    }
}
