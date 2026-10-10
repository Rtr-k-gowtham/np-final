<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ServiceModel;

class ServiceController extends BaseController
{
    // GET /api/services
    public function index()
    {
        $model = new ServiceModel();

        $rows = $model->orderBy('id', 'DESC')->findAll();

        return $this->response->setJSON([
            'success' => true,
            'data'    => $rows
        ]);
    }

    // GET /api/services/{id}
    public function show($id)
    {
        $model = new ServiceModel();
        $row   = $model->find((int)$id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Service not found'
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'data'    => $row
        ]);
    }

    // POST /api/services
    public function create()
    {
        $data = $this->request->getJSON(true) ?? [];

        $payload = [
            'code'             => strtoupper(trim($data['code'] ?? '')),
            'name'             => trim($data['name'] ?? ''),
            'description'      => $data['description'] ?? null,
            'default_sla_days' => (int)($data['default_sla_days'] ?? 0),
            'status'           => (int)($data['status'] ?? 1),
        ];

        $model = new ServiceModel();

        if (!$model->insert($payload)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $model->errors()
            ]);
        }

        return $this->response->setStatusCode(201)->setJSON([
            'success' => true,
            'message' => 'Service created',
            'data'    => $model->find($model->getInsertID())
        ]);
    }

    // PUT /api/services/{id}
    public function update($id)
    {
        $id   = (int)$id;
        $data = $this->request->getJSON(true) ?? [];

        $model = new ServiceModel();
        $row   = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Service not found'
            ]);
        }

        // allow partial update
        $payload = [
            'code'             => isset($data['code']) ? strtoupper(trim($data['code'])) : $row['code'],
            'name'             => isset($data['name']) ? trim($data['name']) : $row['name'],
            'description'      => array_key_exists('description', $data) ? $data['description'] : $row['description'],
            'default_sla_days' => isset($data['default_sla_days']) ? (int)$data['default_sla_days'] : (int)$row['default_sla_days'],
            'status'           => isset($data['status']) ? (int)$data['status'] : (int)$row['status'],
        ];

        if (!$model->update($id, $payload)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Update failed',
                'errors'  => $model->errors()
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Service updated',
            'data'    => $model->find($id)
        ]);
    }

    // DELETE /api/services/{id}
    public function delete($id)
    {
        $id = (int)$id;

        $model = new ServiceModel();
        $row   = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Service not found'
            ]);
        }

        $model->delete($id);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Service deleted'
        ]);
    }
}
