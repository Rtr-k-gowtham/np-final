<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ExpenseCategoryModel;

class ExpenseCategoryController extends ResourceController
{
    protected $format = 'json';

    private function ok($data = null, string $message = 'OK', int $code = 200)
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ]);
    }

    private function failRes($errors = null, string $message = 'Error', int $code = 400)
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
        ]);
    }

    /**
     * GET /api/expense-categories?q=&page=&perPage=
     * List with optional search + pagination
     */
    public function index()
    {
        $model   = new ExpenseCategoryModel();

        $q       = trim((string) $this->request->getGet('q'));
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = max(1, min(100, (int) ($this->request->getGet('perPage') ?? 20)));

        $builder = $model->builder();

        if ($q !== '') {
            $builder->like('category_name', $q);
        }

        $total = (clone $builder)->countAllResults(false);

        $rows = $builder
            ->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return $this->ok([
            'items' => $rows,
            'meta'  => [
                'q'       => $q,
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
            ],
        ], 'Expense categories loaded');
    }

    /**
     * GET /api/expense-categories/{id}
     */
    public function show($id = null)
    {
        $id = (int) $id;
        if ($id <= 0) return $this->failRes(['id' => 'Invalid id'], 'Validation error', 422);

        $model = new ExpenseCategoryModel();
        $row   = $model->find($id);

        if (!$row) return $this->failRes(null, 'Category not found', 404);

        return $this->ok($row, 'Category loaded');
    }

    /**
     * POST /api/expense-categories
     * Body JSON: { "category_name": "Fuel" }
     */
    public function create()
    {
        $model = new ExpenseCategoryModel();

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) {
            $payload = $this->request->getPost(); // fallback
        }

        $data = [
            'category_name' => trim((string)($payload['category_name'] ?? '')),
        ];

        // Optional: prevent duplicates (case-insensitive)
        if ($data['category_name'] !== '') {
            $exists = $model->where('LOWER(category_name)', strtolower($data['category_name']))->first();
            if ($exists) {
                return $this->failRes(['category_name' => 'Category already exists'], 'Duplicate', 409);
            }
        }

        if (!$model->insert($data)) {
            return $this->failRes($model->errors(), 'Validation failed', 422);
        }

        $newId = (int) $model->getInsertID();
        $row   = $model->find($newId);

        return $this->ok($row, 'Category created', 201);
    }

    /**
     * PUT /api/expense-categories/{id}
     * Body JSON: { "category_name": "Travel" }
     */
    public function update($id = null)
    {
        $id = (int) $id;
        if ($id <= 0) return $this->failRes(['id' => 'Invalid id'], 'Validation error', 422);

        $model = new ExpenseCategoryModel();
        $row   = $model->find($id);
        if (!$row) return $this->failRes(null, 'Category not found', 404);

        $payload = $this->request->getJSON(true);
        if (!is_array($payload)) {
            $payload = $this->request->getRawInput() ?: $this->request->getPost();
        }

        $data = [
            'category_name' => trim((string)($payload['category_name'] ?? $row['category_name'])),
        ];

        // Optional duplicate check excluding current id
        if ($data['category_name'] !== '') {
            $exists = $model->where('LOWER(category_name)', strtolower($data['category_name']))
                ->where('id !=', $id)
                ->first();
            if ($exists) {
                return $this->failRes(['category_name' => 'Category already exists'], 'Duplicate', 409);
            }
        }

        if (!$model->update($id, $data)) {
            return $this->failRes($model->errors(), 'Validation failed', 422);
        }

        return $this->ok($model->find($id), 'Category updated');
    }

    /**
     * DELETE /api/expense-categories/{id}
     */
    public function delete($id = null)
    {
        $id = (int) $id;
        if ($id <= 0) return $this->failRes(['id' => 'Invalid id'], 'Validation error', 422);

        $model = new ExpenseCategoryModel();
        $row   = $model->find($id);
        if (!$row) return $this->failRes(null, 'Category not found', 404);

        // If you have FK references (expenses using category), this may fail -> catch it
        try {
            $model->delete($id);
        } catch (\Throwable $e) {
            return $this->failRes(['db' => $e->getMessage()], 'Cannot delete (in use?)', 409);
        }

        return $this->ok(['id' => $id], 'Category deleted');
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