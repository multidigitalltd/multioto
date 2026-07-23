<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Customer-facing satisfaction (CSAT) rating for a resolved ticket. The link is
 * signed (temporarySignedRoute), so it can't be enumerated to another ticket;
 * everything else is a plain rate-and-thank-you flow. A customer may re-submit
 * to correct their rating while the link is valid.
 */
class CsatController extends Controller
{
    /** Show the 1–5 rating form (or a "thanks" once already rated). */
    public function show(Ticket $ticket): View
    {
        // The form posts back to a signature valid for the POST route, so the
        // rating can't be submitted without a valid signed link.
        $action = URL::temporarySignedRoute(
            'csat.store',
            now()->addDays((int) config('billing.support.csat.link_days', 30)),
            ['ticket' => $ticket->id],
        );

        return view('csat.rate', ['ticket' => $ticket, 'action' => $action]);
    }

    /** Record the rating (+ optional comment) and show the thank-you page. */
    public function store(Ticket $ticket, Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $ticket->forceFill([
            'csat_rating' => (int) $data['rating'],
            'csat_comment' => filled($data['comment'] ?? null) ? Str::limit((string) $data['comment'], 1000, '') : null,
            'csat_rated_at' => now(),
        ])->save();

        return view('csat.thanks', ['ticket' => $ticket]);
    }
}
