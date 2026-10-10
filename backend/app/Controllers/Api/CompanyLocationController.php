<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class CompanyLocationController extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = db_connect();
        $this->ensureTablesExist();
    }

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
        if (is_array($req) && !empty($req['company_id'])) {
            return (int)$req['company_id'];
        }

        $param = (int)$this->request->getGet('company_id');
        return $param > 0 ? $param : 1;
    }

    /**
     * Auto-create required tables if not already created
     */
    private function ensureTablesExist(): void
    {
        try {
            $this->db->query("CREATE TABLE IF NOT EXISTS `company_locations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `company_id` INT NOT NULL DEFAULT 1,
                `name` VARCHAR(150) NOT NULL DEFAULT 'Headquarters / Main Office',
                `address` TEXT NULL,
                `latitude` DECIMAL(10, 8) NOT NULL,
                `longitude` DECIMAL(11, 8) NOT NULL,
                `radius_meters` INT NOT NULL DEFAULT 100,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                INDEX (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->db->query("CREATE TABLE IF NOT EXISTS `user_login_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `employee_id` INT NULL,
                `latitude` DECIMAL(10, 8) NULL,
                `longitude` DECIMAL(11, 8) NULL,
                `distance_meters` DECIMAL(10, 2) NULL,
                `work_mode` VARCHAR(50) DEFAULT 'company',
                `status` VARCHAR(20) DEFAULT 'success',
                `ip_address` VARCHAR(45) NULL,
                `created_at` DATETIME NULL,
                INDEX (`user_id`),
                INDEX (`employee_id`),
                INDEX (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Ensure work_mode exists on employees table
            $columns = $this->db->getFieldNames('employees');
            if (!in_array('work_mode', $columns, true)) {
                $this->db->query("ALTER TABLE `employees` ADD COLUMN `work_mode` VARCHAR(50) NOT NULL DEFAULT 'company' AFTER `status`;");
            }

            // Ensure latitude and longitude exist on attendance table
            $attColumns = $this->db->getFieldNames('attendance');
            if (!in_array('latitude', $attColumns, true)) {
                $this->db->query("ALTER TABLE `attendance` ADD COLUMN `latitude` DECIMAL(10, 8) NULL AFTER `remark`;");
            }
            if (!in_array('longitude', $attColumns, true)) {
                $this->db->query("ALTER TABLE `attendance` ADD COLUMN `longitude` DECIMAL(11, 8) NULL AFTER `latitude`;");
            }
        } catch (\Throwable $e) {
            log_message('error', 'ensureTablesExist error: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/company-location
     */
    public function show(): ResponseInterface
    {
        $companyId = $this->companyId();

        $loc = $this->db->table('company_locations')
            ->where('company_id', $companyId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if (!$loc) {
            // Default placeholder location (100m radius)
            $loc = [
                'id'            => 0,
                'company_id'    => $companyId,
                'name'          => 'Company Office',
                'address'       => 'Please configure company office address',
                'latitude'      => 12.9716,
                'longitude'     => 77.5946,
                'radius_meters' => 100,
                'is_configured' => false,
            ];
        } else {
            $loc['latitude']      = (float)$loc['latitude'];
            $loc['longitude']     = (float)$loc['longitude'];
            $loc['radius_meters'] = (int)$loc['radius_meters'];
            $loc['is_configured'] = true;
        }

        return $this->json(true, 'Company location fetched', $loc);
    }

    /**
     * POST /api/company-location
     */
    public function save(): ResponseInterface
    {
        $companyId = $this->companyId();
        $payload   = $this->request->getJSON(true) ?? $this->request->getPost() ?? [];

        $name   = trim((string)($payload['name'] ?? 'Company Office'));
        $addr   = trim((string)($payload['address'] ?? ''));
        $lat    = isset($payload['latitude']) ? (float)$payload['latitude'] : null;
        $lng    = isset($payload['longitude']) ? (float)$payload['longitude'] : null;
        $radius = isset($payload['radius_meters']) ? max(10, (int)$payload['radius_meters']) : 100;

        if ($lat === null || $lng === null) {
            return $this->json(false, 'Latitude and longitude are required', null, 422);
        }

        $now = date('Y-m-d H:i:s');

        $existing = $this->db->table('company_locations')
            ->where('company_id', $companyId)
            ->get()
            ->getRowArray();

        $saveData = [
            'company_id'    => $companyId,
            'name'          => $name !== '' ? $name : 'Company Office',
            'address'       => $addr,
            'latitude'      => $lat,
            'longitude'     => $lng,
            'radius_meters' => $radius,
            'updated_at'    => $now,
        ];

        if ($existing) {
            $this->db->table('company_locations')
                ->where('id', (int)$existing['id'])
                ->update($saveData);
            $id = (int)$existing['id'];
        } else {
            $saveData['created_at'] = $now;
            $this->db->table('company_locations')->insert($saveData);
            $id = (int)$this->db->insertID();
        }

        $saved = $this->db->table('company_locations')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        $saved['latitude']      = (float)$saved['latitude'];
        $saved['longitude']     = (float)$saved['longitude'];
        $saved['radius_meters'] = (int)$saved['radius_meters'];
        $saved['is_configured'] = true;

        return $this->json(true, 'Company location saved successfully', $saved);
    }

    /**
     * GET /api/attendance/map-locations?date=YYYY-MM-DD
     * Returns today's (or filtered date's) logins and attendance coordinates for the interactive map
     */
    public function mapLocations(): ResponseInterface
    {
        $companyId = $this->companyId();
        $date = trim((string)$this->request->getGet('date'));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        // 1. Get Company Office Location
        $office = $this->db->table('company_locations')
            ->where('company_id', $companyId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        $officeConfig = null;
        if ($office) {
            $officeConfig = [
                'id'            => (int)$office['id'],
                'name'          => $office['name'],
                'address'       => $office['address'],
                'latitude'      => (float)$office['latitude'],
                'longitude'     => (float)$office['longitude'],
                'radius_meters' => (int)$office['radius_meters'],
            ];
        }

        // 2. Fetch logins for the given date
        $startDate = $date . ' 00:00:00';
        $endDate   = $date . ' 23:59:59';

        $logs = $this->db->table('user_login_logs l')
            ->select("
                l.id,
                l.user_id,
                l.employee_id,
                l.latitude,
                l.longitude,
                l.distance_meters,
                l.work_mode,
                l.status,
                l.ip_address,
                l.created_at as login_time,
                u.name as user_name,
                u.email as user_email,
                e.emp_code,
                e.name as employee_name,
                e.work_mode as emp_work_mode,
                r.name as role_name
            ")
            ->join('users u', 'u.id = l.user_id', 'left')
            ->join('employees e', 'e.id = l.employee_id', 'left')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->where('l.created_at >=', $startDate)
            ->where('l.created_at <=', $endDate)
            ->where('l.latitude IS NOT NULL', null, false)
            ->where('l.longitude IS NOT NULL', null, false)
            ->orderBy('l.id', 'DESC')
            ->get()
            ->getResultArray();

        // Also check attendance table for any entries with latitude/longitude
        $attLogs = $this->db->table('attendance a')
            ->select("
                a.id,
                a.emp_id as employee_id,
                a.att_date,
                a.time_in,
                a.time_out,
                a.status as att_status,
                a.latitude,
                a.longitude,
                e.name as employee_name,
                e.emp_code,
                e.work_mode as emp_work_mode
            ")
            ->join('employees e', 'e.id = a.emp_id', 'left')
            ->where('a.company_id', $companyId)
            ->where('a.att_date', $date)
            ->where('a.latitude IS NOT NULL', null, false)
            ->where('a.longitude IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        // Format and merge results
        $formatted = [];
        $seenEmployees = [];

        foreach ($logs as $log) {
            $empKey = $log['employee_id'] ? 'emp_' . $log['employee_id'] : 'usr_' . $log['user_id'];
            $lat = (float)$log['latitude'];
            $lng = (float)$log['longitude'];

            // Compute distance if office configured and distance_meters is null
            $dist = $log['distance_meters'] !== null ? (float)$log['distance_meters'] : null;
            if ($dist === null && $officeConfig) {
                $dist = self::haversineDistance($lat, $lng, $officeConfig['latitude'], $officeConfig['longitude']);
            }

            $wMode = $log['work_mode'] ?: ($log['emp_work_mode'] ?: 'company');

            $formatted[] = [
                'id'              => (int)$log['id'],
                'user_id'         => (int)$log['user_id'],
                'employee_id'     => $log['employee_id'] ? (int)$log['employee_id'] : null,
                'name'            => $log['employee_name'] ?: ($log['user_name'] ?: 'User #' . $log['user_id']),
                'emp_code'        => $log['emp_code'] ?: 'N/A',
                'role_name'       => $log['role_name'] ?: 'Staff',
                'work_mode'       => $wMode,
                'latitude'        => $lat,
                'longitude'       => $lng,
                'distance_meters' => $dist !== null ? round($dist, 1) : null,
                'is_within_range' => ($dist !== null && $officeConfig) ? ($dist <= $officeConfig['radius_meters']) : true,
                'login_time'      => $log['login_time'],
                'ip_address'      => $log['ip_address'],
                'status'          => $log['status'] ?: 'success',
            ];

            $seenEmployees[$empKey] = true;
        }

        // If attendance table has records with coordinates not yet in $formatted
        foreach ($attLogs as $att) {
            $empKey = 'emp_' . $att['employee_id'];
            if (isset($seenEmployees[$empKey])) continue;

            $lat = (float)$att['latitude'];
            $lng = (float)$att['longitude'];

            $dist = null;
            if ($officeConfig) {
                $dist = self::haversineDistance($lat, $lng, $officeConfig['latitude'], $officeConfig['longitude']);
            }

            $wMode = $att['emp_work_mode'] ?: 'company';

            $formatted[] = [
                'id'              => (int)$att['id'],
                'user_id'         => null,
                'employee_id'     => (int)$att['employee_id'],
                'name'            => $att['employee_name'] ?: 'Employee #' . $att['employee_id'],
                'emp_code'        => $att['emp_code'] ?: 'N/A',
                'role_name'       => 'Employee',
                'work_mode'       => $wMode,
                'latitude'        => $lat,
                'longitude'       => $lng,
                'distance_meters' => $dist !== null ? round($dist, 1) : null,
                'is_within_range' => ($dist !== null && $officeConfig) ? ($dist <= $officeConfig['radius_meters']) : true,
                'login_time'      => $att['att_date'] . ' ' . ($att['time_in'] ?: '09:00:00'),
                'ip_address'      => null,
                'status'          => 'present',
            ];
            $seenEmployees[$empKey] = true;
        }

        return $this->json(true, 'Map locations fetched', [
            'office'    => $officeConfig,
            'date'      => $date,
            'locations' => $formatted,
            'total'     => count($formatted),
        ]);
    }

    /**
     * Haversine formula to compute distance in meters between two lat/lng coordinates
     */
    public static function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
