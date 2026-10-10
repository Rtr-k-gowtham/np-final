<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class ServiceTaskTemplateController extends BaseController
{
    private function json(bool $success, string $message = '', $data = null, int $code = 200, array $errors = []): ResponseInterface
    {
        $payload = ['success' => $success, 'message' => $message];
        if ($data !== null) $payload['data'] = $data;
        if (!empty($errors)) $payload['errors'] = $errors;
        return $this->response->setStatusCode($code)->setJSON($payload);
    }

    private function ensureDefaultUserColumn(): void
    {
        try {
            $db = db_connect();
            $fields = $db->getFieldNames('service_task_templates');
            if (!in_array('default_assigned_user_id', $fields, true)) {
                $db->query("ALTER TABLE `service_task_templates` ADD COLUMN `default_assigned_user_id` INT NULL DEFAULT NULL AFTER `is_default`");
            }
        } catch (\Throwable $e) {
            // Silently ignore if table altered or permission restricted
        }
    }

    // GET /api/services/{serviceId}/task-templates
    public function index($serviceId): ResponseInterface
    {
        $serviceId = (int)$serviceId;
        $db = db_connect();
        $this->ensureDefaultUserColumn();

        $rows = $db->table('service_task_templates stt')
            ->select('stt.*, u.name as default_user_name, u.email as default_user_email')
            ->join('users u', 'u.id = stt.default_assigned_user_id', 'left')
            ->where('stt.service_id', $serviceId)
            ->where('stt.status', 1)
            ->orderBy('stt.is_default', 'DESC')
            ->orderBy('stt.id', 'DESC')
            ->get()
            ->getResultArray();

        return $this->json(true, 'OK', $rows);
    }

    // GET /api/services/task-templates/{id}
    public function show($id): ResponseInterface
    {
        $id = (int)$id;
        $db = db_connect();
        $this->ensureDefaultUserColumn();

        $row = $db->table('service_task_templates stt')
            ->select('stt.*, u.name as default_user_name, u.email as default_user_email')
            ->join('users u', 'u.id = stt.default_assigned_user_id', 'left')
            ->where('stt.id', $id)
            ->get()
            ->getRowArray();
        if (!$row) return $this->json(false, 'Task template not found', null, 404);

        return $this->json(true, 'OK', $row);
    }

    // POST /api/services/{serviceId}/task-templates
    public function create($serviceId): ResponseInterface
    {
        $serviceId = (int)$serviceId;
        $db = db_connect();
        $this->ensureDefaultUserColumn();

        $service = $db->table('services')->where('id', $serviceId)->get()->getRowArray();
        if (!$service) return $this->json(false, 'Service not found', null, 404);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $name = trim((string)($data['name'] ?? ''));
        $description = $data['description'] ?? null;
        $isDefault = (int)($data['is_default'] ?? 0);
        $defaultUserId = array_key_exists('default_assigned_user_id', $data) ? $data['default_assigned_user_id'] : null;
        $defaultUserId = ($defaultUserId === '' || $defaultUserId === null) ? null : (int)$defaultUserId;

        $errors = [];
        if ($name === '') $errors['name'] = 'Name is required';

        if (!empty($errors)) return $this->json(false, 'Validation failed', null, 422, $errors);

        $now = date('Y-m-d H:i:s');

        $db->transBegin();

        // if is_default=1 => unset others
        if ($isDefault === 1) {
            $db->table('service_task_templates')
                ->where('service_id', $serviceId)
                ->set(['is_default' => 0, 'updated_at' => $now])
                ->update();
        }

        $db->table('service_task_templates')->insert([
            'service_id'               => $serviceId,
            'name'                     => $name,
            'description'              => $description,
            'default_assigned_user_id' => $defaultUserId,
            'is_default'               => $isDefault ? 1 : 0,
            'status'                   => 1,
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->json(false, 'Failed to create task template', null, 500);
        }

        $newId = (int)$db->insertID();
        $db->transCommit();

        $row = $db->table('service_task_templates stt')
            ->select('stt.*, u.name as default_user_name, u.email as default_user_email')
            ->join('users u', 'u.id = stt.default_assigned_user_id', 'left')
            ->where('stt.id', $newId)
            ->get()
            ->getRowArray();
        return $this->json(true, 'Task template created', $row, 201);
    }

    // PUT/PATCH /api/services/task-templates/{id}
    public function update($id): ResponseInterface
    {
        $id = (int)$id;
        $db = db_connect();
        $this->ensureDefaultUserColumn();

        $existing = $db->table('service_task_templates')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Task template not found', null, 404);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getRawInput();
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $name = array_key_exists('name', $data) ? trim((string)$data['name']) : $existing['name'];
        $description = array_key_exists('description', $data) ? $data['description'] : $existing['description'];
        $isDefault = array_key_exists('is_default', $data) ? (int)$data['is_default'] : (int)$existing['is_default'];
        $status = array_key_exists('status', $data) ? (int)$data['status'] : (int)$existing['status'];
        $defaultUserId = array_key_exists('default_assigned_user_id', $data) ? $data['default_assigned_user_id'] : ($existing['default_assigned_user_id'] ?? null);
        $defaultUserId = ($defaultUserId === '' || $defaultUserId === null) ? null : (int)$defaultUserId;

        $errors = [];
        if ($name === '') $errors['name'] = 'Name is required';
        if (!empty($errors)) return $this->json(false, 'Validation failed', null, 422, $errors);

        $now = date('Y-m-d H:i:s');

        $db->transBegin();

        if ($isDefault === 1) {
            $db->table('service_task_templates')
                ->where('service_id', (int)$existing['service_id'])
                ->set(['is_default' => 0, 'updated_at' => $now])
                ->update();
        }

        $db->table('service_task_templates')->where('id', $id)->update([
            'name'                     => $name,
            'description'              => $description,
            'default_assigned_user_id' => $defaultUserId,
            'is_default'               => $isDefault ? 1 : 0,
            'status'                   => $status ? 1 : 0,
            'updated_at'               => $now,
        ]);

        if ($db->transStatus() === false) {
            $db->transRollback();
            return $this->json(false, 'Failed to update task template', null, 500);
        }

        $db->transCommit();

        $row = $db->table('service_task_templates stt')
            ->select('stt.*, u.name as default_user_name, u.email as default_user_email')
            ->join('users u', 'u.id = stt.default_assigned_user_id', 'left')
            ->where('stt.id', $id)
            ->get()
            ->getRowArray();
        return $this->json(true, 'Task template updated', $row);
    }

    // DELETE /api/services/task-templates/{id} (soft delete)
    public function delete($id): ResponseInterface
    {
        $id = (int)$id;
        $db = db_connect();

        $existing = $db->table('service_task_templates')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Task template not found', null, 404);

        $now = date('Y-m-d H:i:s');

        // soft delete template
        $db->table('service_task_templates')->where('id', $id)->update([
            'status'     => 0,
            'is_default' => 0,
            'updated_at' => $now,
        ]);

        // soft delete items
        $db->table('service_task_template_items')->where('template_id', $id)->update([
            'status'     => 0,
            'updated_at' => $now,
        ]);

        return $this->json(true, 'Task template deleted');
    }
}
