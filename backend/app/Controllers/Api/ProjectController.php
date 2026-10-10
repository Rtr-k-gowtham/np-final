<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProjectModel;

class ProjectController extends BaseController
{
    /**
     * GET /api/projects
     */
    public function index()
    {
        $model = new ProjectModel();

        $rows = $model->orderBy('id', 'DESC')->findAll(500);

        // Eagerly attach tasks for all projects in a single query
        $db = db_connect();
        $pIds = array_column($rows, 'id');
        $tasksByProject = [];

        if (!empty($pIds)) {
            $tasks = $db->table('project_tasks pt')
                ->select('pt.*, u.name as assigned_user_name, u.email as assigned_user_email')
                ->join('users u', 'u.id = pt.assigned_to', 'left')
                ->whereIn('pt.project_id', $pIds)
                ->whereIn('pt.status', ['pending', 'in_progress', 'done', 'canceled'])
                ->orderBy('pt.sort_order', 'ASC')
                ->orderBy('pt.id', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($tasks as $t) {
                $tasksByProject[$t['project_id']][] = $t;
            }
        }

        foreach ($rows as &$r) {
            $r['tasks'] = $tasksByProject[$r['id']] ?? [];
        }

        return $this->response->setJSON([
            'success' => true,
            'data' => $rows
        ]);
    }

    /**
     * GET /api/projects/{id}
     */
    public function show($id)
    {
        $id = (int)$id;

        $model = new ProjectModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Project not found'
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'data' => $row
        ]);
    }

    /**
     * POST /api/projects
     * Body:
     * {
     *   "service_id": 1,
     *   "project_title": "Panchayat Building Approval",
     *   "description": "Test",
     *   "client_id": 1,
     *   "applicant_id": 1,
     *   "assigned_agent_id": 2
     * }
     */
    public function create()
{
    $data = $this->request->getJSON(true) ?? [];

    $createdBy = (int)($_SERVER['HTTP_X_USER_ID'] ?? 0);
    if ($createdBy <= 0) {
        return $this->response->setStatusCode(401)->setJSON([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
    }

    $payload = [
        'service_id'         => (int)($data['service_id'] ?? 0),
        'project_title'      => trim((string)($data['project_title'] ?? '')),
        'description'        => $data['description'] ?? null,
        'status'             => $data['status'] ?? 'open',
        'client_id'          => !empty($data['client_id']) ? (int)$data['client_id'] : null,
        'applicant_id'       => !empty($data['applicant_id']) ? (int)$data['applicant_id'] : null,
        'assigned_agent_id'  => !empty($data['assigned_agent_id']) ? (int)$data['assigned_agent_id'] : null,
        'created_by_user_id' => $createdBy,
    ];

    $payload['project_code'] = trim((string)($data['project_code'] ?? ''));
    if ($payload['project_code'] === '') {
        $payload['project_code'] = 'PRJ-' . date('Ymd-His');
    }

    $db = db_connect();
    try {
        $existingCols = $db->getFieldNames('projects');
        $forge = \Config\Database::forge();
        if (!in_array('start_date', $existingCols)) {
            $forge->addColumn('projects', [
                'start_date' => ['type' => 'DATE', 'null' => true]
            ]);
        }
        if (!in_array('deadline', $existingCols)) {
            $forge->addColumn('projects', [
                'deadline' => ['type' => 'DATE', 'null' => true]
            ]);
        }
    } catch (\Throwable $e) {}

    $startDate = !empty($data['start_date']) ? $data['start_date'] : (!empty($data['opening_date']) ? $data['opening_date'] : date('Y-m-d'));
    $deadline = !empty($data['deadline']) ? $data['deadline'] : (!empty($data['due_date']) ? $data['due_date'] : null);

    try {
        $cols = $db->getFieldNames('projects');
        if (in_array('start_date', $cols)) $payload['start_date'] = $startDate;
        if (in_array('opening_date', $cols)) $payload['opening_date'] = $startDate;
        if (in_array('deadline', $cols)) $payload['deadline'] = $deadline;
        if (in_array('due_date', $cols)) $payload['due_date'] = $deadline;
    } catch (\Throwable $e) {}

    $model = new \App\Models\ProjectModel();

    if (!$model->insert($payload)) {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'Validation failed',
            'errors'  => $model->errors(),
        ]);
    }

    $projectId = (int)$model->getInsertID();

    return $this->response->setStatusCode(201)->setJSON([
        'success' => true,
        'message' => 'Project created',
        'data'    => [
            'project_id' => $projectId,
            'project'    => $model->find($projectId),
        ],
    ]);
}


    /**
     * PUT /api/projects/{id}
     */
    public function update($id)
    {
        $id = (int)$id;

        $model = new ProjectModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Project not found'
            ]);
        }

        $data = $this->request->getJSON(true) ?? [];

        $payload = [];
        foreach ($model->allowedFields as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = $data[$f];
            }
        }

        if (isset($payload['project_title'])) {
            $payload['project_title'] = trim((string)$payload['project_title']);
        }

        // Support opening_date and due_date aliases
        if (!empty($data['opening_date']) && empty($payload['start_date'])) {
            $payload['start_date'] = $data['opening_date'];
        }
        if (!empty($data['due_date']) && empty($payload['deadline'])) {
            $payload['deadline'] = $data['due_date'];
        }

        $db = db_connect();
        try {
            $cols = $db->getFieldNames('projects');
            if (isset($payload['start_date']) && !in_array('start_date', $cols)) {
                unset($payload['start_date']);
            }
            if (isset($payload['deadline']) && !in_array('deadline', $cols)) {
                unset($payload['deadline']);
            }
        } catch (\Throwable $e) {}

        // Do NOT allow changing created_by_user_id via update
        unset($payload['created_by_user_id']);

        if (!$model->update($id, $payload)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Update failed',
                'errors'  => $model->errors()
            ]);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Project updated',
            'data'    => $model->find($id)
        ]);
    }

    /**
     * DELETE /api/projects/{id}
     */
    public function delete($id)
    {
        $id = (int)$id;

        $model = new ProjectModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'Project not found'
            ]);
        }

        $model->delete($id);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Project deleted'
        ]);
    }
	
	public function saveDocuments($projectId)
{
    $projectId = (int)$projectId;

    $createdBy = (int)($_SERVER['HTTP_X_USER_ID'] ?? 0);
    if ($createdBy <= 0) {
        return $this->response->setStatusCode(401)->setJSON([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
    }

    $data = $this->request->getJSON(true) ?? [];

    $templateId  = (int)($data['doc_template_id'] ?? 0);
    $selectedIds = $data['selected_item_ids'] ?? [];

    if ($projectId <= 0 || $templateId <= 0 || !is_array($selectedIds)) {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'Invalid payload'
        ]);
    }

    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds))));
    if (empty($selectedIds)) {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'Select at least one document'
        ]);
    }

    $db = \Config\Database::connect();

    // Ensure project exists
    $project = $db->table('projects')
        ->select('id')
        ->where('id', $projectId)
        ->get()
        ->getRowArray();

    if (!$project) {
        return $this->response->setStatusCode(404)->setJSON([
            'success' => false,
            'message' => 'Project not found'
        ]);
    }

    $db->transStart();

    // Replace old selections (if user back and re-save)
    $db->table('project_documents')
        ->where('project_id', $projectId)
        ->delete();

    // Copy selected template items into project_documents
    $items = $db->table('service_doc_template_items')
        ->select('id,item_no,document_title,description,copies_required,is_mandatory,provided_by')
        ->where('doc_template_id', $templateId)
        ->whereIn('id', $selectedIds)
        ->orderBy('sort_order', 'ASC')
        ->orderBy('item_no', 'ASC')
        ->get()
        ->getResultArray();

    $batch = [];
    foreach ($items as $it) {
        $batch[] = [
            'project_id'                   => $projectId,
            'service_doc_template_item_id' => (int)$it['id'],
            'item_no'                      => (int)$it['item_no'],
            'document_title'               => (string)$it['document_title'],
            'description'                  => $it['description'] ?? null,
            'copies_required'              => (int)$it['copies_required'],
            'is_mandatory'                 => (int)$it['is_mandatory'],
            'provided_by'                  => (string)$it['provided_by'], // client/northpoint
            'status'                       => 'pending',
            'received_date'                => null,
            'verified_by'                  => null,
            'verified_date'                => null,
            'remarks'                      => null,
        ];
    }

    if (!empty($batch)) {
        $db->table('project_documents')->insertBatch($batch);
    }

    $db->transComplete();

    if ($db->transStatus() === false) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'Failed to save checklist'
        ]);
    }

    return $this->response->setJSON([
        'success' => true,
        'message' => 'Checklist saved',
        'data'    => [
            'project_id' => $projectId,
            'inserted'   => count($batch),
        ]
    ]);
}

}
