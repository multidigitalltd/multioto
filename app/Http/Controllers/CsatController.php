<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    /** Show the 1–5 rating form. */
    public function show(Ticket $ticket, Request $request): View
    {
        // The form posts back with a signature that expires at the SAME time as
        // the invitation the customer clicked — reopening the page near expiry
        // must never mint a fresh, longer validity window.
        $expires = (int) $request->query('expires', 0);
        $expiresAt = $expires > 0
            ? Carbon::createFromTimestamp($expires)
            : now()->addDays((int) config('billing.support.csat.link_days', 30));

        $action = URL::temporarySignedRoute('csat.store', $expiresAt, ['ticket' => $ticket->id]);

        return view('csat.rate', ['ticket' => $ticket, 'action' => $action]);
    }

    /** Record the rating (+ optional comment) and show the thank-you page. */
    public function store(Ticket $ticket, Request $request): View
    {
        // A ticket reopened after the invitation went out is no longer the thing
        // being rated — reject the stale rating instead of recording feedback for
        // a resolution that was undone.
        if (! in_array($ticket->status, Ticket::TERMINAL, true)) {
            return view('csat.unavailable', ['ticket' => $ticket]);
        }

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
