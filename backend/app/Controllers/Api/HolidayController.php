<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

class HolidayController extends ResourceController
{
    protected $format = 'json';

    private function json(bool $ok, string $msg, $data = null, int $code = 200, $errors = null): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $ok,
            'message' => $msg,
            'data'    => $data,
            'errors'  => $errors,
        ]);
    }

    private function companyId(): int
    {
        $cid = (int)(session()->get('company_id') ?? 0);
        if ($cid > 0) return $cid;

        $req = $this->request->getJSON(true);
        if (is_array($req) && isset($req['company_id'])) return (int)$req['company_id'];

        return (int)$this->request->getGet('company_id');
    }

    private function isValidDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    /**
     * GET /api/holidays?from=YYYY-MM-DD&to=YYYY-MM-DD&page=1&limit=50
     */
    public function index(): ResponseInterface
    {
        $db = db_connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $from = trim((string)$this->request->getGet('from'));
        $to   = trim((string)$this->request->getGet('to'));

        $page  = max(1, (int)$this->request->getGet('page'));
        $limit = (int)$this->request->getGet('limit');
        if ($limit <= 0 || $limit > 200) $limit = 50;
        $offset = ($page - 1) * $limit;

        $b = $db->table('holidays h')
            ->select('h.*')
            ->where('h.company_id', $companyId);

        if ($from !== '' && $this->isValidDate($from)) $b->where('h.holiday_date >=', $from);
        if ($to !== '' && $this->isValidDate($to))     $b->where('h.holiday_date <=', $to);

        // total count (safe)
        $countBuilder = clone $b;
        $totalRow = $countBuilder->select('COUNT(*) AS c', false)->get()->getRowArray();
        $total = (int)($totalRow['c'] ?? 0);

        $rows = $b->orderBy('h.holiday_date', 'ASC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        return $this->json(true, 'OK', [
            'items' => $rows,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    /**
     * GET /api/holidays/{id}
     */
    public function show($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $db = db_connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $row = $db->table('holidays')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()->getRowArray();

        if (!$row) return $this->json(false, 'Holiday not found', null, 404);
        return $this->json(true, 'OK', $row);
    }

    /**
     * POST /api/holidays
     * Body: holiday_date, title(optional)
     */
    public function create(): ResponseInterface
    {
        $db = db_connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $p = $this->request->getJSON(true);
        if (!is_array($p) || empty($p)) $p = $this->request->getPost();
        if (!is_array($p)) $p = [];

        $date  = trim((string)($p['holiday_date'] ?? ''));
        $title = trim((string)($p['title'] ?? ''));

        $errors = [];
        if (!$this->isValidDate($date)) $errors['holiday_date'] = 'holiday_date must be YYYY-MM-DD';

        if (!empty($errors)) return $this->json(false, 'Validation failed', null, 422, $errors);

        // prevent duplicate holiday date per company
        $dup = $db->table('holidays')
            ->where('company_id', $companyId)
            ->where('holiday_date', $date)
            ->get()->getRowArray();

        if ($dup) return $this->json(false, 'Holiday already exists for this date', null, 409);

        $data = [
            'company_id'   => $companyId,
            'holiday_date' => $date,
            'title'        => ($title === '' ? null : $title),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $db->table('holidays')->insert($data);
        $id = (int)$db->insertID();

        return $this->json(true, 'Holiday created', ['id' => $id], 201);
    }

    /**
     * PUT/PATCH /api/holidays/{id}
     */
    public function update($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $db = db_connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $row = $db->table('holidays')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()->getRowArray();

        if (!$row) return $this->json(false, 'Holiday not found', null, 404);

        $p = $this->request->getJSON(true);
        if (!is_array($p) || empty($p)) $p = $this->request->getRawInput();
        if (!is_array($p)) $p = [];

        $data = [];
        $errors = [];

        if (array_key_exists('holiday_date', $p)) {
            $date = trim((string)$p['holiday_date']);
            if (!$this->isValidDate($date)) $errors['holiday_date'] = 'holiday_date must be YYYY-MM-DD';
            else {
                // duplicate check
                $dup = $db->table('holidays')
                    ->where('company_id', $companyId)
                    ->where('holiday_date', $date)
                    ->where('id !=', $id)
                    ->get()->getRowArray();

                if ($dup) $errors['holiday_date'] = 'Holiday already exists for this date';
                else $data['holiday_date'] = $date;
            }
        }

        if (array_key_exists('title', $p)) {
            $title = trim((string)$p['title']);
            $data['title'] = ($title === '' ? null : $title);
        }

        if (!empty($errors)) return $this->json(false, 'Validation failed', null, 422, $errors);
        if (empty($data)) return $this->json(false, 'No data to update', null, 422);

        $data['updated_at'] = date('Y-m-d H:i:s');
        $db->table('holidays')->where('id', $id)->update($data);

        return $this->json(true, 'Holiday updated', ['id' => $id]);
    }

    /**
     * DELETE /api/holidays/{id}
     */
    public function delete($id = null): ResponseInterface
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $db = db_connect();
        $companyId = $this->companyId();
        if ($companyId <= 0) return $this->json(false, 'company_id missing', null, 422);

        $row = $db->table('holidays')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->get()->getRowArray();

        if (!$row) return $this->json(false, 'Holiday not found', null, 404);

        $db->table('holidays')->where('id', $id)->delete();
        return $this->json(true, 'Holiday deleted', ['id' => $id]);
    }
}