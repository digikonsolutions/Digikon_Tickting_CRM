<?php

namespace App\Http\Controllers;

use App\Services\Assistant\AssistantService;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The in-app assistant: role-specific preset questions answered from the database. */
class AssistantController extends Controller
{
    public function __construct(private AssistantService $assistant, private UserAccountService $accounts)
    {
    }

    public function questions(Request $request): JsonResponse
    {
        $this->ensureUsable($request);

        return response()->json($this->assistant->questions($request->user()));
    }

    public function ask(Request $request): JsonResponse
    {
        $this->ensureUsable($request);
        $data = $request->validate([
            'question_key' => ['required', 'string', 'max:80'],
            'params' => ['sometimes', 'array'],
        ]);

        return response()->json($this->assistant->ask($request->user(), $data['question_key'], $data['params'] ?? []));
    }

    /** Deactivated users and suspended agencies get 403, like the rest of the app. */
    private function ensureUsable(Request $request): void
    {
        abort_unless($this->accounts->canUseAccount($request->user()), 403, 'Your account cannot use the assistant right now.');
    }
}
