<?php

namespace App\Services;

use App\Models\GeneratedFile;
use App\Models\ProcessingJob;
use App\Models\TelegramUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessingService
{
    /**
     * @param  array<string, mixed>  $update
     */
    public function handleWebhookUpdate(array $update): void
    {
        $message = $this->extractMessage($update);

        if ($message === null) {
            return;
        }

        $chatId = Arr::get($message, 'chat.id');

        if (! is_int($chatId) && ! is_string($chatId)) {
            return;
        }

        $text = trim((string) Arr::get($message, 'text', ''));

        if ($this->isCommand($text, '/start')) {
            $this->sendStartMessage($chatId);

            return;
        }

        if ($this->isCommand($text, '/help')) {
            $this->sendHelpMessage($chatId);

            return;
        }

        if (is_array(Arr::get($message, 'document')) || is_array(Arr::get($message, 'photo'))) {
            $this->handleDocumentOrPhoto($message, $chatId);

            return;
        }

        $this->telegramService->sendMessage($chatId, 'Please send a PDF, JPG, or PNG file so I can convert it into printable variants. Use /help for details.');
    }

    public function __construct(public TelegramService $telegramService) {}

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleDocumentOrPhoto(array $message, int|string $chatId): void
    {
        $job = null;

        try {
            $telegramUser = $this->upsertTelegramUser($message);
            $incomingFile = $this->extractIncomingFile($message);

            $job = ProcessingJob::create([
                'telegram_user_id' => $telegramUser->id,
                'chat_id' => (int) $chatId,
                'telegram_file_id' => $incomingFile['file_id'],
                'original_filename' => $incomingFile['original_filename'],
                'status' => ProcessingJob::STATUS_PENDING,
            ]);

            $job->update([
                'status' => ProcessingJob::STATUS_PROCESSING,
                'started_at' => now(),
            ]);

            $telegramFile = $this->telegramService->getFile($incomingFile['file_id']);
            $telegramFilePath = Arr::get($telegramFile, 'file_path');
            $remoteFileSize = Arr::get($telegramFile, 'file_size');

            if (! is_string($telegramFilePath) || $telegramFilePath === '') {
                throw new RuntimeException('telegram_get_file_failure');
            }

            $fileSize = $this->resolveFileSize($incomingFile['file_size'], $remoteFileSize);

            if ($fileSize <= 0) {
                throw new RuntimeException('empty_file');
            }

            if ($fileSize > $this->maxFileSize()) {
                throw new RuntimeException('file_too_large');
            }

            $binary = $this->telegramService->downloadFile($telegramFilePath);

            if ($binary === '') {
                throw new RuntimeException('empty_file');
            }

            $mimeType = $this->resolveMimeType($binary, $incomingFile['declared_mime_type']);

            if (! $this->isSupportedMimeType($mimeType)) {
                throw new RuntimeException('unsupported_file_type');
            }

            if (! $this->isBinaryContentValid($binary, $mimeType)) {
                throw new RuntimeException('invalid_or_corrupted_file');
            }

            $extension = $this->extensionForMimeType($mimeType);
            $sanitizedFileName = $this->sanitizeFileName($incomingFile['original_filename'], $extension);

            $jobDirectory = "telegram/jobs/{$job->id}";
            $inputDirectory = "{$jobDirectory}/input";
            $outputDirectory = "{$jobDirectory}/output";
            $inputRelativePath = "{$inputDirectory}/{$sanitizedFileName}";

            $disk = Storage::disk('local');
            $disk->makeDirectory($inputDirectory);
            $disk->makeDirectory($outputDirectory);
            $disk->put($inputRelativePath, $binary);

            if (! $disk->exists($inputRelativePath) || $disk->size($inputRelativePath) <= 0) {
                throw new RuntimeException('empty_file');
            }

            $job->update([
                'input_file_type' => $mimeType,
                'input_file_path' => $inputRelativePath,
            ]);

            $inputAbsolutePath = storage_path("app/private/{$inputRelativePath}");
            $outputAbsolutePath = storage_path("app/private/{$outputDirectory}");

            $this->runPythonProcessor($inputAbsolutePath, $outputAbsolutePath);

            $generatedFiles = $this->persistGeneratedFiles($job, $outputDirectory);

            $this->telegramService->sendMessage(
                $chatId,
                'Processing is complete. This tool only formats your file for printing and does not verify authenticity, issue IDs, or intentionally alter identity data.'
            );

            $this->sendGeneratedFilesToTelegram($chatId, $generatedFiles);

            $job->update([
                'status' => ProcessingJob::STATUS_COMPLETED,
                'completed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $throwable) {
            $this->handleFailure($throwable, $job, $chatId);
        }
    }

    private function sendStartMessage(int|string $chatId): void
    {
        $this->telegramService->sendMessage(
            $chatId,
            "Welcome. Send a National ID or Fayda file as PDF, JPG, or PNG and I will generate printable variants.\n\nThis bot is a formatting/conversion tool only. It does not verify authenticity, does not issue IDs, and does not intentionally alter identity data."
        );
    }

    private function sendHelpMessage(int|string $chatId): void
    {
        $maxSizeMb = number_format($this->maxFileSize() / 1024 / 1024, 1);

        $this->telegramService->sendMessage(
            $chatId,
            "Supported uploads:\n- PDF\n- PNG\n- JPG/JPEG\n\nMaximum file size: {$maxSizeMb} MB\n\nOutput files:\n- normal.png\n- mirror.png\n- a4_color.pdf\n- a4_gray.pdf\n\nThis bot does not verify authenticity or issue IDs."
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function upsertTelegramUser(array $message): TelegramUser
    {
        $telegramId = Arr::get($message, 'from.id');

        if (! is_int($telegramId) && ! is_string($telegramId)) {
            throw new RuntimeException('missing_sender');
        }

        return TelegramUser::updateOrCreate(
            [
                'telegram_id' => (int) $telegramId,
            ],
            [
                'username' => $this->nullableString(Arr::get($message, 'from.username')),
                'first_name' => $this->nullableString(Arr::get($message, 'from.first_name')),
                'last_name' => $this->nullableString(Arr::get($message, 'from.last_name')),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{file_id: string, original_filename: string, file_size: int, declared_mime_type: string|null}
     */
    private function extractIncomingFile(array $message): array
    {
        $document = Arr::get($message, 'document');

        if (is_array($document)) {
            $fileId = Arr::get($document, 'file_id');

            if (! is_string($fileId) || $fileId === '') {
                throw new RuntimeException('missing_file_id');
            }

            return [
                'file_id' => $fileId,
                'original_filename' => $this->nullableString(Arr::get($document, 'file_name')) ?? 'document',
                'file_size' => (int) Arr::get($document, 'file_size', 0),
                'declared_mime_type' => $this->nullableString(Arr::get($document, 'mime_type')),
            ];
        }

        $photos = Arr::get($message, 'photo');

        if (! is_array($photos) || $photos === []) {
            throw new RuntimeException('missing_file_id');
        }

        $photo = collect($photos)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->sortBy(fn (array $item): int => (int) Arr::get($item, 'file_size', 0))
            ->last();

        if (! is_array($photo)) {
            throw new RuntimeException('missing_file_id');
        }

        $fileId = Arr::get($photo, 'file_id');

        if (! is_string($fileId) || $fileId === '') {
            throw new RuntimeException('missing_file_id');
        }

        return [
            'file_id' => $fileId,
            'original_filename' => 'photo.jpg',
            'file_size' => (int) Arr::get($photo, 'file_size', 0),
            'declared_mime_type' => 'image/jpeg',
        ];
    }

    private function maxFileSize(): int
    {
        return (int) config('telegram.max_file_size', 5 * 1024 * 1024);
    }

    private function resolveFileSize(int $messageFileSize, mixed $remoteFileSize): int
    {
        if ($messageFileSize > 0) {
            return $messageFileSize;
        }

        if (is_int($remoteFileSize)) {
            return $remoteFileSize;
        }

        if (is_string($remoteFileSize) && is_numeric($remoteFileSize)) {
            return (int) $remoteFileSize;
        }

        return 0;
    }

    private function resolveMimeType(string $binary, ?string $declaredMimeType): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_buffer($finfo, $binary) : false;

        if ($finfo) {
            finfo_close($finfo);
        }

        $mimeType = is_string($detected) && $detected !== ''
            ? $detected
            : (string) $declaredMimeType;

        return $this->normalizeMimeType($mimeType);
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $normalized = strtolower(trim($mimeType));

        return match ($normalized) {
            'image/pjpeg', 'image/jpg' => 'image/jpeg',
            default => $normalized,
        };
    }

    private function isSupportedMimeType(string $mimeType): bool
    {
        $allowed = config('telegram.allowed_mime_types', []);

        if (! is_array($allowed)) {
            return false;
        }

        return in_array($mimeType, array_map(fn (mixed $item): string => $this->normalizeMimeType((string) $item), $allowed), true);
    }

    private function isBinaryContentValid(string $binary, string $mimeType): bool
    {
        if ($mimeType === 'application/pdf') {
            return str_starts_with($binary, '%PDF');
        }

        if ($mimeType === 'image/png' || $mimeType === 'image/jpeg') {
            return getimagesizefromstring($binary) !== false;
        }

        return false;
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            default => 'jpg',
        };
    }

    private function sanitizeFileName(string $fileName, string $defaultExtension): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

        $safeBaseName = preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName);
        $safeBaseName = trim((string) $safeBaseName, '_');

        if ($safeBaseName === '') {
            $safeBaseName = 'input_file';
        }

        $safeBaseName = Str::limit($safeBaseName, 80, '');

        if (! preg_match('/^[a-z0-9]+$/', $extension)) {
            $extension = $defaultExtension;
        }

        if ($extension === '') {
            $extension = $defaultExtension;
        }

        return sprintf('%s.%s', $safeBaseName, $extension);
    }

    private function runPythonProcessor(string $inputAbsolutePath, string $outputAbsolutePath): void
    {
        $pythonBin = (string) config('telegram.python_bin', 'python3');
        $processorPath = (string) config('telegram.python_processor_path', base_path('processor/main.py'));

        if (! str_starts_with($processorPath, DIRECTORY_SEPARATOR)) {
            $processorPath = base_path($processorPath);
        }

        if (! is_file($processorPath)) {
            throw new RuntimeException('python_script_missing');
        }

        $timeout = (int) config('telegram.process_timeout', 120);

        $result = Process::timeout($timeout)->run([
            $pythonBin,
            $processorPath,
            '--input='.$inputAbsolutePath,
            '--output='.$outputAbsolutePath,
        ]);

        if ($result->failed()) {
            $errorOutput = trim($result->errorOutput());
            $output = trim($result->output());

            Log::error('telegram.python_execution_failure', [
                'error_output' => $errorOutput,
                'output' => $output,
            ]);

            throw new RuntimeException('python_execution_failure');
        }
    }

    /**
     * @return array<int, GeneratedFile>
     */
    private function persistGeneratedFiles(ProcessingJob $job, string $outputDirectory): array
    {
        $expectedOutputFiles = config('telegram.expected_output_files', []);

        if (! is_array($expectedOutputFiles) || $expectedOutputFiles === []) {
            throw new RuntimeException('missing_expected_output_configuration');
        }

        $disk = Storage::disk('local');
        $generatedFiles = [];

        foreach ($expectedOutputFiles as $fileName => $mimeType) {
            $relativePath = "{$outputDirectory}/{$fileName}";

            if (! $disk->exists($relativePath)) {
                throw new RuntimeException('expected_output_missing');
            }

            $variant = pathinfo((string) $fileName, PATHINFO_FILENAME);

            $generatedFiles[] = $job->generatedFiles()->create([
                'variant' => $variant,
                'file_name' => (string) $fileName,
                'file_path' => $relativePath,
                'mime_type' => (string) $mimeType,
            ]);
        }

        return $generatedFiles;
    }

    /**
     * @param  array<int, GeneratedFile>  $generatedFiles
     */
    private function sendGeneratedFilesToTelegram(int|string $chatId, array $generatedFiles): void
    {
        foreach ($generatedFiles as $generatedFile) {
            $absolutePath = storage_path('app/private/'.$generatedFile->file_path);

            if ($generatedFile->mime_type === 'image/png') {
                $this->telegramService->sendPhoto($chatId, $absolutePath, $generatedFile->file_name);

                continue;
            }

            $this->telegramService->sendDocument($chatId, $absolutePath, $generatedFile->file_name);
        }
    }

    private function handleFailure(Throwable $throwable, ?ProcessingJob $job, int|string $chatId): void
    {
        $errorCategory = $throwable->getMessage();

        Log::warning('telegram.processing_failed', [
            'job_id' => $job?->id,
            'category' => $errorCategory,
        ]);

        if ($job !== null) {
            $job->update([
                'status' => ProcessingJob::STATUS_FAILED,
                'error_message' => Str::limit($errorCategory, 500),
                'completed_at' => now(),
            ]);
        }

        try {
            $this->telegramService->sendMessage($chatId, $this->userFacingErrorMessage($errorCategory));
        } catch (Throwable $telegramThrowable) {
            Log::error('telegram.user_notification_failed', [
                'job_id' => $job?->id,
                'category' => $errorCategory,
            ]);
        }
    }

    private function userFacingErrorMessage(string $category): string
    {
        return match ($category) {
            'missing_file_id' => 'I could not read the file from your message. Please send the document again.',
            'telegram_get_file_failure' => 'I could not fetch file details from Telegram. Please try again shortly.',
            'telegram_download_failure' => 'I could not download your file from Telegram. Please try again.',
            'unsupported_file_type' => 'Unsupported file type. Please upload a PDF, PNG, or JPG/JPEG file.',
            'empty_file' => 'The uploaded file appears to be empty. Please send a valid file.',
            'file_too_large' => 'The file is too large. Please upload a file within the configured limit.',
            'invalid_or_corrupted_file' => 'The file looks invalid or corrupted. Please upload a valid PDF or image.',
            'python_script_missing' => 'The processor is not available on the server. Please contact support.',
            'python_execution_failure' => 'I could not process your file right now. Please try again later.',
            'expected_output_missing' => 'Processing finished but expected output files were missing. Please try again later.',
            'telegram_send_document_failure', 'telegram_send_photo_failure' => 'Your file was processed, but I could not send all outputs. Please try again.',
            default => 'I could not process your file right now. Please try again later.',
        };
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    private function extractMessage(array $update): ?array
    {
        $message = Arr::get($update, 'message') ?? Arr::get($update, 'edited_message');

        return is_array($message) ? $message : null;
    }

    private function isCommand(string $text, string $command): bool
    {
        return $text !== '' && str_starts_with($text, $command);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
