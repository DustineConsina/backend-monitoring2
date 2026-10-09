<?php

namespace App\Services;

use App\Models\SmsMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SemaphoreSmsService
{
    private const API_URL = 'https://api.semaphore.co/api/v4/messages';

    public function send(
        string $phoneNumber,
        string $message,
        ?int $sentByUserId = null,
        ?int $paymentId = null,
        ?int $contractId = null
    ): string
    {
        $phoneNumber = trim($phoneNumber);
        $apiKey = trim((string) config('services.semaphore.api_key'));

        if ($apiKey === '') {
            $this->recordAttempt($phoneNumber, $message, $sentByUserId, $paymentId, $contractId, null, 'failed', 'SEMAPHORE_API_KEY is not configured.');
            throw new RuntimeException('SEMAPHORE_API_KEY is not configured.');
        }
        if ($phoneNumber === '') {
            $this->recordAttempt($phoneNumber, $message, $sentByUserId, $paymentId, $contractId, null, 'failed', 'Recipient phone number is missing.');
            throw new RuntimeException('Recipient phone number is missing.');
        }

        $payload = [
            'apikey' => $apiKey,
            'number' => $phoneNumber,
            'message' => $message,
        ];

        $senderName = trim((string) config('services.semaphore.sender_name'));
        if ($senderName !== '') {
            $payload['sendername'] = $senderName;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post(self::API_URL, $payload);
        } catch (\Throwable $error) {
            $errorMessage = 'Semaphore request failed: ' . $error->getMessage();
            $this->recordAttempt($phoneNumber, $message, $sentByUserId, $paymentId, $contractId, null, 'failed', $errorMessage);
            throw new RuntimeException($errorMessage, 0, $error);
        }

        $responseData = $response->json();
        $messageRecord = $this->getMessageRecord($responseData);
        $providerError = $this->getProviderError($responseData, $messageRecord);

        if ($response->failed()) {
            $error = $providerError ?? 'Semaphore returned HTTP ' . $response->status() . '.';
            $this->recordAttempt($phoneNumber, $message, $sentByUserId, $paymentId, $contractId, null, 'failed', $error);
            throw new RuntimeException($error);
        }

        $messageId = is_array($messageRecord) ? ($messageRecord['message_id'] ?? null) : null;
        if ((!is_string($messageId) && !is_int($messageId)) || trim((string) $messageId) === '') {
            if ($providerError !== null) {
                $this->recordAttempt($phoneNumber, $message, $sentByUserId, $paymentId, $contractId, null, 'failed', $providerError);
                throw new RuntimeException('Semaphore rejected the request: ' . $providerError);
            }

            $responseKeys = is_array($messageRecord) ? implode(', ', array_keys($messageRecord)) : 'none';
            $error = 'Semaphore returned no message ID; delivery cannot be confirmed. Response fields: '
                . ($responseKeys !== '' ? $responseKeys : 'none')
                . '. Do not retry until the message status is checked in Semaphore.';
            $this->recordAttempt($phoneNumber, $message, $sentByUserId, $paymentId, $contractId, null, 'unknown', $error);
            throw new RuntimeException($error);
        }

        $status = (string) ($messageRecord['status'] ?? 'queued');
        $isFailed = in_array(strtolower($status), ['failed', 'rejected', 'refunded'], true);
        $this->recordAttempt(
            $phoneNumber,
            $message,
            $sentByUserId,
            $paymentId,
            $contractId,
            (string) $messageId,
            $status,
            $isFailed ? $this->getProviderError($messageRecord, $messageRecord) ?? "Semaphore reported message status: {$status}." : null
        );

        return (string) $messageId;
    }

    public function refreshStatus(SmsMessage $smsMessage): void
    {
        $apiKey = trim((string) config('services.semaphore.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('SEMAPHORE_API_KEY is not configured.');
        }
        if (!$smsMessage->provider_message_id) {
            throw new RuntimeException('SMS history record has no Semaphore message ID.');
        }

        $response = Http::timeout(15)->get(
            self::API_URL . '/' . rawurlencode($smsMessage->provider_message_id),
            ['apikey' => $apiKey]
        );
        $responseData = $response->json();
        $messageRecord = $this->getMessageRecord($responseData);
        $providerError = $this->getProviderError($responseData, $messageRecord);

        if ($response->failed() || !$messageRecord) {
            throw new RuntimeException(
                $providerError ?? 'Semaphore status lookup failed with HTTP ' . $response->status() . '.'
            );
        }

        $status = (string) ($messageRecord['status'] ?? '');
        if ($status === '') {
            throw new RuntimeException('Semaphore status response did not include a message status.');
        }

        $smsMessage->status = $status;
        $smsMessage->error_message = in_array(strtolower($status), ['failed', 'rejected', 'refunded'], true)
            ? $this->getProviderError($messageRecord, $messageRecord) ?? 'Semaphore reported that the message was not delivered.'
            : null;
        $smsMessage->save();
    }

    private function recordAttempt(
        string $phoneNumber,
        string $message,
        ?int $sentByUserId,
        ?int $paymentId,
        ?int $contractId,
        ?string $providerMessageId,
        string $status,
        ?string $errorMessage = null
    ): SmsMessage {
        $tenant = Schema::hasTable('tenants')
            ? Tenant::with('user')
                ->where('contact_number', $phoneNumber)
                ->orWhereHas('user', fn ($query) => $query->where('phone', $phoneNumber))
                ->first()
            : null;

        return SmsMessage::create([
            'sender_id' => $sentByUserId,
            'recipient_user_id' => $tenant?->user_id,
            'payment_id' => $paymentId,
            'contract_id' => $contractId,
            'phone_number' => $phoneNumber,
            'message' => $message,
            'provider' => 'semaphore',
            'provider_message_id' => $providerMessageId,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    private function getMessageRecord(mixed $responseData): ?array
    {
        if (!is_array($responseData)) {
            return null;
        }

        $record = array_is_list($responseData) ? ($responseData[0] ?? null) : $responseData;
        if (!is_array($record)) {
            return null;
        }

        if (isset($record['data']) && is_array($record['data'])) {
            $record = array_is_list($record['data']) ? ($record['data'][0] ?? null) : $record['data'];
        }

        return is_array($record) ? $record : null;
    }

    private function getProviderError(mixed $responseData, ?array $messageRecord): ?string
    {
        if (!is_array($responseData)) {
            return null;
        }

        $error = $responseData['error']
            ?? $messageRecord['error']
            ?? $messageRecord['error_message']
            ?? $messageRecord['reason']
            ?? $messageRecord['description']
            ?? $messageRecord['senderName']
            ?? null;
        if (is_string($error) && $error !== '') {
            return $error;
        }

        $validationErrors = [];
        if (!array_is_list($responseData)) {
            foreach ($responseData as $messages) {
                if (!is_array($messages)) {
                    continue;
                }

                foreach ($messages as $message) {
                    if (is_string($message) && $message !== '') {
                        $validationErrors[] = $message;
                    }
                }
            }
        }

        return $validationErrors === [] ? null : implode(' ', array_unique($validationErrors));
    }
}
