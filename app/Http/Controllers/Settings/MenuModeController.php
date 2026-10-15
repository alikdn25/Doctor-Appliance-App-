<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "My menu: Field | Office" at the top of More (UX-REDESIGN §22), saved for the person on all their devices.
 */
class MenuModeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canChooseMenu(), 403);

        $data = $request->validate(['mode' => ['required', Rule::in(['field', 'office'])]]);
        $user->forceFill(['menu_mode' => $data['mode']])->save();

        return back();
    }
}
