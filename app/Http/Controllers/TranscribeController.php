<?php

namespace App\Http\Controllers;

use App\Services\Ai\TranscriptionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Turns a recording made in the panel into text in the instruction box.
 *
 * Team-only, and it returns the words rather than acting on them: the operator
 * reads what was heard and presses send themselves. That extra half-second is
 * deliberate — speech recognition mishears numbers, and "twenty percent" heard
 * as "seventy" would otherwise become a proposal built on a number nobody said.
 *
 * Runs in the request rather than on the queue because a person is standing
 * there waiting for their own sentence. The recording length is capped, which
 * caps how long that wait can be.
 */
class TranscribeController extends Controller
{
    /** What a browser's MediaRecorder actually produces, plus common uploads. */
    private const ALLOWED = [
        'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg',
        'audio/wav', 'audio/x-wav', 'audio/x-m4a', 'audio/aac', 'video/webm',
    ];

    public function __invoke(Request $request, TranscriptionClient $transcription): JsonResponse
    {
        if (! $transcription->enabled()) {
            return response()->json([
                'message' => 'תמלול אינו מוגדר בשרת הזה. ראו config/transcription.php.',
            ], 503);
        }

        $request->validate([
            'audio' => [
                'required',
                'file',
                'max:'.(int) ceil($transcription->maxBytes() / 1024),
                'mimetypes:'.implode(',', self::ALLOWED),
            ],
        ], [
            'audio.max' => 'ההקלטה ארוכה מדי.',
            'audio.mimetypes' => 'סוג הקובץ אינו נתמך להקלטה.',
        ]);

        $file = $request->file('audio');
        $text = $transcription->transcribe($file->getRealPath(), $file->getClientOriginalName() ?: 'recording.webm');

        if ($text === null) {
            // "We could not hear it" and "nothing was said" are different
            // answers, and only the first one is worth retrying.
            Log::warning('Transcription returned nothing', [
                'user_id' => $request->user()?->id,
                'bytes' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]);

            return response()->json([
                'message' => 'לא הצלחנו לתמלל את ההקלטה. אפשר לנסות שוב או להקליד.',
            ], 422);
        }

        return response()->json(['text' => $text]);
    }
}
