{{--
    One centred, comma-separated contact line (address · phone · email · website)
    for the Elegant header. Honours the same show_* switches as the shared
    company header partial.
--}}
@php
    $parts = collect();
    if ($settings->show_company_address) {
        $parts->push(collect([
            $company->address_line1,
            $company->address_line2,
            collect([$company->address_city, $company->address_region, $company->address_postal_code])->filter()->implode(' '),
        ])->filter()->implode(', '));
    }
    if ($settings->show_company_phone) { $parts->push($company->phone); }
    if ($settings->show_company_email) { $parts->push($company->email); }
    if ($settings->show_company_website) { $parts->push($company->website); }
    $parts = $parts->filter(fn ($part) => filled($part))->values();
@endphp
@if ($parts->isNotEmpty())
    <div class="muted">{{ $parts->implode('  ·  ') }}</div>
@endif
