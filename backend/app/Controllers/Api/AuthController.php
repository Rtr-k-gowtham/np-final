<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Libraries\JwtService;

class AuthController extends BaseController
{
    /**
     * POST /api/auth/login
     * Body: { "email": "...", "password": "..." }
     * Sets HttpOnly cookie: npcrm_token
     */
    public function login()
    {
        $data = $this->request->getJSON(true) ?? [];

        $email    = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'Email and password are required',
                'data'    => null,
                'errors'  => [
                    'email'    => $email === '' ? 'Email is required' : null,
                    'password' => $password === '' ? 'Password is required' : null,
                ],
            ]);
        }

        $userModel = new UserModel();
        $user      = $userModel->findActiveByEmail($email);

        if (!$user) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Invalid credentials',
                'data'    => null,
                'errors'  => null,
            ]);
        }

        if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Invalid credentials',
                'data'    => null,
                'errors'  => null,
            ]);
        }

        // Record successful login in user_login_logs (GPS lock removed - users can log in from anywhere)
        try {
            $db = \Config\Database::connect();
            $employeeId = (int)($user['employee_id'] ?? 0);
            $lat = isset($data['latitude']) ? (float)$data['latitude'] : (isset($data['lat']) ? (float)$data['lat'] : null);
            $lng = isset($data['longitude']) ? (float)$data['longitude'] : (isset($data['lng']) ? (float)$data['lng'] : null);

            $workMode = 'company';
            if ($employeeId > 0) {
                $employee = $db->table('employees')->where('id', $employeeId)->get()->getRowArray();
                if ($employee && !empty($employee['work_mode'])) {
                    $workMode = strtolower(trim((string)$employee['work_mode']));
                }
            }

            $db->table('user_login_logs')->insert([
                'user_id'         => (int)$user['id'],
                'employee_id'     => $employeeId > 0 ? $employeeId : null,
                'latitude'        => $lat,
                'longitude'       => $lng,
                'distance_meters' => null,
                'work_mode'       => $workMode,
                'status'          => 'success',
                'ip_address'      => $this->request->getIPAddress(),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Failed to insert login log: ' . $e->getMessage());
        }

        $now = time();
        $ttl = JwtService::ttlSeconds();

        $payload = [
            'iss'       => 'npcrm',
            'iat'       => $now,
            'exp'       => $now + $ttl,
            'sub'       => (int) $user['id'],
            'role_id'   => (int) $user['role_id'],
            'role_code' => (string)($user['role_code'] ?? ''),
            'email'     => (string)($user['email'] ?? ''),
        ];

        $token = JwtService::encode($payload);

        // Cookie config: works for HTTPS server and local HTTP
        $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $sameSite = $secure ? 'None' : 'Lax';

        setcookie('npcrm_token', $token, [
            'expires'  => $now + $ttl,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        unset($user['password_hash']);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Login success',
            'token'   => $token,
            'data'    => [
                'user'      => $user,
                'work_mode' => $workMode,
                'token'     => $token,
            ],
            'errors'  => null,
        ]);
    }

    /**
     * GET /api/auth/me
     * Protected by AuthFilter
     */
    public function me()
    {
        $userId = (int) ($_SERVER['HTTP_X_USER_ID'] ?? 0);

        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Unauthorized',
                'data'    => null,
                'errors'  => null
            ]);
        }

        $userModel = new UserModel();
        $user = $userModel->findWithRole($userId);

        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON([
                'success' => false,
                'message' => 'User not found',
                'data'    => null,
                'errors'  => null
            ]);
        }

        unset($user['password_hash']);

        // ---- Permissions (Role based) ----
        $roleId = (int) ($_SERVER['HTTP_X_ROLE_ID'] ?? ($user['role_id'] ?? 0));

        $permissionCodes = [];
        if ($roleId > 0) {
            $db = \Config\Database::connect();

            $rows = $db->table('role_permissions rp')
                ->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', $roleId)
                ->get()
                ->getResultArray();

            $permissionCodes = array_values(array_unique(array_column($rows, 'code')));
            sort($permissionCodes);
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => 'OK',
            'data'    => [
                'user'        => $user,
                'permissions' => $permissionCodes
            ],
            'errors'  => null
        ]);
    }

    /**
     * POST /api/auth/logout
     * Clears the npcrm_token cookie
     */
    public function logout()
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        setcookie('npcrm_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $secure ? 'None' : 'Lax',
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Logged out',
            'data'    => null,
            'errors'  => null,
        ]);
    }
}