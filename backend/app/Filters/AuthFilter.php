<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\JwtService;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        log_message('debug', 'AuthFilter triggered');

        $token = '';

        // 1) Cookie JWT
        $cookieToken = $request->getCookie('npcrm_token');
        if (!empty($cookieToken)) {
            $token = trim($cookieToken);
        } else {
            // 2) Authorization header (dev)
            $authHeader = $request->getHeaderLine('Authorization');
            if ($authHeader && preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
            } else {
                // 3) X-Auth-Token (legacy)
                $xToken = $request->getHeaderLine('X-Auth-Token');
                if (!empty($xToken)) {
                    $token = trim(preg_replace('/^Bearer\s+/i', '', $xToken));
                }
            }
        }

        if (!$token) {
            return service('response')->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Missing authentication token',
                'data'    => null,
                'errors'  => null,
            ]);
        }

        try {
            $decoded = JwtService::decode($token);

            $userId   = (int) ($decoded->sub ?? 0);
            $roleId   = (int) ($decoded->role_id ?? 0);
            $roleCode = (string) ($decoded->role_code ?? '');

            if ($userId <= 0) {
                return service('response')->setStatusCode(401)->setJSON([
                    'success' => false,
                    'message' => 'Invalid token (missing user)',
                    'data'    => null,
                    'errors'  => null,
                ]);
            }

            // Keep your existing server vars (if perm filter uses them)
            $_SERVER['HTTP_X_USER_ID']   = (string) $userId;
            $_SERVER['HTTP_X_ROLE_ID']   = (string) $roleId;
            $_SERVER['HTTP_X_ROLE_CODE'] = $roleCode;

            // ✅ THIS is what your controllers need
            $request->user = [
                'id'        => $userId,
                'role_id'   => $roleId,
                'role_code' => $roleCode,
            ];

            return $request; // ✅ IMPORTANT

        } catch (\Throwable $e) {
            return service('response')->setStatusCode(401)->setJSON([
                'success' => false,
                'message' => 'Invalid or expired token',
                'data'    => null,
                'errors'  => null,
            ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No action required
    }
}
