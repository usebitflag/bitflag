<?php

declare(strict_types=1);

namespace App\Http\Controllers\Testing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Lets the end-to-end specs read and clear the mail Mailpit caught, through the application, so they do not need to reach
 * Mailpit's own port. Each message comes back with its recipients, subject and plain text.
 */
final class MailpitController
{
    public function index(Request $request): JsonResponse
    {
        $to = strtolower($request->string('to')->toString());
        $base = config()->string('services.mailpit.url');
        $list = Http::acceptJson()->timeout(5)->get("{$base}/api/v1/messages", ['limit' => 50]);

        if ($list->failed()) {
            return response()->json(['error' => ['code' => 'MAILPIT_UNAVAILABLE', 'message' => 'Mailpit did not answer.']], 502);
        }

        $messages = [];
        $summaries = $list->json('messages');

        foreach (is_array($summaries) ? $summaries : [] as $summary) {
            if (! is_array($summary) || ! is_string($summary['ID'] ?? null)) {
                continue;
            }

            $recipients = [];

            foreach (is_array($summary['To'] ?? null) ? $summary['To'] : [] as $address) {
                if (is_array($address) && is_string($address['Address'] ?? null)) {
                    $recipients[] = strtolower($address['Address']);
                }
            }

            if ($to !== '' && ! in_array($to, $recipients, true)) {
                continue;
            }

            $detail = Http::acceptJson()->timeout(5)->get("{$base}/api/v1/message/{$summary['ID']}");

            $messages[] = [
                'to' => $recipients,
                'subject' => is_string($summary['Subject'] ?? null) ? $summary['Subject'] : '',
                'text' => is_string($detail->json('Text')) ? $detail->json('Text') : '',
                'created' => is_string($summary['Created'] ?? null) ? $summary['Created'] : '',
            ];
        }

        return response()->json(['data' => $messages]);
    }

    public function destroy(): JsonResponse
    {
        Http::timeout(5)->delete(config()->string('services.mailpit.url').'/api/v1/messages');

        return response()->json(['data' => ['cleared' => true]]);
    }
}
