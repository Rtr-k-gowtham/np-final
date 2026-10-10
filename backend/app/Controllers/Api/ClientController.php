<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ClientModel;

class ClientController extends BaseController
{
    protected ClientModel $model;

    public function __construct()
    {
        $this->model = new ClientModel();
    }

    public function index()
    {
        $db = \Config\Database::connect();
        $search  = trim((string) $this->request->getGet('search'));
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = (int) ($this->request->getGet('perPage') ?? 10);
        $offset  = ($page - 1) * $perPage;

        // Base where clause to hide soft-deleted (inactive) clients
        $whereClause = "IFNULL(status, '') != 'inactive'";
        
        if ($search !== '') {
            $escaped = $db->escapeLikeString($search);
            $whereClause .= " AND (`name` LIKE '%{$escaped}%' 
                               OR `email` LIKE '%{$escaped}%' 
                               OR `phone` LIKE '%{$escaped}%' 
                               OR `city` LIKE '%{$escaped}%' 
                               OR `state` LIKE '%{$escaped}%' 
                               OR `reference_source` LIKE '%{$escaped}%')";
        }

        // 1. Get Total Count
        $countQuery = $db->query("SELECT COUNT(*) AS total FROM clients WHERE {$whereClause}");
        $total = (int)$countQuery->getRow()->total;

        // 2. Get Data
        $dataQuery = $db->query("SELECT * FROM clients WHERE {$whereClause} ORDER BY id DESC LIMIT {$offset}, {$perPage}");
        $rows = $dataQuery->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data'   => $rows,
            'meta'   => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function datatables()
    {
        $db = \Config\Database::connect();
        $draw   = (int) $this->request->getGet('draw');
        $start  = (int) $this->request->getGet('start');
        $length = (int) $this->request->getGet('length');
        $search = $this->request->getGet('search')['value'] ?? '';

        // Base where clause to hide soft-deleted (inactive) clients
        $whereClause = "IFNULL(status, '') != 'inactive'";
        
        if (!empty($search)) {
            $escaped = $db->escapeLikeString($search);
            $whereClause .= " AND (`name` LIKE '%{$escaped}%' 
                               OR `email` LIKE '%{$escaped}%' 
                               OR `phone` LIKE '%{$escaped}%' 
                               OR `city` LIKE '%{$escaped}%' 
                               OR `state` LIKE '%{$escaped}%' 
                               OR `reference_source` LIKE '%{$escaped}%')";
        }

        $totalQuery = $db->query("SELECT COUNT(*) AS total FROM clients WHERE IFNULL(status, '') != 'inactive'");
        $recordsTotal = (int)$totalQuery->getRow()->total;

        $filteredQuery = $db->query("SELECT COUNT(*) AS total FROM clients WHERE {$whereClause}");
        $recordsFiltered = (int)$filteredQuery->getRow()->total;

        $dataQuery = $db->query("SELECT * FROM clients WHERE {$whereClause} ORDER BY id DESC LIMIT {$start}, {$length}");
        $data = $dataQuery->getResultArray();

        return $this->response->setJSON([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data
        ]);
    }

    public function show($id = null)
    {
        $row = $this->model->find((int)$id);
        
        // Hide inactive records if accessed directly
        if (!$row || (isset($row['status']) && $row['status'] === 'inactive')) {
            return $this->response->setStatusCode(404)->setJSON(['status'=>'error','message'=>'Not found']);
        }
        
        return $this->response->setJSON(['status'=>'success','data'=>$row]);
    }

    public function create()
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $data = [
            'name'             => trim((string)($payload['name'] ?? '')),
            'email'            => trim((string)($payload['email'] ?? '')),
            'phone'            => trim((string)($payload['phone'] ?? '')),
            'address'          => (string)($payload['address'] ?? ''),
            'city'             => trim((string)($payload['city'] ?? '')),
            'state'            => trim((string)($payload['state'] ?? '')),
            'reference_source' => trim((string)($payload['reference_source'] ?? $payload['reference source'] ?? '')),
            'status'           => 'active'
        ];
        
        $db = \Config\Database::connect();
        $builder = $db->table('clients');
        
        if (!$builder->insert($data)) {
            return $this->response->setStatusCode(422)->setJSON(['status'=>'error','message'=>'Insert failed']);
        }
        
        $newId = $db->insertID();
        return $this->response->setStatusCode(201)->setJSON(['status'=>'success','data'=>$this->model->find($newId)]);
    }

    public function update($id = null)
    {
        if (!$this->model->find($id)) {
            return $this->response->setStatusCode(404)->setJSON(['status'=>'error','message'=>'Not found']);
        }
        
        $payload = $this->request->getJSON(true) ?: $this->request->getRawInput();
        $data = [];
        
        foreach (['name','email','phone','address','city','state','reference_source','status'] as $f) {
            if (array_key_exists($f, $payload)) {
                $data[$f] = $payload[$f];
            } elseif ($f === 'reference_source' && array_key_exists('reference source', $payload)) {
                $data[$f] = $payload['reference source']; 
            }
        }
        
        if (empty($data)) {
            return $this->response->setStatusCode(400)->setJSON(['status'=>'error','message'=>'No fields']);
        }
        
        // Use Query Builder directly to bypass the Model's allowedFields strictness
        $db = \Config\Database::connect();
        $db->table('clients')->where('id', $id)->update($data);
        
        return $this->response->setJSON(['status'=>'success','data'=>$this->model->find($id)]);
    }

    public function delete($id = null)
    {
        // Check if client exists
        if (!$this->model->find($id)) {
            return $this->response->setStatusCode(404)->setJSON(['status'=>'error','message'=>'Not found']);
        }
        
        // Use Query Builder directly to ensure 'status' is updated without Model restrictions
        $db = \Config\Database::connect();
        $db->table('clients')->where('id', $id)->update(['status' => 'inactive']);
        
        return $this->response->setJSON(['status'=>'success','message'=>'Client softly deleted (inactive)']);
    }
}
