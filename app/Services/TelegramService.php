<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use RuntimeException;

class TelegramService
{
    public function isValidWebhookSecret(?string $providedSecret): bool
    {
        $expectedSecret = (string) config('services.telegram.webhook_secret');

        if ($expectedSecret === '') {
            Log::error('telegram.webhook_secret_missing');

            return false;
        }

        if (! is_string($providedSecret) || $providedSecret === '') {
            return false;
        }

        return hash_equals($expectedSecret, $providedSecret);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFile(string $fileId): array
    {
        $result = $this->request('getFile', [
            'file_id' => $fileId,
        ], 'telegram_get_file_failure');

        if (! is_array($result)) {
            throw new RuntimeException('telegram_get_file_failure');
        }

        return $result;
    }

    public function downloadFile(string $filePath): string
    {
        $response = Http::timeout(60)->get($this->buildFileUrl($filePath));

        if (! $response->successful()) {
            throw new RuntimeException('telegram_download_failure');
        }

        return $response->body();
    }

    public function sendMessage(int|string $chatId, string $text): void
    {
        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ], 'telegram_send_message_failure');
    }

    public function sendPhoto(int|string $chatId, string $absolutePath, string $caption = ''): void
    {
        if (! File::exists($absolutePath)) {
            throw new RuntimeException('telegram_photo_missing');
        }

        $response = Http::timeout(60)
            ->attach('photo', File::get($absolutePath), basename($absolutePath))
            ->post($this->buildApiUrl('sendPhoto'), [
                'chat_id' => $chatId,
                'caption' => $caption,
            ]);

        $this->ensureSuccessful($response, 'telegram_send_photo_failure');
    }

    public function sendDocument(int|string $chatId, string $absolutePath, string $caption = ''): void
    {
        if (! File::exists($absolutePath)) {
            throw new RuntimeException('telegram_document_missing');
        }

        $response = Http::timeout(60)
            ->attach('document', File::get($absolutePath), basename($absolutePath))
            ->post($this->buildApiUrl('sendDocument'), [
                'chat_id' => $chatId,
                'caption' => $caption,
            ]);

        $this->ensureSuccessful($response, 'telegram_send_document_failure');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return mixed
     */
    private function request(string $method, array $payload = [], string $error = 'telegram_api_failure'): mixed
    {
        $response = Http::asForm()
            ->timeout(30)
            ->acceptJson()
            ->post($this->buildApiUrl($method), $payload);

        $this->ensureSuccessful($response, $error);

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw new RuntimeException($error);
        }

        return $body['result'] ?? null;
    }

    private function ensureSuccessful(Response $response, string $error): void
    {
        if (! $response->successful()) {
            throw new RuntimeException($error);
        }

        $body = $response->json();

        if (is_array($body) && array_key_exists('ok', $body) && $body['ok'] !== true) {
            throw new RuntimeException($error);
        }
    }

    private function buildApiUrl(string $method): string
    {
        $base = rtrim((string) config('services.telegram.bot_api'), '/');
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            throw new RuntimeException('telegram_token_missing');
        }

        return sprintf('%s/bot%s/%s', $base, $token, $method);
    }

    private function buildFileUrl(string $filePath): string
    {
        $base = rtrim((string) config('services.telegram.bot_api'), '/');
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            throw new RuntimeException('telegram_token_missing');
        }

        return sprintf('%s/file/bot%s/%s', $base, $token, ltrim($filePath, '/'));
    }
}
