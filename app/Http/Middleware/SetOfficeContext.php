<?php

namespace App\Http\Middleware;

use App\Models\Office;
use App\Services\OfficeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetOfficeContext
{
    /**
     * Set the current office from X-Office-Id header or authenticated user's office.
     */
    public function handle(Request $request, Closure $next): Response
    {
        OfficeContext::clear();
        $user = $request->user();
        $organizationId = $user?->organization_id;

        $officeId = $request->header('X-Office-Id');
        if ($officeId !== null && $organizationId) {
            $office = Office::where('id', (int) $officeId)
                ->where('organization_id', $organizationId)
                ->first();
            if ($office) {
                OfficeContext::setOffice($office);
            }
        }
        if (OfficeContext::getOffice() === null && $user?->office_id) {
            $office = Office::find($user->office_id);
            if ($office && $office->organization_id === $organizationId) {
                OfficeContext::setOffice($office);
            }
        }
        if (OfficeContext::getOffice() === null && $user && $organizationId) {
            $headOffice = Office::where('organization_id', $organizationId)
                ->where('is_head_office', true)
                ->first();
            if ($headOffice) {
                OfficeContext::setOffice($headOffice);
            }
        }

        $response = $next($request);
        OfficeContext::clear();
        return $response;
    }
}
