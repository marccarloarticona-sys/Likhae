<?php

namespace App\Http\Controllers;

use App\Services\Ai\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class WorkspaceAiController extends Controller
{
    public function chat(Request $request, GeminiService $gemini): JsonResponse
    {
        $workspace = strtolower((string) $request->route('workspace'));
        $config = config("workspace_ai.workspaces.{$workspace}");

        abort_unless(is_array($config), 404);
        abort_unless($request->user()?->isAccountType(strtoupper($workspace)), 403);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'page' => ['nullable', 'string', 'max:80'],
        ]);

        $message = trim(strip_tags($data['message']));
        $context = $this->pageContext($workspace, (string) ($data['page'] ?? 'dashboard'), $config);

        if ($reply = $this->notificationCountAnswer($request, $message, $config)) {
            return response()->json(['reply' => $reply, 'mode' => 'local-live-data']);
        }

        if ($workspace !== 'seller' && ($reply = $this->curatedAnswer($message, $config))) {
            return response()->json(['reply' => $reply, 'mode' => 'local-help']);
        }

        if (! $gemini->configured()) {
            if ($workspace === 'seller') {
                return response()->json([
                    'reply' => $this->curatedAnswer($message, $config) ?? $this->localHelp($context),
                    'mode' => 'local-help',
                ]);
            }
            return response()->json(['reply' => $this->localHelp($context), 'mode' => 'local-help']);
        }

        try {
            return response()->json([
                'reply' => $gemini->generate(
                    (string) $config['system_prompt'],
                    $this->generalPrompt($message, $context)
                ),
                'mode' => 'ai',
            ]);
        } catch (Throwable) {
            report("LIKHAE {$config['label']} AI request failed.");

            if ($workspace === 'seller') {
                return response()->json([
                    'reply' => $this->curatedAnswer($message, $config) ?? $this->localHelp($context),
                    'mode' => 'local-help',
                ]);
            }

            return response()->json([
                'reply' => $this->localHelp($context),
                'mode' => 'offline-help',
            ]);
        }
    }

    /** @param array<string,mixed> $config @return array{title:string,features:array<int,string>,workspace:string,role:string} */
    private function pageContext(string $workspace, string $page, array $config): array
    {
        $pageKey = Str::after($page, $workspace.'.');
        $pageKey = Str::before($pageKey, '.');
        $pages = $config['pages'];
        $selected = $pages[$pageKey] ?? $pages['dashboard'];

        return [
            'title' => (string) $selected['title'],
            'features' => array_values($selected['features']),
            'workspace' => $workspace,
            'role' => (string) $config['label'],
        ];
    }

    /** @param array<string,mixed> $config */
    private function curatedAnswer(string $message, array $config): ?string
    {
        $question = mb_strtolower($message);

        if (preg_match('/^\s*(hi|hello|hey)\b/', $question)) {
            return (string) $config['welcome'];
        }

        foreach ($config['local_answers'] as $answer) {
            foreach ($answer['patterns'] as $pattern) {
                if (str_contains($question, mb_strtolower($pattern))) {
                    return (string) $answer['reply'];
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $config */
    private function notificationCountAnswer(Request $request, string $message, array $config): ?string
    {
        if (! preg_match('/\b(notification|notifications|alert|alerts)\b/i', $message)
            || ! preg_match('/\b(how many|count|unread|new|do i have|any)\b/i', $message)) {
            return null;
        }

        $notifications = $request->user()->notifications();
        $total = (clone $notifications)->count();
        $unread = (clone $notifications)->whereNull('read_at')->count();

        if ($total === 0) {
            return "You do not have any notifications right now in your {$config['label']} workspace.";
        }

        return "You have {$unread} unread notification".($unread === 1 ? '' : 's')." out of {$total} total in your {$config['label']} workspace. Use the notification bell to review them.";
    }

    /** @param array{title:string,features:array<int,string>,workspace:string,role:string} $context */
    private function localHelp(array $context): string
    {
        return "You’re on the {$context['title']} page in the {$context['role']} workspace. I can help with ".implode(', ', $context['features']).'.';
    }

    /** @param array{title:string,features:array<int,string>,workspace:string,role:string} $context */
    private function generalPrompt(string $message, array $context): string
    {
        return "Workspace question: {$message}\n\nSanitized page context only:\nWorkspace: {$context['role']}\nPage: {$context['title']}\nAvailable features: ".implode(', ', $context['features'])."\n\nNo live operational data is included. Do not imply that you checked users, customers, listings, inventory, orders, parcels, riders, addresses, finance, earnings, reports, or account records.";
    }
}
