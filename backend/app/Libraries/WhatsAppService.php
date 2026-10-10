<?php

namespace App\Libraries;

class WhatsAppService
{
    protected bool $enabled;
    protected string $phoneNumberId;
    protected string $accessToken;
    protected string $apiVersion;
    protected string $taskTemplateName;
    protected string $taskTemplateLang;

    public function __construct()
    {
        $this->enabled          = filter_var(env('WHATSAPP_ENABLED'), FILTER_VALIDATE_BOOLEAN);
        $this->phoneNumberId    = trim((string) env('WHATSAPP_PHONE_NUMBER_ID'));
        $this->accessToken      = trim((string) env('WHATSAPP_ACCESS_TOKEN'));
        $this->apiVersion       = trim((string) (env('WHATSAPP_API_VERSION') ?: 'v25.0'));
        $this->taskTemplateName = trim((string) (env('WHATSAPP_TASK_TEMPLATE_NAME') ?: 'project_task_update'));
        $this->taskTemplateLang = trim((string) (env('WHATSAPP_TASK_TEMPLATE_LANG') ?: 'en'));

        // Safe debug logs - DO NOT log token value
        log_message('error', 'WA ENABLED = ' . ($this->enabled ? 'true' : 'false'));
        log_message('error', 'WA PHONE ID = ' . $this->phoneNumberId);
        log_message('error', 'WA TOKEN LOADED = ' . ($this->accessToken !== '' ? 'YES' : 'NO'));
        log_message('error', 'WA API VERSION = ' . $this->apiVersion);
        log_message('error', 'WA TEMPLATE = ' . $this->taskTemplateName);
        log_message('error', 'WA TEMPLATE LANG = ' . $this->taskTemplateLang);
    }

    public function sendTaskUpdateTemplate(
        string $to,
        string $clientName,
        string $projectName,
        string $taskTitle,
        string $taskStatus
    ): array {
        if (!$this->enabled) {
            return [
                'success'   => false,
                'message'   => 'WhatsApp disabled',
                'http_code' => null,
                'response'  => null,
            ];
        }

        $to = $this->cleanPhone($to);

        if ($to === '') {
            return [
                'success'   => false,
                'message'   => 'Invalid phone number',
                'http_code' => null,
                'response'  => null,
            ];
        }

        if ($this->phoneNumberId === '' || $this->accessToken === '') {
            return [
                'success'   => false,
                'message'   => 'WhatsApp credentials missing',
                'http_code' => null,
                'response'  => null,
            ];
        }

        $clientName  = $this->safeText($clientName ?: 'Customer');
        $projectName = $this->safeText($projectName ?: 'Project');
        $taskTitle   = $this->safeText($taskTitle ?: 'Task');
        $taskStatus  = $this->safeText($taskStatus ?: 'Updated');

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'     => $this->taskTemplateName,
                'language' => [
                    'code' => $this->taskTemplateLang,
                ],
                'components' => [
                    [
                        'type'       => 'body',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $clientName,
                            ],
                            [
                                'type' => 'text',
                                'text' => $projectName,
                            ],
                            [
                                'type' => 'text',
                                'text' => $taskTitle,
                            ],
                            [
                                'type' => 'text',
                                'text' => $taskStatus,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        log_message('error', 'WA SEND TO = ' . $to);
        log_message('error', 'WA SEND TEMPLATE = ' . $this->taskTemplateName);
        log_message('error', 'WA SEND LANG = ' . $this->taskTemplateLang);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $curlErrNo   = curl_errno($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($rawResponse === false) {
            log_message('error', 'WA CURL ERROR = ' . $curlError);

            return [
                'success'    => false,
                'message'    => $curlError ?: 'Curl error',
                'http_code'  => $httpCode,
                'curl_errno' => $curlErrNo,
                'response'   => null,
            ];
        }

        $decoded = json_decode($rawResponse, true);

        log_message('error', 'WA HTTP CODE = ' . $httpCode);
        log_message('error', 'WA RAW RESPONSE = ' . $rawResponse);

        $success = ($httpCode >= 200 && $httpCode < 300);

        return [
            'success'   => $success,
            'message'   => $success ? 'WhatsApp template accepted by Meta' : 'WhatsApp API error',
            'http_code' => $httpCode,
            'response'  => $decoded ?: $rawResponse,
        ];
    }

    public function sendTextMessage(string $to, string $message): array
    {
        if (!$this->enabled) {
            return [
                'success'   => false,
                'message'   => 'WhatsApp disabled',
                'http_code' => null,
                'response'  => null,
            ];
        }

        $to = $this->cleanPhone($to);

        if ($to === '') {
            return [
                'success'   => false,
                'message'   => 'Invalid phone number',
                'http_code' => null,
                'response'  => null,
            ];
        }

        if ($this->phoneNumberId === '' || $this->accessToken === '') {
            return [
                'success'   => false,
                'message'   => 'WhatsApp credentials missing',
                'http_code' => null,
                'response'  => null,
            ];
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $message,
            ],
        ];

        log_message('error', 'WA TEXT SEND TO = ' . $to);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $curlErrNo   = curl_errno($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($rawResponse === false) {
            log_message('error', 'WA TEXT CURL ERROR = ' . $curlError);

            return [
                'success'    => false,
                'message'    => $curlError ?: 'Curl error',
                'http_code'  => $httpCode,
                'curl_errno' => $curlErrNo,
                'response'   => null,
            ];
        }

        $decoded = json_decode($rawResponse, true);

        log_message('error', 'WA TEXT HTTP CODE = ' . $httpCode);
        log_message('error', 'WA TEXT RAW RESPONSE = ' . $rawResponse);

        $success = ($httpCode >= 200 && $httpCode < 300);

        return [
            'success'   => $success,
            'message'   => $success ? 'WhatsApp message accepted by Meta' : 'WhatsApp API error',
            'http_code' => $httpCode,
            'response'  => $decoded ?: $rawResponse,
        ];
    }

    public function sendTaskCompletionMessage(
        string $to,
        string $clientName,
        string $projectName,
        string $taskTitle,
        ?string $customMessage = null
    ): array {
        if (!empty($customMessage)) {
            $formattedMessage = str_replace(
                [
                    '{client_name}', '{customer_name}', '{client}', '{customer}',
                    '{project_name}', '{project_title}', '{project}',
                    '{task_name}', '{task_title}', '{task}',
                    '{task_status}', '{status}'
                ],
                [
                    $clientName, $clientName, $clientName, $clientName,
                    $projectName, $projectName, $projectName,
                    $taskTitle, $taskTitle, $taskTitle,
                    'Completed', 'Completed'
                ],
                $customMessage
            );

            $res = $this->sendTextMessage($to, $formattedMessage);
            if ($res['success']) {
                $res['sent_message'] = $formattedMessage;
                return $res;
            }
        }

        // Fallback to approved template
        $res = $this->sendTaskUpdateTemplate($to, $clientName, $projectName, $taskTitle, 'Completed');
        $res['sent_message'] = "Hello {$clientName}, Your project {$projectName} task {$taskTitle} has been updated to Completed.";
        return $res;
    }

    private function cleanPhone(string $phone): string
    {
        $phone = trim($phone);

        // Remove +, space, dash, brackets, etc.
        $phone = preg_replace('/\D+/', '', $phone);

        if (!$phone) {
            return '';
        }

        // Example: 8056773503 -> 918056773503
        if (strlen($phone) === 10) {
            $phone = '91' . $phone;
        }

        // Example: 08056773503 -> 918056773503
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '0') {
            $phone = '91' . substr($phone, 1);
        }

        // WhatsApp international format length validation
        if (strlen($phone) < 11 || strlen($phone) > 15) {
            return '';
        }

        return $phone;
    }

    private function safeText(string $text): string
    {
        $text = trim($text);

        // Remove extra spaces/new lines
        $text = preg_replace('/\s+/', ' ', $text);

        // WhatsApp template text parameter safe limit
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 900);
        }

        return substr($text, 0, 900);
    }
}