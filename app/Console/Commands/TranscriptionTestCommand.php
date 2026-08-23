<?php

namespace App\Console\Commands;

use App\Services\Ai\TranscriptionClient;
use Illuminate\Console\Command;

/**
 * Does the transcription endpoint answer, and in a shape we understand.
 *
 * Speech servers all speak roughly the OpenAI dialect and disagree in the
 * details, so "configured" and "working" are different states. This is how to
 * tell them apart before an operator holds a microphone and finds out.
 */
class TranscriptionTestCommand extends Command
{
    protected $signature = 'transcription:test {file : A local audio file to send}';

    protected $description = 'Send one audio file to the configured transcription model and print what comes back';

    public function handle(TranscriptionClient $client): int
    {
        if (! $client->enabled()) {
            $this->error('תמלול אינו מופעל.');
            $this->line('נדרש TRANSCRIPTION_ENABLED=true ו-TRANSCRIPTION_URL עם כתובת הנקודה המלאה.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("הקובץ {$path} לא נמצא או לא ניתן לקריאה.");

            return self::FAILURE;
        }

        $this->line('כתובת: '.config('transcription.url'));
        $this->line('מודל: '.config('transcription.model').' · שפה: '.config('transcription.language'));
        $this->line('קובץ: '.$path.' ('.number_format(filesize($path)).' bytes)');
        $this->newLine();

        $started = microtime(true);
        $text = $client->transcribe($path);
        $seconds = round(microtime(true) - $started, 1);

        if ($text === null) {
            $this->error("לא התקבל תמלול ({$seconds} שניות).");
            $this->line('הסיבה המדויקת נרשמה ביומן — storage/logs. חפשו TranscriptionClient.');

            return self::FAILURE;
        }

        $this->info("התקבל תמלול ב-{$seconds} שניות:");
        $this->newLine();
        $this->line($text);

        return self::SUCCESS;
    }
}
