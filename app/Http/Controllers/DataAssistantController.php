<?php

namespace App\Http\Controllers;

use App\Services\Assistant\DataAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// "Ask the data" for administrators. Answers come from report statistics only.
class DataAssistantController extends ApiController
{
    public function ask(Request $request, DataAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'question' => [
                'required', 'string',
                'min:'.config('assistant.question_min_characters'),
                'max:'.config('assistant.question_max_characters'),
            ],
        ]);

        if (! $assistant->available()) {
            return $this->error('The AI assistant is not switched on for this system.', 503);
        }

        $result = $assistant->ask(trim($validated['question']));
        if ($result === null) {
            return $this->error('The AI assistant could not answer right now (it may be busy). Please try again in a minute.', 503);
        }

        return response()->json(['success' => true, ...$result]);
    }
}
