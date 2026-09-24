<?php

namespace App\Http\Controllers;

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The company switcher's target. The switcher posts here from a
 * `target="_blank"` form, so the chosen company opens in a new tab while the
 * tab the user clicked in stays where it was.
 *
 * The tab's own company arrives as `from` rather than being read off the
 * user's current company: with several tabs open, that pointer names whichever
 * tab loaded last, so rewriting the Referer with it would find nothing to
 * replace and reopen the old company.
 */
class SwitchCompanyController extends Controller
{
    public function __invoke(Request $request, Company $company, SecurityLogRecorder $recorder): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->belongsToCompany($company), 403);

        $fromSlug = $this->fromCompany($request, $user)?->slug;

        $user->switchCompany($company);

        if ($fromSlug !== $company->slug) {
            $recorder->record(SecurityEvent::CompanySwitched, $user, metadata: [
                'from_company_slug' => $fromSlug,
                'to_company_slug' => $company->slug,
            ]);
        }

        $referer = $this->sameOriginReferer($request);

        if (! $referer) {
            return redirect()->route('dashboard', ['company' => $company->slug]);
        }

        if (! $fromSlug) {
            return redirect()->to($referer);
        }

        return redirect()->to(
            $this->replaceCurrentCompanyInReferer($referer, $fromSlug, $company->slug) ?? $referer
        );
    }

    /**
     * The company of the tab the switch came from, trusted only when the user
     * belongs to it; otherwise the user's most recently used company.
     */
    protected function fromCompany(Request $request, User $user): ?Company
    {
        $slug = $request->input('from');

        $from = is_string($slug) && $slug !== ''
            ? $user->companies()->where('companies.slug', $slug)->first()
            : null;

        return $from ?? $user->currentCompany;
    }

    /**
     * The Referer, only when it points back at this app — never redirect a new
     * tab off-site.
     */
    protected function sameOriginReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        $root = url('/');

        if (! is_string($referer) || ($referer !== $root && ! str_starts_with($referer, $root.'/'))) {
            return null;
        }

        return $referer;
    }

    protected function replaceCurrentCompanyInReferer(string $referer, string $currentCompanySlug, string $newCompanySlug): ?string
    {
        $redirectTo = preg_replace(
            '#/'.preg_quote($currentCompanySlug, '#').'(?=/|\?|$)#',
            '/'.$newCompanySlug,
            $referer,
            1,
        );

        return preg_replace(
            '#([?&]company=)'.preg_quote($currentCompanySlug, '#').'(?=&|$)#',
            '$1'.$newCompanySlug,
            $redirectTo ?? $referer,
            1,
        );
    }
}
