<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RU / EN next to the app's microphone (owner's task of November 25): saved for the person, used in every field on
 * every device. The app sets the language, not the phone's keyboard.
 */
class DictationLanguageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['language' => ['required', Rule::in(['en', 'ru'])]]);
        $request->user()->forceFill(['dictation_language' => $data['language']])->save();

        return response()->json(['language' => $data['language']]);
    }
}
