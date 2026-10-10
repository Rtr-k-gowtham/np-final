<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ProjectExpenseModel;
use App\Models\ProjectExpenseApprovalModel;

class ProjectExpenseController extends ResourceController
{
    protected $format = 'json';

    private function json($success, $message, $data = null, $code = 200, $errors = null)
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => (bool)$success,
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors
        ]);
    }

    private function payload(): array
    {
        $p = $this->request->getJSON(true);
        if (!is_array($p) || empty($p)) $p = $this->request->getRawInput();
        if (!is_array($p) || empty($p)) $p = $this->request->getPost() ?? [];
        return is_array($p) ? $p : [];
    }

    // In your project you already use auth filter; you also pass X_USER_ID in some places.
    // Use your existing helper method if available. Else this is okay.
    private function authUserId(): int
    {
        return (int)($_SERVER['HTTP_X_USER_ID'] ?? 0);
    }

    /**
     * GET /api/projects/{projectId}/expenses
     * Query: search, status, payment_status, category_id, from, to, page, perPage
     */
    public function index($projectId = null)
    {
        $projectId = (int)$projectId;
        if ($projectId <= 0) return $this->json(false, 'Invalid project id', null, 422);

        $search        = trim((string)$this->request->getGet('search'));
        $status        = trim((string)$this->request->getGet('status'));
        $paymentStatus = trim((string)$this->request->getGet('payment_status'));
        $categoryId    = (int)($this->request->getGet('category_id') ?? 0);
        $from          = trim((string)$this->request->getGet('from')); // YYYY-MM-DD
        $to            = trim((string)$this->request->getGet('to'));   // YYYY-MM-DD

        $page    = max(1, (int)($this->request->getGet('page') ?? 1));
        $perPage = max(1, min(100, (int)($this->request->getGet('perPage') ?? 10)));
        $offset  = ($page - 1) * $perPage;

        $db = db_connect();
        $b  = $db->table('project_expenses pe')
            ->where('pe.project_id', $projectId);

        if ($status !== '') $b->where('pe.status', $status);
        if ($paymentStatus !== '') $b->where('pe.payment_status', $paymentStatus);
        if ($categoryId > 0) $b->where('pe.category_id', $categoryId);

        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $b->where('pe.expense_date >=', $from);
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $b->where('pe.expense_date <=', $to);

        if ($search !== '') {
            $b->groupStart()
                ->like('pe.title', $search)
                ->orLike('pe.description', $search)
              ->groupEnd();
        }

        $countB = clone $b;
        $total  = (int)$countB->select('COUNT(*) cnt', false)->get()->getRow('cnt');

        $rows = $b->select('pe.*')
            ->orderBy('pe.expense_date', 'DESC')
            ->orderBy('pe.id', 'DESC')
            ->limit($perPage, $offset)
            ->get()->getResultArray();

        return $this->json(true, 'OK', [
            'items' => $rows,
            'meta'  => [
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
                'pages'   => (int)ceil($total / $perPage),
            ]
        ]);
    }

    /**
     * GET /api/project-expenses/{id}
     */
    public function show($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $model = new ProjectExpenseModel();
        $row = $model->find($id);

        if (!$row) return $this->json(false, 'Expense not found', null, 404);
        return $this->json(true, 'OK', $row);
    }

    /**
     * POST /api/projects/{projectId}/expenses
     */
    public function create($projectId = null)
    {
        $projectId = (int)$projectId;
        if ($projectId <= 0) return $this->json(false, 'Invalid project id', null, 422);

        $userId = $this->authUserId();
        if ($userId <= 0) return $this->json(false, 'Login required', null, 401);

        $p = $this->payload();

        $data = [
            'project_id'     => $projectId,
            'category_id'    => (int)($p['category_id'] ?? 0),
            'expense_date'   => trim((string)($p['expense_date'] ?? '')),
            'title'          => trim((string)($p['title'] ?? '')),
            'description'    => $p['description'] ?? null,
            'amount'         => (string)($p['amount'] ?? '0.00'),
            'tax_amount'     => (string)($p['tax_amount'] ?? '0.00'),
            'total_amount'   => (string)($p['total_amount'] ?? ''), // optional, will compute if blank
            'status'         => $p['status'] ?? 'draft',
            'payment_status' => $p['payment_status'] ?? 'unpaid',
            'submitted_by'   => null,
            'approved_by'    => null,
            'approved_at'    => null,
        ];

        // compute total if not passed
        $amt = (float)$data['amount'];
        $tax = (float)$data['tax_amount'];
        if ($data['total_amount'] === '' || $data['total_amount'] === null) {
            $data['total_amount'] = number_format($amt + $tax, 2, '.', '');
        }

        $model = new ProjectExpenseModel();
        if (!$model->insert($data)) {
            return $this->json(false, 'Validation failed', null, 422, $model->errors());
        }

        $id = (int)$model->getInsertID();
        return $this->json(true, 'Expense created', $model->find($id), 201);
    }

    /**
     * PUT/PATCH /api/project-expenses/{id}
     */
    public function update($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $userId = $this->authUserId();
        if ($userId <= 0) return $this->json(false, 'Login required', null, 401);

        $model = new ProjectExpenseModel();
        $row = $model->find($id);
        if (!$row) return $this->json(false, 'Expense not found', null, 404);

        // professional restriction
        $roleCode = strtolower(trim((string)($_SERVER['HTTP_X_ROLE_CODE'] ?? '')));

		$isAdmin = ($roleCode === 'admin');

		if (strtolower((string)($row['status'] ?? '')) === 'approved' && !$isAdmin) {
			return $this->json(false, 'Approved expense can be edited by admin only', null, 409);
		}

        $p = $this->payload();

        $data = [];
        if (array_key_exists('category_id', $p))  $data['category_id'] = (int)$p['category_id'];
        if (array_key_exists('expense_date', $p)) $data['expense_date'] = trim((string)$p['expense_date']);
        if (array_key_exists('title', $p))        $data['title'] = trim((string)$p['title']);
        if (array_key_exists('description', $p))  $data['description'] = $p['description'];

        if (array_key_exists('amount', $p))     $data['amount'] = (string)$p['amount'];
        if (array_key_exists('tax_amount', $p)) $data['tax_amount'] = (string)$p['tax_amount'];

        if (array_key_exists('payment_status', $p)) $data['payment_status'] = trim((string)$p['payment_status']);

        // recalc total if amount/tax changed
        if (isset($data['amount']) || isset($data['tax_amount'])) {
            $amt = (float)($data['amount'] ?? $row['amount']);
            $tax = (float)($data['tax_amount'] ?? $row['tax_amount']);
            $data['total_amount'] = number_format($amt + $tax, 2, '.', '');
        }

        if (!$model->update($id, $data)) {
            return $this->json(false, 'Update failed', null, 422, $model->errors());
        }

        return $this->json(true, 'Expense updated', $model->find($id));
    }

    /**
     * DELETE /api/project-expenses/{id}
     */
    public function delete($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $userId = $this->authUserId();
        if ($userId <= 0) return $this->json(false, 'Login required', null, 401);

        $model = new ProjectExpenseModel();
        $row = $model->find($id);
        if (!$row) return $this->json(false, 'Expense not found', null, 404);

        if (($row['status'] ?? '') === 'approved') {
            return $this->json(false, 'Approved expense cannot be deleted', null, 409);
        }

        $model->delete($id);
        return $this->json(true, 'Expense deleted');
    }

    /**
     * POST /api/project-expenses/{id}/submit
     */
    public function submit($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $userId = $this->authUserId();
        if ($userId <= 0) return $this->json(false, 'Login required', null, 401);

        $model = new ProjectExpenseModel();
        $row = $model->find($id);
        if (!$row) return $this->json(false, 'Expense not found', null, 404);

        if (strtolower((string)$row['status']) !== 'draft') {
            return $this->json(false, 'Only draft can be submitted', null, 409);
        }

        $model->update($id, [
            'status'       => 'submitted',
            'submitted_by' => $userId,
        ]);

        (new ProjectExpenseApprovalModel())->insert([
            'expense_id' => $id,
            'action'     => 'submitted',
            'remarks'    => null,
            'action_by'  => $userId,
            'action_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->json(true, 'Expense submitted', $model->find($id));
    }

    /**
     * POST /api/project-expenses/{id}/approve
     * Body: { "remarks": "ok" }
     */
    public function approve($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $userId = $this->authUserId();
        if ($userId <= 0) return $this->json(false, 'Login required', null, 401);

        $p = $this->payload();
        $remarks = trim((string)($p['remarks'] ?? ''));

        $model = new ProjectExpenseModel();
        $row = $model->find($id);
        if (!$row) return $this->json(false, 'Expense not found', null, 404);

        if (strtolower((string)$row['status']) !== 'submitted') {
            return $this->json(false, 'Only submitted can be approved', null, 409);
        }

        $model->update($id, [
            'status'      => 'approved',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        (new ProjectExpenseApprovalModel())->insert([
            'expense_id' => $id,
            'action'     => 'approved',
            'remarks'    => $remarks ?: null,
            'action_by'  => $userId,
            'action_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->json(true, 'Expense approved', $model->find($id));
    }

    /**
     * POST /api/project-expenses/{id}/reject
     * Body: { "remarks": "reason" }
     */
    public function reject($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $userId = $this->authUserId();
        if ($userId <= 0) return $this->json(false, 'Login required', null, 401);

        $p = $this->payload();
        $remarks = trim((string)($p['remarks'] ?? ''));

        $model = new ProjectExpenseModel();
        $row = $model->find($id);
        if (!$row) return $this->json(false, 'Expense not found', null, 404);

        if (strtolower((string)$row['status']) !== 'submitted') {
            return $this->json(false, 'Only submitted can be rejected', null, 409);
        }

        $model->update($id, [
            'status'      => 'rejected',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        (new ProjectExpenseApprovalModel())->insert([
            'expense_id' => $id,
            'action'     => 'rejected',
            'remarks'    => $remarks ?: null,
            'action_by'  => $userId,
            'action_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->json(true, 'Expense rejected', $model->find($id));
    }

    /**
     * GET /api/project-expenses/{id}/approvals
     */
    public function approvals($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) return $this->json(false, 'Invalid id', null, 422);

        $db = db_connect();
        $exists = $db->table('project_expenses')->select('id')->where('id', $id)->get()->getRowArray();
        if (!$exists) return $this->json(false, 'Expense not found', null, 404);

        $rows = $db->table('project_expense_approvals')
            ->where('expense_id', $id)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return $this->json(true, 'OK', $rows);
    }
	
	public function dropdown()
	{
		$db = db_connect();

		$rows = $db->table('expense_categories')
			->select('id, category_name')
			->orderBy('category_name', 'ASC')
			->get()
			->getResultArray();

		return $this->response->setJSON([
			'success' => true,
			'message' => 'OK',
			'data'    => $rows
		]);
	}
}
