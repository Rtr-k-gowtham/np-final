<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\WhatsappMessageModel;

class WhatsappWebhookController extends Controller
{
    /**
     * Handle Meta Webhook Endpoint (GET Verification & POST Event Ingestion)
     */
    public function index()
    {
        $request = service('request');
        $method  = strtolower($request->getMethod());

        // 1. Webhook Verification (GET Request)
        if ($method === 'get') {
            $verifyToken = env('WHATSAPP_VERIFY_TOKEN') ?: 'my_secure_whatsapp_token_123';
            
            $hubMode        = $request->getGet('hub_mode');
            $hubVerifyToken = $request->getGet('hub_verify_token');
            $hubChallenge   = $request->getGet('hub_challenge');

            if ($hubMode === 'subscribe' && $hubVerifyToken === $verifyToken) {
                log_message('info', 'WhatsApp webhook verified successfully.');
                return $this->response->setStatusCode(200)
                                      ->setContentType('text/plain')
                                      ->setBody($hubChallenge);
            }

            log_message('error', 'WhatsApp webhook verification failed. Token mismatch.');
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }

        // 2. Incoming Event Ingestion (POST Request)
        if ($method === 'post') {
            $rawPayload = $request->getBody();
            
            // Log payload for debugging (maps to App\Libraries\WhatsAppService structure)
            log_message('info', "Received WhatsApp Webhook Payload:\n" . $rawPayload);

            $payload = json_decode($rawPayload, true);
            if (!$payload) {
                log_message('error', 'Failed to decode WhatsApp JSON payload.');
                return $this->response->setStatusCode(400)->setBody('Invalid JSON');
            }

            try {
                $db = \Config\Database::connect();
                
                if (isset($payload['entry']) && is_array($payload['entry'])) {
                    foreach ($payload['entry'] as $entry) {
                        if (isset($entry['changes']) && is_array($entry['changes'])) {
                            foreach ($entry['changes'] as $change) {
                                $value = $change['value'] ?? null;
                                
                                if ($value && isset($value['messages']) && is_array($value['messages'])) {
                                    // Parse contact profile names
                                    $contactNames = [];
                                    if (isset($value['contacts']) && is_array($value['contacts'])) {
                                        foreach ($value['contacts'] as $contact) {
                                            $waId = $contact['wa_id'] ?? null;
                                            $name = $contact['profile']['name'] ?? null;
                                            if ($waId && $name) {
                                                $contactNames[$waId] = $name;
                                            }
                                        }
                                    }

                                    // Store messages in DB
                                    foreach ($value['messages'] as $msg) {
                                        $messageId   = $msg['id'] ?? null;
                                        $senderPhone = $msg['from'] ?? null;
                                        $msgType     = $msg['type'] ?? 'unknown';
                                        $timestamp   = $msg['timestamp'] ?? time();

                                        if (!$messageId || !$senderPhone) {
                                            continue; // Skip invalid entries
                                        }

                                        $senderName = $contactNames[$senderPhone] ?? null;
                                        if (!$senderName && !empty($contactNames)) {
                                            // Fallback to first available contact name
                                            $senderName = reset($contactNames);
                                        }

                                        // Extract content safely
                                        $messageContent = '';
                                        switch ($msgType) {
                                            case 'text':
                                                $messageContent = $msg['text']['body'] ?? '';
                                                break;
                                            case 'image':
                                                $caption = $msg['image']['caption'] ?? '';
                                                $messageContent = trim("[Image] " . $caption);
                                                break;
                                            case 'video':
                                                $caption = $msg['video']['caption'] ?? '';
                                                $messageContent = trim("[Video] " . $caption);
                                                break;
                                            case 'document':
                                                $filename = $msg['document']['filename'] ?? 'document';
                                                $caption = $msg['document']['caption'] ?? '';
                                                $messageContent = trim("[Document: $filename] " . $caption);
                                                break;
                                            case 'audio':
                                                $messageContent = '[Audio Message]';
                                                break;
                                            case 'voice':
                                                $messageContent = '[Voice Note]';
                                                break;
                                            case 'location':
                                                $lat = $msg['location']['latitude'] ?? '';
                                                $lng = $msg['location']['longitude'] ?? '';
                                                $locName = $msg['location']['name'] ?? '';
                                                $messageContent = "[Location] Lat: $lat, Lng: $lng" . ($locName ? " ($locName)" : "");
                                                break;
                                            case 'button':
                                                $messageContent = "[Button Clicked] " . ($msg['button']['text'] ?? '');
                                                break;
                                            case 'interactive':
                                                $interactiveType = $msg['interactive']['type'] ?? '';
                                                if ($interactiveType === 'button_reply') {
                                                    $messageContent = "[Reply Button] " . ($msg['interactive']['button_reply']['title'] ?? '');
                                                } elseif ($interactiveType === 'list_reply') {
                                                    $messageContent = "[List Selected] " . ($msg['interactive']['list_reply']['title'] ?? '');
                                                } else {
                                                    $messageContent = "[Interactive message]";
                                                }
                                                break;
                                            default:
                                                $messageContent = "[" . ucfirst($msgType) . " message]";
                                                break;
                                        }

                                        // Set timezone to Asia/Kolkata for DB timestamp
                                        date_default_timezone_set('Asia/Kolkata');
                                        $dbTime = date('Y-m-d H:i:s', $timestamp);

                                        // Idempotent insertion using CI4 query builder (INSERT IGNORE equivalent)
                                        $db->table('whatsapp_messages')
                                           ->ignore()
                                           ->insert([
                                               'message_id'      => $messageId,
                                               'sender_phone'    => $senderPhone,
                                               'sender_name'     => $senderName,
                                               'message_type'    => $msgType,
                                               'message_content' => $messageContent,
                                               'raw_payload'     => $rawPayload,
                                               'created_at'      => $dbTime
                                           ]);

                                        log_message('info', "Saved WhatsApp Message ID: $messageId");
                                    }
                                }
                            }
                        }
                    }
                }

                return $this->response->setStatusCode(200)->setBody('EVENT_RECEIVED');
            } catch (\Exception $e) {
                log_message('error', 'Error processing WhatsApp Webhook: ' . $e->getMessage());
                return $this->response->setStatusCode(500)->setBody('Internal Server Error');
            }
        }

        return $this->response->setStatusCode(405)->setBody('Method Not Allowed');
    }

    /**
     * Display the Inbox Admin Dashboard
     */
    public function dashboard()
    {
        $request     = service('request');
        $search      = trim((string) $request->getGet('search'));
        $autoRefresh = $request->getGet('refresh') ?? '1';

        $messageModel = new WhatsappMessageModel();

        if ($search !== '') {
            // Safe search using model builder (uses prepared statements)
            $messages = $messageModel->like('sender_phone', $search)
                                     ->orderBy('created_at', 'DESC')
                                     ->findAll();
        } else {
            $messages = $messageModel->orderBy('created_at', 'DESC')
                                     ->findAll();
        }

        // Return view: app/Views/whatsapp/admin.php
        return view('whatsapp/admin', [
            'messages'    => $messages,
            'search'      => $search,
            'autoRefresh' => $autoRefresh
        ]);
    }
}
