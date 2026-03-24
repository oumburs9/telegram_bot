<?php

use App\Models\ProcessingJob;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.bot_api' => 'https://api.telegram.org',
        'services.telegram.webhook_secret' => 'test-secret',
        'telegram.max_file_size' => 5 * 1024 * 1024,
        'telegram.python_bin' => PHP_BINARY,
        'telegram.python_processor_path' => base_path('tests/Fixtures/fake_processor.php'),
        'telegram.process_timeout' => 30,
        'telegram.expected_output_files' => [
            'normal.png' => 'image/png',
            'mirror.png' => 'image/png',
            'a4_color.pdf' => 'application/pdf',
            'a4_gray.pdf' => 'application/pdf',
        ],
    ]);

    Storage::disk('local')->deleteDirectory('telegram/jobs');
});

it('rejects webhook request with invalid secret header', function (): void {
    $response = $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 111],
            'text' => '/start',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
    ]);

    $response->assertForbidden();
});

it('handles start command', function (): void {
    Http::fake([
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $response = $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 111],
            'from' => ['id' => 777],
            'text' => '/start',
        ],
    ], telegramWebhookHeaders());

    $response->assertSuccessful()->assertJson(['ok' => true]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
    expect(ProcessingJob::query()->count())->toBe(0);
});

it('handles help command', function (): void {
    Http::fake([
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $response = $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 111],
            'from' => ['id' => 777],
            'text' => '/help',
        ],
    ], telegramWebhookHeaders());

    $response->assertSuccessful()->assertJson(['ok' => true]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && str_contains((string) $request['text'], 'Supported uploads'));
});

it('returns friendly message for unsupported message types', function (): void {
    Http::fake([
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $response = $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 111],
            'from' => ['id' => 777],
            'text' => 'hello there',
        ],
    ], telegramWebhookHeaders());

    $response->assertSuccessful()->assertJson(['ok' => true]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && str_contains((string) $request['text'], 'Please send a PDF'));
});

it('processes document upload and stores records', function (): void {
    $pdfBinary = validPdfBinary();

    Http::fake([
        'https://api.telegram.org/bottest-token/getFile' => Http::response([
            'ok' => true,
            'result' => [
                'file_path' => 'docs/input.pdf',
                'file_size' => strlen($pdfBinary),
            ],
        ], 200),
        'https://api.telegram.org/file/bottest-token/*' => Http::response($pdfBinary, 200),
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
        'https://api.telegram.org/bottest-token/sendPhoto' => Http::response(['ok' => true, 'result' => true], 200),
        'https://api.telegram.org/bottest-token/sendDocument' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $response = $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 999],
            'from' => [
                'id' => 12345,
                'username' => 'demo_user',
                'first_name' => 'Demo',
                'last_name' => 'User',
            ],
            'document' => [
                'file_id' => 'document-file-id',
                'file_name' => 'national-id.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => strlen($pdfBinary),
            ],
        ],
    ], telegramWebhookHeaders());

    $response->assertSuccessful()->assertJson(['ok' => true]);

    $job = ProcessingJob::query()->first();

    expect($job)->not->toBeNull();
    expect($job?->status)->toBe(ProcessingJob::STATUS_COMPLETED);

    $this->assertDatabaseHas('telegram_users', [
        'telegram_id' => 12345,
        'username' => 'demo_user',
    ]);

    $this->assertDatabaseCount('generated_files', 4);

    expect(Storage::disk('local')->exists("telegram/jobs/{$job->id}/output/normal.png"))->toBeTrue();
    expect(Storage::disk('local')->exists("telegram/jobs/{$job->id}/output/mirror.png"))->toBeTrue();
    expect(Storage::disk('local')->exists("telegram/jobs/{$job->id}/output/a4_color.pdf"))->toBeTrue();
    expect(Storage::disk('local')->exists("telegram/jobs/{$job->id}/output/a4_gray.pdf"))->toBeTrue();

    $requests = Http::recorded();

    $photoCalls = collect($requests)->filter(
        fn (array $entry): bool => str_ends_with($entry[0]->url(), '/sendPhoto')
    )->count();
    $documentCalls = collect($requests)->filter(
        fn (array $entry): bool => str_ends_with($entry[0]->url(), '/sendDocument')
    )->count();

    expect($photoCalls)->toBe(2);
    expect($documentCalls)->toBe(2);
});

it('processes photo upload and marks job completed', function (): void {
    $imageBinary = validPngBinary();

    Http::fake([
        'https://api.telegram.org/bottest-token/getFile' => Http::response([
            'ok' => true,
            'result' => [
                'file_path' => 'photos/input.jpg',
                'file_size' => strlen($imageBinary),
            ],
        ], 200),
        'https://api.telegram.org/file/bottest-token/*' => Http::response($imageBinary, 200),
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
        'https://api.telegram.org/bottest-token/sendPhoto' => Http::response(['ok' => true, 'result' => true], 200),
        'https://api.telegram.org/bottest-token/sendDocument' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $response = $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 888],
            'from' => ['id' => 67890, 'first_name' => 'Photo'],
            'photo' => [
                ['file_id' => 'small-photo', 'file_size' => 10],
                ['file_id' => 'largest-photo', 'file_size' => strlen($imageBinary)],
            ],
        ],
    ], telegramWebhookHeaders());

    $response->assertSuccessful();

    $job = ProcessingJob::query()->first();

    expect($job)->not->toBeNull();
    expect($job?->status)->toBe(ProcessingJob::STATUS_COMPLETED);
});

it('fails when uploaded file type is unsupported', function (): void {
    Http::fake([
        'https://api.telegram.org/bottest-token/getFile' => Http::response([
            'ok' => true,
            'result' => [
                'file_path' => 'docs/input.txt',
                'file_size' => 12,
            ],
        ], 200),
        'https://api.telegram.org/file/bottest-token/*' => Http::response('plain text', 200),
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 333],
            'from' => ['id' => 444],
            'document' => [
                'file_id' => 'text-file',
                'file_name' => 'notes.txt',
                'mime_type' => 'text/plain',
                'file_size' => 12,
            ],
        ],
    ], telegramWebhookHeaders())->assertSuccessful();

    $job = ProcessingJob::query()->first();

    expect($job)->not->toBeNull();
    expect($job?->status)->toBe(ProcessingJob::STATUS_FAILED);
    expect($job?->error_message)->toBe('unsupported_file_type');
});

it('fails when file is larger than configured limit', function (): void {
    config([
        'telegram.max_file_size' => 8,
    ]);

    Http::fake([
        'https://api.telegram.org/bottest-token/getFile' => Http::response([
            'ok' => true,
            'result' => [
                'file_path' => 'docs/input.pdf',
                'file_size' => 20,
            ],
        ], 200),
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 555],
            'from' => ['id' => 666],
            'document' => [
                'file_id' => 'large-file',
                'file_name' => 'large.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 20,
            ],
        ],
    ], telegramWebhookHeaders())->assertSuccessful();

    $job = ProcessingJob::query()->first();

    expect($job)->not->toBeNull();
    expect($job?->status)->toBe(ProcessingJob::STATUS_FAILED);
    expect($job?->error_message)->toBe('file_too_large');
});

it('fails when file id is missing', function (): void {
    Http::fake([
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 777],
            'from' => ['id' => 888],
            'document' => [
                'file_name' => 'missing-id.pdf',
            ],
        ],
    ], telegramWebhookHeaders())->assertSuccessful();

    expect(TelegramUser::query()->count())->toBe(1);
    expect(ProcessingJob::query()->count())->toBe(0);
});

it('fails when telegram getFile call fails', function (): void {
    Http::fake([
        'https://api.telegram.org/bottest-token/getFile' => Http::response([
            'ok' => false,
            'description' => 'bad request',
        ], 200),
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 123],
            'from' => ['id' => 456],
            'document' => [
                'file_id' => 'id-file',
                'file_name' => 'id.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 100,
            ],
        ],
    ], telegramWebhookHeaders())->assertSuccessful();

    $job = ProcessingJob::query()->first();

    expect($job)->not->toBeNull();
    expect($job?->status)->toBe(ProcessingJob::STATUS_FAILED);
    expect($job?->error_message)->toBe('telegram_get_file_failure');
});

it('fails when python execution fails', function (): void {
    config([
        'telegram.python_processor_path' => base_path('tests/Fixtures/failing_processor.php'),
    ]);

    $pdfBinary = validPdfBinary();

    Http::fake([
        'https://api.telegram.org/bottest-token/getFile' => Http::response([
            'ok' => true,
            'result' => [
                'file_path' => 'docs/input.pdf',
                'file_size' => strlen($pdfBinary),
            ],
        ], 200),
        'https://api.telegram.org/file/bottest-token/*' => Http::response($pdfBinary, 200),
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 214],
            'from' => ['id' => 215],
            'document' => [
                'file_id' => 'doc-id',
                'file_name' => 'source.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => strlen($pdfBinary),
            ],
        ],
    ], telegramWebhookHeaders())->assertSuccessful();

    $job = ProcessingJob::query()->first();

    expect($job)->not->toBeNull();
    expect($job?->status)->toBe(ProcessingJob::STATUS_FAILED);
    expect($job?->error_message)->toBe('python_execution_failure');
});

it('fails when expected output file is missing', function (): void {
    config([
        'telegram.expected_output_files' => [
            'normal.png' => 'image/png',
            'mirror.png' => 'image/png',
            'a4_color.pdf' => 'application/pdf',
            'a4_gray.pdf' => 'application/pdf',
            'missing.pdf' => 'application/pdf',
        ],
    ]);

    $pdfBinary = validPdfBinary();

    Http::fake([
        'https://api.telegram.org/bottest-token/getFile' => Http::response([
            'ok' => true,
            'result' => [
                'file_path' => 'docs/input.pdf',
                'file_size' => strlen($pdfBinary),
            ],
        ], 200),
        'https://api.telegram.org/file/bottest-token/*' => Http::response($pdfBinary, 200),
        'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $this->postJson('/telegram/webhook', [
        'message' => [
            'chat' => ['id' => 321],
            'from' => ['id' => 654],
            'document' => [
                'file_id' => 'doc-id-2',
                'file_name' => 'source.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => strlen($pdfBinary),
            ],
        ],
    ], telegramWebhookHeaders())->assertSuccessful();

    $job = ProcessingJob::query()->first();

    expect($job)->not->toBeNull();
    expect($job?->status)->toBe(ProcessingJob::STATUS_FAILED);
    expect($job?->error_message)->toBe('expected_output_missing');
});

function telegramWebhookHeaders(): array
{
    return [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ];
}

function validPdfBinary(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function validPngBinary(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgA9W9RMAAAAASUVORK5CYII=') ?: '';
}
