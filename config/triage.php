<?php

/*
|--------------------------------------------------------------------------
| AI-assisted triage
|--------------------------------------------------------------------------
|
| Suggests a category for a new report and flags likely duplicates. Every
| suggestion is advisory: a resident or administrator must confirm it, and
| nothing is changed automatically.
|
| AI options (TRIAGE_AI_PROVIDER):
| - "anthropic" (default): Claude, a paid cloud service. Used only when
|   ANTHROPIC_API_KEY is set.
| - "gemini": Google Gemini, a cloud service with a free tier. Used only when
|   GEMINI_API_KEY is set. On the free tier Google may use the text it receives
|   to improve its products; the privacy notice says so while Gemini is selected.
| Without the AI, or when the AI call fails, times out, or declines, the keyword
| rules below are used, so reporting never depends on the AI.
|
*/

return [
    'ai_enabled' => (bool) env('TRIAGE_AI_ENABLED', true),
    'provider' => env('TRIAGE_AI_PROVIDER', 'anthropic'),

    // Model and effort. Low effort keeps short classification calls fast and cheap.
    'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
    'effort' => env('TRIAGE_AI_EFFORT', 'low'),

    // A resident is waiting on the form, so fail fast and fall back to the rules.
    'timeout_seconds' => (float) env('TRIAGE_AI_TIMEOUT', 15),
    'max_retries' => (int) env('TRIAGE_AI_MAX_RETRIES', 1),

    // Google Gemini (provider "gemini"). Flash-Lite is the fastest, cheapest model and has a free tier.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        // Tried in order when the main model is busy (503) or slow. Comma separated.
        'fallback_models' => env('GEMINI_FALLBACK_MODELS', 'gemini-3.1-flash-lite'),
        // Time each model gets for a triage suggestion before the next one is tried.
        'model_timeout_seconds' => (float) env('GEMINI_MODEL_TIMEOUT', 8),
    ],

    // Duplicate detection looks at open, unarchived reports at the same location.
    'duplicate_window_days' => (int) env('TRIAGE_DUPLICATE_WINDOW_DAYS', 7),
    'max_duplicate_candidates' => 10,

    // Identical inputs reuse a recent answer instead of calling the AI again.
    'cache_minutes' => 30,
    // Category suggestions each resident may request per day (the form asks as they type).
    'daily_suggestions_per_resident' => (int) env('TRIAGE_DAILY_SUGGESTIONS', 100),

    /*
    | Keyword rules (English, Tagalog, Bisaya/Cebuano), keyed by CATEGORY name.
    | If you rename or add a category in Reference Data, update its key here.
    | Matching is case-insensitive and whole-word; phrases are allowed.
    */
    'keywords' => [
        'Sanitation' => [
            'garbage', 'trash', 'rubbish', 'waste', 'litter', 'dump', 'dumping', 'smell', 'stink', 'flies',
            'dead animal', 'not collected', 'basura', 'kalat', 'dumi', 'mabaho', 'baho', 'langaw', 'uod',
            'patay na hayop', 'hindi kinokolekta', 'hugaw', 'basura nga', 'nagbaho', 'patay nga hayop',
        ],
        'Clogged Drainage' => [
            'drainage', 'drain', 'clogged', 'canal', 'sewer', 'flood', 'flooding', 'flooded', 'overflow',
            'overflowing', 'stagnant water', 'kanal', 'barado', 'baradong', 'baha', 'bumabaha', 'binaha',
            'imburnal', 'estero', 'umaapaw', 'lunop', 'nalunop', 'nagbaha', 'bara', 'kanal nga',
        ],
        'Streetlights' => [
            'streetlight', 'streetlights', 'street light', 'street lights', 'lamp post', 'lamppost',
            'light is out', 'lights are out', 'no light', 'dark street', 'flickering', 'poste', 'ilaw',
            'walang ilaw', 'patay ang ilaw', 'patay na ilaw', 'madilim', 'bombilya', 'suga', 'walay suga',
            'ngitngit', 'patay ang suga',
        ],
        'Road Damage' => [
            'pothole', 'potholes', 'road damage', 'damaged road', 'crack', 'cracked', 'sinkhole', 'sidewalk',
            'pavement', 'asphalt', 'lubak', 'lubak-lubak', 'butas sa kalsada', 'sira ang kalsada', 'bitak',
            'bangketa', 'aspalto', 'guba ang dalan', 'lungag', 'buslot sa dalan',
        ],
        'Public Safety' => [
            'open manhole', 'manhole', 'live wire', 'exposed wire', 'fallen wire', 'fire', 'fight', 'theft',
            'stolen', 'robbery', 'crime', 'drunk', 'stray dog', 'stray dogs', 'dog bite', 'suspicious',
            'vandalism', 'drugs', 'sunog', 'nag-aaway', 'nagaaway', 'gulo', 'nakaw', 'ninakaw', 'magnanakaw', 'holdap',
            'lasing', 'asong gala', 'kinagat', 'kahina-hinala', 'kawat', 'gikawat', 'iro', 'nangagat',
            'hubog', 'kable', 'nakalawlaw nga wire',
        ],
        // "Others" has no keywords: it is the fallback when nothing else fits.
    ],
];
