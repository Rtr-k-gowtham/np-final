<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class LeadController extends BaseController
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

    private function generateLeadCode(): string
    {
        return 'LD' . date('Ymd') . '-' . random_int(100000, 999999);
    }

    // GET /api/leads
    public function index(): ResponseInterface
    {
        $db = db_connect();

        $status     = (string)($this->request->getGet('status') ?? '');
        $assignedTo = $this->request->getGet('assigned_to');
        $serviceId  = $this->request->getGet('service_id');

        $subLatest = "(SELECT MAX(lf.next_followup_at) FROM lead_followups lf WHERE lf.lead_id = l.id)";

        $b = $db->table('leads l')
            ->select("l.*, {$subLatest} AS next_followup_at, s.name AS service_name, c.name AS converted_client_name")
            ->join('services s', 's.id = l.service_id', 'left')
            ->join('clients c', 'c.id = l.converted_client_id', 'left')
            ->orderBy('l.id', 'DESC');

        if ($status !== '') $b->where('l.status', $status);
        if ($assignedTo !== null && $assignedTo !== '') $b->where('l.assigned_to', (int)$assignedTo);
        if ($serviceId !== null && $serviceId !== '') $b->where('l.service_id', (int)$serviceId);

        $rows = $b->get()->getResultArray();
        return $this->json(true, 'Leads fetched', $rows);
    }

    // GET /api/leads/{id}
    public function show($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid lead id', null, 400);

        $db = db_connect();
        $subLatest = "(SELECT MAX(lf.next_followup_at) FROM lead_followups lf WHERE lf.lead_id = l.id)";

        $row = $db->table('leads l')
            ->select("l.*, {$subLatest} AS next_followup_at, s.name AS service_name, c.name AS converted_client_name")
            ->join('services s', 's.id = l.service_id', 'left')
            ->join('clients c', 'c.id = l.converted_client_id', 'left')
            ->where('l.id', $id)
            ->get()->getRowArray();

        if (!$row) return $this->json(false, 'Lead not found', null, 404);

        return $this->json(true, 'Lead fetched', $row);
    }

    // POST /api/leads
    public function create(): ResponseInterface
    {
        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = $this->request->getPost();

        $rules = [
            'name'   => 'required|min_length[2]|max_length[120]',
            'phone'  => 'required|min_length[7]|max_length[30]',
            'email'  => 'permit_empty|valid_email|max_length[150]',
            'source' => 'permit_empty|max_length[50]',
            'priority' => 'permit_empty|max_length[20]',
            'status' => 'permit_empty|max_length[20]',
        ];

        if (!$this->validateData($payload, $rules)) {
            return $this->json(false, 'Validation failed', ['errors' => $this->validator->getErrors()], 422);
        }

        $db = db_connect();

        $data = [
            'lead_code'           => $payload['lead_code'] ?? $this->generateLeadCode(),
            'source'              => $payload['source'] ?? null,
            'service_id'          => isset($payload['service_id']) && $payload['service_id'] !== '' ? (int)$payload['service_id'] : null,

            'name'                => $payload['name'],
            'company_name'        => $payload['company_name'] ?? null,
            'phone'               => $payload['phone'],
            'email'               => $payload['email'] ?? null,

            'address'             => $payload['address'] ?? null,
            'city'                => $payload['city'] ?? null,
            'state'               => $payload['state'] ?? null,
            'country'             => $payload['country'] ?? null,

            'requirement'         => $payload['requirement'] ?? null,
            'lead_value'          => isset($payload['lead_value']) && $payload['lead_value'] !== '' ? (float)$payload['lead_value'] : null,
            'budget'              => isset($payload['budget']) && $payload['budget'] !== '' ? (float)$payload['budget'] : null,
            'priority'            => $payload['priority'] ?? 'normal',
            'expected_start_date' => $payload['expected_start_date'] ?? null,

            'status'              => $payload['status'] ?? 'new',
            'assigned_to'         => isset($payload['assigned_to']) && $payload['assigned_to'] !== '' ? (int)$payload['assigned_to'] : null,
            'created_by'          => $this->authUserId(),
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => null,
        ];

        try {
            $db->table('leads')->insert($data);
            $id = (int)$db->insertID();
            return $this->json(true, 'Lead created', ['id' => $id, 'lead_code' => $data['lead_code']], 201);
        } catch (\Throwable $e) {
            return $this->json(false, 'Failed to create lead', ['error' => $e->getMessage()], 500);
        }
    }

    // PUT/PATCH /api/leads/{id}
    public function update($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid lead id', null, 400);

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = $this->request->getRawInput();

        $rules = [
            'name'   => 'permit_empty|min_length[2]|max_length[120]',
            'phone'  => 'permit_empty|min_length[7]|max_length[30]',
            'email'  => 'permit_empty|valid_email|max_length[150]',
            'source' => 'permit_empty|max_length[50]',
            'priority' => 'permit_empty|max_length[20]',
            'status' => 'permit_empty|max_length[20]',
        ];

        if (!$this->validateData($payload, $rules)) {
            return $this->json(false, 'Validation failed', ['errors' => $this->validator->getErrors()], 422);
        }

        $db = db_connect();

        $lead = $db->table('leads')->select('id,status')->where('id', $id)->get()->getRowArray();
        if (!$lead) return $this->json(false, 'Lead not found', null, 404);

        $allowed = [
            'source','service_id','name','company_name','phone','email',
            'address','city','state','country',
            'requirement','lead_value','budget','priority','expected_start_date',
            'status','assigned_to'
        ];

        $data = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $payload)) {
                $v = $payload[$k];
                if (in_array($k, ['service_id','assigned_to'], true)) {
                    $data[$k] = ($v === '' || $v === null) ? null : (int)$v;
                } elseif (in_array($k, ['lead_value','budget'], true)) {
                    $data[$k] = ($v === '' || $v === null) ? null : (float)$v;
                } else {
                    $data[$k] = ($v === '' ? null : $v);
                }
            }
        }

        if (!$data) return $this->json(true, 'Nothing to update', ['id' => $id]);

        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $db->table('leads')->where('id', $id)->update($data);
            return $this->json(true, 'Lead updated', ['id' => $id]);
        } catch (\Throwable $e) {
            return $this->json(false, 'Failed to update lead', ['error' => $e->getMessage()], 500);
        }
    }

    // DELETE /api/leads/{id}
    public function delete($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid lead id', null, 400);

        $db = db_connect();
        $lead = $db->table('leads')->select('id,status')->where('id', $id)->get()->getRowArray();
        if (!$lead) return $this->json(false, 'Lead not found', null, 404);

        if (($lead['status'] ?? '') === 'converted') {
            return $this->json(false, 'Converted lead cannot be deleted', null, 409);
        }

        $db->table('leads')->where('id', $id)->delete();
        return $this->json(true, 'Lead deleted', ['id' => $id]);
    }

    // GET /api/leads/datatables
    public function datatables(): ResponseInterface
    {
        $db = db_connect();

        $draw   = (int)($this->request->getGet('draw') ?? 1);
        $start  = (int)($this->request->getGet('start') ?? 0);
        $length = (int)($this->request->getGet('length') ?? 10);

        $search = (string)($this->request->getGet('search')['value'] ?? '');

        $status     = (string)($this->request->getGet('status') ?? '');
        $assignedTo = $this->request->getGet('assigned_to');
        $serviceId  = $this->request->getGet('service_id');

        $subLatest = "(SELECT MAX(lf.next_followup_at) FROM lead_followups lf WHERE lf.lead_id = l.id)";

        $base = $db->table('leads l')
            ->select("l.id, l.lead_code, l.name, l.company_name, l.phone, l.email, l.source,
                      l.service_id, s.name AS service_name,
                      l.status, l.priority, l.assigned_to, l.created_at,
                      l.converted_client_id, {$subLatest} AS next_followup_at")
            ->join('services s', 's.id = l.service_id', 'left')
            ->orderBy('l.id', 'DESC');

        if ($status !== '') $base->where('l.status', $status);
        if ($assignedTo !== null && $assignedTo !== '') $base->where('l.assigned_to', (int)$assignedTo);
        if ($serviceId !== null && $serviceId !== '') $base->where('l.service_id', (int)$serviceId);

        // Count after filters (before search)
        $countBase = clone $base;
        $filteredBase = (int)$countBase->countAllResults(false);

        if ($search !== '') {
            $escaped = $db->escapeLikeString($search);
            $base->groupStart()
                ->where("l.lead_code LIKE '%{$escaped}%'")
                ->orWhere("l.name LIKE '%{$escaped}%'")
                ->orWhere("l.company_name LIKE '%{$escaped}%'")
                ->orWhere("l.phone LIKE '%{$escaped}%'")
                ->orWhere("l.email LIKE '%{$escaped}%'")
                ->orWhere("l.source LIKE '%{$escaped}%'")
                ->orWhere("s.name LIKE '%{$escaped}%'")
                ->groupEnd();
        }

        $countSearch = clone $base;
        $filtered = (int)$countSearch->countAllResults(false);

        if ($length > 0) $base->limit($length, $start);

        $data = $base->get()->getResultArray();
        $total = (int)$db->table('leads')->countAllResults();

        return $this->response->setJSON([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => ($search !== '') ? $filtered : $filteredBase,
            'data' => $data
        ]);
    }

    // POST /api/leads/{id}/convert
    public function convert($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid lead id', null, 400);

        $db = db_connect();

        $lead = $db->table('leads')->where('id', $id)->get()->getRowArray();
        if (!$lead) return $this->json(false, 'Lead not found', null, 404);

        if (($lead['status'] ?? '') === 'converted' && !empty($lead['converted_client_id'])) {
            return $this->json(true, 'Lead already converted', [
                'lead_id'   => $id,
                'client_id' => (int)$lead['converted_client_id'],
            ]);
        }

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) $payload = [];

        $linkClientId = isset($payload['client_id']) && $payload['client_id'] !== '' ? (int)$payload['client_id'] : 0;

        $db->transStart();

        try {
            $clientId = 0;

            if ($linkClientId > 0) {
                $c = $db->table('clients')->select('id')->where('id', $linkClientId)->get()->getRowArray();
                if (!$c) throw new \RuntimeException('Selected client not found');
                $clientId = $linkClientId;
            } else {
                $phone = trim((string)($lead['phone'] ?? ''));
                $email = trim((string)($lead['email'] ?? ''));

                $existing = null;
                if ($phone !== '') {
                    $existing = $db->table('clients')->select('id')->where('phone', $phone)->get()->getRowArray();
                }
                if (!$existing && $email !== '') {
                    $existing = $db->table('clients')->select('id')->where('email', $email)->get()->getRowArray();
                }

                if ($existing && isset($existing['id'])) {
                    $clientId = (int)$existing['id'];
                } else {
                    // FIXED: Added company_id and reference_source to ensure successful insert
                    $clientData = [
                        'company_id'      => 1, // Defaulting to 1 as per frontend req
                        'name'            => $lead['company_name'] ?: ($lead['name'] ?? ''),
                        'phone'           => $lead['phone'] ?? null,
                        'email'           => $lead['email'] ?? null,
                        'address'         => $lead['address'] ?? null,
                        'city'            => $lead['city'] ?? null,
                        'state'           => $lead['state'] ?? null,
                        'country'         => $lead['country'] ?? null,
                        'reference_source' => 'Lead Conversion', 
                        'created_at'      => date('Y-m-d H:i:s'),
                        'updated_at'      => null,
                    ];

                    $db->table('clients')->insert($clientData);
                    $clientId = (int)$db->insertID();
                }
            }

            $db->table('leads')->where('id', $id)->update([
                'status'              => 'converted',
                'converted_client_id' => $clientId,
                'converted_at'        => date('Y-m-d H:i:s'),
                'updated_at'          => date('Y-m-d H:i:s'),
            ]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->json(false, 'Conversion failed', null, 500);
            }

            return $this->json(true, 'Lead converted to client', [
                'lead_id'   => $id,
                'client_id' => $clientId,
            ]);

        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->json(false, 'Conversion failed', ['error' => $e->getMessage()], 500);
        }
    }
}
