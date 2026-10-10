<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class LeadFollowupController extends BaseController
{
    private function authUserId(): ?int
    {
        $u = $this->request->user ?? null;
        if (is_array($u) && isset($u['id'])) return (int)$u['id'];
        if (is_object($u) && isset($u->id)) return (int)$u->id;
        return null;
    }

    private function json(bool $success, string $message = '', $data = null, int $code = 200): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    // GET /api/lead-followups/lead/{leadId}
    public function index($leadId = null): ResponseInterface
    {
        $leadId = (int)$leadId;
        if ($leadId <= 0) return $this->json(false, 'Invalid lead id', null, 400);

        $db = db_connect();

        $lead = $db->table('leads')
            ->select('id, lead_code, name, company_name, status')
            ->where('id', $leadId)
            ->get()->getRowArray();

        if (!$lead) return $this->json(false, 'Lead not found', null, 404);

        $rows = $db->table('lead_followups')
            ->where('lead_id', $leadId)
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        return $this->json(true, 'Followups fetched', [
            'lead' => $lead,
            'followups' => $rows
        ]);
    }

    // POST /api/lead-followups/lead/{leadId}
    public function create($leadId = null): ResponseInterface
    {
        $leadId = (int)$leadId;
        if ($leadId <= 0) return $this->json(false, 'Invalid lead id', null, 400);

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = $this->request->getPost();

        $rules = [
            'followup_type'   => 'permit_empty|max_length[20]',
            'remarks'         => 'permit_empty',
            'next_followup_at' => 'permit_empty',
        ];

        if (!$this->validateData($payload, $rules)) {
            return $this->json(false, 'Validation failed', ['errors' => $this->validator->getErrors()], 422);
        }

        $db = db_connect();

        $lead = $db->table('leads')->select('id,status')->where('id', $leadId)->get()->getRowArray();
        if (!$lead) return $this->json(false, 'Lead not found', null, 404);

        $next = isset($payload['next_followup_at']) ? trim((string)$payload['next_followup_at']) : null;
        if ($next === '') $next = null;

        // Allow "YYYY-MM-DD HH:MM" or "YYYY-MM-DD HH:MM:SS"
        if ($next !== null) {
            if (!preg_match('/^\d{4}\-\d{2}\-\d{2}\s\d{2}\:\d{2}(\:\d{2})?$/', $next)) {
                return $this->json(false, 'next_followup_at must be YYYY-MM-DD HH:MM[:SS]', null, 422);
            }
            if (strlen($next) === 16) $next .= ':00';
        }

        $data = [
            'lead_id' => $leadId,
            'user_id' => $this->authUserId(),
            'followup_type' => isset($payload['followup_type']) ? trim((string)$payload['followup_type']) : null,
            'remarks' => $payload['remarks'] ?? null,
            'next_followup_at' => $next,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $db->table('lead_followups')->insert($data);
            $id = (int)$db->insertID();

            // optional auto-status move
            if (($lead['status'] ?? 'new') === 'new') {
                $db->table('leads')->where('id', $leadId)->update([
                    'status' => 'contacted',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $this->json(true, 'Followup created', [
                'id' => $id,
                'lead_id' => $leadId
            ], 201);

        } catch (\Throwable $e) {
            return $this->json(false, 'Failed to create followup', ['error' => $e->getMessage()], 500);
        }
    }
	
	public function update($id = null): ResponseInterface
{
    $id = (int)$id;
    if ($id <= 0) return $this->json(false, 'Invalid followup id', null, 400);

    $payload = $this->request->getJSON(true);
    if (!is_array($payload)) $payload = $this->request->getRawInput();
    if (!is_array($payload) || $payload === []) $payload = $this->request->getPost();

    $rules = [
        'followup_type'    => 'permit_empty|max_length[20]',
        'remarks'          => 'permit_empty',
        'next_followup_at' => 'permit_empty',
    ];

    if (!$this->validateData($payload, $rules)) {
        return $this->json(false, 'Validation failed', ['errors' => $this->validator->getErrors()], 422);
    }

    $db = db_connect();

    $row = $db->table('lead_followups')->select('id,lead_id')->where('id', $id)->get()->getRowArray();
    if (!$row) return $this->json(false, 'Followup not found', null, 404);

    $data = [];

    if (array_key_exists('followup_type', $payload)) {
        $v = trim((string)$payload['followup_type']);
        $data['followup_type'] = ($v === '') ? null : $v;
    }

    if (array_key_exists('remarks', $payload)) {
        $v = (string)$payload['remarks'];
        $data['remarks'] = (trim($v) === '') ? null : $v;
    }

    if (array_key_exists('next_followup_at', $payload)) {
        $next = trim((string)$payload['next_followup_at']);
        if ($next === '') {
            $data['next_followup_at'] = null;
        } else {
            // Allow "YYYY-MM-DD HH:MM" or "YYYY-MM-DD HH:MM:SS"
            if (!preg_match('/^\d{4}\-\d{2}\-\d{2}\s\d{2}\:\d{2}(\:\d{2})?$/', $next)) {
                return $this->json(false, 'next_followup_at must be YYYY-MM-DD HH:MM[:SS]', null, 422);
            }
            if (strlen($next) === 16) $next .= ':00';
            $data['next_followup_at'] = $next;
        }
    }

    if (!$data) return $this->json(true, 'Nothing to update', ['id' => $id]);

    try {
        $db->table('lead_followups')->where('id', $id)->update($data);
        return $this->json(true, 'Followup updated', [
            'id' => $id,
            'lead_id' => (int)$row['lead_id']
        ]);
    } catch (\Throwable $e) {
        return $this->json(false, 'Failed to update followup', ['error' => $e->getMessage()], 500);
    }
}


public function delete($id = null): \CodeIgniter\HTTP\ResponseInterface
{
    $id = (int) $id;
    if ($id <= 0) return $this->json(false, 'Invalid followup id', null, 400);

    $db = db_connect();

    $row = $db->table('lead_followups')->select('id')->where('id', $id)->get()->getRowArray();
    if (!$row) return $this->json(false, 'Followup not found', null, 404);

    try {
        $db->table('lead_followups')->where('id', $id)->delete();
        return $this->json(true, 'Followup deleted', ['id' => $id]);
    } catch (\Throwable $e) {
        return $this->json(false, 'Failed to delete followup', ['error' => $e->getMessage()], 500);
    }
}

}
