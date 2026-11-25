<?php

namespace App\Http\Controllers;

use App\Models\ServiceJob;
use App\Services\AI\AiUnavailable;
use App\Services\AI\ReportWriter;
use App\Services\AI\TextTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * AI helpers under text fields (owner's task of November 25): "Translate to English" and "Write with AI" in reports.
 * The answer goes into the field; nothing is saved here.
 */
class AiTextController extends Controller
{
    public function translate(Request $request, TextTranslator $translator): JsonResponse
    {
        $data = $request->validate(['text' => ['nullable', 'string', 'max:20000']]);
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            return response()->json(['text' => '']);
        }

        try {
            return response()->json(['text' => $translator->toEnglish($text, currentCompany(), $request->user())]);
        } catch (AiUnavailable) {
            return response()->json(['message' => __('ui.translate.failed')], 422);
        }
    }

    public function report(Request $request, ReportWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:20000'],
            'job_id' => ['nullable', 'integer'],
        ]);
        $job = filled($data['job_id'] ?? null) ? ServiceJob::query()->findOrFail((int) $data['job_id']) : null;
        if ($job !== null) {
            Gate::authorize('work', $job);
        }

        try {
            return response()->json(['text' => $writer->write($job, (string) ($data['text'] ?? ''), currentCompany(), $request->user())]);
        } catch (AiUnavailable) {
            return response()->json(['message' => __('ui.ai_write.failed')], 422);
        }
    }
}
