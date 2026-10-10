<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class ServiceTaskTemplateItemController extends BaseController
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
            $fields = $db->getFieldNames('service_task_template_items');
            if (!in_array('default_assigned_user_id', $fields, true)) {
                $db->query("ALTER TABLE `service_task_template_items` ADD COLUMN `default_assigned_user_id` INT NULL DEFAULT NULL AFTER `default_assignee_role_id`");
            }
            if (!in_array('enable_whatsapp_msg', $fields, true)) {
                $db->query("ALTER TABLE `service_task_template_items` ADD COLUMN `enable_whatsapp_msg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_milestone`");
            }
            if (!in_array('whatsapp_message', $fields, true)) {
                $db->query("ALTER TABLE `service_task_template_items` ADD COLUMN `whatsapp_message` TEXT NULL DEFAULT NULL AFTER `enable_whatsapp_msg`");
            }
            if (!in_array('subtasks_json', $fields, true)) {
                $db->query("ALTER TABLE `service_task_template_items` ADD COLUMN `subtasks_json` JSON NULL AFTER `whatsapp_message`");
            }
        } catch (\Throwable $e) {
            // Silently ignore if table altered or permission restricted
        }
    }

    // GET /api/task-templates/{templateId}/items
    public function index($templateId): ResponseInterface
    {
        $templateId = (int)$templateId;
        $db = db_connect();
        $this->ensureDefaultUserColumn();

        $tpl = $db->table('service_task_templates')->where('id', $templateId)->get()->getRowArray();
        if (!$tpl) return $this->json(false, 'Task template not found', null, 404);

        $builder = $db->table('service_task_template_items stti');
        $builder->select('stti.*, u.name as default_user_name, u.email as default_user_email');
        $builder->join('users u', 'u.id = stti.default_assigned_user_id', 'left');
        $builder->where('stti.template_id', $templateId);
        $builder->where('stti.status', 1);
        $builder->orderBy('stti.sort_order', 'ASC');
        $builder->orderBy('stti.id', 'ASC');
        $rows = $builder->get()->getResultArray();

        $rows = array_map(function ($r) {
            $r['enable_whatsapp_msg'] = !empty($r['enable_whatsapp_msg']) ? 1 : 0;
            $r['is_mandatory'] = (int)($r['is_mandatory'] ?? 1);
            $r['is_milestone'] = (int)($r['is_milestone'] ?? 0);
            return $r;
        }, $rows);

        return $this->json(true, 'OK', $rows);
    }

    // POST /api/task-templates/{templateId}/items
    public function create($templateId): ResponseInterface
    {
        $templateId = (int)$templateId;
        $db = db_connect();
        $this->ensureDefaultUserColumn();

        $tpl = $db->table('service_task_templates')->where('id', $templateId)->get()->getRowArray();
        if (!$tpl) return $this->json(false, 'Task template not found', null, 404);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $taskName = trim((string)($data['task_name'] ?? ''));
        $taskDesc = $data['task_description'] ?? null;
        $sortOrder = (int)($data['sort_order'] ?? 1);

        $defaultDueDays = array_key_exists('default_due_days', $data) ? $data['default_due_days'] : null;
        $defaultDueDays = ($defaultDueDays === '' || $defaultDueDays === null) ? null : (int)$defaultDueDays;

        $defaultRoleId = array_key_exists('default_assignee_role_id', $data) ? $data['default_assignee_role_id'] : null;
        $defaultRoleId = ($defaultRoleId === '' || $defaultRoleId === null) ? null : (int)$defaultRoleId;

        $defaultUserId = array_key_exists('default_assigned_user_id', $data) ? $data['default_assigned_user_id'] : null;
        $defaultUserId = ($defaultUserId === '' || $defaultUserId === null) ? null : (int)$defaultUserId;

        $isMandatory = (int)($data['is_mandatory'] ?? 1);
        $isMilestone = (int)($data['is_milestone'] ?? 0);
        $enableWhatsApp = (int)($data['enable_whatsapp_msg'] ?? 0);
        $whatsAppMessage = array_key_exists('whatsapp_message', $data) ? $data['whatsapp_message'] : null;
        $whatsAppMessage = ($whatsAppMessage === '' || $whatsAppMessage === null) ? null : trim((string)$whatsAppMessage);

        $dependsOn = array_key_exists('depends_on_template_item_id', $data) ? $data['depends_on_template_item_id'] : null;
        $dependsOn = ($dependsOn === '' || $dependsOn === null) ? null : (int)$dependsOn;

        $subtasksJson = array_key_exists('subtasks', $data) && is_array($data['subtasks']) ? json_encode($data['subtasks']) : null;

        $errors = [];
        if ($taskName === '') $errors['task_name'] = 'Task name is required';

        // dependency must be same template
        if ($dependsOn !== null) {
            $dep = $db->table('service_task_template_items')->where('id', $dependsOn)->get()->getRowArray();
            if (!$dep) $errors['depends_on_template_item_id'] = 'Dependency item not found';
            else if ((int)$dep['template_id'] !== $templateId) $errors['depends_on_template_item_id'] = 'Dependency must be in same template';
        }

        if (!empty($errors)) return $this->json(false, 'Validation failed', null, 422, $errors);

        $now = date('Y-m-d H:i:s');

        $db->table('service_task_template_items')->insert([
            'template_id'                 => $templateId,
            'task_name'                   => $taskName,
            'task_description'            => $taskDesc,
            'sort_order'                  => $sortOrder,
            'default_due_days'            => $defaultDueDays,
            'default_assignee_role_id'    => $defaultRoleId,
            'default_assigned_user_id'    => $defaultUserId,
            'is_mandatory'                => $isMandatory ? 1 : 0,
            'is_milestone'                => $isMilestone ? 1 : 0,
            'enable_whatsapp_msg'         => $enableWhatsApp ? 1 : 0,
            'whatsapp_message'            => $whatsAppMessage,
            'depends_on_template_item_id' => $dependsOn,
            'subtasks_json'               => $subtasksJson,
            'status'                      => 1,
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ]);

        $newId = (int)$db->insertID();
        $row = $db->table('service_task_template_items stti')
            ->select('stti.*, u.name as default_user_name, u.email as default_user_email')
            ->join('users u', 'u.id = stti.default_assigned_user_id', 'left')
            ->where('stti.id', $newId)
            ->get()
            ->getRowArray();

        return $this->json(true, 'Task item created', $row, 201);
    }

    // PUT/PATCH /api/task-template-items/{id}
    public function update($id): ResponseInterface
    {
        $id = (int)$id;
        $db = db_connect();
        $this->ensureDefaultUserColumn();

        $existing = $db->table('service_task_template_items')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Task item not found', null, 404);

        $data = $this->request->getJSON(true);
        if (!is_array($data) || empty($data)) $data = $this->request->getRawInput();
        if (!is_array($data) || empty($data)) $data = $this->request->getPost();
        if (!is_array($data)) $data = [];

        $templateId = (int)$existing['template_id'];

        $taskName = array_key_exists('task_name', $data) ? trim((string)$data['task_name']) : $existing['task_name'];
        $taskDesc = array_key_exists('task_description', $data) ? $data['task_description'] : $existing['task_description'];
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)$existing['sort_order'];

        $defaultDueDays = array_key_exists('default_due_days', $data) ? $data['default_due_days'] : $existing['default_due_days'];
        $defaultDueDays = ($defaultDueDays === '' || $defaultDueDays === null) ? null : (int)$defaultDueDays;

        $defaultRoleId = array_key_exists('default_assignee_role_id', $data) ? $data['default_assignee_role_id'] : ($existing['default_assignee_role_id'] ?? null);
        $defaultRoleId = ($defaultRoleId === '' || $defaultRoleId === null) ? null : (int)$defaultRoleId;

        $defaultUserId = array_key_exists('default_assigned_user_id', $data) ? $data['default_assigned_user_id'] : ($existing['default_assigned_user_id'] ?? null);
        $defaultUserId = ($defaultUserId === '' || $defaultUserId === null) ? null : (int)$defaultUserId;

        $isMandatory = array_key_exists('is_mandatory', $data) ? (int)$data['is_mandatory'] : (int)$existing['is_mandatory'];
        $isMilestone = array_key_exists('is_milestone', $data) ? (int)$data['is_milestone'] : (int)$existing['is_milestone'];

        $enableWhatsApp = array_key_exists('enable_whatsapp_msg', $data) ? (int)$data['enable_whatsapp_msg'] : (int)($existing['enable_whatsapp_msg'] ?? 0);
        $whatsAppMessage = array_key_exists('whatsapp_message', $data) ? $data['whatsapp_message'] : ($existing['whatsapp_message'] ?? null);
        $whatsAppMessage = ($whatsAppMessage === '' || $whatsAppMessage === null) ? null : trim((string)$whatsAppMessage);

        $dependsOn = array_key_exists('depends_on_template_item_id', $data) ? $data['depends_on_template_item_id'] : $existing['depends_on_template_item_id'];
        $dependsOn = ($dependsOn === '' || $dependsOn === null) ? null : (int)$dependsOn;

        $status = array_key_exists('status', $data) ? (int)$data['status'] : (int)$existing['status'];

        $errors = [];
        if ($taskName === '') $errors['task_name'] = 'Task name is required';

        if ($dependsOn !== null) {
            if ($dependsOn === $id) {
                $errors['depends_on_template_item_id'] = 'Item cannot depend on itself';
            } else {
                $dep = $db->table('service_task_template_items')->where('id', $dependsOn)->get()->getRowArray();
                if (!$dep) $errors['depends_on_template_item_id'] = 'Dependency item not found';
                else if ((int)$dep['template_id'] !== $templateId) $errors['depends_on_template_item_id'] = 'Dependency must be in same template';
            }
        }

        if (!empty($errors)) return $this->json(false, 'Validation failed', null, 422, $errors);

        $now = date('Y-m-d H:i:s');

        $db->table('service_task_template_items')->where('id', $id)->update([
            'task_name'                   => $taskName,
            'task_description'            => $taskDesc,
            'sort_order'                  => $sortOrder,
            'default_due_days'            => $defaultDueDays,
            'default_assignee_role_id'    => $defaultRoleId,
            'default_assigned_user_id'    => $defaultUserId,
            'is_mandatory'                => $isMandatory ? 1 : 0,
            'is_milestone'                => $isMilestone ? 1 : 0,
            'enable_whatsapp_msg'         => $enableWhatsApp ? 1 : 0,
            'whatsapp_message'            => $whatsAppMessage,
            'depends_on_template_item_id' => $dependsOn,
            'subtasks_json'               => array_key_exists('subtasks', $data) ? (is_array($data['subtasks']) ? json_encode($data['subtasks']) : null) : ($existing['subtasks_json'] ?? null),
            'status'                      => $status ? 1 : 0,
            'updated_at'                  => $now,
        ]);

        $row = $db->table('service_task_template_items stti')
            ->select('stti.*, u.name as default_user_name, u.email as default_user_email')
            ->join('users u', 'u.id = stti.default_assigned_user_id', 'left')
            ->where('stti.id', $id)
            ->get()
            ->getRowArray();
        return $this->json(true, 'Task item updated', $row);
    }

    // DELETE /api/task-template-items/{id} (soft delete)
    public function delete($id): ResponseInterface
    {
        $id = (int)$id;
        $db = db_connect();

        $existing = $db->table('service_task_template_items')->where('id', $id)->get()->getRowArray();
        if (!$existing) return $this->json(false, 'Task item not found', null, 404);

        $now = date('Y-m-d H:i:s');

        $db->table('service_task_template_items')->where('id', $id)->update([
            'status'     => 0,
            'updated_at' => $now,
        ]);

        // clear dependencies referencing this item
        $db->table('service_task_template_items')
            ->where('depends_on_template_item_id', $id)
            ->update(['depends_on_template_item_id' => null, 'updated_at' => $now]);

        return $this->json(true, 'Task item deleted');
    }
}
