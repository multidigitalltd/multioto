<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Speech to text
    |--------------------------------------------------------------------------
    |
    | Turns a recording into an instruction the agent can read. The model runs
    | on our own server — audio of a manager talking about customers never
    | leaves the machine.
    |
    | Nothing here decides anything: a transcript is text typed by a person,
    | shown to them for review before it is sent. Every action the agent then
    | proposes still goes through the same approval as a typed instruction.
    |
    */

    // Off until an endpoint is configured. A missing URL is not "use a default"
    // — a transcription feature that silently sends audio somewhere unexpected
    // is worse than one that says it is not set up.
    'enabled' => (bool) env('TRANSCRIPTION_ENABLED', false),

    /*
    | Which engine dictates, when both are available.
    |
    |   'auto'    — our model first; if a request to it fails, the rest of the
    |               page session falls back to the browser and says so. Nobody
    |               is left unable to dictate because a container is restarting.
    |   'server'  — our model only. No fallback, so audio can never reach a
    |               third party even when our own model is down.
    |   'browser' — the browser's engine only (Chrome/Edge). Fast and needs no
    |               model, but the audio travels through Google.
    |
    | The browser engine remains available in every mode but 'server': it is the
    | only thing that works before a model is configured, and it is what a
    | machine with no microphone permissions for our origin still has.
    */
    'engine' => env('TRANSCRIPTION_ENGINE', 'auto'),

    /*
    | The FULL endpoint URL, not a base to which a path is guessed.
    |
    | Most local servers (faster-whisper-server, whisper.cpp's server, LocalAI,
    | vLLM) speak the OpenAI shape:
    |
    |   http://whisper:8000/v1/audio/transcriptions
    |
    | Naming the whole URL means a server that mounts it somewhere else needs a
    | config change and not a code change.
    */
    'url' => env('TRANSCRIPTION_URL', ''),

    // Sent as the `model` field. Local servers usually ignore it or accept the
    // name of whatever is loaded.
    'model' => env('TRANSCRIPTION_MODEL', 'whisper-1'),

    // Optional bearer token, for a server that asks for one.
    'api_key' => env('TRANSCRIPTION_API_KEY', ''),

    // Naming the language markedly improves Hebrew: left to auto-detect, a
    // short clip of Hebrew is regularly transcribed as Arabic or Persian.
    'language' => env('TRANSCRIPTION_LANGUAGE', 'he'),

    // A person is waiting for their own words, so this runs inside the request
    // rather than on the queue — bounded by the recording length below.
    'timeout_seconds' => (int) env('TRANSCRIPTION_TIMEOUT', 120),

    // How long one recording may be, in seconds. The browser stops at this by
    // itself, and the server refuses anything past the size it implies.
    'max_seconds' => (int) env('TRANSCRIPTION_MAX_SECONDS', 120),

    // Hard ceiling on the upload, independent of duration.
    'max_bytes' => (int) env('TRANSCRIPTION_MAX_BYTES', 26214400), // 25 MB

];
